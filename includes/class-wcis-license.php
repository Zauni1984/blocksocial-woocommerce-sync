<?php
/**
 * Lizenz: Aktivierung beim Lizenzserver (blocksocial.eu), Plätze, Testphase.
 *
 * Modell:
 *  - Die Lizenz gehört dem Hauptshop. Nur dort wird der Schlüssel eingetragen.
 *  - Plätze = verbundene Shops: Hauptshop + eigene Shops + Partnershops +
 *    Shopify-Shops. Neben- und Partnershops brauchen keinen eigenen Schlüssel.
 *  - Sind mehr Shops verbunden als Plätze vorhanden, werden die zuletzt
 *    hinzugefügten nicht mehr beliefert (Reihenfolge: eigene Shops, Partner,
 *    Shopify).
 *  - 14 Tage Testphase ohne Schlüssel mit vollem Funktionsumfang.
 *  - Abgelaufene Lizenz: Synchronisation läuft weiter (kein Überverkaufs-Risiko),
 *    aber keine Updates und kein Support.
 *  - Ohne Lizenz nach der Testphase pausiert die Synchronisation.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lizenz-Client.
 */
class WCIS_License {

	const OPT        = 'wcis_license';
	const TRIAL_OPT  = 'wcis_trial_start';
	const MASTER_OPT = 'wcis_master_license';
	const TRIAL_DAYS = 14;
	const CRON       = 'wcis_license_daily';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		if ( ! get_option( self::TRIAL_OPT ) ) {
			update_option( self::TRIAL_OPT, time(), false );
		}
	}

	/**
	 * Basis-URL der Lizenz-API.
	 *
	 * @return string
	 */
	public static function server() {
		$url = defined( 'WCIS_LICENSE_SERVER' ) ? WCIS_LICENSE_SERVER : 'https://blocksocial.eu/wp-json/bsls/v1';
		return untrailingslashit( (string) apply_filters( 'wcis_license_server', $url ) );
	}

	/**
	 * Shop-Seite zum Kaufen/Verlängern.
	 *
	 * @return string
	 */
	public static function shop_url() {
		return (string) apply_filters( 'wcis_license_shop_url', 'https://blocksocial.eu/produkt/blocksocial-woocommerce-sync/' );
	}

	/**
	 * Normalisierte Shop-Adresse (identisch zum Lizenzserver).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$host = strtolower( $p['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		if ( ! empty( $p['port'] ) ) {
			$host .= ':' . (int) $p['port'];
		}
		$path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
		if ( isset( $p['scheme'] ) && 'shopify' === strtolower( $p['scheme'] ) ) {
			$host = 'shopify:' . $host;
		}
		return substr( $host . $path, 0, 190 );
	}

	// -------------------------------------------------------------------------
	// Zustand
	// -------------------------------------------------------------------------

	/**
	 * Rolle dieses Shops: 'master' (Hauptshop bzw. ohne Netzwerk),
	 * 'member' (eigener Neben-Shop), 'partner' (Partner-Plugin).
	 *
	 * @return string
	 */
	public static function role() {
		if ( WCIS_Edition::is_partner() ) {
			return 'partner';
		}
		$master = (string) WCIS_Settings::get( 'master_url', '' );
		return ( '' === $master || WCIS_Settings::is_master() ) ? 'master' : 'member';
	}

	/**
	 * Gespeicherte Lizenzdaten.
	 *
	 * @return array
	 */
	public static function data() {
		return wp_parse_args(
			(array) get_option( self::OPT, array() ),
			array(
				'key'         => '',
				'status'      => 'none',
				'tier'        => '',
				'sites'       => 0,
				'expires'     => null,
				'covered'     => array(),
				'checked_at'  => 0,
				'message'     => '',
				'account_url' => '',
			)
		);
	}

	/**
	 * Speichert Lizenzdaten.
	 *
	 * @param array $data Daten.
	 */
	protected static function save( array $data ) {
		update_option( self::OPT, array_merge( self::data(), $data ), false );
	}

	/**
	 * Verbleibende Tage der Testphase.
	 *
	 * @return int
	 */
	public static function trial_days_left() {
		$start = (int) get_option( self::TRIAL_OPT, time() );
		$days  = (int) apply_filters( 'wcis_trial_days', self::TRIAL_DAYS );
		return max( 0, (int) ceil( ( $start + $days * DAY_IN_SECONDS - time() ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Lizenzzustand des Hauptshops: 'licensed' (aktiv/abgelaufen), 'trial', 'unlicensed'.
	 *
	 * @return string
	 */
	public static function state() {
		$d = self::data();
		if ( '' !== $d['key'] && in_array( $d['status'], array( 'active', 'expired' ), true ) ) {
			return 'licensed';
		}
		return self::trial_days_left() > 0 ? 'trial' : 'unlicensed';
	}

	/**
	 * Verbundene Shops in Platz-Reihenfolge (ohne Hauptshop).
	 *
	 * @return array Liste [ url, role, name ]
	 */
	public static function seat_list() {
		$out  = array();
		$seen = array( self::normalize( WCIS_Settings::this_url() ) => true );
		foreach ( WCIS_Settings::get_peers() as $p ) {
			$n = self::normalize( $p['url'] );
			if ( '' === $n || isset( $seen[ $n ] ) ) {
				continue;
			}
			$seen[ $n ] = true;
			$out[]      = array( 'url' => $p['url'], 'role' => $p['type'], 'name' => $p['name'] );
		}
		if ( class_exists( 'WCIS_Shopify' ) && WCIS_Edition::is_admin_edition() ) {
			// Jeder eingeschaltete Shopify-Shop belegt einen Platz – schon vor dem
			// ersten Verbindungstest (sonst wäre der Test selbst blockiert).
			foreach ( WCIS_Shopify::all() as $st ) {
				if ( empty( $st['active'] ) || empty( $st['domain'] ) ) {
					continue;
				}
				$url = 'shopify://' . $st['domain'];
				$n   = self::normalize( $url );
				if ( isset( $seen[ $n ] ) ) {
					continue;
				}
				$seen[ $n ] = true;
				$out[]      = array( 'url' => $url, 'role' => 'shopify', 'name' => $st['domain'] );
			}
		}
		return $out;
	}

	/**
	 * Anzahl Plätze (inkl. Hauptshop); 0 = unbegrenzt.
	 *
	 * @return int
	 */
	public static function seats() {
		return 'licensed' === self::state() ? (int) self::data()['sites'] : 0;
	}

	/**
	 * Normalisierte Adressen der abgedeckten Shops (ohne Hauptshop).
	 *
	 * @return array normalisierte URL => true
	 */
	public static function covered() {
		$state = self::state();
		if ( 'unlicensed' === $state ) {
			return array();
		}
		$limit = self::seats();
		$out   = array();
		$i     = 1;
		foreach ( self::seat_list() as $s ) {
			if ( 0 !== $limit && $i >= $limit ) {
				break;
			}
			$out[ self::normalize( $s['url'] ) ] = true;
			$i++;
		}
		return $out;
	}

	/**
	 * Ist ein verbundener Shop durch die Lizenz abgedeckt (aus Sicht des Hauptshops)?
	 *
	 * @param string $url Adresse.
	 * @return bool
	 */
	public static function is_covered( $url ) {
		$covered = self::covered();
		return isset( $covered[ self::normalize( $url ) ] );
	}

	/**
	 * Darf an dieses Ziel gesendet werden? Liefert sonst einen Fehler.
	 *
	 * @param string $url      Ziel-Adresse.
	 * @param string $endpoint Endpunkt (Lizenzabfrage per /ping ist immer erlaubt).
	 * @return WP_Error|null
	 */
	public static function outbound_block( $url, $endpoint = '' ) {
		if ( '/ping' === $endpoint ) {
			return null;
		}
		$role = self::role();
		if ( 'partner' === $role ) {
			return null; // Der Hauptshop entscheidet.
		}
		if ( 'member' === $role ) {
			$m = (array) get_option( self::MASTER_OPT, array() );
			if ( isset( $m['covered'] ) && false === $m['covered'] ) {
				return new WP_Error( 'wcis_license', __( 'Dieser Shop ist durch die Lizenz des Hauptshops nicht abgedeckt – Synchronisation pausiert.', 'blocksocial-woocommerce-sync' ) );
			}
			return null;
		}
		if ( self::is_covered( $url ) ) {
			return null;
		}
		if ( 'unlicensed' === self::state() ) {
			return new WP_Error( 'wcis_license', __( 'Keine gültige Lizenz – Synchronisation pausiert. Bitte unter BlockSocial Sync → Lizenz einen Lizenzschlüssel eintragen.', 'blocksocial-woocommerce-sync' ) );
		}
		return new WP_Error( 'wcis_license', __( 'Alle Plätze der Lizenz sind belegt – dieser Shop wird nicht beliefert. Upgrade unter BlockSocial Sync → Lizenz.', 'blocksocial-woocommerce-sync' ) );
	}

	/**
	 * Darf ein eingehender Absender (Neben-/Partnershop) bedient werden?
	 *
	 * @param array  $caller Absender (type, url).
	 * @param string $route  Route.
	 * @return WP_Error|null
	 */
	public static function inbound_block( $caller, $route ) {
		if ( 'master' !== self::role() || ! is_array( $caller ) || 'master' === $caller['type'] ) {
			return null;
		}
		if ( false !== strpos( (string) $route, '/ping' ) || empty( $caller['url'] ) ) {
			return null;
		}
		if ( self::is_covered( $caller['url'] ) ) {
			return null;
		}
		return new WP_Error( 'wcis_license', __( 'Dieser Shop ist durch die Lizenz des Hauptshops nicht abgedeckt.', 'blocksocial-woocommerce-sync' ), array( 'status' => 403 ) );
	}

	/**
	 * Darf der Hauptshop überhaupt synchronisieren (CSV-Feeds u. ä.)?
	 *
	 * @return bool
	 */
	public static function sync_allowed() {
		return 'master' !== self::role() || 'unlicensed' !== self::state();
	}

	/**
	 * Lizenz-Info für /ping (Neben-Shops erfahren so, ob sie abgedeckt sind).
	 *
	 * @param string $from Adresse des Anfragenden.
	 * @return array
	 */
	public static function ping_info( $from ) {
		if ( 'master' !== self::role() ) {
			return array();
		}
		return array(
			'state'   => self::state(),
			'tier'    => self::data()['tier'],
			'covered' => '' === $from ? null : self::is_covered( $from ),
		);
	}

	// -------------------------------------------------------------------------
	// Lizenzserver
	// -------------------------------------------------------------------------

	/**
	 * Aufruf der Lizenz-API.
	 *
	 * @param string $endpoint Endpunkt.
	 * @param array  $body     Daten.
	 * @return array|WP_Error Antwort-JSON.
	 */
	protected static function api( $endpoint, array $body ) {
		$res = wp_remote_post(
			self::server() . $endpoint,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wcis_license_server', sprintf( __( 'Lizenzserver nicht erreichbar (HTTP %d).', 'blocksocial-woocommerce-sync' ), (int) wp_remote_retrieve_response_code( $res ) ) );
		}
		return $data;
	}

	/**
	 * Shop-Liste für den Server.
	 *
	 * @return array
	 */
	protected static function shops_payload() {
		return array_map(
			static function ( $s ) {
				return array( 'url' => $s['url'], 'role' => $s['role'] );
			},
			self::seat_list()
		);
	}

	/**
	 * Übernimmt die Lizenzdaten aus einer Server-Antwort.
	 *
	 * @param array  $lic Lizenzdaten.
	 * @param string $key Schlüssel.
	 */
	protected static function store_license( array $lic, $key ) {
		self::save(
			array(
				'key'         => $key,
				'status'      => isset( $lic['status'] ) ? sanitize_key( $lic['status'] ) : 'none',
				'tier'        => isset( $lic['tier'] ) ? sanitize_text_field( $lic['tier'] ) : '',
				'sites'       => isset( $lic['sites'] ) ? (int) $lic['sites'] : 0,
				'expires'     => isset( $lic['expires'] ) ? sanitize_text_field( (string) $lic['expires'] ) : null,
				'covered'     => isset( $lic['covered'] ) ? array_map( 'sanitize_text_field', (array) $lic['covered'] ) : array(),
				'account_url' => isset( $lic['account_url'] ) ? esc_url_raw( $lic['account_url'] ) : '',
				'checked_at'  => time(),
				'message'     => '',
			)
		);
		WCIS_Updater::flush();
	}

	/**
	 * Aktiviert einen Lizenzschlüssel für diesen Hauptshop.
	 *
	 * @param string $key Schlüssel.
	 * @return true|WP_Error
	 */
	public static function activate( $key ) {
		$key = strtoupper( trim( sanitize_text_field( $key ) ) );
		if ( '' === $key ) {
			return new WP_Error( 'wcis_license', __( 'Bitte einen Lizenzschlüssel eingeben.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'master' !== self::role() ) {
			return new WP_Error( 'wcis_license', __( 'Die Lizenz wird nur im Hauptshop eingetragen. Neben- und Partnershops sind darüber abgedeckt.', 'blocksocial-woocommerce-sync' ) );
		}
		$res = self::api(
			'/activate',
			array(
				'license_key' => $key,
				'site'        => WCIS_Settings::this_url(),
				'product'     => WCIS_Updater::slug(),
				'version'     => WCIS_VERSION,
				'shops'       => self::shops_payload(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['ok'] ) ) {
			return new WP_Error( 'wcis_license', isset( $res['message'] ) ? sanitize_text_field( $res['message'] ) : __( 'Aktivierung fehlgeschlagen.', 'blocksocial-woocommerce-sync' ) );
		}
		self::store_license( (array) $res['license'], $key );
		WCIS_Logger::info( sprintf( 'Lizenz aktiviert: %s (%s).', self::data()['tier'], self::mask( $key ) ) );
		return true;
	}

	/**
	 * Deaktiviert die Lizenz (z. B. für einen Umzug).
	 *
	 * @return true
	 */
	public static function deactivate() {
		$d = self::data();
		if ( '' !== $d['key'] ) {
			self::api( '/deactivate', array( 'license_key' => $d['key'], 'site' => WCIS_Settings::this_url() ) );
		}
		delete_option( self::OPT );
		WCIS_Updater::flush();
		return true;
	}

	/**
	 * Prüft die Lizenz beim Server und meldet die verbundenen Shops.
	 *
	 * @return true|WP_Error
	 */
	public static function check() {
		$d = self::data();
		if ( '' === $d['key'] || 'master' !== self::role() ) {
			return true;
		}
		$res = self::api(
			'/check',
			array(
				'license_key' => $d['key'],
				'site'        => WCIS_Settings::this_url(),
				'version'     => WCIS_VERSION,
				'shops'       => self::shops_payload(),
			)
		);
		if ( is_wp_error( $res ) ) {
			// Server nicht erreichbar: letzten Stand behalten (kein Sync-Stopp).
			self::save( array( 'message' => $res->get_error_message() ) );
			return $res;
		}
		if ( ! empty( $res['ok'] ) ) {
			self::store_license( (array) $res['license'], $d['key'] );
			return true;
		}
		$code = isset( $res['code'] ) ? (string) $res['code'] : '';
		if ( in_array( $code, array( 'invalid_key', 'revoked', 'disabled', 'not_activated' ), true ) ) {
			self::save(
				array(
					'status'     => 'not_activated' === $code ? 'invalid' : ( 'invalid_key' === $code ? 'invalid' : $code ),
					'checked_at' => time(),
					'message'    => isset( $res['message'] ) ? sanitize_text_field( $res['message'] ) : '',
				)
			);
		}
		return new WP_Error( 'wcis_license', isset( $res['message'] ) ? sanitize_text_field( $res['message'] ) : $code );
	}

	/**
	 * Täglich: Hauptshop prüft beim Server, Neben-Shops fragen den Hauptshop.
	 */
	public static function daily() {
		if ( 'master' === self::role() ) {
			self::check();
		} elseif ( 'member' === self::role() ) {
			self::refresh_from_master();
		}
	}

	/**
	 * Neben-Shop: Lizenzstatus beim Hauptshop abfragen (über /ping).
	 */
	public static function refresh_from_master() {
		$master = (string) WCIS_Settings::get( 'master_url', '' );
		if ( '' === $master ) {
			return;
		}
		$res = WCIS_Client::get( $master, '/ping' );
		if ( is_wp_error( $res ) || 200 !== (int) $res['code'] ) {
			return; // Hauptshop nicht erreichbar: letzten Stand behalten.
		}
		$data = json_decode( $res['body'], true );
		if ( is_array( $data ) && isset( $data['license'] ) && is_array( $data['license'] ) ) {
			update_option(
				self::MASTER_OPT,
				array(
					'state'      => isset( $data['license']['state'] ) ? sanitize_key( $data['license']['state'] ) : '',
					'tier'       => isset( $data['license']['tier'] ) ? sanitize_text_field( $data['license']['tier'] ) : '',
					'covered'    => isset( $data['license']['covered'] ) ? (bool) $data['license']['covered'] : null,
					'checked_at' => time(),
				),
				false
			);
		}
	}

	/**
	 * Schlüssel maskiert (für Protokoll/Anzeige).
	 *
	 * @param string $key Schlüssel.
	 * @return string
	 */
	public static function mask( $key ) {
		return strlen( $key ) > 10 ? substr( $key, 0, 5 ) . str_repeat( '•', 10 ) . substr( $key, -5 ) : $key;
	}

	/**
	 * Kurztext für die Kopfzeile.
	 *
	 * @return array [ css-klasse, text ]
	 */
	public static function badge() {
		$role = self::role();
		if ( 'member' === $role ) {
			$m = (array) get_option( self::MASTER_OPT, array() );
			if ( isset( $m['covered'] ) && false === $m['covered'] ) {
				return array( 'is-warn', __( 'Lizenz: nicht abgedeckt', 'blocksocial-woocommerce-sync' ) );
			}
			return array( '', __( 'Lizenz über Hauptshop', 'blocksocial-woocommerce-sync' ) );
		}
		$state = self::state();
		if ( 'trial' === $state ) {
			return array( 'is-warn', sprintf( _n( 'Testphase: %d Tag', 'Testphase: %d Tage', self::trial_days_left(), 'blocksocial-woocommerce-sync' ), self::trial_days_left() ) );
		}
		if ( 'unlicensed' === $state ) {
			return array( 'is-off', __( 'Keine Lizenz', 'blocksocial-woocommerce-sync' ) );
		}
		$d = self::data();
		return array( 'expired' === $d['status'] ? 'is-warn' : 'is-on', sprintf( __( 'Lizenz: %s', 'blocksocial-woocommerce-sync' ), $d['tier'] ? $d['tier'] : __( 'aktiv', 'blocksocial-woocommerce-sync' ) ) );
	}
}
