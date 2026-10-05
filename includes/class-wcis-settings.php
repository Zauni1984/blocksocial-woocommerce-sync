<?php
/**
 * Einstellungen: Speicherung und Zugriffshelfer.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verwaltet die Plugin-Optionen.
 *
 * Alle Shops im Netzwerk teilen sich dasselbe "Netzwerk-Secret" und dieselbe
 * Shop-Liste (Topologie). Ein Shop ist der Master (Hauptshop); dieser startet
 * die erste Voll-Synchronisation. Der Master ist jederzeit änderbar.
 */
class WCIS_Settings {

	/**
	 * Zwischenspeicher der Optionen.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Standardwerte.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => true,
			'network_secret' => '',
			'this_shop_name' => get_bloginfo( 'name' ),
			'this_shop_url'  => home_url(),
			'master_url'     => home_url(),
			// Liste aller Shops im Netzwerk: [ ['name'=>..,'url'=>..], ... ]
			// Enthält üblicherweise auch diesen Shop selbst.
			'shops'          => array(),
			// Bei fehlender SKU oder nicht vorhandenem Produkt still ignorieren.
			'sync_status'    => true,  // Auch Lagerstatus (in/out of stock) mitsenden.
			'log_level'      => 'info', // info | error
			'batch_size'     => 50,    // Artikel pro Sync-Batch (1–500).
			'http_timeout'   => 20,    // Timeout je Anfrage in Sekunden (5–60).
			// Periodischer Abgleich: off | hourly | sixhourly | daily.
			'reconcile_interval' => 'hourly',
			// Strategie: 'lowest' (niedrigster Bestand gewinnt, kein Überverkauf)
			// oder 'local' (Hauptshop ist maßgeblich).
			'reconcile_strategy' => 'lowest',
			// Produkt-Sync: neue Produkte 1:1 an andere Shops übertragen (optional).
			'product_sync_enabled'         => false,
			'product_sync_source'          => 'master', // master | any
			'product_sync_images'          => true,     // Bilder mitübertragen.
			'product_sync_update_existing' => false,    // vorhandene Produkte überschreiben.
			'update_prices'                => false,    // Empfänger: Preise bestehender Produkte aktualisieren (auch ohne volle Überschreibung).
			'price_gross_mode'             => false,    // Empfänger (Kleinunternehmer §19): eingehende Preise als Brutto übernehmen.
			'accept_sale_prices'           => true,     // Empfänger: Angebotspreise übernehmen (aus = eigene Angebotspreise bleiben unangetastet).
			// Sync-Filter: welche Produkte werden synchronisiert (gesendet UND empfangen)?
			'filter_mode'        => 'all', // all | selected
			'filter_categories'  => array(),
			'filter_brands'      => array(),
			'filter_include_ids' => array(),
			'filter_exclude_ids' => array(),
			'filter_exclude_categories' => array(),
			// Marken, die vom Kategorie-Ausschluss ausgenommen sind.
			'filter_exclude_except_brands' => array(),
			// Ignorierte Kategorien (z. B. „Angebote"): zählen nicht für den Filter,
			// werden weder gesendet noch beim Empfang zugeordnet.
			'filter_ignore_categories'     => array(),
			// Welche Produkt-Felder werden beim Produkt-Sync übertragen? (null = alle)
			'product_fields'     => array( 'name', 'price', 'sale_price', 'tax', 'description', 'short_description', 'images', 'categories', 'tags', 'brands', 'manufacturer', 'gtin', 'attributes', 'shipping_class', 'delivery_time', 'germanized', 'dimensions', 'status', 'stock' ),
			// Steuerklassen-Zuordnung (Empfängerseite), z. B. "reduzierter-preis=reduced-rate" je Zeile.
			'tax_class_map'      => '',
			// Preisregeln (Empfänger-Shops): Auf-/Abschlag in % für alle Produkte
			// und/oder je Kategorie, optional mit Preis-Rundung.
			'price_rules'        => array(
				'global'     => 0,
				'categories' => array(),
				'rounding'   => 'none',
			),
			// Admin-Edition: Vorgaben für alle Partnershops (siehe WCIS_Partners).
			'partner_policy'     => array(),
			// Partner-Edition: Verbindung zum Hauptshop (aus dem Verbindungscode).
			'partner_conn'       => array(),
			// Partner-Edition: vom Hauptshop vorgegebene (schreibgeschützte) Einstellungen.
			'managed'            => array(),
		);
	}

	/**
	 * Gespeicherte Werte + Defaults (ohne Partner-Overlay).
	 *
	 * @return array
	 */
	protected static function raw() {
		$saved = get_option( WCIS_OPT, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Liefert alle Einstellungen (mit Defaults gemischt).
	 *
	 * Im Partner-Plugin werden alle vom Administrator vorgegebenen Werte
	 * erzwungen (Overlay) – lokal gespeicherte Abweichungen haben keine Wirkung.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$all = self::raw();
			if ( WCIS_Edition::is_partner() ) {
				$all = self::partner_overlay( $all );
			}
			self::$cache = $all;
		}
		return self::$cache;
	}

