<?php
/**
 * REST-API für die Plugins: Aktivieren, Prüfen, Freigeben, Update-Infos, Downloads.
 *
 * Namespace: /wp-json/bsls/v1
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST-Endpunkte.
 */
class BSLS_Api {

	/** Anfragen je IP und Stunde. */
	const RATE_LIMIT = 300;

	/**
	 * Registriert die Routen.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routen.
	 */
	public static function routes() {
		$open = static function () {
			return true; // Authentifizierung über Lizenzschlüssel/Signatur in den Handlern.
		};
		register_rest_route( BSLS_REST_NS, '/activate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'activate' ), 'permission_callback' => $open ) );
		register_rest_route( BSLS_REST_NS, '/deactivate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'deactivate' ), 'permission_callback' => $open ) );
		register_rest_route( BSLS_REST_NS, '/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check' ), 'permission_callback' => $open ) );
		register_rest_route( BSLS_REST_NS, '/info', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'info' ), 'permission_callback' => $open ) );
		register_rest_route( BSLS_REST_NS, '/download', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'download' ), 'permission_callback' => $open ) );
	}

	/**
	 * Einfache Begrenzung der Anfragen je IP.
	 *
	 * @return WP_REST_Response|null Fehlerantwort oder null.
	 */
	protected static function throttle() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'bsls_rl_' . md5( $ip . '|' . gmdate( 'YmdH' ) );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, HOUR_IN_SECONDS );
		if ( $n > (int) apply_filters( 'bsls_rate_limit', self::RATE_LIMIT ) ) {
			return self::error( 'rate_limited', __( 'Zu viele Anfragen – bitte später erneut versuchen.', 'blocksocial-license-server' ), 429 );
		}
		return null;
	}

	/**
	 * Fehlerantwort.
	 *
	 * @param string $code    Code.
	 * @param string $message Meldung.
	 * @param int    $status  HTTP-Status.
	 * @param array  $extra   Zusatzdaten.
	 * @return WP_REST_Response
	 */
	protected static function error( $code, $message, $status = 400, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array( 'ok' => false, 'code' => $code, 'message' => $message ), $extra ), $status );
	}

	/**
	 * Lizenz aus Schlüssel; Fehlerantwort, wenn ungültig/widerrufen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_REST_Response
	 */
	protected static function license_from( $request ) {
		$lic = BSLS_Licenses::get_by_key( (string) $request->get_param( 'license_key' ) );
		if ( ! $lic ) {
			return self::error( 'invalid_key', __( 'Lizenzschlüssel ungültig.', 'blocksocial-license-server' ), 404 );
		}
		$status = BSLS_Licenses::status( $lic );
		if ( in_array( $status, array( 'revoked', 'disabled' ), true ) ) {
			return self::error( $status, BSLS_Licenses::status_label( $status ), 403, array( 'license' => BSLS_Licenses::to_api( $lic ) ) );
		}
		return $lic;
	}

	/**
	 * POST /activate – bindet die Lizenz an den Hauptshop.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function activate( $request ) {
		$limited = self::throttle();
		if ( $limited ) {
			return $limited;
		}
		$lic = self::license_from( $request );
		if ( $lic instanceof WP_REST_Response ) {
			return $lic;
		}
		$site = BSLS_Licenses::normalize_site( (string) $request->get_param( 'site' ) );
		if ( '' === $site ) {
			return self::error( 'invalid_site', __( 'Shop-Adresse fehlt.', 'blocksocial-license-server' ) );
		}
		if ( '' !== $lic['master_site'] && $lic['master_site'] !== $site ) {
			return self::error(
				'already_active',
				sprintf(
					/* translators: %s: Shop */
					__( 'Diese Lizenz ist bereits im Hauptshop %s aktiviert. Zum Umzug dort deaktivieren oder im Kundenkonto auf blocksocial.eu unter „Lizenzen" freigeben.', 'blocksocial-license-server' ),
					$lic['master_site']
				),
				409
			);
		}
		if ( '' === $lic['master_site'] ) {
			BSLS_Licenses::update( (int) $lic['id'], array( 'master_site' => $site ) );
			$lic['master_site'] = $site;
		}
		$covered = BSLS_Licenses::sync_sites( $lic, (array) $request->get_param( 'shops' ) );
		return new WP_REST_Response( array( 'ok' => true, 'license' => BSLS_Licenses::to_api( $lic, $covered ) ), 200 );
	}

	/**
	 * POST /deactivate – gibt den Hauptshop frei.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function deactivate( $request ) {
		$limited = self::throttle();
		if ( $limited ) {
			return $limited;
		}
		$lic = BSLS_Licenses::get_by_key( (string) $request->get_param( 'license_key' ) );
		$site = BSLS_Licenses::normalize_site( (string) $request->get_param( 'site' ) );
		if ( $lic && '' !== $site && $lic['master_site'] === $site ) {
			BSLS_Licenses::release_master( (int) $lic['id'] );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /check – täglicher Abgleich: Status, Plätze, abgedeckte Shops.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function check( $request ) {
		$limited = self::throttle();
		if ( $limited ) {
			return $limited;
		}
		$lic = self::license_from( $request );
		if ( $lic instanceof WP_REST_Response ) {
			return $lic;
		}
		$site = BSLS_Licenses::normalize_site( (string) $request->get_param( 'site' ) );
		if ( '' === $lic['master_site'] || $lic['master_site'] !== $site ) {
			return self::error( 'not_activated', __( 'Die Lizenz ist für diesen Shop nicht (mehr) aktiviert.', 'blocksocial-license-server' ), 403, array( 'license' => BSLS_Licenses::to_api( $lic ) ) );
		}
		$covered = BSLS_Licenses::sync_sites( $lic, (array) $request->get_param( 'shops' ) );
		return new WP_REST_Response( array( 'ok' => true, 'license' => BSLS_Licenses::to_api( $lic, $covered ) ), 200 );
	}

	/**
	 * GET /info – Update-Informationen eines Plugins (mit Download-Link, wenn
	 * der anfragende Shop durch eine aktive Lizenz abgedeckt ist).
	 *
	 * Hauptshop: license_key + site. Neben-/Partnershops: master + site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function info( $request ) {
		$limited = self::throttle();
		if ( $limited ) {
			return $limited;
		}
		$slug = sanitize_key( (string) $request->get_param( 'product' ) );
		$rel  = BSLS_Releases::get( $slug );
		if ( ! $rel ) {
			return self::error( 'unknown_product', __( 'Kein Release für dieses Plugin vorhanden.', 'blocksocial-license-server' ), 404 );
		}
		$family = BSLS_Licenses::family_for_slug( $slug );
		$site   = BSLS_Licenses::normalize_site( (string) $request->get_param( 'site' ) );
		$key    = (string) $request->get_param( 'license_key' );
		$lic    = null;
		$reason = 'no_license';

		if ( '' !== $key ) {
			$lic = BSLS_Licenses::get_by_key( $key );
			if ( $lic && ( $lic['family'] !== $family || $lic['master_site'] !== $site ) ) {
				$lic    = null;
				$reason = 'not_activated';
			}
		} else {
			$master = BSLS_Licenses::normalize_site( (string) $request->get_param( 'master' ) );
			$lic    = BSLS_Licenses::get_by_master( $master, $family );
			if ( $lic && $site !== $master && ! BSLS_Licenses::site_covered( (int) $lic['id'], $site ) ) {
				$lic    = null;
				$reason = 'not_covered';
			}
		}

		$download = null;
		if ( $lic ) {
			$status = BSLS_Licenses::status( $lic );
			if ( 'active' === $status ) {
				$download = BSLS_Releases::download_url( $slug, (int) $lic['id'] );
				$reason   = '';
			} else {
				$reason = $status;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'slug'         => $slug,
				'name'         => $rel['name'],
				'version'      => $rel['version'],
				'requires'     => $rel['requires'],
				'tested'       => $rel['tested'],
				'requires_php' => $rel['requires_php'],
				'last_updated' => $rel['date'],
				'changelog'    => $rel['changelog'],
				'download_url' => $download,
				'reason'       => $reason,
				'homepage'     => home_url( '/' ),
			),
			200
		);
	}

	/**
	 * GET /download – liefert die Release-ZIP über einen signierten Link aus.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|void
	 */
	public static function download( $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		$lid  = (int) $request->get_param( 'lic' );
		if ( ! BSLS_Releases::verify_download( $slug, $lid, (int) $request->get_param( 'exp' ), (string) $request->get_param( 'sig' ) ) ) {
			return self::error( 'invalid_link', __( 'Download-Link ungültig oder abgelaufen.', 'blocksocial-license-server' ), 403 );
		}
		$lic = BSLS_Licenses::get( $lid );
		if ( ! $lic || 'active' !== BSLS_Licenses::status( $lic ) || BSLS_Licenses::family_for_slug( $slug ) !== $lic['family'] ) {
			return self::error( 'license_inactive', __( 'Lizenz nicht aktiv.', 'blocksocial-license-server' ), 403 );
		}
		$rel = BSLS_Releases::get( $slug );
		if ( ! $rel ) {
			return self::error( 'unknown_product', __( 'Kein Release vorhanden.', 'blocksocial-license-server' ), 404 );
		}
		$path = BSLS_Releases::local_file( $rel );
		if ( is_wp_error( $path ) ) {
			return self::error( 'file_missing', $path->get_error_message(), 500 );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}
}
