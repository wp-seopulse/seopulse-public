<?php

/**
 * Date/time variable provider (date.*).
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
 * DateProvider — resolves date.* variables.
 */
final class DateProvider implements VariableProviderInterface
{
    private const VARIABLES = [
        'current_year',
        'current_month',
        'current_month_num',
        'current_day',
        'current_date',
        'current_time',
        'timestamp',
        'day_of_week',
    ];

    public function supports(string $variable): bool
    {
        return in_array($variable, self::VARIABLES, true);
    }

    public function resolve(string $variable, ContextBag $context): ?string
    {
        return match ($variable) {
            'current_year'      => date_i18n('Y'),
            'current_month'     => date_i18n('F'),
            'current_month_num' => date_i18n('m'),
            'current_day'       => date_i18n('j'),
            'current_date'      => date_i18n(get_option('date_format', 'F j, Y')),
            'current_time'      => date_i18n(get_option('time_format', 'g:i a')),
            'timestamp'         => (string) time(),
            'day_of_week'       => date_i18n('l'),
            default             => null,
        };
    }

    public function getDefinitions(): array
    {
        return [
            new VariableDefinition('current_year', 'Current year', '2026', 'date'),
            new VariableDefinition('current_month', 'Current month name', 'March', 'date'),
            new VariableDefinition('current_month_num', 'Current month number', '03', 'date'),
            new VariableDefinition('current_day', 'Current day of month', '2', 'date'),
            new VariableDefinition('current_date', 'Full formatted date', 'March 2, 2026', 'date'),
            new VariableDefinition('current_time', 'Current time', '3:45 pm', 'date'),
            new VariableDefinition('timestamp', 'Unix timestamp', '1772611200', 'date'),
            new VariableDefinition('day_of_week', 'Day of the week name', 'Monday', 'date'),
        ];
    }
}
