<?php
/**
 * QSM Bulk Importer — Uninstall helper
 *
 * Functions used by uninstall.php when removal is requested.
 *
 * @package QSM_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'QSM_Bulk_Uninstall_Handler' ) ) {

	class QSM_Bulk_Uninstall_Handler {

		/**
		 * Drop plugin tables created for logs.
		 *
		 * @return bool True on success.
		 */
		public static function drop_tables() {
			global $wpdb;
			$table = QSM_Bulk_DB::import_logs_table();
			// Only drop if table exists
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
				return true;
			}
			return false;
		}

		/**
		 * Remove plugin options from wp_options.
		 *
		 * @return void
		 */
		public static function cleanup_options() {
			delete_option( 'qsm_bulk_version' );
		}
	}
}
