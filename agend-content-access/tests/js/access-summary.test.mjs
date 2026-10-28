/**
 * The one-line summary at the top of the panel (src/access-summary.mjs).
 *
 * Run with: node --test agend-content-access/tests/js/access-summary.test.mjs
 *
 * It is what an author reads to check what they just did, so it must say the
 * same thing the stored rules mean: Any or All, reversed or not, how many.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { DEFAULT_STRINGS } from '../../src/copy.mjs';
import { normalise } from '../../src/access-model.mjs';
import { summarise } from '../../src/access-summary.mjs';

const rules = ( count ) => Array.from( { length: count }, ( _, n ) => 'agend_segments:s' + n );
const say = ( stored ) => summarise( normalise( stored ) );

test( 'no rules is everyone who can see the page', () => {
	for ( const stored of [ undefined, null, {}, [], { flag: 'x' } ] ) {
		assert.equal( say( stored ), 'Everyone who can see the page' );
	}
} );

test( 'a page with no rules is for everyone, and says so in two words', () => {
	assert.equal( summarise( normalise( undefined ), DEFAULT_STRINGS, true ), 'Everyone' );
	assert.equal( summarise( { rules: [], unreadable: false }, DEFAULT_STRINGS, true ), 'Everyone' );
	assert.equal( summarise( { rules: rules( 2 ), match: 'any', invert: false }, DEFAULT_STRINGS, true ), 'Only people who match any of these 2 rules' );
	assert.equal( summarise( { rules: rules( 2 ), match: 'all', invert: true }, DEFAULT_STRINGS, true ), 'Everyone except people who match all of these 2 rules' );
	assert.equal( summarise( { rules: [], unreadable: true }, DEFAULT_STRINGS, true ), 'Hidden from everyone, because the setting cannot be read' );
} );

test( 'one rule', () => {
	assert.equal( say( { rules: rules( 1 ) } ), 'Only people who match this rule' );
	assert.equal( say( { rules: rules( 1 ), invert: true } ), 'Everyone except people who match this rule' );
} );

test( 'two or more rules say how many, and Any or All', () => {
	assert.equal( say( { rules: rules( 2 ) } ), 'Only people who match any of these 2 rules' );
	assert.equal( say( { rules: rules( 2 ), match: 'any' } ), 'Only people who match any of these 2 rules' );
	assert.equal( say( { rules: rules( 3 ), match: 'all' } ), 'Only people who match all of these 3 rules' );
	assert.equal( say( { rules: rules( 2 ), invert: true } ), 'Everyone except people who match any of these 2 rules' );
	assert.equal( say( { rules: rules( 3 ), match: 'all', invert: true } ), 'Everyone except people who match all of these 3 rules' );
	assert.equal( say( { rules: rules( 12 ), match: 'all' } ), 'Only people who match all of these 12 rules' );
} );

test( 'a Match left on one rule says nothing about Any or All', () => {
	assert.equal( say( { rules: rules( 1 ), match: 'all' } ), 'Only people who match this rule' );
} );

test( 'a value the panel cannot read is said to be hidden from everyone', () => {
	assert.equal( say( 'members' ), 'Hidden from everyone, because the setting cannot be read' );
	assert.equal( say( { rules: [ 7 ] } ), 'Hidden from everyone, because the setting cannot be read' );
} );

test( 'a first-shape block is summarised as the rules it is shown as', () => {
	assert.equal( say( { mode: 'selected_tiers', tierIds: [ '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222' ] } ), 'Only people who match any of these 2 rules' );
	assert.equal( say( { mode: 'active_member', conditionsEnabled: true, conditions: [ 'agend_wordpress:logged_in' ] } ), 'Only people who match all of these 2 rules' );
} );

test( 'translated copy is used', () => {
	const copy = Object.assign( {}, DEFAULT_STRINGS, { summaryOnlyAny: 'Seulement %d regles' } );

	assert.equal( summarise( normalise( { rules: rules( 4 ) } ), copy ), 'Seulement 4 regles' );
} );

test( 'no line uses an em dash or a jargon word', () => {
	for ( const text of Object.values( DEFAULT_STRINGS ) ) {
		assert.equal( text.includes( '\u2014' ), false, text );
		assert.equal( text.includes( '\u2013' ), false, text );
	}

	for ( const key of Object.keys( DEFAULT_STRINGS ).filter( ( name ) => name.startsWith( 'summary' ) ) ) {
		assert.doesNotMatch( DEFAULT_STRINGS[ key ], /predicate|boolean|negat|invert|provider|policy/i, key );
	}
} );
