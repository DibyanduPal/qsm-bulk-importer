<?php
/**
 * Recent Imports admin page controller - UPDATED
 *
 * - enqueues ThickBox for modal display of errors/details
 * - if ?log_id= is present, includes the single-log template (templates/import-results.php)
 * - otherwise prepares variables and includes the recent-imports list template
 *
 * Keep your existing capability checks and helper calls intact; this file only handles routing / UI assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* Keep your existing permission check helper */
if ( function_exists( 'qsm_bulk_current_user_can_manage' ) ) {
    if ( ! qsm_bulk_current_user_can_manage() ) {
        wp_die( __( 'Insufficient permissions.', 'qsm-bulk-importer' ) );
    }
} else {
    // Fallback capability check (safe default)
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions.', 'qsm-bulk-importer' ) );
    }
}

/* Make ThickBox available for inline modals (WP built-in) */
if ( function_exists( 'add_thickbox' ) ) {
    add_thickbox();
}

/* Ensure DB helper is available (your plugin likely provides this) */
if ( function_exists( 'qsm_bulk_table_import_logs' ) ) {
    $logs_table = qsm_bulk_table_import_logs();
} else {
    global $wpdb;
    $logs_table = $wpdb->prefix . 'qsm_bulk_import_logs';
}
global $wpdb;

/* If a single log is requested, include the single-log template which accepts a $_GET['log_id'] */
$log_id = isset( $_GET['log_id'] ) ? intval( wp_unslash( $_GET['log_id'] ) ) : 0;
if ( $log_id > 0 ) {
    $single_tpl = defined( 'QSM_BULK_DIR' ) ? QSM_BULK_DIR . 'templates/import-results.php' : plugin_dir_path( __FILE__ ) . '../templates/import-results.php';
    if ( file_exists( $single_tpl ) ) {
        include $single_tpl;
        return;
    }
}

/* Pagination & query for recent imports */
$per_page = 20;
$paged    = isset( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;
$offset   = ( $paged - 1 ) * $per_page;

/* Total count */
$total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" ) );

/* Fetch rows (most recent first) */
$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$logs_table} ORDER BY import_time DESC LIMIT %d OFFSET %d", $per_page, $offset ) );

/* Base URL for pagination and links (adjust page slug if yours differs) */
$base_url = admin_url( 'admin.php?page=qsm-bulk-import/recent' );

/* Provide variables to the template */
$template = defined( 'QSM_BULK_DIR' ) ? QSM_BULK_DIR . 'templates/recent-imports-list.php' : plugin_dir_path( __FILE__ ) . '../templates/recent-imports-list.php';
if ( file_exists( $template ) ) {
    include $template;
    return;
}

/* Fallback inline rendering (should not normally be used if template file exists) */
?>
<div class="wrap">
    <h1><?php esc_html_e( 'QSM Bulk Import — Recent Imports', 'qsm-bulk-importer' ); ?></h1>
    <p><?php esc_html_e( 'No template found (recent-imports-list.php).', 'qsm-bulk-importer' ); ?></p>
</div>
<?php
