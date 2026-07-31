<?php
/**
 * Voll-Synchronisation als abbruchsicherer Hintergrund-Job mit Fortschritt.
 *
 * Der Job wird in kleinen Häppchen ("Ticks") abgearbeitet – gesteuert per AJAX
 * aus dem Browser. Dadurch entstehen keine PHP-Timeouts und der Fortschritt lässt
 * sich in Prozent anzeigen.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job-Manager für die Voll-Synchronisation.
 */
class WCIS_Fullsync {

	/**
	 * Options-Schlüssel für den Job-Status.
	 */
	const JOB_OPT = 'wcis_fullsync_job';

	/**
	 * Transient-Schlüssel für die gesammelten Artikel.
	 */
	const ITEMS_TR = 'wcis_fullsync_items';

	/**
	 * Zeitbudget pro Tick in Sekunden (verhindert Request-Timeouts).
	 */
	const TICK_BUDGET = 8.0;

	/**
	 * Startet einen neuen Voll-Sync-Job.
	 *
	 * @param int $batch_size Optionale Batchgröße (0 = aus Einstellungen).
	 * @return array|WP_Error Job-Status oder Fehler.
	 */
	public static function start( $batch_size = 0 ) {
		// Läuft bereits ein Job? Dann nicht überschreiben (Doppelklick / zweiter Tab).
		$existing = self::state();
		if ( is_array( $existing ) && 'running' === $existing['status'] ) {
			return $existing;
		}

		$peers = WCIS_Settings::get_peers();
		if ( empty( $peers ) ) {
			return new WP_Error( 'wcis_no_targets', __( 'Keine Ziel-Shops konfiguriert.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( '' === WCIS_Settings::secret() ) {
			return new WP_Error( 'wcis_no_secret', __( 'Kein Netzwerk-Secret gesetzt.', 'blocksocial-woocommerce-sync' ) );
		}

		$batch_size = $batch_size > 0 ? (int) $batch_size : (int) WCIS_Settings::get( 'batch_size', 50 );
		$batch_size = max( 1, min( 500, $batch_size ) );

		$items = WCIS_Sync_Engine::collect_all_items();
		set_transient( self::ITEMS_TR, $items, HOUR_IN_SECONDS );

		$total_items      = count( $items );
		$batches_per_peer = (int) ceil( $total_items / $batch_size );
		$peer_urls        = wp_list_pluck( $peers, 'url' );

		$job = array(
			'status'           => $total_items > 0 ? 'running' : 'done',
			'batch_size'       => $batch_size,
			'total_items'      => $total_items,
			'batches_per_peer' => $batches_per_peer,
			'peers'            => array_values( $peer_urls ),
			'peer_names'       => array_values( wp_list_pluck( $peers, 'name' ) ),
			'peer_index'       => 0,
			'batch_index'      => 0,
			'tasks_done'       => 0,
			'total_tasks'      => max( 1, $batches_per_peer * count( $peer_urls ) ),
			'sent'             => 0,
			'failed'           => 0,
			'started_at'       => time(),
			'updated_at'       => time(),
		);

		update_option( self::JOB_OPT, $job, false );

		if ( 'done' === $job['status'] ) {
			delete_transient( self::ITEMS_TR );
		}

		WCIS_Logger::info(
			sprintf( 'Voll-Synchronisation gestartet: %d Artikel, Batchgröße %d, %d Shop(s).', $total_items, $batch_size, count( $peer_urls ) ),
			'outbound'
		);

		return $job;
	}

	/**
	 * Verarbeitet den nächsten Abschnitt des Jobs (mehrere Batches im Zeitbudget).
	 *
	 * @return array|WP_Error
	 */
	public static function tick() {
		$job = get_option( self::JOB_OPT, null );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'wcis_no_job', __( 'Kein laufender Sync-Job.', 'blocksocial-woocommerce-sync' ) );
		}
		if ( 'running' !== $job['status'] ) {
			return $job;
		}

		// Gegen überlappende Ticks (zwei Tabs / Reload während des Pollings)
		// absichern – sonst würden Zähler doppelt hochgezählt und Batches
		// mehrfach gesendet. Selbstfreigebend nach 30 s.
		if ( get_transient( 'wcis_fullsync_lock' ) ) {
			return $job;
		}
		set_transient( 'wcis_fullsync_lock', 1, 30 );

		try {
			return self::tick_locked( $job );
		} finally {
			delete_transient( 'wcis_fullsync_lock' );
		}
	}

