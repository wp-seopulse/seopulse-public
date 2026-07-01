<?php

/**
 * 404 Monitor – Email Reporter
 *
 * Sends weekly email reports summarising 404 activity.
 * Scheduled via WordPress Cron (seopulse_404_weekly_report).
 *
 * @package SEOPulse\Modules\Monitor404
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Monitor404;

if (!defined('ABSPATH')) {
    exit;
}

class Monitor404EmailReporter
{
    private Monitor404Repository $repo;

    public function __construct()
    {
        $this->repo = new Monitor404Repository();
    }

    // =========================================================================
    // Scheduling
    // =========================================================================

    /**
     * Ensures the weekly cron is scheduled (idempotent).
     */
    public function scheduleCron(): void
    {
        if (!wp_next_scheduled('seopulse_404_weekly_report')) {
            wp_schedule_event(strtotime('next monday 08:00:00'), 'weekly', 'seopulse_404_weekly_report');
        }
    }

    /**
     * Clears the scheduled cron event.
     */
    public function clearCron(): void
    {
        wp_clear_scheduled_hook('seopulse_404_weekly_report');
    }

    // =========================================================================
    // Sending
    // =========================================================================

    /**
     * Sends the weekly report. This is the cron callback.
     */
    public function send(): void
    {
        $opts = $this->getOptions();

        if (!($opts['email_report_enabled'] ?? false)) {
            return;
        }

        $recipient = sanitize_email($opts['email_report_recipient'] ?? get_option('admin_email'));

        if (empty($recipient)) {
            return;
        }

        $data    = $this->repo->getWeeklyReportData();
        $subject = $this->buildSubject();
        $body    = $this->buildBody($data);

        wp_mail(
            $recipient,
            $subject,
            $body,
            ['Content-Type: text/html; charset=UTF-8'],
        );
    }

    /**
     * Sends a test email immediately, bypassing the enabled/schedule checks.
     *
     * @param string $recipient Email address (falls back to saved recipient, then admin_email).
     * @return array{sent: bool, error?: string} Result with optional error message.
     */
    public function sendTest(string $recipient = ''): array
    {
        if (empty($recipient)) {
            $opts      = $this->getOptions();
            $recipient = sanitize_email($opts['email_report_recipient'] ?? '');
        }

        if (empty($recipient)) {
            $recipient = (string) get_option('admin_email');
        }

        $recipient = sanitize_email($recipient);

        if (empty($recipient)) {
            return ['sent' => false, 'error' => 'No valid recipient address.'];
        }

        $data    = $this->repo->getWeeklyReportData();
        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] Test — Weekly 404 Error Report', 'seopulse'),
            get_bloginfo('name'),
        );
        $body = $this->buildBody($data);

        // Capture wp_mail_failed to surface PHPMailer / SMTP errors.
        $mail_error = null;
        $capture    = static function (\WP_Error $error) use (&$mail_error): void {
            $mail_error = $error->get_error_message();
        };
        add_action('wp_mail_failed', $capture);

        $sent = (bool) wp_mail(
            $recipient,
            $subject,
            $body,
            ['Content-Type: text/html; charset=UTF-8'],
        );

        remove_action('wp_mail_failed', $capture);

        if (!$sent) {
            return ['sent' => false, 'error' => $mail_error ?? 'wp_mail() returned false.'];
        }

        return ['sent' => true];
    }

    // =========================================================================
    // Templates
    // =========================================================================

    private function buildSubject(): string
    {
        return sprintf(
            /* translators: %s: site name */
            __('[%s] Weekly 404 Error Report', 'seopulse'),
            get_bloginfo('name'),
        );
    }

    /**
     * Builds the HTML email body.
     *
     * @param array{new_count: int, top_urls: array, top_referrers: array} $data
     */
    private function buildBody(array $data): string
    {
        $siteUrl  = get_bloginfo('url');
        $siteName = get_bloginfo('name');
        $adminUrl = admin_url('admin.php?page=seopulse-404-monitor');
        $accent   = '#2271b1';
        $company  = 'SEOPulse';

        $topUrlsHtml = '';
        foreach ($data['top_urls'] as $item) {
            $url          = esc_html($item['url'] ?? '');
            $hits         = (int) ($item['hits'] ?? 0);
            $topUrlsHtml .= "<tr><td style='padding:8px 12px;border-bottom:1px solid #eee;'>{$url}</td>"
                            . "<td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:600;'>{$hits}</td></tr>";
        }

        $topRefHtml = '';
        foreach ($data['top_referrers'] as $ref) {
            $refUrl      = esc_html($ref['referrer'] ?? '');
            $hits        = (int) ($ref['total_hits'] ?? 0);
            $topRefHtml .= "<tr><td style='padding:8px 12px;border-bottom:1px solid #eee;'>{$refUrl}</td>"
                        . "<td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:600;'>{$hits}</td></tr>";
        }

        $newCount = (int) $data['new_count'];

        $html  = '<!DOCTYPE html>';
        $html .= '<html>';
        $html .= '<head><meta charset="UTF-8"><title>404 Report</title></head>';
        $html .= '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f0f0f1;margin:0;padding:24px;">';
        $html .= '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">';

        // Header
        $html .= '<div style="background:' . esc_attr($accent) . ';padding:24px 32px;">';
        $html .= '<h1 style="color:#fff;margin:0;font-size:20px;">404 Error Weekly Report</h1>';
        $html .= '<p style="color:rgba(255,255,255,.75);margin:4px 0 0;font-size:14px;">' . esc_html($siteName) . ' &mdash; ' . esc_html($siteUrl) . '</p>';
        $html .= '</div>';

        // Summary
        $html .= '<div style="padding:24px 32px;background:#f6f7f7;">';
        $html .= '<p style="margin:0;font-size:15px;color:#1d2327;">';
        $html .= '<strong>' . (int) $newCount . '</strong> new 404 error(s) detected in the past 7 days.';
        $html .= '</p></div>';

        // Top 404 URLs
        $html .= '<div style="padding:24px 32px;">';
        $html .= '<h2 style="font-size:16px;color:#1d2327;margin:0 0 12px;">Top 404 URLs (human traffic)</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
        $html .= '<thead><tr style="background:#f6f7f7;">';
        $html .= '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">URL</th>';
        $html .= '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">Hits</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>' . $topUrlsHtml . '</tbody>';
        $html .= '</table></div>';

        // Top Referrers
        $html .= '<div style="padding:0 32px 24px;">';
        $html .= '<h2 style="font-size:16px;color:#1d2327;margin:0 0 12px;">Top Referrers</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
        $html .= '<thead><tr style="background:#f6f7f7;">';
        $html .= '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">Referrer</th>';
        $html .= '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">Hits</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>' . $topRefHtml . '</tbody>';
        $html .= '</table></div>';

        // CTA
        $html .= '<div style="padding:16px 32px 32px;text-align:center;">';
        $html .= '<a href="' . esc_url($adminUrl) . '" style="display:inline-block;padding:12px 24px;background:' . esc_attr($accent) . ';color:#fff;border-radius:4px;text-decoration:none;font-size:14px;font-weight:600;">';
        $html .= 'View Full Report &rarr;</a></div>';

        // Footer
        $html .= '<div style="padding:16px 32px;border-top:1px solid #eee;text-align:center;font-size:12px;color:#999;">';
        $html .= 'This report was sent by ' . esc_html($company) . ' &bull; <a href="' . esc_url($adminUrl) . '" style="color:' . esc_attr($accent) . ';">Manage settings</a>';
        $html .= '</div>';

        $html .= '</div></body></html>';

        return $html;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function getOptions(): array
    {
        $opts = get_option('seopulse_404_settings', []);

        return is_array($opts) ? $opts : [];
    }
}
