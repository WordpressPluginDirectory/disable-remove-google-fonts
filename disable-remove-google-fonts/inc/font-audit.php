<?php
/**
 * Font Audit — scan the site for Google Fonts and display results.
 *
 * @package disable-remove-google-fonts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the current request is a font-audit bypass scan.
 *
 * When the audit runs it sends a loopback request with a one-time token so
 * that remove-google-fonts.php can skip its removal hooks and detect the
 * fonts a theme or plugin would otherwise load.
 *
 * @return bool
 */
function drgf_is_bypass_active() {
	if ( ! isset( $_GET['drgf_scan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}

	$token = sanitize_text_field( wp_unslash( $_GET['drgf_scan'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( empty( $token ) ) {
		return false;
	}

	return (bool) get_transient( 'drgf_scan_' . $token );
}

/**
 * Run a font audit via a loopback request or provided HTML.
 *
 * @param string $scan_url Optional page URL to scan. Defaults to the homepage.
 * @param string $html     Optional pre-fetched HTML to parse directly.
 * @return array {
 *     @type array  $fonts       Parsed font families (may be empty).
 *     @type string $scanned_at  MySQL-formatted local time.
 *     @type string $scanned_url URL that was scanned.
 *     @type string $error       Error key if the scan failed ('loopback_failed').
 * }
 */
function drgf_run_font_audit( $scan_url = '', $html = '' ) {
	$scan_url = drgf_validate_scan_url( $scan_url );
	if ( ! $scan_url ) {
		$scan_url = home_url( '/' );
	}

	if ( ! empty( $html ) ) {
		$fonts = drgf_extract_fonts_from_html( $html );
		$result = array(
			'fonts'       => $fonts,
			'scanned_at'  => current_time( 'mysql' ),
			'scanned_url' => $scan_url,
			'error'       => '',
		);
		update_option( 'drgf_font_audit', $result, false );
		return $result;
	}

	$token = drgf_generate_scan_token();

	$url = add_query_arg( 'drgf_scan', $token, $scan_url );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 20,
			'sslverify' => false,
		)
	);

	delete_transient( 'drgf_scan_' . $token );

	if ( is_wp_error( $response ) ) {
		$result = array(
			'fonts'       => array(),
			'scanned_at'  => current_time( 'mysql' ),
			'scanned_url' => $scan_url,
			'error'       => 'loopback_failed: ' . $response->get_error_message(),
		);
		update_option( 'drgf_font_audit', $result, false );
		return $result;
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 400 ) {
		$result = array(
			'fonts'       => array(),
			'scanned_at'  => current_time( 'mysql' ),
			'scanned_url' => $scan_url,
			'error'       => 'loopback_failed: HTTP Status ' . $status,
		);
		update_option( 'drgf_font_audit', $result, false );
		return $result;
	}

	$html  = wp_remote_retrieve_body( $response );
	$fonts = drgf_extract_fonts_from_html( $html );

	$result = array(
		'fonts'       => $fonts,
		'scanned_at'  => current_time( 'mysql' ),
		'scanned_url' => $scan_url,
		'error'       => '',
	);

	update_option( 'drgf_font_audit', $result, false );

	return $result;
}

/**
 * Extract Google Font families from rendered HTML.
 *
 * Looks for <link> tags, @import statements, inline @font-face blocks,
 * and same-domain linked stylesheets that reference Google Fonts.
 *
 * @param string $html Full page HTML.
 * @return array Array of font entries, each with 'name', 'weights', 'handle'.
 */
function drgf_extract_fonts_from_html( $html ) {
	$fonts = array();

	// 1. <link> tags with Google Fonts URLs.
	preg_match_all(
		'/<link[^>]*href=["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\'][^>]*>/i',
		$html,
		$link_tags
	);

	foreach ( $link_tags[0] as $index => $tag ) {
		$url    = html_entity_decode( $link_tags[1][ $index ] );
		$handle = '';

		if ( preg_match( '/id=["\']([^"\']+)-css["\']/', $tag, $id ) ) {
			$handle = $id[1];
		}

		if ( strpos( $url, '/css' ) !== false ) {
			$parsed = drgf_parse_google_fonts_url( $url );
			foreach ( $parsed as $font ) {
				drgf_merge_font_entry( $fonts, $font['name'], $font['weights'], $handle );
			}
		}
	}

	// 2. @import url(...) inside page HTML.
	preg_match_all(
		'/@import\s+url\(["\']?([^"\')]*fonts\.googleapis\.com\/css[^"\')]*)["\']?\)/i',
		$html,
		$imports
	);

	foreach ( $imports[1] as $url ) {
		$parsed = drgf_parse_google_fonts_url( html_entity_decode( $url ) );
		foreach ( $parsed as $font ) {
			drgf_merge_font_entry( $fonts, $font['name'], $font['weights'], 'inline-css' );
		}
	}

	// 3. Bare @import "https://fonts..." (without url()).
	preg_match_all(
		'/@import\s+["\']([^"\';]*fonts\.googleapis\.com\/css[^"\';]*)["\']/',
		$html,
		$bare_imports
	);

	foreach ( $bare_imports[1] as $url ) {
		$parsed = drgf_parse_google_fonts_url( html_entity_decode( $url ) );
		foreach ( $parsed as $font ) {
			drgf_merge_font_entry( $fonts, $font['name'], $font['weights'], 'inline-css' );
		}
	}

	// 4. Inline <style> blocks — @font-face and other Google Font references.
	if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $html, $style_matches ) ) {
		foreach ( $style_matches[0] as $index => $style_tag ) {
			$handle = 'inline-css';

			if ( preg_match( '/id=["\']([^"\']+)-css["\']/', $style_tag, $id ) ) {
				$handle = $id[1];
			}

			drgf_extract_fonts_from_style_content( $fonts, $style_matches[1][ $index ], $handle );
		}
	}

	// 5. Same-domain linked stylesheets.
	$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
	$stylesheet_urls = drgf_extract_stylesheet_urls( $html, $site_domain );

	foreach ( $stylesheet_urls as $stylesheet_url ) {
		$css = drgf_fetch_stylesheet_content( $stylesheet_url );
		if ( $css ) {
			$path           = wp_parse_url( $stylesheet_url, PHP_URL_PATH );
			$wp_content_pos = strpos( $path, '/wp-content/' );
			$handle         = ( false !== $wp_content_pos )
				? substr( $path, $wp_content_pos + 12 )
				: basename( $path );
			drgf_extract_fonts_from_style_content( $fonts, $css, $handle, false );
		}
	}

	// Sort alphabetically by name and re-index.
	usort(
		$fonts,
		function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		}
	);

	return array_values( $fonts );
}

/**
 * Merge a font entry into the collected fonts list.
 *
 * @param array  $fonts   Fonts list keyed by sanitized title.
 * @param string $name    Font family name.
 * @param array  $weights Font weights.
 * @param string $handle  WordPress style handle or source label.
 */
function drgf_merge_font_entry( &$fonts, $name, $weights, $handle = '', $removable = true ) {
	$name = trim( $name );
	if ( '' === $name ) {
		return;
	}

	$key = sanitize_title( $name );

	if ( isset( $fonts[ $key ] ) ) {
		$fonts[ $key ]['weights'] = array_values(
			array_unique(
				array_merge( $fonts[ $key ]['weights'], $weights )
			)
		);
		if ( $handle && ! $fonts[ $key ]['handle'] ) {
			$fonts[ $key ]['handle'] = $handle;
		}
		if ( ! $removable ) {
			$fonts[ $key ]['removable'] = false;
			if ( $handle ) {
				$fonts[ $key ]['handle'] = $handle;
			}
		}
		return;
	}

	$fonts[ $key ] = array(
		'name'      => $name,
		'weights'   => array_values( array_unique( $weights ) ),
		'handle'    => $handle,
		'removable' => $removable,
	);
}

/**
 * Check whether a string references Google Fonts hosting.
 *
 * @param string $text CSS or URL text.
 * @return bool
 */
function drgf_contains_google_font_reference( $text ) {
	return (bool) preg_match(
		'/fonts\.(googleapis|gstatic)\.com|\/fonts\.(googleapis|gstatic)\.com/i',
		$text
	);
}

/**
 * Extract fonts from CSS content (@font-face blocks and @import rules).
 *
 * @param array  $fonts  Fonts list keyed by sanitized title.
 * @param string $css    CSS content.
 * @param string $handle Source handle label.
 */
function drgf_extract_fonts_from_style_content( &$fonts, $css, $handle = 'inline-css', $removable = true ) {
	if ( ! drgf_contains_google_font_reference( $css ) ) {
		return;
	}

	if ( preg_match_all( '/@font-face\s*\{([^}]+)\}/is', $css, $font_faces ) ) {
		foreach ( $font_faces[1] as $block ) {
			if ( ! drgf_contains_google_font_reference( $block ) ) {
				continue;
			}

			$name    = drgf_parse_font_face_family( $block );
			$weights = drgf_parse_font_face_weights( $block );

			if ( '' === $name ) {
				$name = drgf_guess_font_name_from_url( $block );
			}

			if ( $name ) {
				drgf_merge_font_entry( $fonts, $name, $weights, $handle, $removable );
			}
		}
	}

	if ( preg_match_all(
		'/@import\s+url\(\s*["\']?([^"\')\s]*fonts\.googleapis\.com\/css[^"\')\s]*)["\']?\s*\)/i',
		$css,
		$url_imports
	) ) {
		foreach ( $url_imports[1] as $url ) {
			$parsed = drgf_parse_google_fonts_url( html_entity_decode( trim( $url ) ) );
			foreach ( $parsed as $font ) {
				drgf_merge_font_entry( $fonts, $font['name'], $font['weights'], $handle, $removable );
			}
		}
	}

	if ( preg_match_all(
		'/@import\s+["\']([^"\';]*fonts\.googleapis\.com\/css[^"\';]*)["\']/',
		$css,
		$bare_imports
	) ) {
		foreach ( $bare_imports[1] as $url ) {
			$parsed = drgf_parse_google_fonts_url( html_entity_decode( trim( $url ) ) );
			foreach ( $parsed as $font ) {
				drgf_merge_font_entry( $fonts, $font['name'], $font['weights'], $handle, $removable );
			}
		}
	}
}

/**
 * Parse font-family from an @font-face block.
 *
 * @param string $block @font-face rule body.
 * @return string
 */
function drgf_parse_font_face_family( $block ) {
	if ( preg_match( '/font-family\s*:\s*["\']?([^;"\'}\s][^;"\'}\n]*)/i', $block, $match ) ) {
		return trim( trim( $match[1] ), "'\"" );
	}

	return '';
}

/**
 * Parse font-weight values from an @font-face block.
 *
 * @param string $block @font-face rule body.
 * @return array
 */
function drgf_parse_font_face_weights( $block ) {
	if ( ! preg_match( '/font-weight\s*:\s*([^;]+)/i', $block, $match ) ) {
		return array( '400' );
	}

	$value = trim( $match[1] );

	if ( preg_match( '/^(\d+)\s+(\d+)$/', $value, $range ) ) {
		return array( $range[1] . '-' . $range[2] );
	}

	preg_match_all( '/\d+/', $value, $weights );

	if ( empty( $weights[0] ) ) {
		return array( '400' );
	}

	return array_values( array_unique( $weights[0] ) );
}

/**
 * Guess a font family name from a Google Fonts asset URL in CSS.
 *
 * @param string $text CSS text containing a font URL.
 * @return string
 */
function drgf_guess_font_name_from_url( $text ) {
	if ( preg_match( '/\/s\/([^\/]+)\//i', $text, $match ) ) {
		return ucwords( str_replace( '-', ' ', $match[1] ) );
	}

	return '';
}

/**
 * Extract same-domain stylesheet URLs from HTML.
 *
 * @param string $html        Page HTML.
 * @param string $site_domain Site hostname.
 * @return array
 */
function drgf_extract_stylesheet_urls( $html, $site_domain ) {
	$stylesheet_urls = array();

	if ( ! preg_match_all(
		'/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
		$html,
		$matches
	) ) {
		return $stylesheet_urls;
	}

	foreach ( $matches[1] as $url ) {
		$absolute_url      = drgf_make_absolute_url( $url, home_url( '/' ) );
		$stylesheet_domain = wp_parse_url( $absolute_url, PHP_URL_HOST );

		if ( $stylesheet_domain === $site_domain ) {
			$stylesheet_urls[] = $absolute_url;
		}
	}

	return array_values( array_unique( $stylesheet_urls ) );
}

/**
 * Convert a relative URL to an absolute URL.
 *
 * @param string $url      Relative or absolute URL.
 * @param string $base_url Base URL.
 * @return string
 */
function drgf_make_absolute_url( $url, $base_url ) {
	if ( preg_match( '/^https?:\/\//i', $url ) ) {
		return $url;
	}

	$base_parts = wp_parse_url( $base_url );

	if ( strpos( $url, '//' ) === 0 ) {
		return $base_parts['scheme'] . ':' . $url;
	}

	if ( strpos( $url, '/' ) === 0 ) {
		return $base_parts['scheme'] . '://' . $base_parts['host'] . $url;
	}

	$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
	$base_dir  = dirname( $base_path );

	if ( '.' === $base_dir ) {
		$base_dir = '/';
	}

	$base_dir = rtrim( $base_dir, '/' ) . '/';

	return $base_parts['scheme'] . '://' . $base_parts['host'] . $base_dir . $url;
}

/**
 * Fetch stylesheet content for font detection.
 *
 * @param string $stylesheet_url Stylesheet URL.
 * @return string
 */
function drgf_fetch_stylesheet_content( $stylesheet_url ) {
	// 1. Try to convert URL to local absolute path and use file_get_contents()
	$wp_content_url = content_url();
	if ( strpos( $stylesheet_url, $wp_content_url ) === 0 ) {
		$local_path = str_replace( $wp_content_url, WP_CONTENT_DIR, $stylesheet_url );
		$local_path = strtok( $local_path, '?' ); // Remove versioning query strings
		if ( file_exists( $local_path ) && is_readable( $local_path ) ) {
			$content = file_get_contents( $local_path );
			if ( false !== $content ) {
				return $content;
			}
		}
	}

	$wp_includes_url = includes_url();
	if ( strpos( $stylesheet_url, $wp_includes_url ) === 0 ) {
		$local_path = str_replace( $wp_includes_url, ABSPATH . WPINC . '/', $stylesheet_url );
		$local_path = strtok( $local_path, '?' ); // Remove versioning query strings
		if ( file_exists( $local_path ) && is_readable( $local_path ) ) {
			$content = file_get_contents( $local_path );
			if ( false !== $content ) {
				return $content;
			}
		}
	}

	// 2. Fallback to wp_remote_get if local path fails
	$response = wp_remote_get(
		$stylesheet_url,
		array(
			'timeout'    => 20,
			'sslverify'  => false,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return '';
	}

	return wp_remote_retrieve_body( $response );
}

/**
 * Parse font families and weights from a Google Fonts CSS API URL.
 *
 * Handles both the v1 and v2 API URL formats:
 *   v1: fonts.googleapis.com/css?family=Roboto:400,700|Open+Sans:400,600
 *   v2: fonts.googleapis.com/css2?family=Roboto:wght@400;700&family=Open+Sans:wght@400;600
 *
 * @param string $url A Google Fonts CSS URL.
 * @return array Array of arrays, each with 'name' (string) and 'weights' (string[]).
 */
function drgf_parse_google_fonts_url( $url ) {
	$fonts = array();

	$parsed = wp_parse_url( $url );
	if ( ! $parsed || empty( $parsed['query'] ) ) {
		return $fonts;
	}

	$query = $parsed['query'];

	// CSS2 (v2) API — may have multiple family= params.
	if ( false !== strpos( $url, 'css2' ) ) {
		preg_match_all( '/(?:^|&)family=([^&]+)/', $query, $matches );

		foreach ( $matches[1] as $family_str ) {
			$family_str = urldecode( $family_str );
			$parts      = explode( ':', $family_str, 2 );
			$name       = str_replace( '+', ' ', trim( $parts[0] ) );
			$weights    = array();

			if ( isset( $parts[1] ) && preg_match( '/@(.+)$/', $parts[1], $wm ) ) {
				foreach ( explode( ';', $wm[1] ) as $spec ) {
					$vals     = explode( ',', $spec );
					$weight   = end( $vals );
					$weight   = preg_replace( '/[^0-9]/', '', $weight );
					if ( $weight ) {
						$weights[] = $weight;
					}
				}
			}

			if ( empty( $weights ) ) {
				$weights = array( '400' );
			}

			$fonts[] = array(
				'name'    => $name,
				'weights' => array_values( array_unique( $weights ) ),
			);
		}

		return $fonts;
	}

	// CSS (v1) API — families separated by |.
	parse_str( $query, $params );

	if ( empty( $params['family'] ) ) {
		return $fonts;
	}

	$family_strings = explode( '|', $params['family'] );

	foreach ( $family_strings as $family_str ) {
		$parts   = explode( ':', $family_str, 2 );
		$name    = str_replace( '+', ' ', trim( $parts[0] ) );
		$weights = array();

		if ( isset( $parts[1] ) ) {
			foreach ( explode( ',', $parts[1] ) as $spec ) {
				$weight = preg_replace( '/[^0-9]/', '', $spec );
				if ( $weight ) {
					$weights[] = $weight;
				}
			}
		}

		if ( empty( $weights ) ) {
			$weights = array( '400' );
		}

		$fonts[] = array(
			'name'    => $name,
			'weights' => array_values( array_unique( $weights ) ),
		);
	}

	return $fonts;
}

/**
 * Return the stored font-audit data, or an empty default.
 *
 * @return array
 */
function drgf_get_font_audit() {
	return get_option(
		'drgf_font_audit',
		array(
			'fonts'       => array(),
			'scanned_at'  => '',
			'scanned_url' => '',
			'error'       => '',
		)
	);
}

/**
 * Get the current front-end page URL.
 *
 * @return string
 */
function drgf_get_current_page_url() {
	if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
		return set_url_scheme(
			'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] )
		);
	}

	return home_url( '/' );
}

/**
 * Validate a scan URL — must belong to this site.
 *
 * @param string $url URL to validate.
 * @return string Sanitized URL, or empty string if invalid.
 */
function drgf_validate_scan_url( $url ) {
	$url = esc_url_raw( wp_unslash( $url ) );
	if ( ! $url ) {
		return '';
	}

	$url_host  = wp_parse_url( $url, PHP_URL_HOST );
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! $url_host || $url_host !== $site_host ) {
		return '';
	}

	return $url;
}

