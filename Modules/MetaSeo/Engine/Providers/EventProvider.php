<?php

/**
 * Event schema provider for JSON-LD
 *
 * Supports The Events Calendar (tribe_events), generic 'event' CPT,
 * and future 'seopulse_event' CPT with deterministic meta mapping.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EventProvider — generates Event schema for event post types
 */
final class EventProvider implements SchemaProvider
{
    /**
     * Last error message
     *
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Meta key mapping (schema field => list of meta keys to try in order)
     *
     * @var array<string, array<string>>
     */
    private const META_MAP = [
        'start_date'  => ['_EventStartDate', 'event_start_date'],
        'end_date'    => ['_EventEndDate', 'event_end_date'],
        'venue_name'  => ['_VenueName', 'event_location_name'],
        'street'      => ['_VenueAddress', 'event_street'],
        'city'        => ['_VenueCity', 'event_city'],
        'postal_code' => ['_VenueZip', 'event_postcode'],
        'country'     => ['_VenueCountry', 'event_country'],
        'organizer'   => ['_OrganizerOrganizer', 'event_organizer'],
    ];

    /**
     * Get the schema type
     *
     * @return string
     */
    public function get_type(): string
    {
        return 'Event';
    }

    /**
     * Check if this provider should inject on the current request
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        if (!is_singular()) {
            return false;
        }

        // Check admin toggle (default: enabled)
        $settings = get_option('seopulse_meta_seo_global', []);
        if (isset($settings['schema_event_enabled']) && !$settings['schema_event_enabled']) {
            return false;
        }

        global $post;
        if (!$post instanceof \WP_Post) {
            return false;
        }

        $supported = $this->get_supported_post_types();

        if (!in_array($post->post_type, $supported, true)) {
            return false;
        }

        // Must have a start date to emit valid Event schema
        $start_date = $this->resolve_meta($post->ID, 'start_date');

        return !empty($start_date);
    }

    /**
     * Build the Event schema
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        global $post;

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $post_id    = $post->ID;
        $start_date = $this->resolve_meta($post_id, 'start_date');

        if (empty($start_date)) {
            return [];
        }

        $schema = [
            '@context'  => 'https://schema.org',
            '@type'     => 'Event',
            'name'      => get_the_title($post),
            'startDate' => $this->format_date($start_date),
            'url'       => get_permalink($post),
        ];

        // Description
        $description = $this->get_description($post);
        if (!empty($description)) {
            $schema['description'] = $description;
        }

        // End date
        $end_date = $this->resolve_meta($post_id, 'end_date');
        if (!empty($end_date)) {
            $schema['endDate'] = $this->format_date($end_date);
        }

        // Image
        $image = $this->get_image($post);
        if (!empty($image)) {
            $schema['image'] = $image;
        }

        // Location
        $location = $this->build_location($post_id);
        if (!empty($location)) {
            $schema['location'] = $location;
        }

        // Organizer
        $organizer = $this->resolve_meta($post_id, 'organizer');
        if (!empty($organizer)) {
            $schema['organizer'] = [
                '@type' => 'Organization',
                'name'  => $organizer,
            ];
        }

        // Event status (default: scheduled)
        $schema['eventStatus'] = 'https://schema.org/EventScheduled';

        // Attendance mode (default: offline)
        $schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';

        return $schema;
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        $schema = $this->build();

        if (empty($schema)) {
            $this->error = 'Schema is empty';

            return false;
        }

        if (empty($schema['name'])) {
            $this->error = 'Missing event name';

            return false;
        }

        if (empty($schema['startDate'])) {
            $this->error = 'Missing startDate';

            return false;
        }

        return true;
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error;
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    /**
     * Get supported event post types
     *
     * @return array<string>
     */
    private function get_supported_post_types(): array
    {
        $defaults = ['tribe_events', 'event', 'seopulse_event'];

        /** @var array<string> */
        return apply_filters('seopulse_event_supported_post_types', $defaults);
    }

    /**
     * Resolve a meta value using the fallback mapping
     *
     * @param int $post_id
     * @param string $field Key in META_MAP
     * @return string
     */
    private function resolve_meta(int $post_id, string $field): string
    {
        $keys = self::META_MAP[ $field ] ?? [];

        foreach ($keys as $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (!empty($value)) {
                return sanitize_text_field((string) $value);
            }
        }

        // For The Events Calendar venue/organizer: linked post pattern
        if ($field === 'venue_name') {
            return $this->resolve_tec_venue($post_id);
        }

        if ($field === 'organizer') {
            return $this->resolve_tec_organizer($post_id);
        }

        return '';
    }

    /**
     * Resolve venue name from The Events Calendar linked venue post
     *
     * @param int $post_id
     * @return string
     */
    private function resolve_tec_venue(int $post_id): string
    {
        $venue_id = (int) get_post_meta($post_id, '_EventVenueID', true);

        if ($venue_id > 0) {
            $venue = get_post($venue_id);
            if ($venue instanceof \WP_Post) {
                return $venue->post_title;
            }
        }

        return '';
    }

    /**
     * Resolve organizer name from The Events Calendar linked organizer post
     *
     * @param int $post_id
     * @return string
     */
    private function resolve_tec_organizer(int $post_id): string
    {
        $organizer_id = (int) get_post_meta($post_id, '_EventOrganizerID', true);

        if ($organizer_id > 0) {
            $organizer = get_post($organizer_id);
            if ($organizer instanceof \WP_Post) {
                return $organizer->post_title;
            }
        }

        return '';
    }

    /**
     * Get event description
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_description(\WP_Post $post): string
    {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        $content = wp_strip_all_tags($post->post_content);

        if (empty($content)) {
            return '';
        }

        return wp_trim_words($content, 50, '…');
    }

    /**
     * Get featured image
     *
     * @param \WP_Post $post
     * @return string
     */
    private function get_image(\WP_Post $post): string
    {
        $url = get_the_post_thumbnail_url($post, 'large');

        return $url ? (string) $url : '';
    }

    /**
     * Build the Place location schema
     *
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function build_location(int $post_id): array
    {
        $venue_name = $this->resolve_meta($post_id, 'venue_name');
        $street     = $this->resolve_meta($post_id, 'street');
        $city       = $this->resolve_meta($post_id, 'city');
        $postal     = $this->resolve_meta($post_id, 'postal_code');
        $country    = $this->resolve_meta($post_id, 'country');

        // Need at least a venue name or some address fields
        if (empty($venue_name) && empty($street) && empty($city)) {
            return [];
        }

        $location = [
            '@type' => 'Place',
        ];

        if (!empty($venue_name)) {
            $location['name'] = $venue_name;
        }

        // Build address if any fields present
        if (!empty($street) || !empty($city) || !empty($postal) || !empty($country)) {
            $address = [
                '@type' => 'PostalAddress',
            ];

            if (!empty($street)) {
                $address['streetAddress'] = $street;
            }
            if (!empty($city)) {
                $address['addressLocality'] = $city;
            }
            if (!empty($postal)) {
                $address['postalCode'] = $postal;
            }
            if (!empty($country)) {
                $address['addressCountry'] = $country;
            }

            $location['address'] = $address;
        }

        return $location;
    }

    /**
     * Format a date string to ISO 8601
     *
     * @param string $date
     * @return string
     */
    private function format_date(string $date): string
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return $date;
        }

        return wp_date('c', $timestamp) ?: $date;
    }
}
