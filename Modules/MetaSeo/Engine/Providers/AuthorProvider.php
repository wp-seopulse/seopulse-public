<?php

/**
 * Author variable provider (author.*).
 *
 * @package SEOPulse\Modules\MetaSeo\Engine\Providers
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\MetaSeo\Engine\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Modules\MetaSeo\Engine\ContextBag;
use SEOPulse\Modules\MetaSeo\Engine\VariableDefinition;
use SEOPulse\Modules\MetaSeo\Engine\VariableProviderInterface;
use WP_User;

/**
 * AuthorProvider — resolves author.* variables.
 */
final class AuthorProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'name',
        'first_name',
        'last_name',
        'bio',
        'avatar',
        'url',
        'post_count',
        'email',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        $author = $context->getAuthor();

        if (!$author instanceof WP_User) {
            return null;
        }

        return match ($variable) {
            'name'       => $author->display_name,
            'first_name' => $author->first_name,
            'last_name'  => $author->last_name,
            'bio'        => wp_strip_all_tags((string) $author->description),
            'avatar'     => (string) get_avatar_url($author->ID, ['size' => 512]),
            'url'        => (string) get_author_posts_url($author->ID),
            'post_count' => (string) count_user_posts($author->ID),
            'email'      => $author->user_email,
            default      => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('name', 'Author display name', 'John Doe', 'author'),
            new VariableDefinition('first_name', 'Author first name', 'John', 'author'),
            new VariableDefinition('last_name', 'Author last name', 'Doe', 'author'),
            new VariableDefinition('bio', 'Author biography', 'WordPress developer and SEO expert', 'author'),
            new VariableDefinition('avatar', 'Author avatar URL', 'https://example.com/avatar.jpg', 'author'),
            new VariableDefinition('url', 'Author archive page URL', 'https://example.com/author/john/', 'author'),
            new VariableDefinition('post_count', 'Number of author publications', '42', 'author'),
            new VariableDefinition('email', 'Author email address', 'john@example.com', 'author'),
        ];
    }
}
