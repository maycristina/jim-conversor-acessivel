/**
 * Ouvir no modo post (assets/js/post.js + speech.js), rodando os scripts de
 * verdade num DOM (jsdom) com um sintetizador simulado: a mesma escolha de voz
 * e a mesma divisão em pedaços de até 220 caracteres do leitor completo
 * (frontend.js), com o parágrafo destacado uma vez enquanto seus pedaços são
 * lidos.
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
const SPEECH = fs.readFileSync( path.join( ROOT, 'assets/js/speech.js' ), 'utf8' );
const POST = fs.readFileSync( path.join( ROOT, 'assets/js/post.js' ), 'utf8' );
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const VOICES = [
	{ name: 'Microsoft Maria - Portuguese (Brazil)', lang: 'pt-BR', localService: true },
	{ name: 'Joana', lang: 'pt-PT', localService: true },
	{ name: 'Milena', lang: 'ru-RU', localService: true },
	{ name: 'Google русский', lang: 'ru-RU', localService: false },
	{ name: 'Lekha', lang: 'hi-IN', localService: true },
	{ name: 'Ting-Ting', lang: 'zh-CN', localService: true },
];

/**
 * @param {object}   o
 * @param {string}   o.docLang    data-doc-lang of the document ('' = none)
 * @param {string[]} o.paragraphs texts of the paragraphs
 * @param {Array}    o.voices
 * @param {boolean}  o.speech     load speech.js (false: a script optimizer dropped it)
 */
async function setup( { docLang = 'pt-BR', paragraphs = [ 'Um parágrafo curto.' ], voices = VOICES, speech = true } = {} ) {
	const dom = new JSDOM(
		`<html lang="pt-BR"><body><section data-jimca-viewer${ docLang ? ` data-doc-lang="${ docLang }"` : '' }>
			<div data-jimca-postbar data-rate="1" data-listen="Listen" data-pause="Pause" data-resume="Resume"
				data-unsupported="This browser does not support reading aloud." data-done="Reading finished.">
				<div data-jimca-postbar-actions hidden>
					<button type="button" data-jimca-post-listen aria-pressed="false">
						<span data-jimca-icon="play"></span><span data-jimca-icon="pause" hidden></span>
						<span data-jimca-post-listen-label>Listen</span>
					</button>
				</div>
				<p data-jimca-post-status></p>
			</div>
			<div data-jimca-content lang="${ docLang.split( '-' )[ 0 ] }">${ paragraphs.map( ( p ) => `<p>${ p }</p>` ).join( '' ) }</div>
		</section></body></html>`,
		{ runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' }
	);
	const w = dom.window;
	const spoken = [];
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
			setTimeout( () => {
				if ( current !== u ) {
					return;
				}
				u.onstart && u.onstart();
				// What is marked while this piece is heard.
				spoken.push( { u, marked: [ ...w.document.querySelectorAll( '.jimca-reading' ) ].map( ( el ) => el.textContent ) } );
				setTimeout( () => {
					if ( current === u ) {
						current = null;
						u.onend && u.onend();
					}
				}, 1 );
			}, 1 );
		},
		cancel() {
			current = null;
		},
	};

	if ( speech ) {
		w.eval( SPEECH );
	}
	w.eval( POST );
	await wait( 10 );

	const status = () => w.document.querySelector( '[data-jimca-post-status]' ).textContent;

	return {
		w,
		button: () => w.document.querySelector( '[data-jimca-post-listen]' ),
		status,
		/** Clicks Listen and waits until the whole post was spoken. */
		async read() {
			w.document.querySelector( '[data-jimca-post-listen]' ).click();
			for ( let i = 0; i < 400 && 'Reading finished.' !== status(); i++ ) {
				await wait( 5 );
			}
			return spoken;
		},
	};
}

const texts = ( spoken ) => spoken.map( ( s ) => s.u.text );
const joined = ( spoken, sep = ' ' ) => texts( spoken ).join( sep ).replace( /\s+/g, ' ' ).trim();

test( 'post mode: the document language picks the voice, the better one of that language', async () => {
	const spoken = await ( await setup( { docLang: 'ru-RU', paragraphs: [ 'Это короткий абзац.' ] } ) ).read();

	assert.equal( spoken[ 0 ].u.voice.name, 'Google русский' );
	assert.equal( spoken[ 0 ].u.lang, 'ru-RU' );
} );

test( 'post mode: the exact region beats the same language', async () => {
	assert.equal( ( await ( await setup( { docLang: 'pt-BR' } ) ).read() )[ 0 ].u.voice.name, 'Microsoft Maria - Portuguese (Brazil)' );
	assert.equal( ( await ( await setup( { docLang: 'pt-PT' } ) ).read() )[ 0 ].u.voice.name, 'Joana' );
} );

