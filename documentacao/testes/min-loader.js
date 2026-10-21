/*
 * Makes the tests run on the minified files (name.min.js), the ones the
 * package ships, instead of the readable source.
 *
 *   bin/minify-assets.sh
 *   node --require ./documentacao/testes/min-loader.js --test documentacao/testes/<tests>
 *
 * Every read or require of assets/js/<name>.js goes to assets/js/<name>.min.js
 * when it exists. a11y.test.js is left out of this run: it reads the source
 * text (function names, comments) rather than running the scripts.
 */
'use strict';

const fs = require( 'fs' );
const Module = require( 'module' );

const ASSET = /assets[\\/]js[\\/][a-z-]+\.js$/;

const readFileSync = fs.readFileSync;

fs.readFileSync = function ( file, ...rest ) {
	if ( 'string' === typeof file && ASSET.test( file ) ) {
		const min = file.replace( /\.js$/, '.min.js' );

		if ( fs.existsSync( min ) ) {
			file = min;
		}
	}

	return readFileSync.call( this, file, ...rest );
};

const resolve = Module._resolveFilename;

Module._resolveFilename = function ( request, ...rest ) {
	if ( 'string' === typeof request && ASSET.test( request ) ) {
		try {
			return resolve.call( this, request.replace( /\.js$/, '.min.js' ), ...rest );
		} catch ( error ) {
			// No minified copy: use the source.
		}
	}

	return resolve.call( this, request, ...rest );
};
