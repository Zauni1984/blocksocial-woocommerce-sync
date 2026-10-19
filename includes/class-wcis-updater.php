<?php
/**
 * Automatische Updates über den Lizenzserver (blocksocial.eu).
 *
 * Die Plugin-Versionen kommen aus GitHub-Releases; der Lizenzserver liefert die
 * ZIPs nur an lizenzierte Shops aus. WordPress zeigt Updates wie gewohnt unter
 * Dashboard → Aktualisierungen an (inkl. automatischer Aktualisierung).
 *
 *  - Hauptshop: fragt mit Lizenzschlüssel.
 *  - Neben- und Partnershops: fragen mit der Adresse ihres Hauptshops – sie sind
 *    über dessen Lizenz abgedeckt.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update-Client.
 */
class WCIS_Updater {

	const CACHE = 'wcis_update_info';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Plugin-Slug dieser Edition.
	 *
	 * @return string
	 */
	public static function slug() {
		return WCIS_Edition::is_partner() ? 'blocksocial-woocommerce-sync-partner' : 'blocksocial-woocommerce-sync';
	}

	/**
	 * Cache leeren (nach Lizenzänderung/Update).
	 */
	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Hauptshop-Adresse aus Sicht dieses Shops.
	 *
	 * @return string
	 */
	protected static function master_url() {
		if ( WCIS_Edition::is_partner() ) {
			$c = WCIS_Settings::partner_conn();
			return $c ? (string) $c['master_url'] : '';
		}
		return (string) WCIS_Settings::get( 'master_url', '' );
	}

	/**
	 * Update-Info vom Lizenzserver (zwischengespeichert, 6 Stunden).
	 *
	 * @param bool $force Cache ignorieren.
	 * @return array|null
	 */
	public static function info( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$args = array(
			'product' => self::slug(),
			'site'    => WCIS_Settings::this_url(),
			'version' => WCIS_VERSION,
		);
		if ( 'master' === WCIS_License::role() ) {
			$args['license_key'] = WCIS_License::data()['key'];
		} else {
			$args['master'] = self::master_url();
		}
		$res = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), WCIS_License::server() . '/info' ), array( 'timeout' => 15 ) );
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		}
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			set_site_transient( self::CACHE, array(), HOUR_IN_SECONDS );
			return null;
		}
		set_site_transient( self::CACHE, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Trägt ein verfügbares Update in den WordPress-Update-Transient ein.
	 *
	 * @param object $transient Transient.
	 * @return object
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::info();
		if ( empty( $info['version'] ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'            => self::slug(),
			'slug'          => self::slug(),
			'plugin'        => WCIS_BASENAME,
			'new_version'   => (string) $info['version'],
			'url'           => isset( $info['homepage'] ) ? (string) $info['homepage'] : '',
			'package'       => ! empty( $info['download_url'] ) ? (string) $info['download_url'] : '',
			'tested'        => isset( $info['tested'] ) ? (string) $info['tested'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);
		if ( version_compare( $info['version'], WCIS_VERSION, '>' ) ) {
			if ( empty( $item->package ) ) {
				// Ohne gültige Lizenz wird das Update angezeigt, aber nicht geladen.
				$item->upgrade_notice = __( 'Für dieses Update ist eine aktive Lizenz nötig (BlockSocial Sync → Lizenz).', 'blocksocial-woocommerce-sync' );
			}
			$transient->response[ WCIS_BASENAME ] = $item;
		} else {
			$transient->no_update[ WCIS_BASENAME ] = $item;
		}
		return $transient;
	}

	/**
	 * Detail-Popup („Details ansehen") im Plugin-Bereich.
	 *
	 * @param false|object|array $result Ergebnis.
	 * @param string             $action Aktion.
	 * @param object             $args   Argumente.
	 * @return false|object
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$info = self::info( true );
		if ( empty( $info['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => WCIS_Edition::is_partner() ? 'BlockSocial WooCommerce Sync – Partner' : 'BlockSocial WooCommerce Sync',
			'slug'          => self::slug(),
			'version'       => (string) $info['version'],
			'author'        => '<a href="https://blocksocial.eu">BlockSocial</a>',
			'homepage'      => isset( $info['homepage'] ) ? (string) $info['homepage'] : '',
			'requires'      => isset( $info['requires'] ) ? (string) $info['requires'] : '',
			'tested'        => isset( $info['tested'] ) ? (string) $info['tested'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
			'last_updated'  => isset( $info['last_updated'] ) ? (string) $info['last_updated'] : '',
			'download_link' => ! empty( $info['download_url'] ) ? (string) $info['download_url'] : '',
			'sections'      => array(
				'changelog' => isset( $info['changelog'] ) && '' !== $info['changelog'] ? wp_kses_post( $info['changelog'] ) : __( 'Siehe blocksocial.eu', 'blocksocial-woocommerce-sync' ),
			),
		);
	}
}
