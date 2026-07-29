<?php
/**
 * Retry-Queue für fehlgeschlagene Zustellungen.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verwaltet ausstehende/fehlgeschlagene Sync-Zustellungen.
 */
class WCIS_Queue {

	/**
	 * Maximale Zustellversuche, bevor ein Eintrag als 'failed' gilt.
	 */
	const MAX_ATTEMPTS = 6;

	/**
	 * Fügt einen Eintrag zur Queue hinzu.
	 *
	 * @param string $peer_url Peer-URL.
	 * @param array  $payload  Nutzdaten.
	 * @param string $error    Fehlermeldung.
	 */
	public static function add( $peer_url, array $payload, $error = '', $endpoint = '/stock' ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		$now   = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'peer_url'   => untrailingslashit( $peer_url ),
				'endpoint'   => $endpoint,
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'attempts'   => 0,
				'last_error' => (string) $error,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Verarbeitet ausstehende Queue-Einträge.
	 *
	 * @param int $limit Maximal zu verarbeitende Einträge pro Lauf.
	 */
	public static function process( $limit = 50 ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		$limit = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND attempts < %d ORDER BY id ASC LIMIT %d",
				self::MAX_ATTEMPTS,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$payload = json_decode( $row['payload'], true );
			if ( ! is_array( $payload ) ) {
				self::mark_failed( (int) $row['id'], 'Ungültiges Payload.' );
				continue;
			}

			$endpoint = ! empty( $row['endpoint'] ) ? $row['endpoint'] : '/stock';
			$result   = WCIS_Client::post( $row['peer_url'], $endpoint, $payload, true );
			$attempts = (int) $row['attempts'] + 1;

			if ( ! is_wp_error( $result ) && $result['code'] >= 200 && $result['code'] < 300 ) {
				self::mark_done( (int) $row['id'] );
				WCIS_Logger::info(
					sprintf( 'Queue: Zustellung an %s nach %d Versuch(en) erfolgreich.', $row['peer_url'], $attempts ),
					'outbound'
				);
				continue;
			}

			$error = is_wp_error( $result ) ? $result->get_error_message() : ( 'HTTP ' . $result['code'] );

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				self::mark_failed( (int) $row['id'], $error, $attempts );
				WCIS_Logger::error(
					sprintf( 'Queue: Zustellung an %s nach %d Versuchen endgültig fehlgeschlagen: %s', $row['peer_url'], $attempts, $error ),
					'outbound'
				);
			} else {
				self::bump_attempt( (int) $row['id'], $error, $attempts );
			}
		}
	}

	/**
	 * Markiert einen Eintrag als erledigt (entfernt ihn).
	 *
	 * @param int $id ID.
	 */
	protected static function mark_done( $id ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Erhöht den Versuchszähler.
	 *
	 * @param int    $id       ID.
	 * @param string $error    Fehler.
	 * @param int    $attempts Versuche.
	 */
	protected static function bump_attempt( $id, $error, $attempts ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'attempts'   => $attempts,
				'last_error' => (string) $error,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Markiert einen Eintrag als endgültig fehlgeschlagen.
	 *
	 * @param int    $id       ID.
	 * @param string $error    Fehler.
	 * @param int    $attempts Versuche.
	 */
	protected static function mark_failed( $id, $error, $attempts = self::MAX_ATTEMPTS ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'status'     => 'failed',
				'attempts'   => $attempts,
				'last_error' => (string) $error,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Zählt Einträge nach Status.
	 *
	 * @return array [ 'pending' => n, 'failed' => n ]
	 */
	public static function counts() {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );

		$out = array( 'pending' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Setzt fehlgeschlagene Einträge zur erneuten Verarbeitung zurück.
	 */
	public static function retry_failed() {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::QUEUE_TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET status='pending', attempts=0 WHERE status='failed'" );
	}
}
