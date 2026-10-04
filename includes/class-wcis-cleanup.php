<?php
/**
 * Aufräumen: entfernt Produkte, die vom Hauptshop übertragen wurden, aber
 * NICHT im Sync-Umfang dieses Shops liegen (z. B. nach einem falsch
 * übertragenen Sortiment).
 *
 * Ablauf (beides mit Fortschrittsbalken):
 *  1. Analyse: holt vom Hauptshop eine schlanke Liste (SKUs, Kategorie-Pfade,
 *     Marken, Umfang) und prüft jedes lokale Produkt gegen den Sync-Filter
 *     dieses Shops. Nur Produkte, deren SKU es auch im Hauptshop gibt, kommen
 *     in Frage – eigene Produkte dieses Shops bleiben immer unangetastet.
 *  2. Entfernen: verschiebt die gefundenen Produkte in den Papierkorb
 *     (wiederherstellbar) oder löscht sie endgültig inkl. importierter Bilder.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aufräum-Job.
 */
class WCIS_Cleanup {

	/** Meta: Zeitpunkt, zu dem der Sync das Produkt angelegt hat. */
	const ORIGIN_META = '_wcis_origin';

	/** Option: Job-Status. */
	const JOB_OPT = 'wcis_cleanup_job';

	/** Option: SKU-Daten des Hauptshops (während der Analyse). */
	const MAP_OPT = 'wcis_cleanup_master';

	/** Option: Kandidaten (Produkt-IDs). */
	const IDS_OPT = 'wcis_cleanup_ids';

	/** Produkte je Seite beim Abruf vom Hauptshop. */
	const MASTER_PAGE = 200;

	/** Lokale Produkte je Prüf-Abschnitt. */
	const SCAN_CHUNK = 100;

	/** Anzahl Beispiel-Produkte im Ergebnis. */
	const SAMPLE = 50;

	// -------------------------------------------------------------------------
	// Hauptshop-Seite: schlanker Export für die Analyse
	// -------------------------------------------------------------------------