test( 'post mode: no voice of the language means no voice of another one, only lang', async () => {
	const spoken = await ( await setup( { docLang: 'de-DE', paragraphs: [ 'Ein kurzer Absatz.' ] } ) ).read();

	assert.equal( spoken[ 0 ].u.voice, undefined );
	assert.equal( spoken[ 0 ].u.lang, 'de-DE' );
} );

test( 'post mode: without data-doc-lang the page language is used; voices not listed yet still get lang', async () => {
	const page = await ( await setup( { docLang: '' } ) ).read();
	assert.equal( page[ 0 ].u.lang, 'pt-BR' );

	const late = await ( await setup( { docLang: 'zh-CN', paragraphs: [ '这是一个短段落。' ], voices: [] } ) ).read();
	assert.equal( late[ 0 ].u.voice, undefined );
	assert.equal( late[ 0 ].u.lang, 'zh-CN' );
} );

test( 'post mode: a long paragraph goes in pieces of up to 220 characters, in order, nothing lost', async () => {
	const long = 'Esta frase tem um tamanho médio e termina com ponto final. '.repeat( 12 ).trim();
	const spoken = await ( await setup( { paragraphs: [ long, 'Segundo parágrafo.' ] } ) ).read();
	const ofLong = spoken.slice( 0, -1 );

	assert.ok( ofLong.length > 1, 'more than one piece' );
	assert.ok( spoken.every( ( s ) => s.u.text.length <= 220 ), texts( spoken ).map( ( t ) => t.length ).join( ',' ) );
	assert.ok( ofLong.every( ( s ) => /\.$/.test( s.u.text ) ), 'whole sentences' );
	assert.equal( joined( ofLong ), long );
	assert.equal( spoken[ spoken.length - 1 ].u.text, 'Segundo parágrafo.', 'then the next paragraph' );
} );

test( 'post mode: the paragraph stays marked through all its pieces, then the mark moves on', async () => {
	const long = 'Uma frase que se repete para encher o parágrafo inteiro. '.repeat( 10 ).trim();
	const spoken = await ( await setup( { paragraphs: [ long, 'Fim.' ] } ) ).read();

	spoken.slice( 0, -1 ).forEach( ( s ) => assert.deepEqual( s.marked, [ long ] ) );
	assert.deepEqual( spoken[ spoken.length - 1 ].marked, [ 'Fim.' ] );
} );

test( 'post mode: numbers are never cut ("3.5", "R$ 1.250,00")', async () => {
	const paragraph = 'O valor subiu de R$ 1.250,00 para 3.5 milhões em poucos anos de trabalho contínuo e paciente '.repeat( 6 ).trim() + '.';
	const spoken = await ( await setup( { paragraphs: [ paragraph ] } ) ).read();
	const count = ( needle ) => texts( spoken ).reduce( ( n, t ) => n + t.split( needle ).length - 1, 0 );

	assert.ok( spoken.length > 1 && spoken.every( ( s ) => s.u.text.length <= 220 ) );
	assert.equal( count( 'R$ 1.250,00' ), 6 );
	assert.equal( count( '3.5 milhões' ), 6 );
	assert.equal( joined( spoken ), paragraph );
} );

test( 'post mode: Chinese ends pieces at 。 and Hindi at the danda', async () => {
	const zh = '这是一个关于阅读的句子，它没有空格但有标点符号。'.repeat( 15 );
	const chinese = await ( await setup( { docLang: 'zh-CN', paragraphs: [ zh ] } ) ).read();
	assert.ok( chinese.length > 1 && chinese.every( ( s ) => s.u.text.length <= 220 && /。$/.test( s.u.text ) ) );
	assert.equal( joined( chinese, '' ), zh );

	const hi = 'यह एक लंबा वाक्य है जो पढ़ने के बारे में बात करता है और इसमें कई शब्द हैं। '.repeat( 8 ).trim();
	const hindi = await ( await setup( { docLang: 'hi-IN', paragraphs: [ hi ] } ) ).read();
	assert.ok( hindi.length > 1 && hindi.every( ( s ) => s.u.text.length <= 220 && /।$/.test( s.u.text ) ) );
	assert.equal( hindi[ 0 ].u.voice.name, 'Lekha' );
} );

test( 'post mode: without speech.js (dropped by a script optimizer) Listen is off, not broken', async () => {
	const e = await setup( { speech: false } );

	assert.equal( e.button().disabled, true );
	assert.equal( e.status(), 'This browser does not support reading aloud.' );
} );
