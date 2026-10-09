/**
 * Reading player of a converted document: text size, theme, high contrast
 * and reading aloud (Web Speech API).
 *
 * Progressive enhancement: without JS, or without browser support, the
 * content stays fully readable as plain text; the player keeps its `hidden`
 * attribute from the HTML and is never revealed.
 *
 * Menus follow the Material 3 menu pattern: they open from their button, one
 * at a time, close on Esc, an outside click or Tab, and give focus back to
 * the button that opened them.
 */
( function () {
	'use strict';

	var i18n = window.jimcaFrontendI18n || {};
	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var activeStop = null; // Stops whichever player is playing (one per page).
	var openMenu = null;   // The open menu (one per page).

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	/* ---------------------------------------------------------------
	 * Reader preferences, saved per document in the reader's browser.
	 * ------------------------------------------------------------- */

	function createPrefs( viewer ) {
		var key = 'jimca-prefs-' + viewer.id;
		var data = {};

		try {
			data = JSON.parse( window.localStorage.getItem( key ) || '{}' ) || {};
		} catch ( e ) {
			data = {}; // localStorage unavailable: the site defaults apply.
		}

		return {
			get: function ( name, fallback ) {
				return typeof data[ name ] === 'undefined' ? fallback : data[ name ];
			},
			set: function ( name, value ) {
				data[ name ] = value;
				try {
					window.localStorage.setItem( key, JSON.stringify( data ) );
				} catch ( e ) {
					// Without localStorage the choice lasts for this visit only.
				}
			},
		};
	}

	/* ---------------------------------------------------------------
	 * Menus
	 * ------------------------------------------------------------- */

	function menuItems( menu ) {
		return Array.prototype.slice.call(
			menu.querySelectorAll( '[role="menuitem"], [role="menuitemradio"]' )
		);
	}

	/**
	 * Keeps the menu inside the screen when its button is near an edge (always
	 * the case on phones, where the bar takes almost the whole width).
	 */
	function keepMenuOnScreen( menu ) {
		menu.style.setProperty( '--jimca-menu-shift', '0px' );

		var rect = menu.getBoundingClientRect();
		var margin = 8;
		var shift = 0;

		if ( rect.left < margin ) {
			shift = margin - rect.left;
		} else if ( rect.right > window.innerWidth - margin ) {
			shift = ( window.innerWidth - margin ) - rect.right;
		}

		if ( shift ) {
			menu.style.setProperty( '--jimca-menu-shift', Math.round( shift ) + 'px' );
		}
	}

	function closeMenu( entry, returnFocus ) {
		if ( ! entry ) {
			return;
		}

		entry.menu.hidden = true;
		entry.trigger.setAttribute( 'aria-expanded', 'false' );

		if ( openMenu === entry ) {
			openMenu = null;
		}

		if ( returnFocus ) {
			entry.trigger.focus();
		}
	}

	function openMenuEntry( entry ) {
		if ( openMenu && openMenu !== entry ) {
			closeMenu( openMenu, false );
		}

		entry.menu.hidden = false;
		entry.trigger.setAttribute( 'aria-expanded', 'true' );
		openMenu = entry;

		keepMenuOnScreen( entry.menu );

		var items = menuItems( entry.menu );
		if ( ! items.length ) {
			return;
		}

		// Single-choice menus open on the selected item.
		var checked = items.filter( function ( item ) {
			return 'true' === item.getAttribute( 'aria-checked' );
		} );

		( checked[ 0 ] || items[ 0 ] ).focus();
	}

	function moveFocus( items, current, delta ) {
		if ( ! items.length ) {
			return;
		}
		var index = items.indexOf( current );
		var next = ( index + delta + items.length ) % items.length;
		items[ next ].focus();
	}

	function setupMenus( viewer ) {
		var entries = {};

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-menu-trigger]' ),
			function ( trigger ) {
				var name = trigger.getAttribute( 'data-jimca-menu-trigger' );
				var menu = viewer.querySelector( '[data-jimca-menu="' + name + '"]' );

				if ( ! menu ) {
					return;
				}

				var entry = { trigger: trigger, menu: menu, name: name };
				entries[ name ] = entry;

				trigger.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					event.stopPropagation();

					if ( openMenu === entry ) {
						closeMenu( entry, true );
					} else {
						openMenuEntry( entry );
					}
				} );

				trigger.addEventListener( 'keydown', function ( event ) {
					if ( 'ArrowUp' === event.key || 'ArrowDown' === event.key ) {
						event.preventDefault();
						openMenuEntry( entry );
					}
				} );

				menu.addEventListener( 'keydown', function ( event ) {
					var items = menuItems( menu );

					if ( 'Escape' === event.key ) {
						event.preventDefault();
						closeMenu( entry, true );
					} else if ( 'ArrowDown' === event.key ) {
						event.preventDefault();
						moveFocus( items, document.activeElement, 1 );
					} else if ( 'ArrowUp' === event.key ) {
						event.preventDefault();
						moveFocus( items, document.activeElement, -1 );
					} else if ( 'Home' === event.key ) {
						event.preventDefault();
						if ( items[ 0 ] ) {
							items[ 0 ].focus();
						}
					} else if ( 'End' === event.key ) {
						event.preventDefault();
						if ( items.length ) {
							items[ items.length - 1 ].focus();
						}
					} else if ( 'Tab' === event.key ) {
						// Leaving the menu with Tab closes it, as the pattern says.
						closeMenu( entry, false );
					}
				} );

				// Clicks inside the menu must not reach the document and close it
				// before the item itself is handled.
				menu.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
				} );
			}
		);

		return entries;
	}

	/* ---------------------------------------------------------------
	 * Text size, theme and contrast
	 * ------------------------------------------------------------- */

	function setupAppearance( viewer, prefs, entries ) {
		var content = viewer.querySelector( '[data-jimca-content]' );
		var contrastBtn = viewer.querySelector( '[data-jimca-contrast-toggle]' );
		var scale = parseFloat( prefs.get( 'scale', 1 ) ) || 1;

		function applyScale() {
			if ( content ) {
				content.style.fontSize = scale + 'em';
			}
		}

		function applyTheme( theme ) {
			if ( ! theme ) {
				return;
			}

			// Remove the current jimca-theme-* before adding the new one (the
			// site default is already one of these classes, rendered by PHP).
			viewer.className = viewer.className.replace( /\bjimca-theme-\S+/g, '' ).trim();
			viewer.classList.add( 'jimca-theme-' + theme );

			Array.prototype.forEach.call(
				viewer.querySelectorAll( '[data-jimca-theme]' ),
				function ( item ) {
					item.setAttribute(
						'aria-checked',
						item.getAttribute( 'data-jimca-theme' ) === theme ? 'true' : 'false'
					);
				}
			);
		}

		applyScale();

		if ( prefs.get( 'theme', '' ) ) {
			applyTheme( prefs.get( 'theme', '' ) );
		}

		if ( prefs.get( 'contrast', false ) ) {
			viewer.classList.add( 'jimca-high-contrast' );
			if ( contrastBtn ) {
				contrastBtn.setAttribute( 'aria-pressed', 'true' );
			}
		}

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-font]' ),
			function ( btn ) {
				btn.addEventListener( 'click', function () {
					var action = btn.getAttribute( 'data-jimca-font' );

					if ( 'increase' === action ) {
						scale = Math.min( 2, Math.round( ( scale + 0.1 ) * 10 ) / 10 );
					} else if ( 'decrease' === action ) {
						scale = Math.max( 0.75, Math.round( ( scale - 0.1 ) * 10 ) / 10 );
					} else {
						scale = 1;
					}

					applyScale();
					prefs.set( 'scale', scale );

					/*
					 * The menu stays open on purpose: enlarging text is
					 * incremental, and closing on every tap would mean reopening
					 * the menu for each step.
					 */
				} );
			}
		);

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-theme]' ),
			function ( btn ) {
				btn.addEventListener( 'click', function () {
					var theme = btn.getAttribute( 'data-jimca-theme' );
					applyTheme( theme );
					prefs.set( 'theme', theme );
					closeMenu( entries.font, true );
				} );
			}
		);

		if ( contrastBtn ) {
			contrastBtn.addEventListener( 'click', function () {
				var isOn = viewer.classList.toggle( 'jimca-high-contrast' );
				contrastBtn.setAttribute( 'aria-pressed', isOn ? 'true' : 'false' );
				prefs.set( 'contrast', isOn );
			} );
		}
	}

	/* ---------------------------------------------------------------
	 * Table of contents
	 * ------------------------------------------------------------- */

	/**
	 * Turns the table of contents (a list before the text without JS) into a
	 * side panel opened and closed from the reading bar.
	 *
	 * The panel does not trap focus (it is not a modal dialog): readers can go
	 * back to the text with Tab or a click. Esc, an outside click or choosing
	 * an item close it. Choosing an item moves focus to that heading, so the
	 * screen reader goes on reading from there.
	 */
	function setupToc( viewer ) {
		var nav = viewer.querySelector( '[data-jimca-toc]' );
		var toggle = viewer.querySelector( '[data-jimca-toc-toggle]' );
		var closeBtn = viewer.querySelector( '[data-jimca-toc-close]' );

		if ( ! nav || ! toggle ) {
			return;
		}

		var links = Array.prototype.slice.call( nav.querySelectorAll( '[data-jimca-toc-link]' ) );

		// After a click in the panel, the clicked item stays current until scrolling ends.
		var pinnedUntil = 0;

		viewer.classList.add( 'jimca-toc-enhanced' );
		nav.hidden = true;
		if ( closeBtn ) {
			closeBtn.hidden = false;
		}

		function isOpen() {
			return ! nav.hidden;
		}

		function open() {
			if ( openMenu ) {
				closeMenu( openMenu, false );
			}

			nav.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );

			// Opens on the item of the section being read (or the first one).
			var current = nav.querySelector( '[aria-current="location"]' ) || links[ 0 ];
			if ( current ) {
				current.scrollIntoView( { block: 'nearest' } );
				current.focus();
			}
		}

		function close( returnFocus ) {
			if ( ! isOpen() ) {
				return;
			}

			nav.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );

			if ( returnFocus ) {
				toggle.focus();
			}
		}

		function markCurrent( id ) {
			links.forEach( function ( link ) {
				if ( link.getAttribute( 'href' ) === '#' + id ) {
					link.setAttribute( 'aria-current', 'location' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		}

		toggle.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			if ( isOpen() ) {
				close( true );
			} else {
				open();
			}
		} );

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				close( true );
			} );
		}

		nav.addEventListener( 'click', function ( event ) {
			event.stopPropagation();

			var link = event.target.closest ? event.target.closest( '[data-jimca-toc-link]' ) : null;
			if ( ! link ) {
				return;
			}

			var id = link.getAttribute( 'href' ).slice( 1 );
			var target = document.getElementById( id );
			if ( ! target ) {
				return;
			}

			event.preventDefault();
			close( false );
			markCurrent( id );
			pinnedUntil = Date.now() + 1200;

			// Headings are not focusable by default; tabindex -1 allows focus without entering the Tab order.
			if ( ! target.hasAttribute( 'tabindex' ) ) {
				target.setAttribute( 'tabindex', '-1' );
			}
			target.scrollIntoView( { behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' } );
			target.focus( { preventScroll: true } );
		} );

		nav.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				close( true );
			}
		} );

		document.addEventListener( 'click', function () {
			close( false );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && isOpen() ) {
				close( true );
			}
		} );

		/*
		 * Marks the section being read: the last heading that has passed the
		 * top third of the screen.
		 */
		if ( 'IntersectionObserver' in window ) {
			var headings = links
				.map( function ( link ) {
					return document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );
				} )
				.filter( Boolean );

			var observer = new window.IntersectionObserver(
				function () {
					if ( Date.now() < pinnedUntil ) {
						return;
					}

					var limit = window.innerHeight / 3;
					var current = null;

					headings.forEach( function ( heading ) {
						if ( heading.getBoundingClientRect().top <= limit ) {
							current = heading;
						}
					} );

					if ( current ) {
						markCurrent( current.id );
					}
				},
				{ rootMargin: '0px 0px -66% 0px' }
			);

			headings.forEach( function ( heading ) {
				observer.observe( heading );
			} );
		}
	}

	/* ---------------------------------------------------------------
	 * Hide / show the bar
	 * ------------------------------------------------------------- */

	function setupVisibilityToggle( viewer, player, prefs ) {
		var bar = player.querySelector( '[data-jimca-player-bar]' );
		var hideBtn = player.querySelector( '[data-jimca-hide]' );
		var showBtn = player.querySelector( '[data-jimca-show]' );

		if ( ! bar || ! hideBtn || ! showBtn ) {
			return;
		}

		function apply( hidden, moveFocusTo ) {
			bar.hidden = hidden;
			showBtn.hidden = ! hidden;

			/*
			 * `jimca-player-active` stays on even with the bar hidden: the
			 * restore button also floats over the text, and without the reserved
			 * space it would cover the last line of the document.
			 */

			if ( moveFocusTo ) {
				moveFocusTo.focus();
			}
		}

		apply( true === prefs.get( 'barHidden', false ), null );

		hideBtn.addEventListener( 'click', function () {
			if ( openMenu ) {
				closeMenu( openMenu, false );
			}
			prefs.set( 'barHidden', true );
			apply( true, showBtn );
		} );

		showBtn.addEventListener( 'click', function () {
			prefs.set( 'barHidden', false );
			apply( false, hideBtn );
		} );
	}

	/* ---------------------------------------------------------------
	 * Reading aloud
	 * ------------------------------------------------------------- */

	function setupAudio( viewer, player, prefs, entries ) {
		var playBtn = player.querySelector( '[data-jimca-play]' );
		var stopBtn = player.querySelector( '[data-jimca-stop]' );

		if ( ! playBtn || ! stopBtn ) {
			return;
		}

		var supported = ( 'speechSynthesis' in window ) &&
			typeof window.SpeechSynthesisUtterance !== 'undefined';

		if ( ! supported ) {
			/*
			 * Without browser support the audio controls go away instead of
			 * sitting there doing nothing. The visual settings (size, theme,
			 * contrast) keep working.
			 */
			Array.prototype.forEach.call(
				player.querySelectorAll( '[data-jimca-play], [data-jimca-stop], [data-jimca-menu-trigger="voice"], [data-jimca-menu-trigger="speed"]' ),
				function ( el ) {
					var slot = el.closest( '.jimca-player__slot' );
					if ( slot ) {
						slot.hidden = true;
					}
				}
			);
			return;
		}

		var synth = window.speechSynthesis;
		var content = viewer.querySelector( '[data-jimca-content]' );
		var statusEl = player.querySelector( '[data-jimca-status]' );
		var playIcon = playBtn.querySelector( '[data-jimca-icon="play"]' );
		var pauseIcon = playBtn.querySelector( '[data-jimca-icon="pause"]' );
		var playLabel = playBtn.querySelector( '[data-jimca-play-label]' );
		var voiceMenu = player.querySelector( '[data-jimca-menu="voice"]' );

		/*
		 * Images are read through their description (alt). Left out: decorative
		 * images (empty alt) and those still carrying the conversion's
		 * placeholder ("Image 2 on page 5 (no description)"): hearing that at
		 * every figure would only interrupt the text.
		 */
		var elements = Array.prototype.slice.call(
			content ? content.querySelectorAll( 'h1, h2, h3, h4, h5, h6, p, li, blockquote, figcaption, img[alt]:not([alt=""]):not([data-jimca-alt-missing])' ) : []
		);

		if ( ! elements.length && content ) {
			elements = [ content ];
		}

		var index = 0;
		var state = 'idle'; // idle | playing | paused
		var rate = parseFloat( prefs.get( 'rate', 1 ) ) || 1;
		var voiceName = prefs.get( 'voice', '' );

		function setStatus( text ) {
			if ( statusEl ) {
				statusEl.textContent = text;
			}
		}

		function clearHighlight() {
			elements.forEach( function ( el ) {
				el.classList.remove( 'jimca-reading' );
			} );
		}

		function updateUI() {
			var playing = 'playing' === state;

			playBtn.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );

			if ( playIcon && pauseIcon ) {
				playIcon.hidden = playing;
				pauseIcon.hidden = ! playing;
			}

			if ( playLabel ) {
				playLabel.textContent = playing
					? t( 'pause', 'Pause' )
					: ( 'paused' === state ? t( 'resume', 'Resume' ) : t( 'play', 'Listen' ) );
			}

			stopBtn.disabled = 'idle' === state;
		}

		function stopUI( statusText ) {
			synth.cancel();
			state = 'idle';
			index = 0;
			clearHighlight();
			updateUI();

			if ( statusText ) {
				setStatus( statusText );
			}
		}

		function currentVoice() {
			var voices = synth.getVoices();
			var match = voices.filter( function ( voice ) {
				return voice.name === voiceName;
			} );
			return match[ 0 ] || null;
		}

		function buildVoiceMenu() {
			var voices = synth.getVoices();

			if ( ! voiceMenu || ! voices.length ) {
				return;
			}

			voiceMenu.textContent = '';

			voices.forEach( function ( voice ) {
				var item = document.createElement( 'button' );
				item.type = 'button';
				item.className = 'jimca-player__menuitem';
				item.setAttribute( 'role', 'menuitemradio' );
				item.setAttribute( 'data-jimca-voice', voice.name );
				item.setAttribute( 'aria-checked', voice.name === voiceName ? 'true' : 'false' );
				item.textContent = voice.name + ' (' + voice.lang + ')';

				item.addEventListener( 'click', function () {
					voiceName = voice.name;
					prefs.set( 'voice', voiceName );

					Array.prototype.forEach.call(
						voiceMenu.querySelectorAll( '[data-jimca-voice]' ),
						function ( other ) {
							other.setAttribute(
								'aria-checked',
								other.getAttribute( 'data-jimca-voice' ) === voiceName ? 'true' : 'false'
							);
						}
					);

					closeMenu( entries.voice, true );
				} );

				voiceMenu.appendChild( item );
			} );
		}

		buildVoiceMenu();

		// In several browsers the voice list is only ready later.
		if ( typeof synth.onvoiceschanged !== 'undefined' ) {
			synth.addEventListener( 'voiceschanged', buildVoiceMenu );
		}

		// Speed.
		Array.prototype.forEach.call(
			player.querySelectorAll( '[data-jimca-rate]' ),
			function ( item ) {
				if ( parseFloat( item.getAttribute( 'data-jimca-rate' ) ) === rate ) {
					item.setAttribute( 'aria-checked', 'true' );
				} else {
					item.setAttribute( 'aria-checked', 'false' );
				}

				item.addEventListener( 'click', function () {
					rate = parseFloat( item.getAttribute( 'data-jimca-rate' ) ) || 1;
					prefs.set( 'rate', rate );

					Array.prototype.forEach.call(
						player.querySelectorAll( '[data-jimca-rate]' ),
						function ( other ) {
							other.setAttribute(
								'aria-checked',
								parseFloat( other.getAttribute( 'data-jimca-rate' ) ) === rate ? 'true' : 'false'
							);
						}
					);

					/*
					 * The Web Speech API does not change the `rate` of an utterance
					 * already being spoken, so the current passage restarts for the
					 * new speed to apply at once.
					 */
					if ( 'playing' === state ) {
						synth.cancel();
						speakNext();
					}

					closeMenu( entries.speed, true );
				} );
			}
		);

		function speakNext() {
			if ( index >= elements.length ) {
				state = 'idle';
				index = 0;
				clearHighlight();
				updateUI();
				setStatus( t( 'statusDone', 'Reading finished.' ) );
				return;
			}

			var el = elements[ index ];
			var text = 'IMG' === el.tagName
				? t( 'imageLabel', 'Image:' ) + ' ' + el.getAttribute( 'alt' ).trim()
				: el.textContent.trim();

			if ( '' === text ) {
				index++;
				speakNext();
				return;
			}

			var utterance = new window.SpeechSynthesisUtterance( text );
			var voice = currentVoice();

			if ( voice ) {
				utterance.voice = voice;
			}

			utterance.rate = rate;

			utterance.onend = function () {
				index++;
				speakNext();
			};

			utterance.onerror = function () {
				index++;
				speakNext();
			};

			clearHighlight();
			el.classList.add( 'jimca-reading' );
			el.scrollIntoView( { block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' } );

			synth.speak( utterance );
		}

		playBtn.addEventListener( 'click', function () {
			if ( 'playing' === state ) {
				synth.pause();
				state = 'paused';
				setStatus( t( 'statusPaused', 'Reading paused.' ) );
			} else if ( 'paused' === state ) {
				synth.resume();
				state = 'playing';
				setStatus( t( 'statusReading', 'Reading aloud.' ) );
				activeStop = stopUI;
			} else {
				if ( activeStop && activeStop !== stopUI ) {
					activeStop();
				}
				synth.cancel();
				index = 0;
				state = 'playing';
				setStatus( t( 'statusReading', 'Reading aloud.' ) );
				activeStop = stopUI;
				speakNext();
			}

			updateUI();
		} );

		stopBtn.addEventListener( 'click', function () {
			stopUI( t( 'statusStopped', 'Reading stopped.' ) );
		} );

		updateUI();
	}

	/* ---------------------------------------------------------------
	 * The bar only shows while the document is on screen
	 * ------------------------------------------------------------- */

	function setupPlayerVisibility( viewer, player ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			player.classList.add( 'is-visible' );
			return;
		}

		var observer = new window.IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					player.classList.toggle( 'is-visible', entry.isIntersecting );

					if ( ! entry.isIntersecting && openMenu ) {
						closeMenu( openMenu, false );
					}
				} );
			},
			// A small margin keeps the bar from flickering at the edge.
			{ rootMargin: '-40px 0px -40px 0px' }
		);

		observer.observe( viewer );
	}

	/* ---------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------- */

	function initViewer( viewer ) {
		var player = viewer.querySelector( '[data-jimca-player]' );

		if ( ! player ) {
			return;
		}

		// Only now does the player appear: with JS it really works.
		player.hidden = false;
		viewer.classList.add( 'jimca-player-active' );

		var prefs = createPrefs( viewer );
		var entries = setupMenus( viewer );

		setupAppearance( viewer, prefs, entries );
		setupToc( viewer );
		setupAudio( viewer, player, prefs, entries );
		setupVisibilityToggle( viewer, player, prefs );
		setupPlayerVisibility( viewer, player );
	}

	/*
	 * Real screen width for the CSS (`--jimca-vw`). Some themes and page
	 * elements widen the layout viewport on phones, so `100%` and `100vw`
	 * can be wider than the screen and the fixed bar and panels end up
	 * partly off it. On touch devices the narrower of the layout width and
	 * the screen width wins; `jimca-vp-expanded` marks the widened case so
	 * the bar hugs the left edge instead of the (off-screen) center.
	 */
	function syncViewportWidth() {
		var root = document.documentElement;
		var layoutW = window.innerWidth || root.clientWidth;
		var screenW = window.screen && window.screen.width ? window.screen.width : layoutW;
		var touch = ( 'ontouchstart' in window ) || ( navigator.maxTouchPoints > 0 );
		var width = touch ? Math.min( layoutW, screenW ) : layoutW;

		root.style.setProperty( '--jimca-vw', width + 'px' );
		root.classList.toggle( 'jimca-vp-expanded', touch && layoutW > width + 1 );
	}

	function init() {
		syncViewportWidth();
		window.addEventListener( 'resize', syncViewportWidth );
		window.addEventListener( 'orientationchange', syncViewportWidth );

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-jimca-viewer]' ),
			initViewer
		);

		// An outside click closes the open menu.
		document.addEventListener( 'click', function () {
			if ( openMenu ) {
				closeMenu( openMenu, false );
			}
		} );

		// Esc closes even with focus outside the menu.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && openMenu ) {
				closeMenu( openMenu, true );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
