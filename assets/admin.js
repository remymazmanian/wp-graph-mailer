/* Graph Mailer settings screen: the show/hide switch for the step-by-step guide.
   The choice is remembered in this browser only. */
( function () {
	var guide = document.getElementById( 'gm-guide' );
	var toggle = document.getElementById( 'gm-guide-toggle' );
	if ( ! guide || ! toggle ) {
		return;
	}
	try {
		if ( window.localStorage.getItem( 'gmGuide' ) ) {
			guide.classList.add( 'open' );
			toggle.checked = true;
		}
	} catch ( e ) {}
	toggle.addEventListener( 'change', function () {
		guide.classList.toggle( 'open', toggle.checked );
		try {
			window.localStorage.setItem( 'gmGuide', toggle.checked ? '1' : '' );
		} catch ( e ) {}
	} );
}() );
