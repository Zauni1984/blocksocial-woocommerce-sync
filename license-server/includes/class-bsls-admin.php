<?php
/**
 * Admin: WooCommerce → Lizenzen (Lizenzen verwalten, Releases/GitHub, Einstellungen).
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-Seiten.
 */
class BSLS_Admin {

	const SLUG = 'bsls-licenses';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		foreach ( array( 'save_license', 'new_license', 'release_master', 'save_github', 'refresh_github', 'upload_release', 'save_settings' ) as $a ) {
			add_action( 'admin_post_bsls_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Menü.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Lizenzen', 'blocksocial-license-server' ), __( 'Lizenzen', 'blocksocial-license-server' ), 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Berechtigung + Nonce.
	 *
	 * @param string $action Aktion.
	 */
	protected static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blocksocial-license-server' ) );
		}
		check_admin_referer( 'bsls_' . $action );
	}

	/**
	 * Zurück zur Seite mit Meldung.
	 *
	 * @param array $args Query-Argumente.
	 */
	protected static function back( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Seite.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'licenses';
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap"><h1>' . esc_html__( 'Lizenzen', 'blocksocial-license-server' ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array( 'licenses' => __( 'Lizenzen', 'blocksocial-license-server' ), 'releases' => __( 'Releases & Updates', 'blocksocial-license-server' ), 'settings' => __( 'Einstellungen', 'blocksocial-license-server' ) ) as $k => $label ) {
			echo '<a class="nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $k ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'releases' === $tab ) {
			self::render_releases();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_edit( (int) $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * Lizenzliste mit Suche und „Neue Lizenz".
	 */
	protected static function render_list() {
		$s    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list = BSLS_Licenses::search( $s, 200 );
		echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Schlüssel, E-Mail, Shop, Bestellnr.', 'blocksocial-license-server' ) . '" style="width:320px"> <button class="button">' . esc_html__( 'Suchen', 'blocksocial-license-server' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'Schlüssel', 'Stufe', 'Shops', 'Hauptshop', 'Kunde', 'Bestellung', 'Gültig bis', 'Status' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $list ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Keine Lizenzen gefunden.', 'blocksocial-license-server' ) . '</td></tr>';
		}
		foreach ( $list as $lic ) {
			$status = BSLS_Licenses::status( $lic );
			$edit   = add_query_arg( array( 'page' => self::SLUG, 'edit' => (int) $lic['id'] ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $edit ) . '"><code>' . esc_html( $lic['license_key'] ) . '</code></a></td>';
			echo '<td>' . esc_html( $lic['tier'] ) . '</td>';
			echo '<td>' . esc_html( count( BSLS_Licenses::sites( (int) $lic['id'] ) ) . ' / ' . BSLS_Licenses::sites_label( $lic['sites'] ) ) . '</td>';
			echo '<td>' . esc_html( $lic['master_site'] ? $lic['master_site'] : '–' ) . '</td>';
			echo '<td>' . esc_html( $lic['email'] ) . '</td>';
			$order = $lic['order_id'] ? wc_get_order( (int) $lic['order_id'] ) : null;
			echo '<td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '–' ) . '</td>';
			echo '<td>' . esc_html( BSLS_Orders::date( $lic['expires_at'] ) ) . '</td>';
			echo '<td>' . esc_html( BSLS_Licenses::status_label( $status ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Lizenz manuell anlegen', 'blocksocial-license-server' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsls_new_license">';
		wp_nonce_field( 'bsls_new_license' );
		echo '<table class="form-table">';
		self::row( __( 'Plugin', 'blocksocial-license-server' ), self::family_select( 'family', '' ) );
		self::row( __( 'Stufe', 'blocksocial-license-server' ), '<input name="tier" value="Standard" class="regular-text">' );
		self::row( __( 'Plätze (0 = unbegrenzt)', 'blocksocial-license-server' ), '<input type="number" min="0" name="sites" value="3">' );
		self::row( __( 'Laufzeit Monate (0 = lebenslang)', 'blocksocial-license-server' ), '<input type="number" min="0" name="months" value="12">' );
		self::row( __( 'E-Mail des Kunden', 'blocksocial-license-server' ), '<input type="email" name="email" class="regular-text">' );
		self::row( __( 'Notiz', 'blocksocial-license-server' ), '<input name="notes" class="large-text">' );
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Lizenz anlegen', 'blocksocial-license-server' ) . '</button></p></form>';
	}

	/**
	 * Formularzeile.
	 *
	 * @param string $label Beschriftung.
	 * @param string $html  Feld (bereits escaped).
	 */
	protected static function row( $label, $html ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Auswahl der Lizenzfamilie.
	 *
	 * @param string $name  Feldname.
	 * @param string $value Wert.
	 * @return string
	 */
	protected static function family_select( $name, $value ) {
		$h = '<select name="' . esc_attr( $name ) . '">';
		foreach ( BSLS_Licenses::families() as $k => $f ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $f['label'] ) . '</option>';
		}
		return $h . '</select>';
	}

	/**
	 * Bearbeiten einer Lizenz.
	 *
	 * @param int $id ID.
	 */
	protected static function render_edit( $id ) {
		$lic = BSLS_Licenses::get( $id );
		if ( ! $lic ) {
			echo '<p>' . esc_html__( 'Lizenz nicht gefunden.', 'blocksocial-license-server' ) . '</p>';
			return;
		}
		echo '<p><a href="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '">← ' . esc_html__( 'Alle Lizenzen', 'blocksocial-license-server' ) . '</a></p>';
		echo '<h2><code>' . esc_html( $lic['license_key'] ) . '</code></h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsls_save_license"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'bsls_save_license' );
		echo '<table class="form-table">';
		self::row( __( 'Stufe', 'blocksocial-license-server' ), '<input name="tier" class="regular-text" value="' . esc_attr( $lic['tier'] ) . '">' );
		self::row( __( 'Plätze (0 = unbegrenzt)', 'blocksocial-license-server' ), '<input type="number" min="0" name="sites" value="' . (int) $lic['sites'] . '">' );
		self::row( __( 'Gültig bis (leer = lebenslang)', 'blocksocial-license-server' ), '<input type="date" name="expires" value="' . esc_attr( $lic['expires_at'] ? substr( $lic['expires_at'], 0, 10 ) : '' ) . '">' );
		$st = '<select name="status">';
		foreach ( array( 'active', 'disabled', 'revoked' ) as $s ) {
			$st .= '<option value="' . esc_attr( $s ) . '"' . selected( $lic['status'], $s, false ) . '>' . esc_html( BSLS_Licenses::status_label( $s ) ) . '</option>';
		}
		self::row( __( 'Status', 'blocksocial-license-server' ), $st . '</select>' );
		self::row( __( 'E-Mail', 'blocksocial-license-server' ), '<input type="email" name="email" value="' . esc_attr( $lic['email'] ) . '" style="width:280px">' );
		self::row( __( 'Notiz', 'blocksocial-license-server' ), '<textarea name="notes" rows="3" cols="60">' . esc_textarea( (string) $lic['notes'] ) . '</textarea>' );
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Speichern', 'blocksocial-license-server' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Verbundene Shops', 'blocksocial-license-server' ) . '</h2>';
		$sites = BSLS_Licenses::sites( $id );
		echo '<table class="widefat striped"><thead><tr><th>Shop</th><th>Rolle</th><th>Abgedeckt</th><th>Zuletzt gesehen</th></tr></thead><tbody>';
		if ( empty( $sites ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Noch nicht aktiviert.', 'blocksocial-license-server' ) . '</td></tr>';
		}
		foreach ( $sites as $s ) {
			echo '<tr><td>' . esc_html( $s['site'] ) . '</td><td>' . esc_html( $s['role'] ) . '</td><td>' . ( $s['covered'] ? '✓' : '✗' ) . '</td><td>' . esc_html( get_date_from_gmt( $s['last_seen'], 'd.m.Y H:i' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $lic['master_site'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="bsls_release_master"><input type="hidden" name="id" value="' . (int) $id . '">';
			wp_nonce_field( 'bsls_release_master' );
			echo '<button class="button">' . esc_html__( 'Hauptshop freigeben', 'blocksocial-license-server' ) . '</button></form>';
		}
	}

	/**
	 * Releases: GitHub-Anbindung, Upload, aktueller Stand.
	 */
	protected static function render_releases() {
		$gh  = BSLS_Releases::github();
		$err = (string) get_option( 'bsls_github_error', '' );
		echo '<h2>' . esc_html__( 'GitHub (empfohlen)', 'blocksocial-license-server' ) . '</h2>';
		echo '<p>' . esc_html__( 'Das neueste GitHub-Release des Repositorys wird automatisch übernommen (stündlich). ZIP-Assets müssen <slug>-<version>.zip heißen – der Build-Workflow erledigt das. Das Token bleibt auf diesem Server; Kunden laden nur über signierte Links mit gültiger Lizenz.', 'blocksocial-license-server' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsls_save_github">';
		wp_nonce_field( 'bsls_save_github' );
		echo '<table class="form-table">';
		self::row( __( 'Repository (owner/name)', 'blocksocial-license-server' ), '<input name="repo" value="' . esc_attr( $gh['repo'] ) . '" placeholder="Zauni1984/blocksocial-woocommerce-sync" style="width:360px">' );
		self::row( __( 'Zugriffstoken', 'blocksocial-license-server' ), '<input type="password" name="token" value="" placeholder="' . esc_attr( '' !== $gh['token'] ? __( '•••••• gespeichert – leer lassen zum Beibehalten', 'blocksocial-license-server' ) : 'github_pat_…' ) . '" style="width:360px" autocomplete="off"><p class="description">' . esc_html__( 'Fine-grained Token, nur dieses Repository, Berechtigung „Contents: Read-only".', 'blocksocial-license-server' ) . '</p>' );
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Speichern', 'blocksocial-license-server' ) . '</button></p></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsls_refresh_github">';
		wp_nonce_field( 'bsls_refresh_github' );
		echo '<p><button class="button">' . esc_html__( 'Jetzt bei GitHub nachsehen', 'blocksocial-license-server' ) . '</button></p></form>';
		if ( '' !== $err ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $err ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Aktuelle Versionen', 'blocksocial-license-server' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Quelle</th><th>Datum</th></tr></thead><tbody>';
		$all = BSLS_Releases::all();
		if ( empty( $all ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Noch kein Release.', 'blocksocial-license-server' ) . '</td></tr>';
		}
		foreach ( $all as $rel ) {
			echo '<tr><td>' . esc_html( $rel['slug'] ) . '</td><td>' . esc_html( $rel['version'] ) . '</td><td>' . esc_html( $rel['source'] ) . '</td><td>' . esc_html( $rel['date'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Manueller Upload (Fallback)', 'blocksocial-license-server' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsls_upload_release">';
		wp_nonce_field( 'bsls_upload_release' );
		echo '<input type="file" name="zip" accept=".zip"> <button class="button">' . esc_html__( 'Hochladen', 'blocksocial-license-server' ) . '</button></form>';
	}

	/**
	 * Einstellungen.
	 */
	protected static function render_settings() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsls_save_settings">';
		wp_nonce_field( 'bsls_save_settings' );
		echo '<table class="form-table">';
		self::row( __( 'Rabatt auf Verlängerungen (%)', 'blocksocial-license-server' ), '<input type="number" min="0" max="100" step="1" name="renewal_discount" value="' . esc_attr( (string) get_option( 'bsls_renewal_discount', 0 ) ) . '">' );
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Speichern', 'blocksocial-license-server' ) . '</button></p></form>';
		echo '<h2>' . esc_html__( 'API', 'blocksocial-license-server' ) . '</h2><p><code>' . esc_html( rest_url( BSLS_REST_NS ) ) . '</code></p>';
	}

	// -------------------------------------------------------------------------
	// Handler
	// -------------------------------------------------------------------------

	/**
	 * Lizenz speichern.
	 */
	public static function handle_save_license() {
		self::guard( 'save_license' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$expires = isset( $_POST['expires'] ) ? sanitize_text_field( wp_unslash( $_POST['expires'] ) ) : '';
		$status  = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active';
		BSLS_Licenses::update(
			$id,
			array(
				'tier'       => isset( $_POST['tier'] ) ? sanitize_text_field( wp_unslash( $_POST['tier'] ) ) : '',
				'sites'      => isset( $_POST['sites'] ) ? max( 0, (int) $_POST['sites'] ) : 3,
				'expires_at' => '' !== $expires ? gmdate( 'Y-m-d 23:59:59', strtotime( $expires ) ) : null,
				'status'     => in_array( $status, array( 'active', 'disabled', 'revoked' ), true ) ? $status : 'active',
				'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'notes'      => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);
		// phpcs:enable
		self::back( array( 'edit' => $id, 'msg' => __( 'Gespeichert.', 'blocksocial-license-server' ) ) );
	}

	/**
	 * Neue Lizenz.
	 */
	public static function handle_new_license() {
		self::guard( 'new_license' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$user  = $email ? get_user_by( 'email', $email ) : null;
		$lic   = BSLS_Licenses::create(
			array(
				'family'  => isset( $_POST['family'] ) ? sanitize_key( $_POST['family'] ) : 'blocksocial-sync',
				'tier'    => isset( $_POST['tier'] ) ? sanitize_text_field( wp_unslash( $_POST['tier'] ) ) : '',
				'sites'   => isset( $_POST['sites'] ) ? (int) $_POST['sites'] : 3,
				'months'  => isset( $_POST['months'] ) ? (int) $_POST['months'] : 12,
				'email'   => $email,
				'user_id' => $user ? $user->ID : 0,
				'notes'   => isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);
		// phpcs:enable
		self::back( array( 'edit' => (int) $lic['id'], 'msg' => __( 'Lizenz angelegt.', 'blocksocial-license-server' ) ) );
	}

	/**
	 * Hauptshop freigeben.
	 */
	public static function handle_release_master() {
		self::guard( 'release_master' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		BSLS_Licenses::release_master( $id );
		self::back( array( 'edit' => $id, 'msg' => __( 'Hauptshop freigegeben.', 'blocksocial-license-server' ) ) );
	}

	/**
	 * GitHub-Einstellungen.
	 */
	public static function handle_save_github() {
		self::guard( 'save_github' );
		$gh = BSLS_Releases::github();
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$gh['repo'] = isset( $_POST['repo'] ) ? preg_replace( '#[^A-Za-z0-9_.\-/]#', '', wp_unslash( $_POST['repo'] ) ) : '';
		$token      = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		// phpcs:enable
		if ( '' !== $token ) {
			$gh['token'] = $token;
		}
		update_option( BSLS_Releases::GITHUB_OPT, $gh, false );
		delete_transient( BSLS_Releases::GITHUB_CACHE );
		BSLS_Releases::github_releases( true );
		self::back( array( 'tab' => 'releases', 'msg' => __( 'Gespeichert.', 'blocksocial-license-server' ) ) );
	}

	/**
	 * GitHub neu abfragen.
	 */
	public static function handle_refresh_github() {
		self::guard( 'refresh_github' );
		$rel = BSLS_Releases::github_releases( true );
		/* translators: %d: Anzahl */
		self::back( array( 'tab' => 'releases', 'msg' => sprintf( __( '%d Plugin-Version(en) bei GitHub gefunden.', 'blocksocial-license-server' ), count( $rel ) ) ) );
	}

	/**
	 * ZIP hochladen.
	 */
	public static function handle_upload_release() {
		self::guard( 'upload_release' );
		if ( empty( $_FILES['zip']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::back( array( 'tab' => 'releases', 'msg' => __( 'Keine Datei gewählt.', 'blocksocial-license-server' ) ) );
		}
		$res = BSLS_Releases::add_from_file( $_FILES['zip']['tmp_name'] ); // phpcs:ignore
		$msg = is_wp_error( $res ) ? $res->get_error_message() : sprintf( '%s %s hochgeladen.', $res['slug'], $res['version'] );
		self::back( array( 'tab' => 'releases', 'msg' => $msg ) );
	}

	/**
	 * Einstellungen speichern.
	 */
	public static function handle_save_settings() {
		self::guard( 'save_settings' );
		update_option( 'bsls_renewal_discount', isset( $_POST['renewal_discount'] ) ? min( 100, max( 0, (int) $_POST['renewal_discount'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::back( array( 'tab' => 'settings', 'msg' => __( 'Gespeichert.', 'blocksocial-license-server' ) ) );
	}
}
