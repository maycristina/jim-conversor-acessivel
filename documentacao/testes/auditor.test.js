/**
 * Testes do auditor de semântica (assets/js/auditor.js): PROT-A11Y-CONV-001
 * (CT-001 a CT-003, CT-007) e PROT-A11Y-JS-001 (CT-001-JS, CT-002-JS).
 *
 * Os CT-004 a CT-006 (leitor de tela e leitura contínua) dependem de um leitor
 * real e estão em TESTES-E-LIMITACOES.md; aqui se confere o que o leitor do
 * próprio Jim (frontend.js) lê, que é o que muda com a correção.
 *
 * Rodar (precisa do jsdom, que não faz parte do plugin):
 *   npm install --no-save jsdom
 *   node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { JSDOM } = require( 'jsdom' );

const dom = new JSDOM( '<!doctype html><body></body>' );
globalThis.DOMParser = dom.window.DOMParser;

const { audit, parse, applyFixes } = require( '../../assets/js/auditor.js' );

const run = ( html ) => {
	const root = parse( html );
	return { root, ...audit( root ) };
};
const types = ( r ) => r.issues.map( ( i ) => i.type );
const fixed = ( html ) => {
	const r = run( html );
	return applyFixes( r.root, r.issues );
};

// Elements the reader (frontend.js) walks to read aloud.
const READABLE = 'h1, h2, h3, h4, h5, h6, p, li, blockquote, figcaption';
const readable = ( html ) => Array.from( parse( html ).querySelectorAll( READABLE ) ).map( ( n ) => n.tagName );

test( 'CT-001: "Capítulo 1" in <p><strong> is reported and becomes <h2>', () => {
	const html = '<p><strong>Capítulo 1</strong></p><p>Era uma vez um reino distante.</p>';
	const r = run( html );

	assert.deepEqual( types( r ), [ 'heading-like' ] );
	assert.equal( r.issues[ 0 ].excerpt, 'Capítulo 1' );
	assert.match( r.issues[ 0 ].suggestion, /<h2>/ );
	assert.equal( fixed( html ), '<h2>Capítulo 1</h2><p>Era uma vez um reino distante.</p>' );
} );

test( 'CT-001-JS: <p class="title" style="font-weight:bold;font-size:24px"> is a fake heading', () => {
	const r = run( '<p class="title" style="font-weight: bold; font-size: 24px;">Introdução à obra</p>' );
	assert.deepEqual( types( r ), [ 'heading-like' ] );
	assert.equal( r.issues[ 0 ].level, 'error' );
} );

test( 'CT-001: a plain sentence and a bold phrase inside a paragraph are not headings', () => {
	assert.deepEqual( types( run( '<p>Isto é uma frase normal que termina com ponto.</p>' ) ), [] );
	assert.deepEqual( types( run( '<p>Ele disse <strong>muito importante</strong> para todos nós, sem pausa.</p>' ) ), [] );
	assert.deepEqual( types( run( '<p>Uma linha curta sem ponto final</p>' ) ), [] );
} );

test( 'CT-002: text with no <p> (the Sapiens bug) is reported and wrapped', () => {
	const html = '<h2>Parte 1</h2>\n\nPrimeiro parágrafo longo do livro.\n\nSegundo parágrafo, também solto.\n\n<figure><img alt="Mapa" src="a.jpg"></figure>\n\nTerceiro.';
	const r = run( html );

	assert.ok( types( r ).includes( 'loose-text' ) );

	const out = fixed( html );
	assert.equal( parse( out ).querySelectorAll( 'p' ).length, 3 );
	assert.deepEqual( types( run( out ) ), [] );
} );

test( 'CT-002: <span> and <br> pieces are grouped into <p>', () => {
	const html = '<span>Um texto</span><br><br><span>outro texto</span>';
	const r = run( html );
	assert.deepEqual( types( r ), [ 'loose-text' ] );
	assert.equal( fixed( html ), '<p><span>Um texto</span></p><p><span>outro texto</span></p>' );
} );

test( 'CT-002: one sentence broken over several headings is joined', () => {
	const html = '<h3>Era uma vez um reino muito distante onde</h3><h3>vivia um rei cansado de tantas guerras e</h3><h3>que decidiu viajar pelo mundo inteiro.</h3>';
	const r = run( html );

	assert.deepEqual( types( r ), [ 'split-heading' ] );
	assert.equal( fixed( html ), '<p>Era uma vez um reino muito distante onde vivia um rei cansado de tantas guerras e que decidiu viajar pelo mundo inteiro.</p>' );
} );

test( 'CT-002: a heading holding a whole paragraph becomes <p>', () => {
	const long = 'Este é um parágrafo inteiro que foi marcado como título pela conversão e que continua por muitas palavras, até passar de qualquer tamanho razoável para um título.';
	assert.deepEqual( types( run( '<h2>' + long + '</h2>' ) ), [ 'long-heading' ] );
	assert.equal( fixed( '<h2>' + long + '</h2>' ), '<p>' + long + '</p>' );
} );

test( 'CT-002-JS: empty paragraphs and <br> runs', () => {
	const r = run( '<p>Texto.</p><p>&nbsp;</p><p><br></p><p>​</p><p>Fim.</p>' );
	assert.deepEqual( types( r ), [ 'empty-block', 'empty-block', 'empty-block' ] );
	assert.equal( fixed( '<p>Texto.</p><p>&nbsp;</p><p><br></p><p>Fim.</p>' ), '<p>Texto.</p><p>Fim.</p>' );

	const br = '<p>Primeiro bloco.<br><br><br>Segundo bloco.</p>';
	assert.deepEqual( types( run( br ) ), [ 'excess-br' ] );
	assert.equal( fixed( br ), '<p>Primeiro bloco.</p><p>Segundo bloco.</p>' );
} );

test( 'CT-002-JS: a single <br> (poem, address) is left alone; an image paragraph is not empty', () => {
	assert.deepEqual( types( run( '<p>Linha um<br>linha dois</p>' ) ), [] );
	assert.deepEqual( types( run( '<p><img src="a.jpg" alt="x"></p>' ) ), [] );
} );

test( 'heading levels that skip are reported and fixed', () => {
	const r = run( '<h2>A</h2><h4>B</h4><p>Texto.</p>' );
	assert.deepEqual( types( r ), [ 'heading-skip' ] );
	assert.equal( r.issues[ 0 ].level, 'warning' );
	assert.equal( fixed( '<h2>A</h2><h4>B</h4><p>Texto.</p>' ), '<h2>A</h2><h3>B</h3><p>Texto.</p>' );
} );

test( 'CT-003: a well converted page has no findings (no false positives)', () => {
	const html = [
		'<h2 class="jimca-page-title">Page 1</h2>',
		'<h2>Capítulo 1</h2>',
		'<p>Era uma vez um reino distante, onde vivia uma rainha sábia.</p>',
		'<h3>Origens</h3>',
		'<p>O texto segue por vários parágrafos.</p>',
		'<ul class="jimca-printed-toc"><li><span>Capítulo 1</span> <span>7</span></li></ul>',
		'<p class="jimca-printed-toc__heading"><strong>Sumário</strong></p>',
		'<figure class="jimca-figure"><img src="a.jpg" alt="Mapa"></figure>',
		'<blockquote><p>Citação.</p></blockquote>',
		'<table><tr><th scope="col">A</th></tr><tr><td>1</td></tr></table>',
		'<p>Poema:<br>verso um<br>verso dois</p>'
	].join( '\n' );

	assert.deepEqual( run( html ).issues, [] );
} );

test( 'fixes are idempotent: auditing the fixed HTML finds nothing', () => {
	const messy = '<p><b>Capítulo 2</b></p>Texto solto.<br><br>Mais texto.<p>&nbsp;</p><h2>A</h2><h5>B</h5>';
	const once = fixed( messy );
	assert.deepEqual( types( run( once ) ), [] );
	assert.equal( fixed( once ), once );
} );

test( 'the reader (frontend.js selector) reads nothing before the fix and everything after', () => {
	const broken = '<h2>Título</h2>\n\nTexto solto um.\n\nTexto solto dois.';
	assert.deepEqual( readable( broken ), [ 'H2' ] );
	assert.deepEqual( readable( fixed( broken ) ), [ 'H2', 'P', 'P' ] );
} );

test( 'CT-007: 60 pages (about 6000 blocks) are checked in under 2 seconds', () => {
	let html = '';
	for ( let page = 1; page <= 60; page++ ) {
		html += '<h2 class="jimca-page-title">Page ' + page + '</h2>\n';
		for ( let i = 0; i < 100; i++ ) {
			html += '<p>Parágrafo ' + i + ' da página ' + page + ', com texto o bastante para parecer real e terminar bem.</p>\n';
		}
		html += '<p><strong>Capítulo ' + page + '</strong></p>\n<p>&nbsp;</p>\n';
	}

	const t0 = process.hrtime.bigint();
	const r = run( html );
	const ms = Number( process.hrtime.bigint() - t0 ) / 1e6;

	assert.equal( r.issues.length, 120 );
	assert.ok( ms < 2000, 'took ' + ms + ' ms' );
	console.log( '  CT-007: ' + r.stats.blocks + ' blocks, ' + Math.round( ms ) + ' ms (jsdom, slower than a browser)' );
} );
