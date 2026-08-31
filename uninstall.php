<?php
/**
 * Deinstallation: entfernt Optionen und Tabellen.
 *
 * @package BlockSocial_WooCommerce_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Optionen entfernen.
delete_option( 'wcis_settings' );
delete_option( 'wcis_fullsync_job' );
delete_option( 'wcis_productsync_job' );
delete_option( 'wcis_productpull_job' );
delete_option( 'wcis_db_version' );

// Transients entfernen.
delete_transient( 'wcis_fullsync_result' );
delete_transient( 'wcis_pushconfig_result' );
delete_transient( 'wcis_fullsync_items' );
delete_transient( 'wcis_reconcile_result' );
delete_transient( 'wcis_productsync_ids' );
delete_transient( 'wcis_queue_lock' );
delete_transient( 'wcis_fullsync_lock' );

// Cron-Events entfernen.
wp_clear_scheduled_hook( 'wcis_process_queue' );
wp_clear_scheduled_hook( 'wcis_daily_cleanup' );
wp_clear_scheduled_hook( 'wcis_reconcile' );

// Tabellen entfernen.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcis_log" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Produkt-Meta bereinigen.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wcis_synced_at'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wcis_price_sig'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
