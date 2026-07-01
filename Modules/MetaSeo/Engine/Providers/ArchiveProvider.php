<?php

/**
 * Archive variable provider (archive.*).
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

/**
 * ArchiveProvider — resolves archive.* variables.
 */
final class ArchiveProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'title',
        'description',
        'type',
        'date',
        'year',
        'month',
        'day',
        'count',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'title'       => $this->getArchiveTitle($context),
            'description' => $this->getArchiveDescription($context),
            'type'        => $this->getArchiveType($context),
            'date'        => $this->getArchiveDate($context),
            'year'        => $context->getExtra('year', ''),
            'month'       => $this->getMonthName($context),
            'day'         => $context->getExtra('day', ''),
            'count'       => $this->getResultCount(),
            default       => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('title', 'Archive page title', 'Latest Posts', 'archive'),
            new VariableDefinition('description', 'Archive description', 'Browse all articles', 'archive'),
            new VariableDefinition('type', 'Archive type (date, post_type, etc.)', 'date', 'archive'),
            new VariableDefinition('date', 'Archive date string', 'March 2026', 'archive'),
            new VariableDefinition('year', 'Archive year', '2026', 'archive'),
            new VariableDefinition('month', 'Archive month name', 'March', 'archive'),
            new VariableDefinition('day', 'Archive day', '15', 'archive'),
            new VariableDefinition('count', 'Number of results in archive', '42', 'archive'),
        ];
    }

    private function getArchiveTitle(ContextBag $context): string
    {
        // Post-type archive
        $postType = $context->getExtra('post_type');
        if (is_string($postType) && $postType !== '') {
            $obj = get_post_type_object($postType);

            return $obj ? $obj->labels->name : $postType;
        }

        // Date archive
        $archiveType = $context->getExtra('archive_type');
        if ($archiveType === 'date') {
            return $this->getArchiveDate($context);
        }

        // Fallback to WordPress' built-in title
        return function_exists('get_the_archive_title')
            ? wp_strip_all_tags((string) get_the_archive_title())
            : '';
    }

    private function getArchiveDescription(ContextBag $context): string
    {
        $postType = $context->getExtra('post_type');
        if (is_string($postType) && $postType !== '') {
            $obj = get_post_type_object($postType);

            return $obj ? (string) $obj->description : '';
        }

        return function_exists('get_the_archive_description')
            ? wp_strip_all_tags((string) get_the_archive_description())
            : '';
    }

    private function getArchiveType(ContextBag $context): string
    {
        if ($context->getExtra('archive_type') === 'date') {
            return 'date';
        }

        $postType = $context->getExtra('post_type');

        return is_string($postType) && $postType !== '' ? 'post_type' : 'generic';
    }

    private function getArchiveDate(ContextBag $context): string
    {
        $year  = $context->getExtra('year', '');
        $month = $context->getExtra('month', '');
        $day   = $context->getExtra('day', '');

        if ($day !== '' && $day !== '0') {
            return date_i18n('F j, Y', mktime(0, 0, 0, (int) $month, (int) $day, (int) $year));
        }

        if ($month !== '' && $month !== '0') {
            return date_i18n('F Y', mktime(0, 0, 0, (int) $month, 1, (int) $year));
        }

        return (string) $year;
    }

    private function getMonthName(ContextBag $context): string
    {
        $month = $context->getExtra('month', '');
        if ($month === '' || $month === '0') {
            return '';
        }

        return date_i18n('F', mktime(0, 0, 0, (int) $month, 1, 2000));
    }

    private function getResultCount(): string
    {
        global $wp_query;

        return ($wp_query instanceof \WP_Query) ? (string) $wp_query->found_posts : '0';
    }
}
