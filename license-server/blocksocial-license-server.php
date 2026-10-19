<?php
/**
 * Plugin Name:       BlockSocial Lizenzserver
 * Plugin URI:        https://blocksocial.eu
 * Description:       Verkauf und Verwaltung von Plugin-Lizenzen über WooCommerce: Lizenzschlüssel beim Kauf, Plätze (verbundene Shops) je Lizenzstufe, Verlängerung und Upgrade im Kundenkonto, automatische Plugin-Updates für Lizenznehmer.
 * Version:           1.0.0
 * Author:            BlockSocial UG (haftungsbeschränkt)
 * Author URI:        https://blocksocial.eu
 * License:           GPL-2.0-or-later
 * Text Domain:       blocksocial-license-server
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BSLS_VERSION', '1.0.0' );
define( 'BSLS_FILE', __FILE__ );
define( 'BSLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSLS_REST_NS', 'bsls/v1' );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'BSLS_' ) ) {
			return;
		}
		$file = BSLS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'BSLS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// HPOS-Kompatibilität (WooCommerce-Bestellungen in eigenen Tabellen).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Lädt die Übersetzung (Deutsch ist die Ausgangssprache). Fehlt die genaue
 * Sprachvariante (z. B. fr_CH), wird eine Variante derselben Sprache genutzt.
 */
function bsls_load_translations( $locale = '' ) {
	$domain = 'blocksocial-license-server';
	$locale = '' !== (string) $locale ? (string) $locale : determine_locale();
	$dir    = BSLS_PATH . 'languages/';
	$file   = $dir . $domain . '-' . $locale . '.mo';
	if ( ! file_exists( $file ) ) {
		$same = glob( $dir . $domain . '-' . substr( $locale, 0, 2 ) . '_*.mo' );
		$file = $same ? $same[0] : '';
	}
	unload_textdomain( $domain ); // bei Sprachwechsel alte Sprache entfernen
	if ( '' !== $file ) {
		load_textdomain( $domain, $file, $locale );
	}
}
// Bei Sprachwechsel (z. B. E-Mails in der Sprache des Kunden) neu laden.
add_action( 'change_locale', 'bsls_load_translations' );

add_action(
	'plugins_loaded',
	static function () {
		bsls_load_translations();
		BSLS_Install::maybe_upgrade();
		BSLS_Api::init();
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'BlockSocial Lizenzserver benötigt WooCommerce.', 'blocksocial-license-server' ) . '</p></div>';
				}
			);
			return;
		}
		BSLS_Product::init();
		BSLS_Orders::init();
		BSLS_Account::init();
		if ( is_admin() ) {
			BSLS_Admin::init();
		}
	}
);
