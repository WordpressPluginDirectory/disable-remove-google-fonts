<?php
/**
 * Google Fonts Detection Functions.
 *
 * @package disable-remove-google-fonts
 */


/**
 * Check homepage for Google Fonts references.
 *
 * @return array {
 *     @type bool   $found          Whether Google Fonts were found.
 *     @type array  $references      Array of found references with details.
 *     @type int    $stylesheets_checked Number of stylesheets checked.
 *     @type string $error           Error message if check failed.
 * }
 */
function drgf_check_google_fonts() {
	$result = array(
		'found'              => false,
		'references'         => array(),
		'stylesheets_checked' => 0,
		'error'              => '',
	);

	// Check if we have captured HTML from a real page visit (better results).
	$captured_html_data = get_transient( 'drgf_captured_html' );
	$html = '';
	
	// Use captured HTML if available (no caching - user must capture fresh HTML each time).
	if ( $captured_html_data && isset( $captured_html_data['html'] ) ) {
		$html = $captured_html_data['html'];
		$result['using_captured'] = true;
		if ( isset( $captured_html_data['url'] ) ) {
			$result['captured_url'] = $captured_html_data['url'];
		}
		// Clear the captured HTML immediately after use so user must capture fresh next time.
		delete_transient( 'drgf_captured_html' );
	}
	
	// Get site domain for filtering stylesheets.
	$site_domain = parse_url( home_url(), PHP_URL_HOST );

	// Check HTML directly for Google Fonts references.
	$html_references = drgf_check_html_for_fonts( $html );
	if ( ! empty( $html_references ) ) {
		$result['found'] = true;
		$result['references'] = array_merge( $result['references'], $html_references );
	}

	// Extract stylesheet URLs from HTML.
	$stylesheet_urls = drgf_extract_stylesheet_urls( $html, $site_domain );

	// Check each same-domain stylesheet.
	foreach ( $stylesheet_urls as $stylesheet_url ) {
		$result['stylesheets_checked']++;
		$css_references = drgf_check_stylesheet_for_fonts( $stylesheet_url );
		if ( ! empty( $css_references ) ) {
			$result['found'] = true;
			$result['references'] = array_merge( $result['references'], $css_references );
		}
	}

	return $result;
}

/**
 * Check HTML content for Google Fonts references.
 *
 * @param string $html HTML content to check.
 * @return array Array of found references.
 */
