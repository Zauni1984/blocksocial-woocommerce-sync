<?php
/**
 * Kleine Darstellungs-Helfer für die Admin-Seiten beider Editionen.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * View-Helfer (geben escaptes HTML aus).
 */
class WCIS_View {

	/**
	 * Zwischenspeicher der Kategorien (hierarchisch sortiert).
	 *
	 * @var array|null
	 */
	protected static $cats = null;

	/**
	 * Produktkategorien hierarchisch: Liste von [ id, name, depth ].
	 *
	 * @return array
	 */
	public static function categories() {
		if ( null !== self::$cats ) {
			return self::$cats;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		self::$cats = array();
		if ( is_wp_error( $terms ) ) {
			return self::$cats;
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$walk = static function ( $parent, $depth ) use ( &$walk, $by_parent ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $t ) {
				self::$cats[] = array( (int) $t->term_id, $t->name, $depth );
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return self::$cats;
	}

	/**
	 * <option>-Liste der Kategorien.
	 *
	 * @param array|int $selected Ausgewählte ID(s).
	 * @return string
	 */
	public static function category_options( $selected = array() ) {
		$selected = array_map( 'intval', (array) $selected );
		$html     = '';
		foreach ( self::categories() as $c ) {
			$html .= sprintf(
				'<option value="%d"%s>%s%s</option>',
				$c[0],
				in_array( $c[0], $selected, true ) ? ' selected' : '',
				str_repeat( '&nbsp;&nbsp;&nbsp;', $c[2] ),
				esc_html( $c[1] )
			);
		}
		return $html;
	}

	/**
	 * Mehrfachauswahl für Kategorien (select2).
	 *
	 * @param string $name     Feldname (ohne []).
	 * @param array  $selected Ausgewählte IDs.
	 * @param string $id       HTML-ID.
	 */
	public static function category_multiselect( $name, $selected, $id = '' ) {
		printf(
			'<select name="%1$s[]" %2$s multiple="multiple" class="wc-enhanced-select" style="width:100%%" data-placeholder="%3$s">%4$s</select>',
			esc_attr( $name ),
			$id ? 'id="' . esc_attr( $id ) . '"' : '',
			esc_attr__( 'Kategorien wählen …', 'blocksocial-woocommerce-sync' ),
			self::category_options( $selected ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in category_options().
		);
	}

	/**
	 * Editor für Preisregeln (alle Produkte, je Kategorie, Rundung).
	 *
	 * @param array $rules Regeln.
	 * @param float $min   Minimum %.
	 * @param float $max   Maximum %.
	 */
	public static function rules_editor( array $rules, $min = -90, $max = 1000 ) {
		$rules = WCIS_Pricing::sanitize_rules( $rules, $min, $max );
		$row   = static function ( $tid, $pct ) use ( $min, $max ) {
			?>
			<div class="wcis-rule-row">
				<select name="rule_cat[]" class="wcis-rule-cat">
					<option value="0"><?php esc_html_e( '— Kategorie wählen —', 'blocksocial-woocommerce-sync' ); ?></option>
					<?php echo WCIS_View::category_options( (int) $tid ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</select>
				<span class="wcis-pct"><input type="number" name="rule_pct[]" step="0.01" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" value="<?php echo esc_attr( '' === $pct ? '' : $pct ); ?>" placeholder="0" /> %</span>
				<button type="button" class="wcis-iconbtn wcis-rule-remove" title="<?php esc_attr_e( 'Regel entfernen', 'blocksocial-woocommerce-sync' ); ?>">&times;</button>
			</div>
			<?php
		};
		?>
		<div class="wcis-rules-editor">
			<div class="wcis-field">
				<label><?php esc_html_e( 'Alle Produkte', 'blocksocial-woocommerce-sync' ); ?></label>
				<span class="wcis-pct"><input type="number" name="rule_global" step="0.01" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" value="<?php echo esc_attr( $rules['global'] ); ?>" /> %</span>
				<small><?php esc_html_e( 'Positiv = Aufschlag, negativ = Abschlag. Beispiel: 15 = +15 %, -5 = −5 %.', 'blocksocial-woocommerce-sync' ); ?></small>
			</div>
			<div class="wcis-field">
				<label><?php esc_html_e( 'Je Kategorie (hat Vorrang vor „Alle Produkte")', 'blocksocial-woocommerce-sync' ); ?></label>
				<div class="wcis-rules">
					<?php
					foreach ( $rules['categories'] as $tid => $pct ) {
						$row( $tid, $pct );
					}
					?>
				</div>
				<template class="wcis-rule-tpl"><?php $row( 0, '' ); ?></template>
				<p><button type="button" class="wcis-btn wcis-btn--ghost wcis-rule-add">+ <?php esc_html_e( 'Kategorie-Regel hinzufügen', 'blocksocial-woocommerce-sync' ); ?></button></p>
				<small><?php esc_html_e( 'Unterkategorien erben die Regel ihrer Oberkategorie, eigene Regeln der Unterkategorie haben Vorrang. Liegt ein Produkt in mehreren Kategorien mit Regel, gilt die spezifischste (bei Gleichstand der höhere Wert).', 'blocksocial-woocommerce-sync' ); ?></small>
			</div>
			<div class="wcis-field">
				<label><?php esc_html_e( 'Rundung', 'blocksocial-woocommerce-sync' ); ?></label>
				<select name="rule_rounding">
					<?php foreach ( WCIS_Pricing::rounding_options() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rules['rounding'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<small><?php esc_html_e( 'Wird nur auf angepasste Preise angewendet (0 % = Originalpreis ohne Rundung).', 'blocksocial-woocommerce-sync' ); ?></small>
			</div>
		</div>
		<?php
	}

	/**
	 * Fortschrittsbalken.
	 *
	 * @param string $prefix  ID-Präfix (z. B. „wcis-pricing").
	 * @param int    $percent Prozent.
	 * @param bool   $visible Sichtbar?
	 */
	public static function progress( $prefix, $percent, $visible ) {
		?>
		<div id="<?php echo esc_attr( $prefix ); ?>-progress-wrap" class="wcis-progress-wrap" style="<?php echo $visible ? '' : 'display:none;'; ?>">
			<div class="wcis-progress-bar"><div class="wcis-progress-fill" id="<?php echo esc_attr( $prefix ); ?>-progress-fill" style="width:<?php echo esc_attr( (int) $percent ); ?>%;"><span id="<?php echo esc_attr( $prefix ); ?>-progress-label"><?php echo esc_html( (int) $percent . '%' ); ?></span></div></div>
			<p class="wcis-progress-text" id="<?php echo esc_attr( $prefix ); ?>-progress-text"></p>
		</div>
		<?php
	}

	/**
	 * Karte „Preisregeln" inkl. Vorschau, Anwenden und Fortschritt.
	 */
	public static function pricing_card() {
		?>
		<div class="wcis-card">
			<div class="wcis-card-head"><h2><?php esc_html_e( 'Preisregeln: Preise anpassen', 'blocksocial-woocommerce-sync' ); ?></h2>
				<p><?php esc_html_e( 'Passe die Preise nach dem Einspielen in Prozent an – nach oben oder unten, für alle Produkte oder je Kategorie. Die Regeln bleiben gespeichert und werden bei jedem Preis-Update vom Hauptshop automatisch wieder angewendet, sodass deine Marge erhalten bleibt.', 'blocksocial-woocommerce-sync' ); ?></p></div>
			<div class="wcis-card-body">
				<?php if ( ! WCIS_Pricing::is_available() ) : ?>
					<p class="wcis-hint">
						<?php
						if ( WCIS_Edition::is_partner() ) {
							esc_html_e( 'Eigene Preisregeln sind für deinen Shop vom Hauptshop nicht freigegeben (oder der Shop ist noch nicht verbunden).', 'blocksocial-woocommerce-sync' );
						} else {
							esc_html_e( 'Preisregeln sind für Empfänger-Shops (Neben- und Partnershops) gedacht. Dieser Shop ist der Hauptshop und gibt die Originalpreise vor. Für Shopify-Shops lassen sich eigene Preisregeln im Reiter „Shopify" festlegen.', 'blocksocial-woocommerce-sync' );
						}
						?>
					</p>
				<?php else : ?>
					<?php
					$min = WCIS_Pricing::limit_min();
					$max = WCIS_Pricing::limit_max();
					$job = WCIS_Pricing::job_state();
					$run = $job && 'running' === $job['status'];
					?>
					<form id="wcis-pricing-form" onsubmit="return false;">
						<?php self::rules_editor( WCIS_Pricing::rules(), $min, $max ); ?>
						<?php if ( WCIS_Edition::is_partner() ) : ?>
							<p class="wcis-hint"><?php echo esc_html( sprintf( __( 'Vom Hauptshop erlaubter Rahmen: %1$s %% bis %2$s %%. Werte außerhalb werden automatisch begrenzt.', 'blocksocial-woocommerce-sync' ), wc_format_localized_decimal( $min ), wc_format_localized_decimal( $max ) ) ); ?></p>
						<?php endif; ?>
						<div class="wcis-actionrow">
							<button type="submit" class="wcis-btn wcis-btn--primary" id="wcis-pricing-apply"><?php esc_html_e( 'Speichern & auf alle Produkte anwenden', 'blocksocial-woocommerce-sync' ); ?></button>
							<button type="button" class="wcis-btn wcis-btn--ghost" id="wcis-pricing-preview-btn"><?php esc_html_e( 'Vorschau', 'blocksocial-woocommerce-sync' ); ?></button>
							<button type="button" class="wcis-btn wcis-btn--ghost" id="wcis-pricing-save"><?php esc_html_e( 'Nur speichern', 'blocksocial-woocommerce-sync' ); ?></button>
							<button type="button" class="wcis-btn wcis-btn--ghost" id="wcis-pricing-reset"><?php esc_html_e( 'Originalpreise wiederherstellen', 'blocksocial-woocommerce-sync' ); ?></button>
							<button type="button" class="wcis-btn wcis-btn--ghost" id="wcis-pricing-cancel" style="display:none;"><?php esc_html_e( 'Abbrechen', 'blocksocial-woocommerce-sync' ); ?></button>
							<span class="wcis-hint" id="wcis-pricing-msg"></span>
						</div>
					</form>
					<div id="wcis-pricing-preview" class="wcis-preview" style="display:none;"></div>
					<?php self::progress( 'wcis-pricing', WCIS_Pricing::job_percent( $job ), $run ); ?>
					<p class="wcis-hint"><?php esc_html_e( 'Sicher & umkehrbar: Je Produkt wird der Basispreis gespeichert. Mehrfaches Anwenden ergibt keinen Aufschlag auf den Aufschlag; 0 % bzw. „Originalpreise wiederherstellen" setzt zurück. Manuell im Shop geänderte Preise gelten als neue Basis.', 'blocksocial-woocommerce-sync' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Protokoll-Tabelle.
	 */
	public static function log_card() {
		?>
		<div class="wcis-card">
			<div class="wcis-card-head">
				<h2><?php esc_html_e( 'Protokoll', 'blocksocial-woocommerce-sync' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wcis_clear_log" />
					<?php wp_nonce_field( 'wcis_clear_log' ); ?>
					<button type="submit" class="wcis-btn wcis-btn--ghost"><?php esc_html_e( 'Leeren', 'blocksocial-woocommerce-sync' ); ?></button>
				</form>
			</div>
			<div class="wcis-card-body">
				<?php $logs = WCIS_Logger::recent( 80 ); ?>
				<div class="wcis-logwrap">
					<table class="wcis-log">
						<thead>
							<tr>
								<th style="width:160px;"><?php esc_html_e( 'Zeit (UTC)', 'blocksocial-woocommerce-sync' ); ?></th>
								<th style="width:70px;"><?php esc_html_e( 'Ebene', 'blocksocial-woocommerce-sync' ); ?></th>
								<th style="width:90px;"><?php esc_html_e( 'Richtung', 'blocksocial-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Nachricht', 'blocksocial-woocommerce-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr><td colspan="4" class="wcis-empty"><?php esc_html_e( 'Noch keine Einträge.', 'blocksocial-woocommerce-sync' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $logs as $l ) : ?>
									<tr>
										<td class="code"><?php echo esc_html( $l['created_at'] ); ?></td>
										<td><span class="wcis-lvl wcis-lvl-<?php echo esc_attr( $l['level'] ); ?>"><?php echo esc_html( $l['level'] ); ?></span></td>
										<td><?php echo esc_html( $l['direction'] ); ?></td>
										<td><?php echo esc_html( $l['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Gemeinsame Meldungen (inkl. Fehler-Transient des Benutzers).
	 *
	 * @param string $notice Meldungs-Slug.
	 * @param array  $extra  Zusätzliche Meldungen slug => [typ, text].
	 */
	public static function notices( $notice, array $extra = array() ) {
		$messages = array_merge(
			array(
				'saved'                => array( 'ok', __( 'Einstellungen gespeichert.', 'blocksocial-woocommerce-sync' ) ),
				'log_cleared'          => array( 'ok', __( 'Protokoll geleert.', 'blocksocial-woocommerce-sync' ) ),
				'locked'               => array( 'err', __( 'Diese Einstellung wird vom Hauptshop vorgegeben und kann hier nicht geändert werden.', 'blocksocial-woocommerce-sync' ) ),
				'partner_error'        => array( 'err', __( 'Aktion fehlgeschlagen.', 'blocksocial-woocommerce-sync' ) ),
				'shopify_error'        => array( 'err', __( 'Shopify-Shop konnte nicht gespeichert werden.', 'blocksocial-woocommerce-sync' ) ),
			),
			$extra
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		$m = $messages[ $notice ];
		printf( '<div class="wcis-alert is-%s">%s</div>', esc_attr( $m[0] ), esc_html( $m[1] ) );

		$key = 'wcis_admin_error_' . get_current_user_id();
		$err = get_transient( $key );
		if ( $err && 'err' === $m[0] ) {
			printf( '<div class="wcis-alert is-err">%s</div>', esc_html( $err ) );
			delete_transient( $key );
		}
	}
}
