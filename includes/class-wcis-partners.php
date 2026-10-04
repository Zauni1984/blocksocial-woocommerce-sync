<?php
/**
 * Partner-Verwaltung (Admin-Edition, Hauptshop).
 *
 * Jeder Partnershop erhält einen EIGENEN Zugangsschlüssel (Key-ID + Secret)
 * statt des gemeinsamen Netzwerk-Secrets. Dadurch …
 * - … kann ein Partner nur mit dem Hauptshop sprechen (nicht mit anderen
 *   Partnern oder den eigenen Shops des Administrators),
 * - … erkennt der Hauptshop eindeutig, WELCHER Partner eine Anfrage sendet,
 *   und erlaubt ihm nur das Nötigste (Verkäufe melden, Produkte holen),
 * - … lässt sich ein einzelner Partner jederzeit sperren oder neu verschlüsseln,
 *   ohne den restlichen Verbund anzufassen.
 *
 * Der Partner richtet sich mit einem einzigen „Verbindungscode" ein, der
 * Hauptshop-URL, Key-ID und Secret enthält.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partner-Registry und Partner-Richtlinien.
 */
class WCIS_Partners {

	/**
	 * Option mit den Partnern (enthält Secrets – nicht autoloaden).
	 */
	const OPT = 'wcis_partners';

	/**
	 * Präfix des Verbindungscodes (Version 1).
	 */
	const CODE_PREFIX = 'BSWS1-';

	/**
	 * Zwischenspeicher.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Standard-Richtlinie für alle Partner (vom Administrator änderbar).
	 *
	 * @return array
	 */
	public static function policy_defaults() {
		return array(
			// Produktdaten (Texte, Bilder, Pflichtangaben) beim Partner aktuell halten.
			'update_existing'     => true,
			// Preisänderungen des Hauptshops beim Partner nachziehen.
			'update_prices'       => true,
			// Bilder beim Neuanlegen mitübertragen.
			'images'              => true,
			// Lagerstatus (ohne Mengenverwaltung) mitsynchronisieren.
			'sync_status'         => true,
			// Darf der Partner eigene Preisaufschläge/-abschläge setzen?
			'allow_price_rules'   => true,
			// Erlaubter Rahmen für Preisregeln in Prozent.
			'price_min'           => -50,
			'price_max'           => 300,
			// Partner dürfen den Bestand des Hauptshops nur VERRINGERN (Verkäufe),
			// nie erhöhen – schützt vor Fehlbedienung und Manipulation.
			'stock_decrease_only' => true,
		);
	}

	/**
	 * Aktuelle Partner-Richtlinie (gespeicherte Werte + Defaults).
	 *
	 * @return array
	 */
	public static function policy() {
		$saved = WCIS_Settings::get( 'partner_policy', array() );
		$saved = is_array( $saved ) ? $saved : array();
		return self::sanitize_policy( wp_parse_args( $saved, self::policy_defaults() ) );
	}

	/**
	 * Bereinigt/validiert eine Richtlinie.
	 *
	 * @param array $p Rohdaten.
	 * @return array
	 */
	public static function sanitize_policy( $p ) {
		$d   = self::policy_defaults();
		$p   = is_array( $p ) ? $p : array();
		$out = array();
		foreach ( array( 'update_existing', 'update_prices', 'images', 'sync_status', 'allow_price_rules', 'stock_decrease_only' ) as $k ) {
			$out[ $k ] = isset( $p[ $k ] ) ? (bool) $p[ $k ] : $d[ $k ];
		}
		$min = isset( $p['price_min'] ) ? (float) $p['price_min'] : $d['price_min'];
		$max = isset( $p['price_max'] ) ? (float) $p['price_max'] : $d['price_max'];
		$min = max( -99, min( 1000, $min ) );
		$max = max( -99, min( 1000, $max ) );
		if ( $min > $max ) {
			$tmp = $min;
			$min = $max;
			$max = $tmp;
		}
		$out['price_min'] = $min;
		$out['price_max'] = $max;
		return $out;
	}

