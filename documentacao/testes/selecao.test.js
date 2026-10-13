/**
 * Regressão da barra de seleção (assets/js/marker.js), rodando o script de
 * verdade num DOM (jsdom).
 *
 * Cobre: a barra aparece junto do trecho selecionado (acima no mouse, abaixo
 * no toque, sem sair da tela); "Baixar como texto" entrega só o trecho
 * selecionado; o botão de imagem existe e sem canvas avisa em vez de falhar
 * calado.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/marker.js' ), 'utf8' );

const HTML = `
<section data-jimca-viewer data-jimca-doc="9" data-jimca-title="Livro de Teste">
	<div data-jimca-content><p>Um trecho de texto para selecionar e levar.</p></div>
	<div data-jimca-marker hidden>
		<button type="button" data-jimca-share-image>Share</button>
		<button type="button" data-jimca-download-text>Download</button>
		<button type="button" data-jimca-mark="yellow">Y</button>
		<button type="button" data-jimca-marker-remove hidden>Remove</button>
		<button type="button" data-jimca-marker-clear hidden>Clear</button>
		<span data-jimca-marker-status></span>
	</div>
</section>`;

const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

async function setup( { rect = { top: 200, bottom: 220, left: 100, width: 200, height: 20 }, coarse = false } = {} ) {
	const dom = new JSDOM( HTML, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/livro/' } );
	const win = dom.window;
	const saved = [];

	win.jimcaMarkerI18n = { saved: 'Text file downloaded.', failed: 'Could not create the image.' };
	win.matchMedia = () => ( { matches: coarse } );

	// jsdom has no layout: give the selection and the bar a size.
	win.Range.prototype.getClientRects = () => [ { ...rect, right: rect.left + rect.width } ];
	win.Range.prototype.getBoundingClientRect = () => ( { ...rect, right: rect.left + rect.width } );
	Object.defineProperty( win.HTMLElement.prototype, 'offsetWidth', { get: () => 300 } );
	Object.defineProperty( win.HTMLElement.prototype, 'offsetHeight', { get: () => 50 } );

	win.URL.createObjectURL = ( blob ) => {
		saved.push( { blob } );
		return 'blob:test';
	};
	win.URL.revokeObjectURL = () => {};
	win.HTMLAnchorElement.prototype.click = function () {
		saved[ saved.length - 1 ].name = this.download;
	};

	win.eval( SOURCE );
	await wait( 10 );

	const q = ( sel ) => win.document.querySelector( sel );

	function select( from, to ) {
		const text = q( '[data-jimca-content] p' ).firstChild;
		const range = win.document.createRange();

		range.setStart( text, from );
		range.setEnd( text, to );
		win.getSelection().removeAllRanges();
		win.getSelection().addRange( range );
		win.document.dispatchEvent( new win.Event( 'selectionchange' ) );
	}

	return { win, q, saved, select };
}

const readBlob = ( win, blob ) => new Promise( ( resolve ) => {
	const reader = new win.FileReader();

	reader.onload = () => resolve( reader.result );
	reader.readAsText( blob );
} );

test( 'the bar appears right above the selected text, centered on it', async () => {
	const env = await setup();

	env.select( 3, 20 );
	await wait( 250 );

	const bar = env.q( '[data-jimca-marker]' );

	assert.equal( bar.hidden, false );
	assert.equal( bar.style.top, '140px', '200 (top of the text) - 50 (bar) - 10 (gap)' );
	assert.equal( bar.style.left, '50px', 'centered on the text: 100 + 200 / 2 - 300 / 2' );
} );

test( 'near the top of the screen the bar goes below the text instead of off screen', async () => {
	const env = await setup( { rect: { top: 20, bottom: 40, left: 100, width: 200, height: 20 } } );

	env.select( 3, 20 );
	await wait( 250 );

	assert.equal( env.q( '[data-jimca-marker]' ).style.top, '50px', 'below: 40 + 10' );
} );

test( 'on a touch screen the bar goes below the text, away from the system menu', async () => {
	const env = await setup( { coarse: true } );

	env.select( 3, 20 );
	await wait( 250 );

	assert.equal( env.q( '[data-jimca-marker]' ).style.top, '250px', 'below the handles: 220 + 30' );
} );

test( 'the bar never leaves the sides of the screen', async () => {
	const env = await setup( { rect: { top: 200, bottom: 220, left: 0, width: 40, height: 20 } } );

	env.select( 3, 10 );
	await wait( 250 );

	assert.equal( env.q( '[data-jimca-marker]' ).style.left, '8px' );
} );

test( 'download as text gives only the selected passage, with a name from the title', async () => {
	const env = await setup();

	env.select( 3, 18 );
	await wait( 250 );
	env.q( '[data-jimca-download-text]' ).click();

	assert.equal( env.saved.length, 1 );
	assert.equal( env.saved[ 0 ].name, 'Livro-de-Teste-excerpt.txt' );
	assert.equal( await readBlob( env.win, env.saved[ 0 ].blob ), 'trecho de texto\n' );
	assert.equal( env.q( '[data-jimca-marker-status]' ).textContent, 'Text file downloaded.' );
} );

test( 'share as image without canvas support says so instead of failing silently', async () => {
	const env = await setup();

	env.select( 3, 20 );
	await wait( 250 );
	env.q( '[data-jimca-share-image]' ).click();
	await wait( 50 );

	assert.equal( env.q( '[data-jimca-marker-status]' ).textContent, 'Could not create the image.' );
	assert.equal( env.saved.length, 0 );
} );
