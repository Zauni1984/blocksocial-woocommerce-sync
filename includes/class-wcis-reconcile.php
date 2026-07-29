<?php
/**
 * Periodischer Abgleich (Reconciliation): erkennt Bestands-Drift zwischen den
 * Shops und reicht Korrekturen nach. Fängt Fälle ab, in denen ein Shop kurz
 * nicht erreichbar war und eine Änderung verpasst hat (über die Retry-Queue
 * hinaus).
 *
 * Der Abgleich wird vom Hauptshop (Master) koordiniert, damit nicht mehrere
 * Shops gleichzeitig widersprüchliche Korrekturen verteilen.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abgleich-Logik und Zeitplanung.
 */
class WCIS_Reconcile {

	/**
	 * Cron-Hook-Name.
	 */
	const EVENT = 'wcis_reconcile';

	/**
	 * Registriert die Hooks.
	 */
	public static function init() {
		add_action( self::EVENT, array( __CLASS__, 'run_cron' ) );
	}

	/**
	 * Ordnet ein Intervall-Setting einer Cron-Wiederholung zu.
	 *
	 * @param string $interval Intervall-Slug.
	 * @return string|false Recurrence oder false (aus).
	 */
	protected static function recurrence_for( $interval ) {
		switch ( $interval ) {
			case 'hourly':
				return 'hourly';
			case 'sixhourly':
				return 'wcis_six_hours';
			case 'daily':
				return 'daily';
			case 'off':
			default:
				return false;
		}
	}

	/**
	 * Plant das Abgleich-Event gemäß den Einstellungen neu.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::EVENT );

		$interval   = WCIS_Settings::get( 'reconcile_interval', 'hourly' );
		$recurrence = self::recurrence_for( $interval );

		if ( $recurrence ) {
			wp_schedule_event( time() + 300, $recurrence, self::EVENT );
		}
	}

	/**
	 * Cron-Einstiegspunkt: läuft nur auf dem Hauptshop.
	 */
	public static function run_cron() {
		if ( ! WCIS_Settings::is_enabled() ) {
			return;
		}
		if ( ! WCIS_Settings::is_master() ) {
			// Nur der Hauptshop koordiniert den Abgleich.
			return;
		}
		self::run();
	}

	/**
	 * Führt den Abgleich aus.
	 *
	 * @return array Statistik: checked_peers, drift, corrected, queued, unreachable.
	 */
	public static function run() {
		$peers = WCIS_Settings::get_peers();
		$stats = array(
			'checked_peers' => 0,
			'skus_checked'  => 0,
			'drift'         => 0,
			'corrected'     => 0,
			'queued'        => 0,
			'unreachable'   => 0,
		);

		if ( empty( $peers ) || '' === WCIS_Settings::secret() ) {
			return $stats;
		}

		$strategy  = WCIS_Settings::get( 'reconcile_strategy', 'lowest' );
		$local_map = self::stock_map( WCIS_Sync_Engine::collect_all_items() );

		// Bestände aller erreichbaren Peers einsammeln.
		$peer_maps = array();
		foreach ( $peers as $peer ) {
			$res = WCIS_Client::get( $peer['url'], '/inventory' );
			if ( is_wp_error( $res ) || $res['code'] < 200 || $res['code'] >= 300 ) {
				$stats['unreachable']++;
				WCIS_Logger::error(
					sprintf( 'Abgleich: %s nicht erreichbar (%s).', $peer['url'], is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'] ),
					'outbound'
				);
				continue;
			}
			$data = json_decode( $res['body'], true );
			$items = ( is_array( $data ) && isset( $data['items'] ) ) ? $data['items'] : array();
			$peer_maps[ $peer['url'] ] = self::stock_map( $items );
			$stats['checked_peers']++;
		}

		if ( empty( $peer_maps ) ) {
			return $stats;
		}

		// Pro SKU (die dieser Shop führt) den Sollwert bestimmen und Abweichungen korrigieren.
		foreach ( $local_map as $sku => $local_stock ) {
			// Alle Shops sammeln, die diese SKU führen (dieser Shop + Peers).
			$holders = array( '__self__' => $local_stock );
			foreach ( $peer_maps as $purl => $pmap ) {
				if ( array_key_exists( $sku, $pmap ) ) {
					$holders[ $purl ] = $pmap[ $sku ];
				}
			}

			// Produkte, die nur in einem Shop existieren, werden ignoriert.
			if ( count( $holders ) < 2 ) {
				continue;
			}

			$stats['skus_checked']++;

			// Sollwert je nach Strategie.
			if ( 'local' === $strategy ) {
				$target = $local_stock; // Hauptshop ist maßgeblich.
			} else {
				$target = min( $holders ); // Niedrigster Bestand gewinnt (kein Überverkauf).
			}

			// Alle abweichenden Shops korrigieren.
			foreach ( $holders as $where => $value ) {
				if ( (int) $value === (int) $target ) {
					continue;
				}
				$stats['drift']++;

				if ( '__self__' === $where ) {
					WCIS_Sync_Engine::set_local_stock( $sku, $target );
					$stats['corrected']++;
					continue;
				}

				$payload = array(
					'source' => WCIS_Settings::this_url(),
					'items'  => array(
						array(
							'sku'          => $sku,
							'manage_stock' => true,
							'stock'        => (int) $target,
							'in_stock'     => $target > 0,
							'timestamp'    => time(),
						),
					),
				);

				$res = WCIS_Client::post( $where, '/stock', $payload, true );
				if ( is_wp_error( $res ) || $res['code'] < 200 || $res['code'] >= 300 ) {
					// Nicht zustellbar -> in Retry-Queue, wird später nachgereicht.
					WCIS_Queue::add( $where, $payload, is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'] );
					$stats['queued']++;
				} else {
					$stats['corrected']++;
				}
			}
		}

		WCIS_Logger::info(
			sprintf(
				'Abgleich abgeschlossen: %d Shop(s) geprüft, %d SKUs, %d Abweichungen, %d korrigiert, %d nachzureichen (Queue), %d nicht erreichbar.',
				$stats['checked_peers'],
				$stats['skus_checked'],
				$stats['drift'],
				$stats['corrected'],
				$stats['queued'],
				$stats['unreachable']
			),
			'outbound',
			$stats
		);

		return $stats;
	}

	/**
	 * Wandelt eine Item-Liste in eine SKU=>Bestand-Map (nur mengenverwaltete Artikel).
	 *
	 * @param array $items Item-Liste.
	 * @return array
	 */
	protected static function stock_map( $items ) {
		$map = array();
		foreach ( (array) $items as $item ) {
			if ( empty( $item['sku'] ) ) {
				continue;
			}
			if ( empty( $item['manage_stock'] ) || ! isset( $item['stock'] ) || null === $item['stock'] ) {
				continue; // Nur Artikel mit Mengenverwaltung abgleichen.
			}
			$map[ (string) $item['sku'] ] = (int) $item['stock'];
		}
		return $map;
	}
}
