<?php

/**
 * Resolved meta output data transfer object.
 *
 * @package SEOPulse\Modules\MetaSeo\Engine
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaOutput
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $ogTitle,
        public readonly string $ogDescription,
        public readonly string $ogImage,
        public readonly string $ogType,
        public readonly string $twitterTitle,
        public readonly string $twitterDescription,
        public readonly string $twitterImage,
        public readonly string $twitterCard,
        public readonly string $canonical,
        public readonly string $robots,
    ) {
    }

    /**
     * Return an empty MetaOutput (no values).
     */
    public static function empty(): self
    {
        return new self('', '', '', '', '', '', '', '', '', '', '', '');
    }

    /**
     * Convert to associative array (REST API / JSON).
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'title'               => $this->title,
            'description'         => $this->description,
            'og_title'            => $this->ogTitle,
            'og_description'      => $this->ogDescription,
            'og_image'            => $this->ogImage,
            'og_type'             => $this->ogType,
            'twitter_title'       => $this->twitterTitle,
            'twitter_description' => $this->twitterDescription,
            'twitter_image'       => $this->twitterImage,
            'twitter_card'        => $this->twitterCard,
            'canonical'           => $this->canonical,
            'robots'              => $this->robots,
        ];
    }
}
