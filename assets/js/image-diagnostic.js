/**
 * Image Diagnostic — table, filters, bulk actions, inline edit, CSV export
 *
 * @param $
 * @package
 * @since 3.1.0
 */
( function ( $ ) {
	'use strict';

	const config = window.seopulseImageDiag || {};
	const restUrl = config.restUrl || '';
	const nonce = config.nonce || '';
	const i18n = config.i18n || {};

	// State
	let currentPage = 1;
	let currentFilter = '';
	let searchQuery = '';
	let totalPages = 1;
	let selectedIds = [];
	let searchTimer = null;

	// ──────────────────────────────────────────────
	//  INIT
	// ──────────────────────────────────────────────

	$( document ).ready( function () {
		loadImages();
		bindEvents();
	} );

	// ──────────────────────────────────────────────
	//  EVENT BINDING
	// ──────────────────────────────────────────────

	function bindEvents() {
		// Filters
		$( '.seopulse-image-diagnostic__filter-btn' ).on( 'click', function () {
			$( '.seopulse-image-diagnostic__filter-btn' ).removeClass(
				'active'
			);
			$( this ).addClass( 'active' );
			currentFilter = $( this ).data( 'filter' ) || '';
			currentPage = 1;
			loadImages();
		} );

		// Search (debounced)
		$( '#seopulse-diag-search' ).on( 'input', function () {
			clearTimeout( searchTimer );
			const val = $( this ).val();
			searchTimer = setTimeout( function () {
				searchQuery = val;
				currentPage = 1;
				loadImages();
			}, 400 );
		} );

		// Select all
		$( '#seopulse-diag-select-all' ).on( 'change', function () {
			const checked = $( this ).is( ':checked' );
			$( '.seopulse-diag-row-check' ).prop( 'checked', checked );
			updateSelection();
		} );

		// Row checkbox delegation
		$( '#seopulse-diag-tbody' ).on(
			'change',
			'.seopulse-diag-row-check',
			function () {
				updateSelection();
			}
		);

		// Inline edit
		$( '#seopulse-diag-tbody' ).on(
			'click',
			'.seopulse-diag-edit-alt',
			function () {
				const $row = $( this ).closest( 'tr' );
				const id = $row.data( 'id' );
				const $cell = $row.find( '.seopulse-diag-alt-cell' );
				const current = $cell.data( 'alt' ) || '';

				$cell.html(
					'<div class="seopulse-image-diagnostic__inline-edit">' +
						'<input type="text" class="seopulse-diag-alt-input" value="' +
						escHtml( current ) +
						'" />' +
						'<button type="button" class="seopulse-core__btn-small seopulse-core__btn-small--primary seopulse-diag-save-alt">' +
						( i18n.save || 'Save' ) +
						'</button>' +
						'<button type="button" class="seopulse-core__btn-small seopulse-core__btn-small--secondary seopulse-diag-cancel-alt">' +
						( i18n.cancel || 'Cancel' ) +
						'</button>' +
						'</div>'
				);
				$cell.find( '.seopulse-diag-alt-input' ).focus();
			}
		);

		// Save inline alt
		$( '#seopulse-diag-tbody' ).on(
			'click',
			'.seopulse-diag-save-alt',
			function () {
				const $row = $( this ).closest( 'tr' );
				const id = $row.data( 'id' );
				const $cell = $row.find( '.seopulse-diag-alt-cell' );
				const newAlt = $cell.find( '.seopulse-diag-alt-input' ).val();

				$.ajax( {
					url: restUrl + '/edit-alt',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify( { id, alt: newAlt } ),
					beforeSend( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', nonce );
					},
					success() {
						$cell.data( 'alt', newAlt );
						renderAltCell( $cell, newAlt );
						if ( window.SEOPulse && window.SEOPulse.notify ) {
							window.SEOPulse.notify.success(
								i18n.altSaved || 'Alt text saved.'
							);
						}
					},
					error() {
						renderAltCell( $cell, $cell.data( 'alt' ) || '' );
						if ( window.SEOPulse && window.SEOPulse.notify ) {
							window.SEOPulse.notify.error(
								i18n.error || 'Error'
							);
						}
					},
				} );
			}
		);

		// Cancel inline edit
		$( '#seopulse-diag-tbody' ).on(
			'click',
			'.seopulse-diag-cancel-alt',
			function () {
				const $cell = $( this ).closest( '.seopulse-diag-alt-cell' );
				renderAltCell( $cell, $cell.data( 'alt' ) || '' );
			}
		);

		// Escape key to cancel
		$( '#seopulse-diag-tbody' ).on(
			'keydown',
			'.seopulse-diag-alt-input',
			function ( e ) {
				if ( e.key === 'Escape' ) {
					$( this )
						.closest( 'tr' )
						.find( '.seopulse-diag-cancel-alt' )
						.click();
				}
				if ( e.key === 'Enter' ) {
					$( this )
						.closest( 'tr' )
						.find( '.seopulse-diag-save-alt' )
						.click();
				}
			}
		);

		// Bulk fill alt
		$( '#seopulse-diag-bulk-alt' ).on( 'click', function () {
			if ( selectedIds.length === 0 ) {
				return;
			}
			const $btn = $( this );
			$btn.prop( 'disabled', true );
			$( '#seopulse-bulk-status' ).text(
				i18n.processing || 'Processing...'
			);

			$.ajax( {
				url: restUrl + '/bulk-alt',
				method: 'POST',
				contentType: 'application/json',
				data: JSON.stringify( { ids: selectedIds } ),
				beforeSend( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				},
				success( response ) {
					const d = response.data || response;
					const msg =
						d.updated +
						' ' +
						( i18n.updated || 'updated' ) +
						', ' +
						d.skipped +
						' ' +
						( i18n.skipped || 'skipped' );
					$( '#seopulse-bulk-status' ).text( msg );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						window.SEOPulse.notify.success( msg );
					}
					loadImages(); // Refresh
				},
				error() {
					$( '#seopulse-bulk-status' ).text( i18n.error || 'Error' );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						window.SEOPulse.notify.error( i18n.error || 'Error' );
					}
				},
				complete() {
					$btn.prop( 'disabled', false );
				},
			} );
		} );

		// Bulk rename
		$( '#seopulse-diag-bulk-rename' ).on( 'click', function () {
			if ( selectedIds.length === 0 ) {
				return;
			}
			if ( ! confirm( i18n.confirmRename || 'Rename files on disk?' ) ) {
				return;
			}

			const $btn = $( this );
			$btn.prop( 'disabled', true );
			$( '#seopulse-bulk-status' ).text(
				i18n.processing || 'Processing...'
			);

			$.ajax( {
				url: restUrl + '/bulk-rename',
				method: 'POST',
				contentType: 'application/json',
				data: JSON.stringify( { ids: selectedIds } ),
				beforeSend( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				},
				success( response ) {
					const d = response.data || response;
					const msg =
						d.renamed +
						' ' +
						( i18n.renamed || 'renamed' ) +
						', ' +
						d.skipped +
						' ' +
						( i18n.skipped || 'skipped' ) +
						( d.errors > 0
							? ', ' +
							  d.errors +
							  ' ' +
							  ( i18n.errors || 'errors' )
							: '' );
					$( '#seopulse-bulk-status' ).text( msg );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						d.errors > 0
							? window.SEOPulse.notify.warning( msg )
							: window.SEOPulse.notify.success( msg );
					}
					loadImages();
				},
				error() {
					$( '#seopulse-bulk-status' ).text( i18n.error || 'Error' );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						window.SEOPulse.notify.error( i18n.error || 'Error' );
					}
				},
				complete() {
					$btn.prop( 'disabled', false );
				},
			} );
		} );

		// Export CSV
		$( '#seopulse-diag-export-csv' ).on( 'click', function () {
			const $btn = $( this );
			$btn.prop( 'disabled', true );

			$.ajax( {
				url: restUrl + '/export',
				method: 'GET',
				beforeSend( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', nonce );
				},
				success( response ) {
					const d = response.data || response;
					const blob = new Blob( [ d.csv ], {
						type: 'text/csv;charset=utf-8;',
					} );
					const link = document.createElement( 'a' );
					link.href = URL.createObjectURL( blob );
					link.download = d.filename || 'export.csv';
					link.click();
					URL.revokeObjectURL( link.href );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						window.SEOPulse.notify.success(
							i18n.exported || 'CSV exported.'
						);
					}
				},
				error() {
					$( '#seopulse-bulk-status' ).text( i18n.error || 'Error' );
					if ( window.SEOPulse && window.SEOPulse.notify ) {
						window.SEOPulse.notify.error( i18n.error || 'Error' );
					}
				},
				complete() {
					$btn.prop( 'disabled', false );
				},
			} );
		} );

		// Pagination delegation
		$( document ).on( 'click', '.seopulse-diag-page-btn', function () {
			const page = $( this ).data( 'page' );
			if ( page && page !== currentPage ) {
				currentPage = page;
				loadImages();
			}
		} );
	}

	// ──────────────────────────────────────────────
	//  LOAD IMAGES
	// ──────────────────────────────────────────────

	function loadImages() {
		const $tbody = $( '#seopulse-diag-tbody' );
		$tbody.html(
			'<tr><td colspan="7" class="seopulse-image-diagnostic__loading">' +
				'<span class="spinner is-active"></span> ' +
				( i18n.loading || 'Loading...' ) +
				'</td></tr>'
		);

		selectedIds = [];
		updateBulkBar();
		$( '#seopulse-diag-select-all' ).prop( 'checked', false );

		$.ajax( {
			url: restUrl,
			method: 'GET',
			data: {
				page: currentPage,
				filter: currentFilter,
				search: searchQuery,
			},
			beforeSend( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', nonce );
			},
			success( response ) {
				const data = response.data || response;
				totalPages = data.total_pages || 1;
				renderTable( data.items || [] );
				renderPagination(
					data.page || 1,
					data.total_pages || 1,
					data.total || 0
				);
			},
			error() {
				$tbody.html(
					'<tr><td colspan="7" class="seopulse-image-diagnostic__empty">' +
						( i18n.error || 'Error loading images.' ) +
						'</td></tr>'
				);
			},
		} );
	}

	// ──────────────────────────────────────────────
	//  RENDER TABLE
	// ──────────────────────────────────────────────

	function renderTable( items ) {
		const $tbody = $( '#seopulse-diag-tbody' );

		if ( ! items.length ) {
			$tbody.html(
				'<tr><td colspan="7" class="seopulse-image-diagnostic__empty">' +
					( i18n.noImages || 'No images found.' ) +
					'</td></tr>'
			);
			return;
		}

		let html = '';
		for ( let i = 0; i < items.length; i++ ) {
			const item = items[ i ];
			html += buildRow( item );
		}
		$tbody.html( html );
	}

	function buildRow( item ) {
		const sizeKb = Math.round( item.filesize / 1024 );
		const sizeClass =
			item.filesize > 512000
				? ' seopulse-image-diagnostic__size--large'
				: '';
		const altHtml = item.alt
			? '<span class="seopulse-image-diagnostic__alt-text">' +
			  escHtml( item.alt ) +
			  '</span>'
			: '<span class="seopulse-image-diagnostic__alt-missing">' +
			  ( i18n.missing || 'Missing' ) +
			  '</span>';

		let badges = '';
		if ( ! item.alt ) {
			badges +=
				'<span class="seopulse-image-diagnostic__badge seopulse-image-diagnostic__badge--danger">' +
				( i18n.missingAlt || 'Missing alt' ) +
				'</span>';
		}
		if ( ! item.seo_friendly ) {
			badges +=
				'<span class="seopulse-image-diagnostic__badge seopulse-image-diagnostic__badge--warning">' +
				( i18n.poorFilename || 'Poor filename' ) +
				'</span>';
		}
		if ( item.filesize > 512000 ) {
			badges +=
				'<span class="seopulse-image-diagnostic__badge seopulse-image-diagnostic__badge--info">' +
				( i18n.largeFile || 'Large' ) +
				'</span>';
		}
		if ( ! badges ) {
			badges =
				'<span class="seopulse-image-diagnostic__badge seopulse-image-diagnostic__badge--success">' +
				( i18n.ok || 'OK' ) +
				'</span>';
		}

		return (
			'<tr data-id="' +
			item.id +
			'">' +
			'<td><input type="checkbox" class="seopulse-diag-row-check" value="' +
			item.id +
			'" /></td>' +
			'<td class="seopulse-image-diagnostic__td-thumb">' +
			( item.thumbnail
				? '<img src="' +
				  escHtml( item.thumbnail ) +
				  '" alt="" width="50" height="50" loading="lazy" />'
				: '<span class="dashicons dashicons-format-image"></span>' ) +
			'</td>' +
			'<td class="seopulse-image-diagnostic__td-filename">' +
			'<a href="' +
			escHtml( item.edit_url ) +
			'" target="_blank" title="' +
			escHtml( item.filename ) +
			'">' +
			escHtml( item.filename ) +
			'</a>' +
			'</td>' +
			'<td class="seopulse-diag-alt-cell" data-alt="' +
			escAttr( item.alt ) +
			'">' +
			altHtml +
			' <button type="button" class="seopulse-diag-edit-alt" title="' +
			( i18n.editAlt || 'Edit' ) +
			'"><span class="dashicons dashicons-edit"></span></button>' +
			'</td>' +
			'<td class="' +
			sizeClass +
			'">' +
			sizeKb +
			' KB</td>' +
			'<td>' +
			( item.parent_title ? escHtml( item.parent_title ) : '—' ) +
			'</td>' +
			'<td>' +
			badges +
			'</td>' +
			'</tr>'
		);
	}

	// ──────────────────────────────────────────────
	//  RENDER ALT CELL
	// ──────────────────────────────────────────────

	function renderAltCell( $cell, alt ) {
		const altHtml = alt
			? '<span class="seopulse-image-diagnostic__alt-text">' +
			  escHtml( alt ) +
			  '</span>'
			: '<span class="seopulse-image-diagnostic__alt-missing">' +
			  ( i18n.missing || 'Missing' ) +
			  '</span>';

		$cell.html(
			altHtml +
				' <button type="button" class="seopulse-diag-edit-alt" title="' +
				( i18n.editAlt || 'Edit' ) +
				'">' +
				'<span class="dashicons dashicons-edit"></span></button>'
		);
	}

	// ──────────────────────────────────────────────
	//  PAGINATION
	// ──────────────────────────────────────────────

	function renderPagination( page, pages, total ) {
		if ( pages <= 1 ) {
			$( '#seopulse-diag-pagination' ).html( '' );
			return;
		}

		let html =
			'<span class="seopulse-image-diagnostic__page-info">' +
			total +
			' ' +
			( i18n.images || 'images' ) +
			' — ' +
			( i18n.page || 'Page' ) +
			' ' +
			page +
			' ' +
			( i18n.pageOf || 'of' ) +
			' ' +
			pages +
			'</span>';

		html += '<div class="seopulse-image-diagnostic__page-btns">';

		if ( page > 1 ) {
			html +=
				'<button type="button" class="seopulse-core__btn-small seopulse-core__btn-small--secondary seopulse-diag-page-btn" data-page="' +
				( page - 1 ) +
				'">&laquo; ' +
				( i18n.prevPage || 'Prev' ) +
				'</button>';
		}
		if ( page < pages ) {
			html +=
				'<button type="button" class="seopulse-core__btn-small seopulse-core__btn-small--secondary seopulse-diag-page-btn" data-page="' +
				( page + 1 ) +
				'">' +
				( i18n.nextPage || 'Next' ) +
				' &raquo;</button>';
		}

		html += '</div>';

		$( '#seopulse-diag-pagination' ).html( html );
	}

	// ──────────────────────────────────────────────
	//  SELECTION
	// ──────────────────────────────────────────────

	function updateSelection() {
		selectedIds = [];
		$( '.seopulse-diag-row-check:checked' ).each( function () {
			selectedIds.push( parseInt( $( this ).val(), 10 ) );
		} );
		updateBulkBar();
	}

	function updateBulkBar() {
		const $bar = $( '#seopulse-bulk-bar' );
		if ( selectedIds.length > 0 ) {
			$bar.show();
			$( '#seopulse-selected-count' ).text( selectedIds.length );
			$( '#seopulse-bulk-status' ).text( '' );
		} else {
			$bar.hide();
		}
	}

	// ──────────────────────────────────────────────
	//  HELPERS
	// ──────────────────────────────────────────────

	function escHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str || '' ) );
		return div.innerHTML;
	}

	function escAttr( str ) {
		return escHtml( str ).replace( /"/g, '&quot;' );
	}
} )( jQuery );
