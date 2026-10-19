<?php
/**
 * Bestellungen: Lizenzschlüssel beim Kauf erzeugen, Verlängerung und Upgrade
 * über den Warenkorb, Widerruf bei Erstattung/Stornierung.
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestell- und Warenkorb-Logik.
 */
class BSLS_Orders {

	/** Sichtbares Positions-Meta mit den Schlüsseln (erscheint in Mails/Bestellung). */
	const KEY_META = 'Lizenzschlüssel';

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'process_order' ), 5 );
		}
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'revoke_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'revoke_order' ) );

		add_action( 'wp_loaded', array( __CLASS__, 'handle_cart_action' ), 30 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'adjust_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'fixed_quantity' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 3 );

		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_instructions' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_instructions' ) );
	}

	// -------------------------------------------------------------------------
	// Lizenzen erzeugen
	// -------------------------------------------------------------------------

	/**
	 * Erzeugt/verlängert/upgradet Lizenzen einer bezahlten Bestellung (idempotent).
	 *
	 * @param int $order_id Bestellung.
	 */
	public static function process_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ( ! $order->is_paid() && ! $order->has_status( 'completed' ) ) ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || 'yes' === $item->get_meta( '_bsls_processed' ) ) {
				continue;
			}
			$pid = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$cfg = BSLS_Product::config( $pid );
			if ( ! $cfg ) {
				continue;
			}
			$qty   = max( 1, (int) $item->get_quantity() );
			$renew = (string) $item->get_meta( '_bsls_renew' );
			$upgr  = (string) $item->get_meta( '_bsls_upgrade' );

			if ( '' !== $renew ) {
				$lic = BSLS_Licenses::get_by_key( $renew );
				if ( $lic ) {
					BSLS_Licenses::extend( (int) $lic['id'], $cfg['months'] * $qty );
					$lic = BSLS_Licenses::get( (int) $lic['id'] );
					$item->add_meta_data( __( 'Verlängert', 'blocksocial-license-server' ), $lic['license_key'] . ' → ' . self::date( $lic['expires_at'] ), true );
				}
			} elseif ( '' !== $upgr ) {
				$lic = BSLS_Licenses::get_by_key( $upgr );
				if ( $lic ) {
					BSLS_Licenses::update(
						(int) $lic['id'],
						array(
							'sites'      => $cfg['sites'],
							'tier'       => $cfg['tier'],
							'product_id' => $pid,
						)
					);
					$item->add_meta_data( __( 'Upgrade', 'blocksocial-license-server' ), $lic['license_key'] . ' → ' . $cfg['tier'], true );
				}
			} else {
				$keys = array();
				for ( $i = 0; $i < $qty; $i++ ) {
					$lic = BSLS_Licenses::create(
						array_merge(
							$cfg,
							array(
								'order_id'      => $order->get_id(),
								'order_item_id' => $item_id,
								'product_id'    => $pid,
								'user_id'       => $order->get_customer_id(),
								'email'         => $order->get_billing_email(),
							)
						)
					);
					if ( $lic ) {
						$keys[] = $lic['license_key'];
					}
				}
				$item->add_meta_data( self::KEY_META, implode( "\n", $keys ), true );
			}
			$item->add_meta_data( '_bsls_processed', 'yes', true );
			$item->save();
		}
	}

	/**
	 * Widerruft bei Erstattung/Stornierung die mit der Bestellung erzeugten Lizenzen.
	 *
	 * @param int $order_id Bestellung.
	 */
	public static function revoke_order( $order_id ) {
		foreach ( BSLS_Licenses::for_order( $order_id ) as $lic ) {
			if ( 'active' === $lic['status'] ) {
				BSLS_Licenses::update( (int) $lic['id'], array( 'status' => 'revoked' ) );
			}
		}
	}

	/**
	 * Datum lesbar.
	 *
	 * @param string|null $mysql UTC-Datum.
	 * @return string
	 */
	public static function date( $mysql ) {
		if ( empty( $mysql ) ) {
			return __( 'unbegrenzt', 'blocksocial-license-server' );
		}
		return date_i18n( get_option( 'date_format' ), strtotime( $mysql . ' UTC' ) );
	}

	// -------------------------------------------------------------------------
	// Verlängerung / Upgrade über den Warenkorb
	// -------------------------------------------------------------------------

	/**
	 * Link für Verlängerung bzw. Upgrade (aus dem Kundenkonto).
	 *
	 * @param string $action 'renew' | 'upgrade'.
	 * @param string $key    Lizenzschlüssel.
	 * @param int    $to     Ziel-Produkt (Upgrade).
	 * @return string
	 */
	public static function action_url( $action, $key, $to = 0 ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'bsls_action' => $action,
					'bsls_key'    => rawurlencode( $key ),
					'bsls_to'     => (int) $to,
				),
				wc_get_cart_url()
			),
			'bsls_' . $action . '_' . $key
		);
	}

	/**
	 * Legt Verlängerung/Upgrade in den Warenkorb.
	 */
	public static function handle_cart_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce wird unten geprüft.
		if ( empty( $_GET['bsls_action'] ) || empty( $_GET['bsls_key'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['bsls_action'] ) );
		$key    = strtoupper( sanitize_text_field( wp_unslash( $_GET['bsls_key'] ) ) );
		$to     = isset( $_GET['bsls_to'] ) ? (int) $_GET['bsls_to'] : 0;
		// phpcs:enable
		if ( ! in_array( $action, array( 'renew', 'upgrade' ), true ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'bsls_' . $action . '_' . $key ) ) {
			return;
		}
		$lic = BSLS_Licenses::get_by_key( $key );
		if ( ! $lic || ! is_user_logged_in() || (int) $lic['user_id'] !== get_current_user_id() || 'revoked' === $lic['status'] ) {
			wc_add_notice( __( 'Diese Lizenz gehört nicht zu deinem Konto.', 'blocksocial-license-server' ), 'error' );
			return;
		}

		$target = 'renew' === $action ? (int) $lic['product_id'] : $to;
		$p      = wc_get_product( $target );
		$cfg    = BSLS_Product::config( $p );
		if ( ! $p || ! $cfg || $cfg['family'] !== $lic['family'] || ! $p->is_purchasable() ) {
			wc_add_notice( __( 'Dieses Lizenzprodukt ist derzeit nicht erhältlich.', 'blocksocial-license-server' ), 'error' );
			return;
		}
		if ( 'upgrade' === $action && ! self::is_bigger( $cfg['sites'], (int) $lic['sites'] ) ) {
			wc_add_notice( __( 'Upgrade nur auf eine Stufe mit mehr Plätzen möglich.', 'blocksocial-license-server' ), 'error' );
			return;
		}

		// Nur eine Verlängerung/ein Upgrade je Lizenz im Warenkorb.
		foreach ( WC()->cart->get_cart() as $ck => $ci ) {
			if ( ( isset( $ci['bsls_renew'] ) && $ci['bsls_renew'] === $key ) || ( isset( $ci['bsls_upgrade'] ) && $ci['bsls_upgrade'] === $key ) ) {
				WC()->cart->remove_cart_item( $ck );
			}
		}
		$data = array( 'renew' === $action ? 'bsls_renew' : 'bsls_upgrade' => $key );
		if ( $p->is_type( 'variation' ) ) {
			WC()->cart->add_to_cart( $p->get_parent_id(), 1, $p->get_id(), wc_get_product_variation_attributes( $p->get_id() ), $data );
		} else {
			WC()->cart->add_to_cart( $p->get_id(), 1, 0, array(), $data );
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Mehr Plätze? (0 = unbegrenzt ist das Maximum)
	 *
	 * @param int $new Neue Plätze.
	 * @param int $old Bisherige Plätze.
	 * @return bool
	 */
	public static function is_bigger( $new, $old ) {
		if ( 0 === (int) $old ) {
			return false;
		}
		return 0 === (int) $new || (int) $new > (int) $old;
	}

	/**
	 * Preise für Verlängerung (Rabatt) und Upgrade (Differenz) anpassen.
	 *
	 * @param WC_Cart $cart Warenkorb.
	 */
	public static function adjust_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$discount = min( 100, max( 0, (float) get_option( 'bsls_renewal_discount', 0 ) ) );
		foreach ( $cart->get_cart() as $ck => $ci ) {
			if ( empty( $ci['bsls_renew'] ) && empty( $ci['bsls_upgrade'] ) ) {
				continue;
			}
			if ( ! empty( $ci['bsls_upgrade'] ) && (int) $ci['quantity'] > 1 ) {
				$cart->cart_contents[ $ck ]['quantity'] = 1;
			}
			$product = $ci['data'];
			$base    = (float) wc_get_product( $product->get_id() )->get_price();
			if ( ! empty( $ci['bsls_renew'] ) ) {
				$product->set_price( round( $base * ( 100 - $discount ) / 100, wc_get_price_decimals() ) );
			} else {
				$lic     = BSLS_Licenses::get_by_key( $ci['bsls_upgrade'] );
				$current = $lic ? wc_get_product( (int) $lic['product_id'] ) : null;
				$paid    = $current ? (float) $current->get_price() : 0.0;
				$product->set_price( max( 0, round( $base - $paid, wc_get_price_decimals() ) ) );
			}
		}
	}

	/**
	 * Hinweis im Warenkorb.
	 *
	 * @param array $data Anzeige-Daten.
	 * @param array $ci   Warenkorb-Position.
	 * @return array
	 */
	public static function cart_item_data( $data, $ci ) {
		if ( ! empty( $ci['bsls_renew'] ) ) {
			$data[] = array( 'name' => __( 'Verlängerung für', 'blocksocial-license-server' ), 'value' => esc_html( $ci['bsls_renew'] ) );
		}
		if ( ! empty( $ci['bsls_upgrade'] ) ) {
			$data[] = array( 'name' => __( 'Upgrade für', 'blocksocial-license-server' ), 'value' => esc_html( $ci['bsls_upgrade'] ) );
		}
		return $data;
	}

	/**
	 * Upgrades haben immer Menge 1.
	 *
	 * @param string $html Mengenfeld.
	 * @param string $ck   Schlüssel.
	 * @param array  $ci   Position.
	 * @return string
	 */
	public static function fixed_quantity( $html, $ck, $ci ) {
		return ! empty( $ci['bsls_upgrade'] ) ? '1' : $html;
	}

	/**
	 * Überträgt Verlängerung/Upgrade in die Bestellposition.
	 *
	 * @param WC_Order_Item_Product $item   Position.
	 * @param string                $ck     Warenkorb-Schlüssel.
	 * @param array                 $values Warenkorb-Daten.
	 */
	public static function order_item_meta( $item, $ck, $values ) {
		foreach ( array( 'bsls_renew', 'bsls_upgrade' ) as $k ) {
			if ( ! empty( $values[ $k ] ) ) {
				$item->add_meta_data( '_' . $k, $values[ $k ], true );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Hinweise in Mail und Bestellübersicht
	// -------------------------------------------------------------------------

	/**
	 * Enthält die Bestellung neue Lizenzen?
	 *
	 * @param WC_Order $order Bestellung.
	 * @return bool
	 */
	protected static function has_new_keys( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( '' !== (string) $item->get_meta( self::KEY_META ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Anleitung in der Bestellmail.
	 *
	 * @param WC_Order $order         Bestellung.
	 * @param bool     $sent_to_admin An Admin?
	 * @param bool     $plain_text    Nur Text?
	 * @param WC_Email $email         Mail.
	 */
	public static function email_instructions( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! self::has_new_keys( $order ) ) {
			return;
		}
		$text = self::instructions_text();
		if ( $plain_text ) {
			echo "\n" . esc_html( wp_strip_all_tags( implode( "\n", $text ) ) ) . "\n";
			return;
		}
		echo '<h2>' . esc_html( $text[0] ) . '</h2><ol>';
		foreach ( array_slice( $text, 1 ) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Anleitung auf der Bestellseite.
	 *
	 * @param WC_Order $order Bestellung.
	 */
	public static function order_instructions( $order ) {
		self::email_instructions( $order, false, false );
	}

	/**
	 * Text der Kurzanleitung.
	 *
	 * @return array Überschrift + Schritte.
	 */
	protected static function instructions_text() {
		return array(
			__( 'So aktivierst du deine Lizenz', 'blocksocial-license-server' ),
			__( 'Plugin herunterladen: Mein Konto → Lizenzen → Download.', 'blocksocial-license-server' ),
			__( 'Im Hauptshop installieren und aktivieren (WordPress → Plugins → Installieren → Plugin hochladen).', 'blocksocial-license-server' ),
			__( 'WooCommerce → BlockSocial Sync → Lizenz: Lizenzschlüssel einfügen → „Aktivieren".', 'blocksocial-license-server' ),
			__( 'Neben- und Partnershops brauchen keinen eigenen Schlüssel – sie werden über den Hauptshop abgedeckt.', 'blocksocial-license-server' ),
		);
	}
}
