<?php
/**
 * Plugin Name: QSM Bulk Importer
 * Plugin URI:  https://example.com/qsm-bulk-importer
 * Description: Import questions in bulk into Quiz and Survey Master (QSM) from .xlsx, .xls, .csv, and .json files. Admin/editor access only.
 * Version:     1.0.0
 * Author:      Dibyandu Pal
 * Author URI:  https://civilnotes.in
 * Text Domain: qsm-bulk-importer
 * Domain Path: /languages
 *
 * @package QSM_Bulk_Importer
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic plugin constants
 */
define( 'QSM_BULK_VERSION', '1.0.0' );
define( 'QSM_BULK_FILE', __FILE__ );
define( 'QSM_BULK_DIR', plugin_dir_path( __FILE__ ) );
define( 'QSM_BULK_URL', plugin_dir_url( __FILE__ ) );
define( 'QSM_BULK_BASE', basename( __FILE__, '.php' ) );

/**
 * Load Composer autoloader if present (vendor/)
 */
$vendor_autoload = QSM_BULK_DIR . 'vendor/autoload.php';
if ( file_exists( $vendor_autoload ) ) {
	require_once $vendor_autoload;
}

/**
 * Load i18n textdomain
 */
add_action( 'init', 'qsm_bulk_load_textdomain', 5 );
function qsm_bulk_load_textdomain() {
	load_plugin_textdomain( 'qsm-bulk-importer', false, dirname( plugin_basename( QSM_BULK_FILE ) ) . '/languages' );
}

/**
 * Activation hook: create plugin tables (import logs)
 *
 * Uses sql/install-tables.php if present; otherwise falls back to inline dbDelta.
 */
register_activation_hook( QSM_BULK_FILE, 'qsm_bulk_activate' );
function qsm_bulk_activate() {
	global $wpdb;

	// Prefer installed SQL helper if present.
	$installer = QSM_BULK_DIR . 'sql/install-tables.php';
	if ( file_exists( $installer ) ) {
		require_once $installer;
		if ( function_exists( 'qsm_bulk_install_tables_from_sql' ) ) {
			$result = qsm_bulk_install_tables_from_sql();
			if ( ! empty( $result ) && ! empty( $result['success'] ) ) {
				update_option( 'qsm_bulk_version', QSM_BULK_VERSION );
				return;
			}
			// Otherwise fall through to inline creation.
		}
	}

	// Fallback inline table creation (uses $wpdb->prefix).
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->prefix . 'qsm_import_logs';

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		file_name TEXT NOT NULL,
		uploader_id BIGINT(20) NOT NULL DEFAULT 0,
		quiz_id BIGINT(20) NOT NULL DEFAULT 0,
		quiz_name TEXT DEFAULT '',
		import_time DATETIME NOT NULL,
		status VARCHAR(50) NOT NULL DEFAULT 'pending',
		total_rows INT NOT NULL DEFAULT 0,
		success_rows INT NOT NULL DEFAULT 0,
		failed_rows INT NOT NULL DEFAULT 0,
		question_ids LONGTEXT DEFAULT NULL,
		errors LONGTEXT DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY quiz_id_idx (quiz_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'qsm_bulk_version', QSM_BULK_VERSION );
}

/**
 * Deactivation hook: housekeeping (do not delete data)
 */
register_deactivation_hook( QSM_BULK_FILE, 'qsm_bulk_deactivate' );
function qsm_bulk_deactivate() {
	// Placeholder: do not destructively remove data on deactivation.
}

/**
 * Load core includes and admin controllers safely.
 *
 * IMPORTANT: Do NOT require admin page templates here (admin/page-*.php).
 * Those templates perform capability checks and should only be loaded
 * inside callbacks (after WP pluggable functions are available).
 */
