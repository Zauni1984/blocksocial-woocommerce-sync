<?php
/**
 * Aktivierung / Deaktivierung: DB-Tabellen und Cron-Events.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installer-Klasse.
 */
class WCIS_Install {

	/**
	 * Name der Retry-Queue-Tabelle (ohne Präfix).
	 */
	const QUEUE_TABLE = 'wcis_queue';

	/**
	 * Name der Log-Tabelle (ohne Präfix).
	 */
	const LOG_TABLE = 'wcis_log';

	/**
	 * Gibt den vollständigen Tabellennamen zurück.
	 *
	 * @param string $name Kurzname der Tabelle.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Aktivierungsroutine.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'wcis_db_version', WCIS_VERSION );

		// Standard-Einstellungen anlegen, falls noch nicht vorhanden.
		if ( false === get_option( WCIS_OPT, false ) ) {
			add_option( WCIS_OPT, WCIS_Settings::defaults() );
		}

		// Cron: Retry-Queue jede Minute abarbeiten.
		if ( ! wp_next_scheduled( 'wcis_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'wcis_every_minute', 'wcis_process_queue' );
		}
		// Cron: Logs täglich aufräumen.
		if ( ! wp_next_scheduled( 'wcis_daily_cleanup' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'wcis_daily_cleanup' );
		}

		// Cron: periodischer Abgleich gemäß Einstellungen.
		WCIS_Reconcile::reschedule();

		flush_rewrite_rules();
	}

	/**
	 * Deaktivierungsroutine.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wcis_process_queue' );
		wp_clear_scheduled_hook( 'wcis_daily_cleanup' );
		wp_clear_scheduled_hook( 'wcis_reconcile' );
		flush_rewrite_rules();
	}

	/**
	 * Führt bei Versionswechsel nötige DB-Upgrades aus (z. B. neue Spalten).
	 * Wird bei jedem Laden geprüft, ist aber nur bei Versionswechsel aktiv.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'wcis_db_version', '0' );
		if ( version_compare( $installed, WCIS_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables(); // dbDelta ergänzt fehlende Spalten/Indizes idempotent.
		WCIS_Reconcile::reschedule();
		update_option( 'wcis_db_version', WCIS_VERSION );
	}

	/**
	 * Legt die benötigten Datenbanktabellen an.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$queue           = self::table( self::QUEUE_TABLE );
		$log             = self::table( self::LOG_TABLE );

		$sql_queue = "CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			peer_url VARCHAR(255) NOT NULL DEFAULT '',
			endpoint VARCHAR(64) NOT NULL DEFAULT '/stock',
			payload LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			direction VARCHAR(10) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_queue );
		dbDelta( $sql_log );
	}
}
