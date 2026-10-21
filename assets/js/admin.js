/**
 * "New Document" screen: confirms the chosen file (also for screen readers)
 * and follows the conversion until it ends.
 */
( function () {
	'use strict';

	var i18n = window.jimcaAdminI18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	function formatSize( bytes ) {
		if ( ! bytes && 0 !== bytes ) {
			return '';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		var value = bytes;
		while ( value >= 1024 && i < units.length - 1 ) {
			value = value / 1024;
			i++;
		}
		return ( i === 0 ? value : value.toFixed( 1 ) ) + ' ' + units[ i ];
	}

	function format( template, values ) {
		var next = 0;
		return template.replace( /%(\d+\$)?s/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : next++;
			return String( values[ index ] );
		} );
	}

	function megabytes( bytes ) {
		return ( bytes / 1048576 ).toLocaleString( document.documentElement.lang || undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' MB';
	}

	/**
	 * Why a file cannot be converted ('' when it can). Same rules as the
	 * server (JIMCA_Admin::receive_file()): allowed type, not empty, within the
	 * size limit (and, for a PDF, within what the server memory converts).
	 */
	function problemWith( file, index ) {
		var allowed = ( i18n.allowedTypes || [] ).map( function ( type ) {
			return String( type ).toLowerCase();
		} );
		var match = /\.([A-Za-z0-9]+)$/.exec( file.name );
		var ext = match ? match[ 1 ].toLowerCase() : '';

		if ( 'markdown' === ext ) {
			ext = 'md';
		}

		if ( allowed.length && -1 === allowed.indexOf( ext ) ) {
			return format( t( 'badType', 'This type is not accepted. Use: %s.' ), [ allowed.join( ', ' ).toUpperCase() ] );
		}

		if ( 0 === file.size ) {
			return t( 'emptyFile', 'The file is empty (0 bytes).' );
		}

		var ceiling = t( 'maxBytes', Infinity );

		if ( 'pdf' === ext && t( 'maxPdfBytes', 0 ) > 0 ) {
			ceiling = Math.min( t( 'maxPdfBytes', 0 ), ceiling );
		}

		if ( file.size > ceiling ) {
			return format( t( 'tooBig', 'This file is %1$s; the limit here is %2$s.' ), [ megabytes( file.size ), megabytes( ceiling ) ] );
		}

		if ( index >= t( 'maxFiles', 20 ) ) {
			return format( t( 'beyondLimit', 'Over the limit of %s files per upload.' ), [ t( 'maxFiles', 20 ) ] );
		}

		return '';
	}

	/**
	 * List of the chosen files, right below the field, before anything is
	 * sent: name, size and a badge (text and icon, never color alone). The
	 * button that converts stays off while any file cannot be converted.
	 */
	function initFileList() {
		var input = document.getElementById( 'jimca_file' );
		var wrap  = document.querySelector( '[data-jimca-file-list]' );
		var form  = document.getElementById( 'jimca-upload-form' );

		if ( ! input || ! wrap || ! form ) {
			return;
		}

		var items    = wrap.querySelector( '[data-jimca-file-items]' );
		var summary  = wrap.querySelector( '[data-jimca-file-summary]' );
		var button   = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		var expected = document.getElementById( 'jimca_expected_files' );

		function render() {
			var files = input.files ? Array.prototype.slice.call( input.files ) : [];

			items.textContent = '';
			form.removeAttribute( 'data-jimca-invalid' );

			if ( ! files.length ) {
				wrap.hidden = true;
				if ( button ) {
					button.disabled = false;
				}
				return;
			}

			var bad = 0;

			files.forEach( function ( file, index ) {
				var reason = problemWith( file, index );
				var li     = document.createElement( 'li' );
				var name   = document.createElement( 'span' );
				var size   = document.createElement( 'span' );
				var badge  = document.createElement( 'span' );
				var icon   = document.createElement( 'span' );

				li.className = 'jimca-file-list__item';
				name.className = 'jimca-file-list__name';
				name.textContent = file.name;
				size.className = 'jimca-file-list__size';
				size.textContent = megabytes( file.size );
				badge.className = 'jimca-file-badge ' + ( reason ? 'jimca-file-badge--error' : 'jimca-file-badge--ok' );
				icon.setAttribute( 'aria-hidden', 'true' );
				icon.textContent = reason ? '✕' : '✓';
				badge.appendChild( icon );
				badge.appendChild( document.createTextNode( reason ? t( 'fileInvalid', 'Error' ) : t( 'fileValid', 'Valid' ) ) );

				li.appendChild( name );
				li.appendChild( size );
				li.appendChild( badge );

				if ( window.DataTransfer ) {
					var remove = document.createElement( 'button' );

					remove.type = 'button';
					remove.className = 'button-link jimca-file-list__remove';
					remove.textContent = t( 'removeLabel', 'Remove' );
					remove.setAttribute( 'aria-label', format( t( 'removeFile', 'Remove %s' ), [ file.name ] ) );
					remove.addEventListener( 'click', function () {
						var kept = new window.DataTransfer();

						files.forEach( function ( other, position ) {
							if ( position !== index ) {
								kept.items.add( other );
							}
						} );
						input.files = kept.files;
						if ( expected ) {
							expected.value = input.files.length;
						}
						render();
						input.focus();
					} );
					li.appendChild( remove );
				}

				if ( reason ) {
					var why = document.createElement( 'p' );

					why.className = 'jimca-file-list__reason';
					why.textContent = reason;
					li.appendChild( why );
					bad++;
				}

				items.appendChild( li );
			} );

			if ( bad ) {
				summary.textContent = format( t( 'listProblems', '%1$s of %2$s files cannot be converted. Remove them to continue.' ), [ bad, files.length ] );
				form.setAttribute( 'data-jimca-invalid', '1' );
			} else {
				summary.textContent = 1 === files.length ? t( 'listReadyOne', '1 file ready to convert.' ) : format( t( 'listReady', '%s files ready to convert.' ), [ files.length ] );
			}

			if ( button ) {
				button.disabled = bad > 0;
			}
			wrap.hidden = false;
		}

		input.addEventListener( 'change', render );
		render();
	}

	function init() {
		var input = document.getElementById( 'jimca_file' );
		var toast = document.getElementById( 'jimca-file-toast' );

		if ( ! input || ! toast ) {
			return;
		}

		var hideTimer = null;

		var expected = document.getElementById( 'jimca_expected_files' );

		input.addEventListener( 'change', function () {
			// The server compares with what arrived: PHP silently drops files beyond max_file_uploads.
			if ( expected ) {
				expected.value = input.files ? input.files.length : 0;
			}

			if ( ! input.files || ! input.files.length ) {
				toast.hidden = true;
				return;
			}

			var file = input.files[ 0 ];
			var size = formatSize( file.size );
			var template = t( 'fileAdded', 'File added: %s' );
			var label = file.name + ( size ? ' (' + size + ')' : '' );

			if ( input.files.length > 1 ) {
				var total = 0;
				Array.prototype.forEach.call( input.files, function ( f ) {
					total += f.size;
				} );
				template = t( 'filesAdded', '%s files added' );
				label = input.files.length + ' (' + formatSize( total ) + ')';
			}

			toast.textContent = template.indexOf( '%s' ) !== -1 ? template.replace( '%s', label ) : template + ' ' + label;
			toast.hidden = false;

			if ( hideTimer ) {
				window.clearTimeout( hideTimer );
			}
			hideTimer = window.setTimeout( function () {
				toast.hidden = true;
			}, 6000 );
		} );
	}

	/**
	 * Upload with progress. Each file is converted in the background (see
	 * JIMCA_Conversion_Job), one request per file and one file after another:
	 * the browser sends the file under a neutral name, asks for the conversion
	 * without waiting for that answer (which may turn into a proxy "504") and
	 * follows the progress until the end. A browser without fetch uses the
	 * plain form submission.
	 */
	function initUpload() {
		var form     = document.getElementById( 'jimca-upload-form' );
		var progress = document.getElementById( 'jimca-upload-progress' );

		if ( ! form || ! progress ) {
			return;
		}

		var ui = {
			title: progress.querySelector( '[data-jimca-progress-title]' ),
			hint: progress.querySelector( '[data-jimca-progress-hint]' ),
			bar: progress.querySelector( '[data-jimca-progress-bar]' ),
			detail: progress.querySelector( '[data-jimca-progress-detail]' ),
			live: progress.querySelector( '[data-jimca-progress-live]' ),
			spinner: progress.querySelector( '[data-jimca-progress-spinner]' )
		};
		var input     = document.getElementById( 'jimca_file' );
		var toast     = document.getElementById( 'jimca-file-toast' );
		var button    = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		var busy      = false;
		var milestone = -1;

		function setBusy( on ) {
			busy = on;
			if ( button ) {
				// aria-disabled, not disabled: a disabled button is left out of the plain form submission.
				if ( on ) {
					button.setAttribute( 'aria-disabled', 'true' );
				} else {
					button.removeAttribute( 'aria-disabled' );
				}
				button.classList.toggle( 'is-busy', on );
			}
		}

		function announce( text ) {
			if ( ui.live ) {
				ui.live.textContent = text;
			}
		}

		function show( title, hint ) {
			progress.hidden = false;
			progress.classList.remove( 'is-failed' );
			if ( ui.spinner ) {
				ui.spinner.hidden = false;
			}
			ui.title.textContent = title;
			ui.hint.textContent = hint || '';
			ui.detail.textContent = '';
		}

		function fail( message ) {
			progress.classList.add( 'is-failed' );
			if ( ui.spinner ) {
				ui.spinner.hidden = true;
			}
			ui.bar.hidden = true;
			ui.title.textContent = t( 'failed', 'The conversion failed.' );
			ui.hint.textContent = '';
			// The server message is already escaped (it may contain <code> and <br>).
			ui.detail.innerHTML = message || '';
			announce( t( 'failed', 'The conversion failed.' ) + ' ' + ui.detail.textContent );
			setBusy( false );
		}

		function update( job ) {
			if ( 'pages' !== job.stage || ! job.total ) {
				ui.title.textContent = t( 'reading', 'Reading the file structure…' );
				return;
			}

			var percent = Math.floor( ( job.done / job.total ) * 100 );

			ui.title.textContent = format( t( 'pages', 'Page %1$s of %2$s' ), [ job.done, job.total ] );
			ui.bar.hidden = false;
			ui.bar.value = percent;
			ui.detail.textContent = job.images ? format( t( 'images', '%s images so far' ), [ job.images ] ) : '';

			if ( Math.floor( percent / 25 ) > milestone ) {
				milestone = Math.floor( percent / 25 );
				announce( format( t( 'percent', '%s converted' ), [ percent + '%' ] ) );
			}
		}

		function send( data ) {
			return fetch( t( 'ajaxUrl', '' ), { method: 'POST', body: data, credentials: 'same-origin' } ).then( function ( response ) {
				return response.json().catch( function () {
					// A block by the host's firewall never reaches the plugin log: leave a trace in the browser console.
					if ( window.console && console.warn ) {
						response.clone().text().then( function ( body ) {
							console.warn( 'Jim: HTTP ' + response.status + ' from ' + response.url + ' — ' + body.replace( /\s+/g, ' ' ).slice( 0, 300 ) );
						} ).catch( function () {} );
					}

					var message = format( t( 'httpError', 'The server answered with an error (HTTP %s).' ), [ response.status ] );

					if ( 413 === response.status ) {
						message = t( 'tooLarge', 'The server refused the file because it is too large.' );
					} else if ( 403 === response.status ) {
						// WordPress answers JSON to everything it handles, so a 403 page came from the server in front of it.
						message = format( t( 'blocked', 'The server firewall blocked this file (HTTP %s) before it reached WordPress.' ), [ response.status ] );
					}

					return { success: false, data: { message: message } };
				} );
			} );
		}

		function post( fields ) {
			var data = new FormData();
			Object.keys( fields ).forEach( function ( key ) {
				data.append( key, fields[ key ] );
			} );
			return send( data );
		}

		/**
		 * Drives the conversion: asks for one step after another (each one at
		 * most ~20 s on the server, see JIMCA_Conversion_Job) and polls the
		 * progress while a step runs. When a step's answer is lost (the host
		 * cut the request), the next step resumes from where the server saved;
		 * the server gives up by itself after a few attempts on the same page.
		 */
		function track( job, nonce ) {
			return new Promise( function ( resolve ) {
				var finished = false;
				var misses   = 0;

				function end( result ) {
					finished = true;
					resolve( result );
				}

				function settle( status ) {
					if ( finished ) {
						return true;
					}
					if ( 'done' === status.status ) {
						end( { ok: true, status: status } );
						return true;
					}
					if ( 'failed' === status.status ) {
						end( { ok: false, message: status.message } );
						return true;
					}
					update( status );
					return false;
				}

				function step() {
					if ( finished ) {
						return;
					}
					post( { action: t( 'actionRun', '' ), job: job, jimca_upload_nonce: nonce } ).then( function ( answer ) {
						if ( ! answer.success ) {
							return retry( answer.data && answer.data.message );
						}
						misses = 0;
						if ( ! settle( answer.data ) ) {
							// Another step is still running on the server: give it time.
							window.setTimeout( step, answer.data.busy ? 3000 : 0 );
						}
					} ).catch( function () {
						retry();
					} );
				}

				// A lost answer (proxy timeout, network blip) is retried; many in a row are not.
				function retry( message ) {
					misses++;
					if ( misses >= 6 ) {
						end( { ok: false, message: message || t( 'network', 'Could not reach the server.' ) } );
						return;
					}
					window.setTimeout( step, 3000 );
				}

				function poll() {
					if ( finished ) {
						return;
					}
					post( { action: t( 'actionStatus', '' ), job: job, jimca_upload_nonce: nonce } ).then( function ( answer ) {
						if ( answer.success && settle( answer.data ) ) {
							return;
						}
						window.setTimeout( poll, 2000 );
					} ).catch( function () {
						window.setTimeout( poll, 4000 );
					} );
				}

				step();
				window.setTimeout( poll, 1500 );
			} );
		}

		/**
		 * base64url of the UTF-8 bytes of a name: the real name travels in a
		 * field of its own, so the file part can carry a neutral one.
		 */
		function encodeName( name ) {
			var bytes  = new TextEncoder().encode( name );
			var binary = '';
			bytes.forEach( function ( byte ) {
				binary += String.fromCharCode( byte );
			} );
			return window.btoa( binary ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
		}

		/**
		 * Neutral name for the upload: nothing a firewall could read as a word
		 * (see JIMCA_Conversion_Job::decoded_name()), same extension.
		 */
		function neutralName( file, index ) {
			var match = /\.([A-Za-z0-9]{1,8})$/.exec( file.name );
			return 'jim-' + ( index + 1 ) + '-' + Date.now().toString( 36 ) + ( match ? '.' + match[ 1 ].toLowerCase() : '' );
		}

		function link( text, href ) {
			var a = document.createElement( 'a' );
			a.href = href;
			a.textContent = text;
			return a;
		}

		/**
		 * One file after another, each in its own small request: a failure (or a
		 * firewall block) affects that file only and is reported by name.
		 */
		function convertQueue( files, nonce ) {
			var results = [];
			var total   = files.length;
			var queued  = Promise.resolve();

			files.forEach( function ( file, index ) {
				queued = queued.then( function () {
					milestone = -1;
					ui.bar.hidden = true;

					// Too big for the plugin setting or for the server's memory: say so now, without uploading it first.
					var ceiling = /\.pdf$/i.test( file.name ) && t( 'maxPdfBytes', 0 ) > 0 ? Math.min( t( 'maxPdfBytes', 0 ), t( 'maxBytes', Infinity ) ) : t( 'maxBytes', Infinity );

					if ( file.size > ceiling ) {
						results.push( { name: file.name, ok: false, message: format( t( 'tooBig', 'This file is %1$s; the limit here is %2$s.' ), [ formatSize( file.size ), formatSize( ceiling ) ] ) } );
						return null;
					}

					var label = total > 1 ? format( t( 'fileOf', 'File %1$s of %2$s: %3$s' ), [ index + 1, total, file.name ] ) : t( 'sending', 'Sending the file…' );
					show( label, t( 'longHint', '' ) );
					announce( label );

					var converted = results.filter( function ( r ) {
						return r.ok;
					} ).length;
					var data      = new FormData( form );

					data.delete( 'jimca_file[]' );
					data.delete( 'jimca_file' );
					if ( total > 1 ) {
						data.delete( 'jimca_title' );
					}
					data.set( 'action', t( 'actionStart', '' ) );
					data.set( 'jimca_name', encodeName( file.name ) );
					data.set( 'jimca_index', String( converted ) );
					data.append( 'jimca_file', file, neutralName( file, index ) );

					return send( data ).then( function ( answer ) {
						if ( ! answer.success ) {
							results.push( { name: file.name, ok: false, message: answer.data && answer.data.message } );
							return null;
						}

						ui.title.textContent = total > 1 ? label : t( 'reading', 'Reading the file structure…' );

						return track( answer.data.job, nonce ).then( function ( outcome ) {
							results.push( { name: file.name, ok: outcome.ok, message: outcome.message, status: outcome.status } );
						} );
					} ).catch( function () {
						results.push( { name: file.name, ok: false, message: t( 'network', 'Could not reach the server.' ) } );
					} );
				} );
			} );

			return queued.then( function () {
				return results;
			} );
		}

		function finish( results ) {
			var good = results.filter( function ( r ) {
				return r.ok;
			} );

			// One file that worked: straight to the result, as always.
			if ( 1 === results.length && good.length ) {
				ui.bar.value = 100;
				ui.title.textContent = t( 'done', 'Conversion finished. Opening the result…' );
				announce( ui.title.textContent );
				window.location.href = good[ 0 ].status.redirect;
				return;
			}

			if ( 1 === results.length ) {
				fail( results[ 0 ].message );
				return;
			}

			var summary = format( t( 'queueSummary', '%1$s of %2$s files converted.' ), [ good.length, results.length ] );
			var list    = document.createElement( 'ul' );

			results.forEach( function ( r ) {
				var item = document.createElement( 'li' );

				if ( r.ok && r.status.post ) {
					item.appendChild( r.status.post.edit ? link( r.status.post.title, r.status.post.edit ) : document.createTextNode( r.status.post.title ) );
					item.appendChild( document.createTextNode( ' ' ) );

					var code = document.createElement( 'code' );
					code.textContent = r.status.post.shortcode;
					item.appendChild( code );

					if ( r.status.post.unreadable ) {
						var warning = document.createElement( 'p' );
						warning.textContent = t( 'unreadableNote', 'Created, but the text is unreadable.' );
						item.appendChild( warning );
					}
				} else {
					var strong = document.createElement( 'strong' );
					var reason = document.createElement( 'span' );

					strong.textContent = r.name + ': ';
					// The server message is already escaped (it may contain <code> and <br>).
					reason.innerHTML = r.message || t( 'failed', 'The conversion failed.' );
					item.appendChild( strong );
					item.appendChild( reason );
				}
				list.appendChild( item );
			} );

			progress.classList.toggle( 'is-failed', good.length < results.length );
			if ( ui.spinner ) {
				ui.spinner.hidden = true;
			}
			ui.bar.hidden = true;
			ui.title.textContent = summary;
			ui.hint.textContent = '';
			ui.detail.textContent = '';
			ui.detail.appendChild( list );
			ui.detail.appendChild( link( t( 'viewList', 'View documents' ), t( 'listUrl', '' ) ) );
			announce( summary );
			setBusy( false );
		}

		form.addEventListener( 'submit', function ( event ) {
			// A second click would send the file again and create a duplicate document.
			if ( busy ) {
				event.preventDefault();
				return;
			}

			// A chosen file cannot be converted (see initFileList()): nothing is sent until it is removed.
			if ( form.hasAttribute( 'data-jimca-invalid' ) ) {
				event.preventDefault();
				var firstProblem = document.querySelector( '.jimca-file-list__reason' );
				if ( firstProblem && firstProblem.scrollIntoView ) {
					firstProblem.scrollIntoView( { block: 'center' } );
				}
				return;
			}

			if ( toast ) {
				toast.hidden = true;
			}

			var files = input && input.files ? Array.prototype.slice.call( input.files ) : [];

			// Without these the plain form submission still works (all files in one request).
			if ( ! files.length || ! window.fetch || ! window.FormData || ! window.TextEncoder || ! window.Promise ) {
				setBusy( true );
				show( t( 'converting', 'Converting the document…' ), t( 'convertingHint', '' ) );
				return;
			}

			event.preventDefault();
			setBusy( true );

			if ( files.length > t( 'maxFiles', 20 ) ) {
				fail( format( t( 'tooMany', 'Choose up to %s files at a time.' ), [ t( 'maxFiles', 20 ) ] ) );
				return;
			}

			convertQueue( files, String( new FormData( form ).get( 'jimca_upload_nonce' ) || '' ) ).then( finish );
		} );

		// Going back in the browser may restore the page from cache still showing "converting".
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				progress.hidden = true;
				setBusy( false );
			}
		} );
	}

	/**
	 * "Also publish in": shows only the fields that apply to the chosen post
	 * type and status. Fields of hidden rows are disabled, so they are not sent.
	 * Without JavaScript everything stays visible and the server reads what
	 * matches the chosen type.
	 */
	function initDestination() {
		var type   = document.getElementById( 'jimca_dest_type' );
		var status = document.getElementById( 'jimca_dest_status' );
		var date   = document.getElementById( 'jimca_dest_date' );

		if ( ! type || ! status ) {
			return;
		}

		function toggle( row, visible ) {
			row.hidden = ! visible;
			Array.prototype.forEach.call( row.querySelectorAll( 'input, select, textarea' ), function ( field ) {
				field.disabled = ! visible;
			} );
		}

		function update() {
			var chosen   = type.value;
			var schedule = '' !== chosen && 'future' === status.value;

			Array.prototype.forEach.call( document.querySelectorAll( '[data-jimca-dest-only]' ), function ( row ) {
				toggle( row, '' !== chosen );
			} );

			Array.prototype.forEach.call( document.querySelectorAll( '[data-jimca-dest-schedule]' ), function ( row ) {
				toggle( row, schedule );
			} );

			Array.prototype.forEach.call( document.querySelectorAll( '[data-jimca-dest-type]' ), function ( row ) {
				toggle( row, row.getAttribute( 'data-jimca-dest-type' ) === chosen );
			} );

			if ( date ) {
				date.required = schedule;
			}
		}

		type.addEventListener( 'change', update );
		status.addEventListener( 'change', update );
		update();
	}

	/**
	 * Assistant card: only the key and model fields of the chosen provider
	 * are shown. Hidden fields are still sent, so a saved key of another
	 * provider is kept. Without JavaScript every provider stays visible.
	 */
	function initAssistant() {
		var select = document.querySelector( '[data-jimca-provider]' );

		if ( ! select ) {
			return;
		}

		var blocks = document.querySelectorAll( '[data-jimca-provider-block]' );

		function show() {
			Array.prototype.forEach.call( blocks, function ( block ) {
				block.hidden = block.getAttribute( 'data-jimca-provider-block' ) !== select.value;
			} );
		}

		select.addEventListener( 'change', show );
		show();
	}

	function boot() {
		init();
		initFileList();
		initUpload();
		initAssistant();
		initDestination();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
