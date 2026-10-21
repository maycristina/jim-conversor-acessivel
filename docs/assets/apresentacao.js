/*
 * Jim — apresentação em slides. Sem bibliotecas.
 * Teclado: setas, PageUp/PageDown, Espaço, Home/End, F (tela cheia), N (notas), G (miniaturas). Toque: arrastar para os lados.
 * O endereço guarda o slide (#3). Slides inativos ficam com inert e aria-hidden; o anúncio vai para uma região aria-live.
 */
(function () {
	'use strict';

	var deck = document.getElementById('deck');
	if (!deck) { return; }
	var viewport = deck.querySelector('.deck__viewport');
	var stage = deck.querySelector('.stage');
	var slides = Array.prototype.slice.call(stage.querySelectorAll('.slide'));
	var total = slides.length;
	var count = deck.querySelector('[data-count]');
	var live = deck.querySelector('[data-live]');
	var prog = deck.querySelector('.deck__prog');
	var btnPrev = deck.querySelector('[data-prev]');
	var btnNext = deck.querySelector('[data-next]');
	var btnThumbs = deck.querySelector('[data-thumbs]');
	var btnNotes = deck.querySelector('[data-notes]');
	var btnFull = deck.querySelector('[data-full]');
	var btnPdf = deck.querySelector('[data-pdf]');
	var thumbsBox = deck.querySelector('.deck__thumbs');
	var notesBox = deck.querySelector('.deck__notes');
	var notesBody = deck.querySelector('[data-notes-body]');
	var slideWord = deck.getAttribute('data-slide-word') || '';
	var current = -1;
	var leaveTimer = null;

	/* ------------------------------------------------ escala do palco (transform direto, sem variável no pai) */
	function fit() {
		var w = viewport.clientWidth, h = viewport.clientHeight;
		var k = Math.min(w / 1920, h / 1080);
		stage.style.transform = 'scale(' + k + ')';
	}
	if ('ResizeObserver' in window) { new ResizeObserver(fit).observe(viewport); }
	window.addEventListener('resize', fit);
	fit();

	function label(i) { return slideWord + ' ' + (i + 1) + ' / ' + total; }

	/* ------------------------------------------------ miniaturas: cópias reais dos slides, só para olhar */
	slides.forEach(function (sl, i) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'd-thumb';
		b.setAttribute('aria-label', label(i) + ': ' + (sl.getAttribute('data-title') || ''));
		var mini = document.createElement('div');
		mini.className = 'mini';
		mini.setAttribute('aria-hidden', 'true');
		var c = sl.cloneNode(true);
		c.removeAttribute('id'); c.className = 'slide s' + (i + 1);
		c.setAttribute('inert', '');
		mini.appendChild(c);
		var n = document.createElement('span');
		n.className = 'n'; n.textContent = String(i + 1);
		b.appendChild(mini); b.appendChild(n);
		b.addEventListener('click', function () { go(i); });
		thumbsBox.appendChild(b);
	});
	var thumbs = Array.prototype.slice.call(thumbsBox.querySelectorAll('.d-thumb'));

	/* ------------------------------------------------ navegação */
	function clamp(i) { return Math.max(0, Math.min(total - 1, i)); }
	

	function go(i, silent) {
		i = clamp(i);
		if (i === current) { return; }
		var prev = current > -1 ? slides[current] : null;
		if (prev) {
			prev.classList.remove('is-active');
			prev.classList.add('is-leaving');
			prev.setAttribute('aria-hidden', 'true'); prev.setAttribute('inert', '');
			var gone = prev;
			clearTimeout(leaveTimer);
			leaveTimer = setTimeout(function () { gone.classList.remove('is-leaving'); }, 340);
		}
		current = i;
		var sl = slides[i];
		sl.classList.remove('is-leaving');
		sl.removeAttribute('aria-hidden'); sl.removeAttribute('inert');
		// um quadro para o navegador registrar o estado inicial antes de ativar (a transição de entrada precisa dele)
		void sl.offsetWidth;
		sl.classList.add('is-active');
		count.textContent = String(i + 1) + ' / ' + total;
		prog.style.transform = 'scaleX(' + ((i + 1) / total) + ')';
		btnPrev.disabled = i === 0; btnNext.disabled = i === total - 1;
		thumbs.forEach(function (t, k) { if (k === i) { t.setAttribute('aria-current', 'true'); } else { t.removeAttribute('aria-current'); } });
		if (thumbsBox.classList.contains('is-open') && thumbs[i].scrollIntoView) { thumbs[i].scrollIntoView({ block: 'nearest', inline: 'center' }); }
		// vídeo só toca no slide ativo
		slides.forEach(function (x) { var v = x.querySelector('video'); if (v) { if (x === sl) { var p = v.play(); if (p && p.catch) { p.catch(function () {}); } } else { v.pause(); } } });
		var notes = sl.querySelector('.notes');
		notesBody.innerHTML = notes ? notes.innerHTML : '';
		if (!silent) { live.textContent = label(i) + ': ' + (sl.getAttribute('data-title') || ''); }
		if (history.replaceState) { history.replaceState(null, '', '#' + (i + 1)); }
	}
	function next() { go(current + 1); }
	function prevS() { go(current - 1); }

	btnPrev.addEventListener('click', prevS);
	btnNext.addEventListener('click', next);

	function toggle(btn, box) {
		var on = !box.classList.contains('is-open');
		box.classList.toggle('is-open', on);
		deck.classList.toggle(box === thumbsBox ? 'has-thumbs' : 'has-notes', on);
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		fit();
		return on;
	}
	btnThumbs.addEventListener('click', function () { if (toggle(btnThumbs, thumbsBox) && thumbs[current]) { thumbs[current].scrollIntoView({ block: 'nearest', inline: 'center' }); } });
	btnNotes.addEventListener('click', function () { toggle(btnNotes, notesBox); });

	function isFull() { return document.fullscreenElement === deck || document.webkitFullscreenElement === deck; }
	function full() {
		if (isFull()) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
		var req = deck.requestFullscreen || deck.webkitRequestFullscreen;
		if (!req) { return; }
		var p = req.call(deck);
		var lock = function () { if (screen.orientation && screen.orientation.lock) { screen.orientation.lock('landscape').catch(function () {}); } };
		if (p && p.then) { p.then(lock).catch(function () {}); } else { lock(); }
	}
	btnFull.addEventListener('click', full);
	document.addEventListener('fullscreenchange', function () { btnFull.setAttribute('aria-pressed', isFull() ? 'true' : 'false'); fit(); });
	document.addEventListener('webkitfullscreenchange', fit);

	// PDF estático: o CSS de impressão põe um slide por página, em 1920×1080, com as cores e a grade.
	btnPdf.addEventListener('click', function () { window.print(); });

	document.addEventListener('keydown', function (e) {
		var t = e.target;
		if (e.metaKey || e.ctrlKey || e.altKey) { return; }
		if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) { return; }
		var k = e.key;
		// em um botão ou link, Espaço e Enter continuam sendo do botão
		var onControl = t && (t.tagName === 'BUTTON' || t.tagName === 'A') && (k === ' ' || k === 'Enter');
		if (onControl) { return; }
		if (k === 'ArrowRight' || k === 'PageDown' || k === ' ') { e.preventDefault(); next(); }
		else if (k === 'ArrowLeft' || k === 'PageUp') { e.preventDefault(); prevS(); }
		else if (k === 'Home') { e.preventDefault(); go(0); }
		else if (k === 'End') { e.preventDefault(); go(total - 1); }
		else if (k === 'f' || k === 'F') { full(); }
		else if (k === 'n' || k === 'N') { toggle(btnNotes, notesBox); }
		else if (k === 'g' || k === 'G') { toggle(btnThumbs, thumbsBox); }
	});

	/* toque: arrasta para os lados; decide pela velocidade, não só pela distância */
	var sx = 0, sy = 0, st = 0, tracking = false;
	viewport.addEventListener('pointerdown', function (e) {
		if (e.pointerType === 'mouse' || e.target.closest('a,button')) { return; }
		tracking = true; sx = e.clientX; sy = e.clientY; st = Date.now();
		try { viewport.setPointerCapture(e.pointerId); } catch (err) { /* sem captura */ }
	});
	viewport.addEventListener('pointerup', function (e) {
		if (!tracking) { return; }
		tracking = false;
		var dx = e.clientX - sx, dy = e.clientY - sy, dt = Math.max(1, Date.now() - st);
		if (Math.abs(dx) > Math.abs(dy) * 1.4 && (Math.abs(dx) > 60 || Math.abs(dx) / dt > 0.11)) { if (dx < 0) { next(); } else { prevS(); } }
	});
	viewport.addEventListener('pointercancel', function () { tracking = false; });

	/* tela cheia: esconde a barra depois de um tempo parado */
	var idle = null;
	deck.addEventListener('pointermove', function () {
		if (!isFull()) { return; }
		deck.classList.remove('is-clean'); clearTimeout(idle);
		idle = setTimeout(function () { deck.classList.add('is-clean'); }, 2600);
	});

	// slides inativos fora da árvore de acessibilidade desde o início
	slides.forEach(function (s) { s.setAttribute('aria-hidden', 'true'); s.setAttribute('inert', ''); });
	var start = parseInt((location.hash || '').replace('#', ''), 10);
	go(isNaN(start) ? 0 : start - 1, true);
	window.addEventListener('hashchange', function () { var n = parseInt((location.hash || '').replace('#', ''), 10); if (!isNaN(n)) { go(n - 1); } });
}());
