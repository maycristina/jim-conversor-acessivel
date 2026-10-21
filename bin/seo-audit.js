#!/usr/bin/env node
/**
 * Local SEO and accessibility audit for pages that show a Jim document.
 *
 * Does what the free web checkers (Rank Math, Sitechecker, Semrush) check
 * for the page itself, with no account, no daily limit and no network but
 * the page: run it as often as you like. It reads a URL or a saved .html
 * file; no dependencies (Node 18+).
 *
 *   node bin/seo-audit.js <url|file.html> [more...] [--json] [--strict] [--warnings-fail]
 *
 *   --strict          exit code 1 when any check FAILS (used by the CI)
 *   --warnings-fail   warnings count as failures too
 *   --require=a,b     these checks (ids) must PASS: a WARN becomes a FAIL
 *   --json            machine-readable output
 *
 * Statuses: PASS, WARN (worth fixing), FAIL (a rule the plugin guarantees).
 * The mode of the page (reader or post) is detected from its markup, and the
 * checks that depend on it follow.
 *
 * Whole files are checked end to end with the WP-CLI command
 * `wp jimca convert <file> --mode=post`, which prints the address of the page.
 */
'use strict';

const GENERIC_AUTHORS = ['user', 'admin', 'administrator', 'administrador', 'author', 'autor', 'unknown', 'microsoft word'];

