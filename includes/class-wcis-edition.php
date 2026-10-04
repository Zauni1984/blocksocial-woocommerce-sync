<?php
/**
 * Editions-Helfer: Admin-Plugin (Hauptshop / eigene Shops) oder Partner-Plugin.
 *
 * - admin:   volles Plugin für den Administrator (Hauptshop und eigene Neben-
 *            Shops): Netzwerk, Partner-Verwaltung, Shopify, alle Einstellungen.
 * - partner: abgesichertes Plugin für Partnershops. Verbindet sich mit einem
 *            persönlichen Verbindungscode nur mit dem Hauptshop; alle Vorgaben
 *            (Sync-Verhalten, Sortiment, Preis-Grenzen) kommen vom Administrator
 *            und können im Partnershop nicht verändert werden.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edition des laufenden Plugins.
 */
class WCIS_Edition {

	/**
	 * Aktuelle Edition ('admin' | 'partner').
	 *
	 * @return string
	 */
	public static function current() {
		return ( defined( 'WCIS_EDITION' ) && 'partner' === WCIS_EDITION ) ? 'partner' : 'admin';
	}

	/**
	 * Läuft das Partner-Plugin?
	 *
	 * @return bool
	 */
	public static function is_partner() {
		return 'partner' === self::current();
	}

	/**
	 * Läuft das Admin-Plugin?
	 *
	 * @return bool
	 */
	public static function is_admin_edition() {
		return 'admin' === self::current();
	}

	/**
	 * Anzeigename des Plugins.
	 *
	 * @return string
	 */
	public static function plugin_name() {
		return self::is_partner()
			? __( 'BlockSocial WooCommerce Sync – Partner', 'blocksocial-woocommerce-sync' )
			: __( 'BlockSocial WooCommerce Sync', 'blocksocial-woocommerce-sync' );
	}
}
