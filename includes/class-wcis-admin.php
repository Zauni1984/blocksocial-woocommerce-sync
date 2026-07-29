<?php
/**
 * Admin-Oberfläche: Einstellungsseite, Aktionen und AJAX-Handler.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verwaltet das Backend.
 */
class WCIS_Admin {

	/**
	 * Singleton.
	 *
	 * @var WCIS_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Menü-Slug.
	 */
	const SLUG = 'blocksocial-woocommerce-sync';

	/**
	 * Singleton-Instanz.
	 *
	 * @return WCIS_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks registrieren.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_wcis_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wcis_full_sync', array( $this, 'handle_full_sync' ) );
		add_action( 'admin_post_wcis_push_config', array( $this, 'handle_push_config' ) );
		add_action( 'admin_post_wcis_retry_queue', array( $this, 'handle_retry_queue' ) );
		add_action( 'admin_post_wcis_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_wcis_reconcile_now', array( $this, 'handle_reconcile_now' ) );

		add_action( 'wp_ajax_wcis_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wcis_fullsync_start', array( $this, 'ajax_fullsync_start' ) );
		add_action( 'wp_ajax_wcis_fullsync_tick', array( $this, 'ajax_fullsync_tick' ) );
		add_action( 'wp_ajax_wcis_fullsync_cancel', array( $this, 'ajax_fullsync_cancel' ) );
		add_action( 'wp_ajax_wcis_productsync_start', array( $this, 'ajax_productsync_start' ) );
		add_action( 'wp_ajax_wcis_productsync_tick', array( $this, 'ajax_productsync_tick' ) );
		add_action( 'wp_ajax_wcis_productsync_cancel', array( $this, 'ajax_productsync_cancel' ) );
		add_action( 'wp_ajax_wcis_productpull_start', array( $this, 'ajax_productpull_start' ) );
		add_action( 'wp_ajax_wcis_productpull_tick', array( $this, 'ajax_productpull_tick' ) );
		add_action( 'wp_ajax_wcis_productpull_cancel', array( $this, 'ajax_productpull_cancel' ) );
		add_action( 'wp_ajax_wcis_filter_preview', array( $this, 'ajax_filter_preview' ) );
	}

	/**
	 * Menüeintrag unter WooCommerce.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Lagerbestand-Sync', 'blocksocial-woocommerce-sync' ),
			__( 'Lagerbestand-Sync', 'blocksocial-woocommerce-sync' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Assets laden (nur auf der Plugin-Seite).
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		// WooCommerce-Auswahlfelder (select2) für Produktsuche und Mehrfachauswahl.
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'select2' );

		wp_enqueue_style( 'wcis-admin', WCIS_URL . 'assets/admin.css', array(), WCIS_VERSION );
		wp_enqueue_script( 'wcis-admin', WCIS_URL . 'assets/admin.js', array( 'jquery', 'wc-enhanced-select' ), WCIS_VERSION, true );
		wp_localize_script(
			'wcis-admin',
			'WCIS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcis_ajax' ),
				'i18n'    => array(
					'testing'     => __( 'Teste …', 'blocksocial-woocommerce-sync' ),
					'confirmFull' => __( 'Voll-Synchronisation vom Hauptshop an alle Shops starten? Dies überschreibt die Bestände der anderen Shops mit den Werten dieses Shops (Zuordnung per SKU).', 'blocksocial-woocommerce-sync' ),
					'starting'    => __( 'Starte …', 'blocksocial-woocommerce-sync' ),
					'syncing'     => __( 'Synchronisiere', 'blocksocial-woocommerce-sync' ),
					'toShop'      => __( 'an', 'blocksocial-woocommerce-sync' ),
					'done'        => __( 'Fertig', 'blocksocial-woocommerce-sync' ),
					'cancelled'   => __( 'Abgebrochen', 'blocksocial-woocommerce-sync' ),
					'itemsUnit'   => __( 'Artikel', 'blocksocial-woocommerce-sync' ),
					'batchesSent' => __( 'Batches gesendet', 'blocksocial-woocommerce-sync' ),
					'failedUnit'  => __( 'fehlgeschlagen', 'blocksocial-woocommerce-sync' ),
					'genericError' => __( 'Fehler', 'blocksocial-woocommerce-sync' ),
					'confirmProducts' => __( 'Alle einfachen und variablen Produkte (inkl. Status) an alle verbundenen Shops übertragen? Neue Produkte werden angelegt; bestehende bleiben unangetastet, sofern nicht anders eingestellt.', 'blocksocial-woocommerce-sync' ),
					'confirmPull'  => __( 'Produkte vom Hauptshop auf diesen Shop holen? Neue Produkte werden hier angelegt; bestehende bleiben unangetastet, sofern nicht anders eingestellt.', 'blocksocial-woocommerce-sync' ),
					'products'    => __( 'Produkte', 'blocksocial-woocommerce-sync' ),
					'createdUnit' => __( 'angelegt', 'blocksocial-woocommerce-sync' ),
					'updatedUnit' => __( 'aktualisiert', 'blocksocial-woocommerce-sync' ),
					'skippedUnit' => __( 'übersprungen', 'blocksocial-woocommerce-sync' ),
					'previewLoading' => __( 'Vorschau wird berechnet …', 'blocksocial-woocommerce-sync' ),
					'previewHeading' => __( 'Produkte im Sync-Umfang', 'blocksocial-woocommerce-sync' ),
					'previewOf'      => __( 'von', 'blocksocial-woocommerce-sync' ),
					'previewScanned' => __( 'geprüft', 'blocksocial-woocommerce-sync' ),
					'previewExcluded' => __( 'ausgeschlossen', 'blocksocial-woocommerce-sync' ),
					'previewSample'  => __( 'Beispiele', 'blocksocial-woocommerce-sync' ),
					'previewTruncated' => __( 'Hinweis: Es wurden nur die ersten Produkte geprüft.', 'blocksocial-woocommerce-sync' ),
				),
			)
		);
	}

	/**
	 * Capability-Check.
	 */
	protected function require_cap() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blocksocial-woocommerce-sync' ) );
		}
	}

	/**
	 * Redirect mit Statusmeldung zurück zur Plugin-Seite.
	 *
	 * @param string $notice Meldungs-Slug.
	 */
	protected function redirect_back( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wcis_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Aktionen
	// -------------------------------------------------------------------------

	/**
	 * Speichert die Einstellungen.
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( 'wcis_save' );

		$shops = array();
		if ( isset( $_POST['shop_name'], $_POST['shop_url'] ) && is_array( $_POST['shop_url'] ) ) {
			$names = array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['shop_name'] ) );
			$urls  = array_map( 'esc_url_raw', wp_unslash( (array) $_POST['shop_url'] ) );
			foreach ( $urls as $i => $url ) {
				$url = untrailingslashit( trim( $url ) );
				if ( '' === $url ) {
					continue;
				}
				$shops[] = array(
					'name' => isset( $names[ $i ] ) ? $names[ $i ] : '',
					'url'  => $url,
				);
			}
		}

		$values = array(
			'enabled'        => ! empty( $_POST['enabled'] ),
			'sync_status'    => ! empty( $_POST['sync_status'] ),
			'network_secret' => isset( $_POST['network_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['network_secret'] ) ) : '',
			'this_shop_name' => isset( $_POST['this_shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['this_shop_name'] ) ) : '',
			'this_shop_url'  => isset( $_POST['this_shop_url'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $_POST['this_shop_url'] ) ) ) : home_url(),
			'master_url'     => isset( $_POST['master_url'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $_POST['master_url'] ) ) ) : '',
			'log_level'      => ( isset( $_POST['log_level'] ) && 'error' === $_POST['log_level'] ) ? 'error' : 'info',
			'batch_size'     => isset( $_POST['batch_size'] ) ? max( 1, min( 500, (int) $_POST['batch_size'] ) ) : 50,
			'http_timeout'   => isset( $_POST['http_timeout'] ) ? max( 5, min( 60, (int) $_POST['http_timeout'] ) ) : 20,
			'reconcile_interval' => $this->clean_choice( isset( $_POST['reconcile_interval'] ) ? $_POST['reconcile_interval'] : '', array( 'off', 'hourly', 'sixhourly', 'daily' ), 'hourly' ),
			'reconcile_strategy' => $this->clean_choice( isset( $_POST['reconcile_strategy'] ) ? $_POST['reconcile_strategy'] : '', array( 'lowest', 'local' ), 'lowest' ),
			'product_sync_enabled'         => ! empty( $_POST['product_sync_enabled'] ),
			'product_sync_source'          => $this->clean_choice( isset( $_POST['product_sync_source'] ) ? $_POST['product_sync_source'] : '', array( 'master', 'any' ), 'master' ),
			'product_sync_images'          => ! empty( $_POST['product_sync_images'] ),
			'product_sync_update_existing' => ! empty( $_POST['product_sync_update_existing'] ),
			'filter_mode'        => $this->clean_choice( isset( $_POST['filter_mode'] ) ? $_POST['filter_mode'] : '', array( 'all', 'selected' ), 'all' ),
			'filter_categories'  => isset( $_POST['filter_categories'] ) ? array_map( 'intval', (array) $_POST['filter_categories'] ) : array(),
			'filter_brands'      => isset( $_POST['filter_brands'] ) ? array_map( 'intval', (array) $_POST['filter_brands'] ) : array(),
			'filter_include_ids' => isset( $_POST['filter_include_ids'] ) ? array_map( 'intval', (array) $_POST['filter_include_ids'] ) : array(),
			'filter_exclude_ids' => isset( $_POST['filter_exclude_ids'] ) ? array_map( 'intval', (array) $_POST['filter_exclude_ids'] ) : array(),
			'filter_exclude_categories' => isset( $_POST['filter_exclude_categories'] ) ? array_map( 'intval', (array) $_POST['filter_exclude_categories'] ) : array(),
			'product_fields'     => isset( $_POST['product_fields'] ) ? array_map( 'sanitize_key', (array) $_POST['product_fields'] ) : array(),
			'tax_class_map'      => isset( $_POST['tax_class_map'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tax_class_map'] ) ) : '',
			'shops'          => $shops,
		);

		WCIS_Settings::update( $values );

		// Abgleich-Zeitplan an die (ggf. geänderte) Einstellung anpassen.
		WCIS_Reconcile::reschedule();

		$this->redirect_back( 'saved' );
	}

	/**
	 * Validiert eine Auswahl gegen eine Whitelist.
	 *
	 * @param string $value    Wert.
	 * @param array  $allowed  Erlaubte Werte.
	 * @param string $fallback Standard.
	 * @return string
	 */
	protected function clean_choice( $value, array $allowed, $fallback ) {
		$value = sanitize_key( wp_unslash( $value ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Führt den Abgleich sofort aus.
	 */
	public function handle_reconcile_now() {
		$this->require_cap();
		check_admin_referer( 'wcis_reconcile_now' );

		$stats = WCIS_Reconcile::run();
		set_transient( 'wcis_reconcile_result', $stats, 120 );
		$this->redirect_back( 'reconciled' );
	}

	/**
	 * Startet die Voll-Synchronisation von diesem Shop aus.
	 */
	public function handle_full_sync() {
		$this->require_cap();
		check_admin_referer( 'wcis_full_sync' );

		$result = WCIS_Sync_Engine::full_sync();

		if ( empty( $result['ok'] ) ) {
			$this->redirect_back( 'fullsync_error' );
		}
		set_transient( 'wcis_fullsync_result', $result, 120 );
		$this->redirect_back( 'fullsync_done' );
	}

	/**
	 * Verteilt die Netzwerk-Konfiguration (Shops + Hauptshop) an alle Peers.
	 */
	public function handle_push_config() {
		$this->require_cap();
		check_admin_referer( 'wcis_push_config' );

		$payload = array(
			'shops'      => WCIS_Settings::get( 'shops', array() ),
			'master_url' => WCIS_Settings::get( 'master_url' ),
		);

		$ok   = 0;
		$fail = 0;
		foreach ( WCIS_Settings::get_peers() as $peer ) {
			$res = WCIS_Client::post( $peer['url'], '/config', $payload, true );
			if ( ! is_wp_error( $res ) && $res['code'] >= 200 && $res['code'] < 300 ) {
				$ok++;
			} else {
				$fail++;
			}
		}

		set_transient( 'wcis_pushconfig_result', array( 'ok' => $ok, 'fail' => $fail ), 120 );
		$this->redirect_back( 'config_pushed' );
	}

	/**
	 * Setzt fehlgeschlagene Queue-Einträge zurück.
	 */
	public function handle_retry_queue() {
		$this->require_cap();
		check_admin_referer( 'wcis_retry_queue' );
		WCIS_Queue::retry_failed();
		WCIS_Queue::process();
		$this->redirect_back( 'queue_retried' );
	}

	/**
	 * Leert das Log.
	 */
	public function handle_clear_log() {
		$this->require_cap();
		check_admin_referer( 'wcis_clear_log' );
		WCIS_Logger::clear();
		$this->redirect_back( 'log_cleared' );
	}

	/**
	 * AJAX: Verbindungstest zu einem Peer.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'wcis_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'blocksocial-woocommerce-sync' ) ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Keine URL angegeben.', 'blocksocial-woocommerce-sync' ) ) );
		}
		if ( '' === WCIS_Settings::secret() ) {
			wp_send_json_error( array( 'message' => __( 'Kein Netzwerk-Secret gesetzt. Bitte zuerst speichern.', 'blocksocial-woocommerce-sync' ) ) );
		}

		$res = WCIS_Client::get( $url, '/ping' );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		if ( 200 !== $res['code'] ) {
			$msg = 401 === $res['code']
				? __( 'Verbindung erreichbar, aber Secret stimmt nicht überein (401).', 'blocksocial-woocommerce-sync' )
				: sprintf( __( 'Fehler: HTTP %d.', 'blocksocial-woocommerce-sync' ), $res['code'] );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		$data = json_decode( $res['body'], true );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: Shop-Name, 2: Version */
					__( 'Verbunden mit „%1$s" (Plugin v%2$s).', 'blocksocial-woocommerce-sync' ),
					isset( $data['shop'] ) ? $data['shop'] : '?',
					isset( $data['version'] ) ? $data['version'] : '?'
				),
			)
		);
	}

	/**
	 * Gemeinsame Prüfung für die Fullsync-AJAX-Endpunkte.
	 */
	protected function check_ajax() {
		check_ajax_referer( 'wcis_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'blocksocial-woocommerce-sync' ) ) );
		}
	}

	/**
	 * AJAX: startet den Voll-Sync-Job.
	 */
	public function ajax_fullsync_start() {
		$this->check_ajax();
		$batch = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 0;

		$job = WCIS_Fullsync::start( $batch );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Fullsync::to_response( $job ) );
	}

	/**
	 * AJAX: verarbeitet den nächsten Abschnitt des Jobs.
	 */
	public function ajax_fullsync_tick() {
		$this->check_ajax();
		$job = WCIS_Fullsync::tick();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Fullsync::to_response( $job ) );
	}

	/**
	 * AJAX: bricht den laufenden Job ab.
	 */
	public function ajax_fullsync_cancel() {
		$this->check_ajax();
		WCIS_Fullsync::cancel();
		wp_send_json_success( WCIS_Fullsync::to_response( WCIS_Fullsync::state() ) );
	}

	/**
	 * AJAX: startet die Produkt-Massen-Übertragung.
	 */
	public function ajax_productsync_start() {
		$this->check_ajax();
		$job = WCIS_Product_Sync::bulk_start();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Product_Sync::bulk_to_response( $job ) );
	}

	/**
	 * AJAX: verarbeitet den nächsten Abschnitt der Produkt-Übertragung.
	 */
	public function ajax_productsync_tick() {
		$this->check_ajax();
		$job = WCIS_Product_Sync::bulk_tick();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Product_Sync::bulk_to_response( $job ) );
	}

	/**
	 * AJAX: bricht die Produkt-Übertragung ab.
	 */
	public function ajax_productsync_cancel() {
		$this->check_ajax();
		WCIS_Product_Sync::bulk_cancel();
		wp_send_json_success( WCIS_Product_Sync::bulk_to_response( WCIS_Product_Sync::bulk_state() ) );
	}

	/**
	 * AJAX: startet den Produkt-Pull vom Hauptshop.
	 */
	public function ajax_productpull_start() {
		$this->check_ajax();
		$job = WCIS_Product_Sync::pull_start();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Product_Sync::pull_to_response( $job ) );
	}

	/**
	 * AJAX: verarbeitet den nächsten Abschnitt des Pull.
	 */
	public function ajax_productpull_tick() {
		$this->check_ajax();
		$job = WCIS_Product_Sync::pull_tick();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Product_Sync::pull_to_response( $job ) );
	}

	/**
	 * AJAX: bricht den Pull ab.
	 */
	public function ajax_productpull_cancel() {
		$this->check_ajax();
		WCIS_Product_Sync::pull_cancel();
		wp_send_json_success( WCIS_Product_Sync::pull_to_response( WCIS_Product_Sync::pull_state() ) );
	}

	/**
	 * AJAX: Vorschau des Sync-Umfangs (berücksichtigt ungespeicherte Auswahl).
	 */
	public function ajax_filter_preview() {
		$this->check_ajax();

		$intarr = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? array_map( 'intval', (array) wp_unslash( $_POST[ $key ] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		};

		WCIS_Filter::set_overrides(
			array(
				'filter_mode'               => $this->clean_choice( isset( $_POST['filter_mode'] ) ? $_POST['filter_mode'] : '', array( 'all', 'selected' ), 'all' ),
				'filter_categories'         => $intarr( 'filter_categories' ),
				'filter_brands'             => $intarr( 'filter_brands' ),
				'filter_include_ids'        => $intarr( 'filter_include_ids' ),
				'filter_exclude_ids'        => $intarr( 'filter_exclude_ids' ),
				'filter_exclude_categories' => $intarr( 'filter_exclude_categories' ),
			)
		);

		$preview = WCIS_Filter::preview();
		WCIS_Filter::clear_overrides();

		wp_send_json_success( $preview );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Rendert die Einstellungsseite.
	 */
	public function render_page() {
		$this->require_cap();
		$s = WCIS_Settings::all();
		require WCIS_PATH . 'includes/views/settings-page.php';
	}
}
