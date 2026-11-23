<?php

/**
 * Editor-driven Schema.org provider (Article, FAQPage, HowTo, Recipe, Event, Course, VideoObject, SoftwareApplication)
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
 * AdvancedSchemaProvider — builds the JSON-LD of the type chosen in the editor panel
 */
final class AdvancedSchemaProvider implements SchemaProvider
{
    private ?string $error = null;

    /**
     * Get the schema type
     *
     * @return string
     */
    public function get_type(): string
    {
        return SchemaCatalog::current_type() ?? 'Thing';
    }

    /**
     * Inject only when an editor configuration exists and no schema block already prints the same type
     *
     * @return bool
     */
    public function should_inject(): bool
    {
        $type = SchemaCatalog::current_type();

        if ($type === null) {
            return false;
        }

        $post = get_queried_object();

        if (!$post instanceof \WP_Post) {
            return false;
        }

        return !SchemaBlockDetector::has_type((string) $post->post_content, $type);
    }

    /**
     * Build the schema of the current post
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $post = get_queried_object();

        if (!$post instanceof \WP_Post) {
            return [];
        }

        $config = SchemaCatalog::get($post->ID);

        if ($config === null) {
            return [];
        }

        return $this->build_for($post, $config['type'], $config['data']);
    }

    /**
     * Build the schema of a given post and type (also used by the REST preview)
     *
     * @param \WP_Post $post Post.
     * @param string $type Schema.org type.
     * @param array<string, mixed> $data Sanitized field values.
     *
     * @return array<string, mixed> Empty when mandatory data is missing.
     */
    public function build_for(\WP_Post $post, string $type, array $data): array
    {
        $this->error = null;

        $node = match ($type) {
            'Article'             => $this->article($post, $data),
            'FAQPage'             => $this->faq($data),
            'HowTo'               => $this->howto($post, $data),
            'Recipe'              => $this->recipe($post, $data),
            'Event'               => $this->event($post, $data),
            'Course'              => $this->course($post, $data),
            'VideoObject'         => $this->video($post, $data),
            'SoftwareApplication' => $this->software($post, $data),
            default               => [],
        };

        if ($node === []) {
            $this->error = 'Missing required data for ' . $type;

            return [];
        }

        $schema = array_merge(['@context' => 'https://schema.org'], $node);

        /**
         * Filters an editor-driven Schema.org node.
         *
         * @param array<string, mixed> $schema JSON-LD node.
         * @param string $type Schema.org type.
         * @param \WP_Post $post Post.
         */
        $filtered = apply_filters('seopulse_advanced_schema', $schema, $type, $post);

        return is_array($filtered) ? $filtered : [];
    }

