/**
 * Caixa "SEO and accessibility score" do documento (assets/js/seo-box.js):
 * salva cada campo por AJAX e redesenha a nota e a lista sem recarregar.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/seo-box.js' ), 'utf8' );

const BOX = ( score ) => `
	<p role="status" tabindex="-1" data-jimca-seo-status></p>
	<p><strong data-jimca-seo-score>${ score }</strong>/100</p>
	<input type="text" id="jimca_seo_author" data-jimca-seo-field="author" value="">
	<button type="button" data-jimca-seo-detect hidden>Auto-detect</button>
	<button type="button" data-jimca-seo-save="author" hidden>Save author</button>
	<input type="text" id="jimca_seo_title" data-jimca-seo-field="title" data-jimca-min="10" data-jimca-max="70" value="Curto">
	<span id="jimca_seo_title_count" data-jimca-count-for="title"></span>
	<button type="button" data-jimca-seo-save="title" hidden>Save</button>
	<button type="button" data-jimca-seo-headings hidden>Auto-fix headings</button>`;

const HTML = `
<input id="title" value="Curto">
<textarea id="content"><p>1. Intro</p><p>texto</p></textarea>
<div id="jimca-seo-box">${ BOX( 70 ) }</div>`;

const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

async function setup( reply ) {
	const dom = new JSDOM( HTML, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/wp-admin/' } );
	const win = dom.window;
	const calls = [];

	win.jimcaSeoBox = {
		ajaxUrl: '/ajax', nonce: 'n1', doc: 7, actSave: 'save', actDetect: 'detect', actFix: 'fix',
		working: 'Saving…', failed: 'Could not save.', detected: 'Suggestions filled in.', nothing: 'Nothing found.',
		tooShort: 'too short', tooLong: 'too long', characters: 'characters',
	};
	win.fetch = ( url, options ) => {
		const body = {};
		options.body.forEach( ( value, key ) => {
			body[ key ] = value;
		} );
		calls.push( body );
		return Promise.resolve( { json: () => Promise.resolve( reply( body ) ) } );
	};

	win.eval( SOURCE );
	await wait( 10 );

	const q = ( sel ) => win.document.querySelector( sel );

	return {
		win, q, calls,
		click: async ( sel ) => {
			q( sel ).dispatchEvent( new win.MouseEvent( 'click', { bubbles: true } ) );
			await wait( 20 );
		},
	};
}

test( 'the buttons stay hidden until the script runs, then show', async () => {
	const env = await setup( () => ( { success: true, data: {} } ) );

	assert.equal( env.q( '[data-jimca-seo-save="author"]' ).hidden, false );
	assert.equal( env.q( '[data-jimca-seo-detect]' ).hidden, false );
	assert.equal( env.q( '[data-jimca-seo-headings]' ).hidden, false );
} );

test( 'the counter follows what is typed and says when it is out of range', async () => {
	const env = await setup( () => ( { success: true, data: {} } ) );
	const field = env.q( '#jimca_seo_title' );

	assert.match( env.q( '#jimca_seo_title_count' ).textContent, /^5 characters \(10–70\) — too short$/ );

	field.value = 'Um título com tamanho bom';
	field.dispatchEvent( new env.win.Event( 'input', { bubbles: true } ) );
	assert.equal( env.q( '#jimca_seo_title_count' ).textContent, '25 characters (10–70)' );

	field.value = 'x'.repeat( 71 );
	field.dispatchEvent( new env.win.Event( 'input', { bubbles: true } ) );
	assert.match( env.q( '#jimca_seo_title_count' ).textContent, /too long$/ );
} );

test( 'Save author sends the field and redraws the box with the new score', async () => {
	const env = await setup( () => ( { success: true, data: { message: 'Author saved.', html: BOX( 80 ) } } ) );

	env.q( '#jimca_seo_author' ).value = 'Maria Silva';
	await env.click( '[data-jimca-seo-save="author"]' );

	assert.deepEqual( env.calls[ 0 ], { action: 'save', nonce: 'n1', doc: '7', field: 'author', value: 'Maria Silva' } );
	assert.equal( env.q( '[data-jimca-seo-score]' ).textContent, '80' );
	assert.equal( env.q( '[data-jimca-seo-status]' ).textContent, 'Author saved.' );
	assert.equal( env.q( '[data-jimca-seo-save="author"]' ).hidden, false, 'the redrawn buttons are shown again' );
} );

test( 'Save title also puts the new title in the editor, so "Update" does not bring the old one back', async () => {
	const env = await setup( () => ( { success: true, data: { message: 'Title saved.', html: BOX( 85 ), title: 'Um título com tamanho bom' } } ) );

	env.q( '#jimca_seo_title' ).value = 'Um título com tamanho bom';
	await env.click( '[data-jimca-seo-save="title"]' );

	assert.equal( env.q( '#title' ).value, 'Um título com tamanho bom' );
	assert.equal( env.calls[ 0 ].field, 'title' );
} );

test( 'Auto-fix headings sends the content of the editor and writes the answer back', async () => {
	const fixed = '<h2>1. Intro</h2><p>texto</p>';
	const env = await setup( () => ( { success: true, data: { message: '1 heading created.', html: BOX( 90 ), content: fixed } } ) );

	await env.click( '[data-jimca-seo-headings]' );

	assert.equal( env.calls[ 0 ].action, 'fix' );
	assert.equal( env.calls[ 0 ].content, '<p>1. Intro</p><p>texto</p>' );
	assert.equal( env.q( '#content' ).value, fixed );
	assert.equal( env.q( '[data-jimca-seo-score]' ).textContent, '90' );
} );

test( 'Auto-detect fills the fields with the suggestions and saves nothing', async () => {
	const env = await setup( () => ( { success: true, data: { author: 'Ricardo Diogo', title: 'Título sugerido', summary: '' } } ) );

	await env.click( '[data-jimca-seo-detect]' );

	assert.equal( env.q( '#jimca_seo_author' ).value, 'Ricardo Diogo' );
	assert.equal( env.q( '#jimca_seo_title' ).value, 'Título sugerido' );
	assert.equal( env.calls.length, 1 );
	assert.equal( env.calls[ 0 ].action, 'detect' );
	assert.equal( env.q( '[data-jimca-seo-status]' ).textContent, 'Suggestions filled in.' );
} );

test( 'Auto-detect says so when there is nothing to suggest', async () => {
	const env = await setup( () => ( { success: true, data: { author: '', title: '', summary: '' } } ) );

	await env.click( '[data-jimca-seo-detect]' );

	assert.equal( env.q( '[data-jimca-seo-status]' ).textContent, 'Nothing found.' );
} );

test( 'an error from the server is shown and the buttons come back', async () => {
	const env = await setup( () => ( { success: false, data: { message: 'No lines found.' } } ) );

	await env.click( '[data-jimca-seo-headings]' );

	assert.equal( env.q( '[data-jimca-seo-status]' ).textContent, 'No lines found.' );
	assert.equal( env.q( '[data-jimca-seo-headings]' ).disabled, false );
} );
