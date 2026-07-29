<?php
/**
 * Optionaler Produkt-Sync: überträgt neue Produkte 1:1 an die anderen Shops
 * (einfache und variable Produkte), inklusive Veröffentlichungsstatus.
 *
 * Zuordnung per SKU. Bestehende Produkte werden standardmäßig nicht verändert
 * (nur der Lagerbestand wird über den regulären Sync gepflegt).
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produkt-Synchronisation.
 */
class WCIS_Product_Sync {

	/**
	 * Sperre gegen Endlosschleifen, während ein Produkt lokal angelegt/aktualisiert wird.
	 *
	 * @var bool
	 */
	protected static $suppress = false;

	/**
	 * Options-Schlüssel für den Massen-Übertragungs-Job.
	 */
	const JOB_OPT = 'wcis_productsync_job';

	/**
	 * Transient mit den zu übertragenden Produkt-IDs.
	 */
	const IDS_TR = 'wcis_productsync_ids';

	/**
	 * Options-Schlüssel für den Pull-Job (Produkte vom Hauptshop holen).
	 */
	const PULL_JOB_OPT = 'wcis_productpull_job';

	/**
	 * Zeitbudget pro Tick (Sekunden).
	 */
	const TICK_BUDGET = 8.0;

	/**
	 * Produkt-IDs, die in diesem Request bereits übertragen wurden (Dedupe).
	 *
	 * @var array
	 */
	protected static $broadcasted = array();

