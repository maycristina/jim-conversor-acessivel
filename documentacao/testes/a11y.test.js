/**
 * Testes de acessibilidade do visualizador (WCAG 2.1 AA), com as técnicas do
 * W3C (WAI Tutorials, Techniques) e do WebAIM. Cada teste cita o critério e a
 * técnica. Lê o CSS, o template e o JS do plugin; não precisa de PHP.
 *
 * O que não dá para automatizar (leitor de tela real, teclado em aparelho)
 * está na lista manual de TESTES-DE-ACESSIBILIDADE.md.
 *
 * Rodar: node --test "documentacao/testes/*.test.js"
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const root = path.join( __dirname, '../..' );
const read = ( f ) => fs.readFileSync( path.join( root, f ), 'utf8' );
const css = read( 'assets/css/frontend.css' );
const tpl = read( 'templates/document-viewer.php' );
const js = read( 'assets/js/frontend.js' );

/* ---------------------------------------------------------------- helpers */

function luminance( hex ) {
	const n = parseInt( hex.slice( 1 ), 16 );
	return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ]
		.map( ( v ) => {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} )
		.reduce( ( sum, v, i ) => sum + v * [ 0.2126, 0.7152, 0.0722 ][ i ], 0 );
}

function contrast( a, b ) {
	const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

/** Custom properties of the block opened by `selector` (first occurrence). */
function tokens( selector ) {
	const start = css.indexOf( selector + ' {' );
	assert.ok( start > -1, 'selector not found: ' + selector );
	const body = css.slice( start, css.indexOf( '\n}', start ) );
	const out = {};
	body.replace( /(--jimca-[\w-]+):\s*(#[0-9a-fA-F]{6})\b/g, ( m, name, value ) => {
		out[ name ] = value;
		return m;
	} );
	return out;
}

const THEMES = {
	light: '.jimca-viewer',
	sepia: '.jimca-viewer.jimca-theme-sepia',
	dark: '.jimca-viewer.jimca-theme-dark',
	'high contrast': '.jimca-viewer.jimca-high-contrast'
};

/* ----------------------------------------------- 1.4.3 / 1.4.11 contrast */

for ( const [ name, selector ] of Object.entries( THEMES ) ) {
	test( `WCAG 1.4.3 Contrast (Minimum), theme ${ name }: text 4.5:1`, () => {
		const t = { ...tokens( '.jimca-viewer' ), ...tokens( selector ) };
		const pairs = [
			[ '--jimca-fg', '--jimca-bg' ],
			[ '--jimca-accent-fg', '--jimca-accent' ],
			[ '--jimca-player-fg', '--jimca-player-surface' ],
			[ '--jimca-player-fg-strong', '--jimca-player-surface' ],
			[ '--jimca-fg', '--jimca-highlight-bg' ]
		];

		for ( const [ fg, bg ] of pairs ) {
			assert.ok( t[ fg ] && t[ bg ], `${ fg } / ${ bg } defined` );
			const ratio = contrast( t[ fg ], t[ bg ] );
			assert.ok( ratio >= 4.5, `${ fg } on ${ bg } is ${ ratio.toFixed( 2 ) }:1 (needs 4.5)` );
		}
	} );

	test( `WCAG 1.4.11 Non-text Contrast, theme ${ name }: focus ring and highlight edge 3:1`, () => {
		const t = { ...tokens( '.jimca-viewer' ), ...tokens( selector ) };

		assert.ok( contrast( t[ '--jimca-accent' ], t[ '--jimca-bg' ] ) >= 3, 'focus outline (accent) on the page background' );
		assert.ok( contrast( t[ '--jimca-highlight-border' ], t[ '--jimca-bg' ] ) >= 3, 'highlight border on the page background' );
	} );
}

/* ----------------------------------------- 2.4.1 Bypass Blocks: skip links */

test( 'WCAG 2.4.1 / W3C G1 / WebAIM: the skip links are the first Tab stops of the viewer', () => {
	const firstFocusable = tpl.search( /<(a|button|input|select|textarea)\b/ );
	const skipNav = tpl.indexOf( 'class="jimca-skip"' );
	const afterViewerOpen = tpl.indexOf( '<section class="jimca-viewer' );

	assert.ok( skipNav > afterViewerOpen, 'skip nav is inside the viewer' );
	assert.ok( firstFocusable > -1 && firstFocusable >= skipNav - 10 && firstFocusable <= skipNav + 200, 'the first focusable element is a skip link' );
	assert.match( tpl, /<nav class="jimca-skip" aria-label="/, 'the skip links are a labelled landmark' );
} );

test( 'W3C G123/G124: every skip link points to an element that exists', () => {
	const hrefs = [ ...tpl.matchAll( /class="jimca-skip__link" href="#<\?php echo esc_attr\( \$uid \); \?>(-[a-z]+)"/g ) ].map( ( m ) => m[ 1 ] );

	assert.deepEqual( hrefs.sort(), [ '-content', '-controls', '-toc' ] );
	for ( const suffix of hrefs ) {
		assert.ok( new RegExp( 'id="<\\?php echo esc_attr\\( \\$uid \\); \\?>' + suffix + '"' ).test( tpl ), `target ${ suffix } exists` );
	}
} );

test( 'WebAIM: skip link targets can take focus (tabindex -1) so the next Tab continues from there', () => {
	for ( const suffix of [ '-content', '-controls' ] ) {
		const m = tpl.match( new RegExp( '<[^>]*id="<\\?php echo esc_attr\\( \\$uid \\); \\?>' + suffix + '"[^>]*>' ) );
		assert.ok( m && /tabindex="-1"/.test( m[ 0 ] ), `${ suffix } has tabindex="-1"` );
	}
} );

test( 'WebAIM: skip links are hidden off-screen (not display:none) and shown on focus', () => {
	const hidden = css.match( /\.jimca-skip:not\(:focus-within\)\s*\{[^}]*\}/ );
	const shown = css.match( /\.jimca-skip:focus-within\s*\{[^}]*\}/ );

	assert.ok( hidden && /clip:\s*rect\(0, 0, 0, 0\)/.test( hidden[ 0 ] ) && /overflow:\s*hidden/.test( hidden[ 0 ] ) );
	assert.ok( ! /display:\s*none|visibility:\s*hidden/.test( hidden[ 0 ] ), 'display:none or visibility:hidden would remove the link from the Tab order' );
	assert.ok( shown && /display:\s*flex/.test( shown[ 0 ] ), 'visible while a skip link has focus' );
} );

