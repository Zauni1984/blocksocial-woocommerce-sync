<?php
/**
 * CSV-Produktfeeds für Shops ohne Plugin (Admin-Edition).
 *
 * Jeder Feed besitzt eine eigene, geheime Abruf-URL (Token) und eigene
 * Einstellungen (Sortiment, brutto/netto, Preisregeln, Trennzeichen …).
 *
 * Keine Datei, die ständig neu geschrieben wird: Jede Produktzeile liegt
 * einzeln im Zeilen-Cache (Tabelle wcis_feed_rows). Ändert sich Bestand,
 * Preis oder ein Produkt, wird NUR dieses Produkt als geändert markiert und
 * seine Zeile(n) einzeln neu berechnet. Beim Abruf wird die CSV direkt aus
 * den Zeilen ausgeliefert. Abrufer erhalten damit immer den aktuellen Stand;
 * „If-None-Match"/ETag und „If-Modified-Since" werden unterstützt (HTTP 304).
 *
 * Abruf: https://shop.de/?blocksocial_feed=<id>&key=<token>
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV-Feeds.
 */
class WCIS_Feeds {

	/**
	 * Option mit den Feeds (enthält Tokens – nicht autoloaden).
	 */
	const OPT = 'wcis_feeds';

	/**
	 * Cron-Hook für die Hintergrund-Aktualisierung.
	 */
	const CRON = 'wcis_feed_refresh';

	/**
	 * Zwischenspeicher.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * In diesem Request bereits markierte Produkte.
	 *
	 * @var array
	 */
	protected static $marked = array();

