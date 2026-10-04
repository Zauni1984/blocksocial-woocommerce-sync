<?php
/**
 * Shopify Admin GraphQL API – schlanker HTTP-Client.
 *
 * Unterstützt beide Zugangsarten:
 * - token:  direkter Admin-API-Zugriffstoken (shpat_…) einer bestehenden
 *           „Legacy Custom App" aus dem Shopify-Admin,
 * - client: Client-ID + Client-Secret einer App aus dem Shopify Dev Dashboard
 *           (seit 2026 Standard). Der Zugriffstoken wird per Client-Credentials-
 *           Grant geholt, zwischengespeichert und vor Ablauf (24 h) erneuert.
 *
 * Drosselung: Shopify antwortet bei Überlast mit HTTP 200 und dem Fehlercode
 * THROTTLED (oder selten HTTP 429). Der Client wartet dann kurz und wiederholt.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GraphQL-Client für einen Shopify-Shop.
 */
class WCIS_Shopify_Api {

	/**
	 * Verwendete (stabile) API-Version.
	 */
	const API_VERSION = '2026-10';

	/**
	 * Shop-Konfiguration.
	 *
	 * @var array
	 */
	protected $store;

	/**
	 * Konstruktor.
	 *
	 * @param array $store Shop-Konfiguration (siehe WCIS_Shopify::defaults()).
	 */
	public function __construct( array $store ) {
		$this->store = $store;
	}

	/**
	 * Normalisiert eine Shop-Domain auf „name.myshopify.com".
	 *
	 * @param string $domain Eingabe (URL, Domain oder Shopname).
	 * @return string
	 */
	public static function normalize_domain( $domain ) {
		$d = strtolower( trim( (string) $domain ) );
		$d = preg_replace( '#^https?://#', '', $d );
		$d = preg_replace( '#/.*$#', '', $d );
		if ( '' !== $d && false === strpos( $d, '.' ) ) {
			$d .= '.myshopify.com';
		}
		return preg_match( '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $d ) ? $d : '';
	}

	/**
	 * Liefert einen gültigen Zugriffstoken (holt/erneuert ihn bei Bedarf).
	 *
	 * @return string|WP_Error
	 */
	public function access_token() {
		if ( 'client' !== $this->store['auth'] ) {
			return '' !== (string) $this->store['token']
				? (string) $this->store['token']
				: new WP_Error( 'wcis_shopify_token', __( 'Kein Shopify-Zugriffstoken hinterlegt.', 'blocksocial-woocommerce-sync' ) );
		}

		if ( ! empty( $this->store['access_token'] ) && (int) $this->store['token_expires'] > time() + 300 ) {
			return (string) $this->store['access_token'];
		}

		$res = wp_remote_post(
			'https://' . $this->store['domain'] . '/admin/oauth/access_token',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => (string) $this->store['client_id'],
					'client_secret' => (string) $this->store['client_secret'],
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			$msg = is_array( $data ) && isset( $data['error_description'] ) ? $data['error_description'] : ( is_array( $data ) && isset( $data['error'] ) ? $data['error'] : 'HTTP ' . $code );
			return new WP_Error(
				'wcis_shopify_token',
				sprintf(
					/* translators: %s: Fehlermeldung */
					__( 'Shopify-Token konnte nicht abgerufen werden: %s (Client-ID/Secret prüfen; App muss im Shop installiert sein).', 'blocksocial-woocommerce-sync' ),
					$msg
				)
			);
		}

		$this->store['access_token']  = (string) $data['access_token'];
		$this->store['token_expires'] = time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 86399 );
		WCIS_Shopify::update_store(
			$this->store['id'],
			array(
				'access_token'  => $this->store['access_token'],
				'token_expires' => $this->store['token_expires'],
			)
		);
		return $this->store['access_token'];
	}

