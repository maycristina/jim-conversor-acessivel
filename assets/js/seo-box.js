/*
 * "SEO and accessibility score" box of a document: saves its fields by AJAX
 * and redraws the score and the list of checks without reloading the page.
 * The server sends the redrawn box (JIMCA_Seo_Box); this script only sends
 * what the person typed and shows what comes back.
 *
 * Without JavaScript the buttons stay hidden and the author still saves
 * with the page's "Update" button.
 */
( function () {
	'use strict';

	var cfg = window.jimcaSeoBox || {};

	function t( key, fallback ) {
		return cfg[ key ] || fallback;
	}

	function box() {
		return document.getElementById( 'jimca-seo-box' );
	}

	function status( message, focus ) {
		var el = box() && box().querySelector( '[data-jimca-seo-status]' );

		if ( el ) {
			el.textContent = message;
			if ( focus ) {
				el.focus();
			}
		}
	}

	/** The buttons need this script: they are shown only when it runs. */
	function reveal() {
		Array.prototype.forEach.call( box().querySelectorAll( '[data-jimca-seo-save], [data-jimca-seo-detect], [data-jimca-seo-headings]' ), function ( button ) {
			button.hidden = false;
		} );
	}

	function counter( field ) {
		var name = field.getAttribute( 'data-jimca-seo-field' );
		var out = box().querySelector( '[data-jimca-count-for="' + name + '"]' );
		var min = parseInt( field.getAttribute( 'data-jimca-min' ), 10 );
		var max = parseInt( field.getAttribute( 'data-jimca-max' ), 10 );

		if ( ! out || ! max ) {
			return;
		}

		var length = field.value.length;
		var note = length < min ? ' — ' + t( 'tooShort', 'too short' ) : ( length > max ? ' — ' + t( 'tooLong', 'too long' ) : '' );

		out.textContent = length + ' ' + t( 'characters', 'characters' ) + ' (' + min + '–' + max + ')' + note;
		out.style.color = note ? '#8a2424' : '';
	}

	function post( action, data ) {
		var body = new window.FormData();

		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'doc', cfg.doc );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function busy( on ) {
		Array.prototype.forEach.call( box().querySelectorAll( 'button' ), function ( button ) {
			button.disabled = on;
		} );
	}

	function redraw( answer ) {
		box().innerHTML = answer.data.html;
		reveal();
		Array.prototype.forEach.call( box().querySelectorAll( '[data-jimca-max]' ), counter );
		status( answer.data.message, true );
	}

	function run( action, data, done ) {
		status( t( 'working', 'Saving…' ), false );
		busy( true );

		post( action, data ).then( function ( answer ) {
			busy( false );

			if ( ! answer || ! answer.success ) {
				status( answer && answer.data && answer.data.message ? answer.data.message : t( 'failed', 'Could not save.' ), true );
				return;
			}

			if ( done ) {
				done( answer );
			}
		} ).catch( function () {
			busy( false );
			status( t( 'failed', 'Could not save.' ), true );
		} );
	}

	/** The title field of the edit screen shows the new title, or "Update" would bring the old one back. */
	function showTitle( title ) {
		var field = document.getElementById( 'title' );

		if ( field ) {
			field.value = title;
			field.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		}
	}

	/** Content of the editor, which may have changes not saved yet. */
	function editorContent() {
		var editor = window.tinyMCE && window.tinyMCE.get( 'content' );

		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}

		var area = document.getElementById( 'content' );

		return area ? area.value : '';
	}

	function setEditorContent( html ) {
		var editor = window.tinyMCE && window.tinyMCE.get( 'content' );
		var area = document.getElementById( 'content' );

		if ( editor ) {
			editor.setContent( html );
		}
		if ( area ) {
			area.value = html;
		}
	}

	function init() {
		var root = box();

		if ( ! root ) {
			return;
		}

		reveal();
		Array.prototype.forEach.call( root.querySelectorAll( '[data-jimca-max]' ), counter );

		root.addEventListener( 'input', function ( event ) {
			if ( event.target.hasAttribute && event.target.hasAttribute( 'data-jimca-max' ) ) {
				counter( event.target );
			}
		} );

		root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( 'button' ) : null;

			if ( ! button ) {
				return;
			}

			var save = button.getAttribute( 'data-jimca-seo-save' );

			if ( save ) {
				var field = root.querySelector( '[data-jimca-seo-field="' + save + '"]' );

				run( cfg.actSave, { field: save, value: field ? field.value : '' }, function ( answer ) {
					if ( answer.data.title ) {
						showTitle( answer.data.title );
					}
					redraw( answer );
				} );
			} else if ( button.hasAttribute( 'data-jimca-seo-detect' ) ) {
				run( cfg.actDetect, {}, function ( answer ) {
					var found = false;

					[ 'author', 'title', 'summary' ].forEach( function ( name ) {
						var input = root.querySelector( '[data-jimca-seo-field="' + name + '"]' );

						if ( input && answer.data[ name ] ) {
							input.value = answer.data[ name ];
							counter( input );
							found = true;
						}
					} );

					status( found ? t( 'detected', 'Suggestions filled in. Review them and save.' ) : t( 'nothing', 'Nothing was found in the text to suggest.' ), true );
				} );
			} else if ( button.hasAttribute( 'data-jimca-seo-headings' ) ) {
				run( cfg.actFix, { content: editorContent() }, function ( answer ) {
					setEditorContent( answer.data.content );
					redraw( answer );
				} );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
