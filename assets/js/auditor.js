/**
 * Post-conversion semantic review of a converted document (document edit
 * screen).
 *
 * Reads the document HTML, looks for structure a screen reader (and the
 * "Listen" button of the reader, which walks h1-h6, p, li...) would get wrong,
 * lists the problems and can fix them:
 *
 *   heading-like   <p> that only looks like a heading (bold, big, "title"
 *                  class, "Chapter 1")                       -> <h2>
 *   loose-text     text or inline elements outside any block  -> <p>
 *   long-heading   heading holding a whole paragraph          -> <p>
 *   split-heading  one sentence broken over several headings  -> one block
 *   empty-block    <p>&nbsp;</p>, empty headings              -> removed
 *   excess-br      <br><br> used as paragraph spacing          -> separate <p>
 *   heading-skip   <h2> followed by <h4>                       -> <h3>
 *
 * Plain DOM API on a detached copy of the HTML (DOMParser): nothing is
 * rendered, no script runs, and the page is only touched when the user asks
 * for a fix. Styles are read from the inline `style` attribute and the class
 * names, since a detached copy has no computed style.
 *
 * The audit is a pure function of a DOM element (`audit( root )`), so it is
 * also run outside the browser by the tests in documentacao/.
 */
( function ( global ) {
	'use strict';

	var i18n = global.jimcaAuditI18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	/** Replaces %s, %1$s, %2$s in a translated template. */
	function fmt( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return template.replace( /%(?:(\d+)\$)?s/g, function ( all, position ) {
			var value = position ? args[ position - 1 ] : args[ next++ ];
			return undefined === value ? '' : value;
		} );
	}

	var HEADING = /^H[1-6]$/;
	var INVISIBLE = /[\s ​-‍﻿]+/g;

	/** Tags that hold content by themselves: a <p> with only these is not empty. */
	var MEDIA = 'img, figure, svg, video, audio, iframe, canvas, hr, table, object, embed, picture';

	/** Elements that can contain loose text (containers, not text blocks). */
	var CONTAINER = /^(DIV|SECTION|ARTICLE|ASIDE|MAIN|BLOCKQUOTE|LI)$/;

	var PHRASING = /^(A|ABBR|B|BDI|BDO|BR|CITE|CODE|DATA|DFN|EM|I|KBD|MARK|Q|S|SAMP|SMALL|SPAN|STRONG|SUB|SUP|TIME|U|VAR|WBR|FONT)$/;

	var TITLE_CLASS = /(^|[\s_-])(title|titulo|t[ií]tulo|heading|headline|chapter|cap[ií]tulo|subtitle|subt[ií]tulo)([\s_-]|$)/i;
	var TITLE_TEXT = /^(cap[ií]tulo|chapter|parte|part|se[cç][aã]o|section|pr[oó]logo|pref[aá]cio|introdu[cç][aã]o|introduction|conclus[aã]o|conclusion|ap[eê]ndice|appendix)\b/i;
	var SENTENCE_END = /[.!?…;,]["”'»)\]]?$/;

	/** Classes the plugin itself writes: deliberate, never flagged. */
	var PLUGIN_CLASS = /\bjimca-(page-title|printed-toc__heading|visually-hidden)\b/;

	function textOf( node ) {
		return ( node.textContent || '' ).replace( INVISIBLE, ' ' ).trim();
	}

	function hasContent( node ) {
		return '' !== textOf( node ) || ( node.querySelector && null !== node.querySelector( MEDIA ) );
	}

	function excerpt( text ) {
		text = text.replace( /\s+/g, ' ' ).trim();
		return text.length > 90 ? text.slice( 0, 87 ) + '…' : text;
	}

	function wordCount( text ) {
		return text ? text.split( /\s+/ ).length : 0;
	}

	/** Pixel size of an inline font-size ("24px", "18pt", "1.5em"...); 0 when unknown. */
	function inlineFontPx( el ) {
		var m = /font-size\s*:\s*([\d.]+)\s*(px|pt|em|rem|%)?/i.exec( el.getAttribute( 'style' ) || '' );
		if ( ! m ) {
			return 0;
		}
		var n = parseFloat( m[ 1 ] );
		switch ( ( m[ 2 ] || 'px' ).toLowerCase() ) {
			case 'pt':
				return n * 1.333;
			case 'em':
			case 'rem':
				return n * 16;
			case '%':
				return n * 0.16;
			default:
				return n;
		}
	}

	function inlineBold( el ) {
		var m = /font-weight\s*:\s*(bold|bolder|[5-9]00)/i.exec( el.getAttribute( 'style' ) || '' );
		return !! m;
	}

	/** True when the whole text of the <p> sits inside <strong>/<b> (maybe with <em> around). */
	function allBold( p ) {
		var total = textOf( p );
		if ( '' === total ) {
			return false;
		}
		var bold = '';
		Array.prototype.forEach.call( p.querySelectorAll( 'strong, b' ), function ( el ) {
			if ( ! el.parentNode.closest( 'strong, b' ) ) {
				bold += ' ' + textOf( el );
			}
		} );
		return textOf( { textContent: bold } ) === total;
	}

	/** Why a <p> looks like a heading, or '' when it does not. */
	function headingSignal( p ) {
		if ( PLUGIN_CLASS.test( p.getAttribute( 'class' ) || '' ) ) {
			return '';
		}

		var text = textOf( p );
		if ( '' === text || text.length > 120 || wordCount( text ) > 14 || SENTENCE_END.test( text ) ) {
			return '';
		}
		if ( p.querySelector( MEDIA ) ) {
			return '';
		}

		var style = p.getAttribute( 'style' ) || '';

		if ( TITLE_CLASS.test( p.getAttribute( 'class' ) || '' ) ) {
			return 'class';
		}
		if ( inlineFontPx( p ) >= 18 && ( inlineBold( p ) || allBold( p ) || /text-align\s*:\s*center/i.test( style ) ) ) {
			return 'size';
		}
		if ( TITLE_TEXT.test( text ) && ( allBold( p ) || inlineBold( p ) || wordCount( text ) <= 4 ) ) {
			return 'name';
		}
		if ( allBold( p ) || inlineBold( p ) ) {
			return 'bold';
		}
		return '';
	}

	function replaceTag( el, tag ) {
		var doc = el.ownerDocument;
		var out = doc.createElement( tag );

		Array.prototype.slice.call( el.childNodes ).forEach( function ( child ) {
			out.appendChild( child );
		} );

		if ( el.id ) {
			out.id = el.id;
		}
		el.parentNode.replaceChild( out, el );
		return out;
	}

	/** Heading made from a <p>: the text only, without the bold/size markup that faked it. */
	function paragraphToHeading( p, tag ) {
		var doc = p.ownerDocument;
		var h = doc.createElement( tag );
		h.textContent = textOf( p );
		p.parentNode.replaceChild( h, p );
	}

	function isAttached( root, node ) {
		return !! node.parentNode && root.contains( node );
	}

	/* ---------------------------------------------------------------
	 * Loose text -> paragraphs
	 * ------------------------------------------------------------- */

	/**
	 * Splits a run of text/inline nodes into paragraphs: at a blank line in
	 * the text and at two or more <br> in a row. A single line break joins
	 * the lines (the stored text is a sentence broken by the editor).
	 *
	 * @return {Element[]} <p> elements, not yet in the document.
	 */
	function paragraphsFromRun( doc, nodes ) {
		var paragraphs = [];
		var current = doc.createElement( 'p' );
		var pendingBr = 0;

		function flush() {
			if ( hasContent( current ) ) {
				// Trim the edges, where a leftover space or <br> would only be noise.
				while ( current.firstChild && 3 === current.firstChild.nodeType && '' === current.firstChild.nodeValue.trim() ) {
					current.removeChild( current.firstChild );
				}
				while ( current.lastChild && ( 'BR' === current.lastChild.nodeName || ( 3 === current.lastChild.nodeType && '' === current.lastChild.nodeValue.trim() ) ) ) {
					current.removeChild( current.lastChild );
				}
				paragraphs.push( current );
			}
			current = doc.createElement( 'p' );
		}

		function addText( text ) {
			if ( text ) {
				current.appendChild( doc.createTextNode( text ) );
			}
		}

		nodes.forEach( function ( node ) {
			if ( 'BR' === node.nodeName ) {
				++pendingBr;
				return;
			}

			if ( 3 === node.nodeType ) {
				var parts = node.nodeValue.split( /\n[ \t ]*\n\s*/ );

				parts.forEach( function ( part, i ) {
					if ( i > 0 ) {
						pendingBr = 0;
						flush();
					}
					var joined = part.replace( /[ \t]*\n[ \t]*/g, ' ' );

					if ( pendingBr > 1 && '' !== joined.trim() ) {
						flush();
					} else if ( 1 === pendingBr && '' !== joined.trim() ) {
						addText( ' ' );
					}
					if ( '' !== joined.trim() ) {
						pendingBr = 0;
					}
					addText( joined );
				} );
				return;
			}

			if ( pendingBr > 1 ) {
				flush();
			} else if ( 1 === pendingBr ) {
				addText( ' ' );
			}
			pendingBr = 0;
			current.appendChild( node.cloneNode( true ) );
		} );

		flush();
		return paragraphs;
	}

	/* ---------------------------------------------------------------
	 * The audit
	 * ------------------------------------------------------------- */

	/**
	 * @param {Element} root Element whose children are the document blocks.
	 * @return {{issues: Object[], stats: Object}}
	 */
	function audit( root ) {
		var started = global.performance && global.performance.now ? global.performance.now() : Date.now();
		var issues = [];
		var counter = 0;

		function add( type, level, node, message, suggestion, fix ) {
			issues.push( {
				id: 'i' + ( ++counter ),
				type: type,
				level: level, // 'error' | 'warning'
				node: node,
				excerpt: excerpt( textOf( node ) ),
				message: message,
				suggestion: suggestion,
				fix: fix
			} );
		}

		/* Paragraphs: fake headings, empty ones, <br><br> as spacing. */
		Array.prototype.forEach.call( root.querySelectorAll( 'p' ), function ( p ) {
			if ( ! hasContent( p ) ) {
				add(
					'empty-block', 'warning', p,
					t( 'emptyParagraph', 'Empty paragraph (used only as spacing). Screen readers announce it as "blank".' ),
					t( 'sugRemove', 'Remove it.' ),
					function () {
						if ( isAttached( root, p ) ) {
							p.parentNode.removeChild( p );
						}
					}
				);
				return;
			}

			var signal = headingSignal( p );
			if ( signal ) {
				var why = {
					bold: t( 'whyBold', 'it is all in bold' ),
					size: t( 'whySize', 'it has a large font' ),
					'class': t( 'whyClass', 'its class says it is a title' ),
					name: t( 'whyName', 'it starts like a chapter or section title' )
				}[ signal ];

				add(
					'heading-like', 'error', p,
					fmt( t( 'fakeHeading', 'Visual heading without a heading tag (%s): a screen reader reads it as plain text and "jump to next heading" skips it.' ), why ),
					t( 'sugHeading', 'Change it to <h2>.' ),
					function () {
						if ( isAttached( root, p ) ) {
							paragraphToHeading( p, 'h2' );
						}
					}
				);
				return;
			}

			var brs = p.querySelectorAll( 'br' );
			if ( brs.length > 1 && hasDoubleBr( p ) ) {
				add(
					'excess-br', 'warning', p,
					t( 'excessBr', 'Line breaks (<br><br>) used instead of separate paragraphs. The reader pauses wrongly and announces the block as a single paragraph.' ),
					t( 'sugSplit', 'Split it into separate paragraphs.' ),
					function () {
						if ( isAttached( root, p ) ) {
							var parts = paragraphsFromRun( p.ownerDocument, Array.prototype.slice.call( p.childNodes ) );
							parts.forEach( function ( part ) {
								p.parentNode.insertBefore( part, p );
							} );
							p.parentNode.removeChild( p );
						}
					}
				);
			}
		} );

		/* Headings: empty, holding a whole paragraph, or one sentence broken over several. */
		var headings = Array.prototype.slice.call( root.querySelectorAll( 'h1, h2, h3, h4, h5, h6' ) );
		var skipTo = {};

		headings.forEach( function ( h, i ) {
			if ( PLUGIN_CLASS.test( h.getAttribute( 'class' ) || '' ) || skipTo[ i ] ) {
				return;
			}

			var text = textOf( h );

			if ( ! hasContent( h ) ) {
				add(
					'empty-block', 'error', h,
					t( 'emptyHeading', 'Empty heading: a screen reader announces a heading with nothing to read.' ),
					t( 'sugRemove', 'Remove it.' ),
					function () {
						if ( isAttached( root, h ) ) {
							h.parentNode.removeChild( h );
						}
					}
				);
				return;
			}

			// A sentence cut over consecutive headings of the same level.
			var chain = [ h ];
			var merged = text;
			var j = i + 1;
			while ( headings[ j ] && headings[ j ] === chain[ chain.length - 1 ].nextElementSibling && headings[ j ].nodeName === h.nodeName &&
				! SENTENCE_END.test( merged ) && /^[a-zà-ÿ]/.test( textOf( headings[ j ] ) ) ) {
				chain.push( headings[ j ] );
				merged += ' ' + textOf( headings[ j ] );
				skipTo[ j ] = true;
				++j;
			}

			if ( chain.length > 1 ) {
				var asHeading = merged.length <= 100;
				add(
					'split-heading', 'error', h,
					fmt( t( 'splitHeading', 'A single sentence is broken over %s heading tags: the reader announces each piece as a separate heading.' ), chain.length ),
					asHeading ? t( 'sugMergeHeading', 'Join them into one heading.' ) : t( 'sugMergeParagraph', 'Join them into one paragraph.' ),
					function () {
						if ( ! isAttached( root, h ) ) {
							return;
						}
						var doc = h.ownerDocument;
						var block = doc.createElement( asHeading ? h.nodeName.toLowerCase() : 'p' );
						block.textContent = merged;
						h.parentNode.insertBefore( block, h );
						chain.forEach( function ( piece ) {
							if ( piece.parentNode ) {
								piece.parentNode.removeChild( piece );
							}
						} );
					}
				);
				issues[ issues.length - 1 ].excerpt = excerpt( merged );
				return;
			}

			if ( text.length > 160 || ( text.length > 100 && SENTENCE_END.test( text ) ) ) {
				add(
					'long-heading', 'error', h,
					t( 'longHeading', 'Long text inside a heading tag: it looks like a paragraph, and the heading list of the reader gets polluted.' ),
					t( 'sugParagraph', 'Change it to a paragraph (<p>).' ),
					function () {
						if ( isAttached( root, h ) ) {
							replaceTag( h, 'p' );
						}
					}
				);
			}
		} );

		/* Heading levels that skip one (h2 -> h4). */
		var previous = 0;
		headings.forEach( function ( h ) {
			if ( PLUGIN_CLASS.test( h.getAttribute( 'class' ) || '' ) || ! hasContent( h ) ) {
				return;
			}
			var level = parseInt( h.nodeName.charAt( 1 ), 10 );

			if ( previous && level > previous + 1 ) {
				var wanted = 'h' + ( previous + 1 );
				add(
					'heading-skip', 'warning', h,
					fmt( t( 'headingSkip', 'The heading level jumps from h%1$s to h%2$s. Readers that navigate by level lose the hierarchy.' ), previous, level ),
					fmt( t( 'sugLevel', 'Change it to <%s>.' ), wanted ),
					function () {
						if ( isAttached( root, h ) ) {
							replaceTag( h, wanted );
						}
					}
				);
				level = previous + 1;
			}
			previous = level;
		} );

		/* Loose text and inline elements outside any block. */
		( function scan( container ) {
			var run = [];

			function closeRun() {
				var hasText = run.some( function ( n ) {
					return 'BR' !== n.nodeName && '' !== textOf( n );
				} );

				if ( hasText ) {
					var first = run[ 0 ];
					var nodes = run.slice();
					var probe = { textContent: nodes.map( function ( n ) {
						return n.textContent;
					} ).join( ' ' ) };

					issues.push( {
						id: 'i' + ( ++counter ),
						type: 'loose-text',
						level: 'error',
						node: first,
						excerpt: excerpt( textOf( probe ) ),
						message: t( 'looseText', 'Text outside any paragraph (<p>): a screen reader may skip it, and the "Listen" button does not read it.' ),
						suggestion: t( 'sugWrap', 'Wrap it in paragraphs (<p>).' ),
						fix: function () {
							if ( ! first.parentNode ) {
								return;
							}
							var parent = first.parentNode;
							var parts = paragraphsFromRun( parent.ownerDocument, nodes );
							parts.forEach( function ( part ) {
								parent.insertBefore( part, first );
							} );
							nodes.forEach( function ( n ) {
								if ( n.parentNode === parent ) {
									parent.removeChild( n );
								}
							} );
						}
					} );
				}
				run = [];
			}

			Array.prototype.slice.call( container.childNodes ).forEach( function ( node ) {
				var inline = 3 === node.nodeType || ( 1 === node.nodeType && PHRASING.test( node.nodeName ) );

				if ( inline ) {
					run.push( node );
					return;
				}

				closeRun();

				if ( 1 === node.nodeType && CONTAINER.test( node.nodeName ) ) {
					scan( node );
				}
			} );

			closeRun();
		}( root ) );

		var ended = global.performance && global.performance.now ? global.performance.now() : Date.now();

		return {
			issues: issues,
			stats: {
				blocks: root.querySelectorAll( 'p, h1, h2, h3, h4, h5, h6, li' ).length,
				headings: headings.length,
				ms: Math.round( ended - started )
			}
		};
	}

	/** True when a <p> has two <br> in a row (ignoring blank text between them). */
	function hasDoubleBr( p ) {
		var seen = 0;
		var nodes = p.childNodes;

		for ( var i = 0; i < nodes.length; i++ ) {
			var n = nodes[ i ];
			if ( 'BR' === n.nodeName ) {
				if ( ++seen > 1 ) {
					return true;
				}
			} else if ( 3 !== n.nodeType || '' !== n.nodeValue.trim() ) {
				seen = 0;
			}
		}
		return false;
	}

	/** Parses an HTML fragment into a detached container (nothing loads or runs). */
	function parse( html ) {
		var doc = new global.DOMParser().parseFromString( '<!doctype html><body><div id="jimca-audit-root"></div></body>', 'text/html' );
		var root = doc.getElementById( 'jimca-audit-root' );
		root.innerHTML = html;
		return root;
	}

	/** Applies the fix of every issue, or of the ids in `only`. Returns the new HTML. */
	function applyFixes( root, issues, only ) {
		issues.forEach( function ( issue ) {
			if ( ! only || only.indexOf( issue.id ) !== -1 ) {
				issue.fix();
			}
		} );
		return root.innerHTML;
	}

	var api = { audit: audit, parse: parse, applyFixes: applyFixes };

	if ( 'object' === typeof module && module.exports ) {
		module.exports = api;
	}
	global.JimcaAuditor = api;

	/* ---------------------------------------------------------------
	 * Screen: the "Click here" box of the document edit screen
	 * ------------------------------------------------------------- */

	function editor() {
		var ed = global.tinymce && global.tinymce.get && global.tinymce.get( 'content' );
		return ed && ! ed.isHidden() ? ed : null;
	}

	function readHtml() {
		var ed = editor();
		if ( ed ) {
			return ed.getContent();
		}
		var area = document.getElementById( 'content' );
		return area ? area.value : '';
	}

	function writeHtml( html ) {
		var area = document.getElementById( 'content' );
		var ed = editor();

		if ( ed ) {
			ed.setContent( html );
			ed.save();
		}
		if ( area ) {
			area.value = html;
			area.dispatchEvent( new global.Event( 'input', { bubbles: true } ) );
		}
	}

	function init() {
		var run = document.getElementById( 'jimca-audit-run' );
		var out = document.getElementById( 'jimca-audit-result' );

		if ( ! run || ! out ) {
			return;
		}

		var state = null; // { root, issues } of the last run; replaced on each run, never accumulated.

		function el( tag, className, text ) {
			var node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( text ) {
				node.textContent = text;
			}
			return node;
		}

		function render() {
			out.textContent = '';
			var issues = state.issues;
			var stats = state.stats;

			if ( ! issues.length ) {
				var ok = el( 'p', 'jimca-audit__ok', t( 'none', 'No semantic or structure problems were found.' ) );
				ok.tabIndex = -1;
				out.appendChild( ok );
				ok.focus();
				return;
			}

			var title = el( 'p', 'jimca-audit__summary', fmt(
				1 === issues.length ? t( 'foundOne', '%s problem found.' ) : t( 'foundMany', '%s problems found.' ),
				issues.length
			) );
			title.tabIndex = -1;
			out.appendChild( title );

			var all = el( 'button', 'button button-primary', t( 'fixAll', 'Fix all' ) );
			all.type = 'button';
			all.setAttribute( 'data-jimca-fix-all', '' );
			out.appendChild( all );

			var list = el( 'ol', 'jimca-audit__list' );
			issues.forEach( function ( issue ) {
				var li = el( 'li', 'jimca-audit__item jimca-audit__item--' + issue.level );
				li.appendChild( el( 'strong', 'jimca-audit__level', 'error' === issue.level ? t( 'levelError', 'Structural error' ) : t( 'levelWarning', 'Warning' ) ) );
				li.appendChild( el( 'span', 'jimca-audit__message', ' ' + issue.message ) );

				if ( issue.excerpt ) {
					var quote = el( 'blockquote', 'jimca-audit__excerpt' );
					quote.appendChild( el( 'mark', '', issue.excerpt ) );
					li.appendChild( quote );
				}

				li.appendChild( el( 'span', 'jimca-audit__suggestion', issue.suggestion ) );

				var fix = el( 'button', 'button', t( 'fixOne', 'Fix this' ) );
				fix.type = 'button';
				fix.setAttribute( 'data-jimca-fix', issue.id );
				fix.setAttribute( 'aria-label', fmt( t( 'fixOneFor', 'Fix this: %s' ), issue.excerpt ) );
				li.appendChild( fix );

				list.appendChild( li );
			} );
			out.appendChild( list );

			out.appendChild( el( 'p', 'description', fmt( t( 'timing', 'Checked %1$s blocks in %2$s ms.' ), stats.blocks, stats.ms ) ) );
			out.appendChild( el( 'p', 'description', t( 'saveHint', 'The fixes change the editor only. Click "Update" to save them.' ) ) );
			title.focus();
		}

		function review() {
			var root = parse( readHtml() );
			var result = audit( root );
			state = { root: root, issues: result.issues, stats: result.stats };
			render();
		}

		run.addEventListener( 'click', review );

		out.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( 'button' ) : null;
			if ( ! button || ! state ) {
				return;
			}

			if ( button.hasAttribute( 'data-jimca-fix-all' ) ) {
				writeHtml( applyFixes( state.root, state.issues ) );
				review();
				return;
			}

			var id = button.getAttribute( 'data-jimca-fix' );
			if ( id ) {
				writeHtml( applyFixes( state.root, state.issues, [ id ] ) );
				review();
			}
		} );
	}

	if ( global.document ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}
}( 'undefined' !== typeof window ? window : globalThis ) );
