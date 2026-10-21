/**
 * Reading player of a converted document: text size, theme, high contrast
 * and reading aloud (Web Speech API).
 *
 * Progressive enhancement: without JS, or without browser support, the
 * content stays fully readable as plain text; the player keeps its `hidden`
 * attribute from the HTML and is never revealed.
 *
 * Popovers (menus and small panels) open from their button on the bar, one at
 * a time, close on Esc, an outside click or when Tab leaves them, and give
 * focus back to the button that opened them. A blob slides behind the button
 * whose popover is open (the "liquid" tab bar of the design reference).
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

		// Measured without the opening animation, which starts smaller.
		menu.style.animation = 'none';
		var rect = menu.getBoundingClientRect();
		menu.style.animation = '';
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

	/** Controls of a popover that is a panel (selects, buttons) instead of a list of menu items. */
	function focusables( menu ) {
		return Array.prototype.slice.call(
			menu.querySelectorAll( 'button:not([disabled]):not([aria-disabled="true"]), select:not([disabled]), input:not([disabled])' )
		).filter( function ( el ) {
			return ! el.closest( '[hidden]' );
		} );
	}

	/**
	 * Moves the blob behind the given button (or hides it): a pill that glides
	 * with a little overshoot, like the active tab of the reference.
	 */
	function moveBlob( viewer, target ) {
		var blob = viewer.querySelector( '[data-jimca-blob]' );
		var bar = viewer.querySelector( '[data-jimca-player-bar]' );

		if ( ! blob || ! bar ) {
			return;
		}

		if ( ! target ) {
			blob.classList.remove( 'is-on' );
			return;
		}

		var b = bar.getBoundingClientRect();
		var r = target.getBoundingClientRect();

		blob.style.width = Math.round( r.width ) + 'px';
		blob.style.height = Math.round( r.height ) + 'px';
		blob.style.transform = 'translate(' + Math.round( r.left - b.left ) + 'px, ' + Math.round( r.top - b.top ) + 'px)';
		blob.classList.add( 'is-on' );
	}

	function closeMenu( entry, returnFocus ) {
		if ( ! entry ) {
			return;
		}

		entry.menu.hidden = true;
		entry.trigger.setAttribute( 'aria-expanded', 'false' );

		if ( openMenu === entry ) {
			openMenu = null;

			var owner = entry.trigger.closest( '[data-jimca-viewer]' );
			if ( owner ) {
				moveBlob( owner, null );
			}
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

		var owner = entry.trigger.closest( '[data-jimca-viewer]' );
		if ( owner ) {
			// The search and contents panels give way to a popover.
			owner.dispatchEvent( new window.CustomEvent( 'jimca:panel-open', { detail: { name: 'menu' } } ) );
			moveBlob( owner, entry.trigger );
		}

		var items = menuItems( entry.menu );
		if ( ! items.length ) {
			// A panel: focus goes to its first control.
			var first = focusables( entry.menu )[ 0 ];
			if ( first ) {
				first.focus();
			}
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
					} else if ( ! items.length ) {
						// A panel: Tab walks through its controls and closes it on leaving the first or the last.
						if ( 'Tab' === event.key ) {
							var controls = focusables( menu );
							var at = controls.indexOf( document.activeElement );

							if ( ( event.shiftKey && 0 === at ) || ( ! event.shiftKey && at === controls.length - 1 ) ) {
								closeMenu( entry, false );
							}
						}
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

	function setupAppearance( viewer, prefs ) {
		var content = viewer.querySelector( '[data-jimca-content]' );
		var valueBtn = viewer.querySelector( '[data-jimca-font-value]' );
		var minusBtn = viewer.querySelector( '[data-jimca-font="decrease"]' );
		var plusBtn = viewer.querySelector( '[data-jimca-font="increase"]' );
		var radios = Array.prototype.slice.call( viewer.querySelectorAll( 'input[data-jimca-theme]' ) );
		var scale = parseFloat( prefs.get( 'scale', 1 ) ) || 1;
		var contrast = true === prefs.get( 'contrast', false );
		var currentTheme = ( viewer.className.match( /\bjimca-theme-(\S+)/ ) || [] )[ 1 ] || 'light';
		var statusEl = viewer.querySelector( '[data-jimca-status]' );

		function applyScale( announce ) {
			var percent = Math.round( scale * 100 );

			if ( content ) {
				content.style.fontSize = scale + 'em';
			}

			if ( valueBtn ) {
				valueBtn.textContent = percent + '%';
				valueBtn.setAttribute( 'aria-label', t( 'resetFont', 'Default text size' ) + ' (' + percent + '%)' );
			}

			// The ends are marked, not disabled: a disabled button would drop the focus.
			if ( minusBtn ) {
				minusBtn.setAttribute( 'aria-disabled', scale <= 0.75 ? 'true' : 'false' );
			}
			if ( plusBtn ) {
				plusBtn.setAttribute( 'aria-disabled', scale >= 2 ? 'true' : 'false' );
			}

			if ( announce && statusEl ) {
				statusEl.textContent = t( 'textSize', 'Text size' ) + ': ' + percent + '%';
			}
		}

		function applyTheme() {
			// Remove the current jimca-theme-* before adding the new one (the
			// site default is already one of these classes, rendered by PHP).
			viewer.className = viewer.className.replace( /\bjimca-theme-\S+/g, '' ).trim();
			viewer.classList.add( 'jimca-theme-' + currentTheme );
			viewer.classList.toggle( 'jimca-high-contrast', contrast );

			// High contrast is one of the choices of the theme list; it sits on top of the theme chosen before.
			radios.forEach( function ( radio ) {
				radio.checked = radio.value === ( contrast ? 'high-contrast' : currentTheme );
			} );
		}

		if ( prefs.get( 'theme', '' ) ) {
			currentTheme = prefs.get( 'theme', '' );
		}

		applyScale( false );
		applyTheme();

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-font]' ),
			function ( btn ) {
				btn.addEventListener( 'click', function () {
					var action = btn.getAttribute( 'data-jimca-font' );

					if ( 'true' === btn.getAttribute( 'aria-disabled' ) ) {
						return;
					}

					if ( 'increase' === action ) {
						scale = Math.min( 2, Math.round( ( scale + 0.1 ) * 10 ) / 10 );
					} else if ( 'decrease' === action ) {
						scale = Math.max( 0.75, Math.round( ( scale - 0.1 ) * 10 ) / 10 );
					} else {
						scale = 1;
					}

					applyScale( true );
					prefs.set( 'scale', scale );

					/*
					 * The popover stays open on purpose: enlarging text is
					 * incremental, and closing on every tap would mean reopening
					 * it for each step.
					 */
				} );
			}
		);

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( ! radio.checked ) {
					return;
				}

				if ( 'high-contrast' === radio.value ) {
					contrast = true;
				} else {
					contrast = false;
					currentTheme = radio.value;
					prefs.set( 'theme', currentTheme );
				}

				prefs.set( 'contrast', contrast );
				applyTheme();
			} );
		} );
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
		// "Contents" is an item of the navigation popover, which is closed by then: focus goes back to the button of the menu.
		var home = viewer.querySelector( '[data-jimca-menu-trigger="nav"]' ) || toggle;

		if ( ! nav || ! toggle ) {
			return;
		}

		var links = Array.prototype.slice.call( nav.querySelectorAll( '[data-jimca-toc-link]' ) );

		// In a document in parts the href also carries the part (for no-JS), so the id is read from data-id.
		function idOf( link ) {
			return link.getAttribute( 'data-id' ) || link.getAttribute( 'href' ).slice( 1 );
		}

		// After a click in the panel, the clicked item stays current until scrolling ends.
		var pinnedUntil = 0;

		viewer.classList.add( 'jimca-toc-enhanced' );
		nav.hidden = true;

		// Another panel (search) or a popover takes the place of the contents.
		viewer.addEventListener( 'jimca:panel-open', function ( event ) {
			if ( event.detail && 'toc' !== event.detail.name ) {
				close( false );
			}
		} );

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

			viewer.dispatchEvent( new window.CustomEvent( 'jimca:panel-open', { detail: { name: 'toc' } } ) );
			nav.hidden = false;
			moveBlob( viewer, home );
			if ( toggle.hasAttribute( 'aria-expanded' ) ) {
				toggle.setAttribute( 'aria-expanded', 'true' );
			}

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
			if ( ! openMenu ) {
				moveBlob( viewer, null );
			}
			if ( toggle.hasAttribute( 'aria-expanded' ) ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}

			if ( returnFocus ) {
				home.focus();
			}
		}

		function markCurrent( id ) {
			links.forEach( function ( link ) {
				if ( idOf( link ) === id ) {
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

			var id = idOf( link );
			var target = document.getElementById( id );
			var part = parseInt( link.getAttribute( 'data-part' ) || '0', 10 );

			// A heading in another part of the document: open that part, then go to the heading.
			if ( ! target && part && viewer.jimcaPager ) {
				event.preventDefault();
				close( false );
				markCurrent( id );
				pinnedUntil = Date.now() + 1500;
				viewer.jimcaPager.goTo( part, { id: id } ).catch( function () {} );
				return;
			}

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
		var observer = null;

		// Only the headings of the part on the page exist; the list is looked at again when the part changes.
		function watch() {
			if ( ! ( 'IntersectionObserver' in window ) ) {
				return;
			}

			if ( observer ) {
				observer.disconnect();
			}

			var headings = links
				.map( function ( link ) {
					return document.getElementById( idOf( link ) );
				} )
				.filter( Boolean );

			observer = new window.IntersectionObserver(
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

		watch();
		viewer.addEventListener( 'jimca:content-changed', watch );
	}

	/* ---------------------------------------------------------------
	 * Search panel
	 * ------------------------------------------------------------- */

	/**
	 * The search form (a block above the text without the bar) becomes a panel
	 * that floats over the bar, opened from the navigation menu. pager.js runs
	 * the search itself; here only the opening and closing live.
	 */
	function setupSearchPanel( viewer ) {
		var form = viewer.querySelector( '[data-jimca-search]' );
		var openBtn = viewer.querySelector( '[data-jimca-search-open]' );
		var closeBtn = viewer.querySelector( '[data-jimca-search-close]' );
		var input = form ? form.querySelector( '[data-jimca-search-input]' ) : null;
		var home = viewer.querySelector( '[data-jimca-menu-trigger="nav"]' ) || openBtn;

		if ( ! form || ! openBtn || ! input ) {
			return;
		}

		viewer.classList.add( 'jimca-search-enhanced' );
		form.hidden = true;

		if ( closeBtn ) {
			closeBtn.hidden = false;
		}

		function isOpen() {
			return ! form.hidden;
		}

		function open() {
			if ( openMenu ) {
				closeMenu( openMenu, false );
			}

			viewer.dispatchEvent( new window.CustomEvent( 'jimca:panel-open', { detail: { name: 'search' } } ) );

			// Just above the bar, whatever its height (it has two rows on a very narrow screen).
			var bar = viewer.querySelector( '[data-jimca-player-bar]' );
			if ( bar && ! bar.hidden ) {
				form.style.bottom = Math.round( window.innerHeight - bar.getBoundingClientRect().top + 8 ) + 'px';
			}

			form.hidden = false;
			moveBlob( viewer, home );
			input.focus();
		}

		function close( returnFocus ) {
			if ( ! isOpen() ) {
				return;
			}

			form.hidden = true;
			if ( ! openMenu ) {
				moveBlob( viewer, null );
			}

			if ( returnFocus ) {
				home.focus();
			}
		}

		openBtn.addEventListener( 'click', function () {
			open();
		} );

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				close( true );
			} );
		}

		// Clicks inside the panel are not "outside" clicks.
		form.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
		} );

		// pager.js keeps Esc for clearing the field while it has text; an empty field closes the panel.
		form.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				close( true );
			}
		} );

		document.addEventListener( 'click', function () {
			close( false );
		} );

		// Choosing a result moves the reader to the passage: the panel gets out of the way.
		viewer.addEventListener( 'jimca:search-chosen', function () {
			close( false );
		} );

		viewer.addEventListener( 'jimca:panel-open', function ( event ) {
			if ( event.detail && 'search' !== event.detail.name ) {
				close( false );
			}
		} );
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
			// Panels that hang from the bar (search, contents) go with it.
			viewer.dispatchEvent( new window.CustomEvent( 'jimca:panel-open', { detail: { name: 'hide' } } ) );
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
		var speedBtn = player.querySelector( '[data-jimca-speed]' );

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
				player.querySelectorAll( '[data-jimca-play], [data-jimca-stop], [data-jimca-speed]' ),
				function ( el ) {
					var slot = el.closest( '.jimca-player__slot' );
					if ( slot ) {
						slot.hidden = true;
					}
				}
			);
			// The voice part of the settings is not there either (the theme stays).
			Array.prototype.forEach.call( player.querySelectorAll( '[data-jimca-voice-group]' ), function ( group ) {
				group.remove();
			} );
			return;
		}

		var synth = window.speechSynthesis;
		var content = viewer.querySelector( '[data-jimca-content]' );
		var statusEl = player.querySelector( '[data-jimca-status]' );
		var playIcon = playBtn.querySelector( '[data-jimca-icon="play"]' );
		var pauseIcon = playBtn.querySelector( '[data-jimca-icon="pause"]' );
		var playLabel = playBtn.querySelector( '[data-jimca-play-label]' );
		var voiceGroup = player.querySelector( '[data-jimca-voice-group]' );

		/*
		 * Images are read through their description (alt). Left out: decorative
		 * images (empty alt) and those still carrying the conversion's
		 * placeholder ("Image 2 on page 5 (no description)"): hearing that at
		 * every figure would only interrupt the text.
		 */
		function collectElements() {
			var found = Array.prototype.slice.call(
				content ? content.querySelectorAll( 'h1, h2, h3, h4, h5, h6, p, li, blockquote, figcaption, img[alt]:not([alt=""]):not([data-jimca-alt-missing])' ) : []
			);

			if ( ! found.length && content ) {
				found = [ content ];
			}

			return found;
		}

		var elements = collectElements();

		var index = 0;
		var state = 'idle'; // idle | playing | paused

		/*
		 * Identifies the current reading. speechSynthesis.cancel() makes the
		 * browser fire `error` ("canceled"/"interrupted") on the utterance it
		 * cut, AFTER our code has already moved on. Without this token that
		 * late event advanced the reading and spoke the next passage right
		 * after Stop (or after a speed change or a new start). Every handler
		 * of an utterance acts only while its token is still the current one.
		 */
		var run = 0;

		/** Cuts the speech and makes every pending event of it harmless. */
		function cutSpeech() {
			run++;
			synth.cancel();
			// Some browsers stay paused after a cancel() made while paused, and the next reading would be silent.
			synth.resume();
		}

		/*
		 * Where reading begins: the passage the reader is at, not the top of
		 * the document. In order: the start of a text selection inside the
		 * document, the element that has focus inside it (a heading reached
		 * from the contents or a skip link), the first element on screen, or
		 * the first one when the document is below the screen.
		 */
		function startIndex() {
			if ( ! content ) {
				return 0;
			}

			function indexWithin( node ) {
				var el = 3 === node.nodeType ? node.parentNode : node;
				for ( var i = 0; i < elements.length; i++ ) {
					if ( elements[ i ] === el || elements[ i ].contains( el ) || ( el.contains && el.contains( elements[ i ] ) && el !== content ) ) {
						return i;
					}
				}
				return -1;
			}

			var selection = window.getSelection ? window.getSelection() : null;
			if ( selection && selection.rangeCount && ! selection.isCollapsed && content.contains( selection.anchorNode ) ) {
				var fromSelection = indexWithin( selection.getRangeAt( 0 ).startContainer );
				if ( fromSelection > -1 ) {
					return fromSelection;
				}
			}

			var active = document.activeElement;
			if ( active && active !== content && content.contains( active ) ) {
				var fromFocus = indexWithin( active );
				if ( fromFocus > -1 ) {
					return fromFocus;
				}
			}

			for ( var j = 0; j < elements.length; j++ ) {
				var rect = elements[ j ].getBoundingClientRect();
				// First element still (mostly) in view, below any fixed header.
				if ( rect.bottom > 48 && rect.top < window.innerHeight ) {
					return j;
				}
				if ( rect.top >= window.innerHeight ) {
					break;
				}
			}

			return 0;
		}
		var rate = parseFloat( prefs.get( 'rate', speedBtn ? parseFloat( speedBtn.getAttribute( 'data-rate' ) ) || 1 : 1 ) ) || 1;
		var voiceName = prefs.get( 'voice', '' );
		var voiceLang = prefs.get( 'voiceLang', '' );
		var voiceKind = prefs.get( 'voiceKind', '' );
		var langKnown = false; // The language is the reader's choice or the page's: only then it is passed to the voice.

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

			/*
			 * Play and pause are one button whose NAME changes ("Listen", "Pause",
			 * "Resume"): with the name changing, aria-pressed would say the same
			 * thing twice.
			 */
			playBtn.classList.toggle( 'is-playing', playing );

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
			cutSpeech();
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

		/*
		 * Voice settings: language, type of voice (when the browser's names tell
		 * it) and narrator. The browser lists its own voices and does not say
		 * whether a voice is female or male, so the type is only offered when the
		 * names reveal it (a word such as "Female" or a well-known first name);
		 * the voices that do not say stay under "All voices".
		 */
		var FEMALE = /\b(female|feminina?|femme|weiblich|mujer|mulher|maria|francisca|luciana|joana|helena|vitoria|camila|thalita|fernanda|zira|hedda|katja|hortense|denise|elvira|paulina|monica|samantha|victoria|karen|tessa|moira|susan|hazel|jenny|aria|sabina|lekha|kalpana|heera|swara|xiaoxiao|xiaoyi|huihui|yaoyao|milena|svetlana|tatyana|irina)\b/i;
		var MALE = /\b(male|masculino|homme|mannlich|hombre|homem|daniel|antonio|heitor|felipe|guy|david|mark|stefan|conrad|pablo|raul|jorge|diego|thomas|george|ryan|brian|fred|ricardo|rishi|hemant|ravi|kangkang|yunyang|yunxi|dmitri|pavel|yuri|maxim|markus|killian|alain|henri|luca)\b/i;

		function genderOf( voice ) {
			var name = String( voice.name || '' );

			// Without accents, so "M\u00f3nica" and "Vit\u00f3ria" match the lists.
			if ( name.normalize ) {
				name = name.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
			}

			// "Male" is inside "Female": the female test goes first.
			if ( FEMALE.test( name ) ) {
				return 'female';
			}

			return MALE.test( name ) ? 'male' : '';
		}

		function langLabel( code ) {
			try {
				return new window.Intl.DisplayNames( [ document.documentElement.lang || 'en' ], { type: 'language' } ).of( code ) || code;
			} catch ( e ) {
				return code;
			}
		}

		function option( value, text, selected ) {
			var item = document.createElement( 'option' );

			item.value = value;
			item.textContent = text;
			item.selected = !! selected;

			return item;
		}

		function voiceCode( voice ) {
			return String( voice.lang || '' ).replace( '_', '-' );
		}

		/** The language to start with: the one saved, else the page's, else the browser's. */
		function startLanguage( codes ) {
			var named = synth.getVoices().filter( function ( voice ) {
				return voice.name === voiceName;
			} )[ 0 ];

			if ( named && codes.indexOf( voiceCode( named ) ) > -1 ) {
				return voiceCode( named );
			}

			var wanted = [ viewer.getAttribute( 'data-jimca-lang' ), document.documentElement.lang, window.navigator.language ].filter( Boolean );

			for ( var i = 0; i < wanted.length; i++ ) {
				var exact = codes.filter( function ( code ) {
					return code.toLowerCase() === wanted[ i ].toLowerCase();
				} )[ 0 ];
				var primary = exact || codes.filter( function ( code ) {
					return code.split( '-' )[ 0 ].toLowerCase() === wanted[ i ].split( '-' )[ 0 ].toLowerCase();
				} )[ 0 ];

				if ( primary ) {
					langKnown = true;
					return primary;
				}
			}

			return codes[ 0 ];
		}

		function fillVoices() {
			var langSel = voiceGroup.querySelector( '[data-jimca-voice-lang]' );
			var kindSel = voiceGroup.querySelector( '[data-jimca-voice-kind]' );
			var kindWrap = voiceGroup.querySelector( '[data-jimca-voice-kind-wrap]' );
			var nameSel = voiceGroup.querySelector( '[data-jimca-voice-name]' );
			var inLang = synth.getVoices().filter( function ( voice ) {
				return voiceCode( voice ) === voiceLang;
			} );
			var kinds = { female: false, male: false };

			inLang.forEach( function ( voice ) {
				var kind = genderOf( voice );
				if ( kind ) {
					kinds[ kind ] = true;
				}
			} );

			kindSel.textContent = '';
			kindSel.appendChild( option( '', t( 'voiceAll', 'All voices' ), '' === voiceKind ) );
			if ( kinds.female ) {
				kindSel.appendChild( option( 'female', t( 'voiceFemale', 'Female' ), 'female' === voiceKind ) );
			}
			if ( kinds.male ) {
				kindSel.appendChild( option( 'male', t( 'voiceMale', 'Male' ), 'male' === voiceKind ) );
			}

			if ( voiceKind && ! kinds[ voiceKind ] ) {
				voiceKind = '';
				kindSel.value = '';
			}

			// The type of voice is offered only when the names tell it.
			kindWrap.hidden = ! ( kinds.female || kinds.male );

			var shown = voiceKind ? inLang.filter( function ( voice ) {
				return genderOf( voice ) === voiceKind;
			} ) : inLang;

			if ( voiceName && ! shown.some( function ( voice ) {
				return voice.name === voiceName;
			} ) ) {
				voiceName = '';
				prefs.set( 'voice', '' );
			}

			nameSel.textContent = '';
			nameSel.appendChild( option( '', t( 'voiceAuto', 'Automatic' ), '' === voiceName ) );
			shown.forEach( function ( voice ) {
				nameSel.appendChild( option( voice.name, voice.name, voice.name === voiceName ) );
			} );

			langSel.value = voiceLang;
		}

		function buildVoiceUI() {
			var voices = synth.getVoices();

			if ( ! voiceGroup || ! voices.length ) {
				return;
			}

			var langSel = voiceGroup.querySelector( '[data-jimca-voice-lang]' );
			var seen = {};

			voices.forEach( function ( voice ) {
				if ( voiceCode( voice ) ) {
					seen[ voiceCode( voice ) ] = true;
				}
			} );

			var codes = Object.keys( seen ).sort( function ( a, b ) {
				return langLabel( a ).localeCompare( langLabel( b ) );
			} );

			if ( ! codes.length ) {
				return;
			}

			if ( codes.indexOf( voiceLang ) < 0 ) {
				voiceLang = startLanguage( codes );
			} else {
				langKnown = true;
			}

			langSel.textContent = '';
			codes.forEach( function ( code ) {
				langSel.appendChild( option( code, langLabel( code ) + ' (' + code + ')', code === voiceLang ) );
			} );

			fillVoices();
			voiceGroup.hidden = false;
		}

		if ( voiceGroup ) {
			voiceGroup.addEventListener( 'change', function ( event ) {
				var target = event.target;

				if ( target.hasAttribute( 'data-jimca-voice-lang' ) ) {
					voiceLang = target.value;
					langKnown = true;
					voiceName = '';
					prefs.set( 'voiceLang', voiceLang );
					prefs.set( 'voice', '' );
				} else if ( target.hasAttribute( 'data-jimca-voice-kind' ) ) {
					voiceKind = target.value;
					prefs.set( 'voiceKind', voiceKind );
				} else if ( target.hasAttribute( 'data-jimca-voice-name' ) ) {
					voiceName = target.value;
					prefs.set( 'voice', voiceName );
				}

				fillVoices();
			} );
		}

		buildVoiceUI();

		// In several browsers the voice list is only ready later.
		if ( typeof synth.onvoiceschanged !== 'undefined' ) {
			synth.addEventListener( 'voiceschanged', buildVoiceUI );
		}

		/*
		 * Speed: one click goes to the next value and back to the first after the
		 * last (1x, 1.25x, 1.5x, 2x, 1x): no menu to open. A saved speed outside
		 * the list (0.75x from an older version) goes to the next one above it.
		 */
		var RATES = [ 1, 1.25, 1.5, 2 ];

		function rateText( value ) {
			var text;

			try {
				text = value.toLocaleString( document.documentElement.lang || undefined, { maximumFractionDigits: 2 } );
			} catch ( e ) {
				text = String( value );
			}

			return text + '\u00d7';
		}

		function updateSpeedUI() {
			if ( ! speedBtn ) {
				return;
			}

			var valueEl = speedBtn.querySelector( '[data-jimca-speed-value]' );

			if ( valueEl ) {
				valueEl.textContent = rateText( rate );
			}

			speedBtn.setAttribute( 'data-rate', String( rate ) );
			speedBtn.setAttribute( 'aria-label', t( 'rateLabel', 'Reading speed' ) + ': ' + rateText( rate ) );
		}

		if ( speedBtn ) {
			updateSpeedUI();

			speedBtn.addEventListener( 'click', function () {
				var above = RATES.filter( function ( value ) {
					return value > rate + 0.001;
				} );

				rate = above.length ? above[ 0 ] : RATES[ 0 ];
				prefs.set( 'rate', rate );
				updateSpeedUI();
				setStatus( t( 'rateLabel', 'Reading speed' ) + ': ' + rateText( rate ) );

				/*
				 * The Web Speech API does not change the `rate` of an utterance
				 * already being spoken, so the current passage restarts for the
				 * new speed to apply at once.
				 */
				if ( 'playing' === state ) {
					cutSpeech();
					speakNext();
				}
			} );
		}

		function speakNext() {
			// End of a part of a long document: the next part is fetched and the reading goes on there.
			if ( index >= elements.length && viewer.jimcaPager && viewer.jimcaPager.hasNext() ) {
				var waiting = run;

				viewer.jimcaPager.next( { focus: false, auto: true } ).then( function () {
					if ( waiting === run && 'playing' === state ) {
						index = 0;
						speakNext();
					}
				} ).catch( function () {
					stopUI( t( 'statusStopped', 'Reading stopped.' ) );
				} );
				return;
			}

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
				utterance.lang = voice.lang;
			} else if ( voiceLang && langKnown ) {
				utterance.lang = voiceLang;
			}

			utterance.rate = rate;

			var mine = run;

			// A late event of a cut utterance, or one that arrives after Stop or Pause, does nothing.
			function next() {
				if ( mine !== run || 'playing' !== state ) {
					return;
				}
				index++;
				speakNext();
			}

			utterance.onend = next;
			utterance.onerror = next;

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
				cutSpeech();
				index = startIndex();
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

		// New text (another part of a long document): the elements to read are those of the new part.
		viewer.addEventListener( 'jimca:content-changed', function ( event ) {
			if ( ! ( event.detail && event.detail.auto ) && 'idle' !== state ) {
				stopUI( t( 'statusStopped', 'Reading stopped.' ) );
			}
			elements = collectElements();
			index = 0;
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

	/*
	 * Skip links: "reading controls" is revealed (the bar exists only with JS)
	 * and lands on the bar, or on the restore button when the bar is hidden;
	 * the contents link goes away, since the contents open from the bar.
	 */
	function setupSkipLinks( viewer, player ) {
		var controls = viewer.querySelector( '[data-jimca-skip-controls]' );
		var toc = viewer.querySelector( '[data-jimca-skip-toc]' );

		if ( toc && viewer.querySelector( '[data-jimca-toc-toggle]' ) ) {
			toc.parentNode.removeChild( toc );
		}

		if ( ! controls ) {
			return;
		}

		controls.hidden = false;
		controls.addEventListener( 'click', function ( event ) {
			var bar = player.querySelector( '[data-jimca-player-bar]' );
			var restore = player.querySelector( '[data-jimca-show]' );
			var target = bar && ! bar.hidden ? bar : restore;

			event.preventDefault();
			if ( target ) {
				// The player is only shown while the document is on screen.
				player.classList.add( 'is-visible' );
				target.focus();
			}
		} );
	}

	function initViewer( viewer ) {
		var player = viewer.querySelector( '[data-jimca-player]' );

		if ( ! player ) {
			return;
		}

		// Only now does the player appear: with JS it really works.
		player.hidden = false;
		viewer.classList.add( 'jimca-player-active' );

		setupSkipLinks( viewer, player );

		var prefs = createPrefs( viewer );
		var entries = setupMenus( viewer );

		setupAppearance( viewer, prefs );
		setupToc( viewer );
		setupSearchPanel( viewer );
		setupAudio( viewer, player, prefs, entries );

		// Choosing a part from the list closes its popover (pager.js moves to the part).
		viewer.addEventListener( 'change', function ( event ) {
			if ( event.target.hasAttribute && event.target.hasAttribute( 'data-jimca-pager-select' ) && entries.pager && ! entries.pager.menu.hidden ) {
				closeMenu( entries.pager, false );
			}
		} );
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
		// Visible height: `100vh` counts the area under the phone's address bar, which would hide the top of the tutor panel.
		root.style.setProperty( '--jimca-vh', ( window.innerHeight || root.clientHeight ) + 'px' );
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
