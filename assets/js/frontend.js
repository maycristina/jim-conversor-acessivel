/**
 * Player de leitura do documento convertido: tamanho do texto, tema,
 * alto contraste e leitura em voz alta (Web Speech API).
 *
 * Progressive enhancement: sem JS, ou sem suporte do navegador, o conteúdo
 * continua totalmente legível como texto normal — o player inteiro fica
 * com o atributo `hidden` definido no HTML e nunca é revelado.
 *
 * Os menus seguem o padrão de menu do Material 3: abrem a partir do botão,
 * só um de cada vez, fecham com Esc, clique fora ou Tab, e devolvem o foco
 * ao botão que os abriu.
 */
( function () {
	'use strict';

	var i18n = window.jimcaFrontendI18n || {};
	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var activeStop = null; // Para o player que estiver tocando (só um por página).
	var openMenu = null;   // Menu aberto no momento (só um por página).

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	/* ---------------------------------------------------------------
	 * Preferências do leitor, guardadas por documento no navegador dele.
	 * ------------------------------------------------------------- */

	function createPrefs( viewer ) {
		var key = 'jimca-prefs-' + viewer.id;
		var data = {};

		try {
			data = JSON.parse( window.localStorage.getItem( key ) || '{}' ) || {};
		} catch ( e ) {
			data = {}; // localStorage indisponível: segue com os padrões do site.
		}

		return {
			get: function ( name, fallback ) {
				return typeof data[ name ] === 'undefined' ? fallback : data[ name ];
			},
			set: function ( name, value ) {
				data[ name ] = value;
				try {
					window.localStorage.setItem( key, JSON.stringify( data ) );
				} catch ( e ) {
					// Sem localStorage a escolha vale só para esta visita.
				}
			},
		};
	}

	/* ---------------------------------------------------------------
	 * Menus
	 * ------------------------------------------------------------- */

	function menuItems( menu ) {
		return Array.prototype.slice.call(
			menu.querySelectorAll( '[role="menuitem"], [role="menuitemradio"]' )
		);
	}

	/**
	 * Impede que o menu vaze para fora da tela quando o botão que o abre
	 * está perto de uma das bordas (acontece sempre no celular, com a barra
	 * ocupando quase toda a largura).
	 */
	function keepMenuOnScreen( menu ) {
		menu.style.setProperty( '--jimca-menu-shift', '0px' );

		var rect = menu.getBoundingClientRect();
		var margin = 8;
		var shift = 0;

		if ( rect.left < margin ) {
			shift = margin - rect.left;
		} else if ( rect.right > window.innerWidth - margin ) {
			shift = ( window.innerWidth - margin ) - rect.right;
		}

		if ( shift ) {
			menu.style.setProperty( '--jimca-menu-shift', Math.round( shift ) + 'px' );
		}
	}

	function closeMenu( entry, returnFocus ) {
		if ( ! entry ) {
			return;
		}

		entry.menu.hidden = true;
		entry.trigger.setAttribute( 'aria-expanded', 'false' );

		if ( openMenu === entry ) {
			openMenu = null;
		}

		if ( returnFocus ) {
			entry.trigger.focus();
		}
	}

	function openMenuEntry( entry ) {
		if ( openMenu && openMenu !== entry ) {
			closeMenu( openMenu, false );
		}

		entry.menu.hidden = false;
		entry.trigger.setAttribute( 'aria-expanded', 'true' );
		openMenu = entry;

		keepMenuOnScreen( entry.menu );

		var items = menuItems( entry.menu );
		if ( ! items.length ) {
			return;
		}

		// Abre já no item selecionado, quando o menu é de escolha única.
		var checked = items.filter( function ( item ) {
			return 'true' === item.getAttribute( 'aria-checked' );
		} );

		( checked[ 0 ] || items[ 0 ] ).focus();
	}

	function moveFocus( items, current, delta ) {
		if ( ! items.length ) {
			return;
		}
		var index = items.indexOf( current );
		var next = ( index + delta + items.length ) % items.length;
		items[ next ].focus();
	}

	function setupMenus( viewer ) {
		var entries = {};

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-menu-trigger]' ),
			function ( trigger ) {
				var name = trigger.getAttribute( 'data-jimca-menu-trigger' );
				var menu = viewer.querySelector( '[data-jimca-menu="' + name + '"]' );

				if ( ! menu ) {
					return;
				}

				var entry = { trigger: trigger, menu: menu, name: name };
				entries[ name ] = entry;

				trigger.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					event.stopPropagation();

					if ( openMenu === entry ) {
						closeMenu( entry, true );
					} else {
						openMenuEntry( entry );
					}
				} );

				trigger.addEventListener( 'keydown', function ( event ) {
					if ( 'ArrowUp' === event.key || 'ArrowDown' === event.key ) {
						event.preventDefault();
						openMenuEntry( entry );
					}
				} );

				menu.addEventListener( 'keydown', function ( event ) {
					var items = menuItems( menu );

					if ( 'Escape' === event.key ) {
						event.preventDefault();
						closeMenu( entry, true );
					} else if ( 'ArrowDown' === event.key ) {
						event.preventDefault();
						moveFocus( items, document.activeElement, 1 );
					} else if ( 'ArrowUp' === event.key ) {
						event.preventDefault();
						moveFocus( items, document.activeElement, -1 );
					} else if ( 'Home' === event.key ) {
						event.preventDefault();
						if ( items[ 0 ] ) {
							items[ 0 ].focus();
						}
					} else if ( 'End' === event.key ) {
						event.preventDefault();
						if ( items.length ) {
							items[ items.length - 1 ].focus();
						}
					} else if ( 'Tab' === event.key ) {
						// Sair do menu por Tab fecha, como manda o padrão.
						closeMenu( entry, false );
					}
				} );

				// Cliques dentro do menu não devem chegar ao document e fechá-lo
				// antes do próprio item ser processado.
				menu.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
				} );
			}
		);

		return entries;
	}

	/* ---------------------------------------------------------------
	 * Tamanho do texto, tema e contraste
	 * ------------------------------------------------------------- */

	function setupAppearance( viewer, prefs, entries ) {
		var content = viewer.querySelector( '[data-jimca-content]' );
		var contrastBtn = viewer.querySelector( '[data-jimca-contrast-toggle]' );
		var scale = parseFloat( prefs.get( 'scale', 1 ) ) || 1;

		function applyScale() {
			if ( content ) {
				content.style.fontSize = scale + 'em';
			}
		}

		function applyTheme( theme ) {
			if ( ! theme ) {
				return;
			}

			// Tira qualquer jimca-theme-* atual antes de pôr o novo (o padrão
			// do site já vem numa dessas classes, renderizado pelo PHP).
			viewer.className = viewer.className.replace( /\bjimca-theme-\S+/g, '' ).trim();
			viewer.classList.add( 'jimca-theme-' + theme );

			Array.prototype.forEach.call(
				viewer.querySelectorAll( '[data-jimca-theme]' ),
				function ( item ) {
					item.setAttribute(
						'aria-checked',
						item.getAttribute( 'data-jimca-theme' ) === theme ? 'true' : 'false'
					);
				}
			);
		}

		applyScale();

		if ( prefs.get( 'theme', '' ) ) {
			applyTheme( prefs.get( 'theme', '' ) );
		}

		if ( prefs.get( 'contrast', false ) ) {
			viewer.classList.add( 'jimca-high-contrast' );
			if ( contrastBtn ) {
				contrastBtn.setAttribute( 'aria-pressed', 'true' );
			}
		}

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-font]' ),
			function ( btn ) {
				btn.addEventListener( 'click', function () {
					var action = btn.getAttribute( 'data-jimca-font' );

					if ( 'increase' === action ) {
						scale = Math.min( 2, Math.round( ( scale + 0.1 ) * 10 ) / 10 );
					} else if ( 'decrease' === action ) {
						scale = Math.max( 0.75, Math.round( ( scale - 0.1 ) * 10 ) / 10 );
					} else {
						scale = 1;
					}

					applyScale();
					prefs.set( 'scale', scale );

					/*
					 * O menu continua aberto de propósito: aumentar o texto é
					 * uma ação incremental, e fechar a cada toque obrigaria a
					 * reabrir o menu para cada passo.
					 */
				} );
			}
		);

		Array.prototype.forEach.call(
			viewer.querySelectorAll( '[data-jimca-theme]' ),
			function ( btn ) {
				btn.addEventListener( 'click', function () {
					var theme = btn.getAttribute( 'data-jimca-theme' );
					applyTheme( theme );
					prefs.set( 'theme', theme );
					closeMenu( entries.font, true );
				} );
			}
		);

		if ( contrastBtn ) {
			contrastBtn.addEventListener( 'click', function () {
				var isOn = viewer.classList.toggle( 'jimca-high-contrast' );
				contrastBtn.setAttribute( 'aria-pressed', isOn ? 'true' : 'false' );
				prefs.set( 'contrast', isOn );
			} );
		}
	}

	/* ---------------------------------------------------------------
	 * Ocultar / mostrar a barra
	 * ------------------------------------------------------------- */

	function setupVisibilityToggle( viewer, player, prefs ) {
		var bar = player.querySelector( '[data-jimca-player-bar]' );
		var hideBtn = player.querySelector( '[data-jimca-hide]' );
		var showBtn = player.querySelector( '[data-jimca-show]' );

		if ( ! bar || ! hideBtn || ! showBtn ) {
			return;
		}

		function apply( hidden, moveFocusTo ) {
			bar.hidden = hidden;
			showBtn.hidden = ! hidden;

			/*
			 * `jimca-player-active` continua posta mesmo com a barra oculta:
			 * o botão de reabrir também flutua sobre o texto, e sem a reserva
			 * de espaço ele cobriria a última linha do documento.
			 */

			if ( moveFocusTo ) {
				moveFocusTo.focus();
			}
		}

		apply( true === prefs.get( 'barHidden', false ), null );

		hideBtn.addEventListener( 'click', function () {
			if ( openMenu ) {
				closeMenu( openMenu, false );
			}
			prefs.set( 'barHidden', true );
			apply( true, showBtn );
		} );

		showBtn.addEventListener( 'click', function () {
			prefs.set( 'barHidden', false );
			apply( false, hideBtn );
		} );
	}

	/* ---------------------------------------------------------------
	 * Leitura em voz alta
	 * ------------------------------------------------------------- */

	function setupAudio( viewer, player, prefs, entries ) {
		var playBtn = player.querySelector( '[data-jimca-play]' );
		var stopBtn = player.querySelector( '[data-jimca-stop]' );

		if ( ! playBtn || ! stopBtn ) {
			return;
		}

		var supported = ( 'speechSynthesis' in window ) &&
			typeof window.SpeechSynthesisUtterance !== 'undefined';

		if ( ! supported ) {
			/*
			 * Sem suporte do navegador, os controles de áudio saem de cena em
			 * vez de ficarem ali sem fazer nada. Os ajustes visuais (tamanho,
			 * tema, contraste) continuam funcionando normalmente.
			 */
			Array.prototype.forEach.call(
				player.querySelectorAll( '[data-jimca-play], [data-jimca-stop], [data-jimca-menu-trigger="voice"], [data-jimca-menu-trigger="speed"]' ),
				function ( el ) {
					var slot = el.closest( '.jimca-player__slot' );
					if ( slot ) {
						slot.hidden = true;
					}
				}
			);
			return;
		}

		var synth = window.speechSynthesis;
		var content = viewer.querySelector( '[data-jimca-content]' );
		var statusEl = player.querySelector( '[data-jimca-status]' );
		var playIcon = playBtn.querySelector( '[data-jimca-icon="play"]' );
		var pauseIcon = playBtn.querySelector( '[data-jimca-icon="pause"]' );
		var playLabel = playBtn.querySelector( '[data-jimca-play-label]' );
		var voiceMenu = player.querySelector( '[data-jimca-menu="voice"]' );

		var elements = Array.prototype.slice.call(
			content ? content.querySelectorAll( 'h1, h2, h3, h4, h5, h6, p, li, blockquote, figcaption' ) : []
		);

		if ( ! elements.length && content ) {
			elements = [ content ];
		}

		var index = 0;
		var state = 'idle'; // idle | playing | paused
		var rate = parseFloat( prefs.get( 'rate', 1 ) ) || 1;
		var voiceName = prefs.get( 'voice', '' );

		function setStatus( text ) {
			if ( statusEl ) {
				statusEl.textContent = text;
			}
		}

		function clearHighlight() {
			elements.forEach( function ( el ) {
				el.classList.remove( 'jimca-reading' );
			} );
		}

		function updateUI() {
			var playing = 'playing' === state;

			playBtn.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );

			if ( playIcon && pauseIcon ) {
				playIcon.hidden = playing;
				pauseIcon.hidden = ! playing;
			}

			if ( playLabel ) {
				playLabel.textContent = playing
					? t( 'pause', 'Pausar' )
					: ( 'paused' === state ? t( 'resume', 'Continuar' ) : t( 'play', 'Ouvir' ) );
			}

			stopBtn.disabled = 'idle' === state;
		}

		function stopUI( statusText ) {
			synth.cancel();
			state = 'idle';
			index = 0;
			clearHighlight();
			updateUI();

			if ( statusText ) {
				setStatus( statusText );
			}
		}

		function currentVoice() {
			var voices = synth.getVoices();
			var match = voices.filter( function ( voice ) {
				return voice.name === voiceName;
			} );
			return match[ 0 ] || null;
		}

		function buildVoiceMenu() {
			var voices = synth.getVoices();

			if ( ! voiceMenu || ! voices.length ) {
				return;
			}

			voiceMenu.textContent = '';

			voices.forEach( function ( voice ) {
				var item = document.createElement( 'button' );
				item.type = 'button';
				item.className = 'jimca-player__menuitem';
				item.setAttribute( 'role', 'menuitemradio' );
				item.setAttribute( 'data-jimca-voice', voice.name );
				item.setAttribute( 'aria-checked', voice.name === voiceName ? 'true' : 'false' );
				item.textContent = voice.name + ' (' + voice.lang + ')';

				item.addEventListener( 'click', function () {
					voiceName = voice.name;
					prefs.set( 'voice', voiceName );

					Array.prototype.forEach.call(
						voiceMenu.querySelectorAll( '[data-jimca-voice]' ),
						function ( other ) {
							other.setAttribute(
								'aria-checked',
								other.getAttribute( 'data-jimca-voice' ) === voiceName ? 'true' : 'false'
							);
						}
					);

					closeMenu( entries.voice, true );
				} );

				voiceMenu.appendChild( item );
			} );
		}

		buildVoiceMenu();

		// Em vários navegadores a lista de vozes só fica pronta depois.
		if ( typeof synth.onvoiceschanged !== 'undefined' ) {
			synth.addEventListener( 'voiceschanged', buildVoiceMenu );
		}

		// Velocidade.
		Array.prototype.forEach.call(
			player.querySelectorAll( '[data-jimca-rate]' ),
			function ( item ) {
				if ( parseFloat( item.getAttribute( 'data-jimca-rate' ) ) === rate ) {
					item.setAttribute( 'aria-checked', 'true' );
				} else {
					item.setAttribute( 'aria-checked', 'false' );
				}

				item.addEventListener( 'click', function () {
					rate = parseFloat( item.getAttribute( 'data-jimca-rate' ) ) || 1;
					prefs.set( 'rate', rate );

					Array.prototype.forEach.call(
						player.querySelectorAll( '[data-jimca-rate]' ),
						function ( other ) {
							other.setAttribute(
								'aria-checked',
								parseFloat( other.getAttribute( 'data-jimca-rate' ) ) === rate ? 'true' : 'false'
							);
						}
					);

					/*
					 * Trocar a velocidade no meio da leitura só vale para a
					 * próxima fala se não recomeçarmos: a Web Speech API não
					 * muda o `rate` de uma fala já em andamento. Reiniciamos
					 * o trecho atual para o efeito ser imediato.
					 */
					if ( 'playing' === state ) {
						synth.cancel();
						speakNext();
					}

					closeMenu( entries.speed, true );
				} );
			}
		);

		function speakNext() {
			if ( index >= elements.length ) {
				state = 'idle';
				index = 0;
				clearHighlight();
				updateUI();
				setStatus( t( 'statusDone', 'Leitura concluída.' ) );
				return;
			}

			var el = elements[ index ];
			var text = el.textContent.trim();

			if ( '' === text ) {
				index++;
				speakNext();
				return;
			}

			var utterance = new window.SpeechSynthesisUtterance( text );
			var voice = currentVoice();

			if ( voice ) {
				utterance.voice = voice;
			}

			utterance.rate = rate;

			utterance.onend = function () {
				index++;
				speakNext();
			};

			utterance.onerror = function () {
				index++;
				speakNext();
			};

			clearHighlight();
			el.classList.add( 'jimca-reading' );
			el.scrollIntoView( { block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' } );

			synth.speak( utterance );
		}

		playBtn.addEventListener( 'click', function () {
			if ( 'playing' === state ) {
				synth.pause();
				state = 'paused';
				setStatus( t( 'statusPaused', 'Leitura pausada.' ) );
			} else if ( 'paused' === state ) {
				synth.resume();
				state = 'playing';
				setStatus( t( 'statusReading', 'Lendo em voz alta.' ) );
				activeStop = stopUI;
			} else {
				if ( activeStop && activeStop !== stopUI ) {
					activeStop();
				}
				synth.cancel();
				index = 0;
				state = 'playing';
				setStatus( t( 'statusReading', 'Lendo em voz alta.' ) );
				activeStop = stopUI;
				speakNext();
			}

			updateUI();
		} );

		stopBtn.addEventListener( 'click', function () {
			stopUI( t( 'statusStopped', 'Leitura interrompida.' ) );
		} );

		updateUI();
	}

	/* ---------------------------------------------------------------
	 * A barra só aparece enquanto o documento está na tela
	 * ------------------------------------------------------------- */

	function setupPlayerVisibility( viewer, player ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			player.classList.add( 'is-visible' );
			return;
		}

		var observer = new window.IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					player.classList.toggle( 'is-visible', entry.isIntersecting );

					if ( ! entry.isIntersecting && openMenu ) {
						closeMenu( openMenu, false );
					}
				} );
			},
			// Uma folga pequena evita a barra piscar ao passar raspando.
			{ rootMargin: '-40px 0px -40px 0px' }
		);

		observer.observe( viewer );
	}

	/* ---------------------------------------------------------------
	 * Início
	 * ------------------------------------------------------------- */

	function initViewer( viewer ) {
		var player = viewer.querySelector( '[data-jimca-player]' );

		if ( ! player ) {
			return;
		}

		// Só agora o player entra em cena: com JS ele funciona de verdade.
		player.hidden = false;
		viewer.classList.add( 'jimca-player-active' );

		var prefs = createPrefs( viewer );
		var entries = setupMenus( viewer );

		setupAppearance( viewer, prefs, entries );
		setupAudio( viewer, player, prefs, entries );
		setupVisibilityToggle( viewer, player, prefs );
		setupPlayerVisibility( viewer, player );
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-jimca-viewer]' ),
			initViewer
		);

		// Clique fora fecha o menu aberto.
		document.addEventListener( 'click', function () {
			if ( openMenu ) {
				closeMenu( openMenu, false );
			}
		} );

		// Esc fecha mesmo com o foco fora do menu.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && openMenu ) {
				closeMenu( openMenu, true );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
