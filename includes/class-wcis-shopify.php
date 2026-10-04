<?php
/**
 * Shopify-Anbindung (Admin-Edition, Hauptshop).
 *
 * Der Hauptshop beliefert angebundene Shopify-Shops mit Beständen und optional
 * mit Produkten (inkl. eigener Preisregeln je Shopify-Shop). Verkäufe im
 * Shopify-Shop kommen per Webhook zurück und werden an alle Shops verteilt.
 *
 * Bestands-Abgleich ohne verlorene Verkäufe (Compare-and-Swap):
 * - Für jeden Artikel merkt sich der Hauptshop den zuletzt an Shopify
 *   übertragenen Bestand („last").
 * - Beim Übertragen wird Shopify gebeten, den Bestand NUR zu ändern, wenn er
 *   noch „last" ist (changeFromQuantity). Hat sich der Shopify-Bestand
 *   zwischenzeitlich geändert (Verkauf), wird die Differenz zuerst auf den
 *   Hauptshop gebucht und dann der neue Bestand übertragen.
 * - Webhooks lösen denselben Abgleich aus; das „Echo" unserer eigenen
 *   Übertragung ergibt Differenz 0 und wird ignoriert.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shopify-Shops, Bestands-Sync, Produktexport, Webhooks und Jobs.
 */
class WCIS_Shopify {

	/**
	 * Option mit den Shopify-Shops (enthält Zugangsdaten – nicht autoloaden).
	 */
	const OPT = 'wcis_shopify_stores';

	/**
	 * Options-Schlüssel des Massen-Jobs.
	 */
	const JOB_OPT = 'wcis_shopify_job';

	/**
	 * Transient mit den Produkt-IDs des Jobs.
	 */
	const IDS_TR = 'wcis_shopify_ids';

	/**
	 * Zeitbudget pro Tick (Sekunden).
	 */
	const TICK_BUDGET = 8.0;

	/**
	 * Präfix der Queue-Ziele für Shopify.
	 */
	const QUEUE_PREFIX = 'shopify://';

	/**
	 * Zwischenspeicher.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Im aktuellen Request vorgemerkte Webhook-Verarbeitungen.
	 *
	 * @var array
	 */
	protected static $webhooks = array();

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	// -------------------------------------------------------------------------
	// Registry
	// -------------------------------------------------------------------------

	/**
	 * Standardwerte eines Shopify-Shops.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'             => '',
			'name'           => '',
			'domain'         => '',
			'auth'           => 'client', // client | token
			'token'          => '',       // shpat_… (Legacy Custom App)
			'client_id'      => '',
			'client_secret'  => '',       // auch Webhook-Signaturschlüssel
			'webhook_secret' => '',       // optional abweichend (Legacy: API secret key)
			'access_token'   => '',
			'token_expires'  => 0,
			'active'         => true,
			'sync_stock'     => true,
			'sync_products'  => false,
			'sync_sale'      => true,  // Angebotspreise (als Vergleichspreis) übertragen.
			'product_status' => 'DRAFT', // neue Produkte zunächst als Entwurf
			'scope'          => 'all',
			'categories'     => array(),
			'price_rules'    => array( 'global' => 0, 'categories' => array(), 'rounding' => 'none' ),
			'location_id'    => '',
			'location_name'  => '',
			'shop_name'      => '',
			'currency'       => '',
			'taxes_included' => true,
			'webhook_id'     => '',
			'last_test'      => 0,
			'last_error'     => '',
		);
	}

	/**
	 * Alle Shops (ID => Shop).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$list = get_option( self::OPT, array() );
			$list = is_array( $list ) ? $list : array();
			foreach ( $list as $id => $s ) {
				$list[ $id ] = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
			}
			self::$cache = $list;
		}
		return self::$cache;
	}

	/**
	 * Liest die Liste frisch aus der Datenbank (vor Schreibvorgängen).
	 *
	 * @return array
	 */
	protected static function fresh() {
		self::$cache = null;
		wp_cache_delete( self::OPT, 'options' );
		return self::all();
	}

