(function () {
	'use strict';
	var root = document.documentElement;
	root.classList.add('js');

	var motion = 'full';
	try { motion = localStorage.getItem('jim-motion') || 'full'; } catch (e) {}
	if (matchMedia('(prefers-reduced-motion: reduce)').matches) { motion = 'reduced'; }
	root.setAttribute('data-motion', motion);

	var motionBtn = document.getElementById('motionBtn');
	if (motionBtn) {
		motionBtn.setAttribute('aria-pressed', motion === 'reduced' ? 'true' : 'false');
		motionBtn.addEventListener('click', function () {
			motion = motion === 'reduced' ? 'full' : 'reduced';
			root.setAttribute('data-motion', motion);
			motionBtn.setAttribute('aria-pressed', motion === 'reduced' ? 'true' : 'false');
			try { localStorage.setItem('jim-motion', motion); } catch (e) {}
		});
	}

	var menuBtn = document.getElementById('menuBtn');
	var drawer = document.getElementById('drawer');
	if (menuBtn && drawer) {
		var close = function () { drawer.classList.remove('is-open'); menuBtn.setAttribute('aria-expanded', 'false'); };
		menuBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = drawer.classList.toggle('is-open');
			menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		drawer.addEventListener('click', function (e) { if (e.target.closest('a')) { close(); } });
		document.addEventListener('click', function (e) { if (!drawer.contains(e.target)) { close(); } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); menuBtn.focus(); } });
	}

	var lang = document.getElementById('langMenu');
	if (lang) {
		document.addEventListener('click', function (e) { if (lang.open && !lang.contains(e.target)) { lang.open = false; } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && lang.open) { lang.open = false; lang.querySelector('summary').focus(); } });
	}

	var top = document.getElementById('top');
	if (top) {
		var onScroll = function () { top.classList.toggle('is-scrolled', window.scrollY > 8); };
		onScroll();
		window.addEventListener('scroll', onScroll, { passive: true });
	}

	var items = document.querySelectorAll('[data-reveal]');
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
		}, { rootMargin: '0px 0px -8% 0px' });
		items.forEach(function (el) { io.observe(el); });
	} else {
		items.forEach(function (el) { el.classList.add('is-in'); });
	}
}());
