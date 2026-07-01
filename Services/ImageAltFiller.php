<?php

/**
 * Image ALT text auto-filler service
 *
 * Automatically fills alt text on upload and provides batch filling
 * for existing images missing alt attributes.
 *
 * @package SEOPulse\Services
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ImageAltFiller — auto-fills image alt text for SEO
 */
class ImageAltFiller
{
    // ──────────────────────────────────────────────
    // CONSTANTS
    // ──────────────────────────────────────────────

    /** @var string Strategy: derive alt from attachment filename/slug */
    public const STRATEGY_FILENAME = 'filename';

    /** @var string Strategy: derive alt from attachment title */
    public const STRATEGY_TITLE = 'title';

    /** @var string Strategy: derive alt from parent post title */
    public const STRATEGY_PARENT = 'parent';

    /** @var string Option key for Image Alt settings */
    public const OPTION_KEY = 'seopulse_image_alt_settings';

    /** @var int Batch size for bulk operations */
    public const BATCH_SIZE = 100;

    /** @var int Max characters for a renamed filename (excluding extension) */
    private const RENAME_MAX_LENGTH = 50;

    /** @var array<string> Patterns indicating non-SEO-friendly filenames */
    private const NON_SEO_PATTERNS = [
        '/^IMG[_-]/i',
        '/^DSC[_-]/i',
        '/^DCIM[_-]/i',
        '/^Screenshot[_-]/i',
        '/^Screen\s?Shot/i',
        '/^photo[_-]/i',
        '/^image[_-]?\d/i',
        '/^P\d{7,}/i',          // Panasonic-style P1000123
        '/^[A-F0-9]{8,}$/i',    // hex hash
        '/^\d{6,}$/',            // numbers-only (timestamps etc.)
        '/^[A-Z]{2,4}\d{4,}/',  // camera codes like DSCN1234, PANO0001
    ];

    /** @var array<string> Allowed MIME types for alt text filling */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    // ──────────────────────────────────────────────
    // HOOKS
    // ──────────────────────────────────────────────

    /**
     * Register WordPress hooks for auto-fill on upload
     *
     * @return void
     */
    public function register_hooks(): void
    {
        $settings = $this->get_settings();

        if (empty($settings['enabled'])) {
            return;
        }

        // Hook after attachment metadata is generated (thumbnail sizes are ready)
        add_action('wp_generate_attachment_metadata', [$this, 'auto_fill_on_upload'], 10, 2);

        // Image renaming on upload (prefilter fires before file is moved)
        if (!empty($settings['rename_enabled'])) {
            add_filter('wp_handle_upload_prefilter', [$this, 'rename_on_upload']);
        }
    }

    // ──────────────────────────────────────────────
    // AUTO-FILL ON UPLOAD
    // ──────────────────────────────────────────────

    /**
     * Auto-fill alt text when an image is uploaded
     *
     * Hooked to `wp_generate_attachment_metadata` which fires after
     * the attachment post is already created and metadata generated.
     *
     * @param array<string, mixed> $metadata Attachment metadata
     * @param int $attachment_id Attachment post ID
     *
     * @return array<string, mixed> Unmodified metadata (passthrough)
     */
    public function auto_fill_on_upload(array $metadata, int $attachment_id): array
    {
        $post = get_post($attachment_id);

        if (!$post || $post->post_type !== 'attachment') {
            return $metadata;
        }

        // Only process allowed image types
        if (!in_array($post->post_mime_type, self::ALLOWED_MIME_TYPES, true)) {
            return $metadata;
        }

        $settings = $this->get_settings();

        // Skip if alt already exists and overwrite is disabled
        if (!($settings['overwrite'] ?? false)) {
            $existing_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if (!empty($existing_alt)) {
                return $metadata;
            }
        }

        $alt_text = $this->generate_alt_text($post, $settings['strategy'] ?? self::STRATEGY_FILENAME);

        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        return $metadata;
    }

    // ──────────────────────────────────────────────
    // BATCH FILL FOR EXISTING IMAGES
    // ──────────────────────────────────────────────

