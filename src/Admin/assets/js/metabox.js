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

				// 成功路径上也可能有话要说：翻完了、但页面几乎没变。
				if ( data.notice ) {
					var $n = $row.find( '.aumlang-notice' );
					if ( ! $n.length ) {
						$n = $( '<p class="aumlang-notice description"></p>' ).appendTo( $row );
					}
					$n.text( data.notice ).show();
				}
			/*
			 * 🔴 这个分支不是「服务端说失败了」，是**请求根本没拿到正常响应**：PHP 致命
			 * 错误、500/502、网关超时、连接断开。原来这里只显示 errorPrefix，后面什么都
			 * 不拼 —— 用户看到的就是一句「Translation failed:」加一片空白，而我们手上
			 * 明明有状态码、状态文本和响应体。2026-10-06 一个用户就是这么卡住的：界面
			 * 承诺了原因，却交了空，只能让他开 DevTools 才知道发生了什么。
			 *
			 * 超时单独说，因为它最常见也最可行动：页面太长时整个请求会跑过服务器的时间
			 * 上限，而这和「翻译本身出错」要给的建议完全不同。
			 */
			} ).fail( function ( jqXHR, textStatus ) {
				var why;

				if ( 'timeout' === textStatus ) {
					why = AumLangMetaBox.timeoutFail;
				} else if ( jqXHR && jqXHR.status ) {
					why = AumLangMetaBox.httpFail
						.replace( '%1$d', jqXHR.status )
						.replace( '%2$s', jqXHR.statusText || '' );

					/* 服务端的致命错误往往把原文写在响应体里，第一行通常就够定位。 */
					if ( jqXHR.responseText ) {
						var first = $.trim( $( '<div>' ).html( jqXHR.responseText ).text() ).split( '\n' )[0];
						if ( first ) {
							why += ' ' + first.substring( 0, 300 );
						}
					}
				} else if ( 'abort' === textStatus || 'error' === textStatus ) {
					why = AumLangMetaBox.abortFail;
				} else {
					why = AumLangMetaBox.unknownFail;
				}

				$status.text( AumLangMetaBox.errorPrefix + why );
			} ).always( function () {
				$button.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
