<?php
/**
 * Lizenzen: Anlegen, Lesen, Verlängern, Upgraden, Plätze (verbundene Shops).
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lizenz-Datenmodell.
 */
class BSLS_Licenses {

	/** Zeichen für Lizenzschlüssel (ohne verwechselbare Zeichen wie 0/O, 1/I). */
	const KEY_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Produktfamilien: welche Plugin-Slugs gehören zu welcher Lizenzfamilie?
	 *
	 * @return array family => [ 'label' => .., 'prefix' => .., 'slugs' => [..] ]
	 */
	public static function families() {
		return apply_filters(
			'bsls_families',
			array(
				'blocksocial-sync' => array(
					'label'  => 'BlockSocial WooCommerce Sync',
					'prefix' => 'BSWS',
					'slugs'  => array( 'blocksocial-woocommerce-sync', 'blocksocial-woocommerce-sync-partner' ),
				),
			)
		);
	}

	/**
	 * Familie zu einem Plugin-Slug.
	 *
	 * @param string $slug Plugin-Slug.
	 * @return string Familie oder ''.
	 */
	public static function family_for_slug( $slug ) {
		foreach ( self::families() as $family => $f ) {
			if ( in_array( $slug, $f['slugs'], true ) ) {
				return $family;
			}
		}
		return '';
	}

	/**
	 * Normalisiert eine Shop-Adresse: ohne Schema, ohne „www.", ohne Slash am Ende,
	 * Host kleingeschrieben – „https://www.Hanfjack.de/" → „hanfjack.de".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_site( $url ) {
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
		if ( 'shopify' === strtolower( isset( $p['scheme'] ) ? $p['scheme'] : '' ) ) {
			$host = 'shopify:' . $host;
		}
		return substr( $host . $path, 0, 190 );
	}

	/**
	 * Erzeugt einen neuen, eindeutigen Lizenzschlüssel.
	 *
	 * @param string $prefix Präfix (z. B. BSWS).
	 * @return string
	 */
	public static function generate_key( $prefix = 'BSWS' ) {
		do {
			$groups = array();
			for ( $g = 0; $g < 4; $g++ ) {
				$part = '';
				for ( $i = 0; $i < 5; $i++ ) {
					$part .= self::KEY_CHARS[ random_int( 0, strlen( self::KEY_CHARS ) - 1 ) ];
				}
				$groups[] = $part;
			}
			$key = strtoupper( $prefix ) . '-' . implode( '-', $groups );
		} while ( self::get_by_key( $key ) );
		return $key;
	}

