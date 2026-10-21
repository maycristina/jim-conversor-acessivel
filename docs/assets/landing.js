/*
 * Demonstrações da landing: PDF no celular x página do Jim, estrutura HTML, leitor com tema, fonte e voz,
 * chat do assistente e antes x depois. Sem bibliotecas. Tudo funciona com o teclado, respeita "Reduzir
 * movimento" (html[data-motion=reduced]) e não toca áudio sem um clique.
 */
(function () {
	'use strict';

	var root = document.documentElement;
	function reduced() { return root.getAttribute('data-motion') === 'reduced'; }
	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	function inView(el, cb, once) {
		if (!('IntersectionObserver' in window)) { cb(); return; }
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) {
				if (en.isIntersecting) { cb(); if (once !== false) { io.unobserve(el); } }
			});
		}, { threshold: 0.45 });
		io.observe(el);
	}

	/* ------------------------------------------------- PDF no celular x página do Jim */
	function pdfDemo(box) {
		var phone = $('.jh-phone', box);
		var cap = $('[data-cap]', box);
		var btns = $$('.jh-seg', box);
		var touched = false;

		function set(mode) {
			phone.setAttribute('data-state', mode);
			btns.forEach(function (b) {
				var on = b.getAttribute('data-mode') === mode;
				b.classList.toggle('is-on', on);
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			cap.textContent = cap.getAttribute('data-' + mode);
		}
		btns.forEach(function (b) {
			b.addEventListener('click', function () { touched = true; set(b.getAttribute('data-mode')); });
		});
		// Uma vez, ao aparecer na tela: mostra a virada de PDF para página do Jim.
		inView(box, function () {
			if (reduced() || touched) { return; }
			window.setTimeout(function () { if (!touched) { set('jim'); } }, 2600);
		});
	}

	/* --------------------------------------------------------- estrutura do HTML */
	function structDemo(box) {
		var btn = $('[data-btn]', box);
		btn.addEventListener('click', function () {
			var on = box.classList.toggle('is-shown');
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			btn.textContent = box.getAttribute(on ? 'data-hide' : 'data-show');
		});
	}

	/* ------------------------------------------------ leitor: tema, fonte e leitura */
	function reader(box) {
		var page = $('.jh-reader__page', box);
		var paras = $$('[data-p]', box);
		var status = $('[data-status]', box);
		var playBtn = $('[data-play-btn]', box);
		var playLabel = $('[data-play-label]', box);
		var lang = box.getAttribute('data-lang') || 'pt-BR';
		var size = 0;
		var playing = false, index = -1, timer = null, run = 0;

		function say(msg) { status.textContent = msg; }

		$$('[data-font]', box).forEach(function (b) {
			b.addEventListener('click', function () {
				size = Math.max(-2, Math.min(4, size + parseInt(b.getAttribute('data-font'), 10)));
				page.style.setProperty('--s', size);
			});
		});

		$$('[data-theme-btn]', box).forEach(function (b) {
			b.addEventListener('click', function () {
				page.setAttribute('data-theme', b.getAttribute('data-theme-btn'));
				$$('[data-theme-btn]', box).forEach(function (o) {
					var on = o === b;
					o.classList.toggle('is-on', on);
					o.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
			});
		});

		function clearMarks() { paras.forEach(function (p) { p.classList.remove('is-reading'); }); }
		function setPlaying(on) {
			playing = on;
			playBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
			playBtn.classList.toggle('is-playing', on);
			playLabel.textContent = box.getAttribute(on ? 'data-pause' : 'data-play');
		}
		function cut() {
			run++;
			if (timer) { window.clearTimeout(timer); timer = null; }
			if ('speechSynthesis' in window) { window.speechSynthesis.cancel(); }
		}
		function voices() { return 'speechSynthesis' in window && window.speechSynthesis.getVoices().length > 0; }

		function next(myRun, useVoice) {
			if (myRun !== run || !playing) { return; }
			index++;
			if (index >= paras.length) {
				cut(); setPlaying(false); clearMarks(); index = -1; say(box.getAttribute('data-done')); return;
			}
			clearMarks();
			paras[index].classList.add('is-reading');
			var text = paras[index].textContent;
			if (useVoice) {
				var u = new window.SpeechSynthesisUtterance(text);
				u.lang = lang;
				u.onend = function () { next(myRun, true); };
				u.onerror = function () { next(myRun, false); };
				window.speechSynthesis.speak(u);
			} else {
				var words = text.split(/\s+/).length;
				timer = window.setTimeout(function () { next(myRun, false); }, Math.max(2600, words * 380));
			}
		}

		playBtn.addEventListener('click', function () {
			if (playing) {
				cut(); setPlaying(false); index = Math.max(-1, index - 1); say(box.getAttribute('data-stopped')); return;
			}
			cut();
			var useVoice = voices();
			setPlaying(true);
			say(box.getAttribute(useVoice ? 'data-voice' : 'data-sim'));
			next(run, useVoice);
		});

		box.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && playing) {
				cut(); setPlaying(false); clearMarks(); index = -1; say(box.getAttribute('data-stopped'));
			}
		});
		// A lista de vozes carrega de forma assíncrona em alguns navegadores.
		if ('speechSynthesis' in window) { window.speechSynthesis.getVoices(); }
	}

	/* ----------------------------------------------------------- chat do assistente */
	function chat(box) {
		var q = box.getAttribute('data-q'), a = box.getAttribute('data-a');
		var qEl = $('[data-q-el]', box), aEl = $('[data-a-el]', box);
		var token = 0;

		function build(el, text) {
			el.innerHTML = '<span class="sr"></span><span aria-hidden="true"></span>';
			el.firstChild.textContent = text;
			return el.lastChild;
		}
		function type(span, text, speed, done, my) {
			var i = 0;
			(function step() {
				if (my !== token) { return; }
				i++;
				span.textContent = text.slice(0, i);
				if (i < text.length) { window.setTimeout(step, speed); } else if (done) { done(); }
			}());
		}
		function play() {
			token++;
			var my = token;
			var qs = build(qEl, q), as = build(aEl, a);
			qEl.classList.remove('is-in'); aEl.classList.remove('is-in');
			if (reduced()) { qs.textContent = q; as.textContent = a; qEl.classList.add('is-in'); aEl.classList.add('is-in'); return; }
			qEl.classList.add('is-in');
			type(qs, q, 28, function () {
				window.setTimeout(function () {
					if (my !== token) { return; }
					aEl.classList.add('is-in');
					type(as, a, 20, null, my);
				}, 500);
			}, my);
		}
		$('[data-replay]', box).addEventListener('click', play);
		inView(box, play);
		// Sem JS o texto completo já está nas bolhas (aria); com JS elas começam vazias até a animação.
		build(qEl, q).textContent = q; build(aEl, a).textContent = a;
		qEl.classList.add('is-in'); aEl.classList.add('is-in');
	}

	/* ----------------------------------------------------------- antes x depois */
	function beforeAfter(box) {
		var status = $('[data-status]', box);
		var oldLane = $('.jh-ba__lane--old', box), newLane = $('.jh-ba__lane--new', box);
		var oldSteps = $$('[data-step]', oldLane), newSteps = $$('[data-step]', newLane);
		var token = 0;

		function reset() { oldSteps.concat(newSteps).forEach(function (s) { s.classList.remove('is-active', 'is-done'); }); }
		function finishAll() {
			oldSteps.concat(newSteps).forEach(function (s) { s.classList.remove('is-active'); s.classList.add('is-done'); });
			status.textContent = $('h3', newLane).textContent + ' ✓';
		}
		function lane(steps, my, name, delay) {
			var i = 0;
			(function tick() {
				if (my !== token) { return; }
				if (i > 0) { steps[i - 1].classList.remove('is-active'); steps[i - 1].classList.add('is-done'); }
				if (i < steps.length) {
					steps[i].classList.add('is-active');
					status.textContent = name + ' ' + (i + 1) + '/' + steps.length + ': ' + steps[i].textContent;
					i++;
					window.setTimeout(tick, delay);
				}
			}());
		}
		// sem botão: a comparação roda sozinha, uma vez, quando aparece na tela
		function start() {
			token++;
			reset();
			if (reduced()) { finishAll(); return; }
			var my = token;
			lane(oldSteps, my, $('h3', oldLane).textContent.split(':')[0], 800);
			lane(newSteps, my, $('h3', newLane).textContent.split(':')[0], 800);
			window.setTimeout(function () { if (my === token) { finishAll(); } }, 800 * (oldSteps.length + 1));
		}
		inView(box, start);
	}

	function init() {
		$$('[data-jh-pdfdemo]').forEach(pdfDemo);
		$$('[data-jh-struct]').forEach(structDemo);
		$$('[data-jh-reader]').forEach(reader);
		$$('[data-jh-chat]').forEach(chat);
		$$('[data-jh-ba]').forEach(beforeAfter);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
