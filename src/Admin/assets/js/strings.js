/**
 * AumLang String Translations admin page.
 *
 * @package AumLang
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var cfg = window.AumLangStrings || {};

		// Bulk AI translation: loop batches until none remain.
		$( '#aumlang-translate-all' ).on( 'click', function () {
			var $button = $( this );
			var $progress = $( '#aumlang-progress' );

			if ( $button.prop( 'disabled' ) ) {
				return;
			}

			var total = parseInt( $button.data( 'untranslated' ), 10 ) || 0;

			if ( total === 0 ) {
				return;
			}

			$button.prop( 'disabled', true );

			function step() {
				$.post( cfg.ajaxUrl, {
					action: 'aumlang_strings_translate',
					nonce: cfg.nonce,
					lang: cfg.lang
				} ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						var msg = response && response.data ? response.data.message : 'error';
						$progress.text( msg );
						$button.prop( 'disabled', false );
						return;
					}

					var remaining = response.data.remaining;
					var done = total - remaining;
					$progress.text( cfg.translating + ' ' + done + ' / ' + total );

					if ( remaining > 0 && response.data.done > 0 ) {
						step();
					} else {
						$progress.text( cfg.done );
						window.location.reload();
					}
				} ).fail( function () {
					$progress.text( 'error' );
					$button.prop( 'disabled', false );
				} );
			}

			step();
		} );

		// Inline save on edit.
		$( '.aumlang-strings-table' ).on( 'change', '.aumlang-string-input', function () {
			var $input = $( this );
			var $row = $input.closest( 'tr' );
			var $flag = $row.find( '.aumlang-saved-flag' );

			$.post( cfg.ajaxUrl, {
				action: 'aumlang_strings_save',
				nonce: cfg.nonce,
				id: $row.data( 'id' ),
				text: $input.val()
			} ).done( function () {
				$flag.text( cfg.saved ).fadeIn();
				setTimeout( function () {
					$flag.fadeOut();
				}, 1500 );
			} );
		} );

		var $bulkButton = $( '#aumlang-delete-selected' );

		// Enable the bulk-delete button only when something is checked.
		function refreshBulkState() {
			$bulkButton.prop( 'disabled', $( '.aumlang-cb:checked' ).length === 0 );
		}

		// Select-all toggle.
		$( '#aumlang-check-all' ).on( 'change', function () {
			$( '.aumlang-cb' ).prop( 'checked', $( this ).prop( 'checked' ) );
			refreshBulkState();
		} );

		$( '.aumlang-strings-table' ).on( 'change', '.aumlang-cb', refreshBulkState );

		// Delete a set of ids, then drop their rows.
		function deleteIds( ids, $rows ) {
			if ( ! ids.length ) {
				return;
			}

			$.post( cfg.ajaxUrl, {
				action: 'aumlang_strings_delete',
				nonce: cfg.nonce,
				ids: ids
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$rows.fadeOut( 200, function () {
						$( this ).remove();
						refreshBulkState();
					} );
				}
			} );
		}

		// Per-row delete.
		$( '.aumlang-strings-table' ).on( 'click', '.aumlang-delete', function () {
			var $row = $( this ).closest( 'tr' );
			deleteIds( [ $row.data( 'id' ) ], $row );
		} );

		// Bulk delete.
		$bulkButton.on( 'click', function () {
			var $rows = $( '.aumlang-cb:checked' ).closest( 'tr' );

			if ( ! $rows.length || ! window.confirm( cfg.confirmDelete ) ) {
				return;
			}

			var ids = $rows.map( function () {
				return $( this ).data( 'id' );
			} ).get();

			deleteIds( ids, $rows );
		} );
	} );
} )( jQuery );