	/**
	 * Zeitbudget für Aktualisierungen während eines Abrufs (Sekunden).
	 */
	const SERVE_BUDGET = 20.0;

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		// Abruf über die Shop-URL (ohne REST-JSON-Hülle, damit reine CSV ausgeliefert wird).
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 20 );
		add_action( self::CRON, array( __CLASS__, 'cron_refresh' ) );
		add_action( 'wcis_feed_build', array( __CLASS__, 'cron_build' ) );

		// Jede Bestands-/Produktänderung markiert NUR das betroffene Produkt.
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_new_product', 'woocommerce_new_product_variation', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'mark_dirty' ), 99 );
		}
		foreach ( array( 'trashed_post', 'untrashed_post', 'before_delete_post', 'transition_post_status' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'mark_dirty_post' ), 99, 'transition_post_status' === $hook ? 3 : 1 );
		}
	}

	// -------------------------------------------------------------------------
	// Registry
	// -------------------------------------------------------------------------

	/**
	 * Standardwerte eines Feeds.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'             => '',
			'name'           => '',
			'token'          => '',
			'active'         => true,
			'scope'          => 'all',
			'categories'     => array(),
			'prices'         => 'gross', // gross | net
			'price_rules'    => array( 'global' => 0, 'categories' => array(), 'rounding' => 'none' ),
			'delimiter'      => ';',
			'bom'            => true,    // UTF-8-BOM (Excel erkennt Umlaute).
			'only_instock'   => false,
			'parents'        => true,    // Eltern-Zeilen variabler Produkte.
			'descriptions'   => true,    // Beschreibungstexte mitliefern.
			'sale_prices'    => true,    // Angebotspreise mitliefern.
			'built_at'       => 0,
			'last_access'    => 0,
			'access_count'   => 0,
			'last_error'     => '',
		);
	}

	/**
	 * Alle Feeds (ID => Feed).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$list = get_option( self::OPT, array() );
			$list = is_array( $list ) ? $list : array();
			foreach ( $list as $id => $f ) {
				$list[ $id ] = wp_parse_args( is_array( $f ) ? $f : array(), self::defaults() );
			}
			self::$cache = $list;
		}
		return self::$cache;
	}

	/**
	 * Liste frisch aus der Datenbank (vor Schreibvorgängen).
	 *
	 * @return array
	 */
	protected static function fresh() {
		self::$cache = null;
		wp_cache_delete( self::OPT, 'options' );
		return self::all();
	}

	/**
	 * Speichert die Liste.
	 *
	 * @param array $list Feeds.
	 */
	protected static function save_all( array $list ) {
		self::$cache = $list;
		if ( false === get_option( self::OPT, false ) ) {
			add_option( self::OPT, $list, '', false );
		} else {
			update_option( self::OPT, $list, false );
		}
		self::ensure_cron();
	}

	/**
	 * Feed per ID.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Legt einen Feed an oder aktualisiert ihn (Formulardaten).
	 *
	 * @param array  $data Daten.
	 * @param string $id   Vorhandene ID oder '' (neu).
	 * @return array Feed.
	 */
	public static function save_feed( array $data, $id = '' ) {
		$all    = self::fresh();
		$is_new = ( '' === $id || ! isset( $all[ $id ] ) );
		$f      = $is_new ? self::defaults() : $all[ $id ];

		$f['name']         = isset( $data['name'] ) && '' !== trim( $data['name'] ) ? sanitize_text_field( $data['name'] ) : ( $f['name'] ? $f['name'] : __( 'CSV-Feed', 'blocksocial-woocommerce-sync' ) );
		$f['active']       = ! empty( $data['active'] );
		$f['scope']        = ( isset( $data['scope'] ) && 'categories' === $data['scope'] ) ? 'categories' : 'all';
		$f['categories']   = isset( $data['categories'] ) ? array_values( array_filter( array_map( 'intval', (array) $data['categories'] ) ) ) : array();
		$f['prices']       = ( isset( $data['prices'] ) && 'net' === $data['prices'] ) ? 'net' : 'gross';
		$f['delimiter']    = ( isset( $data['delimiter'] ) && in_array( $data['delimiter'], array( ';', ',', 'tab' ), true ) ) ? $data['delimiter'] : ';';
		$f['bom']          = ! empty( $data['bom'] );
		$f['only_instock'] = ! empty( $data['only_instock'] );
		$f['parents']      = ! empty( $data['parents'] );
		$f['descriptions'] = ! empty( $data['descriptions'] );
		$f['sale_prices']  = ! empty( $data['sale_prices'] );
		if ( isset( $data['price_rules'] ) ) {
			$f['price_rules'] = WCIS_Pricing::sanitize_rules( $data['price_rules'] );
		}
		if ( $is_new ) {
			$f['id']    = 'csv_' . strtolower( wp_generate_password( 8, false, false ) );
			$f['token'] = wp_generate_password( 40, false, false );
		}
		$f['built_at']   = 0; // Einstellungen geändert → alle Zeilen neu aufbauen.
		$all[ $f['id'] ] = $f;
		self::save_all( $all );
		self::reset_rows( $f['id'] );
		self::schedule_build();
		return $f;
	}

	/**
	 * Aktualisiert einzelne Felder (intern).
	 *
	 * @param string $id     ID.
	 * @param array  $fields Felder.
	 */
	public static function update( $id, array $fields ) {
		$all = self::fresh();
		if ( isset( $all[ $id ] ) ) {
			$all[ $id ] = array_merge( $all[ $id ], $fields );
			self::save_all( $all );
		}
	}

	/**
	 * Neuer Token (alte URL wird ungültig).
	 *
	 * @param string $id ID.
	 */
	public static function rotate( $id ) {
		if ( self::get( $id ) ) {
			self::update( $id, array( 'token' => wp_generate_password( 40, false, false ) ) );
		}
	}

	/**
	 * Löscht einen Feed (inkl. Datei).
	 *
	 * @param string $id ID.
	 */
	public static function delete( $id ) {
		$all = self::fresh();
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			self::save_all( $all );
			self::delete_rows( $id );
		}
	}

	/**
	 * Abruf-URL eines Feeds.
	 *
	 * @param array $f Feed.
	 * @return string
	 */
	public static function url( array $f ) {
		return add_query_arg(
			array(
				'blocksocial_feed' => $f['id'],
				'key'              => $f['token'],
			),
			home_url( '/' )
		);
	}

	/**
	 * Minuten-Cron nur einplanen, solange es aktive Feeds gibt.
	 */
	protected static function ensure_cron() {
		$active = array_filter(
			(array) self::$cache,
			static function ( $f ) {
				return ! empty( $f['active'] );
			}
		);
		$next = wp_next_scheduled( self::CRON );
		if ( $active && ! $next ) {
			wp_schedule_event( time() + 60, 'wcis_every_minute', self::CRON );
		} elseif ( ! $active && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	// -------------------------------------------------------------------------
	// Zeilen-Cache
	// -------------------------------------------------------------------------

	/**
	 * Tabellenname des Zeilen-Caches.
	 *
	 * @return string
	 */
	protected static function table() {
		return WCIS_Install::table( WCIS_Install::FEED_TABLE );
	}

	/**
	 * Monotoner Zeitstempel in Millisekunden (Markierung/Revision).
	 *
	 * @return int
	 */
	protected static function now_ms() {
		return (int) floor( microtime( true ) * 1000 );
	}

	/**
	 * Markiert die Produkte als geändert (für alle aktiven, aufgebauten Feeds).
	 *
	 * @param int[] $product_ids Produkt-IDs (Eltern-Produkte).
	 */
	protected static function mark_products( array $product_ids ) {
		global $wpdb;
		$feeds = array_filter(
			self::all(),
			static function ( $f ) {
				return ! empty( $f['active'] );
			}
		);
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		if ( empty( $feeds ) || empty( $ids ) ) {
			return;
		}
		$now    = self::now_ms();
		$values = array();
		$args   = array();
		foreach ( array_keys( $feeds ) as $fid ) {
			foreach ( $ids as $pid ) {
				$values[] = '(%s, %d, %d, 0, %s)';
				array_push( $args, $fid, $pid, $now, '' );
			}
		}
		$table = self::table();
		// Atomar je Zeile: neue Produkte werden angelegt, bestehende nur markiert.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (feed_id, product_id, dirty, rev, rows_csv) VALUES " . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE dirty = VALUES(dirty)', $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Hook: Produkt/Variation geändert (Bestand, Status, Daten).
	 *
	 * @param mixed $arg Produkt-Objekt oder ID.
	 */
	public static function mark_dirty( $arg ) {
		if ( empty( self::all() ) ) {
			return;
		}
		$product = $arg instanceof WC_Product ? $arg : wc_get_product( (int) $arg );
		if ( ! $product ) {
			return;
		}
		$pid = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( isset( self::$marked[ $pid ] ) ) {
			return;
		}
		self::$marked[ $pid ] = true;
		self::mark_products( array( $pid ) );
	}

	/**
	 * Hook: Produkt in den Papierkorb/gelöscht/Status geändert.
	 *
	 * @param mixed $a Post-ID bzw. neuer Status (transition_post_status).
	 * @param mixed $b Alter Status.
	 * @param mixed $c Post (transition_post_status).
	 */
	public static function mark_dirty_post( $a, $b = null, $c = null ) {
		if ( empty( self::all() ) ) {
			return;
		}
		$post = $c instanceof WP_Post ? $c : get_post( (int) $a );
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		if ( $c instanceof WP_Post && $a === $b ) {
			return; // Status unverändert.
		}
		$pid = 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
		if ( $pid && ! isset( self::$marked[ $pid ] ) ) {
			self::$marked[ $pid ] = true;
			self::mark_products( array( $pid ) );
		}
	}

	/**
	 * Entfernt alle Zeilen eines Feeds und legt sie für den Neuaufbau an.
	 *
	 * @param string $id Feed-ID.
	 */
	public static function reset_rows( $id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE feed_id = %s", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$now = self::now_ms();
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $pid ) {
				$values[] = '(%s, %d, %d, 0, %s)';
				array_push( $args, $id, (int) $pid, $now, '' );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (feed_id, product_id, dirty, rev, rows_csv) VALUES " . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE dirty = VALUES(dirty)', $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		}
		self::update( $id, array( 'built_at' => 0 ) );
	}

	/**
	 * Löscht alle Zeilen eines Feeds.
	 *
	 * @param string $id Feed-ID.
	 */
	protected static function delete_rows( $id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE feed_id = %s", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Anzahl noch nicht aktualisierter Produkte eines Feeds.
	 *
	 * @param string $id Feed-ID.
	 * @return int
	 */
	public static function pending( $id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE feed_id = %s AND dirty > 0", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Anzahl der Produkte im Feed.
	 *
	 * @param string $id Feed-ID.
	 * @return int
	 */
	public static function product_count( $id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE feed_id = %s AND rows_csv <> ''", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Berechnet die Zeilen aller geänderten Produkte eines Feeds neu – und nur
	 * diese. Eine Markierung, die während der Berechnung neu gesetzt wird, bleibt
	 * erhalten (Bedingung „dirty = gelesener Wert").
	 *
	 * @param array $f      Feed.
	 * @param float $budget Zeitbudget in Sekunden.
	 * @return int Anzahl der noch offenen Produkte.
	 */
	public static function refresh( array $f, $budget = 20.0 ) {
		global $wpdb;
		$lock = 'wcis_feedlock_' . $f['id'];
		if ( ! WCIS_Install::claim( $lock ) ) {
			$since = (int) WCIS_Install::claimed_value( $lock );
			if ( $since && time() - $since > 300 ) {
				WCIS_Install::release( $lock ); // verwaiste Sperre.
			}
			return self::pending( $f['id'] );
		}
		$table = self::table();
		$start = microtime( true );
		try {
			do {
				$batch = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, dirty, rows_csv FROM {$table} WHERE feed_id = %s AND dirty > 0 ORDER BY product_id LIMIT 50", $f['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $batch as $r ) {
					$csv = self::render_product( $f, (int) $r['product_id'] );
					if ( '' === $csv ) {
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE feed_id = %s AND product_id = %d AND dirty = %d", $f['id'], $r['product_id'], $r['dirty'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					} else {
						$rev = ( $csv === (string) $r['rows_csv'] ) ? null : self::now_ms();
						if ( null === $rev ) {
							$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET dirty = 0 WHERE feed_id = %s AND product_id = %d AND dirty = %d", $f['id'], $r['product_id'], $r['dirty'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						} else {
							$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET rows_csv = %s, rev = %d, dirty = 0 WHERE feed_id = %s AND product_id = %d AND dirty = %d", $csv, $rev, $f['id'], $r['product_id'], $r['dirty'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						}
					}
				}
				if ( function_exists( 'wp_cache_flush_runtime' ) && count( (array) $batch ) >= 50 ) {
					wp_cache_flush_runtime(); // Speicher bei großen Katalogen begrenzen.
				}
			} while ( ! empty( $batch ) && ( microtime( true ) - $start ) < $budget );

			$left = self::pending( $f['id'] );
			if ( 0 === $left && empty( $f['built_at'] ) ) {
				self::update( $f['id'], array( 'built_at' => time(), 'last_error' => '' ) );
			}
			return $left;
		} catch ( \Throwable $e ) {
			self::update( $f['id'], array( 'last_error' => $e->getMessage() ) );
			WCIS_Logger::error( sprintf( 'CSV-Feed „%s": %s', $f['name'], $e->getMessage() ), 'local' );
			return self::pending( $f['id'] );
		} finally {
			WCIS_Install::release( $lock );
		}
	}

	/**
	 * CSV-Text (eine oder mehrere Zeilen) eines Produkts; '' = nicht im Feed.
	 *
	 * @param array $f   Feed.
	 * @param int   $pid Produkt-ID.
	 * @return string
	 */
	protected static function render_product( array $f, $pid ) {
		$product = wc_get_product( $pid );
		if ( ! $product || 'publish' !== $product->get_status() || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) || ! self::allows( $f, $product ) ) {
			return '';
		}
		$rows = self::rows_for( $f, $product );
		if ( empty( $rows ) ) {
			return '';
		}
		$fh    = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$delim = 'tab' === $f['delimiter'] ? "\t" : $f['delimiter'];
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row, $delim, '"', '' );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $csv;
	}

	/**
	 * Minuten-Cron: geänderte Produkte aller aktiven Feeds nachziehen.
	 */
	public static function cron_refresh() {
		foreach ( self::all() as $f ) {
			if ( ! empty( $f['active'] ) ) {
				self::refresh( $f, 40.0 );
			}
		}
	}

	/**
	 * Aufbau im Hintergrund sofort anstoßen (nach Anlegen/Ändern eines Feeds).
	 */
	protected static function schedule_build() {
		if ( ! wp_next_scheduled( 'wcis_feed_build' ) ) {
			wp_schedule_single_event( time(), 'wcis_feed_build' );
		}
	}

	/**
	 * Hintergrund-Aufbau (plant sich neu, bis alles aufgebaut ist).
	 */
	public static function cron_build() {
		$left = 0;
		foreach ( self::all() as $f ) {
			if ( ! empty( $f['active'] ) ) {
				$left += self::refresh( $f, 40.0 );
			}
		}
		if ( $left > 0 ) {
			wp_schedule_single_event( time() + 5, 'wcis_feed_build' );
		}
	}

	/**
	 * Kennwerte für ETag/Last-Modified.
	 *
	 * @param array $f Feed.
	 * @return array { etag, mtime }
	 */
	protected static function fingerprint( array $f ) {
		global $wpdb;
		$table = self::table();
		$r     = $wpdb->get_row( $wpdb->prepare( "SELECT MAX(rev) AS rev, COUNT(*) AS cnt FROM {$table} WHERE feed_id = %s AND rows_csv <> ''", $f['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rev   = isset( $r['rev'] ) ? (int) $r['rev'] : 0;
		$cnt   = isset( $r['cnt'] ) ? (int) $r['cnt'] : 0;
		$sig   = md5( wp_json_encode( array( $f['delimiter'], $f['bom'], $f['built_at'] ) ) );
		return array(
			'etag'  => '"' . md5( $f['id'] . '|' . $sig . '|' . $rev . '|' . $cnt ) . '"',
			'mtime' => $rev ? (int) floor( $rev / 1000 ) : time(),
		);
	}

	/**
	 * Gibt die CSV aus (Kopfzeile + Zeilen-Cache, seitenweise aus der Datenbank).
	 *
	 * @param array $f Feed.
	 */
	protected static function stream( array $f ) {
		global $wpdb;
		$table = self::table();
		$delim = 'tab' === $f['delimiter'] ? "\t" : $f['delimiter'];
		if ( ! empty( $f['bom'] ) ) {
			echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array_keys( self::columns() ), $delim, '"', '' );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$last = 0;
		do {
			$batch = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, rows_csv FROM {$table} WHERE feed_id = %s AND product_id > %d AND rows_csv <> '' ORDER BY product_id LIMIT 500", $f['id'], $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $batch as $r ) {
				echo $r['rows_csv']; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV-Daten (kein HTML).
				$last = (int) $r['product_id'];
			}
		} while ( ! empty( $batch ) );
	}

	/**
	 * Spalten der CSV (Schlüssel => Beschreibung für die Oberfläche).
	 *
	 * @return array
	 */
	public static function columns() {
		return array(
			'sku'               => __( 'Artikelnummer (SKU)', 'blocksocial-woocommerce-sync' ),
			'parent_sku'        => __( 'SKU des Eltern-Produkts (bei Varianten)', 'blocksocial-woocommerce-sync' ),
			'type'              => __( 'simple | variable | variation', 'blocksocial-woocommerce-sync' ),
			'name'              => __( 'Produktname', 'blocksocial-woocommerce-sync' ),
			'variant'           => __( 'Variante, z. B. „Größe: M"', 'blocksocial-woocommerce-sync' ),
			'categories'        => __( 'Kategorien (Pfad mit „>", mehrere mit „|")', 'blocksocial-woocommerce-sync' ),
			'brand'             => __( 'Marke', 'blocksocial-woocommerce-sync' ),
			'manufacturer'      => __( 'Hersteller', 'blocksocial-woocommerce-sync' ),
			'gtin'              => __( 'EAN/GTIN', 'blocksocial-woocommerce-sync' ),
			'regular_price'     => __( 'Regulärer Preis', 'blocksocial-woocommerce-sync' ),
			'sale_price'        => __( 'Angebotspreis (leer = kein Angebot)', 'blocksocial-woocommerce-sync' ),
			'currency'          => __( 'Währung', 'blocksocial-woocommerce-sync' ),
			'price_type'        => __( 'gross | net', 'blocksocial-woocommerce-sync' ),
			'tax_rate'          => __( 'Steuersatz in %', 'blocksocial-woocommerce-sync' ),
			'unit_price'        => __( 'Grundpreis (Germanized)', 'blocksocial-woocommerce-sync' ),
			'unit'              => __( 'Grundpreis-Einheit, z. B. „1 l"', 'blocksocial-woocommerce-sync' ),
			'stock_quantity'    => __( 'Bestand (leer = ohne Bestandsführung)', 'blocksocial-woocommerce-sync' ),
			'stock_status'      => __( 'instock | outofstock | onbackorder', 'blocksocial-woocommerce-sync' ),
			'backorders'        => __( 'no | notify | yes', 'blocksocial-woocommerce-sync' ),
			'delivery_time'     => __( 'Lieferzeit', 'blocksocial-woocommerce-sync' ),
			'weight'            => __( 'Gewicht', 'blocksocial-woocommerce-sync' ),
			'image_url'         => __( 'Hauptbild-URL', 'blocksocial-woocommerce-sync' ),
			'gallery_urls'      => __( 'Weitere Bilder (mit „|" getrennt)', 'blocksocial-woocommerce-sync' ),
			'product_url'       => __( 'Produkt-URL', 'blocksocial-woocommerce-sync' ),
			'short_description' => __( 'Kurzbeschreibung (HTML)', 'blocksocial-woocommerce-sync' ),
			'description'       => __( 'Beschreibung (HTML)', 'blocksocial-woocommerce-sync' ),
			'updated_at'        => __( 'Zuletzt geändert (ISO 8601)', 'blocksocial-woocommerce-sync' ),
		);
	}

	/**
	 * Gehört ein Produkt zum Feed?
	 *
	 * @param array      $f       Feed.
	 * @param WC_Product $product Produkt.
	 * @return bool
	 */
	protected static function allows( array $f, $product ) {
		if ( WCIS_Filter::is_excluded( $product ) ) {
			return false;
		}
		return WCIS_Partners::allows_product(
			array(
				'scope'      => $f['scope'],
				'categories' => $f['categories'],
			),
			$product
		);
	}

	/**
	 * CSV-Zeilen eines Produkts (Eltern-Zeile + Variationen bzw. eine Zeile).
	 *
	 * @param array      $f       Feed.
	 * @param WC_Product $product Produkt.
	 * @return array
	 */
	protected static function rows_for( array $f, $product ) {
		$rules = WCIS_Pricing::sanitize_rules( $f['price_rules'] );
		$pct   = WCIS_Pricing::percent_for( $product->get_id(), $rules );

		$common = array(
			'categories'   => self::category_paths( $product->get_id() ),
			'brand'        => self::term_names( $product->get_id(), WCIS_Filter::brand_taxonomy() ),
			'manufacturer' => self::term_names( $product->get_id(), 'product_manufacturer' ),
			'product_url'  => get_permalink( $product->get_id() ),
		);

		$rows = array();
		if ( $product->is_type( 'variable' ) ) {
			$children = array_filter( array_map( 'wc_get_product', $product->get_children() ) );
			$vrows    = array();
			foreach ( $children as $v ) {
				if ( ! $v instanceof WC_Product || 'publish' !== $v->get_status() ) {
					continue;
				}
				if ( ! empty( $f['only_instock'] ) && 'outofstock' === $v->get_stock_status() ) {
					continue;
				}
				$vrows[] = self::row( $f, $v, $product, $common, $pct, $rules );
			}
			if ( empty( $vrows ) ) {
				return array();
			}
			if ( ! empty( $f['parents'] ) ) {
				$rows[] = self::row( $f, $product, null, $common, $pct, $rules );
			}
			return array_merge( $rows, $vrows );
		}

		if ( ! empty( $f['only_instock'] ) && 'outofstock' === $product->get_stock_status() ) {
			return array();
		}
		return array( self::row( $f, $product, null, $common, $pct, $rules ) );
	}

	/**
	 * Eine CSV-Zeile.
	 *
	 * @param array           $f       Feed.
	 * @param WC_Product      $p       Produkt/Variation.
	 * @param WC_Product|null $parent  Eltern-Produkt (bei Variationen).
	 * @param array           $common  Gemeinsame Werte.
	 * @param float           $pct     Preisregel-Prozent.
	 * @param array           $rules   Preisregeln.
	 * @return array
	 */
	protected static function row( array $f, $p, $parent, array $common, $pct, array $rules ) {
		$is_var    = $p->is_type( 'variation' );
		$is_parent = $p->is_type( 'variable' );
		$src       = $parent ? $parent : $p;

		$regular = $is_parent ? '' : self::price( $f, $p, $p->get_regular_price( 'edit' ), $pct, $rules );
		$sale    = ( $is_parent || empty( $f['sale_prices'] ) ) ? '' : self::price( $f, $p, $p->get_sale_price( 'edit' ), $pct, $rules );
		if ( '' !== $sale && '' !== $regular && (float) $sale >= (float) $regular ) {
			$sale = '';
		}

		$variant = array();
		if ( $is_var ) {
			foreach ( $p->get_attributes() as $key => $value ) {
				$label = wc_attribute_label( $key, $parent );
				if ( taxonomy_exists( $key ) ) {
					$term  = get_term_by( 'slug', $value, $key );
					$value = $term ? $term->name : $value;
				}
				$variant[] = $label . ': ' . ( '' === (string) $value ? __( 'beliebig', 'blocksocial-woocommerce-sync' ) : $value );
			}
		}

		$gtin = method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : '';
		if ( '' === $gtin ) {
			$gtin = (string) get_post_meta( $p->get_id(), '_ts_gtin', true );
		}

		$images = array();
		foreach ( array_merge( array( $p->get_image_id() ), $is_var ? array() : $src->get_gallery_image_ids() ) as $aid ) {
			$u = $aid ? wp_get_attachment_image_url( $aid, 'full' ) : '';
			if ( $u ) {
				$images[] = $u;
			}
		}
		if ( empty( $images ) && $is_var && $parent ) {
			$u = wp_get_attachment_image_url( $parent->get_image_id(), 'full' );
			if ( $u ) {
				$images[] = $u;
			}
		}

		$unit_price = (string) get_post_meta( $p->get_id(), '_unit_price', true );
		$unit_base  = (string) get_post_meta( $p->get_id(), '_unit_base', true );
		$unit       = (string) get_post_meta( $p->get_id(), '_unit', true );
		if ( '' === $unit && $is_var ) {
			$unit      = (string) get_post_meta( $parent->get_id(), '_unit', true );
			$unit_base = '' !== $unit_base ? $unit_base : (string) get_post_meta( $parent->get_id(), '_unit_base', true );
		}
		if ( '' !== $unit_price ) {
			$unit_price = self::price( $f, $p, $unit_price, $pct, array( 'rounding' => 'none' ) + $rules );
		}

		$updated = $p->get_date_modified();
		$row     = array(
			'sku'               => $p->get_sku(),
			'parent_sku'        => $parent ? $parent->get_sku() : '',
			'type'              => $is_var ? 'variation' : ( $is_parent ? 'variable' : 'simple' ),
			'name'              => $is_var ? $parent->get_name() : $p->get_name(),
			'variant'           => implode( ', ', $variant ),
			'categories'        => $common['categories'],
			'brand'             => $common['brand'],
			'manufacturer'      => $common['manufacturer'],
			'gtin'              => $gtin,
			'regular_price'     => $regular,
			'sale_price'        => $sale,
			'currency'          => get_woocommerce_currency(),
			'price_type'        => $f['prices'],
			'tax_rate'          => ( 'none' === $p->get_tax_status() ) ? '0' : (string) WCIS_Product_Sync::tax_rate_for_class( $p->get_tax_class() ),
			'unit_price'        => $unit_price,
			'unit'              => trim( ( '' !== $unit_base && '1' !== $unit_base ? $unit_base . ' ' : ( '' !== $unit ? '1 ' : '' ) ) . $unit ),
			'stock_quantity'    => $is_parent ? '' : ( $p->managing_stock() ? (string) (int) $p->get_stock_quantity() : '' ),
			'stock_status'      => $is_parent ? '' : $p->get_stock_status(),
			'backorders'        => $is_parent ? '' : $p->get_backorders(),
			'delivery_time'     => self::term_names( $p->get_id(), 'product_delivery_time' ) ?: ( $parent ? self::term_names( $parent->get_id(), 'product_delivery_time' ) : '' ),
			'weight'            => (string) $p->get_weight(),
			'image_url'         => isset( $images[0] ) ? $images[0] : '',
			'gallery_urls'      => implode( '|', array_slice( $images, 1 ) ),
			'product_url'       => $is_var ? $p->get_permalink() : $common['product_url'],
			'short_description' => ! empty( $f['descriptions'] ) && ! $is_var ? $src->get_short_description() : '',
			'description'       => ! empty( $f['descriptions'] ) ? ( $is_var ? $p->get_description() : $src->get_description() ) : '',
			'updated_at'        => $updated ? $updated->date( 'c' ) : '',
		);

		// Schutz vor „CSV-Injection" (Formeln in Tabellenprogrammen) bei Textfeldern.
		foreach ( array( 'name', 'variant', 'categories', 'brand', 'manufacturer', 'short_description', 'description', 'delivery_time' ) as $k ) {
			if ( '' !== $row[ $k ] && in_array( $row[ $k ][0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$row[ $k ] = "'" . $row[ $k ];
			}
		}
		return array_values( $row );
	}

	/**
	 * Preis für den Feed (brutto/netto, Preisregel des Feeds).
	 *
	 * @param array      $f     Feed.
	 * @param WC_Product $p     Artikel.
	 * @param string     $price WooCommerce-Preis.
	 * @param float      $pct   Prozent.
	 * @param array      $rules Regeln.
	 * @return string
	 */
	protected static function price( array $f, $p, $price, $pct, array $rules ) {
		if ( '' === (string) $price ) {
			return '';
		}
		$v = 'gross' === $f['prices']
			? wc_get_price_including_tax( $p, array( 'price' => $price, 'qty' => 1 ) )
			: wc_get_price_excluding_tax( $p, array( 'price' => $price, 'qty' => 1 ) );
		return WCIS_Pricing::compute( wc_format_decimal( $v, wc_get_price_decimals() ), $pct, isset( $rules['rounding'] ) ? $rules['rounding'] : 'none' );
	}

	/**
	 * Kategorie-Pfade („Dünger > Bio", mehrere mit „|").
	 *
	 * @param int $pid Produkt-ID.
	 * @return string
	 */
	protected static function category_paths( $pid ) {
		$terms = wp_get_post_terms( $pid, 'product_cat' );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		$paths = array();
		foreach ( $terms as $t ) {
			$names = array( $t->name );
			foreach ( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) as $a ) {
				$at = get_term( $a, 'product_cat' );
				if ( $at && ! is_wp_error( $at ) ) {
					array_unshift( $names, $at->name );
				}
			}
			$paths[] = implode( ' > ', $names );
		}
		return implode( '|', $paths );
	}

	/**
	 * Begriffsnamen einer Taxonomie („|"-getrennt).
	 *
	 * @param int    $pid Produkt-ID.
	 * @param string $tax Taxonomie.
	 * @return string
	 */
	protected static function term_names( $pid, $tax ) {
		if ( ! $tax || ! taxonomy_exists( $tax ) ) {
			return '';
		}
		$names = wp_get_post_terms( $pid, $tax, array( 'fields' => 'names' ) );
		return is_wp_error( $names ) ? '' : implode( '|', $names );
	}

	// -------------------------------------------------------------------------
	// Auslieferung
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Auslieferung
	// -------------------------------------------------------------------------

	/**
	 * Liefert einen Feed aus, wenn die Shop-URL mit ?blocksocial_feed=… aufgerufen wird.
	 */
	public static function maybe_serve() {
		if ( empty( $_GET['blocksocial_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id  = sanitize_key( wp_unslash( $_GET['blocksocial_feed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$f   = self::get( $id );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( ! $f || empty( $f['active'] ) || '' === $key || ! hash_equals( (string) $f['token'], $key ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Forbidden';
			exit;
		}

		// Nur geänderte Produkte nachziehen (meist wenige Zeilen, sofort erledigt).
		$left = self::refresh( $f, self::SERVE_BUDGET );
		$f    = self::get( $id );
		if ( empty( $f['built_at'] ) && $left > 0 ) {
			self::schedule_build();
			status_header( 503 );
			header( 'Retry-After: 30' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( sprintf( 'Feed wird aufgebaut (%d Produkte offen) – bitte in Kürze erneut abrufen.', $left ) );
			exit;
		}

		// Abrufstatistik (höchstens einmal pro Minute schreiben).
		if ( time() - (int) $f['last_access'] > 60 ) {
			self::update( $id, array( 'last_access' => time(), 'access_count' => (int) $f['access_count'] + 1 ) );
		}

		$fp = self::fingerprint( $f );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $fp['mtime'] ) . ' GMT' );
		header( 'ETag: ' . $fp['etag'] );
		header( 'Cache-Control: no-cache, must-revalidate', true );
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ( '' !== $inm && $inm === $fp['etag'] ) || ( '' === $inm && $ims && $ims >= $fp['mtime'] ) ) {
			status_header( 304 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $f['name'] ) . '.csv"' );
		if ( 'HEAD' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::stream( $f );
		}
		exit;
	}

	/**
	 * Admin-Download (aktuellste Fassung).
	 *
	 * @param string $id Feed-ID.
	 */
	public static function download( $id ) {
		$f = self::get( $id );
		if ( ! $f ) {
			wp_die( esc_html__( 'Feed nicht gefunden.', 'blocksocial-woocommerce-sync' ) );
		}
		$left = self::refresh( $f, 60.0 );
		$f    = self::get( $id );
		if ( empty( $f['built_at'] ) && $left > 0 ) {
			wp_die( esc_html( sprintf( __( 'Der Feed wird noch aufgebaut (%d Produkte offen). Bitte in Kürze erneut versuchen.', 'blocksocial-woocommerce-sync' ), $left ) ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $f['name'] . '-' . gmdate( 'Y-m-d-His' ) ) . '.csv"' );
		self::stream( $f );
		exit;
	}
}
