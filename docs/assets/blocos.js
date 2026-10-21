/* Blocos da landing (gerado por build_blocos_assets.py) */
/*
 * Hero em órbita. Cada widget gira numa elipse; z (cos) dá a profundidade: os da frente ficam nítidos e maiores, os de trás
 * menores, mais claros e desfocados. O que passa na frente do JIM acende. O mouse inclina a órbita; o scroll acelera e abre.
 * transform/opacity/filter direto no elemento (sem variável CSS no pai). Parado fora da tela e com "Reduzir movimento".
 */
(function () {
	'use strict';
	var root = document.documentElement;
	var hero = document.querySelector('.ho');
	if (!hero) { return; }
	var stage = hero.querySelector('.ho__stage');
	var orbit = hero.querySelector('.ho__orbit');
	var jim = hero.querySelector('.ho__jim');
	var ring = hero.querySelector('.ho__ring');
	var ws = Array.prototype.slice.call(hero.querySelectorAll('.ho__w'));
	var N = ws.length;
	var reduced = function () { return root.getAttribute('data-motion') === 'reduced' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); };

	var ang = -Math.PI / 2 + 0.35, boost = 0, tx = 0, ty = 0, cx = 0, cy = 0, t0 = 0, last = 0, running = false, visible = true;
	var lastLit = -1;
	var INTRO_DELAY = 900, INTRO = 1500;

	function ease(k) { return 1 - Math.pow(1 - k, 4); }

	var ringKey = '', blurLevel = [];
	function place(e) {
		var W = stage.clientWidth, H = stage.clientHeight;
		var small = W < 640;
		var act = small ? ws.slice(0, 4) : ws, n = act.length;
		ws.forEach(function (el, i) { var off = small && i > 3; if (el.hidden !== off) { el.hidden = off; } });
		var rx = Math.min(W * (small ? 0.3 : 0.4), 520);
		var open = 1 + Math.min(boost * 0.12, 0.45);
		var ry0 = H * (small ? 0.21 : 0.24), ry = ry0 * open;
		var mx = W / 2 + cx * (small ? 8 : 26);
		var my = H * 0.6 + cy * 10;
		// a elipse é desenhada uma vez por tamanho; depois só se move/escala (sem layout a cada quadro)
		var key = W + 'x' + H;
		if (key !== ringKey) { ringKey = key; ring.style.width = (rx * 2).toFixed(0) + 'px'; ring.style.height = (ry0 * 2).toFixed(0) + 'px'; ring.style.left = '0'; ring.style.top = '0'; }
		ring.style.transform = 'translate3d(' + (mx - rx).toFixed(1) + 'px,' + (my - ry0).toFixed(1) + 'px,0) scale(1,' + open.toFixed(3) + ')';
		ring.style.transformOrigin = '50% 50%';
		jim.style.marginLeft = (-cx * 8).toFixed(1) + 'px';
		var best = -1, bestScore = 0.9;
		for (var i = 0; i < n; i++) {
			var th = ang + i * Math.PI * 2 / n;
			var s = Math.sin(th), c = Math.cos(th);
			var depth = (c + 1) / 2;
			var x = mx + rx * s * e;
			var y = my + ry * c * e;
			var sc = (0.6 + 0.55 * depth) * (0.35 + 0.65 * e) * (small ? 0.8 : 1);
			var el = act[i];
			el.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) translate(-50%,-50%) scale(' + sc.toFixed(3) + ')';
			el.style.opacity = ((0.3 + 0.7 * depth) * e).toFixed(3);
			var lvl = depth > 0.82 ? 0 : depth > 0.55 ? 1 : 2;       // o desfoque troca de degrau, não varia a cada quadro
			if (blurLevel[i] !== lvl) { blurLevel[i] = lvl; el.style.filter = lvl === 0 ? 'none' : 'blur(' + (lvl === 1 ? 1.6 : 3.2) + 'px)'; }
			el.style.zIndex = String(c > 0 ? 50 + Math.round(depth * 40) : 10 + Math.round(depth * 20));
			var score = c - Math.abs(s) * 0.9;
			if (c > 0 && Math.abs(s) < 0.34 && score > 0.72) { best = i; bestScore = score; }
		}
		if (best !== lastLit) {
			ws.forEach(function (el, i) { el.classList.toggle('is-lit', i === best); });
			lastLit = best;
		}
		orbit.style.transform = 'rotateX(' + (-ty * 4).toFixed(2) + 'deg) rotateY(' + (tx * 5).toFixed(2) + 'deg)';
	}

	function frame(t) {
		if (!running) { return; }
		var dt = Math.min(0.05, (t - last) / 1000 || 0.016); last = t;
		boost *= Math.exp(-dt * 2.2);
		ang += (0.16 + boost) * dt;
		cx += (tx - cx) * Math.min(1, dt * 6); cy += (ty - cy) * Math.min(1, dt * 6);
		var k = Math.max(0, Math.min(1, (t - t0 - INTRO_DELAY) / INTRO));
		place(ease(k));
		requestAnimationFrame(frame);
	}
	function start() { if (running || reduced() || !visible) { return; } running = true; last = performance.now(); requestAnimationFrame(frame); }
	function stop() { running = false; }

	function staticLayout() { running = false; ang = -Math.PI / 2 + 0.35; boost = 0; place(1); ws.forEach(function (el) { el.style.opacity = el.style.opacity; }); }

	hero.addEventListener('pointermove', function (e) {
		if (e.pointerType === 'touch') { return; }
		var r = hero.getBoundingClientRect();
		tx = ((e.clientX - r.left) / r.width - 0.5) * 2;
		ty = ((e.clientY - r.top) / r.height - 0.5) * 2;
	});
	hero.addEventListener('pointerleave', function () { tx = 0; ty = 0; });

	var lastY = window.scrollY;
	window.addEventListener('scroll', function () {
		var y = window.scrollY; boost = Math.min(2.6, boost + Math.abs(y - lastY) * 0.006); lastY = y;
	}, { passive: true });

	if ('IntersectionObserver' in window) {
		new IntersectionObserver(function (en) { visible = en[0].isIntersecting; if (visible) { start(); } else { stop(); } }, { threshold: 0.05 }).observe(hero);
	}
	window.addEventListener('resize', function () { if (!running) { place(reduced() ? 1 : 1); } });

	// título: divide em palavras (o texto continua lendo normalmente: as palavras são spans inline-block)
	var h1 = hero.querySelector('.ho__h1');
	if (h1 && !h1.getAttribute('data-split')) {
		var i = 0;
		var walk = function (node) {
			Array.prototype.slice.call(node.childNodes).forEach(function (n) {
				if (n.nodeType === 3) {
					var parts = n.textContent.split(/(\s+)/);
					var frag = document.createDocumentFragment();
					parts.forEach(function (p) {
						if (!p) { return; }
						if (/^\s+$/.test(p)) { frag.appendChild(document.createTextNode(' ')); return; }
						var s = document.createElement('span'); s.className = 'w'; s.style.setProperty('--i', String(i++)); s.textContent = p; frag.appendChild(s);
					});
					n.parentNode.replaceChild(frag, n);
				} else if (n.nodeType === 1) { walk(n); }
			});
		};
		walk(h1); h1.setAttribute('data-split', '1');
	}

	var pts = hero.querySelector('.ho__pts');
	if (pts) {
		if ('IntersectionObserver' in window && !reduced()) { new IntersectionObserver(function (en, io) { if (en[0].isIntersecting) { pts.classList.add('is-in'); io.disconnect(); } }, { threshold: 0.35 }).observe(pts); } else { pts.classList.add('is-in'); }
	}

	if (reduced()) { hero.classList.add('is-in'); staticLayout(); ws.forEach(function (el) { el.style.opacity = ''; }); place(1); return; }
	t0 = performance.now();
	requestAnimationFrame(function () { hero.classList.add('is-in'); });
	start();
}());

