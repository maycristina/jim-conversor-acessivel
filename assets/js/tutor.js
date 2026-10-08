/**
 * AI tutor on the document page: chat bubble, conversation, voice input.
 *
 * The conversation stays in the reader's browser (sessionStorage, per
 * document) and is sent back with each question so the tutor remembers the
 * last turns. Hiding the tutor is remembered for that document
 * (localStorage) until the reader shows it again.
 */
( function () {
	'use strict';

	var i18n        = window.jimcaTutorI18n || {};
	var HIDDEN_KEY  = 'jimca-tutor-hidden';
	var MAX_SAVED   = 20;
	var MAX_HISTORY = 4;

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	function storage( kind ) {
		try {
			var store = window[ kind ];
			store.setItem( 'jimca-test', '1' );
			store.removeItem( 'jimca-test' );
			return store;
		} catch ( e ) {
			return null;
		}
	}

	var local   = storage( 'localStorage' );
	var session = storage( 'sessionStorage' );

	function setup( root ) {
		var viewer   = root.closest( '[data-jimca-viewer]' );
		var panel    = root.querySelector( '[data-jimca-tutor-panel]' );
		var fab      = root.querySelector( '[data-jimca-tutor-open]' );
		var log      = root.querySelector( '[data-jimca-tutor-log]' );
		var form     = root.querySelector( '[data-jimca-tutor-form]' );
		var input    = root.querySelector( '[data-jimca-tutor-input]' );
		var mic      = root.querySelector( '[data-jimca-tutor-mic]' );
		var status   = root.querySelector( '[data-jimca-tutor-status]' );
		var restore  = viewer ? viewer.querySelector( '[data-jimca-tutor-restore]' ) : null;
		var doc      = root.getAttribute( 'data-doc' );
		var nonce    = root.getAttribute( 'data-nonce' );
		var greeting = root.getAttribute( 'data-greeting' ) || '';
		var name     = root.getAttribute( 'data-name' ) || '';
		var key      = 'jimca-tutor-' + doc;
		var hiddenKey = HIDDEN_KEY + '-' + doc;
		var history  = load();
		var waiting  = false;

		function load() {
			try {
				var saved = session ? JSON.parse( session.getItem( key ) || '[]' ) : [];
				return Array.isArray( saved ) ? saved : [];
			} catch ( e ) {
				return [];
			}
		}

		function save() {
			if ( session ) {
				session.setItem( key, JSON.stringify( history.slice( -MAX_SAVED ) ) );
			}
		}

		function announce( text ) {
			status.textContent = text;
		}

		/**
		 * Shows an answer as text, turning **bold** (which models write even when
		 * asked for plain text) into <strong>. Built from text nodes, never HTML.
		 */
		function renderText( el, text ) {
			el.textContent = '';
			String( text ).split( /\*\*(.+?)\*\*/ ).forEach( function ( part, index ) {
				if ( index % 2 ) {
					var strong = document.createElement( 'strong' );
					strong.textContent = part;
					el.appendChild( strong );
				} else if ( part ) {
					el.appendChild( document.createTextNode( part ) );
				}
			} );
		}

		function bubble( role, text ) {
			var item  = document.createElement( 'div' );
			var who   = document.createElement( 'p' );
			var body  = document.createElement( 'p' );

			item.className = 'jimca-tutor__msg jimca-tutor__msg--' + role;
			who.className  = 'jimca-tutor__who';
			body.className = 'jimca-tutor__text';
			who.textContent  = 'user' === role ? t( 'you', 'You' ) : name;
			// Never innerHTML: the answer comes from an AI service.
			renderText( body, text );

			item.appendChild( who );
			item.appendChild( body );
			log.appendChild( item );
			log.scrollTop = log.scrollHeight;

			return item;
		}

		function render() {
			log.textContent = '';
			if ( greeting ) {
				bubble( 'assistant', greeting );
			}
			if ( ! history.length ) {
				suggestions();
			}
			history.forEach( function ( turn ) {
				bubble( turn.role, turn.content );
			} );
		}

		// Starting points under the greeting: a click puts the beginning of a question in the field.
		function suggestions() {
			var chips = i18n.chips || [];
			if ( ! chips.length ) {
				return;
			}
			var group = document.createElement( 'div' );
			group.className = 'jimca-tutor__chips';
			group.setAttribute( 'role', 'group' );
			group.setAttribute( 'aria-label', t( 'suggestions', 'Suggestions' ) );
			group.setAttribute( 'data-jimca-tutor-chips', '' );
			chips.forEach( function ( chip ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'jimca-tutor__chip';
				button.textContent = chip[0];
				button.addEventListener( 'click', function () {
					input.value = chip[1];
					input.focus();
					input.setSelectionRange( input.value.length, input.value.length );
				} );
				group.appendChild( button );
			} );
			log.appendChild( group );
		}

		function open() {
			panel.hidden = false;
			fab.setAttribute( 'aria-expanded', 'true' );
			root.classList.add( 'is-open' );
			log.scrollTop = log.scrollHeight;
			input.focus();
		}

		function close() {
			panel.hidden = true;
			fab.setAttribute( 'aria-expanded', 'false' );
			root.classList.remove( 'is-open' );
			fab.focus();
		}

		function showWidget( visible ) {
			root.hidden = ! visible;
			if ( restore ) {
				restore.hidden = visible;
			}
		}

		function ask( question ) {
			var data      = new FormData();
			var asking    = bubble( 'assistant', t( 'thinking', 'Thinking…' ) );
			var text      = asking.querySelector( '.jimca-tutor__text' );
			var streaming = !! ( window.ReadableStream && window.TextDecoder );

			asking.classList.add( 'is-pending' );
			// aria-busy keeps screen readers from announcing every streamed piece; the answer is read once complete.
			log.setAttribute( 'aria-busy', 'true' );
			waiting = true;

			data.append( 'action', t( 'action', '' ) );
			data.append( 'doc', doc );
			data.append( 'nonce', nonce );
			data.append( 'question', question );
			data.append( 'history', JSON.stringify( history.slice( -MAX_HISTORY ) ) );
			if ( streaming ) {
				data.append( 'stream', '1' );
			}

			function finish( answer ) {
				answer = String( answer ).trim();
				asking.classList.remove( 'is-pending' );
				renderText( text, answer );
				history.push( { role: 'user', content: question }, { role: 'assistant', content: answer } );
				save();
			}

			function failed( message ) {
				asking.remove();
				bubble( 'error', message || t( 'failed', '' ) );
			}

			// Server-sent events: "data: {...}" blocks with {t: piece}, then {done} or {error}.
			function readStream( response ) {
				var reader  = response.body.getReader();
				var decoder = new TextDecoder();
				var buffer  = '';
				var answer  = '';
				var error   = '';

				function pump() {
					return reader.read().then( function ( chunk ) {
						if ( chunk.done ) {
							if ( error || ! answer ) {
								failed( error );
							} else {
								finish( answer.trim() );
							}
							return;
						}

						buffer += decoder.decode( chunk.value, { stream: true } );

						var events = buffer.split( '\n\n' );
						buffer = events.pop();

						events.forEach( function ( block ) {
							var line = block.trim();
							if ( 0 !== line.indexOf( 'data:' ) ) {
								return;
							}
							try {
								var event = JSON.parse( line.slice( 5 ) );
								if ( event.t ) {
									if ( ! answer ) {
										asking.classList.remove( 'is-pending' );
									}
									answer += event.t;
									text.textContent = answer;
									log.scrollTop = log.scrollHeight;
								} else if ( event.error ) {
									error = event.error;
								}
							} catch ( e ) {}
						} );

						return pump();
					} );
				}

				return pump();
			}

			fetch( t( 'ajaxUrl', '' ), { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( response ) {
					var type = response.headers.get( 'Content-Type' ) || '';
					if ( streaming && -1 !== type.indexOf( 'text/event-stream' ) && response.body ) {
						return readStream( response );
					}
					// Saved answers, refusals and servers without streaming answer in JSON.
					return response.json().catch( function () {
						return { success: false, data: { message: t( 'failed', '' ) } };
					} ).then( function ( answer ) {
						if ( answer.success ) {
							finish( answer.data.answer );
						} else {
							failed( answer.data && answer.data.message );
						}
					} );
				} )
				.catch( function () {
					failed( t( 'failed', '' ) );
				} )
				.then( function () {
					waiting = false;
					log.removeAttribute( 'aria-busy' );
				} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( waiting ) {
				return;
			}
			var question = input.value.trim();
			if ( ! question ) {
				announce( t( 'empty', 'Type a question first.' ) );
				input.focus();
				return;
			}
			var chips = log.querySelector( '[data-jimca-tutor-chips]' );
			if ( chips ) {
				chips.remove();
			}
			bubble( 'user', question );
			input.value = '';
			ask( question );
		} );

		// Enter sends; Shift+Enter breaks the line.
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
			}
		} );

		fab.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				open();
			} else {
				close();
			}
		} );

		root.querySelector( '[data-jimca-tutor-close]' ).addEventListener( 'click', close );

		panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		} );

		root.querySelector( '[data-jimca-tutor-clear]' ).addEventListener( 'click', function () {
			history = [];
			save();
			render();
			announce( t( 'cleared', 'New conversation started.' ) );
			input.focus();
		} );

		root.querySelector( '[data-jimca-tutor-hide]' ).addEventListener( 'click', function () {
			if ( local ) {
				local.setItem( hiddenKey, '1' );
			}
			panel.hidden = true;
			fab.setAttribute( 'aria-expanded', 'false' );
			showWidget( false );
			if ( restore ) {
				restore.querySelector( 'button' ).focus();
			}
		} );

		if ( restore ) {
			restore.querySelector( 'button' ).addEventListener( 'click', function () {
				if ( local ) {
					local.removeItem( hiddenKey );
				}
				showWidget( true );
				open();
			} );
		}

		setupVoice();
		render();
		showWidget( ! ( local && '1' === local.getItem( hiddenKey ) ) );

		/*
		 * Voice input through the browser's speech recognition. Some browsers
		 * (Chrome, Edge) send the audio to their vendor to transcribe it; the
		 * button only appears where the feature exists.
		 */
		function setupVoice() {
			var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

			if ( ! Recognition || ! mic ) {
				return;
			}

			var recognition = null;
			var base        = '';

			mic.hidden = false;

			function stop() {
				mic.setAttribute( 'aria-pressed', 'false' );
				root.classList.remove( 'is-listening' );
				recognition = null;
			}

			mic.addEventListener( 'click', function () {
				if ( recognition ) {
					recognition.stop();
					return;
				}

				recognition                = new Recognition();
				recognition.lang           = document.documentElement.lang || navigator.language || 'en';
				recognition.interimResults = true;
				recognition.continuous     = false;
				base                       = input.value ? input.value.replace( /\s*$/, ' ' ) : '';

				recognition.onresult = function ( event ) {
					var text = '';
					for ( var i = 0; i < event.results.length; i++ ) {
						text += event.results[ i ][ 0 ].transcript;
					}
					input.value = base + text;
				};
				recognition.onerror = function ( event ) {
					if ( 'not-allowed' === event.error || 'service-not-allowed' === event.error ) {
						announce( t( 'micDenied', 'The browser did not allow the microphone.' ) );
					}
				};
				recognition.onend = function () {
					stop();
					announce( t( 'heard', 'Done listening. Check the question and send it.' ) );
					input.focus();
				};

				mic.setAttribute( 'aria-pressed', 'true' );
				root.classList.add( 'is-listening' );
				announce( t( 'listening', 'Listening… speak your question.' ) );
				recognition.start();
			} );
		}
	}

	function boot() {
		if ( ! window.fetch || ! window.FormData ) {
			return;
		}
		Array.prototype.forEach.call( document.querySelectorAll( '[data-jimca-tutor]' ), setup );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