	/**
	 * Speichert die Liste.
	 *
	 * @param array $list Shops.
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
	 * Shop per ID.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Legt einen Shop an oder aktualisiert ihn (Formulardaten).
	 *
	 * @param array  $data Daten.
	 * @param string $id   Vorhandene ID oder '' (neu).
	 * @return array|WP_Error Shop.
	 */
	public static function save_store( array $data, $id = '' ) {
		$all     = self::fresh();
		$is_new  = ( '' === $id || ! isset( $all[ $id ] ) );
		$current = $is_new ? self::defaults() : $all[ $id ];

		$domain = isset( $data['domain'] ) ? WCIS_Shopify_Api::normalize_domain( $data['domain'] ) : $current['domain'];
		if ( '' === $domain ) {
			return new WP_Error( 'wcis_shopify_domain', __( 'Bitte die Shopify-Adresse im Format „meinshop.myshopify.com" angeben.', 'blocksocial-woocommerce-sync' ) );
		}
		foreach ( $all as $sid => $s ) {
			if ( $sid !== $id && $s['domain'] === $domain ) {
				return new WP_Error( 'wcis_shopify_exists', __( 'Dieser Shopify-Shop ist bereits angebunden.', 'blocksocial-woocommerce-sync' ) );
			}
		}

		$s           = $current;
		$s['domain'] = $domain;
		$s['name']   = isset( $data['name'] ) && '' !== trim( $data['name'] ) ? sanitize_text_field( $data['name'] ) : ( $s['name'] ? $s['name'] : $domain );
		$s['auth']   = ( isset( $data['auth'] ) && 'token' === $data['auth'] ) ? 'token' : 'client';

		// Geheimnisse: leeres Feld = unverändert lassen (werden nie im Formular angezeigt).
		foreach ( array( 'token', 'client_secret', 'webhook_secret' ) as $k ) {
			if ( isset( $data[ $k ] ) && '' !== trim( (string) $data[ $k ] ) ) {
				$s[ $k ] = trim( sanitize_text_field( $data[ $k ] ) );
			}
		}
		if ( isset( $data['client_id'] ) ) {
			$s['client_id'] = trim( sanitize_text_field( $data['client_id'] ) );
		}
		if ( $current['client_id'] !== $s['client_id'] || $current['client_secret'] !== $s['client_secret'] || $current['domain'] !== $s['domain'] ) {
			$s['access_token']  = '';
			$s['token_expires'] = 0;
		}

		foreach ( array( 'active', 'sync_stock', 'sync_products', 'sync_sale' ) as $k ) {
			$s[ $k ] = ! empty( $data[ $k ] );
		}
		$s['product_status'] = ( isset( $data['product_status'] ) && 'ACTIVE' === $data['product_status'] ) ? 'ACTIVE' : 'DRAFT';
		$s['scope']          = ( isset( $data['scope'] ) && 'categories' === $data['scope'] ) ? 'categories' : 'all';
		$s['categories']     = isset( $data['categories'] ) ? array_values( array_filter( array_map( 'intval', (array) $data['categories'] ) ) ) : array();
		if ( isset( $data['price_rules'] ) ) {
			$s['price_rules'] = WCIS_Pricing::sanitize_rules( $data['price_rules'] );
		}

		if ( 'client' === $s['auth'] && ( '' === $s['client_id'] || '' === $s['client_secret'] ) ) {
			return new WP_Error( 'wcis_shopify_cred', __( 'Bitte Client-ID und Client-Secret der Shopify-App angeben.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'token' === $s['auth'] && '' === $s['token'] ) {
			return new WP_Error( 'wcis_shopify_cred', __( 'Bitte den Admin-API-Zugriffstoken (shpat_…) angeben.', 'blocksocial-woocommerce-sync' ) );
		}

		if ( $is_new ) {
			$s['id'] = 'shp_' . strtolower( wp_generate_password( 10, false, false ) );
		}
		$all[ $s['id'] ] = $s;
		self::save_all( $all );
		return $s;
	}

	/**
	 * Aktualisiert einzelne Felder (intern).
	 *
	 * @param string $id     ID.
	 * @param array  $fields Felder.
	 */
	public static function update_store( $id, array $fields ) {
		$all = self::fresh();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ] = array_merge( $all[ $id ], $fields );
		self::save_all( $all );
	}

	/**
	 * Entfernt einen Shop (inkl. Webhook und Zuordnungen).
	 *
	 * @param string $id ID.
	 */
	public static function delete_store( $id ) {
		$s = self::get( $id );
		if ( ! $s ) {
			return;
		}
		if ( ! empty( $s['webhook_id'] ) ) {
			$api = new WCIS_Shopify_Api( $s );
			$api->query( 'mutation($id:ID!){ webhookSubscriptionDelete(id:$id){ deletedWebhookSubscriptionId userErrors{ field message } } }', array( 'id' => $s['webhook_id'] ) );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wcis_shp_' . $id . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$all = self::fresh();
		unset( $all[ $id ] );
		self::save_all( $all );
		WCIS_Logger::info( sprintf( 'Shopify-Shop „%s" entfernt.', $s['name'] ), 'outbound' );
	}

	/**
	 * Aktive Shops (optional gefiltert nach Funktion).
	 *
	 * @param string $feature '' | 'stock' | 'products'.
	 * @return array
	 */
	public static function active_stores( $feature = '' ) {
		return array_filter(
			self::all(),
			static function ( $s ) use ( $feature ) {
				if ( empty( $s['active'] ) || empty( $s['location_id'] ) ) {
					return false;
				}
				if ( 'stock' === $feature ) {
					return ! empty( $s['sync_stock'] );
				}
				if ( 'products' === $feature ) {
					return ! empty( $s['sync_products'] );
				}
				return true;
			}
		);
	}

	/**
	 * Ist die Shopify-Anbindung in diesem Shop wirksam (nur Hauptshop)?
	 *
	 * @return bool
	 */
	public static function is_hub() {
		return WCIS_Edition::is_admin_edition() && WCIS_Settings::is_master() && WCIS_Settings::is_enabled();
	}

	/**
	 * Gehört ein Produkt zum Sortiment des Shopify-Shops?
	 *
	 * @param array      $s       Shop.
	 * @param WC_Product $product Produkt/Variation.
	 * @return bool
	 */
	public static function allows_product( array $s, $product ) {
		if ( ! $product instanceof WC_Product || WCIS_Filter::is_excluded( $product ) ) {
			return false;
		}
		return WCIS_Partners::allows_product(
			array(
				'scope'      => $s['scope'],
				'categories' => $s['categories'],
			),
			$product
		);
	}

	// -------------------------------------------------------------------------
	// Verbindung & Webhook
	// -------------------------------------------------------------------------

	/**
	 * Verbindungstest: liest Shopdaten + Standard-Lagerort und richtet den
	 * Bestands-Webhook ein.
	 *
	 * @param string $id Shop-ID.
	 * @return array|WP_Error { message }.
	 */
	public static function test( $id ) {
		$s = self::get( $id );
		if ( ! $s ) {
			return new WP_Error( 'wcis_shopify_missing', __( 'Shopify-Shop nicht gefunden.', 'blocksocial-woocommerce-sync' ) );
		}
		$api  = new WCIS_Shopify_Api( $s );
		$data = $api->query( '{ shop { name myshopifyDomain currencyCode taxesIncluded } location { id name } }' );
		if ( is_wp_error( $data ) ) {
			self::update_store( $id, array( 'last_error' => $data->get_error_message(), 'last_test' => time() ) );
			return $data;
		}

		$fields = array(
			'shop_name'      => isset( $data['shop']['name'] ) ? sanitize_text_field( $data['shop']['name'] ) : '',
			'currency'       => isset( $data['shop']['currencyCode'] ) ? sanitize_text_field( $data['shop']['currencyCode'] ) : '',
			'taxes_included' => ! empty( $data['shop']['taxesIncluded'] ),
			'last_test'      => time(),
			'last_error'     => '',
		);
		if ( empty( $s['location_id'] ) && ! empty( $data['location']['id'] ) ) {
			$fields['location_id']   = sanitize_text_field( $data['location']['id'] );
			$fields['location_name'] = isset( $data['location']['name'] ) ? sanitize_text_field( $data['location']['name'] ) : '';
		}
		self::update_store( $id, $fields );

		$wh  = self::ensure_webhook( $id );
		$msg = sprintf(
			/* translators: 1: Shopname, 2: Währung, 3: Lagerort, 4: brutto/netto */
			__( 'Verbunden mit „%1$s" (%2$s). Lagerort: %3$s. Preise in Shopify: %4$s.', 'blocksocial-woocommerce-sync' ),
			$fields['shop_name'],
			$fields['currency'],
			isset( $fields['location_name'] ) ? $fields['location_name'] : $s['location_name'],
			$fields['taxes_included'] ? __( 'inkl. MwSt. (brutto)', 'blocksocial-woocommerce-sync' ) : __( 'zzgl. MwSt. (netto)', 'blocksocial-woocommerce-sync' )
		);
		if ( 'EUR' !== $fields['currency'] && function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() !== $fields['currency'] ) {
			$msg .= ' ' . sprintf( __( 'Achtung: Shopify nutzt %1$s, dieser Shop %2$s – Preise werden NICHT umgerechnet.', 'blocksocial-woocommerce-sync' ), $fields['currency'], get_woocommerce_currency() );
		}
		if ( is_wp_error( $wh ) ) {
			$msg .= ' ' . sprintf( __( 'Webhook konnte nicht eingerichtet werden: %s', 'blocksocial-woocommerce-sync' ), $wh->get_error_message() );
		}
		return array( 'message' => $msg );
	}

	/**
	 * Webhook-URL für einen Shop.
	 *
	 * @param string $id Shop-ID.
	 * @return string
	 */
	public static function webhook_url( $id ) {
		return rest_url( WCIS_REST_NS . '/shopify/webhook/' . $id );
	}

	/**
	 * Richtet den Bestands-Webhook (inventory_levels/update) ein, falls nötig.
	 *
	 * @param string $id Shop-ID.
	 * @return true|WP_Error
	 */
	public static function ensure_webhook( $id ) {
		$s = self::get( $id );
		if ( ! $s || empty( $s['sync_stock'] ) ) {
			return true;
		}
		$api = new WCIS_Shopify_Api( $s );
		$uri = self::webhook_url( $id );

		$data = $api->query( '{ webhookSubscriptions(first: 50, topics: [INVENTORY_LEVELS_UPDATE]) { nodes { id uri } } }' );
		if ( ! is_wp_error( $data ) && ! empty( $data['webhookSubscriptions']['nodes'] ) ) {
			foreach ( $data['webhookSubscriptions']['nodes'] as $n ) {
				if ( isset( $n['uri'] ) && $n['uri'] === $uri ) {
					self::update_store( $id, array( 'webhook_id' => $n['id'] ) );
					return true;
				}
			}
		}

		$data = $api->query(
			'mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) { webhookSubscription { id } userErrors { field message } } }',
			array(
				'topic' => 'INVENTORY_LEVELS_UPDATE',
				'sub'   => array(
					'uri'    => $uri,
					'format' => 'JSON',
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$err = WCIS_Shopify_Api::user_errors( isset( $data['webhookSubscriptionCreate']['userErrors'] ) ? $data['webhookSubscriptionCreate']['userErrors'] : array() );
		if ( $err ) {
			return $err;
		}
		self::update_store( $id, array( 'webhook_id' => (string) $data['webhookSubscriptionCreate']['webhookSubscription']['id'] ) );
		WCIS_Logger::info( sprintf( 'Shopify-Webhook für „%s" eingerichtet.', $s['name'] ), 'outbound' );
		return true;
	}

	/**
	 * REST-Route für Shopify-Webhooks (von Shopify signiert, nicht vom Verbund).
	 */
	public static function register_routes() {
		register_rest_route(
			WCIS_REST_NS,
			'/shopify/webhook/(?P<store>shp_[a-z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive_webhook' ),
				// Authentifizierung erfolgt über die Shopify-HMAC-Signatur im Callback.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Empfängt einen Shopify-Webhook. Antwortet sofort (Shopify erwartet < 5 s)
	 * und verarbeitet am Request-Ende.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive_webhook( $request ) {
		$id = (string) $request['store'];
		$s  = self::get( $id );
		if ( ! $s || empty( $s['active'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}

		$secret = '' !== $s['webhook_secret'] ? $s['webhook_secret'] : $s['client_secret'];
		$hmac   = (string) $request->get_header( 'x_shopify_hmac_sha256' );
		$calc   = base64_encode( hash_hmac( 'sha256', $request->get_body(), (string) $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		if ( '' === (string) $secret || '' === $hmac || ! hash_equals( $calc, $hmac ) ) {
			WCIS_Logger::error( sprintf( 'Shopify-Webhook für „%s" mit ungültiger Signatur abgelehnt.', $s['name'] ), 'inbound' );
			return new WP_REST_Response( array( 'ok' => false ), 401 );
		}

		$topic = (string) $request->get_header( 'x_shopify_topic' );
		if ( 'inventory_levels/update' !== $topic || ! self::is_hub() || empty( $s['sync_stock'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}

		// Doppelte Zustellungen (Shopify-Retries) nur einmal verarbeiten.
		$wid = (string) $request->get_header( 'x_shopify_webhook_id' );
		if ( '' !== $wid ) {
			$k = 'wcis_shpwh_' . md5( $wid );
			if ( get_transient( $k ) ) {
				return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
			}
			set_transient( $k, 1, DAY_IN_SECONDS );
		}

		$p = $request->get_json_params();
		if ( empty( $p['inventory_item_id'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		self::$webhooks[] = array(
			'store'    => $id,
			'item'     => 'gid://shopify/InventoryItem/' . preg_replace( '/\D/', '', (string) $p['inventory_item_id'] ),
			'location' => isset( $p['location_id'] ) ? 'gid://shopify/Location/' . preg_replace( '/\D/', '', (string) $p['location_id'] ) : '',
		);
		if ( 1 === count( self::$webhooks ) ) {
			add_action( 'shutdown', array( __CLASS__, 'process_webhooks' ), 0 );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Verarbeitet vorgemerkte Webhooks (nach der Antwort an Shopify).
	 */
	public static function process_webhooks() {
		$list           = self::$webhooks;
		self::$webhooks = array();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		foreach ( $list as $w ) {
			$s = self::get( $w['store'] );
			if ( ! $s || ( '' !== $w['location'] && $w['location'] !== $s['location_id'] ) ) {
				continue; // anderer Lagerort → nicht maßgeblich.
			}
			$product = self::product_for_item( $s, $w['item'] );
			if ( ! $product ) {
				continue;
			}
			$res = self::sync_product_stock( $s, $product, true );
			if ( is_wp_error( $res ) ) {
				WCIS_Logger::error( sprintf( 'Shopify „%s": Webhook-Abgleich für SKU %s fehlgeschlagen: %s', $s['name'], $product->get_sku(), $res->get_error_message() ), 'inbound' );
			}
		}
		// Weiterleitungen sofort ausführen (der Shutdown-Hook läuft bereits).
		WCIS_Sync_Engine::dispatch_forward();
	}

	/**
	 * Findet das WooCommerce-Produkt zu einem Shopify-Inventory-Item.
	 *
	 * @param array  $s    Shop.
	 * @param string $item Inventory-Item-GID.
	 * @return WC_Product|null
	 */
	protected static function product_for_item( array $s, $item ) {
		global $wpdb;
		$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::meta_key( $s, 'item' ), $item ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				return $p;
			}
		}
		$api  = new WCIS_Shopify_Api( $s );
		$data = $api->query( 'query($id:ID!){ inventoryItem(id:$id){ id sku variants(first:1){ nodes{ id product{ id } } } } }', array( 'id' => $item ) );
		if ( is_wp_error( $data ) || empty( $data['inventoryItem']['sku'] ) ) {
			return null;
		}
		$wid = wc_get_product_id_by_sku( (string) $data['inventoryItem']['sku'] );
		$p   = $wid ? wc_get_product( $wid ) : null;
		if ( ! $p || $p->is_type( 'variable' ) ) {
			return null;
		}
		update_post_meta( $p->get_id(), self::meta_key( $s, 'item' ), $item );
		if ( ! empty( $data['inventoryItem']['variants']['nodes'][0]['id'] ) ) {
			update_post_meta( $p->get_id(), self::meta_key( $s, 'vid' ), $data['inventoryItem']['variants']['nodes'][0]['id'] );
		}
		return $p;
	}

	// -------------------------------------------------------------------------
	// Bestand
	// -------------------------------------------------------------------------

	/**
	 * Meta-Schlüssel für Shopify-Zuordnungen.
	 *
	 * @param array  $s    Shop.
	 * @param string $what item | last | vid | pid.
	 * @return string
	 */
	public static function meta_key( array $s, $what ) {
		return '_wcis_shp_' . $s['id'] . '_' . $what;
	}

	/**
	 * Überträgt die Bestände der angegebenen SKUs an alle aktiven Shopify-Shops
	 * (Echtzeit). Fehler landen in der Retry-Queue.
	 *
	 * @param array  $skus          SKUs.
	 * @param string $exclude_store Shop-ID, der übersprungen wird (Absender).
	 */
	public static function push_skus( array $skus, $exclude_store = '' ) {
		if ( ! self::is_hub() || empty( $skus ) ) {
			return;
		}
		foreach ( self::active_stores( 'stock' ) as $s ) {
			if ( $s['id'] === $exclude_store ) {
				continue;
			}
			$failed = array();
			foreach ( array_unique( $skus ) as $sku ) {
				$pid = wc_get_product_id_by_sku( $sku );
				$p   = $pid ? wc_get_product( $pid ) : null;
				if ( ! $p ) {
					continue;
				}
				$res = self::sync_product_stock( $s, $p, false );
				if ( is_wp_error( $res ) ) {
					$failed[] = $sku;
					WCIS_Logger::error( sprintf( 'Shopify „%s": Bestand für SKU %s nicht übertragen (in Queue): %s', $s['name'], $sku, $res->get_error_message() ), 'outbound' );
				}
			}
			if ( $failed ) {
				WCIS_Queue::add( self::QUEUE_PREFIX . $s['id'], array( 'skus' => $failed ), 'Shopify', '/stock' );
			}
		}
	}

	/**
	 * Reiht ein Produkt zur Übertragung an alle Shopify-Shops mit aktivem
	 * Produkt-Sync ein (neue Produkte, Preis-/Statusänderungen).
	 *
	 * @param WC_Product $product Produkt.
	 */
	public static function queue_product( $product ) {
		if ( ! self::is_hub() || ! $product instanceof WC_Product ) {
			return;
		}
		foreach ( self::active_stores( 'products' ) as $s ) {
			if ( self::allows_product( $s, $product ) ) {
				WCIS_Queue::add( self::QUEUE_PREFIX . $s['id'], array( 'product_id' => $product->get_id() ), '', '/product' );
			}
		}
	}

	/**
	 * Queue-Zustellung (aufgerufen von WCIS_Queue für shopify://-Ziele).
	 *
	 * @param string $target   shopify://ID.
	 * @param array  $payload  { skus } bzw. { product_id }.
	 * @param string $endpoint '/stock' | '/product'.
	 * @return true|WP_Error
	 */
	public static function deliver_queued( $target, array $payload, $endpoint = '/stock' ) {
		$id = substr( $target, strlen( self::QUEUE_PREFIX ) );
		$s  = self::get( $id );
		if ( ! $s || empty( $s['active'] ) || ! self::is_hub() ) {
			return true; // Shop entfernt/deaktiviert → Eintrag verwerfen.
		}
		if ( '/product' === $endpoint ) {
			$product = ! empty( $payload['product_id'] ) ? wc_get_product( (int) $payload['product_id'] ) : null;
			if ( ! $product || empty( $s['sync_products'] ) || ! self::allows_product( $s, $product ) ) {
				return true;
			}
			$r = self::export_product( $s, $product );
			WCIS_Sync_Engine::dispatch_forward();
			return is_wp_error( $r ) ? $r : true;
		}
		$error = null;
		foreach ( (array) ( isset( $payload['skus'] ) ? $payload['skus'] : array() ) as $sku ) {
			$pid = wc_get_product_id_by_sku( (string) $sku );
			$p   = $pid ? wc_get_product( $pid ) : null;
			if ( ! $p ) {
				continue;
			}
			$res = self::sync_product_stock( $s, $p, false );
			if ( is_wp_error( $res ) ) {
				$error = $res;
			}
		}
		if ( $error ) {
			return $error;
		}
		WCIS_Sync_Engine::dispatch_forward();
		return true;
	}

	/**
	 * Maßgeblicher Bestand eines Artikels für Shopify (null = nicht übertragen).
	 *
	 * @param WC_Product $p Produkt/Variation.
	 * @return int|null
	 */
	protected static function master_quantity( $p ) {
		if ( $p->managing_stock() ) {
			return max( 0, (int) $p->get_stock_quantity() );
		}
		return 'outofstock' === $p->get_stock_status() ? 0 : null;
	}

	/**
	 * Gleicht den Bestand eines Artikels mit einem Shopify-Shop ab
	 * (Compare-and-Swap, s. Klassenbeschreibung).
	 *
	 * Absicherungen:
	 * - Sperre je Artikel (kein paralleler Abgleich durch Webhook + Push + Queue),
	 * - „last" wird in jeder Runde frisch aus der Datenbank gelesen,
	 * - unklarer Schreibvorgang (Antwort verloren) wird beim nächsten Mal mit
	 *   demselben Idempotenz-Schlüssel wiederholt → Shopify meldet, ob er schon
	 *   ausgeführt wurde; das „Echo" wird nie als Verkauf gezählt,
	 * - Produkte ohne Bestandsführung in WooCommerce werden in Shopify ohne
	 *   Bestandsführung geführt (verkaufbar) bzw. bei „nicht vorrätig" auf 0 gesetzt.
	 *
	 * @param array      $s            Shop.
	 * @param WC_Product $p            Einfaches Produkt oder Variation.
	 * @param bool       $from_webhook Ausgelöst durch Shopify (Änderung dort)?
	 * @return true|WP_Error
	 */
	public static function sync_product_stock( array $s, $p, $from_webhook ) {
		if ( ! $p instanceof WC_Product || $p->is_type( 'variable' ) || '' === $p->get_sku() ) {
			return true;
		}
		if ( ! self::allows_product( $s, $p ) ) {
			return true;
		}
		$api  = new WCIS_Shopify_Api( $s );
		$item = self::resolve_item( $s, $p, $api );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		if ( '' === $item ) {
			return true; // Artikel existiert (noch) nicht in Shopify.
		}

		$lock = 'wcis_shplock_' . $s['id'] . '_' . $p->get_id();
		if ( ! self::acquire( $lock ) ) {
			return new WP_Error( 'wcis_shopify_busy', __( 'Artikel wird gerade abgeglichen – wird später wiederholt.', 'blocksocial-woocommerce-sync' ) );
		}
		try {
			// Nach dem Warten auf die Sperre: Produkt frisch laden (Bestand kann sich geändert haben).
			wp_cache_delete( $p->get_id(), 'post_meta' );
			$fresh = wc_get_product( $p->get_id() );
			$p     = $fresh ? $fresh : $p;
			if ( ! $p->managing_stock() ) {
				return self::sync_unmanaged( $api, $s, $p, $item );
			}
			return self::sync_managed( $api, $s, $p, $item, $from_webhook );
		} finally {
			WCIS_Install::release( $lock );
		}
	}

	/**
	 * Sperre je Artikel (wartet bis zu 8 s; verwaiste Sperren > 60 s werden gelöst).
	 *
	 * @param string $name Sperr-Name.
	 * @return bool
	 */
	protected static function acquire( $name ) {
		$deadline = microtime( true ) + 8;
		do {
			if ( WCIS_Install::claim( $name ) ) {
				return true;
			}
			$since = (int) WCIS_Install::claimed_value( $name );
			if ( $since && time() - $since > 60 ) {
				WCIS_Install::release( $name );
				continue;
			}
			usleep( 250000 );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	/**
	 * Liest einen Meta-Wert direkt aus der Datenbank (ohne Cache – andere
	 * Prozesse können ihn gerade geändert haben).
	 *
	 * @param int    $post_id Post-ID.
	 * @param string $key     Meta-Key.
	 * @return string|null
	 */
	protected static function meta_fresh( $post_id, $key ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", (int) $post_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Abgleich für Artikel MIT Bestandsführung.
	 *
	 * @param WCIS_Shopify_Api $api          API.
	 * @param array            $s            Shop.
	 * @param WC_Product       $p            Artikel.
	 * @param string           $item         Inventory-Item-GID.
	 * @param bool             $from_webhook Durch Webhook ausgelöst?
	 * @return true|WP_Error
	 */
	protected static function sync_managed( $api, array $s, $p, $item, $from_webhook ) {
		$pid      = $p->get_id();
		$k_last   = self::meta_key( $s, 'last' );
		$k_pend   = self::meta_key( $s, 'pend' );
		$set_last = static function ( $v ) use ( $pid, $k_last ) {
			update_post_meta( $pid, $k_last, (string) (int) $v );
		};

		// 1) Unklarer früherer Schreibvorgang? Mit demselben Idempotenz-Schlüssel
		//    wiederholen – Shopify führt ihn höchstens einmal aus.
		$pend = json_decode( (string) self::meta_fresh( $pid, $k_pend ), true );
		if ( is_array( $pend ) && isset( $pend['qty'], $pend['key'] ) ) {
			$r = self::set_available( $api, $s, $item, (int) $pend['qty'], isset( $pend['cmp'] ) ? $pend['cmp'] : null, (string) $pend['key'] );
			if ( true === $r ) {
				$set_last( $pend['qty'] );
				delete_post_meta( $pid, $k_pend );
			} elseif ( 'stale' === $r || ( is_wp_error( $r ) && 'wcis_shopify_user' === $r->get_error_code() ) ) {
				delete_post_meta( $pid, $k_pend ); // nicht ausgeführt / Schlüssel abgelaufen → normal weiter.
			} else {
				return $r; // Shopify weiterhin nicht erreichbar → später erneut.
			}
		}

		$master = self::master_quantity( $p );
		for ( $round = 0; $round < 3; $round++ ) {
			$raw  = self::meta_fresh( $pid, $k_last );
			$last = ( null === $raw || '' === $raw ) ? null : (int) $raw;

			// Schneller Weg: unverändert seit der letzten Übertragung → nichts zu tun.
			if ( 0 === $round && ! $from_webhook && null !== $last && $master === $last ) {
				return true;
			}

			$compare = $last;
			if ( $from_webhook || null === $last || $round > 0 ) {
				$read = self::read_available( $api, $s, $item, true );
				if ( is_wp_error( $read ) ) {
					return $read;
				}
				$shopify = $read['qty'];
				if ( $read['activated'] ) {
					$last = null; // frisch aktivierter Lagerort: keine Differenz ableiten.
				}
				// Verkauf/Änderung in Shopify seit unserer letzten Übertragung → auf den Hauptshop buchen.
				if ( null !== $last && $shopify !== $last ) {
					$master = self::apply_delta( $s, $p, $shopify - $last );
					$set_last( $shopify ); // Differenz ist gebucht – nie doppelt.
				}
				if ( $master === $shopify ) {
					$set_last( $shopify );
					return true;
				}
				$from_webhook = false;
				$compare      = $shopify;
			}

			// 2) Schreiben – vorher als „unklar" vormerken (falls die Antwort verloren geht).
			$key = WCIS_Shopify_Api::uuid();
			update_post_meta( $pid, $k_pend, wp_json_encode( array( 'qty' => $master, 'cmp' => $compare, 'key' => $key, 't' => time() ) ) );
			$res = self::set_available( $api, $s, $item, $master, $compare, $key );
			if ( true === $res ) {
				$set_last( $master );
				delete_post_meta( $pid, $k_pend );
				return true;
			}
			if ( 'stale' !== $res ) {
				return $res; // Ergebnis unbekannt → „pend" bleibt für die Wiederholung.
			}
			delete_post_meta( $pid, $k_pend ); // abgelehnt (Bestand geändert) → neu lesen.
		}
		return new WP_Error( 'wcis_shopify_stale', __( 'Shopify-Bestand ändert sich laufend – Abgleich wird später wiederholt.', 'blocksocial-woocommerce-sync' ) );
	}

	/**
	 * Abgleich für Artikel OHNE Bestandsführung in WooCommerce: „vorrätig" /
	 * „Lieferrückstand" → in Shopify ohne Bestandsführung (verkaufbar);
	 * „nicht vorrätig" → Bestandsführung an, Bestand 0.
	 *
	 * @param WCIS_Shopify_Api $api  API.
	 * @param array            $s    Shop.
	 * @param WC_Product       $p    Artikel.
	 * @param string           $item Inventory-Item-GID.
	 * @return true|WP_Error
	 */
	protected static function sync_unmanaged( $api, array $s, $p, $item ) {
		$state = 'outofstock' === $p->get_stock_status() ? 'out' : 'open';
		$k     = self::meta_key( $s, 'state' );
		if ( self::meta_fresh( $p->get_id(), $k ) === $state ) {
			return true;
		}
		if ( 'open' === $state ) {
			$r = $api->query( 'mutation($id:ID!){ inventoryItemUpdate(id:$id, input:{ tracked: false }){ inventoryItem{ id } userErrors{ field message } } }', array( 'id' => $item ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			for ( $i = 0; $i < 2; $i++ ) {
				$read = self::read_available( $api, $s, $item, true );
				if ( is_wp_error( $read ) ) {
					return $read;
				}
				$r = self::set_available( $api, $s, $item, 0, $read['qty'], WCIS_Shopify_Api::uuid() );
				if ( true === $r ) {
					break;
				}
				if ( 'stale' !== $r ) {
					return $r;
				}
			}
		}
		update_post_meta( $p->get_id(), $k, $state );
		delete_post_meta( $p->get_id(), self::meta_key( $s, 'last' ) );
		return true;
	}

	/**
	 * Bucht eine in Shopify entstandene Differenz atomar auf den Hauptshop und
	 * merkt die Weiterleitung an alle übrigen Shops vor.
	 *
	 * @param array      $s     Shop.
	 * @param WC_Product $p     Artikel.
	 * @param int        $delta Differenz (negativ = Verkauf in Shopify).
	 * @return int Neuer Bestand des Hauptshops.
	 */
	protected static function apply_delta( array $s, $p, $delta ) {
		WCIS_Sync_Engine::set_suppress( true );
		try {
			$new = wc_update_product_stock( $p, abs( (int) $delta ), $delta < 0 ? 'decrease' : 'increase' );
		} finally {
			WCIS_Sync_Engine::set_suppress( false );
		}
		WCIS_Logger::info( sprintf( 'Shopify „%s": SKU %s %+d (neuer Bestand %s).', $s['name'], $p->get_sku(), $delta, is_wp_error( $new ) ? '?' : (int) $new ), 'inbound' );
		WCIS_Sync_Engine::schedule_forward(
			array( $p->get_sku() ),
			array(
				'type'  => 'shopify',
				'store' => $s['id'],
			)
		);
		$fresh = wc_get_product( $p->get_id() );
		return $fresh ? self::master_quantity( $fresh ) : max( 0, (int) $new );
	}

	/**
	 * Ermittelt (und merkt sich) das Shopify-Inventory-Item zu einem Artikel.
	 *
	 * @param array            $s   Shop.
	 * @param WC_Product       $p   Artikel.
	 * @param WCIS_Shopify_Api $api API.
	 * @return string|WP_Error Item-GID oder '' (nicht in Shopify).
	 */
	protected static function resolve_item( array $s, $p, $api ) {
		$item = (string) get_post_meta( $p->get_id(), self::meta_key( $s, 'item' ), true );
		if ( '' !== $item ) {
			return $item;
		}
		$v = self::find_variant_by_sku( $api, $p->get_sku() );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		if ( ! $v ) {
			return '';
		}
		self::remember_variant( $s, $p, $v );
		return (string) $v['inventoryItem']['id'];
	}

	/**
	 * Speichert die Zuordnung Artikel ↔ Shopify-Variante.
	 *
	 * @param array      $s Shop.
	 * @param WC_Product $p Artikel.
	 * @param array      $v Variante { id, inventoryItem{id}, product{id} }.
	 */
	protected static function remember_variant( array $s, $p, array $v ) {
		update_post_meta( $p->get_id(), self::meta_key( $s, 'vid' ), (string) $v['id'] );
		if ( ! empty( $v['inventoryItem']['id'] ) ) {
			update_post_meta( $p->get_id(), self::meta_key( $s, 'item' ), (string) $v['inventoryItem']['id'] );
		}
		$parent = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
		if ( ! empty( $v['product']['id'] ) ) {
			update_post_meta( $parent, self::meta_key( $s, 'pid' ), (string) $v['product']['id'] );
		}
	}

	/**
	 * Sucht eine Shopify-Variante per exakter SKU.
	 *
	 * @param WCIS_Shopify_Api $api API.
	 * @param string           $sku SKU.
	 * @return array|null|WP_Error
	 */
	protected static function find_variant_by_sku( $api, $sku ) {
		$q    = 'sku:"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $sku ) . '"';
		$data = $api->query(
			'query($q:String!){ productVariants(first: 10, query: $q){ nodes{ id sku product{ id } inventoryItem{ id } } } }',
			array( 'q' => $q )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$nodes = isset( $data['productVariants']['nodes'] ) ? (array) $data['productVariants']['nodes'] : array();
		foreach ( $nodes as $n ) {
			if ( isset( $n['sku'] ) && (string) $n['sku'] === (string) $sku ) {
				return $n; // nur exakte Treffer (Shopify-Suche ist unscharf).
			}
		}
		return null;
	}

	/**
	 * Liest den verfügbaren Bestand am Lagerort; aktiviert Bestandsführung (nur
	 * wenn gewünscht) und den Lagerort bei Bedarf.
	 *
	 * @param WCIS_Shopify_Api $api   API.
	 * @param array            $s     Shop.
	 * @param string           $item  Inventory-Item-GID.
	 * @param bool             $track Bestandsführung in Shopify einschalten?
	 * @return array|WP_Error { qty: int, activated: bool }
	 */
	protected static function read_available( $api, array $s, $item, $track = true ) {
		$data = $api->query(
			'query($id:ID!, $loc:ID!){ inventoryItem(id:$id){ id tracked inventoryLevel(locationId:$loc){ id quantities(names:["available"]){ name quantity } } } }',
			array(
				'id'  => $item,
				'loc' => $s['location_id'],
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['inventoryItem'] ) ) {
			return new WP_Error( 'wcis_shopify_item', __( 'Shopify-Artikel nicht mehr vorhanden.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( $track && empty( $data['inventoryItem']['tracked'] ) ) {
			$r = $api->query( 'mutation($id:ID!){ inventoryItemUpdate(id:$id, input:{ tracked: true }){ inventoryItem{ id } userErrors{ field message } } }', array( 'id' => $item ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		$level = isset( $data['inventoryItem']['inventoryLevel'] ) ? $data['inventoryItem']['inventoryLevel'] : null;
		if ( empty( $level ) ) {
			$r = $api->query(
				'mutation($item:ID!, $loc:ID!, $key:String!){ inventoryActivate(inventoryItemId:$item, locationId:$loc) @idempotent(key:$key){ inventoryLevel{ id } userErrors{ field message } } }',
				array(
					'item' => $item,
					'loc'  => $s['location_id'],
					'key'  => WCIS_Shopify_Api::uuid(),
				)
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$err = WCIS_Shopify_Api::user_errors( isset( $r['inventoryActivate']['userErrors'] ) ? $r['inventoryActivate']['userErrors'] : array() );
			return $err ? $err : array( 'qty' => 0, 'activated' => true );
		}
		foreach ( (array) ( isset( $level['quantities'] ) ? $level['quantities'] : array() ) as $q ) {
			if ( isset( $q['name'] ) && 'available' === $q['name'] ) {
				return array( 'qty' => (int) $q['quantity'], 'activated' => false );
			}
		}
		return array( 'qty' => 0, 'activated' => false );
	}

	/**
	 * Setzt den verfügbaren Bestand (nur wenn Shopify noch $compare hat).
	 *
	 * @param WCIS_Shopify_Api $api     API.
	 * @param array            $s       Shop.
	 * @param string           $item    Inventory-Item-GID.
	 * @param int              $qty     Neuer Bestand.
	 * @param int|null         $compare Erwarteter aktueller Shopify-Bestand (null = ohne Prüfung).
	 * @param string           $key     Idempotenz-Schlüssel (gleicher Schlüssel = höchstens einmal ausgeführt).
	 * @return true|string|WP_Error true, 'stale' oder Fehler.
	 */
	protected static function set_available( $api, array $s, $item, $qty, $compare, $key = '' ) {
		$data = $api->query(
			'mutation($input: InventorySetQuantitiesInput!, $key: String!){ inventorySetQuantities(input: $input) @idempotent(key: $key){ inventoryAdjustmentGroup{ id } userErrors{ code field message } } }',
			array(
				'input' => array(
					'name'                 => 'available',
					'reason'               => 'correction',
					'referenceDocumentUri' => 'gid://blocksocial-woocommerce-sync/StockSync/' . time(),
					'quantities'           => array(
						array(
							'inventoryItemId'    => $item,
							'locationId'         => $s['location_id'],
							'quantity'           => max( 0, (int) $qty ),
							'changeFromQuantity' => null === $compare ? null : (int) $compare,
						),
					),
				),
				'key'   => '' !== $key ? $key : WCIS_Shopify_Api::uuid(),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$err = WCIS_Shopify_Api::user_errors( isset( $data['inventorySetQuantities']['userErrors'] ) ? $data['inventorySetQuantities']['userErrors'] : array() );
		if ( $err ) {
			$edata = $err->get_error_data();
			$codes = is_array( $edata ) && isset( $edata['codes'] ) ? (array) $edata['codes'] : array();
			if ( in_array( 'CHANGE_FROM_QUANTITY_STALE', $codes, true ) || false !== stripos( $err->get_error_message(), 'stale' ) ) {
				return 'stale';
			}
			return $err;
		}
		return true;
	}

	/**
	 * Gleicht alle Artikel (inkl. Variationen) eines Produkts ab.
	 *
	 * @param array      $s       Shop.
	 * @param WC_Product $product Produkt.
	 * @return true|WP_Error
	 */
	protected static function sync_all_stock_of( array $s, $product ) {
		$targets = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		$error   = null;
		foreach ( $targets as $t ) {
			$r = self::sync_product_stock( $s, $t, false );
			if ( is_wp_error( $r ) ) {
				$error = $r;
			}
		}
		return $error ? $error : true;
	}

	// -------------------------------------------------------------------------
	// Massen-Jobs (Fortschrittsbalken): Bestand oder Produkte übertragen
	// -------------------------------------------------------------------------

	/**
	 * Startet einen Job.
	 *
	 * @param string $id   Shop-ID.
	 * @param string $mode 'stock' | 'products'.
	 * @return array|WP_Error
	 */
	public static function job_start( $id, $mode ) {
		$s = self::get( $id );
		if ( ! $s ) {
			return new WP_Error( 'wcis_shopify_missing', __( 'Shopify-Shop nicht gefunden.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( ! self::is_hub() ) {
			return new WP_Error( 'wcis_shopify_hub', __( 'Die Shopify-Anbindung arbeitet nur auf dem Hauptshop.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( empty( $s['location_id'] ) ) {
			return new WP_Error( 'wcis_shopify_untested', __( 'Bitte zuerst „Verbindung testen" ausführen.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'products' === $mode && empty( $s['sync_products'] ) ) {
			return new WP_Error( 'wcis_shopify_noproducts', __( 'Produkt-Übertragung ist für diesen Shopify-Shop nicht aktiviert.', 'blocksocial-woocommerce-sync' ) );
		}
		$existing = self::job_state();
		if ( is_array( $existing ) && 'running' === $existing['status'] ) {
			return $existing;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		set_transient( self::IDS_TR, $ids, HOUR_IN_SECONDS );

		$job = array(
			'status'     => ! empty( $ids ) ? 'running' : 'done',
			'store'      => $id,
			'mode'       => 'products' === $mode ? 'products' : 'stock',
			'total'      => count( $ids ),
			'index'      => 0,
			'created'    => 0,
			'updated'    => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'last_error' => '',
			'started_at' => time(),
			'updated_at' => time(),
		);
		update_option( self::JOB_OPT, $job, false );
		WCIS_Logger::info( sprintf( 'Shopify „%s": %s gestartet (%d Produkte).', $s['name'], 'products' === $mode ? 'Produkt-Übertragung' : 'Bestands-Übertragung', count( $ids ) ), 'outbound' );
		return $job;
	}

	/**
	 * Nächster Abschnitt des Jobs.
	 *
	 * @return array|WP_Error
	 */
	public static function job_tick() {
		$job = self::job_state();
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'wcis_no_job', __( 'Kein laufender Vorgang.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'running' !== $job['status'] || get_transient( 'wcis_shopify_lock' ) ) {
			return $job;
		}
		set_transient( 'wcis_shopify_lock', 1, 60 );

		try {
			$s   = self::get( $job['store'] );
			$ids = get_transient( self::IDS_TR );
			if ( ! $s || ! is_array( $ids ) ) {
				$job['status'] = 'done';
				update_option( self::JOB_OPT, $job, false );
				return $job;
			}
			set_transient( self::IDS_TR, $ids, HOUR_IN_SECONDS );

			$start = microtime( true );
			do {
				if ( $job['index'] >= $job['total'] ) {
					$job['status'] = 'done';
					break;
				}
				$product = wc_get_product( (int) $ids[ $job['index'] ] );
				try {
					if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) || ! self::allows_product( $s, $product ) ) {
						$job['skipped']++;
					} elseif ( 'products' === $job['mode'] ) {
						$r = self::export_product( $s, $product );
						if ( is_wp_error( $r ) ) {
							$job['failed']++;
							$job['last_error'] = $product->get_name() . ': ' . $r->get_error_message();
							WCIS_Logger::error( sprintf( 'Shopify „%s": Produkt „%s" nicht übertragen: %s', $s['name'], $product->get_name(), $r->get_error_message() ), 'outbound' );
						} else {
							$job[ 'created' === $r ? 'created' : ( 'skipped' === $r ? 'skipped' : 'updated' ) ]++;
						}
					} else {
						$r = self::sync_all_stock_of( $s, $product );
						if ( is_wp_error( $r ) ) {
							$job['failed']++;
							$job['last_error'] = $product->get_name() . ': ' . $r->get_error_message();
						} else {
							$job['updated']++;
						}
					}
				} catch ( \Throwable $e ) {
					$job['failed']++;
					$job['last_error'] = $e->getMessage();
				}
				$job['index']++;
				if ( $job['index'] >= $job['total'] ) {
					$job['status'] = 'done';
				}
				$job['updated_at'] = time();
				update_option( self::JOB_OPT, $job, false );
			} while ( 'running' === $job['status'] && ( microtime( true ) - $start ) < self::TICK_BUDGET );

			// Während des Jobs ausgelöste Weiterleitungen (Differenzen aus Shopify) verteilen.
			WCIS_Sync_Engine::dispatch_forward();

			if ( 'done' === $job['status'] ) {
				delete_transient( self::IDS_TR );
				WCIS_Logger::info( sprintf( 'Shopify „%s": fertig – %d angelegt, %d aktualisiert, %d übersprungen, %d Fehler.', $s['name'], $job['created'], $job['updated'], $job['skipped'], $job['failed'] ), 'outbound' );
			}
			return $job;
		} finally {
			delete_transient( 'wcis_shopify_lock' );
		}
	}

	/**
	 * Job-Status.
	 *
	 * @return array|null
	 */
	public static function job_state() {
		$job = get_option( self::JOB_OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Bricht den Job ab.
	 */
	public static function job_cancel() {
		$job = self::job_state();
		if ( is_array( $job ) && 'running' === $job['status'] ) {
			$job['status']     = 'cancelled';
			$job['updated_at'] = time();
			update_option( self::JOB_OPT, $job, false );
		}
		delete_transient( self::IDS_TR );
	}

	/**
	 * Fortschritt in Prozent.
	 *
	 * @param array|null $job Job.
	 * @return int
	 */
	public static function job_percent( $job ) {
		if ( ! is_array( $job ) || empty( $job['total'] ) ) {
			return 0;
		}
		if ( 'done' === $job['status'] ) {
			return 100;
		}
		return (int) min( 100, floor( 100 * $job['index'] / $job['total'] ) );
	}

	/**
	 * Job für die AJAX-Ausgabe.
	 *
	 * @param array|null $job Job.
	 * @return array
	 */
	public static function job_to_response( $job ) {
		if ( ! is_array( $job ) ) {
			return array( 'status' => 'idle', 'percent' => 0 );
		}
		return array(
			'status'  => $job['status'],
			'percent' => self::job_percent( $job ),
			'mode'    => $job['mode'],
			'store'   => $job['store'],
			'total'   => (int) $job['total'],
			'index'   => (int) $job['index'],
			'created' => (int) $job['created'],
			'updated' => (int) $job['updated'],
			'skipped' => (int) $job['skipped'],
			'failed'  => (int) $job['failed'],
			'message' => (string) $job['last_error'],
		);
	}

	// -------------------------------------------------------------------------
	// Produktexport
	// -------------------------------------------------------------------------

	/**
	 * Überträgt ein Produkt an Shopify (anlegen oder aktualisieren).
	 *
	 * - Neu: productCreate (inkl. Bilder und Optionen) + Varianten.
	 * - Vorhanden: productUpdate (Texte, Hersteller, Typ, Tags, Status) und
	 *   gezielte Varianten-Updates per Varianten-ID. Bilder und vorhandene
	 *   Varianten in Shopify werden dabei NIE gelöscht oder neu angelegt
	 *   (Lagerbestände/Bestellhistorie bleiben erhalten).
	 *
	 * @param array      $s       Shop.
	 * @param WC_Product $product Einfaches oder variables Produkt.
	 * @return string|WP_Error 'created' | 'updated' | 'skipped'.
	 */
	public static function export_product( array $s, $product ) {
		$data = self::product_data( $s, $product );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['variants'] ) ) {
			return 'skipped'; // keine Artikel mit SKU → nicht zuordenbar.
		}
		$api = new WCIS_Shopify_Api( $s );

		$pid = (string) get_post_meta( $product->get_id(), self::meta_key( $s, 'pid' ), true );
		if ( '' === $pid ) {
			// Evtl. bereits in Shopify vorhanden (gleiche SKU) → zuordnen statt doppelt anlegen.
			foreach ( $data['variants'] as $v ) {
				$found = self::find_variant_by_sku( $api, $v['sku'] );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				if ( $found ) {
					self::remember_variant( $s, $v['wc'], $found );
					$pid = (string) $found['product']['id'];
					break;
				}
			}
		}

		if ( '' === $pid ) {
			$r = self::create_product( $s, $api, $product, $data );
			$result = 'created';
		} else {
			$r = self::update_product( $s, $api, $product, $data, $pid );
			if ( 'gone' === $r ) {
				// In Shopify gelöscht → Zuordnungen verwerfen und neu anlegen.
				self::forget_product( $s, $product );
				$r      = self::create_product( $s, $api, $product, $data );
				$result = 'created';
			} else {
				$result = 'updated';
			}
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		// Bestände der (ggf. neuen) Varianten setzen.
		if ( ! empty( $s['sync_stock'] ) ) {
			$st = self::sync_all_stock_of( $s, $product );
			if ( is_wp_error( $st ) ) {
				WCIS_Logger::error( sprintf( 'Shopify „%s": Bestand für „%s" nicht gesetzt: %s', $s['name'], $product->get_name(), $st->get_error_message() ), 'outbound' );
			}
		}
		return $result;
	}

	/**
	 * Entfernt alle Shopify-Zuordnungen eines Produkts.
	 *
	 * @param array      $s       Shop.
	 * @param WC_Product $product Produkt.
	 */
	protected static function forget_product( array $s, $product ) {
		$ids = array_merge( array( $product->get_id() ), $product->is_type( 'variable' ) ? $product->get_children() : array() );
		foreach ( $ids as $id ) {
			foreach ( array( 'pid', 'vid', 'item', 'last' ) as $w ) {
				delete_post_meta( $id, self::meta_key( $s, $w ) );
			}
		}
	}

	/**
	 * Preis für Shopify (brutto/netto passend zur Shopify-Einstellung, mit den
	 * Preisregeln dieses Shopify-Shops).
	 *
	 * @param array      $s     Shop.
	 * @param WC_Product $p     Artikel.
	 * @param string     $price WooCommerce-Preis.
	 * @param float      $pct   Prozent-Regel.
	 * @return string
	 */
	protected static function price_for( array $s, $p, $price, $pct ) {
		if ( '' === (string) $price ) {
			return '';
		}
		$v     = ! empty( $s['taxes_included'] )
			? wc_get_price_including_tax( $p, array( 'price' => $price, 'qty' => 1 ) )
			: wc_get_price_excluding_tax( $p, array( 'price' => $price, 'qty' => 1 ) );
		$rules = WCIS_Pricing::sanitize_rules( $s['price_rules'] );
		return WCIS_Pricing::compute( wc_format_decimal( $v, wc_get_price_decimals() ), $pct, $rules['rounding'] );
	}

	/**
	 * Baut die Shopify-Daten eines Produkts.
	 *
	 * @param array      $s       Shop.
	 * @param WC_Product $product Produkt.
	 * @return array|WP_Error
	 */
	protected static function product_data( array $s, $product ) {
		$rules = WCIS_Pricing::sanitize_rules( $s['price_rules'] );
		$pct   = WCIS_Pricing::percent_for( $product->get_id(), $rules );

		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );

		$vendor = '';
		$btax   = WCIS_Filter::brand_taxonomy();
		foreach ( array_filter( array( $btax, 'product_manufacturer' ) ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$names = wp_get_post_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $names ) && ! empty( $names ) ) {
					$vendor = (string) $names[0];
					break;
				}
			}
		}

		$images = array();
		$ids    = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );
		foreach ( array_filter( $ids ) as $aid ) {
			$url = wp_get_attachment_image_url( $aid, 'full' );
			if ( $url && count( $images ) < 20 ) {
				$images[] = array(
					'originalSource'   => $url,
					'mediaContentType' => 'IMAGE',
					'alt'              => $product->get_name(),
				);
			}
		}

		$options  = array(); // Optionsname => [Werte].
		$variants = array();
		$items    = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );

		foreach ( $items as $v ) {
			if ( ! $v instanceof WC_Product || '' === $v->get_sku() ) {
				continue;
			}
			$opt_values = array();
			if ( $v->is_type( 'variation' ) ) {
				$complete = true;
				foreach ( $v->get_attributes() as $key => $value ) {
					if ( '' === (string) $value ) {
						$complete = false; // „beliebig" lässt sich in Shopify nicht abbilden.
						break;
					}
					$oname = wc_attribute_label( $key, $product );
					if ( taxonomy_exists( $key ) ) {
						$term  = get_term_by( 'slug', $value, $key );
						$value = $term ? $term->name : $value;
					}
					$options[ $oname ][ (string) $value ] = true;
					$opt_values[]                       = array(
						'optionName' => $oname,
						'name'       => (string) $value,
					);
				}
				if ( ! $complete || empty( $opt_values ) ) {
					WCIS_Logger::error( sprintf( 'Shopify „%s": Variation %s hat „beliebige" Attribute und wird übersprungen.', $s['name'], $v->get_sku() ), 'outbound' );
					continue;
				}
			}

			$regular = self::price_for( $s, $v, $v->get_regular_price( 'edit' ), $pct );
			$sale    = ! empty( $s['sync_sale'] ) ? self::price_for( $s, $v, $v->get_sale_price( 'edit' ), $pct ) : '';
			$on_sale = '' !== $sale && '' !== $regular && (float) $sale < (float) $regular;

			$gtin = method_exists( $v, 'get_global_unique_id' ) ? (string) $v->get_global_unique_id() : '';
			if ( '' === $gtin ) {
				$gtin = (string) get_post_meta( $v->get_id(), '_ts_gtin', true );
			}

			$item = array(
				'sku'     => $v->get_sku(),
				'tracked' => (bool) $v->managing_stock(),
			);
			$weight = (float) $v->get_weight();
			if ( $weight > 0 ) {
				$units = array( 'kg' => 'KILOGRAMS', 'g' => 'GRAMS', 'lbs' => 'POUNDS', 'oz' => 'OUNCES' );
				$unit  = get_option( 'woocommerce_weight_unit', 'kg' );
				if ( isset( $units[ $unit ] ) ) {
					$item['measurement'] = array( 'weight' => array( 'value' => $weight, 'unit' => $units[ $unit ] ) );
				}
			}

			$input = array(
				'price'           => '' !== ( $on_sale ? $sale : $regular ) ? ( $on_sale ? $sale : $regular ) : '0',
				'compareAtPrice'  => $on_sale ? $regular : null,
				'inventoryPolicy' => $v->backorders_allowed() ? 'CONTINUE' : 'DENY',
				'taxable'         => 'none' !== $v->get_tax_status(),
				'inventoryItem'   => $item,
			);
			if ( empty( $s['sync_sale'] ) ) {
				unset( $input['compareAtPrice'] ); // Angebote nicht übertragen → Vergleichspreis in Shopify unangetastet lassen.
			}
			if ( '' !== $gtin ) {
				$input['barcodes'] = array( array( 'value' => $gtin ) );
			}
			if ( $opt_values ) {
				$input['optionValues'] = $opt_values;
			}

			$variants[] = array(
				'sku'   => $v->get_sku(),
				'wc'    => $v,
				'input' => $input,
			);
		}

		if ( count( $options ) > 3 ) {
			return new WP_Error( 'wcis_shopify_options', __( 'Shopify erlaubt höchstens 3 Varianten-Optionen – Produkt übersprungen.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( count( $variants ) > 2048 ) {
			return new WP_Error( 'wcis_shopify_variants', __( 'Zu viele Varianten für Shopify (max. 2048).', 'blocksocial-woocommerce-sync' ) );
		}

		$status = ( 'publish' === $product->get_status() ) ? $s['product_status'] : 'DRAFT';

		return array(
			'product'  => array(
				'title'           => $product->get_name(),
				'descriptionHtml' => (string) $product->get_description(),
				'vendor'          => $vendor,
				'productType'     => ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? (string) $cats[0] : '',
				'tags'            => ( ! is_wp_error( $tags ) ) ? array_values( array_map( 'strval', $tags ) ) : array(),
				'status'          => $status,
			),
			'options'  => $options,
			'variants' => $variants,
			'images'   => $images,
		);
	}

	/**
	 * Legt ein Produkt in Shopify an.
	 *
	 * @param array            $s       Shop.
	 * @param WCIS_Shopify_Api $api     API.
	 * @param WC_Product       $product Produkt.
	 * @param array            $data    Daten aus product_data().
	 * @return true|WP_Error
	 */
	protected static function create_product( array $s, $api, $product, array $data ) {
		$input = $data['product'];
		if ( ! empty( $data['options'] ) ) {
			$input['productOptions'] = array();
			foreach ( $data['options'] as $name => $values ) {
				$input['productOptions'][] = array(
					'name'   => (string) $name,
					'values' => array_map(
						static function ( $v ) {
							return array( 'name' => (string) $v );
						},
						array_keys( $values )
					),
				);
			}
		}

		$res = $api->query(
			'mutation($product: ProductCreateInput!, $media: [CreateMediaInput!]){ productCreate(product: $product, media: $media){ product{ id variants(first: 1){ nodes{ id inventoryItem{ id } } } } userErrors{ field message } } }',
			array(
				'product' => $input,
				'media'   => $data['images'],
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$err = WCIS_Shopify_Api::user_errors( isset( $res['productCreate']['userErrors'] ) ? $res['productCreate']['userErrors'] : array() );
		if ( $err ) {
			return $err;
		}
		if ( empty( $res['productCreate']['product']['id'] ) ) {
			return new WP_Error( 'wcis_shopify_create', __( 'Shopify hat das Produkt nicht angelegt (keine Produkt-ID erhalten).', 'blocksocial-woocommerce-sync' ) );
		}
		$pid = (string) $res['productCreate']['product']['id'];
		update_post_meta( $product->get_id(), self::meta_key( $s, 'pid' ), $pid );

		if ( empty( $data['options'] ) ) {
			// Einfaches Produkt: die automatisch angelegte Standard-Variante befüllen.
			if ( empty( $res['productCreate']['product']['variants']['nodes'][0]['id'] ) ) {
				return new WP_Error( 'wcis_shopify_create', __( 'Shopify hat keine Standard-Variante angelegt.', 'blocksocial-woocommerce-sync' ) );
			}
			$default = $res['productCreate']['product']['variants']['nodes'][0];
			$v       = $data['variants'][0];
			$v['input']['id'] = $default['id'];
			$r = self::bulk_variants( $api, 'productVariantsBulkUpdate', $pid, array( $v ), '' );
		} else {
			// Variables Produkt: alle Varianten anlegen, Platzhalter-Variante entfernen.
			$r = self::bulk_variants( $api, 'productVariantsBulkCreate', $pid, $data['variants'], 'REMOVE_STANDALONE_VARIANT' );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( $r as $sku => $node ) {
			foreach ( $data['variants'] as $v ) {
				if ( $v['sku'] === $sku ) {
					$node['product'] = array( 'id' => $pid );
					self::remember_variant( $s, $v['wc'], $node );
				}
			}
		}
		return true;
	}

	/**
	 * Aktualisiert ein vorhandenes Shopify-Produkt.
	 *
	 * @param array            $s       Shop.
	 * @param WCIS_Shopify_Api $api     API.
	 * @param WC_Product       $product Produkt.
	 * @param array            $data    Daten.
	 * @param string           $pid     Shopify-Produkt-GID.
	 * @return true|string|WP_Error true, 'gone' (in Shopify gelöscht) oder Fehler.
	 */
	protected static function update_product( array $s, $api, $product, array $data, $pid ) {
		$input       = $data['product'];
		$input['id'] = $pid;
		// Status nur beim Anlegen setzen. Bei Updates bleibt die Entscheidung des
		// Shopify-Betreibers erhalten (veröffentlicht bleibt veröffentlicht); nur
		// wenn das Produkt im Hauptshop nicht mehr veröffentlicht ist, wird es auch
		// in Shopify auf Entwurf gesetzt.
		if ( 'publish' === $product->get_status() ) {
			unset( $input['status'] );
		} else {
			$input['status'] = 'DRAFT';
		}
		$res = $api->query(
			'mutation($product: ProductUpdateInput!){ productUpdate(product: $product){ product{ id } userErrors{ field message } } }',
			array( 'product' => $input )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$err = WCIS_Shopify_Api::user_errors( isset( $res['productUpdate']['userErrors'] ) ? $res['productUpdate']['userErrors'] : array() );
		if ( $err ) {
			return ( false !== stripos( $err->get_error_message(), 'does not exist' ) || false !== stripos( $err->get_error_message(), 'not found' ) ) ? 'gone' : $err;
		}
		if ( empty( $res['productUpdate']['product'] ) ) {
			return 'gone';
		}

		// Vorhandene Shopify-Varianten (alle Seiten) nach SKU/ID zuordnen.
		$remote = self::remote_variants( $api, $pid );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		$update = array();
		$create = array();
		foreach ( $data['variants'] as $v ) {
			$vid = (string) get_post_meta( $v['wc']->get_id(), self::meta_key( $s, 'vid' ), true );
			if ( '' === $vid || ! isset( $remote[ $vid ] ) ) {
				$vid = '';
				foreach ( $remote as $rid => $n ) {
					if ( isset( $n['sku'] ) && (string) $n['sku'] === $v['sku'] ) {
						$vid = $rid;
						break;
					}
				}
				// Einfaches Produkt mit unbekannter Variante: die einzige Variante nutzen.
				if ( '' === $vid && empty( $data['options'] ) && 1 === count( $remote ) ) {
					$vid = (string) key( $remote );
				}
			}
			if ( '' !== $vid ) {
				$v['input']['id'] = $vid;
				unset( $v['input']['optionValues'] ); // Optionen vorhandener Varianten nicht verändern.
				$update[] = $v;
			} else {
				$create[] = $v;
			}
		}

		$map = array();
		if ( $update ) {
			$r = self::bulk_variants( $api, 'productVariantsBulkUpdate', $pid, $update, '' );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$map = $r;
		}
		if ( $create && ! empty( $data['options'] ) ) {
			$r = self::bulk_variants( $api, 'productVariantsBulkCreate', $pid, $create, 'DEFAULT' );
			if ( is_wp_error( $r ) ) {
				WCIS_Logger::error( sprintf( 'Shopify „%s": neue Varianten für „%s" nicht angelegt: %s', $s['name'], $product->get_name(), $r->get_error_message() ), 'outbound' );
			} else {
				$map = array_merge( $map, $r );
			}
		}
		foreach ( $map as $sku => $node ) {
			foreach ( $data['variants'] as $v ) {
				if ( $v['sku'] === $sku ) {
					$node['product'] = array( 'id' => $pid );
					self::remember_variant( $s, $v['wc'], $node );
				}
			}
		}
		return true;
	}

	/**
	 * Alle Varianten eines Shopify-Produkts (seitenweise, je 250).
	 *
	 * @param WCIS_Shopify_Api $api API.
	 * @param string           $pid Produkt-GID.
	 * @return array|WP_Error Varianten-GID => { id, sku, inventoryItem{id} }.
	 */
	protected static function remote_variants( $api, $pid ) {
		$out    = array();
		$cursor = null;
		for ( $page = 0; $page < 20; $page++ ) {
			$data = $api->query(
				'query($id:ID!, $after:String){ product(id:$id){ variants(first: 250, after: $after){ nodes{ id sku inventoryItem{ id } } pageInfo{ hasNextPage endCursor } } } }',
				array(
					'id'    => $pid,
					'after' => $cursor,
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data['product']['variants'] ) ) {
				break;
			}
			foreach ( (array) $data['product']['variants']['nodes'] as $n ) {
				$out[ (string) $n['id'] ] = $n;
			}
			if ( empty( $data['product']['variants']['pageInfo']['hasNextPage'] ) ) {
				break;
			}
			$cursor = $data['product']['variants']['pageInfo']['endCursor'];
		}
		return $out;
	}

	/**
	 * Varianten in Blöcken anlegen/aktualisieren.
	 *
	 * @param WCIS_Shopify_Api $api      API.
	 * @param string           $mutation productVariantsBulkCreate | productVariantsBulkUpdate.
	 * @param string           $pid      Produkt-GID.
	 * @param array            $variants Varianten (mit 'input').
	 * @param string           $strategy Nur bei Create.
	 * @return array|WP_Error SKU => { id, inventoryItem{id} }.
	 */
	protected static function bulk_variants( $api, $mutation, $pid, array $variants, $strategy ) {
		$map = array();
		foreach ( array_chunk( $variants, 100 ) as $i => $chunk ) {
			$args = '$productId: ID!, $variants: [ProductVariantsBulkInput!]!';
			$call = 'productId: $productId, variants: $variants';
			$vars = array(
				'productId' => $pid,
				'variants'  => array_values( wp_list_pluck( $chunk, 'input' ) ),
			);
			if ( 'productVariantsBulkCreate' === $mutation && '' !== $strategy && 0 === $i ) {
				$args            .= ', $strategy: ProductVariantsBulkCreateStrategy';
				$call            .= ', strategy: $strategy';
				$vars['strategy'] = $strategy;
			}
			$res = $api->query(
				'mutation(' . $args . '){ ' . $mutation . '(' . $call . '){ productVariants{ id sku inventoryItem{ id } } userErrors{ field message code } } }',
				$vars
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$err = WCIS_Shopify_Api::user_errors( isset( $res[ $mutation ]['userErrors'] ) ? $res[ $mutation ]['userErrors'] : array() );
			if ( $err ) {
				return $err;
			}
			foreach ( (array) $res[ $mutation ]['productVariants'] as $n ) {
				if ( ! empty( $n['sku'] ) ) {
					$map[ (string) $n['sku'] ] = $n;
				}
			}
		}
		return $map;
	}
}
