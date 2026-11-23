<?php

/**
 * Advanced Schema.org catalog: field definitions, sanitization and per-post storage
 *
 * A single definition list drives the JSON-LD generators, the REST validation
 * and the generic editing form of the block editor panel.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

use SEOPulse\Core\Constants\PostMeta;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SchemaCatalog — definitions of the editable Schema.org types
 *
 * Field types: text, textarea, url, number, date, datetime, select, lines, repeater.
 */
final class SchemaCatalog
{
    private const MAX_TEXT     = 300;
    private const MAX_TEXTAREA = 3000;
    private const MAX_ROWS     = 50;

    /**
     * Memoized definitions
     *
     * @var array<string, array{label: string, fields: array<int, array<string, mixed>>}>|null
     */
    private static ?array $definitions = null;

    /**
     * All editable types with their fields
     *
     * @return array<string, array{label: string, fields: array<int, array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $currency = self::field('currency', __('Currency (ISO 4217)', 'seopulse'));
        $rating   = [
            self::field('rating_value', __('Rating value', 'seopulse'), 'number'),
            self::field('rating_count', __('Rating count', 'seopulse'), 'number'),
        ];

        $definitions = [
            'Article'             => [
                'label'  => __('Article', 'seopulse'),
                'fields' => [
                    self::field(
                        'subtype',
                        __('Article type', 'seopulse'),
                        'select',
                        [
                            'options' => [
                                'Article'     => __('Article', 'seopulse'),
                                'NewsArticle' => __('News article', 'seopulse'),
                                'BlogPosting' => __('Blog posting', 'seopulse'),
                            ],
                        ],
                    ),
                    self::field('headline', __('Headline (defaults to the title)', 'seopulse')),
                    self::field('description', __('Description', 'seopulse'), 'textarea'),
                    self::field('image', __('Image URL', 'seopulse'), 'url'),
                    self::field('author_name', __('Author name (defaults to the post author)', 'seopulse')),
                    self::field('author_url', __('Author URL', 'seopulse'), 'url'),
                ],
            ],
            'FAQPage'             => [
                'label'  => __('FAQ page', 'seopulse'),
                'fields' => [
                    self::field(
                        'items',
                        __('Questions', 'seopulse'),
                        'repeater',
                        [
                            'fields' => [
                                self::field('question', __('Question', 'seopulse'), 'text', ['required' => true]),
                                self::field('answer', __('Answer', 'seopulse'), 'textarea', ['required' => true]),
                            ],
                        ],
                    ),
                ],
            ],
            'HowTo'               => [
                'label'  => __('How-to', 'seopulse'),
                'fields' => [
                    self::field('name', __('Name (defaults to the title)', 'seopulse')),
                    self::field('description', __('Description', 'seopulse'), 'textarea'),
                    self::field('image', __('Image URL', 'seopulse'), 'url'),
                    self::field('total_time', __('Total time (minutes)', 'seopulse'), 'number'),
                    self::field(
                        'steps',
                        __('Steps', 'seopulse'),
                        'repeater',
                        [
                            'fields' => [
                                self::field('name', __('Step title', 'seopulse')),
                                self::field('text', __('Instructions', 'seopulse'), 'textarea', ['required' => true]),
                                self::field('image', __('Image URL', 'seopulse'), 'url'),
                            ],
                        ],
                    ),
                ],
            ],
            'Recipe'              => [
                'label'  => __('Recipe', 'seopulse'),
                'fields' => array_merge(
                    [
                        self::field('name', __('Name (defaults to the title)', 'seopulse')),
                        self::field('description', __('Description', 'seopulse'), 'textarea'),
                        self::field('image', __('Image URL', 'seopulse'), 'url'),
                        self::field('author_name', __('Author name', 'seopulse')),
                        self::field('prep_time', __('Preparation time (minutes)', 'seopulse'), 'number'),
                        self::field('cook_time', __('Cooking time (minutes)', 'seopulse'), 'number'),
                        self::field('yield', __('Yield (e.g. 4 servings)', 'seopulse')),
                        self::field('category', __('Category (e.g. dessert)', 'seopulse')),
                        self::field('cuisine', __('Cuisine (e.g. French)', 'seopulse')),
                        self::field('keywords', __('Keywords', 'seopulse')),
                        self::field('calories', __('Calories per serving (e.g. 270 calories)', 'seopulse')),
                        self::field('ingredients', __('Ingredients (one per line)', 'seopulse'), 'lines', ['required' => true]),
                        self::field('instructions', __('Instructions (one step per line)', 'seopulse'), 'lines'),
                    ],
                    $rating,
                ),
            ],
            'Event'               => [
                'label'  => __('Event', 'seopulse'),
                'fields' => [
                    self::field('name', __('Name (defaults to the title)', 'seopulse')),
                    self::field('description', __('Description', 'seopulse'), 'textarea'),
                    self::field('image', __('Image URL', 'seopulse'), 'url'),
                    self::field('start_date', __('Start date and time', 'seopulse'), 'datetime', ['required' => true]),
                    self::field('end_date', __('End date and time', 'seopulse'), 'datetime'),
                    self::field(
                        'status',
                        __('Status', 'seopulse'),
                        'select',
                        [
                            'options' => [
                                'EventScheduled'   => __('Scheduled', 'seopulse'),
                                'EventCancelled'   => __('Cancelled', 'seopulse'),
                                'EventPostponed'   => __('Postponed', 'seopulse'),
                                'EventRescheduled' => __('Rescheduled', 'seopulse'),
                                'EventMovedOnline' => __('Moved online', 'seopulse'),
                            ],
                        ],
                    ),
                    self::field(
                        'attendance_mode',
                        __('Attendance mode', 'seopulse'),
                        'select',
                        [
                            'options' => [
                                'OfflineEventAttendanceMode' => __('In person', 'seopulse'),
                                'OnlineEventAttendanceMode'  => __('Online', 'seopulse'),
                                'MixedEventAttendanceMode'   => __('Mixed', 'seopulse'),
                            ],
                        ],
                    ),
                    self::field('location_name', __('Venue name', 'seopulse')),
                    self::field('street', __('Street address', 'seopulse')),
                    self::field('city', __('City', 'seopulse')),
                    self::field('postal_code', __('Postal code', 'seopulse')),
                    self::field('country', __('Country (ISO 3166-1 alpha-2)', 'seopulse')),
                    self::field('online_url', __('Online event URL', 'seopulse'), 'url'),
                    self::field('organizer_name', __('Organizer', 'seopulse')),
                    self::field('price', __('Ticket price', 'seopulse'), 'number'),
                    $currency,
                    self::field('ticket_url', __('Ticket URL', 'seopulse'), 'url'),
                ],
            ],
            'Course'              => [
                'label'  => __('Course', 'seopulse'),
                'fields' => [
                    self::field('name', __('Name (defaults to the title)', 'seopulse')),
                    self::field('description', __('Description', 'seopulse'), 'textarea', ['required' => true]),
                    self::field('image', __('Image URL', 'seopulse'), 'url'),
                    self::field('course_code', __('Course code', 'seopulse')),
                    self::field('provider_name', __('Provider name', 'seopulse'), 'text', ['required' => true]),
                    self::field('provider_url', __('Provider URL', 'seopulse'), 'url'),
                    self::field('price', __('Price', 'seopulse'), 'number'),
                    $currency,
                ],
            ],
            'VideoObject'         => [
                'label'  => __('Video', 'seopulse'),
                'fields' => [
                    self::field('name', __('Name (defaults to the title)', 'seopulse')),
                    self::field('description', __('Description', 'seopulse'), 'textarea'),
                    self::field('thumbnail_url', __('Thumbnail URL', 'seopulse'), 'url', ['required' => true]),
                    self::field('upload_date', __('Upload date', 'seopulse'), 'date', ['required' => true]),
                    self::field('content_url', __('Video file URL', 'seopulse'), 'url'),
                    self::field('embed_url', __('Embed URL', 'seopulse'), 'url'),
                    self::field('duration', __('Duration (seconds)', 'seopulse'), 'number'),
                ],
            ],
            'SoftwareApplication' => [
                'label'  => __('Software application', 'seopulse'),
                'fields' => array_merge(
                    [
                        self::field('name', __('Name (defaults to the title)', 'seopulse')),
                        self::field('description', __('Description', 'seopulse'), 'textarea'),
                        self::field('image', __('Image URL', 'seopulse'), 'url'),
                        self::field(
                            'category',
                            __('Category', 'seopulse'),
                            'select',
                            [
                                'options' => [
                                    'BusinessApplication'      => __('Business', 'seopulse'),
                                    'DesignApplication'        => __('Design', 'seopulse'),
                                    'DeveloperApplication'     => __('Developer', 'seopulse'),
                                    'EducationalApplication'   => __('Educational', 'seopulse'),
                                    'GameApplication'          => __('Game', 'seopulse'),
                                    'MobileApplication'        => __('Mobile', 'seopulse'),
                                    'MultimediaApplication'    => __('Multimedia', 'seopulse'),
                                    'SecurityApplication'      => __('Security', 'seopulse'),
                                    'UtilitiesApplication'     => __('Utilities', 'seopulse'),
                                    'WebApplication'           => __('Web', 'seopulse'),
                                ],
                            ],
                        ),
                        self::field('operating_system', __('Operating system (e.g. Windows, Android)', 'seopulse')),
                        self::field('download_url', __('Download URL', 'seopulse'), 'url'),
                        self::field('price', __('Price (0 for free)', 'seopulse'), 'number'),
                        $currency,
                    ],
                    $rating,
                ),
            ],
        ];

        /**
         * Filters the editable Schema.org type definitions.
         *
         * @param array<string, array<string, mixed>> $definitions
         */
        self::$definitions = apply_filters('seopulse_schema_catalog', $definitions);

