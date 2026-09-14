/**
 * Pequenas melhorias de UX na tela "Novo Documento":
 * confirma visualmente (e para leitores de tela) que um arquivo foi
 * escolhido no campo de upload, antes de o formulário ser enviado.
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

		input.addEventListener( 'change', function () {
			if ( ! input.files || ! input.files.length ) {
				toast.hidden = true;
				return;
			}

			var file = input.files[ 0 ];
			var size = formatSize( file.size );
			var template = t( 'fileAdded', 'Arquivo adicionado: %s' );
			var label = file.name + ( size ? ' (' + size + ')' : '' );

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
	 * Feedback de "convertendo…" enquanto o formulário é enviado.
	 * O envio é um POST normal (a página navega para admin-post.php e volta),
	 * então basta mostrar o estado no momento do submit: ele desaparece
	 * sozinho quando a página seguinte carrega.
	 */
	function initSubmitFeedback() {
		var form     = document.getElementById( 'jimca-upload-form' );
		var progress = document.getElementById( 'jimca-upload-progress' );

		if ( ! form || ! progress ) {
			return;
		}

		var title  = progress.querySelector( '[data-jimca-progress-title]' );
		var hint   = progress.querySelector( '[data-jimca-progress-hint]' );
		var toast  = document.getElementById( 'jimca-file-toast' );
		var button = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		var busy   = false;

		function reset() {
			busy = false;
			progress.hidden = true;
			if ( button ) {
				button.removeAttribute( 'aria-disabled' );
				button.classList.remove( 'is-busy' );
			}
		}

		form.addEventListener( 'submit', function ( event ) {
			// Segundo clique reenviaria o arquivo inteiro e criaria um
			// documento duplicado — então bloqueamos.
			if ( busy ) {
				event.preventDefault();
				return;
			}

			busy = true;

			if ( toast ) {
				toast.hidden = true;
			}

			if ( button ) {
				/*
				 * aria-disabled em vez de disabled: um campo desabilitado pode
				 * ser descartado pelo navegador ao montar os dados do envio.
				 * O bloqueio real de envio duplicado é a variável `busy`.
				 */
				button.setAttribute( 'aria-disabled', 'true' );
				button.classList.add( 'is-busy' );
			}

			if ( title ) {
				title.textContent = t( 'converting', 'Convertendo o documento…' );
			}
			if ( hint ) {
				hint.textContent = t( 'convertingHint', 'Isso pode levar até um minuto. Não feche nem atualize esta página.' );
			}

			progress.hidden = false;
		} );

		/*
		 * Se o usuário voltar pelo botão "voltar" do navegador, a página pode
		 * vir do cache já com o estado de "convertendo" na tela. Limpamos.
		 */
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				reset();
			}
		} );
	}

	function boot() {
		init();
		initSubmitFeedback();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
