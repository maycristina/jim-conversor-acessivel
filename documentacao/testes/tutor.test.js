/**
 * Regressão do chat do tutor de IA (assets/js/tutor.js), rodando o script de
 * verdade num DOM (jsdom).
 *
 * Cobre: estado vazio (orbe + saudação + sugestões) e estado de conversa; a
 * resposta da IA entra como texto, nunca como HTML; "Nova conversa" volta ao
 * estado vazio.
 *
 * Rodar: npm install --no-save jsdom  e  node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/tutor.js' ), 'utf8' );

const HTML = `
<section data-jimca-viewer data-jimca-title="Livro de Teste">
	<div class="jimca-tutor" data-jimca-tutor data-doc="7" data-nonce="n" data-greeting="Oi! Posso ajudar." data-name="Tutor" hidden>
		<button type="button" data-jimca-tutor-open aria-expanded="false"></button>
		<section data-jimca-tutor-panel hidden>
			<button type="button" data-jimca-tutor-export hidden></button>
			<div data-jimca-tutor-quick-menu hidden></div>
			<button type="button" data-jimca-tutor-quick hidden aria-expanded="false"></button>
			<button type="button" data-jimca-tutor-clear></button>
			<button type="button" data-jimca-tutor-hide></button>
			<button type="button" data-jimca-tutor-close></button>
			<div data-jimca-tutor-log></div>
			<form data-jimca-tutor-form>
				<textarea data-jimca-tutor-input></textarea>
				<button type="button" data-jimca-tutor-mic hidden></button>
				<button type="submit"></button>
			</form>
			<p data-jimca-tutor-status></p>
		</section>
	</div>
	<p data-jimca-tutor-restore hidden><button type="button"></button></p>
</section>`;

async function setup( answer ) {
	const dom = new JSDOM( HTML, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.org/' } );
	const win = dom.window;

	win.jimcaTutorI18n = { action: 'jimca_tutor_ask', ajaxUrl: '/ajax', you: 'Você', chips: [ [ 'Resumir', 'Resuma ' ], [ 'Desafio', 'Faça uma pergunta difícil.', true ] ], exported: 'Baixado.' };
	win.fetch = () => Promise.resolve( {
		headers: { get: () => 'application/json' },
		json: () => Promise.resolve( { success: true, data: { answer } } ),
	} );
	const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

	win.eval( SOURCE );
	// The script starts on DOMContentLoaded when the document is still loading.
	await wait( 10 );

	const q = ( sel ) => win.document.querySelector( sel );

	return { win, q, wait };
}

function ask( env, text ) {
	env.q( '[data-jimca-tutor-input]' ).value = text;
	env.q( '[data-jimca-tutor-form]' ).dispatchEvent( new env.win.Event( 'submit', { cancelable: true } ) );
}

test( 'empty state: orb, greeting as plain text and suggestions; no bubbles', async () => {
	const env = await setup( 'ok' );

	assert.equal( env.q( '[data-jimca-tutor-panel]' ).getAttribute( 'data-state' ), 'empty' );
	assert.ok( env.q( '.jimca-tutor__orb[aria-hidden="true"]' ), 'the orb is decorative' );
	assert.equal( env.q( '.jimca-tutor__welcome' ).textContent, 'Oi! Posso ajudar.' );
	assert.equal( env.q( '[data-jimca-tutor-chips] button' ).textContent, 'Resumir' );
	assert.equal( env.win.document.querySelectorAll( '.jimca-tutor__msg' ).length, 0 );
} );

test( 'a suggestion puts the beginning of a question in the field', async () => {
	const env = await setup( 'ok' );

	env.q( '[data-jimca-tutor-chips] button' ).click();

	assert.equal( env.q( '[data-jimca-tutor-input]' ).value, 'Resuma ' );
} );

test( 'asking switches to the conversation and drops the welcome', async () => {
	const env = await setup( 'Resposta' );

	ask( env, 'Quem foi Hammurabi?' );
	await env.wait( 20 );

	assert.equal( env.q( '[data-jimca-tutor-panel]' ).getAttribute( 'data-state' ), 'chatting' );
	assert.equal( env.q( '[data-jimca-tutor-stage]' ), null );
	assert.equal( env.q( '.jimca-tutor__msg--user .jimca-tutor__text' ).textContent, 'Quem foi Hammurabi?' );
	assert.equal( env.q( '.jimca-tutor__msg--assistant .jimca-tutor__text' ).textContent, 'Resposta' );
	assert.equal( env.q( '[data-jimca-tutor-input]' ).value, '', 'the field is cleared after sending' );
} );

test( 'the AI answer is shown as text, never as HTML; **bold** becomes <strong>', async () => {
	const env = await setup( '<img src=x onerror=alert(1)> **importante**' );

	ask( env, 'Pergunta' );
	await env.wait( 20 );

	const text = env.q( '.jimca-tutor__msg--assistant .jimca-tutor__text' );

	assert.equal( text.querySelector( 'img' ), null );
	assert.equal( text.querySelector( 'strong' ).textContent, 'importante' );
	assert.ok( text.textContent.includes( '<img src=x' ) );
} );

test( 'new conversation goes back to the empty state', async () => {
	const env = await setup( 'Resposta' );

	ask( env, 'Pergunta' );
	await env.wait( 20 );
	env.q( '[data-jimca-tutor-clear]' ).click();

	assert.equal( env.q( '[data-jimca-tutor-panel]' ).getAttribute( 'data-state' ), 'empty' );
	assert.ok( env.q( '[data-jimca-tutor-stage]' ) );
	assert.equal( env.win.document.querySelectorAll( '.jimca-tutor__msg' ).length, 0 );
} );

test( 'an empty question is not sent', async () => {
	const env = await setup( 'ok' );
	let sent = false;

	env.win.fetch = () => {
		sent = true;
		return new Promise( () => {} );
	};
	ask( env, '   ' );

	assert.equal( sent, false );
	assert.equal( env.q( '[data-jimca-tutor-panel]' ).getAttribute( 'data-state' ), 'empty' );
} );

test( 'a challenge chip sends at once and asks for a fresh answer (never a saved one)', async () => {
	const env = await setup( 'Pergunta do desafio' );
	const bodies = [];
	const original = env.win.fetch;

	env.win.fetch = ( url, options ) => {
		bodies.push( options.body );
		return original( url, options );
	};

	env.q( '[data-jimca-tutor-chips] button:nth-child(2)' ).click();
	await env.wait( 30 );

	assert.equal( bodies.length, 1, 'no typing and no extra click' );
	assert.equal( bodies[ 0 ].get( 'question' ), 'Faça uma pergunta difícil.' );
	assert.equal( bodies[ 0 ].get( 'fresh' ), '1' );
	assert.equal( env.q( '[data-jimca-tutor-panel]' ).getAttribute( 'data-state' ), 'chatting' );
} );

test( 'a normal question does not ask for a fresh answer', async () => {
	const env = await setup( 'Resposta' );
	const bodies = [];
	const original = env.win.fetch;

	env.win.fetch = ( url, options ) => {
		bodies.push( options.body );
		return original( url, options );
	};

	ask( env, 'Quem foi Hammurabi?' );
	await env.wait( 30 );

	assert.equal( bodies[ 0 ].get( 'fresh' ), null );
} );

test( 'the conversation can be downloaded as text once there is one', async () => {
	const env = await setup( 'Hammurabi foi um rei.' );
	const saved = [];

	env.win.URL.createObjectURL = ( blob ) => {
		saved.push( { blob } );
		return 'blob:test';
	};
	env.win.URL.revokeObjectURL = () => {};
	env.win.HTMLAnchorElement.prototype.click = function () {
		saved[ saved.length - 1 ].name = this.download;
	};

	assert.equal( env.q( '[data-jimca-tutor-export]' ).hidden, true, 'nothing to download yet' );

	ask( env, 'Quem foi Hammurabi?' );
	await env.wait( 30 );

	assert.equal( env.q( '[data-jimca-tutor-export]' ).hidden, false );
	env.q( '[data-jimca-tutor-export]' ).click();

	const text = await new Promise( ( resolve ) => {
		const reader = new env.win.FileReader();

		reader.onload = () => resolve( reader.result );
		reader.readAsText( saved[ 0 ].blob );
	} );

	assert.equal( saved[ 0 ].name, 'Livro-de-Teste-conversation.txt' );
	assert.ok( text.startsWith( 'Livro de Teste\n' ) );
	assert.ok( text.includes( 'Você:\nQuem foi Hammurabi?\n' ) );
	assert.ok( text.includes( 'Tutor:\nHammurabi foi um rei.\n' ) );
	assert.equal( env.q( '[data-jimca-tutor-status]' ).textContent, 'Baixado.' );
} );

test( 'quick texts: the button opens a menu with the same suggestions, at any point of the conversation', async () => {
	const env = await setup( 'Resposta' );
	const button = env.q( '[data-jimca-tutor-quick]' );
	const menu = env.q( '[data-jimca-tutor-quick-menu]' );

	assert.equal( button.hidden, false );
	assert.equal( menu.hidden, true );

	ask( env, 'Primeira pergunta' );
	await env.wait( 30 );

	button.click();

	assert.equal( menu.hidden, false, 'still available after the start screen is gone' );
	assert.equal( button.getAttribute( 'aria-expanded' ), 'true' );
	assert.deepEqual(
		Array.from( menu.querySelectorAll( '[role="menuitem"]' ) ).map( ( item ) => item.textContent ),
		[ 'Resumir', 'Desafio' ],
		'same list as the suggestions at the start'
	);
	assert.equal( env.win.document.activeElement, menu.querySelector( '[role="menuitem"]' ) );
} );

test( 'quick texts: a normal item goes in front of the draft and closes the menu', async () => {
	const env = await setup( 'ok' );
	const input = env.q( '[data-jimca-tutor-input]' );

	input.value = 'a Babilônia';
	env.q( '[data-jimca-tutor-quick]' ).click();
	env.q( '[data-jimca-tutor-quick-menu] [role="menuitem"]' ).click();

	assert.equal( input.value, 'Resuma a Babilônia' );
	assert.equal( env.q( '[data-jimca-tutor-quick-menu]' ).hidden, true );
	assert.equal( env.q( '[data-jimca-tutor-quick]' ).getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'quick texts: a challenge item sends at once and keeps the draft', async () => {
	const env = await setup( 'Pergunta do desafio' );
	const bodies = [];
	const original = env.win.fetch;
	const input = env.q( '[data-jimca-tutor-input]' );

	env.win.fetch = ( url, options ) => {
		bodies.push( options.body );
		return original( url, options );
	};

	input.value = 'minha dúvida';
	env.q( '[data-jimca-tutor-quick]' ).click();
	env.q( '[data-jimca-tutor-quick-menu] [role="menuitem"]:nth-child(2)' ).click();
	await env.wait( 30 );

	assert.equal( bodies[ 0 ].get( 'question' ), 'Faça uma pergunta difícil.' );
	assert.equal( bodies[ 0 ].get( 'fresh' ), '1' );
	assert.equal( input.value, 'minha dúvida', 'what the reader was typing is not lost' );
} );

test( 'quick texts: Escape closes the menu first, then the panel; arrows move between items', async () => {
	const env = await setup( 'ok' );
	const panel = env.q( '[data-jimca-tutor-panel]' );
	const menu = env.q( '[data-jimca-tutor-quick-menu]' );
	const key = ( target, name ) => target.dispatchEvent( new env.win.KeyboardEvent( 'keydown', { key: name, bubbles: true, cancelable: true } ) );

	panel.hidden = false;
	env.q( '[data-jimca-tutor-quick]' ).click();

	const items = menu.querySelectorAll( '[role="menuitem"]' );

	key( items[ 0 ], 'ArrowDown' );
	assert.equal( env.win.document.activeElement, items[ 1 ] );
	key( items[ 1 ], 'ArrowDown' );
	assert.equal( env.win.document.activeElement, items[ 0 ], 'wraps around' );

	key( items[ 0 ], 'Escape' );
	assert.equal( menu.hidden, true );
	assert.equal( panel.hidden, false, 'the panel stays open' );
	assert.equal( env.win.document.activeElement, env.q( '[data-jimca-tutor-quick]' ), 'focus goes back to the button' );
} );
