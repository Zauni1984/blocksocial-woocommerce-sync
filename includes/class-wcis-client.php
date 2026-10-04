<?php
/**
 * HTTP-Client: sendet HMAC-signierte Requests an Peer-Shops.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client für ausgehende Kommunikation.
 */
class WCIS_Client {

	/**
	 * Liefert das konfigurierte HTTP-Timeout (5–60 Sekunden).
	 *
	 * @return int
	 */
	public static function timeout() {
		$t = (int) WCIS_Settings::get( 'http_timeout', 20 );
		return max( 5, min( 60, $t ) );
	}

	/**
	 * Absender der aktuell verarbeiteten, verifizierten Anfrage.
	 *
	 * @var array|null { type: 'network'|'partner'|'master', key?, url?, name? }
	 */
	protected static $caller = null;

	/**
	 * Zugangsdaten für ein Ziel: persönlicher Partner-Schlüssel (Partner bzw.
	 * Hauptshop→Partner) oder gemeinsames Netzwerk-Secret (eigene Shops).
	 *
	 * @param string $peer_url Ziel-URL.
	 * @return array { key: string ('' = Netzwerk), secret: string }
	 */
	public static function credentials_for( $peer_url ) {
		if ( WCIS_Edition::is_partner() ) {
			$c = WCIS_Settings::partner_conn();
			return array(
				'key'    => $c ? (string) $c['key'] : '',
				'secret' => $c ? (string) $c['secret'] : '',
			);
		}
		$none = array(
			'key'    => '',
			'secret' => '',
		);
		$p = ( '' !== (string) $peer_url ) ? WCIS_Partners::find_by_url( $peer_url ) : null;
		if ( $p ) {
			// Partner: ausschließlich der persönliche Schlüssel – gesperrte Partner
			// erhalten gar keine Anfragen (nie Fallback auf das Netzwerk-Secret).
			return ! empty( $p['active'] )
				? array(
					'key'    => (string) $p['key'],
					'secret' => (string) $p['secret'],
				)
				: $none;
		}
		// Netzwerk-Secret nur für eingetragene eigene Shops (inkl. Hauptshop) – nie
		// für beliebige URLs, damit keine gültige Signatur nach außen gelangt.
		return self::is_network_url( $peer_url )
			? array(
				'key'    => '',
				'secret' => (string) WCIS_Settings::get( 'network_secret', '' ),
			)
			: $none;
	}

	/**
	 * Ist die URL ein eingetragener eigener Shop (Shop-Liste oder Hauptshop)?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_network_url( $url ) {
		$n = WCIS_Settings::normalize_url( $url );
		if ( '' === $n ) {
			return false;
		}
		if ( WCIS_Settings::normalize_url( WCIS_Settings::get( 'master_url' ) ) === $n ) {
			return true;
		}
		foreach ( (array) WCIS_Settings::get( 'shops', array() ) as $shop ) {
			if ( ! empty( $shop['url'] ) && WCIS_Settings::normalize_url( $shop['url'] ) === $n ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Erzeugt die Signatur-Header für einen Request.
	 *
	 * Signatur = HMAC-SHA256( secret, timestamp . "." . body ). Bei Partner-
	 * Verbindungen wird zusätzlich die Key-ID (X-WCIS-Key) mitgesendet, damit der
	 * Empfänger das passende Secret wählt.
	 *
	 * @param string $body     Roher Request-Body.
	 * @param string $peer_url Ziel-URL (bestimmt die Zugangsdaten).
	 * @return array Header-Array.
	 */
	public static function sign_headers( $body, $peer_url = '' ) {
		$cred      = self::credentials_for( $peer_url );
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $cred['secret'] );

