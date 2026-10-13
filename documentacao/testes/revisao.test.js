/**
 * Regressão da revisão da conversão (assets/js/auditor.js) nas duas telas que
 * a disparam, rodando o script de verdade num DOM (jsdom) com fetch simulado.
 *
 * Cobre: o botão da lista de documentos revisa sem abrir o documento e mostra
 * o resultado; cada revisão disparada vai para o log com a origem certa
 * ("list" ou "edit"); a revisão que roda sozinha depois de uma correção não
 * entra no log; uma falha de rede avisa na tela e não registra nada.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/auditor.js' ), 'utf8' );
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

// A bold paragraph that looks like a heading, and an empty paragraph: two problems.
const BAD_HTML = '<p><strong>Capítulo 1</strong></p><p>Texto normal do capítulo.</p><p>&nbsp;</p>';
const GOOD_HTML = '<h2>Capítulo 1</h2><p>Texto normal do capítulo.</p>';

function boot( html, { content = GOOD_HTML, fail = false } = {} ) {
	const dom = new JSDOM( html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/wp-admin/' } );
	const win = dom.window;
	const calls = [];

	win.jimcaAuditI18n = { none: 'No problems.', foundOne: '%s problem found.', foundMany: '%s problems found.', reviewing: 'Reviewing…', reviewFailed: 'Could not run.', openToFix: 'Open to fix' };
	win.jimcaReview = { ajaxUrl: '/ajax', nonce: 'n1', actionContent: 'review_content', actionLog: 'review_log' };

	win.fetch = ( url, options ) => {
		const body = options.body;
		calls.push( { action: body.get( 'action' ), doc: body.get( 'doc' ), origin: body.get( 'origin' ), issues: body.get( 'issues' ), nonce: body.get( 'nonce' ) } );

		if ( fail ) {
			return Promise.reject( new Error( 'offline' ) );
		}
		if ( 'review_content' === body.get( 'action' ) ) {
			return Promise.resolve( { json: () => Promise.resolve( { success: true, data: { html: content } } ) } );
		}
		return Promise.resolve( { json: () => Promise.resolve( { success: true } ) } );
	};

	win.eval( SOURCE );
	win.document.dispatchEvent( new win.Event( 'DOMContentLoaded' ) );

	return { win, calls, q: ( sel ) => win.document.querySelector( sel ) };
}

const LIST = `
<table><tr><td>
	<button type="button" data-jimca-review="42" data-jimca-edit="/wp-admin/post.php?post=42&action=edit#jimca_documento_audit" aria-describedby="jimca-review-status-42">Review</button>
	<span id="jimca-review-status-42" role="status"></span>
</td></tr></table>`;

const EDIT = `
<input type="hidden" id="post_ID" value="42">
<textarea id="content"></textarea>
<button type="button" id="jimca-audit-run">Click here</button>
<div id="jimca-audit-result"></div>`;

test( 'list: the button reviews the document without opening it and logs the origin "list"', async () => {
	const env = boot( LIST, { content: BAD_HTML } );

	env.q( '[data-jimca-review]' ).click();
	await wait( 50 );

	assert.equal( env.calls[ 0 ].action, 'review_content' );
	assert.equal( env.calls[ 0 ].doc, '42' );
	assert.equal( env.calls[ 0 ].nonce, 'n1' );

	const log = env.calls.find( ( c ) => 'review_log' === c.action );

	assert.ok( log, 'the run is logged' );
	assert.equal( log.origin, 'list' );
	assert.equal( log.doc, '42' );
	assert.ok( Number( log.issues ) >= 1, 'with the number of problems found' );

	const status = env.q( '#jimca-review-status-42' );

	assert.match( status.textContent, /problems? found\./ );
	assert.equal( status.querySelector( 'a' ).getAttribute( 'href' ), '/wp-admin/post.php?post=42&action=edit#jimca_documento_audit' );
	assert.equal( env.q( '[data-jimca-review]' ).hasAttribute( 'aria-busy' ), false );
} );

test( 'list: a clean document says so, logs a run with zero problems and offers no link', async () => {
	const env = boot( LIST, { content: GOOD_HTML } );

	env.q( '[data-jimca-review]' ).click();
	await wait( 50 );

	assert.equal( env.q( '#jimca-review-status-42' ).textContent, 'No problems.' );
	assert.equal( env.q( '#jimca-review-status-42 a' ), null );
	assert.equal( env.calls.find( ( c ) => 'review_log' === c.action ).issues, '0' );
} );

test( 'list: when the request fails the person is told and nothing is logged', async () => {
	const env = boot( LIST, { fail: true } );

	env.q( '[data-jimca-review]' ).click();
	await wait( 50 );

	assert.equal( env.q( '#jimca-review-status-42' ).textContent, 'Could not run.' );
	assert.equal( env.calls.filter( ( c ) => 'review_log' === c.action ).length, 0 );
	assert.equal( env.q( '[data-jimca-review]' ).hasAttribute( 'aria-busy' ), false, 'the button works again' );
} );

test( 'edit screen: the review is logged with the origin "edit" and the document id', async () => {
	const env = boot( EDIT );

	env.q( '#content' ).value = BAD_HTML;
	env.q( '#jimca-audit-run' ).click();
	await wait( 30 );

	assert.equal( env.calls.length, 1 );
	assert.equal( env.calls[ 0 ].action, 'review_log' );
	assert.equal( env.calls[ 0 ].origin, 'edit' );
	assert.equal( env.calls[ 0 ].doc, '42' );
	assert.ok( env.q( '#jimca-audit-result [data-jimca-fix-all]' ), 'the problems and the fix buttons are shown' );
} );

test( 'edit screen: the review that follows a fix is not logged again', async () => {
	const env = boot( EDIT );

	env.q( '#content' ).value = BAD_HTML;
	env.q( '#jimca-audit-run' ).click();
	await wait( 30 );
	env.q( '[data-jimca-fix-all]' ).click();
	await wait( 30 );

	assert.equal( env.calls.filter( ( c ) => 'review_log' === c.action ).length, 1, 'only the review the person started' );
} );
