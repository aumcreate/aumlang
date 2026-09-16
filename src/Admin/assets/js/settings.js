/**
 * AumLang settings: test the translation provider connection.
 *
 * @package AumLang
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var cfg = window.AumLangSettings || {};

		// Toggle the "how to get an API key" help panel.
		$( '#aumlang-help-toggle' ).on( 'click', function () {
			$( '#aumlang-provider-help' ).prop( 'hidden', function ( i, v ) {
				return ! v;
			} );
		} );

		// Fill endpoint + model from the selected provider. On change: overwrite.
		// On load: only fill blanks, so saved custom values are kept.
		function applyPreset( force ) {
			var sel = document.getElementById( 'aumlang-provider' );
			if ( ! sel ) {
				return;
			}
			var opt = sel.options[ sel.selectedIndex ];
			var $endpoint = $( '#aumlang-endpoint' );
			var $model = $( '#aumlang-model' );

			if ( force || ! $endpoint.val() ) {
				$endpoint.val( opt.getAttribute( 'data-endpoint' ) || '' );
			}
			if ( force || ! $model.val() ) {
				$model.val( opt.getAttribute( 'data-model' ) || '' );
			}
		}

		$( '#aumlang-provider' ).on( 'change', function () {
			applyPreset( true );
		} );

		applyPreset( false );

		// Reachability test across all common providers (no key needed).
		$( '#aumlang-test-reach' ).on( 'click', function () {
			var $button = $( this );
			var $out = $( '#aumlang-reach-results' );

			$button.prop( 'disabled', true );
			$out.html( '<p class="aml-test-result">' + ( cfg.testing || '' ) + '</p>' );

			$.post( cfg.ajaxUrl, {
				action: 'aumlang_test_reachability',
				nonce: cfg.nonce
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$out.html( '<p class="aml-test-result is-err">' + ( response && response.data ? response.data.message : ( cfg.failed || 'error' ) ) + '</p>' );
					return;
				}

				var rows = response.data.results.map( function ( r ) {
					var cls = r.ok ? 'is-ok' : 'is-err';
					var icon = r.ok ? 'dashicons-yes' : 'dashicons-no-alt';
					return '<li class="' + cls + '"><span class="dashicons ' + icon + '"></span><strong>' + r.label + '</strong> — ' + r.detail + '</li>';
				} ).join( '' );

				$out.html( '<ul class="aml-reach-list">' + rows + '</ul>' );
			} ).fail( function () {
				$out.html( '<p class="aml-test-result is-err">' + ( cfg.failed || 'error' ) + '</p>' );
			} ).always( function () {
				$button.prop( 'disabled', false );
			} );
		} );

		$( '#aumlang-test-provider' ).on( 'click', function () {
			var $button = $( this );
			var $result = $( '#aumlang-test-result' );

			$button.prop( 'disabled', true );
			$result.removeClass( 'is-ok is-err' ).text( cfg.testing || '' );

			$.post( cfg.ajaxUrl, {
				action: 'aumlang_test_provider',
				nonce: cfg.nonce,
				engine: $( '#aumlang-engine' ).val(),
				api_key: $( '#aumlang-key' ).val(),
				endpoint: $( '#aumlang-endpoint' ).val(),
				model: $( '#aumlang-model' ).val()
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$result.addClass( 'is-ok' ).text( response.data.message );
				} else {
					$result.addClass( 'is-err' ).text( response && response.data ? response.data.message : ( cfg.failed || 'error' ) );
				}
			} ).fail( function () {
				$result.addClass( 'is-err' ).text( cfg.failed || 'error' );
			} ).always( function () {
				$button.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
