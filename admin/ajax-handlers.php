<?php
/**
 * admin/ajax-handlers.php
 *
 * Full import & rollback handler for QSM Bulk Importer
 * - category handling adopted from last working version (numeric term id links to question_terms)
 * - batch-level correct/incorrect marks applied to inserted answers
 * - improved rollback handler (friendly redirects + nonce/capability handling)
 *
 * @package QSM_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_qsm_bulk_import', 'qsm_bulk_handle_import' );
add_action( 'admin_post_qsm_bulk_rollback', 'qsm_bulk_handle_rollback' );

/* ----------------------
 * Helpers
 * --------------------- */

function qsm_bulk_ensure_tables_exist() {
	global $wpdb;

	$questions_table = function_exists( 'qsm_bulk_table_questions' ) ? qsm_bulk_table_questions() : $wpdb->prefix . 'mlw_questions';
	$logs_table      = function_exists( 'qsm_bulk_table_import_logs' ) ? qsm_bulk_table_import_logs() : $wpdb->prefix . 'qsm_import_logs';

	$need_create = false;
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $questions_table ) ) !== $questions_table ) $need_create = true;
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logs_table ) ) !== $logs_table ) $need_create = true;

	if ( $need_create ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql_q = "CREATE TABLE {$questions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) NOT NULL DEFAULT '0',
			question_name text NOT NULL,
			answer_array longtext,
			answer_one text,
			answer_one_points float DEFAULT 0,
			answer_two text,
			answer_two_points float DEFAULT 0,
			answer_three text,
			answer_three_points float DEFAULT 0,
			answer_four text,
			answer_four_points float DEFAULT 0,
			answer_five text,
			answer_five_points float DEFAULT 0,
			answer_six text,
			answer_six_points float DEFAULT 0,
			correct_answer int(11) DEFAULT 0,
			question_answer_info text,
			comments tinyint(1) DEFAULT 0,
			hints text,
			question_order int(11) DEFAULT 0,
			question_type int(11) DEFAULT 0,
			question_type_new varchar(64) DEFAULT '',
			question_settings longtext,
			category varchar(191) DEFAULT '',
			category_terms text,
			linked_question bigint(20) DEFAULT 0,
			deleted tinyint(1) DEFAULT 0,
			deleted_question_bank tinyint(1) DEFAULT 0,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$sql_l = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_name varchar(255) NOT NULL,
			uploader_id bigint(20) NOT NULL DEFAULT 0,
			quiz_id bigint(20) NOT NULL DEFAULT 0,
			quiz_name varchar(255) DEFAULT '',
			import_time datetime DEFAULT '0000-00-00 00:00:00',
			status varchar(64) DEFAULT '',
			total_rows int(11) DEFAULT 0,
			success_rows int(11) DEFAULT 0,
			failed_rows int(11) DEFAULT 0,
			question_ids longtext,
			errors longtext,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		dbDelta( $sql_q );
		dbDelta( $sql_l );
	}
}

/**
 * Parse numeric or letter index from 'correct answer' free text.
 * returns 1..6 or 0
 */
