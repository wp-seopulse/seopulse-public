<?php

/**
 * Trait for exportable plugin configuration
 *
 * Provides shared constants and backup/export logic used by both
 * ToolsController (export/import/reset) and MigrationController (pre-migration backup).
 *
 * @package SEOPulse\Core\Traits
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Core\Traits;

if (!defined('ABSPATH')) {
    exit;
}

use SEOPulse\Core\Constants\Options;

/**
 * ExportableConfigTrait
 *
 * Centralizes the list of exportable option keys and
 * the backup creation logic to avoid duplication.
 */
trait ExportableConfigTrait
{
    /**
     * Configuration schema version
     *
     * @var string
     */
    private static string $configSchemaVersion = '1.0.0';

    /**
     * Option key for automatic backup
     *
     * @var string
     */
    private static string $backupOption = 'seopulse_config_backup';

    /**
     * Returns the exportable option keys and their labels
     *
     * @return array<string, string>
     */
    private static function getExportableOptions(): array
    {
        return [
            'general'         => Options::GENERAL,
            'meta_seo_global' => Options::META_SEO_GLOBAL,
            'analytics'       => Options::ANALYTICS,
            'local_seo'       => Options::LOCAL_SEO,
            'redirections'    => Options::REDIRECTIONS,
            'sitemap'         => Options::SITEMAP,
            'modules_enabled' => Options::MODULES_ENABLED,
        ];
    }

    /**
     * Builds the export payload from current settings
     *
     * @return array<string, mixed>
     */
    private function buildExportPayload(): array
    {
        $modules = [];

        foreach (self::getExportableOptions() as $key => $option_name) {
            $value           = get_option($option_name, []);
            $modules[ $key ] = is_array($value) ? $value : [];
        }

        return [
            'schema_version' => self::$configSchemaVersion,
            'plugin_version' => SEOPULSE_VERSION,
            'exported_at'    => gmdate('c'),
            'site_url'       => home_url(),
            'modules'        => $modules,
        ];
    }

    /**
     * Creates a backup of the current configuration
     *
     * @param string $reason Backup reason (e.g. 'pre_import', 'pre_migration')
     * @return bool
     */
    private function createConfigBackup(string $reason = 'pre_import'): bool
    {
        $backup                  = $this->buildExportPayload();
        $backup['backup_reason'] = $reason;
        $backup['backup_at']     = gmdate('c');

        return update_option(self::$backupOption, $backup, false);
    }
}
