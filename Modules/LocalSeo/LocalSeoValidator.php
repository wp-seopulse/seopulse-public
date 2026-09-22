<?php

/**
 * Validation and sanitization for Local SEO
 *
 * @package SEOPulse\Modules\LocalSeo
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\LocalSeo;

/**
 * LocalSeoValidator class
 */
class LocalSeoValidator
{
    /**
     * Maximum number of @type values that can be combined for a single
     * entity. Google's own multi-type example combines three
     * (`["Electrician", "Plumber", "Locksmith"]`); this keeps the schema
     * meaningfully specific rather than letting every category be selected.
     *
     * @var int
     */
    private const MAX_TYPES = 3;

    /**
     * Validates and sanitizes settings
     *
     * @param array $input Raw data
     * @return array Validated data
     */
    public static function sanitize_settings($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        // Build JSON-LD Schema.org
        // Accept either `type` or `@type` as the input key: the settings
        // screen's React state mirrors the JSON-LD shape directly (see
        // IdentityTab.tsx / App.tsx, which read and write `settings["@type"]`
        // and send it back as-is), while the WordPress Settings API form
        // historically posted a flat `type` field. Supporting both keeps
        // this validator correct regardless of which REST/admin path calls it.
        $types = self::sanitize_type($input['type'] ?? $input['@type'] ?? 'LocalBusiness');

        $jsonld = [
            '@context' => 'https://schema.org',
            // Google supports (and recommends, when a business genuinely spans
            // more than one category) declaring @type as an array instead of
            // a single string — e.g. `["MedicalBusiness", "Physician"]`.
            // See https://developers.google.com/search/docs/appearance/structured-data/local-business
            '@type'    => count($types) === 1 ? $types[0] : $types,
        ];

        // Simple fields
        $simple_fields = [
            'name',
            'alternateName',
            'description',
            'url',
            'image',
            'telephone',
            'founder',
            'faxNumber',
            'slogan',
            'paymentAccepted',
            'currenciesAccepted',
        ];

        foreach ($simple_fields as $field) {
            if (!empty($input[ $field ])) {
                $value = $field === 'description'
                    ? sanitize_textarea_field($input[ $field ])
                    : ($field === 'url' || $field === 'image'
                        ? esc_url_raw($input[ $field ])
                        : sanitize_text_field($input[ $field ]));

                if (!empty($value)) {
                    $jsonld[ $field ] = $value;
                }
            }
        }

        // Email (sanitize separately)
        if (!empty($input['email'])) {
            $email = sanitize_email($input['email']);
            if (!empty($email)) {
                $jsonld['email'] = $email;
            }
        }

        // Logo (URL field, separate from image)
        if (!empty($input['logo'])) {
            $logo = esc_url_raw($input['logo']);
            if (!empty($logo)) {
                $jsonld['logo'] = $logo;
            }
        }

        // Founding date (YYYY-MM-DD)
        if (!empty($input['foundingDate'])) {
            $date = sanitize_text_field($input['foundingDate']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $jsonld['foundingDate'] = $date;
            }
        }

        // Map URL
        if (!empty($input['hasMap'])) {
            $map_url = esc_url_raw($input['hasMap']);
            if (!empty($map_url)) {
                $jsonld['hasMap'] = $map_url;
            }
        }

        // Number of employees
        if (!empty($input['numberOfEmployees'])) {
            $employees = intval($input['numberOfEmployees']);
            if ($employees > 0) {
                $jsonld['numberOfEmployees'] = [
                    '@type' => 'QuantitativeValue',
                    'value' => $employees,
                ];
            }
        }

        // Keywords (Google expects a string)
        if (!empty($input['keywords'])) {
            $keywords = array_filter(array_map('trim', explode(',', sanitize_text_field($input['keywords']))));
            if (!empty($keywords)) {
                $jsonld['keywords'] = implode(', ', $keywords);
            }
        }

        // Address
        if (!empty($input['address']) && is_array($input['address'])) {
            $address = [
                '@type' => 'PostalAddress',
            ];

            $address_fields = ['streetAddress', 'postalCode', 'addressLocality', 'addressRegion', 'addressCountry'];
            foreach ($address_fields as $field) {
                if (!empty($input['address'][ $field ])) {
                    $address[ $field ] = sanitize_text_field($input['address'][ $field ]);
                }
            }

            if (count($address) > 1) {
                $jsonld['address'] = $address;
            }
        }

        // GPS coordinates
        if (!empty($input['geo']) && is_array($input['geo'])) {
            $latitude  = sanitize_text_field($input['geo']['latitude'] ?? '');
            $longitude = sanitize_text_field($input['geo']['longitude'] ?? '');

            if (!empty($latitude) && !empty($longitude)) {
                $jsonld['geo'] = [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => (float) $latitude,
                    'longitude' => (float) $longitude,
                ];
            }
        }

        // Area served
        if (!empty($input['areaServed']) && is_array($input['areaServed'])) {
            $area_items = [];

            if (!empty($input['areaServed']['region'])) {
                $area_items[] = [
                    '@type' => 'AdministrativeArea',
                    'name'  => sanitize_text_field($input['areaServed']['region']),
                ];
            }
            if (!empty($input['areaServed']['country'])) {
                $area_items[] = [
                    '@type' => 'Country',
                    'name'  => sanitize_text_field($input['areaServed']['country']),
                ];
            }

            if (!empty($area_items)) {
                $jsonld['areaServed'] = count($area_items) === 1 ? $area_items[0] : $area_items;
            }
        }

        // Social networks (sameAs)
        if (!empty($input['sameAs'])) {
            if (is_array($input['sameAs'])) {
                // Filter empty values and clean URLs
                $sameAs = array_values(
                    array_filter(
                        array_map(
                            function ($url) {
                                $cleaned = trim($url);

                                return !empty($cleaned) ? esc_url_raw($cleaned) : '';
                            },
                            $input['sameAs'],
                        ),
                    ),
                );

                if (!empty($sameAs)) {
                    $jsonld['sameAs'] = $sameAs;
                }
            } elseif (is_string($input['sameAs'])) {
                // Fallback: if it's a simple string
                $url = esc_url_raw(trim($input['sameAs']));
                if (!empty($url)) {
                    $jsonld['sameAs'] = [$url];
                }
            }
        }

        // Opening hours
        // openingHours[0][days][], openingHours[0][opens], openingHours[0][closes]
        if (!empty($input['openingHours']) && is_array($input['openingHours'])) {
            $openingHoursSpec = [];

            foreach ($input['openingHours'] as $index => $hours) {
                // Check that it's an array with the correct keys
                if (!is_array($hours)) {
                    continue;
                }

                // Retrieve days
                $days = [];
                if (!empty($hours['days']) && is_array($hours['days'])) {
                    $days = array_filter(
                        array_map('sanitize_text_field', $hours['days']),
                        function ($day) {
                            return !empty($day);
                        },
                    );
                }

                $opens  = sanitize_text_field($hours['opens'] ?? '');
                $closes = sanitize_text_field($hours['closes'] ?? '');

                // Check that all required fields are present
                if (!empty($days) && !empty($opens) && !empty($closes)) {
                    $openingHoursSpec[] = [
                        '@type'     => 'OpeningHoursSpecification',
                        'dayOfWeek' => array_values($days),
                        'opens'     => $opens,
                        'closes'    => $closes,
                    ];
                }
            }

            if (!empty($openingHoursSpec)) {
                $jsonld['openingHoursSpecification'] = $openingHoursSpec;
            }
        }

        // Price range (Pro feature)
        if (!empty($input['priceRange'])) {
            $priceRange = sanitize_text_field($input['priceRange']);
            if (!empty($priceRange)) {
                $jsonld['priceRange'] = $priceRange;
            }
        }

        // Aggregate rating (Pro feature)
        if (!empty($input['aggregateRating']) && is_array($input['aggregateRating'])) {
            $ratingValue = (float) ($input['aggregateRating']['ratingValue'] ?? 0);
            $reviewCount = (int) ($input['aggregateRating']['reviewCount'] ?? 0);
            $bestRating  = (int) ($input['aggregateRating']['bestRating'] ?? 5);
            $worstRating = (int) ($input['aggregateRating']['worstRating'] ?? 1);

            if ($ratingValue > 0 && $reviewCount > 0) {
                $jsonld['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => $ratingValue,
                    'reviewCount' => $reviewCount,
                    'bestRating'  => max($bestRating, 1),
                    'worstRating' => max($worstRating, 1),
                ];
            }
        }

        // Final cleanup
        $jsonld = self::clean_empty_values($jsonld);

        return $jsonld;
    }