function qsm_bulk_parse_numeric_or_letter_index( $raw ) {
	$raw = trim( (string) $raw );
	if ( $raw === '' ) return 0;

	if ( preg_match( '/\d+/', $raw, $m ) ) {
		$n = intval( $m[0] );
		if ( $n >= 1 && $n <= 6 ) return $n;
	}

	if ( preg_match( '/[a-fA-F]/', $raw, $m ) ) {
		$map = array( 'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6 );
		$letter = strtoupper( $m[0] );
		if ( isset( $map[ $letter ] ) ) return $map[ $letter ];
	}

	return 0;
}

/* ----------------------
 * Main import handler
 * --------------------- */

function qsm_bulk_handle_import() {
	// Permissions & nonce
	if ( ! function_exists( 'qsm_bulk_current_user_can_manage' ) || ! qsm_bulk_current_user_can_manage() ) {
		wp_die( __( 'Insufficient permissions.', 'qsm-bulk-importer' ) );
	}
	if ( empty( $_POST['qsm_bulk_import_nonce'] ) || ! check_admin_referer( 'qsm_bulk_import_action', 'qsm_bulk_import_nonce' ) ) {
		wp_die( __( 'Security check failed (nonce).', 'qsm-bulk-importer' ) );
	}

	global $wpdb;

	// Ensure tables exist
	qsm_bulk_ensure_tables_exist();

	$questions_table = function_exists( 'qsm_bulk_table_questions' ) ? qsm_bulk_table_questions() : $wpdb->prefix . 'mlw_questions';
	$logs_table      = function_exists( 'qsm_bulk_table_import_logs' ) ? qsm_bulk_table_import_logs() : $wpdb->prefix . 'qsm_import_logs';

	// Resolve quiz ID
	$quiz_id = 0;
	$quiz_keys = array( 'qsm_target_quiz', 'target_quiz', 'quiz_id', 'quiz', 'qsm_quiz', 'qsm_target_quiz_select' );
	foreach ( $quiz_keys as $k ) {
		if ( isset( $_POST[ $k ] ) && '' !== trim( (string) $_POST[ $k ] ) ) {
			$val = trim( (string) wp_unslash( $_POST[ $k ] ) );
			if ( is_numeric( $val ) ) {
				$quiz_id = intval( $val );
				break;
			}
			// try plugin table or post title fallback
			if ( function_exists( 'qsm_bulk_table_quizzes' ) ) {
				$qtab = qsm_bulk_table_quizzes();
				$maybe = $wpdb->get_var( $wpdb->prepare( "SELECT quiz_id FROM {$qtab} WHERE quiz_name = %s LIMIT 1", $val ) );
				if ( $maybe ) { $quiz_id = intval( $maybe ); break; }
			}
			$post = get_page_by_title( $val, OBJECT, 'quiz' );
			if ( $post && ! is_wp_error( $post ) ) { $quiz_id = intval( $post->ID ); break; }
		}
	}
	$quiz_id = intval( $quiz_id );

	// Category — MUST take from import form (text OR numeric term id)
	$category_input = '';
	$cat_keys = array( 'qsm_question_category', 'target_category', 'category', 'target_cat', 'qsm_category', 'qsm_category_select', 'qsm_category_field' );
	foreach ( $cat_keys as $ck ) {
		if ( isset( $_POST[ $ck ] ) && '' !== trim( (string) $_POST[ $ck ] ) ) {
			$category_input = sanitize_text_field( wp_unslash( $_POST[ $ck ] ) );
			break;
		}
	}

	// Question type (optional)
	$qsm_type = 0;
	$qsm_type_keys = array( 'qsm_question_type', 'question_type', 'qsm_type' );
	foreach ( $qsm_type_keys as $tk ) {
		if ( isset( $_POST[ $tk ] ) && is_numeric( $_POST[ $tk ] ) ) {
			$qsm_type = intval( $_POST[ $tk ] );
			break;
		}
	}

	// Correct & Incorrect marks — MUST come from form
	$correct_mark = null;
	$incorrect_mark = null;
	$correct_mark_keys = array( 'qsm_correct_mark', 'correct_mark', 'marks_correct', 'correct_points', 'qsm_points_correct' );
	$incorrect_mark_keys = array( 'qsm_incorrect_mark', 'incorrect_mark', 'marks_incorrect', 'incorrect_points', 'qsm_points_incorrect' );

	foreach ( $correct_mark_keys as $k ) {
		if ( isset( $_POST[ $k ] ) && '' !== trim( (string) $_POST[ $k ] ) ) {
			$val = wp_unslash( $_POST[ $k ] );
			$val = sanitize_text_field( $val );
			if ( is_numeric( $val ) ) { $correct_mark = floatval( $val ); break; }
		}
	}
	foreach ( $incorrect_mark_keys as $k ) {
		if ( isset( $_POST[ $k ] ) && '' !== trim( (string) $_POST[ $k ] ) ) {
			$val = wp_unslash( $_POST[ $k ] );
			$val = sanitize_text_field( $val );
			if ( is_numeric( $val ) ) { $incorrect_mark = floatval( $val ); break; }
		}
	}
	// Fallbacks if form keys missing
	if ( $correct_mark === null ) $correct_mark = 2.0;
	if ( $incorrect_mark === null ) $incorrect_mark = -0.67;

	// File upload check
	if ( empty( $_FILES['qsm_file'] ) || ! is_uploaded_file( $_FILES['qsm_file']['tmp_name'] ) ) {
		wp_safe_redirect( add_query_arg( array( 'qsm_import_status' => 'error', 'qsm_import_notice' => rawurlencode( __( 'No file uploaded.', 'qsm-bulk-importer' ) ) ), admin_url( 'admin.php?page=qsm-bulk-import/import' ) ) );
		exit;
	}

	$file_path = $_FILES['qsm_file']['tmp_name'];
	$file_name = isset( $_FILES['qsm_file']['name'] ) ? wp_strip_all_tags( $_FILES['qsm_file']['name'] ) : '';
	$ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

	$rows = array();
	$errors = array();
	$header = array();

	// Parse file (xlsx/xls via PhpSpreadsheet, csv, json)
	switch ( $ext ) {
		case 'xlsx':
		case 'xls':
			try {
				if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
					throw new Exception( __( 'PhpSpreadsheet library not available.', 'qsm-bulk-importer' ) );
				}
				$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $file_path );
				$reader->setReadDataOnly( true );
				$spreadsheet = $reader->load( $file_path );
				$sheet = $spreadsheet->getActiveSheet();
				$highestRow = $sheet->getHighestRow();
				$highestCol  = $sheet->getHighestColumn();
				$highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highestCol );

				// header row
				for ( $c = 1; $c <= $highestColIndex; $c++ ) {
					$cell = $sheet->getCellByColumnAndRow( $c, 1 );
					$header[] = strtolower( trim( (string) $cell->getValue() ) );
				}
				// data rows
				for ( $r = 2; $r <= $highestRow; $r++ ) {
					$assoc = array();
					for ( $c = 1; $c <= $highestColIndex; $c++ ) {
						$key = isset( $header[ $c - 1 ] ) ? $header[ $c - 1 ] : ( $c - 1 );
						$cell = $sheet->getCellByColumnAndRow( $c, $r );
						$assoc[ $key ] = (string) $cell->getValue();
					}
					$rows[] = $assoc;
				}
			} catch ( Exception $e ) {
				$errors[] = 'PhpSpreadsheet error: ' . $e->getMessage();
			}
			break;

		case 'csv':
			if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
				$header = fgetcsv( $handle );
				if ( $header ) {
					$header = array_map( 'strtolower', array_map( 'trim', $header ) );
					while ( ( $data = fgetcsv( $handle ) ) !== false ) {
						$assoc = array();
						foreach ( $header as $i => $h ) {
							$assoc[ $h ] = isset( $data[ $i ] ) ? $data[ $i ] : '';
						}
						$rows[] = $assoc;
					}
				} else {
					rewind( $handle );
					while ( ( $data = fgetcsv( $handle ) ) !== false ) {
						$rows[] = $data;
					}
				}
				fclose( $handle );
			} else {
				$errors[] = __( 'Could not open CSV file.', 'qsm-bulk-importer' );
			}
			break;

		case 'json':
			$json = json_decode( file_get_contents( $file_path ), true );
			if ( is_array( $json ) ) {
				foreach ( $json as $item ) {
					if ( is_array( $item ) ) {
						$assoc = array();
						foreach ( $item as $k => $v ) {
							$assoc[ strtolower( trim( $k ) ) ] = $v;
						}
						$rows[] = $assoc;
					}
				}
			} else {
				$errors[] = __( 'JSON decode failed or invalid JSON structure.', 'qsm-bulk-importer' );
			}
			break;

		default:
			$errors[] = __( 'Unsupported file type. Upload CSV/XLS/XLSX/JSON.', 'qsm-bulk-importer' );
			break;
	}

	if ( ! empty( $errors ) ) {
		$redirect = add_query_arg(
			array(
				'qsm_import_status' => 'error',
				'qsm_import_notice' => rawurlencode( implode( '; ', $errors ) ),
			),
			admin_url( 'admin.php?page=qsm-bulk-import/import' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	// Normalize and filter out fully-empty rows
	$effective_rows = array();
	$row_offset = ! empty( $header ) ? 2 : 1;
	foreach ( $rows as $ri => $raw_row ) {
		if ( is_array( $raw_row ) ) {
			$row_assoc = array_change_key_case( $raw_row, CASE_LOWER );
		} elseif ( is_object( $raw_row ) ) {
			$row_assoc = array_change_key_case( (array) $raw_row, CASE_LOWER );
		} else {
			$row_assoc = array( 'value' => (string) $raw_row );
		}
		$has_content = false;
		foreach ( $row_assoc as $v ) {
			$norm = function_exists( 'qsm_bulk_normalize_text' ) ? qsm_bulk_normalize_text( $v ) : trim( (string) $v );
			if ( strlen( $norm ) > 0 ) { $has_content = true; break; }
		}
		if ( $has_content ) {
			$effective_rows[] = array( 'data' => $row_assoc, 'orig_row' => intval( $ri ) + $row_offset );
		}
	}

	$total_rows    = count( $effective_rows );
	$success_count = 0;
	$failed_count  = 0;
	$inserted_ids  = array();

	// Cache columns for questions table
	$available_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$questions_table}", 0 );
	$available_map = array_flip( (array) $available_columns );

	// Process rows
	foreach ( $effective_rows as $item_index => $item ) {
		$row = $item['data'];
		$row_num = isset( $item['orig_row'] ) ? intval( $item['orig_row'] ) : ( $item_index + $row_offset );

		// require question text
		$question_text = isset( $row['question'] ) ? trim( (string) $row['question'] ) : '';
		if ( $question_text === '' ) {
			$failed_count++;
			$errors[] = sprintf( __( 'Row %d: missing question text. Skipped.', 'qsm-bulk-importer' ), $row_num );
			continue;
		}

		// explanation
		$explanation = isset( $row['explanation'] ) ? trim( (string) $row['explanation'] ) : '';

		// Collect options in header order: option1/answer1/opt1 etc
		$opts = array();
		$row_keys = array_keys( $row );
		foreach ( $row_keys as $rk ) {
			if ( preg_match( '/^(option|answer|opt|ans)[\s_]?(\d+)$/i', $rk, $m ) ) {
				$v = trim( (string) $row[ $rk ] );
				if ( $v !== '' ) $opts[ intval( $m[2] ) ] = sanitize_text_field( $v );
			}
		}
		if ( ! empty( $opts ) ) {
			ksort( $opts, SORT_NUMERIC );
			$ordered = array();
			foreach ( $opts as $v ) $ordered[] = $v;
			$opts = $ordered;
		} else {
			// fallback: pick keys containing option/answer in appearance order
			$found = array();
			foreach ( $row_keys as $rk ) {
				if ( preg_match( '/^(option|answer|opt|ans)/i', $rk ) ) {
					$v = trim( (string) $row[ $rk ] );
					if ( $v !== '' ) $found[] = sanitize_text_field( $v );
				}
			}
			$opts = $found;
		}

		// final fallback: explicit option1..option6 keys
		if ( empty( $opts ) ) {
			for ( $i = 1; $i <= 6; $i++ ) {
				$k = 'option' . $i;
				if ( isset( $row[ $k ] ) && trim( (string) $row[ $k ] ) !== '' ) {
					$opts[] = sanitize_text_field( $row[ $k ] );
				}
			}
		}

		if ( empty( $opts ) ) {
			$failed_count++;
			$errors[] = sprintf( __( 'Row %d: no options found. Skipped.', 'qsm-bulk-importer' ), $row_num );
			continue;
		}

		// Correct answer detection — prefer matching by value (case-insensitively)
		$correct_raw = '';
		$correct_keys = array( 'correct_answer', 'correct', 'answer_correct', 'correctoption', 'correct_option', 'correct answer' );
		foreach ( $correct_keys as $ck ) {
			if ( isset( $row[ $ck ] ) && '' !== trim( (string) $row[ $ck ] ) ) {
				$correct_raw = (string) $row[ $ck ];
				break;
			}
		}
		// generic fallback: any key containing 'correct'
		if ( $correct_raw === '' ) {
			foreach ( $row as $k => $v ) {
				if ( stripos( $k, 'correct' ) !== false && '' !== trim( (string) $v ) ) {
					$correct_raw = (string) $v;
					break;
				}
			}
		}

		$correct_index_stored = 0; // 1-based index
		if ( $correct_raw !== '' ) {
			// exact (case-insensitive) match
			$found_match = false;
			foreach ( $opts as $i => $opt_text ) {
				if ( mb_strtolower( trim( $opt_text ) ) === mb_strtolower( trim( $correct_raw ) ) ) {
					$correct_index_stored = $i + 1;
					$found_match = true;
					break;
				}
			}
			// if not matched, try parse numeric/letter
			if ( ! $found_match ) {
				$parsed = qsm_bulk_parse_numeric_or_letter_index( $correct_raw );
				if ( $parsed > 0 && $parsed <= count( $opts ) ) {
					$correct_index_stored = $parsed;
					$found_match = true;
				}
			}
			// last resort: substring match
			if ( ! $found_match ) {
				foreach ( $opts as $i => $opt_text ) {
					if ( stripos( trim( $opt_text ), trim( $correct_raw ) ) !== false ) {
						$correct_index_stored = $i + 1;
						$found_match = true;
						break;
					}
				}
			}
			if ( ! $found_match ) {
				$errors[] = sprintf( __( 'Row %d: correct_answer value "%s" did not match any option; no correct answer assigned for this row.', 'qsm-bulk-importer' ), $row_num, sanitize_text_field( $correct_raw ) );
			}
		}

		// Build answers array: [text, points, is_correct] — use batch-level marks from form
		$answers = array();
		for ( $i = 0; $i < count( $opts ); $i++ ) {
			$idx = $i + 1;
			$is_correct = ( $correct_index_stored === $idx ) ? 1 : 0;
			$points = $is_correct ? floatval( $correct_mark ) : floatval( $incorrect_mark );
			$answers[] = array( $opts[ $i ], $points, intval( $is_correct ) );
		}

		// Build insert data — IMPORTANT: if category_input is numeric, keep 'category' empty here (we will link via question_terms)
		$insert_data = array(
			'quiz_id'               => intval( $quiz_id ),
			'question_name'         => '', // title preserved in question_settings for compatibility
			'answer_array'          => maybe_serialize( $answers ),
			'correct_answer'        => intval( $correct_index_stored ),
			'question_answer_info'  => wp_kses_post( $explanation ),
			'comments'              => isset( $available_map['comments'] ) ? 1 : 0,
			'hints'                 => isset( $available_map['hints'] ) ? '' : '',
			'question_order'        => isset( $available_map['question_order'] ) ? 0 : 0,
			'question_type'         => intval( $qsm_type ),
			'question_type_new'     => (string) intval( $qsm_type ),
			'question_settings'     => maybe_serialize( array( 'question_title' => $question_text, 'isPublished' => '1', 'required' => 1 ) ),
			'category'              => ( is_numeric( $category_input ) ? '' : sanitize_text_field( (string) $category_input ) ),
			'linked_question'       => isset( $available_map['linked_question'] ) ? '' : '',
			'deleted'               => isset( $available_map['deleted'] ) ? 0 : 0,
			'deleted_question_bank' => isset( $available_map['deleted_question_bank'] ) ? 0 : 0,
		);

		// Add option columns + points for present options
		$answer_col_names = array( 1 => 'answer_one', 2 => 'answer_two', 3 => 'answer_three', 4 => 'answer_four', 5 => 'answer_five', 6 => 'answer_six' );
		$answer_points_names = array( 1 => 'answer_one_points', 2 => 'answer_two_points', 3 => 'answer_three_points', 4 => 'answer_four_points', 5 => 'answer_five_points', 6 => 'answer_six_points' );
		for ( $i = 0; $i < count( $opts ); $i++ ) {
			$idx = $i + 1;
			$col = isset( $answer_col_names[ $idx ] ) ? $answer_col_names[ $idx ] : null;
			$colp = isset( $answer_points_names[ $idx ] ) ? $answer_points_names[ $idx ] : null;
			if ( $col ) $insert_data[ $col ] = $opts[ $i ];
			if ( $colp ) $insert_data[ $colp ] = floatval( $answers[ $i ][1] );
		}

		// Ensure quiz is written to favored column
		$quiz_candidates = array( 'quiz_id', 'quizid', 'quiz', 'qsm_quiz_id', 'quizID' );
		foreach ( $quiz_candidates as $qc ) {
			if ( isset( $available_map[ $qc ] ) ) {
				$insert_data[ $qc ] = intval( $quiz_id );
				if ( ! isset( $insert_data['quiz_id'] ) ) $insert_data['quiz_id'] = intval( $quiz_id );
				break;
			}
		}

		// Also write category to alternative column names (text preserved) — if numeric term ID provided we leave those blank (old behavior)
		$category_candidates = array( 'category', 'cat', 'category_name', 'qsm_category', 'category_terms' );
		foreach ( $category_candidates as $cc ) {
			if ( isset( $available_map[ $cc ] ) ) {
				$insert_data[ $cc ] = ( is_numeric( $category_input ) ? '' : sanitize_text_field( (string) $category_input ) );
				break;
			}
		}

		// Filter to only existing DB columns
		$valid_insert = array();
		foreach ( $insert_data as $col => $val ) {
			if ( isset( $available_map[ $col ] ) ) $valid_insert[ $col ] = $val;
		}
		if ( empty( $valid_insert ) ) {
			$failed_count++;
			$errors[] = sprintf( __( 'Row %d: no writable columns found in the target table. Skipped.', 'qsm-bulk-importer' ), $row_num );
			continue;
		}

		// Insert
		$inserted = $wpdb->insert( $questions_table, $valid_insert );
		if ( false === $inserted ) {
			$failed_count++;
			$errors[] = sprintf( __( 'Row %d: DB insert failed: %s', 'qsm-bulk-importer' ), $row_num, isset( $wpdb->last_error ) ? $wpdb->last_error : '' );
			continue;
		}

		$new_qid = $wpdb->insert_id;
		if ( $new_qid ) {
			$success_count++;
			$inserted_ids[] = intval( $new_qid );

			// CATEGORY LINKING: adopt old working behavior
			if ( taxonomy_exists( 'qsm_category' ) && is_numeric( $category_input ) && intval( $category_input ) > 0 ) {
				$term_id = intval( $category_input );
				if ( function_exists( 'qsm_bulk_table_question_terms' ) ) {
					$term_link_data = array(
						'question_id' => $new_qid,
						'quiz_id'     => $quiz_id,
						'term_id'     => $term_id,
						'taxonomy'    => 'qsm_category',
					);
					$wpdb->insert( qsm_bulk_table_question_terms(), $term_link_data );
				} else {
					// fallback if helper missing
					$table_terms = $wpdb->prefix . 'question_terms';
					$wpdb->insert( $table_terms, array( 'question_id' => $new_qid, 'term_id' => $term_id ), array( '%d', '%d' ) );
				}
			} elseif ( ! taxonomy_exists( 'qsm_category' ) && '' !== trim( (string) $category_input ) ) {
				// update questions table's category text using the appropriate PK column name
				$pk_col = isset( $available_map['question_id'] ) ? 'question_id' : ( isset( $available_map['id'] ) ? 'id' : null );
				if ( $pk_col ) {
					$wpdb->update(
						$questions_table,
						array( 'category' => sanitize_text_field( (string) $category_input ) ),
						array( $pk_col => $new_qid ),
						array( '%s' ),
						array( '%d' )
					);
				}
			}
		} else {
			$failed_count++;
			$errors[] = sprintf( __( 'Row %d: insert did not return an insert ID. Skipped.', 'qsm-bulk-importer' ), $row_num );
		}
	}

	// Resolve quiz name for log
	$resolved_quiz_name = '';
	if ( $quiz_id > 0 ) {
		if ( function_exists( 'qsm_bulk_table_quizzes' ) ) {
			$quizzes_table = qsm_bulk_table_quizzes();
			$maybe_name = $wpdb->get_var( $wpdb->prepare( "SELECT quiz_name FROM {$quizzes_table} WHERE quiz_id = %d LIMIT 1", $quiz_id ) );
			if ( $maybe_name ) $resolved_quiz_name = $maybe_name;
		}
		if ( '' === $resolved_quiz_name ) {
			$post = get_post( $quiz_id );
			if ( $post && ! is_wp_error( $post ) ) $resolved_quiz_name = $post->post_title;
		}
	}

	// Log import
	$log = array(
		'file_name'    => $file_name,
		'uploader_id'  => get_current_user_id(),
		'quiz_id'      => $quiz_id,
		'quiz_name'    => ( '' !== $resolved_quiz_name ? $resolved_quiz_name : '' ),
		'import_time'  => current_time( 'mysql' ),
		'status'       => ( $failed_count > 0 ? 'Partial' : 'Success' ),
		'total_rows'   => $total_rows,
		'success_rows' => $success_count,
		'failed_rows'  => $failed_count,
		'question_ids' => maybe_serialize( $inserted_ids ),
		'errors'       => maybe_serialize( $errors ),
	);

	$insert_log_result = $wpdb->insert( $logs_table, $log );
	$log_id = $wpdb->insert_id;
	if ( false === $insert_log_result || empty( $log_id ) ) {
		$errors[] = sprintf( __( 'Import log insert failed. DB error: %s', 'qsm-bulk-importer' ), isset( $wpdb->last_error ) ? $wpdb->last_error : '' );
	}

	// Redirect with summary
	$notice_text = sprintf( __( 'Import complete. %1$d inserted, %2$d failed (log: %3$s).', 'qsm-bulk-importer' ), $success_count, $failed_count, ( $log_id ? intval( $log_id ) : '0' ) );
	$redirect = add_query_arg(
		array(
			'qsm_import_status' => $failed_count ? 'warning' : 'success',
			'qsm_import_notice' => rawurlencode( $notice_text ),
		),
		admin_url( 'admin.php?page=qsm-bulk-import/import' )
	);

	@unlink( $file_path );
	wp_safe_redirect( $redirect );
	exit;
}

/* ----------------------
 * Robust rollback handler (redirects to Recent Imports when safe)
 * --------------------- */

function qsm_bulk_handle_rollback() {
	global $wpdb;

	$logs_table = function_exists( 'qsm_bulk_table_import_logs' ) ? qsm_bulk_table_import_logs() : $wpdb->prefix . 'qsm_import_logs';
	$questions_table = function_exists( 'qsm_bulk_table_questions' ) ? qsm_bulk_table_questions() : $wpdb->prefix . 'mlw_questions';
	$terms_table = function_exists( 'qsm_bulk_table_question_terms' ) ? qsm_bulk_table_question_terms() : $wpdb->prefix . 'question_terms';

	// Ensure user is logged in
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	// Resolve permission: plugin manager OR admin OR editor (edit_posts)
	$allowed = false;
	if ( function_exists( 'qsm_bulk_current_user_can_manage' ) && qsm_bulk_current_user_can_manage() ) {
		$allowed = true;
	}
	if ( current_user_can( 'manage_options' ) ) {
		$allowed = true;
	}
	if ( current_user_can( 'edit_posts' ) ) {
		$allowed = true;
	}

	if ( ! $allowed ) {
		// Redirect with friendly error rather than WP's generic 'Sorry...' page
		$redirect = add_query_arg( array(
			'qsm_import_status' => 'error',
			'qsm_import_notice' => rawurlencode( __( 'Insufficient permissions to rollback.', 'qsm-bulk-importer' ) ),
		), admin_url() ); // redirect to dashboard (safe)
		wp_safe_redirect( $redirect );
		exit;
	}

	// Nonce handling:
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
	if ( 'POST' === $method ) {
		if ( empty( $_POST['qsm_bulk_rollback_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['qsm_bulk_rollback_nonce'] ), 'qsm_bulk_rollback_action' ) ) {
			$redirect = add_query_arg( array(
				'qsm_import_status' => 'error',
				'qsm_import_notice' => rawurlencode( __( 'Security check failed (nonce).', 'qsm-bulk-importer' ) ),
			), admin_url() );
			wp_safe_redirect( $redirect );
			exit;
		}
	} else {
		// GET — if _wpnonce present, verify it; otherwise rely on capability check above
		if ( isset( $_GET['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'qsm_bulk_rollback_action' ) ) {
				$redirect = add_query_arg( array(
					'qsm_import_status' => 'error',
					'qsm_import_notice' => rawurlencode( __( 'Security check failed (nonce).', 'qsm-bulk-importer' ) ),
				), admin_url() );
				wp_safe_redirect( $redirect );
				exit;
			}
		}
	}

	// Get log id from POST or GET
	$log_id = 0;
	if ( isset( $_POST['log_id'] ) ) {
		$log_id = intval( $_POST['log_id'] );
	} elseif ( isset( $_GET['log_id'] ) ) {
		$log_id = intval( $_GET['log_id'] );
	}

	if ( ! $log_id ) {
		$redirect = add_query_arg( array(
			'qsm_import_status' => 'error',
			'qsm_import_notice' => rawurlencode( __( 'Invalid log id for rollback.', 'qsm-bulk-importer' ) ),
		), admin_url() );
		wp_safe_redirect( $redirect );
		exit;
	}

	$log_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$logs_table} WHERE id = %d LIMIT 1", $log_id ), ARRAY_A );
	if ( ! $log_row ) {
		$redirect = add_query_arg( array(
			'qsm_import_status' => 'error',
			'qsm_import_notice' => rawurlencode( __( 'Log entry not found.', 'qsm-bulk-importer' ) ),
		), admin_url() );
		wp_safe_redirect( $redirect );
		exit;
	}

	$question_ids = maybe_unserialize( $log_row['question_ids'] );
	if ( empty( $question_ids ) || ! is_array( $question_ids ) ) {
		$redirect = add_query_arg( array(
			'qsm_import_status' => 'error',
			'qsm_import_notice' => rawurlencode( __( 'No question IDs found for rollback.', 'qsm-bulk-importer' ) ),
		), admin_url() );
		wp_safe_redirect( $redirect );
		exit;
	}

	// Detect PK column for questions table (question_id vs id)
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$questions_table}", 0 );
	$pk_col = in_array( 'question_id', $cols, true ) ? 'question_id' : ( in_array( 'id', $cols, true ) ? 'id' : $cols[0] );

	$deleted_count = 0;
	foreach ( $question_ids as $qid ) {
		$qid = intval( $qid );
		if ( $qid <= 0 ) continue;

		// delete question row with discovered PK
		$wpdb->delete( $questions_table, array( $pk_col => $qid ), array( '%d' ) );

		// delete question terms if table exists
		if ( function_exists( 'qsm_bulk_table_question_terms' ) ) {
			$qt = qsm_bulk_table_question_terms();
			$wpdb->delete( $qt, array( 'question_id' => $qid ), array( '%d' ) );
		} else {
			// fallback
			$wpdb->delete( $terms_table, array( 'question_id' => $qid ), array( '%d' ) );
		}

		$deleted_count++;
	}

	// update log status
	$wpdb->update( $logs_table, array( 'status' => 'RolledBack' ), array( 'id' => $log_id ), array( '%s' ), array( '%d' ) );

	// Prefer redirect target: Recent Imports page (if current user can access it), else dashboard
	$recent_page_url = admin_url( 'admin.php?page=qsm-bulk-import%2Frecent' ); // keep encoded form acceptable in URLs
	$dashboard_url   = admin_url();

	// Determine whether to redirect to recent imports: check plausible capabilities
	$can_view_recent = false;
	if ( function_exists( 'qsm_bulk_current_user_can_manage' ) && qsm_bulk_current_user_can_manage() ) {
		$can_view_recent = true;
	} elseif ( current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) ) {
		$can_view_recent = true;
	}

	$target = $can_view_recent ? $recent_page_url : $dashboard_url;

	$redirect = add_query_arg( array(
		'qsm_import_status' => 'success',
		'qsm_import_notice' => rawurlencode( sprintf( __( 'Rollback complete. %d questions removed.', 'qsm-bulk-importer' ), $deleted_count ) ),
	), $target );

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Download sample spreadsheet file.
 * Hook: admin_post_qsm_bulk_download_sample
 *
 * This handler expects the nonce action string 'qsm_bulk_download_nonce'
 * because your template uses:
 *   wp_nonce_url( admin_url('admin-post.php?action=qsm_bulk_download_sample'), 'qsm_bulk_download_nonce' );
 */
function qsm_bulk_download_sample() {
    // Capability: allow only trusted admin users (adjust capability if required)
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions', 'qsm-bulk-importer' ), '', 403 );
    }

    // Verify nonce (must match second arg passed to wp_nonce_url in the template)
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qsm_bulk_download_nonce' ) ) {
        wp_die( esc_html__( 'Invalid request (bad nonce).', 'qsm-bulk-importer' ), '', 400 );
    }

    // Build path to the sample file.
    // Because this file lives in admin/, plugin_dir_path( __DIR__ ) will point to the plugin root.
    $file = plugin_dir_path( __DIR__ ) . 'assets' . DIRECTORY_SEPARATOR . 'sample-import.xlsx';

    if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
        wp_die( esc_html__( 'Sample file not found.', 'qsm-bulk-importer' ), '', 404 );
    }

    // Clear output buffers to avoid corrupting the binary stream
    while ( ob_get_level() ) {
        ob_end_clean();
    }

    // Send headers for .xlsx download
    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
    header( 'Content-Disposition: attachment; filename="qsm-sample.xlsx"' );
    header( 'Content-Transfer-Encoding: binary' );
    header( 'Expires: 0' );
    header( 'Cache-Control: must-revalidate' );
    header( 'Pragma: public' );
    header( 'Content-Length: ' . filesize( $file ) );

    // Output the file and stop execution
    readfile( $file );
    exit;
}
add_action( 'admin_post_qsm_bulk_download_sample', 'qsm_bulk_download_sample' );
