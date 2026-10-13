/*
 * Long documents in parts, and the search inside a document.
 *
 * A long document is cut at the server (JIMCA_Pagination) so the page does not
 * carry tens of thousands of paragraphs. The page has one part; the reader moves
 * between parts with the previous / next links, the "Go to part" list or the
 * contents. Each part is fetched when needed (the next one is fetched in advance)
 * and replaces the text, so the page never holds more than one part. Without
 * JavaScript the same links reload the page on the chosen part.
 *
 * After a part changes, `jimca:content-changed` is fired on the viewer (detail:
 * { part, auto }) so reading aloud, the contents list, highlights and images
 * pick up the new text. Reading aloud asks for the next part when it reaches the
 * end of one (viewer.jimcaPager.next({ auto: true })).
 *
 * Search: in a document in parts it asks the server, which looks in the whole
 * document, and opens the part of the chosen result; in a short document it
 * looks in the text on the page.
 */
( function () {
	'use strict';

	var i18n = window.jimcaPagerI18n || {};
	var BLOCKS = 'h1, h2, h3, h4, h5, h6, p, li, figcaption';

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function fmt( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return template.replace( /%(?:(\d+)\$)?s/g, function ( all, position ) {
			var value = position ? args[ position - 1 ] : args[ next++ ];

			return undefined === value ? '' : value;
		} );
	}

	/** Lowercase and without accents, to compare "Moisés" with "moises". */
	function normalize( text ) {
		var plain = String( text );

		if ( plain.normalize ) {
			plain = plain.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		}

		return plain.toLowerCase();
	}

	function textOf( el ) {
		return ( el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function reducedMotion() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	/** Moves to an element, makes it the focus and flags it for a moment. */
	function reveal( el ) {
		if ( ! el ) {
			return;
		}

		if ( ! el.hasAttribute( 'tabindex' ) ) {
			el.setAttribute( 'tabindex', '-1' );
		}

		el.scrollIntoView( { block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' } );
		el.focus( { preventScroll: true } );
		el.classList.add( 'jimca-found' );
		window.setTimeout( function () {
			el.classList.remove( 'jimca-found' );
		}, 3500 );
	}

	function init( viewer ) {
		var content = viewer.querySelector( '[data-jimca-content]' );
		var doc = viewer.getAttribute( 'data-jimca-doc' );
		var total = parseInt( viewer.getAttribute( 'data-jimca-parts' ) || '0', 10 );
		var arg = viewer.getAttribute( 'data-jimca-part-arg' ) || '';
		var current = parseInt( viewer.getAttribute( 'data-jimca-part' ) || '1', 10 );
		var cache = {};
		var order = [];

		if ( ! content || ! doc ) {
			return;
		}

		/* -----------------------------------------------------------
		 * Parts
		 * --------------------------------------------------------- */

		function endpoint( params ) {
			var query = Object.keys( params ).map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
			} ).join( '&' );

			return ( i18n.ajaxUrl || '' ) + '?' + query;
		}

		function load( number ) {
			if ( cache[ number ] ) {
				return Promise.resolve( cache[ number ] );
			}

			return window.fetch( endpoint( { action: i18n.actionPart, doc: doc, part: number } ), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( answer ) {
					if ( ! answer || ! answer.success ) {
						throw new Error( 'part' );
					}

					// Only the nearby parts are kept: the point is not to hold the book.
					cache[ number ] = answer.data.html;
					order.push( number );
					while ( order.length > 3 ) {
						delete cache[ order.shift() ];
					}

					return answer.data.html;
				} );
		}

		function updateNav() {
			var label = fmt( t( 'partOf', 'Part %1$s of %2$s' ), current, total );

			Array.prototype.forEach.call( viewer.querySelectorAll( '[data-jimca-pager]' ), function ( nav ) {
				var status = nav.querySelector( '[data-jimca-pager-status]' );
				var prev = nav.querySelector( '[data-jimca-pager-prev]' );
				var next = nav.querySelector( '[data-jimca-pager-next]' );
				var select = nav.querySelector( '[data-jimca-pager-select]' );
				var count = nav.querySelector( '[data-jimca-pager-count]' );

				if ( status ) {
					status.textContent = label;
				}

				// The bar shows "2/93".
				if ( count ) {
					count.textContent = current.toLocaleString() + '/' + total.toLocaleString();
				}

				// The links keep their address (they work without the script); only the ends are switched off.
				[ [ prev, current > 1, current - 1, 'prev' ], [ next, current < total, current + 1, 'next' ] ].forEach( function ( item ) {
					var el = item[ 0 ];

					if ( ! el ) {
						return;
					}

					// Only the links of the page have an address; the buttons of the bar do not.
					var isLink = 'A' === el.tagName;

					if ( item[ 1 ] ) {
						el.setAttribute( 'data-jimca-part-link', item[ 2 ] );
						if ( isLink ) {
							el.setAttribute( 'href', partUrl( item[ 2 ] ) );
						}
						el.removeAttribute( 'aria-disabled' );
						el.classList.remove( 'is-disabled' );
					} else {
						if ( isLink ) {
							el.removeAttribute( 'href' );
						}
						el.removeAttribute( 'data-jimca-part-link' );
						el.setAttribute( 'aria-disabled', 'true' );
						el.classList.add( 'is-disabled' );
					}
				} );

				if ( select ) {
					select.value = String( current );
				}
			} );
		}

		function partUrl( number ) {
			var url = new window.URL( window.location.href );

			url.hash = '';
			url.searchParams.set( arg, number );

			return url.pathname + url.search;
		}

		/**
		 * Shows a part. options: id (heading to go to), focus (default true),
		 * auto (the reading aloud moved on by itself), push (default true: new history entry).
		 */
		function show( number, options ) {
			options = options || {};
			number = Math.max( 1, Math.min( total, number ) );

			return load( number ).then( function ( html ) {
				content.innerHTML = html;
				current = number;
				viewer.setAttribute( 'data-jimca-part', String( number ) );
				updateNav();

				if ( false !== options.push && window.history && window.history.pushState ) {
					window.history[ options.auto ? 'replaceState' : 'pushState' ]( { jimcaPart: number }, '', partUrl( number ) );
				}

				viewer.dispatchEvent( new window.CustomEvent( 'jimca:content-changed', { bubbles: true, detail: { part: number, auto: !! options.auto } } ) );

				if ( options.id ) {
					var target = document.getElementById( options.id );

					if ( target ) {
						reveal( target );
					}
				} else if ( false !== options.focus ) {
					// The top of the part; the text takes the focus. (The navigation floats in the bar.)
					content.focus( { preventScroll: true } );
					content.scrollIntoView( { block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' } );
				}

				// The next part is fetched while this one is read.
				if ( number < total ) {
					window.setTimeout( function () {
						load( number + 1 ).catch( function () {} );
					}, 400 );
				}

				return number;
			} ).catch( function ( error ) {
				Array.prototype.forEach.call( viewer.querySelectorAll( '[data-jimca-pager-status]' ), function ( status ) {
					status.textContent = t( 'loadFailed', 'Could not load this part. Try again.' );
				} );
				throw error;
			} );
		}

		if ( total > 1 ) {
			viewer.jimcaPager = {
				count: total,
				current: function () {
					return current;
				},
				hasNext: function () {
					return current < total;
				},
				next: function ( options ) {
					return show( current + 1, options );
				},
				goTo: show,
			};

			Array.prototype.forEach.call( viewer.querySelectorAll( '[data-jimca-pager-jump]' ), function ( jump ) {
				jump.hidden = false;
			} );

			viewer.addEventListener( 'click', function ( event ) {
				var link = event.target.closest ? event.target.closest( '[data-jimca-part-link]' ) : null;

				if ( ! link || link.getAttribute( 'aria-disabled' ) ) {
					return;
				}

				event.preventDefault();
				show( parseInt( link.getAttribute( 'data-jimca-part-link' ), 10 ) ).catch( function () {} );
			} );

			viewer.addEventListener( 'change', function ( event ) {
				if ( event.target.hasAttribute && event.target.hasAttribute( 'data-jimca-pager-select' ) ) {
					show( parseInt( event.target.value, 10 ) ).catch( function () {} );
				}
			} );

			window.addEventListener( 'popstate', function () {
				var wanted = parseInt( new window.URL( window.location.href ).searchParams.get( arg ) || '1', 10 );

				if ( wanted !== current ) {
					show( wanted, { push: false } ).catch( function () {} );
				}
			} );

			updateNav();
		}

		/* -----------------------------------------------------------
		 * Search
		 * --------------------------------------------------------- */

		var form = viewer.querySelector( '[data-jimca-search]' );

		if ( ! form ) {
			return;
		}

		var input = form.querySelector( '[data-jimca-search-input]' );
		var status = form.querySelector( '[data-jimca-search-status]' );
		var results = form.querySelector( '[data-jimca-search-results]' );
		var lastQuery = '';

		// With the reading bar the form is a panel opened from the bar's menu (frontend.js); without it, it is on the page.
		if ( ! viewer.querySelector( '[data-jimca-player]' ) ) {
			form.hidden = false;
		}

		/** The block of the current part that a result points to. */
		function locate( prefix, dup ) {
			var seen = 0;
			var first = null;
			var blocks = content.querySelectorAll( BLOCKS );

			for ( var i = 0; i < blocks.length; i++ ) {
				if ( 0 === textOf( blocks[ i ] ).indexOf( prefix ) ) {
					first = first || blocks[ i ];

					if ( seen === dup ) {
						return blocks[ i ];
					}
					seen++;
				}
			}

			return first;
		}

		/** A result: the text around the match, with the match marked, as a button. */
		function resultItem( hit, onChoose ) {
			var item = document.createElement( 'li' );
			var button = document.createElement( 'button' );
			var mark = document.createElement( 'mark' );

			button.type = 'button';
			button.className = 'jimca-search__result';
			mark.textContent = hit.match;
			button.appendChild( document.createTextNode( hit.before ) );
			button.appendChild( mark );
			button.appendChild( document.createTextNode( hit.after ) );

			if ( hit.title || hit.part ) {
				var where = document.createElement( 'span' );

				where.className = 'jimca-search__where';
				where.textContent = hit.title || fmt( t( 'partOf', 'Part %1$s of %2$s' ), hit.part, total );
				button.appendChild( where );
			}

			button.addEventListener( 'click', function ( event ) {
				// The panel of the bar (if any) gets out of the way.
				viewer.dispatchEvent( new window.CustomEvent( 'jimca:search-chosen' ) );
				onChoose( event );
			} );
			item.appendChild( button );

			return item;
		}

		function say( text ) {
			status.textContent = text;
		}

		function summary( count ) {
			return 0 === count ? t( 'noResults', 'No results.' ) : fmt( 1 === count ? t( 'resultsOne', '%s result.' ) : t( 'resultsMany', '%s results.' ), count.toLocaleString() );
		}

		/** Search in the text on the page (a document that is not in parts). */
		function searchHere( needle ) {
			var hits = [];

			Array.prototype.forEach.call( content.querySelectorAll( BLOCKS ), function ( el ) {
				// A block that holds other blocks (a list item with paragraphs) is left to them.
				if ( el.querySelector( BLOCKS ) ) {
					return;
				}

				var text = textOf( el );
				var at = normalize( text ).indexOf( needle );

				if ( at < 0 ) {
					return;
				}

				var from = Math.max( 0, at - 70 );

				hits.push( {
					el: el,
					before: ( from > 0 ? '…' : '' ) + text.slice( from, at ),
					match: text.slice( at, at + needle.length ),
					after: text.slice( at + needle.length, at + needle.length + 90 ) + ( text.length > at + needle.length + 90 ? '…' : '' ),
				} );
			} );

			results.textContent = '';
			say( summary( hits.length ) );

			var list = document.createElement( 'ol' );

			list.className = 'jimca-search__list';
			hits.slice( 0, 200 ).forEach( function ( hit ) {
				list.appendChild( resultItem( hit, function () {
					reveal( hit.el );
				} ) );
			} );
			results.appendChild( list );
		}

		/** Search in the whole document at the server (a document in parts). */
		function searchRemote( query, offset ) {
			say( t( 'searching', 'Searching…' ) );

			return window.fetch( endpoint( { action: i18n.actionSearch, doc: doc, q: query, offset: offset } ), { credentials: 'same-origin' } )
				.then( function ( response ) {
					if ( 429 === response.status ) {
						throw new Error( 'rate' );
					}
					return response.json();
				} )
				.then( function ( answer ) {
					if ( ! answer || ! answer.success ) {
						throw new Error( 'search' );
					}

					var list = results.querySelector( '.jimca-search__list' );
					var more = results.querySelector( '.jimca-search__more' );

					if ( ! offset || ! list ) {
						results.textContent = '';
						list = document.createElement( 'ol' );
						list.className = 'jimca-search__list';
						results.appendChild( list );
					}

					if ( more ) {
						more.remove();
					}

					answer.data.results.forEach( function ( hit ) {
						list.appendChild( resultItem( hit, function () {
							function go() {
								reveal( locate( hit.block, hit.dup ) );
							}

							if ( hit.part === current ) {
								go();
							} else {
								show( hit.part, { focus: false } ).then( go ).catch( function () {} );
							}
						} ) );
					} );

					say( summary( answer.data.total ) );

					var shown = list.children.length;

					if ( shown < answer.data.total ) {
						var button = document.createElement( 'button' );

						button.type = 'button';
						button.className = 'jimca-search__more';
						button.textContent = t( 'showMore', 'Show more results' );
						button.addEventListener( 'click', function () {
							searchRemote( query, shown ).catch( function () {} );
						} );
						results.appendChild( button );
					}
				} )
				.catch( function ( error ) {
					say( 'rate' === error.message ? t( 'searchLimit', 'Too many searches in a short time. Wait a minute and try again.' ) : t( 'searchFailed', 'The search could not run. Try again.' ) );
				} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var query = input.value.trim();

			if ( normalize( query ).length < 2 ) {
				say( t( 'tooShort', 'Type at least 2 letters.' ) );
				input.focus();
				return;
			}

			lastQuery = query;

			if ( total > 1 ) {
				searchRemote( query, 0 );
			} else {
				searchHere( normalize( query ) );
			}
		} );

		// Escape in the field clears the results.
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ( lastQuery || input.value ) ) {
				// Cleared first; with the field empty, the next Esc closes the panel.
				event.stopPropagation();
				input.value = '';
				lastQuery = '';
				results.textContent = '';
				say( '' );
			}
		} );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-jimca-viewer]' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
