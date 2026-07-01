<?php
/**
 * Issues tab view — aligned labels & distinct icons per severity
 *
 * @package SEOPulse\Modules\MetaSeo\Views
 * @since 1.0.0
 *
 * @var array $seopulse_issues Issues by severity
 */

if (!defined('ABSPATH')) {
    exit;
}

$seopulse_severity_config = [
    'critical' => [
        'label' => __('Blocker', 'seopulse'),
        'icon'  => 'dashicons-dismiss',
    ],
    'high'     => [
        'label' => __('Important', 'seopulse'),
        'icon'  => 'dashicons-warning',
    ],
    'medium'   => [
        'label' => __('Improvement', 'seopulse'),
        'icon'  => 'dashicons-info',
    ],
    'low'      => [
        'label' => __('Minor', 'seopulse'),
        'icon'  => 'dashicons-editor-help',
    ],
];

$seopulse_has_issues = false;

foreach ($seopulse_severity_config as $seopulse_severity => $seopulse_config) {
    if (!empty($seopulse_issues[ $seopulse_severity ])) {
        $seopulse_has_issues = true;
        ?>
<div class="seopulse-issues-group">
	<h5
		class="seopulse-issues-title seopulse-issues-title--<?php echo esc_attr($seopulse_severity); ?>">
		<span
			class="dashicons <?php echo esc_attr($seopulse_config['icon']); ?>"></span>
		<?php echo esc_html($seopulse_config['label']); ?>
		<span
			class="seopulse-badge seopulse-badge--<?php echo esc_attr($seopulse_severity); ?>">
			<?php echo count($seopulse_issues[ $seopulse_severity ]); ?>
		</span>
	</h5>
	<ul class="seopulse-issues-list">
		<?php foreach ($seopulse_issues[ $seopulse_severity ] as $seopulse_issue) : ?>
		<li
			class="seopulse-issue-item seopulse-issue-item--<?php echo esc_attr($seopulse_severity); ?>">
			<?php echo esc_html($seopulse_issue['message'] ?? ''); ?>
		</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php
    }
}

if (!$seopulse_has_issues) {
    ?>
<div class="seopulse-no-issues">
	<span class="dashicons dashicons-yes-alt"></span>
	<p><?php esc_html_e('No issues found — great job!', 'seopulse'); ?>
	</p>
</div>
<?php
}
?>