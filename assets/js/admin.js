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
	 * Upload with progress. A single file is converted in the background
	 * (see JIMCA_Conversion_Job): the browser sends the file, asks for the
	 * conversion without waiting for that answer (which may turn into a proxy
	 * "504") and follows the progress until the end. Several TXT/MD files, or
	 * a browser without fetch, use the plain form submission.
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

		function format( template, values ) {
			var next = 0;
			return template.replace( /%(\d+\$)?s/g, function ( match, position ) {
				var index = position ? parseInt( position, 10 ) - 1 : next++;
				return String( values[ index ] );
			} );
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
					var message = 413 === response.status
						? t( 'tooLarge', 'The server refused the file because it is too large.' )
						: format( t( 'httpError', 'The server answered with an error (HTTP %s).' ), [ response.status ] );
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
			var finished = false;
			var misses   = 0;

			function settle( status ) {
				if ( finished ) {
					return true;
				}
				if ( 'done' === status.status ) {
					finished = true;
					ui.bar.value = 100;
					ui.title.textContent = t( 'done', 'Conversion finished. Opening the result…' );
					announce( ui.title.textContent );
					window.location.href = status.redirect;
					return true;
				}
				if ( 'failed' === status.status ) {
					finished = true;
					fail( status.message );
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
					finished = true;
					fail( message || t( 'network', 'Could not reach the server.' ) );
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
		}

		form.addEventListener( 'submit', function ( event ) {
			// A second click would send the file again and create a duplicate document.
			if ( busy ) {
				event.preventDefault();
				return;
			}

			if ( toast ) {
				toast.hidden = true;
			}

			var single = input && input.files && 1 === input.files.length;

			if ( ! single || ! window.fetch || ! window.FormData ) {
				setBusy( true );
				show( t( 'converting', 'Converting the document…' ), t( 'convertingHint', '' ) );
				return;
			}

			event.preventDefault();
			setBusy( true );
			milestone = -1;
			ui.bar.hidden = true;
			show( t( 'sending', 'Sending the file…' ), t( 'longHint', '' ) );
			announce( t( 'sending', 'Sending the file…' ) );

			var data  = new FormData( form );
			var nonce = String( data.get( 'jimca_upload_nonce' ) || '' );
			data.set( 'action', t( 'actionStart', '' ) );

			send( data ).then( function ( answer ) {
				if ( ! answer.success ) {
					fail( answer.data && answer.data.message );
					return;
				}

				ui.title.textContent = t( 'reading', 'Reading the file structure…' );

				track( answer.data.job, nonce );
			} ).catch( function () {
				fail( t( 'network', 'Could not reach the server.' ) );
			} );
		} );

		// Going back in the browser may restore the page from cache still showing "converting".
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				progress.hidden = true;
				setBusy( false );
			}
		} );
	}

	function boot() {
		init();
		initUpload();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
