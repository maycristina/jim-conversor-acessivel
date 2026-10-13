/**
 * Guided tour of the "New Document" screen.
 *
 * A non-modal dialog that walks through the form one control at a time. Built
 * for keyboard and screen reader users first:
 * - the dialog has a name and a description, and takes focus at every step, so
 *   a screen reader announces the step;
 * - Back, Next and Skip are real buttons; Esc leaves the tour at any time;
 * - the highlighted control gets an outline (visible in forced-colors mode too),
 *   never a color alone, and the page is never covered or locked;
 * - nothing animates: the page jumps straight to the highlighted control;
 * - closing it, in any way, returns focus to where it was.
 *
 * The steps and texts come from PHP (window.jimcaTourConfig), already
 * translated. Texts are always set with textContent, never as HTML.
 *
 * Loaded in the browser it starts by itself when the config asks for it. As a
 * module (tests) it exports create().
 */
( function ( root, factory ) {
	'use strict';

	var api = factory();

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}

	if ( root && root.document ) {
		root.JimcaTour = api;
		api.boot( root );
	}
}( typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	var TARGET_CLASS = 'jimca-tour-target';

	function format( template, values ) {
		var next = 0;

		return String( template ).replace( /%(\d+\$)?s/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : next++;

			return String( values[ index ] );
		} );
	}

	function isVisible( el ) {
		if ( ! el || el.hidden ) {
			return false;
		}

		// getClientRects() is empty for elements that are not rendered (display: none, hidden parent).
		return !! ( el.getClientRects && el.getClientRects().length ) || !! el.offsetParent;
	}

	function el( doc, tag, className, text ) {
		var node = doc.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	/**
	 * @param {Document} doc
	 * @param {Object}   config steps[{target[], title, text, links[{label,url}]}], strings{...}, onClose(finished)
	 * @return {Object} start(), next(), back(), close(), isOpen(), index()
	 */
	function create( doc, config ) {
		var steps = ( config && config.steps ) || [];
		var strings = ( config && config.strings ) || {};
		var open = false;
		var current = 0;
		var box = null;
		var parts = {};
		var marked = null;
		var returnTo = null;

		function s( key, fallback ) {
			return strings[ key ] || fallback;
		}

		function build() {
			box = el( doc, 'div', 'jimca-tour' );
			box.id = 'jimca-tour';
			box.setAttribute( 'role', 'dialog' );
			// Non-modal on purpose: the page stays usable, so the person can try what a step describes.
			box.setAttribute( 'aria-modal', 'false' );
			box.setAttribute( 'aria-labelledby', 'jimca-tour-title' );
			box.setAttribute( 'aria-describedby', 'jimca-tour-body' );
			box.setAttribute( 'tabindex', '-1' );

			parts.progress = el( doc, 'p', 'jimca-tour__progress' );
			parts.title = el( doc, 'h2', 'jimca-tour__title' );
			parts.title.id = 'jimca-tour-title';
			parts.body = el( doc, 'p', 'jimca-tour__body' );
			parts.body.id = 'jimca-tour-body';
			parts.links = el( doc, 'ul', 'jimca-tour__links' );

			var actions = el( doc, 'div', 'jimca-tour__actions' );

			parts.back = el( doc, 'button', 'button', s( 'back', 'Back' ) );
			parts.back.type = 'button';
			parts.next = el( doc, 'button', 'button button-primary', s( 'next', 'Next' ) );
			parts.next.type = 'button';
			parts.skip = el( doc, 'button', 'button-link jimca-tour__skip', s( 'skip', 'Skip tour' ) );
			parts.skip.type = 'button';

			parts.back.addEventListener( 'click', function () {
				go( current - 1 );
			} );
			parts.next.addEventListener( 'click', function () {
				if ( current >= steps.length - 1 ) {
					close( true );
				} else {
					go( current + 1 );
				}
			} );
			parts.skip.addEventListener( 'click', function () {
				close( false );
			} );

			actions.appendChild( parts.back );
			actions.appendChild( parts.next );
			actions.appendChild( parts.skip );

			box.appendChild( parts.progress );
			box.appendChild( parts.title );
			box.appendChild( parts.body );
			box.appendChild( parts.links );
			box.appendChild( actions );

			doc.body.appendChild( box );
		}

		function unmark() {
			if ( marked ) {
				marked.classList.remove( TARGET_CLASS );
				marked = null;
			}
		}

		function findTarget( step ) {
			var selectors = step.target || [];

			for ( var i = 0; i < selectors.length; i++ ) {
				var found = doc.querySelector( selectors[ i ] );

				// A control inside a closed <details> (the advanced options) is shown by opening it.
				if ( found && 'SUMMARY' !== found.tagName ) {
					var details = found.closest ? found.closest( 'details' ) : null;

					while ( details ) {
						details.open = true;
						details = details.parentElement ? details.parentElement.closest( 'details' ) : null;
					}
				}

				if ( isVisible( found ) ) {
					return found;
				}
			}

			return null;
		}

		function go( index ) {
			if ( index < 0 || index >= steps.length ) {
				return;
			}

			current = index;

			var step = steps[ index ];
			var last = index === steps.length - 1;

			parts.progress.textContent = format( s( 'progress', 'Step %1$s of %2$s' ), [ index + 1, steps.length ] );
			parts.title.textContent = step.title || '';
			parts.body.textContent = step.text || '';

			while ( parts.links.firstChild ) {
				parts.links.removeChild( parts.links.firstChild );
			}

			( step.links || [] ).forEach( function ( link ) {
				var item = el( doc, 'li' );
				var a = el( doc, 'a', '', link.label );

				a.href = link.url;
				item.appendChild( a );
				parts.links.appendChild( item );
			} );
			parts.links.hidden = ! parts.links.firstChild;

			parts.back.hidden = 0 === index;
			parts.next.textContent = last ? s( 'finish', 'Finish' ) : s( 'next', 'Next' );
			parts.skip.hidden = last;

			unmark();

			var target = findTarget( step );

			if ( target ) {
				marked = target;
				target.classList.add( TARGET_CLASS );

				if ( target.scrollIntoView ) {
					target.scrollIntoView( { block: 'start', behavior: 'auto' } );
				}
			}

			box.classList.toggle( 'jimca-tour--centered', ! target );

			// Focus on the dialog makes a screen reader read its name and description.
			box.focus( { preventScroll: true } );
		}

		function onKeydown( event ) {
			if ( open && 'Escape' === event.key ) {
				event.preventDefault();
				close( false );
			}
		}

		function start() {
			if ( open || ! steps.length ) {
				return;
			}

			open = true;
			returnTo = doc.activeElement && doc.activeElement !== doc.body ? doc.activeElement : null;

			if ( ! box ) {
				build();
			}

			box.hidden = false;
			doc.addEventListener( 'keydown', onKeydown );
			go( 0 );
		}

		function close( finished ) {
			if ( ! open ) {
				return;
			}

			open = false;
			unmark();
			box.hidden = true;
			doc.removeEventListener( 'keydown', onKeydown );

			if ( returnTo && doc.contains( returnTo ) && returnTo.focus ) {
				returnTo.focus();
			}

			if ( config && 'function' === typeof config.onClose ) {
				config.onClose( !! finished );
			}
		}

		return {
			start: start,
			close: function () {
				close( false );
			},
			next: function () {
				go( current + 1 );
			},
			back: function () {
				go( current - 1 );
			},
			isOpen: function () {
				return open;
			},
			index: function () {
				return current;
			}
		};
	}

	/**
	 * In the browser: reads window.jimcaTourConfig, wires the "open the tour"
	 * buttons, tells the server when the tour ends (so it is not shown again by
	 * itself) and starts it when asked to.
	 */
	function boot( win ) {
		var config = win.jimcaTourConfig;

		if ( ! config ) {
			return;
		}

		function init() {
			var doc = win.document;
			var tour = create( doc, {
				steps: config.steps,
				strings: config.strings,
				onClose: function () {
					if ( ! win.fetch || ! win.FormData || ! config.ajaxUrl ) {
						return;
					}

					var data = new win.FormData();

					data.append( 'action', config.action );
					data.append( '_wpnonce', config.nonce );
					win.fetch( config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } ).catch( function () {} );
				}
			} );

			Array.prototype.forEach.call( doc.querySelectorAll( '[data-jimca-tour-start]' ), function ( button ) {
				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					tour.start();
				} );
			} );

			if ( config.autostart ) {
				tour.start();
			}

			win.jimcaTour = tour;
		}

		if ( 'loading' === win.document.readyState ) {
			win.document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}

	return { create: create, boot: boot, format: format };
} ) );
