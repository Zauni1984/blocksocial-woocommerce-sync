<?php
/**
 * REST-Endpunkte für eingehende Synchronisation.
 *
 * Jede Route prüft die HMAC-Signatur UND die Rolle des Absenders:
 * - network: eigener Shop des Administrators (gemeinsames Netzwerk-Secret)
 * - partner: Partnershop (persönlicher Schlüssel) – nur auf dem Hauptshop
 * - master:  der Hauptshop (nur im Partner-Plugin)
 *
 * Partner dürfen nur das Nötigste: Verkäufe melden, ihr Sortiment abrufen,
 * ihre Vorgaben lesen. Konfiguration, Produkte und Bestände des Verbunds können
 * sie nicht verändern.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registriert und behandelt die REST-Routen.
 */
class WCIS_REST_Controller {

	/**
	 * Registriert die Routen.
	 */
	public static function register_routes() {
		self::route( '/ping', 'GET', 'ping', array( 'network', 'partner', 'master' ) );
		self::route( '/stock', 'POST', 'receive_stock', array( 'network', 'partner', 'master' ) );
		self::route( '/inventory', 'GET', 'get_inventory', array( 'network', 'master' ) );
		self::route( '/config', 'POST', 'receive_config', array( 'network', 'master' ) );
		self::route( '/product', 'POST', 'receive_product', array( 'network', 'master' ) );
		self::route( '/products-export', 'GET', 'export_products', array( 'network', 'partner' ) );
		self::route( '/products-terms', 'GET', 'export_terms', array( 'network' ) );
		self::route( '/partner/hello', 'GET', 'partner_hello', array( 'partner' ) );
	}

