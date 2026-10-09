/*
 * "Blog post" bar: listen (the browser's Web Speech API) and share (the
 * device's share menu or, without it, copying the link). The buttons are
 * hidden in the HTML and only appear here, with JS.
 */
( function () {
	'use strict';

	var TEXT_SELECTOR = 'h1, h2, h3, h4, h5, h6, p, li, blockquote, figcaption';

	function setStatus( bar, message ) {
		var status = bar.querySelector( '[data-jimca-post-status]' );
		if ( status ) {
			status.textContent = message;
		}
	}

	function setupShare( bar, button ) {
		button.addEventListener( 'click', function () {
			var url = window.location.href;
			var title = bar.getAttribute( 'data-title' ) || document.title;

			if ( navigator.share ) {
				navigator.share( { title: title, url: url } ).catch( function () {} );
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then(
					function () {
						setStatus( bar, bar.getAttribute( 'data-copied' ) );
					},
					function () {
						setStatus( bar, bar.getAttribute( 'data-copy-failed' ) );
					}
				);
				return;
			}

			setStatus( bar, bar.getAttribute( 'data-copy-failed' ) );
		} );
	}

	function setupListen( viewer, bar, button ) {
		var synth = window.speechSynthesis;
		var content = viewer.querySelector( '[data-jimca-content]' );
		var playIcon = button.querySelector( '[data-jimca-icon="play"]' );
		var pauseIcon = button.querySelector( '[data-jimca-icon="pause"]' );
		var label = button.querySelector( '[data-jimca-post-listen-label]' );
		var rate = parseFloat( bar.getAttribute( 'data-rate' ) ) || 1;
		var items = [];
		var index = 0;
		var state = 'idle'; // idle | playing | paused
		var current = null;

		/*
		 * Identifies the current reading: cancel() makes the browser fire
		 * `end`/`error` on the utterance it cut, late, and that must not
		 * advance a reading that was stopped or restarted (see frontend.js).
		 */
		var run = 0;

		if ( ! synth || 'undefined' === typeof SpeechSynthesisUtterance || ! content ) {
			button.disabled = true;
			button.addEventListener( 'click', function () {} );
			setStatus( bar, bar.getAttribute( 'data-unsupported' ) );
			return;
		}

		function render() {
			var playing = 'playing' === state;
			playIcon.hidden = playing;
			pauseIcon.hidden = ! playing;
			button.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
			label.textContent = bar.getAttribute( playing ? 'data-pause' : ( 'paused' === state ? 'data-resume' : 'data-listen' ) );
		}

		function clearHighlight() {
			if ( current ) {
				current.classList.remove( 'jimca-reading' );
				current = null;
			}
		}

		function textOf( el ) {
			return ( el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		}

		function collect() {
			items = Array.prototype.filter.call(
				content.querySelectorAll( TEXT_SELECTOR ),
				function ( el ) {
					return '' !== textOf( el );
				}
			);
		}

		/*
		 * Reading begins where the reader is, not at the top: the start of a
		 * selection inside the post, else the first element on screen, else
		 * the first one when the post is below the screen.
		 */
		function startIndex() {
			var selection = window.getSelection ? window.getSelection() : null;

			if ( selection && selection.rangeCount && ! selection.isCollapsed && content.contains( selection.anchorNode ) ) {
				var node = selection.getRangeAt( 0 ).startContainer;
				var el = 3 === node.nodeType ? node.parentNode : node;
				for ( var i = 0; i < items.length; i++ ) {
					if ( items[ i ].contains( el ) ) {
						return i;
					}
				}
			}

			for ( var j = 0; j < items.length; j++ ) {
				var rect = items[ j ].getBoundingClientRect();
				if ( rect.bottom > 48 && rect.top < window.innerHeight ) {
					return j;
				}
				if ( rect.top >= window.innerHeight ) {
					break;
				}
			}

			return 0;
		}

		function finish( message ) {
			clearHighlight();
			state = 'idle';
			index = 0;
			render();
			if ( message ) {
				setStatus( bar, message );
			}
		}

		function speakNext() {
			if ( index >= items.length ) {
				finish( bar.getAttribute( 'data-done' ) );
				return;
			}

			var el = items[ index ];
			var mine = run;
			var utterance = new SpeechSynthesisUtterance( textOf( el ) );
			utterance.rate = rate;
			utterance.lang = document.documentElement.lang || 'pt-BR';

			utterance.onstart = function () {
				clearHighlight();
				current = el;
				el.classList.add( 'jimca-reading' );
			};
			utterance.onend = function () {
				if ( mine !== run || 'playing' !== state ) {
					return;
				}
				index += 1;
				speakNext();
			};
			utterance.onerror = function () {
				if ( mine === run && 'idle' !== state ) {
					finish();
				}
			};

			synth.speak( utterance );
		}

		button.addEventListener( 'click', function () {
			if ( 'playing' === state ) {
				synth.pause();
				state = 'paused';
				render();
				return;
			}

			if ( 'paused' === state ) {
				synth.resume();
				state = 'playing';
				render();
				return;
			}

			collect();
			run += 1;
			synth.cancel();
			index = startIndex();
			state = 'playing';
			render();
			speakNext();
		} );

		window.addEventListener( 'pagehide', function () {
			state = 'idle';
			run += 1;
			synth.cancel();
		} );
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-jimca-postbar]' ),
			function ( bar ) {
				var viewer = bar.closest( '[data-jimca-viewer]' );
				var actions = bar.querySelector( '[data-jimca-postbar-actions]' );
				var listen = bar.querySelector( '[data-jimca-post-listen]' );
				var share = bar.querySelector( '[data-jimca-post-share]' );

				if ( ! viewer || ! actions ) {
					return;
				}

				actions.hidden = false;

				if ( listen ) {
					setupListen( viewer, bar, listen );
				}
				if ( share ) {
					setupShare( bar, share );
				}
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
