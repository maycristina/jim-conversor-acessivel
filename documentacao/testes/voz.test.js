/**
 * Idioma e voz do leitor em voz alta (assets/js/frontend.js), rodando o script
 * de verdade num DOM (jsdom) com um sintetizador simulado:
 *
 * - a voz segue o data-doc-lang do documento (escolhido no servidor), com a
 *   melhor voz do idioma no modo Automático (região exata > mesmo idioma;
 *   desempate por qualidade no nome e voz local);
 * - uma voz ou língua salva lendo um documento em outro idioma não vale aqui;
 * - o texto vai em pedaços de até 220 caracteres (limite das vozes online do
 *   Chrome), com fim de frase latino, chinês (。！？) e devanágari (। ॥).
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( process.env.JIMCA_FRONTEND_JS || path.join( __dirname, '../../assets/js/frontend.js' ), 'utf8' );
// Loaded before frontend.js, as WordPress does (a dependency of it).
const SPEECH = fs.readFileSync( path.join( __dirname, '../../assets/js/speech.js' ), 'utf8' );
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const VOICES = [
	{ name: 'Microsoft Maria - Portuguese (Brazil)', lang: 'pt-BR', localService: true },
	{ name: 'Joana', lang: 'pt-PT', localService: true },
	{ name: 'Milena', lang: 'ru-RU', localService: true },
	{ name: 'Google русский', lang: 'ru-RU', localService: false },
	{ name: 'Google US English', lang: 'en-US', localService: false },
	{ name: 'Lekha', lang: 'hi-IN', localService: true },
	{ name: 'Ting-Ting', lang: 'zh-CN', localService: true },
];

/**
 * @param {object} o
 * @param {string} o.docLang   data-doc-lang of the reader ('' = none)
 * @param {string} o.paragraph text of the only paragraph
 * @param {Array}  o.voices
 * @param {object} o.prefs     saved preferences of the reader
 */
async function setup( { docLang = 'pt-BR', paragraph = 'Um parágrafo curto.', voices = VOICES, prefs = null } = {} ) {
	const dom = new JSDOM(
		`<html lang="pt-BR"><body><section id="v" data-jimca-viewer${ docLang ? ` data-doc-lang="${ docLang }"` : '' }>
			<div data-jimca-player hidden>
				<div data-jimca-player-bar>
					<button data-jimca-play><span data-jimca-play-label>Listen</span></button>
					<button data-jimca-stop disabled>Stop</button>
				</div>
				<fieldset data-jimca-voice-group hidden>
					<select data-jimca-voice-lang></select>
					<span data-jimca-voice-kind-wrap hidden><select data-jimca-voice-kind></select></span>
					<select data-jimca-voice-name></select>
				</fieldset>
				<p data-jimca-status></p>
			</div>
			<div data-jimca-content lang="${ docLang.split( '-' )[ 0 ] }"><p>${ paragraph }</p></div>
		</section></body></html>`,
		{ runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' }
	);
	const w = dom.window;
	const spoken = [];
	let current = null;

	if ( prefs ) {
		w.localStorage.setItem( 'jimca-prefs-v', JSON.stringify( prefs ) );
	}

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
			}, 2 );
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

	w.eval( SPEECH );
	w.eval( SOURCE );
	await wait( 20 );

	return {
		spoken,
		/** Plays and waits until the paragraph has been spoken whole. */
		async read() {
			w.document.querySelector( '[data-jimca-play]' ).click();
			for ( let i = 0; i < 200 && 'Reading finished.' !== w.document.querySelector( '[data-jimca-status]' ).textContent; i++ ) {
				await wait( 5 );
			}
			return spoken;
		},
	};
}

test( 'the document language picks the voice: a Russian document is read by a Russian voice, the better one', async () => {
	const spoken = await ( await setup( { docLang: 'ru-RU', paragraph: 'Это короткий абзац.' } ) ).read();

	// Both ru-RU (100): the quality name (Google) breaks the tie over the local Milena.
	assert.equal( spoken[ 0 ].voice.name, 'Google русский' );
	assert.equal( spoken[ 0 ].lang, 'ru-RU' );
} );

test( 'the exact region beats the same language: pt-BR gets the Brazilian voice, pt-PT the Portuguese one', async () => {
	assert.equal( ( await ( await setup( { docLang: 'pt-BR' } ) ).read() )[ 0 ].voice.name, 'Microsoft Maria - Portuguese (Brazil)' );
	assert.equal( ( await ( await setup( { docLang: 'pt-PT' } ) ).read() )[ 0 ].voice.name, 'Joana' );
} );

test( 'a language without its region gets a voice of that language; a language without voices gets none, only lang', async () => {
	const hindi = await ( await setup( { docLang: 'hi', paragraph: 'यह एक छोटा अनुच्छेद है।' } ) ).read();
	assert.equal( hindi[ 0 ].voice.name, 'Lekha' );

	const german = await ( await setup( { docLang: 'de-DE', paragraph: 'Ein kurzer Absatz.' } ) ).read();
	assert.equal( german[ 0 ].voice, undefined, 'no German voice: no voice of another language either' );
	assert.equal( german[ 0 ].lang, 'de-DE' );
} );

