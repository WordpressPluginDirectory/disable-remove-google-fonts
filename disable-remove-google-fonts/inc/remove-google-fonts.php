<?php
/**
 * This file contains functions to remove Google Fonts from themes and plugins.
 *
 * @package disable-remove-google-fonts
 */

/**
 * Skip all removal logic when the font-audit bypass is active.
 * This lets the loopback request see which fonts would normally load.
 */
if ( function_exists( 'drgf_is_bypass_active' ) && drgf_is_bypass_active() ) {
	return;
}

/**
 * Remove DNS prefetch, preconnect and preload headers.
 *
 * This function removes the DNS prefetch, preconnect, and preload headers for Google Fonts.
 * It filters the URLs array based on the relation type and removes any URLs related to Google Fonts.
 *
 * @param array  $urls           The array of URLs to filter.
 * @param string $relation_type  The type of relation for the URLs.
 * @return array The filtered array of URLs.
 */
function drgf_remove_prefetch( $urls, $relation_type ) {
	if ( 'dns-prefetch' === $relation_type ) {
		$urls = array_diff( $urls, array( 'fonts.googleapis.com' ) );
	} elseif ( 'preconnect' === $relation_type || 'preload' === $relation_type ) {
		foreach ( $urls as $key => $url ) {
			if ( ! isset( $url['href'] ) ) {
				continue;
			}
			if ( preg_match( '/\/\/fonts\.(gstatic|googleapis)\.com/', $url['href'] ) ) {
				unset( $urls[ $key ] );
			}
		}
	}

	return $urls;
}
add_filter( 'wp_resource_hints', 'drgf_remove_prefetch', PHP_INT_MAX, 2 );

// Remove the aThemes resource hints.
remove_action( 'wp_head', 'sydney_preconnect_google_fonts' );
remove_action( 'wp_head', 'botiga_preconnect_google_fonts' );

/**
 * Dequeue Google Fonts based on URL.
 */
