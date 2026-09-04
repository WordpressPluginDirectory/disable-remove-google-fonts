/* global ajaxurl, drgfNotice, drgfAudit */
jQuery( document ).ready( function( $ ) {

	// Dismiss notice handler (unchanged).
	$( document ).on(
		'click',
		'.notice-dismiss-drgf .notice-dismiss',
		function() {
			var type = $( this ).closest( '.notice-dismiss-drgf' ).data( 'notice' );
			$.ajax( ajaxurl, {
				type: 'POST',
				data: {
					action: 'drgf_dismiss_notice',
					type: type,
					nonce: typeof drgfNotice !== 'undefined' ? drgfNotice.nonce : '',
				},
			} );
		}
	);

	// Font Audit — Re-scan button.
	$( '#drgf-rescan' ).on( 'click', function() {
		var $btn      = $( this );
		var $audit    = $( '#drgf-audit' );
		var $results  = $( '#drgf-audit-results' );
		var $icon     = $btn.find( '.drgf-audit__rescan-icon' );
		var scanUrl   = $audit.data( 'scan-url' ) || drgfAudit.homeUrl;

		$btn.prop( 'disabled', true );
		$icon.addClass( 'drgf-spin' );

		$results.html(
			'<div class="drgf-audit__loading">' +
				'<span class="spinner is-active"></span>' +
				'<span>' + escHtml( drgfAudit.i18n.scanning ) + '</span>' +
			'</div>'
		);

		function sendAuditRequest( htmlContent ) {
			var postData = {
				action: 'drgf_run_audit',
				nonce: drgfAudit.nonce,
				scan_url: scanUrl
			};
			if ( htmlContent ) {
				postData.html = htmlContent;
			}

			$.ajax( {
				url: drgfAudit.ajaxurl,
				type: 'POST',
				data: postData,
				timeout: 30000,
			} )
			.done( function( response ) {
				if ( ! response.success ) {
					$results.html( buildError() );
					return;
				}

				var data  = response.data;
				var fonts = data.fonts || [];

				if ( data.scanned_url ) {
					$audit.data( 'scan-url', data.scanned_url );
				}

				// Update the meta line.
				if ( data.scanned_at ) {
					var metaText = '';
					var d = new Date( data.scanned_at.replace( ' ', 'T' ) );
					if ( ! isNaN( d ) ) {
						metaText = d.toLocaleDateString() + ' \u00b7 ' + fonts.length + ' ' + drgfAudit.i18n.fontFamilies;

						var displayUrl = data.scanned_url || drgfAudit.homeUrl;
						var truncatedUrl = displayUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
						if (truncatedUrl.length > 50) {
							truncatedUrl = truncatedUrl.substring(0, 47) + '...';
						}
					}
					var $meta = $( '.drgf-audit__meta' );
					var urlLine = '<span class="drgf-audit__meta"><a href="' + escHtml(displayUrl) + '" target="_blank">' + escHtml(truncatedUrl) + '</a></span>';
					if ( $meta.length ) {
						$meta.first().html( escHtml( drgfAudit.i18n.lastScanned ) + ' ' + metaText );
						if ( $meta.length > 1 ) {
							$meta.last().replaceWith( urlLine );
						} else {
							$meta.after( urlLine );
						}
					} else {
						$( '.drgf-audit__header-left' ).append(
							'<span class="drgf-audit__meta">' + escHtml( drgfAudit.i18n.lastScanned ) + ' ' + metaText + '</span>' + urlLine
						);
					}
				}

				if ( data.error && data.error.indexOf( 'loopback_failed' ) === 0 ) {
					$results.html( buildError( data.error ) );
					return;
				}

				if ( fonts.length === 0 ) {
					$results.html( buildEmpty() );
					return;
				}

				$results.html( buildFontCards( fonts ) );
				var removedFonts = fonts.filter( function( f ) { return f.removable !== false; } );
				loadPreviewFonts( removedFonts );
			} )
			.fail( function() {
				$results.html( buildError() );
			} )
			.always( function() {
				$btn.prop( 'disabled', false );
				$icon.removeClass( 'drgf-spin' );
			} );
		}

		// First, try client-side fetch (extremely reliable on local environments and avoids loopback deadlocks)
		var fetchUrl = scanUrl;
		if ( drgfAudit.scanToken ) {
			fetchUrl = fetchUrl + ( fetchUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'drgf_scan=' + encodeURIComponent( drgfAudit.scanToken );
		}

		fetch( fetchUrl )
			.then( function( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP Status ' + r.status );
				}
				return r.text();
			} )
			.then( function( html ) {
				sendAuditRequest( html );
			} )
			.catch( function( err ) {
				console.log( 'Client-side fetch failed, falling back to loopback request:', err );
				sendAuditRequest( '' );
			} );
	} );

	function buildFontCards( fonts ) {
		var removed = fonts.filter( function( f ) { return f.removable !== false; } );
		var unremoved = fonts.filter( function( f ) { return f.removable === false; } );
		var html = '';

		if ( removed.length ) {
			html += buildProCta( removed );
		}

		if ( unremoved.length ) {
			html += buildUnremovedWarning( unremoved );
		}

		return html;
	}

	function buildUnremovedWarning( fonts ) {
		var html = '<div class="drgf-audit__unremoved">';
		html += '<p><strong>' + escHtml( fonts.length + ' ' + drgfAudit.i18n.couldNotRemove ) + '</strong></p>';
		html += '<p>' + escHtml( drgfAudit.i18n.unremovedDesc ) + '</p>';
		html += '<ul>';
		fonts.forEach( function( f ) {
			html += '<li>' + escHtml( f.name );
			if ( f.handle ) {
				html += ' <span class="drgf-audit__unremoved-source">&mdash; ' + escHtml( f.handle ) + '</span>';
			}
			html += '</li>';
		} );
		html += '</ul>';
		var fontNames = fonts.map( function( f ) { return f.name; } );
		var mailSubject = encodeURIComponent( 'Help removing Google Fonts' );
		var mailBody = encodeURIComponent( "Hi,\n\nI'm using the Disable & Remove Google Fonts plugin on " + drgfAudit.homeUrl + " and the following fonts could not be removed:\n\n- " + fontNames.join( "\n- " ) + "\n\nCould you help me remove them?\n\nThanks" );
		html += '<p>' + escHtml( drgfAudit.i18n.emailUsPrefix ) + ' <a href="mailto:team@fontsplugin.com?subject=' + mailSubject + '&body=' + mailBody + '">' + escHtml( drgfAudit.i18n.emailUsLink ) + '</a>.</p>';
		html += '</div>';
		return html;
	}

	function buildProCta( fonts ) {
		var names = fonts.map( function( f ) { return f.name; } );
		var fontList;

		if ( names.length > 2 ) {
			fontList = escHtml( names.slice( 0, -1 ).join( ', ' ) ) + ' ' + escHtml( drgfAudit.i18n.and ) + ' ' + escHtml( names[ names.length - 1 ] );
		} else if ( names.length === 2 ) {
			fontList = escHtml( names[0] ) + ' ' + escHtml( drgfAudit.i18n.and ) + ' ' + escHtml( names[1] );
		} else {
			fontList = escHtml( names[0] );
		}

		var desc = drgfAudit.i18n.proDesc
			.replace( '%1$s', '<strong>' + fontList + '</strong>' )
			.replace( '%2$s', '<strong>' + escHtml( drgfAudit.i18n.siteDomain ) + '</strong>' );

		var previewFonts = fonts;

		// Before / after preview.
		var html = '<div class="drgf-audit__pro-preview">';
		html += '<div class="drgf-audit__pro-preview-row drgf-audit__pro-preview-row--header">';
		html += '<span class="drgf-audit__pro-preview-label">' + escHtml( drgfAudit.i18n.proYourFonts ) + '</span>';
		html += '<span class="drgf-audit__pro-preview-label">' + escHtml( drgfAudit.i18n.proVisitorsSee ) + '</span>';
		html += '</div>';
		previewFonts.forEach( function( f ) {
			html += '<div class="drgf-audit__pro-preview-row">';
			html += '<p class="drgf-audit__pro-preview-text" style="font-family:\'' + escAttr( f.name ) + '\',sans-serif;">' + escHtml( f.name ) + ' — The quick brown fox jumps over the lazy dog</p>';
			html += '<p class="drgf-audit__pro-preview-text drgf-audit__pro-preview-text--fallback">' + escHtml( f.name ) + ' — The quick brown fox jumps over the lazy dog</p>';
			html += '</div>';
		} );
		html += '</div>';

		// CTA.
		html += '<div class="drgf-audit__pro-cta">';
		html += '<div class="drgf-audit__pro-body">';
		html += '<h3 class="drgf-audit__pro-heading">' + escHtml( drgfAudit.i18n.proHeading ) + '</h3>';
		html += '<p class="drgf-audit__pro-desc">' + desc + '</p>';
		html += '<div class="drgf-audit__pro-actions">';
		html += '<a class="button button-primary drgf-audit__pro-button" href="https://fontsplugin.com/drgf-upgrade/" target="_blank">' + escHtml( drgfAudit.i18n.proButton ) + ' →</a>';
		html += '<span class="drgf-audit__pro-proof">' + escHtml( drgfAudit.i18n.proProof ) + '</span>';
		html += '</div></div>';
		html += '</div>';
		return html;
	}

	function buildEmpty() {
		return '<div class="drgf-audit__status drgf-audit__status--success">' +
			'<span class="dashicons dashicons-yes-alt"></span>' +
			'<div>' +
			'<p class="drgf-audit__status-title">' + escHtml( drgfAudit.i18n.noFontsTitle ) + '</p>' +
			'<p class="drgf-audit__status-desc">' + escHtml( drgfAudit.i18n.noFontsDesc ) + '</p>' +
			'</div></div>';
	}

	function buildError( errorMsg ) {
		var errorHtml = '';
		if ( errorMsg && errorMsg.indexOf( 'loopback_failed: ' ) === 0 ) {
			var details = errorMsg.substring( 17 );
			errorHtml = '<p class="drgf-audit__error-details" style="margin-top: 10px; font-size: 11px; opacity: 0.8; font-family: monospace;">' + escHtml( details ) + '</p>';
		}
		return '<div class="drgf-audit__status drgf-audit__status--warning">' +
			'<span class="dashicons dashicons-warning"></span>' +
			'<div>' +
			'<p class="drgf-audit__status-title">' + escHtml( drgfAudit.i18n.errorTitle ) + '</p>' +
			'<p class="drgf-audit__status-desc">' + drgfAudit.i18n.errorDesc + '</p>' +
			errorHtml +
			'</div></div>';
	}

	function loadPreviewFonts( fonts ) {
		var families = [];
		fonts.forEach( function( font ) {
			var name = font.name.replace( / /g, '+' );
			var weights = ( font.weights || [ '400' ] ).join( ';' );
			families.push( 'family=' + name + ':wght@' + weights );
		} );

		if ( families.length ) {
			var link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = 'https://fonts.googleapis.com/css2?' + families.join( '&' ) + '&display=swap';
			document.head.appendChild( link );
		}
	}

	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function escAttr( str ) {
		return escHtml( str ).replace( /'/g, '&#039;' );
	}

} );
