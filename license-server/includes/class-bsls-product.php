<?php
/**
 * Lizenz-Einstellungen am WooCommerce-Produkt (einfaches Produkt oder Variante):
 * Lizenzfamilie, Stufe, Plätze, Laufzeit.
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produkt-Felder.
 */
class BSLS_Product {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'simple_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/**
	 * Lizenz-Konfiguration eines Produkts bzw. einer Variante.
	 *
	 * @param WC_Product|int $product Produkt.
	 * @return array|null { family, tier, sites, months }
	 */
	public static function config( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( 'yes' !== $product->get_meta( '_bsls_enabled' ) ) {
			return null;
		}
		$families = BSLS_Licenses::families();
		$family   = (string) $product->get_meta( '_bsls_family' );
		return array(
			'family' => isset( $families[ $family ] ) ? $family : (string) key( $families ),
			'tier'   => (string) $product->get_meta( '_bsls_tier' ),
			'sites'  => max( 0, (int) $product->get_meta( '_bsls_sites' ) ),
			'months' => max( 0, (int) $product->get_meta( '_bsls_months' ) ),
		);
	}

	/**
	 * Alle Lizenzprodukte einer Familie (für Upgrades), sortiert nach Plätzen.
	 *
	 * @param string $family Familie.
	 * @return array Liste [ product_id, tier, sites, price ]
	 */
	public static function tiers( $family ) {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_bsls_enabled', 'value' => 'yes' ),
					array( 'key' => '_bsls_family', 'value' => $family ),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$p   = wc_get_product( $id );
			$cfg = self::config( $p );
			if ( ! $p || ! $cfg || ! $p->is_purchasable() ) {
				continue;
			}
			$out[] = array(
				'product_id' => (int) $id,
				'tier'       => $cfg['tier'],
				'sites'      => $cfg['sites'],
				'months'     => $cfg['months'],
				'price'      => (float) $p->get_price(),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$sa = 0 === $a['sites'] ? PHP_INT_MAX : $a['sites'];
				$sb = 0 === $b['sites'] ? PHP_INT_MAX : $b['sites'];
				return $sa <=> $sb;
			}
		);
		return $out;
	}

	/**
	 * Gemeinsame Felder ausgeben.
	 *
	 * @param WC_Product $product Produkt.
	 * @param string     $suffix  Feldnamen-Suffix (Varianten: [index]).
	 * @param string     $wrap    CSS-Klasse.
	 */
	protected static function fields( $product, $suffix, $wrap ) {
		$families = array();
		foreach ( BSLS_Licenses::families() as $k => $f ) {
			$families[ $k ] = $f['label'];
		}
		woocommerce_wp_checkbox(
			array(
				'id'            => '_bsls_enabled' . $suffix,
				'name'          => '_bsls_enabled' . $suffix,
				'label'         => __( 'BlockSocial-Lizenz', 'blocksocial-license-server' ),
				'description'   => __( 'Beim Kauf einen Lizenzschlüssel erzeugen', 'blocksocial-license-server' ),
				'value'         => $product->get_meta( '_bsls_enabled' ),
				'wrapper_class' => $wrap,
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => '_bsls_family' . $suffix,
				'name'          => '_bsls_family' . $suffix,
				'label'         => __( 'Plugin', 'blocksocial-license-server' ),
				'options'       => $families,
				'value'         => $product->get_meta( '_bsls_family' ),
				'wrapper_class' => $wrap,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_bsls_tier' . $suffix,
				'name'          => '_bsls_tier' . $suffix,
				'label'         => __( 'Lizenzstufe', 'blocksocial-license-server' ),
				'placeholder'   => 'Standard',
				'value'         => $product->get_meta( '_bsls_tier' ),
				'wrapper_class' => $wrap,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_bsls_sites' . $suffix,
				'name'              => '_bsls_sites' . $suffix,
				'label'             => __( 'Plätze (Shops)', 'blocksocial-license-server' ),
				'description'       => __( 'Anzahl verbundener Shops inkl. Hauptshop. 0 = unbegrenzt.', 'blocksocial-license-server' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'value'             => '' === (string) $product->get_meta( '_bsls_sites' ) ? 3 : $product->get_meta( '_bsls_sites' ),
				'wrapper_class'     => $wrap,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_bsls_months' . $suffix,
				'name'              => '_bsls_months' . $suffix,
				'label'             => __( 'Laufzeit (Monate)', 'blocksocial-license-server' ),
				'description'       => __( 'Updates & Support. 0 = lebenslang.', 'blocksocial-license-server' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'value'             => '' === (string) $product->get_meta( '_bsls_months' ) ? 12 : $product->get_meta( '_bsls_months' ),
				'wrapper_class'     => $wrap,
			)
		);
	}

	/**
	 * Felder für einfache Produkte.
	 */
	public static function simple_fields() {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		echo '<div class="options_group show_if_simple">';
		self::fields( $product_object, '', '' );
		echo '</div>';
	}

	/**
	 * Speichert einfache Produkte.
	 *
	 * @param WC_Product $product Produkt.
	 */
	public static function save_simple( $product ) {
		if ( ! $product->is_type( 'simple' ) ) {
			return;
		}
		self::save_values( $product, '' );
	}

	/**
	 * Felder je Variante.
	 *
	 * @param int     $loop           Index.
	 * @param array   $variation_data Daten.
	 * @param WP_Post $variation      Variante.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		$v = wc_get_product( $variation->ID );
		if ( ! $v ) {
			return;
		}
		echo '<div class="bsls-variation"><style>.bsls-variation .checkbox+.description{margin-left:.5em}</style>';
		self::fields( $v, '[' . (int) $loop . ']', 'form-row form-row-full' );
		echo '</div>';
	}

	/**
	 * Speichert eine Variante.
	 *
	 * @param int $variation_id ID.
	 * @param int $i            Index.
	 */
	public static function save_variation( $variation_id, $i ) {
		$v = wc_get_product( $variation_id );
		if ( ! $v ) {
			return;
		}
		self::save_values( $v, $i );
		$v->save();
	}

	/**
	 * Übernimmt die Formularwerte.
	 *
	 * @param WC_Product $product Produkt.
	 * @param string|int $i       Variantenindex oder ''.
	 */
	protected static function save_values( $product, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce des Produktformulars.
		$get = static function ( $key ) use ( $i ) {
			if ( '' === $i ) {
				return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
			}
			return isset( $_POST[ $key ][ $i ] ) ? wp_unslash( $_POST[ $key ][ $i ] ) : null;
		};
		// phpcs:enable
		$product->update_meta_data( '_bsls_enabled', null !== $get( '_bsls_enabled' ) ? 'yes' : 'no' );
		$product->update_meta_data( '_bsls_family', sanitize_key( (string) $get( '_bsls_family' ) ) );
		$product->update_meta_data( '_bsls_tier', sanitize_text_field( (string) $get( '_bsls_tier' ) ) );
		$product->update_meta_data( '_bsls_sites', max( 0, (int) $get( '_bsls_sites' ) ) );
		$product->update_meta_data( '_bsls_months', max( 0, (int) $get( '_bsls_months' ) ) );
	}
}