function drgf_check_html_for_fonts( $html ) {
	$references = array();

	// Check for <link> tags with Google Fonts.
	if ( preg_match_all( '/<link[^>]+href=["\']([^"\']*fonts\.(googleapis|gstatic)\.com[^"\']*)["\'][^>]*>/i', $html, $matches ) ) {
		foreach ( $matches[1] as $url ) {
			$references[] = array(
				'type' => 'html_link',
				'url'  => $url,
				'source' => __( 'HTML: Link tag', 'disable-remove-google-fonts' ),
				'context' => __( 'Found in &lt;link&gt; tag in page HTML', 'disable-remove-google-fonts' ),
			);
		}
	}

	// Check for inline <style> tags with Google Fonts.
	if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $html, $style_matches ) ) {
		foreach ( $style_matches[1] as $style_index => $style_content ) {
			// First, check for @font-face declarations with url() containing Google Fonts.
			if ( preg_match_all( '/@font-face\s*\{[^}]*src[^}]*url\(["\']?([^"\')]*fonts\.(googleapis|gstatic)\.com[^"\')]*)["\']?\)[^}]*\}/is', $style_content, $font_face_matches ) ) {
				foreach ( $font_face_matches[1] as $font_url ) {
					$font_url = trim( $font_url );
					if ( ! empty( $font_url ) ) {
						$references[] = array(
							'type' => 'html_inline_style',
							'url'  => $font_url,
							'source' => __( 'HTML: @font-face in inline style', 'disable-remove-google-fonts' ),
							'context' => __( 'Found in @font-face src: url() declaration in inline &lt;style&gt; tag', 'disable-remove-google-fonts' ),
						);
					}
				}
			}
			
			// Also check for any other Google Fonts URLs in the style tag (for @import, etc.).
			if ( preg_match_all( '/url\(["\']?([^"\')]*fonts\.(googleapis|gstatic)\.com[^"\')]*)["\']?\)/i', $style_content, $url_matches ) ) {
				foreach ( $url_matches[1] as $font_url ) {
					$font_url = trim( $font_url );
					// Skip if already captured by @font-face check above.
					$already_captured = false;
					foreach ( $references as $ref ) {
						if ( $ref['url'] === $font_url ) {
							$already_captured = true;
							break;
						}
					}
					if ( ! $already_captured && ! empty( $font_url ) ) {
						$references[] = array(
							'type' => 'html_inline_style',
							'url'  => $font_url,
							'source' => __( 'HTML: Inline style tag', 'disable-remove-google-fonts' ),
							'context' => __( 'Found in url() declaration in inline &lt;style&gt; tag', 'disable-remove-google-fonts' ),
						);
					}
				}
			}
			
			// Check for @import statements with Google Fonts.
			if ( preg_match_all( '/@import\s+["\']?([^"\';]*fonts\.(googleapis|gstatic)\.com[^"\';]*)["\']?/i', $style_content, $import_matches ) ) {
				foreach ( $import_matches[1] as $import_url ) {
					$import_url = trim( $import_url );
					// Skip if already captured.
					$already_captured = false;
					foreach ( $references as $ref ) {
						if ( $ref['url'] === $import_url ) {
							$already_captured = true;
							break;
						}
					}
					if ( ! $already_captured && ! empty( $import_url ) ) {
						$references[] = array(
							'type' => 'html_inline_style',
							'url'  => $import_url,
							'source' => __( 'HTML: @import in inline style', 'disable-remove-google-fonts' ),
							'context' => __( 'Found in @import statement in inline &lt;style&gt; tag', 'disable-remove-google-fonts' ),
						);
					}
				}
			}
		}
	}

	// Check for JavaScript references to Google Fonts.
	if ( preg_match_all( '/<script[^>]*>(.*?)<\/script>/is', $html, $script_matches ) ) {
		foreach ( $script_matches[1] as $script_content ) {
			if ( preg_match( '/fonts\.(googleapis|gstatic)\.com[^"\'\s\)]*/i', $script_content, $font_url_match ) ) {
				$font_url = $font_url_match[0];
				$references[] = array(
					'type' => 'html_javascript',
					'url'  => $font_url,
					'source' => __( 'HTML: JavaScript reference', 'disable-remove-google-fonts' ),
					'context' => __( 'Found in inline &lt;script&gt; tag in page HTML', 'disable-remove-google-fonts' ),
				);
			}
		}
	}
	
	// Check for script src attributes with Google Fonts.
	if ( preg_match_all( '/<script[^>]+src=["\']([^"\']*fonts\.(googleapis|gstatic)\.com[^"\']*)["\'][^>]*>/i', $html, $script_src_matches ) ) {
		foreach ( $script_src_matches[1] as $url ) {
			$references[] = array(
				'type' => 'html_script_src',
				'url'  => $url,
				'source' => __( 'HTML: Script src attribute', 'disable-remove-google-fonts' ),
				'context' => __( 'Found in &lt;script src&gt; attribute in page HTML', 'disable-remove-google-fonts' ),
			);
		}
	}

	return $references;
}

/**
 * Extract stylesheet URLs from HTML, filtering to same domain only.
 *
 * @param string $html HTML content.
 * @param string $site_domain Site domain to match.
 * @return array Array of stylesheet URLs (same domain only).
 */
function drgf_extract_stylesheet_urls( $html, $site_domain ) {
	$stylesheet_urls = array();

	// Extract all <link rel="stylesheet"> tags.
	if ( preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
		foreach ( $matches[1] as $url ) {
			// Convert relative URLs to absolute.
			$absolute_url = drgf_make_absolute_url( $url, home_url( '/' ) );

			// Extract domain from stylesheet URL.
			$stylesheet_domain = parse_url( $absolute_url, PHP_URL_HOST );

			// Only include same-domain stylesheets.
			if ( $stylesheet_domain === $site_domain ) {
				$stylesheet_urls[] = $absolute_url;
			}
		}
	}

	// Remove duplicates.
	$stylesheet_urls = array_unique( $stylesheet_urls );

	return $stylesheet_urls;
}

