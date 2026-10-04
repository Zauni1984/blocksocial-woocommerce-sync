<?php
/**
 * Plugin Name:       BlockSocial WooCommerce Sync
 * Plugin URI:        https://github.com/Zauni1984/blocksocial-woocommerce-sync
 * Description:        Enterprise-Grade WooCommerce-Plugin für Produkt- und Bestands-Synchronisation zwischen mehreren Shops in nahezu Echtzeit. Zuordnung per SKU, wählbarer Hauptshop (Master), Partner-Verwaltung mit eigenen Zugangsschlüsseln, Preisregeln und Shopify-Anbindung. Baue dein eigenes Dropshipping-/B2B-Business auf. (Admin-Edition – für Partnershops gibt es das separate Partner-Plugin.)
 * Version:           3.1.1
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

define( 'WCIS_EDITION', 'admin' );
define( 'WCIS_FILE', __FILE__ );

require_once __DIR__ . '/includes/bootstrap.php';
