<?php
/**
 * Deinstallation: Lizenzdaten bleiben bewusst erhalten (Kundenlizenzen sind
 * geschäftskritisch). Entfernt werden nur Zwischenspeicher.
 *
 * Zum vollständigen Entfernen vorher in wp-config.php setzen:
 * define( 'BSLS_REMOVE_ALL_DATA', true );
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'bsls_github_release' );

if ( defined( 'BSLS_REMOVE_ALL_DATA' ) && BSLS_REMOVE_ALL_DATA ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bsls_sites" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bsls_licenses" ); // phpcs:ignore
	foreach ( array( 'bsls_db_version', 'bsls_secret', 'bsls_releases', 'bsls_github', 'bsls_github_last', 'bsls_github_error', 'bsls_renewal_discount' ) as $opt ) {
		delete_option( $opt );
	}
}
