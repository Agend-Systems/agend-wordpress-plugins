/**
 * The composed copy (src/copy.mjs) and its translated twin (src/strings.js).
 *
 * Run with: node --test agend-content-access/tests/js/copy.test.mjs
 *
 * The logic modules take their copy as a parameter and the panel passes a
 * translated copy of the same shape. If the two drifted, an author on a
 * translated site would read English in one place and a missing label in
 * another, and nothing would fail. So the panel's file is compared, key for
 * key and word for word, to the one the tests read.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { DEFAULT_STRINGS, format } from '../../src/copy.mjs';

const strings = readFileSync( new URL( '../../src/strings.js', import.meta.url ), 'utf8' );

test( 'every string the logic composes is translated in the panel, with the same words', () => {
	for ( const [ key, text ] of Object.entries( DEFAULT_STRINGS ) ) {
		assert.ok( strings.includes( `\t\t${ key }: __( '${ text }', 'agend-content-access' )` ), `${ key }: ${ text }` );
	}
} );

test( 'the panel translates no key the logic does not know', () => {
	const keys = [ ...strings.matchAll( /^\t\t(\w+): __\(/gm ) ].map( ( entry ) => entry[ 1 ] );

	assert.deepEqual( keys.sort(), Object.keys( DEFAULT_STRINGS ).sort() );
} );

test( 'a placeholder in a string has a translators comment above it', () => {
	for ( const [ key, text ] of Object.entries( DEFAULT_STRINGS ) ) {
		if ( ! /%(\d\$)?[sd]/.test( text ) ) {
			continue;
		}

		// The line above the string.
		const at = strings.indexOf( `\t\t${ key }: __(` );
		const before = strings.slice( strings.lastIndexOf( '\n', at - 2 ) + 1, at - 1 );

		assert.match( before, /translators:/, key );
	}
} );

test( 'no string has a quote or an apostrophe that would break the literal, or an em dash', () => {
	for ( const [ key, text ] of Object.entries( DEFAULT_STRINGS ) ) {
		assert.doesNotMatch( text, /['"\u2014\u2013]/, key );
	}
} );

test( 'format fills placeholders in order, by number, and leaves what it has no value for', () => {
	assert.equal( format( 'Plan: %s', 'Gold' ), 'Plan: Gold' );
	assert.equal( format( '%d results', 7 ), '7 results' );
	assert.equal( format( 'Showing %1$d of %2$d.', 50, 800 ), 'Showing 50 of 800.' );
	assert.equal( format( '%2$s, %1$s', 'a', 'b' ), 'b, a' );
	assert.equal( format( '%s and %s', 'only' ), 'only and %s' );
	assert.equal( format( 'No placeholder', 'x' ), 'No placeholder' );
} );

test( 'format does not read dollar patterns in a value, or placeholders inside one', () => {
	assert.equal( format( 'Plan: %s', 'Gold $& $1 $$' ), 'Plan: Gold $& $1 $$' );
	assert.equal( format( 'Plan: %s and %s', '%s', 'two' ), 'Plan: %s and two' );
} );
