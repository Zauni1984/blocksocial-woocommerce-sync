<?php
/**
 * Preisregeln für Empfänger-Shops (Neben- und Partnershops).
 *
 * Nach dem erstmaligen Einspielen der Produkte kann der Shop seine Preise
 * prozentual anpassen – nach oben oder unten, für alle Produkte und/oder je
 * Kategorie, optional mit Preis-Rundung (z. B. auf ,99).
 *
 * Funktionsweise (idempotent, nichts geht verloren):
 * - Je Produkt/Variation wird der BASISPREIS gespeichert (der vom Hauptshop
 *   gelieferte Preis bzw. der Preis beim ersten Anwenden).
 * - Endpreis = Basispreis × (1 + Prozent/100), danach Rundung.
 * - Mehrfaches Anwenden verändert nichts (kein Aufschlag auf den Aufschlag).
 * - 0 % stellt den Originalpreis wieder her.
 * - Kommt vom Hauptshop ein neuer Preis, wird er die neue Basis und die Regel
 *   automatisch erneut angewendet – die eigene Marge bleibt erhalten.
 * - Wird ein Preis im Shop manuell geändert, gilt dieser als neue Basis.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preisregel-Engine inkl. Massen-Anwendung mit Fortschritt.
 */
class WCIS_Pricing {

	const META_BASE_REGULAR  = '_wcis_base_regular_price';
	const META_BASE_SALE     = '_wcis_base_sale_price';
	const META_FINAL_REGULAR = '_wcis_final_regular_price';
	const META_FINAL_SALE    = '_wcis_final_sale_price';
	const META_PERCENT       = '_wcis_price_percent';

	/**
	 * Options-Schlüssel des Massen-Jobs.
	 */
	const JOB_OPT = 'wcis_pricing_job';

	/**
	 * Transient mit den Produkt-IDs des Jobs.
	 */
	const IDS_TR = 'wcis_pricing_ids';

	/**
	 * Zeitbudget pro Tick (Sekunden).
	 */
	const TICK_BUDGET = 8.0;

	/**
	 * Cache: Kategorie-ID => Vorfahren.
	 *
	 * @var array
	 */
	protected static $ancestors = array();

	/**
	 * Verfügbare Rundungs-Optionen.
	 *
	 * @return array slug => Label.
	 */
	public static function rounding_options() {
		return array(
			'none'  => __( 'Keine Rundung (auf Cent genau)', 'blocksocial-woocommerce-sync' ),
			'x99'   => __( 'Aufrunden auf ,99 (z. B. 23,47 → 23,99)', 'blocksocial-woocommerce-sync' ),
			'x95'   => __( 'Aufrunden auf ,95 (z. B. 23,47 → 23,95)', 'blocksocial-woocommerce-sync' ),
			'x90'   => __( 'Aufrunden auf ,90 (z. B. 23,47 → 23,90)', 'blocksocial-woocommerce-sync' ),
			'tenth' => __( 'Aufrunden auf 10 Cent (z. B. 23,47 → 23,50)', 'blocksocial-woocommerce-sync' ),
			'whole' => __( 'Aufrunden auf volle Euro (z. B. 23,47 → 24,00)', 'blocksocial-woocommerce-sync' ),
		);
	}

	/**
	 * Bereinigt Regeln und begrenzt die Prozentwerte.
	 *
	 * @param mixed $rules Rohdaten.
	 * @param float $min   Minimal erlaubter Prozentwert.
	 * @param float $max   Maximal erlaubter Prozentwert.
	 * @return array { global: float, categories: [term_id => float], rounding: string }
	 */
	public static function sanitize_rules( $rules, $min = -90, $max = 1000 ) {
		$rules = is_array( $rules ) ? $rules : array();
		$min   = (float) $min;
		$max   = (float) $max;
		$clamp = static function ( $v ) use ( $min, $max ) {
			$v = (float) str_replace( ',', '.', trim( (string) $v ) );
			$v = round( $v, 2 );
			return max( $min, min( $max, $v ) );
		};

		$cats = array();
		if ( ! empty( $rules['categories'] ) && is_array( $rules['categories'] ) ) {
			foreach ( $rules['categories'] as $tid => $pct ) {
				$tid = (int) $tid;
				if ( $tid > 0 && '' !== trim( (string) $pct ) ) {
					$cats[ $tid ] = $clamp( $pct );
				}
			}
		}

		$rounding = isset( $rules['rounding'] ) ? (string) $rules['rounding'] : 'none';
		if ( ! array_key_exists( $rounding, self::rounding_options() ) ) {
			$rounding = 'none';
		}

		return array(
			'global'     => $clamp( isset( $rules['global'] ) ? $rules['global'] : 0 ),
			'categories' => $cats,
			'rounding'   => $rounding,
		);
	}

