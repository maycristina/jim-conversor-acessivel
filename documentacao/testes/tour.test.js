/**
 * Testes do tour guiado da tela "Novo Documento" (assets/js/tour.js).
 *
 * Conferem o que importa para leitor de tela e teclado: o diálogo tem nome e
 * descrição, recebe o foco a cada passo, fecha com Esc devolvendo o foco, o
 * controle destacado ganha contorno (e perde ao sair), e os textos nunca são
 * interpretados como HTML.
 *
 * Rodar (precisa do jsdom, que não faz parte do plugin):
 *   npm install --no-save jsdom
 *   node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { JSDOM } = require( 'jsdom' );

const { create, format } = require( '../../assets/js/tour.js' );

const STEPS = [
	{ target: [], title: 'Welcome', text: 'First step.' },
	{ target: [ '#a' ], title: 'File', text: 'Pick a file.' },
	{ target: [ '#hidden', '#b' ], title: 'Second choice', text: 'Falls back to #b.' },
	{ target: [ '#nowhere' ], title: 'Missing', text: 'No target on screen.' },
	{
		target: [],
		title: 'End',
		text: 'Done.',
		links: [ { label: 'Documents', url: 'https://example.test/docs' } ]
	}
];

const STRINGS = {
	progress: 'Step %1$s of %2$s',
	back: 'Back',
	next: 'Next',
	finish: 'Finish',
	skip: 'Skip tour'
};

function setup( overrides ) {
	const dom = new JSDOM(
		'<!doctype html><body>' +
		'<button id="opener">Open</button>' +
		'<input id="a"><input id="b"><input id="hidden" hidden>' +
		'</body>',
		{ pretendToBeVisual: true }
	);
	const { window } = dom;

	// jsdom has no layout: an element counts as rendered unless it (or a parent) is hidden.
	window.HTMLElement.prototype.getClientRects = function () {
		return this.closest( '[hidden]' ) ? [] : [ {} ];
	};

	const closed = [];
	const tour = create( window.document, Object.assign( {
		steps: STEPS,
		strings: STRINGS,
		onClose: ( finished ) => closed.push( finished )
	}, overrides ) );

	const $ = ( sel ) => window.document.querySelector( sel );
	const click = ( el ) => el.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
	const esc = () => window.document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	return { window, doc: window.document, tour, closed, $, click, esc };
}

test( 'the dialog has a role, a name and a description that point to real elements', () => {
	const { doc, tour, $ } = setup();

	tour.start();

	const box = $( '#jimca-tour' );

	assert.equal( box.getAttribute( 'role' ), 'dialog' );
	assert.equal( box.getAttribute( 'aria-modal' ), 'false', 'non-modal: the page stays usable' );
	assert.equal( doc.getElementById( box.getAttribute( 'aria-labelledby' ) ).textContent, 'Welcome' );
	assert.equal( doc.getElementById( box.getAttribute( 'aria-describedby' ) ).textContent, 'First step.' );
} );

test( 'the dialog takes focus at every step, so a screen reader announces it', () => {
	const { doc, tour, $, click } = setup();

	tour.start();
	assert.equal( doc.activeElement, $( '#jimca-tour' ) );

	// Focus is moved away (as a click on the page would) and comes back with the next step.
	$( '#opener' ).focus();
	click( $( '.jimca-tour__actions .button-primary' ) );
	assert.equal( doc.activeElement, $( '#jimca-tour' ) );
} );

test( 'progress, title and buttons follow the step', () => {
	const { tour, $, click } = setup();
	const next = () => $( '.jimca-tour__actions .button-primary' );

	tour.start();
	assert.equal( $( '.jimca-tour__progress' ).textContent, 'Step 1 of 5' );
	assert.equal( $( '.jimca-tour__actions .button:not(.button-primary)' ).hidden, true, 'no Back on the first step' );
	assert.equal( next().textContent, 'Next' );

	click( next() );
	assert.equal( $( '.jimca-tour__progress' ).textContent, 'Step 2 of 5' );
	assert.equal( $( '.jimca-tour__title' ).textContent, 'File' );
	assert.equal( $( '.jimca-tour__actions .button:not(.button-primary)' ).hidden, false );

	for ( let i = 0; i < 3; i++ ) {
		click( next() );
	}
	assert.equal( tour.index(), 4 );
	assert.equal( next().textContent, 'Finish' );
	assert.equal( $( '.jimca-tour__skip' ).hidden, true, 'no Skip on the last step: Finish is the way out' );
} );

test( 'Back returns to the previous step', () => {
	const { tour, $, click } = setup();

	tour.start();
	click( $( '.jimca-tour__actions .button-primary' ) );
	click( $( '.jimca-tour__actions .button:not(.button-primary)' ) );

	assert.equal( tour.index(), 0 );
	assert.equal( $( '.jimca-tour__title' ).textContent, 'Welcome' );
} );

test( 'the target gets an outline class, which moves with the step and is removed on close', () => {
	const { tour, $, click, doc } = setup();
	const next = () => $( '.jimca-tour__actions .button-primary' );

	tour.start();
	assert.equal( doc.querySelector( '.jimca-tour-target' ), null, 'step 1 has no target' );

	click( next() );
	assert.equal( $( '.jimca-tour-target' ), $( '#a' ) );

	click( next() );
	assert.equal( doc.querySelectorAll( '.jimca-tour-target' ).length, 1, 'only one control is highlighted' );
	assert.equal( $( '.jimca-tour-target' ), $( '#b' ), 'a hidden first choice falls back to the next selector' );

	tour.close();
	assert.equal( doc.querySelector( '.jimca-tour-target' ), null );
} );

test( 'a step without a visible target is shown centered', () => {
	const { tour, $, click } = setup();
	const next = () => $( '.jimca-tour__actions .button-primary' );

	tour.start();
	assert.ok( $( '#jimca-tour' ).classList.contains( 'jimca-tour--centered' ) );

	click( next() );
	assert.ok( ! $( '#jimca-tour' ).classList.contains( 'jimca-tour--centered' ), 'a target is on screen' );

	click( next() );
	click( next() );
	assert.ok( $( '#jimca-tour' ).classList.contains( 'jimca-tour--centered' ), '#nowhere does not exist' );
} );

test( 'Esc closes the tour from anywhere and returns focus to where it was', () => {
	const { doc, tour, closed, $, esc } = setup();

	$( '#opener' ).focus();
	tour.start();
	assert.equal( tour.isOpen(), true );

	esc();

	assert.equal( tour.isOpen(), false );
	assert.equal( $( '#jimca-tour' ).hidden, true );
	assert.equal( doc.activeElement, $( '#opener' ) );
	assert.deepEqual( closed, [ false ] );
} );

test( 'Esc does nothing while the tour is closed', () => {
	const { closed, esc } = setup();

	esc();

	assert.deepEqual( closed, [] );
} );

test( 'Skip ends the tour as "not finished"; Finish as "finished"', () => {
	let s = setup();

	s.tour.start();
	s.click( s.$( '.jimca-tour__skip' ) );
	assert.deepEqual( s.closed, [ false ] );

	s = setup();
	s.tour.start();
	for ( let i = 0; i < 4; i++ ) {
		s.click( s.$( '.jimca-tour__actions .button-primary' ) );
	}
	s.click( s.$( '.jimca-tour__actions .button-primary' ) );
	assert.deepEqual( s.closed, [ true ] );
	assert.equal( s.tour.isOpen(), false );
} );

test( 'the tour can be opened again, from the first step, without a second dialog', () => {
	const { doc, tour, $, click } = setup();

	tour.start();
	click( $( '.jimca-tour__actions .button-primary' ) );
	tour.close();
	tour.start();

	assert.equal( tour.index(), 0 );
	assert.equal( doc.querySelectorAll( '#jimca-tour' ).length, 1 );
	assert.equal( $( '#jimca-tour' ).hidden, false );
} );

test( 'links appear only where a step has them, as real anchors', () => {
	const { tour, $, click } = setup();

	tour.start();
	assert.equal( $( '.jimca-tour__links' ).hidden, true );

	for ( let i = 0; i < 4; i++ ) {
		click( $( '.jimca-tour__actions .button-primary' ) );
	}
	const a = $( '.jimca-tour__links a' );

	assert.equal( a.textContent, 'Documents' );
	assert.equal( a.getAttribute( 'href' ), 'https://example.test/docs' );
	assert.equal( $( '.jimca-tour__links' ).hidden, false );
} );

test( 'texts are set as text, never as HTML', () => {
	const { tour, $ } = setup( {
		steps: [ { target: [], title: '<img src=x onerror=alert(1)>', text: '<b>bold</b> & more' } ]
	} );

	tour.start();

	assert.equal( $( '.jimca-tour__title' ).textContent, '<img src=x onerror=alert(1)>' );
	assert.equal( $( '#jimca-tour img' ), null );
	assert.equal( $( '.jimca-tour__body b' ), null );
} );

test( 'without steps nothing opens', () => {
	const { tour, doc } = setup( { steps: [] } );

	tour.start();

	assert.equal( tour.isOpen(), false );
	assert.equal( doc.getElementById( 'jimca-tour' ), null );
} );

test( 'format() fills %s and %1$s placeholders in order', () => {
	assert.equal( format( 'Step %1$s of %2$s', [ 2, 9 ] ), 'Step 2 of 9' );
	assert.equal( format( '%s / %s', [ 'a', 'b' ] ), 'a / b' );
} );