	/**
	 * Erzwingt im Partner-Plugin die Vorgaben des Hauptshops.
	 *
	 * @param array $s Einstellungen.
	 * @return array
	 */
	protected static function partner_overlay( array $s ) {
		$conn = is_array( $s['partner_conn'] ) ? $s['partner_conn'] : array();
		$m    = wp_parse_args( is_array( $s['managed'] ) ? $s['managed'] : array(), WCIS_Partners::policy_defaults() );
		$m    = array_merge( $m, WCIS_Partners::sanitize_policy( $m ) );

		$connected = ! empty( $conn['master_url'] ) && ! empty( $conn['key'] ) && ! empty( $conn['secret'] );

		$s['managed']        = $m;
		$s['enabled']        = $connected;
		$s['network_secret'] = '';
		$s['this_shop_url']  = untrailingslashit( home_url() );
		$s['master_url']     = $connected ? untrailingslashit( $conn['master_url'] ) : '';
		$s['shops']          = $connected
			? array( array( 'name' => isset( $conn['master_name'] ) ? $conn['master_name'] : $conn['master_url'], 'url' => untrailingslashit( $conn['master_url'] ) ) )
			: array();

		// Produkte kommen ausschließlich vom Hauptshop; Partner verteilen keine Produkte.
		$s['product_sync_enabled']         = $connected;
		$s['product_sync_source']          = 'master';
		$s['product_sync_images']          = ! empty( $m['images'] );
		$s['product_sync_update_existing'] = ! empty( $m['update_existing'] );
		$s['update_prices']                = ! empty( $m['update_prices'] );
		$s['sync_status']                  = ! empty( $m['sync_status'] );

		// Bestandsmeldungen umfassen alle Produkte (der Hauptshop ignoriert Fremd-SKUs).
		$s['filter_mode']               = 'all';
		$s['filter_categories']         = array();
		$s['filter_brands']             = array();
		$s['filter_include_ids']        = array();
		$s['filter_exclude_ids']        = array();
		$s['filter_exclude_categories'] = array();
		$s['filter_exclude_except_brands'] = array();
		$s['filter_ignore_categories']     = array();

		// Abgleich koordiniert ausschließlich der Hauptshop.
		$s['reconcile_interval'] = 'off';

		// Preisregeln nur, wenn vom Hauptshop erlaubt – und nur im erlaubten Rahmen.
		$s['price_rules'] = WCIS_Pricing::sanitize_rules(
			$s['price_rules'],
			! empty( $m['allow_price_rules'] ) ? (float) $m['price_min'] : 0,
			! empty( $m['allow_price_rules'] ) ? (float) $m['price_max'] : 0
		);

		return $s;
	}

	/**
	 * Partner-Edition: Verbindungsdaten zum Hauptshop.
	 *
	 * @return array { master_url, master_name, key, secret } oder leer.
	 */
	public static function partner_conn() {
		$c = self::get( 'partner_conn', array() );
		return ( is_array( $c ) && ! empty( $c['key'] ) && ! empty( $c['secret'] ) && ! empty( $c['master_url'] ) ) ? $c : array();
	}

	/**
	 * Liefert einen einzelnen Wert.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Standard.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Speichert die Einstellungen.
	 *
	 * @param array $values Neue Werte (werden mit vorhandenen gemischt).
	 */
	public static function update( array $values ) {
		// Mit den GESPEICHERTEN Werten mischen (nicht mit dem Partner-Overlay),
		// damit erzwungene Vorgaben nicht dauerhaft in die Option geschrieben werden.
		$merged = wp_parse_args( $values, self::raw() );
		update_option( WCIS_OPT, $merged );
		self::$cache = null;
	}

	/**
	 * Ist die Synchronisation global aktiviert?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) self::get( 'enabled', true );
	}

	/**
	 * Normalisiert eine URL für Vergleiche (Schema + Host + Pfad, ohne Trailing-Slash).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = preg_replace( '#^https?://#i', '', $url ); // Schema entfernen für Vergleich.
		$url = strtolower( $url );
		$url = preg_replace( '#^www\.#', '', $url );
		return untrailingslashit( $url );
	}

	/**
	 * URL dieses Shops.
	 *
	 * @return string
	 */
	public static function this_url() {
		$url = self::get( 'this_shop_url' );
		return $url ? $url : home_url();
	}

