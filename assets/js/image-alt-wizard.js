/**
 * Image ALT Wizard — batch fill and settings management
 *
 * @param $
 * @package
 * @since 3.1.0
 */
( function ( $ ) {
	'use strict';

	const config = window.seopulseImageAlt || {};
	const restUrl = config.restUrl || '';
	const nonce = config.nonce || '';
	const i18n = config.i18n || {};

	// ──────────────────────────────────────────────
	//  TAB NAVIGATION
	// ──────────────────────────────────────────────

	$( '.seopulse-image-alt__nav-btn' ).on( 'click', function () {
		const tab = $( this ).data( 'tab' );
		$( '.seopulse-image-alt__nav-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		$( '.seopulse-image-alt__panel' ).removeClass( 'active' );
		$( '#panel-' + tab ).addClass( 'active' );
	} );

	// ──────────────────────────────────────────────
	//  SAVE SETTINGS (on main Meta SEO form submit)
	// ──────────────────────────────────────────────

	function saveImageAltSettings() {
		const data = {
			enabled: $( '#seopulse-alt-enabled' ).is( ':checked' ),
			strategy:
				$( 'input[name="seopulse-alt-strategy"]:checked' ).val() ||
				'filename',
			overwrite: $( '#seopulse-alt-overwrite' ).is( ':checked' ),
			rename_enabled: $( '#seopulse-rename-enabled' ).is( ':checked' ),
		};

		return $.ajax( {
			url: restUrl + '/settings',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify( data ),
			beforeSend( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
			},
			error() {
				if ( window.SEOPulse && window.SEOPulse.notify ) {
					window.SEOPulse.notify.error( i18n.error || 'Error' );
				}
			},
		} );
	}

	// Register as a pre-save action so the main form handler
	// orchestrates the call instead of firing a duplicate submit.
	window.seopulsePreSaveActions = window.seopulsePreSaveActions || [];
	window.seopulsePreSaveActions.push( saveImageAltSettings );

	// ──────────────────────────────────────────────
	//  BATCH FILL
	// ──────────────────────────────────────────────

	$( '#seopulse-batch-fill-btn' ).on( 'click', function () {
		const $btn = $( this );
		const $progress = $( '#seopulse-batch-progress' );
		const $bar = $( '#seopulse-progress-bar' );
		const $text = $( '#seopulse-progress-text' );

		$btn.prop( 'disabled', true );
		$progress.show();
		$bar.css( 'width', '0%' );

		let totalUpdated = 0;
		let totalSkipped = 0;

		function runBatch( page ) {
			$.ajax( {
				url: restUrl + '/batch-fill',
				method: 'POST',
				contentType: 'application/json',
				data: JSON.stringify( { page } ),
				beforeSend( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				},
				success( response ) {
					const result = response.data || response;
					totalUpdated += result.updated || 0;
					totalSkipped += result.skipped || 0;

					const total = result.total || 1;
					const processed = Math.min( page * 100, total );
					const pct = Math.min(
						Math.round( ( processed / total ) * 100 ),
						100
					);

					$bar.css( 'width', pct + '%' );
					$text.text(
						totalUpdated +
							' ' +
							( i18n.updated || 'updated' ) +
							', ' +
							totalSkipped +
							' ' +
							( i18n.skipped || 'skipped' ) +
							' — ' +
							processed +
							' ' +
							( i18n.of || 'of' ) +
							' ' +
							total +
							' ' +
							( i18n.images || 'images' )
					);

					if ( result.has_more ) {
						runBatch( page + 1 );
					} else {
						$bar.css( 'width', '100%' );
						$text.text( i18n.allDone || 'All images processed!' );
						$btn.prop( 'disabled', false );
						if ( window.SEOPulse && window.SEOPulse.notify ) {
							window.SEOPulse.notify.success(
								totalUpdated +
									' ' +
									( i18n.updated || 'updated' ) +
									', ' +
									totalSkipped +
									' ' +
									( i18n.skipped || 'skipped' )
							);
						}
						refreshDiagnostics();
					}
				},
				error() {
					$text
						.text( i18n.error || 'Error' )
						.css( 'color', '#d63638' );
					$btn.prop( 'disabled', false );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						window.SEOPulse.notify.error( i18n.error || 'Error' );
					}
				},
			} );
		}

		runBatch( 1 );
	} );

	// ──────────────────────────────────────────────
	//  REFRESH DIAGNOSTICS
	// ──────────────────────────────────────────────

	function refreshDiagnostics() {
		$.ajax( {
			url: restUrl + '/diagnostics',
			method: 'GET',
			beforeSend( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
			},
			success( response ) {
				const data = response.data || response;
				$( '#stat-total' ).text( data.total_images || 0 );
				$( '#stat-missing' ).text( data.missing_alt || 0 );
				$( '#stat-has-alt' ).text( data.has_alt || 0 );
			},
		} );
	}
} )( jQuery );