    /**
     * Validate the schema
     *
     * @return bool
     */
    public function validate(): bool
    {
        return $this->build() !== [];
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
    // GENERATORS
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function article(\WP_Post $post, array $d): array
    {
        $headline = $this->str($d, 'headline') ?: get_the_title($post);

        if ($headline === '') {
            return [];
        }

        $author_name = $this->str($d, 'author_name') ?: (string) get_the_author_meta('display_name', (int) $post->post_author);
        $author_url  = $this->str($d, 'author_url') ?: (string) get_author_posts_url((int) $post->post_author);

        $author = ['@type' => 'Person', 'name' => $author_name];
        if ($author_url !== '') {
            $author['url'] = $author_url;
        }

        $node = [
            '@type'            => $this->str($d, 'subtype') ?: 'Article',
            'headline'         => mb_substr($headline, 0, 110),
            'description'      => $this->str($d, 'description') ?: $this->excerpt($post),
            'datePublished'    => wp_date('c', (int) strtotime($post->post_date_gmt . ' UTC')),
            'dateModified'     => wp_date('c', (int) strtotime($post->post_modified_gmt . ' UTC')),
            'author'           => $author,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_permalink($post)],
        ];

        $image = $this->str($d, 'image') ?: $this->featured_image($post);
        if ($image !== '') {
            $node['image'] = $image;
        }

        $publisher = OrgSchemaProvider::reference();
        if ($publisher !== null) {
            $node['publisher'] = $publisher;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function faq(array $d): array
    {
        $entities = [];

        foreach ($this->rows($d, 'items') as $row) {
            $question = $this->str($row, 'question');
            $answer   = $this->str($row, 'answer');

            if ($question === '' || $answer === '') {
                continue;
            }

            $entities[] = [
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($entities === []) {
            return [];
        }

        return ['@type' => 'FAQPage', 'mainEntity' => $entities];
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function howto(\WP_Post $post, array $d): array
    {
        $steps = [];

        foreach ($this->rows($d, 'steps') as $row) {
            $text = $this->str($row, 'text');

            if ($text === '') {
                continue;
            }

            $step = [
                '@type' => 'HowToStep',
                'name'  => $this->str($row, 'name') ?: $text,
                'text'  => $text,
            ];

            $image = $this->str($row, 'image');
            if ($image !== '') {
                $step['image'] = $image;
            }

            $steps[] = $step;
        }

        $name = $this->str($d, 'name') ?: get_the_title($post);

        if ($steps === [] || $name === '') {
            return [];
        }

        $node = ['@type' => 'HowTo', 'name' => $name, 'step' => $steps];

        $this->put($node, 'description', $this->str($d, 'description'));
        $this->put($node, 'image', $this->str($d, 'image'));
        $this->put($node, 'totalTime', $this->minutes($this->str($d, 'total_time')));

        return $node;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function recipe(\WP_Post $post, array $d): array
    {
        $name        = $this->str($d, 'name') ?: get_the_title($post);
        $ingredients = $this->lines($d, 'ingredients');

        if ($name === '' || $ingredients === []) {
            return [];
        }

        $node = [
            '@type'            => 'Recipe',
            'name'             => $name,
            'recipeIngredient' => $ingredients,
            'datePublished'    => wp_date('c', (int) strtotime($post->post_date_gmt . ' UTC')),
        ];

        $this->put($node, 'description', $this->str($d, 'description') ?: $this->excerpt($post));
        $this->put($node, 'image', $this->str($d, 'image') ?: $this->featured_image($post));
        $this->put($node, 'prepTime', $this->minutes($this->str($d, 'prep_time')));
        $this->put($node, 'cookTime', $this->minutes($this->str($d, 'cook_time')));
        $this->put($node, 'recipeYield', $this->str($d, 'yield'));
        $this->put($node, 'recipeCategory', $this->str($d, 'category'));
        $this->put($node, 'recipeCuisine', $this->str($d, 'cuisine'));
        $this->put($node, 'keywords', $this->str($d, 'keywords'));

        $total = (float) $this->str($d, 'prep_time') + (float) $this->str($d, 'cook_time');
        if ($total > 0) {
            $node['totalTime'] = $this->minutes((string) $total);
        }

        $author = $this->str($d, 'author_name');
        if ($author !== '') {
            $node['author'] = ['@type' => 'Person', 'name' => $author];
        }

        $calories = $this->str($d, 'calories');
        if ($calories !== '') {
            $node['nutrition'] = ['@type' => 'NutritionInformation', 'calories' => $calories];
        }

        $steps = [];
        foreach ($this->lines($d, 'instructions') as $text) {
            $steps[] = ['@type' => 'HowToStep', 'text' => $text];
        }
        if ($steps !== []) {
            $node['recipeInstructions'] = $steps;
        }

        $this->rating($node, $d);

        return $node;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function event(\WP_Post $post, array $d): array
    {
        $name  = $this->str($d, 'name') ?: get_the_title($post);
        $start = $this->datetime($this->str($d, 'start_date'));

        if ($name === '' || $start === '') {
            return [];
        }

        $mode = $this->str($d, 'attendance_mode') ?: 'OfflineEventAttendanceMode';

        $node = [
            '@type'               => 'Event',
            'name'                => $name,
            'startDate'           => $start,
            'eventStatus'         => 'https://schema.org/' . ($this->str($d, 'status') ?: 'EventScheduled'),
            'eventAttendanceMode' => 'https://schema.org/' . $mode,
            'url'                 => get_permalink($post),
        ];

        $this->put($node, 'description', $this->str($d, 'description') ?: $this->excerpt($post));
        $this->put($node, 'image', $this->str($d, 'image') ?: $this->featured_image($post));
        $this->put($node, 'endDate', $this->datetime($this->str($d, 'end_date')));

        $locations = [];
        if ($mode !== 'OnlineEventAttendanceMode') {
            $place = $this->place($d);
            if ($place !== []) {
                $locations[] = $place;
            }
        }
        if ($mode !== 'OfflineEventAttendanceMode' && $this->str($d, 'online_url') !== '') {
            $locations[] = ['@type' => 'VirtualLocation', 'url' => $this->str($d, 'online_url')];
        }
        if ($locations !== []) {
            $node['location'] = count($locations) === 1 ? $locations[0] : $locations;
        }

        $organizer = $this->str($d, 'organizer_name');
        if ($organizer !== '') {
            $node['organizer'] = ['@type' => 'Organization', 'name' => $organizer];
        }

        $offer = $this->offer($d, $this->str($d, 'ticket_url'));
        if ($offer !== []) {
            $node['offers'] = $offer;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function course(\WP_Post $post, array $d): array
    {
        $name        = $this->str($d, 'name') ?: get_the_title($post);
        $description = $this->str($d, 'description');
        $provider    = $this->str($d, 'provider_name');

        if ($name === '' || $description === '' || $provider === '') {
            return [];
        }

        $organization = ['@type' => 'Organization', 'name' => $provider];
        $this->put($organization, 'sameAs', $this->str($d, 'provider_url'));

        $node = [
            '@type'       => 'Course',
            'name'        => $name,
            'description' => $description,
            'provider'    => $organization,
            'url'         => get_permalink($post),
        ];

        $this->put($node, 'courseCode', $this->str($d, 'course_code'));
        $this->put($node, 'image', $this->str($d, 'image') ?: $this->featured_image($post));

        $offer = $this->offer($d, '');
        if ($offer !== []) {
            $node['offers'] = $offer;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function video(\WP_Post $post, array $d): array
    {
        $name      = $this->str($d, 'name') ?: get_the_title($post);
        $thumbnail = $this->str($d, 'thumbnail_url');
        $uploaded  = $this->str($d, 'upload_date');

        if ($name === '' || $thumbnail === '' || $uploaded === '') {
            return [];
        }

        $node = [
            '@type'        => 'VideoObject',
            'name'         => $name,
            'description'  => $this->str($d, 'description') ?: ($this->excerpt($post) ?: $name),
            'thumbnailUrl' => [$thumbnail],
            'uploadDate'   => $uploaded,
        ];

        $this->put($node, 'contentUrl', $this->str($d, 'content_url'));
        $this->put($node, 'embedUrl', $this->str($d, 'embed_url'));

        $seconds = (int) round((float) $this->str($d, 'duration'));
        if ($seconds > 0) {
            $node['duration'] = sprintf('PT%dH%dM%dS', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function software(\WP_Post $post, array $d): array
    {
        $name = $this->str($d, 'name') ?: get_the_title($post);

        if ($name === '') {
            return [];
        }

        $node = ['@type' => 'SoftwareApplication', 'name' => $name];

        $this->put($node, 'description', $this->str($d, 'description') ?: $this->excerpt($post));
        $this->put($node, 'image', $this->str($d, 'image') ?: $this->featured_image($post));
        $this->put($node, 'applicationCategory', $this->str($d, 'category'));
        $this->put($node, 'operatingSystem', $this->str($d, 'operating_system'));
        $this->put($node, 'downloadUrl', $this->str($d, 'download_url'));

        $offer = $this->offer($d, '');
        if ($offer !== []) {
            $node['offers'] = $offer;
        }

        $this->rating($node, $d);

        return $node;
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data Data.
     */
    private function str(array $data, string $key): string
    {
        $value = $data[ $key ] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data Data.
     * @return array<int, string>
     */
    private function lines(array $data, string $key): array
    {
        $value = $data[ $key ] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $data Data.
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $value = $data[ $key ] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * Set a key only when the value is not empty
     *
     * @param array<string, mixed> $node Node.
     */
    private function put(array &$node, string $key, string $value): void
    {
        if ($value !== '') {
            $node[ $key ] = $value;
        }
    }

    /**
     * Minutes to ISO 8601 duration
     */
    private function minutes(string $value): string
    {
        $minutes = (int) round((float) $value);

        if ($minutes <= 0) {
            return '';
        }

        return sprintf('PT%dH%dM', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Form date-time (Y-m-dTH:i, site timezone) to ISO 8601
     */
    private function datetime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value, wp_timezone()))->format('c');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function place(array $d): array
    {
        $address = ['@type' => 'PostalAddress'];
        $this->put($address, 'streetAddress', $this->str($d, 'street'));
        $this->put($address, 'addressLocality', $this->str($d, 'city'));
        $this->put($address, 'postalCode', $this->str($d, 'postal_code'));
        $this->put($address, 'addressCountry', $this->str($d, 'country'));

        $name = $this->str($d, 'location_name');

        if ($name === '' && count($address) === 1) {
            return [];
        }

        $place = ['@type' => 'Place', 'name' => $name !== '' ? $name : $this->str($d, 'city')];

        if (count($address) > 1) {
            $place['address'] = $address;
        }

        return $place;
    }

    /**
     * @param array<string, mixed> $d Data.
     * @return array<string, mixed>
     */
    private function offer(array $d, string $url): array
    {
        $price = $this->str($d, 'price');

        if ($price === '') {
            return [];
        }

        $offer = [
            '@type'         => 'Offer',
            'price'         => $price,
            'priceCurrency' => strtoupper($this->str($d, 'currency') ?: 'EUR'),
        ];
        $this->put($offer, 'url', $url);

        return $offer;
    }

    /**
     * @param array<string, mixed> $node Node.
     * @param array<string, mixed> $d Data.
     */
    private function rating(array &$node, array $d): void
    {
        $value = $this->str($d, 'rating_value');
        $count = $this->str($d, 'rating_count');

        if ($value === '' || $count === '') {
            return;
        }

        $node['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $value,
            'ratingCount' => (int) $count,
        ];
    }

    private function excerpt(\WP_Post $post): string
    {
        if ($post->post_excerpt !== '') {
            return wp_strip_all_tags($post->post_excerpt);
        }

        return wp_trim_words(wp_strip_all_tags($post->post_content), 30, '…');
    }

    private function featured_image(\WP_Post $post): string
    {
        $url = get_the_post_thumbnail_url($post, 'large');

        return is_string($url) ? $url : '';
    }
}