	/**
	 * Registriert eine Route mit Rollen-Prüfung.
	 *
	 * @param string $path     Pfad.
	 * @param string $methods  HTTP-Methode(n).
	 * @param string $callback Methodenname.
	 * @param array  $allowed  Erlaubte Absender-Rollen.
	 */
	protected static function route( $path, $methods, $callback, array $allowed ) {
		register_rest_route(
			WCIS_REST_NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => static function ( $request ) use ( $allowed ) {
					return WCIS_REST_Controller::authorize( $request, $allowed );
				},
			)
		);
	}

	/**
	 * Prüft Signatur und Absender-Rolle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $allowed Erlaubte Rollen.
	 * @return bool|WP_Error
	 */
	public static function authorize( $request, array $allowed ) {
		if ( ! WCIS_Settings::is_enabled() ) {
			return new WP_Error( 'wcis_disabled', __( 'Synchronisation ist deaktiviert.', 'blocksocial-woocommerce-sync' ), array( 'status' => 403 ) );
		}
		if ( ! WCIS_Client::verify_request( $request ) ) {
			return new WP_Error( 'wcis_bad_signature', __( 'Ungültige oder fehlende Signatur.', 'blocksocial-woocommerce-sync' ), array( 'status' => 401 ) );
		}
		$caller = WCIS_Client::caller();
		if ( ! $caller || ! in_array( $caller['type'], $allowed, true ) ) {
			WCIS_Logger::error(
				sprintf(
					'Zugriff verweigert: %s (%s) darf %s nicht aufrufen.',
					isset( $caller['name'] ) ? $caller['name'] : ( isset( $caller['url'] ) ? $caller['url'] : '?' ),
					$caller ? $caller['type'] : '?',
					$request->get_route()
				),
				'inbound'
			);
			return new WP_Error( 'wcis_forbidden', __( 'Für diesen Shop nicht erlaubt.', 'blocksocial-woocommerce-sync' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Kompatibilität: frühere Versionen riefen check_signature() direkt auf.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function check_signature( $request ) {
		return self::authorize( $request, array( 'network', 'partner', 'master' ) );
	}

	/**
	 * Health-/Pairing-Check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ping( $request ) {
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'shop'      => WCIS_Settings::get( 'this_shop_name' ),
				'url'       => WCIS_Settings::this_url(),
				'version'   => WCIS_VERSION,
				'edition'   => WCIS_Edition::current(),
				'is_master' => WCIS_Settings::is_master(),
			),
			200
		);
	}

	/**
	 * Empfängt Lagerbestands-Änderungen und wendet sie an.
	 *
	 * - Eigene Shops / Hauptshop: absolute Bestände (wie bisher).
	 * - Partner: nur Verkaufs-Deltas aus Bestellungen, idempotent, im eigenen
	 *   Sortiment und (Standard) nur verringernd.
	 *
	 * Auf dem Hauptshop werden angewendete Änderungen anschließend an alle
	 * übrigen Empfänger weitergereicht (Partner, Shopify, ggf. eigene Shops).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive_stock( $request ) {
		$params = $request->get_json_params();
		$items  = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		$caller = WCIS_Client::caller();

		if ( empty( $items ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'applied' => 0, 'ignored' => 0, 'skipped' => 0 ), 200 );
		}

		if ( 'partner' === $caller['type'] ) {
			$stats = WCIS_Sync_Engine::apply_partner_deltas( $items, WCIS_Partners::get( $caller['key'] ) );
			$from  = $caller['name'];
		} else {
			$stats = WCIS_Sync_Engine::apply_items( $items );
			$from  = '' !== $caller['url'] ? $caller['url'] : ( isset( $params['source'] ) ? esc_url_raw( $params['source'] ) : 'unbekannt' );
		}

		WCIS_Logger::info(
			sprintf(
				'Bestand empfangen von %s: %d angewendet, %d ignoriert (SKU unbekannt), %d übersprungen.',
				$from,
				$stats['applied'],
				$stats['ignored'],
				$stats['skipped']
			),
			'inbound',
			array_diff_key( $stats, array( 'applied_skus' => 1 ) )
		);

		// Hauptshop: Änderung an alle weiteren Empfänger durchreichen.
		if ( ! empty( $stats['applied_skus'] ) ) {
			WCIS_Sync_Engine::schedule_forward( $stats['applied_skus'], $caller );
		}

		unset( $stats['applied_skus'] );
		return new WP_REST_Response( array_merge( array( 'ok' => true ), $stats ), 200 );
	}

	/**
	 * Übernimmt Konfiguration vom Hauptshop.
	 *
	 * - Admin-Edition: Netzwerk-Topologie (Shop-Liste + Hauptshop).
	 * - Partner-Edition: Vorgaben des Administrators (schreibgeschützt).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive_config( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		if ( WCIS_Edition::is_partner() ) {
			$managed = isset( $params['managed'] ) && is_array( $params['managed'] ) ? $params['managed'] : array();
			self::store_managed( $managed );
			WCIS_Logger::info( 'Vorgaben vom Hauptshop übernommen.', 'inbound' );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// Zusätzliche Absicherung: Topologie-Änderungen (Shop-Liste + Hauptshop)
		// nur vom konfigurierten Hauptshop akzeptieren. Ist lokal noch kein
		// Hauptshop gesetzt (Erst-Einrichtung), wird der Push zum Bootstrapping
		// zugelassen.
		//
		// Hinweis zum Vertrauensmodell: Die EIGENEN Shops des Administrators teilen
		// sich ein Netzwerk-Secret. Partnershops besitzen dagegen persönliche
		// Schlüssel und können diese Route grundsätzlich nicht aufrufen (403).
		$from         = (string) $request->get_header( 'x_wcis_from' );
		$local_master = (string) WCIS_Settings::get( 'master_url' );
		if ( '' !== $from && '' !== $local_master
			&& WCIS_Settings::normalize_url( $from ) !== WCIS_Settings::normalize_url( $local_master ) ) {
			WCIS_Logger::error(
				sprintf( 'Konfigurations-Push von %s abgelehnt – nicht der Hauptshop.', $from ),
				'inbound'
			);
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'not_master' ), 403 );
		}

		$shops      = isset( $params['shops'] ) && is_array( $params['shops'] ) ? $params['shops'] : null;
		$master_url = isset( $params['master_url'] ) ? esc_url_raw( $params['master_url'] ) : '';

		$update = array();

		if ( null !== $shops ) {
			$clean = array();
			foreach ( $shops as $shop ) {
				if ( empty( $shop['url'] ) ) {
					continue;
				}
				$clean[] = array(
					'name' => isset( $shop['name'] ) ? sanitize_text_field( $shop['name'] ) : '',
					'url'  => esc_url_raw( untrailingslashit( $shop['url'] ) ),
				);
			}
			$update['shops'] = $clean;
		}

		if ( '' !== $master_url ) {
			$update['master_url'] = untrailingslashit( $master_url );
		}

		if ( ! empty( $update ) ) {
			WCIS_Settings::update( $update );
		}

		WCIS_Logger::info( 'Netzwerk-Konfiguration vom Master übernommen.', 'inbound', $update );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Partner-Edition: speichert die Vorgaben des Hauptshops (bereinigt).
	 *
	 * @param array $managed Vorgaben.
	 */
	public static function store_managed( array $managed ) {
		$old   = WCIS_Settings::get( 'managed', array() );
		$clean = WCIS_Partners::sanitize_policy( $managed );
		foreach ( array( 'partner_name', 'master_name', 'scope_label' ) as $k ) {
			$clean[ $k ] = isset( $managed[ $k ] ) ? sanitize_text_field( (string) $managed[ $k ] ) : '';
		}
		$clean['updated_at'] = time();
		WCIS_Settings::update( array( 'managed' => $clean ) );

		// Preisregeln entzogen oder Rahmen verkleinert → bereits angewendete
		// Auf-/Abschläge im Hintergrund an die neuen Vorgaben anpassen.
		$was_allowed = ! empty( $old['allow_price_rules'] );
		if ( $was_allowed && ( empty( $clean['allow_price_rules'] )
			|| (float) $clean['price_min'] > (float) $old['price_min']
			|| (float) $clean['price_max'] < (float) $old['price_max'] ) ) {
			WCIS_Pricing::schedule_background_reprice();
		}
	}

	/**
	 * Empfängt ein Produkt und legt es an bzw. aktualisiert es (per SKU).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive_product( $request ) {
		$params  = $request->get_json_params();
		$product = isset( $params['product'] ) && is_array( $params['product'] ) ? $params['product'] : array();
		$source  = isset( $params['source'] ) ? esc_url_raw( $params['source'] ) : '';

		if ( empty( $product ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'result' => 'skipped' ), 200 );
		}

		if ( ! WCIS_Settings::get( 'product_sync_enabled', false ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'result' => 'skipped', 'reason' => 'disabled' ), 200 );
		}

		$result = WCIS_Product_Sync::apply_product( $product );

		WCIS_Logger::info(
			sprintf( 'Produkt von %s empfangen (SKU %s): %s.', $source ? $source : 'unbekannt', isset( $product['sku'] ) ? $product['sku'] : '?', $result ),
			'inbound'
		);

		return new WP_REST_Response( array( 'ok' => true, 'result' => $result ), 200 );
	}

	/**
	 * Liefert die Produkt-Payloads dieses Shops seitenweise (für Pull durch einen
	 * anderen Shop, der sich die Produkte des Hauptshops selbst holt). Partner
	 * erhalten nur ihr freigegebenes Sortiment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export_products( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 20;

		$caller  = WCIS_Client::caller();
		$partner = ( $caller && 'partner' === $caller['type'] ) ? WCIS_Partners::get( $caller['key'] ) : null;

		$result = WCIS_Product_Sync::export_page( $page, $per_page, $partner );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'source'      => WCIS_Settings::this_url(),
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
				'items'       => $result['items'],
			),
			200
		);
	}

	/**
	 * Schlanker Export (SKUs, Kategorie-Pfade, Marken, Umfang) für das
	 * Aufräumen auf Neben-Shops.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export_terms( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 250, $per_page ) : 200;

		$result = WCIS_Cleanup::export_terms_page( $page, $per_page );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'page'        => $page,
				'total_pages' => $result['total_pages'],
				'items'       => $result['items'],
			),
			200
		);
	}

	/**
	 * Liefert den kompletten Bestand dieses Shops (für Pull-basierte Voll-Syncs).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_inventory( $request ) {
		$items = WCIS_Sync_Engine::collect_all_items();
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'source' => WCIS_Settings::this_url(),
				'count'  => count( $items ),
				'items'  => $items,
			),
			200
		);
	}

	/**
	 * Hauptshop: Partner meldet sich an und erhält seine Vorgaben.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function partner_hello( $request ) {
		$caller  = WCIS_Client::caller();
		$partner = WCIS_Partners::get( $caller['key'] );
		if ( ! $partner ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		// Die URL, unter der der Partner Bestände/Produkte empfängt, ist vom
		// Administrator festgelegt. Weicht der Partner ab, Hinweis ins Protokoll.
		$from = (string) $request->get_header( 'x_wcis_from' );
		if ( '' !== $from && WCIS_Settings::normalize_url( $from ) !== WCIS_Settings::normalize_url( $partner['url'] ) ) {
			WCIS_Logger::error(
				sprintf( 'Partner „%s" meldet sich von %s, hinterlegt ist %s – bitte URL in der Partner-Verwaltung prüfen.', $partner['name'], $from, $partner['url'] ),
				'inbound'
			);
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'managed' => WCIS_Partners::managed_config( $partner ),
				'version' => WCIS_VERSION,
			),
			200
		);
	}
}
