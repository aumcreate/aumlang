/**
 * AumLang translation meta box: one-click translate via AJAX.
 *
 * @package AumLang
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $box = $( '.aumlang-metabox' );

		if ( ! $box.length ) {
			return;
		}

		var postId = $box.data( 'post' );
		var nonce = $( '#aumlang_translate_nonce' ).val();

		$box.on( 'click', '.aumlang-translate', function ( e ) {
			e.preventDefault();

			var $button = $( this );
			var $row = $button.closest( '.aumlang-row' );
			var lang = $row.data( 'lang' );
			var $status = $row.find( '.aumlang-status' );

			$button.prop( 'disabled', true );
			$status.text( AumLangMetaBox.translating );

			$.post( AumLangMetaBox.ajaxUrl, {
				action: 'aumlang_translate',
				post: postId,
				lang: lang,
				nonce: nonce
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					var msg = response && response.data ? response.data.message : '';
					$status.text( AumLangMetaBox.errorPrefix + msg );
					return;
				}

				var data = response.data;
				$status.text( data.statusLabel )
					.attr( 'class', 'aumlang-status aumlang-status-' + data.status );
				$button.text( data.button );

				if ( data.editLink ) {
					$row.find( '.aumlang-edit' ).attr( 'href', data.editLink ).show();
				}
			} ).fail( function () {
				$status.text( AumLangMetaBox.errorPrefix );
			} ).always( function () {
				$button.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
