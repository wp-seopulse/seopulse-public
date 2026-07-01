<?php

/**
 * JSON-LD schema validator
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
 * SchemaValidator — validates JSON-LD schema structure
 */
class SchemaValidator
{
    /**
     * Last validation error
     *
     * @var string|null
     */
    private ?string $error = null;

    /**
     * Validate schema array structure and required fields
     *
     * @param array<string, mixed> $schema Schema to validate
     *
     * @return bool True if valid
     */
    public function validate(array $schema): bool
    {
        $this->error = null;

        // Required top-level fields
        if (empty($schema['@context'])) {
            $this->error = 'Missing @context field';

            return false;
        }

        // Handle @graph structures (e.g., WebSite + Organization combo)
        if (!empty($schema['@graph']) && is_array($schema['@graph'])) {
            foreach ($schema['@graph'] as $item) {
                if (!empty($item['@type']) && !$this->validate_type_specific($item)) {
                    return false;
                }
            }

            return true;
        }

        if (empty($schema['@type'])) {
            $this->error = 'Missing @type field';

            return false;
        }

        // @context should be a string (usually https://schema.org)
        if (!is_string($schema['@context']) && !is_array($schema['@context'])) {
            $this->error = '@context must be string or array';

            return false;
        }

        // @type should be a string
        if (!is_string($schema['@type'])) {
            $this->error = '@type must be string';

            return false;
        }

        // Validate specific schema types (optional, extensible)
        return $this->validate_type_specific($schema);
    }

    /**
     * Validate type-specific required fields
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_type_specific(array $schema): bool
    {
        $type = (string) $schema['@type'];

        return match ($type) {
            'BlogPosting', 'Article' => $this->validate_article($schema),
            'WebPage'                => $this->validate_webpage($schema),
            'WebSite'                => $this->validate_website($schema),
            'Organization'           => $this->validate_organization($schema),
            'LocalBusiness'          => $this->validate_local_business($schema),
            'FAQPage'                => $this->validate_faq_page($schema),
            'Product'                => $this->validate_product($schema),
            'Event'                  => $this->validate_event($schema),
            'HowTo'                  => $this->validate_howto($schema),
            default                  => true, // Allow unknown types
        };
    }

    /**
     * Validate Article/BlogPosting schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_article(array $schema): bool
    {
        if (empty($schema['headline'])) {
            $this->error = 'Article requires headline';

            return false;
        }

        if (empty($schema['datePublished'])) {
            $this->error = 'Article requires datePublished';

            return false;
        }

        return true;
    }

    /**
     * Validate WebPage schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_webpage(array $schema): bool
    {
        if (empty($schema['name'])) {
            $this->error = 'WebPage requires name';

            return false;
        }

        return true;
    }

    /**
     * Validate WebSite schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_website(array $schema): bool
    {
        if (empty($schema['url'])) {
            $this->error = 'WebSite requires url';

            return false;
        }

        if (empty($schema['name'])) {
            $this->error = 'WebSite requires name';

            return false;
        }

        return true;
    }

    /**
     * Validate Organization schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_organization(array $schema): bool
    {
        if (empty($schema['name'])) {
            $this->error = 'Organization requires name';

            return false;
        }

        return true;
    }

    /**
     * Validate LocalBusiness schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_local_business(array $schema): bool
    {
        if (empty($schema['name'])) {
            $this->error = 'LocalBusiness requires name';

            return false;
        }

        if (empty($schema['address'])) {
            $this->error = 'LocalBusiness requires address';

            return false;
        }

        return true;
    }

    /**
     * Validate FAQPage schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_faq_page(array $schema): bool
    {
        if (empty($schema['mainEntity']) || !is_array($schema['mainEntity'])) {
            $this->error = 'FAQPage requires mainEntity array';

            return false;
        }

        if (empty($schema['mainEntity'][0]['@type']) || $schema['mainEntity'][0]['@type'] !== 'Question') {
            $this->error = 'FAQPage mainEntity must contain Question items';

            return false;
        }

        return true;
    }

    /**
     * Validate Event schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_event(array $schema): bool
    {
        if (empty($schema['name'])) {
            $this->error = 'Event requires name';

            return false;
        }

        if (empty($schema['startDate'])) {
            $this->error = 'Event requires startDate';

            return false;
        }

        // Location should have either a name or address
        if (!empty($schema['location'])) {
            $loc = $schema['location'];
            if (empty($loc['name']) && empty($loc['address'])) {
                $this->error = 'Event location must have name or address';

                return false;
            }
        }

        return true;
    }

    /**
     * Validate HowTo schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_howto(array $schema): bool
    {
        if (empty($schema['name'])) {
            $this->error = 'HowTo requires name';

            return false;
        }

        if (empty($schema['step']) || !is_array($schema['step'])) {
            $this->error = 'HowTo requires step array';

            return false;
        }

        if (count($schema['step']) < 3) {
            $this->error = 'HowTo requires at least 3 steps';

            return false;
        }

        // Verify steps have name or text
        foreach ($schema['step'] as $step) {
            if (empty($step['name']) && empty($step['text'])) {
                $this->error = 'Each HowTo step must have name or text';

                return false;
            }
        }

        return true;
    }

    /**
     * Validate Product schema
     *
     * @param array<string, mixed> $schema Schema array
     *
     * @return bool
     */
    private function validate_product(array $schema): bool
    {
        if (empty($schema['name'])) {
            $this->error = 'Product requires name';

            return false;
        }

        if (empty($schema['image'])) {
            $this->error = 'Product requires at least one image';

            return false;
        }

        if (empty($schema['offers'])) {
            $this->error = 'Product requires offers with price';

            return false;
        }

        // Validate offers price and currency
        $offers = $schema['offers'];
        if (empty($offers['price']) || empty($offers['priceCurrency'])) {
            $this->error = 'Product offers must include price and priceCurrency';

            return false;
        }

        return true;
    }

    /**
     * Get last validation error
     *
     * @return string|null
     */
    public function get_error(): ?string
    {
        return $this->error;
    }
}
