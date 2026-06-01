<?php
/**
 * Template: Import Form
 *
 * File input is visible and styled as a native WP button to guarantee the OS file picker opens.
 *
 * @package QSM_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'qsm_bulk_current_user_can_manage' ) || ! qsm_bulk_current_user_can_manage() ) {
	wp_die( esc_html__( 'Insufficient permissions to view this page.', 'qsm-bulk-importer' ) );
}

$quizzes = isset( $quizzes ) && is_array( $quizzes ) ? $quizzes : array();
$categories = isset( $categories ) && is_array( $categories ) ? $categories : array();
$question_types = isset( $question_types ) && is_array( $question_types ) ? $question_types : array(
	'0' => __( 'Multiple Choice (single answer)', 'qsm-bulk-importer' ),
	'1' => __( 'Multiple Choice (multiple answers)', 'qsm-bulk-importer' ),
);

$notice = isset( $notice ) ? (string) $notice : '';
$notice_class = isset( $notice_class ) ? (string) $notice_class : 'notice-success';
?>
<div class="wrap qsm-bulk-wrap">
	<h1><?php esc_html_e( 'Import Questions — QSM Bulk Importer', 'qsm-bulk-importer' ); ?></h1>

	<div id="qsm-bulk-import-card" class="qsm-card" style="max-width:980px; margin-bottom:20px; padding:18px; background:#fff; border:1px solid #e5e7eb;">
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="qsm-bulk-import-form" novalidate>
			<?php wp_nonce_field( 'qsm_bulk_import_action', 'qsm_bulk_import_nonce' ); ?>
			<input type="hidden" name="action" value="qsm_bulk_import" />

			<!-- Drag & Drop area -->
			<div id="qsm-dropzone" class="qsm-dropzone" style="border:2px dashed #d1d5db; padding:24px; text-align:center; border-radius:6px; margin-bottom:16px; position:relative;">
				<p style="margin:0 0 8px 0; font-weight:600;"><?php esc_html_e( 'Drag & drop a file here, or use the button to choose a file', 'qsm-bulk-importer' ); ?></p>
				<p class="description" style="margin:0 0 8px 0;"><?php esc_html_e( 'Accepted: .xlsx, .xls, .csv, .json — Headers required: question, option1, option2, option3, option4, option5, correct_answer and explanation. (option5 and explanation are optional)', 'qsm-bulk-importer' ); ?></p>

				<!-- Visible file input styled as button: this guarantees native picker opens -->
				<label class="qsm-file-button" style="display:inline-block; cursor:pointer;">
					<input
						type="file"
						id="qsm_file"
						name="qsm_file"
						accept=".xlsx,.xls,.csv,.json"
						style="display:inline-block; margin:0; padding:8px 12px; font-size:13px; line-height:1; cursor:pointer; border:1px solid #0073aa; background:#0073aa; color:#fff; border-radius:3px;"
						aria-label="<?php esc_attr_e( 'Upload file', 'qsm-bulk-importer' ); ?>"
					/>
				</label>

				<p id="qsm-selected-file" style="margin-top:10px; color:#374151;"></p>
			</div>

			<!-- Import Options -->
			<table class="form-table" style="max-width:820px;">
				<tbody>
					<tr>
						<th scope="row"><label for="qsm_quiz"><?php esc_html_e( 'Target Quiz', 'qsm-bulk-importer' ); ?></label></th>
						<td>
							<select name="qsm_quiz" id="qsm_quiz" required style="min-width:280px; padding:6px 8px;">
								<option value="0"><?php esc_html_e( '-- Select Quiz --', 'qsm-bulk-importer' ); ?></option>
								<?php foreach ( $quizzes as $id => $name ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose the QSM quiz into which questions will be imported.', 'qsm-bulk-importer' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="qsm_category"><?php esc_html_e( 'Question Category', 'qsm-bulk-importer' ); ?></label></th>
						<td>
							<select name="qsm_category" id="qsm_category" style="min-width:280px; padding:6px 8px;">
								<option value=""><?php esc_html_e( '-- Select Category --', 'qsm-bulk-importer' ); ?></option>
								<?php foreach ( $categories as $tid => $tname ) : ?>
									<option value="<?php echo esc_attr( $tid ); ?>"><?php echo esc_html( $tname ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'If taxonomy is available (qsm_category), choose a term; otherwise choose a category name.', 'qsm-bulk-importer' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="qsm_type"><?php esc_html_e( 'Question Type', 'qsm-bulk-importer' ); ?></label></th>
						<td>
							<select name="qsm_type" id="qsm_type" style="min-width:280px; padding:6px 8px;">
								<?php foreach ( $question_types as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, '0' ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default is Multiple Choice (single answer).', 'qsm-bulk-importer' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Scoring', 'qsm-bulk-importer' ); ?></th>
						<td>
							<input type="text" name="qsm_points_correct" value="2.00" size="6" style="padding:5px;" aria-label="<?php esc_attr_e( 'Points for correct answer', 'qsm-bulk-importer' ); ?>" />
							<span class="description" style="display:inline-block; margin-left:8px;"><?php esc_html_e( 'Points for correct answer', 'qsm-bulk-importer' ); ?></span>
							<br/><br/>
							<input type="text" name="qsm_points_incorrect" value="-0.67" size="6" style="padding:5px;" aria-label="<?php esc_attr_e( 'Points for incorrect answer', 'qsm-bulk-importer' ); ?>" />
							<span class="description" style="display:inline-block; margin-left:8px;"><?php esc_html_e( 'Points for incorrect answer', 'qsm-bulk-importer' ); ?></span>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'qsm-bulk-importer' ); ?></th>
						<td>
							<label style="display:inline-block; margin-right:12px;">
								<input type="checkbox" name="qsm_dry_run" value="1" /> <?php esc_html_e( 'Validation only (dry run) — do not write to database', 'qsm-bulk-importer' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When checked, the importer validates the file and reports issues but does not insert any data.', 'qsm-bulk-importer' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( esc_html__( 'Import Questions', 'qsm-bulk-importer' ) ); ?>
		</form>
		<?php
// Secure download URL (adds a nonce)
$download_url = wp_nonce_url(
    admin_url( 'admin-post.php?action=qsm_bulk_download_sample' ),
    'qsm_bulk_download_nonce'
);
?>
<div style="margin:12px 0 18px;">
    <a href="<?php echo esc_url( $download_url ); ?>" class="button">
        <?php esc_html_e( 'Download sample Excel (import format)', 'qsm-bulk-importer' ); ?>
    </a>
    <p class="description" style="margin-top:8px;">
        <?php
        esc_html_e( 'Required columns: question (or question_text), at least two option, correct_answer. Optional: option5 and explanation. Use this template, fill questions/options/answer/explanation, then import using the form above.', 'qsm-bulk-importer' );
        ?>
    </p>
</div>

	</div>
</div>