		$headers = array(
			'Content-Type'     => 'application/json',
			'X-WCIS-Timestamp' => $timestamp,
			'X-WCIS-Signature' => $signature,
			'X-WCIS-From'      => WCIS_Settings::this_url(),
			'X-WCIS-Version'   => WCIS_VERSION,
		);
		if ( '' !== $cred['key'] ) {
			$headers['X-WCIS-Key'] = $cred['key'];
		}
		return $headers;
	}

	/**
	 * Prüft die Signatur eines eingehenden Requests und ermittelt den Absender.
	 *
	 * - Admin-Edition: mit Key-ID → nur der passende, aktive Partner;
	 *   ohne Key-ID → eigener Netzwerk-Shop (gemeinsames Secret).
	 * - Partner-Edition: ausschließlich der Hauptshop mit dem eigenen Schlüssel.
	 *
	 * @param WP_REST_Request $request Request-Objekt.
	 * @return bool
	 */
	public static function verify_request( $request ) {
		self::$caller = null;

		$timestamp = (string) $request->get_header( 'x_wcis_timestamp' );
		$signature = (string) $request->get_header( 'x_wcis_signature' );
		$key       = sanitize_key( (string) $request->get_header( 'x_wcis_key' ) );

		if ( '' === $timestamp || '' === $signature ) {
			return false;
		}

		// Replay-Schutz: Zeitstempel darf max. 5 Minuten abweichen.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$caller = null;
		$secret = '';

		if ( WCIS_Edition::is_partner() ) {
			$c = WCIS_Settings::partner_conn();
			if ( ! $c || '' === $key || ! hash_equals( (string) $c['key'], $key ) ) {
				return false; // Partner sprechen nur mit ihrem Hauptshop.
			}
			$secret = (string) $c['secret'];
			$caller = array(
				'type' => 'master',
				'url'  => (string) $c['master_url'],
			);
		} elseif ( '' !== $key ) {
			$p = WCIS_Partners::get( $key );
			if ( ! $p || empty( $p['active'] ) ) {
				return false; // unbekannter oder gesperrter Partner.
			}
			$secret = (string) $p['secret'];
			$caller = array(
				'type' => 'partner',
				'key'  => $p['key'],
				'url'  => $p['url'],
				'name' => $p['name'],
			);
		} else {
			$secret = (string) WCIS_Settings::get( 'network_secret', '' );
			$caller = array(
				'type' => 'network',
				'url'  => esc_url_raw( (string) $request->get_header( 'x_wcis_from' ) ),
			);
		}

		if ( '' === $secret ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		self::$caller = $caller;
		if ( 'partner' === $caller['type'] ) {
			WCIS_Partners::touch( $caller['key'], (string) $request->get_header( 'x_wcis_version' ) );
		}
		return true;
	}

	/**
	 * Fehler: für dieses Ziel gibt es keine (gültigen) Zugangsdaten.
	 *
	 * @return WP_Error
	 */
	public static function no_credentials_error() {
		return new WP_Error( 'wcis_no_credentials', __( 'Keine Zugangsdaten für dieses Ziel (Shop nicht eingetragen/gespeichert oder Partner gesperrt) – Anfrage nicht gesendet.', 'blocksocial-woocommerce-sync' ) );
	}

	/**
	 * Absender der aktuellen (verifizierten) Anfrage.
	 *
	 * @return array|null
	 */
	public static function caller() {
		return self::$caller;
	}

	/**
	 * Sendet einen POST-Request an einen Peer.
	 *
	 * @param string   $peer_url Basis-URL des Peers.
	 * @param string   $endpoint Endpunkt, z. B. '/stock'.
	 * @param array    $payload  Nutzdaten.
	 * @param bool     $blocking Auf Antwort warten?
	 * @param int|null $timeout  Optionales Timeout-Override (Sekunden, 5–60).
	 * @return array|WP_Error { code, body } oder WP_Error.
	 */
	public static function post( $peer_url, $endpoint, array $payload, $blocking = true, $timeout = null ) {
		$url  = untrailingslashit( $peer_url ) . '/wp-json/' . WCIS_REST_NS . $endpoint;
		$body = wp_json_encode( $payload );
		if ( '' === self::credentials_for( $peer_url )['secret'] ) {
			return self::no_credentials_error();
		}

		$eff_timeout = ( null !== $timeout ) ? max( 5, min( 60, (int) $timeout ) ) : self::timeout();

		$response = wp_remote_post(
			$url,
			array(
				'headers'   => self::sign_headers( $body, $peer_url ),
				'body'      => $body,
				'timeout'   => $blocking ? $eff_timeout : 0.01,
				'blocking'  => $blocking,
				'sslverify' => apply_filters( 'wcis_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Sendet einen GET-Request an einen Peer.
	 *
	 * @param string $peer_url Basis-URL des Peers.
	 * @param string $endpoint Endpunkt.
	 * @return array|WP_Error
	 */
	public static function get( $peer_url, $endpoint ) {
		$url  = untrailingslashit( $peer_url ) . '/wp-json/' . WCIS_REST_NS . $endpoint;
		$body = ''; // GET signiert einen leeren Body.
		if ( '' === self::credentials_for( $peer_url )['secret'] ) {
			return self::no_credentials_error();
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => self::sign_headers( $body, $peer_url ),
				'timeout'   => self::timeout(),
				'sslverify' => apply_filters( 'wcis_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}
}
