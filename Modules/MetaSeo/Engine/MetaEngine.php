<?php

/**
 * MetaEngine — primary API for variable-based meta generation.
 *
 * This is the single entry point that MetaSeoRenderer, MetaSeoController,
 * the admin metabox, and headless consumers interact with.
 *
 * Registered in the Container as a service with NAME_SERVICE = 'MetaEngine'.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaEngine
{
    /** @var string Service identifier for the Container. */
    public const NAME_SERVICE = 'MetaEngine';

    private VariableRegistry $registry;
    private TemplateParser $parser;
    private ContextResolver $contextResolver;
    private TemplateStore $templateStore;
    private CacheLayer $cache;
    private SecurityLayer $security;

    /** @var array<string, TemplateNode[]> Parsed AST cache (L0 per-instance). */
    private array $astCache = [];

    // ------------------------------------------------------------------
    // Bootstrap
    // ------------------------------------------------------------------

    public function __construct()
    {
        $functionProcessor = new FunctionProcessor();
        $conditionalEngine = new ConditionalEngine();

        $this->registry        = new VariableRegistry();
        $this->parser          = new TemplateParser($functionProcessor, $conditionalEngine);
        $this->contextResolver = new ContextResolver();
        $this->templateStore   = new TemplateStore();
        $this->cache           = new CacheLayer();
        $this->security        = new SecurityLayer();

        $this->registerCoreProviders();
    }

    // ------------------------------------------------------------------
    // Public API — resolution
    // ------------------------------------------------------------------

    /**
     * Resolve a single template string.
     *
     * @param string $template e.g. "%%post.title%% %%sep%% %%site.name%%"
     * @param ContextBag|null $context Null = auto-detect from current WP query.
     * @param string $escaping Output escaping context (attr, html, url, json, raw).
     * @return string Resolved and escaped output.
     */
    public function resolve(string $template, ?ContextBag $context = null, string $escaping = 'attr'): string
    {
        if ($template === '') {
            return '';
        }

        // If template contains no %% %%, it's a literal — skip parsing
        if (!str_contains($template, '%%')) {
            return $this->security->sanitize($template, $escaping);
        }

        $context ??= $this->contextResolver->detect();

        // L2 cache check
        $cacheKey = CacheLayer::key($template, $context);
        $cached   = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        // Parse (or use L0 AST cache)
        $ast = $this->astCache[ $template ] ??= $this->parser->parse($template);

        // Evaluate
        $result = $this->parser->evaluate($ast, $this->registry, $context);

        // Sanitise
        $result = $this->security->sanitize($result, $escaping);

        // Store in L2 cache
        $this->cache->set($cacheKey, $result);

        return $result;
    }

    /**
     * Resolve all meta fields for a given context.
     *
     * Uses the four-level priority system:
     * Post Meta > CPT Template > Global Template > Fallback.
     *
     * @return MetaOutput Fully resolved meta data.
     */
    public function resolveAll(?ContextBag $context = null): MetaOutput
    {
        $context ??= $this->contextResolver->detect();
        $templates = $this->templateStore->getTemplatesForContext($context);

        return new MetaOutput(
            title:              $this->resolveField($templates, 'title', $context),
            description:        $this->resolveField($templates, 'description', $context),
            ogTitle:            $this->resolveField($templates, 'og_title', $context, $templates['title'] ?? ''),
            ogDescription:      $this->resolveField($templates, 'og_description', $context, $templates['description'] ?? ''),
            ogImage:            $this->resolveField($templates, 'og_image', $context, '', 'url'),
            ogType:             $templates['og_type'] ?? 'article',
            twitterTitle:       $this->resolveField($templates, 'twitter_title', $context, $templates['title'] ?? ''),
            twitterDescription: $this->resolveField($templates, 'twitter_description', $context, $templates['description'] ?? ''),
            twitterImage:       $this->resolveField($templates, 'twitter_image', $context, '', 'url'),
            twitterCard:        $templates['twitter_card'] ?? 'summary_large_image',
            canonical:          $this->resolveField($templates, 'canonical', $context, '', 'url'),
            robots:             $templates['robots'] ?? 'index,follow',
        );
    }

    /**
     * Preview resolved meta for a specific post (admin / editor use).
     */
    public function preview(int $postId): MetaOutput
    {
        $post = get_post($postId);

        if (!($post instanceof \WP_Post)) {
            return MetaOutput::empty();
        }

        $authorData = get_userdata((int) $post->post_author);

        $context = new ContextBag(
            type: 'singular',
            post: $post,
            author: ($authorData instanceof \WP_User) ? $authorData : null,
        );

        return $this->resolveAll($context);
    }

    // ------------------------------------------------------------------
    // Public API — introspection
    // ------------------------------------------------------------------

    /**
     * Get all available variables with their metadata (for autocomplete UI).
     *
     * @param string|null $namespace Filter by namespace, or null for all.
     * @return VariableDefinition[]
     */
    public function getAvailableVariables(?string $namespace = null): array
    {
        return $this->registry->getDefinitions($namespace);
    }

    /**
     * Get the variable registry (for external provider registration).
     */
    public function getRegistry(): VariableRegistry
    {
        return $this->registry;
    }

    /**
     * Get the function processor (for external function registration).
     */
    public function getFunctionProcessor(): FunctionProcessor
    {
        return $this->parser->getFunctionProcessor();
    }

    /**
     * Get the template store (for admin settings UI).
     */
    public function getTemplateStore(): TemplateStore
    {
        return $this->templateStore;
    }

    // ------------------------------------------------------------------
    // Public API — cache management
    // ------------------------------------------------------------------

    /**
     * Invalidate cache for a specific post.
     */
    public function invalidatePost(int $postId): void
    {
        $this->cache->invalidatePost($postId);
    }

    /**
     * Flush the entire Meta Engine cache.
     */
    public function flushCache(): void
    {
        $this->cache->flush();
        $this->astCache = [];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Resolve a single field from the templates map.
     *
     * @param array<string, string> $templates
     * @param string $field Field name to resolve.
     * @param ContextBag $context
     * @param string $fallback Fallback template if field is empty.
     * @param string $escaping Output escaping context.
     * @return string
     */
    private function resolveField(
        array $templates,
        string $field,
        ContextBag $context,
        string $fallback = '',
        string $escaping = 'attr',
    ): string {
        $template = $templates[ $field ] ?? $fallback;

        if ($template === '') {
            return '';
        }

        return $this->resolve($template, $context, $escaping);
    }

    /**
     * Register all core variable providers.
     */
    private function registerCoreProviders(): void
    {
        $this->registry->registerProvider('site', new Providers\SiteProvider());
        $this->registry->registerProvider('post', new Providers\PostProvider());
        $this->registry->registerProvider('term', new Providers\TermProvider());
        $this->registry->registerProvider('author', new Providers\AuthorProvider());
        $this->registry->registerProvider('archive', new Providers\ArchiveProvider());
        $this->registry->registerProvider('search', new Providers\SearchProvider());
        $this->registry->registerProvider('error', new Providers\ErrorProvider());
        $this->registry->registerProvider('page', new Providers\PageProvider());
        $this->registry->registerProvider('date', new Providers\DateProvider());
        $this->registry->registerProvider('org', new Providers\OrgProvider());
        $this->registry->registerProvider('env', new Providers\EnvProvider());
        $this->registry->registerProvider('cpt', new Providers\CptProvider());
        $this->registry->registerProvider('global', new Providers\GlobalProvider());
        $this->registry->registerProvider('acf', new Providers\AcfProvider());
        $this->registry->registerProvider('custom_field', new Providers\CustomFieldProvider());

        // WooCommerce: registered only when the plugin is active
        if (class_exists(\WooCommerce::class)) {
            $this->registry->registerProvider('woo', new Providers\WooProvider());
        }
    }
}