	/**
	 * Ist dieser Shop der Master (Hauptshop)?
	 *
	 * @return bool
	 */
	public static function is_master() {
		return self::normalize_url( self::this_url() ) === self::normalize_url( self::get( 'master_url' ) );
	}

	/**
	 * Liefert alle Peer-Shops (alle Shops außer diesem).
	 *
	 * Admin-Edition: eigene Shops des Netzwerks (type 'network') und – auf dem
	 * Hauptshop – alle aktiven Partnershops (type 'partner').
	 * Partner-Edition: ausschließlich der Hauptshop (type 'master').
	 *
	 * @param string $type Optional: nur Peers dieses Typs ('network'|'partner'|'master').
	 * @return array Liste von ['name'=>..,'url'=>..,'type'=>..].
	 */
	public static function get_peers( $type = '' ) {
		$self  = self::normalize_url( self::this_url() );
		$peers = array();
		$seen  = array();

		$shop_type = WCIS_Edition::is_partner() ? 'master' : 'network';
		foreach ( (array) self::get( 'shops', array() ) as $shop ) {
			if ( empty( $shop['url'] ) ) {
				continue;
			}
			$n = self::normalize_url( $shop['url'] );
			if ( $n === $self || isset( $seen[ $n ] ) ) {
				continue; // sich selbst / Doppelte überspringen.
			}
			$seen[ $n ] = true;
			$peers[]    = array(
				'name' => isset( $shop['name'] ) && $shop['name'] ? $shop['name'] : $shop['url'],
				'url'  => untrailingslashit( $shop['url'] ),
				'type' => $shop_type,
			);
		}

		// Partner hängen am Hauptshop: nur dort werden sie beliefert.
		if ( WCIS_Edition::is_admin_edition() && self::is_master() ) {
			foreach ( WCIS_Partners::active() as $p ) {
				$n = self::normalize_url( $p['url'] );
				if ( $n === $self || isset( $seen[ $n ] ) ) {
					continue;
				}
				$seen[ $n ] = true;
				$peers[]    = array(
					'name' => $p['name'],
					'url'  => untrailingslashit( $p['url'] ),
					'type' => 'partner',
					'key'  => $p['key'],
				);
			}
		}

		if ( '' !== $type ) {
			$peers = array_values(
				array_filter(
					$peers,
					static function ( $p ) use ( $type ) {
						return $p['type'] === $type;
					}
				)
			);
		}
		return $peers;
	}

	/**
	 * Ist eine Kommunikation möglich (Secret bzw. Partner-Verbindung vorhanden)?
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		if ( WCIS_Edition::is_partner() ) {
			return ! empty( self::partner_conn() );
		}
		return '' !== self::secret() || ! empty( WCIS_Partners::active() );
	}

	/**
	 * Das Netzwerk-Secret (Partner-Edition: das persönliche Partner-Secret).
	 *
	 * @return string
	 */
	public static function secret() {
		if ( WCIS_Edition::is_partner() ) {
			$c = self::partner_conn();
			return $c ? (string) $c['secret'] : '';
		}
		return (string) self::get( 'network_secret', '' );
	}

	/**
	 * Erzeugt ein neues zufälliges Secret.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Parst die Steuerklassen-Zuordnung in ein Array [quelle => ziel].
	 *
	 * Format je Zeile: "quell-slug=ziel-slug" oder "quell-slug => ziel-slug".
	 *
	 * @return array
	 */
	public static function tax_class_map() {
		$raw = (string) self::get( 'tax_class_map', '' );
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = preg_split( '/\s*(=>|=)\s*/', $line, 2 );
			if ( count( $parts ) === 2 ) {
				$map[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		return $map;
	}

	/**
	 * Daten-Migration bei Versionswechsel.
	 *
	 * @param string $from Bisher installierte Version.
	 */
	public static function migrate( $from ) {
		// 3.1.0: „Preis" (regulär) und „Angebotspreis" sind getrennte Felder. Wer
		// bisher „Preis" übertragen hat, überträgt weiterhin auch Angebotspreise.
		if ( '0' !== (string) $from && version_compare( $from, '3.1.0', '<' ) ) {
			$saved = get_option( WCIS_OPT, array() );
			if ( is_array( $saved ) && isset( $saved['product_fields'] ) && is_array( $saved['product_fields'] )
				&& in_array( 'price', $saved['product_fields'], true ) && ! in_array( 'sale_price', $saved['product_fields'], true ) ) {
				$saved['product_fields'][] = 'sale_price';
				update_option( WCIS_OPT, $saved );
				self::$cache = null;
			}
		}
	}
}
