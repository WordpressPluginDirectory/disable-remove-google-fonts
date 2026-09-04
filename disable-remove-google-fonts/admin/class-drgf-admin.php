<?php
/**
 * DRGF Admin Page.
 *
 * @package disable-remove-google-fonts
 */

/**
 * Create the admin pages.
 */
class DRGF_Admin {

	/**
	 * Start up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 10 );
		add_action( 'admin_init', array( $this, 'admin_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );
		add_action( 'wp_ajax_drgf_run_audit', array( $this, 'ajax_run_audit' ) );
	}

	/**
	 * Redirect to the welcome page after activation.
	 */
	public function admin_redirect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'drgf_do_activation_redirect', false ) ) {
			delete_option( 'drgf_do_activation_redirect' );
			if ( ! isset( $_GET['activate-multi'] ) && ! is_network_admin() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=drgf' ) );
				exit;
			}
		}
	}

	/**
	 * Register the top-level admin menu page.
	 */
	public function add_menu() {
		$audit = drgf_get_font_audit();
		$count = count( $audit['fonts'] );

		$menu_title = __( 'Font Audit', 'disable-remove-google-fonts' );
		if ( $count > 0 ) {
			$menu_title .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>',
				$count
			);
		}

		add_menu_page(
			__( 'Google Fonts Audit', 'disable-remove-google-fonts' ),
			$menu_title,
			'manage_options',
			'drgf',
			array( $this, 'render_welcome_page' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a0a5aa" d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/></svg>' ),
			59
		);
	}

	/**
	 * Enqueue admin assets on the plugin page only.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'toplevel_page_drgf' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'drgf-admin', esc_url( DRGF_DIR_URL . 'admin/style.css' ), array(), DRGF_VERSION );
		wp_enqueue_script( 'drgf-admin', esc_url( DRGF_DIR_URL . 'admin/scripts.js' ), array( 'jquery' ), DRGF_VERSION, true );

		wp_localize_script(
			'drgf-admin',
			'drgfAudit',
			array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'drgf_font_audit' ),
				'homeUrl'   => home_url( '/' ),
				'scanToken' => drgf_generate_scan_token(),
				'i18n'      => array(
					'scanning'     => __( 'Scanning your site for Google Fonts…', 'disable-remove-google-fonts' ),
					'source'       => __( 'Source', 'disable-remove-google-fonts' ),
					'noFontsTitle' => __( 'No Google Fonts detected', 'disable-remove-google-fonts' ),
					'noFontsDesc'  => __( 'Your site is not loading any fonts from Google\'s servers.', 'disable-remove-google-fonts' ),
					'errorTitle'   => __( 'Scan couldn\'t complete', 'disable-remove-google-fonts' ),
					'errorDesc'    => sprintf(
						/* translators: %s: link to online checker */
						__( 'Your server may block loopback requests. Try clicking Re-scan, or use our %s instead.', 'disable-remove-google-fonts' ),
						'<a href="https://fontsplugin.com/google-fonts-checker/" target="_blank">' . __( 'online Google Fonts checker', 'disable-remove-google-fonts' ) . '</a>'
					),
					'lastScanned'  => __( 'Last scanned:', 'disable-remove-google-fonts' ),
					'fontFamilies' => __( 'font families detected', 'disable-remove-google-fonts' ),
					'and'          => __( 'and', 'disable-remove-google-fonts' ),
					'siteDomain'   => preg_replace( '(^https?://)', '', site_url( '', 'https' ) ),
					'proHeading'   => __( 'Keep these fonts — host them locally', 'disable-remove-google-fonts' ),
					/* translators: %1$s: font names, %2$s: site domain */
					'proDesc'      => __( 'Your site was using %1$s. Serve them from %2$s instead — faster page loads, no third-party requests, and fully GDPR & DSGVO compliant.', 'disable-remove-google-fonts' ),
					'proButton'    => __( 'Host Fonts Locally', 'disable-remove-google-fonts' ),
					'proYourFonts' => __( 'Your chosen fonts', 'disable-remove-google-fonts' ),
					'proVisitorsSee' => __( 'What visitors see now', 'disable-remove-google-fonts' ),
					'proProof'     => __( 'One-click setup · No coding required', 'disable-remove-google-fonts' ),
					/* translators: %d: number of font families (replaced in JS) */
					'couldNotRemove' => __( 'font families could not be removed', 'disable-remove-google-fonts' ),
					'unremovedDesc'  => __( 'These fonts are loaded from within CSS files and cannot be removed by this plugin.', 'disable-remove-google-fonts' ),
					'emailUsPrefix'  => __( 'Need help? Our team can remove these for you — just', 'disable-remove-google-fonts' ),
					'emailUsLink'    => __( 'drop us an email', 'disable-remove-google-fonts' ),
				),
			)
		);

		// Load removed fonts from Google for the preview cards.
		$audit = drgf_get_font_audit();
		$preview_fonts = array_filter( $audit['fonts'], function( $f ) {
			return ! isset( $f['removable'] ) || $f['removable'];
		} );
		if ( ! empty( $preview_fonts ) ) {
			$preview_url = drgf_build_preview_url( $preview_fonts );
			if ( $preview_url ) {
				wp_enqueue_style( 'drgf-font-preview', $preview_url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			}
		}
	}

	/**
	 * Ensure Dashicons are available for the admin bar icon on the frontend.
	 */
	public function enqueue_admin_bar() {
		if ( is_admin() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Add admin bar menu item linking to the Font Audit page.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show on public-facing pages (not admin pages).
		if ( is_admin() ) {
			return;
		}

		$current_url = drgf_get_current_page_url();

		$wp_admin_bar->add_menu(
			array(
				'id'    => 'drgf-check-fonts',
				'title' => sprintf(
					'<span class="ab-icon dashicons dashicons-search" aria-hidden="true" style="margin-top: 2px; margin-right: 3px;"></span> %s',
					esc_html__( 'Check Google Fonts', 'disable-remove-google-fonts' )
				),
				'href'  => admin_url(
					'admin.php?page=drgf&drgf_rescan=1&drgf_scan_url=' . rawurlencode( $current_url )
				),
				'meta'  => array(
					'target' => '_blank',
				),
			)
		);
	}

	/**
	 * AJAX handler — run the font audit.
	 */
	public function ajax_run_audit() {
		check_ajax_referer( 'drgf_font_audit', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'disable-remove-google-fonts' ) ) );
		}

		$scan_url = isset( $_POST['scan_url'] ) ? drgf_validate_scan_url( wp_unslash( $_POST['scan_url'] ) ) : '';
		$html     = isset( $_POST['html'] ) ? wp_unslash( $_POST['html'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = drgf_run_font_audit( $scan_url, $html );

		wp_send_json_success( $result );
	}

	/**
	 * Render the main admin / welcome page.
	 */
	public function render_welcome_page() {
		update_option( 'dismissed-drgf-welcome', true );

		$site_url = site_url( '', 'https' );
		$url      = preg_replace( '(^https?://)', '', $site_url );

		$audit          = drgf_get_font_audit();
		$needs_scan     = get_transient( 'drgf_needs_initial_scan' );
		$has_results    = ! empty( $audit['scanned_at'] );
		$force_rescan   = isset( $_GET['drgf_rescan'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$trigger_scan   = $force_rescan || ( $needs_scan && ! $has_results );
		$scan_url       = '';

		if ( isset( $_GET['drgf_scan_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$scan_url = drgf_validate_scan_url( wp_unslash( $_GET['drgf_scan_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( ! empty( $audit['scanned_url'] ) ) {
			$scan_url = $audit['scanned_url'];
		}

		if ( $trigger_scan ) {
			delete_transient( 'drgf_needs_initial_scan' );
		}
		?>
		<style>.notice { display: none; }</style>

		<div class="drgf-admin__wrap">
			<div class="drgf-admin__content">
				<div class="drgf-admin__content__header">
					<h1><?php esc_html_e( 'Google Fonts Audit', 'disable-remove-google-fonts' ); ?></h1>
				</div>
				<div class="drgf-admin__content__inner">
					<?php
					$removed_count = 0;
					foreach ( $audit['fonts'] as $f ) {
						if ( ! isset( $f['removable'] ) || $f['removable'] ) {
							$removed_count++;
						}
					}
					?>
					<div class="drgf-admin__success">
						<?php if ( $removed_count > 0 ) : ?>
							<p>&#x2705; <?php printf( esc_html__( 'The plugin is active and removing %d Google Font families from your site.', 'disable-remove-google-fonts' ), $removed_count ); ?></p>
						<?php elseif ( ! empty( $audit['fonts'] ) ) : ?>
							<p>&#x2705; <?php esc_html_e( 'The plugin is active and working to remove Google Fonts from your site.', 'disable-remove-google-fonts' ); ?></p>
						<?php else : ?>
							<p>&#x2705; <?php esc_html_e( 'The plugin is active and will automatically remove any Google Fonts from your site.', 'disable-remove-google-fonts' ); ?></p>
						<?php endif; ?>
					</div>

					<h3><?php esc_html_e( 'How This Plugin Works', 'disable-remove-google-fonts' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: link to web safe fonts article */
							esc_html__( 'This plugin completely removes all references to Google Fonts from your website. That means that your website will no longer render Google Fonts and will instead revert to a %s.', 'disable-remove-google-fonts' ),
							'<a target="_blank" href="https://fontsplugin.com/web-safe-system-fonts/">' . esc_html__( 'fallback font', 'disable-remove-google-fonts' ) . '</a>'
						);
						?>
					</p>

					<?php $this->render_font_audit( $audit, $trigger_scan, $scan_url ); ?>

					<p class="drgf-admin__note">
						<?php
						printf(
							/* translators: %s: link to article about YouTube etc. */
							esc_html__( 'Note: Some services load Google Fonts within an embedded iFrame. These include YouTube, Google Maps and ReCaptcha. It\'s not possible for this plugin to remove those services for the reasons %s.', 'disable-remove-google-fonts' ),
							'<a target="_blank" href="https://fontsplugin.com/remove-disable-google-fonts/#youtube">' . esc_html__( 'outlined here', 'disable-remove-google-fonts' ) . '</a>'
						);
						?>
					</p>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the font audit section.
	 *
	 * @param array  $audit        Stored audit data.
	 * @param bool   $trigger_scan Whether to auto-trigger a scan via JS.
	 * @param string $scan_url     URL to scan (empty = homepage).
	 */
	private function render_font_audit( $audit, $trigger_scan, $scan_url = '' ) {
		$fonts      = $audit['fonts'];
		$scanned_at = $audit['scanned_at'];
		$error      = isset( $audit['error'] ) ? $audit['error'] : '';
		?>
		<div class="drgf-audit" id="drgf-audit"<?php echo $scan_url ? ' data-scan-url="' . esc_attr( $scan_url ) . '"' : ''; ?>>
			<div class="drgf-audit__header">
				<div class="drgf-audit__header-left">
					<h3 class="drgf-audit__title"><?php esc_html_e( 'Font audit', 'disable-remove-google-fonts' ); ?></h3>
					<?php if ( $scanned_at && ! $trigger_scan ) : ?>
						<span class="drgf-audit__meta">
							<?php
							$display_url = ! empty( $audit['scanned_url'] ) ? $audit['scanned_url'] : home_url( '/' );
							echo wp_kses_post(
								sprintf(
									/* translators: %1$s: date, %2$d: number of fonts */
									__( 'Last scanned: %1$s &middot; %2$d font families detected', 'disable-remove-google-fonts' ),
									esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scanned_at ) ) ),
									count( $fonts )
								)
							);
							?>
						</span>
						<span class="drgf-audit__meta">
							<a href="<?php echo esc_url( $display_url ); ?>" target="_blank"><?php echo esc_html( drgf_truncate_url( $display_url ) ); ?></a>
						</span>
					<?php endif; ?>
				</div>
				<button type="button" class="button drgf-audit__rescan" id="drgf-rescan">
					<svg class="drgf-audit__rescan-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
					<?php esc_html_e( 'Re-scan', 'disable-remove-google-fonts' ); ?>
				</button>
			</div>

			<div id="drgf-audit-results">
				<?php if ( $trigger_scan ) : ?>
					<div class="drgf-audit__loading" id="drgf-audit-loading">
						<span class="spinner is-active"></span>
						<span><?php esc_html_e( 'Scanning your site for Google Fonts…', 'disable-remove-google-fonts' ); ?></span>
					</div>
				<?php elseif ( $error && strpos( $error, 'loopback_failed' ) === 0 ) : ?>
					<?php $this->render_audit_error( $error ); ?>
				<?php elseif ( ! empty( $fonts ) ) : ?>
					<?php $this->render_audit_fonts( $fonts ); ?>
				<?php elseif ( $scanned_at ) : ?>
					<?php $this->render_audit_empty(); ?>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $trigger_scan ) : ?>
			<script>
			jQuery( document ).ready( function( $ ) {
				$( '#drgf-rescan' ).trigger( 'click' );
			} );
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the font cards when fonts were detected.
	 *
	 * @param array $fonts Font entries.
	 */
	private function render_audit_fonts( $fonts ) {
		$removed   = array_values( array_filter( $fonts, function( $f ) {
			return ! isset( $f['removable'] ) || $f['removable'];
		} ) );
		$unremoved = array_values( array_filter( $fonts, function( $f ) {
			return isset( $f['removable'] ) && ! $f['removable'];
		} ) );

		if ( ! empty( $removed ) ) {
			$this->render_pro_cta( $removed );
		}

		if ( ! empty( $unremoved ) ) {
			$this->render_unremoved_fonts( $unremoved );
		}
	}

	/**
	 * Render the Pro upsell CTA with personalized font preview.
	 *
	 * @param array $fonts Detected font entries.
	 */
	private function render_pro_cta( $fonts ) {
		$font_names   = wp_list_pluck( $fonts, 'name' );
		$preview_fonts = $fonts;
		$site_url     = site_url( '', 'https' );
		$domain   = preg_replace( '(^https?://)', '', $site_url );

		if ( count( $font_names ) > 2 ) {
			$font_list = sprintf(
				/* translators: %1$s: comma-separated font names, %2$s: last font name */
				esc_html__( '%1$s and %2$s', 'disable-remove-google-fonts' ),
				esc_html( implode( ', ', array_slice( $font_names, 0, -1 ) ) ),
				esc_html( end( $font_names ) )
			);
		} elseif ( count( $font_names ) === 2 ) {
			$font_list = sprintf(
				/* translators: %1$s: first font, %2$s: second font */
				esc_html__( '%1$s and %2$s', 'disable-remove-google-fonts' ),
				esc_html( $font_names[0] ),
				esc_html( $font_names[1] )
			);
		} else {
			$font_list = esc_html( $font_names[0] );
		}
		?>
		<div class="drgf-audit__pro-preview">
			<div class="drgf-audit__pro-preview-row drgf-audit__pro-preview-row--header">
				<span class="drgf-audit__pro-preview-label"><?php esc_html_e( 'Your chosen fonts', 'disable-remove-google-fonts' ); ?></span>
				<span class="drgf-audit__pro-preview-label"><?php esc_html_e( 'What visitors see now', 'disable-remove-google-fonts' ); ?></span>
			</div>
			<?php foreach ( $preview_fonts as $pf ) : ?>
				<div class="drgf-audit__pro-preview-row">
					<p class="drgf-audit__pro-preview-text" style="font-family: '<?php echo esc_attr( $pf['name'] ); ?>', sans-serif;">
						<?php echo esc_html( $pf['name'] ); ?> — The quick brown fox jumps over the lazy dog
					</p>
					<p class="drgf-audit__pro-preview-text drgf-audit__pro-preview-text--fallback">
						<?php echo esc_html( $pf['name'] ); ?> — The quick brown fox jumps over the lazy dog
					</p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="drgf-audit__pro-cta">
			<div class="drgf-audit__pro-body">
				<h3 class="drgf-audit__pro-heading"><?php esc_html_e( 'Keep these fonts — host them locally', 'disable-remove-google-fonts' ); ?></h3>
				<p class="drgf-audit__pro-desc">
					<?php
					printf(
						/* translators: %1$s: list of font names, %2$s: site domain */
						esc_html__( 'Your site was using %1$s. Serve them from %2$s instead — faster page loads, no third-party requests, and fully GDPR & DSGVO compliant.', 'disable-remove-google-fonts' ),
						'<strong>' . $font_list . '</strong>',
						'<strong>' . esc_html( $domain ) . '</strong>'
					);
					?>
				</p>
				<div class="drgf-audit__pro-actions">
					<a class="button button-primary drgf-audit__pro-button" href="https://fontsplugin.com/drgf-upgrade/" target="_blank">
						<?php esc_html_e( 'Host Fonts Locally', 'disable-remove-google-fonts' ); ?> →
					</a>
					<span class="drgf-audit__pro-proof"><?php esc_html_e( 'One-click setup · No coding required', 'disable-remove-google-fonts' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a warning for fonts that could not be removed.
	 *
	 * @param array $fonts Non-removable font entries.
	 */
	private function render_unremoved_fonts( $fonts ) {
		?>
		<div class="drgf-audit__unremoved">
			<p><strong>
				<?php
				printf(
					/* translators: %d: number of font families */
					esc_html__( '%d font families could not be removed', 'disable-remove-google-fonts' ),
					count( $fonts )
				);
				?>
			</strong></p>
			<p><?php esc_html_e( 'These fonts are loaded from within CSS files and cannot be removed by this plugin.', 'disable-remove-google-fonts' ); ?></p>
			<ul>
				<?php foreach ( $fonts as $font ) : ?>
					<li>
						<?php echo esc_html( $font['name'] ); ?>
						<?php if ( ! empty( $font['handle'] ) ) : ?>
							<span class="drgf-audit__unremoved-source">&mdash; <?php echo esc_html( $font['handle'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
				$font_names = array_map( function( $f ) { return $f['name']; }, $fonts );
				$mailto_subject = rawurlencode( 'Help removing Google Fonts' );
				$mailto_body    = rawurlencode( "Hi,\n\nI'm using the Disable & Remove Google Fonts plugin on " . home_url() . " and the following fonts could not be removed:\n\n- " . implode( "\n- ", $font_names ) . "\n\nCould you help me remove them?\n\nThanks" );
			?>
			<p><?php printf( esc_html__( 'Need help? Our team can remove these for you — just %1$sdrop us an email%2$s.', 'disable-remove-google-fonts' ), '<a href="mailto:team@fontsplugin.com?subject=' . $mailto_subject . '&body=' . $mailto_body . '">', '</a>' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the "no fonts detected" state.
	 */
	private function render_audit_empty() {
		?>
		<div class="drgf-audit__status drgf-audit__status--success">
			<span class="dashicons dashicons-yes-alt"></span>
			<div>
				<p class="drgf-audit__status-title"><?php esc_html_e( 'No Google Fonts detected', 'disable-remove-google-fonts' ); ?></p>
				<p class="drgf-audit__status-desc"><?php esc_html_e( 'Your site is not loading any fonts from Google\'s servers.', 'disable-remove-google-fonts' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the loopback-error state.
	 *
	 * @param string $error Error message.
	 */
	private function render_audit_error( $error = '' ) {
		$error_message = '';
		if ( $error && strpos( $error, 'loopback_failed: ' ) === 0 ) {
			$error_message = substr( $error, 17 );
		}
		?>
		<div class="drgf-audit__status drgf-audit__status--warning">
			<span class="dashicons dashicons-warning"></span>
			<div>
				<p class="drgf-audit__status-title"><?php esc_html_e( 'Scan couldn\'t complete', 'disable-remove-google-fonts' ); ?></p>
				<p class="drgf-audit__status-desc">
					<?php
					printf(
						/* translators: %s: link to online Google Fonts checker */
						esc_html__( 'Your server may block loopback requests. Try clicking Re-scan, or use our %s instead.', 'disable-remove-google-fonts' ),
						'<a href="https://fontsplugin.com/google-fonts-checker/" target="_blank">' . esc_html__( 'online Google Fonts checker', 'disable-remove-google-fonts' ) . '</a>'
					);
					?>
				</p>
				<?php if ( $error_message ) : ?>
					<p class="drgf-audit__error-details" style="margin-top: 10px; font-size: 11px; opacity: 0.8; font-family: monospace;">
						<?php echo esc_html( $error_message ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

if ( is_admin() || is_user_logged_in() ) {
	$drgf_admin = new DRGF_Admin();
}
