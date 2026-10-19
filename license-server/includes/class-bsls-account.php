<?php
/**
 * Kundenkonto: „Mein Konto → Lizenzen" mit Schlüssel, Plätzen, verbundenen
 * Shops, Downloads, Verlängerung, Upgrade und Freigabe des Hauptshops.
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kundenkonto-Bereich.
 */
class BSLS_Account {

	const ENDPOINT = 'lizenzen';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	/**
	 * Endpunkt registrieren.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * WooCommerce-Query-Var.
	 *
	 * @param array $vars Variablen.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menüpunkt vor „Abmelden".
	 *
	 * @param array $items Menü.
	 * @return array
	 */
	public static function menu( $items ) {
		$out = array();
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ self::ENDPOINT ] = __( 'Lizenzen', 'blocksocial-license-server' );
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Lizenzen', 'blocksocial-license-server' );
		}
		return $out;
	}

	/**
	 * Seitentitel.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Lizenzen', 'blocksocial-license-server' );
	}

	/**
	 * Ein paar Stile für die Tabellen.
	 */
	public static function styles() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		wp_register_style( 'bsls-account', false, array(), BSLS_VERSION );
		wp_enqueue_style( 'bsls-account' );
		wp_add_inline_style(
			'bsls-account',
			'.bsls-key{font-family:monospace;font-size:1.05em;letter-spacing:.03em;user-select:all}.bsls-badge{display:inline-block;padding:.1em .55em;border-radius:99px;font-size:.85em;background:#e7f6ec;color:#1e6b35}.bsls-badge.is-expired{background:#fff4e0;color:#8a5300}.bsls-badge.is-revoked,.bsls-badge.is-disabled{background:#fde8e8;color:#9b1c1c}.bsls-box{border:1px solid #e3e3e3;border-radius:8px;padding:1em 1.2em;margin:0 0 1.2em}.bsls-box h3{margin-top:0}.bsls-actions a,.bsls-actions button{margin:0 .4em .4em 0}'
		);
	}

	/**
	 * Lizenzen des angemeldeten Kunden; Gastkäufe mit derselben E-Mail werden
	 * dem Konto zugeordnet.
	 *
	 * @return array
	 */
	protected static function licenses() {
		global $wpdb;
		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return array();
		}
		$t = BSLS_Install::licenses_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET user_id = %d WHERE user_id = 0 AND email = %s", $user->ID, $user->user_email ) ); // phpcs:ignore
		return BSLS_Licenses::for_user( $user->ID );
	}

	/**
	 * Lizenz des Kunden per ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	protected static function own( $id ) {
		$lic = BSLS_Licenses::get( (int) $id );
		return ( $lic && (int) $lic['user_id'] === get_current_user_id() ) ? $lic : null;
	}

	/**
	 * Formular-Aktionen (Hauptshop freigeben).
	 */
	public static function handle_post() {
		if ( empty( $_POST['bsls_release'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$id = (int) $_POST['bsls_release']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'bsls_release_' . $id );
		$lic = self::own( $id );
		if ( $lic ) {
			BSLS_Licenses::release_master( $id );
			wc_add_notice( __( 'Hauptshop freigegeben. Du kannst die Lizenz jetzt in einem anderen Hauptshop aktivieren.', 'blocksocial-license-server' ) );
		}
		wp_safe_redirect( add_query_arg( 'lizenz', $id, wc_get_account_endpoint_url( self::ENDPOINT ) ) );
		exit;
	}

	/**
	 * Ausgabe.
	 */
	public static function render() {
		$detail = isset( $_GET['lizenz'] ) ? self::own( (int) $_GET['lizenz'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $detail ) {
			self::render_detail( $detail );
			return;
		}
		$list = self::licenses();
		if ( empty( $list ) ) {
			echo '<p>' . esc_html__( 'Du hast noch keine Lizenzen.', 'blocksocial-license-server' ) . '</p>';
			return;
		}
		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr>';
		foreach ( array( __( 'Lizenzschlüssel', 'blocksocial-license-server' ), __( 'Stufe', 'blocksocial-license-server' ), __( 'Shops', 'blocksocial-license-server' ), __( 'Hauptshop', 'blocksocial-license-server' ), __( 'Gültig bis', 'blocksocial-license-server' ), __( 'Status', 'blocksocial-license-server' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $list as $lic ) {
			$status = BSLS_Licenses::status( $lic );
			$used   = count( BSLS_Licenses::sites( (int) $lic['id'] ) );
			echo '<tr>';
			echo '<td><span class="bsls-key">' . esc_html( $lic['license_key'] ) . '</span></td>';
			echo '<td>' . esc_html( $lic['tier'] ) . '</td>';
			echo '<td>' . esc_html( $used . ' / ' . BSLS_Licenses::sites_label( $lic['sites'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $lic['master_site'] ? $lic['master_site'] : '–' ) . '</td>';
			echo '<td>' . esc_html( BSLS_Orders::date( $lic['expires_at'] ) ) . '</td>';
			echo '<td><span class="bsls-badge is-' . esc_attr( $status ) . '">' . esc_html( BSLS_Licenses::status_label( $status ) ) . '</span></td>';
			echo '<td><a class="button" href="' . esc_url( add_query_arg( 'lizenz', (int) $lic['id'], wc_get_account_endpoint_url( self::ENDPOINT ) ) ) . '">' . esc_html__( 'Verwalten', 'blocksocial-license-server' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Detailansicht einer Lizenz.
	 *
	 * @param array $lic Lizenz.
	 */
	protected static function render_detail( array $lic ) {
		$status   = BSLS_Licenses::status( $lic );
		$families = BSLS_Licenses::families();
		$family   = isset( $families[ $lic['family'] ] ) ? $families[ $lic['family'] ] : array( 'label' => $lic['family'], 'slugs' => array() );
		$sites    = BSLS_Licenses::sites( (int) $lic['id'] );

		echo '<p><a href="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '">← ' . esc_html__( 'Alle Lizenzen', 'blocksocial-license-server' ) . '</a></p>';

		echo '<div class="bsls-box"><h3>' . esc_html( $family['label'] . ' – ' . $lic['tier'] ) . '</h3>';
		echo '<p>' . esc_html__( 'Lizenzschlüssel', 'blocksocial-license-server' ) . ': <span class="bsls-key">' . esc_html( $lic['license_key'] ) . '</span></p>';
		echo '<p>' . esc_html__( 'Status', 'blocksocial-license-server' ) . ': <span class="bsls-badge is-' . esc_attr( $status ) . '">' . esc_html( BSLS_Licenses::status_label( $status ) ) . '</span><br>';
		echo esc_html__( 'Plätze', 'blocksocial-license-server' ) . ': ' . esc_html( count( $sites ) . ' / ' . BSLS_Licenses::sites_label( $lic['sites'] ) ) . '<br>';
		echo esc_html__( 'Updates & Support bis', 'blocksocial-license-server' ) . ': ' . esc_html( BSLS_Orders::date( $lic['expires_at'] ) ) . '</p>';

		echo '<div class="bsls-actions">';
		if ( ! empty( $lic['expires_at'] ) && 'revoked' !== $status ) {
			echo '<a class="button" href="' . esc_url( BSLS_Orders::action_url( 'renew', $lic['license_key'] ) ) . '">' . esc_html__( 'Verlängern', 'blocksocial-license-server' ) . '</a>';
		}
		foreach ( BSLS_Product::tiers( $lic['family'] ) as $tier ) {
			if ( 'revoked' !== $status && BSLS_Orders::is_bigger( $tier['sites'], (int) $lic['sites'] ) ) {
				$label = 0 === (int) $tier['sites']
					/* translators: %s: Stufe */
					? sprintf( __( 'Upgrade auf %s (unbegrenzt viele Shops)', 'blocksocial-license-server' ), $tier['tier'] )
					/* translators: 1: Stufe, 2: Plätze */
					: sprintf( __( 'Upgrade auf %1$s (bis %2$d Shops)', 'blocksocial-license-server' ), $tier['tier'], (int) $tier['sites'] );
				echo '<a class="button" href="' . esc_url( BSLS_Orders::action_url( 'upgrade', $lic['license_key'], $tier['product_id'] ) ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		echo '</div></div>';

		// Downloads.
		echo '<div class="bsls-box"><h3>' . esc_html__( 'Downloads', 'blocksocial-license-server' ) . '</h3>';
		if ( 'active' !== $status ) {
			echo '<p>' . esc_html__( 'Downloads und Updates sind mit einer aktiven Lizenz verfügbar.', 'blocksocial-license-server' ) . '</p>';
		} else {
			echo '<ul>';
			$any = false;
			foreach ( $family['slugs'] as $slug ) {
				$rel = BSLS_Releases::get( $slug );
				if ( ! $rel ) {
					continue;
				}
				$any = true;
				echo '<li><a href="' . esc_url( BSLS_Releases::download_url( $slug, (int) $lic['id'] ) ) . '">' . esc_html( $slug . ' ' . $rel['version'] ) . '.zip</a></li>';
			}
			echo '</ul>';
			if ( ! $any ) {
				echo '<p>' . esc_html__( 'Derzeit kein Download verfügbar.', 'blocksocial-license-server' ) . '</p>';
			}
			echo '<p><small>' . esc_html__( 'Admin-Plugin für Hauptshop und eigene Shops, Partner-Plugin für Partnershops. Updates kommen danach automatisch über WordPress.', 'blocksocial-license-server' ) . '</small></p>';
		}
		echo '</div>';

		// Verbundene Shops.
		echo '<div class="bsls-box"><h3>' . esc_html__( 'Verbundene Shops', 'blocksocial-license-server' ) . '</h3>';
		if ( empty( $sites ) ) {
			echo '<p>' . esc_html__( 'Noch nicht aktiviert. Trage den Schlüssel im Hauptshop unter WooCommerce → BlockSocial Sync → Lizenz ein.', 'blocksocial-license-server' ) . '</p>';
		} else {
			$roles = array(
				'master'  => __( 'Hauptshop', 'blocksocial-license-server' ),
				'network' => __( 'Eigener Shop', 'blocksocial-license-server' ),
				'partner' => __( 'Partnershop', 'blocksocial-license-server' ),
				'shopify' => __( 'Shopify', 'blocksocial-license-server' ),
			);
			echo '<table class="shop_table"><tbody>';
			foreach ( $sites as $s ) {
				echo '<tr><td>' . esc_html( $s['site'] ) . '</td><td>' . esc_html( isset( $roles[ $s['role'] ] ) ? $roles[ $s['role'] ] : $s['role'] ) . '</td><td>' . ( $s['covered'] ? '✓ ' . esc_html__( 'abgedeckt', 'blocksocial-license-server' ) : '✗ ' . esc_html__( 'kein Platz frei', 'blocksocial-license-server' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Hauptshop wirklich freigeben? Die Synchronisation pausiert, bis die Lizenz erneut aktiviert wird.', 'blocksocial-license-server' ) ) . '\');">';
			wp_nonce_field( 'bsls_release_' . (int) $lic['id'] );
			echo '<input type="hidden" name="bsls_release" value="' . (int) $lic['id'] . '"><button type="submit" class="button">' . esc_html__( 'Hauptshop freigeben (Umzug)', 'blocksocial-license-server' ) . '</button></form>';
		}
		echo '</div>';
	}
}
