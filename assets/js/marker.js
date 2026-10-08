/*
 * Highlighter: readers select a passage and mark it in one of four colors.
 * Highlights are saved only in the reader's browser (localStorage), per
 * document; there is no account and nothing is sent. Without JavaScript the
 * text stays readable and the bar does not appear.
 *
 * Each highlight is stored as a text position (start, end), the marked text
 * and the color. On load, a position is applied only if the text there is
 * still the same: if the document was edited, an old highlight is dropped
 * instead of landing in the wrong place.
 */
( function () {
	'use strict';

	var i18n = window.jimcaMarkerI18n || {};
	var COLORS = [ 'yellow', 'green', 'blue', 'pink' ];

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function storageGet( key ) {
		try {
			var raw = window.localStorage.getItem( key );
			var data = raw ? JSON.parse( raw ) : [];
			return Array.isArray( data ) ? data : [];
		} catch ( e ) {
			return [];
		}
	}

	function storageSet( key, data ) {
		try {
			if ( data.length ) {
				window.localStorage.setItem( key, JSON.stringify( data ) );
			} else {
				window.localStorage.removeItem( key );
			}
		} catch ( e ) {
			// Storage blocked (private window): highlights last until the page reloads.
		}
	}

	function textNodes( root ) {
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
		var nodes = [];
		var node;

		while ( ( node = walker.nextNode() ) ) {
			nodes.push( node );
		}

		return nodes;
	}

	function offsetOf( root, container, offset ) {
		var range = document.createRange();
		range.selectNodeContents( root );
		range.setEnd( container, offset );
		return range.toString().length;
	}

	function unwrapAll( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( 'mark.jimca-mark' ), function ( mark ) {
			var parent = mark.parentNode;

			while ( mark.firstChild ) {
				parent.insertBefore( mark.firstChild, mark );
			}
			parent.removeChild( mark );
			parent.normalize();
		} );
	}

	/** Wraps [start, end) in <mark>, one per text node crossed. */
	function wrap( root, start, end, color ) {
		var position = 0;

		textNodes( root ).forEach( function ( node ) {
			var length = node.nodeValue.length;
			var from = Math.max( start - position, 0 );
			var to = Math.min( end - position, length );

			position += length;

			if ( from >= to ) {
				return;
			}

			var target = node;

			if ( to < length ) {
				target.splitText( to );
			}
			if ( from > 0 ) {
				target = target.splitText( from );
			}

			var mark = document.createElement( 'mark' );
			mark.className = 'jimca-mark jimca-mark--' + color;
			target.parentNode.insertBefore( mark, target );
			mark.appendChild( target );
		} );
	}

	function init( viewer ) {
		var content = viewer.querySelector( '[data-jimca-content]' );
		var toolbar = viewer.querySelector( '[data-jimca-marker]' );
		var docId = viewer.getAttribute( 'data-jimca-doc' );

		if ( ! content || ! toolbar || ! docId || ! window.getSelection ) {
			return;
		}

		var key = 'jimca-marks-' + docId;
		var status = toolbar.querySelector( '[data-jimca-marker-status]' );
		var clearBtn = toolbar.querySelector( '[data-jimca-marker-clear]' );
		var removeBtn = toolbar.querySelector( '[data-jimca-marker-remove]' );
		var marks = storageGet( key );
		var lastRange = null;
		var timer = null;

		function say( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		function render() {
			unwrapAll( content );

			var full = content.textContent.length ? textNodes( content ).map( function ( n ) {
				return n.nodeValue;
			} ).join( '' ) : '';

			marks = marks.filter( function ( m ) {
				return m && COLORS.indexOf( m.c ) !== -1 && m.s < m.e && full.slice( m.s, m.e ) === m.t;
			} );

			marks.forEach( function ( m ) {
				wrap( content, m.s, m.e, m.c );
			} );

			clearBtn.hidden = ! marks.length;
		}

		function selectionInside() {
			var selection = window.getSelection();

			if ( ! selection || selection.rangeCount < 1 || selection.isCollapsed ) {
				return null;
			}

			var range = selection.getRangeAt( 0 );

			return content.contains( range.commonAncestorContainer ) ? range : null;
		}

		function span( range ) {
			var start = offsetOf( content, range.startContainer, range.startOffset );
			var end = offsetOf( content, range.endContainer, range.endOffset );
			var text = range.toString();
			var lead = text.length - text.replace( /^\s+/, '' ).length;
			var trail = text.length - text.replace( /\s+$/, '' ).length;

			return { s: start + lead, e: end - trail };
		}

		function overlaps( m, s, e ) {
			return m.s < e && m.e > s;
		}

		function refresh() {
			var range = selectionInside();

			if ( range ) {
				lastRange = range.cloneRange();
				var s = span( range );
				removeBtn.hidden = ! marks.some( function ( m ) {
					return overlaps( m, s.s, s.e );
				} );
				toolbar.hidden = false;
			} else if ( ! toolbar.contains( document.activeElement ) ) {
				toolbar.hidden = true;
			}
		}

		document.addEventListener( 'selectionchange', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 80 );
		} );

		// Clicking the bar must not clear the selection it is about to use.
		toolbar.addEventListener( 'mousedown', function ( event ) {
			event.preventDefault();
		} );

		Array.prototype.forEach.call( toolbar.querySelectorAll( '[data-jimca-mark]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var range = selectionInside() || lastRange;

				if ( ! range ) {
					return;
				}

				var s = span( range );

				if ( s.s >= s.e ) {
					return;
				}

				var color = button.getAttribute( 'data-jimca-mark' );
				var text = textNodes( content ).map( function ( n ) {
					return n.nodeValue;
				} ).join( '' ).slice( s.s, s.e );

				// The new highlight replaces those it covers, even of another color.
				marks = marks.filter( function ( m ) {
					return ! overlaps( m, s.s, s.e );
				} );
				marks.push( { s: s.s, e: s.e, t: text, c: color } );
				marks.sort( function ( a, b ) {
					return a.s - b.s;
				} );

				storageSet( key, marks );
				window.getSelection().removeAllRanges();
				render();
				toolbar.hidden = true;
				say( t( 'marked', 'Passage highlighted.' ) );
			} );
		} );

		removeBtn.addEventListener( 'click', function () {
			var range = selectionInside() || lastRange;

			if ( ! range ) {
				return;
			}

			var s = span( range );

			marks = marks.filter( function ( m ) {
				return ! overlaps( m, s.s, s.e );
			} );

			storageSet( key, marks );
			window.getSelection().removeAllRanges();
			render();
			toolbar.hidden = true;
			say( t( 'removed', 'Highlight removed.' ) );
		} );

		clearBtn.addEventListener( 'click', function () {
			marks = [];
			storageSet( key, marks );
			render();
			say( t( 'cleared', 'All highlights were removed.' ) );
		} );

		toolbar.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				window.getSelection().removeAllRanges();
				toolbar.hidden = true;
			}
		} );

		render();
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
