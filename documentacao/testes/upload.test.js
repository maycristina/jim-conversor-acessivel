/**
 * Regressão do envio de documentos (assets/js/admin.js), rodando o script de
 * verdade num DOM (jsdom) com um fetch simulado.
 *
 * Cobre: o arquivo vai com nome neutro e o nome real vai codificado (firewalls
 * recusam, com 403, nomes com palavras); um arquivo por requisição, um depois
 * do outro; o 403 do servidor vira aviso na tela com o nome do arquivo; um
 * bloqueio não derruba os outros arquivos.
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
	<input type="text" name="jimca_title" value="">
	<input type="file" id="jimca_file" name="jimca_file[]" multiple>
	<button type="submit">Convert</button>
</form>
<div id="jimca-file-toast" hidden></div>
<div id="jimca-upload-progress" hidden>
	<span data-jimca-progress-spinner></span>
	<strong data-jimca-progress-title></strong>
	<span data-jimca-progress-hint></span>
	<progress max="100" value="0" data-jimca-progress-bar hidden></progress>
	<span data-jimca-progress-detail></span>
	<span data-jimca-progress-live></span>
</div>`;

const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const decodeName = ( encoded ) => Buffer.from( encoded.replace( /-/g, '+' ).replace( /_/g, '/' ), 'base64' ).toString( 'utf8' );

/**
 * @param {string[]} names  files the person picked
 * @param {(call: {action: string, name: string}) => object|null} reply  JSON answer, or null for a firewall 403
 */
