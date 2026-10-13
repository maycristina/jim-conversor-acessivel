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
		var exporter = root.querySelector( '[data-jimca-tutor-export]' );
		var quickBtn = root.querySelector( '[data-jimca-tutor-quick]' );
		var quickMenu = root.querySelector( '[data-jimca-tutor-quick-menu]' );
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
		var freshNext = false;

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

		// Empty: the orb, the greeting and suggestions. With turns: only the conversation.
		function render() {
			closeQuick( false );
			log.textContent = '';
			panel.setAttribute( 'data-state', history.length ? 'chatting' : 'empty' );
			toggleExport();
			if ( ! history.length ) {
				welcome();
			}
			history.forEach( function ( turn ) {
				bubble( turn.role, turn.content );
			} );
		}

		function welcome() {
			var stage = document.createElement( 'div' );
			var orb   = document.createElement( 'span' );
			var text  = document.createElement( 'p' );

			stage.className = 'jimca-tutor__stage';
			stage.setAttribute( 'data-jimca-tutor-stage', '' );
			orb.className = 'jimca-tutor__orb';
			orb.setAttribute( 'aria-hidden', 'true' );
			text.className = 'jimca-tutor__welcome';
			text.textContent = greeting;

			stage.appendChild( orb );
			if ( greeting ) {
				stage.appendChild( text );
			}
			log.appendChild( stage );
			suggestions( stage );
		}

		// Grows the field with the text, up to its max-height in the stylesheet.
		function grow() {
			input.style.height = 'auto';
			input.style.height = input.scrollHeight + 'px';
		}

		function toggleExport() {
			if ( exporter ) {
				exporter.hidden = ! history.length;
			}
		}

		/** The whole conversation as a text file, for the reader to keep. */
		function exportConversation() {
			if ( ! history.length ) {
				return;
			}

			var heading = viewer && viewer.getAttribute( 'data-jimca-title' ) ? viewer.getAttribute( 'data-jimca-title' ) : document.title;
			var lines   = [ heading, window.location.href.split( '#' )[ 0 ], new Date().toLocaleString(), '' ];

			history.forEach( function ( turn ) {
				lines.push( ( 'user' === turn.role ? t( 'you', 'You' ) : name ) + ':' );
				lines.push( turn.content );
				lines.push( '' );
			} );

			var file = heading.replace( /[^\p{L}\p{N}]+/gu, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 ) || 'jim';
			var url  = window.URL.createObjectURL( new Blob( [ lines.join( '\n' ) ], { type: 'text/plain;charset=utf-8' } ) );
			var link = document.createElement( 'a' );

			link.href     = url;
			link.download = file + '-conversation.txt';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			window.setTimeout( function () {
				window.URL.revokeObjectURL( url );
			}, 1000 );
			announce( t( 'exported', 'Conversation downloaded.' ) );
		}

		/**
		 * A suggestion: the beginning of a question goes in front of what is already typed;
		 * a challenge goes out at once, as a new question every time, and the draft stays.
		 */
		function useChip( chip ) {
			var draft = input.value.trim();

			if ( chip[2] ) {
				input.value = chip[1];
				freshNext   = true;
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
				input.value = draft;
				grow();
				return;
			}

			input.value = chip[1] + draft;
			grow();
			input.focus();
			input.setSelectionRange( input.value.length, input.value.length );
		}

		/**
		 * Quick texts: the same suggestions as at the start, in a menu that opens
		 * from the field at any point of the conversation.
		 */
		function setupQuick() {
			var chips = i18n.chips || [];

			if ( ! quickBtn || ! quickMenu || ! chips.length ) {
				return;
			}

			quickBtn.hidden = false;

			chips.forEach( function ( chip ) {
				var item = document.createElement( 'button' );

				item.type      = 'button';
				item.className = 'jimca-tutor__quick-item';
				item.setAttribute( 'role', 'menuitem' );
				item.textContent = chip[0];
				item.addEventListener( 'click', function () {
					closeQuick( false );
					useChip( chip );
				} );
				quickMenu.appendChild( item );
			} );

			quickBtn.addEventListener( 'click', function () {
				if ( quickMenu.hidden ) {
					openQuick();
				} else {
					closeQuick( true );
				}
			} );

			quickMenu.addEventListener( 'keydown', function ( event ) {
				var items = Array.prototype.slice.call( quickMenu.querySelectorAll( '[role="menuitem"]' ) );
				var index = items.indexOf( document.activeElement );
				var next  = -1;

				if ( 'ArrowDown' === event.key ) {
					next = ( index + 1 ) % items.length;
				} else if ( 'ArrowUp' === event.key ) {
					next = ( index - 1 + items.length ) % items.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = items.length - 1;
				} else if ( 'Tab' === event.key ) {
					closeQuick( false );
					return;
				}

				if ( next > -1 ) {
					event.preventDefault();
					items[ next ].focus();
				}
			} );

			// A tap or click anywhere else closes it.
			document.addEventListener( 'click', function ( event ) {
				if ( ! quickMenu.hidden && ! quickMenu.contains( event.target ) && ! quickBtn.contains( event.target ) ) {
					closeQuick( false );
				}
			} );
		}

		function openQuick() {
			quickMenu.hidden = false;
			quickBtn.setAttribute( 'aria-expanded', 'true' );
			quickMenu.querySelector( '[role="menuitem"]' ).focus();
		}

		function closeQuick( refocus ) {
			if ( ! quickMenu || quickMenu.hidden ) {
				return;
			}

			quickMenu.hidden = true;
			quickBtn.setAttribute( 'aria-expanded', 'false' );

			if ( refocus ) {
				quickBtn.focus();
			}
		}

		// Starting points under the greeting: a click puts the beginning of a question in the field.
		function suggestions( parent ) {
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
					useChip( chip );
				} );
				group.appendChild( button );
			} );
			parent.appendChild( group );
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

		function ask( question, fresh ) {
			var data      = new FormData();
			var asking    = bubble( 'assistant', t( 'thinking', 'Thinking…' ) );
			var text      = asking.querySelector( '.jimca-tutor__text' );
			var streaming = !! ( window.ReadableStream && window.TextDecoder );

			asking.classList.add( 'is-pending' );
			// aria-busy keeps screen readers from announcing every streamed piece; the answer is read once complete.
			log.setAttribute( 'aria-busy', 'true' );
			root.classList.add( 'is-busy' );
			waiting = true;

			data.append( 'action', t( 'action', '' ) );
			data.append( 'doc', doc );
			data.append( 'nonce', nonce );
			data.append( 'question', question );
			data.append( 'history', JSON.stringify( history.slice( -MAX_HISTORY ) ) );
			if ( fresh ) {
				data.append( 'fresh', '1' );
			}
			if ( streaming ) {
				data.append( 'stream', '1' );
			}

			function finish( answer ) {
				answer = String( answer ).trim();
				asking.classList.remove( 'is-pending' );
				renderText( text, answer );
				history.push( { role: 'user', content: question }, { role: 'assistant', content: answer } );
				save();
				toggleExport();
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
					root.classList.remove( 'is-busy' );
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
			var stage = log.querySelector( '[data-jimca-tutor-stage]' );
			if ( stage ) {
				stage.remove();
			}
			panel.setAttribute( 'data-state', 'chatting' );
			bubble( 'user', question );
			input.value = '';
			grow();
			ask( question, freshNext );
			freshNext = false;
		} );

		input.addEventListener( 'input', grow );

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

		if ( exporter ) {
			exporter.addEventListener( 'click', exportConversation );
		}

		panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				if ( quickMenu && ! quickMenu.hidden ) {
					closeQuick( true );
					return;
				}
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
		setupQuick();
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
					grow();
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
