/**
 * AumLang language switcher dropdown toggle.
 *
 * @package AumLang
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest ? event.target.closest( '.aml-lang-toggle' ) : null;
		var openParent = toggle ? toggle.parentNode : null;

		document.querySelectorAll( '.aml-lang-dropdown.open' ).forEach( function ( dropdown ) {
			if ( dropdown !== openParent ) {
				dropdown.classList.remove( 'open' );
				var t = dropdown.querySelector( '.aml-lang-toggle' );
				if ( t ) { t.setAttribute( 'aria-expanded', 'false' ); }
			}
		} );

		if ( toggle ) {
			event.preventDefault();
			var isOpen = openParent.classList.toggle( 'open' );
			toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
		}
	} );
} )();