	/**
	 * Eigentliche Tick-Verarbeitung (innerhalb der Tick-Sperre).
	 *
	 * @param array $job Job-Status.
	 * @return array
	 */
	protected static function tick_locked( $job ) {
		$items = get_transient( self::ITEMS_TR );
		if ( ! is_array( $items ) ) {
			// Artikel-Cache abgelaufen -> neu einsammeln (liefert aktuelle Bestände).
			$items = WCIS_Sync_Engine::collect_all_items();
		}
		// TTL bei jedem Tick auffrischen, damit die Artikelliste bei langen Läufen
		// nicht mitten im Job abläuft (sonst Neu-Einsammeln gegen einen eingefrorenen
		// Batch-Plan → verlorene oder leere Batches).
		set_transient( self::ITEMS_TR, $items, HOUR_IN_SECONDS );

		$peer_count = count( $job['peers'] );
		$start      = microtime( true );

		do {
			if ( $job['peer_index'] >= $peer_count ) {
				$job['status'] = 'done';
				break;
			}

			$peer_url  = $job['peers'][ $job['peer_index'] ];
			$offset    = $job['batch_index'] * $job['batch_size'];
			$batch     = array_slice( $items, $offset, $job['batch_size'] );
			$skip_peer = false;

			if ( ! empty( $batch ) ) {
				$payload = array(
					'source' => WCIS_Settings::this_url(),
					'items'  => $batch,
				);
				$res = WCIS_Client::post( $peer_url, '/stock', $payload, true );

				if ( is_wp_error( $res ) ) {
					// Shop nicht erreichbar -> restliche Batches dieses Shops überspringen.
					$job['failed']++;
					WCIS_Queue::add( $peer_url, $payload, $res->get_error_message() );
					$skip_peer = true;
				} elseif ( $res['code'] < 200 || $res['code'] >= 300 ) {
					$job['failed']++;
					WCIS_Queue::add( $peer_url, $payload, 'HTTP ' . $res['code'] );
				} else {
					$job['sent']++;
				}
			}

			$job['tasks_done']++;

			if ( $skip_peer ) {
				$remaining = max( 0, $job['batches_per_peer'] - $job['batch_index'] - 1 );
				$job['tasks_done'] += $remaining;
				$job['failed']     += $remaining;
				$job['batch_index'] = 0;
				$job['peer_index']++;
			} else {
				$job['batch_index']++;
				if ( $job['batch_index'] >= $job['batches_per_peer'] ) {
					$job['batch_index'] = 0;
					$job['peer_index']++;
				}
			}

			if ( $job['peer_index'] >= $peer_count ) {
				$job['status'] = 'done';
			}
		} while ( 'running' === $job['status'] && ( microtime( true ) - $start ) < self::TICK_BUDGET );

		$job['updated_at'] = time();
		update_option( self::JOB_OPT, $job, false );

		if ( 'done' === $job['status'] ) {
			delete_transient( self::ITEMS_TR );
			WCIS_Logger::info(
				sprintf( 'Voll-Synchronisation abgeschlossen: %d Artikel, %d Batches gesendet, %d fehlgeschlagen.', $job['total_items'], $job['sent'], $job['failed'] ),
				'outbound'
			);
		}

		return $job;
	}

	/**
	 * Liefert den aktuellen Job-Status (oder null).
	 *
	 * @return array|null
	 */
	public static function state() {
		$job = get_option( self::JOB_OPT, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Bricht einen laufenden Job ab.
	 */
	public static function cancel() {
		$job = self::state();
		if ( is_array( $job ) && 'running' === $job['status'] ) {
			$job['status']     = 'cancelled';
			$job['updated_at'] = time();
			update_option( self::JOB_OPT, $job, false );
		}
		delete_transient( self::ITEMS_TR );
		WCIS_Logger::info( 'Voll-Synchronisation abgebrochen.', 'outbound' );
	}

	/**
	 * Berechnet den Fortschritt in Prozent (0–100).
	 *
	 * @param array|null $job Job-Status.
	 * @return int
	 */
	public static function percent( $job ) {
		if ( ! is_array( $job ) || empty( $job['total_tasks'] ) ) {
			return 0;
		}
		if ( 'done' === $job['status'] ) {
			return 100;
		}
		return (int) min( 100, floor( 100 * $job['tasks_done'] / $job['total_tasks'] ) );
	}

	/**
	 * Bereitet den Job für die Ausgabe per AJAX auf.
	 *
	 * @param array|null $job Job-Status.
	 * @return array
	 */
	public static function to_response( $job ) {
		if ( ! is_array( $job ) ) {
			return array( 'status' => 'idle', 'percent' => 0 );
		}
		$current_peer = '';
		if ( 'running' === $job['status'] && isset( $job['peers'][ $job['peer_index'] ] ) ) {
			$names        = isset( $job['peer_names'] ) ? $job['peer_names'] : array();
			$current_peer = isset( $names[ $job['peer_index'] ] ) && $names[ $job['peer_index'] ]
				? $names[ $job['peer_index'] ]
				: $job['peers'][ $job['peer_index'] ];
		}
		return array(
			'status'       => $job['status'],
			'percent'      => self::percent( $job ),
			'total_items'  => (int) $job['total_items'],
			'tasks_done'   => (int) $job['tasks_done'],
			'total_tasks'  => (int) $job['total_tasks'],
			'sent'         => (int) $job['sent'],
			'failed'       => (int) $job['failed'],
			'peers'        => count( $job['peers'] ),
			'current_peer' => $current_peer,
		);
	}
}