test( 'WCAG 2.4.1: with JS the controls link is revealed and the contents link (no-JS list only) is removed', () => {
	assert.match( tpl, /data-jimca-skip-controls hidden/, 'the controls link is hidden until the player exists' );
	assert.match( js, /function setupSkipLinks/ );
	assert.match( js, /controls\.hidden = false/ );
} );

/* ------------------------------------------------ 2.1.1 / 4.1.2 / 2.4.7 */

test( 'WCAG 2.1.1 Keyboard: no positive tabindex, and controls are real <button>/<a>', () => {
	assert.ok( ! /tabindex="[1-9]/.test( tpl ), 'a positive tabindex breaks the reading order' );
	assert.ok( ! /<(div|span)[^>]*\bonclick=/.test( tpl ), 'no click handlers on non-interactive elements' );
	assert.ok( ( tpl.match( /<button\b/g ) || [] ).length >= 8, 'the player controls are <button>' );
} );

test( 'WCAG 4.1.2 Name, Role, Value: every button has a text or aria-label; toolbar and navs are labelled', () => {
	const buttons = [ ...tpl.matchAll( /<button\b[\s\S]*?<\/button>/g ) ].map( ( m ) => m[ 0 ] );

	assert.ok( buttons.length > 0 );
	for ( const b of buttons ) {
		const named = /aria-label=|jimca-visually-hidden|esc_html_e|esc_html__|<\?php echo esc_html/.test( b.replace( /<svg[\s\S]*?<\/svg>/g, '' ) );
		assert.ok( named, 'button without an accessible name: ' + b.slice( 0, 90 ).replace( /\s+/g, ' ' ) );
	}
	assert.match( tpl, /role="toolbar" aria-label=/ );
	// The tag is cut at 220 characters because PHP's "?>" inside an attribute ends a naive [^>]* match.
	for ( const m of tpl.matchAll( /<nav\b/g ) ) {
		assert.match( tpl.slice( m.index, m.index + 220 ), /aria-label(?:ledby)?=/, 'every <nav> has aria-label or aria-labelledby' );
	}
} );

test( 'WCAG 4.1.2: toggle buttons expose their state with aria-pressed / aria-expanded', () => {
	assert.match( js, /setAttribute\( 'aria-pressed'/ );
	assert.match( js, /setAttribute\( 'aria-expanded'/ );
} );

test( 'WCAG 4.1.3 Status Messages: reading and review status are polite live regions', () => {
	assert.match( tpl, /role="status" aria-live="polite" data-jimca-status/ );
	assert.match( read( 'includes/class-post-type.php' ), /id="jimca-audit-result"[^>]*role="status" aria-live="polite"/ );
} );

test( 'WCAG 2.4.7 Focus Visible: a 3px focus outline, and no outline:none outside the document container', () => {
	assert.match( css, /\.jimca-viewer :focus-visible\s*\{[^}]*outline:\s*3px solid/ );

	const removals = [ ...css.matchAll( /([^{}]+)\{([^}]*outline:\s*(?:none|0)\b[^}]*)\}/g ) ];
	for ( const [ , sel, body ] of removals ) {
		// Allowed: the document container (focused by script), or a field that swaps the outline for a 2px accent border.
		const ok = /jimca-content/.test( sel ) || /border:\s*[2-9]px solid var\(--jimca-accent\)/.test( body );
		assert.ok( ok, 'outline removed without another focus indicator: ' + sel.trim() );
	}
} );

/* ---------------------------------------------- 2.5.8 / 2.3.3 / 1.4.10 */

test( 'WCAG 2.5.8 Target Size: the buttons of the bar are 48px tall (Material 3) and at least 24px wide', () => {
	assert.match( css, /\.jimca-player__btn\s*\{[^}]*\bheight:\s*48px/ );
	const min = css.match( /\.jimca-player__btn\s*\{[^}]*min-width:\s*(\d+)px/ );
	assert.ok( min && +min[ 1 ] >= 24, 'min-width of the button (WCAG 2.2 AA: 24px)' );
} );

test( 'WCAG 2.3.3 Animation from Interactions: prefers-reduced-motion is honoured in CSS and in the scroll', () => {
	assert.match( css, /@media \(prefers-reduced-motion: reduce\)/ );
	assert.match( js, /reducedMotion \? 'auto' : 'smooth'/ );
} );

test( 'WCAG 1.4.10 Reflow: nothing wider than the screen on a phone', () => {
	assert.match( css, /overflow-wrap:\s*break-word/ );
	assert.match( css, /--jimca-vw/ );
	assert.match( css, /\.jimca-content :is\(video, iframe, svg, canvas\)\s*\{\s*max-width:\s*100%/ );
} );

/* ------------------------------------------------ 1.4.2 / 2.2.2 audio */

test( 'WCAG 1.4.2 Audio Control / 2.2.2 Pause, Stop, Hide: Stop and Pause exist, and Stop stops for good', () => {
	assert.match( tpl, /data-jimca-stop/ );
	assert.match( tpl, /data-jimca-play/ );
	assert.match( js, /function cutSpeech/, 'cutSpeech() invalidates the late events of the cut utterance' );
	assert.match( js, /mine !== run \|\| 'playing' !== state/, 'late events of a cut utterance do nothing' );
	// The behaviour itself is tested against the real script in leitor.test.js.
} );

/* ------------------------------------------------ 1.3.1 / 1.1.1 / 3.1.2 */

test( 'WCAG 1.3.1 Info and Relationships: the viewer has a labelled region and a heading', () => {
	assert.match( tpl, /<section class="jimca-viewer[\s\S]*?aria-labelledby=/ );
	assert.match( tpl, /<h2 id="[^"]*-title" class="jimca-title">/ );
} );

test( 'WCAG 1.1.1 Non-text Content: icons are hidden from assistive technology, their button has the name', () => {
	const icons = read( 'includes/class-shortcode.php' ).match( /<svg class="jimca-player__icon"[^>]*>/ );
	assert.ok( icons && /aria-hidden="true"/.test( icons[ 0 ] ) && /focusable="false"/.test( icons[ 0 ] ) );
} );
