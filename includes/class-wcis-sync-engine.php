<?php
/**
 * Sync-Engine: erkennt Lagerbestands-Änderungen, verteilt sie an alle Peers
 * und wendet eingehende Änderungen an. Zuordnung erfolgt ausschließlich per SKU.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kern der Synchronisation.
 */
class WCIS_Sync_Engine {

	/**
	 * Sperre gegen Endlosschleifen: true, während eine eingehende Änderung
	 * angewendet wird (verhindert erneutes Broadcasten).
	 *
	 * @var bool
	 */
	protected static $suppress = false;

	/**
	 * Gesammelte Änderungen der aktuellen Anfrage (SKU => Item).
	 *
	 * @var array
	 */
	protected static $pending = array();

	/**
	 * Wurde der Shutdown-Dispatch bereits registriert?
	 *
	 * @var bool
	 */
	protected static $shutdown_registered = false;

	/**
	 * Registriert die WooCommerce-Hooks.
	 */
	public static function init() {
		// Feuert bei jeder Lagerbestands-Änderung (Bestellung, Admin, API).
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_change' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_change' ), 20, 1 );

		// Änderungen des reinen Lagerstatus (ohne Mengenverwaltung).
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_status_change' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_status_change' ), 20, 3 );

		// Partner-Plugin: Verkäufe als Deltas aus Bestellungen an den Hauptshop
		// melden (statt absoluter Bestände). So können manuelle Bestandsänderungen
		// im Partnershop den Hauptshop nicht verändern, und veraltete Bestände
		// führen nicht zu verlorenen oder doppelt gezählten Verkäufen.
		if ( WCIS_Edition::is_partner() ) {
			add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'on_order_item_reduced' ), 20, 3 );
			add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'on_order_item_restored' ), 20, 4 );
			add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'on_refund_restocked' ), 20, 5 );
		}

		// Retry-Queue-Verarbeitung per Cron.
		add_action( 'wcis_process_queue', array( __CLASS__, 'process_queue' ) );
		add_action( 'wcis_daily_cleanup', array( 'WCIS_Logger', 'cleanup' ) );
		add_action( 'wcis_daily_cleanup', array( __CLASS__, 'cleanup_events' ) );
	}

	/**
	 * Setzt die Broadcast-Sperre (z. B. während der Produkt-Sync Produkte anlegt).
	 *
	 * @param bool $on true = Broadcasts unterdrücken.
	 */
	public static function set_suppress( $on ) {
		self::$suppress = (bool) $on;
	}

	/**
	 * Handler für Mengen-Änderungen.
	 *
	 * @param WC_Product $product Produkt-Objekt.
	 */
	public static function on_stock_change( $product ) {
		if ( self::$suppress || ! WCIS_Settings::is_enabled() || WCIS_Edition::is_partner() ) {
			return;
		}
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( $product );
		}
		if ( ! WCIS_Filter::should_sync( $product ) ) {
			return; // Produkt nicht im Sync-Umfang dieses Shops.
		}
		$item = self::item_from_product( $product );
		if ( $item ) {
			self::enqueue_local_change( $item );
		}
	}

	/**
	 * Handler für reine Lagerstatus-Änderungen.
	 *
	 * @param int    $product_id Produkt-ID.
	 * @param string $status     Neuer Status.
	 * @param mixed  $product    Produkt (optional).
	 */
	public static function on_status_change( $product_id, $status = '', $product = null ) {
		if ( self::$suppress || ! WCIS_Settings::is_enabled() || WCIS_Edition::is_partner() ) {
			return;
		}
		if ( ! WCIS_Settings::get( 'sync_status', true ) ) {
			return;
		}
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		// Produkte mit Mengenverwaltung werden über on_stock_change abgedeckt.
		if ( $product->managing_stock() ) {
			return;
		}
		if ( ! WCIS_Filter::should_sync( $product ) ) {
			return;
		}
		$item = self::item_from_product( $product );
		if ( $item ) {
			self::enqueue_local_change( $item );
		}
	}

	/**
	 * Baut ein Sync-Item aus einem Produkt. Gibt null zurück, wenn keine SKU
	 * vorhanden ist (dann kann nicht zugeordnet werden -> ignorieren).
	 *
	 * Unterstützt einfache Produkte und Variationen (variable Produkte werden
	 * über ihre Variationen synchronisiert, da diese eigene SKUs besitzen).
	 *
	 * @param WC_Product $product Produkt.
	 * @return array|null
	 */
	public static function item_from_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		// Variable Elternprodukte selbst haben keinen eigenen Bestand -> überspringen.
		if ( $product->is_type( 'variable' ) ) {
			return null;
		}

		$sku = $product->get_sku();
		if ( '' === $sku ) {
			return null; // Ohne SKU keine Zuordnung möglich.
		}

		$manages = $product->managing_stock();

		return array(
			'sku'          => $sku,
			'manage_stock' => (bool) $manages,
			'stock'        => $manages ? wc_stock_amount( $product->get_stock_quantity() ) : null,
			// Echten Status 1:1 mitsenden (instock | outofstock | onbackorder) …
			'stock_status' => $product->get_stock_status(),
			// … sowie die Lieferrückstand-Einstellung (no | notify | yes), damit der
			// Empfänger bei Menge 0 korrekt „onbackorder" statt „outofstock" ableitet.
			'backorders'   => $product->get_backorders(),
			'in_stock'     => $product->is_in_stock(), // Rückwärtskompatibel für ältere Empfänger.
			'timestamp'    => time(),
		);
	}

	/**
	 * Sammelt eine lokale Änderung und plant den Versand am Ende der Anfrage.
	 *
	 * @param array $item Sync-Item.
	 */
	protected static function enqueue_local_change( $item ) {
		self::$pending[ $item['sku'] ] = $item;

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'dispatch_pending' ), 0 );
		}
	}

	/**
	 * Versendet die gesammelten Änderungen an alle Peers (am Request-Ende).
	 *
	 * Nutzt fastcgi_finish_request(), damit der Kunde nicht auf den Versand
	 * warten muss. Fehlgeschlagene Zustellungen landen in der Retry-Queue.
	 */
	public static function dispatch_pending() {
		if ( empty( self::$pending ) ) {
			return;
		}

		$items = array_values( self::$pending );
		self::$pending = array();

		$peers = WCIS_Settings::get_peers();

		// Antwort an den Browser abschließen, dann im Hintergrund senden.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( ! empty( $peers ) && WCIS_Settings::has_credentials() ) {
			foreach ( $peers as $peer ) {
				self::deliver_items( $peer['url'], $items );
			}
		}

		// Hauptshop: angebundene Shopify-Shops ebenfalls aktualisieren.
		if ( self::is_hub() ) {
			WCIS_Shopify::push_skus( wp_list_pluck( $items, 'sku' ) );
		}
	}

	/**
	 * Ist dieser Shop die Verteil-Zentrale (Hauptshop im Admin-Plugin)? Nur dort
	 * werden Partner und Shopify beliefert und Änderungen weitergereicht.
	 *
	 * @return bool
	 */
	public static function is_hub() {
		return WCIS_Edition::is_admin_edition() && WCIS_Settings::is_master();
	}

	/**
	 * Stellt Items an einen Peer zu – gefiltert auf dessen Sortiment (Partner).
	 *
	 * @param string $peer_url Peer-URL.
	 * @param array  $items    Items.
	 */
	public static function deliver_items( $peer_url, array $items ) {
		$items = WCIS_Partners::filter_items_for_url( $peer_url, $items );
		if ( empty( $items ) ) {
			return;
		}
		self::deliver(
			$peer_url,
			array(
				'source' => WCIS_Settings::this_url(),
				'items'  => array_values( $items ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Weiterleitung (Hauptshop als Verteil-Zentrale)
	// -------------------------------------------------------------------------

	/**
	 * Zur Weiterleitung vorgemerkte Änderungen: Liste von [ skus, source ].
	 *
	 * @var array
	 */
	protected static $forward = array();

	/**
	 * Merkt eingehende, angewendete Änderungen zur Weiterleitung vor. Nur auf
	 * dem Hauptshop aktiv. Versand erfolgt am Request-Ende (blockiert die
	 * Antwort an den Absender nicht).
	 *
	 * @param array $skus   Angewendete SKUs.
	 * @param array $source Absender { type: network|partner|master|shopify, url?, key?, store? }.
	 */
	public static function schedule_forward( array $skus, array $source ) {
		if ( ! self::is_hub() || empty( $skus ) ) {
			return;
		}
		self::$forward[] = array(
			'skus'   => array_values( array_unique( array_map( 'strval', $skus ) ) ),
			'source' => $source,
		);
		if ( 1 === count( self::$forward ) ) {
			add_action( 'shutdown', array( __CLASS__, 'dispatch_forward' ), 1 );
		}
	}

	/**
	 * Leitet vorgemerkte Änderungen weiter.
	 *
	 * Regeln (Hauptshop):
	 * - von einem eigenen Shop: nur an Partner + Shopify (eigene Shops haben die
	 *   Änderung bereits direkt erhalten),
	 * - von einem Partner: an alle eigenen Shops, alle Partner (inkl. Absender –
	 *   er erhält den maßgeblichen Bestand zurück) und Shopify,
	 * - von Shopify: an alle Shops/Partner und die übrigen Shopify-Shops.
	 */
	public static function dispatch_forward() {
		if ( empty( self::$forward ) ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Schleife: Während der Weiterleitung können neue Änderungen entstehen (z. B.
		// ein dabei entdeckter Shopify-Verkauf) – auch diese werden verteilt.
		$guard = 0;
		while ( ! empty( self::$forward ) && $guard++ < 10 ) {
			$jobs          = self::$forward;
			self::$forward = array();
			foreach ( $jobs as $job ) {
				self::forward_job( $job );
			}
		}
	}

	/**
	 * Leitet einen einzelnen Weiterleitungs-Auftrag weiter.
	 *
	 * @param array $job { skus, source }.
	 */
	protected static function forward_job( array $job ) {
		$items = array();
		foreach ( $job['skus'] as $sku ) {
			$pid = wc_get_product_id_by_sku( $sku );
			$prd = $pid ? wc_get_product( $pid ) : null;
			$it  = $prd ? self::item_from_product( $prd ) : null;
			if ( $it ) {
				$items[] = $it;
			}
		}
		if ( empty( $items ) ) {
			return;
		}

		$src_type = isset( $job['source']['type'] ) ? $job['source']['type'] : '';
		$src_url  = isset( $job['source']['url'] ) ? WCIS_Settings::normalize_url( $job['source']['url'] ) : '';

		foreach ( WCIS_Settings::get_peers() as $peer ) {
			if ( 'network' === $src_type && 'network' === $peer['type'] ) {
				continue; // eigene Shops haben die Änderung bereits (Mesh).
			}
			if ( 'partner' !== $src_type && '' !== $src_url && WCIS_Settings::normalize_url( $peer['url'] ) === $src_url ) {
				continue; // nicht an den Absender zurück (außer Partner, s. o.).
			}
			self::deliver_items( $peer['url'], $items );
		}

		WCIS_Shopify::push_skus(
			wp_list_pluck( $items, 'sku' ),
			( 'shopify' === $src_type && isset( $job['source']['store'] ) ) ? $job['source']['store'] : ''
		);
	}

	// -------------------------------------------------------------------------
	// Partner-Plugin: Verkaufs-Deltas melden
	// -------------------------------------------------------------------------

	/**
	 * Gesammelte Deltas (Partner-Plugin) des aktuellen Requests.
	 *
	 * @var array
	 */
	protected static $deltas = array();

	/**
	 * Hook: Bestand einer Bestellposition wurde reduziert (Verkauf).
	 *
	 * @param WC_Order_Item_Product $item   Position.
	 * @param array                 $change { product, from, to }.
	 * @param WC_Order              $order  Bestellung.
	 */
	public static function on_order_item_reduced( $item, $change, $order ) {
		if ( ! is_array( $change ) || empty( $change['product'] ) ) {
			return;
		}
		$delta = (int) $change['to'] - (int) $change['from'];
		self::queue_delta( $change['product'], $delta, self::event_id( 'r', $order ) );
	}

	/**
	 * Hook: Bestand einer Bestellposition wurde wiederhergestellt (Storno).
	 *
	 * @param WC_Order_Item_Product $item      Position.
	 * @param int                   $new_stock Neuer Bestand.
	 * @param int                   $old_stock Alter Bestand.
	 * @param WC_Order              $order     Bestellung.
	 */
	public static function on_order_item_restored( $item, $new_stock, $old_stock, $order ) {
		$product = $item ? $item->get_product() : null;
		self::queue_delta( $product, (int) $new_stock - (int) $old_stock, self::event_id( 'i', $order ) );
	}

	/**
	 * Hook: Erstattete Position wurde zurück ins Lager gebucht.
	 *
	 * @param int        $product_id Produkt-ID.
	 * @param int        $old_stock  Alter Bestand.
	 * @param int        $new_stock  Neuer Bestand.
	 * @param WC_Order   $order      Bestellung.
	 * @param WC_Product $product    Produkt.
	 */
	public static function on_refund_restocked( $product_id, $old_stock, $new_stock, $order = null, $product = null ) {
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		self::queue_delta( $product, (int) $new_stock - (int) $old_stock, self::event_id( 'f', $order ) );
	}

	/**
	 * Eindeutige Ereignis-ID je Bestandsereignis. Wird einmal beim Ereignis
	 * erzeugt und bei Wiederholungen (Retry-Queue) unverändert mitgesendet –
	 * dadurch zählt der Hauptshop jedes Ereignis genau einmal, auch wenn eine
	 * Bestellung mehrfach storniert und wieder reduziert wird.
	 *
	 * @param string   $type  r (Verkauf) | i (Storno) | f (Erstattung).
	 * @param WC_Order $order Bestellung.
	 * @return string
	 */
	protected static function event_id( $type, $order ) {
		return $type . ':' . ( $order ? (int) $order->get_id() : 0 ) . ':' . wp_generate_uuid4();
	}

	/**
	 * Merkt ein Delta zum Versand an den Hauptshop vor.
	 *
	 * @param WC_Product|null $product Produkt/Variation.
	 * @param int             $delta   Änderung (negativ = Verkauf).
	 * @param string          $event   Eindeutige Ereignis-ID (Idempotenz).
	 */
	protected static function queue_delta( $product, $delta, $event ) {
		if ( ! WCIS_Edition::is_partner() || ! WCIS_Settings::is_enabled() ) {
			return;
		}
		if ( ! $product instanceof WC_Product || 0 === (int) $delta ) {
			return;
		}
		$sku = $product->get_sku();
		if ( '' === $sku ) {
			return;
		}
		self::$deltas[] = array(
			'sku'       => $sku,
			'delta'     => (int) $delta,
			'event'     => substr( (string) $event, 0, 80 ),
			'timestamp' => time(),
		);
		if ( 1 === count( self::$deltas ) ) {
			add_action( 'shutdown', array( __CLASS__, 'dispatch_deltas' ), 0 );
		}
	}

	/**
	 * Sendet gesammelte Deltas an den Hauptshop (bei Fehler → Retry-Queue).
	 */
	public static function dispatch_deltas() {
		$items        = self::$deltas;
		self::$deltas = array();
		$master       = WCIS_Settings::get( 'master_url' );
		if ( empty( $items ) || '' === (string) $master ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		self::deliver(
			$master,
			array(
				'source' => WCIS_Settings::this_url(),
				'mode'   => 'delta',
				'items'  => $items,
			)
		);
	}

	/**
	 * Hauptshop: wendet Verkaufs-Deltas eines Partners an.
	 *
	 * Schutzmechanismen:
	 * - jedes Ereignis wird nur einmal angewendet (Retry-sicher),
	 * - nur Produkte aus dem Sortiment des Partners,
	 * - nur Produkte mit Mengenverwaltung,
	 * - Standard: nur Verringerungen (Erhöhungen werden ignoriert),
	 * - Bestand wird atomar per wc_update_product_stock() verändert.
	 *
	 * @param array      $items   Delta-Items.
	 * @param array|null $partner Partner.
	 * @return array Statistik inkl. applied_skus.
	 */
	public static function apply_partner_deltas( array $items, $partner ) {
		$stats = array(
			'applied'      => 0,
			'skipped'      => 0,
			'ignored'      => 0,
			'applied_skus' => array(),
		);
		if ( ! $partner ) {
			$stats['skipped'] = count( $items );
			return $stats;
		}
		$policy = WCIS_Partners::policy();

		foreach ( $items as $item ) {
			$sku   = isset( $item['sku'] ) ? sanitize_text_field( (string) $item['sku'] ) : '';
			$delta = isset( $item['delta'] ) ? (int) $item['delta'] : 0;
			$event = isset( $item['event'] ) ? sanitize_text_field( (string) $item['event'] ) : '';

			if ( '' === $sku || 0 === $delta || '' === $event ) {
				$stats['skipped']++; // absolute Werte von Partnern werden nicht akzeptiert.
				continue;
			}

			// Plausibilitätsgrenze je Meldung (schützt vor fehlerhaften/manipulierten Riesenmengen).
			$max = (int) apply_filters( 'wcis_partner_max_delta', 1000, $partner );
			if ( abs( $delta ) > $max ) {
				WCIS_Logger::error( sprintf( 'Bestandsmeldung von Partner „%s" für SKU %s abgelehnt: Menge %d über der Obergrenze %d.', $partner['name'], $sku, $delta, $max ), 'inbound' );
				$stats['skipped']++;
				continue;
			}

			// Ereignis ATOMAR reservieren (add_option schlägt fehl, wenn es existiert) –
			// verhindert Doppelbuchung, auch wenn eine Wiederholung parallel eintrifft.
			$event_key = 'wcis_evt_' . md5( $partner['key'] . '|' . $event . '|' . $sku );
			if ( ! WCIS_Install::claim( $event_key ) ) {
				$stats['skipped']++; // bereits verarbeitet (Wiederholung aus der Retry-Queue).
				continue;
			}

			$pid     = wc_get_product_id_by_sku( $sku );
			$product = $pid ? wc_get_product( $pid ) : null;
			if ( ! $product ) {
				$stats['ignored']++;
				continue;
			}
			if ( ! WCIS_Partners::allows_product( $partner, $product ) || WCIS_Filter::is_excluded( $product ) || ! $product->managing_stock() ) {
				$stats['skipped']++;
				continue;
			}
			if ( $delta > 0 && ! empty( $policy['stock_decrease_only'] ) ) {
				WCIS_Logger::info( sprintf( 'Bestandserhöhung von Partner „%s" für SKU %s ignoriert (nur Verringerungen erlaubt).', $partner['name'], $sku ), 'inbound' );
				$stats['skipped']++;
				// Partner erhält sofort wieder den maßgeblichen Bestand (Weiterleitung inkl. Absender).
				$stats['applied_skus'][] = $sku;
				continue;
			}

			self::$suppress = true;
			try {
				$res = wc_update_product_stock( $product, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
			} finally {
				self::$suppress = false;
			}
			if ( is_wp_error( $res ) || false === $res ) {
				WCIS_Install::release( $event_key ); // nicht angewendet → Wiederholung erlauben.
				$stats['skipped']++;
				continue;
			}

			$stats['applied']++;
			$stats['applied_skus'][] = $sku;
		}

		return $stats;
	}

	/**
	 * Entfernt Ereignis-Marker gemeldeter Partner-Verkäufe, die älter als 30 Tage sind.
	 */
	public static function cleanup_events() {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( 'wcis_evt_' ) . '%',
				time() - 30 * DAY_IN_SECONDS
			)
		);
	}

	/**
	 * Stellt ein Payload an einen Peer zu; bei Fehlern -> Retry-Queue.
	 *
	 * @param string $peer_url Peer-URL.
	 * @param array  $payload  Nutzdaten.
	 */
	protected static function deliver( $peer_url, array $payload ) {
		$result = WCIS_Client::post( $peer_url, '/stock', $payload, true );

		if ( is_wp_error( $result ) ) {
			WCIS_Queue::add( $peer_url, $payload, $result->get_error_message() );
			WCIS_Logger::error(
				sprintf( 'Zustellung an %s fehlgeschlagen (in Queue): %s', $peer_url, $result->get_error_message() ),
				'outbound',
				$payload
			);
			return;
		}

		if ( $result['code'] < 200 || $result['code'] >= 300 ) {
			WCIS_Queue::add( $peer_url, $payload, 'HTTP ' . $result['code'] . ': ' . $result['body'] );
			WCIS_Logger::error(
				sprintf( 'Peer %s antwortete mit HTTP %d (in Queue).', $peer_url, $result['code'] ),
				'outbound',
				array( 'body' => $result['body'] )
			);
			return;
		}

		WCIS_Logger::info(
			sprintf( '%d Artikel an %s synchronisiert.', count( $payload['items'] ), $peer_url ),
			'outbound'
		);
	}

	/**
	 * Wendet eingehende Änderungen an (vom REST-Controller aufgerufen).
	 *
	 * @param array $items Liste von Sync-Items.
	 * @return array Ergebnis-Statistik.
	 */
	public static function apply_items( array $items ) {
		$stats = array(
			'applied'      => 0,
			'skipped'      => 0,
			'ignored'      => 0, // SKU im Zielshop nicht vorhanden -> nur in einem Shop.
			'applied_skus' => array(),
		);

		foreach ( $items as $item ) {
			$result = self::apply_item( $item );
			if ( isset( $stats[ $result ] ) ) {
				$stats[ $result ]++;
			}
			if ( 'applied' === $result ) {
				$stats['applied_skus'][] = (string) $item['sku'];
			}
		}

		return $stats;
	}

	/**
	 * Wendet eine einzelne Änderung an. Zuordnung per SKU.
	 *
	 * @param array $item Sync-Item.
	 * @return string 'applied' | 'ignored' | 'skipped'.
	 */
	protected static function apply_item( $item ) {
		$sku = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
		if ( '' === $sku ) {
			return 'skipped';
		}

		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			// Produkt existiert hier nicht -> kommt nur in einem Shop vor -> ignorieren.
			return 'ignored';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 'ignored';
		}

		// Produkte außerhalb des Sync-Filters (ausgeschlossen bzw. im Modus „Nur
		// ausgewählte" nicht gewählt) auch eingehend nicht verändern.
		if ( ! WCIS_Filter::should_sync( $product ) ) {
			return 'skipped';
		}

		// Reihenfolge-Schutz: veraltete Nachrichten nicht anwenden.
		$incoming_ts = isset( $item['timestamp'] ) ? (int) $item['timestamp'] : time();
		$last_ts     = (int) $product->get_meta( '_wcis_synced_at' );
		if ( $last_ts && $incoming_ts < $last_ts ) {
			return 'skipped';
		}

		self::$suppress = true; // Endlosschleife verhindern.

		// try/finally: die Sperre MUSS auch bei einer Exception in save() wieder
		// gelöst werden, sonst blieben alle folgenden ausgehenden Broadcasts
		// dieses Requests stumm.
		$valid_status     = array( 'instock', 'outofstock', 'onbackorder' );
		$valid_backorder  = array( 'no', 'notify', 'yes' );

		try {
			if ( ! empty( $item['manage_stock'] ) && isset( $item['stock'] ) && null !== $item['stock'] ) {
				$product->set_manage_stock( true );
				// Lieferrückstand-Einstellung zuerst 1:1 übernehmen, damit WooCommerce
				// bei Menge 0 den korrekten Status („onbackorder") ableiten kann.
				if ( isset( $item['backorders'] ) && in_array( $item['backorders'], $valid_backorder, true ) ) {
					$product->set_backorders( $item['backorders'] );
				}
				$product->set_stock_quantity( wc_stock_amount( $item['stock'] ) );
				// Falls der Quell-Status mitgesendet wurde, explizit 1:1 setzen
				// (überschreibt eine evtl. abweichende Ableitung durch WooCommerce).
				if ( ! empty( $item['stock_status'] ) && in_array( $item['stock_status'], $valid_status, true ) ) {
					$product->set_stock_status( $item['stock_status'] );
				}
			} elseif ( WCIS_Settings::get( 'sync_status', true ) ) {
				$product->set_manage_stock( false );
				if ( ! empty( $item['stock_status'] ) && in_array( $item['stock_status'], $valid_status, true ) ) {
					// Status 1:1 übernehmen (inkl. „onbackorder").
					$product->set_stock_status( $item['stock_status'] );
				} else {
					// Rückwärtskompatibel: älterer Payload ohne stock_status.
					$product->set_stock_status( ! empty( $item['in_stock'] ) ? 'instock' : 'outofstock' );
				}
			} else {
				return 'skipped';
			}

			$product->update_meta_data( '_wcis_synced_at', $incoming_ts );
			$product->save();
		} finally {
			self::$suppress = false;
		}

		return 'applied';
	}

	/**
	 * Voll-Synchronisation: sendet den kompletten Bestand dieses Shops an alle
	 * Peers (oder an einen bestimmten Peer). Wird typischerweise vom Master
	 * (Hauptshop) für die erste Synchronisation gestartet.
	 *
	 * @param string $only_peer Optional: nur an diese Peer-URL senden.
	 * @return array Statistik.
	 */
	public static function full_sync( $only_peer = '' ) {
		$peers = WCIS_Settings::get_peers();
		if ( '' !== $only_peer ) {
			$peers = array_filter(
				$peers,
				static function ( $p ) use ( $only_peer ) {
					return WCIS_Settings::normalize_url( $p['url'] ) === WCIS_Settings::normalize_url( $only_peer );
				}
			);
		}

		if ( empty( $peers ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Keine Ziel-Shops konfiguriert.', 'blocksocial-woocommerce-sync' ),
			);
		}

		$items       = self::collect_all_items();
		$total_items = count( $items );
		$batches     = array_chunk( $items, 100 );
		$sent        = 0;
		$failed      = 0;

		foreach ( $peers as $peer ) {
			foreach ( $batches as $batch ) {
				$batch = array_values( WCIS_Partners::filter_items_for_url( $peer['url'], $batch ) );
				if ( empty( $batch ) ) {
					continue;
				}
				$payload = array(
					'source' => WCIS_Settings::this_url(),
					'items'  => $batch,
				);
				$result = WCIS_Client::post( $peer['url'], '/stock', $payload, true );

				if ( is_wp_error( $result ) || $result['code'] < 200 || $result['code'] >= 300 ) {
					$failed++;
					$msg = is_wp_error( $result ) ? $result->get_error_message() : ( 'HTTP ' . $result['code'] );
					WCIS_Queue::add( $peer['url'], $payload, $msg );
				} else {
					$sent++;
				}
			}
		}

		WCIS_Logger::info(
			sprintf( 'Voll-Synchronisation: %d Artikel, %d Batches gesendet, %d fehlgeschlagen.', $total_items, $sent, $failed ),
			'outbound'
		);

		return array(
			'ok'      => true,
			'items'   => $total_items,
			'peers'   => count( $peers ),
			'sent'    => $sent,
			'failed'  => $failed,
		);
	}

	/**
	 * Setzt den lokalen Bestand eines Produkts per SKU – ohne erneutes Broadcasten
	 * (für den Abgleich, wenn dieser Shop selbst korrigiert werden muss).
	 *
	 * @param string $sku   SKU.
	 * @param int    $stock Neuer Bestand.
	 * @return bool Erfolg.
	 */
	public static function set_local_stock( $sku, $stock ) {
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( ! $product_id ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		self::$suppress = true;
		try {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( wc_stock_amount( $stock ) );
			$product->update_meta_data( '_wcis_synced_at', time() );
			$product->save();
		} finally {
			self::$suppress = false;
		}

		return true;
	}

	/**
	 * Sammelt alle synchronisierbaren Artikel dieses Shops (einfache Produkte
	 * und Variationen mit SKU).
	 *
	 * @return array
	 */
	public static function collect_all_items() {
		$items = array();
		$page  = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_sku',
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			);

			foreach ( $query->posts as $post_id ) {
				$product = wc_get_product( $post_id );
				if ( ! $product ) {
					continue;
				}
				if ( ! WCIS_Filter::should_sync( $product ) ) {
					continue; // nicht im Sync-Umfang.
				}
				$item = self::item_from_product( $product );
				if ( $item ) {
					$items[ $item['sku'] ] = $item; // per SKU deduplizieren.
				}
			}

			$max_pages = (int) $query->max_num_pages;
			$page++;
		} while ( $page <= $max_pages );

		return array_values( $items );
	}

	/**
	 * Verarbeitet die Retry-Queue (per Cron).
	 */
	public static function process_queue() {
		WCIS_Queue::process();
	}
}
