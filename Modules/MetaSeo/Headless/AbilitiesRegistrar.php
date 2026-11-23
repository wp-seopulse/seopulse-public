<?php
/**
 * Exposes content analysis, meta generation and redirections through the WordPress Abilities API.
 *
 * @package SEOPulse\Modules\MetaSeo\Headless
 * @since   1.9.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Headless;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Contracts\ExecuteHooks;
use WP_Error;
use WP_REST_Request;

/**
 * Abilities are thin wrappers over the plugin's REST routes: validation,
 * sanitization and business rules stay in one place.
 */
class AbilitiesRegistrar implements ExecuteHooks
{
    public const CATEGORY = 'seopulse';

    private const REST_NAMESPACE = '/seopulse/v1';

    /**
     * Registers WordPress hooks (WordPress 6.9+ only).
     *
     * @return void
     */
    public function hooks(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    /**
     * Registers the SEOPulse ability category.
     *
     * @return void
     */
    public function register_category(): void
    {
        if (!$this->available()) {
            return;
        }

        wp_register_ability_category(
            self::CATEGORY,
            [
                'label'       => __('SEOPulse', 'seopulse'),
                'description' => __('SEO analysis, meta tags and redirections.', 'seopulse'),
            ],
        );
    }

    /**
     * Registers the abilities.
     *
     * @return void
     */
    public function register_abilities(): void
    {
        if (!$this->available()) {
            return;
        }

        $post_id = ['type' => 'integer', 'minimum' => 1, 'description' => __('Post ID.', 'seopulse')];

        $this->register(
            'seopulse/analyze-content',
            __('Analyze content', 'seopulse'),
            __('Runs the SEO and readability analysis of a post and returns the score and the checks.', 'seopulse'),
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'        => $post_id,
                    'focus_keywords' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => __('Focus keywords to analyze.', 'seopulse'),
                    ],
                    'force_refresh'  => ['type' => 'boolean', 'default' => false],
                ],
                'required'   => ['post_id'],
            ],
            fn (array $input) => $this->dispatch('POST', '/analyze', $this->only($input, ['post_id', 'focus_keywords', 'force_refresh'])),
            fn (array $input) => $this->can_edit_post($input),
            ['readonly' => false, 'destructive' => false, 'idempotent' => true],
        );

        $this->register(
            'seopulse/preview-meta',
            __('Preview post meta', 'seopulse'),
            __('Returns the title, description, robots, canonical and social tags SEOPulse generates for a post.', 'seopulse'),
            [
                'type'       => 'object',
                'properties' => ['post_id' => $post_id],
                'required'   => ['post_id'],
            ],
            fn (array $input) => $this->dispatch('GET', '/meta-engine/preview/' . (int) $input['post_id'], []),
            fn (array $input) => $this->can_edit_post($input),
            ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        );

        $this->register(
            'seopulse/resolve-meta-template',
            __('Resolve a meta template', 'seopulse'),
            __('Resolves a template containing SEOPulse variables (for example %%title%% %%sep%% %%sitename%%).', 'seopulse'),
            [
                'type'       => 'object',
                'properties' => ['template' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000]],
                'required'   => ['template'],
            ],
            fn (array $input) => $this->dispatch('POST', '/meta-engine/resolve', ['template' => $input['template']]),
            static fn () => current_user_can('edit_posts'),
            ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        );

        $this->register(
            'seopulse/list-redirects',
            __('List redirects', 'seopulse'),
            __('Lists redirects with optional search and status filters.', 'seopulse'),
            [
                'type'       => 'object',
                'properties' => [
                    'search'   => ['type' => 'string'],
                    'status'   => ['type' => 'string'],
                    'page'     => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                ],
            ],
            fn (array $input) => $this->dispatch('GET', '/redirections/sql', $this->only($input, ['search', 'status', 'page', 'per_page'])),
            static fn () => current_user_can('manage_options'),
            ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        );

        $this->register(
            'seopulse/create-redirect',
            __('Create a redirect', 'seopulse'),
            __('Creates a redirect from a source path to a target URL.', 'seopulse'),
            [
                'type'       => 'object',
                'properties' => [
                    'source_url'    => ['type' => 'string', 'minLength' => 1],
                    'target_url'    => ['type' => 'string'],
                    'redirect_type' => ['type' => 'integer', 'minimum' => 300, 'maximum' => 599, 'default' => 301],
                    'match_type'    => ['type' => 'string', 'default' => 'exact'],
                    'description'   => ['type' => 'string'],
                ],
                'required'   => ['source_url'],
            ],
            fn (array $input) => $this->dispatch('POST', '/redirections/sql', $this->only($input, ['source_url', 'target_url', 'redirect_type', 'match_type', 'description'])),
            static fn () => current_user_can('manage_options'),
            ['readonly' => false, 'destructive' => false, 'idempotent' => false],
        );
    }

    /**
     * Whether the Abilities API exists and the site owner exposes it.
     *
     * @return bool
     */
    private function available(): bool
    {
        return function_exists('wp_register_ability') && HeadSettings::abilities_enabled();
    }

    /**
     * @param array<string, mixed> $input_schema
     * @param array<string, bool> $annotations
     */
    private function register(string $name, string $label, string $description, array $input_schema, callable $execute, callable $permission, array $annotations): void
    {
        wp_register_ability(
            $name,
            [
                'label'               => $label,
                'description'         => $description,
                'category'            => self::CATEGORY,
                'input_schema'        => $input_schema,
                'output_schema'       => ['type' => 'object'],
                'execute_callback'    => static fn ($input = null) => $execute(is_array($input) ? $input : []),
                'permission_callback' => static fn ($input = null) => (bool) $permission(is_array($input) ? $input : []),
                'meta'                => [
                    'show_in_rest' => true,
                    'annotations'  => $annotations,
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function can_edit_post(array $input): bool
    {
        $post_id = (int) ($input['post_id'] ?? 0);

        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input
     * @param string[] $keys
     * @return array<string, mixed>
     */
    private function only(array $input, array $keys): array
    {
        return array_intersect_key($input, array_flip($keys));
    }

    /**
     * Runs a plugin REST route in-process, as the current user.
     *
     * @param string $method HTTP method.
     * @param string $route Route below /seopulse/v1.
     * @param array<string, mixed> $params Request parameters.
     * @return mixed|WP_Error Response data, unwrapped from the {success, data} envelope.
     */
    private function dispatch(string $method, string $route, array $params)
    {
        $request = new WP_REST_Request($method, self::REST_NAMESPACE . $route);
        $request->set_body_params($params);

        if ('GET' === $method) {
            $request->set_query_params($params);
        }

        $response = rest_do_request($request);

        if ($response->is_error()) {
            return $response->as_error();
        }

        $data = $response->get_data();

        return is_array($data) && array_key_exists('data', $data) && array_key_exists('success', $data) ? $data['data'] : $data;
    }
}