        return self::$definitions;
    }

    /**
     * Whether a type is editable
     *
     * @param string $type Schema.org type.
     *
     * @return bool
     */
    public static function has_type(string $type): bool
    {
        return isset(self::definitions()[ $type ]);
    }

    /**
     * Sanitize raw input against a type definition
     *
     * Unknown keys are dropped, every value is coerced to its field type.
     *
     * @param string $type Schema.org type.
     * @param array<string, mixed> $input Raw data.
     *
     * @return array<string, mixed>
     */
    public static function sanitize(string $type, array $input): array
    {
        $definition = self::definitions()[ $type ] ?? null;

        if ($definition === null) {
            return [];
        }

        return self::sanitize_fields($definition['fields'], $input);
    }

    /**
     * Stored configuration of a post
     *
     * @param int $post_id Post ID.
     *
     * @return array{type: string, data: array<string, mixed>}|null Null when nothing is configured.
     */
    public static function get(int $post_id): ?array
    {
        $stored = get_post_meta($post_id, PostMeta::SCHEMA_DATA, true);

        if (!is_array($stored) || !isset($stored['type']) || !is_string($stored['type']) || !self::has_type($stored['type'])) {
            return null;
        }

        $data = isset($stored['data']) && is_array($stored['data']) ? $stored['data'] : [];

        return [
            'type' => $stored['type'],
            'data' => self::sanitize($stored['type'], $data),
        ];
    }

    /**
     * Sanitize and store the configuration of a post
     *
     * An empty or unknown type removes the configuration.
     *
     * @param int $post_id Post ID.
     * @param string $type Schema.org type.
     * @param array<string, mixed> $data Raw data.
     *
     * @return array{type: string, data: array<string, mixed>}|null Stored value, null when removed.
     */
    public static function save(int $post_id, string $type, array $data): ?array
    {
        if (!self::has_type($type)) {
            delete_post_meta($post_id, PostMeta::SCHEMA_DATA);

            return null;
        }

        $value = [
            'type' => $type,
            'data' => self::sanitize($type, $data),
        ];

        update_post_meta($post_id, PostMeta::SCHEMA_DATA, $value);

        return $value;
    }

    /**
     * Type configured for the current singular request
     *
     * @return string|null
     */
    public static function current_type(): ?string
    {
        if (!is_singular()) {
            return null;
        }

        $post_id = (int) get_queried_object_id();

        if ($post_id <= 0) {
            return null;
        }

        $config = self::get($post_id);

        return $config['type'] ?? null;
    }

    /**
     * Whether the editor configuration replaces an automatic provider for the current request
     *
     * @param string ...$types Schema.org types produced by the automatic provider.
     *
     * @return bool
     */
    public static function overrides(string ...$types): bool
    {
        $current = self::current_type();

        return $current !== null && in_array($current, $types, true);
    }

    /**
     * Build a field definition
     *
     * @param string $key Field key.
     * @param string $label Translated label.
     * @param string $type Field type.
     * @param array<string, mixed> $extra Extra attributes (options, fields, required).
     *
     * @return array<string, mixed>
     */
    private static function field(string $key, string $label, string $type = 'text', array $extra = []): array
    {
        return array_merge(
            [
                'key'      => $key,
                'label'    => $label,
                'type'     => $type,
                'required' => false,
            ],
            $extra,
        );
    }

    /**
     * Sanitize a set of fields
     *
     * @param array<int, array<string, mixed>> $fields Definitions.
     * @param array<string, mixed> $input Raw values.
     *
     * @return array<string, mixed>
     */
    private static function sanitize_fields(array $fields, array $input): array
    {
        $clean = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $raw = $input[ $key ] ?? null;

            $clean[ $key ] = self::sanitize_value($field, $raw);
        }

        return $clean;
    }

    /**
     * Coerce a single value to its field type
     *
     * @param array<string, mixed> $field Field definition.
     * @param mixed $value Raw value.
     *
     * @return string|array<int|string, mixed>
     */
    private static function sanitize_value(array $field, $value)
    {
        $type = (string) $field['type'];

        if ($type === 'repeater') {
            return self::sanitize_rows($field, $value);
        }

        if ($type === 'lines') {
            return self::sanitize_lines($value);
        }

        if (!is_scalar($value)) {
            return '';
        }

        $text = (string) $value;

        switch ($type) {
            case 'textarea':
                return mb_substr(sanitize_textarea_field($text), 0, self::MAX_TEXTAREA);

            case 'url':
                return esc_url_raw(trim($text), ['http', 'https']);

            case 'number':
                if (!is_numeric($text) || (float) $text < 0) {
                    return '';
                }

                return (string) round((float) $text, 2);

            case 'date':
                return self::valid_date($text) ? $text : '';

            case 'datetime':
                return self::valid_datetime($text) ? $text : '';

            case 'select':
                $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];

                return array_key_exists($text, $options) ? $text : '';

            default:
                return mb_substr(sanitize_text_field($text), 0, self::MAX_TEXT);
        }
    }

    /**
     * Sanitize a list of lines
     *
     * @param mixed $value Raw array or newline separated string.
     *
     * @return array<int, string>
     */
    private static function sanitize_lines($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $lines = [];

        foreach ($value as $line) {
            if (!is_scalar($line)) {
                continue;
            }

            $line = mb_substr(sanitize_text_field((string) $line), 0, self::MAX_TEXT);

            if ($line !== '') {
                $lines[] = $line;
            }

            if (count($lines) >= self::MAX_ROWS) {
                break;
            }
        }

        return $lines;
    }

    /**
     * Sanitize repeater rows
     *
     * @param array<string, mixed> $field Repeater definition.
     * @param mixed $value Raw rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_rows(array $field, $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $sub_fields = isset($field['fields']) && is_array($field['fields']) ? $field['fields'] : [];
        $rows       = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $clean = self::sanitize_fields($sub_fields, $row);

            if (implode('', array_map('strval', $clean)) === '') {
                continue;
            }

            $rows[] = $clean;

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Strict Y-m-d check
     *
     * @param string $value Date.
     *
     * @return bool
     */
    private static function valid_date(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Strict Y-m-dTH:i check
     *
     * @param string $value Date-time.
     *
     * @return bool
     */
    private static function valid_datetime(string $value): bool
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return self::valid_date($m[1]) && (int) $m[2] < 24 && (int) $m[3] < 60;
    }
}
