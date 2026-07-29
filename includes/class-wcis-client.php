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
	 * Erzeugt die Signatur-Header für einen Request.
	 *
	 * Signatur = HMAC-SHA256( secret, timestamp . "." . body ).
	 *
	 * @param string $body Roher Request-Body.
	 * @return array Header-Array.
	 */
	public static function sign_headers( $body ) {
		$secret    = WCIS_Settings::secret();
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return array(
			'Content-Type'      => 'application/json',
			'X-WCIS-Timestamp'  => $timestamp,
			'X-WCIS-Signature'  => $signature,
			'X-WCIS-From'       => WCIS_Settings::this_url(),
		);
	}

	/**
	 * Prüft die Signatur eines eingehenden Requests.
	 *
	 * @param WP_REST_Request $request Request-Objekt.
	 * @return bool
	 */
	public static function verify_request( $request ) {
		$secret = WCIS_Settings::secret();
		if ( '' === $secret ) {
			return false;
		}

		$timestamp = $request->get_header( 'x_wcis_timestamp' );
		$signature = $request->get_header( 'x_wcis_signature' );

		if ( empty( $timestamp ) || empty( $signature ) ) {
			return false;
		}

		// Replay-Schutz: Zeitstempel darf max. 5 Minuten abweichen.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return hash_equals( $expected, (string) $signature );
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

		$eff_timeout = ( null !== $timeout ) ? max( 5, min( 60, (int) $timeout ) ) : self::timeout();

		$response = wp_remote_post(
			$url,
			array(
				'headers'   => self::sign_headers( $body ),
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

		$response = wp_remote_get(
			$url,
			array(
				'headers'   => self::sign_headers( $body ),
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
