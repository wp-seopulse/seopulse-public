<?php

/**
 * Consent Banner — Frontend HTML renderer
 *
 * Renders the GDPR-compliant cookie consent banner and preferences modal
 * in the WordPress frontend footer.
 *
 * @package SEOPulse\Modules\Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ConsentBanner class
 *
 * Outputs the HTML structure for the consent banner and modal.
 * The actual interactivity is handled by seopulse-consent.js.
 */
class ConsentBanner
{
    /**
     * Render the consent banner HTML
     *
     * The banner is hidden by default and shown via JS when no consent
     * cookie exists. This prevents FOUC.
     *
     * @return void
     */
    public function render(): void
    {
        // Don't render in admin, REST, AJAX, or login pages
        if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
            return;
        }

        // Don't render for logged-in admins if they've already set preferences
        if (current_user_can('manage_options') && isset($_COOKIE['seopulse_consent'])) {
            // Still render — admins should see it too for testing
        }

        $this->renderBanner();
        $this->renderModal();
        $this->renderReopenButton();
    }

    /**
     * Render the main consent banner
     *
     * @return void
     */
    private function renderBanner(): void
    {
        ?>
<!-- SEOPulse Cookie Consent Banner -->
<div id="seopulse-consent-banner" class="seopulse-consent-banner" role="dialog" aria-modal="false"
	aria-label="<?php esc_attr_e('Cookie consent', 'seopulse'); ?>"
	aria-describedby="seopulse-consent-description" data-nosnippet style="display:none;">

	<div class="seopulse-consent-banner__inner">
		<div class="seopulse-consent-banner__content">
			<h2 class="seopulse-consent-banner__title" id="seopulse-consent-title"></h2>
			<p class="seopulse-consent-banner__description" id="seopulse-consent-description"></p>
			<a href="#" class="seopulse-consent-banner__privacy-link" id="seopulse-consent-privacy-link" target="_blank"
				rel="noopener noreferrer"></a>
		</div>

		<div class="seopulse-consent-banner__actions">
			<button type="button" class="seopulse-consent-btn seopulse-consent-btn--accept"
				id="seopulse-consent-accept-all"
				aria-label="<?php esc_attr_e('Accept all cookies', 'seopulse'); ?>">
			</button>
			<button type="button" class="seopulse-consent-btn seopulse-consent-btn--reject"
				id="seopulse-consent-reject-all"
				aria-label="<?php esc_attr_e('Reject all non-essential cookies', 'seopulse'); ?>">
			</button>
			<button type="button" class="seopulse-consent-btn seopulse-consent-btn--customize"
				id="seopulse-consent-customize"
				aria-label="<?php esc_attr_e('Customize cookie preferences', 'seopulse'); ?>">
			</button>
		</div>
	</div>
</div>
<?php
    }

    /**
     * Render the preferences modal
     *
     * @return void
     */
    private function renderModal(): void
    {
        ?>
<!-- SEOPulse Cookie Preferences Modal -->
<div id="seopulse-consent-overlay" class="seopulse-consent-overlay" style="display:none;" aria-hidden="true"></div>

<div id="seopulse-consent-modal" class="seopulse-consent-modal" role="dialog" aria-modal="true"
	aria-labelledby="seopulse-modal-title" style="display:none;" tabindex="-1">

	<div class="seopulse-consent-modal__inner">
		<!-- Modal Header -->
		<div class="seopulse-consent-modal__header">
			<h2 class="seopulse-consent-modal__title" id="seopulse-modal-title"></h2>
			<button type="button" class="seopulse-consent-modal__close" id="seopulse-modal-close"
				aria-label="<?php esc_attr_e('Close preferences', 'seopulse'); ?>">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
					<path d="M15 5L5 15M5 5l10 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
				</svg>
			</button>
		</div>

		<!-- Modal Body — Categories -->
		<div class="seopulse-consent-modal__body" id="seopulse-modal-categories">
			<!-- Categories are populated by JS for i18n flexibility -->
		</div>

		<!-- Modal Footer -->
		<div class="seopulse-consent-modal__footer">
			<button type="button" class="seopulse-consent-btn seopulse-consent-btn--reject seopulse-consent-btn--sm"
				id="seopulse-modal-reject-all">
			</button>
			<button type="button" class="seopulse-consent-btn seopulse-consent-btn--accept seopulse-consent-btn--sm"
				id="seopulse-modal-accept-all">
			</button>
			<button type="button" class="seopulse-consent-btn seopulse-consent-btn--save" id="seopulse-modal-save">
			</button>
		</div>
	</div>
</div>
<?php
    }

    /**
     * Render the floating "Manage cookies" button
     *
     * This button is visible after the user has made their choice,
     * allowing them to reopen the preferences panel at any time.
     *
     * @return void
     */
    private function renderReopenButton(): void
    {
        ?>
<!-- SEOPulse Cookie Reopen Button -->
<button type="button" id="seopulse-consent-reopen" class="seopulse-consent-reopen" style="display:none;"
	aria-label="<?php esc_attr_e('Manage cookie preferences', 'seopulse'); ?>"
	title="<?php esc_attr_e('Manage cookies', 'seopulse'); ?>">
	<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
		<circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.5" />
		<circle cx="7" cy="8" r="1.5" fill="currentColor" />
		<circle cx="13" cy="7" r="1" fill="currentColor" />
		<circle cx="11" cy="13" r="1.5" fill="currentColor" />
		<circle cx="6" cy="13" r="1" fill="currentColor" />
		<circle cx="14" cy="11" r="0.8" fill="currentColor" />
	</svg>
</button>
<?php
    }
}
?>