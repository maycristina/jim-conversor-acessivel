(function () {
	'use strict';
	var side = document.getElementById('docsSide');
	if (!side) { return; }

	var mq = matchMedia('(min-width: 981px)');
	var sync = function () { side.open = mq.matches; };
	sync();
	if (mq.addEventListener) { mq.addEventListener('change', sync); }

	var links = Array.prototype.slice.call(side.querySelectorAll('a[href^="#"]'));
	var byId = {};
	links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });

	// Closes the topic list on a phone after choosing a topic.
	side.addEventListener('click', function (e) { if (!mq.matches && e.target.closest('a')) { side.open = false; } });

	if (!('IntersectionObserver' in window)) { return; }
	var current = null;
	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (en) {
			if (en.isIntersecting && byId[en.target.id]) {
				if (current) { current.removeAttribute('aria-current'); }
				current = byId[en.target.id];
				current.setAttribute('aria-current', 'location');
			}
		});
	}, { rootMargin: '-20% 0px -70% 0px' });
	Object.keys(byId).forEach(function (id) { var el = document.getElementById(id); if (el) { io.observe(el); } });
}());
