<?php
/**
 * View: Einstellungsseite des Partner-Plugins.
 *
 * Bewusst schlank: Verbindung per Code, Produkte holen, eigene Preisregeln
 * (im erlaubten Rahmen), lokale Optionen. Alle Vorgaben des Hauptshops werden
 * nur angezeigt (schreibgeschützt).
 *
 * @package BlockSocial_WooCommerce_Sync
 * @var array $s Einstellungen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wcis_notice    = isset( $_GET['wcis_notice'] ) ? sanitize_key( wp_unslash( $_GET['wcis_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$wcis_conn      = WCIS_Settings::partner_conn();
$wcis_connected = ! empty( $wcis_conn );
$wcis_m         = is_array( $s['managed'] ) ? $s['managed'] : array();
$wcis_counts    = WCIS_Queue::counts();

$wcis_icon = static function ( $name ) {
	$p = array(
		'connection' => '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
		'products'   => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
		'pricing'    => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
		'log'        => '<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		'lock'       => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
	);
	$d = isset( $p[ $name ] ) ? $p[ $name ] : '';
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};

$wcis_tabs = array(
	'connection' => __( 'Verbindung', 'blocksocial-woocommerce-sync' ),
	'products'   => __( 'Produkte', 'blocksocial-woocommerce-sync' ),
	'pricing'    => __( 'Preise', 'blocksocial-woocommerce-sync' ),
	'log'        => __( 'Protokoll', 'blocksocial-woocommerce-sync' ),
);
$wcis_yes = static function ( $v ) {
	return $v ? __( 'Ja', 'blocksocial-woocommerce-sync' ) : __( 'Nein', 'blocksocial-woocommerce-sync' );
};
?>
<div class="wrap wcis-wrap">
<div class="wcis-app" id="wcis-app">

	<header class="wcis-topbar">
		<div class="wcis-brand">
			<span class="wcis-logo"><?php echo $wcis_icon( 'connection' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<span class="wcis-brand-text">
				<strong><?php esc_html_e( 'BlockSocial Partner', 'blocksocial-woocommerce-sync' ); ?></strong>
				<small><?php echo esc_html( sprintf( __( 'Partner-Edition · v%s', 'blocksocial-woocommerce-sync' ), WCIS_VERSION ) ); ?></small>
			</span>
		</div>
		<div class="wcis-pills">
			<span class="wcis-pill <?php echo $wcis_connected ? 'is-on' : 'is-off'; ?>">
				<i class="wcis-dot"></i><?php echo $wcis_connected ? esc_html( sprintf( __( 'Verbunden mit %s', 'blocksocial-woocommerce-sync' ), isset( $wcis_conn['master_name'] ) ? $wcis_conn['master_name'] : '' ) ) : esc_html__( 'Nicht verbunden', 'blocksocial-woocommerce-sync' ); ?>
			</span>
			<span class="wcis-pill <?php echo $wcis_counts['failed'] > 0 ? 'is-warn' : ''; ?>">
				<?php echo esc_html( sprintf( __( 'Queue %1$d/%2$d', 'blocksocial-woocommerce-sync' ), $wcis_counts['pending'], $wcis_counts['failed'] ) ); ?>
			</span>
		</div>
	</header>

	<div class="wcis-notices">
		<?php
		WCIS_View::notices(
			$wcis_notice,
			array(
				'partner_connected'    => array( 'ok', __( 'Erfolgreich mit dem Hauptshop verbunden. Jetzt unter „Produkte" die Produkte holen.', 'blocksocial-woocommerce-sync' ) ),
				'partner_disconnected' => array( 'ok', __( 'Verbindung getrennt. Es werden keine Daten mehr ausgetauscht.', 'blocksocial-woocommerce-sync' ) ),
				'partner_refreshed'    => array( 'ok', __( 'Vorgaben vom Hauptshop aktualisiert.', 'blocksocial-woocommerce-sync' ) ),
				'partner_error'        => array( 'err', __( 'Verbindung fehlgeschlagen.', 'blocksocial-woocommerce-sync' ) ),
			)
		);
		?>
	</div>

	<div class="wcis-shell">
		<nav class="wcis-nav" role="tablist">
			<?php $wcis_first = true; foreach ( $wcis_tabs as $wcis_id => $wcis_label ) : ?>
				<button type="button" class="wcis-navitem<?php echo $wcis_first ? ' is-active' : ''; ?>" data-tab="<?php echo esc_attr( $wcis_id ); ?>" role="tab">
					<span class="wcis-navicon"><?php echo $wcis_icon( $wcis_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span><?php echo esc_html( $wcis_label ); ?></span>
				</button>
			<?php $wcis_first = false; endforeach; ?>
		</nav>

		<div class="wcis-content">

			<!-- TAB: Verbindung -->
			<section class="wcis-tab is-active" data-tab="connection">
				<?php if ( ! $wcis_connected ) : ?>
					<div class="wcis-card">
						<div class="wcis-card-head"><h2><?php esc_html_e( 'Mit dem Hauptshop verbinden', 'blocksocial-woocommerce-sync' ); ?></h2>
							<p><?php esc_html_e( 'Den Verbindungscode erhältst du vom Betreiber des Hauptshops. Er verbindet diesen Shop sicher mit dem Verbund – mit einem nur für dich gültigen Schlüssel.', 'blocksocial-woocommerce-sync' ); ?></p></div>
						<div class="wcis-card-body">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="wcis_partner_connect" />
								<?php wp_nonce_field( 'wcis_partner_connect' ); ?>
								<div class="wcis-field">
									<label for="wcis-code"><?php esc_html_e( 'Verbindungscode', 'blocksocial-woocommerce-sync' ); ?></label>
									<textarea id="wcis-code" class="wcis-code" name="connection_code" rows="3" placeholder="BSWS1-…" required autocomplete="off"></textarea>
								</div>
								<button type="submit" class="wcis-btn wcis-btn--primary"><?php esc_html_e( 'Verbinden', 'blocksocial-woocommerce-sync' ); ?></button>
							</form>
							<p class="wcis-hint"><?php echo esc_html( sprintf( __( 'Dein Shop wird beim Hauptshop unter der Adresse %s erwartet. Weicht sie ab, bitte dem Betreiber mitteilen.', 'blocksocial-woocommerce-sync' ), untrailingslashit( home_url() ) ) ); ?></p>
						</div>
					</div>
				<?php else : ?>
					<div class="wcis-card">
						<div class="wcis-card-head"><h2><?php esc_html_e( 'Verbindung', 'blocksocial-woocommerce-sync' ); ?></h2></div>
						<div class="wcis-card-body">
							<table class="wcis-kv">
								<tr><th><?php esc_html_e( 'Hauptshop', 'blocksocial-woocommerce-sync' ); ?></th><td><strong><?php echo esc_html( $wcis_conn['master_name'] ); ?></strong> <code><?php echo esc_html( $wcis_conn['master_url'] ); ?></code></td></tr>
								<tr><th><?php esc_html_e( 'Verbunden als', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo esc_html( isset( $wcis_m['partner_name'] ) ? $wcis_m['partner_name'] : '' ); ?> <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code></td></tr>
								<tr><th><?php esc_html_e( 'Seit', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo ! empty( $wcis_conn['connected_at'] ) ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $wcis_conn['connected_at'] ) ) : '–'; ?></td></tr>
								<tr><th><?php esc_html_e( 'Sortiment', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo esc_html( isset( $wcis_m['scope_label'] ) ? $wcis_m['scope_label'] : '' ); ?></td></tr>
							</table>
							<div class="wcis-actionrow">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcis-inline-form">
									<input type="hidden" name="action" value="wcis_partner_refresh" />
									<?php wp_nonce_field( 'wcis_partner_refresh' ); ?>
									<button type="submit" class="wcis-btn wcis-btn--ghost"><?php esc_html_e( 'Verbindung prüfen & Vorgaben aktualisieren', 'blocksocial-woocommerce-sync' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcis-inline-form" data-confirm="<?php esc_attr_e( 'Verbindung zum Hauptshop wirklich trennen? Bestände und Produkte werden dann nicht mehr abgeglichen.', 'blocksocial-woocommerce-sync' ); ?>">
									<input type="hidden" name="action" value="wcis_partner_disconnect" />
									<?php wp_nonce_field( 'wcis_partner_disconnect' ); ?>
									<button type="submit" class="wcis-btn wcis-btn--ghost wcis-btn--danger"><?php esc_html_e( 'Verbindung trennen', 'blocksocial-woocommerce-sync' ); ?></button>
								</form>
							</div>
						</div>
					</div>

					<div class="wcis-card">
						<div class="wcis-card-head"><h2><span class="wcis-lock"><?php echo $wcis_icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span> <?php esc_html_e( 'Vorgaben des Hauptshops', 'blocksocial-woocommerce-sync' ); ?></h2>
							<p><?php esc_html_e( 'Diese Einstellungen legt der Betreiber des Hauptshops fest. Sie gelten für deinen Shop und können hier nicht geändert werden.', 'blocksocial-woocommerce-sync' ); ?></p></div>
						<div class="wcis-card-body">
							<table class="wcis-kv">
								<tr><th><?php esc_html_e( 'Produktdaten werden aktuell gehalten', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo esc_html( $wcis_yes( ! empty( $wcis_m['update_existing'] ) ) ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Preisänderungen des Hauptshops werden übernommen', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo esc_html( $wcis_yes( ! empty( $wcis_m['update_prices'] ) ) ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Bilder werden übertragen', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo esc_html( $wcis_yes( ! empty( $wcis_m['images'] ) ) ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Eigene Preisregeln erlaubt', 'blocksocial-woocommerce-sync' ); ?></th><td><?php echo ! empty( $wcis_m['allow_price_rules'] ) ? esc_html( sprintf( __( 'Ja, von %1$s %% bis %2$s %%', 'blocksocial-woocommerce-sync' ), wc_format_localized_decimal( $wcis_m['price_min'] ), wc_format_localized_decimal( $wcis_m['price_max'] ) ) ) : esc_html__( 'Nein', 'blocksocial-woocommerce-sync' ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Verkäufe werden automatisch an den Hauptshop gemeldet', 'blocksocial-woocommerce-sync' ); ?></th><td><?php esc_html_e( 'Ja (aus Bestellungen)', 'blocksocial-woocommerce-sync' ); ?></td></tr>
							</table>
							<p class="wcis-hint"><?php esc_html_e( 'Hinweis: Den Lagerbestand gibt der Hauptshop vor. Manuelle Bestandsänderungen in diesem Shop werden beim nächsten Abgleich überschrieben – Verkäufe aus Bestellungen werden dagegen immer korrekt zurückgemeldet.', 'blocksocial-woocommerce-sync' ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			</section>

			<!-- TAB: Produkte -->
			<section class="wcis-tab" data-tab="products">
				<?php
				$wcis_qjob     = WCIS_Product_Sync::pull_state();
				$wcis_qrunning = $wcis_qjob && 'running' === $wcis_qjob['status'];
				$wcis_qpct     = WCIS_Product_Sync::pull_percent( $wcis_qjob );
				?>
				<div class="wcis-card">
					<div class="wcis-card-head"><h2><?php esc_html_e( 'Produkte vom Hauptshop holen', 'blocksocial-woocommerce-sync' ); ?></h2>
						<p><?php esc_html_e( 'Holt alle für dich freigegebenen Produkte des Hauptshops in diesen Shop (neue werden angelegt, bestehende gemäß Vorgaben aktualisiert). Danach laufen Bestände und Änderungen automatisch.', 'blocksocial-woocommerce-sync' ); ?></p></div>
					<div class="wcis-card-body">
						<?php if ( ! $wcis_connected ) : ?>
							<p class="wcis-hint"><?php esc_html_e( 'Zuerst unter „Verbindung" mit dem Hauptshop verbinden.', 'blocksocial-woocommerce-sync' ); ?></p>
						<?php else : ?>
							<form method="post" id="wcis-productpull-form" class="wcis-actionrow" onsubmit="return false;">
								<button type="submit" class="wcis-btn wcis-btn--primary" id="wcis-product-pull"><?php esc_html_e( 'Produkte vom Hauptshop holen', 'blocksocial-woocommerce-sync' ); ?></button>
								<button type="button" class="wcis-btn wcis-btn--ghost" id="wcis-product-pull-cancel" style="display:none;"><?php esc_html_e( 'Abbrechen', 'blocksocial-woocommerce-sync' ); ?></button>
							</form>
							<div id="wcis-pull-progress-wrap" class="wcis-progress-wrap" style="<?php echo $wcis_qrunning ? '' : 'display:none;'; ?>">
								<div class="wcis-progress-bar"><div class="wcis-progress-fill" id="wcis-pull-progress-fill" style="width:<?php echo esc_attr( $wcis_qpct ); ?>%;"><span id="wcis-pull-progress-label"><?php echo esc_html( $wcis_qpct . '%' ); ?></span></div></div>
								<p class="wcis-progress-text" id="wcis-pull-progress-text"></p>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="wcis-card">
					<div class="wcis-card-head"><h2><?php esc_html_e( 'Eigene Einstellungen', 'blocksocial-woocommerce-sync' ); ?></h2>
						<p><?php esc_html_e( 'Diese Optionen betreffen nur deinen Shop.', 'blocksocial-woocommerce-sync' ); ?></p></div>
					<div class="wcis-card-body">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="wcis_partner_local_save" />
							<?php wp_nonce_field( 'wcis_partner_local_save' ); ?>
							<div class="wcis-field">
								<label for="wcis-this-name"><?php esc_html_e( 'Name dieses Shops', 'blocksocial-woocommerce-sync' ); ?></label>
								<input type="text" id="wcis-this-name" name="this_shop_name" value="<?php echo esc_attr( $s['this_shop_name'] ); ?>" />
							</div>
							<div class="wcis-field wcis-field--switch">
								<div class="wcis-field-main">
									<label class="wcis-switch"><input type="checkbox" name="price_gross_mode" value="1" <?php checked( ! empty( $s['price_gross_mode'] ) ); ?> /><span class="wcis-slider"></span></label>
									<div><strong><?php esc_html_e( 'Kleinunternehmer: Preise als Brutto übernehmen', 'blocksocial-woocommerce-sync' ); ?></strong><p><?php esc_html_e( 'Für §19-Shops ohne USt.: eingehende Preise werden als Bruttopreis übernommen statt als Nettopreis. WooCommerce-Steuer in diesem Shop entsprechend deaktivieren.', 'blocksocial-woocommerce-sync' ); ?></p></div>
								</div>
							</div>
							<div class="wcis-field">
								<label for="wcis-taxmap"><?php esc_html_e( 'Steuerklassen-Zuordnung', 'blocksocial-woocommerce-sync' ); ?></label>
								<textarea id="wcis-taxmap" name="tax_class_map" rows="3" class="code" style="width:100%;" placeholder="reduzierter-preis=reduced-rate"><?php echo esc_textarea( $s['tax_class_map'] ); ?></textarea>
								<small><?php esc_html_e( 'Nur nötig, wenn die automatische Zuordnung über den Steuersatz nicht greift. Eine Zuordnung pro Zeile: „slug-im-hauptshop=slug-hier".', 'blocksocial-woocommerce-sync' ); ?></small>
							</div>
							<div class="wcis-field">
								<label for="wcis-log-level"><?php esc_html_e( 'Protokoll-Umfang', 'blocksocial-woocommerce-sync' ); ?></label>
								<select id="wcis-log-level" name="log_level">
									<option value="info" <?php selected( $s['log_level'], 'info' ); ?>><?php esc_html_e( 'Alles (Info + Fehler)', 'blocksocial-woocommerce-sync' ); ?></option>
									<option value="error" <?php selected( $s['log_level'], 'error' ); ?>><?php esc_html_e( 'Nur Fehler', 'blocksocial-woocommerce-sync' ); ?></option>
								</select>
							</div>
							<button type="submit" class="wcis-btn wcis-btn--primary"><?php esc_html_e( 'Speichern', 'blocksocial-woocommerce-sync' ); ?></button>
						</form>
					</div>
				</div>
			</section>

			<!-- TAB: Preise -->
			<section class="wcis-tab" data-tab="pricing">
				<?php WCIS_View::pricing_card(); ?>
			</section>

			<!-- TAB: Protokoll -->
			<section class="wcis-tab" data-tab="log">
				<?php WCIS_View::log_card(); ?>
			</section>
		</div>
	</div>
</div>
</div>
