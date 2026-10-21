/*
 * "Use my name": a button with data-jimca-fill="#field" and
 * data-jimca-fill-value="text" puts the text in the field and gives it focus.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-jimca-fill]' ) : null;

		if ( ! button ) {
			return;
		}

		var field = document.querySelector( button.getAttribute( 'data-jimca-fill' ) );

		if ( field ) {
			field.value = button.getAttribute( 'data-jimca-fill-value' ) || '';
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			field.focus();
		}
	} );
} )();
