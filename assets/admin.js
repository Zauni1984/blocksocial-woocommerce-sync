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

		// Reiter aus der URL (nach Aktionen) hat Vorrang, sonst zuletzt aktiver Reiter.
		var urlTab = ( window.location.search.match( /[?&]wcis_tab=([a-z_]+)/ ) || [] )[1];
		if ( urlTab && $( '.wcis-navitem[data-tab="' + urlTab + '"]' ).length ) {
			activateTab( urlTab );
		} else {
			try {
				var saved = window.localStorage.getItem( STORE );
				if ( saved ) { activateTab( saved ); }
			} catch ( e ) {}
		}

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

			function start( more, confirmText ) {
				var question = confirmText || ( typeof cfg.confirm === 'function' ? cfg.confirm() : cfg.confirm );
				if ( question && ! window.confirm( question ) ) {
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
				if ( more ) {
					data = $.extend( data, more );
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
			}

			$( cfg.form ).on( 'submit', function ( e ) {
				e.preventDefault();
				start();
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

			return { start: start };
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
				filter_exclude_categories: $( '#wcis-filter-excl-cats' ).val() || [],
				filter_exclude_except_brands: $( '#wcis-filter-except-brands' ).val() || []
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

		// --- Aufräumen: Produkte außerhalb des Sync-Filters ---
		function renderCleanup( d ) {
			var $res = $( '#wcis-cleanup-result' );
			var $rm = $( '#wcis-cleanup-remove-form' );
			if ( ! d || d.status !== 'analyzed' ) {
				$rm.hide();
				if ( ! d || d.status !== 'done' ) {
					$res.hide();
				}
				return;
			}
			var html = '<p>' + num( d.checked ) + ' ' + esc( WCIS.i18n.cleanupChecked ) + ', ' + num( d.own ) + ' ' + esc( WCIS.i18n.cleanupOwn ) + '.</p>';
			if ( ! d.candidates ) {
				html += '<p><strong>✓ ' + esc( WCIS.i18n.cleanupNone ) + '</strong></p>';
				$rm.hide();
			} else {
				html += '<p><strong>' + num( d.candidates ) + ' ' + esc( WCIS.i18n.cleanupFound ) + ':</strong></p><ul class="wcis-preview-list">';
				$.each( d.sample || [], function ( i, r ) {
					html += '<li>' + esc( r.name ) + ' <code>' + esc( r.sku ) + '</code>' + ( r.cats ? ' – ' + esc( r.cats ) : '' ) + '</li>';
				} );
				html += '</ul>';
				if ( d.candidates > ( d.sample || [] ).length ) {
					html += '<p><em>… ' + num( d.candidates - d.sample.length ) + ' ' + esc( WCIS.i18n.cleanupMore ) + '</em></p>';
				}
				$rm.show();
			}
			$res.html( html ).show();
		}
		if ( $( '#wcis-cleanup-card' ).length ) {
			makeRunner( {
				form: '#wcis-cleanup-form', btn: '#wcis-cleanup-analyze', cancel: '#wcis-cleanup-cancel',
				wrap: '#wcis-cleanup-progress-wrap', fill: '#wcis-cleanup-progress-fill', label: '#wcis-cleanup-progress-label', text: '#wcis-cleanup-progress-text',
				startAction: 'wcis_cleanup_analyze', tickAction: 'wcis_cleanup_tick', cancelAction: 'wcis_cleanup_cancel',
				extra: function () {
					return { origin: $( 'input[name="cleanup_origin"]:checked' ).val(), since: $( '#wcis-cleanup-since' ).val() };
				},
				message: function ( d ) {
					renderCleanup( d );
					if ( d.status === 'running' ) {
						return ( d.phase === 'fetch' ? WCIS.i18n.cleanupFetch : WCIS.i18n.cleanupScan ) + ' ' + d.percent + '%';
					}
					if ( d.status === 'analyzed' ) {
						return '✓ ' + WCIS.i18n.done + '.';
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
			makeRunner( {
				form: '#wcis-cleanup-remove-form', btn: '#wcis-cleanup-remove', cancel: '#wcis-cleanup-remove-cancel',
				wrap: '#wcis-cleanup-remove-wrap', fill: '#wcis-cleanup-remove-fill', label: '#wcis-cleanup-remove-label', text: '#wcis-cleanup-remove-text',
				startAction: 'wcis_cleanup_remove', tickAction: 'wcis_cleanup_tick', cancelAction: 'wcis_cleanup_cancel',
				confirm: function () {
					return $( '#wcis-cleanup-mode' ).val() === 'delete' ? WCIS.i18n.confirmCleanupDelete : WCIS.i18n.confirmCleanupTrash;
				},
				extra: function () { return { mode: $( '#wcis-cleanup-mode' ).val() }; },
				message: function ( d ) {
					if ( d.status === 'running' ) {
						return WCIS.i18n.cleanupRemoving + ' ' + d.percent + '% – ' + d.index + '/' + d.candidates + ' ' + WCIS.i18n.products;
					}
					if ( d.status === 'done' ) {
						$( '#wcis-cleanup-remove-form' ).hide();
						return '✓ ' + WCIS.i18n.done + ': ' + d.removed + ' ' + WCIS.i18n.cleanupRemoved + ( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit : '' ) + '.';
					}
					if ( d.status === 'cancelled' ) {
						return WCIS.i18n.cancelled + '.';
					}
					return '';
				}
			} );
			try {
				renderCleanup( JSON.parse( $( '#wcis-cleanup-card' ).attr( 'data-state' ) || 'null' ) );
			} catch ( e ) {}
		}

		// --- Bestätigung für Formulare mit data-confirm (Löschen, Sperren …) ---
		$( document ).on( 'submit', 'form[data-confirm]', function ( e ) {
			if ( ! window.confirm( $( this ).data( 'confirm' ) ) ) {
				e.preventDefault();
			}
		} );

		// --- Code kopieren ---
		$( document ).on( 'click', '.wcis-copy', function () {
			var $btn = $( this );
			var $t = $( $btn.data( 'target' ) );
			$t.trigger( 'select' );
			var done = function () {
				var old = $btn.text();
				$btn.text( WCIS.i18n.copied );
				setTimeout( function () { $btn.text( old ); }, 1500 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( $t.val() ).then( done );
			} else {
				try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
			}
		} );

		// --- Regel-Editor (Preisregeln): Zeilen hinzufügen/entfernen ---
		$( document ).on( 'click', '.wcis-rule-add', function () {
			var $ed = $( this ).closest( '.wcis-rules-editor' );
			var tpl = $ed.find( 'template.wcis-rule-tpl' ).get( 0 );
			if ( tpl ) {
				$ed.find( '.wcis-rules' ).append( $( tpl.innerHTML ) );
			}
		} );
		$( document ).on( 'click', '.wcis-rule-remove', function () {
			$( this ).closest( '.wcis-rule-row' ).remove();
			$save.addClass( 'is-dirty' );
		} );

		// --- Preisregeln ---
		function pricingData() {
			var data = {};
			var $f = $( '#wcis-pricing-form' );
			data.rule_global = $f.find( '[name="rule_global"]' ).val();
			data.rule_rounding = $f.find( '[name="rule_rounding"]' ).val();
			data.rule_cat = [];
			data.rule_pct = [];
			$f.find( '.wcis-rules .wcis-rule-row' ).each( function () {
				data.rule_cat.push( $( this ).find( '[name="rule_cat[]"]' ).val() );
				data.rule_pct.push( $( this ).find( '[name="rule_pct[]"]' ).val() );
			} );
			return data;
		}
		var pricingRunner = null;
		if ( $( '#wcis-pricing-form' ).length ) {
			pricingRunner = makeRunner( {
				form: '#wcis-pricing-form', btn: '#wcis-pricing-apply', cancel: '#wcis-pricing-cancel',
				wrap: '#wcis-pricing-progress-wrap', fill: '#wcis-pricing-progress-fill', label: '#wcis-pricing-progress-label', text: '#wcis-pricing-progress-text',
				startAction: 'wcis_pricing_start', tickAction: 'wcis_pricing_tick', cancelAction: 'wcis_pricing_cancel',
				confirm: WCIS.i18n.confirmPricing,
				extra: pricingData,
				message: function ( d ) {
					if ( d.status === 'running' ) {
						return WCIS.i18n.syncing + ' ' + d.percent + '% – ' + d.index + '/' + d.total + ' ' + WCIS.i18n.products +
							' (' + d.changed + ' ' + WCIS.i18n.changedUnit + ')';
					}
					if ( d.status === 'done' ) {
						return '✓ ' + WCIS.i18n.done + ': ' + d.changed + ' ' + WCIS.i18n.changedUnit + ', ' + d.unchanged + ' ' + WCIS.i18n.unchangedUnit +
							( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit : '' ) + '.';
					}
					if ( d.status === 'cancelled' ) {
						return WCIS.i18n.cancelled + '.';
					}
					return '';
				}
			} );
		}
		$( '#wcis-pricing-reset' ).on( 'click', function () {
			if ( pricingRunner ) {
				pricingRunner.start( { reset: 1 }, WCIS.i18n.confirmReset );
			}
		} );
		$( '#wcis-pricing-save' ).on( 'click', function () {
			var $msg = $( '#wcis-pricing-msg' );
			$.post( WCIS.ajaxUrl, $.extend( { action: 'wcis_pricing_save', nonce: WCIS.nonce }, pricingData() ) )
				.done( function ( resp ) {
					$msg.text( resp && resp.success ? '✓ ' + resp.data.message : '✗ ' + ( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) );
				} )
				.fail( function () { $msg.text( '✗ ' + WCIS.i18n.genericError ); } );
		} );
		$( '#wcis-pricing-preview-btn' ).on( 'click', function () {
			var $out = $( '#wcis-pricing-preview' );
			$out.show().html( '<em>' + esc( WCIS.i18n.previewLoading ) + '</em>' );
			$.post( WCIS.ajaxUrl, $.extend( { action: 'wcis_pricing_preview', nonce: WCIS.nonce }, pricingData() ) )
				.done( function ( resp ) {
					if ( ! resp || ! resp.success ) {
						$out.html( '<span class="wcis-err-text">✗ ' + esc( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) + '</span>' );
						return;
					}
					var d = resp.data;
					var html = '<p><strong>' + num( d.affected ) + ' ' + esc( WCIS.i18n.pricingAffected ) + '</strong> (' + num( d.scanned ) + ' ' + esc( WCIS.i18n.previewScanned ) + ').</p>';
					if ( d.rows && d.rows.length ) {
						html += '<p>' + esc( WCIS.i18n.pricingSample ) + ':</p><ul class="wcis-preview-list">';
						$.each( d.rows, function ( i, r ) {
							html += '<li>' + esc( r.name ) + ': ' + esc( r.base ) + ' → <strong>' + esc( r.new ) + '</strong> (' + ( r.percent > 0 ? '+' : '' ) + esc( r.percent ) + ' %)</li>';
						} );
						html += '</ul>';
					}
					if ( d.truncated ) {
						html += '<p><em>' + esc( WCIS.i18n.previewTruncated ) + '</em></p>';
					}
					$out.html( html );
				} )
				.fail( function () { $out.html( '✗ ' + esc( WCIS.i18n.genericError ) ); } );
		} );

		// --- Partner: Verbindungstest ---
		$( document ).on( 'click', '.wcis-partner-test', function () {
			var $btn = $( this );
			var $res = $btn.closest( 'td' ).find( '.wcis-test-result' );
			$btn.prop( 'disabled', true );
			$res.removeClass( 'ok err' ).text( WCIS.i18n.testing );
			$.post( WCIS.ajaxUrl, { action: 'wcis_partner_test', nonce: WCIS.nonce, key: $btn.data( 'key' ) } )
				.done( function ( resp ) {
					if ( resp && resp.success ) {
						$res.addClass( 'ok' ).text( '✓ ' + resp.data.message );
					} else {
						$res.addClass( 'err' ).text( '✗ ' + ( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) );
					}
				} )
				.fail( function () { $res.addClass( 'err' ).text( '✗ ' + WCIS.i18n.genericError ); } )
				.always( function () { $btn.prop( 'disabled', false ); } );
		} );

		// --- Shopify: Zugangsart umschalten ---
		function toggleAuth( $form ) {
			var mode = $form.find( '.wcis-auth-toggle:checked' ).val();
			$form.find( '.wcis-auth-client' ).toggle( mode !== 'token' );
			$form.find( '.wcis-auth-token' ).toggle( mode === 'token' );
		}
		$( '.wcis-shopify-form' ).each( function () { toggleAuth( $( this ) ); } );
		$( document ).on( 'change', '.wcis-auth-toggle', function () { toggleAuth( $( this ).closest( 'form' ) ); } );

		// --- Shopify: Verbindungstest ---
		$( document ).on( 'click', '.wcis-shopify-test', function () {
			var $btn = $( this );
			var $res = $btn.closest( '.wcis-store' ).find( '.wcis-test-result' ).first();
			$btn.prop( 'disabled', true );
			$res.removeClass( 'ok err' ).text( WCIS.i18n.testing );
			$.post( WCIS.ajaxUrl, { action: 'wcis_shopify_test', nonce: WCIS.nonce, store: $btn.data( 'store' ) } )
				.done( function ( resp ) {
					if ( resp && resp.success ) {
						$res.addClass( 'ok' ).text( '✓ ' + resp.data.message );
					} else {
						$res.addClass( 'err' ).text( '✗ ' + ( resp && resp.data ? resp.data.message : WCIS.i18n.genericError ) );
					}
				} )
				.fail( function () { $res.addClass( 'err' ).text( '✗ ' + WCIS.i18n.genericError ); } )
				.always( function () { $btn.prop( 'disabled', false ); } );
		} );

		// --- Shopify: Übertragung (Fortschrittsbalken) ---
		if ( $( '#wcis-shopify-job-form' ).length ) {
			makeRunner( {
				form: '#wcis-shopify-job-form', btn: '#wcis-shopify-start', cancel: '#wcis-shopify-cancel',
				wrap: '#wcis-shopify-progress-wrap', fill: '#wcis-shopify-progress-fill', label: '#wcis-shopify-progress-label', text: '#wcis-shopify-progress-text',
				startAction: 'wcis_shopify_start', tickAction: 'wcis_shopify_tick', cancelAction: 'wcis_shopify_cancel',
				confirm: WCIS.i18n.confirmShopify,
				extra: function () { return { store: $( '#wcis-shopify-store' ).val(), mode: $( '#wcis-shopify-mode' ).val() }; },
				message: function ( d ) {
					if ( d.status === 'running' ) {
						return WCIS.i18n.syncing + ' ' + d.percent + '% – ' + d.index + '/' + d.total + ' ' + WCIS.i18n.products +
							' (' + d.created + ' ' + WCIS.i18n.createdUnit + ', ' + d.updated + ' ' + WCIS.i18n.updatedUnit +
							( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit : '' ) + ')';
					}
					if ( d.status === 'done' ) {
						return '✓ ' + WCIS.i18n.done + ': ' + d.created + ' ' + WCIS.i18n.createdUnit + ', ' + d.updated + ' ' + WCIS.i18n.updatedUnit + ', ' +
							d.skipped + ' ' + WCIS.i18n.skippedUnit + ( d.failed ? ', ' + d.failed + ' ' + WCIS.i18n.failedUnit + ( d.message ? ' – ' + d.message : '' ) : '' ) + '.';
					}
					if ( d.status === 'cancelled' ) {
						return WCIS.i18n.cancelled + '.';
					}
					return '';
				}
			} );
		}
	} );
} )( jQuery );
