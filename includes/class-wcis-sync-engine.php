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

		// Retry-Queue-Verarbeitung per Cron.
		add_action( 'wcis_process_queue', array( __CLASS__, 'process_queue' ) );
		add_action( 'wcis_daily_cleanup', array( 'WCIS_Logger', 'cleanup' ) );
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
		if ( self::$suppress || ! WCIS_Settings::is_enabled() ) {
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
		if ( self::$suppress || ! WCIS_Settings::is_enabled() ) {
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
			'in_stock'     => $product->is_in_stock(),
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
		if ( empty( $peers ) || '' === WCIS_Settings::secret() ) {
			return;
		}

		// Antwort an den Browser abschließen, dann im Hintergrund senden.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$payload = array(
			'source' => WCIS_Settings::this_url(),
			'items'  => $items,
		);

		foreach ( $peers as $peer ) {
			self::deliver( $peer['url'], $payload );
		}
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
			'applied' => 0,
			'skipped' => 0,
			'ignored' => 0, // SKU im Zielshop nicht vorhanden -> nur in einem Shop.
		);

		foreach ( $items as $item ) {
			$result = self::apply_item( $item );
			if ( isset( $stats[ $result ] ) ) {
				$stats[ $result ]++;
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

		// Explizit ausgeschlossene Produkte auch eingehend nicht verändern.
		if ( WCIS_Filter::is_excluded( $product ) ) {
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
		try {
			if ( ! empty( $item['manage_stock'] ) && isset( $item['stock'] ) && null !== $item['stock'] ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( wc_stock_amount( $item['stock'] ) );
				// stock_status wird von WooCommerce beim Speichern anhand der Menge gesetzt.
			} elseif ( WCIS_Settings::get( 'sync_status', true ) ) {
				$product->set_manage_stock( false );
				$product->set_stock_status( ! empty( $item['in_stock'] ) ? 'instock' : 'outofstock' );
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
