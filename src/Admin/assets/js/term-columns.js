/**
 * AumLang term list one-click translate.
 *
 * @package AumLang
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var cfg = window.AumLangTerms || {};

		$( document ).on( 'click', '.aumlang-term-translate', function () {
			var $button = $( this );

			if ( $button.hasClass( 'is-busy' ) ) {
				return;
			}

			var lang = String( $button.data( 'lang' ) );
			$button.addClass( 'is-busy' ).text( '…' ).attr( 'title', cfg.translating || '' );

			$.post( cfg.ajaxUrl, {
				action: 'aumlang_translate_term',
				nonce: cfg.nonce,
				term: $button.data( 'term' ),
				tax: $button.data( 'tax' ),
				lang: lang
			} ).done( function ( response ) {
				if ( response && response.success ) {
					var $chip = $( '<a></a>' )
						.addClass( 'aumlang-chip aumlang-chip-' + response.data.status )
						.attr( 'href', response.data.edit_url )
						.attr( 'title', response.data.label )
						.text( lang.toUpperCase() );
					$button.replaceWith( $chip );
				} else {
					var msg = response && response.data ? response.data.message : ( cfg.failed || 'error' );
					$button.removeClass( 'is-busy' ).text( lang.toUpperCase() ).attr( 'title', msg );
				}
			} ).fail( function () {
				$button.removeClass( 'is-busy' ).text( lang.toUpperCase() ).attr( 'title', cfg.failed || 'error' );
			} );
		} );
	} );
} )( jQuery );
