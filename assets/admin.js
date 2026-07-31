/* BlockSocial WooCommerce Sync – Admin-Skript */
( function ( $ ) {
	'use strict';

	$( function () {
		var $app = $( '#wcis-app' );

		// HTML-Escaping-Helfer (verhindert Injektion aus Server-/Peer-Daten).
		function esc( s ) {
			return $( '<div>' ).text( ( s === null || s === undefined ) ? '' : s ).html();
		}
		function num( v ) {
			v = parseInt( v, 10 );
			return isFinite( v ) ? v : 0;
		}

		// --- Tab-Navigation ---
		$app.addClass( 'wcis-js' );
		var STORE = 'wcis_active_tab';

		function activateTab( id ) {
			if ( ! id ) { return; }
			var $item = $( '.wcis-navitem[data-tab="' + id + '"]' );
			if ( ! $item.length ) { return; }
			$( '.wcis-navitem' ).removeClass( 'is-active' );
			$item.addClass( 'is-active' );
			$( '.wcis-tab' ).removeClass( 'is-active' );
			$( '.wcis-tab[data-tab="' + id + '"]' ).addClass( 'is-active' );
			try { window.localStorage.setItem( STORE, id ); } catch ( e ) {}
		}

		$( '.wcis-navitem' ).on( 'click', function () {
			activateTab( $( this ).data( 'tab' ) );
		} );

		// Zuletzt aktiven Tab wiederherstellen.
		try {
			var saved = window.localStorage.getItem( STORE );
			if ( saved ) { activateTab( saved ); }
		} catch ( e ) {}

		// --- "Ungespeichert"-Indikator am Speichern-Button ---
		var $save = $( '.wcis-save' );
		$( '#wcis-settings-form' ).on( 'change input', 'input, select, textarea', function () {
			$save.addClass( 'is-dirty' );
		} );

		// Neues Secret erzeugen.
		$( '#wcis-gen-secret' ).on( 'click', function () {
			$( '#wcis-secret' ).val( $( this ).data( 'secret' ) ).trigger( 'change' );
		} );

		// Shop-Zeile hinzufügen.
		$( '#wcis-add-shop' ).on( 'click', function () {
			var $row = $( '#wcis-shops .wcis-shop-row' ).first().clone();
			$row.find( 'input' ).val( '' );
			$row.find( '.wcis-test-result' ).removeClass( 'ok err' ).text( '' );
			$( '#wcis-shops' ).append( $row );
		} );

		// Shop-Zeile entfernen.
		$( '#wcis-shops' ).on( 'click', '.wcis-remove-shop', function () {
			var $rows = $( '#wcis-shops .wcis-shop-row' );
			if ( $rows.length > 1 ) {
				$( this ).closest( '.wcis-shop-row' ).remove();
			} else {
				$( this ).closest( '.wcis-shop-row' ).find( 'input' ).val( '' );
			}
		} );

		// Verbindungstest.
		$( '#wcis-shops' ).on( 'click', '.wcis-test', function () {
			var $btn = $( this );
			var $row = $btn.closest( '.wcis-shop-row' );
			var url = $row.find( '.wcis-shop-url' ).val();
			var $result = $row.find( '.wcis-test-result' );

			if ( ! url ) {
				$result.removeClass( 'ok' ).addClass( 'err' ).text( '✗ URL fehlt' );
				return;
			}

			$btn.prop( 'disabled', true );
			$result.removeClass( 'ok err' ).text( WCIS.i18n.testing );

			$.post( WCIS.ajaxUrl, {
				action: 'wcis_test_connection',
				nonce: WCIS.nonce,
				url: url
			} ).done( function ( resp ) {
				if ( resp && resp.success ) {
					$result.removeClass( 'err' ).addClass( 'ok' ).text( '✓ ' + resp.data.message );
				} else {
					$result.removeClass( 'ok' ).addClass( 'err' ).text( '✗ ' + ( resp.data ? resp.data.message : 'Fehler' ) );
				}
			} ).fail( function () {
				$result.removeClass( 'ok' ).addClass( 'err' ).text( '✗ Anfrage fehlgeschlagen' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// --- Wiederverwendbarer Fortschritts-Runner (AJAX-Ticks) ---
		function makeRunner( cfg ) {
			var $btn = $( cfg.btn );
			var $cancel = $( cfg.cancel );
			var $wrap = $( cfg.wrap );
			var $fill = $( cfg.fill );
			var $label = $( cfg.label );
			var $text = $( cfg.text );
			var polling = false;
			var failCount = 0;
			var MAX_FAILS = 8; // nach so vielen Fehlversuchen in Folge abbrechen (kein endloses Drehen).

			function setBar( pct ) {
				$fill.css( 'width', pct + '%' );
				$label.text( pct + '%' );
			}

			function render( d ) {
				setBar( d.percent );
				$text.text( cfg.message( d ) );
			}

			function finish() {
				polling = false;
				$btn.prop( 'disabled', false );
				$cancel.hide();
			}

			function tick() {
				if ( ! polling ) {
					return;
				}
				$.post( WCIS.ajaxUrl, { action: cfg.tickAction, nonce: WCIS.nonce } )
					.done( function ( resp ) {
						if ( ! resp || ! resp.success ) {
							$text.text( '✗ ' + ( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) );
							finish();
							return;
						}
						failCount = 0; // Erfolg -> Fehlerzähler zurücksetzen.
						render( resp.data );
						if ( resp.data.status === 'running' ) {
							setTimeout( tick, 300 );
						} else {
							finish();
						}
					} )
					.fail( function () {
						failCount++;
						if ( failCount >= MAX_FAILS ) {
							$text.text( '✗ ' + WCIS.i18n.genericError + ' (' + failCount + '×)' );
							finish();
							return;
						}
						setTimeout( tick, 1500 );
					} );
			}

			function startPolling() {
				polling = true;
				failCount = 0;
				$btn.prop( 'disabled', true );
				$cancel.show();
				$wrap.show();
				tick();
			}

			$( cfg.form ).on( 'submit', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( cfg.confirm ) ) {
					return;
				}
				$btn.prop( 'disabled', true );
				$wrap.show();
				setBar( 0 );
				$text.text( WCIS.i18n.starting );

				var data = { action: cfg.startAction, nonce: WCIS.nonce };
				if ( cfg.extra ) {
					data = $.extend( data, cfg.extra() );
				}
				$.post( WCIS.ajaxUrl, data )
					.done( function ( resp ) {
						if ( ! resp || ! resp.success ) {
							$text.text( '✗ ' + ( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) );
							$btn.prop( 'disabled', false );
							return;
						}
						render( resp.data );
						if ( resp.data.status === 'running' ) {
							startPolling();
						} else {
							$btn.prop( 'disabled', false );
						}
					} )
					.fail( function () {
						$text.text( '✗ ' + WCIS.i18n.genericError );
						$btn.prop( 'disabled', false );
					} );
			} );

			$cancel.on( 'click', function () {
				$.post( WCIS.ajaxUrl, { action: cfg.cancelAction, nonce: WCIS.nonce } )
					.always( function () {
						finish();
						$text.text( WCIS.i18n.cancelled + '.' );
					} );
			} );

			if ( $wrap.length && $wrap.is( ':visible' ) ) {
				startPolling();
			}
		}

		// Bestands-Voll-Synchronisation.
		makeRunner( {
			form: '#wcis-fullsync-form', btn: '#wcis-full-sync', cancel: '#wcis-full-sync-cancel',
			wrap: '#wcis-progress-wrap', fill: '#wcis-progress-fill', label: '#wcis-progress-label', text: '#wcis-progress-text',
			startAction: 'wcis_fullsync_start', tickAction: 'wcis_fullsync_tick', cancelAction: 'wcis_fullsync_cancel',
			confirm: WCIS.i18n.confirmFull,
			extra: function () { return { batch_size: $( '#wcis-batch-size' ).val() }; },
			message: function ( d ) {
				if ( d.status === 'running' ) {
					return WCIS.i18n.syncing + ' ' + d.percent + '% – ' +
						( d.current_peer ? ( WCIS.i18n.toShop + ' ' + d.current_peer + ' ' ) : '' ) +
						'(' + d.sent + ' ' + WCIS.i18n.batchesSent +
						( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit : '' ) + ')';
				}
				if ( d.status === 'done' ) {
					return '✓ ' + WCIS.i18n.done + ': ' + d.total_items + ' ' + WCIS.i18n.itemsUnit +
						', ' + d.sent + ' ' + WCIS.i18n.batchesSent +
						( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit : '' ) + '.';
				}
				if ( d.status === 'cancelled' ) {
					return WCIS.i18n.cancelled + '.';
				}
				return '';
			}
		} );

		// --- Sync-Filter-Vorschau ---
		$( '#wcis-filter-preview-btn' ).on( 'click', function () {
			var $out = $( '#wcis-filter-preview' );
			var $btn = $( this );
			$btn.prop( 'disabled', true );
			$out.show().html( '<em>' + WCIS.i18n.previewLoading + '</em>' );

			var data = {
				action: 'wcis_filter_preview',
				nonce: WCIS.nonce,
				filter_mode: $( 'input[name="filter_mode"]:checked' ).val(),
				filter_categories: $( '#wcis-filter-cats' ).val() || [],
				filter_brands: $( '#wcis-filter-brands' ).val() || [],
				filter_include_ids: $( '#wcis-filter-include' ).val() || [],
				filter_exclude_ids: $( '#wcis-filter-exclude' ).val() || [],
				filter_exclude_categories: $( '#wcis-filter-excl-cats' ).val() || []
			};

			$.post( WCIS.ajaxUrl, data )
				.done( function ( resp ) {
					if ( ! resp || ! resp.success ) {
						$out.html( '<span style="color:#b32d2e;">✗ ' + esc( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) + '</span>' );
						return;
					}
					var d = resp.data;
					var html = '<p><strong>' + esc( WCIS.i18n.previewHeading ) + ': ' + num( d.in_scope ) + ' ' + esc( WCIS.i18n.previewOf ) + ' ' + num( d.scanned ) + '</strong> ' +
						esc( WCIS.i18n.previewScanned ) + ' (' + num( d.excluded ) + ' ' + esc( WCIS.i18n.previewExcluded ) + ').</p>';
					if ( d.sample && d.sample.length ) {
						html += '<p>' + WCIS.i18n.previewSample + ':</p><ul class="wcis-preview-list">';
						$.each( d.sample, function ( i, p ) {
							html += '<li>' + $( '<div>' ).text( p.name ).html() + ' <code>' + $( '<div>' ).text( p.sku ).html() + '</code></li>';
						} );
						html += '</ul>';
					}
					if ( d.truncated ) {
						html += '<p><em>' + WCIS.i18n.previewTruncated + '</em></p>';
					}
					$out.html( html );
				} )
				.fail( function () {
					$out.html( '<span style="color:#b32d2e;">✗ ' + WCIS.i18n.genericError + '</span>' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );

		// Produkte vom Hauptshop holen (Pull).
		makeRunner( {
			form: '#wcis-productpull-form', btn: '#wcis-product-pull', cancel: '#wcis-product-pull-cancel',
			wrap: '#wcis-pull-progress-wrap', fill: '#wcis-pull-progress-fill', label: '#wcis-pull-progress-label', text: '#wcis-pull-progress-text',
			startAction: 'wcis_productpull_start', tickAction: 'wcis_productpull_tick', cancelAction: 'wcis_productpull_cancel',
			confirm: WCIS.i18n.confirmPull,
			message: function ( d ) {
				if ( d.status === 'running' ) {
					return WCIS.i18n.syncing + ' ' + d.percent + '% (' + d.created + ' ' + WCIS.i18n.createdUnit + ', ' + d.updated + ' ' + WCIS.i18n.updatedUnit + ')';
				}
				if ( d.status === 'done' ) {
					return '✓ ' + WCIS.i18n.done + ': ' + d.created + ' ' + WCIS.i18n.createdUnit + ', ' + d.updated + ' ' + WCIS.i18n.updatedUnit + ', ' + d.skipped + ' ' + WCIS.i18n.skippedUnit + '.';
				}
				if ( d.status === 'error' ) {
					return '✗ ' + ( d.message || WCIS.i18n.genericError );
				}
				if ( d.status === 'cancelled' ) {
					return WCIS.i18n.cancelled + '.';
				}
				return '';
			}
		} );

		// WooCommerce-Auswahlfelder (Kategorien/Marken/Produktsuche) initialisieren.
		try {
			$( document.body ).trigger( 'wc-enhanced-select-init' );
		} catch ( e ) {}

		// Produkt-Massen-Übertragung.
		makeRunner( {
			form: '#wcis-productsync-form', btn: '#wcis-product-sync', cancel: '#wcis-product-sync-cancel',
			wrap: '#wcis-product-progress-wrap', fill: '#wcis-product-progress-fill', label: '#wcis-product-progress-label', text: '#wcis-product-progress-text',
			startAction: 'wcis_productsync_start', tickAction: 'wcis_productsync_tick', cancelAction: 'wcis_productsync_cancel',
			confirm: WCIS.i18n.confirmProducts,
			message: function ( d ) {
				if ( d.status === 'running' ) {
					return WCIS.i18n.syncing + ' ' + d.percent + '% – ' + d.index + '/' + d.total + ' ' + WCIS.i18n.products +
						' (' + d.created + ' ' + WCIS.i18n.createdUnit + ', ' + d.updated + ' ' + WCIS.i18n.updatedUnit + ')';
				}
				if ( d.status === 'done' ) {
					return '✓ ' + WCIS.i18n.done + ': ' + d.created + ' ' + WCIS.i18n.createdUnit + ', ' +
						d.updated + ' ' + WCIS.i18n.updatedUnit + ', ' + d.skipped + ' ' + WCIS.i18n.skippedUnit +
						( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit : '' ) + '.';
				}
				if ( d.status === 'cancelled' ) {
					return WCIS.i18n.cancelled + '.';
				}
				return '';
			}
		} );
	} );
} )( jQuery );
