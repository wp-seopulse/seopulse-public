<?php

/**
 * Detailed checks list view - grouped by tier (core / secondary)
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_checks Checks list (each item may contain a 'tier' key)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($seopulse_checks)) {
    return;
}

// Split checks by tier
$seopulse_core      = [];
$seopulse_secondary = [];

foreach ($seopulse_checks as $seopulse_check) {
    $seopulse_tier = $seopulse_check['tier'] ?? 'core';
    if ($seopulse_tier === 'core') {
        $seopulse_core[] = $seopulse_check;
    } else {
        // secondary and any other tier rendered in the expanded section
        $seopulse_secondary[] = $seopulse_check;
    }
}

// Icons & colors based on status
$seopulse_icons = [
    'success' => 'yes-alt',
    'warning' => 'warning',
    'error'   => 'dismiss',
    'info'    => 'info',
];
?>
<div class="seopulse-checks-section">
        <?php if (!empty($seopulse_core)) : ?>
        <h5 class="seopulse-checks-title">
                <?php esc_html_e('Key Checks', 'seopulse'); ?>
        </h5>
        <div class="seopulse-checks-list">
                <?php
        foreach ($seopulse_core as $seopulse_check) :
            $seopulse_check_status = $seopulse_check['status'] ?? 'info';
            $seopulse_icon         = $seopulse_icons[ $seopulse_check_status ] ?? 'info';
            ?>
                <div class="seopulse-check-item seopulse-check-item--<?php echo esc_attr($seopulse_check_status); ?>"
                        data-check="<?php echo esc_attr($seopulse_check['name'] ?? ''); ?>">
                        <span
                                class="dashicons dashicons-<?php echo esc_attr($seopulse_icon); ?>"></span>
                        <span
                                class="seopulse-check-message"><?php echo esc_html($seopulse_check['message'] ?? ''); ?></span>
                </div>
                <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($seopulse_secondary)) : ?>
        <div class="seopulse-checks-secondary">
                <button type="button" class="seopulse-checks-toggle" data-target="secondary">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                        <?php
                printf(
                    /* translators: %d: number of secondary checks */
                    esc_html__('More checks (%d)', 'seopulse'),
                    count($seopulse_secondary),
                );
            ?>
                </button>
                <div class="seopulse-checks-list seopulse-checks-list--secondary" style="display: none;">
                        <?php
            foreach ($seopulse_secondary as $seopulse_check) :
                $seopulse_check_status = $seopulse_check['status'] ?? 'info';
                $seopulse_icon         = $seopulse_icons[ $seopulse_check_status ] ?? 'info';
                ?>
                        <div class="seopulse-check-item seopulse-check-item--<?php echo esc_attr($seopulse_check_status); ?>"
                                data-check="<?php echo esc_attr($seopulse_check['name'] ?? ''); ?>">
                                <span
                                        class="dashicons dashicons-<?php echo esc_attr($seopulse_icon); ?>"></span>
                                <span
                                        class="seopulse-check-message"><?php echo esc_html($seopulse_check['message'] ?? ''); ?></span>
                        </div>
                        <?php endforeach; ?>
                </div>
        </div>
        <?php endif; ?>
</div>