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
		add_action( 'wp_ajax_wcis_cleanup_analyze', array( $this, 'ajax_cleanup_analyze' ) );
		add_action( 'wp_ajax_wcis_cleanup_remove', array( $this, 'ajax_cleanup_remove' ) );
		add_action( 'wp_ajax_wcis_cleanup_tick', array( $this, 'ajax_cleanup_tick' ) );
		add_action( 'wp_ajax_wcis_cleanup_cancel', array( $this, 'ajax_cleanup_cancel' ) );

		// Preisregeln (beide Editionen, Empfänger-Shops).
		add_action( 'wp_ajax_wcis_pricing_save', array( $this, 'ajax_pricing_save' ) );
		add_action( 'wp_ajax_wcis_pricing_preview', array( $this, 'ajax_pricing_preview' ) );
		add_action( 'wp_ajax_wcis_pricing_start', array( $this, 'ajax_pricing_start' ) );
		add_action( 'wp_ajax_wcis_pricing_tick', array( $this, 'ajax_pricing_tick' ) );
		add_action( 'wp_ajax_wcis_pricing_cancel', array( $this, 'ajax_pricing_cancel' ) );

		if ( WCIS_Edition::is_partner() ) {
			// Partner-Plugin: Verbindung + lokale Optionen.
			add_action( 'admin_post_wcis_partner_connect', array( $this, 'handle_partner_connect' ) );
			add_action( 'admin_post_wcis_partner_disconnect', array( $this, 'handle_partner_disconnect' ) );
			add_action( 'admin_post_wcis_partner_local_save', array( $this, 'handle_partner_local_save' ) );
			add_action( 'admin_post_wcis_partner_refresh', array( $this, 'handle_partner_refresh' ) );
			return;
		}

		// Admin-Plugin: Partner-Verwaltung.
		add_action( 'admin_post_wcis_partner_add', array( $this, 'handle_partner_add' ) );
		add_action( 'admin_post_wcis_partner_update', array( $this, 'handle_partner_update' ) );
		add_action( 'admin_post_wcis_partner_action', array( $this, 'handle_partner_action' ) );
		add_action( 'admin_post_wcis_partner_policy', array( $this, 'handle_partner_policy' ) );
		add_action( 'wp_ajax_wcis_partner_test', array( $this, 'ajax_partner_test' ) );

		// Admin-Plugin: Shopify.
		add_action( 'admin_post_wcis_shopify_save', array( $this, 'handle_shopify_save' ) );
		add_action( 'admin_post_wcis_shopify_delete', array( $this, 'handle_shopify_delete' ) );
		add_action( 'wp_ajax_wcis_shopify_test', array( $this, 'ajax_shopify_test' ) );
		add_action( 'wp_ajax_wcis_shopify_start', array( $this, 'ajax_shopify_start' ) );
		add_action( 'wp_ajax_wcis_shopify_tick', array( $this, 'ajax_shopify_tick' ) );
		add_action( 'wp_ajax_wcis_shopify_cancel', array( $this, 'ajax_shopify_cancel' ) );

		// Admin-Plugin: CSV-Feeds.
		add_action( 'admin_post_wcis_feed_save', array( $this, 'handle_feed_save' ) );
		add_action( 'admin_post_wcis_feed_action', array( $this, 'handle_feed_action' ) );
	}

	/**
	 * Menüeintrag unter WooCommerce.
	 */
	public function add_menu() {
		$label = WCIS_Edition::is_partner()
			? __( 'BlockSocial Partner', 'blocksocial-woocommerce-sync' )
			: __( 'BlockSocial Sync', 'blocksocial-woocommerce-sync' );
		add_submenu_page(
			'woocommerce',
			$label,
			$label,
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
					'confirmPricing'   => __( 'Preisregeln speichern und jetzt auf alle Produkte anwenden? Die Preise werden aus den gespeicherten Basispreisen neu berechnet (kein Aufschlag auf den Aufschlag). 0 % stellt die Originalpreise wieder her.', 'blocksocial-woocommerce-sync' ),
					'confirmReset'     => __( 'Alle Preisregeln entfernen und die Originalpreise (Basispreise) wiederherstellen?', 'blocksocial-woocommerce-sync' ),
					'changedUnit'      => __( 'geändert', 'blocksocial-woocommerce-sync' ),
					'unchangedUnit'    => __( 'unverändert', 'blocksocial-woocommerce-sync' ),
					'saved'            => __( 'Gespeichert.', 'blocksocial-woocommerce-sync' ),
					'pricingSample'    => __( 'Beispiele', 'blocksocial-woocommerce-sync' ),
					'pricingAffected'  => __( 'Produkte würden sich ändern', 'blocksocial-woocommerce-sync' ),
					'confirmShopify'   => __( 'Übertragung an den gewählten Shopify-Shop starten?', 'blocksocial-woocommerce-sync' ),
					'copied'           => __( 'Kopiert!', 'blocksocial-woocommerce-sync' ),
					'removeRule'       => __( 'Regel entfernen', 'blocksocial-woocommerce-sync' ),
					'cleanupFetch'     => __( 'Lade Produktliste vom Hauptshop …', 'blocksocial-woocommerce-sync' ),
					'cleanupScan'      => __( 'Prüfe Produkte gegen den Sync-Filter …', 'blocksocial-woocommerce-sync' ),
					'cleanupChecked'   => __( 'Produkte geprüft', 'blocksocial-woocommerce-sync' ),
					'cleanupOwn'       => __( 'eigene Produkte (nicht im Hauptshop) – bleiben unangetastet', 'blocksocial-woocommerce-sync' ),
					'cleanupFound'     => __( 'Produkte liegen außerhalb des Sync-Umfangs und können entfernt werden', 'blocksocial-woocommerce-sync' ),
					'cleanupNone'      => __( 'Keine Produkte außerhalb des Sync-Umfangs gefunden.', 'blocksocial-woocommerce-sync' ),
					'cleanupRemoving'  => __( 'Entferne', 'blocksocial-woocommerce-sync' ),
					'cleanupRemoved'   => __( 'entfernt', 'blocksocial-woocommerce-sync' ),
					'cleanupMore'      => __( 'weitere', 'blocksocial-woocommerce-sync' ),
					'confirmCleanupTrash'  => __( 'Die gefundenen Produkte in den Papierkorb verschieben? Sie lassen sich dort 30 Tage lang wiederherstellen.', 'blocksocial-woocommerce-sync' ),
					'confirmCleanupDelete' => __( 'Die gefundenen Produkte ENDGÜLTIG löschen – inklusive der vom Sync importierten Bilder? Das kann nicht rückgängig gemacht werden.', 'blocksocial-woocommerce-sync' ),
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
		if ( WCIS_Edition::is_partner() ) {
			$this->redirect_back( 'locked' ); // Partner können Netzwerk-Einstellungen nicht ändern.
		}

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

		// Netzwerk-Secret: ein zu kurzes/schwaches Secret nicht speichern
		// (schützt vor leicht zu erratenden Tokens). Das vorhandene bleibt dann erhalten.
		$new_secret  = isset( $_POST['network_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['network_secret'] ) ) : '';
		$weak_secret = false;
		if ( '' === $new_secret ) {
			// Leeres Feld: vorhandenes Secret behalten – ein versehentlich geleertes
			// Feld darf nicht den gesamten Verbund lahmlegen.
			$new_secret = WCIS_Settings::secret();
		} elseif ( strlen( $new_secret ) < 16 ) {
			$new_secret  = WCIS_Settings::secret();
			$weak_secret = true;
		}

		$values = array(
			'enabled'        => ! empty( $_POST['enabled'] ),
			'sync_status'    => ! empty( $_POST['sync_status'] ),
			'network_secret' => $new_secret,
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
			'update_prices'                => ! empty( $_POST['update_prices'] ),
			'price_gross_mode'             => ! empty( $_POST['price_gross_mode'] ),
			'accept_sale_prices'           => ! empty( $_POST['accept_sale_prices'] ),
			'filter_mode'        => $this->clean_choice( isset( $_POST['filter_mode'] ) ? $_POST['filter_mode'] : '', array( 'all', 'selected' ), 'all' ),
			'filter_categories'  => isset( $_POST['filter_categories'] ) ? array_map( 'intval', (array) $_POST['filter_categories'] ) : array(),
			'filter_brands'      => isset( $_POST['filter_brands'] ) ? array_map( 'intval', (array) $_POST['filter_brands'] ) : array(),
			'filter_include_ids' => isset( $_POST['filter_include_ids'] ) ? array_map( 'intval', (array) $_POST['filter_include_ids'] ) : array(),
			'filter_exclude_ids' => isset( $_POST['filter_exclude_ids'] ) ? array_map( 'intval', (array) $_POST['filter_exclude_ids'] ) : array(),
			'filter_exclude_categories' => isset( $_POST['filter_exclude_categories'] ) ? array_map( 'intval', (array) $_POST['filter_exclude_categories'] ) : array(),
			'filter_exclude_except_brands' => isset( $_POST['filter_exclude_except_brands'] ) ? array_map( 'intval', (array) $_POST['filter_exclude_except_brands'] ) : array(),
			'product_fields'     => isset( $_POST['product_fields'] ) ? array_map( 'sanitize_key', (array) $_POST['product_fields'] ) : array(),
			'tax_class_map'      => isset( $_POST['tax_class_map'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tax_class_map'] ) ) : '',
			'shops'          => $shops,
		);

		WCIS_Settings::update( $values );

		// Abgleich-Zeitplan an die (ggf. geänderte) Einstellung anpassen.
		WCIS_Reconcile::reschedule();

		$this->redirect_back( $weak_secret ? 'weak_secret' : 'saved' );
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
			if ( 'partner' === $peer['type'] ) {
				$partner = WCIS_Partners::get( $peer['key'] );
				$res     = $partner ? WCIS_Client::post( $peer['url'], '/config', array( 'managed' => WCIS_Partners::managed_config( $partner ) ), true ) : null;
			} else {
				$res = WCIS_Client::post( $peer['url'], '/config', $payload, true );
			}
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
		$cred = WCIS_Client::credentials_for( $url );
		if ( '' === $cred['secret'] ) {
			wp_send_json_error( array( 'message' => __( 'Bitte zuerst Netzwerk-Secret und Shop-URL speichern, dann testen (aus Sicherheitsgründen wird nur an gespeicherte Shops signiert).', 'blocksocial-woocommerce-sync' ) ) );
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
	 * AJAX: startet die Aufräum-Analyse.
	 */
	public function ajax_cleanup_analyze() {
		$this->check_ajax();
		$job = WCIS_Cleanup::analyze_start(
			array(
				'origin' => isset( $_POST['origin'] ) ? sanitize_key( wp_unslash( $_POST['origin'] ) ) : 'marker', // phpcs:ignore WordPress.Security.NonceVerification
				'since'  => isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			)
		);
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Cleanup::to_response( $job ) );
	}

	/**
	 * AJAX: startet das Entfernen der analysierten Produkte.
	 */
	public function ajax_cleanup_remove() {
		$this->check_ajax();
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'trash'; // phpcs:ignore WordPress.Security.NonceVerification
		$job  = WCIS_Cleanup::remove_start( $mode );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Cleanup::to_response( $job ) );
	}

	/**
	 * AJAX: nächster Abschnitt des Aufräumens.
	 */
	public function ajax_cleanup_tick() {
		$this->check_ajax();
		$job = WCIS_Cleanup::tick();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Cleanup::to_response( $job ) );
	}

	/**
	 * AJAX: bricht das Aufräumen ab.
	 */
	public function ajax_cleanup_cancel() {
		$this->check_ajax();
		WCIS_Cleanup::cancel();
		wp_send_json_success( WCIS_Cleanup::to_response( WCIS_Cleanup::state() ) );
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
				'filter_exclude_except_brands' => $intarr( 'filter_exclude_except_brands' ),
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
		if ( WCIS_Edition::is_partner() ) {
			require WCIS_PATH . 'includes/views/partner-page.php';
			return;
		}
		require WCIS_PATH . 'includes/views/settings-page.php';
	}

	// -------------------------------------------------------------------------
	// Preisregeln (AJAX)
	// -------------------------------------------------------------------------

	/**
	 * Liest Preisregeln aus dem Request.
	 *
	 * @return array
	 */
	protected function rules_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- per check_ajax() geprüft.
		$cats = array();
		$ids  = isset( $_POST['rule_cat'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['rule_cat'] ) ) : array();
		$pcts = isset( $_POST['rule_pct'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rule_pct'] ) ) : array();
		foreach ( $ids as $i => $tid ) {
			if ( $tid > 0 && isset( $pcts[ $i ] ) && '' !== trim( $pcts[ $i ] ) ) {
				$cats[ $tid ] = $pcts[ $i ];
			}
		}
		$rules = array(
			'global'     => isset( $_POST['rule_global'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_global'] ) ) : 0,
			'categories' => $cats,
			'rounding'   => isset( $_POST['rule_rounding'] ) ? sanitize_key( wp_unslash( $_POST['rule_rounding'] ) ) : 'none',
		);
		// phpcs:enable
		return WCIS_Pricing::sanitize_rules( $rules, WCIS_Pricing::limit_min(), WCIS_Pricing::limit_max() );
	}

	/**
	 * Prüft, ob Preisregeln hier verfügbar sind (sonst JSON-Fehler).
	 */
	protected function require_pricing() {
		if ( ! WCIS_Pricing::is_available() ) {
			wp_send_json_error( array( 'message' => __( 'Preisregeln sind in diesem Shop nicht verfügbar.', 'blocksocial-woocommerce-sync' ) ) );
		}
	}

	/**
	 * AJAX: Regeln nur speichern (greifen bei künftigen Preis-Updates).
	 */
	public function ajax_pricing_save() {
		$this->check_ajax();
		$this->require_pricing();
		WCIS_Settings::update( array( 'price_rules' => $this->rules_from_request() ) );
		wp_send_json_success( array( 'message' => __( 'Preisregeln gespeichert.', 'blocksocial-woocommerce-sync' ) ) );
	}

	/**
	 * AJAX: Vorschau mit den (ungespeicherten) Regeln.
	 */
	public function ajax_pricing_preview() {
		$this->check_ajax();
		$this->require_pricing();
		wp_send_json_success( WCIS_Pricing::preview( $this->rules_from_request() ) );
	}

	/**
	 * AJAX: Regeln speichern und Massen-Anwendung starten.
	 */
	public function ajax_pricing_start() {
		$this->check_ajax();
		$this->require_pricing();
		$rules = ! empty( $_POST['reset'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? WCIS_Pricing::sanitize_rules( array() )
			: $this->rules_from_request();
		WCIS_Settings::update( array( 'price_rules' => $rules ) );
		$job = WCIS_Pricing::job_start();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Pricing::job_to_response( $job ) );
	}

	/**
	 * AJAX: nächster Abschnitt.
	 */
	public function ajax_pricing_tick() {
		$this->check_ajax();
		$job = WCIS_Pricing::job_tick();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Pricing::job_to_response( $job ) );
	}

	/**
	 * AJAX: abbrechen.
	 */
	public function ajax_pricing_cancel() {
		$this->check_ajax();
		WCIS_Pricing::job_cancel();
		wp_send_json_success( WCIS_Pricing::job_to_response( WCIS_Pricing::job_state() ) );
	}

	// -------------------------------------------------------------------------
	// Partner-Verwaltung (Admin-Plugin)
	// -------------------------------------------------------------------------

	/**
	 * Redirect auf einen bestimmten Reiter.
	 *
	 * @param string $notice Meldung.
	 * @param string $tab    Reiter.
	 * @param array  $extra  Weitere Query-Parameter.
	 */
	protected function redirect_tab( $notice, $tab, array $extra = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'        => self::SLUG,
						'wcis_notice' => $notice,
						'wcis_tab'    => $tab,
					),
					$extra
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Legt einen Partner an.
	 */
	public function handle_partner_add() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_add' );

		$p = WCIS_Partners::create(
			isset( $_POST['partner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_name'] ) ) : '',
			isset( $_POST['partner_url'] ) ? esc_url_raw( wp_unslash( $_POST['partner_url'] ) ) : '',
			isset( $_POST['partner_scope'] ) ? sanitize_key( wp_unslash( $_POST['partner_scope'] ) ) : 'all',
			isset( $_POST['partner_categories'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['partner_categories'] ) ) : array()
		);
		if ( is_wp_error( $p ) ) {
			set_transient( 'wcis_admin_error_' . get_current_user_id(), $p->get_error_message(), 120 );
			$this->redirect_tab( 'partner_error', 'partners' );
		}
		$this->redirect_tab( 'partner_added', 'partners', array( 'wcis_show_code' => $p['key'] ) );
	}

	/**
	 * Aktualisiert Name/Sortiment eines Partners.
	 */
	public function handle_partner_update() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_update' );
		$key = isset( $_POST['partner_key'] ) ? sanitize_key( wp_unslash( $_POST['partner_key'] ) ) : '';
		WCIS_Partners::update(
			$key,
			array(
				'name'       => isset( $_POST['partner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_name'] ) ) : '',
				'scope'      => isset( $_POST['partner_scope'] ) ? sanitize_key( wp_unslash( $_POST['partner_scope'] ) ) : 'all',
				'categories' => isset( $_POST['partner_categories'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['partner_categories'] ) ) : array(),
			)
		);
		$this->push_managed( $key );
		$this->redirect_tab( 'partner_updated', 'partners' );
	}

	/**
	 * Partner-Aktionen: sperren/entsperren, neuer Schlüssel, löschen.
	 */
	public function handle_partner_action() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_action' );
		$key    = isset( $_POST['partner_key'] ) ? sanitize_key( wp_unslash( $_POST['partner_key'] ) ) : '';
		$action = isset( $_POST['partner_do'] ) ? sanitize_key( wp_unslash( $_POST['partner_do'] ) ) : '';
		$p      = WCIS_Partners::get( $key );
		if ( ! $p ) {
			$this->redirect_tab( 'partner_missing', 'partners' );
		}
		switch ( $action ) {
			case 'block':
				WCIS_Partners::update( $key, array( 'active' => false ) );
				WCIS_Logger::info( sprintf( 'Partner „%s" gesperrt.', $p['name'] ), 'outbound' );
				$this->redirect_tab( 'partner_blocked', 'partners' );
				break;
			case 'unblock':
				WCIS_Partners::update( $key, array( 'active' => true ) );
				WCIS_Logger::info( sprintf( 'Partner „%s" entsperrt.', $p['name'] ), 'outbound' );
				$this->redirect_tab( 'partner_unblocked', 'partners' );
				break;
			case 'rotate':
				$n = WCIS_Partners::rotate( $key );
				$this->redirect_tab( 'partner_rotated', 'partners', array( 'wcis_show_code' => $n ? $n['key'] : '' ) );
				break;
			case 'delete':
				WCIS_Partners::delete( $key );
				$this->redirect_tab( 'partner_deleted', 'partners' );
				break;
		}
		$this->redirect_tab( '', 'partners' );
	}

	/**
	 * Speichert die Partner-Vorgaben und verteilt sie an alle Partner.
	 */
	public function handle_partner_policy() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_policy' );
		$raw = array();
		foreach ( array( 'update_existing', 'update_prices', 'images', 'sync_status', 'allow_price_rules', 'stock_decrease_only' ) as $k ) {
			$raw[ $k ] = ! empty( $_POST[ 'policy_' . $k ] );
		}
		$raw['price_min'] = isset( $_POST['policy_price_min'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['policy_price_min'] ) ) ) : -50;
		$raw['price_max'] = isset( $_POST['policy_price_max'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['policy_price_max'] ) ) ) : 300;
		WCIS_Settings::update( array( 'partner_policy' => WCIS_Partners::sanitize_policy( $raw ) ) );

		$ok   = 0;
		$fail = 0;
		foreach ( WCIS_Partners::active() as $p ) {
			if ( $this->push_managed( $p['key'] ) ) {
				$ok++;
			} else {
				$fail++;
			}
		}
		set_transient( 'wcis_pushconfig_result', array( 'ok' => $ok, 'fail' => $fail ), 120 );
		$this->redirect_tab( 'policy_saved', 'partners' );
	}

	/**
	 * Überträgt die Vorgaben an einen Partner.
	 *
	 * @param string $key Key-ID.
	 * @return bool
	 */
	protected function push_managed( $key ) {
		$p = WCIS_Partners::get( $key );
		if ( ! $p || empty( $p['active'] ) || ! WCIS_Settings::is_master() ) {
			return false;
		}
		$res = WCIS_Client::post( $p['url'], '/config', array( 'managed' => WCIS_Partners::managed_config( $p ) ), true, 15 );
		return ! is_wp_error( $res ) && $res['code'] >= 200 && $res['code'] < 300;
	}

	/**
	 * AJAX: Verbindungstest zu einem Partner.
	 */
	public function ajax_partner_test() {
		$this->check_ajax();
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$p   = WCIS_Partners::get( $key );
		if ( ! $p ) {
			wp_send_json_error( array( 'message' => __( 'Partner nicht gefunden.', 'blocksocial-woocommerce-sync' ) ) );
		}
		$res = WCIS_Client::get( $p['url'], '/ping' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		if ( 200 !== $res['code'] ) {
			$msg = 401 === $res['code']
				? __( 'Shop erreichbar, aber noch nicht mit diesem Verbindungscode eingerichtet (401).', 'blocksocial-woocommerce-sync' )
				: ( 403 === $res['code'] ? __( 'Partner-Plugin noch nicht verbunden oder deaktiviert (403).', 'blocksocial-woocommerce-sync' ) : sprintf( __( 'Fehler: HTTP %d.', 'blocksocial-woocommerce-sync' ), $res['code'] ) );
			wp_send_json_error( array( 'message' => $msg ) );
		}
		$data = json_decode( $res['body'], true );
		$this->push_managed( $key );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: Shop-Name, 2: Version */
					__( 'Verbunden mit „%1$s" (Partner-Plugin v%2$s). Vorgaben übertragen.', 'blocksocial-woocommerce-sync' ),
					isset( $data['shop'] ) ? $data['shop'] : '?',
					isset( $data['version'] ) ? $data['version'] : '?'
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Shopify (Admin-Plugin)
	// -------------------------------------------------------------------------

	/**
	 * Speichert (neu/bearbeitet) einen Shopify-Shop.
	 */
	public function handle_shopify_save() {
		$this->require_cap();
		check_admin_referer( 'wcis_shopify_save' );
		$id   = isset( $_POST['shopify_id'] ) ? sanitize_key( wp_unslash( $_POST['shopify_id'] ) ) : '';
		$data = array(
			'name'           => isset( $_POST['shopify_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_name'] ) ) : '',
			'domain'         => isset( $_POST['shopify_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_domain'] ) ) : '',
			'auth'           => isset( $_POST['shopify_auth'] ) ? sanitize_key( wp_unslash( $_POST['shopify_auth'] ) ) : 'client',
			'client_id'      => isset( $_POST['shopify_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_client_id'] ) ) : '',
			'client_secret'  => isset( $_POST['shopify_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_client_secret'] ) ) : '',
			'token'          => isset( $_POST['shopify_token'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_token'] ) ) : '',
			'webhook_secret' => isset( $_POST['shopify_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_webhook_secret'] ) ) : '',
			'active'         => ! empty( $_POST['shopify_active'] ),
			'sync_stock'     => ! empty( $_POST['shopify_sync_stock'] ),
			'sync_products'  => ! empty( $_POST['shopify_sync_products'] ),
			'sync_sale'      => ! empty( $_POST['shopify_sync_sale'] ),
			'product_status' => ( isset( $_POST['shopify_product_status'] ) && 'active' === sanitize_key( wp_unslash( $_POST['shopify_product_status'] ) ) ) ? 'ACTIVE' : 'DRAFT',
			'scope'          => isset( $_POST['shopify_scope'] ) ? sanitize_key( wp_unslash( $_POST['shopify_scope'] ) ) : 'all',
			'categories'     => isset( $_POST['shopify_categories'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['shopify_categories'] ) ) : array(),
		);
		// Preisregeln dieses Shopify-Shops.
		$cats = array();
		$ids  = isset( $_POST['rule_cat'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['rule_cat'] ) ) : array();
		$pcts = isset( $_POST['rule_pct'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rule_pct'] ) ) : array();
		foreach ( $ids as $i => $tid ) {
			if ( $tid > 0 && isset( $pcts[ $i ] ) && '' !== trim( $pcts[ $i ] ) ) {
				$cats[ $tid ] = $pcts[ $i ];
			}
		}
		$data['price_rules'] = array(
			'global'     => isset( $_POST['rule_global'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_global'] ) ) : 0,
			'categories' => $cats,
			'rounding'   => isset( $_POST['rule_rounding'] ) ? sanitize_key( wp_unslash( $_POST['rule_rounding'] ) ) : 'none',
		);

		$s = WCIS_Shopify::save_store( $data, $id );
		if ( is_wp_error( $s ) ) {
			set_transient( 'wcis_admin_error_' . get_current_user_id(), $s->get_error_message(), 120 );
			$this->redirect_tab( 'shopify_error', 'shopify' );
		}
		$this->redirect_tab( 'shopify_saved', 'shopify' );
	}

	/**
	 * Entfernt einen Shopify-Shop.
	 */
	public function handle_shopify_delete() {
		$this->require_cap();
		check_admin_referer( 'wcis_shopify_delete' );
		$id = isset( $_POST['shopify_id'] ) ? sanitize_key( wp_unslash( $_POST['shopify_id'] ) ) : '';
		WCIS_Shopify::delete_store( $id );
		$this->redirect_tab( 'shopify_deleted', 'shopify' );
	}

	/**
	 * AJAX: Verbindungstest Shopify.
	 */
	public function ajax_shopify_test() {
		$this->check_ajax();
		$id  = isset( $_POST['store'] ) ? sanitize_key( wp_unslash( $_POST['store'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$res = WCIS_Shopify::test( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Shopify-Übertragung starten.
	 */
	public function ajax_shopify_start() {
		$this->check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification
		$id   = isset( $_POST['store'] ) ? sanitize_key( wp_unslash( $_POST['store'] ) ) : '';
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'stock';
		// phpcs:enable
		$job = WCIS_Shopify::job_start( $id, $mode );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Shopify::job_to_response( $job ) );
	}

	/**
	 * AJAX: nächster Abschnitt.
	 */
	public function ajax_shopify_tick() {
		$this->check_ajax();
		$job = WCIS_Shopify::job_tick();
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( WCIS_Shopify::job_to_response( $job ) );
	}

	/**
	 * AJAX: abbrechen.
	 */
	public function ajax_shopify_cancel() {
		$this->check_ajax();
		WCIS_Shopify::job_cancel();
		wp_send_json_success( WCIS_Shopify::job_to_response( WCIS_Shopify::job_state() ) );
	}

	// -------------------------------------------------------------------------
	// Partner-Plugin: Verbindung & lokale Optionen
	// -------------------------------------------------------------------------

	/**
	 * Verbindet diesen Partnershop per Verbindungscode mit dem Hauptshop.
	 */
	public function handle_partner_connect() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_connect' );

		$code = isset( $_POST['connection_code'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_code'] ) ) : '';
		$conn = WCIS_Partners::parse_code( $code );
		if ( is_wp_error( $conn ) ) {
			set_transient( 'wcis_admin_error_' . get_current_user_id(), $conn->get_error_message(), 120 );
			$this->redirect_tab( 'partner_error', 'connection' );
		}

		$previous = WCIS_Settings::get( 'partner_conn', array() );
		WCIS_Settings::update( array( 'partner_conn' => array_merge( $conn, array( 'connected_at' => time() ) ) ) );

		$res = WCIS_Partners::refresh_from_master();
		if ( is_wp_error( $res ) ) {
			WCIS_Settings::update( array( 'partner_conn' => $previous ) ); // Fehlschlag → alten Zustand behalten.
			set_transient( 'wcis_admin_error_' . get_current_user_id(), $res->get_error_message(), 120 );
			$this->redirect_tab( 'partner_error', 'connection' );
		}
		WCIS_Logger::info( sprintf( 'Mit Hauptshop %s verbunden.', $conn['master_url'] ), 'outbound' );
		$this->redirect_tab( 'partner_connected', 'connection' );
	}

	/**
	 * Trennt die Verbindung zum Hauptshop.
	 */
	public function handle_partner_disconnect() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_disconnect' );
		WCIS_Settings::update( array( 'partner_conn' => array(), 'managed' => array() ) );
		WCIS_Logger::info( 'Verbindung zum Hauptshop getrennt.', 'outbound' );
		$this->redirect_tab( 'partner_disconnected', 'connection' );
	}

	/**
	 * Holt die Vorgaben erneut vom Hauptshop.
	 */
	public function handle_partner_refresh() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_refresh' );
		$res = WCIS_Partners::refresh_from_master();
		if ( is_wp_error( $res ) ) {
			set_transient( 'wcis_admin_error_' . get_current_user_id(), $res->get_error_message(), 120 );
			$this->redirect_tab( 'partner_error', 'connection' );
		}
		$this->redirect_tab( 'partner_refreshed', 'connection' );
	}

	/**
	 * Speichert die lokalen (vom Partner selbst bestimmbaren) Optionen.
	 */
	public function handle_partner_local_save() {
		$this->require_cap();
		check_admin_referer( 'wcis_partner_local_save' );
		WCIS_Settings::update(
			array(
				'this_shop_name'   => isset( $_POST['this_shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['this_shop_name'] ) ) : get_bloginfo( 'name' ),
				'price_gross_mode'   => ! empty( $_POST['price_gross_mode'] ),
				'accept_sale_prices' => ! empty( $_POST['accept_sale_prices'] ),
				'tax_class_map'    => isset( $_POST['tax_class_map'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tax_class_map'] ) ) : '',
				'log_level'        => ( isset( $_POST['log_level'] ) && 'error' === $_POST['log_level'] ) ? 'error' : 'info',
			)
		);
		$this->redirect_tab( 'saved', 'products' );
	}

	// -------------------------------------------------------------------------
	// CSV-Feeds (Admin-Plugin)
	// -------------------------------------------------------------------------

	/**
	 * Speichert (neu/bearbeitet) einen CSV-Feed.
	 */
	public function handle_feed_save() {
		$this->require_cap();
		check_admin_referer( 'wcis_feed_save' );
		$id   = isset( $_POST['feed_id'] ) ? sanitize_key( wp_unslash( $_POST['feed_id'] ) ) : '';
		$cats = array();
		$ids  = isset( $_POST['rule_cat'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['rule_cat'] ) ) : array();
		$pcts = isset( $_POST['rule_pct'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rule_pct'] ) ) : array();
		foreach ( $ids as $i => $tid ) {
			if ( $tid > 0 && isset( $pcts[ $i ] ) && '' !== trim( $pcts[ $i ] ) ) {
				$cats[ $tid ] = $pcts[ $i ];
			}
		}
		WCIS_Feeds::save_feed(
			array(
				'name'         => isset( $_POST['feed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_name'] ) ) : '',
				'active'       => ! empty( $_POST['feed_active'] ),
				'scope'        => isset( $_POST['feed_scope'] ) ? sanitize_key( wp_unslash( $_POST['feed_scope'] ) ) : 'all',
				'categories'   => isset( $_POST['feed_categories'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['feed_categories'] ) ) : array(),
				'prices'       => isset( $_POST['feed_prices'] ) ? sanitize_key( wp_unslash( $_POST['feed_prices'] ) ) : 'gross',
				'delimiter'    => isset( $_POST['feed_delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_delimiter'] ) ) : ';',
				'bom'          => ! empty( $_POST['feed_bom'] ),
				'only_instock' => ! empty( $_POST['feed_only_instock'] ),
				'parents'      => ! empty( $_POST['feed_parents'] ),
				'descriptions' => ! empty( $_POST['feed_descriptions'] ),
				'sale_prices'  => ! empty( $_POST['feed_sale_prices'] ),
				'price_rules'  => array(
					'global'     => isset( $_POST['rule_global'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_global'] ) ) : 0,
					'categories' => $cats,
					'rounding'   => isset( $_POST['rule_rounding'] ) ? sanitize_key( wp_unslash( $_POST['rule_rounding'] ) ) : 'none',
				),
			),
			$id
		);
		$this->redirect_tab( 'feed_saved', 'feeds' );
	}

	/**
	 * CSV-Feed-Aktionen: herunterladen, neu erzeugen, neuer Schlüssel, löschen.
	 */
	public function handle_feed_action() {
		$this->require_cap();
		check_admin_referer( 'wcis_feed_action' );
		$id = isset( $_POST['feed_id'] ) ? sanitize_key( wp_unslash( $_POST['feed_id'] ) ) : '';
		$do = isset( $_POST['feed_do'] ) ? sanitize_key( wp_unslash( $_POST['feed_do'] ) ) : '';
		$f  = WCIS_Feeds::get( $id );
		if ( ! $f ) {
			$this->redirect_tab( 'feed_missing', 'feeds' );
		}
		switch ( $do ) {
			case 'download':
				WCIS_Feeds::download( $id ); // beendet den Request.
				break;
			case 'regenerate':
				// Kompletter Neuaufbau: alle Zeilen neu berechnen (jetzt soweit möglich, Rest im Hintergrund).
				WCIS_Feeds::reset_rows( $id );
				WCIS_Feeds::refresh( WCIS_Feeds::get( $id ), 20.0 );
				wp_schedule_single_event( time(), 'wcis_feed_build' );
				$this->redirect_tab( 'feed_generated', 'feeds' );
				break;
			case 'rotate':
				WCIS_Feeds::rotate( $id );
				$this->redirect_tab( 'feed_rotated', 'feeds' );
				break;
			case 'delete':
				WCIS_Feeds::delete( $id );
				$this->redirect_tab( 'feed_deleted', 'feeds' );
				break;
		}
		$this->redirect_tab( '', 'feeds' );
	}
}
