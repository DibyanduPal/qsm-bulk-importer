<?php
/**
 * QSM Bulk Importer — DB helpers
 *
 * Central place for table name resolution and small DB utilities.
 *
 * @package QSM_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class QSM_Bulk_DB
 *
 * Light-weight helper to resolve table names and provide very small DB helpers.
 */
class QSM_Bulk_DB {

	/**
	 * Get import logs table name (with WP prefix).
	 *
	 * @return string
	 */
	public static function import_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'qsm_import_logs';
	}

	/**
	 * Get QSM questions table name (use active prefix + mlw_questions).
	 *
	 * @return string
	 */
	public static function questions_table() {
		global $wpdb;
		return $wpdb->prefix . 'mlw_questions';
	}

	/**
	 * Get QSM question terms table name.
	 *
	 * @return string
	 */
	public static function question_terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'mlw_question_terms';
	}

	/**
	 * Get QSM quizzes table name.
	 *
	 * @return string
	 */
	public static function quizzes_table() {
		global $wpdb;
		return $wpdb->prefix . 'mlw_quizzes';
	}

	/**
	 * Helper: runs a safe delete for a given table and where clause.
	 *
	 * @param string $table Table name (should already include prefix).
	 * @param array  $where Associative where clause.
	 * @return int|false Number of rows deleted or false on failure.
	 */
	public static function safe_delete( $table, $where ) {
		global $wpdb;
		if ( empty( $table ) || empty( $where ) || ! is_array( $where ) ) {
			return false;
		}
		$where_fmt = array_fill( 0, count( $where ), '%d' ); // default integer types
		// Try to determine types: if a value is string, change to %s
		$i = 0;
		foreach ( $where as $val ) {
			if ( is_string( $val ) ) {
				$where_fmt[ $i ] = '%s';
			}
			$i++;
		}
		$prepared = $wpdb->prepare( "DELETE FROM {$table} WHERE " . implode( ' AND ', array_map( function( $k ){ return "{$k} = %s"; }, array_keys( $where ) ) ), array_values( $where ) );
		// Use $wpdb->query directly for complex prepared queries to avoid mismatched formats
		return $wpdb->query( $prepared );
	}
}