	/**
	 * Legt eine Lizenz an.
	 *
	 * @param array $data Felder (family, tier, sites, months, order_id, order_item_id, product_id, user_id, email, notes).
	 * @return array|null Lizenz.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$family   = isset( $data['family'] ) ? (string) $data['family'] : 'blocksocial-sync';
		$families = self::families();
		$prefix   = isset( $families[ $family ]['prefix'] ) ? $families[ $family ]['prefix'] : 'BSLS';
		$months   = isset( $data['months'] ) ? (int) $data['months'] : 12;
		$now      = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			BSLS_Install::licenses_table(),
			array(
				'license_key'   => self::generate_key( $prefix ),
				'family'        => $family,
				'tier'          => isset( $data['tier'] ) ? (string) $data['tier'] : '',
				'sites'         => isset( $data['sites'] ) ? max( 0, (int) $data['sites'] ) : 3,
				'status'        => 'active',
				'expires_at'    => $months > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . $months . ' months' ) ) : null,
				'order_id'      => isset( $data['order_id'] ) ? (int) $data['order_id'] : 0,
				'order_item_id' => isset( $data['order_item_id'] ) ? (int) $data['order_item_id'] : 0,
				'product_id'    => isset( $data['product_id'] ) ? (int) $data['product_id'] : 0,
				'user_id'       => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'email'         => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
				'master_site'   => '',
				'notes'         => isset( $data['notes'] ) ? (string) $data['notes'] : '',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Lizenz per ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BSLS_Install::licenses_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Lizenz per Schlüssel.
	 *
	 * @param string $key Schlüssel.
	 * @return array|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;
		$key = strtoupper( trim( (string) $key ) );
		if ( '' === $key ) {
			return null;
		}
		$t = BSLS_Install::licenses_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE license_key = %s", $key ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Aktive Lizenz einer Familie, die an diesen Hauptshop gebunden ist.
	 *
	 * @param string $master_site Normalisierte Adresse des Hauptshops.
	 * @param string $family      Familie.
	 * @return array|null
	 */
	public static function get_by_master( $master_site, $family ) {
		global $wpdb;
		if ( '' === $master_site ) {
			return null;
		}
		$t = BSLS_Install::licenses_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE master_site = %s AND family = %s ORDER BY status = 'active' DESC, id DESC LIMIT 1", $master_site, $family ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Lizenzen eines Kunden.
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$t = BSLS_Install::licenses_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC", (int) $user_id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Lizenzen einer Bestellung.
	 *
	 * @param int $order_id Bestell-ID.
	 * @return array
	 */
	public static function for_order( $order_id ) {
		global $wpdb;
		$t = BSLS_Install::licenses_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id ASC", (int) $order_id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Suche (Admin).
	 *
	 * @param string $search Suchbegriff (Schlüssel, E-Mail, Shop).
	 * @param int    $limit  Anzahl.
	 * @param int    $offset Versatz.
	 * @return array
	 */
	public static function search( $search = '', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t = BSLS_Install::licenses_table();
		if ( '' === $search ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE license_key LIKE %s OR email LIKE %s OR master_site LIKE %s OR order_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $like, $like, $like, (int) $search, $limit, $offset ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Aktualisiert Felder einer Lizenz.
	 *
	 * @param int   $id     ID.
	 * @param array $fields Felder.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( BSLS_Install::licenses_table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Verlängert eine Lizenz um n Monate (ab Ablaufdatum bzw. ab heute, falls abgelaufen).
	 *
	 * @param int $id     ID.
	 * @param int $months Monate.
	 */
	public static function extend( $id, $months ) {
		$lic = self::get( $id );
		if ( ! $lic || $months <= 0 || null === $lic['expires_at'] ) {
			return;
		}
		$base = max( time(), strtotime( $lic['expires_at'] . ' UTC' ) );
		self::update(
			$id,
			array(
				'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+' . (int) $months . ' months', $base ) ),
				'status'     => 'revoked' === $lic['status'] ? 'revoked' : 'active',
			)
		);
	}

	/**
	 * Effektiver Status: active | expired | revoked | disabled.
	 *
	 * @param array $lic Lizenz.
	 * @return string
	 */
	public static function status( array $lic ) {
		if ( 'active' !== $lic['status'] ) {
			return $lic['status'];
		}
		if ( ! empty( $lic['expires_at'] ) && strtotime( $lic['expires_at'] . ' UTC' ) < time() ) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * Lesbarer Status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'active'   => __( 'Aktiv', 'blocksocial-license-server' ),
			'expired'  => __( 'Abgelaufen (Sync läuft weiter, keine Updates)', 'blocksocial-license-server' ),
			'revoked'  => __( 'Widerrufen', 'blocksocial-license-server' ),
			'disabled' => __( 'Gesperrt', 'blocksocial-license-server' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Plätze lesbar („3", „unbegrenzt").
	 *
	 * @param int $sites Plätze (0 = unbegrenzt).
	 * @return string
	 */
	public static function sites_label( $sites ) {
		return 0 === (int) $sites ? __( 'unbegrenzt', 'blocksocial-license-server' ) : (string) (int) $sites;
	}

	// -------------------------------------------------------------------------
	// Plätze (verbundene Shops)
	// -------------------------------------------------------------------------

	/**
	 * Shops einer Lizenz.
	 *
	 * @param int $license_id Lizenz-ID.
	 * @return array
	 */
	public static function sites( $license_id ) {
		global $wpdb;
		$t = BSLS_Install::sites_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE license_id = %d ORDER BY role = 'master' DESC, id ASC", (int) $license_id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Gleicht die Shop-Liste einer Lizenz ab. Der Hauptshop belegt immer den
	 * ersten Platz; weitere Shops werden in der übermittelten Reihenfolge bis zur
	 * Platzzahl abgedeckt.
	 *
	 * @param array $lic   Lizenz.
	 * @param array $shops Liste [ [ 'url' => .., 'role' => .. ], ... ] (ohne Hauptshop).
	 * @return array Normalisierte Adressen der abgedeckten Shops (inkl. Hauptshop).
	 */
	public static function sync_sites( array $lic, array $shops ) {
		global $wpdb;
		$t       = BSLS_Install::sites_table();
		$now     = current_time( 'mysql', true );
		$limit   = (int) $lic['sites'];
		$master  = (string) $lic['master_site'];
		$covered = array( $master );
		$keep    = array( $master );

		self::upsert_site( (int) $lic['id'], $master, 'master', true, $now );

		$i = 1;
		foreach ( $shops as $shop ) {
			$site = self::normalize_site( isset( $shop['url'] ) ? $shop['url'] : '' );
			if ( '' === $site || in_array( $site, $keep, true ) ) {
				continue;
			}
			$role = isset( $shop['role'] ) ? sanitize_key( $shop['role'] ) : 'shop';
			$ok   = 0 === $limit || $i < $limit;
			self::upsert_site( (int) $lic['id'], $site, $role, $ok, $now );
			$keep[] = $site;
			if ( $ok ) {
				$covered[] = $site;
			}
			$i++;
		}

		// Nicht mehr gemeldete Shops entfernen.
		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE license_id = %d AND site NOT IN ({$placeholders})", array_merge( array( (int) $lic['id'] ), $keep ) ) ); // phpcs:ignore

		return $covered;
	}

	/**
	 * Legt einen Shop-Eintrag an bzw. aktualisiert ihn.
	 *
	 * @param int    $license_id Lizenz.
	 * @param string $site       Adresse.
	 * @param string $role       Rolle.
	 * @param bool   $covered    Abgedeckt?
	 * @param string $now        Zeitpunkt.
	 */
	protected static function upsert_site( $license_id, $site, $role, $covered, $now ) {
		global $wpdb;
		$t  = BSLS_Install::sites_table();
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE license_id = %d AND site = %s", $license_id, $site ) ); // phpcs:ignore
		if ( $id ) {
			$wpdb->update( $t, array( 'role' => $role, 'covered' => $covered ? 1 : 0, 'last_seen' => $now ), array( 'id' => (int) $id ) ); // phpcs:ignore
			return;
		}
		$wpdb->insert( // phpcs:ignore
			$t,
			array(
				'license_id' => $license_id,
				'site'       => $site,
				'role'       => $role,
				'covered'    => $covered ? 1 : 0,
				'first_seen' => $now,
				'last_seen'  => $now,
			)
		);
	}

	/**
	 * Ist ein Shop für eine Lizenz registriert und abgedeckt?
	 *
	 * @param int    $license_id Lizenz.
	 * @param string $site       Normalisierte Adresse.
	 * @return bool
	 */
	public static function site_covered( $license_id, $site ) {
		global $wpdb;
		$t = BSLS_Install::sites_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT covered FROM {$t} WHERE license_id = %d AND site = %s", (int) $license_id, $site ) ); // phpcs:ignore
	}

	/**
	 * Gibt den Hauptshop frei (Umzug auf eine andere Domain).
	 *
	 * @param int $license_id Lizenz.
	 */
	public static function release_master( $license_id ) {
		global $wpdb;
		self::update( $license_id, array( 'master_site' => '' ) );
		$wpdb->delete( BSLS_Install::sites_table(), array( 'license_id' => (int) $license_id ) ); // phpcs:ignore
	}

	/**
	 * Antwort-Daten einer Lizenz für die Plugins.
	 *
	 * @param array $lic     Lizenz.
	 * @param array $covered Abgedeckte Shops.
	 * @return array
	 */
	public static function to_api( array $lic, array $covered = array() ) {
		$status = self::status( $lic );
		return array(
			'status'       => $status,
			'status_label' => self::status_label( $status ),
			'tier'         => (string) $lic['tier'],
			'sites'        => (int) $lic['sites'],
			'expires'      => empty( $lic['expires_at'] ) ? null : gmdate( 'c', strtotime( $lic['expires_at'] . ' UTC' ) ),
			'master_site'  => (string) $lic['master_site'],
			'covered'      => array_values( $covered ),
			'account_url'  => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( BSLS_Account::ENDPOINT ) : home_url( '/' ),
		);
	}
}
