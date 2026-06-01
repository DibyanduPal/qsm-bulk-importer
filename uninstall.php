<?php
/**
 * Uninstall script for QSM Bulk Importer
 *
 * This file is executed when the plugin is uninstalled via the WordPress Plugins screen.
 * It will remove plugin-created database objects and options.
 *
 * IMPORTANT: This file must be present at the plugin root and must check WP_UNINSTALL_PLUGIN.
 */

// If uninstall not called from WordPress, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Table name for import logs (uses current DB prefix)
$logs_table = $wpdb->prefix . 'qsm_import_logs';

// Only proceed if table exists
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $logs_table ) ) ) === $logs_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
}

// Option cleanup (if any options were added by plugin)
$option_keys = array(
	'qsm_bulk_version',
);

// Remove options (silent if not present)
foreach ( $option_keys as $opt ) {
	delete_option( $opt );
}

// If you also want to remove questions inserted by this plugin automatically on uninstall,
// be VERY careful: automatic deletion may remove user content. We do NOT perform that destructive action here.
// The plugin stores question IDs per-import in the import logs; if you want to remove those created questions
// manually, use the Recent Imports → Rollback UI inside the plugin before uninstalling.

