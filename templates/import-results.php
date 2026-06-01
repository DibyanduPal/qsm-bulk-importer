<?php
/**
 * View Import (Import Detail) template
 *
 * Replace your existing View Import template with this file.
 * It shows the Quiz name (with fallbacks) and adds a Back button
 * (which returns the user to the previous page).
 *
 * Expected to be loaded in WP Admin with a `log_id` GET parameter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'qsm_manage' ) ) {
	// adjust capability if your plugin uses different capability checks
	wp_die( __( 'Insufficient permissions.', 'qsm-bulk-importer' ) );
}

global $wpdb;

// Get log id from query string
$log_id = isset( $_GET['log_id'] ) ? intval( $_GET['log_id'] ) : 0;
if ( ! $log_id ) {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid import log ID.', 'qsm-bulk-importer' ) . '</p></div>';
	return;
}

// Determine import logs table name (use helper if available)
$logs_table = function_exists( 'qsm_bulk_table_import_logs' ) ? qsm_bulk_table_import_logs() : ( $wpdb->prefix . 'qsm_import_logs' );

$log_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$logs_table} WHERE id = %d LIMIT 1", $log_id ), ARRAY_A );

if ( ! $log_row ) {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Import log not found.', 'qsm-bulk-importer' ) . '</p></div>';
	return;
}

// Extract fields safely
$file_name    = isset( $log_row['file_name'] ) ? $log_row['file_name'] : '';
$quiz_id      = isset( $log_row['quiz_id'] ) ? intval( $log_row['quiz_id'] ) : 0;
$quiz_name    = isset( $log_row['quiz_name'] ) ? trim( (string) $log_row['quiz_name'] ) : '';
$import_time  = isset( $log_row['import_time'] ) ? $log_row['import_time'] : '';
$total_rows   = isset( $log_row['total_rows'] ) ? intval( $log_row['total_rows'] ) : 0;
$success_rows = isset( $log_row['success_rows'] ) ? intval( $log_row['success_rows'] ) : 0;
$failed_rows  = isset( $log_row['failed_rows'] ) ? intval( $log_row['failed_rows'] ) : 0;
$status       = isset( $log_row['status'] ) ? $log_row['status'] : '';
$errors_raw   = isset( $log_row['errors'] ) ? maybe_unserialize( $log_row['errors'] ) : array();
if ( ! is_array( $errors_raw ) ) {
	$errors_raw = array( (string) $errors_raw );
}

// Resolve quiz name if missing
if ( '' === $quiz_name && $quiz_id > 0 ) {
	// 1) try helper function / quiz table if available
	if ( function_exists( 'qsm_bulk_table_quizzes' ) ) {
		$quizzes_table = qsm_bulk_table_quizzes();
		$maybe_name = $wpdb->get_var( $wpdb->prepare( "SELECT quiz_name FROM {$quizzes_table} WHERE quiz_id = %d LIMIT 1", $quiz_id ) );
		if ( $maybe_name ) {
			$quiz_name = $maybe_name;
		}
	}

	// 2) try QSM's possible functions (if plugin exposes them)
	if ( '' === $quiz_name ) {
		if ( function_exists( 'qsm_get_quiz' ) ) {
			$q = qsm_get_quiz( $quiz_id );
			if ( is_array( $q ) && ! empty( $q['quiz_name'] ) ) {
				$quiz_name = $q['quiz_name'];
			}
		}
	}

	// 3) try retrieving from WP posts (in case quizzes are stored as CPT)
	if ( '' === $quiz_name ) {
		$post = get_post( $quiz_id );
		if ( $post && ! is_wp_error( $post ) ) {
			$quiz_name = $post->post_title;
		}
	}
}

// Final fallback text
if ( '' === $quiz_name ) {
	$quiz_name = esc_html__( '— (not set) —', 'qsm-bulk-importer' );
} else {
	// sanitize for display
	$quiz_name = esc_html( $quiz_name );
}

// Build counts string
$counts = sprintf( '%d imported / %d failed', $success_rows, $failed_rows );

?>

<div class="wrap">
	<h1><?php echo esc_html_x( 'QSM Bulk Import — View Import', 'page title', 'qsm-bulk-importer' ); ?></h1>

	<!-- Back button at top (mark 2) -->
	<p>
		<a class="button" href="javascript:history.back();"><?php echo esc_html__( 'Back', 'qsm-bulk-importer' ); ?></a>
	</p>

	<table class="widefat striped">
		<tbody>
			<tr>
				<th style="width:200px; text-align:left; padding:12px;"><?php echo esc_html__( 'Import ID', 'qsm-bulk-importer' ); ?></th>
				<td style="padding:12px;"><?php echo intval( $log_id ); ?></td>
			</tr>

			<tr>
				<th style="text-align:left; padding:12px;"><?php echo esc_html__( 'File Imported', 'qsm-bulk-importer' ); ?></th>
				<td style="padding:12px;"><?php echo esc_html( $file_name ); ?></td>
			</tr>

			<tr>
				<th style="text-align:left; padding:12px;"><?php echo esc_html__( 'Quiz', 'qsm-bulk-importer' ); ?></th>
				<td style="padding:12px;"><?php echo $quiz_name; ?></td>
			</tr>

			<tr>
				<th style="text-align:left; padding:12px;"><?php echo esc_html__( 'Imported At', 'qsm-bulk-importer' ); ?></th>
				<td style="padding:12px;"><?php echo esc_html( $import_time ); ?></td>
			</tr>

			<tr>
				<th style="text-align:left; padding:12px;"><?php echo esc_html__( 'Counts', 'qsm-bulk-importer' ); ?></th>
				<td style="padding:12px;"><?php echo esc_html( $counts ); ?></td>
			</tr>

			<tr>
				<th style="text-align:left; padding:12px;"><?php echo esc_html__( 'Status', 'qsm-bulk-importer' ); ?></th>
				<td style="padding:12px;"><?php echo esc_html( $status ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2 style="margin-top:28px;"><?php echo esc_html__( 'Errors', 'qsm-bulk-importer' ); ?></h2>

	<?php if ( empty( $errors_raw ) ) : ?>
		<p><?php echo esc_html__( 'No errors recorded.', 'qsm-bulk-importer' ); ?></p>
	<?php else : ?>
		<ul>
			<?php foreach ( $errors_raw as $err ) : ?>
				<li style="margin-bottom:6px;"><?php echo esc_html( (string) $err ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3 style="margin-top:28px;"><?php echo esc_html__( 'Rollback Import', 'qsm-bulk-importer' ); ?></h3>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'qsm_bulk_rollback_action', 'qsm_bulk_rollback_nonce' ); ?>
		<input type="hidden" name="action" value="qsm_bulk_rollback" />
		<input type="hidden" name="log_id" value="<?php echo intval( $log_id ); ?>" />
		<input class="button" type="submit" value="<?php echo esc_attr__( 'Rollback', 'qsm-bulk-importer' ); ?>" />
	</form>

	<!-- Back button at bottom (mark 2) -->
	<p style="margin-top:20px;">
		<a class="button" href="javascript:history.back();"><?php echo esc_html__( 'Back', 'qsm-bulk-importer' ); ?></a>
	</p>
</div>
