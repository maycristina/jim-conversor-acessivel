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
		var pieces = null; // Pieces of the text of items[ index ] (see jimcaSpeech.pieces() in speech.js); null until it is read.
		var piece = 0;
		var state = 'idle'; // idle | playing | paused
		var current = null;

		/*
		 * Identifies the current reading: cancel() makes the browser fire
		 * `end`/`error` on the utterance it cut, late, and that must not
		 * advance a reading that was stopped or restarted (see frontend.js).
		 */
		var run = 0;

		// window.jimcaSpeech comes from speech.js: if a script optimizer dropped it, reading is off as in a browser without speech.
		if ( ! synth || 'undefined' === typeof SpeechSynthesisUtterance || ! content || ! window.jimcaSpeech ) {
			button.disabled = true;
			button.addEventListener( 'click', function () {} );
			setStatus( bar, bar.getAttribute( 'data-unsupported' ) );
			return;
		}

		// Chrome starts listing its voices only when asked, and lists them later: asked now, they are there by the first click.
		synth.getVoices();

		/** The document's language (data-doc-lang, set by the server), else the page's, else the browser's. */
		function docLang() {
			return viewer.getAttribute( 'data-doc-lang' ) || viewer.getAttribute( 'data-jimca-lang' ) ||
				document.documentElement.lang || window.navigator.language || 'pt-BR';
		}

		/** Moves the reading to another passage. */
		function goTo( i ) {
			index = i;
			pieces = null;
			piece = 0;
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
			goTo( 0 );
			render();
			if ( message ) {
				setStatus( bar, message );
			}
		}

		function speakNext() {
			// End of a part of a long document: the next part is fetched and the reading goes on there.
			if ( index >= items.length && viewer.jimcaPager && viewer.jimcaPager.hasNext() ) {
				var waiting = run;

				viewer.jimcaPager.next( { focus: false, auto: true } ).then( function () {
					if ( waiting === run && 'playing' === state ) {
						collect();
						goTo( 0 );
						speakNext();
					}
				} ).catch( function () {
					finish();
				} );
				return;
			}

			if ( index >= items.length ) {
				finish( bar.getAttribute( 'data-done' ) );
				return;
			}

			var el = items[ index ];

			if ( ! pieces ) {
				pieces = window.jimcaSpeech.pieces( textOf( el ) );
				piece = 0;
			}

			var mine = run;
			var first = 0 === piece;
			var lang = docLang();
			// The best voice of the document's language; none at all rather than one of another language.
			var voice = window.jimcaSpeech.bestVoice( synth.getVoices(), lang );
			var utterance = new SpeechSynthesisUtterance( pieces[ piece ] );

			utterance.rate = rate;
			// Both: Chrome and Edge follow the voice, Safari on iOS follows the language.
			if ( voice ) {
				utterance.voice = voice;
				utterance.lang = voice.lang;
			} else {
				utterance.lang = lang;
			}

			// The passage is marked once, not again at each of its pieces.
			utterance.onstart = function () {
				if ( first ) {
					clearHighlight();
					current = el;
					el.classList.add( 'jimca-reading' );
				}
			};
			utterance.onend = function () {
				if ( mine !== run || 'playing' !== state ) {
					return;
				}
				if ( piece + 1 < pieces.length ) {
					piece += 1;
				} else {
					goTo( index + 1 );
				}
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
			goTo( startIndex() );
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
