<?php
/**
 * REST-Endpunkte für eingehende Synchronisation.
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
		register_rest_route(
			WCIS_REST_NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'check_signature' ),
			)
		);

		register_rest_route(
			WCIS_REST_NS,
			'/stock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive_stock' ),
				'permission_callback' => array( __CLASS__, 'check_signature' ),
			)
		);

		register_rest_route(
			WCIS_REST_NS,
			'/inventory',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_inventory' ),
				'permission_callback' => array( __CLASS__, 'check_signature' ),
			)
		);

		register_rest_route(
			WCIS_REST_NS,
			'/config',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive_config' ),
				'permission_callback' => array( __CLASS__, 'check_signature' ),
			)
		);

		register_rest_route(
			WCIS_REST_NS,
			'/product',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive_product' ),
				'permission_callback' => array( __CLASS__, 'check_signature' ),
			)
		);

		register_rest_route(
			WCIS_REST_NS,
			'/products-export',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'export_products' ),
				'permission_callback' => array( __CLASS__, 'check_signature' ),
			)
		);
	}

	/**
	 * Permission-Callback: prüft die HMAC-Signatur.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function check_signature( $request ) {
		if ( ! WCIS_Settings::is_enabled() ) {
			return new WP_Error( 'wcis_disabled', __( 'Synchronisation ist deaktiviert.', 'blocksocial-woocommerce-sync' ), array( 'status' => 403 ) );
		}
		if ( ! WCIS_Client::verify_request( $request ) ) {
			return new WP_Error( 'wcis_bad_signature', __( 'Ungültige oder fehlende Signatur.', 'blocksocial-woocommerce-sync' ), array( 'status' => 401 ) );
		}
		return true;
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
				'ok'       => true,
				'shop'     => WCIS_Settings::get( 'this_shop_name' ),
				'url'      => WCIS_Settings::this_url(),
				'version'  => WCIS_VERSION,
				'is_master' => WCIS_Settings::is_master(),
			),
			200
		);
	}

	/**
	 * Empfängt Lagerbestands-Änderungen und wendet sie an.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive_stock( $request ) {
		$params = $request->get_json_params();
		$items  = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		$source = isset( $params['source'] ) ? esc_url_raw( $params['source'] ) : '';

		if ( empty( $items ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'applied' => 0, 'ignored' => 0, 'skipped' => 0 ), 200 );
		}

		$stats = WCIS_Sync_Engine::apply_items( $items );

		WCIS_Logger::info(
			sprintf(
				'Empfangen von %s: %d angewendet, %d ignoriert (SKU unbekannt), %d übersprungen.',
				$source ? $source : 'unbekannt',
				$stats['applied'],
				$stats['ignored'],
				$stats['skipped']
			),
			'inbound',
			$stats
		);

		return new WP_REST_Response( array_merge( array( 'ok' => true ), $stats ), 200 );
	}

	/**
	 * Übernimmt die Netzwerk-Topologie (Shop-Liste + Hauptshop) vom Master.
	 *
	 * Ermöglicht, den Hauptshop zentral zu ändern und an alle Shops zu verteilen.
	 * Das Netzwerk-Secret und die eigene Shop-URL bleiben unberührt.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive_config( $request ) {
		// Zusätzliche Absicherung: Topologie-Änderungen (Shop-Liste + Hauptshop)
		// nur vom konfigurierten Hauptshop akzeptieren. Ist lokal noch kein
		// Hauptshop gesetzt (Erst-Einrichtung), wird der Push zum Bootstrapping
		// zugelassen.
		//
		// Hinweis zum Vertrauensmodell: Alle Shops teilen sich EIN Netzwerk-Secret;
		// der Absender-Header ist damit nicht kryptografisch an einen bestimmten
		// Shop gebunden. Diese Prüfung schützt daher vor versehentlicher
		// Fehlkonfiguration (ein Neben-Shop verteilt ungewollt Topologie), NICHT vor
		// einem böswilligen Inhaber des Secrets. Wer das Secret kennt, ist per Design
		// vollwertiges Mitglied des Verbunds – ein kompromittierter Shop erfordert
		// einen Secret-Wechsel im gesamten Verbund.
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

		$params     = $request->get_json_params();
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
	 * anderen Shop, der sich die Produkte des Hauptshops selbst holt).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export_products( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 50, $per_page ) : 20;

		$result = WCIS_Product_Sync::export_page( $page, $per_page );

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
}
