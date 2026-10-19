<?php
/**
 * Gemeinsamer Bootstrap beider Editionen (Admin-Plugin und Partner-Plugin).
 *
 * Die jeweilige Haupt-Datei definiert vorher WCIS_EDITION ('admin' | 'partner')
 * und WCIS_FILE und bindet diese Datei ein. Dadurch teilen sich beide Plugins
 * denselben Code, dasselbe Protokoll und dieselbe Versionsnummer.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Konstanten
// -----------------------------------------------------------------------------
define( 'WCIS_VERSION', '3.2.0' );
define( 'WCIS_PATH', plugin_dir_path( WCIS_FILE ) );
define( 'WCIS_URL', plugin_dir_url( WCIS_FILE ) );
define( 'WCIS_BASENAME', plugin_basename( WCIS_FILE ) );
define( 'WCIS_REST_NS', 'wc-inventory-sync/v1' );
define( 'WCIS_OPT', 'wcis_settings' );

// -----------------------------------------------------------------------------
// HPOS-Kompatibilität (High-Performance Order Storage) deklarieren.
// -----------------------------------------------------------------------------
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCIS_FILE, true );
		}
	}
);

// -----------------------------------------------------------------------------
// Autoloader für die Plugin-Klassen (includes/class-wcis-*.php).
// -----------------------------------------------------------------------------
spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'WCIS_' ) !== 0 ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$path = WCIS_PATH . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// -----------------------------------------------------------------------------
// Aktivierung / Deaktivierung
// -----------------------------------------------------------------------------
register_activation_hook( WCIS_FILE, array( 'WCIS_Install', 'activate' ) );
register_deactivation_hook( WCIS_FILE, array( 'WCIS_Install', 'deactivate' ) );

// -----------------------------------------------------------------------------
// Übersetzungen (Deutsch ist die Ausgangssprache).
// -----------------------------------------------------------------------------

/**
 * Lädt die Übersetzung passend zur Sprache der Website bzw. des Benutzers.
 * Gibt es keine Datei für die genaue Sprachvariante (z. B. fr_CH, en_GB,
 * es_MX), wird eine Variante derselben Sprache verwendet (fr_FR, en_US, es_ES).
 */
function wcis_load_translations( $locale = '' ) {
	$domain = 'blocksocial-woocommerce-sync';
	$locale = '' !== (string) $locale ? (string) $locale : determine_locale();
	$dir    = WCIS_PATH . 'languages/';
	$file   = $dir . $domain . '-' . $locale . '.mo';
	if ( ! file_exists( $file ) ) {
		$same = glob( $dir . $domain . '-' . substr( $locale, 0, 2 ) . '_*.mo' );
		$file = $same ? $same[0] : '';
	}
	unload_textdomain( $domain ); // bei Sprachwechsel alte Sprache entfernen
	if ( '' !== $file ) {
		load_textdomain( $domain, $file, $locale );
	} else {
		load_plugin_textdomain( $domain, false, dirname( WCIS_BASENAME ) . '/languages' );
	}
}
// Bei Sprachwechsel (z. B. E-Mails in der Sprache des Kunden) neu laden.
add_action( 'change_locale', 'wcis_load_translations' );

// -----------------------------------------------------------------------------
// Bootstrap – erst laden, wenn WooCommerce verfügbar ist.
// -----------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function () {
		wcis_load_translations();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html( sprintf(
						/* translators: %s: Plugin-Name */
						__( '%s benötigt WooCommerce. Bitte WooCommerce installieren und aktivieren.', 'blocksocial-woocommerce-sync' ),
						WCIS_Edition::plugin_name()
					) );
					echo '</p></div>';
				}
			);
			return;
		}

		WCIS_Plugin::instance()->init();
	}
);
