<?php
/**
 * QSM Bulk Importer — Logger
 *
 * Handles writing and reading import logs to/from the plugin log table.
 *
 * @package QSM_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'QSM_Bulk_Logger' ) ) {

	class QSM_Bulk_Logger {

		/**
		 * Write an import log entry.
		 *
		 * $data expects:
		 *  - file_name (string)
		 *  - uploader_id (int)
		 *  - quiz_id (int)
		 *  - quiz_name (string)
		 *  - import_time (datetime string)
		 *  - status (string)
		 *  - total_rows (int)
		 *  - success_rows (int)
		 *  - failed_rows (int)
		 *  - question_ids (array)
		 *  - errors (array|string)
		 *
		 * @param array $data Log data.
		 * @return int|false Insert ID or false on failure.
		 */
		public static function insert_log( $data ) {
			global $wpdb;

			$table = QSM_Bulk_DB::import_logs_table();

			$insert = array(
				'file_name'    => isset( $data['file_name'] ) ? wp_strip_all_tags( $data['file_name'] ) : '',
				'uploader_id'  => isset( $data['uploader_id'] ) ? intval( $data['uploader_id'] ) : get_current_user_id(),
				'quiz_id'      => isset( $data['quiz_id'] ) ? intval( $data['quiz_id'] ) : 0,
				'quiz_name'    => isset( $data['quiz_name'] ) ? sanitize_text_field( $data['quiz_name'] ) : '',
				'import_time'  => isset( $data['import_time'] ) ? sanitize_text_field( $data['import_time'] ) : current_time( 'mysql' ),
				'status'       => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'pending',
				'total_rows'   => isset( $data['total_rows'] ) ? intval( $data['total_rows'] ) : 0,
				'success_rows' => isset( $data['success_rows'] ) ? intval( $data['success_rows'] ) : 0,
				'failed_rows'  => isset( $data['failed_rows'] ) ? intval( $data['failed_rows'] ) : 0,
				'question_ids' => isset( $data['question_ids'] ) ? maybe_serialize( array_map( 'intval', (array) $data['question_ids'] ) ) : '',
				'errors'       => isset( $data['errors'] ) ? maybe_serialize( $data['errors'] ) : '',
			);

			$format = array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' );

			$inserted = $wpdb->insert( $table, $insert, $format );
			if ( false === $inserted ) {
				return false;
			}
			return (int) $wpdb->insert_id;
		}

		/**
		 * Fetch logs with pagination.
		 *
		 * @param int $per_page Number per page.
		 * @param int $offset   Offset.
		 * @return array|false
		 */
		public static function get_logs( $per_page = 20, $offset = 0 ) {
			global $wpdb;
			$table = QSM_Bulk_DB::import_logs_table();
			$per_page = max( 1, intval( $per_page ) );
			$offset = max( 0, intval( $offset ) );
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY import_time DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		}

		/**
		 * Get single log row by ID.
		 *
		 * @param int $id Log ID.
		 * @return object|null
		 */
		public static function get_log( $id ) {
			global $wpdb;
			$table = QSM_Bulk_DB::import_logs_table();
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", intval( $id ) ) );
		}

		/**
		 * Update log status or fields.
		 *
		 * @param int   $id   Log ID.
		 * @param array $data Associative data to update.
		 * @return int|false Number of rows updated or false.
		 */
		public static function update_log( $id, $data ) {
			global $wpdb;
			$table = QSM_Bulk_DB::import_logs_table();
			if ( empty( $id ) || empty( $data ) || ! is_array( $data ) ) {
				return false;
			}
			return $wpdb->update( $table, $data, array( 'id' => intval( $id ) ) );
		}
	}
}
