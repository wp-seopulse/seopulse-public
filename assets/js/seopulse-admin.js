/**
 * Script pour la meta box SEO
 *
 * @param $
 * @package
 * @package
 */

(function ($) {
	'use strict';

	// Prevent double-initialisation (e.g. script loaded twice)
	if (window.__seopulseAdminInitialised) {
		return;
	}
	window.__seopulseAdminInitialised = true;

	/**
	 * Meta Box - Initialize all functionalities
	 */
	$(document).ready(function () {
		initTabs();
		initCharCounters();
		initMediaUploaders();
		initPreviewUpdates();
		initRobotsPresets();
		initAnalysis();
		initDetailsToggle();
		initModuleToggles();
		initPromoDismiss();
		initSidebarSync();
		convertWpSettingsNotice();
	});

	/**
	 * Meta Box - Init tabs
	 *
	 * Uses namespaced event (.seopulseTabs) to allow safe re-binding
	 * after AJAX metabox refresh without double-binding.
	 */
	function initTabs() {
		$('[class^="seopulse-tab-btn-"]')
			.off('click.seopulseTabs')
			.on('click.seopulseTabs', function (e) {
				e.preventDefault();

				const $btn = $(this);
				const tabId = $btn.data('tab');

				// On récupère le type depuis la classe (tags, analysis, etc.)
				const type = $btn
					.attr('class')
					.match(/seopulse-tab-btn-(\w+)/)[1];

				// Navigation
				$(`.seopulse-tab-btn-${type}`).removeClass('active');
				$btn.addClass('active');

				// Contenu
				$(`.seopulse-tab-pane-${type}`).removeClass('active');
				$(`#seopulse-tab-${tabId}`).addClass('active');
			});
	}

	/**
	 * Meta Box - Init char counters
	 */
	function initCharCounters() {
		// Titre (60 caractères)
		updateCharCounter('#seopulse_meta_title', 60);
		$('#seopulse_meta_title').on('input', function () {
			updateCharCounter('#seopulse_meta_title', 60);
		});

		// Description (160 caractères)
		updateCharCounter('#seopulse_meta_description', 160);
		$('#seopulse_meta_description').on('input', function () {
			updateCharCounter('#seopulse_meta_description', 160);
		});
	}

	/**
	 * Meta Box - Update a char counter
	 * @param fieldSelector
	 * @param maxLength
	 */
	function updateCharCounter(fieldSelector, maxLength) {
		const field = $(fieldSelector);
		const counter = $(
			'.seopulse-char-count[data-field="' +
			fieldSelector.replace('#', '') +
			'"]'
		);

		if (field.length && counter.length) {
			const currentLength = field.val().length;
			counter.text(currentLength + '/' + maxLength);

			// Classes de couleur
			counter.removeClass('warning error');
			if (currentLength > maxLength) {
				counter.addClass('error');
			} else if (currentLength > maxLength * 0.9) {
				counter.addClass('warning');
			}
		}
	}

	/**
	 * Meta Box - Init media uploaders
	 *
	 * Requires wp.media to be available (enqueued via wp_enqueue_media).
	 */
	function initMediaUploaders() {
		if (typeof wp === 'undefined' || !wp.media) {
			return;
		}

		// Open Graph Image
		initSingleMediaUploader(
			'#upload-og-image',
			'#remove-upload-og-image',
			'#seopulse_meta_og_image',
			'#og-image-preview'
		);

		// Twitter Image
		initSingleMediaUploader(
			'#upload-twitter-image',
			'#remove-upload-twitter-image',
			'#seopulse_meta_twitter_image',
			'#twitter-image-preview'
		);
	}

	/**
	 * Meta Box - Init a media uploader
	 * @param uploadBtnSelector
	 * @param removeBtnSelector
	 * @param inputSelector
	 * @param previewSelector
	 */
	function initSingleMediaUploader(
		uploadBtnSelector,
		removeBtnSelector,
		inputSelector,
		previewSelector
	) {
		let mediaUploader;

		// Bouton Upload
		$(uploadBtnSelector).on('click', function (e) {
			e.preventDefault();

			// If the media uploader already exists, open it
			if (mediaUploader) {
				mediaUploader.open();
				return;
			}

			// Create the media uploader
			mediaUploader = wp.media({
				title:
					(window.seopulseAdmin &&
						seopulseAdmin.i18n &&
						seopulseAdmin.i18n.selectImage) ||
					'Choose Image',
				button: {
					text:
						(window.seopulseAdmin &&
							seopulseAdmin.i18n &&
							seopulseAdmin.i18n.useThisImage) ||
						'Use this image',
				},
				multiple: false,
				library: {
					type: 'image',
				},
			});

			// Select an image
			mediaUploader.on('select', function () {
				const attachment = mediaUploader
					.state()
					.get('selection')
					.first()
					.toJSON();
				$(inputSelector).val(attachment.url).trigger('change');
				$(previewSelector).attr('src', attachment.url);
				$(previewSelector).parent('.seopulse-image-preview').show();
			});

			mediaUploader.open();
		});

		// Bouton Remove
		$(removeBtnSelector).on('click', function (e) {
			e.preventDefault();
			$(inputSelector).val('');
			$(previewSelector).attr('src', '');
			$(previewSelector).parent('.seopulse-image-preview').hide();
		});
	}

	/**
	 * Meta Box - Update previews
	 */
	function initPreviewUpdates() {
		// Inline SERP Snippet + Google Preview (both update from title/desc)
		$('#seopulse_meta_title').on('input', function () {
			const val = $(this).val() || $(this).attr('placeholder');
			$('#google-preview-title').text(val);
			$('#serp-snippet-title').text(val);
		});

		$('#seopulse_meta_description').on('input', function () {
			const val = $(this).val() || $(this).attr('placeholder');
			$('#google-preview-description').text(val);
			$('#serp-snippet-desc').text(val);
		});

		// Facebook Preview
		$('#seopulse_meta_og_title').on('input', function () {
			const val = $(this).val() || $(this).attr('placeholder');
			$('.seopulse-facebook-title').text(val);
		});

		$('#seopulse_meta_og_description').on('input', function () {
			const val = $(this).val() || $(this).attr('placeholder');
			$('.seopulse-facebook-description').text(val);
		});

		$('#seopulse_meta_og_image').on('change input', function () {
			const val = $(this).val();
			if (val) {
				$('#facebook-preview-image').attr('src', val);
				$('#facebook-preview-image')
					.parent('.seopulse-facebook-image')
					.show();
			} else {
				$('#facebook-preview-image')
					.parent('.seopulse-facebook-image')
					.hide();
			}
		});

		// Twitter Preview
		$('#seopulse_meta_twitter_title').on('input', function () {
			const val = $(this).val() || $(this).attr('placeholder');
			$('.seopulse-twitter-title').text(val);
		});

		$('#seopulse_meta_twitter_description').on('input', function () {
			const val = $(this).val() || $(this).attr('placeholder');
			$('.seopulse-twitter-description').text(val);
		});

		$('#seopulse_meta_twitter_image').on('change input', function () {
			const val = $(this).val();
			if (val) {
				$('#twitter-preview-image').attr('src', val);
				$('#twitter-preview-image')
					.parent('.seopulse-twitter-image')
					.show();
			} else {
				$('#twitter-preview-image')
					.parent('.seopulse-twitter-image')
					.hide();
			}
		});
	}

	/**
	 * Meta Box - Robots preset buttons
	 *
	 * Fills the robots directive field with the selected preset value.
	 */
	function initRobotsPresets() {
		$('.seopulse-robots-preset-btn')
			.off('click.seopulseRobots')
			.on('click.seopulseRobots', function (e) {
				e.preventDefault();
				$('#seopulse_meta_robots').val($(this).data('value'));
			});
	}

	/**
	 * Meta Box Analysis — Refresh when sidebar triggers an analysis.
	 *
	 * Currently the PHP analysis metabox is not registered (the React
	 * AnalysisPanel handles analysis UI on both classic and block editor).
	 * This handler is kept as a safe no-op in case the PHP metabox is
	 * re-enabled in the future — the $metabox.length guard prevents any
	 * action when the DOM element is absent.
	 */
	function initSidebarSync() {
		window.addEventListener('seopulse:analysis-updated', function (e) {
			const postId = e.detail && e.detail.postId;
			if (!postId) {
				return;
			}

			const $metabox = $('#seopulse-metabox');
			if (!$metabox.length || typeof seopulseAdmin === 'undefined') {
				return;
			}

			$.ajax({
				url: window.ajaxurl,
				method: 'POST',
				data: {
					action: 'seopulse_refresh_metabox',
					post_id: postId,
					_nonce: seopulseAdmin.nonce,
				},
				success(res) {
					if (res.success && res.data && res.data.html) {
						$metabox.replaceWith(res.data.html);
						// Re-bind event handlers on the new DOM
						initTabs();
						initDetailsToggle();
						initAnalysis();
					}
				},
			});
		});
	}

	// Meta Box Analysis - Run Analysis
	/**
	 * Meta Box Analysis — Run Analysis button handler.
	 *
	 * Currently the PHP analysis metabox is not registered (React handles it),
	 * so .seopulse-run-analysis will not exist in the DOM. Kept for
	 * forward-compatibility; namespaced to prevent double-binding.
	 */
	function initAnalysis() {
		if (typeof seopulseAdmin === 'undefined') {
			return;
		}

		$('.seopulse-run-analysis')
			.off('click.seopulseAnalysis')
			.on('click.seopulseAnalysis', function () {
				const postId = $(this).data('post-id');
				const $button = $(this);
				const $icon = $button.find('.dashicons');
				const originalHtml = $button.html();

				$button.prop('disabled', true);
				$icon.addClass('spin');

				$.ajax({
					url: seopulseAdmin.restUrl + '/analyze',
					method: 'POST',
					beforeSend(xhr) {
						xhr.setRequestHeader(
							'X-WP-Nonce',
							seopulseAdmin.nonce
						);
					},
					data: JSON.stringify({
						post_id: postId,
						force_refresh: true,
					}),
					contentType: 'application/json',
					success() {
						window.location.reload();
					},
					error() {
						window.SEOPulse.notify.error(
							seopulseAdmin.i18n.analysisFailed
						);
						$button.prop('disabled', false);
						$icon.removeClass('spin');
						$button.html(originalHtml);
					},
				});
			});
	}

	/**
	 * Meta Box Analysis — Toggle details sections.
	 *
	 * Uses namespaced events for safe re-binding after AJAX refresh.
	 */
	function initDetailsToggle() {
		$('.seopulse-toggle-details-analysis')
			.off('click.seopulseDetails')
			.on('click.seopulseDetails', function () {
				const category = $(this).data('category');
				const $data = $(
					'.seopulse-category-data[data-category="' + category + '"]'
				);

				$(this).toggleClass('active');
				$data.slideToggle(200);
			});

		// Show more / Show less toggle for recommendations
		$('#seopulse-toggle-recs')
			.off('click.seopulseDetails')
			.on('click.seopulseDetails', function () {
				const $btn = $(this);
				const $more = $('#seopulse-more-recs');
				const isHidden = $more.is(':hidden');

				$more.slideToggle(200);
				$btn.text(
					isHidden ? $btn.data('hide') : $btn.data('show')
				);
			});

		// Toggle secondary checks section
		$('.seopulse-checks-toggle')
			.off('click.seopulseDetails')
			.on('click.seopulseDetails', function () {
				const $btn = $(this);
				$btn.toggleClass('active');
				$btn.next('.seopulse-checks-list--secondary').slideToggle(
					200
				);
			});
	}

	/**
	 * Convert native WordPress "Settings saved" admin notices into snackbar
	 * notifications on SEOPulse settings pages.
	 */
	function convertWpSettingsNotice() {
		var params = new URLSearchParams(window.location.search);

		if (params.get('settings-updated') !== 'true') {
			return;
		}

		// Remove the WP native notice
		$('#setting-error-settings_updated, .notice.settings-error').each(
			function () {
				$(this).remove();
			}
		);

		// Show the snackbar instead (defer so the notify API is ready)
		setTimeout(function () {
			if (window._seopulseSettingsNotified) {
				return;
			}
			if (window.SEOPulse && window.SEOPulse.notify) {
				window.SEOPulse.notify.success(
					typeof seopulseModules !== 'undefined' &&
						seopulseModules.i18n &&
						seopulseModules.i18n.saved
						? seopulseModules.i18n.saved
						: 'Settings saved.'
				);
			}
		}, 100);

		// Clean URL to prevent re-triggering on refresh
		if (window.history && window.history.replaceState) {
			params.delete('settings-updated');
			var clean =
				window.location.pathname +
				(params.toString() ? '?' + params.toString() : '');
			window.history.replaceState(null, '', clean);
		}
	}

	// Panel Notifications - Modules toggles (unified handler)
	function initModuleToggles() {
		const $toggles = $('.seopulse-module-toggle');
		const $enableBtns = $('.seopulse-module-page__enable-input');

		if (!$toggles.length && !$enableBtns.length) {
			return;
		}

		/**
		 * Update all notification counters (badge, panel count, aria-label).
		 * @param count
		 */
		function updateNotificationCounters(count) {
			const $badge = $('#seopulse-notification-badge');
			const $panelCount = $('.seopulse-notification-panel__count');
			const $btn = $('#seopulse-notification-btn');

			if (count > 0) {
				$badge.text(count).show();
				if ($panelCount.length) {
					$panelCount.text(count).show();
				}
			} else {
				$badge.hide();
				if ($panelCount.length) {
					$panelCount.hide();
				}
			}

			// Update aria-label for accessibility
			if ($btn.length) {
				$btn.attr('aria-label', count + ' notifications');
			}
		}

		/**
		 * Refresh notifications panel content, counters and badge.
		 */
		function refreshNotificationsPanel() {
			const $list = $('#seopulse-notification-list');

			// Notification panel is now rendered by React on the dashboard;
			// on other pages the panel does not exist — bail out silently.
			if (!$list.length) {
				return;
			}

			$list.addClass('seopulse-notification-list--loading');

			fetch(seopulseModules.restUrl + 'notifications', {
				headers: { 'X-WP-Nonce': seopulseModules.restNonce },
			})
				.then(function (res) {
					return res.ok ? res.json() : null;
				})
				.then(function (items) {
					if (items !== null) {
						updateNotificationCounters(items.length);
					}
				})
				.finally(function () {
					if ($list.length) {
						$list.removeClass(
							'seopulse-notification-list--loading'
						);
					}
				});
		}

		// Expose refreshNotificationsPanel globally for the notification panel
		window.SEOPulse = window.SEOPulse || {};
		window.SEOPulse.refreshNotifications = refreshNotificationsPanel;

		$toggles.on('change', function () {
			const $checkbox = $(this);
			const moduleKey = $checkbox.data('module');
			const isEnabled = $checkbox.is(':checked');
			const $tile = $checkbox.closest('.seopulse-module-tile');

			// Prevent double-clicks during request
			$tile.addClass('seopulse-module-tile--loading');

			fetch(
				seopulseModules.restUrl + 'modules/' + moduleKey + '/toggle',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': seopulseModules.restNonce,
					},
					body: JSON.stringify({ enabled: isEnabled }),
				}
			)
				.then(function (res) {
					if (!res.ok) {
						throw new Error(String(res.status));
					}
					return res.json();
				})
				.then(function (data) {
					// Update tile visual state
					$tile.removeClass(
						'seopulse-module-tile--enabled seopulse-module-tile--disabled'
					);
					$tile.addClass(
						isEnabled
							? 'seopulse-module-tile--enabled'
							: 'seopulse-module-tile--disabled'
					);
					$tile
						.find('.seopulse-module-tile__badge')
						.text(
							isEnabled
								? seopulseModules.i18n.active
								: seopulseModules.i18n.inactive
						);

					// Cascade: visually disable dependent modules
					if (data.cascaded && data.cascaded.length) {
						$.each(data.cascaded, function (_, depKey) {
							const $dep = $(
								'.seopulse-module-tile[data-module="' +
								depKey +
								'"]'
							);
							$dep.removeClass(
								'seopulse-module-tile--enabled'
							).addClass('seopulse-module-tile--disabled');
							$dep.find('.seopulse-module-toggle').prop(
								'checked',
								false
							);
							$dep.find('.seopulse-module-tile__badge').text(
								seopulseModules.i18n.inactive
							);
						});
					}

					// Auto-enabled dependencies: visually enable them
					if (data.autoEnabled && data.autoEnabled.length) {
						$.each(data.autoEnabled, function (_, depKey) {
							const $dep = $(
								'.seopulse-module-tile[data-module="' +
								depKey +
								'"]'
							);
							$dep.removeClass(
								'seopulse-module-tile--disabled'
							).addClass('seopulse-module-tile--enabled');
							$dep.find('.seopulse-module-toggle').prop(
								'checked',
								true
							);
							$dep.find('.seopulse-module-tile__badge').text(
								seopulseModules.i18n.active
							);
						});
					}

					if (window.SEOPulse && window.SEOPulse.notify) {
						window.SEOPulse.notify.success(data.message);
					}
					refreshNotificationsPanel();
				})
				.catch(function () {
					$checkbox.prop('checked', !isEnabled);
					if (window.SEOPulse && window.SEOPulse.notify) {
						window.SEOPulse.notify.error(
							seopulseModules.i18n.error
						);
					}
				})
				.finally(function () {
					$tile.removeClass('seopulse-module-tile--loading');
				});
		});

		// Actions du panneau (ex: bouton "Activer le module" ou tout lien du panneau)
		$(document).on(
			'click',
			'.seopulse-panel-item__action',
			function (e) {
				// Toujours rafraîchir après un clic sur une action du panneau
				setTimeout(refreshNotificationsPanel, 1200);
			}
		);

		// In-page "Enable module" toggle on settings pages
		$(document).on(
			'change',
			'.seopulse-module-page__enable-input',
			function () {
				const $checkbox = $(this);
				const moduleKey = $checkbox.data('module');
				const nonce = $checkbox.data('nonce');

				// Prevent interaction during request
				$checkbox.prop('disabled', true);

				fetch(
					seopulseModules.restUrl +
					'modules/' +
					moduleKey +
					'/toggle',
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': seopulseModules.restNonce,
						},
						body: JSON.stringify({ enabled: true }),
					}
				)
					.then(function (res) {
						if (!res.ok) {
							throw new Error(String(res.status));
						}
						return res.json();
					})
					.then(function (data) {
						if (window.SEOPulse && window.SEOPulse.notify) {
							window.SEOPulse.notify.success(data.message);
						}
						// Reload the page so server re-renders the full module content.
						setTimeout(function () {
							window.location.reload();
						}, 600);
					})
					.catch(function () {
						$checkbox.prop('checked', false);
						$checkbox.prop('disabled', false);
						if (window.SEOPulse && window.SEOPulse.notify) {
							window.SEOPulse.notify.error(
								seopulseModules.i18n.error
							);
						}
					});
			}
		);
	}

	/**
	 * =================================================================
	 * SEOPulse Global Helpers & Utilities
	 * =================================================================
	 */

	// Créer le namespace global
	window.SEOPulse = window.SEOPulse || {};

	/* ----------------------------------------------------------------
	 *  Vanilla Snackbar Renderer (fonctionne sur TOUTES les pages admin)
	 * ---------------------------------------------------------------- */
	const SnackbarRenderer = (function () {
		const CONTAINER_ID = 'seopulse-snackbar-root';
		let counter = 0;

		const ICONS = {
			success:
				'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
			error: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
			warning:
				'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
			info: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
		};

		const DURATIONS = {
			success: 4000,
			error: 6000,
			warning: 5000,
			info: 4000,
		};

		function getContainer() {
			let el = document.getElementById(CONTAINER_ID);
			if (!el) {
				el = document.createElement('div');
				el.id = CONTAINER_ID;
				el.className = 'seopulse-snackbar-root';
				el.setAttribute('role', 'region');
				el.setAttribute('aria-label', 'Notifications');
				document.body.appendChild(el);
			}
			return el;
		}

		function dismiss(snackbar) {
			if (snackbar._dismissed) {
				return;
			}
			snackbar._dismissed = true;
			snackbar.classList.add('seopulse-snackbar--dismissing');
			setTimeout(function () {
				if (snackbar.parentNode) {
					snackbar.parentNode.removeChild(snackbar);
				}
			}, 300);
		}

		function show(type, message, options) {
			options = options || {};
			const container = getContainer();
			counter++;

			const duration = options.duration || DURATIONS[type] || 4000;
			const icon = ICONS[type] || ICONS.info;

			const snackbar = document.createElement('div');
			snackbar.className = 'seopulse-snackbar seopulse-snackbar--' + type;
			snackbar.setAttribute('role', 'status');
			snackbar.setAttribute('aria-live', 'polite');
			snackbar._dismissed = false;

			// Contenu
			let content =
				'<span class="seopulse-snackbar__icon">' +
				icon +
				'</span>' +
				'<span class="seopulse-snackbar__message">' +
				escapeHtml(message) +
				'</span>';

			// Bouton de fermeture
			content +=
				'<button type="button" class="seopulse-snackbar__close" aria-label="' +
				((window.seopulseModules &&
					seopulseModules.i18n &&
					seopulseModules.i18n.dismiss) ||
					'Dismiss') +
				'">' +
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
				'</button>';

			snackbar.innerHTML = content;

			// Événement fermeture manuelle
			snackbar
				.querySelector('.seopulse-snackbar__close')
				.addEventListener('click', function () {
					dismiss(snackbar);
				});

			container.appendChild(snackbar);

			// Auto-dismiss
			if (duration > 0) {
				setTimeout(function () {
					dismiss(snackbar);
				}, duration);
			}

			// Limiter à 5 visibles max
			const children = container.children;
			while (children.length > 5) {
				dismiss(children[0]);
			}
		}

		function escapeHtml(str) {
			const div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		}

		return { show };
	})();

	/**
	 * Notification dispatch API — vanilla-JS placeholder.
	 *
	 * Initialised here so `window.SEOPulse.notify` is always available on
	 * every admin page.  When a React entry-point mounts, it calls
	 * `exposeNotificationStoreGlobally()` which *replaces* this object
	 * with Zustand store-backed methods → snackbar React.
	 *
	 * jQuery modules therefore call `window.SEOPulse.notify.success(msg)`
	 * without any availability check.
	 *
	 * See: assets/src/types/global.d.ts for the TypeScript contract.
	 */
	window.SEOPulse.notify = window.SEOPulse.notify || {
		success(message, options) {
			SnackbarRenderer.show('success', message, options);
		},
		error(message, options) {
			SnackbarRenderer.show('error', message, options);
		},
		warning(message, options) {
			SnackbarRenderer.show('warning', message, options);
		},
		info(message, options) {
			SnackbarRenderer.show('info', message, options);
		},
	};

	/**
	 * Helper pour les requêtes AJAX avec notifications
	 * @param options
	 */
	window.SEOPulse.ajax = function (options) {
		const defaults = {
			type: 'POST',
			dataType: 'json',
			autoNotify: true,
		};

		const settings = $.extend({}, defaults, options);

		const originalSuccess = settings.success;
		const originalError = settings.error;

		settings.success = function (response) {
			if (settings.autoNotify) {
				if (response.success) {
					window.SEOPulse.notify.success(
						response.data && response.data.message
							? response.data.message
							: (window.seopulseModules &&
								seopulseModules.i18n &&
								seopulseModules.i18n.operationSuccess) ||
							'Operation successful'
					);
				} else {
					window.SEOPulse.notify.error(
						response.data && response.data.message
							? response.data.message
							: (window.seopulseModules &&
								seopulseModules.i18n &&
								seopulseModules.i18n.error) ||
							'An error occurred'
					);
				}
			}

			if (originalSuccess) {
				originalSuccess.apply(this, arguments);
			}
		};

		settings.error = function () {
			if (settings.autoNotify) {
				window.SEOPulse.notify.error(
					settings.errorMessage ||
					(window.seopulseModules &&
						seopulseModules.i18n &&
						seopulseModules.i18n.networkError) ||
					'Network error occurred'
				);
			}

			if (originalError) {
				originalError.apply(this, arguments);
			}
		};

		return $.ajax(settings);
	};

	/**
	 * Process backend notifications injected via wp_localize_script.
	 *
	 * AdminNotificationBridge (PHP) serialises queued notifications into
	 * window.seopulseNotifications.queue.  On editor pages the React
	 * entry-point handles this, but on non-React admin pages (settings,
	 * post-list bulk actions…) this global script takes care of it.
	 */
	(function processBackendNotifications() {
		if (
			typeof window.seopulseNotifications === 'undefined' ||
			!window.seopulseNotifications.queue ||
			!window.seopulseNotifications.queue.length
		) {
			return;
		}

		const queue = window.seopulseNotifications.queue;

		for (let i = 0; i < queue.length; i++) {
			const n = queue[i];
			const type = n.type || 'info';
			const msg = n.message || '';

			if (
				msg &&
				window.SEOPulse &&
				window.SEOPulse.notify &&
				typeof window.SEOPulse.notify[type] === 'function'
			) {
				window.SEOPulse.notify[type](msg, n.options || {});
			}
		}

		// Mark as processed so the React entry-point doesn't re-dispatch
		window.seopulseNotifications.queue = [];
	})();

	/**
	 * Dashboard — Module toggle tiles
	 * (Handled by the unified initModuleToggles called from document.ready)
	 */

	/**
	 * Dashboard - Notification Panel (slide-in)
	 */
	(function () {
		$(document).ready(function () {
			const $btn = $('#seopulse-notification-btn');
			const $panel = $('#seopulse-notification-panel');
			const $overlay = $('#seopulse-panel-overlay');
			const $close = $('#seopulse-panel-close');
			let isOpen = false;
			let lastFocused = null;

			// Bail if elements are missing (not on dashboard)
			if (!$btn.length || !$panel.length) {
				return;
			}

			/**
			 * Open the notification panel
			 */
			function openPanel() {
				if (isOpen) {
					return;
				}

				// Close the visibility panel if open
				if (
					window.SEOPulse &&
					window.SEOPulse.visibilityPanel &&
					window.SEOPulse.visibilityPanel.isOpen()
				) {
					window.SEOPulse.visibilityPanel.close();
				}

				isOpen = true;
				lastFocused = document.activeElement;

				$panel
					.addClass('seopulse-notification-panel--open')
					.attr('aria-hidden', 'false');
				$overlay
					.addClass('seopulse-panel-overlay--visible')
					.attr('aria-hidden', 'false');
				$btn.addClass('seopulse-notification-button--active').attr(
					'aria-expanded',
					'true'
				);

				// Focus the panel for keyboard accessibility
				setTimeout(function () {
					$panel.trigger('focus');
				}, 50);

				// Prevent body scroll
				$('body').css('overflow', 'hidden');

				// Auto-refresh notifications when panel is opened
				if (
					window.SEOPulse &&
					typeof window.SEOPulse.refreshNotifications === 'function'
				) {
					window.SEOPulse.refreshNotifications();
				}
			}

			/**
			 * Close the notification panel
			 */
			function closePanel() {
				if (!isOpen) {
					return;
				}
				isOpen = false;

				$panel
					.removeClass('seopulse-notification-panel--open')
					.attr('aria-hidden', 'true');
				$overlay
					.removeClass('seopulse-panel-overlay--visible')
					.attr('aria-hidden', 'true');
				$btn.removeClass('seopulse-notification-button--active').attr(
					'aria-expanded',
					'false'
				);

				// Restore body scroll
				$('body').css('overflow', '');

				// Restore focus to the bell button
				if (lastFocused) {
					$(lastFocused).trigger('focus');
					lastFocused = null;
				}
			}

			/**
			 * Toggle panel
			 */
			function togglePanel() {
				if (isOpen) {
					closePanel();
				} else {
					openPanel();
				}
			}

			// Bell button click
			$btn.on('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				togglePanel();
			});

			// Close button
			$close.on('click', function (e) {
				e.preventDefault();
				closePanel();
			});

			// Overlay click
			$overlay.on('click', function () {
				closePanel();
			});

			// Keyboard: ESC to close, Tab trap within panel
			$(document).on('keydown', function (e) {
				if (!isOpen) {
					return;
				}

				// Escape
				if (e.key === 'Escape' || e.keyCode === 27) {
					e.preventDefault();
					closePanel();
					return;
				}

				// Tab trap within the panel
				if (e.key === 'Tab' || e.keyCode === 9) {
					const $focusable = $panel
						.find(
							'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
						)
						.filter(':visible');

					if ($focusable.length === 0) {
						return;
					}

					const $first = $focusable.first();
					const $last = $focusable.last();

					if (e.shiftKey) {
						if (
							document.activeElement === $first[0] ||
							document.activeElement === $panel[0]
						) {
							e.preventDefault();
							$last.trigger('focus');
						}
					} else if (document.activeElement === $last[0]) {
						e.preventDefault();
						$first.trigger('focus');
					}
				}
			});

			// Expose toggle for external scripts
			window.SEOPulse = window.SEOPulse || {};
			window.SEOPulse.notificationPanel = {
				open: openPanel,
				close: closePanel,
				toggle: togglePanel,
				isOpen() {
					return isOpen;
				},
			};
		});
	})();

	/**
	 * Dashboard - Visibility Panel (slide-in)
	 */
	(function () {
		$(document).ready(function () {
			const STORAGE_KEY = 'seopulse_visibility';
			const $btn = $('#seopulse-visibility-btn');
			const $panel = $('#seopulse-visibility-panel');
			const $overlay = $('#seopulse-panel-overlay');
			const $close = $('#seopulse-visibility-close');
			let isOpen = false;
			let lastFocused = null;

			// Bail if elements are missing (not on dashboard)
			if (!$btn.length || !$panel.length) {
				return;
			}

			/**
			 * Load saved visibility state from localStorage
			 */
			function loadState() {
				let saved = null;
				try {
					saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
				} catch (e) {
					/* ignore */
				}
				if (!saved || typeof saved !== 'object') {
					return;
				}

				$panel.find('.seopulse-toggle__input').each(function () {
					const target = $(this).data('target');
					if (saved.hasOwnProperty(target) && !saved[target]) {
						$(this).prop('checked', false);
						const $el = $('.' + target);
						const $block = $el.closest('.seopulse-dnd-block');
						($block.length ? $block : $el).hide();
					}
				});
			}

			/**
			 * Save current visibility state to localStorage
			 */
			function saveState() {
				const state = {};
				$panel.find('.seopulse-toggle__input').each(function () {
					state[$(this).data('target')] =
						$(this).is(':checked');
				});
				try {
					localStorage.setItem(
						STORAGE_KEY,
						JSON.stringify(state)
					);
				} catch (e) {
					/* ignore */
				}
			}

			/**
			 * Open the visibility panel
			 */
			function openPanel() {
				if (isOpen) {
					return;
				}

				// Close the notification panel if open
				if (
					window.SEOPulse &&
					window.SEOPulse.notificationPanel &&
					window.SEOPulse.notificationPanel.isOpen()
				) {
					window.SEOPulse.notificationPanel.close();
				}

				isOpen = true;
				lastFocused = document.activeElement;

				$panel
					.addClass('seopulse-visibility-panel--open')
					.attr('aria-hidden', 'false');
				$overlay
					.addClass('seopulse-panel-overlay--visible')
					.attr('aria-hidden', 'false');
				$btn.addClass('seopulse-visibility-button--active').attr(
					'aria-expanded',
					'true'
				);

				setTimeout(function () {
					$panel.trigger('focus');
				}, 50);

				$('body').css('overflow', 'hidden');
			}

			/**
			 * Close the visibility panel
			 */
			function closePanel() {
				if (!isOpen) {
					return;
				}
				isOpen = false;

				$panel
					.removeClass('seopulse-visibility-panel--open')
					.attr('aria-hidden', 'true');
				$overlay
					.removeClass('seopulse-panel-overlay--visible')
					.attr('aria-hidden', 'true');
				$btn.removeClass('seopulse-visibility-button--active').attr(
					'aria-expanded',
					'false'
				);

				$('body').css('overflow', '');

				if (lastFocused) {
					$(lastFocused).trigger('focus');
					lastFocused = null;
				}
			}

			/**
			 * Toggle panel
			 */
			function togglePanel() {
				if (isOpen) {
					closePanel();
				} else {
					openPanel();
				}
			}

			// Button click
			$btn.on('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				togglePanel();
			});

			// Close button
			$close.on('click', function (e) {
				e.preventDefault();
				closePanel();
			});

			// Overlay click — close whichever panel is open
			$overlay.on('click', function () {
				closePanel();
			});

			// Keyboard: ESC to close, Tab trap within panel
			$(document).on('keydown', function (e) {
				if (!isOpen) {
					return;
				}

				if (e.key === 'Escape' || e.keyCode === 27) {
					e.preventDefault();
					closePanel();
					return;
				}

				if (e.key === 'Tab' || e.keyCode === 9) {
					const $focusable = $panel
						.find(
							'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
						)
						.filter(':visible');

					if ($focusable.length === 0) {
						return;
					}

					const $first = $focusable.first();
					const $last = $focusable.last();

					if (e.shiftKey) {
						if (
							document.activeElement === $first[0] ||
							document.activeElement === $panel[0]
						) {
							e.preventDefault();
							$last.trigger('focus');
						}
					} else if (document.activeElement === $last[0]) {
						e.preventDefault();
						$first.trigger('focus');
					}
				}
			});

			// Toggle switches — show/hide sections
			$panel.on('change', '.seopulse-toggle__input', function () {
				const target = $(this).data('target');
				const visible = $(this).is(':checked');
				const $el = $('.' + target);
				// If inside a dnd-block wrapper, toggle the wrapper
				const $block = $el.closest('.seopulse-dnd-block');
				($block.length ? $block : $el).toggle(visible);
				saveState();
			});

			// Apply saved state on load
			loadState();

			// Expose for external scripts
			window.SEOPulse = window.SEOPulse || {};
			window.SEOPulse.visibilityPanel = {
				open: openPanel,
				close: closePanel,
				toggle: togglePanel,
				isOpen() {
					return isOpen;
				},
			};
		});
	})();

	/**
	 * Dashboard - Drag & Drop block reordering (WordPress-style 2-column grid)
	 *
	 * Two drag zones:
	 *   1. Column blocks   – sortable within / between the two columns
	 *   2. Fullwidth blocks – sortable at the grid level (always span 2 cols)
	 */
	(function () {
		$(document).ready(function () {
			const STORAGE_KEY = 'seopulse_block_order';
			const $wrap = $('#seopulse-dashboard-wp');

			if (!$wrap.length) {
				return;
			}

			const $columns = $wrap.find('.seopulse-dashboard-wp__col');
			let dragSrcEl = null;
			let isFullwidthDrag = false;
			const $placeholder = $(
				'<div class="seopulse-dnd-placeholder"></div>'
			);

			/* ── helpers ─────────────────────────────────── */

			function getDragAfterElement($parent, y, selector) {
				const $items = $parent
					.children(selector)
					.not(dragSrcEl)
					.not('.seopulse-dnd-placeholder');
				let closest = null;
				let closestOffset = Number.NEGATIVE_INFINITY;

				$items.each(function () {
					const box = this.getBoundingClientRect();
					const offset = y - box.top - box.height / 2;
					if (offset < 0 && offset > closestOffset) {
						closestOffset = offset;
						closest = this;
					}
				});
				return closest;
			}

			/**
			 * For fullwidth blocks we need to find position among ALL
			 * direct grid children (columns + fullwidth blocks).
			 * @param y
			 */
			function getGridAfterElement(y) {
				const $children = $wrap
					.children()
					.not(dragSrcEl)
					.not('.seopulse-dnd-placeholder');
				let closest = null;
				let closestOffset = Number.NEGATIVE_INFINITY;

				$children.each(function () {
					const box = this.getBoundingClientRect();
					const offset = y - box.top - box.height / 2;
					if (offset < 0 && offset > closestOffset) {
						closestOffset = offset;
						closest = this;
					}
				});
				return closest;
			}

			/* ── save / restore ──────────────────────────── */

			function saveOrder() {
				const layout = {};

				// Column contents
				$columns.each(function () {
					const ids = [];
					$(this)
						.children('.seopulse-dnd-block')
						.each(function () {
							ids.push($(this).data('block-id'));
						});
					layout[this.id] = ids;
				});

				// Grid-level order (column ids + fullwidth block ids)
				const gridOrder = [];
				$wrap.children().each(function () {
					if ($(this).hasClass('seopulse-dashboard-wp__col')) {
						gridOrder.push(this.id);
					} else if (
						$(this).hasClass('seopulse-dnd-block--fullwidth')
					) {
						gridOrder.push($(this).data('block-id'));
					}
				});
				layout._gridOrder = gridOrder;

				try {
					localStorage.setItem(
						STORAGE_KEY,
						JSON.stringify(layout)
					);
				} catch (e) {
					/* ignore */
				}
			}

			function restoreOrder() {
				let saved = null;
				try {
					saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
				} catch (e) {
					/* ignore */
				}
				if (!saved || typeof saved !== 'object') {
					return;
				}

				// 1. Restore grid-level order (columns + fullwidth blocks)
				const gridOrder = saved._gridOrder;
				if (Array.isArray(gridOrder) && gridOrder.length) {
					const gridItems = {};
					$wrap.children().each(function () {
						const key = $(this).hasClass(
							'seopulse-dashboard-wp__col'
						)
							? this.id
							: $(this).data('block-id');
						if (key) {
							gridItems[key] = $(this);
						}
					});

					gridOrder.forEach(function (key) {
						if (gridItems[key]) {
							$wrap.append(gridItems[key]);
						}
					});
				}

				// 2. Restore block order within columns
				const colBlocks = {};
				$columns.find('.seopulse-dnd-block').each(function () {
					colBlocks[$(this).data('block-id')] = $(this);
				});

				$columns.each(function () {
					const ids = saved[this.id];
					if (!Array.isArray(ids)) {
						return;
					}
					const $col = $(this);
					ids.forEach(function (id) {
						if (colBlocks[id]) {
							$col.append(colBlocks[id]);
							delete colBlocks[id];
						}
					});
				});
			}

			/* ── drag handle activation ──────────────────── */

			$wrap.on('mousedown', '.seopulse-dnd-block__handle', function () {
				$(this)
					.closest('.seopulse-dnd-block')
					.attr('draggable', 'true');
			});

			$wrap.on(
				'mouseup mouseleave',
				'.seopulse-dnd-block__handle',
				function () {
					// Cleaned up after dragend
				}
			);

			/* ── drag start ──────────────────────────────── */

			$wrap.on('dragstart', '.seopulse-dnd-block', function (e) {
				dragSrcEl = this;
				isFullwidthDrag = $(this).hasClass(
					'seopulse-dnd-block--fullwidth'
				);
				$(this).addClass('seopulse-dnd-block--dragging');
				e.originalEvent.dataTransfer.effectAllowed = 'move';
				e.originalEvent.dataTransfer.setData(
					'text/plain',
					$(this).data('block-id')
				);

				// Placeholder inherits fullwidth when needed
				$placeholder.toggleClass(
					'seopulse-dnd-placeholder--fullwidth',
					isFullwidthDrag
				);
			});

			/* ── column zone (non-fullwidth blocks) ──────── */

			$columns.on('dragover', function (e) {
				if (isFullwidthDrag) {
					return;
				}
				e.preventDefault();
				e.originalEvent.dataTransfer.dropEffect = 'move';

				const $col = $(this);
				const afterEl = getDragAfterElement(
					$col,
					e.originalEvent.clientY,
					'.seopulse-dnd-block'
				);
				$placeholder.detach();

				if (afterEl) {
					$(afterEl).before($placeholder);
				} else {
					$col.append($placeholder);
				}

				$columns.removeClass('seopulse-dashboard-wp__col--dragover');
				$col.addClass('seopulse-dashboard-wp__col--dragover');
			});

			$columns.on('dragenter', function (e) {
				if (isFullwidthDrag) {
					return;
				}
				e.preventDefault();
			});

			$columns.on('drop', function (e) {
				if (isFullwidthDrag) {
					return;
				}
				e.preventDefault();
				if (!dragSrcEl) {
					return;
				}

				const $col = $(this);
				$placeholder.detach();

				const afterEl = getDragAfterElement(
					$col,
					e.originalEvent.clientY,
					'.seopulse-dnd-block'
				);
				if (afterEl) {
					$(afterEl).before(dragSrcEl);
				} else {
					$col.append(dragSrcEl);
				}

				$columns.removeClass('seopulse-dashboard-wp__col--dragover');
				saveOrder();
			});

			/* ── grid zone (fullwidth blocks) ────────────── */

			$wrap.on('dragover', function (e) {
				if (!isFullwidthDrag) {
					return;
				}
				e.preventDefault();
				e.originalEvent.dataTransfer.dropEffect = 'move';

				const afterEl = getGridAfterElement(e.originalEvent.clientY);
				$placeholder.detach();

				if (afterEl) {
					$(afterEl).before($placeholder);
				} else {
					$wrap.append($placeholder);
				}
			});

			$wrap.on('dragenter', function (e) {
				if (!isFullwidthDrag) {
					return;
				}
				e.preventDefault();
			});

			$wrap.on('drop', function (e) {
				if (!isFullwidthDrag) {
					return;
				}
				e.preventDefault();
				if (!dragSrcEl) {
					return;
				}

				$placeholder.detach();

				const afterEl = getGridAfterElement(e.originalEvent.clientY);
				if (afterEl) {
					$(afterEl).before(dragSrcEl);
				} else {
					$wrap.append(dragSrcEl);
				}

				saveOrder();
			});

			/* ── drag end (shared) ───────────────────────── */

			$wrap.on('dragend', '.seopulse-dnd-block', function () {
				$(this)
					.removeClass('seopulse-dnd-block--dragging')
					.removeAttr('draggable');
				$placeholder
					.detach()
					.removeClass('seopulse-dnd-placeholder--fullwidth');
				$columns.removeClass('seopulse-dashboard-wp__col--dragover');
				dragSrcEl = null;
				isFullwidthDrag = false;
			});

			/* ── init ────────────────────────────────────── */

			restoreOrder();

			window.SEOPulse = window.SEOPulse || {};
			window.SEOPulse.dashboardDnD = {
				resetOrder() {
					try {
						localStorage.removeItem(STORAGE_KEY);
					} catch (e) {
						/* ignore */
					}
					location.reload();
				},
			};
		});
	})();

	/**
	 * Dashboard - Setup wizard button handler
	 * The button is now an <a> link to the wizard page.
	 * This handler is kept as a fallback for any remaining <button> elements.
	 */
	(function () {
		$(document).ready(function () {
			$('button.seopulse-setup-card__button').on(
				'click',
				function (e) {
					e.preventDefault();
					window.location.href = ajaxurl.replace(
						'admin-ajax.php',
						'admin.php?page=seopulse-setup-wizard'
					);
				}
			);
		});
	})();

	/**
	 * Promo blocks — dismiss via localStorage (30-day expiry).
	 */
	function initPromoDismiss() {
		const KEY_PREFIX = 'seopulse_promo_dismissed_';

		// Hide already-dismissed promos on load
		$('.seopulse-promo').each(function () {
			const id = $(this).attr('data-promo-id');
			const raw = localStorage.getItem(KEY_PREFIX + id);
			if (raw && Date.now() < parseInt(raw, 10)) {
				$(this).remove();
			}
		});

		// Handle dismiss click
		$(document).on('click', '.seopulse-promo__dismiss', function () {
			const btn = $(this);
			const id = btn.attr('data-promo-id');
			const days = parseInt(
				btn.attr('data-dismiss-duration') || '30',
				10
			);
			const expiry = Date.now() + days * 86400000;
			localStorage.setItem(KEY_PREFIX + id, String(expiry));
			btn.closest('.seopulse-promo').fadeOut(250, function () {
				$(this).remove();
			});
		});
	}

	/* ── DASHBOARD COCKPIT — Technical Checks filter chips ─────── */
	(function () {
		$(document).ready(function () {
			$(document).on(
				'click',
				'[data-checks-widget] .seopulse-checks-chip',
				function () {
					var $chip = $(this);
					var $widget = $chip.closest('[data-checks-widget]');
					var filter = $chip.attr('data-filter');

					$widget
						.find('.seopulse-checks-chip')
						.removeClass('seopulse-checks-chip--active');
					$chip.addClass('seopulse-checks-chip--active');

					var $checks = $widget.find('[data-check-status]');
					var visible = 0;

					$checks.each(function () {
						var match =
							filter === 'all' ||
							$(this).attr('data-check-status') === filter;
						$(this).toggle(match);
						if (match) visible++;
					});

					$widget
						.find('.seopulse-checks-empty')
						.toggle(visible === 0);
				}
			);
		});
	})();

	/* ── DASHBOARD COCKPIT — Counter-up animation ───────────────── */
	(function () {
		$(document).ready(function () {
			var items = document.querySelectorAll('[data-counter]');
			if (!items.length) return;

			function easeOut(t) {
				return 1 - Math.pow(1 - t, 3);
			}
			var DURATION = 900;

			function animateCounter(el) {
				var target = parseInt(el.getAttribute('data-counter'), 10);
				if (isNaN(target)) return;
				var start = performance.now();
				function step(now) {
					var elapsed = Math.min((now - start) / DURATION, 1);
					el.textContent = Math.round(easeOut(elapsed) * target);
					if (elapsed < 1) requestAnimationFrame(step);
					else el.textContent = target;
				}
				requestAnimationFrame(step);
			}

			if ('IntersectionObserver' in window) {
				var obs = new IntersectionObserver(
					function (entries) {
						entries.forEach(function (e) {
							if (e.isIntersecting) {
								animateCounter(e.target);
								obs.unobserve(e.target);
							}
						});
					},
					{ threshold: 0.3 }
				);
				items.forEach(function (el) {
					obs.observe(el);
				});
			} else {
				items.forEach(function (el) {
					animateCounter(el);
				});
			}
		});
	})();

	/* ── DASHBOARD COCKPIT — Command Palette (with action support) ─ */
	(function () {
		$(document).ready(function () {
			var $overlay = $('#seopulse-cmd-overlay');
			if (!$overlay.length) return;

			var $input = $overlay.find('.seopulse-cmd-palette__input');
			var $list = $overlay.find('.seopulse-cmd-palette__list');
			var $empty = $overlay.find('.seopulse-cmd-palette__empty');
			var commands = window.seopulseCmdCommands || [];
			var focusedIndex = -1;

			function open() {
				$overlay.addClass('seopulse-cmd-overlay--open');
				$input.val('');
				renderCommands('');
				setTimeout(function () {
					$input.trigger('focus');
				}, 60);
			}

			function close() {
				$overlay.removeClass('seopulse-cmd-overlay--open');
				focusedIndex = -1;
			}

			function esc(s) {
				return $('<span>').text(s).html();
			}

			function executeItem($item) {
				var action = $item.attr('data-action');
				if (action) {
					close();
					var fn =
						window.seopulseActions &&
						window.seopulseActions[action];
					if (typeof fn === 'function') fn();
				} else {
					var href = $item.attr('href');
					if (href && href !== '#') {
						close();
						window.location.href = href;
					}
				}
			}

			function renderCommands(query) {
				var q = query.toLowerCase().trim();
				var filtered = q
					? commands.filter(function (c) {
						return (
							c.label.toLowerCase().indexOf(q) > -1 ||
							(c.desc || '').toLowerCase().indexOf(q) > -1
						);
					})
					: commands;

				$list.empty();
				focusedIndex = -1;

				if (!filtered.length) {
					$list.hide();
					$empty.show();
					return;
				}
				$list.show();
				$empty.hide();

				filtered.forEach(function (cmd) {
					var isAction = cmd.type === 'action';
					var $item = $('<a>')
						.addClass('seopulse-cmd-palette__item')
						.attr('href', isAction ? '#' : cmd.url)
						.html(
							'<span class="dashicons ' +
							esc(cmd.icon) +
							'"></span>' +
							'<span class="seopulse-cmd-palette__item-label">' +
							esc(cmd.label) +
							'</span>' +
							(cmd.desc
								? '<span class="seopulse-cmd-palette__item-desc">' +
								esc(cmd.desc) +
								'</span>'
								: '') +
							(isAction
								? '<span class="seopulse-cmd-palette__item-badge">\u26a1</span>'
								: '')
						)
						.on('click', function (e) {
							e.preventDefault();
							executeItem($(this));
						});
					if (isAction) $item.attr('data-action', cmd.action);
					$list.append($item);
				});
			}

			function moveFocus(dir) {
				var $items = $list.find('.seopulse-cmd-palette__item');
				if (!$items.length) return;
				$items.removeClass('seopulse-cmd-palette__item--focused');
				focusedIndex =
					(focusedIndex + dir + $items.length) % $items.length;
				$items
					.eq(focusedIndex)
					.addClass('seopulse-cmd-palette__item--focused');
			}

			// 1. Disable the native WordPress shortcut (Ctrl+K → command center)
			if (window.wp && window.wp.data) {
				try {
					window.wp.data
						.dispatch('core/keyboard-shortcuts')
						.unregisterShortcut('core/open-command-center');
				} catch (err) {
					/* API not available */
				}
			}

			// 2. Save our own shortcut in the WordPress registry
			if (window.wp && window.wp.data) {
				try {
					window.wp.data
						.dispatch('core/keyboard-shortcuts')
						.registerShortcut({
							name: 'seopulse/command-palette',
							category: 'seopulse',
							description: 'Open SEOPulse Command Palette',
							keyCombination: {
								modifier: 'primary',
								character: 'k',
							},
						});
				} catch (err) {
					/* API non disponible */
				}
			}

			// 3. Intercept Ctrl/Cmd+K in the capture phase (before any other handler)
			document.addEventListener(
				'keydown',
				function (e) {
					if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
						e.preventDefault();
						e.stopImmediatePropagation();
						$overlay.hasClass('seopulse-cmd-overlay--open')
							? close()
							: open();
					}
				},
				true
			);

			// Keyboard navigation when the palette is open
			$(document).on('keydown.seopulseCmdPalette', function (e) {
				if (!$overlay.hasClass('seopulse-cmd-overlay--open'))
					return;
				if (e.key === 'Escape') {
					close();
					return;
				}
				if (e.key === 'ArrowDown') {
					e.preventDefault();
					moveFocus(1);
					return;
				}
				if (e.key === 'ArrowUp') {
					e.preventDefault();
					moveFocus(-1);
					return;
				}
				if (e.key === 'Enter' && focusedIndex >= 0) {
					executeItem(
						$list.find('.seopulse-cmd-palette__item--focused')
					);
				}
			});

			$input.on('input', function () {
				renderCommands($(this).val());
			});

			// Click on backdrop closes
			$overlay.on('click', function (e) {
				if ($(e.target).is($overlay)) close();
			});

			// Toolbar button
			$(document).on('click', '#seopulse-cmd-btn', function () {
				open();
			});
		});
	})();

	/* ── DASHBOARD COCKPIT — KPI Refresh ─────────────────────── */
	(function () {
		$(document).ready(function () {
			var $cluster = $('#seopulse-kpi-cluster');
			var $btn = $('#seopulse-kpi-refresh-btn');
			var $last = $('#seopulse-kpi-last-updated');
			if (!$cluster.length || !$btn.length) return;

			function animateVal($el, newVal) {
				var target = parseInt(newVal, 10);
				if (isNaN(target)) {
					$el.text(newVal);
					return;
				}
				$el.attr('data-counter', target);
				var start = performance.now(),
					dur = 700;
				function ease(t) {
					return 1 - Math.pow(1 - t, 3);
				}
				(function step(now) {
					var p = Math.min((now - start) / dur, 1);
					$el.text(Math.round(ease(p) * target));
					if (p < 1) requestAnimationFrame(step);
					else $el.text(target);
				})(performance.now());
			}

			function doRefresh() {
				var nonce = $cluster.attr('data-refresh-nonce');
				$btn.addClass('seopulse-kpi-refresh__btn--spinning').prop(
					'disabled',
					true
				);
				$.post(ajaxurl, {
					action: 'seopulse_refresh_kpis',
					_nonce: nonce,
				})
					.done(function (res) {
						if (!res || !res.success) return;
						var d = res.data;
						animateVal(
							$cluster.find('[data-kpi="score"]'),
							d.score
						);
						animateVal(
							$cluster.find('[data-kpi="analyzed"]'),
							d.analyzed
						);
						animateVal(
							$cluster.find('[data-kpi="needs_improvement"]'),
							d.needs_improvement
						);
						animateVal(
							$cluster.find('[data-kpi="fail_count"]'),
							d.fail_count
						);
						$cluster
							.find('[data-kpi="sitemap_label"]')
							.text(d.sitemap_label);
						$cluster
							.find('[data-kpi="sitemap_sub"]')
							.text(d.sitemap_sub);
						var now = new Date();
						var hh = String(now.getHours()).padStart(2, '0');
						var mm = String(now.getMinutes()).padStart(2, '0');
						$last
							.text(hh + ':' + mm)
							.addClass('seopulse-kpi-refresh__last--visible');
					})
					.always(function () {
						$btn.removeClass(
							'seopulse-kpi-refresh__btn--spinning'
						).prop('disabled', false);
					});
			}

			$btn.on('click', doRefresh);

			// Expose for command palette
			window.seopulseActions = window.seopulseActions || {};
			window.seopulseActions['refresh-kpis'] = doRefresh;
		});
	})();

	/* ── DASHBOARD COCKPIT — Arc Gauge tooltip ────────────────── */
	(function () {
		$(document).ready(function () {
			var $tooltip = $(
				'<div class="seopulse-gauge-tooltip" id="seopulse-gauge-tooltip" aria-hidden="true" role="tooltip"></div>'
			).appendTo('body');

			function positionTooltip(e) {
				var x = e.clientX + 16;
				var y = e.clientY - 8;
				var tw = $tooltip.outerWidth() || 180;
				var th = $tooltip.outerHeight() || 100;
				if (x + tw > window.innerWidth - 10) x = e.clientX - tw - 16;
				if (y + th > window.innerHeight - 10)
					y = window.innerHeight - th - 10;
				$tooltip.css({ left: x, top: y });
			}

			$(document)
				.on('mouseenter', '.seopulse-arc-gauge', function (e) {
					var raw = $(this).attr('data-gauge-stats');
					if (!raw) return;
					var s;
					try {
						s = JSON.parse(raw);
					} catch (x) {
						return;
					}
					$tooltip
						.html(
							'<div class="seopulse-gauge-tooltip__row"><span>' +
							(s.analyzed || 0) +
							'</span> pages analyzed</div>' +
							'<div class="seopulse-gauge-tooltip__row seopulse-gauge-tooltip__row--warn"><span>' +
							(s.needs_improvement || 0) +
							'</span> need improvement</div>' +
							'<div class="seopulse-gauge-tooltip__row"><span>' +
							(s.missing_meta || 0) +
							'</span> missing meta</div>' +
							'<div class="seopulse-gauge-tooltip__row"><span>' +
							(s.missing_alt || 0) +
							'</span> missing alt text</div>'
						)
						.addClass('seopulse-gauge-tooltip--visible');
					positionTooltip(e);
				})
				.on('mousemove', '.seopulse-arc-gauge', function (e) {
					if (
						$tooltip.hasClass('seopulse-gauge-tooltip--visible')
					)
						positionTooltip(e);
				})
				.on('mouseleave', '.seopulse-arc-gauge', function () {
					$tooltip.removeClass('seopulse-gauge-tooltip--visible');
				});
		});
	})();

	/* ── Anti-FOUC: reveal dashboard after restoreOrder() + loadState() ── */
	// This $(document).ready() is registered last, so it runs after all other
	// ready callbacks (DnD restoreOrder, visibility loadState, counter init…)
	// have finished reordering and hiding blocks.
	$(document).ready(function () {
		var $dashboard = $('#seopulse-dashboard-wp');
		if ($dashboard.length) {
			$dashboard.addClass('seopulse-dashboard--ready');
		}
	});
})(jQuery);
