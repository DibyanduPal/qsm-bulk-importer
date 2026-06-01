<?php
/**
 * includes/class-import-processor.php
 *
 * Processes parsed rows and inserts questions. Returns a detailed summary
 * including counts and errors.
 *
 * Updated: much stronger blank-row detection (removes invisible chars, NBSP, ZWSP, control chars, HTML entities).
 *
 * @package qsm-bulk-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QSM_Bulk_Import_Processor {

    /**
     * Normalize a scalar value for emptiness checking.
     *
     * Removes BOM, control characters, NBSP, zero-width spaces, decodes HTML entities,
     * collapses whitespace, and trims.
     *
     * @param string $val
     * @return string Normalized string
     */
    protected static function normalize_value_for_empty_check( $val ) {
        if ( ! is_scalar( $val ) ) {
            $val = (string) $val;
        }

        // Decode HTML entities (e.g. &nbsp;)
        $val = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Remove BOM if present
        $val = preg_replace('/^\x{FEFF}/u', '', $val);

        // Replace common invisible / non-breaking / zero-width characters with space
        $replace = array(
            // non-breaking space (UTF-8)
            "\xC2\xA0",
            // zero width space, zero width non-joiner, zero width joiner
            "\xE2\x80\x8B",
            "\xE2\x80\x8C",
            "\xE2\x80\x8D",
            // left-to-right mark, right-to-left mark
            "\xE2\x80\x8E",
            "\xE2\x80\x8F",
            // byte order mark (UTF-8)
            "\xEF\xBB\xBF"
        );
        $val = str_replace( $replace, ' ', $val );

        // Remove other control characters (C category in Unicode)
        $val = preg_replace( '/\p{C}+/u', '', $val );

        // Collapse all whitespace sequences to single space
        $val = preg_replace( '/\s+/u', ' ', $val );

        // Trim
        $val = trim( $val );

        return $val;
    }

    /**
     * Recursively flatten a row (array/object/scalar) to a string for emptiness test.
     *
     * @param mixed $value
     * @return string
     */
    protected static function flatten_row_value( $value ) {
        if ( is_object( $value ) ) {
            $value = (array) $value;
        }
        if ( is_array( $value ) ) {
            $parts = array();
            foreach ( $value as $v ) {
                $parts[] = self::flatten_row_value( $v );
            }
            return implode( ' ', $parts );
        }
        return self::normalize_value_for_empty_check( $value );
    }

    /**
     * Check whether a parsed row is completely empty.
     *
     * Returns true when all values are empty after normalization (trim + removal of invisible chars).
     *
     * @param mixed $row Array or object representing a parsed row.
     * @return bool
     */
    protected static function is_row_empty( $row ) {
        if ( is_object( $row ) ) {
            $row = (array) $row;
        }

        // Scalar row (unlikely) — treat by normalization
        if ( ! is_array( $row ) ) {
            $v = self::flatten_row_value( $row );
            return $v === '';
        }

        // For arrays, if any cell has non-empty normalized content, row is NOT empty
        foreach ( $row as $cell ) {
            $flat = self::flatten_row_value( $cell );
            if ( $flat !== '' ) {
                return false; // row has content
            }
        }

        // all cells empty
        return true;
    }

    /**
     * Process rows and insert questions.
     *
     * @param array $rows Array of rows (each row: assoc array). Indices may correspond to original file line numbers (0-based).
     * @param array $context Optional context (e.g. quiz_id, user_id, etc.)
     * @return array Summary:
     *               - total_rows (int)
     *               - imported_rows (int)
     *               - failed_rows (int)
     *               - errors (array)
     *               - inserted_ids (array)
     */
    public static function process_rows( $rows, $context = array() ) {
        $summary = array(
            'total_rows'    => 0,
            'imported_rows' => 0,
            'failed_rows'   => 0,
            'errors'        => array(),
            'inserted_ids'  => array(),
        );

        if ( ! is_array( $rows ) ) {
            $summary['errors'][] = __( 'Processor expected an array of rows.', 'qsm-bulk-importer' );
            return $summary;
        }

        // Build an array of non-empty rows while preserving original indexes.
        // This lets us report "Row X" corresponding to the source file row index (index+1).
        $effective_rows = array(); // key => row
        foreach ( $rows as $idx => $r ) {
            if ( ! self::is_row_empty( $r ) ) {
                $effective_rows[ $idx ] = $r;
            }
        }

        $summary['total_rows'] = count( $effective_rows );

        // Candidate insertion function names used by various QSM/related plugins.
        // If your plugin uses a different insertion function, add its name below.
        $insert_candidates = array(
            'qsm_bulk_insert_question_from_row',
            'qsm_insert_question_from_row',
            'qsm_bulk_insert_question',
            'qsm_insert_question',
            'qsm_insert_single_question',
            // add more function names here if necessary
        );

        // Find a callable insertion function if available
        $insert_callable = null;
        foreach ( $insert_candidates as $fn ) {
            if ( function_exists( $fn ) ) {
                $insert_callable = $fn;
                break;
            }
        }

        // Fallback: allow external code to hook into insertion via filter
        $use_filter_fallback = ! $insert_callable && has_filter( 'qsm_bulk_insert_question' );

        // Process each non-empty row. $orig_idx is the original index from the parsed array.
        foreach ( $effective_rows as $orig_idx => $row ) {
            // Compute human-friendly row number (1-based). If parser included header row removal,
            // this will correspond to the original file index + 1.
            $row_number = intval( $orig_idx ) + 1;

            // Basic validation: require question text field if present in data.
            // If parser uses a header like 'question_text' or 'question', validate that.
            $required_failed = false;
            if ( is_array( $row ) ) {
                if ( isset( $row['question'] ) || isset( $row['question_text'] ) ) {
                    $q_text = isset( $row['question'] ) ? $row['question'] : $row['question_text'];
                    $q_text_norm = self::flatten_row_value( $q_text );
                    if ( strlen( $q_text_norm ) === 0 ) {
                        $required_failed = true;
                        $summary['failed_rows']++;
                        $summary['errors'][] = sprintf( __( 'Row %d: missing required question text.', 'qsm-bulk-importer' ), $row_number );
                    }
                }
            }

            if ( $required_failed ) {
                continue;
            }

            $insert_result = null;

            // 1) Try the discovered insert function (most probable)
            if ( $insert_callable ) {
                try {
                    $insert_result = call_user_func( $insert_callable, $row, $context );
                } catch ( Exception $e ) {
                    $insert_result = new WP_Error( 'insert_exception', $e->getMessage() );
                }
            } elseif ( $use_filter_fallback ) {
                // 2) Try apply_filters fallback (other code can hook and handle insertion)
                try {
                    $insert_result = apply_filters( 'qsm_bulk_insert_question', null, $row, $context );
                } catch ( Exception $e ) {
                    $insert_result = new WP_Error( 'insert_exception', $e->getMessage() );
                }
            } else {
                // 3) No insertion mechanism found — mark as failed and add instructive error
                $insert_result = new WP_Error( 'no_insert_fn', __( 'No insertion function found. Please provide a function to insert questions or add it to the insert_candidates array in class-import-processor.php', 'qsm-bulk-importer' ) );
            }

            // Evaluate result
            if ( is_wp_error( $insert_result ) || ( $insert_result === false ) ) {
                $summary['failed_rows']++;
                $err_msg = is_wp_error( $insert_result ) ? $insert_result->get_error_message() : __( 'Insertion failed', 'qsm-bulk-importer' );
                $summary['errors'][] = sprintf( __( 'Row %d: %s', 'qsm-bulk-importer' ), $row_number, $err_msg );
                continue;
            }

            // If the insertion returns an ID (int/string), consider it success and store inserted id
            if ( is_numeric( $insert_result ) || ( is_string( $insert_result ) && ctype_digit( (string) $insert_result ) ) ) {
                $summary['imported_rows']++;
                $summary['inserted_ids'][] = intval( $insert_result );
                continue;
            }

            // If insertion returns a truthy value but not numeric (e.g., true), count it as success
            if ( $insert_result ) {
                if ( is_array( $insert_result ) && isset( $insert_result['id'] ) ) {
                    $summary['inserted_ids'][] = intval( $insert_result['id'] );
                }
                $summary['imported_rows']++;
                continue;
            }

            // Fallback: treat as failure
            $summary['failed_rows']++;
            $summary['errors'][] = sprintf( __( 'Row %d: unknown insertion result.', 'qsm-bulk-importer' ), $row_number );
        }

        // Final sanity: ensure total_rows equals successful + failed (if mismatch, prefer computed counts)
        $calculated_total = $summary['imported_rows'] + $summary['failed_rows'];
        if ( $summary['total_rows'] !== $calculated_total ) {
            $summary['total_rows'] = $calculated_total;
        }

        return $summary;
    }
}
