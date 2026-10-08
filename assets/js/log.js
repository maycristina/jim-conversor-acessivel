/**
 * Live view of the activity log (Settings screen).
 *
 * Polls admin-ajax every 3 seconds while the "Update live" box is checked and
 * the tab is visible, and adds only the entries it doesn't have yet. Text is
 * always inserted with textContent, never as HTML. Without JavaScript the
 * server-rendered table still shows the last entries.
 */
( function () {
	'use strict';

	var i18n = window.jimcaLogI18n || {};
	var root = document.querySelector( '[data-jimca-log]' );

	if ( ! root || ! i18n.ajaxUrl ) {
		return;
	}

	var body = root.querySelector( '[data-jimca-log-body]' );
	var live = root.querySelector( '[data-jimca-log-live]' );
	var refresh = root.querySelector( '[data-jimca-log-refresh]' );
	var clear = root.querySelector( '[data-jimca-log-clear]' );
	var status = root.querySelector( '[data-jimca-log-status]' );
	var lastId = parseInt( root.getAttribute( 'data-last-id' ), 10 ) || 0;
	var MAX_ROWS = 200;
	var timer = null;
	var busy = false;

	refresh.hidden = false;
	clear.hidden = false;

	function say( text ) {
		status.textContent = '';
		// Re-set on the next tick so screen readers announce repeated messages.
		window.setTimeout( function () {
			status.textContent = text;
		}, 50 );
	}

	function post( action, data ) {
		var form = new URLSearchParams();
		form.set( 'action', action );
		form.set( 'nonce', i18n.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			form.set( key, data[ key ] );
		} );

		return window.fetch( i18n.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function cell( text, className, tag ) {
		var td = document.createElement( 'td' );
		var inner = text;

		if ( tag ) {
			inner = document.createElement( tag );
			inner.textContent = text;
			if ( className ) {
				inner.className = className;
			}
			td.appendChild( inner );
		} else {
			td.textContent = text;
		}

		return td;
	}

	function details( context ) {
		return Object.keys( context || {} ).map( function ( key ) {
			return key + '=' + context[ key ];
		} ).join( '; ' );
	}

	function addRow( entry ) {
		var tr = document.createElement( 'tr' );
		var level = [ 'info', 'warning', 'error' ].indexOf( entry.level ) > -1 ? entry.level : 'info';

		tr.setAttribute( 'data-id', entry.id );
		tr.appendChild( cell( entry.time ) );
		tr.appendChild( cell( level, 'jimca-log-level jimca-log-level--' + level, 'strong' ) );
		tr.appendChild( cell( entry.event, '', 'code' ) );
		tr.appendChild( cell( entry.message ) );
		tr.appendChild( cell( details( entry.context ) ) );
		body.insertBefore( tr, body.firstChild );
	}

	function showEmpty() {
		var tr = document.createElement( 'tr' );
		var td = document.createElement( 'td' );

		tr.setAttribute( 'data-jimca-log-empty', '' );
		td.colSpan = 5;
		td.textContent = i18n.empty;
		tr.appendChild( td );
		body.appendChild( tr );
	}

	function poll() {
		if ( busy ) {
			return Promise.resolve();
		}

		busy = true;

		return post( i18n.fetch, { since: lastId } ).then( function ( reply ) {
			busy = false;

			if ( ! reply || ! reply.success ) {
				say( i18n.failed );
				return;
			}

			var entries = reply.data.entries || [];

			if ( ! entries.length ) {
				return;
			}

			var placeholder = body.querySelector( '[data-jimca-log-empty]' );
			if ( placeholder ) {
				body.removeChild( placeholder );
			}

			entries.forEach( function ( entry ) {
				addRow( entry );
				lastId = Math.max( lastId, parseInt( entry.id, 10 ) || 0 );
			} );

			while ( body.rows.length > MAX_ROWS ) {
				body.deleteRow( body.rows.length - 1 );
			}

			say( 1 === entries.length ? i18n.newOne : i18n.newMany.replace( '%d', entries.length ) );
		} ).catch( function () {
			busy = false;
			say( i18n.failed );
		} );
	}

	function schedule() {
		window.clearInterval( timer );
		timer = null;

		if ( live.checked ) {
			timer = window.setInterval( function () {
				if ( ! document.hidden ) {
					poll();
				}
			}, 3000 );
		}
	}

	live.addEventListener( 'change', function () {
		schedule();
		say( live.checked ? i18n.liveOn : i18n.liveOff );
	} );

	refresh.addEventListener( 'click', poll );

	clear.addEventListener( 'click', function () {
		if ( ! window.confirm( i18n.confirm ) ) {
			return;
		}

		post( i18n.clear ).then( function ( reply ) {
			if ( ! reply || ! reply.success ) {
				say( i18n.failed );
				return;
			}

			body.textContent = '';
			lastId = 0;
			showEmpty();
			say( i18n.cleared );
		} ).catch( function () {
			say( i18n.failed );
		} );
	} );

	schedule();
}() );
