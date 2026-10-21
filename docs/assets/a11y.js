/* Botão de acessibilidade (gerado por build_a11y.py). Sem bibliotecas. Guarda as escolhas neste navegador (localStorage "jim-a11y"). */
(function () {
	'use strict';
	var TEXTS = {"pt": {"btn": "Acessibilidade", "title": "Ajustes de acessibilidade", "close": "Fechar", "text_size": "Tamanho do texto", "smaller": "Diminuir o texto", "bigger": "Aumentar o texto", "contrast": "Alto contraste", "links": "Sublinhar links", "spacing": "Espaçamento do texto", "font": "Fonte de leitura simples", "motion": "Reduzir movimento", "reset": "Restaurar padrão", "note": "Os ajustes ficam salvos neste navegador. Eles ajudam na leitura deste site; não substituem as configurações do seu dispositivo.", "on": "ligado", "off": "desligado"}, "es": {"btn": "Accesibilidad", "title": "Ajustes de accesibilidad", "close": "Cerca", "text_size": "Tamaño del texto", "smaller": "Disminución del texto", "bigger": "Aumento del texto", "contrast": "Alto contraste", "links": "Enlaces en línea", "spacing": "Espaciado de texto", "font": "Fuente de lectura simple", "motion": "Reducir movimiento", "reset": "Restore Default", "note": "Los ajustes se guardan en este navegador. Te ayudan a leer este sitio; no reemplazan la configuración de tu dispositivo.", "on": "conectado", "off": "apagado"}, "fr": {"btn": "Accessibilité", "title": "Ajustements d'accessibilité", "close": "Fermer", "text_size": "Taille du texte", "smaller": "Réduire le texte", "bigger": "Augmenter le texte", "contrast": "Haut contraste", "links": "Liens sous-jacents", "spacing": "Espacement des textes", "font": "Source de lecture simple", "motion": "Réduire le déplacement", "reset": "Restaurer par défaut", "note": "Les ajustements sont enregistrés dans ce navigateur. Ils vous aident à lire ce site; ils ne remplacent pas les paramètres de votre appareil.", "on": "connecté", "off": "Départ"}, "de": {"btn": "Zugänglichkeit", "title": "Zugänglichkeitsanpassungen", "close": "Schließen", "text_size": "Textgröße", "smaller": "Text verkleinern", "bigger": "Erhöhen Sie den Text", "contrast": "hoher Kontrast", "links": "Unterstreichungslinks", "spacing": "Textabstand", "font": "Einfache Lesequelle", "motion": "Reduziere Bewegung", "reset": "Standardwiederherstellung", "note": "Anpassungen werden in diesem Browser gespeichert. Sie helfen ihnen, diese seite zu lesen; sie ersetzen nicht ihre geräteeinstellungen.", "on": "verbunden", "off": "ausgeschaltet"}, "ru": {"btn": "Доступность", "title": "Корректировки доступности", "close": "Закрыть", "text_size": "Размер текста", "smaller": "Уменьшить текст", "bigger": "Увеличить текст", "contrast": "Высокий контраст", "links": "Подчеркнуть ссылки", "spacing": "Текстовое расстояние", "font": "Простой источник чтения", "motion": "Уменьшить движение", "reset": "Восстановить Default", "note": "Корректировки сохраняются в этом браузере. Они помогают вам читать этот сайт; они не заменяют настройки вашего устройства.", "on": "соединенный", "off": "выключать"}, "zh": {"btn": "无障碍", "title": "无障碍调整", "close": "关闭", "text_size": "文本大小", "smaller": "减少文本", "bigger": "增加文本", "contrast": "高对比度", "links": "下划线链接", "spacing": "文本间距", "font": "简单的读取源", "motion": "减少移动", "reset": "恢复默认", "note": "调整在此浏览器中保存 。 它们帮助您读取此网站; 它们不会替换您的设备设置 。", "on": "已连接", "off": "关"}, "hi": {"btn": "सुलभता", "title": "अभिगम्यता समायोजन", "close": "बंद", "text_size": "पाठ का आकार", "smaller": "पाठ घटाना", "bigger": "पाठ बढ़ाएं", "contrast": "उच्च विपरीत", "links": "अंडरलाइन लिंक", "spacing": "टेक्स्ट स्पेसिंग", "font": "सरल पठन स्रोत", "motion": "कम करें", "reset": "डिफ़ॉल्ट पुनर्स्थापित करें", "note": "इस ब्राउज़र में समायोजन को बचाया जाता है। वे आपको इस साइट को पढ़ने में मदद करते हैं; वे आपके डिवाइस सेटिंग्स को प्रतिस्थापित नहीं करते हैं।", "on": "जुड़े", "off": "बंद"}};
	var lang = (document.documentElement.lang || 'pt').toLowerCase();
	var key = lang.indexOf('zh') === 0 ? 'zh' : lang.slice(0, 2);
	var T = TEXTS[key] || TEXTS.pt;
	var KEY = 'jim-a11y', root = document.documentElement;
	var state = { size: 0, hc: false, links: false, space: false, font: false, motion: false };
	try { var s = JSON.parse(localStorage.getItem(KEY) || '{}'); Object.keys(state).forEach(function (k) { if (typeof s[k] === typeof state[k]) { state[k] = s[k]; } }); } catch (e) { /* sem armazenamento */ }
	function save() { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* ok */ } }

	function apply() {
		root.style.fontSize = state.size ? (100 + state.size * 12.5) + '%' : '';
		root.classList.toggle('a11y-hc', state.hc);
		root.classList.toggle('a11y-links', state.links);
		root.classList.toggle('a11y-space', state.space);
		root.classList.toggle('a11y-font', state.font);
		if (state.motion) {
			root.setAttribute('data-motion', 'reduced');
			try { localStorage.setItem('jim-motion', 'reduced'); } catch (e) { /* ok */ }
			var mb = document.getElementById('motionBtn'); if (mb) { mb.setAttribute('aria-pressed', 'true'); }
		}
	}
	apply();

	function build() {
		var wrap = document.createElement('div'); wrap.className = 'a11y';
		var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'a11y__btn'; btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'a11yPanel'); btn.setAttribute('aria-label', T.btn);
		btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.6" r="1.8"/><path d="M4.5 8.2c5 1.4 10 1.4 15 0M12 8.4v5.2m0 0l-3.4 6.4m3.4-6.4l3.4 6.4"/></svg><span></span>';
		btn.lastChild.textContent = T.btn;
		var panel = document.createElement('div'); panel.id = 'a11yPanel'; panel.className = 'a11y__panel'; panel.hidden = true; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-labelledby', 'a11yTitle');
		panel.innerHTML = '<div class="a11y__head"><h2 id="a11yTitle"></h2><button type="button" class="a11y__x"></button></div>' +
			'<div class="a11y__row"><span id="a11ySz"></span><div class="a11y__sz" role="group" aria-labelledby="a11ySz"><button type="button" data-sz="-1">A−</button><button type="button" data-sz="1">A+</button></div></div>' +
			'<div class="a11y__row"><span></span><button type="button" class="a11y__t" data-k="hc"></button></div>' +
			'<div class="a11y__row"><span></span><button type="button" class="a11y__t" data-k="links"></button></div>' +
			'<div class="a11y__row"><span></span><button type="button" class="a11y__t" data-k="space"></button></div>' +
			'<div class="a11y__row"><span></span><button type="button" class="a11y__t" data-k="font"></button></div>' +
			'<div class="a11y__row"><span></span><button type="button" class="a11y__t" data-k="motion"></button></div>' +
			'<button type="button" class="a11y__reset"></button><p class="a11y__note"></p><p class="sr" role="status" aria-live="polite" data-live></p>';
		wrap.appendChild(panel); wrap.appendChild(btn); document.body.appendChild(wrap);
		panel.querySelector('h2').textContent = T.title;
		panel.querySelector('.a11y__x').textContent = '×'; panel.querySelector('.a11y__x').setAttribute('aria-label', T.close);
		panel.querySelector('#a11ySz').textContent = T.text_size;
		panel.querySelector('[data-sz="-1"]').setAttribute('aria-label', T.smaller);
		panel.querySelector('[data-sz="1"]').setAttribute('aria-label', T.bigger);
		var labels = { hc: T.contrast, links: T.links, space: T.spacing, font: T.font, motion: T.motion };
		Array.prototype.forEach.call(panel.querySelectorAll('.a11y__t'), function (b) {
			var k = b.getAttribute('data-k'); b.parentNode.firstChild.textContent = labels[k]; b.setAttribute('aria-label', labels[k]);
		});
		panel.querySelector('.a11y__reset').textContent = T.reset; panel.querySelector('.a11y__note').textContent = T.note;
		var live = panel.querySelector('[data-live]');
		function paint() {
			Array.prototype.forEach.call(panel.querySelectorAll('.a11y__t'), function (b) {
				var on = !!state[b.getAttribute('data-k')]; b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.textContent = on ? T.on : T.off;
			});
		}
		paint();
		function set(msg) { apply(); paint(); save(); live.textContent = msg; }
		panel.addEventListener('click', function (e) {
			var b = e.target.closest('button'); if (!b) { return; }
			if (b.hasAttribute('data-sz')) { state.size = Math.max(-1, Math.min(4, state.size + parseInt(b.getAttribute('data-sz'), 10))); set(T.text_size + ' ' + (100 + state.size * 12.5) + '%'); }
			else if (b.hasAttribute('data-k')) { var k = b.getAttribute('data-k'); state[k] = !state[k]; if (k === 'motion' && !state[k]) { try { localStorage.setItem('jim-motion', 'full'); } catch (er) { /* ok */ } root.setAttribute('data-motion', 'full'); } set(labels[k] + ': ' + (state[k] ? T.on : T.off)); }
			else if (b.classList.contains('a11y__reset')) { state = { size: 0, hc: false, links: false, space: false, font: false, motion: false }; try { localStorage.setItem('jim-motion', 'full'); } catch (er) { /* ok */ } root.setAttribute('data-motion', 'full'); set(T.reset); }
			else if (b.classList.contains('a11y__x')) { close(true); }
		});
		function open() { panel.hidden = false; btn.setAttribute('aria-expanded', 'true'); window.setTimeout(function () { panel.querySelector('.a11y__x').focus(); }, 0); }
		function close(back) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); if (back) { btn.focus(); window.setTimeout(function () { btn.focus(); }, 0); } }
		btn.addEventListener('click', function () { if (panel.hidden) { open(); } else { close(false); } });
		wrap.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { close(true); } });
		document.addEventListener('click', function (e) { if (!panel.hidden && !wrap.contains(e.target)) { close(false); } });
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', build); } else { build(); }
}());
