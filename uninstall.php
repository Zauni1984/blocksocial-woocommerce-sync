<?php
/**
 * Deinstallation: entfernt Optionen, Tabellen und Produkt-Metadaten.
 *
 * Beide Editionen (Admin- und Partner-Plugin) nutzen dieselben Daten. Ist die
 * jeweils andere Edition noch installiert, bleiben die Daten erhalten.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'blocksocial-woocommerce-sync/blocksocial-woocommerce-sync.php', 'blocksocial-woocommerce-sync-partner/blocksocial-woocommerce-sync-partner.php' ) as $wcis_other ) {
	if ( WP_UNINSTALL_PLUGIN !== $wcis_other && file_exists( WP_PLUGIN_DIR . '/' . $wcis_other ) ) {
		return; // Andere Edition noch installiert → Daten behalten.
	}
}

// Optionen entfernen.
foreach ( array( 'wcis_settings', 'wcis_fullsync_job', 'wcis_productsync_job', 'wcis_productpull_job', 'wcis_db_version', 'wcis_partners', 'wcis_shopify_stores', 'wcis_pricing_job', 'wcis_shopify_job', 'wcis_reprice_offset', 'wcis_feeds' ) as $wcis_opt ) {
	delete_option( $wcis_opt );
}

// Transients entfernen.
foreach ( array( 'wcis_fullsync_result', 'wcis_pushconfig_result', 'wcis_fullsync_items', 'wcis_reconcile_result', 'wcis_productsync_ids', 'wcis_queue_lock', 'wcis_fullsync_lock', 'wcis_pricing_ids', 'wcis_pricing_lock', 'wcis_shopify_ids', 'wcis_shopify_lock' ) as $wcis_tr ) {
	delete_transient( $wcis_tr );
}

// Cron-Events entfernen.
wp_clear_scheduled_hook( 'wcis_process_queue' );
wp_clear_scheduled_hook( 'wcis_daily_cleanup' );
wp_clear_scheduled_hook( 'wcis_reconcile' );
wp_clear_scheduled_hook( 'wcis_reprice_batch' );
wp_clear_scheduled_hook( 'wcis_feed_refresh' );

wp_clear_scheduled_hook( 'wcis_feed_build' );

// Tabellen entfernen.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_log" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_feed_rows" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Produkt-Meta bereinigen (Sync-Zeitstempel, Preissignatur, Preisregeln, Shopify-Zuordnungen).
// Die aktuellen Produktpreise selbst bleiben unverändert.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wcis_synced_at','_wcis_price_sig','_wcis_base_regular_price','_wcis_base_sale_price','_wcis_final_regular_price','_wcis_final_sale_price','_wcis_price_percent') OR meta_key LIKE '\_wcis\_shp\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Idempotenz-Marker gemeldeter Partner-Verkäufe / Shopify-Webhooks.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wcis\_evt\_%' OR option_name LIKE '\_transient\_timeout\_wcis\_evt\_%' OR option_name LIKE '\_transient\_wcis\_shpwh\_%' OR option_name LIKE '\_transient\_timeout\_wcis\_shpwh\_%' OR option_name LIKE 'wcis\_evt\_%' OR option_name LIKE 'wcis\_shplock\_%' OR option_name LIKE 'wcis\_feedlock\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
