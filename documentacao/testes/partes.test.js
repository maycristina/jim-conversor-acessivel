/**
 * Regressão dos documentos longos em partes e da busca (assets/js/pager.js),
 * rodando o script de verdade num DOM (jsdom) com fetch simulado.
 *
 * Cobre: a navegação troca a parte sem recarregar a página e avisa o resto do
 * leitor (evento jimca:content-changed); só as últimas partes ficam na memória;
 * a busca no servidor abre a parte do resultado e leva ao parágrafo certo, mesmo
 * quando vários começam igual; a busca local de um documento curto; limite de
 * buscas; a leitura em voz alta pode pedir a parte seguinte.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/pager.js' ), 'utf8' );
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const PARTS = {
	1: '<h2 id="a">Gênesis</h2><p>Primeiro texto do livro.</p>',
	2: '<h2 id="b">Êxodo</h2><p>Então Moisés falou ao povo.</p><p>Amém. Fim do capítulo.</p><p>Amém. Fim do capítulo.</p>',
	3: '<p>Última parte.</p>',
	4: '<p>Quarta.</p>',
	5: '<p>Quinta.</p>',
};

const NAV = `
<nav data-jimca-pager>
	<a data-jimca-pager-prev aria-disabled="true" class="is-disabled">Previous</a>
	<span data-jimca-pager-status role="status"></span>
	<a data-jimca-pager-next rel="next" href="/livro/?jimca_part_5=2" data-jimca-part-link="2">Next</a>
	<span data-jimca-pager-jump hidden><select data-jimca-pager-select>${ [ 1, 2, 3, 4, 5 ].map( ( n ) => `<option value="${ n }">${ n }</option>` ).join( '' ) }</select></span>
</nav>`;

const SEARCH = `
<form data-jimca-search hidden>
	<input type="search" data-jimca-search-input>
	<button type="submit">Search</button>
	<p data-jimca-search-status></p>
	<div data-jimca-search-results></div>
</form>`;

const PAGED = `<section data-jimca-viewer data-jimca-doc="5" data-jimca-parts="5" data-jimca-part="1" data-jimca-part-arg="jimca_part_5">${ SEARCH }${ NAV }<div data-jimca-content tabindex="-1">${ PARTS[ 1 ] }</div>${ NAV }</section>`;
const SHORT = `<section data-jimca-viewer data-jimca-doc="6">${ SEARCH }<div data-jimca-content tabindex="-1"><h2>Título</h2><p>O rio Jordão corre.</p><p>Moisés e o rio.</p></div></section>`;

async function setup( html, { search } = {} ) {
	const dom = new JSDOM( html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/livro/' } );
	const win = dom.window;
	const requests = [];

	win.Element.prototype.scrollIntoView = () => {};
	win.jimcaPagerI18n = {
		ajaxUrl: '/ajax', actionPart: 'part', actionSearch: 'search',
		partOf: 'Part %1$s of %2$s', loadFailed: 'Could not load.', searching: 'Searching…', tooShort: 'Too short.',
		searchFailed: 'Search failed.', searchLimit: 'Too many.', noResults: 'No results.', resultsOne: '%s result.', resultsMany: '%s results.', showMore: 'More',
	};

	win.fetch = ( url ) => {
		const query = new win.URL( url, 'https://example.org' ).searchParams;
		requests.push( Object.fromEntries( query ) );

		if ( 'part' === query.get( 'action' ) ) {
			const html = PARTS[ query.get( 'part' ) ];

			return Promise.resolve( { status: html ? 200 : 404, json: () => Promise.resolve( html ? { success: true, data: { html, part: Number( query.get( 'part' ) ), count: 5 } } : { success: false } ) } );
		}

		return search ? search( query ) : Promise.resolve( { status: 200, json: () => Promise.resolve( { success: false } ) } );
	};

	win.eval( SOURCE );
	await wait( 10 );

	return { win, requests, q: ( sel ) => win.document.querySelector( sel ), all: ( sel ) => win.document.querySelectorAll( sel ) };
}

test( 'next part: the text is replaced in the page, the nav and the address follow, and the rest of the reader is told', async () => {
	const env = await setup( PAGED );
	const events = [];

	env.q( '[data-jimca-viewer]' ).addEventListener( 'jimca:content-changed', ( e ) => events.push( e.detail ) );
	env.q( '[data-jimca-pager-next]' ).click();
	await wait( 30 );

	assert.match( env.q( '[data-jimca-content]' ).textContent, /Moisés falou ao povo/ );
	assert.doesNotMatch( env.q( '[data-jimca-content]' ).textContent, /Primeiro texto/, 'only the part on screen is in the page' );
	assert.equal( env.q( '[data-jimca-viewer]' ).getAttribute( 'data-jimca-part' ), '2' );
	assert.equal( env.q( '[data-jimca-pager-status]' ).textContent, 'Part 2 of 5' );
	assert.equal( env.all( '[data-jimca-pager-status]' )[ 1 ].textContent, 'Part 2 of 5', 'the nav below too' );
	assert.equal( env.win.location.search, '?jimca_part_5=2' );
	assert.deepEqual( JSON.parse( JSON.stringify( events ) ), [ { part: 2, auto: false } ] );
	assert.equal( env.q( '[data-jimca-pager-prev]' ).hasAttribute( 'href' ), true, 'previous is available now' );
	assert.equal( env.q( '[data-jimca-pager-prev]' ).getAttribute( 'aria-disabled' ), null );
} );

test( 'the "go to part" list is shown and changes the part', async () => {
	const env = await setup( PAGED );
	const select = env.q( '[data-jimca-pager-select]' );

	assert.equal( env.q( '[data-jimca-pager-jump]' ).hidden, false );
	select.value = '3';
	select.dispatchEvent( new env.win.Event( 'change', { bubbles: true } ) );
	await wait( 30 );

	assert.match( env.q( '[data-jimca-content]' ).textContent, /Última parte/ );
	assert.equal( env.q( '[data-jimca-pager-next]' ).hasAttribute( 'href' ), true );
} );

test( 'the end of the book switches "next" off', async () => {
	const env = await setup( PAGED );

	await env.q( '[data-jimca-viewer]' ).jimcaPager.goTo( 5 );

	assert.equal( env.q( '[data-jimca-pager-next]' ).getAttribute( 'aria-disabled' ), 'true' );
	assert.equal( env.q( '[data-jimca-pager-next]' ).hasAttribute( 'href' ), false );
	assert.equal( env.q( '[data-jimca-viewer]' ).jimcaPager.hasNext(), false );
} );

test( 'goTo with a heading id opens that part and goes to the heading', async () => {
	const env = await setup( PAGED );

	await env.q( '[data-jimca-viewer]' ).jimcaPager.goTo( 2, { id: 'b' } );

	assert.ok( env.win.document.getElementById( 'b' ).classList.contains( 'jimca-found' ), 'the heading was reached and flagged' );
} );

test( 'reading aloud can ask for the next part and is not stopped by it (auto)', async () => {
	const env = await setup( PAGED );
	const pager = env.q( '[data-jimca-viewer]' ).jimcaPager;
	const events = [];

	env.q( '[data-jimca-viewer]' ).addEventListener( 'jimca:content-changed', ( e ) => events.push( e.detail ) );
	await pager.next( { focus: false, auto: true } );

	assert.deepEqual( JSON.parse( JSON.stringify( events ) ), [ { part: 2, auto: true } ] );
	assert.equal( pager.current(), 2 );
} );

test( 'only the nearby parts are kept in memory while reading through the book', async () => {
	const env = await setup( PAGED );
	const pager = env.q( '[data-jimca-viewer]' ).jimcaPager;

	for ( const n of [ 2, 3, 4, 5 ] ) {
		await pager.goTo( n, { focus: false } );
	}
	// Going back to part 2 has to ask the server again: it was let go.
	const before = env.requests.filter( ( r ) => 'part' === r.action && '2' === r.part ).length;

	await pager.goTo( 2, { focus: false } );

	assert.equal( env.requests.filter( ( r ) => 'part' === r.action && '2' === r.part ).length, before + 1 );
} );

test( 'a part that cannot be loaded is reported and the current text stays', async () => {
	const env = await setup( PAGED );

	await env.q( '[data-jimca-viewer]' ).jimcaPager.goTo( 4 ).catch( () => {} );
	env.win.fetch = () => Promise.resolve( { status: 500, json: () => Promise.resolve( { success: false } ) } );
	await env.q( '[data-jimca-viewer]' ).jimcaPager.goTo( 3 ).catch( () => {} );

	assert.equal( env.q( '[data-jimca-pager-status]' ).textContent, 'Could not load.' );
	assert.match( env.q( '[data-jimca-content]' ).textContent, /Quarta/ );
} );

test( 'search in a document in parts: results come from the server and a click opens the part and the paragraph', async () => {
	const answer = {
		total: 3,
		results: [
			{ part: 2, title: 'Êxodo', block: 'Então Moisés falou ao povo.', dup: 0, before: 'Então ', match: 'Moisés', after: ' falou ao povo.' },
			{ part: 2, title: 'Êxodo', block: 'Amém. Fim do capítulo.', dup: 1, before: '', match: 'Amém', after: '. Fim do capítulo.' },
		],
	};
	const env = await setup( PAGED, { search: () => Promise.resolve( { status: 200, json: () => Promise.resolve( { success: true, data: answer } ) } ) } );

	assert.equal( env.q( '[data-jimca-search]' ).hidden, false );
	env.q( '[data-jimca-search-input]' ).value = 'moises';
	env.q( '[data-jimca-search]' ).dispatchEvent( new env.win.Event( 'submit', { cancelable: true } ) );
	await wait( 30 );

	assert.equal( env.q( '[data-jimca-search-status]' ).textContent, '3 results.' );
	assert.equal( env.all( '.jimca-search__result' ).length, 2 );
	assert.equal( env.q( '.jimca-search__result mark' ).textContent, 'Moisés' );
	assert.ok( env.q( '.jimca-search__more' ), '"show more" while there are more results than shown' );

	// The second result: the second paragraph that starts with "Amém." in that part.
	env.all( '.jimca-search__result' )[ 1 ].click();
	await wait( 60 );

	const found = env.q( '.jimca-found' );

	assert.equal( env.q( '[data-jimca-viewer]' ).getAttribute( 'data-jimca-part' ), '2' );
	assert.ok( found, 'the paragraph is flagged' );
	assert.equal( found, env.all( '[data-jimca-content] p' )[ 2 ], 'the right one of the two that start alike' );
} );

test( 'search sends the query, the document and the offset; "show more" asks for the next ones', async () => {
	const seen = [];
	const env = await setup( PAGED, {
		search: ( query ) => {
			seen.push( Object.fromEntries( query ) );
			return Promise.resolve( { status: 200, json: () => Promise.resolve( { success: true, data: { total: 25, results: Array.from( { length: 20 }, ( _, i ) => ( { part: 1, title: '', block: 'x' + i, dup: 0, before: '', match: 'a', after: '' } ) ) } } ) } );
		},
	} );

	env.q( '[data-jimca-search-input]' ).value = 'água';
	env.q( '[data-jimca-search]' ).dispatchEvent( new env.win.Event( 'submit', { cancelable: true } ) );
	await wait( 30 );
	env.q( '.jimca-search__more' ).click();
	await wait( 30 );

	assert.deepEqual( seen.map( ( s ) => [ s.doc, s.q, s.offset ] ), [ [ '5', 'água', '0' ], [ '5', 'água', '20' ] ] );
	assert.equal( env.all( '.jimca-search__result' ).length, 40, 'the new ones are added to the list' );
} );

test( 'search: too short, no results, and the limit of searches are said in words', async () => {
	let mode = 'empty';
	const env = await setup( PAGED, {
		search: () => ( 'rate' === mode
			? Promise.resolve( { status: 429, json: () => Promise.resolve( { success: false } ) } )
			: Promise.resolve( { status: 200, json: () => Promise.resolve( { success: true, data: { total: 0, results: [] } } ) } ) ),
	} );
	const submit = () => env.q( '[data-jimca-search]' ).dispatchEvent( new env.win.Event( 'submit', { cancelable: true } ) );

	env.q( '[data-jimca-search-input]' ).value = 'a';
	submit();
	assert.equal( env.q( '[data-jimca-search-status]' ).textContent, 'Too short.' );

	env.q( '[data-jimca-search-input]' ).value = 'zzz';
	submit();
	await wait( 30 );
	assert.equal( env.q( '[data-jimca-search-status]' ).textContent, 'No results.' );

	mode = 'rate';
	submit();
	await wait( 30 );
	assert.equal( env.q( '[data-jimca-search-status]' ).textContent, 'Too many.' );
} );

test( 'search in a short document looks at the page, ignoring case and accents, and goes to the paragraph', async () => {
	const env = await setup( SHORT );

	env.q( '[data-jimca-search-input]' ).value = 'MOISES';
	env.q( '[data-jimca-search]' ).dispatchEvent( new env.win.Event( 'submit', { cancelable: true } ) );

	assert.equal( env.q( '[data-jimca-search-status]' ).textContent, '1 result.' );
	assert.equal( env.requests.length, 0, 'nothing is asked of the server' );

	env.q( '.jimca-search__result' ).click();

	assert.equal( env.q( '.jimca-found' ).textContent, 'Moisés e o rio.' );
} );
