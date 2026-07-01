<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * REST API controller for Image Diagnostic
 *
 * Provides endpoints for listing, filtering, and bulk-acting on images.
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api\Images;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Services\ImageAltFiller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * ImageDiagnosticController class
 *
 * Endpoints:
 *  GET    /seopulse/v1/image-diagnostic          — list images with filters
 *  POST   /seopulse/v1/image-diagnostic/bulk-alt  — bulk fill alt on selected IDs
 *  POST   /seopulse/v1/image-diagnostic/bulk-rename — bulk rename selected IDs
 *  POST   /seopulse/v1/image-diagnostic/edit-alt  — inline edit alt for one image
 *  GET    /seopulse/v1/image-diagnostic/export    — CSV export
 */
class ImageDiagnosticController extends RestController
{
    /** @var ImageAltFiller */
    private ImageAltFiller $filler;

    /** @var int Items per page */
    private const PER_PAGE = 50;

    /** @var int Large file threshold in bytes (500 KB) */
    private const LARGE_SIZE_THRESHOLD = 512000;

    public function __construct()
    {
        $this->rest_base = 'image-diagnostic';
        $this->filler    = new ImageAltFiller();
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // List images with filters + pagination
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list_images'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args'                => [
                    'page'    => [
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'filter'  => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'search'  => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'orderby' => [
                        'type'              => 'string',
                        'default'           => 'date',
                        'enum'              => ['date', 'title', 'filesize'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'order'   => [
                        'type'              => 'string',
                        'default'           => 'DESC',
                        'enum'              => ['ASC', 'DESC'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        );

        // Bulk fill alt text
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk-alt',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'bulk_fill_alt'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Bulk rename
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk-rename',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'bulk_rename'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // Inline edit alt
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/edit-alt',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'edit_alt'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );

        // CSV export
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'export_csv'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        );
    }

    // ──────────────────────────────────────────────
    // LIST IMAGES
    // ──────────────────────────────────────────────

    /**
     * List images with filtering, search, and pagination
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function list_images(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $page    = (int) $request->get_param('page');
        $filter  = (string) $request->get_param('filter');
        $search  = (string) $request->get_param('search');
        $orderby = (string) $request->get_param('orderby');
        $order   = strtoupper((string) $request->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';
        $offset  = ($page - 1) * self::PER_PAGE;

        $mime_placeholders = implode(',', array_fill(0, 4, '%s'));
        $mime_types        = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        // Build WHERE conditions
        $where_clauses = [
            "p.post_type = 'attachment'",
            "p.post_status = 'inherit'",
        ];
        $params        = [];

        // MIME filter
        $where_clauses[] = "p.post_mime_type IN ($mime_placeholders)";
        $params          = array_merge($params, $mime_types);

        // Search filter
        if (!empty($search)) {
            $like            = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(p.post_title LIKE %s OR pm_file.meta_value LIKE %s)';
            $params[]        = $like;
            $params[]        = $like;
        }

        switch ($filter) {
            case 'missing_alt':
                $where_clauses[] = "(pm_alt.meta_value IS NULL OR pm_alt.meta_value = '')";
                break;
            case 'poor_filename':
                // Filter applied in PHP after query (need file path)
                break;
            case 'large_size':
                // Filter applied in PHP after query (need file metadata)
                break;
            case 'unused':
                $where_clauses[] = 'p.post_parent = 0';
                break;
        }

        // ORDER BY — fully allowlisted mapping, no variable interpolation in SQL.
        $order_map = [
            'title_ASC'     => 'p.post_title ASC',
            'title_DESC'    => 'p.post_title DESC',
            'filesize_ASC'  => 'p.post_date ASC',   // Approx; real filesize sort in PHP
            'filesize_DESC' => 'p.post_date DESC',
        ];
        $order_key = "{$orderby}_{$order}";
        $order_sql = $order_map[$order_key] ?? ($order === 'ASC' ? 'p.post_date ASC' : 'p.post_date DESC');

        $where_sql = implode(' AND ', $where_clauses);

        // For poor_filename and large_size filters, we need to fetch more and filter in PHP
        $needs_php_filter = in_array($filter, ['poor_filename', 'large_size'], true);
        $fetch_limit      = $needs_php_filter ? self::PER_PAGE * 10 : self::PER_PAGE;
        $fetch_offset     = $needs_php_filter ? 0 : $offset;

        // Count total (without PHP filters)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is built from hardcoded SQL fragments with %s/%d placeholders; all runtime values go through $wpdb->prepare().
        if (!empty($params)) {
            $total_db = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
                     LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
                     WHERE $where_sql",
                    ...$params,
                ),
            );
        } else {
            $total_db = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
                     LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
                     WHERE $where_sql AND 1=%d",
                    1,
                ),
            );
        }

        // Fetch rows
        $query_params = array_merge($params, [$fetch_limit, $fetch_offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql/$order_sql are built from hardcoded SQL fragments and allowlisted values; all runtime values go through $wpdb->prepare().
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
                 LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
                 WHERE $where_sql ORDER BY $order_sql LIMIT %d OFFSET %d",
                ...$query_params,
            ),
        );

        // Build results
        $items = [];
        foreach ($ids as $id) {
            $item = $this->build_image_item((int) $id);
            if ($item === null) {
                continue;
            }

            // PHP-level filter
            if ($filter === 'poor_filename' && $item['seo_friendly']) {
                continue;
            }
            if ($filter === 'large_size' && $item['filesize'] < self::LARGE_SIZE_THRESHOLD) {
                continue;
            }

            $items[] = $item;
        }

        // If PHP-filtered, handle pagination manually
        if ($needs_php_filter) {
            $total = count($items);
            $items = array_slice($items, $offset, self::PER_PAGE);
        } else {
            $total = $total_db;
        }

        return $this->success(
            [
                'items'       => array_values($items),
                'total'       => $total,
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total_pages' => (int) ceil($total / self::PER_PAGE),
            ],
        );
    }

    // ──────────────────────────────────────────────
    // BULK FILL ALT
    // ──────────────────────────────────────────────

    /**
     * Bulk fill alt text for selected image IDs
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_fill_alt(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ids = $request->get_param('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No images selected.', 'seopulse'), 400);
        }

        $ids      = array_map('absint', $ids);
        $settings = $this->filler->get_settings();
        $strategy = $settings['strategy'] ?? 'filename';
        $updated  = 0;
        $skipped  = 0;

        foreach ($ids as $attachment_id) {
            $post = get_post($attachment_id);
            if (!$post || $post->post_type !== 'attachment') {
                ++$skipped;
                continue;
            }

            $existing_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if (!empty($existing_alt)) {
                ++$skipped;
                continue;
            }

            // Use reflection to call private generate_alt_text — or use public batch_fill approach
            // Instead, directly generate from filename (simplest and most universal)
            $alt = $this->generate_alt_for_attachment($post, $strategy);

            if (!empty($alt)) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
                ++$updated;
            } else {
                ++$skipped;
            }
        }

        return $this->success(
            [
                'updated' => $updated,
                'skipped' => $skipped,
                'total'   => count($ids),
            ],
        );
    }

    // ──────────────────────────────────────────────
    // BULK RENAME
    // ──────────────────────────────────────────────

    /**
     * Bulk rename selected images to SEO-friendly filenames
     *
     * Renames the physical file, updates attachment metadata and post_content references.
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_rename(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ids = $request->get_param('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No images selected.', 'seopulse'), 400);
        }

        $ids     = array_map('absint', $ids);
        $renamed = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($ids as $attachment_id) {
            $result = $this->rename_single_image($attachment_id);
            switch ($result) {
                case 'renamed':
                    ++$renamed;
                    break;
                case 'skipped':
                    ++$skipped;
                    break;
                default:
                    ++$errors;
                    break;
            }
        }

        return $this->success(
            [
                'renamed' => $renamed,
                'skipped' => $skipped,
                'errors'  => $errors,
                'total'   => count($ids),
            ],
        );
    }

    // ──────────────────────────────────────────────
    // INLINE EDIT ALT
    // ──────────────────────────────────────────────

    /**
     * Inline edit alt text for a single image
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response|WP_Error
     */
    public function edit_alt(WP_REST_Request $request)
    {
        $id  = absint($request->get_param('id'));
        $alt = sanitize_text_field((string) ($request->get_param('alt') ?? ''));

        if ($id <= 0) {
            return $this->error(__('Invalid image ID.', 'seopulse'), 400);
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return $this->error(__('Image not found.', 'seopulse'), 404);
        }

        update_post_meta($id, '_wp_attachment_image_alt', $alt);

        return $this->success(
            [
                'id'  => $id,
                'alt' => $alt,
            ],
        );
    }

    // ──────────────────────────────────────────────
    // CSV EXPORT
    // ──────────────────────────────────────────────

    /**
     * Export all images as CSV
     *
     * @param WP_REST_Request $request Request
     * @return WP_REST_Response
     */
    public function export_csv(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $mime_types   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a hardcoded set of %s tokens; values go through prepare().
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_mime_type IN ($placeholders)
                 AND post_status = 'inherit'
                 ORDER BY post_date DESC",
                ...$mime_types,
            ),
        );

        $rows   = [];
        $rows[] = ['ID', 'Filename', 'Alt Text', 'File Size (KB)', 'SEO Friendly', 'Parent Post', 'Date'];

        foreach ($ids as $id) {
            $item = $this->build_image_item((int) $id);
            if ($item === null) {
                continue;
            }

            $rows[] = [
                $item['id'],
                $item['filename'],
                $item['alt'],
                round($item['filesize'] / 1024, 1),
                $item['seo_friendly'] ? __('Yes', 'seopulse') : __('No', 'seopulse'),
                $item['parent_title'] ?? '',
                $item['date'],
            ];
        }

        // Build CSV string
        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(
                ',',
                array_map(
                    function ($field) {
                        $field = str_replace('"', '""', (string) $field);

                        return '"' . $field . '"';
                    },
                    $row,
                ),
            ) . "\n";
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => [
                    'csv'      => $csv,
                    'filename' => 'seopulse-image-diagnostic-' . gmdate('Y-m-d') . '.csv',
                ],
            ],
        );
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────

    /**
     * Build a single image item array for the response
     *
     * @param int $attachment_id Attachment ID
     * @return array<string, mixed>|null
     */
    private function build_image_item(int $attachment_id): ?array
    {
        $post = get_post($attachment_id);
        if (!$post) {
            return null;
        }

        $file      = get_attached_file($attachment_id);
        $filename  = $file ? basename($file) : '';
        $basename  = pathinfo($filename, PATHINFO_FILENAME);
        $filesize  = $file && file_exists($file) ? (int) filesize($file) : 0;
        $alt       = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');

        $parent_title = '';
        if ($post->post_parent > 0) {
            $parent = get_post($post->post_parent);
            if ($parent) {
                $parent_title = $parent->post_title;
            }
        }

        return [
            'id'           => $attachment_id,
            'filename'     => $filename,
            'alt'          => $alt,
            'filesize'     => $filesize,
            'seo_friendly' => $this->filler->is_seo_friendly($basename),
            'parent_id'    => $post->post_parent,
            'parent_title' => $parent_title,
            'thumbnail'    => $thumb_url ?: '',
            'date'         => $post->post_date,
            'edit_url'     => get_edit_post_link($attachment_id, 'raw') ?: '',
        ];
    }

    /**
     * Generate alt text for an attachment
     *
     * @param \WP_Post $post Attachment
     * @param string $strategy Strategy
     * @return string
     */
    private function generate_alt_for_attachment(\WP_Post $post, string $strategy): string
    {
        switch ($strategy) {
            case 'title':
                $title = $post->post_title;
                if (preg_match('/^[a-z0-9_-]+$/i', $title)) {
                    return ucwords(strtolower(str_replace(['-', '_'], ' ', $title)));
                }

                return $title;

            case 'parent':
                if ($post->post_parent > 0) {
                    $parent = get_post($post->post_parent);
                    if ($parent && !empty($parent->post_title)) {
                        return $parent->post_title;
                    }
                }
                // Fall through to filename
                // no break
            case 'filename':
            default:
                $file = get_attached_file($post->ID);
                if (!$file) {
                    return '';
                }
                $name = pathinfo(basename($file), PATHINFO_FILENAME);
                $text = str_replace(['-', '_'], ' ', $name);
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;

                return ucwords(strtolower(trim($text)));
        }
    }

    /**
     * Rename a single image to an SEO-friendly filename
     *
     * @param int $attachment_id Attachment ID
     * @return string 'renamed' | 'skipped' | 'error'
     */
    private function rename_single_image(int $attachment_id): string
    {
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return 'error';
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return 'error';
        }

        $filename  = basename($file);
        $basename  = pathinfo($filename, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Only process image types
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return 'skipped';
        }

        // Skip already SEO-friendly names
        if ($this->filler->is_seo_friendly($basename)) {
            return 'skipped';
        }

        // Generate new slug
        $slug = '';
        if ($post->post_parent > 0) {
            $parent = get_post($post->post_parent);
            if ($parent && !empty($parent->post_name)) {
                $slug = $parent->post_name;
            } elseif ($parent && !empty($parent->post_title)) {
                $slug = sanitize_title($parent->post_title);
            }
        }

        if (empty($slug)) {
            // Sanitize current filename to a clean slug
            $slug = sanitize_title(str_replace(['-', '_'], ' ', $basename));
            if (empty($slug)) {
                $slug = 'image';
            }
        }

        // Truncate
        if (mb_strlen($slug) > 50) {
            $slug      = mb_substr($slug, 0, 50);
            $last_dash = strrpos($slug, '-');
            if ($last_dash !== false && $last_dash > 10) {
                $slug = substr($slug, 0, $last_dash);
            }
        }

        // Find unique name
        $dir       = trailingslashit(dirname($file));
        $counter   = 1;
        $candidate = $slug . '-' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
        while (file_exists($dir . $candidate . '.' . $extension)) {
            ++$counter;
            $candidate = $slug . '-' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
            if ($counter > 999) {
                return 'error';
            }
        }

        $new_filename = $candidate . '.' . $extension;
        $new_filepath = $dir . $new_filename;

        // Rename physical file
        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wp_filesystem;

        if (!WP_Filesystem() || !$wp_filesystem || !$wp_filesystem->move($file, $new_filepath, true)) {
            return 'error';
        }

        // Update WordPress metadata
        $old_url = wp_get_attachment_url($attachment_id);
        update_attached_file($attachment_id, $new_filepath);

        // Regenerate metadata (thumbnails etc.)
        $metadata = wp_generate_attachment_metadata($attachment_id, $new_filepath);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Update URLs in parent post content
        $new_url = wp_get_attachment_url($attachment_id);
        if ($old_url && $new_url && $post->post_parent > 0) {
            $parent = get_post($post->post_parent);
            if ($parent && !empty($parent->post_content)) {
                $updated_content = str_replace($old_url, $new_url, $parent->post_content);

                // Also replace thumbnail URLs
                $old_base = pathinfo($old_url, PATHINFO_FILENAME);
                $new_base = pathinfo($new_url, PATHINFO_FILENAME);
                $old_dir  = pathinfo($old_url, PATHINFO_DIRNAME);
                $new_dir  = pathinfo($new_url, PATHINFO_DIRNAME);

                // Replace sized variants (e.g., image-300x200.jpg)
                $updated_content = preg_replace(
                    '/' . preg_quote($old_dir . '/' . $old_base, '/') . '-(\d+x\d+)\.' . preg_quote($extension, '/') . '/',
                    $new_dir . '/' . $new_base . '-$1.' . $extension,
                    $updated_content,
                ) ?? $updated_content;

                if ($updated_content !== $parent->post_content) {
                    wp_update_post(
                        [
                            'ID'           => $parent->ID,
                            'post_content' => $updated_content,
                        ],
                    );
                }
            }
        }

        return 'renamed';
    }
}