if ( is_admin() ) {
	$admin_includes = array(
		// Core helpers (safe)
		'includes/class-db.php',
		'includes/functions-helpers.php',

		// Core processing classes (safe to include; they don't run user functions on include)
		'includes/class-import-parser.php',
		'includes/class-import-processor.php',
		'includes/class-import-logger.php',
		'includes/uninstall-handler.php',

		// Admin controllers that register hooks but do not execute capability checks immediately.
		'admin/admin-menu.php',
		'admin/ajax-handlers.php',
	);

	foreach ( $admin_includes as $rel ) {
		$path = QSM_BULK_DIR . $rel;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

/**
 * Enqueue admin assets (only if files exist)
 */
add_action( 'admin_enqueue_scripts', 'qsm_bulk_enqueue_admin_assets' );
function qsm_bulk_enqueue_admin_assets( $hook ) {
	// Optionally limit loading by checking $hook or current screen.
	$admin_css = QSM_BULK_DIR . 'assets/admin/css/admin.css';
	$admin_js  = QSM_BULK_DIR . 'assets/admin/js/admin.js';

	$css_url = QSM_BULK_URL . 'assets/admin/css/admin.css';
	$js_url  = QSM_BULK_URL . 'assets/admin/js/admin.js';

	if ( file_exists( $admin_css ) ) {
		wp_register_style( 'qsm-bulk-admin', $css_url, array(), QSM_BULK_VERSION );
		wp_enqueue_style( 'qsm-bulk-admin' );
	}

	if ( file_exists( $admin_js ) ) {
		wp_register_script( 'qsm-bulk-admin', $js_url, array( 'jquery' ), QSM_BULK_VERSION, true );
		wp_localize_script(
			'qsm-bulk-admin',
			'qsmBulkVars',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'qsm_bulk_nonce' ),
				'i18n'    => array(
					'importing' => __( 'Importing…', 'qsm-bulk-importer' ),
				),
			)
		);
		wp_enqueue_script( 'qsm-bulk-admin' );
	}
}

/**
 * Enqueue the dropzone-fix assets on QSM Bulk Import admin page.
 */
add_action( 'admin_enqueue_scripts', 'qsm_bulk_enqueue_dropzone_fix' );
function qsm_bulk_enqueue_dropzone_fix( $hook ) {
    // Narrow down to the plugin admin page: check query param page.
    // Adjust the page slug string below if your import page uses a different 'page' value.
    if ( ! isset( $_GET['page'] ) ) {
        return;
    }

    $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
    // Example page slugs we expect: 'qsm-bulk-import/import' or 'qsm-bulk-import'
    if ( false === strpos( $page, 'qsm-bulk-import' ) ) {
        return;
    }

    // Use plugin_dir_url( __FILE__ ) only if this code lives in the main plugin file.
    // If you place this in an include file, adjust the path accordingly.
    $base = plugin_dir_url( __FILE__ );

    wp_enqueue_script(
        'qsm-dropzone-fix',
        $base . 'assets/admin/js/qsm-dropzone.js',
        array(), // no deps
        '1.0.0',
        true
    );

    wp_enqueue_style(
        'qsm-dropzone-fix',
        $base . 'assets/admin/css/qsm-dropzone.css',
        array(),
        '1.0.0'
    );
}

/**
 * Utility: return plugin table names (use $wpdb->prefix)
 */
function qsm_bulk_table_import_logs() {
	global $wpdb;
	return $wpdb->prefix . 'qsm_import_logs';
}

function qsm_bulk_table_questions() {
	global $wpdb;
	return $wpdb->prefix . 'mlw_questions';
}

function qsm_bulk_table_question_terms() {
	global $wpdb;
	return $wpdb->prefix . 'mlw_question_terms';
}

function qsm_bulk_table_quizzes() {
	global $wpdb;
	return $wpdb->prefix . 'mlw_quizzes';
}

/**
 * Admin capability check helper (only called inside hooks / callbacks)
 */
function qsm_bulk_current_user_can_manage() {
	// Admins and editors should be able to use the importer (adjust if needed).
	return current_user_can( 'manage_options' ) || current_user_can( 'edit_others_posts' );
}

/**
 * Provide a safe wrapper to access vendor classes (e.g. PhpSpreadsheet)
 */
function qsm_bulk_has_phpspreadsheet() {
	return class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' );
}

/* End of bootstrap file */