/**
 * Truncate a URL for display purposes.
 *
 * @param string $url The URL to truncate.
 * @return string
 */
function drgf_truncate_url( $url ) {
	$url = preg_replace( '(^https?://)', '', $url );
	$url = rtrim( $url, '/' );
	
	if ( strlen( $url ) > 50 ) {
		$url = substr( $url, 0, 47 ) . '...';
	}
	
	return $url;
}

/**
 * Build a Google Fonts <link> URL from an array of font entries.
 *
 * Used on the admin page to load fonts for the preview cards.
 *
 * @param array $fonts Array of font entries (from drgf_get_font_audit).
 * @return string Google Fonts CSS2 URL, or empty string.
 */
function drgf_build_preview_url( $fonts ) {
	if ( empty( $fonts ) ) {
		return '';
	}

	$families = array();
	foreach ( $fonts as $font ) {
		$name = str_replace( ' ', '+', $font['name'] );
		if ( ! empty( $font['weights'] ) ) {
			$families[] = 'family=' . $name . ':wght@' . implode( ';', $font['weights'] );
		} else {
			$families[] = 'family=' . $name;
		}
	}

	return 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap';
}

/**
 * Generate a scan bypass token and save it in a transient.
 *
 * @return string
 */
function drgf_generate_scan_token() {
	$token = wp_generate_password( 32, false );
	set_transient( 'drgf_scan_' . $token, true, 600 ); // 10 minutes
	return $token;
}
