/* Sendelva settings screen: the show/hide switch for the step-by-step guide.
   The choice is remembered in this browser only. */
( function () {
	var guide = document.getElementById( 'sendelva-guide' );
	var toggle = document.getElementById( 'sendelva-guide-toggle' );
	if ( ! guide || ! toggle ) {
		return;
	}
	try {
		if ( window.localStorage.getItem( 'sendelvaGuide' ) ) {
			guide.classList.add( 'open' );
			toggle.checked = true;
		}
	} catch ( e ) {}
	toggle.addEventListener( 'change', function () {
		guide.classList.toggle( 'open', toggle.checked );
		try {
			window.localStorage.setItem( 'sendelvaGuide', toggle.checked ? '1' : '' );
		} catch ( e ) {}
	} );
}() );