(function () {
  'use strict';
  if (!document.getElementById('cvStage')) { return; }
  /* A conversão é uma função do progresso p (0 → 1) do bloco na tela: o scroll é o relógio (sem botão, sem disparo único).
     Rolando para baixo avança; para cima, desfaz. Com "Reduzir movimento": só o estado final. */
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var root = document.documentElement;
  var stage = $('#cvStage'), pdf = $('#cvPdf'), win = $('#cvWin'), over = $('#cvOver'), scan = $('#cvScan');
  var pdfB = $$('[data-b]', pdf).filter(function (e) { return !e.classList.contains('cols'); });
  var webB = $$('[data-b]', $('#cvPage'));
  var stepsEl = $$('#cvSteps li'), cap = $('#cvCap'), jimLabel = $('#cvJimLabel');
  var bad = $$('#cvBad li'), good = $$('#cvGood li');
  var N = $('#cvN'), ring = $('#cvScoreRing'), mk = $('#cvMk'), toc = $('#cvToc'), alt = $('#cvAlt');
  var TAGS = ['H1', 'P', 'IMG + alt', 'H2', 'P', 'UL', 'H2', 'P'];
  var SEC = document.getElementById('conversao');
  var CAPS = JSON.parse(SEC.getAttribute('data-caps')), LABELS = JSON.parse(SEC.getAttribute('data-labels'));
  var T_PLAY = SEC.getAttribute('data-play'), T_PAUSE = SEC.getAttribute('data-pause');

  function reduced() { return root.getAttribute('data-motion') === 'reduced' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function clamp(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
  function seg(p, a, b) { return clamp((p - a) / (b - a)); }
  function out(k) { return 1 - Math.pow(1 - k, 3); }
  function io(k) { return k < .5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2; }

  /* -------- traço irregular, gerado em JS -------- */
  function rng(seed) { return function () { seed |= 0; seed = seed + 0x6D2B79F5 | 0; var t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
  function roughRect(x, y, w, h, r) {
    var j = function () { return (r() - .5) * 3; };
    var p = [[x + j(), y + j()], [x + w / 2 + j(), y + j()], [x + w + j(), y + j()], [x + w + j(), y + h / 2 + j()], [x + w + j(), y + h + j()], [x + w / 2 + j(), y + h + j()], [x + j(), y + h + j()], [x + j(), y + h / 2 + j()], [x + 3 + j(), y + 4 + j()]];
    var d = 'M' + p[0][0].toFixed(1) + ' ' + p[0][1].toFixed(1);
    for (var i = 1; i < p.length; i++) {
      var mx = (p[i - 1][0] + p[i][0]) / 2, my2 = (p[i - 1][1] + p[i][1]) / 2;
      d += ' Q' + p[i - 1][0].toFixed(1) + ' ' + p[i - 1][1].toFixed(1) + ' ' + mx.toFixed(1) + ' ' + my2.toFixed(1);
    }
    return d + ' L' + p[p.length - 1][0].toFixed(1) + ' ' + p[p.length - 1][1].toFixed(1);
  }
  var outlines = [], tags = [], geo = [], fy = [], H = 1, step = 0, K = 1;
  var pinned = false, wrap = document.getElementById('cvFit'), SECT = document.getElementById('conversao');
  var mq = window.matchMedia ? window.matchMedia('(max-width:860px)') : { matches: false };
  function setPin() {
    var on = !reduced() && !mq.matches;
    if (on !== pinned) { pinned = on; SECT.classList.toggle('cv--pin', on); }
    wrap.style.transform = '';
    K = 1;
    if (pinned) { var avail = window.innerHeight - 92 - 16, h = wrap.offsetHeight; K = Math.min(1, avail / h); if (K < 1) { wrap.style.transform = 'scale(' + K.toFixed(4) + ')'; } }
  }

  function measure() {
    setPin();
    webB.forEach(function (e) { e.style.transform = 'none'; });
    var pr = pdf.getBoundingClientRect(); H = pdf.offsetHeight;
    over.setAttribute('viewBox', '0 0 ' + pdf.offsetWidth + ' ' + pdf.offsetHeight);
    over.innerHTML = '';
    tags.forEach(function (t) { t.remove(); });
    outlines = []; tags = []; fy = [];
    var r = rng(11);
    var first = pdfB.map(function (b) { return b.getBoundingClientRect(); });
    var last = webB.map(function (b) { return b.getBoundingClientRect(); });
    geo = webB.map(function (e, i) { return { dx: (first[i].left - last[i].left) / K, dy: (first[i].top - last[i].top) / K, sc: Math.min(1, first[i].width / last[i].width) }; });
    pdfB.forEach(function (b, i) {
      var br = first[i], x = (br.left - pr.left) / K, y = (br.top - pr.top) / K, bw = br.width / K, bh = br.height / K;
      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', roughRect(x - 3, y - 3, bw + 6, bh + 6, r));
      path.setAttribute('pathLength', '1'); path.style.strokeDasharray = '1'; path.style.strokeDashoffset = '1';
      over.appendChild(path); outlines.push(path);
      var tg = document.createElement('span'); tg.className = 'cv-tag'; tg.textContent = TAGS[i];
      tg.style.left = Math.min(pdf.offsetWidth - 70, x + bw - 34) + 'px'; tg.style.top = Math.max(0, y - 19) + 'px';
      pdf.appendChild(tg); tags.push(tg);
      fy.push((y + bh * .35) / H);
    });
  }

  function setStep(n, p) {
    if (n !== step) {
      step = n; stage.setAttribute('data-step', String(n));
      stepsEl.forEach(function (li, i) { if (i + 1 === n) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); } });
      cap.textContent = CAPS[n - 1]; jimLabel.textContent = LABELS[n - 1];
    }
    stepsEl.forEach(function (li, i) { li.classList.toggle('is-done', i + 1 < n || (n === 4 && p >= 0.995)); });
  }

  function render(p) {
    setStep(p < 0.1 ? 1 : p < 0.46 ? 2 : p < 0.8 ? 3 : 4, p);
    if (p > 0.02 && p < 0.98) { stage.setAttribute('data-working', ''); } else { stage.removeAttribute('data-working'); }

    // 2. a linha de leitura desce e cada bloco ganha contorno e etiqueta
    var sc = seg(p, 0.12, 0.46);
    scan.style.opacity = (sc > 0 && sc < 1) ? '1' : '0';
    scan.style.transform = 'translateY(' + (sc * (H - 2)).toFixed(1) + 'px)';
    pdfB.forEach(function (b, i) {
      var k = out(seg(sc, fy[i], fy[i] + 0.12)), f = io(seg(p, 0.5 + i * 0.035, 0.74 + i * 0.03));
      outlines[i].style.strokeDashoffset = (1 - k).toFixed(3);
      outlines[i].style.opacity = f > 0.02 ? '0' : '1';
      tags[i].style.opacity = (f > 0.02 ? 0 : k).toFixed(3);
      tags[i].style.transform = 'scale(' + (0.6 + 0.4 * k).toFixed(3) + ') rotate(' + (-6 + 3 * k).toFixed(2) + 'deg)';
      b.classList.toggle('is-ghost', f > 0.02);
      // 3. FLIP: o bloco voa da folha para a página
      var g = geo[i] || { dx: 0, dy: 0, sc: 1 }, w = webB[i];
      w.style.opacity = (f <= 0 ? 0 : 0.85 + 0.15 * f).toFixed(3);
      w.style.transform = f >= 1 ? 'none' : 'translate(' + (g.dx * (1 - f)).toFixed(1) + 'px,' + (g.dy * (1 - f)).toFixed(1) + 'px) scale(' + (g.sc + (1 - g.sc) * f).toFixed(3) + ')';
      w.style.zIndex = f > 0 && f < 1 ? '6' : '';
    });

    // 4. página acessível
    N.textContent = String(Math.round(92 * out(seg(p, 0.82, 0.94))));
    ring.style.strokeDashoffset = (1 - out(seg(p, 0.82, 0.92))).toFixed(3);
    mk.style.backgroundSize = (100 * out(seg(p, 0.88, 0.96))).toFixed(1) + '% 62%';
    alt.classList.toggle('is-in', p >= 0.9); toc.classList.toggle('is-in', p >= 0.9);
    bad.forEach(function (l, i) { l.classList.toggle('is-struck', p >= 0.9 + i * 0.03); });
    good.forEach(function (l, i) { l.classList.toggle('is-checked', p >= 0.9 + i * 0.03); });
  }

  var last = -1, raf = 0;
  function progress() {
    var vh = window.innerHeight;
    if (pinned) { var r = SECT.getBoundingClientRect(), total = r.height - vh; return total > 0 ? clamp(-r.top / total / 0.92) : 1; }
    var rs = stage.getBoundingClientRect(); return clamp((vh * 0.9 - rs.top) / (vh * 0.75));
  }
  function update(force) { raf = 0; var p = reduced() ? 1 : progress(); if (force || Math.abs(p - last) > 0.001) { last = p; render(p); } }
  function schedule() { if (!raf) { raf = requestAnimationFrame(function () { update(false); }); } }
  function measureAll() { measure(); update(true); }
  window.addEventListener('scroll', schedule, { passive: true });
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(measureAll, 150); });
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(measureAll); }

  /* -------- interação (A−, A+, contraste, ouvir, sumário) -------- */
  var fs = 0;
  function setFs(d) { fs = Math.max(-2, Math.min(4, fs + d)); win.style.setProperty('--fs', String(1 + fs * .12)); }
  $('#cvDown').addEventListener('click', function () { setFs(-1); });
  $('#cvUp').addEventListener('click', function () { setFs(1); });
  $('#cvHc').addEventListener('click', function () {
    var on = !win.classList.contains('is-hc');
    win.classList.toggle('is-hc', on);
    this.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  var playing = false, idx = -1, timer = null, token = 0;
  function readable() { return webB.filter(function (e) { return e.textContent.trim() && !e.querySelector('svg'); }); }
  function clearMarks() { $$('.is-reading', win).forEach(function (e) { e.classList.remove('is-reading'); }); }
  function stopSpeech() {
    token++; playing = false; idx = -1;
    if (timer) { clearTimeout(timer); timer = null; }
    if ('speechSynthesis' in window) { window.speechSynthesis.cancel(); }
    clearMarks();
    var b = $('#cvPlay'); if (b) { b.setAttribute('aria-pressed', 'false'); b.textContent = T_PLAY; }
  }
  function next(my, voice, list) {
    if (my !== token || !playing) { return; }
    idx++; clearMarks();
    if (idx >= list.length) { stopSpeech(); return; }
    list[idx].classList.add('is-reading');
    var text = list[idx].textContent.replace(/\s+/g, ' ').trim();
    if (voice) {
      var u = new window.SpeechSynthesisUtterance(text); u.lang = 'pt-BR';
      u.onend = function () { next(my, true, list); }; u.onerror = function () { next(my, false, list); };
      window.speechSynthesis.speak(u);
    } else { timer = setTimeout(function () { next(my, false, list); }, Math.max(1800, text.split(' ').length * 330)); }
  }
  $('#cvPlay').addEventListener('click', function () {
    if (playing) { stopSpeech(); return; }
    stopSpeech();
    var list = readable(), voice = 'speechSynthesis' in window && window.speechSynthesis.getVoices().length > 0;
    playing = true; this.setAttribute('aria-pressed', 'true'); this.textContent = T_PAUSE;
    next(token, voice, list);
  });
  win.addEventListener('keydown', function (e) { if (e.key === 'Escape') { stopSpeech(); } });
  $$('#cvToc a').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); var t = $(a.getAttribute('href')); if (t) { t.focus({ preventScroll: true }); t.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' }); } });
  });
  if ('speechSynthesis' in window) { window.speechSynthesis.getVoices(); }

  measureAll();
}());

