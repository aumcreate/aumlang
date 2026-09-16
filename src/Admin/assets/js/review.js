/**
 * Review tab: the header checkbox mirrors its state onto every row.
 *
 * Lives in a file rather than an inline <script> so it goes through
 * wp_enqueue_script() like the rest of the plugin's assets.
 */
( function () {
	var all = document.getElementById( 'aumlang-review-all' );

	if ( ! all ) {
		return;
	}

	all.addEventListener( 'change', function () {
		var boxes = document.querySelectorAll( '.aumlang-review-cb' );

		for ( var i = 0; i < boxes.length; i++ ) {
			boxes[ i ].checked = all.checked;
		}
	} );
}() );