	// -------------------------------------------------------------------------
	// Registry
	// -------------------------------------------------------------------------

	/**
	 * Alle Partner (Key-ID => Partner).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$list        = get_option( self::OPT, array() );
			self::$cache = is_array( $list ) ? $list : array();
		}
		return self::$cache;
	}

	/**
	 * Liest die Partnerliste frisch aus der Datenbank (vor Schreibvorgängen, damit
	 * parallele Requests – z. B. „letzter Kontakt" – keine Admin-Änderungen
	 * wie Sperren oder Schlüsselwechsel überschreiben).
	 *
	 * @return array
	 */
	protected static function fresh() {
		self::$cache = null;
		wp_cache_delete( self::OPT, 'options' );
		return self::all();
	}

	/**
	 * Speichert die Partnerliste.
	 *
	 * @param array $list Partner.
	 */
	protected static function save_all( array $list ) {
		self::$cache = $list;
		if ( false === get_option( self::OPT, false ) ) {
			add_option( self::OPT, $list, '', false );
		} else {
			update_option( self::OPT, $list, false );
		}
	}

	/**
	 * Partner per Key-ID.
	 *
	 * @param string $key Key-ID.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		$key = (string) $key;
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Partner per URL.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public static function find_by_url( $url ) {
		$n = WCIS_Settings::normalize_url( $url );
		if ( '' === $n ) {
			return null;
		}
		foreach ( self::all() as $p ) {
			if ( WCIS_Settings::normalize_url( $p['url'] ) === $n ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Aktive Partner.
	 *
	 * @return array
	 */
	public static function active() {
		return array_filter(
			self::all(),
			static function ( $p ) {
				return ! empty( $p['active'] ) && ! empty( $p['url'] );
			}
		);
	}

