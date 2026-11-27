/**
 * SEOPulse Cookie Consent Banner — Frontend JavaScript
 *
 * GDPR/CNIL-compliant cookie consent management.
 * Vanilla JS, no dependencies.
 *
 * Features:
 * - Block scripts until explicit consent
 * - 4 categories: Essential (always), Statistics, Marketing, Preferences
 * - Google Consent Mode v2 integration
 * - Accessible (WCAG, ARIA, focus trap, keyboard navigation)
 * - localStorage/cookie storage with configurable expiry
 * - Reopen panel at any time
 * - No FOUC (hidden by default, shown via JS)
 *
 * @package
 * @since 1.2.0
 */

(function () {
	'use strict';

	// ── Config & i18n ───────────────────────────────────────────
	const config = window.seopulseConsent || {};
	const settings = config.settings || {};
	const scripts = config.scripts || {};
	const i18n = config.i18n || {};

	const COOKIE_NAME = settings.cookieName || 'seopulse_consent';
	const COOKIE_EXPIRY = settings.cookieExpiry || 365;
	const GCM_V2 = settings.gcmV2 || false;

	// ── DOM References ──────────────────────────────────────────
	let banner = null;
	let modal = null;
	let overlay = null;
	let backdrop = null;
	let reopenBtn = null;
	let previousFocusElement = null;

	// ── Categories definition ───────────────────────────────────
	const CATEGORIES = [
		{
			id: 'essential',
			label: i18n.catEssential || 'Essential',
			description:
				i18n.catEssentialDesc || 'Required for basic functionality.',
			required: true,
		},
		{
			id: 'statistics',
			label: i18n.catStatistics || 'Statistics',
			description:
				i18n.catStatisticsDesc ||
				'Help us understand visitor behavior.',
			required: false,
		},
		{
			id: 'marketing',
			label: i18n.catMarketing || 'Marketing',
			description:
				i18n.catMarketingDesc || 'Used for personalized advertising.',
			required: false,
		},
		{
			id: 'preferences',
			label: i18n.catPreferences || 'Preferences',
			description:
				i18n.catPreferencesDesc ||
				'Remember your settings and choices.',
			required: false,
		},
	];

	// ── Utility: Cookie operations ──────────────────────────────
	function setCookie(name, value, days) {
		let expires = '';
		if (days) {
			const date = new Date();
			date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
			expires = '; expires=' + date.toUTCString();
		}
		const sameSite = '; SameSite=Lax';
		const secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie =
			name +
			'=' +
			encodeURIComponent(value) +
			expires +
			'; path=/' +
			sameSite +
			secure;
	}

	function getCookie(name) {
		const nameEQ = name + '=';
		const ca = document.cookie.split(';');
		for (let i = 0; i < ca.length; i++) {
			const c = ca[i].trim();
			if (c.indexOf(nameEQ) === 0) {
				return decodeURIComponent(c.substring(nameEQ.length));
			}
		}
		return null;
	}

	// ── Utility: Parse stored consent ───────────────────────────
	function getStoredConsent() {
		const raw = getCookie(COOKIE_NAME);
		if (!raw) {
			return null;
		}
		try {
			const parsed = JSON.parse(raw);
			if (parsed && parsed.categories) {
				return parsed;
			}
		} catch (e) {
			// Invalid cookie
		}
		return null;
	}

	// ── Utility: Save consent ───────────────────────────────────
	function saveConsent(categories) {
		const previous = getStoredConsent();
		const consentData = {
			categories,
			timestamp: new Date().toISOString(),
			version: '1.0',
		};
		setCookie(COOKIE_NAME, JSON.stringify(consentData), COOKIE_EXPIRY);

		try {
			localStorage.setItem(COOKIE_NAME, JSON.stringify(consentData));
		} catch (e) {
			// localStorage not available
		}

		// 1. consent update  2. and then load allowed scripts
		applyConsent(categories);

		// If statistics consent is revoked, clean up GA cookies.
		if (previous && previous.categories && previous.categories.statistics && !categories.statistics) {
			purgeAnalyticsCookies();
		}

		fireConsentEvent(categories);
	}

	// This function applies the consent settings: updates Google Consent Mode first, then loads allowed scripts.
	function applyConsent(categories) {
		if (GCM_V2) {
			updateGoogleConsentMode(categories);
		}
		loadConsentedScripts(categories);
	}

	function purgeAnalyticsCookies() {
		const host = location.hostname;
		const parts = host.split('.');
		const domains = ['', host, '.' + host];
		if (parts.length > 2) {
			domains.push('.' + parts.slice(-2).join('.'));
		}
		document.cookie.split(';').forEach(function (c) {
			const name = c.split('=')[0].trim();
			if (!/^(_ga|_gid|_gat)/.test(name)) {
				return;
			}
			domains.forEach(function (d) {
				document.cookie =
					name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' +
					(d ? '; domain=' + d : '');
			});
		});
	}

	// ── Google Consent Mode v2 ──────────────────────────────────
	function updateGoogleConsentMode(categories) {
		if (typeof gtag !== 'function') {
			// Define gtag if not available
			window.dataLayer = window.dataLayer || [];
			window.gtag = function () {
				window.dataLayer.push(arguments);
			};
		}

		const consentUpdate = {
			analytics_storage: categories.statistics ? 'granted' : 'denied',
			ad_storage: categories.marketing ? 'granted' : 'denied',
			ad_user_data: categories.marketing ? 'granted' : 'denied',
			ad_personalization: categories.marketing ? 'granted' : 'denied',
			functionality_storage: categories.preferences
				? 'granted'
				: 'denied',
			personalization_storage: categories.preferences
				? 'granted'
				: 'denied',
			security_storage: 'granted',
		};

		window.gtag('consent', 'update', consentUpdate);
	}

	// ── Script loading after consent ────────────────────────────
	function loadConsentedScripts(categories) {
		// Load GA4 if statistics accepted
		if (categories.statistics) {
			if (scripts.gtm_id) {
				loadGTM(scripts.gtm_id);
			} else if (scripts.ga4_id) {
				loadGA4(scripts.ga4_id);
			}
		}

		// Matomo and Clarity (statistics category)
		if (categories.statistics && scripts.matomo) {
			loadMatomo(scripts.matomo.url, scripts.matomo.site_id);
		}

		if (categories.statistics && scripts.clarity_id) {
			loadClarity(scripts.clarity_id);
		}

		// Custom code (head / footer), always provided by the server (unless the
		// user is excluded) and executed here once statistics are accepted.
		// Guarded against double execution within the page.
		if (categories.statistics && scripts.custom_code) {
			loadCustomCode(scripts.custom_code);
		}
	}

	function runInlineScript(code, parent) {
		if (!code || !parent) {
			return;
		}
		const el = document.createElement('script');
		el.textContent = code;
		parent.appendChild(el);
	}

	function loadCustomCode(custom) {
		if (window.__seopulseCustomCodeLoaded) {
			return;
		}
		window.__seopulseCustomCodeLoaded = true;

		runInlineScript(custom.head, document.head);
		runInlineScript(custom.footer, document.body || document.head);
	}

	function loadMatomo(baseUrl, siteId) {
		if (window.__seopulseMatomoLoaded) {
			return;
		}
		window.__seopulseMatomoLoaded = true;

		const _paq = (window._paq = window._paq || []);
		_paq.push(['trackPageView']);
		_paq.push(['enableLinkTracking']);
		_paq.push(['setTrackerUrl', baseUrl + 'matomo.php']);
		_paq.push(['setSiteId', siteId]);

		const g = document.createElement('script');
		g.async = true;
		g.src = baseUrl + 'matomo.js';
		document.head.appendChild(g);
	}

	function loadClarity(projectId) {
		if (window.__seopulseClarityLoaded) {
			return;
		}
		window.__seopulseClarityLoaded = true;

		(function (c, l, a, r, i) {
			c[a] =
				c[a] ||
				function () {
					(c[a].q = c[a].q || []).push(arguments);
				};
			const t = l.createElement(r);
			t.async = 1;
			t.src = 'https://www.clarity.ms/tag/' + i;
			const y = l.getElementsByTagName(r)[0];
			y.parentNode.insertBefore(t, y);
		})(window, document, 'clarity', 'script', projectId);
	}

	function loadGA4(ga4Id) {
		if (window.__seopulseGa4Loaded || document.querySelector('script[src*="googletagmanager.com/gtag/js"]')) {
			return;
		}
		window.__seopulseGa4Loaded = true;

		const script = document.createElement('script');
		script.async = true;
		script.src = 'https://www.googletagmanager.com/gtag/js?id=' + ga4Id;
		document.head.appendChild(script);

		window.dataLayer = window.dataLayer || [];
		if (typeof window.gtag !== 'function') {
			window.gtag = function () {
				window.dataLayer.push(arguments);
			};
		}
		window.gtag('js', new Date());
		window.gtag('config', ga4Id);
	}

	function loadGTM(gtmId) {
		// Avoid double-loading
		if (window.__seopulseGtmLoaded || document.querySelector('script[src*="googletagmanager.com/gtm.js"]')) {
			return;
		}
		window.__seopulseGtmLoaded = true;

		(function (w, d, s, l, i) {
			w[l] = w[l] || [];
			w[l].push({
				'gtm.start': new Date().getTime(),
				event: 'gtm.js',
			});
			const f = d.getElementsByTagName(s)[0],
				j = d.createElement(s),
				dl = l !== 'dataLayer' ? '&l=' + l : '';
			j.async = true;
			j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
			f.parentNode.insertBefore(j, f);
		})(window, document, 'script', 'dataLayer', gtmId);
	}

	// ── Custom event ────────────────────────────────────────────
	function fireConsentEvent(categories) {
		try {
			const event = new CustomEvent('seopulse:consent', {
				detail: { categories },
			});
			document.dispatchEvent(event);
		} catch (e) {
			// IE11 fallback — not strictly needed for modern browsers
		}
	}

	// ── Initialize banner ───────────────────────────────────────
	function init() {
		banner = document.getElementById('seopulse-consent-banner');
		modal = document.getElementById('seopulse-consent-modal');
		overlay = document.getElementById('seopulse-consent-overlay');
		backdrop = document.getElementById('seopulse-consent-backdrop');
		reopenBtn = document.getElementById('seopulse-consent-reopen');

		if (!banner) {
			return;
		}

		// Populate i18n text content
		populateTexts();

		// Build modal categories
		buildModalCategories();

		// Bind events
		bindEvents();

		// Check existing consent
		const storedConsent = getStoredConsent();

		if (storedConsent) {
			// Consentment already exists, no need to show the banner again.
			showReopenButton();
		} else {
			showBanner();
		}
	}

	// ── Populate text content from i18n ─────────────────────────
	function populateTexts() {
		setText('seopulse-consent-title', i18n.bannerTitle);
		setText('seopulse-consent-description', i18n.bannerDescription);
		setText('seopulse-consent-accept-all', i18n.acceptAll);
		setText('seopulse-consent-reject-all', i18n.rejectAll);
		setText('seopulse-consent-customize', i18n.customize);
		setText('seopulse-modal-title', i18n.modalTitle);
		setText('seopulse-modal-reject-all', i18n.rejectAll);
		setText('seopulse-modal-accept-all', i18n.acceptAll);
		setText('seopulse-modal-save', i18n.saveChoices);

		// Privacy link
		const privacyLink = document.getElementById(
			'seopulse-consent-privacy-link'
		);
		if (privacyLink && i18n.privacyPolicyUrl) {
			privacyLink.href = i18n.privacyPolicyUrl;
			privacyLink.textContent = i18n.privacyPolicy || 'Privacy policy';
			privacyLink.style.display = '';
		} else if (privacyLink) {
			privacyLink.style.display = 'none';
		}
	}

	function setText(id, text) {
		const el = document.getElementById(id);
		if (el && text) {
			el.textContent = text;
		}
	}

	// ── Build modal categories ──────────────────────────────────
	function buildModalCategories() {
		const container = document.getElementById(
			'seopulse-modal-categories'
		);
		if (!container) {
			return;
		}

		let html = '';
		for (let i = 0; i < CATEGORIES.length; i++) {
			const cat = CATEGORIES[i];
			const isRequired = cat.required;

			html +=
				'<div class="seopulse-consent-category" data-category="' +
				cat.id +
				'">';
			html += '  <div class="seopulse-consent-category__header">';
			html += '    <div class="seopulse-consent-category__info">';
			html +=
				'      <h3 class="seopulse-consent-category__title">' +
				escapeHtml(cat.label) +
				'</h3>';
			html +=
				'      <p class="seopulse-consent-category__desc">' +
				escapeHtml(cat.description) +
				'</p>';
			html += '    </div>';
			html += '    <div class="seopulse-consent-category__toggle">';

			if (isRequired) {
				html +=
					'      <span class="seopulse-consent-category__always-active">' +
					escapeHtml(i18n.alwaysActive || 'Always active') +
					'</span>';
				html +=
					'      <input type="checkbox" checked disabled class="seopulse-consent-toggle" data-category="' +
					cat.id +
					'">';
			} else {
				html +=
					'      <label class="seopulse-consent-switch" for="consent-cat-' +
					cat.id +
					'">';
				html +=
					'        <input type="checkbox" id="consent-cat-' +
					cat.id +
					'" class="seopulse-consent-toggle" data-category="' +
					cat.id +
					'" checked>';
				html +=
					'        <span class="seopulse-consent-switch__slider"></span>';
				html +=
					'        <span class="sr-only">' +
					escapeHtml(cat.label) +
					'</span>';
				html += '      </label>';
			}

			html += '    </div>';
			html += '  </div>';
			html += '</div>';
		}

		container.innerHTML = html;
	}

	function escapeHtml(text) {
		const div = document.createElement('div');
		div.appendChild(document.createTextNode(text || ''));
		return div.innerHTML;
	}

	// ── Event binding ───────────────────────────────────────────
	function bindEvents() {
		// Banner buttons
		on('seopulse-consent-accept-all', 'click', handleAcceptAll);
		on('seopulse-consent-reject-all', 'click', handleRejectAll);
		on('seopulse-consent-customize', 'click', handleOpenModal);

		// Modal buttons
		on('seopulse-modal-accept-all', 'click', handleAcceptAll);
		on('seopulse-modal-reject-all', 'click', handleRejectAll);
		on('seopulse-modal-save', 'click', handleSaveChoices);
		on('seopulse-modal-close', 'click', handleCloseModal);

		// Overlay click
		on('seopulse-consent-overlay', 'click', handleCloseModal);

		// Reopen button
		on('seopulse-consent-reopen', 'click', handleOpenModal);

		// Keyboard: Escape closes modal
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				if (modal && modal.style.display !== 'none') {
					handleCloseModal();
				}
			}
		});
	}

	function on(id, event, handler) {
		const el = document.getElementById(id);
		if (el) {
			el.addEventListener(event, handler);
		}
	}

	// ── Handlers ────────────────────────────────────────────────
	function handleAcceptAll(e) {
		if (e) {
			e.preventDefault();
		}
		const categories = {
			essential: true,
			statistics: true,
			marketing: true,
			preferences: true,
		};
		saveConsent(categories);
		hideBanner();
		hideModal();
		showReopenButton();
	}

	function handleRejectAll(e) {
		if (e) {
			e.preventDefault();
		}
		const categories = {
			essential: true,
			statistics: false,
			marketing: false,
			preferences: false,
		};
		saveConsent(categories);
		hideBanner();
		hideModal();
		showReopenButton();
	}

	function handleSaveChoices(e) {
		if (e) {
			e.preventDefault();
		}
		const categories = { essential: true };
		const toggles = document.querySelectorAll('.seopulse-consent-toggle');
		for (let i = 0; i < toggles.length; i++) {
			const toggle = toggles[i];
			const catId = toggle.getAttribute('data-category');
			if (catId && catId !== 'essential') {
				categories[catId] = toggle.checked;
			}
		}
		saveConsent(categories);
		hideBanner();
		hideModal();
		showReopenButton();
	}

	function handleOpenModal(e) {
		if (e) {
			e.preventDefault();
		}
		previousFocusElement = document.activeElement;
		hideBanner();
		showModal();

		// Pre-populate toggles with stored consent
		const stored = getStoredConsent();
		if (stored && stored.categories) {
			const toggles = document.querySelectorAll(
				'.seopulse-consent-toggle'
			);
			for (let i = 0; i < toggles.length; i++) {
				const catId = toggles[i].getAttribute('data-category');
				if (
					catId &&
					catId !== 'essential' &&
					!toggles[i].disabled
				) {
					toggles[i].checked = !!stored.categories[catId];
				}
			}
		}
	}

	function handleCloseModal() {
		hideModal();
		// If no consent stored, show banner again
		if (!getStoredConsent()) {
			showBanner();
		} else {
			showReopenButton();
		}
		// Restore focus
		if (previousFocusElement && previousFocusElement.focus) {
			previousFocusElement.focus();
		}
	}

	// ── Show/Hide helpers ───────────────────────────────────────
	function showBanner() {
		if (!banner) {
			return;
		}
		banner.style.display = '';
		banner.classList.add('seopulse-consent-banner--visible');
		// Apply position class
		const pos = settings.position || 'bottom';
		banner.setAttribute('data-position', pos);
		// Apply theme
		applyTheme(banner);

		showBackdrop();

		// Set initial focus on first button
		requestAnimationFrame(function () {
			const firstBtn = banner.querySelector('button');
			if (firstBtn) {
				firstBtn.focus();
			}
		});
	}

	function hideBanner() {
		if (!banner) {
			return;
		}
		hideBackdrop();
		banner.classList.remove('seopulse-consent-banner--visible');
		banner.classList.add('seopulse-consent-banner--hiding');
		setTimeout(function () {
			banner.style.display = 'none';
			banner.classList.remove('seopulse-consent-banner--hiding');
		}, 300);
	}

	// Optional page backdrop displayed together with the banner
	function showBackdrop() {
		if (!backdrop) {
			return;
		}
		backdrop.style.display = '';
		requestAnimationFrame(function () {
			backdrop.classList.add('seopulse-consent-backdrop--visible');
		});
	}

	function hideBackdrop() {
		if (!backdrop) {
			return;
		}
		backdrop.classList.remove('seopulse-consent-backdrop--visible');
		setTimeout(function () {
			if (
				!backdrop.classList.contains(
					'seopulse-consent-backdrop--visible'
				)
			) {
				backdrop.style.display = 'none';
			}
		}, 300);
	}

	function showModal() {
		if (!modal || !overlay) {
			return;
		}
		overlay.style.display = '';
		modal.style.display = '';
		overlay.setAttribute('aria-hidden', 'false');
		applyTheme(modal);

		requestAnimationFrame(function () {
			overlay.classList.add('seopulse-consent-overlay--visible');
			modal.classList.add('seopulse-consent-modal--visible');
			modal.focus();
			setupFocusTrap(modal);
		});

		// Prevent body scroll
		document.body.style.overflow = 'hidden';
	}

	function hideModal() {
		if (!modal || !overlay) {
			return;
		}
		modal.classList.remove('seopulse-consent-modal--visible');
		overlay.classList.remove('seopulse-consent-overlay--visible');
		modal.classList.add('seopulse-consent-modal--hiding');

		setTimeout(function () {
			modal.style.display = 'none';
			overlay.style.display = 'none';
			overlay.setAttribute('aria-hidden', 'true');
			modal.classList.remove('seopulse-consent-modal--hiding');
		}, 300);

		// Restore body scroll
		document.body.style.overflow = '';
		removeFocusTrap();
	}

	function showReopenButton() {
		if (!reopenBtn) {
			return;
		}
		reopenBtn.style.display = '';
		reopenBtn.classList.add('seopulse-consent-reopen--visible');
	}

	// ── Theme ───────────────────────────────────────────────────
	function applyTheme(el) {
		let theme = settings.theme || 'light';
		if (theme === 'auto') {
			theme =
				window.matchMedia &&
					window.matchMedia('(prefers-color-scheme: dark)').matches
					? 'dark'
					: 'light';
		}
		el.setAttribute('data-theme', theme);
	}

	// ── Focus trap (accessibility) ──────────────────────────────
	let focusTrapHandler = null;

	function setupFocusTrap(container) {
		removeFocusTrap();
		focusTrapHandler = function (e) {
			if (e.key !== 'Tab' && e.keyCode !== 9) {
				return;
			}

			const focusable = container.querySelectorAll(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
			);
			if (focusable.length === 0) {
				return;
			}

			const first = focusable[0];
			const last = focusable[focusable.length - 1];

			if (e.shiftKey) {
				if (document.activeElement === first) {
					e.preventDefault();
					last.focus();
				}
			} else if (document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		};
		document.addEventListener('keydown', focusTrapHandler);
	}

	function removeFocusTrap() {
		if (focusTrapHandler) {
			document.removeEventListener('keydown', focusTrapHandler);
			focusTrapHandler = null;
		}
	}

	// ── Public API ──────────────────────────────────────────────
	window.SEOPulseConsent = {
		/**
		 * Open the preferences modal programmatically
		 */
		openPreferences() {
			handleOpenModal();
		},

		/**
		 * Get current consent state
		 * @return {Object|null}
		 */
		getConsent() {
			const stored = getStoredConsent();
			return stored ? stored.categories : null;
		},

		/**
		 * Check if a specific category is consented
		 * @param {string} category
		 * @return {boolean}
		 */
		hasConsent(category) {
			const stored = getStoredConsent();
			if (!stored || !stored.categories) {
				return false;
			}
			if (category === 'essential') {
				return true;
			}
			return !!stored.categories[category];
		},

		/**
		 * Revoke all consents
		 */
		revokeAll() {
			setCookie(COOKIE_NAME, '', -1);
			try {
				localStorage.removeItem(COOKIE_NAME);
			} catch (e) {
				// Ignore
			}
			if (GCM_V2) {
				updateGoogleConsentMode({ statistics: false, marketing: false, preferences: false });
			}
			purgeAnalyticsCookies();
			showBanner();
			if (reopenBtn) {
				reopenBtn.style.display = 'none';
			}
		},

		/**
		 * Listen for consent changes
		 * @param {Function} callback
		 */
		onChange(callback) {
			document.addEventListener('seopulse:consent', function (e) {
				callback(e.detail.categories);
			});
		},
	};

	// ── Boot ────────────────────────────────────────────────────
	// Visitor has already made a choice: apply consent immediately without waiting for DOMContentLoaded.
	// This script is in the <head>, after the default Consent Mode and before any GTM loading.
	const earlyConsent = getStoredConsent();
	if (earlyConsent && earlyConsent.categories) {
		applyConsent(earlyConsent.categories);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();