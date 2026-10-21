/*
 * Documentação: busca no próprio texto (sem servidor), índice lateral com a seção atual e atalho "/".
 * O índice de busca é montado a partir dos títulos, parágrafos, itens e perguntas da página.
 */
(function () {
	'use strict';
	var input = document.getElementById('dq');
	var box = document.getElementById('dres');
	if (!input || !box) { return; }
	var NONE = input.getAttribute('data-none') || '';
	var COUNT = input.getAttribute('data-count') || '';
	var side = document.getElementById('dside');
	var small = window.matchMedia && window.matchMedia('(max-width:900px)');

	function norm(s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
	function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

	/* ------------------------------------------------ índice */
	var index = [];
	Array.prototype.forEach.call(document.querySelectorAll('.dsec'), function (sec) {
		var title = sec.querySelector('h2').textContent.trim();
		var anchor = sec.id, sub = '';
		Array.prototype.forEach.call(sec.querySelectorAll('h2,h3,p,li,summary,figcaption,td,th'), function (el) {
			if (el.closest('summary') && el.tagName !== 'SUMMARY') { return; }
			if (el.tagName === 'LI' && el.querySelector('p,h3')) { return; }
			var t = el.textContent.replace(/\s+/g, ' ').trim();
			if (!t) { return; }
			if (el.tagName === 'H3') { sub = t; anchor = el.id || sec.id; }
			else if (el.tagName === 'H2') { anchor = sec.id; sub = ''; }
			index.push({ sec: sec.id, title: title, sub: sub, anchor: el.tagName === 'SUMMARY' ? sec.id : anchor, text: t, n: norm(t), isHead: el.tagName === 'H2' || el.tagName === 'H3', el: el });
		});
	});

	/* ------------------------------------------------ busca */
	function terms(q) { return norm(q).split(/\s+/).filter(function (w) { return w.length > 1 || /\d/.test(w); }); }
	function snippet(text, ts) {
		var n = norm(text), pos = -1, i;
		for (i = 0; i < ts.length; i++) { pos = n.indexOf(ts[i]); if (pos > -1) { break; } }
		var start = Math.max(0, pos - 40), end = Math.min(text.length, start + 170);
		var piece = (start > 0 ? '… ' : '') + text.slice(start, end) + (end < text.length ? ' …' : '');
		var out = esc(piece);
		ts.forEach(function (t) {
			var re = new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
			// marca só no texto original (acentos entram juntos via normalização simples)
			out = out.replace(re, '<mark>$1</mark>');
		});
		return out;
	}
	var results = [], sel = -1;
	function render(q, ts) {
		var seen = {}, items = [];
		results.slice(0, 40).forEach(function (r) {
			var key = r.anchor + '|' + (r.isHead ? 'h' : r.text.slice(0, 40));
			if (seen[key]) { return; }
			seen[key] = 1; items.push(r);
		});
		items = items.slice(0, 10);
		if (!items.length) { box.innerHTML = '<p class="dres__none">' + esc(NONE) + ' “' + esc(q) + '”</p>'; box.hidden = false; return; }
		box.innerHTML = '<div class="dres__meta">' + results.length + ' ' + esc(COUNT) + '</div><ul>' + items.map(function (r, i) {
			return '<li><a href="#' + esc(r.anchor) + '" data-i="' + i + '"><b>' + esc(r.isHead ? r.text : r.title) + '</b>' + (r.sub && !r.isHead ? '<small>' + esc(r.sub) + '</small>' : '') + (r.isHead ? '' : '<span>' + snippet(r.text, ts) + '</span>') + '</a></li>';
		}).join('') + '</ul>';
		box.hidden = false; sel = -1;
	}
	var timer = null;
	function search() {
		var q = input.value.trim();
		if (q.length < 2) { box.hidden = true; box.innerHTML = ''; results = []; return; }
		var ts = terms(q);
		if (!ts.length) { box.hidden = true; return; }
		results = index.map(function (r) {
			var ok = ts.every(function (t) { return r.n.indexOf(t) > -1 || norm(r.title).indexOf(t) > -1; });
			if (!ok) { return null; }
			var score = (r.isHead ? 20 : 0) + (norm(r.title).indexOf(ts[0]) > -1 ? 6 : 0) + ts.reduce(function (a, t) { return a + (r.n.indexOf(t) === 0 ? 4 : 1); }, 0);
			return { r: r, s: score };
		}).filter(Boolean).sort(function (a, b) { return b.s - a.s; }).map(function (x) { return x.r; });
		render(q, ts);
	}
	input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 90); });
	function move(d) {
		var links = box.querySelectorAll('li a'); if (!links.length) { return; }
		sel = (sel + d + links.length) % links.length;
		Array.prototype.forEach.call(links, function (a, i) { a.setAttribute('aria-selected', i === sel ? 'true' : 'false'); });
		links[sel].scrollIntoView({ block: 'nearest' });
	}
	input.addEventListener('keydown', function (e) {
		if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
		else if (e.key === 'Enter') { var l = box.querySelectorAll('li a'); var a = l[sel > -1 ? sel : 0]; if (a) { e.preventDefault(); a.click(); } }
		else if (e.key === 'Escape') { input.value = ''; box.hidden = true; }
	});
	box.addEventListener('click', function (e) {
		var a = e.target.closest('a'); if (!a) { return; }
		box.hidden = true;
		var t = document.getElementById(a.getAttribute('href').slice(1));
		if (t && t.tagName !== 'ARTICLE') { t.classList.add('dflash'); }
	});
	document.addEventListener('click', function (e) { if (!e.target.closest('.dsearch,.dres')) { box.hidden = true; } });
	document.addEventListener('keydown', function (e) {
		var t = e.target;
		if (e.key === '/' && !e.metaKey && !e.ctrlKey && !(t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) && !(t && t.isContentEditable)) { e.preventDefault(); input.focus(); input.select(); }
	});

	/* ------------------------------------------------ índice lateral: seção atual; no celular começa fechado */
	if (small && small.matches && side) { side.removeAttribute('open'); }
	var links = Array.prototype.slice.call(document.querySelectorAll('.dside a'));
	var cur = null;
	if (side) { cur = document.createElement('span'); cur.className = 'dside__cur'; side.querySelector('summary').insertBefore(cur, null); }
	function setCur(t) { if (cur) { cur.textContent = t; } }
	if ('IntersectionObserver' in window) {
		var secs = Array.prototype.slice.call(document.querySelectorAll('.dsec'));
		var obs = new IntersectionObserver(function (en) {
			en.forEach(function (x) {
				if (!x.isIntersecting) { return; }
				links.forEach(function (a) { if (a.getAttribute('href') === '#' + x.target.id) { a.setAttribute('aria-current', 'true'); setCur(a.textContent); } else { a.removeAttribute('aria-current'); } });
			});
		}, { rootMargin: '-110px 0px -65% 0px', threshold: 0 });
		secs.forEach(function (s) { obs.observe(s); });
	}
	links.forEach(function (a) { a.addEventListener('click', function () { if (small && small.matches && side) { side.removeAttribute('open'); } }); });
}());

/* índice retrátil: lembra se ficou recolhido (só no desktop) */
(function () {
	'use strict';
	var d = document.getElementById('dside'), grid = document.querySelector('.dgrid');
	if (!d || !grid) { return; }
	var KEY = 'jim-docs-toc', wide = window.matchMedia('(min-width:901px)');
	function sync() { grid.classList.toggle('is-collapsed', !d.open && wide.matches); }
	try { if (wide.matches && localStorage.getItem(KEY) === 'closed') { d.removeAttribute('open'); } } catch (e) { /* ok */ }
	d.addEventListener('toggle', function () { sync(); if (wide.matches) { try { localStorage.setItem(KEY, d.open ? 'open' : 'closed'); } catch (e) { /* ok */ } } });
	sync();
}());

/* menu do celular: fecha ao tocar fora ou com Esc */
(function () {
	'use strict';
	var d = document.getElementById('dside');
	if (!d || !window.matchMedia) { return; }
	var small = window.matchMedia('(max-width:900px)');
	document.addEventListener('click', function (e) { if (small.matches && d.open && !d.contains(e.target)) { d.removeAttribute('open'); } });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && small.matches && d.open) { d.removeAttribute('open'); d.querySelector('summary').focus(); } });
}());
