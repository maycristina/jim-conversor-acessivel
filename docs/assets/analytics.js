/* Cloudflare Web Analytics (sem cookies) e Microsoft Clarity (só depois do aceite).
 * Gerado por _privado/landing/build_home.py: o texto do aviso vem de home_<idioma>.json. */
(function () {
	'use strict';
	var TEXTS = {
 "pt": {
  "text": "Este site usa o Cloudflare Web Analytics, sem cookies, para contar visitas. Com a sua permissão, usa também o Microsoft Clarity, que grava como a página é usada (cliques e rolagem) e pode usar cookies, para melhorar o site.",
  "accept": "Aceitar o Clarity",
  "decline": "Recusar",
  "policy": "Política de privacidade",
  "prefs": "Preferências de privacidade"
 },
 "es": {
  "text": "Este sitio utiliza Cloudflare Web Analytics sin cookies para contar las visitas. Con su permiso, también use Microsoft Clarity, que registra cómo se utiliza la página (clics y desplazamiento) y puede utilizar cookies para mejorar el sitio web.",
  "accept": "Aceptar Clarity",
  "decline": "Rechazar",
  "policy": "Política de privacidad",
  "prefs": "Preferencias de privacidad"
 },
 "fr": {
  "text": "Ce site utilise Cloudflare Web Analytics sans cookies pour compter les visites. Avec votre permission, utilisez également Microsoft Clarity, qui enregistre comment la page est utilisée (clics et défilement) et peut utiliser des cookies pour améliorer le site Web.",
  "accept": "Accepter Clarity",
  "decline": "Refuser",
  "policy": "Politique de confidentialité",
  "prefs": "Préférences de confidentialité"
 },
 "de": {
  "text": "Diese Website verwendet Cloudflare Web Analytics ohne Cookies, um Besuche zu zählen. Verwenden Sie mit Ihrer Erlaubnis auch Microsoft Clarity, das aufzeichnet, wie die Seite verwendet wird (Klicks und Scrollen) und Cookies zur Verbesserung der Website verwenden kann.",
  "accept": "Clarity akzeptieren",
  "decline": "Abfall",
  "policy": "Datenschutzpolitik",
  "prefs": "Datenschutzpräferenzen"
 },
 "ru": {
  "text": "Этот сайт использует Cloudflare Web Analytics без файлов cookie для подсчета посещений. С вашего разрешения также используйте Microsoft Clarity, которая записывает, как используется страница (клики и прокрутка), и может использовать файлы cookie для улучшения веб-сайта.",
  "accept": "Примите Clarity",
  "decline": "отказываться",
  "policy": "Политика конфиденциальности",
  "prefs": "Предпочтения конфиденциальности"
 },
 "zh": {
  "text": "这个网站使用Cloudflare Web Analytics不用饼干就可以计算访问次数. 在您的许可下,也可以使用Microsoft Clarity来记录页面的使用方式(点击和滚动),并可以使用cookie来改进网站.",
  "accept": "接受Clarity",
  "decline": "拒绝",
  "policy": "隐私政策",
  "prefs": "隐私首选项"
 },
 "hi": {
  "text": "यह साइट बिना कुकीज़ के Cloudflare वेब एनालिटिक्स का उपयोग करती है। आपकी अनुमति के साथ, Microsoft Clarity का भी उपयोग करें, जो यह रिकॉर्ड करता है कि पृष्ठ का उपयोग कैसे किया जाता है (क्लिक और स्क्रॉलिंग) और वेबसाइट को बेहतर बनाने के लिए कुकीज़ का उपयोग कर सकता है।",
  "accept": "Clarity को स्वीकार करना",
  "decline": "मना करना",
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

	var box = null;
	function close() { if (box) { box.hidden = true; } }
	function decide(v) {
		store(v);
		if (v === 'granted') { loadClarity(); }
		close();
		var prefs = document.getElementById('jhPrefs');
		if (prefs) { prefs.focus(); }
	}
	function open() {
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
		box.querySelector('[data-yes]').focus();
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