function decode(text) {
	return String(text)
		.replace(/&#(\d+);/g, (m, n) => String.fromCodePoint(Number(n)))
		.replace(/&#x([0-9a-f]+);/gi, (m, n) => String.fromCodePoint(parseInt(n, 16)))
		.replace(/&nbsp;/g, ' ')
		.replace(/&quot;/g, '"')
		.replace(/&#039;|&apos;/g, "'")
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&amp;/g, '&');
}

function stripTags(html) {
	return decode(html.replace(/<(script|style)\b[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]*>/g, ' ')).replace(/\s+/g, ' ').trim();
}

function metaContent(html, attr, name) {
	const out = [];
	const re = new RegExp('<meta\\s[^>]*' + attr + '=["\']' + name.replace(/[:.]/g, '\\$&') + '["\'][^>]*>', 'gi');
	let m;
	while ((m = re.exec(html))) {
		const c = /content=("([^"]*)"|'([^']*)')/i.exec(m[0]);
		out.push(c ? decode(c[2] !== undefined ? c[2] : c[3]) : '');
	}
	return out;
}

function foldWords(text) {
	return (String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').match(/[a-zÀ-￿]{4,}/g)) || [];
}

function headings(html) {
	const out = [];
	const re = /<h([1-6])\b([^>]*)>([\s\S]*?)<\/h\1>/gi;
	let m;
	while ((m = re.exec(html))) {
		if (/jimca-page-title/.test(m[2])) {
			continue;
		}
		out.push({ level: Number(m[1]), text: stripTags(m[3]) });
	}
	return out;
}

function jsonLd(html) {
	const out = [];
	const re = /<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi;
	let m;
	while ((m = re.exec(html))) {
		try {
			out.push(JSON.parse(m[1]));
		} catch (e) {
			out.push(null);
		}
	}
	return out;
}

/**
 * @param {string} html Page source.
 * @returns {{mode: string, results: {id: string, status: string, message: string}[], score: number}}
 */
function audit(html) {
	const results = [];
	const add = (id, status, message) => results.push({ id, status, message });
	const check = (id, ok, passMessage, failMessage, failStatus = 'FAIL') => add(id, ok ? 'PASS' : failStatus, ok ? passMessage : failMessage);

	const mode = /jimca-mode-post/.test(html) ? 'post' : (/jimca-mode-reader/.test(html) ? 'reader' : 'none');
	const head = (html.split(/<\/head>/i)[0]) || html;

	if ('none' === mode) {
		add('document', 'FAIL', 'no Jim document found on the page (no .jimca-viewer)');
	}

	// ----- title
	const titleMatch = /<title[^>]*>([\s\S]*?)<\/title>/i.exec(head);
	const title = titleMatch ? stripTags(titleMatch[1]) : '';
	check('title', title.length > 0, 'title tag present', 'title tag missing');
	if (title) {
		check('title-length', title.length <= 75, `title has ${title.length} characters (up to 75)`, `title has ${title.length} characters: search engines cut it after about 75`);
	}

	// ----- description
	const descriptions = metaContent(head, 'name', 'description');
	check('description', 1 === descriptions.length, 'one meta description', `${descriptions.length} meta descriptions (need exactly 1)`);
	const description = descriptions[0] || '';
	if (description) {
		check('description-length', description.length >= 70 && description.length <= 160, `description has ${description.length} characters (70-160)`, `description has ${description.length} characters (use 70-160)`, 'WARN');
		check('description-clean', !/([=*_#~-])\1{2,}/.test(description), 'description has no divider characters', 'description has divider characters (=== --- ***)');
		const sheet = (description.match(/\b[A-Z][\p{L} ]{1,24}:\s/gu) || []).length;
		check('description-prose', sheet < 2, 'description reads as prose', 'description looks like a data sheet ("Key: value" pairs)');
		const wanted = foldWords(title.replace(/\s[-–—|]\s[^-–—|]*$/, ''));
		const found = foldWords(description);
		check('description-title', !wanted.length || wanted.some((w) => found.some((f) => f.includes(w) || w.includes(f))), 'title and description share words', 'title and description share no word', 'WARN');
	}

	// ----- canonical, language, robots
	check('canonical', 1 === (head.match(/<link[^>]*rel=["']canonical["']/gi) || []).length, 'one canonical link', 'canonical link missing or repeated');
	const htmlLang = (/<html[^>]*\slang=["']([^"']+)["']/i.exec(html) || [])[1] || '';
	check('html-lang', '' !== htmlLang, `html lang="${htmlLang}"`, 'html element has no lang attribute');
	const robots = metaContent(head, 'name', 'robots').join(',');
	check('robots', !/noindex/i.test(robots), 'page can be indexed', 'meta robots says noindex');

	// ----- headings
	const hs = headings(html);
	const h1 = hs.filter((h) => 1 === h.level);
	check('h1', 1 === h1.length, 'one h1', `${h1.length} h1 headings (need exactly 1)`);
	check('h-empty', hs.every((h) => h.text !== ''), 'no empty headings', 'there are empty headings');
	let jumps = 0;
	for (let i = 1; i < hs.length; i++) {
		if (hs[i].level > hs[i - 1].level + 1) {
			jumps++;
		}
	}
	check('h-order', 0 === jumps, 'heading levels do not skip', `heading levels skip ${jumps} time(s)`);
	const h1Text = h1[0] ? h1[0].text.toLowerCase() : null;
	check('h-duplicate', !h1Text || !hs.some((h) => h.level > 1 && h.text.toLowerCase() === h1Text), 'no heading repeats the h1', 'an h2-h6 repeats the h1 text (duplicate heading)');

	// ----- Open Graph and Twitter
	for (const prop of ['og:type', 'og:title', 'og:description', 'og:url', 'og:image', 'og:locale', 'og:updated_time']) {
		const found = metaContent(head, 'property', prop);
		check(prop, 1 === found.length && '' !== found[0], `${prop} present`, `${prop} ${found.length ? 'repeated or empty' : 'missing'}`, 'og:image' === prop && !found.length ? 'WARN' : 'FAIL');
	}
	check('twitter:card', 1 === metaContent(head, 'name', 'twitter:card').length, 'twitter:card present', 'twitter:card missing');
	const ogLocale = metaContent(head, 'property', 'og:locale')[0] || '';
	const ogDesc = metaContent(head, 'property', 'og:description')[0];
	check('og-matches', !description || !ogDesc || ogDesc === description, 'og:description equals the meta description', 'og:description differs from the meta description', 'WARN');

	// ----- structured data
	const blocks = jsonLd(html);
	check('json-ld', blocks.length > 0 && blocks.every(Boolean), 'JSON-LD parses', 'JSON-LD missing or invalid');
	const article = blocks.find((b) => b && 'Article' === b['@type']);
	check('json-ld-article', !!article, 'JSON-LD has an Article', 'JSON-LD has no Article');
	if (article) {
		const authorName = article.author && article.author.name ? String(article.author.name).toLowerCase() : '';
		const shownAuthor = article.author && article.author.name ? article.author.name : '';
		check('author', '' === authorName || !GENERIC_AUTHORS.includes(authorName), shownAuthor ? `author "${shownAuthor}"` : 'no author declared (omitted)', `author "${shownAuthor}" is not a real name`);
		check('author-set', '' !== authorName, 'author declared', 'no author declared: set the Author in the file properties', 'WARN');
		check('in-language', !!article.inLanguage, `inLanguage ${article.inLanguage}`, 'inLanguage missing');
		check('time-required', /^PT\d+M$/.test(article.timeRequired || ''), `timeRequired ${article.timeRequired}`, 'timeRequired missing', 'WARN');
		check('accessibility', Array.isArray(article.accessibilityFeature) && article.accessibilityFeature.includes('readingOrder'), 'accessibilityFeature declared', 'accessibilityFeature missing');
		check('no-typo-key', !('@Type' in article), 'keys are well written', 'a key is mistyped ("@Type")');
		const reading = Array.isArray(article.accessibilityFeature) && article.accessibilityFeature.includes('displayTransformability');
		check('claims-match-mode', 'reader' === mode ? reading : !reading, 'declared features match the display mode', `declared features do not match the ${mode} mode`);
		const contentLang = (/class="jimca-content"[^>]*\slang="([^"]+)"/i.exec(html) || [])[1];
		if (contentLang) {
			check('lang-consistent', article.inLanguage.toLowerCase() === contentLang.toLowerCase(), 'inLanguage equals the lang of the text', `inLanguage ${article.inLanguage} differs from the text lang ${contentLang}`);
			const expected = contentLang.replace('-', '_').toLowerCase();
			check('og-locale-lang', ogLocale.toLowerCase().startsWith(expected.split('_')[0]), 'og:locale follows the document language', `og:locale ${ogLocale} does not follow the document language ${contentLang}`, 'WARN');
		}
	}

	// ----- content
	const body = html.slice(html.search(/<body/i));
	const text = stripTags(body);
	const words = (text.match(/\p{L}{2,}/gu) || []).length;
	check('words', words >= 300, `${words} words`, `only ${words} words (thin content under 300)`, 'WARN');
	check('text-ratio', text.length / html.length >= 0.1, `text is ${Math.round(100 * text.length / html.length)}% of the HTML`, 'text is under 10% of the HTML', 'WARN');
	const imgs = html.match(/<img\b[^>]*>/gi) || [];
	check('img-alt', imgs.every((t) => /\salt=/i.test(t)), `${imgs.length} images, all with an alt attribute`, 'an image has no alt attribute');
	const links = (html.match(/<a\s[^>]*href=/gi) || []).length;
	check('links', links <= 300, `${links} links`, `${links} links (more than 300 dilutes the page)`, 'WARN');

	// ----- scripts of the plugin
	const scripts = (html.match(/<script\b[^>]*src=["'][^"']*jim-conversor-acessivel[^"']*["'][^>]*>/gi) || []);
	const names = scripts.map((t) => ((/assets\/js\/([a-z-]+)(?:\.min)?\.js/i.exec(t) || [])[1] || ''));
	check('defer', scripts.every((t) => /\sdefer\b|\sasync\b/i.test(t)), `${scripts.length} plugin scripts, all deferred`, 'a plugin script is not deferred (blocks parsing)');
	check('minified', scripts.every((t) => /\.min\.js/.test(t)), 'plugin scripts are minified', 'a plugin script is not minified (SCRIPT_DEBUG on, or a package without .min files)', 'WARN');
	if ('post' === mode) {
		check('post-no-pager', !names.includes('pager'), 'post mode does not load pager.js', 'post mode loads pager.js');
		check('post-no-reader', !names.includes('frontend') || /data-jimca-tutor|jimca-tutor/.test(html), 'post mode does not load the reader bar script', 'post mode loads frontend.js without a tutor');
		check('post-script', names.includes('post'), 'post mode loads post.js', 'post mode misses post.js');
		check('post-no-search', !/data-jimca-search\b/.test(html), 'post mode has no search form', 'post mode has a search form');
		check('post-no-parts', !/class="jimca-pager\b|data-jimca-pager\b/.test(html), 'post mode has no pagination', 'post mode has pagination');
	} else if ('reader' === mode) {
		check('reader-scripts', names.includes('frontend') && names.includes('pager'), 'reader mode loads frontend.js and pager.js', 'reader mode misses frontend.js or pager.js');
		check('reader-no-post', !names.includes('post'), 'reader mode does not load post.js', 'reader mode loads post.js', 'WARN');
		check('reader-search', /data-jimca-search\b/.test(html), 'reader mode has the search form', 'reader mode misses the search form');
	}
	const contentImages = imgs.filter((t) => !/jimca-marker|jimca-lightbox/.test(t)).length;
	check('lightbox', (contentImages > 0) === names.includes('lightbox'), 'image viewer loaded only when there are images', contentImages ? 'images but no lightbox.js' : 'lightbox.js loaded without images', 'WARN');

	const fails = results.filter((r) => 'FAIL' === r.status).length;
	const warns = results.filter((r) => 'WARN' === r.status).length;

	return { mode, results, score: Math.max(0, 100 - fails * 8 - warns * 3) };
}

async function load(target) {
	if (/^https?:\/\//i.test(target)) {
		const response = await fetch(target, { redirect: 'follow', headers: { 'user-agent': 'Mozilla/5.0 (compatible; JimSeoAudit/1.0)' } });
		if (!response.ok) {
			throw new Error(`${target} answered ${response.status}`);
		}
		return response.text();
	}
	return require('fs').readFileSync(target, 'utf8');
}

async function main() {
	const argv = process.argv.slice(2);
	const flags = new Set(argv.filter((a) => a.startsWith('--') && !a.startsWith('--require=')));
	const targets = argv.filter((a) => !a.startsWith('--'));

	if (!targets.length) {
		console.error('Usage: node bin/seo-audit.js <url|file.html> [more...] [--json] [--strict] [--warnings-fail]');
		process.exit(2);
	}

	let failed = 0;
	const all = [];
	const required = new Set(((argv.find((a) => a.startsWith('--require=')) || '').slice(10)).split(',').filter(Boolean));

	for (const target of targets) {
		const report = audit(await load(target));
		for (const r of report.results) {
			if ('WARN' === r.status && required.has(r.id)) {
				r.status = 'FAIL';
			}
		}
		all.push({ target, ...report });

		failed += report.results.filter((r) => 'FAIL' === r.status || (flags.has('--warnings-fail') && 'WARN' === r.status)).length;

		if (!flags.has('--json')) {
			console.log(`\n== ${target}  [mode: ${report.mode}]  score ${report.score}/100`);
			for (const r of report.results) {
				if ('PASS' !== r.status || flags.has('--verbose')) {
					console.log(`${r.status.padEnd(4)}  ${r.id}: ${r.message}`);
				}
			}
			const passed = report.results.filter((r) => 'PASS' === r.status).length;
			console.log(`${passed}/${report.results.length} checks passed.`);
		}
	}

	if (flags.has('--json')) {
		console.log(JSON.stringify(all, null, 2));
	}

	process.exit(flags.has('--strict') && failed ? 1 : 0);
}

if (require.main === module) {
	main().catch((error) => {
		console.error(error.message);
		process.exit(2);
	});
}

module.exports = { audit };