/*
 * Conversões A → B guiadas pelo scroll: cada animação é uma função do progresso p (0 → 1) do bloco na tela, sem botão de repetir.
 * Rolando para baixo a conversão avança; rolando para cima, volta. Sem tempo, sem disparo único: o scroll é o relógio.
 * Com "Reduzir movimento" ou prefers-reduced-motion: só o estado final. Só transform, opacity e traço.
 */
(function () {
	'use strict';
	var root = document.documentElement;
	var $ = function (s, c) { return (c || document).querySelector(s); };
	var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
	function reduced() { return root.getAttribute('data-motion') === 'reduced' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
	function clamp(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
	function seg(p, a, b) { return clamp((p - a) / (b - a)); }
	function out(k) { return 1 - Math.pow(1 - k, 3); }                 // ease-out
	function io(k) { return k < .5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2; }
	function rand(seed) { return function () { seed |= 0; seed = seed + 0x6D2B79F5 | 0; var t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }

	var items = [];
	var STICK = 96;                                                   // logo abaixo do menu fixo
	var wide = window.matchMedia ? window.matchMedia('(min-width:861px)') : { matches: true };
	function scene(id, def) {
		var el = $('[data-cx="' + id + '"]'); if (!el) { return; }
		var it = { el: el, stage: $('[data-stage]', el), last: -1, pinned: false };
		// o palco fica fixo (sticky) dentro de uma "pista" mais alta que ele: o scroll conduz a conversão com o palco inteiro à vista
		var track = document.createElement('div'); track.className = 'cx-track';
		var stick = document.createElement('div'); stick.className = 'cx-stick';
		it.stage.parentNode.insertBefore(track, it.stage); track.appendChild(stick); stick.appendChild(it.stage);
		it.track = track;
		it.fit = function () {
			var on = !reduced() && wide.matches && window.innerHeight >= STICK + it.stage.offsetHeight + 24;
			it.pinned = on;
			track.classList.toggle('is-on', on);
			track.style.height = on ? Math.round(it.stage.offsetHeight + window.innerHeight * 0.75) + 'px' : '';
		};
		def(it); items.push(it);
	}
	function progress(it) {
		var vh = window.innerHeight;
		if (it.pinned) {
			var tr = it.track.getBoundingClientRect(), total = it.track.offsetHeight - it.stage.offsetHeight;
			return total > 0 ? clamp((STICK - tr.top) / total) : 1;
		}
		var r = it.stage.getBoundingClientRect();
		return clamp((vh * 0.9 - r.top) / (vh * 0.6));      // 0: o palco acaba de aparecer embaixo · 1: já está na altura da leitura
	}
	var raf = 0;
	function update(force) {
		raf = 0;
		items.forEach(function (it) {
			var p = reduced() ? 1 : progress(it);
			if (force || Math.abs(p - it.last) > 0.001) { it.last = p; it.render(p); }
		});
	}
	function schedule() { if (!raf) { raf = requestAnimationFrame(function () { update(false); }); } }

	/* =========================================================== B: texto corrido -> tags -> layout */
	scene('html', function (it) {
		var cText = $('#cxTxt'), cCode = $('#cxCode'), cPage = $('#cxPage');
		var txt = $('.cx-html__text', cText), blocks = $$('[data-b]', txt);
		var lines = $$('[data-l]', cCode), pages = $$('[data-b]', cPage);
		var labels = ['H2', 'H3', 'P'];
		var tags = blocks.map(function (b, i) { var t = document.createElement('span'); t.className = 'cx-tag'; t.textContent = labels[i]; t.setAttribute('aria-hidden', 'true'); b.appendChild(t); return t; });
		var geo = [];
		it.measure = function () {                         // posição de cada bloco no texto (origem) e na página (destino), sem transforms
			pages.forEach(function (p) { p.style.transform = 'none'; });
			var first = blocks.map(function (b) { return b.getBoundingClientRect(); });
			var last = pages.map(function (p) { return p.getBoundingClientRect(); });
			geo = pages.map(function (p, i) { return { dx: first[i].left - last[i].left, dy: first[i].top - last[i].top, sc: Math.min(1, first[i].width / last[i].width) }; });
		};
		it.render = function (p) {
			cCode.style.opacity = (0.35 + 0.65 * seg(p, 0.28, 0.36)).toFixed(3);
			cPage.style.opacity = (0.35 + 0.65 * seg(p, 0.58, 0.66)).toFixed(3);
			var anyFlip = false;
			blocks.forEach(function (b, i) {
				var t = out(seg(p, 0.06 + i * 0.07, 0.17 + i * 0.07)), f = io(seg(p, 0.64 + i * 0.07, 0.9 + i * 0.03));
				tags[i].style.opacity = (f > 0.02 ? 0 : t).toFixed(3);
				tags[i].style.transform = 'scale(' + (0.6 + 0.4 * t).toFixed(3) + ') rotate(' + (-6 + 3 * t).toFixed(2) + 'deg)';
				b.classList.toggle('is-ol', t > 0.05 && f < 0.02);
				if (f > 0.02) { anyFlip = true; }
				var g = geo[i] || { dx: 0, dy: 0, sc: 1 }, e = f;
				pages[i].style.opacity = (f <= 0 ? 0 : 0.85 + 0.15 * e).toFixed(3);
				pages[i].style.transform = f >= 1 ? 'none' : 'translate(' + (g.dx * (1 - e)).toFixed(1) + 'px,' + (g.dy * (1 - e)).toFixed(1) + 'px) scale(' + (g.sc + (1 - g.sc) * e).toFixed(3) + ')';
				pages[i].style.filter = f >= 1 ? '' : 'grayscale(' + (1 - e).toFixed(2) + ')';
				pages[i].style.zIndex = f > 0 && f < 1 ? '6' : '';
			});
			txt.classList.toggle('is-ghost', p > 0.9);
			lines.forEach(function (l, i) {
				var c = seg(p, 0.3 + i * 0.09, 0.4 + i * 0.09);
				l.style.clipPath = c >= 1 ? 'none' : 'inset(0 ' + ((1 - c) * 100).toFixed(1) + '% 0 0)';
			});
		};
	});

	/* =========================================================== E: pilha de 20 arquivos -> grade de 20 páginas */
	scene('lote', function (it) {
		var grid = $('#cxGrid'), pile = $('.cx-pile__ghost', it.el), nEl = $('[data-n]', it.el);
		var EXT = [['PDF', '#d64545'], ['DOCX', '#2b6cd4'], ['MD', '#6a4bd6'], ['TXT', '#5b6b7a']];
		var cells = [], from = [];
		for (var i = 0; i < 20; i++) {
			var f = document.createElement('div'); f.className = 'cx-f';
			f.innerHTML = '<s></s><s></s><s class="y"></s><s></s><s></s><span class="ext">' + EXT[i % 4][0] + '</span><span class="ok"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5l3.2 3.2L13 4.5"/></svg></span>';
			f.style.setProperty('--c', EXT[i % 4][1]);
			grid.appendChild(f); cells.push(f);
		}
		it.measure = function () {
			var r = rand(9), pr = pile.getBoundingClientRect(), pcx = pr.left + pr.width / 2, pcy = pr.top + pr.height / 2;
			cells.forEach(function (c) { c.style.transform = 'none'; });
			from = cells.map(function (c) {
				var cr = c.getBoundingClientRect();
				return { dx: pcx - (cr.left + cr.width / 2) + (r() - .5) * 30, dy: pcy - (cr.top + cr.height / 2) + (r() - .5) * 30, rot: (r() - .5) * 24 };
			});
		};
		it.render = function (p) {
			var done = 0;
			cells.forEach(function (c, i) {
				var k = seg(p, 0.06 + i * 0.036, 0.06 + i * 0.036 + 0.24), e = out(k), g = from[i] || { dx: 0, dy: 0, rot: 0 };
				c.style.transform = k >= 1 ? 'none' : 'translate(' + (g.dx * (1 - e)).toFixed(1) + 'px,' + (g.dy * (1 - e)).toFixed(1) + 'px) rotate(' + (g.rot * (1 - e)).toFixed(1) + 'deg) scale(' + (1.9 - 0.9 * e).toFixed(3) + ')';
				c.style.zIndex = k > 0 && k < 1 ? '30' : String(i % 5);
				var page = k >= 0.62; c.classList.toggle('is-file', !page); if (page) { done++; }
			});
			nEl.textContent = String(done);
		};
	});

	/* =========================================================== C: DOCX -> card de post publicado */
	scene('nota', function (it) {
		var docx = $('#cxDocx'), jim = $('#cxJim'), post = $('#cxPost'), ring = $('#cxRing'), score = $('#cxScore'), pub = $('#cxPub'), ck = $('#cxPubCk'), cap = $('#cxNotaCap');
		var dx = 0, dy = 0;
		it.measure = function () { docx.style.transform = 'none'; var d = docx.getBoundingClientRect(), j = jim.getBoundingClientRect(); dx = (j.left + j.width / 2) - (d.left + d.width / 2); dy = (j.top + j.height / 2) - (d.top + d.height / 2); };
		it.render = function (p) {
			var a = io(seg(p, 0.04, 0.38));
			docx.style.transform = 'translate(' + (dx * a).toFixed(1) + 'px,' + (dy * a).toFixed(1) + 'px) scale(' + (1 - 0.78 * a).toFixed(3) + ')';
			docx.style.opacity = Math.max(1 - a, 0.3 * seg(p, 0.9, 1)).toFixed(3);
			var b = seg(p, 0.34, 0.46), s = Math.sin(Math.PI * b);
			jim.style.transform = 'scale(' + (1 + 0.14 * s).toFixed(3) + ') rotate(' + (-4 * s).toFixed(2) + 'deg)';
			var c = out(seg(p, 0.42, 0.62));
			post.style.opacity = c.toFixed(3);
			post.style.transform = 'translateX(' + (28 * (1 - c)).toFixed(1) + 'px) scale(' + (0.96 + 0.04 * c).toFixed(3) + ')';
			var n = out(seg(p, 0.5, 0.82));
			score.textContent = String(Math.round(92 * n));
			ring.style.strokeDashoffset = (1 - out(seg(p, 0.5, 0.78))).toFixed(3);
			var d = out(seg(p, 0.84, 0.95));
			pub.style.opacity = d.toFixed(3); pub.style.transform = 'scale(' + (0.85 + 0.15 * d).toFixed(3) + ')';
			ck.style.strokeDashoffset = (1 - out(seg(p, 0.88, 1))).toFixed(3);
			cap.textContent = p < 0.42 ? cap.getAttribute('data-alt') : cap.getAttribute('data-fin');
		};
	});

	/* =========================================================== mockup: os dois GIFs reais, em sequência, com os cards de apoio */
	(function mock() {
		var wrap = $('.cx-mock'); if (!wrap) { return; }
		var notes = $$('.cx-note', wrap), gifs = $$('.cx-gif', wrap);
		function show() { notes.forEach(function (n) { n.classList.add('is-in'); }); }
		if (reduced() || !('IntersectionObserver' in window)) { show(); return; }       // parado: fica o quadro estático
		new IntersectionObserver(function (en, o) { if (en[0].isIntersecting) { o.disconnect(); show(); } }, { threshold: 0.25 }).observe(wrap);
		// cada GIF só carrega e anima quando entra na tela
		gifs.forEach(function (g) {
			new IntersectionObserver(function (en, o) { if (en[0].isIntersecting) { o.disconnect(); g.src = g.getAttribute('data-gif'); } }, { threshold: 0.4 }).observe(g);
		});
	}());

	function measureAll() { items.forEach(function (it) { if (it.fit) { it.fit(); } if (it.measure) { it.measure(); } }); update(true); }
	window.addEventListener('scroll', schedule, { passive: true });
	window.addEventListener('resize', function () { measureAll(); });
	if (document.fonts && document.fonts.ready) { document.fonts.ready.then(measureAll); }
	measureAll();
}());
