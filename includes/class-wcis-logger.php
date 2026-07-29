<?php
/**
 * Logging in eine eigene Datenbanktabelle.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einfache Logger-Klasse.
 */
class WCIS_Logger {

	/**
	 * Schreibt einen Log-Eintrag.
	 *
	 * @param string $message   Nachricht.
	 * @param string $level     info | error.
	 * @param string $direction outbound | inbound | ''.
	 * @param mixed  $context   Zusätzliche Daten (wird als JSON gespeichert).
	 */
	public static function log( $message, $level = 'info', $direction = '', $context = null ) {
		global $wpdb;

		// Info-Logs unterdrücken, wenn nur Fehler gewünscht sind.
		if ( 'info' === $level && 'error' === WCIS_Settings::get( 'log_level', 'info' ) ) {
			return;
		}

		$table = WCIS_Install::table( WCIS_Install::LOG_TABLE );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'level'      => substr( (string) $level, 0, 10 ),
				'direction'  => substr( (string) $direction, 0, 10 ),
				'message'    => (string) $message,
				'context'    => null === $context ? null : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Kurzform für Fehler.
	 *
	 * @param string $message   Nachricht.
	 * @param string $direction Richtung.
	 * @param mixed  $context   Kontext.
	 */
	public static function error( $message, $direction = '', $context = null ) {
		self::log( $message, 'error', $direction, $context );
	}

	/**
	 * Kurzform für Infos.
	 *
	 * @param string $message   Nachricht.
	 * @param string $direction Richtung.
	 * @param mixed  $context   Kontext.
	 */
	public static function info( $message, $direction = '', $context = null ) {
		self::log( $message, 'info', $direction, $context );
	}

	/**
	 * Liefert die letzten Log-Einträge.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::LOG_TABLE );
		$limit = max( 1, min( 500, (int) $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Löscht Log-Einträge, die älter als N Tage sind.
	 *
	 * @param int $days Tage.
	 */
	public static function cleanup( $days = 14 ) {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::LOG_TABLE );
		$days  = max( 1, (int) $days );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )", $days ) );
	}

	/**
	 * Leert das komplette Log.
	 */
	public static function clear() {
		global $wpdb;
		$table = WCIS_Install::table( WCIS_Install::LOG_TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
