<?php
/**
 * Admin menu registration for QSM Bulk Importer
 *
 * Registers top-level menu and two submenus: Import Questions and Recent Imports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'qsm_bulk_register_admin_menu', 30 );
function qsm_bulk_register_admin_menu() {
	if ( ! function_exists( 'qsm_bulk_current_user_can_manage' ) ) {
		return;
	}
	
// Show import/rollback notices on the plugin pages.
// Place this after your admin menu registration (see instructions above).
add_action( 'admin_notices', 'qsm_bulk_maybe_show_import_notice' );
function qsm_bulk_maybe_show_import_notice() {
	// Only show on plugin pages to avoid noisy notices everywhere.
	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	// adjust these slugs if your admin pages use different slugs
	$allowed_pages = array(
		'qsm-bulk-import/import',
		'qsm-bulk-import/recent',
		'qsm-bulk-import/import-logs',
	);

	if ( empty( $page ) || ! in_array( $page, $allowed_pages, true ) ) {
		return;
	}

	if ( empty( $_GET['qsm_import_notice'] ) ) {
		return;
	}

	// status for CSS class: success / warning / error
	$status = isset( $_GET['qsm_import_status'] ) ? sanitize_text_field( wp_unslash( $_GET['qsm_import_status'] ) ) : 'success';
	$notice_text = rawurldecode( sanitize_text_field( wp_unslash( $_GET['qsm_import_notice'] ) ) );

	$classes = 'notice is-dismissible';
	if ( 'warning' === $status ) {
		$classes .= ' notice-warning';
	} elseif ( in_array( $status, array( 'error', 'danger' ), true ) ) {
		$classes .= ' notice-error';
	} else {
		$classes .= ' notice-success';
	}

	// allow safe HTML if needed (but keep it minimal)
	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $classes ), wp_kses_post( nl2br( esc_html( $notice_text ) ) ) );
}	

	$capability = 'manage_options'; // adjust if you want editors to also access, but check inside callbacks

	$parent_slug = 'qsm-bulk-import';

	add_menu_page(
		__( 'QSM Bulk Import', 'qsm-bulk-importer' ),
		__( 'QSM Bulk Import', 'qsm-bulk-importer' ),
		$capability,
		$parent_slug,
		'qsm_bulk_page_import_router',
		'dashicons-upload',
		58
	);

	// Submenu: Import Questions (main)
	add_submenu_page(
		$parent_slug,
		__( 'Import Questions', 'qsm-bulk-importer' ),
		__( 'Import Questions', 'qsm-bulk-importer' ),
		$capability,
		$parent_slug . '/import',
		'qsm_bulk_page_import_router'
	);

	// Submenu: Recent Imports
	add_submenu_page(
		$parent_slug,
		__( 'Recent Imports', 'qsm-bulk-importer' ),
		__( 'Recent Imports', 'qsm-bulk-importer' ),
		$capability,
		$parent_slug . '/recent',
		'qsm_bulk_page_recent_router'
	);
		// Remove the auto-generated duplicate submenu
	remove_submenu_page('qsm-bulk-import', 'qsm-bulk-import');
}

/**
 * Router for the Import page (single place to control rendering).
 */
function qsm_bulk_page_import_router() {
	if ( ! qsm_bulk_current_user_can_manage() ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.', 'qsm-bulk-importer' ) );
	}

	// Include page controller which renders or loads template
	$path = QSM_BULK_DIR . 'admin/page-import.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	} else {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Missing admin/page-import.php', 'qsm-bulk-importer' ) . '</p></div>';
	}
}

/**
 * Router for the Recent Imports page.
 */
function qsm_bulk_page_recent_router() {
	if ( ! qsm_bulk_current_user_can_manage() ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.', 'qsm-bulk-importer' ) );
	}

	$path = QSM_BULK_DIR . 'admin/page-recent-imports.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	} else {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Missing admin/page-recent-imports.php', 'qsm-bulk-importer' ) . '</p></div>';
	}
}
