/**
 * Lista dos arquivos escolhidos na tela "Novo documento" (assets/js/admin.js,
 * initFileList): nome, tamanho em MB e um selo "Válido" ou "Erro" por arquivo,
 * antes de qualquer envio; o botão de converter fica desligado e o envio é
 * barrado enquanto houver arquivo que não pode ser convertido.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/admin.js' ), 'utf8' );

const HTML = `
<form id="jimca-upload-form">
	<input type="hidden" name="jimca_upload_nonce" value="abc123">
	<input type="file" id="jimca_file" name="jimca_file[]" multiple>
	<input type="hidden" id="jimca_expected_files" value="0">
	<div data-jimca-file-list hidden>
		<p role="status" data-jimca-file-summary></p>
		<ul data-jimca-file-items></ul>
	</div>
	<button type="submit">Convert</button>
</form>
<div id="jimca-file-toast" hidden></div>
<div id="jimca-upload-progress" hidden>
	<span data-jimca-progress-spinner></span><strong data-jimca-progress-title></strong>
	<span data-jimca-progress-hint></span><progress max="100" value="0" data-jimca-progress-bar hidden></progress>
	<span data-jimca-progress-detail></span><span data-jimca-progress-live></span>
</div>`;

const MB = 1048576;
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * @param {{name: string, size: number}[]} specs files the person picked
 */
async function setup( specs, extra = {} ) {
	const dom = new JSDOM( HTML, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/wp-admin/' } );
	const win = dom.window;
	const fetched = [];

	win.TextEncoder = TextEncoder;
	win.jimcaAdminI18n = Object.assign( {
		maxBytes: 10 * MB, maxPdfBytes: 0, maxFiles: 3, allowedTypes: [ 'pdf', 'docx', 'txt', 'md' ],
		fileValid: 'Valid', fileInvalid: 'Error',
		badType: 'This type is not accepted. Use: %s.', emptyFile: 'The file is empty (0 bytes).',
		tooBig: 'This file is %1$s; the limit here is %2$s.', beyondLimit: 'Over the limit of %s files per upload.',
		listReady: '%s files ready to convert.', listReadyOne: '1 file ready to convert.',
		listProblems: '%1$s of %2$s files cannot be converted. Remove them to continue.',
	}, extra );
	win.fetch = ( url, options ) => {
		fetched.push( options );
		return Promise.reject( new Error( 'no network in this test' ) );
	};

	win.eval( SOURCE );
	await wait( 10 );

	const files = specs.map( ( spec ) => {
		const file = new win.File( [ 'x' ], spec.name, { type: 'text/plain' } );
		Object.defineProperty( file, 'size', { value: spec.size } );
		return file;
	} );
	const input = win.document.getElementById( 'jimca_file' );

	Object.defineProperty( input, 'files', { value: files, configurable: true } );
	input.dispatchEvent( new win.Event( 'change', { bubbles: true } ) );

	const q = ( sel ) => win.document.querySelector( sel );

	return {
		win, q, fetched,
		items: () => Array.from( win.document.querySelectorAll( '[data-jimca-file-items] li' ) ),
		button: () => q( 'button[type="submit"]' ),
		submit: () => {
			const event = new win.Event( 'submit', { cancelable: true, bubbles: true } );
			q( '#jimca-upload-form' ).dispatchEvent( event );
			return event;
		},
	};
}

test( 'valid files are listed with name, size in MB and a "Valid" badge', async () => {
	const env = await setup( [ { name: 'a.pdf', size: 2.5 * MB }, { name: 'b.docx', size: 1024 } ] );
	const items = env.items();

	assert.equal( items.length, 2 );
	assert.match( items[ 0 ].textContent, /a\.pdf/ );
	assert.match( items[ 0 ].textContent, /2[.,]50 MB/ );
	assert.match( items[ 0 ].textContent, /Valid/ );
	assert.match( items[ 1 ].textContent, /0[.,]00 MB/ );
	assert.equal( env.q( '[data-jimca-file-summary]' ).textContent, '2 files ready to convert.' );
	assert.equal( env.q( '[data-jimca-file-list]' ).hidden, false );
	assert.equal( env.button().disabled, false );
} );

test( 'a type that is not accepted gets an "Error" badge with the reason, and blocks the button', async () => {
	const env = await setup( [ { name: 'ok.txt', size: 100 }, { name: 'virus.exe', size: 100 } ] );
	const items = env.items();

	assert.match( items[ 0 ].textContent, /Valid/ );
	assert.match( items[ 1 ].textContent, /Error/ );
	assert.match( items[ 1 ].textContent, /not accepted. Use: PDF, DOCX, TXT, MD/ );
	assert.equal( env.button().disabled, true );
	assert.match( env.q( '[data-jimca-file-summary]' ).textContent, /1 of 2 files cannot be converted/ );
} );

test( 'a file over the size limit is an error and says both sizes', async () => {
	const env = await setup( [ { name: 'big.pdf', size: 11 * MB } ] );

	assert.match( env.items()[ 0 ].textContent, /Error/ );
	assert.match( env.items()[ 0 ].textContent, /11[.,]00 MB; the limit here is 10[.,]00 MB/ );
	assert.equal( env.button().disabled, true );
} );

test( 'a PDF is held to what the server memory converts, other types are not', async () => {
	const env = await setup( [ { name: 'a.pdf', size: 6 * MB }, { name: 'b.txt', size: 6 * MB } ], { maxPdfBytes: 5 * MB } );

	assert.match( env.items()[ 0 ].textContent, /Error/ );
	assert.match( env.items()[ 1 ].textContent, /Valid/ );
} );

test( 'an empty file is an error', async () => {
	const env = await setup( [ { name: 'empty.txt', size: 0 } ] );

	assert.match( env.items()[ 0 ].textContent, /empty \(0 bytes\)/ );
} );

test( 'files beyond the per-upload limit are errors', async () => {
	const env = await setup( [ 1, 2, 3, 4, 5 ].map( ( n ) => ( { name: n + '.txt', size: 10 } ) ) );

	assert.match( env.items()[ 2 ].textContent, /Valid/ );
	assert.match( env.items()[ 3 ].textContent, /Over the limit of 3 files/ );
	assert.equal( env.button().disabled, true );
} );

test( 'while a file is invalid the form is not sent', async () => {
	const env = await setup( [ { name: 'virus.exe', size: 100 } ] );
	const event = env.submit();

	await wait( 20 );
	assert.equal( event.defaultPrevented, true );
	assert.equal( env.fetched.length, 0, 'nothing was uploaded' );
} );

test( 'the markdown extension .markdown counts as md', async () => {
	const env = await setup( [ { name: 'notes.markdown', size: 100 } ] );

	assert.match( env.items()[ 0 ].textContent, /Valid/ );
} );

test( 'the badges have text and an icon hidden from screen readers (never color alone)', async () => {
	const env = await setup( [ { name: 'a.txt', size: 10 }, { name: 'b.exe', size: 10 } ] );

	env.items().forEach( ( li ) => {
		const badge = li.querySelector( '.jimca-file-badge' );
		assert.ok( badge.textContent.replace( /[✓✕]/g, '' ).trim().length > 0 );
		assert.equal( badge.querySelector( '[aria-hidden="true"]' ).textContent.length, 1 );
	} );
} );