async function setup( names, reply ) {
	const dom = new JSDOM( HTML, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/wp-admin/' } );
	const win = dom.window;
	const calls = [];

	win.TextEncoder = TextEncoder;
	win.jimcaAdminI18n = {
		ajaxUrl: '/ajax', actionStart: 'start', actionRun: 'run', actionStatus: 'status',
		blocked: 'Firewall blocked (HTTP %s).', fileOf: 'File %1$s of %2$s: %3$s',
		queueSummary: '%1$s of %2$s converted.', viewList: 'View documents', listUrl: '/wp-admin/list',
	};

	win.fetch = ( url, options ) => {
		const body = options.body;
		const call = { action: body.get( 'action' ), job: body.get( 'job' ), body };
		calls.push( call );
		const answer = reply( call );

		if ( null === answer ) {
			return Promise.resolve( {
				status: 403, url: '/ajax',
				json: () => Promise.reject( new Error( 'not json' ) ),
				clone: () => ( { text: () => Promise.resolve( '<html>403 Forbidden</html>' ) } ),
			} );
		}
		return Promise.resolve( { status: 200, json: () => Promise.resolve( answer ) } );
	};

	win.eval( SOURCE );
	// The script starts on DOMContentLoaded when the document is still loading.
	await wait( 10 );

	const files = names.map( ( name ) => new win.File( [ 'conteudo' ], name, { type: 'text/plain' } ) );
	Object.defineProperty( win.document.getElementById( 'jimca_file' ), 'files', { value: files } );

	const q = ( sel ) => win.document.querySelector( sel );

	return {
		win, q, calls,
		submit: () => q( '#jimca-upload-form' ).dispatchEvent( new win.Event( 'submit', { cancelable: true, bubbles: true } ) ),
	};
}

// Plugin answers: start gives a job, run finishes it at once.
const happy = ( call ) => {
	if ( 'start' === call.action ) {
		return { success: true, data: { job: 'job-' + call.body.get( 'jimca_name' ).slice( 0, 6 ) } };
	}
	return { success: true, data: { status: 'done', redirect: '/wp-admin/panel', post: { title: 'Doc', edit: '/wp-admin/post.php?post=1', shortcode: '[jim id="1"]' } } };
};

test( 'the file goes with a neutral name and the real name travels encoded', async () => {
	const env = await setup( [ "Pêcheur d'Islande.txt" ], happy );

	env.submit();
	await wait( 50 );

	const start = env.calls.find( ( c ) => 'start' === c.action );
	const sent = start.body.get( 'jimca_file' );

	assert.match( sent.name, /^jim-1-[a-z0-9]+\.txt$/, 'no word of the real name reaches the firewall' );
	assert.equal( decodeName( start.body.get( 'jimca_name' ) ), "Pêcheur d'Islande.txt" );
	assert.equal( start.body.getAll( 'jimca_file[]' ).length, 0, 'the multi-file field is not sent' );
} );

test( 'several files go one per request, one after the other, with their position', async () => {
	const env = await setup( [ 'a.txt', 'b.txt', 'c.txt' ], happy );

	env.submit();
	await wait( 80 );

	const starts = env.calls.filter( ( c ) => 'start' === c.action );

	assert.equal( starts.length, 3 );
	assert.deepEqual( starts.map( ( c ) => decodeName( c.body.get( 'jimca_name' ) ) ), [ 'a.txt', 'b.txt', 'c.txt' ] );
	assert.deepEqual( starts.map( ( c ) => c.body.get( 'jimca_index' ) ), [ '0', '1', '2' ] );
	assert.ok( starts.every( ( c ) => 1 === c.body.getAll( 'jimca_file' ).length ) );
	assert.equal( env.q( '[data-jimca-progress-title]' ).textContent, '3 of 3 converted.' );
	assert.equal( env.q( '[data-jimca-progress-detail] a[href="/wp-admin/list"]' ).textContent, 'View documents' );
} );

test( 'a firewall 403 is shown on screen with the file name, and the other files still go', async () => {
	const env = await setup( [ 'bom.txt', 'bloqueado.txt', 'outro.txt' ], ( call ) => {
		if ( 'start' === call.action && 'bloqueado.txt' === decodeName( call.body.get( 'jimca_name' ) ) ) {
			return null;
		}
		return happy( call );
	} );

	env.submit();
	await wait( 80 );

	const detail = env.q( '[data-jimca-progress-detail]' );

	assert.equal( env.q( '[data-jimca-progress-title]' ).textContent, '2 of 3 converted.' );
	assert.match( detail.textContent, /bloqueado\.txt: Firewall blocked \(HTTP 403\)\./ );
	assert.equal( detail.querySelectorAll( 'li' ).length, 3 );
} );

test( 'one file that works goes straight to the result', async () => {
	const env = await setup( [ 'unico.txt' ], happy );
	// jsdom cannot navigate; the script announces the redirect in the title first.
	env.submit();
	await wait( 50 );

	assert.equal( env.q( '[data-jimca-progress-title]' ).textContent, 'Conversion finished. Opening the result…' );
} );

test( 'one file that is blocked shows the firewall message', async () => {
	const env = await setup( [ 'unico.txt' ], () => null );

	env.submit();
	await wait( 50 );

	assert.match( env.q( '[data-jimca-progress-detail]' ).textContent, /Firewall blocked \(HTTP 403\)\./ );
	assert.ok( env.q( '#jimca-upload-progress' ).classList.contains( 'is-failed' ) );
} );

test( 'more files than the limit are refused before sending any', async () => {
	const names = Array.from( { length: 21 }, ( _, i ) => 'f' + i + '.txt' );
	const env = await setup( names, happy );

	env.win.jimcaAdminI18n.maxFiles = 20;
	env.win.jimcaAdminI18n.tooMany = 'Choose up to %s files at a time.';
	env.submit();
	await wait( 50 );

	assert.equal( env.calls.length, 0, 'nothing is uploaded' );
	assert.match( env.q( '[data-jimca-progress-detail]' ).textContent, /Choose up to 20 files at a time\./ );
} );

test( 'a file over the size limit is refused by name without uploading it; the others still go', async () => {
	const env = await setup( [ 'pequeno.txt', 'enorme.pdf' ], happy );
	const files = env.win.document.getElementById( 'jimca_file' ).files;

	Object.defineProperty( files[ 1 ], 'size', { value: 60 * 1024 * 1024 } );
	env.win.jimcaAdminI18n.maxBytes = 30 * 1024 * 1024;
	env.win.jimcaAdminI18n.maxPdfBytes = 42 * 1024 * 1024;
	env.win.jimcaAdminI18n.tooBig = 'This file is %1$s; the limit here is %2$s.';
	env.submit();
	await wait( 80 );

	const starts = env.calls.filter( ( c ) => 'start' === c.action );

	assert.equal( starts.length, 1, 'only the small file was uploaded' );
	assert.match( env.q( '[data-jimca-progress-detail]' ).textContent, /enorme\.pdf: This file is 60\.0 MB; the limit here is 30\.0 MB\./ );
	assert.equal( env.q( '[data-jimca-progress-title]' ).textContent, '1 of 2 converted.' );
} );

test( 'a PDF over what the server memory can convert is refused even under the size setting', async () => {
	const env = await setup( [ 'livro.pdf', 'ok.txt' ], happy );
	const files = env.win.document.getElementById( 'jimca_file' ).files;

	Object.defineProperty( files[ 0 ], 'size', { value: 45 * 1024 * 1024 } );
	env.win.jimcaAdminI18n.maxBytes = 100 * 1024 * 1024;
	env.win.jimcaAdminI18n.maxPdfBytes = 42 * 1024 * 1024;
	env.win.jimcaAdminI18n.tooBig = 'This file is %1$s; the limit here is %2$s.';
	env.submit();
	await wait( 80 );

	assert.match( env.q( '[data-jimca-progress-detail]' ).textContent, /livro\.pdf: This file is 45\.0 MB; the limit here is 42\.0 MB\./ );
	assert.equal( env.calls.filter( ( c ) => 'start' === c.action ).length, 1 );
} );

test( 'a document with unreadable text is reported in the summary', async () => {
	const env = await setup( [ 'a.txt', 'b.txt' ], ( call ) => {
		if ( 'run' === call.action ) {
			return { success: true, data: { status: 'done', redirect: '/x', post: { title: 'Biblia', edit: '/e', shortcode: '[jim id="2"]', unreadable: true } } };
		}
		return happy( call );
	} );

	env.win.jimcaAdminI18n.unreadableNote = 'Created, but the text is unreadable.';
	env.submit();
	await wait( 80 );

	assert.match( env.q( '[data-jimca-progress-detail]' ).textContent, /Created, but the text is unreadable\./ );
} );
