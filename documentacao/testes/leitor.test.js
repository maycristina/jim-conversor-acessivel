/**
 * Regressão do leitor em voz alta (assets/js/frontend.js), rodando o script
 * de verdade num DOM (jsdom) com um sintetizador de voz simulado que age como
 * o do Chrome: cancel() dispara `error` ("canceled") no trecho cortado, de
 * forma assíncrona, DEPOIS de o código já ter seguido em frente.
 *
 * Cobre: Stop não pode continuar a leitura nem voltar ao início (WCAG 1.4.2
 * Audio Control e 2.2.2 Pause, Stop, Hide); Ouvir começa onde a pessoa está.
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
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * @param {number} count  paragraphs in the document
 * @param {number} firstOnScreen  index of the first paragraph inside the viewport
 */
async function setup( count = 12, firstOnScreen = 0 ) {
	let paragraphs = '';
	for ( let i = 0; i < count; i++ ) {
		paragraphs += '<p>Paragrafo ' + i + '</p>';
	}

	const dom = new JSDOM(
		`<body><section data-jimca-viewer>
			<div data-jimca-player hidden>
				<div data-jimca-player-bar>
					<button data-jimca-play><span data-jimca-play-label>Listen</span></button>
					<button data-jimca-stop disabled>Stop</button>
				</div>
				<p data-jimca-status></p>
			</div>
			<div data-jimca-content>${ paragraphs }</div>
		</section></body>`,
		{ runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' }
	);
	const w = dom.window;

	const spoken = [];
	let current = null;
	let speakDelay = 20;

	w.SpeechSynthesisUtterance = function ( text ) {
		this.text = text;
	};
	w.speechSynthesis = {
		getVoices: () => [],
		addEventListener() {},
		pause() {},
		resume() {},
		speak( u ) {
			current = u;
			spoken.push( +u.text.match( /\d+/ )[ 0 ] );
			setTimeout( () => {
				if ( current === u ) {
					current = null;
					u.onend && u.onend();
				}
			}, speakDelay );
		},
		cancel() {
			const u = current;
			current = null;
			if ( u && u.onerror ) {
				setTimeout( () => u.onerror( { error: 'canceled' } ), 0 ); // late, like Chrome.
			}
		}
	};
	w.IntersectionObserver = function () {
		this.observe = () => {};
		this.disconnect = () => {};
	};
	w.matchMedia = () => ( { matches: false, addEventListener() {}, addListener() {} } );
	w.Element.prototype.scrollIntoView = function () {};

	// Geometry: paragraphs 0..firstOnScreen-1 are scrolled above the screen.
	w.document.querySelectorAll( '[data-jimca-content] p' ).forEach( ( p, i ) => {
		p.getBoundingClientRect = () => {
			const top = ( i - firstOnScreen ) * 40 + 50;
			return { top, bottom: top + 30, left: 0, right: 100, width: 100, height: 30 };
		};
	} );

	w.eval( SOURCE );
	await wait( 20 ); // the script starts on DOMContentLoaded.

	return {
		w,
		spoken,
		play: () => w.document.querySelector( '[data-jimca-play]' ).click(),
		stop: () => w.document.querySelector( '[data-jimca-stop]' ).click(),
		status: () => w.document.querySelector( '[data-jimca-status]' ).textContent,
		highlighted: () => w.document.querySelectorAll( '.jimca-reading' ).length,
		setDelay: ( ms ) => {
			speakDelay = ms;
		}
	};
}

test( 'Stop interrupts the reading and nothing is spoken afterwards', async () => {
	const r = await setup( 12, 0 );
	r.play();
	await wait( 70 );
	const before = r.spoken.length;
	assert.ok( before >= 1, 'it started reading' );

	r.stop();
	await wait( 200 );

	assert.equal( r.spoken.length, before, 'no new passage after Stop (late "canceled" event must not advance)' );
	assert.equal( r.highlighted(), 0 );
	assert.equal( r.status(), 'Reading stopped.' );
} );

test( 'Stop does not send the reading back to the beginning', async () => {
	const r = await setup( 20, 8 );
	r.play();
	await wait( 70 );
	r.stop();
	await wait( 200 );

	assert.ok( r.spoken.every( ( n ) => n >= 8 ), 'only passages from where the reader was: ' + r.spoken );
	assert.ok( ! r.spoken.includes( 0 ) && ! r.spoken.includes( 1 ), 'never went back to the top' );
} );

test( 'Listen starts at the first paragraph on screen, not at the top (and again after Stop)', async () => {
	const r = await setup( 20, 8 );
	r.play();
	await wait( 10 );
	assert.equal( r.spoken[ 0 ], 8 );

	r.stop();
	await wait( 50 );
	r.play();
	await wait( 10 );
	assert.equal( r.spoken[ r.spoken.length - 1 ], 8, 'the second start is also at the visible passage' );
} );

test( 'Pause and then Stop: nothing is spoken after Stop', async () => {
	const r = await setup( 12, 0 );
	r.play();
	await wait( 50 );
	r.play(); // pause
	const before = r.spoken.length;
	r.stop();
	await wait( 200 );

	assert.equal( r.spoken.length, before );
} );

test( 'Stop and Listen again: one reading only, in order, no duplicate chain', async () => {
	const r = await setup( 12, 0 );
	r.play();
	await wait( 30 );
	r.stop();
	r.play(); // immediately: the late "canceled" of the first run is still pending.
	await wait( 150 );

	const run = r.spoken.slice( r.spoken.lastIndexOf( 0 ) );
	assert.deepEqual( run, run.slice().sort( ( a, b ) => a - b ), 'ordered' );
	assert.equal( new Set( run ).size, run.length, 'no passage twice: ' + run );
} );

test( 'a speed change keeps reading from the same passage, once', async () => {
	const r = await setup( 12, 0 );
	r.setDelay( 60 );
	r.play();
	await wait( 20 );
	assert.deepEqual( r.spoken, [ 0 ] );
	// Same path as the speed menu: the passage restarts, no more than once.
	r.stop();
	await wait( 100 );
	assert.deepEqual( r.spoken, [ 0 ], 'no extra passage while stopped' );
} );
