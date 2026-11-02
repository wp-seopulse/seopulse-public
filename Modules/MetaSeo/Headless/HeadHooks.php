<?php
/**
 * Hooks for the headless head payload: REST field and cache invalidation.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.8.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Constants\PostMeta;
use SEOPulse\Core\Contracts\ExecuteHooks;
use SEOPulse\Modules\MetaSeo\Engine\MetaEngine;
use WP_Post;

/**
 * Registers the "seopulse_head_json" field on public post types and keeps the
 * payload cache consistent with content and settings changes.
 */
class HeadHooks implements ExecuteHooks
{
    /**
     * REST field name.
     */
    public const FIELD = 'seopulse_head_json';

    /**
     * Cron action that warms the cache of a published post.
     */
    public const WARM_ACTION = 'seopulse_head_warm';

    /**
     * Post meta keys that change the payload without going through save_post.
     *
     * @var string[]
     */
    private const WATCHED_POST_META = [
        PostMeta::META_SEO,
        '_seopulse_faqs',
        '_thumbnail_id',
        '_wp_attachment_image_alt',
    ];

    /**
     * Term meta key holding term-level SEO overrides.
     */
    private const TERM_META_SEO = '_seopulse_term_meta_seo';

    /**
     * Exact option names that change the payload.
     *
     * @var string[]
     */
    private const WATCHED_OPTIONS = [
        Options::META_SEO_GLOBAL,
        Options::META_TEMPLATES,
        Options::LOCAL_SEO,
        Options::ARCHIVE_SETTINGS,
        Options::TAXONOMY_SETTINGS,
        Options::MODULES_ENABLED,
        Options::REDIRECTIONS,
        'blogname',
        'blogdescription',
        'home',
        'siteurl',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'permalink_structure',
        'category_base',
        'tag_base',
        'rewrite_rules',
        'site_icon',
        'WPLANG',
    ];

    /**
     * Payload generator, created on first use.
     *
     * @var HeadPayloadGenerator|null
     */
    private ?HeadPayloadGenerator $generator = null;

    /**
     * Payloads built by the REST field during this request.
     *
     * @var int
     */
    private int $cold_built = 0;

    /**
     * Registers WordPress hooks.
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_field']);
        add_action(self::WARM_ACTION, [$this, 'warm']);
        add_action('shutdown', [new HeadWebhook(), 'flush']);

        add_action('save_post', [$this, 'on_save_post'], 20, 2);
        add_action('transition_post_status', [$this, 'on_transition_post_status'], 20, 3);
        add_action('deleted_post', [$this, 'on_deleted_post'], 20, 2);

        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [$this, 'on_post_meta'], 20, 3);
        }

        foreach (['added_term_meta', 'updated_term_meta', 'deleted_term_meta'] as $hook) {
            add_action($hook, [$this, 'on_term_meta'], 20, 3);
        }

        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, [$this, 'on_option'], 20, 1);
        }

        foreach (['created_term', 'edited_term', 'delete_term', 'profile_update', 'deleted_user', 'after_switch_theme'] as $hook) {
            add_action($hook, [$this, 'invalidate'], 20, 0);
        }
    }

    /**
     * Registers the REST field on every public post type exposed in REST.
     *
     * @return void
     */
    public function register_field(): void
    {
        if (!HeadSettings::field_enabled()) {
            return;
        }

        $post_types = get_post_types(
            [
                'public'       => true,
                'show_in_rest' => true,
            ],
            'names',
        );

        if (empty($post_types)) {
            return;
        }

        register_rest_field(
            array_values($post_types),
            self::FIELD,
            [
                'get_callback' => [$this, 'get_field'],
                'schema'       => [
                    'description' => __('SEO head data (title, robots, canonical, Open Graph, Twitter, JSON-LD) for headless rendering. Null when the content is not public.', 'seopulse'),
                    'type'        => ['object', 'null'],
                    'context'     => ['view', 'edit'],
                    'readonly'    => true,
                ],
            ],
        );
    }

    /**
     * Returns the field value for a REST item.
     *
     * @param array<string, mixed> $item REST response data of the post.
     * @return array<string, mixed>|null
     */
    public function get_field(array $item): ?array
    {
        $post = isset($item['id']) ? get_post((int) $item['id']) : null;

        if (!$post instanceof WP_Post) {
            return null;
        }

        if (null === $this->generator) {
            $this->generator = new HeadPayloadGenerator();
        }

        $cached = $this->generator->cached_post($post);

        if (null !== $cached) {
            return $cached;
        }

        /**
         * Filters how many payloads one request may build for the REST field (0 = no limit).
         *
         * Beyond the limit, payloads are built in the background and the field is
         * {"version": 1, "pending": true}, so long lists stay fast on a cold cache.
         *
         * @since 1.8.0
         * @param int $limit Maximum number of payloads built per request.
         */
        $limit = (int) apply_filters('seopulse_head_field_cold_limit', 20);

        if ($limit > 0 && $this->cold_built >= $limit && $this->generator->is_exposable($post)) {
            if (!wp_next_scheduled(self::WARM_ACTION, [$post->ID])) {
                wp_schedule_single_event(time() + 5, self::WARM_ACTION, [$post->ID]);
            }

            return ['version' => HeadPayloadGenerator::VERSION, 'pending' => true];
        }

        ++$this->cold_built;

        return $this->generator->for_post($post);
    }