	/**
	 * Registriert die Hooks.
	 *
	 * Bewusst NICHT an `woocommerce_update_product` gehängt: dieser Hook feuert
	 * bei jeder Speicherung (auch bei jeder Bestandsänderung durch einen Verkauf)
	 * und würde die volle Produkt-Payload unnötig oft senden. Stattdessen:
	 * - Neuanlage: `woocommerce_new_product`
	 * - Statuswechsel (veröffentlicht/privat/…): `transition_post_status`
	 */
	public static function init() {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_new_product' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_transition' ), 20, 3 );
	}

	/**
	 * Soll dieser Shop Produkte broadcasten?
	 *
	 * @return bool
	 */
	protected static function should_broadcast() {
		if ( self::$suppress || ! WCIS_Settings::is_enabled() ) {
			return false;
		}
		if ( ! WCIS_Settings::get( 'product_sync_enabled', false ) ) {
			return false;
		}
		if ( '' === WCIS_Settings::secret() || empty( WCIS_Settings::get_peers() ) ) {
			return false;
		}
		// Quelle: nur Hauptshop oder jeder Shop.
		if ( 'any' !== WCIS_Settings::get( 'product_sync_source', 'master' ) && ! WCIS_Settings::is_master() ) {
			return false;
		}
		return true;
	}

	/**
	 * Hook: neues Produkt angelegt.
	 *
	 * @param int        $product_id Produkt-ID.
	 * @param WC_Product $product    Produkt (optional).
	 */
	public static function on_new_product( $product_id, $product = null ) {
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		self::broadcast_product( $product );
	}

	/**
	 * Hook: Statuswechsel eines Produkts (veröffentlicht/privat/Entwurf).
	 *
	 * @param string  $new_status Neuer Status.
	 * @param string  $old_status Alter Status.
	 * @param WP_Post $post       Beitrag.
	 */
	public static function on_status_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		// Nur relevante Zielstatus; Zwischenzustände ignorieren.
		if ( ! in_array( $new_status, array( 'publish', 'private', 'draft', 'pending' ), true ) ) {
			return;
		}
		self::broadcast_product( wc_get_product( $post->ID ) );
	}

	/**
	 * Überträgt ein Produkt an alle Peers (über die Retry-Queue, dedupliziert).
	 *
	 * @param WC_Product|false $product Produkt.
	 */
	protected static function broadcast_product( $product ) {
		if ( ! self::should_broadcast() ) {
			return;
		}
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		// Nur einfache und variable Produkte mit SKU.
		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			return;
		}
		if ( '' === $product->get_sku() ) {
			return;
		}
		// Sync-Filter dieses Shops berücksichtigen.
		if ( ! WCIS_Filter::should_sync( $product ) ) {
			return;
		}
		// Pro Request nur einmal je Produkt übertragen.
		if ( isset( self::$broadcasted[ $product->get_id() ] ) ) {
			return;
		}
		self::$broadcasted[ $product->get_id() ] = true;

		$payload = self::build_payload( $product );
		if ( ! $payload ) {
			return;
		}

		// Guaranteed Delivery: über die Retry-Queue (wird bei Ausfall nachgereicht).
		foreach ( WCIS_Settings::get_peers() as $peer ) {
			WCIS_Queue::add( $peer['url'], array( 'source' => WCIS_Settings::this_url(), 'product' => $payload ), '', '/product' );
		}
		WCIS_Logger::info( sprintf( 'Produkt „%s" (SKU %s) zur Übertragung eingereiht.', $product->get_name(), $payload['sku'] ), 'outbound' );
	}

	/**
	 * Baut die 1:1-Payload eines Produkts.
	 *
	 * @param WC_Product $product Produkt.
	 * @return array|null
	 */
	public static function build_payload( $product ) {
		$sku = $product->get_sku();
		if ( '' === $sku ) {
			return null;
		}
		$type = $product->is_type( 'variable' ) ? 'variable' : 'simple';

		// Grunddaten (immer nötig für Zuordnung/Anlage).
		$data = array(
			'sku'  => $sku,
			'type' => $type,
			'name' => $product->get_name(),
			'slug' => $product->get_slug(),
		);

		// Feld-Auswahl dieses Shops berücksichtigen.
		if ( WCIS_Filter::field_enabled( 'status' ) ) {
			$data['status'] = get_post_status( $product->get_id() );
		}
		if ( WCIS_Filter::field_enabled( 'description' ) ) {
			$data['description'] = $product->get_description();
		}
		if ( WCIS_Filter::field_enabled( 'short_description' ) ) {
			$data['short_description'] = $product->get_short_description();
		}
		if ( WCIS_Filter::field_enabled( 'price' ) ) {
			$data['regular_price'] = $product->get_regular_price();
			$data['sale_price']    = $product->get_sale_price();
		}
		if ( WCIS_Filter::field_enabled( 'tax' ) ) {
			$data['tax_status'] = $product->get_tax_status();
			$data['tax_class']  = $product->get_tax_class();
		}
		if ( WCIS_Filter::field_enabled( 'stock' ) ) {
			$data['manage_stock']   = $product->managing_stock();
			$data['stock_quantity'] = $product->get_stock_quantity();
			$data['stock_status']   = $product->get_stock_status();
			$data['backorders']     = $product->get_backorders();
		}
		if ( WCIS_Filter::field_enabled( 'dimensions' ) ) {
			$data['weight']     = $product->get_weight();
			$data['dimensions'] = array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			);
		}
		if ( WCIS_Filter::field_enabled( 'categories' ) ) {
			$data['categories'] = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		}
		if ( WCIS_Filter::field_enabled( 'tags' ) ) {
			$data['tags'] = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		}
		if ( WCIS_Filter::field_enabled( 'attributes' ) ) {
			$data['attributes'] = self::export_attributes( $product );
		}
		if ( WCIS_Filter::field_enabled( 'images' ) && WCIS_Settings::get( 'product_sync_images', true ) ) {
			$data['images'] = self::export_images( $product );
		}

		$data['variations'] = ( 'variable' === $type ) ? self::export_variations( $product ) : array();

		return $data;
	}

	/**
	 * Exportiert Produktattribute als [name, options, visible, variation].
	 *
	 * @param WC_Product $product Produkt.
	 * @return array
	 */
	protected static function export_attributes( $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute ) {
				continue;
			}
			if ( $attr->is_taxonomy() ) {
				$name    = wc_attribute_label( $attr->get_name() );
				$options = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) );
			} else {
				$name    = $attr->get_name();
				$options = $attr->get_options();
			}
			$out[] = array(
				'name'      => $name,
				'options'   => array_values( (array) $options ),
				'visible'   => (bool) $attr->get_visible(),
				'variation' => (bool) $attr->get_variation(),
			);
		}
		return $out;
	}

	/**
	 * Exportiert die Variationen eines variablen Produkts.
	 *
	 * @param WC_Product $product Produkt.
	 * @return array
	 */
	protected static function export_variations( $product ) {
		$out = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v instanceof WC_Product_Variation ) {
				continue;
			}

			// Variations-Attribute als Name=>Options-Label (konsistent zum Parent).
			$vattr = array();
			foreach ( $v->get_attributes() as $key => $value ) {
				if ( taxonomy_exists( $key ) ) {
					$label = wc_attribute_label( $key );
					$term  = get_term_by( 'slug', $value, $key );
					$opt   = $term ? $term->name : $value;
				} else {
					$label = $key;
					$opt   = $value;
				}
				$vattr[ $label ] = $opt;
			}

			$out[] = array(
				'sku'            => $v->get_sku(),
				'attributes'     => $vattr,
				'regular_price'  => $v->get_regular_price(),
				'sale_price'     => $v->get_sale_price(),
				'tax_status'     => $v->get_tax_status(),
				'tax_class'      => $v->get_tax_class(),
				'manage_stock'   => $v->managing_stock(),
				'stock_quantity' => $v->get_stock_quantity(),
				'stock_status'   => $v->get_stock_status(),
				'status'         => $v->get_status(),
				'weight'         => $v->get_weight(),
				'image'          => WCIS_Settings::get( 'product_sync_images', true ) ? self::first_image_url( $v->get_image_id() ) : '',
			);
		}
		return $out;
	}

	/**
	 * Exportiert Bild-URLs (Beitragsbild zuerst, dann Galerie).
	 *
	 * @param WC_Product $product Produkt.
	 * @return array
	 */
	protected static function export_images( $product ) {
		$urls = array();
		$main = self::first_image_url( $product->get_image_id() );
		if ( $main ) {
			$urls[] = $main;
		}
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$u = self::first_image_url( $gid );
			if ( $u ) {
				$urls[] = $u;
			}
		}
		return $urls;
	}

	/**
	 * Liefert die volle Bild-URL zu einer Attachment-ID.
	 *
	 * @param int $attachment_id Anhang-ID.
	 * @return string
	 */
	protected static function first_image_url( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $attachment_id, 'full' );
		return $src ? $src : '';
	}

	// -------------------------------------------------------------------------
	// Empfangsseite: Produkt anlegen/aktualisieren
	// -------------------------------------------------------------------------

	/**
	 * Wendet eine empfangene Produkt-Payload an.
	 *
	 * @param array $payload Produktdaten.
	 * @return string 'created' | 'updated' | 'skipped'.
	 */
	public static function apply_product( $payload ) {
		if ( ! WCIS_Settings::get( 'product_sync_enabled', false ) ) {
			return 'skipped';
		}
		$sku = isset( $payload['sku'] ) ? sanitize_text_field( $payload['sku'] ) : '';
		if ( '' === $sku ) {
			return 'skipped';
		}

		$existing_id     = wc_get_product_id_by_sku( $sku );
		$update_existing = (bool) WCIS_Settings::get( 'product_sync_update_existing', false );

		// Explizit ausgeschlossene Produkte auch eingehend nicht verändern.
		if ( $existing_id && WCIS_Filter::is_excluded( $existing_id ) ) {
			return 'skipped';
		}

		if ( $existing_id && ! $update_existing ) {
			return 'skipped'; // Vorhandenes Produkt nicht anfassen (nur Bestand wird separat gesynct).
		}

		// Broadcasts während des Anlegens unterdrücken (kein Rück-Sync).
		self::$suppress = true;
		WCIS_Sync_Engine::set_suppress( true );

		$is_new  = ! $existing_id;
		$type    = ( isset( $payload['type'] ) && 'variable' === $payload['type'] ) ? 'variable' : 'simple';

		if ( $existing_id ) {
			$product = wc_get_product( $existing_id );
		} elseif ( 'variable' === $type ) {
			$product = new WC_Product_Variable();
		} else {
			$product = new WC_Product_Simple();
		}

		self::apply_common_fields( $product, $payload );

		// Attribute (als produkteigene, benutzerdefinierte Attribute).
		if ( ! empty( $payload['attributes'] ) ) {
			$product->set_attributes( self::build_attribute_objects( $payload['attributes'] ) );
		}

		$product_id = $product->save();

		// Kategorien / Schlagwörter per Name.
		if ( isset( $payload['categories'] ) ) {
			wp_set_object_terms( $product_id, self::term_ids( (array) $payload['categories'], 'product_cat' ), 'product_cat' );
		}
		if ( isset( $payload['tags'] ) ) {
			wp_set_object_terms( $product_id, self::term_ids( (array) $payload['tags'], 'product_tag' ), 'product_tag' );
		}

		// Bilder nur beim Neuanlegen importieren (verhindert Dubletten).
		if ( $is_new && ! empty( $payload['images'] ) && WCIS_Settings::get( 'product_sync_images', true ) ) {
			self::import_images( $product_id, $payload['images'] );
		}

		// Variationen (variable Produkte).
		if ( 'variable' === $type && ! empty( $payload['variations'] ) ) {
			self::apply_variations( $product_id, $payload['variations'] );
		}

		self::$suppress = false;
		WCIS_Sync_Engine::set_suppress( false );

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * Setzt die gemeinsamen Felder inkl. Status.
	 *
	 * @param WC_Product $product Produkt.
	 * @param array      $p       Payload.
	 */
	protected static function apply_common_fields( $product, $p ) {
		if ( isset( $p['name'] ) ) {
			$product->set_name( sanitize_text_field( $p['name'] ) );
		}
		if ( isset( $p['description'] ) ) {
			$product->set_description( wp_kses_post( $p['description'] ) );
		}
		if ( isset( $p['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $p['short_description'] ) );
		}
		if ( isset( $p['regular_price'] ) ) {
			$product->set_regular_price( (string) $p['regular_price'] );
		}
		if ( isset( $p['sale_price'] ) ) {
			$product->set_sale_price( (string) $p['sale_price'] );
		}
		if ( isset( $p['tax_status'] ) ) {
			$product->set_tax_status( $p['tax_status'] );
		}
		if ( isset( $p['tax_class'] ) ) {
			self::apply_tax_class( $product, $p['tax_class'] );
		}
		if ( isset( $p['weight'] ) ) {
			$product->set_weight( $p['weight'] );
		}
		if ( isset( $p['dimensions'] ) && is_array( $p['dimensions'] ) ) {
			$product->set_length( isset( $p['dimensions']['length'] ) ? $p['dimensions']['length'] : '' );
			$product->set_width( isset( $p['dimensions']['width'] ) ? $p['dimensions']['width'] : '' );
			$product->set_height( isset( $p['dimensions']['height'] ) ? $p['dimensions']['height'] : '' );
		}

		// Lagerbestand (nur bei einfachen Produkten sinnvoll; variable steuern über Variationen).
		if ( 'variable' !== ( isset( $p['type'] ) ? $p['type'] : 'simple' ) ) {
			if ( ! empty( $p['manage_stock'] ) ) {
				$product->set_manage_stock( true );
				if ( isset( $p['stock_quantity'] ) && null !== $p['stock_quantity'] ) {
					$product->set_stock_quantity( wc_stock_amount( $p['stock_quantity'] ) );
				}
			} else {
				$product->set_manage_stock( false );
			}
			if ( isset( $p['stock_status'] ) ) {
				$product->set_stock_status( $p['stock_status'] );
			}
			if ( isset( $p['backorders'] ) ) {
				$product->set_backorders( $p['backorders'] );
			}
		}

		// Veröffentlichungsstatus 1:1 übernehmen (publish | private | draft | pending).
		if ( ! empty( $p['status'] ) ) {
			$allowed = array( 'publish', 'private', 'draft', 'pending' );
			$status  = in_array( $p['status'], $allowed, true ) ? $p['status'] : 'publish';
			$product->set_status( $status );
		}

		$product->set_sku( sanitize_text_field( $p['sku'] ) );
	}

	/**
	 * Baut WC_Product_Attribute-Objekte aus der Payload (benutzerdefiniert).
	 *
	 * @param array $attrs Attribut-Liste.
	 * @return array
	 */
	protected static function build_attribute_objects( $attrs ) {
		$objects = array();
		foreach ( (array) $attrs as $a ) {
			if ( empty( $a['name'] ) || empty( $a['options'] ) ) {
				continue;
			}
			$obj = new WC_Product_Attribute();
			$obj->set_id( 0 ); // 0 = benutzerdefiniertes (produkteigenes) Attribut.
			$obj->set_name( sanitize_text_field( $a['name'] ) );
			$obj->set_options( array_map( 'sanitize_text_field', (array) $a['options'] ) );
			$obj->set_visible( ! empty( $a['visible'] ) );
			$obj->set_variation( ! empty( $a['variation'] ) );
			$objects[] = $obj;
		}
		return $objects;
	}

	/**
	 * Legt Variationen an bzw. aktualisiert sie (per SKU zugeordnet).
	 *
	 * @param int   $parent_id  Eltern-Produkt-ID.
	 * @param array $variations Variationsliste.
	 */
	/**
	 * Setzt die Steuerklasse mit optionalem Mapping und Slug-Validierung.
	 *
	 * Übersetzt den Quell-Slug per Zuordnung (z. B. "reduzierter-preis" =>
	 * "reduced-rate"). Existiert der (ggf. gemappte) Slug auf diesem Shop nicht,
	 * wird die Steuerklasse NICHT geändert – so fällt sie nicht fälschlich auf
	 * Standard zurück.
	 *
	 * @param WC_Product $product Produkt/Variation.
	 * @param string     $slug    Eingehender Steuerklassen-Slug.
	 */
	protected static function apply_tax_class( $product, $slug ) {
		$slug = (string) $slug;
		$map  = WCIS_Settings::tax_class_map();
		if ( isset( $map[ $slug ] ) ) {
			$slug = $map[ $slug ];
		}

		// '' = Standard ist immer gültig; andere Slugs müssen auf diesem Shop existieren.
		$valid = array_merge( array( '' ), WC_Tax::get_tax_class_slugs() );
		if ( in_array( $slug, $valid, true ) ) {
			$product->set_tax_class( $slug );
		} else {
			WCIS_Logger::error(
				sprintf( 'Steuerklasse „%s" existiert hier nicht (keine Zuordnung) – unverändert gelassen.', $slug ),
				'inbound'
			);
		}
	}

	/**
	 * Legt Variationen an bzw. aktualisiert sie (per SKU zugeordnet).
	 *
	 * @param int   $parent_id  Eltern-Produkt-ID.
	 * @param array $variations Variationsliste.
	 */
	protected static function apply_variations( $parent_id, $variations ) {
		foreach ( (array) $variations as $vp ) {
			$vsku = isset( $vp['sku'] ) ? sanitize_text_field( $vp['sku'] ) : '';
			$vid  = $vsku ? wc_get_product_id_by_sku( $vsku ) : 0;

			$variation = $vid ? wc_get_product( $vid ) : new WC_Product_Variation();
			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
			}
			$variation->set_parent_id( $parent_id );

			// Attribut-Auswahl: Name -> sanitize_title(Name) => Options-Label.
			$va = array();
			if ( ! empty( $vp['attributes'] ) && is_array( $vp['attributes'] ) ) {
				foreach ( $vp['attributes'] as $aname => $opt ) {
					$va[ sanitize_title( $aname ) ] = $opt;
				}
			}
			$variation->set_attributes( $va );

			if ( $vsku ) {
				$variation->set_sku( $vsku );
			}
			if ( isset( $vp['regular_price'] ) ) {
				$variation->set_regular_price( (string) $vp['regular_price'] );
			}
			if ( isset( $vp['sale_price'] ) ) {
				$variation->set_sale_price( (string) $vp['sale_price'] );
			}
			if ( isset( $vp['tax_status'] ) ) {
				$variation->set_tax_status( $vp['tax_status'] );
			}
			if ( isset( $vp['tax_class'] ) ) {
				self::apply_tax_class( $variation, $vp['tax_class'] );
			}
			if ( ! empty( $vp['manage_stock'] ) ) {
				$variation->set_manage_stock( true );
				if ( isset( $vp['stock_quantity'] ) && null !== $vp['stock_quantity'] ) {
					$variation->set_stock_quantity( wc_stock_amount( $vp['stock_quantity'] ) );
				}
			}
			if ( isset( $vp['stock_status'] ) ) {
				$variation->set_stock_status( $vp['stock_status'] );
			}
			if ( isset( $vp['weight'] ) ) {
				$variation->set_weight( $vp['weight'] );
			}
			if ( ! empty( $vp['status'] ) ) {
				$variation->set_status( 'private' === $vp['status'] ? 'private' : 'publish' );
			}

			$variation->save();
		}

		// Datenbank-Konsistenz des variablen Produkts sicherstellen.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $parent_id );
		}
		WC_Product_Variable::sync( $parent_id );
	}

	/**
	 * Legt Terme an (falls nötig) und liefert deren IDs.
	 *
	 * @param array  $names    Term-Namen.
	 * @param string $taxonomy Taxonomie.
	 * @return array
	 */
	protected static function term_ids( $names, $taxonomy ) {
		$ids = array();
		foreach ( $names as $name ) {
			$name = trim( wp_strip_all_tags( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$new = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $new ) ) {
				$ids[] = (int) $new['term_id'];
			}
		}
		return $ids;
	}

	/**
	 * Importiert Bilder per URL und setzt Beitragsbild + Galerie.
	 *
	 * @param int   $product_id Produkt-ID.
	 * @param array $urls       Bild-URLs.
	 */
	protected static function import_images( $product_id, $urls ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = array();
		foreach ( (array) $urls as $url ) {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				continue;
			}
			$att_id = media_sideload_image( $url, $product_id, null, 'id' );
			if ( ! is_wp_error( $att_id ) ) {
				$ids[] = (int) $att_id;
			}
		}

		if ( empty( $ids ) ) {
			return;
		}
		$featured = array_shift( $ids );
		set_post_thumbnail( $product_id, $featured );
		if ( ! empty( $ids ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $ids ) );
		}
	}

	// -------------------------------------------------------------------------
	// Massen-Übertragung (Fortschritts-Job)
	// -------------------------------------------------------------------------

	/**
	 * Startet die Massen-Übertragung aller lokalen Produkte an alle Shops.
	 *
	 * @return array|WP_Error
	 */
	public static function bulk_start() {
		$peers = WCIS_Settings::get_peers();
		if ( empty( $peers ) ) {
			return new WP_Error( 'wcis_no_targets', __( 'Keine Ziel-Shops konfiguriert.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( '' === WCIS_Settings::secret() ) {
			return new WP_Error( 'wcis_no_secret', __( 'Kein Netzwerk-Secret gesetzt.', 'blocksocial-woocommerce-sync' ) );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
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

		set_transient( self::IDS_TR, $ids, HOUR_IN_SECONDS );

		$job = array(
			'status'      => ! empty( $ids ) ? 'running' : 'done',
			'total'       => count( $ids ),
			'index'       => 0,
			'peers'       => array_values( wp_list_pluck( $peers, 'url' ) ),
			'created'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'started_at'  => time(),
			'updated_at'  => time(),
		);
		update_option( self::JOB_OPT, $job, false );

		if ( 'done' === $job['status'] ) {
			delete_transient( self::IDS_TR );
		}

		return $job;
	}

	/**
	 * Verarbeitet den nächsten Abschnitt der Massen-Übertragung.
	 *
	 * @return array|WP_Error
	 */
	public static function bulk_tick() {
		$job = get_option( self::JOB_OPT, null );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'wcis_no_job', __( 'Keine laufende Übertragung.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'running' !== $job['status'] ) {
			return $job;
		}

		$ids = get_transient( self::IDS_TR );
		if ( ! is_array( $ids ) ) {
			$job['status'] = 'done';
			update_option( self::JOB_OPT, $job, false );
			return $job;
		}

		$start = microtime( true );

		do {
			if ( $job['index'] >= $job['total'] ) {
				$job['status'] = 'done';
				break;
			}

			$pid     = isset( $ids[ $job['index'] ] ) ? $ids[ $job['index'] ] : 0;
			$product = $pid ? wc_get_product( $pid ) : null;

			if ( $product && ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) && WCIS_Filter::should_sync( $product ) ) {
				$payload = self::build_payload( $product );
				if ( $payload ) {
					foreach ( $job['peers'] as $peer_url ) {
						$res  = WCIS_Client::post( $peer_url, '/product', array( 'source' => WCIS_Settings::this_url(), 'product' => $payload ), true );
						$body = ( ! is_wp_error( $res ) && isset( $res['body'] ) ) ? json_decode( $res['body'], true ) : null;

						if ( is_wp_error( $res ) || $res['code'] < 200 || $res['code'] >= 300 ) {
							$job['failed']++;
							WCIS_Queue::add( $peer_url, array( 'source' => WCIS_Settings::this_url(), 'product' => $payload ), is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'], '/product' );
						} elseif ( is_array( $body ) && isset( $body['result'] ) ) {
							$key = $body['result'];
							if ( isset( $job[ $key ] ) ) {
								$job[ $key ]++;
							}
						}
					}
				}
			}

			$job['index']++;
			if ( $job['index'] >= $job['total'] ) {
				$job['status'] = 'done';
			}
		} while ( 'running' === $job['status'] && ( microtime( true ) - $start ) < self::TICK_BUDGET );

		$job['updated_at'] = time();
		update_option( self::JOB_OPT, $job, false );

		if ( 'done' === $job['status'] ) {
			delete_transient( self::IDS_TR );
			WCIS_Logger::info(
				sprintf( 'Produkt-Massenübertragung fertig: %d angelegt, %d aktualisiert, %d übersprungen, %d fehlgeschlagen.', $job['created'], $job['updated'], $job['skipped'], $job['failed'] ),
				'outbound'
			);
		}

		return $job;
	}

	/**
	 * Aktueller Job-Status.
	 *
	 * @return array|null
	 */
	public static function bulk_state() {
		$job = get_option( self::JOB_OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Bricht die Massen-Übertragung ab.
	 */
	public static function bulk_cancel() {
		$job = self::bulk_state();
		if ( is_array( $job ) && 'running' === $job['status'] ) {
			$job['status']     = 'cancelled';
			$job['updated_at'] = time();
			update_option( self::JOB_OPT, $job, false );
		}
		delete_transient( self::IDS_TR );
	}

	/**
	 * Fortschritt in Prozent.
	 *
	 * @param array|null $job Job.
	 * @return int
	 */
	public static function bulk_percent( $job ) {
		if ( ! is_array( $job ) || empty( $job['total'] ) ) {
			return 0;
		}
		if ( 'done' === $job['status'] ) {
			return 100;
		}
		return (int) min( 100, floor( 100 * $job['index'] / $job['total'] ) );
	}

	/**
	 * Job für die AJAX-Ausgabe aufbereiten.
	 *
	 * @param array|null $job Job.
	 * @return array
	 */
	public static function bulk_to_response( $job ) {
		if ( ! is_array( $job ) ) {
			return array( 'status' => 'idle', 'percent' => 0 );
		}
		return array(
			'status'  => $job['status'],
			'percent' => self::bulk_percent( $job ),
			'total'   => (int) $job['total'],
			'index'   => (int) $job['index'],
			'created' => (int) $job['created'],
			'updated' => (int) $job['updated'],
			'skipped' => (int) $job['skipped'],
			'failed'  => (int) $job['failed'],
		);
	}

	// -------------------------------------------------------------------------
	// Export (Quelle) + Pull (Empfänger holt Produkte vom Hauptshop)
	// -------------------------------------------------------------------------

	/**
	 * Liefert eine Seite an Produkt-Payloads dieses Shops (für Pull).
	 *
	 * @param int $page     Seite (1-basiert).
	 * @param int $per_page Produkte pro Seite.
	 * @return array { items, total, total_pages }.
	 */
	public static function export_page( $page, $per_page ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => (int) $per_page,
				'paged'          => (int) $page,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sku',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) ) {
				continue;
			}
			if ( ! WCIS_Filter::should_sync( $product ) ) {
				continue;
			}
			$payload = self::build_payload( $product );
			if ( $payload ) {
				$items[] = $payload;
			}
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Startet einen Pull: holt die Produkte des Hauptshops auf diesen Shop.
	 *
	 * @return array|WP_Error
	 */
	public static function pull_start() {
		$master = (string) WCIS_Settings::get( 'master_url' );
		if ( '' === $master || WCIS_Settings::normalize_url( $master ) === WCIS_Settings::normalize_url( WCIS_Settings::this_url() ) ) {
			return new WP_Error( 'wcis_no_master', __( 'Dieser Shop ist selbst der Hauptshop – nutze „Alle Produkte übertragen".', 'blocksocial-woocommerce-sync' ) );
		}
		if ( '' === WCIS_Settings::secret() ) {
			return new WP_Error( 'wcis_no_secret', __( 'Kein Netzwerk-Secret gesetzt.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( ! WCIS_Settings::get( 'product_sync_enabled', false ) ) {
			return new WP_Error( 'wcis_disabled', __( 'Produkt-Sync ist auf diesem Shop nicht aktiv – bitte zuerst einschalten.', 'blocksocial-woocommerce-sync' ) );
		}

		$per_page = 20;
		$res      = WCIS_Client::get( $master, '/products-export?page=1&per_page=' . $per_page );
		if ( is_wp_error( $res ) || $res['code'] < 200 || $res['code'] >= 300 ) {
			return new WP_Error( 'wcis_master_unreachable', __( 'Hauptshop nicht erreichbar oder Secret falsch.', 'blocksocial-woocommerce-sync' ) );
		}
		$data        = json_decode( $res['body'], true );
		$total       = ( is_array( $data ) && isset( $data['total'] ) ) ? (int) $data['total'] : 0;
		$total_pages = ( is_array( $data ) && isset( $data['total_pages'] ) ) ? (int) $data['total_pages'] : 1;

		$job = array(
			'status'      => $total > 0 ? 'running' : 'done',
			'master'      => untrailingslashit( $master ),
			'per_page'    => $per_page,
			'page'        => 1,
			'total_pages' => max( 1, $total_pages ),
			'total'       => $total,
			'created'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'last_error'  => '',
			'started_at'  => time(),
			'updated_at'  => time(),
		);
		update_option( self::PULL_JOB_OPT, $job, false );

		WCIS_Logger::info( sprintf( 'Produkt-Pull vom Hauptshop %s gestartet: %d Produkte.', $master, $total ), 'inbound' );

		return $job;
	}

	/**
	 * Verarbeitet den nächsten Abschnitt des Pull-Jobs.
	 *
	 * @return array|WP_Error
	 */
	public static function pull_tick() {
		$job = get_option( self::PULL_JOB_OPT, null );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'wcis_no_job', __( 'Kein laufender Pull.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'running' !== $job['status'] ) {
			return $job;
		}

		$start = microtime( true );

		do {
			if ( $job['page'] > $job['total_pages'] ) {
				$job['status'] = 'done';
				break;
			}

			$res = WCIS_Client::get( $job['master'], '/products-export?page=' . $job['page'] . '&per_page=' . $job['per_page'] );
			if ( is_wp_error( $res ) || $res['code'] < 200 || $res['code'] >= 300 ) {
				$job['status']     = 'error';
				$job['last_error'] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'];
				break;
			}

			$data  = json_decode( $res['body'], true );
			$items = ( is_array( $data ) && isset( $data['items'] ) ) ? $data['items'] : array();
			foreach ( $items as $payload ) {
				$result = self::apply_product( $payload );
				if ( isset( $job[ $result ] ) ) {
					$job[ $result ]++;
				}
			}

			$job['page']++;
			if ( $job['page'] > $job['total_pages'] ) {
				$job['status'] = 'done';
			}
		} while ( 'running' === $job['status'] && ( microtime( true ) - $start ) < self::TICK_BUDGET );

		$job['updated_at'] = time();
		update_option( self::PULL_JOB_OPT, $job, false );

		if ( 'done' === $job['status'] ) {
			WCIS_Logger::info(
				sprintf( 'Produkt-Pull fertig: %d angelegt, %d aktualisiert, %d übersprungen.', $job['created'], $job['updated'], $job['skipped'] ),
				'inbound'
			);
		}

		return $job;
	}

	/**
	 * Aktueller Pull-Job-Status.
	 *
	 * @return array|null
	 */
	public static function pull_state() {
		$job = get_option( self::PULL_JOB_OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Bricht den Pull ab.
	 */
	public static function pull_cancel() {
		$job = self::pull_state();
		if ( is_array( $job ) && 'running' === $job['status'] ) {
			$job['status']     = 'cancelled';
			$job['updated_at'] = time();
			update_option( self::PULL_JOB_OPT, $job, false );
		}
	}

	/**
	 * Pull-Fortschritt in Prozent.
	 *
	 * @param array|null $job Job.
	 * @return int
	 */
	public static function pull_percent( $job ) {
		if ( ! is_array( $job ) || empty( $job['total_pages'] ) ) {
			return 0;
		}
		if ( 'done' === $job['status'] ) {
			return 100;
		}
		return (int) min( 100, floor( 100 * ( $job['page'] - 1 ) / $job['total_pages'] ) );
	}

	/**
	 * Pull-Job für die AJAX-Ausgabe.
	 *
	 * @param array|null $job Job.
	 * @return array
	 */
	public static function pull_to_response( $job ) {
		if ( ! is_array( $job ) ) {
			return array( 'status' => 'idle', 'percent' => 0 );
		}
		return array(
			'status'  => $job['status'],
			'percent' => self::pull_percent( $job ),
			'total'   => (int) $job['total'],
			'created' => (int) $job['created'],
			'updated' => (int) $job['updated'],
			'skipped' => (int) $job['skipped'],
			'failed'  => (int) $job['failed'],
			'message' => isset( $job['last_error'] ) ? (string) $job['last_error'] : '',
		);
	}
}
