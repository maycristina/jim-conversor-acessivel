/*
 * Selection bar: readers select a passage and a bar appears right next to it
 * (above on a mouse, below on a touch screen, where the system menu takes
 * the space above) to share it as an image, download it as a text file or
 * mark it in one of four colors. Highlights are saved only in the reader's
 * browser (localStorage), per document; there is no account and nothing is
 * sent: the image and the text file are made in the browser. Without
 * JavaScript the text stays readable and the bar does not appear.
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

	function slug( text ) {
		var clean = String( text || '' ).replace( /[^\p{L}\p{N}]+/gu, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 );

		return clean || 'jim';
	}

	function saveBlob( blob, name ) {
		var url = window.URL.createObjectURL( blob );
		var link = document.createElement( 'a' );

		link.href = url;
		link.download = name;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		window.setTimeout( function () {
			window.URL.revokeObjectURL( url );
		}, 1000 );
	}

	/** Lines that fit in maxWidth. Breaks at spaces; a word wider than the line (or text without spaces, like Chinese) breaks by letter. */
	function wrapLines( ctx, text, maxWidth ) {
		var lines = [];

		text.split( '\n' ).forEach( function ( paragraph ) {
			if ( '' === paragraph.trim() ) {
				lines.push( '' );
				return;
			}

			var line = '';

			paragraph.split( /( +)/ ).forEach( function ( piece ) {
				if ( ctx.measureText( piece ).width > maxWidth ) {
					Array.from( piece ).forEach( function ( letter ) {
						if ( line && ctx.measureText( line + letter ).width > maxWidth ) {
							lines.push( line );
							line = '';
						}
						line += letter;
					} );
					return;
				}

				if ( line && ctx.measureText( line + piece ).width > maxWidth ) {
					lines.push( line.replace( / +$/, '' ) );
					line = /^ +$/.test( piece ) ? '' : piece;
				} else {
					line += piece;
				}
			} );

			lines.push( line.replace( / +$/, '' ) );
		} );

		return lines;
	}

	/**
	 * Draws the passage on a card: the text, the book title and, in a quiet
	 * line, the address of the page.
	 */
	function drawCard( text, title, address, family, rtl ) {
		var W = 1080;
		var PAD = 88;
		var FONT = 46;
		var LINE = 68;
		var MAX_LINES = 16;
		var canvas = document.createElement( 'canvas' );
		var ctx = canvas.getContext( '2d' );

		if ( ! ctx ) {
			return null;
		}

		canvas.width = W;
		ctx.direction = rtl ? 'rtl' : 'ltr';
		ctx.font = FONT + 'px ' + family;

		var lines = wrapLines( ctx, text, W - 2 * PAD );

		if ( lines.length > MAX_LINES ) {
			lines = lines.slice( 0, MAX_LINES );
			lines[ MAX_LINES - 1 ] = lines[ MAX_LINES - 1 ].replace( /[\s,;:.]+$/, '' ) + '…';
		}

		ctx.font = 'bold 36px ' + family;
		var titleLines = wrapLines( ctx, title, W - 2 * PAD ).slice( 0, 2 );
		var height = PAD + 110 + lines.length * LINE + 50 + titleLines.length * 50 + 70 + PAD / 2;

		canvas.height = height;
		ctx.direction = rtl ? 'rtl' : 'ltr';
		ctx.textAlign = rtl ? 'right' : 'left';
		ctx.textBaseline = 'alphabetic';

		var gradient = ctx.createLinearGradient( 0, 0, W, height );
		gradient.addColorStop( 0, '#0b4a8f' );
		gradient.addColorStop( 1, '#1668c7' );
		ctx.fillStyle = gradient;
		ctx.fillRect( 0, 0, W, height );

		var x = rtl ? W - PAD : PAD;
		var y = PAD + 60;

		ctx.fillStyle = '#7db9f5';
		ctx.font = 'bold 160px Georgia, serif';
		ctx.fillText( '\u201C', x, y + 40 );

		y += 70;
		ctx.fillStyle = '#ffffff';
		ctx.font = FONT + 'px ' + family;
		lines.forEach( function ( line ) {
			ctx.fillText( line, x, y );
			y += LINE;
		} );

		y += 10;
		ctx.fillStyle = '#cfe1f7';
		ctx.font = 'bold 36px ' + family;
		titleLines.forEach( function ( line, index ) {
			ctx.fillText( ( 0 === index ? '\u2014 ' : '' ) + line, x, y );
			y += 50;
		} );

		y += 24;
		ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
		ctx.font = '26px ' + family;
		ctx.fillText( address, x, y );

		return canvas;
	}

	function init( viewer ) {
		var content = viewer.querySelector( '[data-jimca-content]' );
		var toolbar = viewer.querySelector( '[data-jimca-marker]' );
		var docId = viewer.getAttribute( 'data-jimca-doc' );

		if ( ! content || ! toolbar || ! docId || ! window.getSelection ) {
			return;
		}

		// In a document in parts the highlights belong to the part: their positions are counted in its text.
		function keyFor() {
			var part = viewer.getAttribute( 'data-jimca-part' );

			return 'jimca-marks-' + docId + ( part ? '-p' + part : '' );
		}

		var key = keyFor();
		var status = toolbar.querySelector( '[data-jimca-marker-status]' );
		var clearBtn = toolbar.querySelector( '[data-jimca-marker-clear]' );
		var removeBtn = toolbar.querySelector( '[data-jimca-marker-remove]' );
		var shareBtn = toolbar.querySelector( '[data-jimca-share-image]' );
		var textBtn = toolbar.querySelector( '[data-jimca-download-text]' );
		var title = viewer.getAttribute( 'data-jimca-title' ) || document.title;
		var marks = storageGet( key );
		var lastRange = null;
		var timer = null;
		var dragging = false;

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

		/**
		 * Puts the bar next to the selection: above it, or below when there is
		 * no room (and always below on a touch screen, where the system menu
		 * and the selection handles use the space above and just under it).
		 */
		function place( range ) {
			var rects = range.getClientRects();

			if ( ! rects.length ) {
				return;
			}

			var first = rects[ 0 ];
			var last = rects[ rects.length - 1 ];
			var box = range.getBoundingClientRect();
			var width = toolbar.offsetWidth;
			var height = toolbar.offsetHeight;
			var gap = 10;
			var coarse = !! ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );
			var viewportW = document.documentElement.clientWidth || window.innerWidth;
			var viewportH = window.innerHeight;
			var left = Math.max( 8, Math.min( box.left + box.width / 2 - width / 2, viewportW - width - 8 ) );
			var top = coarse ? last.bottom + 30 : first.top - height - gap;

			if ( ! coarse && top < 8 ) {
				top = last.bottom + gap;
			} else if ( coarse && top + height > viewportH - 8 ) {
				top = first.top - height - 44;
			}

			toolbar.style.left = left + 'px';
			toolbar.style.top = Math.max( 8, Math.min( top, viewportH - height - 8 ) ) + 'px';
		}

		function reposition() {
			if ( ! toolbar.hidden && lastRange ) {
				place( lastRange );
			}
		}

		function refresh() {
			var range = selectionInside();

			// While the mouse is still dragging the selection, wait: the bar would follow it around.
			if ( dragging ) {
				return;
			}

			if ( range ) {
				lastRange = range.cloneRange();
				var s = span( range );
				removeBtn.hidden = ! marks.some( function ( m ) {
					return overlaps( m, s.s, s.e );
				} );
				toolbar.hidden = false;
				place( lastRange );
			} else if ( ! toolbar.contains( document.activeElement ) ) {
				toolbar.hidden = true;
			}
		}

		/** The selected passage as plain text: lines kept, runs of spaces and blank lines trimmed. */
		function selectedText() {
			var range = selectionInside() || lastRange;

			if ( ! range ) {
				return '';
			}

			return range.toString().replace( /\r/g, '' ).replace( /[ \t]+/g, ' ' ).replace( / ?\n ?/g, '\n' ).replace( /\n{3,}/g, '\n\n' ).trim();
		}

		document.addEventListener( 'selectionchange', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 80 );
		} );

		content.addEventListener( 'mousedown', function () {
			dragging = true;
		} );

		document.addEventListener( 'mouseup', function () {
			if ( dragging ) {
				dragging = false;
				window.clearTimeout( timer );
				timer = window.setTimeout( refresh, 0 );
			}
		} );

		window.addEventListener( 'scroll', reposition, { passive: true } );
		window.addEventListener( 'resize', reposition );

		if ( textBtn ) {
			textBtn.addEventListener( 'click', function () {
				var text = selectedText();

				if ( ! text ) {
					return;
				}

				saveBlob( new Blob( [ text + '\n' ], { type: 'text/plain;charset=utf-8' } ), slug( title ) + '-excerpt.txt' );
				say( t( 'saved', 'Text file downloaded.' ) );
			} );
		}

		if ( shareBtn ) {
			shareBtn.addEventListener( 'click', function () {
				var text = selectedText();

				if ( ! text ) {
					return;
				}

				var style = window.getComputedStyle( content );
				var address = ( window.location.host + window.location.pathname ).replace( /\/$/, '' );
				var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();

				ready.then( function () {
					var canvas = drawCard( text, title, address, style.fontFamily || 'sans-serif', 'rtl' === style.direction );

					if ( ! canvas ) {
						say( t( 'failed', 'Could not create the image.' ) );
						return;
					}

					canvas.toBlob( function ( blob ) {
						if ( ! blob ) {
							say( t( 'failed', 'Could not create the image.' ) );
							return;
						}

						var file = new File( [ blob ], slug( title ) + '-excerpt.png', { type: 'image/png' } );

						// Phones open their share sheet with the image; elsewhere it is downloaded.
						if ( navigator.canShare && navigator.share && navigator.canShare( { files: [ file ] } ) ) {
							navigator.share( { files: [ file ], title: title } ).catch( function () {} );
						} else {
							saveBlob( blob, file.name );
						}
						say( t( 'shared', 'Image ready.' ) );
					}, 'image/png' );
				} );
			} );
		}

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

		viewer.addEventListener( 'jimca:content-changed', function () {
			key = keyFor();
			marks = storageGet( key );
			toolbar.hidden = true;
			render();
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