    /**
     * Invalidates the cache when a publicly viewable post is saved.
     *
     * @param int $post_id Post ID.
     * @param WP_Post $post Post.
     * @return void
     */
    public function on_save_post(int $post_id, WP_Post $post): void
    {
        if (!wp_is_post_revision($post_id) && is_post_publicly_viewable($post)) {
            $this->invalidate_post($post);
        }
    }

    /**
     * Invalidates the cache when a post becomes or stops being publicly viewable.
     *
     * @param string $new_status New status.
     * @param string $old_status Old status.
     * @param WP_Post $post Post.
     * @return void
     */
    public function on_transition_post_status(string $new_status, string $old_status, WP_Post $post): void
    {
        if ($new_status === $old_status || !is_post_type_viewable($post->post_type)) {
            return;
        }

        if (is_post_status_viewable($new_status) || is_post_status_viewable($old_status)) {
            $this->invalidate_post($post);
        }
    }

    /**
     * Invalidates the cache when a publicly viewable post is deleted.
     *
     * @param int $post_id Post ID.
     * @param WP_Post $post Deleted post.
     * @return void
     */
    public function on_deleted_post(int $post_id, WP_Post $post): void
    {
        if (is_post_type_viewable($post->post_type) && is_post_status_viewable($post->post_status)) {
            $this->invalidate_post($post);
        }
    }

    /**
     * Invalidates the cache when a watched post meta changes.
     *
     * @param int $meta_id Meta ID (unused).
     * @param int $object_id Post ID.
     * @param string $meta_key Meta key.
     * @return void
     */
    public function on_post_meta($meta_id, $object_id, $meta_key): void
    {
        if (!in_array($meta_key, self::WATCHED_POST_META, true)) {
            return;
        }

        $post = get_post((int) $object_id);

        if (!$post instanceof WP_Post) {
            return;
        }

        if ('attachment' === $post->post_type) {
            // An image can feed any content.
            $this->invalidate();
        } elseif (is_post_publicly_viewable($post)) {
            (new HeadCache())->touch_post($post->ID);
            (new HeadWebhook())->queue('post', $this->permalink($post));
        }
    }

    /**
     * Invalidates the cache when term-level SEO data changes.
     *
     * @param int $meta_id Meta ID (unused).
     * @param int $object_id Term ID (unused).
     * @param string $meta_key Meta key.
     * @return void
     */
    public function on_term_meta($meta_id, $object_id, $meta_key): void
    {
        if (self::TERM_META_SEO === $meta_key) {
            $this->invalidate();
        }
    }

    /**
     * Invalidates the cache when a watched option changes.
     *
     * @param string $option Option name.
     * @return void
     */
    public function on_option($option): void
    {
        if (!is_string($option)) {
            return;
        }

        $is_watched = in_array($option, self::WATCHED_OPTIONS, true)
            || str_starts_with($option, Options::META_TEMPLATES . '_')
            || str_starts_with($option, 'theme_mods_');

        if ($is_watched) {
            $this->invalidate();
        }
    }

    /**
     * Scoped invalidation for a public post: its own entries and the listings.
     *
     * A hierarchical post (page) also feeds its children's breadcrumbs, so it flushes everything.
     *
     * @param WP_Post $post Post.
     * @return void
     */
    private function invalidate_post(WP_Post $post): void
    {
        if (is_post_type_hierarchical($post->post_type)) {
            $this->invalidate();

            return;
        }

        $cache = new HeadCache();
        $cache->touch_post($post->ID);

        if ($cache->flush_content()) {
            (new MetaEngine())->flushCache();
        }

        (new HeadWebhook())->queue('content', $this->permalink($post));

        if (is_post_status_viewable(get_post_status($post))
            && !wp_next_scheduled(self::WARM_ACTION, [$post->ID])
        ) {
            wp_schedule_single_event(time() + 10, self::WARM_ACTION, [$post->ID]);
        }
    }

    /**
     * Rebuilds the cached payloads of a post in the background.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function warm($post_id): void
    {
        $post = get_post((int) $post_id);

        if (!$post instanceof WP_Post || !is_post_publicly_viewable($post)) {
            return;
        }

        $generator = $this->generator ??= new HeadPayloadGenerator();
        $url       = $this->permalink($post);

        $generator->for_post($post);

        if ('' !== $url) {
            $generator->for_url($url);
        }
    }

    /**
     * Returns the public URL of a post, or '' when it has none.
     *
     * @param WP_Post $post Post.
     * @return string
     */
    private function permalink(WP_Post $post): string
    {
        $url = get_permalink($post);

        return is_string($url) ? $url : '';
    }

    /**
     * Invalidates every cached payload and the Meta Engine cache it is built from.
     *
     * The engine cache is flushed too because payloads also depend on site-wide
     * data (options, terms, authors) that its per-post invalidation does not cover.
     *
     * @return void
     */
    public function invalidate(): void
    {
        (new HeadWebhook())->queue('global');

        if ((new HeadCache())->flush()) {
            (new MetaEngine())->flushCache();
        }
    }
}
