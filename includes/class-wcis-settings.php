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
			// Sync-Filter: welche Produkte werden (ausgehend) synchronisiert?
			'filter_mode'        => 'all', // all | selected
			'filter_categories'  => array(),
			'filter_brands'      => array(),
			'filter_include_ids' => array(),
			'filter_exclude_ids' => array(),
			'filter_exclude_categories' => array(),
			// Welche Produkt-Felder werden beim Produkt-Sync übertragen? (null = alle)
			'product_fields'     => array( 'name', 'price', 'tax', 'description', 'short_description', 'images', 'categories', 'tags', 'brands', 'attributes', 'shipping_class', 'delivery_time', 'germanized', 'dimensions', 'status', 'stock' ),
			// Steuerklassen-Zuordnung (Empfängerseite), z. B. "reduzierter-preis=reduced-rate" je Zeile.
			'tax_class_map'      => '',
		);
	}

	/**
	 * Liefert alle Einstellungen (mit Defaults gemischt).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved        = get_option( WCIS_OPT, array() );
			$saved        = is_array( $saved ) ? $saved : array();
			self::$cache  = wp_parse_args( $saved, self::defaults() );
		}
		return self::$cache;
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
		$merged = wp_parse_args( $values, self::all() );
		update_option( WCIS_OPT, $merged );
		self::$cache = $merged;
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
	 * @return array Liste von ['name'=>..,'url'=>..].
	 */
	public static function get_peers() {
		$self  = self::normalize_url( self::this_url() );
		$peers = array();
		foreach ( (array) self::get( 'shops', array() ) as $shop ) {
			if ( empty( $shop['url'] ) ) {
				continue;
			}
			if ( self::normalize_url( $shop['url'] ) === $self ) {
				continue; // sich selbst überspringen.
			}
			$peers[] = array(
				'name' => isset( $shop['name'] ) ? $shop['name'] : $shop['url'],
				'url'  => untrailingslashit( $shop['url'] ),
			);
		}
		return $peers;
	}

	/**
	 * Das Netzwerk-Secret.
	 *
	 * @return string
	 */
	public static function secret() {
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
}
