<?php
/**
 * Sync-Filter: bestimmt pro Shop, welche Produkte synchronisiert werden
 * (Auswahl einzelner Produkte, nach Kategorie, nach Marke; plus Ausschlüsse)
 * und welche Felder (z. B. Preis) beim Produkt-Sync übertragen werden.
 *
 * Der Filter gilt in beide Richtungen: Produkte außerhalb des Umfangs werden
 * weder gesendet noch empfangen (angelegt/verändert).
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
			'price'             => __( 'Preis (regulär)', 'blocksocial-woocommerce-sync' ),
			'sale_price'        => __( 'Angebotspreis', 'blocksocial-woocommerce-sync' ),
			'tax'               => __( 'Steuerstatus & Steuerklasse', 'blocksocial-woocommerce-sync' ),
			'description'       => __( 'Beschreibung', 'blocksocial-woocommerce-sync' ),
			'short_description' => __( 'Kurzbeschreibung', 'blocksocial-woocommerce-sync' ),
			'images'            => __( 'Bilder', 'blocksocial-woocommerce-sync' ),
			'categories'        => __( 'Kategorien', 'blocksocial-woocommerce-sync' ),
			'tags'              => __( 'Schlagwörter', 'blocksocial-woocommerce-sync' ),
			'brands'            => __( 'Marken', 'blocksocial-woocommerce-sync' ),
			'manufacturer'      => __( 'Hersteller', 'blocksocial-woocommerce-sync' ),
			'gtin'              => __( 'EAN / GTIN', 'blocksocial-woocommerce-sync' ),
			'attributes'        => __( 'Attribute', 'blocksocial-woocommerce-sync' ),
			'shipping_class'    => __( 'Versandklasse', 'blocksocial-woocommerce-sync' ),
			'delivery_time'     => __( 'Lieferzeit', 'blocksocial-woocommerce-sync' ),
			'germanized'        => __( 'Germanized: Grundpreis', 'blocksocial-woocommerce-sync' ),
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
	 * Zwischenspeicher der erkannten Marken-Taxonomie (pro Request).
	 *
	 * @var string|null
	 */
	protected static $brand_tax_cache = null;

	/**
	 * Ermittelt die aktive Marken-Taxonomie. Sind mehrere registriert (z. B. die
	 * native WooCommerce-Marke `product_brand` UND „Perfect Woocommerce Brands"
	 * `pwb-brand`), wird die tatsächlich genutzte (mit den meisten Begriffen)
	 * bevorzugt – so wird nicht versehentlich eine leere Taxonomie gewählt.
	 *
	 * @return string Taxonomie-Slug oder '' wenn keine gefunden.
	 */
	public static function brand_taxonomy() {
		if ( null !== self::$brand_tax_cache ) {
			return self::$brand_tax_cache;
		}
		$candidates = apply_filters(
			'wcis_brand_taxonomies',
			array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand', 'product_brands' )
		);
		$best       = '';
		$best_count = -1;
		foreach ( (array) $candidates as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			if ( '' === $best ) {
				$best = $tax; // Fallback: erste vorhandene Taxonomie.
			}
			$count = (int) wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( $count > $best_count ) {
				$best_count = $count;
				$best       = $tax;
			}
		}
		self::$brand_tax_cache = $best;
		return $best;
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
	 * Zwischenspeicher: Term-IDs inkl. aller Unterkategorien (pro Request).
	 *
	 * @var array
	 */
	protected static $tree_cache = array();

	/**
	 * Erweitert Kategorie-IDs um alle Unterkategorien. Eine gewählte (oder
	 * ausgeschlossene) Oberkategorie gilt damit auch für Produkte, die nur einer
	 * Unterkategorie zugeordnet sind.
	 *
	 * @param array  $ids      Term-IDs.
	 * @param string $taxonomy Taxonomie.
	 * @return int[]
	 */
	public static function with_children( array $ids, $taxonomy = 'product_cat' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$key = $taxonomy . ':' . implode( ',', $ids );
		if ( isset( self::$tree_cache[ $key ] ) ) {
			return self::$tree_cache[ $key ];
		}
		$all = $ids;
		foreach ( $ids as $tid ) {
			$children = get_term_children( $tid, $taxonomy );
			if ( ! is_wp_error( $children ) ) {
				$all = array_merge( $all, array_map( 'intval', (array) $children ) );
			}
		}
		self::$tree_cache[ $key ] = array_values( array_unique( $all ) );
		return self::$tree_cache[ $key ];
	}

	/**
	 * Setzt die Request-Caches zurück (z. B. nach geänderten Einstellungen).
	 */
	public static function flush_cache() {
		self::$tree_cache      = array();
		self::$brand_tax_cache = null;
	}

	/**
	 * Hat das Produkt eine der Marken, die vom Kategorie-Ausschluss ausgenommen
	 * sind (z. B. „Growshop ausschließen – außer Marke Spider Farmer")?
	 *
	 * @param int $id Produkt-ID (Eltern).
	 * @return bool
	 */
	protected static function has_exception_brand( $id ) {
		$except = array_map( 'intval', (array) self::cfg( 'filter_exclude_except_brands', array() ) );
		$btax   = self::brand_taxonomy();
		return ! empty( $except ) && $btax && has_term( $except, $btax, $id );
	}

	/**
	 * Soll dieses Produkt synchronisiert werden (ausgehend UND eingehend)?
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

		$cats = self::with_children( (array) self::cfg( 'filter_categories', array() ) );
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

		// Kategorie-Ausschluss inkl. aller Unterkategorien – außer für Marken,
		// die ausdrücklich davon ausgenommen sind.
		$excl_cats = self::with_children( (array) self::cfg( 'filter_exclude_categories', array() ) );
		if ( ! empty( $excl_cats ) && has_term( $excl_cats, 'product_cat', $id ) ) {
			return ! self::has_exception_brand( $id );
		}

		return false;
	}

	/**
	 * Ist ein EINGEHENDES Produkt (Payload) ausgeschlossen?
	 *
	 * @deprecated 3.1.1 – nutze accepts_incoming(), das auch den Modus
	 *             „Nur ausgewählte" berücksichtigt.
	 * @param array $payload Produkt-Payload.
	 * @return bool
	 */
	public static function is_excluded_incoming( $payload ) {
		return ! self::accepts_incoming( $payload );
	}

	/**
	 * Darf ein EINGEHENDES Produkt (Payload) hier angelegt/verändert werden?
	 *
	 * Der Sync-Filter dieses Shops gilt in BEIDE Richtungen: Was hier nicht im
	 * Umfang liegt, wird weder gesendet noch empfangen. Die Prüfung arbeitet mit
	 * den mitgesendeten Kategorie-Pfaden (inkl. Oberkategorien) und Marken – sie
	 * greift deshalb auch bei Produkten, die es hier noch NICHT gibt.
	 *
	 * @param array $payload     Produkt-Payload.
	 * @param int   $existing_id Bereits vorhandenes lokales Produkt (0 = neu, null = per SKU suchen).
	 * @return bool
	 */
	public static function accepts_incoming( $payload, $existing_id = null ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}
		if ( null === $existing_id ) {
			$sku         = isset( $payload['sku'] ) ? (string) $payload['sku'] : '';
			$existing_id = '' !== $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
		}
		$existing_id = (int) $existing_id;

		$terms = self::payload_terms( $payload );

		// 1) Harte Ausschlüsse: Einzelprodukt (lokal) …
		if ( $existing_id ) {
			$excluded_ids = array_map( 'intval', (array) self::cfg( 'filter_exclude_ids', array() ) );
			if ( in_array( self::base_id( $existing_id ), $excluded_ids, true ) ) {
				return false;
			}
		}

		// … und Kategorie (Payload ODER lokale Zuordnung), außer Ausnahme-Marke.
		$excl_cat_ids = (array) self::cfg( 'filter_exclude_categories', array() );
		if ( ! empty( $excl_cat_ids ) ) {
			$cat_hit = self::category_match( $terms, $excl_cat_ids );
			if ( ! $cat_hit && $existing_id ) {
				$cat_hit = has_term( self::with_children( $excl_cat_ids ), 'product_cat', self::base_id( $existing_id ) );
			}
			if ( $cat_hit ) {
				$except = (array) self::cfg( 'filter_exclude_except_brands', array() );
				$exempt = ! empty( $except ) && self::names_intersect( $terms['brands'], self::brand_names( $except ) );
				if ( ! $exempt && $existing_id ) {
					$exempt = self::has_exception_brand( self::base_id( $existing_id ) );
				}
				if ( ! $exempt ) {
					return false;
				}
			}
		}

		// 2) Umfang: „Alle" oder „Nur ausgewählte".
		if ( 'selected' !== self::cfg( 'filter_mode', 'all' ) ) {
			return true;
		}

		$sel_cats = (array) self::cfg( 'filter_categories', array() );
		if ( ! empty( $sel_cats ) && self::category_match( $terms, $sel_cats ) ) {
			return true;
		}
		$sel_brands = (array) self::cfg( 'filter_brands', array() );
		if ( ! empty( $sel_brands ) && self::names_intersect( $terms['brands'], self::brand_names( $sel_brands ) ) ) {
			return true;
		}
		// Bereits vorhandenes Produkt, das lokal im Umfang liegt (Einzel-Freigabe,
		// lokale Kategorie/Marke).
		if ( $existing_id && self::should_sync( $existing_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalisierte Kategorie- und Markennamen einer Payload. Kategorien inkl.
	 * aller Oberkategorien (aus 'cat_paths'), damit z. B. „Growshop" auch dann
	 * erkannt wird, wenn das Produkt nur in „Growshop › Zeltzubehör" liegt.
	 *
	 * @param array $payload Payload.
	 * @return array { cats: name => true, brands: name => true }
	 */
	public static function payload_terms( array $payload ) {
		$cats  = array();
		$paths = array();
		if ( ! empty( $payload['cat_paths'] ) && is_array( $payload['cat_paths'] ) ) {
			foreach ( $payload['cat_paths'] as $path ) {
				$norm = array();
				foreach ( (array) $path as $name ) {
					if ( is_scalar( $name ) && '' !== self::norm_name( $name ) ) {
						$cats[] = $name;
						$norm[] = self::norm_name( $name );
					}
				}
				if ( $norm ) {
					$paths[] = $norm;
				}
			}
		}
		foreach ( array( 'cat_names', 'categories' ) as $k ) {
			if ( ! empty( $payload[ $k ] ) && is_array( $payload[ $k ] ) ) {
				$cats = array_merge( $cats, $payload[ $k ] );
			}
		}
		$brands = array();
		foreach ( array( 'brand_names', 'brands' ) as $k ) {
			if ( ! empty( $payload[ $k ] ) && is_array( $payload[ $k ] ) ) {
				$brands = array_merge( $brands, $payload[ $k ] );
			}
		}
		return array(
			'cats'   => self::name_set( $cats ),
			'paths'  => $paths,
			'brands' => self::name_set( $brands ),
		);
	}

	/**
	 * Liegt eine Payload in einer der Kategorien (inkl. Unterkategorien)?
	 *
	 * Mit Kategorie-Pfaden (Absender ab 3.1.1) wird der Zweig berücksichtigt:
	 * Trifft nur ein gleichnamiger Unterkategorie-Name (z. B. „Filter"), liegt
	 * der Pfad aber hier in einer ANDEREN Hauptkategorie, zählt das nicht.
	 * Ohne Pfade (ältere Absender) wird über die Kategorienamen verglichen.
	 *
	 * @param array $terms   Ergebnis von payload_terms().
	 * @param array $cat_ids Term-IDs (gewählt bzw. ausgeschlossen).
	 * @return bool
	 */
	protected static function category_match( array $terms, array $cat_ids ) {
		$direct = array();
		foreach ( array_map( 'intval', $cat_ids ) as $tid ) {
			$term = get_term( $tid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$direct[ self::norm_name( $term->name ) ] = true;
			}
		}
		$all = self::category_names( $cat_ids );

		if ( empty( $terms['paths'] ) ) {
			return self::names_intersect( $terms['cats'], $all );
		}

		$roots = self::root_category_names();
		foreach ( $terms['paths'] as $path ) {
			if ( self::names_intersect( array_flip( $path ), $direct ) ) {
				return true;
			}
			if ( ! self::names_intersect( array_flip( $path ), $all ) ) {
				continue;
			}
			// Nur über einen Unterkategorie-Namen getroffen: zählt nicht, wenn die
			// Hauptkategorie des Pfads hier eine andere (bekannte) Hauptkategorie ist.
			$root = $path[0];
			if ( count( $path ) > 1 && isset( $roots[ $root ] ) && ! isset( $all[ $root ] ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Set der Hauptkategorien (ohne Elternkategorie) dieses Shops.
	 *
	 * @return array name => true
	 */
	protected static function root_category_names() {
		if ( isset( self::$tree_cache['__roots'] ) ) {
			return self::$tree_cache['__roots'];
		}
		$names = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		self::$tree_cache['__roots'] = is_wp_error( $names ) ? array() : self::name_set( (array) $names );
		return self::$tree_cache['__roots'];
	}

	/**
	 * Wandelt eine Namensliste in ein normalisiertes Set um.
	 *
	 * @param array $names Namen.
	 * @return array name => true
	 */
	protected static function name_set( array $names ) {
		$set = array();
		foreach ( $names as $n ) {
			if ( ! is_scalar( $n ) ) {
				continue;
			}
			$key = self::norm_name( $n );
			if ( '' !== $key ) {
				$set[ $key ] = true;
			}
		}
		return $set;
	}

	/**
	 * Haben zwei Namens-Sets eine Schnittmenge?
	 *
	 * @param array $a Set.
	 * @param array $b Set.
	 * @return bool
	 */
	protected static function names_intersect( array $a, array $b ) {
		foreach ( $a as $k => $unused ) {
			if ( isset( $b[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Set (name => true) der Kategorien inkl. ihrer Unterkategorien.
	 *
	 * @param array $cat_ids Term-IDs.
	 * @return array
	 */
	protected static function category_names( array $cat_ids ) {
		$names = array();
		foreach ( self::with_children( $cat_ids ) as $tid ) {
			$term = get_term( $tid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[ self::norm_name( $term->name ) ] = true;
			}
		}
		unset( $names[''] );
		return $names;
	}

	/**
	 * Set (name => true) der Marken.
	 *
	 * @param array $brand_ids Term-IDs.
	 * @return array
	 */
	protected static function brand_names( array $brand_ids ) {
		$btax = self::brand_taxonomy();
		if ( ! $btax ) {
			return array();
		}
		$names = array();
		foreach ( array_map( 'intval', $brand_ids ) as $tid ) {
			$term = get_term( $tid, $btax );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[ self::norm_name( $term->name ) ] = true;
			}
		}
		unset( $names[''] );
		return $names;
	}

	/**
	 * Normalisiert einen Namen für den Vergleich (Entities dekodieren, trim,
	 * Kleinschreibung) – „Curing &amp; Lagerung" == „Curing & Lagerung".
	 *
	 * @param string $name Name.
	 * @return string
	 */
	protected static function norm_name( $name ) {
		$name = trim( html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$name = preg_replace( '/\s+/u', ' ', $name );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
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