/**
 * Convert relative URL to absolute URL.
 *
 * @param string $url Relative or absolute URL.
 * @param string $base_url Base URL for relative URLs.
 * @return string Absolute URL.
 */
function drgf_make_absolute_url( $url, $base_url ) {
	// If already absolute, return as is.
	if ( preg_match( '/^https?:\/\//i', $url ) ) {
		return $url;
	}

	// Parse base URL.
	$base_parts = parse_url( $base_url );

	// Handle protocol-relative URLs.
	if ( strpos( $url, '//' ) === 0 ) {
		return $base_parts['scheme'] . ':' . $url;
	}

	// Handle absolute paths.
	if ( strpos( $url, '/' ) === 0 ) {
		return $base_parts['scheme'] . '://' . $base_parts['host'] . $url;
	}

	// Handle relative paths.
	$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
	$base_dir = dirname( $base_path );
	if ( $base_dir === '.' ) {
		$base_dir = '/';
	}
	$base_dir = rtrim( $base_dir, '/' ) . '/';

	return $base_parts['scheme'] . '://' . $base_parts['host'] . $base_dir . $url;
}

/**
 * Check a stylesheet for Google Fonts references.
 *
 * @param string $stylesheet_url URL of the stylesheet to check.
 * @return array Array of found references.
 */
function drgf_check_stylesheet_for_fonts( $stylesheet_url ) {
	$references = array();

	// Fetch stylesheet content.
	$response = wp_remote_get(
		$stylesheet_url,
		array(
			'timeout'   => 20,
			'sslverify' => false,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Skip failed stylesheets, but don't fail entire check.
		return $references;
	}

	$css = wp_remote_retrieve_body( $response );
	if ( empty( $css ) ) {
		return $references;
	}

	$stylesheet_name = basename( $stylesheet_url );
	
	// Check for @import statements with Google Fonts.
	if ( preg_match_all( '/@import\s+["\']?([^"\';]+fonts\.(googleapis|gstatic)\.com[^"\';]*)["\']?/i', $css, $import_matches ) ) {
		foreach ( $import_matches[1] as $import_url ) {
			$references[] = array(
				'type' => 'css_import',
				'url'  => trim( $import_url ),
				'source' => sprintf( __( 'CSS: @import in %s', 'disable-remove-google-fonts' ), $stylesheet_name ),
				'context' => sprintf( __( 'Found @import statement in stylesheet: %s', 'disable-remove-google-fonts' ), $stylesheet_name ),
			);
		}
	}

	// Check for url() declarations with Google Fonts.
	if ( preg_match_all( '/url\(["\']?([^"\')]+fonts\.(googleapis|gstatic)\.com[^"\')]*)["\']?\)/i', $css, $url_matches ) ) {
		foreach ( $url_matches[1] as $font_url ) {
			$references[] = array(
				'type' => 'css_url',
				'url'  => trim( $font_url ),
				'source' => sprintf( __( 'CSS: url() in %s', 'disable-remove-google-fonts' ), $stylesheet_name ),
				'context' => sprintf( __( 'Found url() reference in stylesheet: %s', 'disable-remove-google-fonts' ), $stylesheet_name ),
			);
		}
	}

	// Check for any other Google Fonts references in CSS.
	if ( preg_match_all( '/fonts\.(googleapis|gstatic)\.com[^"\'\s\)]*/i', $css, $other_matches ) ) {
		// Get unique URLs that aren't already captured.
		$unique_urls = array_unique( $other_matches[0] );
		foreach ( $unique_urls as $font_url ) {
			// Skip if already captured by @import or url()
			$already_captured = false;
			foreach ( $references as $ref ) {
				if ( strpos( $ref['url'], $font_url ) !== false || strpos( $font_url, $ref['url'] ) !== false ) {
					$already_captured = true;
					break;
				}
			}
			if ( ! $already_captured ) {
				$references[] = array(
					'type' => 'css_other',
					'url'  => $font_url,
					'source' => sprintf( __( 'CSS: Other reference in %s', 'disable-remove-google-fonts' ), $stylesheet_name ),
					'context' => sprintf( __( 'Found Google Fonts reference in stylesheet: %s', 'disable-remove-google-fonts' ), $stylesheet_name ),
				);
			}
		}
	}

	return $references;
}