	/**
	 * Liefert eine Seite schlanker Produktdaten (für die Analyse der Empfänger).
	 *
	 * @param int $page     Seite (1-basiert).
	 * @param int $per_page Produkte je Seite.
	 * @return array { items, total_pages }
	 */
	public static function export_terms_page( $page, $per_page ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => (int) $per_page,
				'paged'          => (int) $page,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $query->posts as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) ) {
				continue;
			}
			$skus = self::product_skus( $product );
			if ( empty( $skus ) ) {
				continue;
			}
			$items[] = array(
				'skus'        => $skus,
				'cat_paths'   => WCIS_Product_Sync::category_paths( $pid ),
				'brand_names' => WCIS_Product_Sync::export_brands( $product ),
				'in_scope'    => WCIS_Filter::should_sync( $product ),
			);
		}

		return array(
			'items'       => $items,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * SKUs eines Produkts (Eltern-SKU und Variations-SKUs).
	 *
	 * @param WC_Product $product Produkt.
	 * @return string[]
	 */
	protected static function product_skus( $product ) {
		$skus = array();
		if ( '' !== (string) $product->get_sku() ) {
			$skus[] = (string) $product->get_sku();
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$vsku = (string) get_post_meta( $vid, '_sku', true );
				if ( '' !== $vsku ) {
					$skus[] = $vsku;
				}
			}
		}
		return array_values( array_unique( $skus ) );
	}

	// -------------------------------------------------------------------------
	// Empfänger-Seite: Analyse + Entfernen
	// -------------------------------------------------------------------------

	/**
	 * Steht das Aufräumen auf diesem Shop zur Verfügung (Neben-Shop mit Hauptshop)?
	 *
	 * @return bool
	 */
	public static function available() {
		if ( WCIS_Edition::is_partner() ) {
			return false;
		}
		$master = (string) WCIS_Settings::get( 'master_url' );
		return '' !== $master && WCIS_Settings::normalize_url( $master ) !== WCIS_Settings::normalize_url( WCIS_Settings::this_url() );
	}

	/**
	 * Startet die Analyse.
	 *
	 * @param array $args { origin: 'marker'|'since', since: 'YYYY-MM-DD' }.
	 * @return array|WP_Error
	 */
	public static function analyze_start( array $args ) {
		if ( ! self::available() ) {
			return new WP_Error( 'wcis_no_master', __( 'Aufräumen ist nur auf Neben-Shops mit eingetragenem Hauptshop möglich.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( ! WCIS_Settings::has_credentials() ) {
			return new WP_Error( 'wcis_no_secret', __( 'Kein Netzwerk-Secret gesetzt.', 'blocksocial-woocommerce-sync' ) );
		}
		$job = self::state();
		if ( $job && 'running' === $job['status'] ) {
			return new WP_Error( 'wcis_busy', __( 'Es läuft bereits ein Aufräum-Vorgang.', 'blocksocial-woocommerce-sync' ) );
		}

		$origin = ( isset( $args['origin'] ) && 'since' === $args['origin'] ) ? 'since' : 'marker';
		$since  = 0;
		if ( 'since' === $origin ) {
			$since = isset( $args['since'] ) ? strtotime( (string) $args['since'] . ' 00:00:00' ) : false;
			if ( ! $since ) {
				return new WP_Error( 'wcis_bad_date', __( 'Bitte ein gültiges Datum angeben.', 'blocksocial-woocommerce-sync' ) );
			}
		}

		// Erste Seite vom Hauptshop: prüft gleichzeitig Erreichbarkeit/Version.
		$first = self::fetch_master_page( 1 );
		if ( is_wp_error( $first ) ) {
			return $first;
		}

		update_option( self::MAP_OPT, array(), false );
		update_option( self::IDS_OPT, array(), false );
		self::merge_master_items( $first['items'] );

		$job = array(
			'status'       => 'running',
			'phase'        => 'fetch',
			'origin'       => $origin,
			'since'        => (int) $since,
			'mode'         => 'trash',
			'page'         => 2,
			'total_pages'  => max( 1, (int) $first['total_pages'] ),
			'local_ids'    => 0,
			'index'        => 0,
			'checked'      => 0,
			'own'          => 0,
			'candidates'   => 0,
			'sample'       => array(),
			'removed'      => 0,
			'failed'       => 0,
			'message'      => '',
			'started_at'   => time(),
			'updated_at'   => time(),
		);
		update_option( self::JOB_OPT, $job, false );
		return $job;
	}

	/**
	 * Startet das Entfernen der zuvor analysierten Produkte.
	 *
	 * @param string $mode 'trash' (Papierkorb) oder 'delete' (endgültig inkl. Bilder).
	 * @return array|WP_Error
	 */
	public static function remove_start( $mode ) {
		$job = self::state();
		if ( ! $job || 'analyzed' !== $job['status'] ) {
			return new WP_Error( 'wcis_no_analysis', __( 'Bitte zuerst „Analysieren" ausführen.', 'blocksocial-woocommerce-sync' ) );
		}
		$ids = get_option( self::IDS_OPT, array() );
		if ( empty( $ids ) ) {
			return new WP_Error( 'wcis_nothing', __( 'Keine Produkte zum Entfernen gefunden.', 'blocksocial-woocommerce-sync' ) );
		}
		$job['status']     = 'running';
		$job['phase']      = 'remove';
		$job['mode']       = ( 'delete' === $mode ) ? 'delete' : 'trash';
		$job['index']      = 0;
		$job['removed']    = 0;
		$job['failed']     = 0;
		$job['updated_at'] = time();
		update_option( self::JOB_OPT, $job, false );
		WCIS_Logger::info( sprintf( 'Aufräumen gestartet: %d Produkte werden %s.', count( $ids ), 'delete' === $job['mode'] ? 'endgültig gelöscht' : 'in den Papierkorb verschoben' ) );
		return $job;
	}

	/**
	 * Verarbeitet den nächsten Abschnitt (Analyse oder Entfernen).
	 *
	 * @return array|WP_Error
	 */
	public static function tick() {
		$job = self::state();
		if ( ! $job ) {
			return new WP_Error( 'wcis_no_job', __( 'Kein laufender Aufräum-Vorgang.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'running' !== $job['status'] ) {
			return $job;
		}

		$start = microtime( true );
		do {
			if ( 'fetch' === $job['phase'] ) {
				$job = self::tick_fetch( $job );
			} elseif ( 'scan' === $job['phase'] ) {
				$job = self::tick_scan( $job );
			} else {
				$job = self::tick_remove( $job );
			}
			$job['updated_at'] = time();
			update_option( self::JOB_OPT, $job, false );
		} while ( 'running' === $job['status'] && ( microtime( true ) - $start ) < WCIS_Product_Sync::TICK_BUDGET );

		return $job;
	}

	/**
	 * Analyse, Schritt 1: Daten des Hauptshops seitenweise holen.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	protected static function tick_fetch( array $job ) {
		if ( $job['page'] > $job['total_pages'] ) {
			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			update_option( self::IDS_OPT, array( 'scan' => array_map( 'intval', $ids ), 'found' => array() ), false );
			$job['phase']     = 'scan';
			$job['local_ids'] = count( $ids );
			$job['index']     = 0;
			return $job;
		}
		$res = self::fetch_master_page( $job['page'] );
		if ( is_wp_error( $res ) ) {
			$job['status']  = 'error';
			$job['message'] = $res->get_error_message();
			return $job;
		}
		self::merge_master_items( $res['items'] );
		$job['page']++;
		return $job;
	}

	/**
	 * Analyse, Schritt 2: lokale Produkte gegen den Sync-Filter prüfen.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	protected static function tick_scan( array $job ) {
		$state = get_option( self::IDS_OPT, array() );
		$scan  = isset( $state['scan'] ) ? (array) $state['scan'] : array();
		$found = isset( $state['found'] ) ? (array) $state['found'] : array();
		$map   = get_option( self::MAP_OPT, array() );

		$end = min( count( $scan ), $job['index'] + self::SCAN_CHUNK );
		for ( $i = $job['index']; $i < $end; $i++ ) {
			$product = wc_get_product( $scan[ $i ] );
			if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) ) {
				continue;
			}
			$job['checked']++;
			$verdict = self::evaluate( $product, $map, $job );
			if ( 'own' === $verdict ) {
				$job['own']++;
			} elseif ( 'remove' === $verdict ) {
				$found[] = (int) $product->get_id();
				if ( count( $job['sample'] ) < self::SAMPLE ) {
					$cats            = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
					$job['sample'][] = array(
						'name' => $product->get_name(),
						'sku'  => $product->get_sku(),
						'cats' => is_wp_error( $cats ) ? '' : html_entity_decode( implode( ', ', $cats ), ENT_QUOTES, 'UTF-8' ),
					);
				}
			}
		}
		$job['index'] = $end;

		if ( $job['index'] >= count( $scan ) ) {
			update_option( self::IDS_OPT, $found, false );
			delete_option( self::MAP_OPT );
			$job['status']     = 'analyzed';
			$job['candidates'] = count( $found );
			WCIS_Logger::info( sprintf( 'Aufräumen – Analyse: %d Produkte geprüft, %d außerhalb des Sync-Umfangs gefunden.', $job['checked'], $job['candidates'] ) );
		} else {
			$state['found'] = $found;
			update_option( self::IDS_OPT, $state, false );
		}
		return $job;
	}

	/**
	 * Bewertet ein lokales Produkt.
	 *
	 * @param WC_Product $product Produkt.
	 * @param array      $map     SKU => Index der Hauptshop-Daten (+ 'items').
	 * @param array      $job     Job (Herkunfts-Kriterium).
	 * @return string 'own' (nicht im Hauptshop) | 'keep' | 'remove'
	 */
	protected static function evaluate( $product, array $map, array $job ) {
		$entry = null;
		foreach ( self::product_skus( $product ) as $sku ) {
			if ( isset( $map['skus'][ $sku ] ) ) {
				$entry = $map['items'][ $map['skus'][ $sku ] ];
				break;
			}
		}
		if ( null === $entry ) {
			return 'own'; // Eigenes Produkt dieses Shops – nie anfassen.
		}

		// Herkunft: nur vom Sync angelegte Produkte bzw. ab Stichtag angelegte.
		if ( 'since' === $job['origin'] ) {
			$created = $product->get_date_created();
			if ( ! $created || $created->getTimestamp() < (int) $job['since'] ) {
				return 'keep';
			}
		} elseif ( ! $product->get_meta( self::ORIGIN_META ) ) {
			return 'keep';
		}

		// Der Hauptshop würde das Produkt nicht (mehr) senden …
		if ( empty( $entry['in_scope'] ) ) {
			return 'remove';
		}
		// … oder dieser Shop würde es nicht annehmen.
		$payload = array(
			'sku'         => $product->get_sku(),
			'cat_paths'   => isset( $entry['cat_paths'] ) ? $entry['cat_paths'] : array(),
			'brand_names' => isset( $entry['brand_names'] ) ? $entry['brand_names'] : array(),
		);
		return WCIS_Filter::accepts_incoming( $payload, (int) $product->get_id() ) ? 'keep' : 'remove';
	}

	/**
	 * Entfernen: verarbeitet die nächsten Produkte.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	protected static function tick_remove( array $job ) {
		$ids   = (array) get_option( self::IDS_OPT, array() );
		$total = count( $ids );
		$end   = min( $total, $job['index'] + 10 );

		for ( $i = $job['index']; $i < $end; $i++ ) {
			try {
				if ( self::remove_product( (int) $ids[ $i ], 'delete' === $job['mode'] ) ) {
					$job['removed']++;
				} else {
					$job['failed']++;
				}
			} catch ( \Throwable $e ) {
				$job['failed']++;
				WCIS_Logger::error( sprintf( 'Aufräumen: Produkt-ID %d nicht entfernt: %s', (int) $ids[ $i ], $e->getMessage() ) );
			}
		}
		$job['index'] = $end;

		if ( $job['index'] >= $total ) {
			$job['status'] = 'done';
			delete_option( self::IDS_OPT );
			WCIS_Logger::info( sprintf( 'Aufräumen fertig: %d Produkte %s, %d fehlgeschlagen.', $job['removed'], 'delete' === $job['mode'] ? 'endgültig gelöscht' : 'in den Papierkorb verschoben', $job['failed'] ) );
		}
		return $job;
	}

	/**
	 * Entfernt ein Produkt (Papierkorb oder endgültig inkl. importierter Bilder).
	 *
	 * @param int  $product_id Produkt-ID.
	 * @param bool $force      Endgültig löschen?
	 * @return bool
	 */
	protected static function remove_product( $product_id, $force ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		// Nur Bilder, die der Sync für genau dieses Produkt importiert hat (Eltern-
		// Beitrag = Produkt). Vor dem Löschen ermitteln – WordPress hängt die
		// Anhänge beim Löschen des Produkts um.
		$images = array();
		if ( $force ) {
			foreach ( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) as $att_id ) {
				if ( $att_id && (int) wp_get_post_parent_id( $att_id ) === (int) $product_id ) {
					$images[] = $att_id;
				}
			}
		}

		WCIS_Product_Sync::set_suppress( true );
		WCIS_Sync_Engine::set_suppress( true );
		try {
			$ok = $product->delete( $force );
		} finally {
			WCIS_Product_Sync::set_suppress( false );
			WCIS_Sync_Engine::set_suppress( false );
		}

		// … und nur, wenn kein anderes Produkt sie verwendet.
		if ( $ok ) {
			foreach ( array_unique( $images ) as $att_id ) {
				if ( ! self::image_in_use( $att_id ) ) {
					wp_delete_attachment( $att_id, true );
				}
			}
		}

		return (bool) $ok;
	}

	/**
	 * Wird ein Bild noch von einem anderen Produkt verwendet?
	 *
	 * @param int $att_id Anhang-ID.
	 * @return bool
	 */
	protected static function image_in_use( $att_id ) {
		global $wpdb;
		$att_id = (int) $att_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$used = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE ( meta_key = '_thumbnail_id' AND meta_value = %s ) OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %s, meta_value ) )",
				(string) $att_id,
				(string) $att_id
			)
		);
		return (int) $used > 0;
	}

	/**
	 * Holt eine Seite vom Hauptshop.
	 *
	 * @param int $page Seite.
	 * @return array|WP_Error
	 */
	protected static function fetch_master_page( $page ) {
		$master = (string) WCIS_Settings::get( 'master_url' );
		$res    = WCIS_Client::get( $master, '/products-terms?page=' . (int) $page . '&per_page=' . self::MASTER_PAGE );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'wcis_master_unreachable', sprintf( __( 'Hauptshop nicht erreichbar: %s', 'blocksocial-woocommerce-sync' ), $res->get_error_message() ) );
		}
		if ( 404 === (int) $res['code'] ) {
			return new WP_Error( 'wcis_master_old', __( 'Der Hauptshop unterstützt das Aufräumen noch nicht – bitte dort zuerst das Plugin auf Version 3.1.1 oder neuer aktualisieren.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			return new WP_Error( 'wcis_master_error', sprintf( __( 'Hauptshop antwortet mit HTTP %d.', 'blocksocial-woocommerce-sync' ), (int) $res['code'] ) );
		}
		$data = json_decode( $res['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['items'] ) ) {
			return new WP_Error( 'wcis_master_error', __( 'Ungültige Antwort des Hauptshops.', 'blocksocial-woocommerce-sync' ) );
		}
		return array(
			'items'       => (array) $data['items'],
			'total_pages' => isset( $data['total_pages'] ) ? (int) $data['total_pages'] : 1,
		);
	}

	/**
	 * Übernimmt Hauptshop-Daten in die Analyse-Zuordnung.
	 *
	 * @param array $items Einträge.
	 */
	protected static function merge_master_items( array $items ) {
		$map = get_option( self::MAP_OPT, array() );
		if ( ! isset( $map['items'] ) ) {
			$map = array( 'items' => array(), 'skus' => array() );
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['skus'] ) ) {
				continue;
			}
			$idx            = count( $map['items'] );
			$map['items'][] = array(
				'cat_paths'   => isset( $item['cat_paths'] ) ? (array) $item['cat_paths'] : array(),
				'brand_names' => isset( $item['brand_names'] ) ? (array) $item['brand_names'] : array(),
				'in_scope'    => ! empty( $item['in_scope'] ),
			);
			foreach ( (array) $item['skus'] as $sku ) {
				$map['skus'][ (string) $sku ] = $idx;
			}
		}
		update_option( self::MAP_OPT, $map, false );
	}

	/**
	 * Aktueller Job.
	 *
	 * @return array|null
	 */
	public static function state() {
		$job = get_option( self::JOB_OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Bricht ab.
	 */
	public static function cancel() {
		$job = self::state();
		if ( $job && 'running' === $job['status'] ) {
			$job['status']     = 'cancelled';
			$job['updated_at'] = time();
			update_option( self::JOB_OPT, $job, false );
		}
		delete_option( self::MAP_OPT );
	}

	/**
	 * Fortschritt in Prozent.
	 *
	 * @param array|null $job Job.
	 * @return int
	 */
	public static function percent( $job ) {
		if ( ! is_array( $job ) ) {
			return 0;
		}
		if ( in_array( $job['status'], array( 'done', 'analyzed' ), true ) ) {
			return 100;
		}
		if ( 'fetch' === $job['phase'] ) {
			// Abruf vom Hauptshop: erste Hälfte des Balkens.
			return (int) min( 50, floor( 50 * ( $job['page'] - 1 ) / max( 1, $job['total_pages'] ) ) );
		}
		if ( 'scan' === $job['phase'] ) {
			return (int) min( 99, 50 + floor( 50 * $job['index'] / max( 1, $job['local_ids'] ) ) );
		}
		return (int) min( 99, floor( 100 * $job['index'] / max( 1, $job['candidates'] ) ) );
	}

	/**
	 * Job für die AJAX-Ausgabe.
	 *
	 * @param array|null $job Job.
	 * @return array
	 */
	public static function to_response( $job ) {
		if ( ! is_array( $job ) ) {
			return array( 'status' => 'idle', 'percent' => 0 );
		}
		return array(
			'status'     => $job['status'],
			'phase'      => $job['phase'],
			'percent'    => self::percent( $job ),
			'checked'    => (int) $job['checked'],
			'own'        => (int) $job['own'],
			'candidates' => (int) $job['candidates'],
			'sample'     => (array) $job['sample'],
			'mode'       => $job['mode'],
			'index'      => (int) $job['index'],
			'removed'    => (int) $job['removed'],
			'failed'     => (int) $job['failed'],
			'message'    => (string) $job['message'],
		);
	}
}
