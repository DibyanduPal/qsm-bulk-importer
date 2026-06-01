<?php
/**
 * includes/class-import-parser.php
 *
 * Robust import parser for QSM Bulk Importer
 *
 * Responsibilities:
 *  - Accept an uploaded file (path or $_FILES array) and parse it into a normalized array of rows
 *  - Support CSV, JSON, and XLSX/XLS (via PhpSpreadsheet if available)
 *  - Remove completely empty rows (trimmed) so trailing blank spreadsheet lines do not become errors
 *  - Return a consistent structure with parsed rows, any parsing errors, and meta info
 *
 * Usage:
 *  $result = QSM_Bulk_Import_Parser::parse_file( $file ); // $file = path string or $_FILES['input']
 *  $rows   = $result['rows'];    // array of associative arrays (or indexed arrays if no header)
 *  $errors = $result['errors'];  // array of error messages (if any)
 *  $meta   = $result['meta'];    // additional info (mime, extension, total_before_filter, total_after_filter)
 *
 * Notes:
 *  - This file is intentionally defensive: it does not assume presence of external libs.
 *  - If you expect XLSX support and PhpSpreadsheet is not installed, the parser will return an error message
 *    in the 'errors' array describing the missing dependency.
 *
 * @package qsm-bulk-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QSM_Bulk_Import_Parser {

    /**
     * Parse a file and return rows.
     *
     * @param string|array $file Path to file OR a $_FILES[]-style array with ['tmp_name'] and ['name'].
     * @param array $args Optional arguments:
     *                    - 'has_header' => null|true|false (null = auto-detect)
     *                    - 'delimiter'  => null|','
     * @return array {
     *     @type array $rows    Parsed rows (each row: assoc array if header present, else indexed array)
     *     @type array $errors  Parsing errors and warnings
     *     @type array $meta    meta info: mime, ext, total_before_filter, total_after_filter
     * }
     */
    public static function parse_file( $file, $args = array() ) {
        $errors = array();
        $rows   = array();
        $meta   = array(
            'mime'                  => '',
            'extension'             => '',
            'total_before_filter'   => 0,
            'total_after_filter'    => 0,
        );

        // Normalize $file input
        if ( is_array( $file ) && ! empty( $file['tmp_name'] ) ) {
            $file_path = $file['tmp_name'];
            $file_name = isset( $file['name'] ) ? $file['name'] : basename( $file_path );
        } elseif ( is_string( $file ) && file_exists( $file ) ) {
            $file_path = $file;
            $file_name = basename( $file );
        } else {
            $errors[] = __( 'Invalid file provided to parser.', 'qsm-bulk-importer' );
            return array( 'rows' => array(), 'errors' => $errors, 'meta' => $meta );
        }

        // Determine mime & extension
        $finfo = false;
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = @finfo_open( FILEINFO_MIME_TYPE );
        }

        if ( $finfo ) {
            $mime = @finfo_file( $finfo, $file_path );
            @finfo_close( $finfo );
        } else {
            $mime = wp_check_filetype( $file_name )['type'] ?? '';
        }

        $ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

        $meta['mime']      = $mime;
        $meta['extension'] = $ext;

        // Choose parser by extension or mime
        try {
            if ( in_array( $ext, array( 'csv', 'txt' ), true ) || strpos( $mime, 'csv' ) !== false ) {
                $rows = self::parse_csv( $file_path, $args );
            } elseif ( in_array( $ext, array( 'json' ), true ) || strpos( $mime, 'json' ) !== false ) {
                $rows = self::parse_json( $file_path, $args );
            } elseif ( in_array( $ext, array( 'xlsx', 'xls' ), true ) || stripos( $mime, 'spreadsheet' ) !== false || stripos( $mime, 'excel' ) !== false ) {
                $rows = self::parse_xlsx( $file_path, $args );
            } else {
                // Fallback: try CSV parser first, else JSON
                $try_csv = self::parse_csv( $file_path, $args );
                if ( empty( $try_csv ) ) {
                    $try_json = self::parse_json( $file_path, $args );
                    $rows = $try_json;
                } else {
                    $rows = $try_csv;
                }
            }
        } catch ( Exception $e ) {
            $errors[] = sprintf( __( 'Exception while parsing file: %s', 'qsm-bulk-importer' ), $e->getMessage() );
            return array( 'rows' => array(), 'errors' => $errors, 'meta' => $meta );
        }

        // Ensure rows is array
        if ( ! is_array( $rows ) ) {
            $errors[] = __( 'Parsed data is not an array.', 'qsm-bulk-importer' );
            return array( 'rows' => array(), 'errors' => $errors, 'meta' => $meta );
        }

        $meta['total_before_filter'] = count( $rows );

        // Normalize each row (convert objects to arrays, trim values)
        $normalized = array();
        foreach ( $rows as $r ) {
            if ( is_object( $r ) ) {
                $r = (array) $r;
            }

            if ( is_array( $r ) ) {
                $norm = array();
                foreach ( $r as $k => $v ) {
                    if ( is_array( $v ) || is_object( $v ) ) {
                        // Flatten nested arrays to string space-separated (keeps data readable)
                        $v = implode( ' ', (array) $v );
                    }
                    // trim strings
                    if ( is_scalar( $v ) ) {
                        $v = trim( (string) $v );
                    }
                    // keep original keys (could be numeric indexes or header names)
                    $norm[ $k ] = $v;
                }
                $normalized[] = $norm;
            } else {
                // non-array row: cast to string entry
                $normalized[] = array( 0 => trim( (string) $r ) );
            }
        }

        // Filter out completely empty rows (all columns empty after trim)
        $filtered = array();
        foreach ( $normalized as $r ) {
            $has_non_empty = false;
            foreach ( $r as $val ) {
                if ( is_array( $val ) || is_object( $val ) ) {
                    $val = implode( ' ', (array) $val );
                }
                if ( is_scalar( $val ) && strlen( trim( (string) $val ) ) > 0 ) {
                    $has_non_empty = true;
                    break;
                }
            }
            if ( $has_non_empty ) {
                $filtered[] = $r;
            }
        }

        // Re-index rows to be contiguous (Row 0..N-1)
        $rows = array_values( $filtered );
        $meta['total_after_filter'] = count( $rows );

        return array( 'rows' => $rows, 'errors' => $errors, 'meta' => $meta );
    }

    /**
     * Parse CSV file into rows.
     *
     * - Auto-detect header row (if $args['has_header'] is null)
     * - Returns an array of assoc arrays when header detected, otherwise indexed arrays
     *
     * @param string $file_path
     * @param array  $args
     * @return array
     */
    protected static function parse_csv( $file_path, $args = array() ) {
        $rows = array();
        $has_header = isset( $args['has_header'] ) ? $args['has_header'] : null;
        $delimiter = isset( $args['delimiter'] ) ? $args['delimiter'] : null;

        // Attempt to open
        if ( ( $handle = @fopen( $file_path, 'r' ) ) === false ) {
            return array();
        }

        // If delimiter not provided, try to detect common delimiters
        if ( empty( $delimiter ) ) {
            // read first 4096 bytes and guess
            $sample = fread( $handle, 4096 );
            rewind( $handle );
            $comma = substr_count( $sample, ',' );
            $semicolon = substr_count( $sample, ';' );
            $tab = substr_count( $sample, "\t" );
            if ( $tab > max( $comma, $semicolon ) ) {
                $delimiter = "\t";
            } elseif ( $semicolon > $comma ) {
                $delimiter = ';';
            } else {
                $delimiter = ',';
            }
        }

        // Read rows
        $first_row = null;
        while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            // Skip BOM-only rows (some CSVs may include BOM on first field)
            if ( count( $data ) === 1 && trim( $data[0] ) === '' ) {
                // treat as empty row; will be filtered later
                $rows[] = $data;
                continue;
            }

            if ( $first_row === null ) {
                $first_row = $data;
                $rows[] = $data;
                continue;
            }

            $rows[] = $data;
        }
        fclose( $handle );

        // If we have at least 2 rows, attempt header detection when has_header is null
        if ( $has_header === null && is_array( $first_row ) && count( $rows ) >= 2 ) {
            $has_header = self::detect_header_from_row( $first_row );
        }

        // If header detected, map subsequent rows as associative arrays
        if ( $has_header && is_array( $first_row ) ) {
            $header = array_map( 'trim', $first_row );
            // sanitize header names: replace spaces with underscores, lowercase
            $header_sanitized = array();
            foreach ( $header as $h ) {
                $h_s = sanitize_title_with_dashes( $h );
                if ( $h_s === '' ) {
                    // fallback to raw text but trimmed
                    $h_s = trim( $h );
                }
                $header_sanitized[] = $h_s;
            }

            $mapped = array();
            foreach ( $rows as $i => $r ) {
                if ( $i === 0 ) {
                    // header row
                    continue;
                }
                $row_assoc = array();
                foreach ( $header_sanitized as $col_index => $col_name ) {
                    $row_assoc[ $col_name ] = isset( $r[ $col_index ] ) ? $r[ $col_index ] : '';
                }
                // if there are extra columns without header, append them with numeric keys
                if ( count( $r ) > count( $header_sanitized ) ) {
                    for ( $j = count( $header_sanitized ); $j < count( $r ); $j++ ) {
                        $row_assoc[ $j ] = isset( $r[ $j ] ) ? $r[ $j ] : '';
                    }
                }
                $mapped[] = $row_assoc;
            }
            return $mapped;
        }

        // No header: return indexed arrays
        return $rows;
    }

    /**
     * Try to detect if a row looks like a header.
     *
     * Heuristic: if more than half the cells contain letters or non-numeric text, treat as header.
     *
     * @param array $row
     * @return bool
     */
    protected static function detect_header_from_row( $row ) {
        if ( ! is_array( $row ) || empty( $row ) ) {
            return false;
        }
        $text_like = 0;
        $total = 0;
        foreach ( $row as $cell ) {
            $total++;
            $cell_trim = trim( (string) $cell );
            if ( $cell_trim === '' ) {
                continue;
            }
            // if cell contains any letter or non-digit characters, count as text-like
            if ( preg_match( '/[A-Za-z]/', $cell_trim ) || ! is_numeric( $cell_trim ) ) {
                $text_like++;
            }
        }
        // if majority of non-empty cells are text-like, assume header
        if ( $total > 0 && $text_like / $total >= 0.5 ) {
            return true;
        }
        return false;
    }

    /**
     * Parse JSON file.
     *
     * @param string $file_path
     * @param array  $args
     * @return array
     */
    protected static function parse_json( $file_path, $args = array() ) {
        $content = @file_get_contents( $file_path );
        if ( $content === false ) {
            return array();
        }
        $decoded = json_decode( $content, true );
        if ( $decoded === null ) {
            // invalid json
            return array();
        }

        // If top-level contains 'data' or 'rows' keys, try to use them
        if ( isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
            return $decoded['rows'];
        } elseif ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
            return $decoded['data'];
        }

        // If associative array of objects (list), return it
        if ( self::is_associative_array_of_rows( $decoded ) ) {
            // normalize to list of arrays
            $rows = array();
            foreach ( $decoded as $item ) {
                $rows[] = (array) $item;
            }
            return $rows;
        }

        // Otherwise, try to convert top-level to rows if it's a sequential array
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        return array();
    }

    /**
     * Parse XLSX/XLS using PhpSpreadsheet when available.
     *
     * @param string $file_path
     * @param array  $args
     * @return array
     */
    protected static function parse_xlsx( $file_path, $args = array() ) {
        // If PhpSpreadsheet not available, return empty and let caller decide fallback
        if ( ! class_exists( 'PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx' ) && ! class_exists( 'PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
            return array();
        }

        try {
            // Use IOFactory to auto-detect
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile( $file_path );
            $reader->setReadDataOnly( true );
            $spreadsheet = $reader->load( $file_path );
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray( null, true, true, true ); // preserve empty cells, columns keyed by A,B,C...
            if ( empty( $data ) ) {
                return array();
            }

            // Convert column-letter keyed rows into numeric or assoc rows.
            // Detect header heuristic (first row)
            $first = reset( $data );
            $has_header = isset( $args['has_header'] ) ? $args['has_header'] : null;
            if ( $has_header === null ) {
                $has_header = self::detect_header_from_row( $first );
            }

            $rows = array();
            if ( $has_header ) {
                // Build header keys
                $header = array();
                foreach ( $first as $col_letter => $val ) {
                    $header[] = sanitize_title_with_dashes( (string) $val );
                }
                foreach ( $data as $r_i => $row ) {
                    if ( $r_i === key( $data ) ) {
                        // skip header (first)
                        continue;
                    }
                    $assoc = array();
                    $i = 0;
                    foreach ( $row as $col_letter => $val ) {
                        $col_name = isset( $header[ $i ] ) && $header[ $i ] !== '' ? $header[ $i ] : $col_letter;
                        $assoc[ $col_name ] = $val;
                        $i++;
                    }
                    $rows[] = $assoc;
                }
            } else {
                // Return indexed arrays
                foreach ( $data as $row ) {
                    $rows[] = array_values( $row );
                }
            }

            return $rows;
        } catch ( Exception $e ) {
            // On any error, return empty so fallback may be attempted
            return array();
        }
    }

    /**
     * Utility: check if decoded JSON is associative array of rows.
     *
     * @param mixed $arr
     * @return bool
     */
    protected static function is_associative_array_of_rows( $arr ) {
        if ( ! is_array( $arr ) ) {
            return false;
        }
        // If keys are sequential numeric starting at 0 it's probably list; return true for list.
        $keys = array_keys( $arr );
        foreach ( $keys as $k ) {
            if ( ! is_int( $k ) ) {
                // associative top-level: could still be object containing row data
                if ( is_array( $arr[ $k ] ) || is_object( $arr[ $k ] ) ) {
                    return true;
                }
                return false;
            }
        }
        return true;
    }
}
