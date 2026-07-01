<?php

/**
 * REST controller for Google Suggest keyword autocomplete.
 *
 * Route: GET /seopulse/v1/keyword/suggestions?q=<keyword>&lang=<lang>
 *
 * @package SEOPulse\Api
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Api;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Abstracts\RestController;
use SEOPulse\Services\GoogleSuggestClient;
use WP_REST_Request;
use WP_REST_Response;

class GoogleSuggestController extends RestController
{
    protected string $rest_base = 'keyword';

    private GoogleSuggestClient $client;

    public function __construct()
    {
        $this->client = new GoogleSuggestClient();
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/suggestions',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_suggestions'],
                    'permission_callback' => [$this, 'check_permissions'],
                    'args'                => [
                        'q'    => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => static function ($value): bool {
                                return is_string($value) && mb_strlen(trim($value)) >= 2;
                            },
                        ],
                        'lang' => [
                            'required'          => false,
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Handle GET /seopulse/v1/keyword/suggestions
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_suggestions(WP_REST_Request $request): WP_REST_Response
    {
        $keyword  = (string) $request->get_param('q');
        $language = (string) $request->get_param('lang');

        if ($language === '') {
            $language = GoogleSuggestClient::detectLanguage();
        }

        // Normalize to 2-char language code.
        $language = substr($language, 0, 2);

        $suggestions = $this->client->getSuggestions($keyword, $language);

        return $this->success(
            [
                'suggestions' => $suggestions,
            ],
        );
    }
}
