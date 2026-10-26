/*
 * What the reader (frontend.js) and the post bar (post.js) share to read
 * aloud: the best voice for a language and the text cut in pieces short
 * enough for every voice. Loaded before both (see JIMCA_Shortcode).
 */
( function () {
	'use strict';

	/*
	 * Chrome cuts its online voices (Google) after about 15 seconds of one
	 * utterance, in the middle of a long paragraph. So the text goes in
	 * pieces of up to MAX characters: whole sentences when they fit (ending
	 * in . ! ? or the Chinese and Devanagari full stops), else cut at a comma
	 * or pause mark, else at a space, else (Chinese and Japanese have no
	 * spaces) at the limit.
	 */
	var MAX = 220;
	var WIDE_STOP = /[\u3002\uff01\uff1f\u0964\u0965]/;
	var CLOSING = /[.!?\u3002\uff01\uff1f\u0964\u0965"'\u201d\u2019\u00bb\u300d\u300f)\]]/;
	var PAUSE = /[,;\uff0c\uff1b\u3001]/;
	var WIDE_PAUSE = /[\uff0c\uff1b\u3001]/;
	var CJK = /[\u3000-\u303f\u3040-\u30ff\u4e00-\u9fff\uff00-\uffef]/;
	var QUALITY = /natural|neural|google|microsoft|premium|enhanced/i;

	/** "pt_BR" and "PT-br" alike: "pt-br". */
	function normLang( code ) {
		return String( code || '' ).replace( /_/g, '-' ).toLowerCase();
	}

	function primaryLang( code ) {
		return normLang( code ).split( '-' )[ 0 ];
	}

	/**
	 * The best voice for a language: the exact region first (pt-BR for
	 * pt-BR, 100 points), else the same language (pt-PT, 50); voices of other
	 * languages are left out. Ties go to the voices whose names tell a better
	 * quality (natural, neural...), then to local ones.
	 *
	 * @param {SpeechSynthesisVoice[]} voices
	 * @param {string}                 lang   BCP-47 tag.
	 * @return {SpeechSynthesisVoice|null}
	 */
	function bestVoice( voices, lang ) {
		var wanted = normLang( lang );
		var best = null;
		var bestScore = 0;

		if ( ! wanted ) {
			return null;
		}

		( voices || [] ).forEach( function ( voice ) {
			var code = normLang( voice.lang );
			var score = code === wanted ? 100 : ( primaryLang( code ) === primaryLang( wanted ) ? 50 : 0 );

			if ( ! score ) {
				return;
			}

			score += ( QUALITY.test( voice.name || '' ) ? 10 : 0 ) + ( voice.localService ? 1 : 0 );

			if ( score > bestScore ) {
				best = voice;
				bestScore = score;
			}
		} );

		return best;
	}

	/**
	 * Sentences of a text. A Latin . ! ? ends one only before a space or the
	 * end ("3.5" and "R$ 1.250,00" stay whole); the Chinese and Devanagari
	 * marks, which no space follows, always do. Closing quotes and brackets
	 * stay with their sentence.
	 *
	 * @param {string} text
	 * @return {string[]}
	 */
	function sentences( text ) {
		var out = [];
		var start = 0;

		for ( var i = 0; i < text.length; i++ ) {
			var ch = text.charAt( i );
			var wide = WIDE_STOP.test( ch );

			if ( ! wide && ! /[.!?]/.test( ch ) ) {
				continue;
			}

			var end = i + 1;
			while ( end < text.length && CLOSING.test( text.charAt( end ) ) ) {
				end++;
			}

			if ( wide || end === text.length || /\s/.test( text.charAt( end ) ) ) {
				out.push( text.slice( start, end ) );
				start = end;
			}
			i = end - 1;
		}

		if ( start < text.length ) {
			out.push( text.slice( start ) );
		}

		return out;
	}

	/**
	 * @param {string} text A sentence.
	 * @return {string[]} The sentence cut in parts of up to MAX characters.
	 */
	function cutLong( text ) {
		var out = [];

		while ( text.length > MAX ) {
			var cut = -1;
			var i;

			// The last pause mark in the second half (a Latin comma only before a space: not inside "1.250,00"), then the last space.
			for ( i = MAX - 1; i >= MAX / 2 && cut < 0; i-- ) {
				if ( PAUSE.test( text.charAt( i ) ) && ( WIDE_PAUSE.test( text.charAt( i ) ) || /\s/.test( text.charAt( i + 1 ) ) ) ) {
					cut = i + 1;
				}
			}
			for ( i = MAX - 1; i >= MAX / 2 && cut < 0; i-- ) {
				if ( /\s/.test( text.charAt( i ) ) ) {
					cut = i + 1;
				}
			}

			cut = cut > 0 ? cut : MAX;
			out.push( text.slice( 0, cut ).trim() );
			text = text.slice( cut ).trim();
		}

		if ( text ) {
			out.push( text );
		}

		return out;
	}

	/**
	 * @param {string} text Text of a passage.
	 * @return {string[]} Pieces to speak one after the other, none over MAX characters.
	 */
	function pieces( text ) {
		var out = [];
		var current = '';

		sentences( String( text || '' ).replace( /\s+/g, ' ' ).trim() ).forEach( function ( sentence ) {
			sentence = sentence.trim();

			if ( ! sentence ) {
				return;
			}

			// Sentences go together while they fit: fewer pauses than one utterance per sentence. Chinese and Japanese have no spaces.
			var glue = CJK.test( current.slice( -1 ) ) || CJK.test( sentence.charAt( 0 ) ) ? '' : ' ';

			if ( current && ( current + glue + sentence ).length <= MAX ) {
				current += glue + sentence;
				return;
			}

			if ( current ) {
				out.push( current );
			}

			var parts = cutLong( sentence );
			current = parts.pop();
			out = out.concat( parts );
		} );

		if ( current ) {
			out.push( current );
		}

		return out;
	}

	window.jimcaSpeech = {
		MAX: MAX,
		normLang: normLang,
		primaryLang: primaryLang,
		bestVoice: bestVoice,
		pieces: pieces,
	};
}() );
