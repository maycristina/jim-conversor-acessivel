/**
 * Regressão da barra de leitura nova (assets/js/frontend.js + pager.js), rodando
 * os scripts de verdade num DOM (jsdom): menu de navegação (busca e sumário),
 * ajustes (voz e tema), tamanho do texto, play/pause, velocidade que gira a cada
 * clique, paginação dentro da barra e a bolha que acompanha o botão aberto.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const ROOT = path.join( __dirname, '../..' );
const FRONTEND = fs.readFileSync( path.join( ROOT, 'assets/js/frontend.js' ), 'utf8' );
const PAGER = fs.readFileSync( path.join( ROOT, 'assets/js/pager.js' ), 'utf8' );
// Loaded before frontend.js, as WordPress does (a dependency of it).
const SPEECH = fs.readFileSync( path.join( ROOT, 'assets/js/speech.js' ), 'utf8' );
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const PARTS = { 1: '<h2 id="a">Um</h2><p>Primeiro.</p>', 2: '<h2 id="b">Dois</h2><p>Segundo.</p>', 3: '<p>Terceiro.</p>' };

function trigger( name, extra = '' ) {
	return `data-jimca-menu-trigger="${ name }" aria-haspopup="true" aria-expanded="false" aria-controls="m-${ name }" ${ extra }`;
}

function page( { paged = true, toc = true } = {} ) {
	return `<html lang="pt-BR"><body><section class="jimca-viewer jimca-theme-light" id="v" data-jimca-viewer data-jimca-doc="9"${ paged ? ' data-jimca-parts="3" data-jimca-part="1" data-jimca-part-arg="jimca_part_9"' : '' }>
	<div data-jimca-player hidden>
		<div data-jimca-player-bar role="toolbar">
			<span data-jimca-blob></span>
			<div class="jimca-player__slot"><button type="button" ${ trigger( 'nav' ) }>Navigation</button>
				<div role="menu" data-jimca-menu="nav" id="m-nav" hidden>
					<button type="button" role="menuitem" data-jimca-search-open>Search in the document</button>
					${ toc ? '<button type="button" role="menuitem" data-jimca-toc-toggle>Contents</button>' : '' }
				</div></div>
			<div class="jimca-player__slot"><button type="button" ${ trigger( 'settings' ) }>Settings</button>
				<div role="group" data-jimca-menu="settings" id="m-settings" hidden>
					<fieldset data-jimca-voice-group hidden>
						<select data-jimca-voice-lang></select>
						<span data-jimca-voice-kind-wrap hidden><select data-jimca-voice-kind></select></span>
						<select data-jimca-voice-name></select>
					</fieldset>
					<label><input type="radio" name="t" value="light" data-jimca-theme checked><span>Light</span></label>
					<label><input type="radio" name="t" value="sepia" data-jimca-theme><span>Sepia</span></label>
					<label><input type="radio" name="t" value="dark" data-jimca-theme><span>Dark</span></label>
					<label><input type="radio" name="t" value="high-contrast" data-jimca-theme><span>High contrast</span></label>
				</div></div>
			<div class="jimca-player__slot"><button type="button" ${ trigger( 'font' ) }>Aa</button>
				<div role="group" data-jimca-menu="font" id="m-font" hidden>
					<button type="button" data-jimca-font="decrease" aria-label="Decrease">A-</button>
					<button type="button" data-jimca-font="reset" data-jimca-font-value>100%</button>
					<button type="button" data-jimca-font="increase" aria-label="Increase">A+</button>
				</div></div>
			<div class="jimca-player__slot"><button type="button" data-jimca-play>
				<span data-jimca-icon="play">P</span><span data-jimca-icon="pause" hidden>||</span>
				<span data-jimca-play-label>Listen</span></button></div>
			<div class="jimca-player__slot"><button type="button" data-jimca-speed data-rate="1" aria-label="Reading speed: 1×"><span data-jimca-speed-value>1×</span></button></div>
			<div class="jimca-player__slot"><button type="button" data-jimca-stop disabled>Stop</button></div>
			${ paged ? `<div class="jimca-player__slot" data-jimca-pager role="group">
				<button type="button" data-jimca-pager-prev aria-disabled="true" class="is-disabled">Previous</button>
				<button type="button" ${ trigger( 'pager' ) }><span data-jimca-pager-count>1/3</span></button>
				<button type="button" data-jimca-pager-next data-jimca-part-link="2">Next</button>
				<div role="group" data-jimca-menu="pager" id="m-pager" hidden><select data-jimca-pager-select><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></div>
				<span role="status" data-jimca-pager-status></span></div>` : '' }
			<div class="jimca-player__slot"><button type="button" data-jimca-hide>Hide</button></div>
		</div>
		<button type="button" data-jimca-show hidden>Show</button>
		<p data-jimca-status></p>
	</div>
	<form data-jimca-search hidden>
		<div><label for="q">Search</label><button type="button" data-jimca-search-close hidden>Close</button></div>
		<input type="search" id="q" data-jimca-search-input><button type="submit">Go</button>
		<p data-jimca-search-status></p><div data-jimca-search-results></div>
	</form>
	${ toc ? '<nav data-jimca-toc><button type="button" data-jimca-toc-close hidden>x</button><a href="#a" data-id="a" data-jimca-toc-link>Um</a></nav>' : '' }
	<div data-jimca-content tabindex="-1">${ PARTS[ 1 ] }</div>
</section></body></html>`;
}

async function setup( html, { voices = [], prefs = null } = {} ) {
	const dom = new JSDOM( html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/livro/' } );
	const w = dom.window;
	const spoken = [];

	if ( prefs ) {
		w.localStorage.setItem( 'jimca-prefs-v', JSON.stringify( prefs ) );
	}

	let current = null;

	w.SpeechSynthesisUtterance = function ( text ) {
		this.text = text;
	};
	w.speechSynthesis = {
		getVoices: () => voices,
		addEventListener() {},
		pause() {},
		resume() {},
		speak( u ) {
			current = u;
			spoken.push( u );
			setTimeout( () => {
				if ( current === u ) {
					current = null;
					u.onend && u.onend();
				}
			}, 15 );
		},
		cancel() {
			current = null;
		},
	};
	w.IntersectionObserver = function () {
		this.observe = () => {};
		this.disconnect = () => {};
	};
	w.matchMedia = () => ( { matches: false, addEventListener() {}, addListener() {} } );
	w.Element.prototype.scrollIntoView = function () {};
	w.jimcaPagerI18n = { ajaxUrl: '/ajax', actionPart: 'part', actionSearch: 'search', partOf: 'Part %1$s of %2$s', noResults: 'No results.', resultsOne: '%s result.', resultsMany: '%s results.' };
	w.jimcaFrontendI18n = { rateLabel: 'Reading speed', textSize: 'Text size', resetFont: 'Default text size', voiceAll: 'All voices', voiceFemale: 'Female', voiceMale: 'Male', voiceAuto: 'Automatic', play: 'Listen', pause: 'Pause', resume: 'Resume' };
	w.fetch = ( url ) => {
		const q = new w.URL( url, 'https://example.org' ).searchParams;
		const body = PARTS[ q.get( 'part' ) ];

		return Promise.resolve( { status: 200, json: () => Promise.resolve( body ? { success: true, data: { html: body } } : { success: false } ) } );
	};
	w.eval( SPEECH );
	w.eval( FRONTEND );
	w.eval( PAGER );
	await wait( 20 );

	const q = ( sel ) => w.document.querySelector( sel );

	return { w, q, spoken, click: ( sel ) => q( sel ).click() };
}

const VOICES = [
	{ name: 'Microsoft Maria - Portuguese (Brazil)', lang: 'pt-BR' },
	{ name: 'Microsoft Daniel - Portuguese (Brazil)', lang: 'pt-BR' },
	{ name: 'Google português do Brasil', lang: 'pt-BR' },
	{ name: 'Microsoft Joana - Portuguese (Portugal)', lang: 'pt-PT' },
	{ name: 'Google US English', lang: 'en-US' },
];

test( 'the bar appears with JS; the popovers are closed and only one opens at a time', async () => {
	const e = await setup( page() );

	assert.equal( e.q( '[data-jimca-player]' ).hidden, false );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	assert.equal( e.q( '[data-jimca-menu="nav"]' ).hidden, false );
	assert.equal( e.q( '[data-jimca-menu-trigger="nav"]' ).getAttribute( 'aria-expanded' ), 'true' );

	e.click( '[data-jimca-menu-trigger="font"]' );
	assert.equal( e.q( '[data-jimca-menu="nav"]' ).hidden, true, 'the first one closed' );
	assert.equal( e.q( '[data-jimca-menu="font"]' ).hidden, false );
	assert.equal( e.q( '[data-jimca-menu-trigger="nav"]' ).getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'Esc closes the popover and gives the focus back to its button', async () => {
	const e = await setup( page() );
	const btn = e.q( '[data-jimca-menu-trigger="settings"]' );

	btn.click();
	assert.equal( e.q( '[data-jimca-menu="settings"]' ).hidden, false );
	e.q( '[data-jimca-menu="settings"]' ).dispatchEvent( new e.w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	assert.equal( e.q( '[data-jimca-menu="settings"]' ).hidden, true );
	assert.equal( e.w.document.activeElement, btn );
} );

test( 'the blob goes behind the button of the open popover and leaves when it closes', async () => {
	const e = await setup( page() );
	const blob = e.q( '[data-jimca-blob]' );

	assert.equal( blob.classList.contains( 'is-on' ), false );
	e.click( '[data-jimca-menu-trigger="font"]' );
	assert.equal( blob.classList.contains( 'is-on' ), true );
	e.click( '[data-jimca-menu-trigger="font"]' );
	assert.equal( blob.classList.contains( 'is-on' ), false );
} );

test( 'navigation menu: Search opens the search panel (and closes the menu); Esc closes the panel back to the menu button', async () => {
	const e = await setup( page() );
	const form = e.q( '[data-jimca-search]' );

	assert.equal( form.hidden, true, 'the form is not on the page: it is a panel' );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-search-open]' );

	assert.equal( e.q( '[data-jimca-menu="nav"]' ).hidden, true );
	assert.equal( form.hidden, false );
	assert.equal( e.w.document.activeElement, e.q( '[data-jimca-search-input]' ), 'the field has the focus' );
	assert.equal( e.q( '[data-jimca-search-close]' ).hidden, false, 'a close button for touch screens' );

	e.q( '[data-jimca-search-input]' ).dispatchEvent( new e.w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	assert.equal( form.hidden, true );
	assert.equal( e.w.document.activeElement, e.q( '[data-jimca-menu-trigger="nav"]' ) );
} );

test( 'Esc clears the typed text first; only an empty field closes the panel', async () => {
	const e = await setup( page() );
	const input = e.q( '[data-jimca-search-input]' );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-search-open]' );
	input.value = 'moises';
	input.dispatchEvent( new e.w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	assert.equal( input.value, '' );
	assert.equal( e.q( '[data-jimca-search]' ).hidden, false, 'still open after the first Esc' );

	input.dispatchEvent( new e.w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	assert.equal( e.q( '[data-jimca-search]' ).hidden, true );
} );

test( 'a click outside closes the search panel; a click inside does not', async () => {
	const e = await setup( page() );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-search-open]' );
	e.q( '[data-jimca-search-input]' ).click();
	assert.equal( e.q( '[data-jimca-search]' ).hidden, false );

	e.q( '[data-jimca-content]' ).click();
	assert.equal( e.q( '[data-jimca-search]' ).hidden, true );
} );

test( 'choosing a search result closes the panel', async () => {
	const e = await setup( page( { paged: false } ) );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-search-open]' );
	e.q( '[data-jimca-search-input]' ).value = 'primeiro';
	e.q( '[data-jimca-search]' ).dispatchEvent( new e.w.Event( 'submit', { cancelable: true } ) );
	await wait( 10 );

	e.click( '.jimca-search__result' );
	assert.equal( e.q( '[data-jimca-search]' ).hidden, true );
} );

test( 'navigation menu: Contents opens the contents panel and the search panel gives way to it', async () => {
	const e = await setup( page() );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-search-open]' );
	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-toc-toggle]' );

	assert.equal( e.q( '[data-jimca-toc]' ).hidden, false );
	assert.equal( e.q( '[data-jimca-search]' ).hidden, true, 'one panel at a time' );
	assert.equal( e.q( '[data-jimca-menu="nav"]' ).hidden, true );
} );

test( 'a document without headings has no Contents item', async () => {
	const e = await setup( page( { toc: false } ) );

	assert.equal( e.q( '[data-jimca-toc-toggle]' ), null );
	e.click( '[data-jimca-menu-trigger="nav"]' );
	assert.ok( e.q( '[data-jimca-search-open]' ), 'search is always there' );
} );

test( 'text size: A+ and A- change the text, the value is shown and the ends are marked', async () => {
	const e = await setup( page() );
	const content = e.q( '[data-jimca-content]' );

	e.click( '[data-jimca-menu-trigger="font"]' );
	e.click( '[data-jimca-font="increase"]' );
	assert.equal( content.style.fontSize, '1.1em' );
	assert.equal( e.q( '[data-jimca-font-value]' ).textContent, '110%' );
	assert.match( e.q( '[data-jimca-font-value]' ).getAttribute( 'aria-label' ), /110%/ );
	assert.equal( e.q( '[data-jimca-menu="font"]' ).hidden, false, 'the popover stays open for the next step' );

	for ( let i = 0; i < 12; i++ ) {
		e.click( '[data-jimca-font="increase"]' );
	}
	assert.equal( content.style.fontSize, '2em', 'stops at 200%' );
	assert.equal( e.q( '[data-jimca-font="increase"]' ).getAttribute( 'aria-disabled' ), 'true' );

	e.click( '[data-jimca-font="reset"]' );
	assert.equal( content.style.fontSize, '1em' );
	e.click( '[data-jimca-font="decrease"]' );
	assert.equal( content.style.fontSize, '0.9em' );
} );

test( 'theme: High contrast sits on top of the chosen theme; another theme turns it off; the choice is remembered', async () => {
	const e = await setup( page() );
	const viewer = e.q( '[data-jimca-viewer]' );
	const pick = ( value ) => {
		const radio = e.q( `input[value="${ value }"]` );
		radio.checked = true;
		radio.dispatchEvent( new e.w.Event( 'change', { bubbles: true } ) );
	};

	pick( 'sepia' );
	assert.ok( viewer.classList.contains( 'jimca-theme-sepia' ) && ! viewer.classList.contains( 'jimca-high-contrast' ) );

	pick( 'high-contrast' );
	assert.ok( viewer.classList.contains( 'jimca-high-contrast' ) );
	assert.ok( viewer.classList.contains( 'jimca-theme-sepia' ), 'the theme underneath is kept' );
	assert.equal( e.q( 'input[value="high-contrast"]' ).checked, true );

	pick( 'dark' );
	assert.ok( viewer.classList.contains( 'jimca-theme-dark' ) && ! viewer.classList.contains( 'jimca-high-contrast' ) );

	const saved = JSON.parse( e.w.localStorage.getItem( 'jimca-prefs-v' ) );
	assert.equal( saved.theme, 'dark' );
	assert.equal( saved.contrast, false );
} );

test( 'speed: one click goes to the next value 1 > 1.25 > 1.5 > 2 > 1, no menu, and the name says the value', async () => {
	const e = await setup( page() );
	const btn = e.q( '[data-jimca-speed]' );
	const seen = [];

	for ( let i = 0; i < 5; i++ ) {
		btn.click();
		seen.push( btn.getAttribute( 'data-rate' ) );
	}

	assert.deepEqual( seen, [ '1.25', '1.5', '2', '1', '1.25' ] );
	assert.match( btn.getAttribute( 'aria-label' ), /^Reading speed: 1[.,]25×$/ );
	assert.match( e.q( '[data-jimca-status]' ).textContent, /Reading speed: 1[.,]25/ );
	assert.equal( JSON.parse( e.w.localStorage.getItem( 'jimca-prefs-v' ) ).rate, 1.25 );
	assert.equal( btn.getAttribute( 'aria-haspopup' ), null, 'it is not a menu button' );
} );

test( 'speed: a saved speed outside the list (0.75) goes to the next one above it', async () => {
	const e = await setup( page(), { prefs: { rate: 0.75 } } );
	const btn = e.q( '[data-jimca-speed]' );

	assert.equal( btn.getAttribute( 'data-rate' ), '0.75', 'shown as it was saved' );
	btn.click();
	assert.equal( btn.getAttribute( 'data-rate' ), '1' );
} );

test( 'speed changed while reading restarts the passage at the new speed', async () => {
	const e = await setup( page( { paged: false } ) );

	e.click( '[data-jimca-play]' );
	await wait( 5 );
	const before = e.spoken.length;
	e.click( '[data-jimca-speed]' );
	await wait( 5 );

	assert.ok( e.spoken.length > before, 'the passage was spoken again' );
	assert.equal( e.spoken[ e.spoken.length - 1 ].rate, 1.25 );
} );

test( 'play and pause are one button: the name changes, there is no aria-pressed, the icon swaps', async () => {
	const e = await setup( page( { paged: false } ) );
	const play = e.q( '[data-jimca-play]' );

	assert.equal( play.hasAttribute( 'aria-pressed' ), false );
	play.click();
	assert.equal( e.q( '[data-jimca-play-label]' ).textContent, 'Pause' );
	assert.equal( e.q( '[data-jimca-icon="pause"]' ).hidden, false );
	assert.equal( e.q( '[data-jimca-icon="play"]' ).hidden, true );
	assert.equal( play.hasAttribute( 'aria-pressed' ), false );

	play.click();
	assert.equal( e.q( '[data-jimca-play-label]' ).textContent, 'Resume' );

	e.click( '[data-jimca-stop]' );
	assert.equal( e.q( '[data-jimca-play-label]' ).textContent, 'Listen' );
	assert.equal( e.q( '[data-jimca-stop]' ).disabled, true );
} );

test( 'voice settings: language starts as the page language, the voices are of that language, the type is offered only when names tell it', async () => {
	const e = await setup( page( { paged: false } ), { voices: VOICES } );
	const lang = e.q( '[data-jimca-voice-lang]' );
	const names = [ ...e.q( '[data-jimca-voice-name]' ).options ].map( ( o ) => o.textContent );

	assert.equal( e.q( '[data-jimca-voice-group]' ).hidden, false );
	assert.equal( lang.value, 'pt-BR' );
	assert.ok( [ ...lang.options ].some( ( o ) => 'pt-PT' === o.value ) && [ ...lang.options ].some( ( o ) => 'en-US' === o.value ) );
	assert.deepEqual( names, [ 'Automatic', 'Microsoft Maria - Portuguese (Brazil)', 'Microsoft Daniel - Portuguese (Brazil)', 'Google português do Brasil' ] );

	assert.equal( e.q( '[data-jimca-voice-kind-wrap]' ).hidden, false );
	assert.deepEqual( [ ...e.q( '[data-jimca-voice-kind]' ).options ].map( ( o ) => o.value ), [ '', 'female', 'male' ] );
} );

test( 'voice settings: choosing Female lists only female voices; the narrator is used when reading, with its language', async () => {
	const e = await setup( page( { paged: false } ), { voices: VOICES } );
	const kind = e.q( '[data-jimca-voice-kind]' );

	kind.value = 'female';
	kind.dispatchEvent( new e.w.Event( 'change', { bubbles: true } ) );
	assert.deepEqual( [ ...e.q( '[data-jimca-voice-name]' ).options ].map( ( o ) => o.textContent ), [ 'Automatic', 'Microsoft Maria - Portuguese (Brazil)' ] );

	const name = e.q( '[data-jimca-voice-name]' );
	name.value = 'Microsoft Maria - Portuguese (Brazil)';
	name.dispatchEvent( new e.w.Event( 'change', { bubbles: true } ) );

	e.click( '[data-jimca-play]' );
	await wait( 5 );
	assert.equal( e.spoken[ 0 ].voice.name, 'Microsoft Maria - Portuguese (Brazil)' );
	assert.equal( e.spoken[ 0 ].lang, 'pt-BR' );
} );

test( 'voice settings: changing the language empties the narrator back to Automatic, and Automatic reads with that language', async () => {
	const e = await setup( page( { paged: false } ), { voices: VOICES } );
	const lang = e.q( '[data-jimca-voice-lang]' );

	lang.value = 'en-US';
	lang.dispatchEvent( new e.w.Event( 'change', { bubbles: true } ) );
	assert.deepEqual( [ ...e.q( '[data-jimca-voice-name]' ).options ].map( ( o ) => o.textContent ), [ 'Automatic', 'Google US English' ] );
	assert.equal( e.q( '[data-jimca-voice-kind-wrap]' ).hidden, true, 'no name tells the type: no type list' );

	e.click( '[data-jimca-play]' );
	await wait( 5 );
	// Automatic picks the best voice of the language (not the browser's default, which may be of another language).
	assert.equal( e.spoken[ 0 ].voice.name, 'Google US English' );
	assert.equal( e.spoken[ 0 ].lang, 'en-US' );
} );

test( 'without voices the voice part of the settings stays hidden (the theme part is still there)', async () => {
	const e = await setup( page( { paged: false } ), { voices: [] } );

	assert.equal( e.q( '[data-jimca-voice-group]' ).hidden, true );
	assert.ok( e.q( 'input[data-jimca-theme]' ) );
} );

test( 'parts in the bar: "2/3" follows the part, previous/next are marked at the ends, the status is said', async () => {
	const e = await setup( page() );
	const prev = e.q( '[data-jimca-pager-prev]' );
	const next = e.q( '[data-jimca-pager-next]' );

	assert.equal( prev.getAttribute( 'aria-disabled' ), 'true' );

	next.click();
	await wait( 30 );
	assert.equal( e.q( '[data-jimca-pager-count]' ).textContent, '2/3' );
	assert.equal( e.q( '[data-jimca-pager-status]' ).textContent, 'Part 2 of 3' );
	assert.equal( prev.getAttribute( 'aria-disabled' ), null );
	assert.equal( prev.getAttribute( 'data-jimca-part-link' ), '1' );
	assert.match( e.q( '[data-jimca-content]' ).textContent, /Segundo/ );

	next.click();
	await wait( 30 );
	assert.equal( e.q( '[data-jimca-pager-count]' ).textContent, '3/3' );
	assert.equal( next.getAttribute( 'aria-disabled' ), 'true' );
	assert.equal( next.hasAttribute( 'href' ), false, 'buttons never get an address' );

	// Disabled at the end: a click does nothing.
	next.click();
	await wait( 20 );
	assert.equal( e.q( '[data-jimca-pager-count]' ).textContent, '3/3' );
} );

test( 'parts in the bar: clicking the number opens the list of parts; choosing one goes there and closes the popover', async () => {
	const e = await setup( page() );

	e.click( '[data-jimca-menu-trigger="pager"]' );
	assert.equal( e.q( '[data-jimca-menu="pager"]' ).hidden, false );
	assert.equal( e.w.document.activeElement, e.q( '[data-jimca-pager-select]' ), 'the focus goes to the list' );

	const select = e.q( '[data-jimca-pager-select]' );
	select.value = '3';
	select.dispatchEvent( new e.w.Event( 'change', { bubbles: true } ) );
	await wait( 30 );

	assert.equal( e.q( '[data-jimca-pager-count]' ).textContent, '3/3' );
	assert.equal( e.q( '[data-jimca-menu="pager"]' ).hidden, true );
	assert.match( e.q( '[data-jimca-content]' ).textContent, /Terceiro/ );
} );

test( 'a document that is not in parts has no pager in the bar', async () => {
	const e = await setup( page( { paged: false } ) );

	assert.equal( e.q( '[data-jimca-pager]' ), null );
	assert.equal( e.q( '[data-jimca-menu-trigger="pager"]' ), null );
} );

test( 'hide: the whole bar goes away (and with it the open popover and the panels); the button brings it back', async () => {
	const e = await setup( page() );

	e.click( '[data-jimca-menu-trigger="nav"]' );
	e.click( '[data-jimca-search-open]' );
	e.click( '[data-jimca-hide]' );

	assert.equal( e.q( '[data-jimca-player-bar]' ).hidden, true );
	assert.equal( e.q( '[data-jimca-search]' ).hidden, true );
	assert.equal( e.q( '[data-jimca-show]' ).hidden, false );

	e.click( '[data-jimca-show]' );
	assert.equal( e.q( '[data-jimca-player-bar]' ).hidden, false );
} );

test( 'popover with controls: Tab walks through them and leaves (closing) after the last one', async () => {
	const e = await setup( page() );

	e.click( '[data-jimca-menu-trigger="font"]' );
	const buttons = [ ...e.q( '[data-jimca-menu="font"]' ).querySelectorAll( 'button' ) ];

	assert.equal( e.w.document.activeElement, buttons[ 0 ], 'opens on the first control' );

	buttons[ 1 ].focus();
	buttons[ 1 ].dispatchEvent( new e.w.KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true } ) );
	assert.equal( e.q( '[data-jimca-menu="font"]' ).hidden, false, 'still open between its controls' );

	buttons[ 2 ].focus();
	buttons[ 2 ].dispatchEvent( new e.w.KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true } ) );
	assert.equal( e.q( '[data-jimca-menu="font"]' ).hidden, true, 'Tab after the last control closes it' );
} );
