<?php
/**
 * Installation: Datenbanktabellen, Geheimschlüssel, Release-Verzeichnis.
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installation und Upgrades.
 */
class BSLS_Install {

	const DB_VERSION = '1';

	/**
	 * Tabellenname Lizenzen.
	 *
	 * @return string
	 */
	public static function licenses_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsls_licenses';
	}

	/**
	 * Tabellenname Shops (Plätze).
	 *
	 * @return string
	 */
	public static function sites_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsls_sites';
	}

	/**
	 * Aktivierung.
	 */
	public static function activate() {
		self::create_tables();
		if ( ! get_option( 'bsls_secret' ) ) {
			update_option( 'bsls_secret', wp_generate_password( 64, true, true ), false );
		}
		BSLS_Releases::ensure_dir();
		BSLS_Account::add_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Tabellen bei Versionswechsel anlegen/aktualisieren.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'bsls_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Legt die Tabellen an (dbDelta).
	 */
	protected static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$l       = self::licenses_table();
		$s       = self::sites_table();

		dbDelta(
			"CREATE TABLE {$l} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_key varchar(40) NOT NULL,
				family varchar(64) NOT NULL DEFAULT '',
				tier varchar(64) NOT NULL DEFAULT '',
				sites int(11) NOT NULL DEFAULT 3,
				status varchar(20) NOT NULL DEFAULT 'active',
				expires_at datetime NULL DEFAULT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				master_site varchar(190) NOT NULL DEFAULT '',
				notes text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_key (license_key),
				KEY user_id (user_id),
				KEY order_id (order_id),
				KEY master_site (master_site)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$s} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				site varchar(190) NOT NULL,
				role varchar(20) NOT NULL DEFAULT 'shop',
				covered tinyint(1) NOT NULL DEFAULT 1,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_site (license_id,site),
				KEY site (site)
			) {$charset};"
		);
		update_option( 'bsls_db_version', self::DB_VERSION );
	}
}
