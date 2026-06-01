<?php
/**
 * QSM Bulk Importer — Helper functions
 *
 * Small, commonly used helpers kept in a single file.
 *
 * @package QSM_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return a URL for a plugin asset.
 *
 * @param string $relative Relative path inside plugin folder (no leading slash).
 * @return string
 */
if ( ! function_exists( 'qsm_bulk_asset_url' ) ) {
	function qsm_bulk_asset_url( $relative ) {
		$relative = ltrim( $relative, '/\\' );
		if ( defined( 'QSM_BULK_URL' ) ) {
			return trailingslashit( QSM_BULK_URL ) . $relative;
		}
		// Fallback: attempt to compute from plugin_dir_url
		return plugin_dir_url( dirname( __FILE__, 2 ) ) . $relative;
	}
}

/**
 * Normalize a string for emptiness checks.
 *
 * This function:
 *  - Converts arrays/objects to string if possible
 *  - Decodes HTML entities
 *  - Replaces common invisible/zero-width characters (NBSP, ZWSP, ZWJ, LRM/RLM, BOM)
 *  - Removes other Unicode control characters
 *  - Collapses whitespace to a single ASCII space
 *  - Trims leading/trailing whitespace
 *
 * Use this to determine whether a cell or a row is truly empty.
 *
 * @param mixed $value Scalar/array/object to normalize
 * @return string Normalized string (may be empty)
 */
if ( ! function_exists( 'qsm_bulk_normalize_text' ) ) {
	function qsm_bulk_normalize_text( $value ) {
		// Convert arrays/objects to string representation
		if ( is_array( $value ) ) {
			// join array parts with space
			$value = implode( ' ', array_map( 'strval', $value ) );
		} elseif ( is_object( $value ) ) {
			$value = implode( ' ', array_map( 'strval', (array) $value ) );
		} else {
			$value = (string) $value;
		}

		// Decode HTML entities
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Replace several known invisible/zero-width characters with a space
		$replace = array(
			// common no-break space (UTF-8)
			"\xC2\xA0",
			// zero-width space
			"\xE2\x80\x8B",
			// zero-width non-joiner, joiner
			"\xE2\x80\x8C",
			"\xE2\x80\x8D",
			// left-to-right mark, right-to-left mark
			"\xE2\x80\x8E",
			"\xE2\x80\x8F",
			// byte order mark (UTF-8)
			"\xEF\xBB\xBF",
		);
		$value = str_replace( $replace, ' ', $value );

		// Remove other control characters (category C)
		// Use @ to suppress warnings on broken regex if mbstring isn't available (rare)
		$value = preg_replace( '/\p{C}+/u', '', $value );

		// Collapse all whitespace sequences to single ASCII space
		$value = preg_replace( '/\s+/u', ' ', $value );

		// Trim
		$value = trim( $value );

		return $value;
	}
}
