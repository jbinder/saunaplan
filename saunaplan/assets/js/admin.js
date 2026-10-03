( function () {
	'use strict';

	// Confirmation prompts for destructive actions.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '.saunaplan-confirm' );
		if ( el && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// "Copy link" buttons for the booking page URL.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.saunaplan-copy' );
		if ( ! btn || ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( btn.getAttribute( 'data-copy' ) ).then( function () {
			var label = btn.textContent;
			btn.textContent = btn.getAttribute( 'data-done' );
			setTimeout( function () {
				btn.textContent = label;
			}, 1500 );
		} );
	} );

	// Recurring session form: only show options that fit the chosen kind of session.
	var form = document.querySelector( '.saunaplan-series-form' );
	if ( form ) {
		var update = function () {
			var checked = form.querySelector( 'input[name="mode"]:checked' );
			var mode = checked ? checked.value : '';
			form.querySelectorAll( '.saunaplan-field-option' ).forEach( function ( label ) {
				var fits = ( ' ' + label.getAttribute( 'data-modes' ) + ' ' ).indexOf( ' ' + mode + ' ' ) > -1;
				label.style.opacity = fits ? '' : '0.4';
				label.querySelector( 'input' ).disabled = ! fits;
			} );
		};
		form.addEventListener( 'change', update );
		update();
	}

	// QR codes on the code letters.
	if ( window.qrcode ) {
		document.querySelectorAll( '.saunaplan-qr[data-qr]' ).forEach( function ( box ) {
			var qr = window.qrcode( 0, 'M' );
			qr.addData( box.getAttribute( 'data-qr' ) );
			qr.make();
			box.innerHTML = qr.createSvgTag( { cellSize: 4, margin: 0, scalable: true } );
		} );
	}
} )();
