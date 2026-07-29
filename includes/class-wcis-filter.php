<?php
/**
 * Sync-Filter: bestimmt pro Shop, welche Produkte synchronisiert werden
 * (Auswahl einzelner Produkte, nach Kategorie, nach Marke; plus Ausschlüsse)
 * und welche Felder (z. B. Preis) beim Produkt-Sync übertragen werden.
 *
 * Der Filter gilt für ausgehende Synchronisation (was dieser Shop sendet).
 * Ausgeschlossene Produkte werden zusätzlich auch eingehend nicht verändert.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter-Logik.
 */
class WCIS_Filter {

	/**
	 * Optionale Override-Konfiguration (für die Live-Vorschau ungespeicherter Werte).
	 *
	 * @var array|null
	 */
	protected static $overrides = null;

	/**
	 * Setzt temporäre Override-Werte (Vorschau).
	 *
	 * @param array $config Teilkonfiguration.
	 */
	public static function set_overrides( array $config ) {
		self::$overrides = $config;
	}

	/**
	 * Entfernt die Override-Werte.
	 */
	public static function clear_overrides() {
		self::$overrides = null;
	}

	/**
	 * Liefert einen Filter-Konfigurationswert (Override hat Vorrang).
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Standard.
	 * @return mixed
	 */
	protected static function cfg( $key, $default = null ) {
		if ( null !== self::$overrides && array_key_exists( $key, self::$overrides ) ) {
			return self::$overrides[ $key ];
		}
		return WCIS_Settings::get( $key, $default );
	}

	/**
	 * Alle bekannten Feld-Schlüssel des Produkt-Syncs (für die Feld-Auswahl).
	 *
	 * @return array key => Label.
	 */
	public static function product_field_labels() {
		return array(
			'name'              => __( 'Name/Titel', 'blocksocial-woocommerce-sync' ),
			'price'             => __( 'Preis (regulär & Angebot)', 'blocksocial-woocommerce-sync' ),
			'tax'               => __( 'Steuerstatus & Steuerklasse', 'blocksocial-woocommerce-sync' ),
			'description'       => __( 'Beschreibung', 'blocksocial-woocommerce-sync' ),
			'short_description' => __( 'Kurzbeschreibung', 'blocksocial-woocommerce-sync' ),
			'images'            => __( 'Bilder', 'blocksocial-woocommerce-sync' ),
			'categories'        => __( 'Kategorien', 'blocksocial-woocommerce-sync' ),
			'tags'              => __( 'Schlagwörter', 'blocksocial-woocommerce-sync' ),
			'attributes'        => __( 'Attribute', 'blocksocial-woocommerce-sync' ),
			'dimensions'        => __( 'Maße & Gewicht', 'blocksocial-woocommerce-sync' ),
			'status'            => __( 'Veröffentlichungsstatus', 'blocksocial-woocommerce-sync' ),
			'stock'             => __( 'Lagerbestand (bei Produktanlage)', 'blocksocial-woocommerce-sync' ),
		);
	}

	/**
	 * Ist ein Produkt-Feld für die Übertragung freigegeben?
	 *
	 * @param string $key Feld-Schlüssel.
	 * @return bool
	 */
	public static function field_enabled( $key ) {
		$fields = WCIS_Settings::get( 'product_fields', null );
		if ( ! is_array( $fields ) ) {
			return true; // Standard: alle Felder.
		}
		if ( 'name' === $key ) {
			return true; // Name wird für die Zuordnung/Anlage immer benötigt.
		}
		return in_array( $key, $fields, true );
	}

	/**
	 * Ermittelt die aktive Marken-Taxonomie (falls vorhanden).
	 *
	 * @return string Taxonomie-Slug oder '' wenn keine gefunden.
	 */
	public static function brand_taxonomy() {
		$candidates = apply_filters(
			'wcis_brand_taxonomies',
			array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand', 'product_brands' )
		);
		foreach ( (array) $candidates as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}
		return '';
	}

	/**
	 * Normalisiert ein Produkt auf die relevante (Eltern-)ID.
	 *
	 * @param WC_Product|int $product Produkt oder ID.
	 * @return int
	 */
	protected static function base_id( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}
		return $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	}

	/**
	 * Soll dieses Produkt (ausgehend) synchronisiert werden?
	 *
	 * @param WC_Product|int $product Produkt.
	 * @return bool
	 */
	public static function should_sync( $product ) {
		$id = self::base_id( $product );
		if ( ! $id ) {
			return false;
		}

		// Harte Ausschlüsse (Einzelprodukt oder Kategorie) haben immer Vorrang.
		if ( self::is_excluded( $id ) ) {
			return false;
		}

		$mode = self::cfg( 'filter_mode', 'all' );
		if ( 'all' === $mode ) {
			return true;
		}

		// Modus "selected": mindestens ein Kriterium muss zutreffen.
		$include = array_map( 'intval', (array) self::cfg( 'filter_include_ids', array() ) );
		if ( in_array( $id, $include, true ) ) {
			return true;
		}

		$cats = array_map( 'intval', (array) self::cfg( 'filter_categories', array() ) );
		if ( ! empty( $cats ) && has_term( $cats, 'product_cat', $id ) ) {
			return true;
		}

		$brands = array_map( 'intval', (array) self::cfg( 'filter_brands', array() ) );
		$btax   = self::brand_taxonomy();
		if ( ! empty( $brands ) && $btax && has_term( $brands, $btax, $id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Ist dieses Produkt explizit ausgeschlossen (gilt auch eingehend)?
	 *
	 * @param WC_Product|int $product Produkt.
	 * @return bool
	 */
	public static function is_excluded( $product ) {
		$id = self::base_id( $product );
		if ( ! $id ) {
			return false;
		}
		$excluded = array_map( 'intval', (array) self::cfg( 'filter_exclude_ids', array() ) );
		if ( in_array( $id, $excluded, true ) ) {
			return true;
		}

		$excl_cats = array_map( 'intval', (array) self::cfg( 'filter_exclude_categories', array() ) );
		if ( ! empty( $excl_cats ) && has_term( $excl_cats, 'product_cat', $id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Vorschau: zählt Produkte im Sync-Umfang und liefert eine Stichprobe.
	 *
	 * @param int $sample_size Anzahl Beispielprodukte.
	 * @param int $scan_limit  Maximale Anzahl zu prüfender Produkte.
	 * @return array
	 */
	public static function preview( $sample_size = 25, $scan_limit = 5000 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => (int) $scan_limit,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sku',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$scanned  = count( $ids );
		$in_scope = 0;
		$excluded = 0;
		$sample   = array();

		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) ) {
				continue;
			}
			if ( self::is_excluded( $product ) ) {
				$excluded++;
				continue;
			}
			if ( self::should_sync( $product ) ) {
				$in_scope++;
				if ( count( $sample ) < $sample_size ) {
					$sample[] = array(
						'name' => $product->get_name(),
						'sku'  => $product->get_sku(),
					);
				}
			}
		}

		return array(
			'scanned'   => $scanned,
			'in_scope'  => $in_scope,
			'excluded'  => $excluded,
			'sample'    => $sample,
			'truncated' => $scanned >= (int) $scan_limit,
		);
	}
}
