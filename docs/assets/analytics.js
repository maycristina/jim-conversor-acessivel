/* Cloudflare Web Analytics (sem cookies) e Microsoft Clarity (só depois do aceite).
 * Gerado por _privado/landing/build_home.py: o texto do aviso vem de home_<idioma>.json. */
(function () {
	'use strict';
	var TEXTS = {
 "pt": {
  "text": "Contamos visitas sem cookies. Podemos também usar o Microsoft Clarity para ver como a página é usada e melhorar o site?",
  "accept": "Sim, pode",
  "decline": "Não",
  "policy": "Política de privacidade",
  "prefs": "Preferências de privacidade"
 },
 "es": {
  "text": "Contamos visitas sin cookies. ¿También podemos utilizar Microsoft Clarity para ver cómo se utiliza la página y mejorar el sitio?",
  "accept": "Sí",
  "decline": "No, gracias",
  "policy": "Política de privacidad",
  "prefs": "Preferencias de privacidad"
 },
 "fr": {
  "text": "Nous comptons les visites sans cookies. Pouvons-nous également utiliser Microsoft Clarity pour voir comment la page est utilisée et améliorer le site?",
  "accept": "Oui",
  "decline": "Non, merci",
  "policy": "Politique de confidentialité",
  "prefs": "Préférences de confidentialité"
 },
 "de": {
  "text": "Wir zählen Besuche ohne Cookies. Können wir auch Microsoft Clarity verwenden, um zu sehen, wie die Seite verwendet wird und die Website zu verbessern?",
  "accept": "Ja",
  "decline": "Nein, danke",
  "policy": "Datenschutzpolitik",
  "prefs": "Datenschutzpräferenzen"
 },
 "ru": {
  "text": "Мы считаем посещения без cookies. Можем ли мы также использовать Microsoft Clarity, чтобы увидеть, как используется страница и улучшить сайт?",
  "accept": "Да",
  "decline": "Нет, спасибо",
  "policy": "Политика конфиденциальности",
  "prefs": "Предпочтения конфиденциальности"
 },
 "zh": {
  "text": "我们数一下不使用cookies的访问。 我们能否也使用微软Clarity来查看该页面如何使用和改进该网站?",
  "accept": "对",
  "decline": "不,谢谢",
  "policy": "隐私政策",
  "prefs": "隐私首选项"
 },
 "hi": {
  "text": "हम कुकीज़ के बिना यात्रा करते हैं। क्या हम Microsoft Clarity का उपयोग यह देखने के लिए कर सकते हैं कि कैसे पृष्ठ का उपयोग किया जाता है और साइट में सुधार किया जाता है?",
  "accept": "हाँ",
  "decline": "नहीं, धन्यवाद",
  "policy": "गोपनीयता नीति",
  "prefs": "गोपनीयता प्राथमिकता"
 }
};
	var CF_TOKEN = 'cbee8b091cf74fba8e7e2f45360f3ed1';
	var CLARITY_ID = 'z0eu0k25i7';
	var KEY = 'jim-consent';
	var lang = (document.documentElement.lang || 'pt').split('-')[0];
	var t = TEXTS[lang] || TEXTS.pt;

	function stored() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
	function store(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }

	// Cloudflare Web Analytics: conta visitas sem cookies.
	var cf = document.createElement('script');
	cf.type = 'module';
	cf.src = 'https://static.cloudflareinsights.com/beacon.min.js';
	cf.setAttribute('data-cf-beacon', JSON.stringify({ token: CF_TOKEN }));
	document.head.appendChild(cf);

	var clarityOn = false;
	function loadClarity() {
		if (clarityOn) { return; }
		clarityOn = true;
		(function (c, l, a, r, i, s, y) {
			c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments); };
			s = l.createElement(r); s.async = 1; s.src = 'https://www.clarity.ms/tag/' + i;
			y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(s, y);
		})(window, document, 'clarity', 'script', CLARITY_ID);
	}

	// Sinal "Global Privacy Control" do navegador conta como recusa.
	var gpc = navigator.globalPrivacyControl === true;
	if (gpc && stored() === null) { store('denied'); }

	var box = null, returnFocus = null;

	function close() {
		if (!box) { return; }
		box.hidden = true;
		document.removeEventListener('keydown', onKey);
		// Devolve o foco só se o aviso foi aberto pelo botão do rodapé, sem rolar a página.
		if (returnFocus) { returnFocus.focus({ preventScroll: true }); returnFocus = null; }
	}
	function onKey(e) { if (e.key === 'Escape') { close(); } }

	function decide(v) {
		store(v);
		if (v === 'granted') { loadClarity(); }
		close();
	}

	function open(e) {
		if (!box) {
			box = document.createElement('div');
			box.className = 'jh-consent';
			box.setAttribute('role', 'region');
			box.setAttribute('aria-label', t.policy);
			box.innerHTML = '<p></p><div class="jh-consent__row"><button type="button" class="btn btn--dark" data-yes></button> <button type="button" class="btn btn--line-dark" data-no></button> <a class="jh-consent__link" href="/privacidade/"></a></div>';
			box.querySelector('p').textContent = t.text;
			box.querySelector('[data-yes]').textContent = t.accept;
			box.querySelector('[data-no]').textContent = t.decline;
			box.querySelector('a').textContent = t.policy;
			box.querySelector('[data-yes]').addEventListener('click', function () { decide('granted'); });
			box.querySelector('[data-no]').addEventListener('click', function () { decide('denied'); });
			document.body.appendChild(box);
		}
		box.hidden = false;
		document.addEventListener('keydown', onKey);
		// Aberto pelo rodapé: leva o foco ao aviso, sem rolar. Aberto no carregamento: não rouba o foco.
		if (e && e.currentTarget) {
			returnFocus = e.currentTarget;
			box.querySelector('[data-yes]').focus({ preventScroll: true });
		}
	}

	function addPrefsLink() {
		var host = document.querySelector('.foot__bottom');
		if (!host || document.getElementById('jhPrefs')) { return; }
		var b = document.createElement('button');
		b.type = 'button'; b.id = 'jhPrefs'; b.className = 'jh-prefs'; b.textContent = t.prefs;
		b.addEventListener('click', open);
		host.appendChild(b);
	}

	function init() {
		addPrefsLink();
		var v = stored();
		if (v === 'granted') { loadClarity(); }
		else if (v === null) { open(); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
