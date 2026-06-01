<?php
/**
 * Import Questions admin page controller
 *
 * This file prepares data and renders the import form. It will use the template
 * '/templates/import-form.php' if present. Otherwise it renders a safe fallback form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Capability check (defensive)
if ( ! qsm_bulk_current_user_can_manage() ) {
	wp_die( __( 'Insufficient permissions.', 'qsm-bulk-importer' ) );
}

// Prepare dropdown data: quizzes, categories, question types
$quizzes = array();
$quiz_table = qsm_bulk_table_quizzes();
$results = $wpdb->get_results( "SELECT quiz_id, quiz_name FROM {$quiz_table} ORDER BY quiz_id ASC" );
if ( $results ) {
	foreach ( $results as $row ) {
		$quizzes[ intval( $row->quiz_id ) ] = wp_kses_post( $row->quiz_name );
	}
}

// Categories: prefer WP taxonomy qsm_category, fallback to distinct category names in mlw_questions table
$categories = array();
if ( taxonomy_exists( 'qsm_category' ) ) {
	$terms = get_terms( array(
		'taxonomy'   => 'qsm_category',
		'hide_empty' => false,
	) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			$categories[ intval( $t->term_id ) ] = esc_html( $t->name );
		}
	}
}

if ( empty( $categories ) ) {
	$term_rows = $wpdb->get_results( "SELECT DISTINCT category FROM " . qsm_bulk_table_questions() . " WHERE category <> '' LIMIT 200" );
	if ( $term_rows ) {
		foreach ( $term_rows as $tr ) {
			$value = is_object( $tr ) ? $tr->category : $tr;
			if ( strlen( trim( (string) $value ) ) ) {
				$categories[ sanitize_text_field( $value ) ] = sanitize_text_field( $value );
			}
		}
	}
}

// Question types: QSM often uses numeric type keys. Provide common defaults and try to detect from existing table
$question_types = array(
	'0' => __( 'Multiple Choice (single answer)', 'qsm-bulk-importer' ),
	'1' => __( 'Multiple Choice (multiple answers)', 'qsm-bulk-importer' ),
	'2' => __( 'True/False', 'qsm-bulk-importer' ),
);

// try to detect custom types from existing questions table (question_type_new)
$types_from_db = $wpdb->get_results( "SELECT DISTINCT question_type, question_type_new FROM " . qsm_bulk_table_questions() . " LIMIT 20" );
if ( $types_from_db ) {
	foreach ( $types_from_db as $t ) {
		$key = (string) $t->question_type;
		if ( ! isset( $question_types[ $key ] ) && strlen( (string) $t->question_type_new ) ) {
			$question_types[ $key ] = sanitize_text_field( $t->question_type_new );
		}
	}
}

// Prepare any admin notices passed in query args (redirect after import)
$notice = '';
if ( isset( $_GET['qsm_import_notice'] ) ) {
	$notice = sanitize_text_field( wp_unslash( $_GET['qsm_import_notice'] ) );
}

$notice_class = isset( $_GET['qsm_import_status'] ) && 'error' === $_GET['qsm_import_status'] ? 'notice-error' : 'notice-success';

// If template file exists, render it and return
$template = QSM_BULK_DIR . 'templates/import-form.php';
if ( file_exists( $template ) ) {
	// Pass local variables to template
	include $template;
	return;
}

// Fallback: Render inline safe form (no templates present)
?>
<div class="wrap">
	<h1><?php esc_html_e( 'QSM Bulk Import — Import Questions', 'qsm-bulk-importer' ); ?></h1>

	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'qsm_bulk_import_action', 'qsm_bulk_import_nonce' ); ?>
		<input type="hidden" name="action" value="qsm_bulk_import">

		<table class="form-table">
			<tr>
				<th scope="row"><label for="qsm_file"><?php esc_html_e( 'File (xlsx, xls, csv, json)', 'qsm-bulk-importer' ); ?></label></th>
				<td>
					<input type="file" name="qsm_file" id="qsm_file" accept=".xlsx,.xls,.csv,.json" required />
					<p class="description"><?php esc_html_e( 'Upload your Excel/CSV/JSON file with headers: question, option1, option2, option3, option4, option5 (optional), correct_answer, explanation (optional).', 'qsm-bulk-importer' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="qsm_quiz"><?php esc_html_e( 'Target Quiz', 'qsm-bulk-importer' ); ?></label></th>
				<td>
					<select name="qsm_quiz" id="qsm_quiz" required>
						<option value="0"><?php esc_html_e( '-- Select Quiz --', 'qsm-bulk-importer' ); ?></option>
						<?php foreach ( $quizzes as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Select the QSM quiz to import into.', 'qsm-bulk-importer' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="qsm_category"><?php esc_html_e( 'Question Category', 'qsm-bulk-importer' ); ?></label></th>
				<td>
					<select name="qsm_category" id="qsm_category" required>
						<option value=""><?php esc_html_e( '-- Select Category --', 'qsm-bulk-importer' ); ?></option>
						<?php foreach ( $categories as $tid => $tname ) : ?>
							<option value="<?php echo esc_attr( $tid ); ?>"><?php echo esc_html( $tname ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Select a category to attach to imported questions. If taxonomy is not available, category names will be used.', 'qsm-bulk-importer' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="qsm_type"><?php esc_html_e( 'Question Type', 'qsm-bulk-importer' ); ?></label></th>
				<td>
					<select name="qsm_type" id="qsm_type">
						<?php foreach ( $question_types as $tid => $tlabel ) : ?>
							<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $tid, '0' ); ?>><?php echo esc_html( $tlabel ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Scoring', 'qsm-bulk-importer' ); ?></th>
				<td>
					<label><input type="text" name="qsm_points_correct" value="2.00" size="6" /> <?php esc_html_e( 'Points for correct answer', 'qsm-bulk-importer' ); ?></label><br/>
					<label><input type="text" name="qsm_points_incorrect" value="-0.67" size="6" /> <?php esc_html_e( 'Points for incorrect answer', 'qsm-bulk-importer' ); ?></label>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'qsm-bulk-importer' ); ?></th>
				<td>
					<label><input type="checkbox" name="qsm_dry_run" value="1" /> <?php esc_html_e( 'Validation only (dry run) — do not write to database', 'qsm-bulk-importer' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Import Questions', 'qsm-bulk-importer' ) ); ?>
	</form>

	<hr />

	<p><strong><?php esc_html_e( 'Notes', 'qsm-bulk-importer' ); ?></strong></p>
	<ul>
		<li><?php esc_html_e( 'Option5 and Explanation columns are optional. Correct answer can be a number (1-5) or the exact option text.', 'qsm-bulk-importer' ); ?></li>
		<li><?php esc_html_e( 'If you need preview functionality, add the templates/import-form.php to the plugin templates folder.', 'qsm-bulk-importer' ); ?></li>
	</ul>
</div>
<?php
