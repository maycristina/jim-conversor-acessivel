/*
 * Lightbox for the document images, on the native <dialog>: the browser
 * handles the layer above the page, the focus trap and Esc.
 *
 * Each image gets a button around it from the JS (a keyboard and screen
 * reader target). Without JS or without <dialog>, images stay as they are.
 */
( function () {
	'use strict';

	var i18n = window.jimcaLightboxI18n || {};
	var dialog = null;
	var figureImg = null;
	var caption = null;
	var counter = null;
	var prevBtn = null;
	var nextBtn = null;
	var group = [];
	var index = 0;
	var opener = null;

	function altOf( img ) {
		return img.hasAttribute( 'data-jimca-alt-missing' ) ? '' : ( img.getAttribute( 'alt' ) || '' );
	}

	function format( template, a, b ) {
		return ( template || '' ).replace( '%1$s', a ).replace( '%2$s', b );
	}

	function show( i ) {
		index = ( i + group.length ) % group.length;
		var img = group[ index ];

		figureImg.src = img.currentSrc || img.src;
		figureImg.alt = altOf( img );
		caption.textContent = altOf( img );
		caption.hidden = '' === caption.textContent;
		counter.textContent = group.length > 1 ? format( i18n.counter, index + 1, group.length ) : '';
		prevBtn.hidden = nextBtn.hidden = group.length < 2;
	}

	// Restores scrolling and focus. Idempotent: runs on the "close" event (Esc)
	// and directly from the buttons, without relying on the browser firing it.
	function cleanup() {
		document.documentElement.classList.remove( 'jimca-lightbox-open' );
		if ( opener ) {
			opener.focus();
			opener = null;
		}
	}

	function closeDialog() {
		if ( dialog.open ) {
			dialog.close();
		}
		cleanup();
	}

	function build() {
		dialog = document.createElement( 'dialog' );
		dialog.className = 'jimca-lightbox';
		dialog.setAttribute( 'aria-label', i18n.dialog || 'Enlarged image' );

		dialog.innerHTML =
			'<div class="jimca-lightbox__stage">' +
			'<button type="button" class="jimca-lightbox__btn jimca-lightbox__close"></button>' +
			'<button type="button" class="jimca-lightbox__btn jimca-lightbox__prev"></button>' +
			'<img class="jimca-lightbox__img" alt="">' +
			'<button type="button" class="jimca-lightbox__btn jimca-lightbox__next"></button>' +
			'<p class="jimca-lightbox__caption"></p>' +
			'<p class="jimca-lightbox__counter" aria-live="polite"></p>' +
			'</div>';

		var closeBtn = dialog.querySelector( '.jimca-lightbox__close' );
		prevBtn = dialog.querySelector( '.jimca-lightbox__prev' );
		nextBtn = dialog.querySelector( '.jimca-lightbox__next' );
		figureImg = dialog.querySelector( '.jimca-lightbox__img' );
		caption = dialog.querySelector( '.jimca-lightbox__caption' );
		counter = dialog.querySelector( '.jimca-lightbox__counter' );

		closeBtn.textContent = '×';
		closeBtn.setAttribute( 'aria-label', i18n.close || 'Close' );
		prevBtn.textContent = '‹';
		prevBtn.setAttribute( 'aria-label', i18n.previous || 'Previous image' );
		nextBtn.textContent = '›';
		nextBtn.setAttribute( 'aria-label', i18n.next || 'Next image' );

		closeBtn.addEventListener( 'click', closeDialog );
		prevBtn.addEventListener( 'click', function () {
			show( index - 1 );
		} );
		nextBtn.addEventListener( 'click', function () {
			show( index + 1 );
		} );

		// A click outside the image (on the dark backdrop) closes.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog || event.target === dialog.firstChild ) {
				closeDialog();
			}
		} );

		dialog.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key && group.length > 1 ) {
				show( index - 1 );
			} else if ( 'ArrowRight' === event.key && group.length > 1 ) {
				show( index + 1 );
			}
		} );

		dialog.addEventListener( 'close', cleanup );

		document.body.appendChild( dialog );
	}

	function open( img, trigger ) {
		var content = img.closest( '[data-jimca-content]' );

		group = Array.prototype.slice.call( content.querySelectorAll( 'img.jimca-image' ) );
		opener = trigger;

		if ( ! dialog ) {
			build();
		}

		show( Math.max( 0, group.indexOf( img ) ) );
		document.documentElement.classList.add( 'jimca-lightbox-open' );
		dialog.showModal();
	}

	function init() {
		if ( 'function' !== typeof HTMLDialogElement ) {
			return;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-jimca-content] img.jimca-image' ),
			function ( img ) {
				if ( img.parentNode.classList.contains( 'jimca-lightbox-trigger' ) ) {
					return;
				}

				var button = document.createElement( 'button' );
				var alt = altOf( img );

				button.type = 'button';
				button.className = 'jimca-lightbox-trigger';
				button.setAttribute( 'aria-label', ( i18n.open || 'Enlarge image' ) + ( alt ? ': ' + alt : '' ) );
				img.parentNode.insertBefore( button, img );
				button.appendChild( img );

				button.addEventListener( 'click', function () {
					open( img, button );
				} );
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
