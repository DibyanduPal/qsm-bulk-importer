<?php
/**
 * Helper: install-tables.php
 *
 * Reads the SQL template located at sql/install-tables.sql, replaces the
 * placeholders with the active $wpdb->prefix and the site's charset/collation,
 * and executes it via dbDelta(). This is safe to call from your plugin's
 * activation routine or upgrade routines.
 *
 * Usage:
 *   require_once QSM_BULK_DIR . 'sql/install-tables.php';
 *   qsm_bulk_install_tables_from_sql();
 *
 * The function is idempotent and will not destroy existing data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read SQL template and run dbDelta after replacing placeholders.
 *
 * @return array Array with 'success' (bool) and 'message' (string) keys.
 */
function qsm_bulk_install_tables_from_sql() {
	global $wpdb;

	$sql_file = QSM_BULK_DIR . 'sql/install-tables.sql';

	if ( ! file_exists( $sql_file ) ) {
		return array(
			'success' => false,
			'message' => sprintf( 'SQL template not found: %s', esc_html( $sql_file ) ),
		);
	}

	$sql_template = file_get_contents( $sql_file );
	if ( false === $sql_template ) {
		return array(
			'success' => false,
			'message' => sprintf( 'Unable to read SQL template: %s', esc_html( $sql_file ) ),
		);
	}

	// Prepare replacements
	$prefix = $wpdb->prefix;
	$charset_collate = $wpdb->get_charset_collate();

	// Replace placeholders __PREFIX__ and __CHARSET__
	$sql_ready = str_replace(
		array( '__PREFIX__', '__CHARSET__' ),
		array( $prefix, $charset_collate ),
		$sql_template
	);

	// dbDelta requires statements to end with a semicolon and be separated.
	// Normalize line endings and statements just in case.
	$sql_statements = preg_split( '/;[\r\n]+/', $sql_ready );
	$sql_to_run = '';
	foreach ( $sql_statements as $stmt ) {
		$trimmed = trim( $stmt );
		if ( empty( $trimmed ) ) {
			continue;
		}
		// ensure each statement ends with a semicolon + newline for dbDelta compatibility
		$sql_to_run .= $trimmed . ';' . PHP_EOL;
	}

	if ( empty( $sql_to_run ) ) {
		return array(
			'success' => false,
			'message' => 'No SQL statements found after processing template.',
		);
	}

	// Run dbDelta
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// Suppress direct output from dbDelta; capture messages if needed
	ob_start();
	dbDelta( $sql_to_run );
	$dbdelta_output = ob_get_clean();

	// Optionally inspect $dbdelta_output for messages — but treat as success unless an exception occurred.
	return array(
		'success' => true,
		'message' => 'dbDelta executed. Output: ' . $dbdelta_output,
	);
}