function drgf_dequeue_fonts() {
	// Remove fonts added by the Divi Extra theme.
	remove_action( 'wp_footer', 'et_builder_print_font' );

	// Dequeue Google Fonts loaded by Revolution Slider.
	remove_action( 'wp_footer', array( 'RevSliderFront', 'load_google_fonts' ) );

	// Dequeue common font loader scripts.
	$scripts_to_dequeue = array(
		'mk-webfontloader',
		'jupiterx-webfont',
		'csf-google-web-fonts',
		'mo-google-webfont',
	);
	foreach ( $scripts_to_dequeue as $script ) {
		wp_dequeue_script( $script );
	}

	global $wp_styles;

	if ( ! ( $wp_styles instanceof WP_Styles ) ) {
		return;
	}

	$allowed = apply_filters( 'drgf_exceptions', array() );

	foreach ( $wp_styles->registered as $style ) {
		$handle = $style->handle;
		$src    = $style->src;

		if ( strpos( $src, 'fonts.googleapis' ) !== false ) {
			if ( ! array_key_exists( $handle, array_flip( $allowed ) ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * Some themes set the Google Fonts URL as a dependency, so we need to replace
	 * it with a blank value rather than removing it entirely. As that would
	 * remove the stylesheet too.
	 */
	if ( ! empty( $style->deps ) ) {
		$strings = array(
			'google-fonts',
			'google_fonts',
			'googlefonts',
			'bookyourtravel-heading-font',
			'bookyourtravel-base-font',
			'bookyourtravel-font-icon',
			'twb-open-sans',
		);
		foreach ( $style->deps as $dep ) {
			if ( drgf_strposa( $dep, $strings ) === true ) {
				$wp_styles->remove( $dep );
				$wp_styles->add( $dep, '' );
			}
		}
	}

	remove_action( 'wp_head', 'hu_print_gfont_head_link', 2 );
	remove_action( 'wp_head', 'appointment_load_google_font' );
	remove_action( 'wp_head', 'aca_pre_load_fonts' );
}

add_action( 'wp_enqueue_scripts', 'drgf_dequeue_fonts', PHP_INT_MAX );
add_action( 'wp_print_styles', 'drgf_dequeue_fonts', PHP_INT_MAX );

/**
 * Dequeue Google Fonts loaded by Elementor.
 */
add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );

/**
 * Dequeue Google Fonts loaded by Beaver Builder.
 */
add_filter(
	'fl_builder_google_fonts_pre_enqueue',
	function ( $fonts ) {
		return array();
	}
);

/**
 * Dequeue Google Fonts loaded by JupiterX theme.
 */
add_filter(
	'jupiterx_register_fonts',
	function ( $fonts ) {
		return array();
	},
	99999
);

/**
 * Dequeue Google Fonts loaded by the Hustle plugin.
 */
add_filter( 'hustle_load_google_fonts', '__return_false' );

/**
 * Dequeue Google Fonts loaded by the Vantage theme.
 */
add_filter( 'vantage_import_google_fonts', '__return_false' );

/**
 * Dequeue Google Fonts loaded by the Hustle plugin.
 */
add_filter( 'mailpoet_display_custom_fonts', '__return_false' );

if ( ! function_exists( 'apollo13framework_get_web_fonts_dynamic' ) ) {
	/**
	 * Dequeue Google Fonts loaded by the Apollo13 Themes Framework.
	 */
	function apollo13framework_get_web_fonts_dynamic() {
		return;
	}
}

if ( ! function_exists( 'apollo13framework_get_web_fonts_static' ) ) {
	/**
	 * Dequeue Google Fonts loaded by the Apollo13 Themes Framework.
	 */
	function apollo13framework_get_web_fonts_static() {
		return;
	}
}

if ( ! function_exists( 'hemingway_get_google_fonts_url' ) ) {
	/**
	 * Dequeue Google Fonts loaded by the Hemingway theme.
	 */
	function hemingway_get_google_fonts_url() {
		return false;
	}
}

/**
 * Dequeue Google Fonts loaded by the Avia framework (Enfold theme).
 */
function drgf_enfold_customization_switch_fonts() {
	if ( class_exists( 'avia_style_generator' ) ) {
		global $avia;
		$avia->style->print_extra_output = false;
	}
}
add_action( 'init', 'drgf_enfold_customization_switch_fonts' );

/**
 * Remove the preconnect hint to fonts.gstatic.com.
 */
function drgf_remove_divi_preconnect() {
	remove_action( 'wp_enqueue_scripts', 'et_builder_preconnect_google_fonts', 9 );
}
add_action( 'init', 'drgf_remove_divi_preconnect' );

/**
 * Dequeue Google Fonts loaded by Avada theme.
 */
if ( class_exists( 'Avada' ) || function_exists( 'fusion_reset_all_caches' ) ) {
	$fusion_options = get_option( 'fusion_options', false );
	if (
			$fusion_options
			&& isset( $fusion_options['gfonts_load_method'] )
			&& $fusion_options['gfonts_load_method'] === 'cdn'
		) {
		add_filter(
			'fusion_google_fonts',
			function ( $fonts ) {
				return array();
			},
			99999
		);
	}
}

/**
 * Avada caches the CSS output so we need to clear the
 * cache once the fonts have been removed.
 */
function drgf_flush_avada_cache() {
	if ( function_exists( 'fusion_reset_all_caches' ) ) {
		fusion_reset_all_caches();
	}
}
register_activation_hook( __FILE__, 'drgf_flush_avada_cache' );

/**
 * WPBakery enqueues fonts correctly using wp_enqueue_style
 * but does it late so this is required.
 */
function drgf_dequeue_wpbakery_fonts() {
	global $wp_styles;

	if ( ! ( $wp_styles instanceof WP_Styles ) ) {
		return;
	}

	$allowed = apply_filters( 'drgf_exceptions', array() );

	foreach ( $wp_styles->registered as $style ) {
		$handle = $style->handle;
		$src    = $style->src;

		if ( strpos( $src, 'fonts.googleapis' ) !== false ) {
			if ( ! array_key_exists( $handle, array_flip( $allowed ) ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}
}
add_action( 'wp_footer', 'drgf_dequeue_wpbakery_fonts' );

/**
 * Dequeue Google Fonts loaded by Kadence theme.
 */
add_filter( 'kadence_theme_google_fonts_array', '__return_empty_array' );
add_filter( 'kadence_print_google_fonts', '__return_false' );

/**
 * Dequeue Google Fonts loaded by X theme.
 */
add_filter( 'cs_load_google_fonts', '__return_false' );

/**
 * Helper function to run strpos() using an array as the needle.
 *
 * @param string $haystack The string to search in.
 * @param array  $needles  Array of strings to search for.
 * @param int    $offset   Optional. Start position of search. Default 0.
 * @return bool True if any needle is found, false otherwise.
 */
function drgf_strposa( $haystack, $needles, $offset = 0 ) {
	foreach ( $needles as $needle ) {
		$res = strpos( $haystack, $needle, $offset );
		if ( $res !== false ) {
			return true;
		}
	}

	return false;
}

/**
 * Dequeue Google Fonts loaded by Unyson.
 */
function drgf_remove_unyson_fonts() {
	remove_action( 'wp_enqueue_scripts', array( 'Artey_Unyson_Google_Fonts', 'output_url' ), 9999 );
};
add_action( 'init', 'drgf_remove_unyson_fonts' );

/**
 * Dequeue Google Fonts loaded in wp-admin by the Sucuri plugin.
 */
function drgf_remove_sucuri_admin_fonts() {
	wp_dequeue_style( 'sucuriscan-google-fonts' );
}
add_action( 'admin_enqueue_scripts', 'drgf_remove_sucuri_admin_fonts' );

/**
 * Dequeue Google Fonts loaded by Kadence Blocks.
 */
add_filter( 'kadence_blocks_print_google_fonts', '__return_false' );

/**
 * Dequeue Google Fonts loaded in GeneratePress.
 */
function drgf_remove_generatepress_fonts() {
	wp_dequeue_style( 'generate-google-fonts' );
}
add_action( 'wp_enqueue_scripts', 'drgf_remove_generatepress_fonts', 99 );

/**
 * Dequeue Google Fonts loaded by Ajax Search lite.
 */
add_filter( 'asl_custom_fonts', '__return_empty_array' );
add_filter( 'asp_custom_fonts', '__return_empty_array' );

/**
 * Dequeue Google Fonts loaded in GeneratePress.
 */
function drgf_remove_artale_fonts() {
	wp_dequeue_script( 'webfont-loader' );
}
add_action( 'wp_head', 'drgf_remove_artale_fonts', 9999 );

/**
 * Disable Google Fonts in Redux.
 */
add_action(
	'redux/loaded',
	function ( $redux ) {
		$redux->args['async_typography'] = false;
	}
);

add_action( 'plugins_loaded', 'drgf_after_plugins_loaded', 9999 );

/**
 * Run this code after all plugins have been loaded.
 */
function drgf_after_plugins_loaded() {
	/**
	 * Dequeue Google Fonts loaded by the GroovyMenu plugin.
	 */
	remove_action( 'wp_head', 'groovy_menu_add_gfonts_from_pre_storage' );
}

/**
 * Dequeue Google Fonts loaded by Stackable.
 */
add_filter( 'stackable_enqueue_font', '__return_false' );

/**
 * Whether the frontend HTML output buffer should run.
 *
 * @return bool
 */
function drgf_should_filter_output() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	return true;
}

/**
 * Start output buffering so inline Google Font CSS can be stripped.
 */
function drgf_start_output_buffer() {
	if ( ! drgf_should_filter_output() ) {
		return;
	}

	ob_start( 'drgf_filter_google_fonts_from_html' );
}
add_action( 'template_redirect', 'drgf_start_output_buffer', 0 );

/**
 * Strip Google Fonts from the full page HTML.
 *
 * @param string $html Page HTML.
 * @return string
 */
function drgf_filter_google_fonts_from_html( $html ) {
	if ( empty( $html ) || false === stripos( $html, 'font' ) ) {
		return $html;
	}

	$allowed = apply_filters( 'drgf_exceptions', array() );

	$html = preg_replace_callback(
		'/<link[^>]*href=["\'][^"\']*fonts\.(googleapis|gstatic)\.com[^"\']*["\'][^>]*>\s*/i',
		function ( $match ) use ( $allowed ) {
			if ( drgf_tag_matches_allowed_handle( $match[0], $allowed ) ) {
				return $match[0];
			}
			return '';
		},
		$html
	);

	$html = preg_replace_callback(
		'/<style\b([^>]*)>(.*?)<\/style>/is',
		function ( $matches ) use ( $allowed ) {
			if ( drgf_tag_matches_allowed_handle( $matches[0], $allowed ) ) {
				return $matches[0];
			}
			return drgf_strip_google_fonts_from_style_tag( $matches );
		},
		$html
	);

	return $html;
}

/**
 * Check if an HTML tag's id attribute matches an allowed handle.
 *
 * WordPress outputs enqueued styles with id="handle-css" or id="handle-inline-css".
 *
 * @param string $tag     The full HTML tag.
 * @param array  $allowed Allowed handle names.
 * @return bool
 */
function drgf_tag_matches_allowed_handle( $tag, $allowed ) {
	if ( ! preg_match( '/id=["\']([^"\']+)["\']/', $tag, $id_match ) ) {
		return false;
	}

	$id = $id_match[1];

	foreach ( $allowed as $handle ) {
		if ( $id === $handle . '-css' || $id === $handle . '-inline-css' ) {
			return true;
		}
	}

	return false;
}

/**
 * Remove Google Font rules from an inline <style> tag.
 *
 * @param array $matches Regex matches.
 * @return string
 */
function drgf_strip_google_fonts_from_style_tag( $matches ) {
	$attrs    = $matches[1];
	$original = $matches[2];
	$css      = drgf_strip_google_fonts_from_css( $original );

	if ( $css === $original ) {
		return $matches[0];
	}

	$without_comments = preg_replace( '/\/\*.*?\*\//s', '', $css );
	if ( '' === trim( $without_comments ) ) {
		return '';
	}

	return '<style' . $attrs . '>' . $css . '</style>';
}

/**
 * Remove Google Font @font-face and @import rules from CSS.
 *
 * @param string $css CSS content.
 * @return string
 */
function drgf_strip_google_fonts_from_css( $css ) {
	if ( ! function_exists( 'drgf_contains_google_font_reference' ) || ! drgf_contains_google_font_reference( $css ) ) {
		return $css;
	}

	$css = preg_replace_callback(
		'/@font-face\s*\{[^}]*\}/is',
		function ( $match ) {
			return drgf_contains_google_font_reference( $match[0] ) ? '' : $match[0];
		},
		$css
	);

	$css = preg_replace(
		'/@import\s+(?:url\(\s*)?["\']?[^;"\']*fonts\.(googleapis|gstatic)\.com[^;"\']*["\']?\s*\)?[^;]*;?/i',
		'',
		$css
	);

	$css = preg_replace( '/^\s*\/\*[^*]*\*\/\s*$/m', '', $css );

	return trim( preg_replace( '/\n{3,}/', "\n\n", $css ) );
}