    /**
     * Fill alt text for existing images in batch
     *
     * @param int $page Page number (1-based)
     * @param string|null $strategy Override strategy (null = use settings)
     * @param bool|null $overwrite Override overwrite setting (null = use settings)
     *
     * @return array{processed: int, updated: int, skipped: int, total: int, has_more: bool}
     */
    public function batch_fill(int $page = 1, ?string $strategy = null, ?bool $overwrite = null): array
    {
        $settings  = $this->get_settings();
        $strategy  = $strategy ?? ($settings['strategy'] ?? self::STRATEGY_FILENAME);
        $overwrite = $overwrite ?? ($settings['overwrite'] ?? false);

        $offset = ($page - 1) * self::BATCH_SIZE;

        // Count total images
        $total = $this->count_images($overwrite);

        // Get batch of images
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => self::ALLOWED_MIME_TYPES,
            'post_status'    => 'inherit',
            'posts_per_page' => self::BATCH_SIZE,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ];

        // If not overwriting, only get images without alt
        if (!$overwrite) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_wp_attachment_image_alt',
                    'value'   => '',
                    'compare' => '=',
                ],
            ];
        }

        $image_ids = get_posts($args);

        $updated = 0;
        $skipped = 0;

        foreach ($image_ids as $attachment_id) {
            $post = get_post($attachment_id);

            if (!$post) {
                ++$skipped;
                continue;
            }

            $alt_text = $this->generate_alt_text($post, $strategy);

            if (!empty($alt_text)) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
                ++$updated;
            } else {
                ++$skipped;
            }
        }

        return [
            'processed' => count($image_ids),
            'updated'   => $updated,
            'skipped'   => $skipped,
            'total'     => $total,
            'has_more'  => ($offset + self::BATCH_SIZE) < $total,
        ];
    }

    // ──────────────────────────────────────────────
    // DIAGNOSTICS
    // ──────────────────────────────────────────────

    /**
     * Get summary of images missing alt text
     *
     * @return array{total_images: int, missing_alt: int, has_alt: int}
     */
    public function get_diagnostics(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_mime_type IN (%s, %s, %s, %s)
                 AND post_status = 'inherit'",
                ...self::ALLOWED_MIME_TYPES,
            ),
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $has_alt = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'attachment'
                 AND p.post_mime_type IN (%s, %s, %s, %s)
                 AND p.post_status = 'inherit'
                 AND pm.meta_key = '_wp_attachment_image_alt'
                 AND pm.meta_value != ''",
                ...self::ALLOWED_MIME_TYPES,
            ),
        );

        return [
            'total_images' => $total,
            'missing_alt'  => $total - $has_alt,
            'has_alt'      => $has_alt,
        ];
    }

    // ──────────────────────────────────────────────
    // ALT TEXT GENERATION
    // ──────────────────────────────────────────────

    /**
     * Generate alt text for an attachment based on the chosen strategy
     *
     * @param \WP_Post $post Attachment post
     * @param string $strategy Strategy to use
     *
     * @return string Generated alt text (may be empty)
     */
    private function generate_alt_text(\WP_Post $post, string $strategy): string
    {
        switch ($strategy) {
            case self::STRATEGY_TITLE:
                return $this->alt_from_title($post);

            case self::STRATEGY_PARENT:
                return $this->alt_from_parent($post);

            case self::STRATEGY_FILENAME:
            default:
                return $this->alt_from_filename($post);
        }
    }

    /**
     * Generate alt from filename/slug
     *
     * Transforms "my-product-photo.jpg" → "My Product Photo"
     *
     * @param \WP_Post $post Attachment post
     *
     * @return string
     */
    private function alt_from_filename(\WP_Post $post): string
    {
        $file = get_attached_file($post->ID);

        if (!$file) {
            return '';
        }

        // Get filename without extension
        $filename = pathinfo(basename($file), PATHINFO_FILENAME);

        return $this->humanize_slug($filename);
    }

    /**
     * Generate alt from attachment title
     *
     * @param \WP_Post $post Attachment post
     *
     * @return string
     */
    private function alt_from_title(\WP_Post $post): string
    {
        $title = $post->post_title;

        // WordPress often sets the title to the filename without extension
        // If it looks like a slug, humanize it
        if (preg_match('/^[a-z0-9_-]+$/i', $title)) {
            return $this->humanize_slug($title);
        }

        return $title;
    }

    /**
     * Generate alt from parent post title
     *
     * Falls back to filename strategy if no parent exists.
     *
     * @param \WP_Post $post Attachment post
     *
     * @return string
     */
    private function alt_from_parent(\WP_Post $post): string
    {
        if ($post->post_parent > 0) {
            $parent = get_post($post->post_parent);
            if ($parent && !empty($parent->post_title)) {
                return $parent->post_title;
            }
        }

        // Fallback to filename
        return $this->alt_from_filename($post);
    }

    /**
     * Convert a slug/filename to human-readable text
     *
     * "my-product-photo" → "My Product Photo"
     * "IMG_20250101_123456" → "Img 20250101 123456"
     *
     * @param string $slug Slug or filename
     *
     * @return string Humanized text
     */
    private function humanize_slug(string $slug): string
    {
        // Replace dashes and underscores with spaces
        $text = str_replace(['-', '_'], ' ', $slug);

        // Remove multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);

        // Capitalize first letter of each word
        $text = ucwords(strtolower(trim($text)));

        return $text;
    }

    // ──────────────────────────────────────────────
    // IMAGE RENAMING ON UPLOAD
    // ──────────────────────────────────────────────

    /**
     * Rename uploaded image file to an SEO-friendly slug
     *
     * Hooked to `wp_handle_upload_prefilter` which fires before the file
     * is moved to wp-content/uploads. Modifying the name here means
     * WordPress stores the file with the new name — no URL rewriting needed.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file Upload data
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public function rename_on_upload(array $file): array
    {
        // Only process allowed image types
        $mime = $file['type'] ?? '';
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return $file;
        }

        $original  = $file['name'];
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $basename  = pathinfo($original, PATHINFO_FILENAME);

        // Skip if filename is already SEO-friendly
        if ($this->is_seo_friendly($basename)) {
            return $file;
        }

        $new_name = $this->generate_seo_filename($basename, $extension);

        if (!empty($new_name) && $new_name !== $original) {
            $file['name'] = $new_name;
        }

        return $file;
    }

    /**
     * Check if a filename is already SEO-friendly
     *
     * A SEO-friendly filename:
     * - Does not match known camera/screenshot patterns
     * - Contains at least one word character separated by dashes
     * - Is not purely numeric or a hex hash
     *
     * @param string $basename Filename without extension
     *
     * @return bool
     */
    public function is_seo_friendly(string $basename): bool
    {
        foreach (self::NON_SEO_PATTERNS as $pattern) {
            if (preg_match($pattern, $basename)) {
                return false;
            }
        }

        // Must contain at least one alphabetic word of 3+ chars separated by dashes/underscores
        if (!preg_match('/[a-z]{3,}/i', $basename)) {
            return false;
        }

        return true;
    }

    /**
     * Generate an SEO-friendly filename for an image
     *
     * Strategy:
     * 1. If a parent post ID is available (uploading from editor), use {post-slug}-{counter}
     * 2. Otherwise, sanitize the current filename
     *
     * @param string $basename Original filename without extension
     * @param string $extension File extension (jpg, png, webp)
     *
     * @return string New filename with extension
     */
    private function generate_seo_filename(string $basename, string $extension): string
    {
        $slug = '';

        // Check if we're uploading from a post editor context.
        // Only read $_REQUEST['post_id'] after verifying the WordPress upload nonce.
        $post_id = 0;
        if (
            isset($_REQUEST['post_id'], $_REQUEST['_wpnonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'media-form')
        ) {
            $post_id = absint($_REQUEST['post_id']);
        }

        if ($post_id > 0) {
            $parent = get_post($post_id);
            if ($parent && !empty($parent->post_name)) {
                $slug = $parent->post_name;
            } elseif ($parent && !empty($parent->post_title)) {
                $slug = sanitize_title($parent->post_title);
            }
        }

        // If no parent context, humanize the current filename
        if (empty($slug)) {
            $slug = $this->sanitize_filename_to_slug($basename);
        }

        // Truncate slug to max length
        if (mb_strlen($slug) > self::RENAME_MAX_LENGTH) {
            $slug = mb_substr($slug, 0, self::RENAME_MAX_LENGTH);
            // Don't end on a partial word — trim back to last dash
            $last_dash = strrpos($slug, '-');
            if ($last_dash !== false && $last_dash > 10) {
                $slug = substr($slug, 0, $last_dash);
            }
        }

        // Append a counter to avoid collisions
        $slug = $this->make_unique_filename($slug, $extension);

        return $slug . '.' . $extension;
    }

    /**
     * Sanitize a raw filename into a clean slug
     *
     * "IMG_20250319_My Product" → "my-product"
     * "DCIM_photo123" → "photo123"
     *
     * @param string $basename Raw filename
     *
     * @return string Sanitized slug
     */
    private function sanitize_filename_to_slug(string $basename): string
    {
        // Strip known camera prefixes
        $clean = preg_replace('/^(IMG|DSC|DCIM|DSCN|PANO|Screenshot|Screen\s?Shot|photo|image)[_\-\s]*/i', '', $basename);

        // Replace underscores and spaces with dashes
        $clean = str_replace(['_', ' '], '-', $clean ?? $basename);

        // Remove non-alphanumeric chars (keep dashes)
        $clean = preg_replace('/[^a-z0-9\-]/i', '', $clean ?? '');

        // Remove consecutive dashes
        $clean = preg_replace('/-+/', '-', $clean ?? '');

        // Remove leading/trailing dashes
        $clean = trim($clean ?? '', '-');

        // Lowercase
        $clean = strtolower($clean);

        // If still empty or purely numeric, use a generic slug
        if (empty($clean) || preg_match('/^\d+$/', $clean)) {
            $clean = 'image';
        }

        return $clean;
    }

    /**
     * Append a counter to the slug to ensure no file collision
     *
     * Checks the uploads directory for existing files and increments
     * the counter until a unique name is found.
     *
     * @param string $slug Base slug
     * @param string $extension File extension
     *
     * @return string Slug with counter (e.g., "my-article-01")
     */
    private function make_unique_filename(string $slug, string $extension): string
    {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit($upload_dir['path']);

        // Start with -01 suffix
        $counter   = 1;
        $candidate = $slug . '-' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);

        while (file_exists($dir . $candidate . '.' . $extension)) {
            ++$counter;
            $candidate = $slug . '-' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);

            // Safety: bail after 999 to avoid infinite loop
            if ($counter > 999) {
                $candidate = $slug . '-' . wp_generate_password(4, false);
                break;
            }
        }

        return $candidate;
    }

    // ──────────────────────────────────────────────
    // SETTINGS
    // ──────────────────────────────────────────────

    /**
     * Get image alt settings
     *
     * @return array{enabled: bool, strategy: string, overwrite: bool, rename_enabled: bool}
     */
    public function get_settings(): array
    {
        $defaults = [
            'enabled'        => false,
            'strategy'       => self::STRATEGY_FILENAME,
            'overwrite'      => false,
            'rename_enabled' => false,
        ];

        $settings = get_option(self::OPTION_KEY, []);

        if (!is_array($settings)) {
            return $defaults;
        }

        return array_merge($defaults, $settings);
    }

    /**
     * Save image alt settings
     *
     * @param array<string, mixed> $settings Settings to save
     *
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        $sanitized = [
            'enabled'        => !empty($settings['enabled']),
            'strategy'       => in_array(
                $settings['strategy'] ?? '',
                [
                    self::STRATEGY_FILENAME,
                    self::STRATEGY_TITLE,
                    self::STRATEGY_PARENT,
                ],
                true,
            ) ? $settings['strategy'] : self::STRATEGY_FILENAME,
            'overwrite'      => !empty($settings['overwrite']),
            'rename_enabled' => !empty($settings['rename_enabled']),
        ];

        return update_option(self::OPTION_KEY, $sanitized);
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────

    /**
     * Count images matching criteria
     *
     * @param bool $include_with_alt Include images that already have alt text
     *
     * @return int
     */
    private function count_images(bool $include_with_alt): int
    {
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => self::ALLOWED_MIME_TYPES,
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if (!$include_with_alt) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_wp_attachment_image_alt',
                    'value'   => '',
                    'compare' => '=',
                ],
            ];
        }

        $query = new \WP_Query($args);

        return $query->found_posts;
    }
}