    /**
     * Sanitizes the raw `type` input into a list of valid Schema.org `@type` values.
     *
     * Accepts either a single type (string) or several (array), so a
     * business that spans more than one category can be marked up with a
     * multi-type array, e.g. `["MedicalBusiness", "Physician"]` or
     * `["Electrician", "Plumber", "Locksmith"]`. Unknown or duplicate
     * values are dropped, and the list is capped at self::MAX_TYPES.
     * Falls back to `["LocalBusiness"]` if nothing valid remains.
     *
     * @param mixed $raw_type Raw @type input: a string, or an array of strings.
     * @return string[] One or more valid, deduplicated Schema.org type names.
     */
    private static function sanitize_type($raw_type): array
    {
        $allowed_types = LocalSeoDefaults::get_allowed_type_keys();
        $candidates    = is_array($raw_type) ? $raw_type : [$raw_type];

        $types = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = sanitize_text_field($candidate);

            if ($candidate === '' || !in_array($candidate, $allowed_types, true)) {
                continue;
            }

            if (!in_array($candidate, $types, true)) {
                $types[] = $candidate;
            }
        }

        if (empty($types)) {
            return ['LocalBusiness'];
        }

        return array_slice($types, 0, self::MAX_TYPES);
    }

    /**
     * Sanitizes a field according to its type
     *
     * @param string $field Field name
     * @param mixed $value Value
     * @return string
     */
    private static function sanitize_field(string $field, $value): string
    {
        if ($field === 'description') {
            return sanitize_textarea_field($value);
        }

        if (in_array($field, ['url', 'image'], true)) {
            return esc_url_raw($value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Sanitizes the address
     *
     * @param array $address Raw address
     * @return array
     */
    private static function sanitize_address(array $address): array
    {
        $sanitized = ['@type' => 'PostalAddress'];

        $fields = ['streetAddress', 'postalCode', 'addressLocality', 'addressRegion', 'addressCountry'];

        foreach ($fields as $field) {
            if (!empty($address[ $field ])) {
                $sanitized[ $field ] = sanitize_text_field($address[ $field ]);
            }
        }

        return count($sanitized) > 1 ? $sanitized : [];
    }

    /**
     * Sanitizes GPS coordinates
     *
     * @param array $geo Raw coordinates
     * @return array
     */
    private static function sanitize_geo(array $geo): array
    {
        $latitude  = sanitize_text_field($geo['latitude'] ?? '');
        $longitude = sanitize_text_field($geo['longitude'] ?? '');

        if (empty($latitude) || empty($longitude)) {
            return [];
        }

        return [
            '@type'     => 'GeoCoordinates',
            'latitude'  => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Sanitizes the area served
     *
     * @param array $area Raw area
     * @return array
     */
    private static function sanitize_area_served(array $area): array
    {
        $sanitized = [];

        if (!empty($area['region'])) {
            $sanitized['region'] = sanitize_text_field($area['region']);
        }

        if (!empty($area['country'])) {
            $sanitized['country'] = sanitize_text_field($area['country']);
        }

        return $sanitized;
    }

    /**
     * Sanitizes social network URLs
     *
     * @param mixed $sameAs Raw URLs
     * @return array
     */
    private static function sanitize_same_as($sameAs): array
    {
        if (is_array($sameAs)) {
            return array_values(
                array_filter(
                    array_map(
                        function ($url) {
                            $cleaned = trim($url);

                            return !empty($cleaned) ? esc_url_raw($cleaned) : '';
                        },
                        $sameAs,
                    ),
                ),
            );
        }

        if (is_string($sameAs)) {
            $url = esc_url_raw(trim($sameAs));

            return !empty($url) ? [$url] : [];
        }

        return [];
    }

    /**
     * Sanitizes opening hours
     *
     * @param array $hours Raw hours
     * @return array
     */
    private static function sanitize_opening_hours(array $hours): array
    {
        $sanitized = [];

        foreach ($hours as $hour) {
            if (!is_array($hour)) {
                continue;
            }

            $days = [];
            if (!empty($hour['days']) && is_array($hour['days'])) {
                $days = array_filter(
                    array_map('sanitize_text_field', $hour['days']),
                    fn ($day) => !empty($day),
                );
            }

            $opens  = sanitize_text_field($hour['opens'] ?? '');
            $closes = sanitize_text_field($hour['closes'] ?? '');

            if (!empty($days) && !empty($opens) && !empty($closes)) {
                $sanitized[] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => array_values($days),
                    'opens'     => $opens,
                    'closes'    => $closes,
                ];
            }
        }

        return $sanitized;
    }

    /**
     * Cleans empty values
     *
     * @param array $array Array to clean
     * @return array
     */
    private static function clean_empty_values($array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[ $key ] = self::clean_empty_values($value);
                // Only remove if completely empty
                if (empty($array[ $key ]) && $key !== '@type') {
                    unset($array[ $key ]);
                }
            } elseif (empty($value) && $value !== 0 && $value !== '0' && $key !== '@type') {
                unset($array[ $key ]);
            }
        }

        return $array;
    }

}