	/**
	 * Führt eine GraphQL-Anfrage aus.
	 *
	 * @param string $query     GraphQL.
	 * @param array  $variables Variablen.
	 * @param int    $attempts  Max. Versuche bei Drosselung.
	 * @return array|WP_Error   Das „data"-Objekt oder Fehler.
	 */
	public function query( $query, array $variables = array(), $attempts = 4 ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url  = 'https://' . $this->store['domain'] . '/admin/api/' . self::API_VERSION . '/graphql.json';
		$body = wp_json_encode(
			array(
				'query'     => $query,
				'variables' => empty( $variables ) ? new stdClass() : $variables,
			)
		);

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$res = wp_remote_post(
				$url,
				array(
					'timeout' => 25,
					'headers' => array(
						'Content-Type'           => 'application/json',
						'Accept'                 => 'application/json',
						'X-Shopify-Access-Token' => $token,
					),
					'body'    => $body,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );

			if ( 429 === $code || self::is_throttled( $data ) ) {
				if ( $i < $attempts ) {
					$wait = (int) wp_remote_retrieve_header( $res, 'retry-after' );
					sleep( max( 1, min( 10, $wait ? $wait : $i * 2 ) ) );
					continue;
				}
				return new WP_Error( 'wcis_shopify_throttled', __( 'Shopify-API ausgelastet (Drosselung) – bitte später erneut versuchen.', 'blocksocial-woocommerce-sync' ) );
			}

			if ( 401 === $code || 403 === $code ) {
				if ( 'client' === $this->store['auth'] ) {
					// Token evtl. abgelaufen/widerrufen → beim nächsten Aufruf neu holen.
					WCIS_Shopify::update_store( $this->store['id'], array( 'access_token' => '', 'token_expires' => 0 ) );
				}
				return new WP_Error( 'wcis_shopify_auth', sprintf( __( 'Shopify lehnt den Zugriff ab (HTTP %d) – Zugangsdaten und API-Berechtigungen (Scopes) prüfen.', 'blocksocial-woocommerce-sync' ), $code ) );
			}
			if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
				return new WP_Error( 'wcis_shopify_http', sprintf( __( 'Shopify-API antwortete mit HTTP %d.', 'blocksocial-woocommerce-sync' ), $code ) );
			}
			if ( ! empty( $data['errors'] ) ) {
				$msgs = array();
				foreach ( (array) $data['errors'] as $e ) {
					$msgs[] = is_array( $e ) && isset( $e['message'] ) ? $e['message'] : wp_json_encode( $e );
				}
				return new WP_Error( 'wcis_shopify_gql', 'Shopify: ' . implode( ' | ', $msgs ) );
			}
			return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		}
		return new WP_Error( 'wcis_shopify_http', __( 'Shopify-API nicht erreichbar.', 'blocksocial-woocommerce-sync' ) );
	}

	/**
	 * Wurde die Anfrage gedrosselt?
	 *
	 * @param mixed $data Antwort.
	 * @return bool
	 */
	protected static function is_throttled( $data ) {
		if ( ! is_array( $data ) || empty( $data['errors'] ) || ! is_array( $data['errors'] ) ) {
			return false;
		}
		foreach ( $data['errors'] as $e ) {
			if ( is_array( $e ) && isset( $e['extensions']['code'] ) && 'THROTTLED' === $e['extensions']['code'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fasst userErrors einer Mutation zu einem WP_Error zusammen (oder null).
	 *
	 * @param array $errors userErrors.
	 * @return WP_Error|null
	 */
	public static function user_errors( $errors ) {
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return null;
		}
		$msgs  = array();
		$codes = array();
		foreach ( $errors as $e ) {
			$msgs[] = ( isset( $e['field'] ) && $e['field'] ? implode( '.', (array) $e['field'] ) . ': ' : '' ) . ( isset( $e['message'] ) ? $e['message'] : '' );
			if ( ! empty( $e['code'] ) ) {
				$codes[] = $e['code'];
			}
		}
		return new WP_Error( 'wcis_shopify_user', 'Shopify: ' . implode( ' | ', $msgs ), array( 'codes' => $codes ) );
	}

	/**
	 * UUID v4 (für @idempotent-Schlüssel).
	 *
	 * @return string
	 */
	public static function uuid() {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );
	}
}