test( 'a voice and language saved while reading a Portuguese document do not read a Russian one', async () => {
	const prefs = { voice: 'Microsoft Maria - Portuguese (Brazil)', voiceLang: 'pt-BR' };
	const spoken = await ( await setup( { docLang: 'ru-RU', paragraph: 'Это короткий абзац.', prefs } ) ).read();

	assert.equal( spoken[ 0 ].lang, 'ru-RU' );
	assert.equal( spoken[ 0 ].voice.lang, 'ru-RU' );
} );

test( 'the saved voice still reads documents in its own language', async () => {
	const prefs = { voice: 'Joana', voiceLang: 'pt-PT' };
	const spoken = await ( await setup( { docLang: 'pt-BR', prefs } ) ).read();

	assert.equal( spoken[ 0 ].voice.name, 'Joana', 'same language (pt): the reader\'s choice is kept' );
} );

test( 'voices not listed yet (Chrome lists them late): the utterance still carries the document language', async () => {
	const spoken = await ( await setup( { docLang: 'zh-CN', paragraph: '这是一个短段落。', voices: [] } ) ).read();

	assert.equal( spoken[ 0 ].voice, undefined );
	assert.equal( spoken[ 0 ].lang, 'zh-CN' );
} );

/** Text of the pieces, whitespace normalised, to compare with the paragraph. */
const joined = ( spoken, sep = ' ' ) => spoken.map( ( u ) => u.text ).join( sep ).replace( /\s+/g, ' ' ).trim();

test( 'a long paragraph is read in pieces of up to 220 characters, whole sentences, nothing lost', async () => {
	const sentence = 'Esta frase tem um tamanho médio e termina com ponto final. ';
	const paragraph = sentence.repeat( 12 ).trim();
	const spoken = await ( await setup( { paragraph } ) ).read();

	assert.ok( spoken.length > 1, 'more than one piece' );
	assert.ok( spoken.every( ( u ) => u.text.length <= 220 ), spoken.map( ( u ) => u.text.length ).join( ',' ) );
	assert.ok( spoken.every( ( u ) => /\.$/.test( u.text ) ), 'every piece ends a sentence' );
	assert.equal( joined( spoken ), paragraph );
} );

test( 'a number with a dot is not a sentence end: "3.5" and "R$ 1.250,00" are never cut', async () => {
	const paragraph = ( 'O valor subiu de R$ 1.250,00 para 3.5 milhões em poucos anos de trabalho contínuo e paciente ' ).repeat( 6 ).trim() + '.';
	const spoken = await ( await setup( { paragraph } ) ).read();

	const count = ( needle ) => spoken.reduce( ( n, u ) => n + u.text.split( needle ).length - 1, 0 );

	assert.ok( spoken.length > 1 && spoken.every( ( u ) => u.text.length <= 220 ) );
	// Every number stays whole inside one piece (a cut may fall on the space before it).
	assert.equal( count( 'R$ 1.250,00' ), 6, spoken.map( ( u ) => u.text ).join( ' | ' ) );
	assert.equal( count( '3.5 milhões' ), 6 );
	assert.equal( joined( spoken ), paragraph );
} );

test( 'a sentence over 220 characters without a full stop is cut at a comma, else at a space', async () => {
	const commas = ( 'uma parte da frase sem ponto, ' ).repeat( 15 ).trim();
	const byComma = await ( await setup( { paragraph: commas } ) ).read();
	assert.ok( byComma.length > 1 && byComma.every( ( u ) => u.text.length <= 220 ) );
	assert.ok( byComma.slice( 0, -1 ).every( ( u ) => /,$/.test( u.text ) ), 'cut after a comma' );
	assert.equal( joined( byComma ), commas );

	const words = ( 'palavras sem pontuação alguma ' ).repeat( 15 ).trim();
	const bySpace = await ( await setup( { paragraph: words } ) ).read();
	assert.ok( bySpace.every( ( u ) => u.text.length <= 220 ) );
	assert.equal( joined( bySpace ), words, 'cut between words, never inside one' );
} );

test( 'Chinese: pieces end at 。 when they can, and a text with no punctuation is cut at the limit', async () => {
	const zh = '这是一个关于阅读的句子，它没有空格但有标点符号。'.repeat( 15 );
	const spoken = await ( await setup( { docLang: 'zh-CN', paragraph: zh } ) ).read();
	assert.ok( spoken.length > 1 && spoken.every( ( u ) => u.text.length <= 220 ) );
	assert.ok( spoken.every( ( u ) => /。$/.test( u.text ) ), 'whole sentences' );
	assert.equal( joined( spoken, '' ), zh );

	const bare = '阅'.repeat( 500 );
	const cut = await ( await setup( { docLang: 'zh-CN', paragraph: bare } ) ).read();
	assert.deepEqual( cut.map( ( u ) => u.text.length ), [ 220, 220, 60 ] );
} );

test( 'Hindi: the danda (। ॥) ends a sentence', async () => {
	const hi = 'यह एक लंबा वाक्य है जो पढ़ने के बारे में बात करता है और इसमें कई शब्द हैं। '.repeat( 8 ).trim();
	const spoken = await ( await setup( { docLang: 'hi-IN', paragraph: hi } ) ).read();

	assert.ok( spoken.length > 1 && spoken.every( ( u ) => u.text.length <= 220 ) );
	assert.ok( spoken.every( ( u ) => /।$/.test( u.text ) ) );
	assert.equal( joined( spoken ), hi );
} );
