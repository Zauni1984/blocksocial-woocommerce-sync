<?php
/**
 * Plugin Name:       BlockSocial WooCommerce Sync – Partner
 * Plugin URI:        https://github.com/Zauni1984/blocksocial-woocommerce-sync
 * Description:        Partner-Plugin für Partnershops im BlockSocial-Verbund. Verbindet diesen Shop per persönlichem Verbindungscode mit dem Hauptshop: Produkte und Bestände kommen automatisch vom Hauptshop, Verkäufe werden zurückgemeldet. Alle Vorgaben des Administrators sind geschützt – eigene Preisaufschläge (in %, je Kategorie oder für alle Produkte) sind im erlaubten Rahmen möglich.
 * Version:           3.1.2
 * Author:            BlockSocial UG (haftungsbeschränkt)
 * Author URI:        https://blocksocial.eu
 * License:           MIT
 * Text Domain:       blocksocial-woocommerce-sync
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   11.1
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direktzugriff verhindern.
}

// Beide Editionen teilen sich den Code – sie dürfen nicht gleichzeitig aktiv sein.
if ( defined( 'WCIS_EDITION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'BlockSocial WooCommerce Sync: Admin- und Partner-Plugin sind gleichzeitig aktiv. Bitte nur EINES davon aktivieren.', 'blocksocial-woocommerce-sync' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'WCIS_EDITION', 'partner' );
define( 'WCIS_FILE', __FILE__ );

require_once __DIR__ . '/includes/bootstrap.php';