	/**
	 * Aktuelle Regeln dieses Shops.
	 *
	 * @return array
	 */
	public static function rules() {
		return self::sanitize_rules( WCIS_Settings::get( 'price_rules', array() ), self::limit_min(), self::limit_max() );
	}

	/**
	 * Erlaubter Mindest-Prozentwert (Partner: Vorgabe des Hauptshops).
	 *
	 * @return float
	 */
	public static function limit_min() {
		if ( WCIS_Edition::is_partner() ) {
			$m = WCIS_Settings::get( 'managed', array() );
			return ! empty( $m['allow_price_rules'] ) ? (float) $m['price_min'] : 0.0;
		}
		return -90.0;
	}

	/**
	 * Erlaubter Höchst-Prozentwert (Partner: Vorgabe des Hauptshops).
	 *
	 * @return float
	 */
	public static function limit_max() {
		if ( WCIS_Edition::is_partner() ) {
			$m = WCIS_Settings::get( 'managed', array() );
			return ! empty( $m['allow_price_rules'] ) ? (float) $m['price_max'] : 0.0;
		}
		return 1000.0;
	}

	/**
	 * Sind Preisregeln in diesem Shop verfügbar?
	 *
	 * Gedacht für Empfänger-Shops (Neben-/Partnershops): Der Hauptshop gibt die
	 * Originalpreise vor und nutzt die Regeln nicht.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( WCIS_Edition::is_partner() ) {
			$m = WCIS_Settings::get( 'managed', array() );
			return WCIS_Settings::is_enabled() && ! empty( $m['allow_price_rules'] );
		}
		return ! WCIS_Settings::is_master();
	}

	/**
	 * Gibt es mindestens eine wirksame Regel?
	 *
	 * @param array $rules Regeln.
	 * @return bool
	 */
	public static function has_active_rules( array $rules ) {
		if ( 0.0 !== (float) $rules['global'] ) {
			return true;
		}
		foreach ( $rules['categories'] as $pct ) {
			if ( 0.0 !== (float) $pct ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prozentwert für ein Produkt: spezifischste Kategorie-Regel (Unter- vor
	 * Oberkategorie, bei Gleichstand der höhere Wert), sonst die globale Regel.
	 *
	 * @param int   $product_id Eltern-Produkt-ID.
	 * @param array $rules      Regeln.
	 * @return float
	 */
	public static function percent_for( $product_id, array $rules ) {
		if ( empty( $rules['categories'] ) ) {
			return (float) $rules['global'];
		}
		$cats = wp_get_post_terms( (int) $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $cats ) || empty( $cats ) ) {
			return (float) $rules['global'];
		}

		$best_depth = -1;
		$best_pct   = null;
		foreach ( $cats as $cid ) {
			$chain = array_merge( array( (int) $cid ), self::ancestors( (int) $cid ) );
			foreach ( $chain as $tid ) {
				if ( ! array_key_exists( $tid, $rules['categories'] ) ) {
					continue;
				}
				$depth = count( self::ancestors( $tid ) );
				$pct   = (float) $rules['categories'][ $tid ];
				if ( $depth > $best_depth || ( $depth === $best_depth && $pct > $best_pct ) ) {
					$best_depth = $depth;
					$best_pct   = $pct;
				}
			}
		}
		return null === $best_pct ? (float) $rules['global'] : $best_pct;
	}

	/**
	 * Vorfahren einer Kategorie (gecacht).
	 *
	 * @param int $tid Term-ID.
	 * @return array
	 */
	protected static function ancestors( $tid ) {
		if ( ! isset( self::$ancestors[ $tid ] ) ) {
			self::$ancestors[ $tid ] = array_map( 'intval', get_ancestors( $tid, 'product_cat', 'taxonomy' ) );
		}
		return self::$ancestors[ $tid ];
	}

	/**
	 * Berechnet den Endpreis.
	 *
	 * @param string $base     Basispreis.
	 * @param float  $percent  Prozent (+/-).
	 * @param string $rounding Rundung.
	 * @return string
	 */
	public static function compute( $base, $percent, $rounding ) {
		if ( '' === (string) $base || ! is_numeric( $base ) ) {
			return (string) $base;
		}
		if ( 0.0 === (float) $percent || 0.0 === (float) $base ) {
			return (string) $base; // 0 % = Originalpreis; Gratis-Artikel bleiben gratis (keine Rundung auf 0,99).
		}
		$v   = (float) $base * ( 1 + (float) $percent / 100 );
		$v   = max( 0.0, $v );
		$dec = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		switch ( $rounding ) {
			case 'x99':
				$v = ceil( round( $v + 0.01, 4 ) ) - 0.01;
				break;
			case 'x95':
				$v = ceil( round( $v + 0.05, 4 ) ) - 0.05;
				break;
			case 'x90':
				$v = ceil( round( $v + 0.10, 4 ) ) - 0.10;
				break;
			case 'tenth':
				$v = ceil( round( $v * 10, 4 ) ) / 10;
				break;
			case 'whole':
				$v = ceil( round( $v, 4 ) );
				break;
			default:
				$v = round( $v, $dec );
		}
		return wc_format_decimal( max( 0.0, $v ), $dec );
	}

	/**
	 * Setzt den Basispreis (bei eingehenden Preisen vom Hauptshop).
	 *
	 * @param WC_Product $product Produkt/Variation.
	 * @param string     $which   'regular' | 'sale'.
	 * @param string     $value   Preis.
	 */
	public static function set_base( $product, $which, $value ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$base  = 'sale' === $which ? self::META_BASE_SALE : self::META_BASE_REGULAR;
		$final = 'sale' === $which ? self::META_FINAL_SALE : self::META_FINAL_REGULAR;
		$product->update_meta_data( $base, (string) $value );
		// Der eingehende Preis ist zugleich der aktuell gesetzte Preis: als „zuletzt
		// gesetzt" merken, damit spätere manuelle Änderungen im Shop erkannt werden.
		$product->update_meta_data( $final, (string) $value );
	}

	/**
	 * Gleich bei zwei Preis-Strings (numerisch tolerant).
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return bool
	 */
	protected static function same_price( $a, $b ) {
		$a = (string) $a;
		$b = (string) $b;
		if ( '' === $a || '' === $b ) {
			return $a === $b;
		}
		return abs( (float) $a - (float) $b ) < 0.00001;
	}

	/**
	 * Ermittelt den Basispreis: Erstanwendung → aktueller Preis; manuell
	 * geänderter Preis (≠ zuletzt gesetzter Endpreis) → aktueller Preis; sonst
	 * gespeicherte Basis.
	 *
	 * @param WC_Product $p     Produkt/Variation.
	 * @param string     $which 'regular' | 'sale'.
	 * @param string     $cur   Aktueller Preis.
	 * @return string
	 */
	protected static function resolve_base( $p, $which, $cur ) {
		$base_key  = 'sale' === $which ? self::META_BASE_SALE : self::META_BASE_REGULAR;
		$final_key = 'sale' === $which ? self::META_FINAL_SALE : self::META_FINAL_REGULAR;

		if ( ! $p->meta_exists( $base_key ) ) {
			return (string) $cur;
		}
		if ( $p->meta_exists( $final_key ) && ! self::same_price( $cur, $p->get_meta( $final_key ) ) ) {
			return (string) $cur; // im Shop manuell geändert → neue Basis.
		}
		return (string) $p->get_meta( $base_key );
	}

	/**
	 * Wendet die Regel auf ein einzelnes Produkt/eine Variation an (ohne Speichern).
	 *
	 * @param WC_Product $p        Produkt/Variation.
	 * @param float      $percent  Prozent.
	 * @param string     $rounding Rundung.
	 * @return bool Preis geändert?
	 */
	protected static function reprice_one( $p, $percent, $rounding ) {
		$changed = false;

		$base_r  = self::resolve_base( $p, 'regular', $p->get_regular_price( 'edit' ) );
		$base_s  = self::resolve_base( $p, 'sale', $p->get_sale_price( 'edit' ) );
		$final_r = self::compute( $base_r, $percent, $rounding );
		$final_s = self::compute( $base_s, $percent, $rounding );

		if ( ! self::same_price( $final_r, $p->get_regular_price( 'edit' ) ) ) {
			$p->set_regular_price( $final_r );
			$changed = true;
		}
		if ( ! self::same_price( $final_s, $p->get_sale_price( 'edit' ) ) ) {
			$p->set_sale_price( $final_s );
			$changed = true;
		}

		$p->update_meta_data( self::META_BASE_REGULAR, $base_r );
		$p->update_meta_data( self::META_BASE_SALE, $base_s );
		$p->update_meta_data( self::META_FINAL_REGULAR, $final_r );
		$p->update_meta_data( self::META_FINAL_SALE, $final_s );
		$p->update_meta_data( self::META_PERCENT, (string) $percent );

		return $changed;
	}

	/**
	 * Wendet die Preisregeln auf ein Produkt (inkl. aller Variationen) an.
	 *
	 * @param int  $product_id Produkt-ID.
	 * @param bool $force      Auch ohne aktive Regel anwenden (Rücksetzen auf Basis).
	 * @return bool|null true = geändert, false = unverändert, null = nicht anwendbar.
	 */
	public static function reprice_product( $product_id, $force = false ) {
		if ( ! self::is_available() ) {
			// Regeln (nicht mehr) erlaubt: bei erzwungenem Lauf früher angewendete
			// Auf-/Abschläge entfernen (zurück auf den Basispreis).
			return $force ? self::revert_product( $product_id ) : null;
		}
		$rules = self::rules();
		if ( ! $force && ! self::has_active_rules( $rules ) ) {
			return null;
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) ) {
			return null;
		}

		// Ohne Regel und ohne frühere Anpassung nichts anfassen (keine unnötigen Saves).
		$percent = self::percent_for( $product->get_id(), $rules );

		$targets = $product->is_type( 'variable' )
			? array_filter( array_map( 'wc_get_product', $product->get_children() ) )
			: array( $product );

		$changed = false;
		WCIS_Product_Sync::set_suppress( true );
		WCIS_Sync_Engine::set_suppress( true );
		try {
			foreach ( $targets as $t ) {
				if ( ! $t instanceof WC_Product ) {
					continue;
				}
				if ( 0.0 === (float) $percent && ! $t->meta_exists( self::META_BASE_REGULAR ) ) {
					continue; // nie angepasst und keine Regel → unverändert lassen.
				}
				$did = self::reprice_one( $t, $percent, $rules['rounding'] );
				$t->save();
				$changed = $changed || $did;
			}
			if ( $product->is_type( 'variable' ) && $changed ) {
				WC_Product_Variable::sync( $product->get_id() );
				wc_delete_product_transients( $product->get_id() );
			}
		} finally {
			WCIS_Product_Sync::set_suppress( false );
			WCIS_Sync_Engine::set_suppress( false );
		}
		return $changed;
	}

	/**
	 * Setzt ein Produkt (inkl. Variationen) auf die gespeicherten Basispreise zurück.
	 *
	 * @param int $product_id Produkt-ID.
	 * @return bool|null
	 */
	public static function revert_product( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) ) {
			return null;
		}
		$targets = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		$changed = false;
		WCIS_Product_Sync::set_suppress( true );
		WCIS_Sync_Engine::set_suppress( true );
		try {
			foreach ( $targets as $t ) {
				if ( ! $t instanceof WC_Product || ! $t->meta_exists( self::META_BASE_REGULAR ) ) {
					continue;
				}
				$did     = self::reprice_one( $t, 0.0, 'none' );
				$t->save();
				$changed = $changed || $did;
			}
			if ( $product->is_type( 'variable' ) && $changed ) {
				WC_Product_Variable::sync( $product->get_id() );
				wc_delete_product_transients( $product->get_id() );
			}
		} finally {
			WCIS_Product_Sync::set_suppress( false );
			WCIS_Sync_Engine::set_suppress( false );
		}
		return $changed;
	}

	/**
	 * Partner-Plugin: Vorgaben zu Preisregeln haben sich geändert (entzogen oder
	 * Rahmen verkleinert) → alle Preise im Hintergrund neu berechnen.
	 */
	public static function schedule_background_reprice() {
		update_option( 'wcis_reprice_offset', 0, false );
		if ( ! wp_next_scheduled( 'wcis_reprice_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'wcis_reprice_batch' );
		}
	}

	/**
	 * Cron: verarbeitet den nächsten Block von Produkten (je 100, plant sich neu).
	 */
	public static function background_reprice_batch() {
		$offset = (int) get_option( 'wcis_reprice_offset', 0 );
		$ids    = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'offset'         => $offset,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $pid ) {
			try {
				self::reprice_product( (int) $pid, true );
			} catch ( \Throwable $e ) {
				WCIS_Logger::error( sprintf( 'Preis-Neuberechnung für Produkt-ID %d fehlgeschlagen: %s', (int) $pid, $e->getMessage() ), 'local' );
			}
		}
		if ( count( $ids ) === 100 ) {
			update_option( 'wcis_reprice_offset', $offset + 100, false );
			wp_schedule_single_event( time() + 5, 'wcis_reprice_batch' );
		} else {
			delete_option( 'wcis_reprice_offset' );
			WCIS_Logger::info( 'Preise nach geänderten Vorgaben des Hauptshops neu berechnet.', 'local' );
		}
	}

	/**
	 * Vorschau: Beispielpreise mit den übergebenen (ungespeicherten) Regeln.
	 *
	 * @param array $rules Regeln.
	 * @param int   $limit Anzahl Beispiele.
	 * @return array
	 */
	public static function preview( array $rules, $limit = 8 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$rows      = array();
		$scanned   = 0;
		$affected  = 0;
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$scanned++;
			$pct = self::percent_for( $pid, $rules );
			$t   = $product->is_type( 'variable' ) ? wc_get_product( current( $product->get_children() ) ) : $product;
			if ( ! $t instanceof WC_Product ) {
				continue;
			}
			$base = self::resolve_base( $t, 'regular', $t->get_regular_price( 'edit' ) );
			$new  = self::compute( $base, $pct, $rules['rounding'] );
			if ( ! self::same_price( $new, $t->get_regular_price( 'edit' ) ) ) {
				$affected++;
			}
			if ( count( $rows ) < $limit && '' !== (string) $base && 0.0 !== $pct ) {
				$rows[] = array(
					'name'    => $product->get_name(),
					'percent' => $pct,
					'base'    => wc_format_localized_price( $base ),
					'new'     => wc_format_localized_price( $new ),
				);
			}
		}
		return array(
			'rows'      => $rows,
			'scanned'   => $scanned,
			'affected'  => $affected,
			'truncated' => count( $ids ) >= 300,
		);
	}

	// -------------------------------------------------------------------------
	// Massen-Anwendung (Fortschrittsbalken)
	// -------------------------------------------------------------------------

	/**
	 * Startet die Anwendung der Regeln auf alle Produkte.
	 *
	 * @return array|WP_Error
	 */
	public static function job_start() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wcis_pricing_na', __( 'Preisregeln sind in diesem Shop nicht verfügbar.', 'blocksocial-woocommerce-sync' ) );
		}
		$existing = self::job_state();
		if ( is_array( $existing ) && 'running' === $existing['status'] ) {
			return $existing;
		}

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
		set_transient( self::IDS_TR, $ids, HOUR_IN_SECONDS );

		$job = array(
			'status'     => ! empty( $ids ) ? 'running' : 'done',
			'total'      => count( $ids ),
			'index'      => 0,
			'changed'    => 0,
			'unchanged'  => 0,
			'failed'     => 0,
			'started_at' => time(),
			'updated_at' => time(),
		);
		update_option( self::JOB_OPT, $job, false );

		$r = self::rules();
		WCIS_Logger::info(
			sprintf( 'Preisregeln werden angewendet: alle Produkte %+.2f %%, %d Kategorie-Regel(n), Rundung „%s", %d Produkte.', $r['global'], count( $r['categories'] ), $r['rounding'], count( $ids ) ),
			'local'
		);
		return $job;
	}

	/**
	 * Nächster Abschnitt des Jobs.
	 *
	 * @return array|WP_Error
	 */
	public static function job_tick() {
		$job = self::job_state();
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'wcis_no_job', __( 'Kein laufender Vorgang.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'running' !== $job['status'] ) {
			return $job;
		}
		if ( get_transient( 'wcis_pricing_lock' ) ) {
			return $job;
		}
		set_transient( 'wcis_pricing_lock', 1, 30 );

		try {
			$ids = get_transient( self::IDS_TR );
			if ( ! is_array( $ids ) ) {
				$job['status'] = 'done';
				update_option( self::JOB_OPT, $job, false );
				return $job;
			}
			set_transient( self::IDS_TR, $ids, HOUR_IN_SECONDS );

			$start = microtime( true );
			do {
				if ( $job['index'] >= $job['total'] ) {
					$job['status'] = 'done';
					break;
				}
				$pid = (int) $ids[ $job['index'] ];
				try {
					$res = self::reprice_product( $pid, true );
					if ( true === $res ) {
						$job['changed']++;
					} else {
						$job['unchanged']++;
					}
				} catch ( \Throwable $e ) {
					$job['failed']++;
					WCIS_Logger::error( sprintf( 'Preisregel für Produkt-ID %d fehlgeschlagen: %s', $pid, $e->getMessage() ), 'local' );
				}
				$job['index']++;
				if ( $job['index'] >= $job['total'] ) {
					$job['status'] = 'done';
				}
				$job['updated_at'] = time();
				update_option( self::JOB_OPT, $job, false );
			} while ( 'running' === $job['status'] && ( microtime( true ) - $start ) < self::TICK_BUDGET );

			if ( 'done' === $job['status'] ) {
				delete_transient( self::IDS_TR );
				WCIS_Logger::info( sprintf( 'Preisregeln angewendet: %d Produkte geändert, %d unverändert, %d Fehler.', $job['changed'], $job['unchanged'], $job['failed'] ), 'local' );
			}
			return $job;
		} finally {
			delete_transient( 'wcis_pricing_lock' );
		}
	}

	/**
	 * Job-Status.
	 *
	 * @return array|null
	 */
	public static function job_state() {
		$job = get_option( self::JOB_OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Bricht den Job ab.
	 */
	public static function job_cancel() {
		$job = self::job_state();
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
	public static function job_percent( $job ) {
		if ( ! is_array( $job ) || empty( $job['total'] ) ) {
			return 0;
		}
		if ( 'done' === $job['status'] ) {
			return 100;
		}
		return (int) min( 100, floor( 100 * $job['index'] / $job['total'] ) );
	}

	/**
	 * Job für die AJAX-Ausgabe.
	 *
	 * @param array|null $job Job.
	 * @return array
	 */
	public static function job_to_response( $job ) {
		if ( ! is_array( $job ) ) {
			return array( 'status' => 'idle', 'percent' => 0 );
		}
		return array(
			'status'    => $job['status'],
			'percent'   => self::job_percent( $job ),
			'total'     => (int) $job['total'],
			'index'     => (int) $job['index'],
			'changed'   => (int) $job['changed'],
			'unchanged' => (int) $job['unchanged'],
			'failed'    => (int) $job['failed'],
		);
	}
}