	/**
	 * Legt einen neuen Partner an.
	 *
	 * @param string $name       Name.
	 * @param string $url        Shop-URL.
	 * @param string $scope      'all' | 'categories'.
	 * @param array  $categories Kategorie-IDs (bei scope=categories).
	 * @return array|WP_Error Partner.
	 */
	public static function create( $name, $url, $scope = 'all', $categories = array() ) {
		$url = untrailingslashit( esc_url_raw( trim( (string) $url ) ) );
		if ( '' === $url ) {
			return new WP_Error( 'wcis_partner_url', __( 'Bitte eine gültige Shop-URL des Partners angeben.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( self::find_by_url( $url ) ) {
			return new WP_Error( 'wcis_partner_exists', __( 'Für diese URL existiert bereits ein Partner.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( WCIS_Settings::normalize_url( $url ) === WCIS_Settings::normalize_url( WCIS_Settings::this_url() ) ) {
			return new WP_Error( 'wcis_partner_self', __( 'Die URL ist dieser Shop selbst.', 'blocksocial-woocommerce-sync' ) );
		}
		foreach ( (array) WCIS_Settings::get( 'shops', array() ) as $shop ) {
			if ( ! empty( $shop['url'] ) && WCIS_Settings::normalize_url( $shop['url'] ) === WCIS_Settings::normalize_url( $url ) ) {
				return new WP_Error( 'wcis_partner_network', __( 'Diese URL ist bereits als eigener Shop im Netzwerk eingetragen.', 'blocksocial-woocommerce-sync' ) );
			}
		}

		$key = self::new_key_id();
		$all = self::fresh();

		$all[ $key ] = array(
			'key'          => $key,
			'name'         => '' !== trim( (string) $name ) ? sanitize_text_field( $name ) : $url,
			'url'          => $url,
			'secret'       => self::new_secret(),
			'active'       => true,
			'scope'        => 'categories' === $scope ? 'categories' : 'all',
			'categories'   => array_values( array_filter( array_map( 'intval', (array) $categories ) ) ),
			'created_at'   => time(),
			'last_seen'    => 0,
			'last_version' => '',
		);
		self::save_all( $all );

		WCIS_Logger::info( sprintf( 'Partner „%s" (%s) angelegt.', $all[ $key ]['name'], $url ), 'outbound' );
		return $all[ $key ];
	}

	/**
	 * Aktualisiert Felder eines Partners.
	 *
	 * @param string $key    Key-ID.
	 * @param array  $fields Felder (name, active, scope, categories).
	 * @return bool
	 */
	public static function update( $key, array $fields ) {
		$all = self::fresh();
		if ( ! isset( $all[ $key ] ) ) {
			return false;
		}
		if ( isset( $fields['name'] ) ) {
			$all[ $key ]['name'] = sanitize_text_field( $fields['name'] );
		}
		if ( isset( $fields['active'] ) ) {
			$all[ $key ]['active'] = (bool) $fields['active'];
		}
		if ( isset( $fields['scope'] ) ) {
			$all[ $key ]['scope'] = 'categories' === $fields['scope'] ? 'categories' : 'all';
		}
		if ( isset( $fields['categories'] ) ) {
			$all[ $key ]['categories'] = array_values( array_filter( array_map( 'intval', (array) $fields['categories'] ) ) );
		}
		foreach ( array( 'last_seen', 'last_version' ) as $k ) {
			if ( isset( $fields[ $k ] ) ) {
				$all[ $key ][ $k ] = $fields[ $k ];
			}
		}
		self::save_all( $all );
		return true;
	}

	/**
	 * Entfernt einen Partner.
	 *
	 * @param string $key Key-ID.
	 */
	public static function delete( $key ) {
		$all = self::fresh();
		if ( isset( $all[ $key ] ) ) {
			WCIS_Logger::info( sprintf( 'Partner „%s" entfernt.', $all[ $key ]['name'] ), 'outbound' );
			unset( $all[ $key ] );
			self::save_all( $all );
		}
	}

	/**
	 * Erzeugt einen neuen Schlüssel für einen Partner (alter wird ungültig).
	 *
	 * @param string $key Key-ID.
	 * @return array|null Partner mit neuem Schlüssel.
	 */
	public static function rotate( $key ) {
		$all = self::fresh();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$p          = $all[ $key ];
		$new_key    = self::new_key_id();
		$p['key']   = $new_key;
		$p['secret'] = self::new_secret();
		unset( $all[ $key ] );
		$all[ $new_key ] = $p;
		self::save_all( $all );
		WCIS_Logger::info( sprintf( 'Neuer Zugangsschlüssel für Partner „%s" erzeugt – alter Schlüssel ist ungültig.', $p['name'] ), 'outbound' );
		return $p;
	}

	/**
	 * Merkt sich den letzten Kontakt eines Partners (max. alle 5 Minuten).
	 *
	 * @param string $key     Key-ID.
	 * @param string $version Plugin-Version des Partners (optional).
	 */
	public static function touch( $key, $version = '' ) {
		$p = self::get( $key );
		if ( ! $p ) {
			return;
		}
		if ( time() - (int) $p['last_seen'] < 300 && ( '' === $version || $version === $p['last_version'] ) ) {
			return;
		}
		$fields = array( 'last_seen' => time() );
		if ( '' !== $version ) {
			$fields['last_version'] = sanitize_text_field( $version );
		}
		self::update( $key, $fields );
	}

	/**
	 * Neue Key-ID.
	 *
	 * @return string
	 */
	protected static function new_key_id() {
		return 'bsp_' . strtolower( wp_generate_password( 16, false, false ) );
	}

	/**
	 * Neues Secret.
	 *
	 * @return string
	 */
	protected static function new_secret() {
		return wp_generate_password( 48, false, false );
	}

	// -------------------------------------------------------------------------
	// Verbindungscode
	// -------------------------------------------------------------------------

	/**
	 * Erzeugt den Verbindungscode für einen Partner.
	 *
	 * @param array $p Partner.
	 * @return string
	 */
	public static function connection_code( array $p ) {
		$data = array(
			'v' => 1,
			'm' => untrailingslashit( WCIS_Settings::this_url() ),
			'n' => (string) WCIS_Settings::get( 'this_shop_name', get_bloginfo( 'name' ) ),
			'k' => $p['key'],
			's' => $p['secret'],
		);
		return self::CODE_PREFIX . rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Liest einen Verbindungscode (Partner-Edition).
	 *
	 * @param string $code Code.
	 * @return array|WP_Error { master_url, master_name, key, secret }.
	 */
	public static function parse_code( $code ) {
		$code = trim( preg_replace( '/\s+/', '', (string) $code ) );
		if ( 0 !== strpos( $code, self::CODE_PREFIX ) ) {
			return new WP_Error( 'wcis_code', __( 'Ungültiger Verbindungscode (muss mit „BSWS1-" beginnen).', 'blocksocial-woocommerce-sync' ) );
		}
		$raw  = substr( $code, strlen( self::CODE_PREFIX ) );
		$raw  = strtr( $raw, '-_', '+/' );
		$pad  = strlen( $raw ) % 4;
		$raw .= $pad ? str_repeat( '=', 4 - $pad ) : '';
		$json = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = $json ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) || empty( $data['m'] ) || empty( $data['k'] ) || empty( $data['s'] ) ) {
			return new WP_Error( 'wcis_code', __( 'Der Verbindungscode ist beschädigt oder unvollständig. Bitte erneut vollständig kopieren.', 'blocksocial-woocommerce-sync' ) );
		}
		$url = untrailingslashit( esc_url_raw( (string) $data['m'] ) );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'wcis_code', __( 'Der Verbindungscode enthält keine gültige Hauptshop-URL.', 'blocksocial-woocommerce-sync' ) );
		}
		return array(
			'master_url'  => $url,
			'master_name' => isset( $data['n'] ) ? sanitize_text_field( (string) $data['n'] ) : $url,
			'key'         => sanitize_key( (string) $data['k'] ),
			'secret'      => (string) $data['s'],
		);
	}

	// -------------------------------------------------------------------------
	// Vorgaben & Sortiment
	// -------------------------------------------------------------------------

	/**
	 * Vorgaben, die der Hauptshop einem Partner auferlegt (wird an den Partner
	 * übertragen und dort schreibgeschützt angewendet).
	 *
	 * @param array $p Partner.
	 * @return array
	 */
	public static function managed_config( array $p ) {
		$policy = self::policy();
		$scope  = __( 'Gesamtes Sortiment', 'blocksocial-woocommerce-sync' );
		if ( 'categories' === $p['scope'] && ! empty( $p['categories'] ) ) {
			$names = array();
			foreach ( $p['categories'] as $tid ) {
				$t = get_term( (int) $tid, 'product_cat' );
				if ( $t && ! is_wp_error( $t ) ) {
					$names[] = $t->name;
				}
			}
			$scope = sprintf(
				/* translators: %s: Kategorien */
				__( 'Nur Kategorien: %s', 'blocksocial-woocommerce-sync' ),
				implode( ', ', $names )
			);
		}

		return array_merge(
			$policy,
			array(
				'partner_name' => $p['name'],
				'master_name'  => (string) WCIS_Settings::get( 'this_shop_name', get_bloginfo( 'name' ) ),
				'master_url'   => untrailingslashit( WCIS_Settings::this_url() ),
				'scope_label'  => $scope,
				'updated_at'   => time(),
			)
		);
	}

	/**
	 * Gehört ein Produkt zum Sortiment eines Partners?
	 *
	 * @param array      $p       Partner.
	 * @param WC_Product $product Produkt oder Variation.
	 * @return bool
	 */
	public static function allows_product( array $p, $product ) {
		if ( 'categories' !== $p['scope'] || empty( $p['categories'] ) ) {
			return true;
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$pid = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$cats = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $cats ) || empty( $cats ) ) {
			return false;
		}
		$allowed = array_map( 'intval', $p['categories'] );
		foreach ( $cats as $cid ) {
			if ( in_array( (int) $cid, $allowed, true ) ) {
				return true;
			}
			foreach ( get_ancestors( (int) $cid, 'product_cat', 'taxonomy' ) as $anc ) {
				if ( in_array( (int) $anc, $allowed, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Filtert Bestands-Items auf das Sortiment des Ziel-Shops (nur Partner mit
	 * eingeschränktem Sortiment; alle anderen Ziele erhalten alles).
	 *
	 * @param string $url   Ziel-URL.
	 * @param array  $items Items.
	 * @return array
	 */
	public static function filter_items_for_url( $url, array $items ) {
		if ( ! WCIS_Edition::is_admin_edition() ) {
			return $items;
		}
		$p = self::find_by_url( $url );
		if ( ! $p || 'categories' !== $p['scope'] || empty( $p['categories'] ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $item ) {
			$pid = ! empty( $item['sku'] ) ? wc_get_product_id_by_sku( $item['sku'] ) : 0;
			$prd = $pid ? wc_get_product( $pid ) : null;
			if ( $prd && self::allows_product( $p, $prd ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Darf ein Produkt an diese Ziel-URL übertragen werden?
	 *
	 * @param string     $url     Ziel-URL.
	 * @param WC_Product $product Produkt.
	 * @return bool
	 */
	public static function url_allows_product( $url, $product ) {
		if ( ! WCIS_Edition::is_admin_edition() ) {
			return true;
		}
		$p = self::find_by_url( $url );
		return $p ? self::allows_product( $p, $product ) : true;
	}

	// -------------------------------------------------------------------------
	// Partner-Plugin: Vorgaben vom Hauptshop holen
	// -------------------------------------------------------------------------

	/**
	 * Meldet diesen Partnershop beim Hauptshop an und übernimmt die Vorgaben.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh_from_master() {
		if ( ! WCIS_Edition::is_partner() ) {
			return true;
		}
		$conn = WCIS_Settings::partner_conn();
		if ( ! $conn ) {
			return new WP_Error( 'wcis_not_connected', __( 'Nicht mit einem Hauptshop verbunden.', 'blocksocial-woocommerce-sync' ) );
		}
		$res = WCIS_Client::get( $conn['master_url'], '/partner/hello' );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'wcis_master_unreachable', sprintf( __( 'Hauptshop nicht erreichbar: %s', 'blocksocial-woocommerce-sync' ), $res->get_error_message() ) );
		}
		if ( 401 === $res['code'] ) {
			return new WP_Error( 'wcis_bad_code', __( 'Der Hauptshop lehnt den Verbindungscode ab (ungültig, gesperrt oder neu erzeugt). Bitte beim Administrator einen aktuellen Code anfordern.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 200 !== $res['code'] ) {
			return new WP_Error( 'wcis_master_error', sprintf( __( 'Hauptshop antwortete mit HTTP %d. Ist dort das BlockSocial-Admin-Plugin (ab v3.0) aktiv?', 'blocksocial-woocommerce-sync' ), $res['code'] ) );
		}
		$data = json_decode( $res['body'], true );
		if ( ! is_array( $data ) || empty( $data['managed'] ) || ! is_array( $data['managed'] ) ) {
			return new WP_Error( 'wcis_master_error', __( 'Unerwartete Antwort vom Hauptshop.', 'blocksocial-woocommerce-sync' ) );
		}
		WCIS_REST_Controller::store_managed( $data['managed'] );
		return true;
	}
}
