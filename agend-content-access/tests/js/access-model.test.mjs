/**
 * The logic behind the Agend Access block panel (src/access-model.mjs).
 *
 * Run with: node --test agend-content-access/tests/js/access-model.test.mjs
 *
 * What these protect is what ends up in a post. The panel is the only thing
 * standing between an author's clicks and the stored `agendAccess` object, so
 * the cases are about that object: a block that was only looked at stays
 * clean, a stale value cannot linger after the rules it belonged to are gone, a
 * key the panel does not own survives an edit, a block saved by the first panel
 * is only rewritten by a real change, and a stored value the panel cannot read
 * neither crashes it nor quietly loosens anything.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import {
	ATTRIBUTE,
	CONTRACT_KEYS,
	EXCLUDED_BLOCKS,
	RULE_KEYS,
	addRule,
	carriesAttribute,
	isExcluded,
	isRestricted,
	normalise,
	removeRule,
	resetAccess,
	setFallback,
	setInvert,
	setMatch,
	withAccessAttribute,
} from '../../src/access-model.mjs';

const PLAN_A = '11111111-1111-4111-8111-111111111111';
const SEG_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

const GOLD = 'agend_membership:' + PLAN_A;
const BOARD = 'agend_segments:' + SEG_A;
const PREMIUM = 'agend_entitlements:premium.directory';
const SIGNED_IN = 'agend_wordpress:logged_in';
const EDITOR = 'agend_wordpress:role_editor';

/** Freezes a value all the way down, so any mutation by the code under test throws. */
function deepFreeze( value ) {
	if ( value && typeof value === 'object' ) {
		Object.values( value ).forEach( deepFreeze );
		Object.freeze( value );
	}

	return value;
}

const serialised = ( value ) => JSON.stringify( value );

// ---------------------------------------------------------------------------
// The attribute
// ---------------------------------------------------------------------------

test( 'the attribute is an object defaulting to empty, and the registration filter adds it', () => {
	const settings = { title: 'Paragraph', attributes: { content: { type: 'string' } }, save: () => null };
	const result = withAccessAttribute( settings, 'core/paragraph' );

	assert.deepEqual( result.attributes.agendAccess, { type: 'object', default: {} } );
	assert.deepEqual( result.attributes.content, { type: 'string' } );
	assert.equal( result.save, settings.save, 'save() is carried through untouched' );
	assert.equal( ATTRIBUTE, 'agendAccess' );
} );

test( 'the registration filter does not mutate the settings it is given', () => {
	const settings = deepFreeze( { attributes: { content: { type: 'string' } } } );

	assert.doesNotThrow( () => withAccessAttribute( settings, 'core/paragraph' ) );
	assert.equal( 'agendAccess' in settings.attributes, false );
} );

test( 'a block with no attributes of its own still gets the attribute', () => {
	assert.deepEqual( Object.keys( withAccessAttribute( {}, 'acme/plain' ).attributes ), [ 'agendAccess' ] );
} );

test( 'a block that already declares the attribute keeps its own declaration', () => {
	const settings = { attributes: { agendAccess: { type: 'string', default: 'theirs' } } };

	assert.equal( withAccessAttribute( settings, 'acme/own' ), settings, 'returned as it was' );
} );

test( 'the two blocks with no comment delimiter are excluded, and nothing else is', () => {
	assert.deepEqual( [ ...EXCLUDED_BLOCKS ], [ 'core/missing', 'core/freeform' ] );

	EXCLUDED_BLOCKS.forEach( ( name ) => {
		const settings = { attributes: {} };

		assert.equal( isExcluded( name ), true );
		assert.equal( withAccessAttribute( settings, name ), settings );
	} );

	[ 'core/paragraph', 'core/block', 'core/template-part', 'core/query', 'acme/anything' ].forEach( ( name ) => {
		assert.equal( isExcluded( name ), false, name );
	} );
} );

test( 'a value that is not a block name or not settings is passed through, not thrown on', () => {
	assert.equal( isExcluded( undefined ), true );
	assert.equal( isExcluded( 42 ), true );
	assert.equal( withAccessAttribute( null, 'core/paragraph' ), null );
	assert.equal( withAccessAttribute( undefined, 'core/paragraph' ), undefined );
} );

test( 'the exclusion list matches the one in PHP', () => {
	const php = readFileSync( new URL( '../../includes/class-agend-content-access-block-editor.php', import.meta.url ), 'utf8' );
	const match = php.match( /const EXCLUDED_BLOCKS\s*=\s*array\(([^)]*)\)/ );

	assert.ok( match, 'EXCLUDED_BLOCKS found in the PHP class' );

	const names = [ ...match[ 1 ].matchAll( /'([^']+)'/g ) ].map( ( entry ) => entry[ 1 ] );

	assert.deepEqual( names, [ ...EXCLUDED_BLOCKS ] );
} );

test( 'the panel is only offered where the block type actually carries the attribute', () => {
	assert.equal( carriesAttribute( { name: 'core/paragraph', attributes: { agendAccess: {} } } ), true );
	assert.equal( carriesAttribute( { name: 'acme/early', attributes: { content: {} } } ), false, 'registered before the filter ran' );
	assert.equal( carriesAttribute( { name: 'core/freeform', attributes: { agendAccess: {} } } ), false );
	assert.equal( carriesAttribute( undefined ), false );
	assert.equal( carriesAttribute( { name: 'acme/odd', attributes: 'nope' } ), false );
} );

// ---------------------------------------------------------------------------
// What is written
// ---------------------------------------------------------------------------

test( 'the first rule writes only the rules; every default is left out', () => {
	for ( const untouched of [ [], {}, undefined, null ] ) {
		assert.deepEqual( addRule( untouched, GOLD ), { rules: [ GOLD ] } );
	}
} );

test( 'a rule is added last and never twice', () => {
	const two = addRule( addRule( undefined, GOLD ), BOARD );

	assert.deepEqual( two, { rules: [ GOLD, BOARD ] } );
	assert.equal( addRule( two, GOLD ), two, 'adding what is there changes nothing, and is not a write' );
	assert.equal( addRule( two, '' ), two, 'an empty rule is not a rule' );
	assert.equal( addRule( two, 5 ), two );
} );

test( 'Match is written only when it is all, and only with two rules', () => {
	const one = addRule( undefined, GOLD );
	const two = addRule( one, BOARD );

	assert.equal( setMatch( one, 'all' ), one, 'one rule has nothing to match' );
	assert.deepEqual( setMatch( two, 'all' ), { rules: [ GOLD, BOARD ], match: 'all' } );
	assert.deepEqual( setMatch( setMatch( two, 'all' ), 'any' ), { rules: [ GOLD, BOARD ] }, 'any is the default, so not written' );
	assert.equal( setMatch( two, 'any' ), two, 'choosing what is already chosen is not a write' );
	assert.deepEqual( setMatch( two, 'banana' ), two, 'anything but all is any' );
} );

test( 'Reverse is written only when it is on, and only with a rule', () => {
	const one = addRule( undefined, GOLD );

	assert.equal( setInvert( undefined, true ), undefined, 'nothing to reverse' );
	assert.deepEqual( setInvert( one, true ), { rules: [ GOLD ], invert: true } );
	assert.deepEqual( setInvert( setInvert( one, true ), false ), { rules: [ GOLD ] } );
	assert.equal( setInvert( one, false ), one );
	assert.deepEqual( setInvert( one, 'yes' ), one, 'only the boolean true turns it on' );
} );

test( 'the replacement content is a positive post id, written only when it is not zero', () => {
	const one = addRule( undefined, GOLD );

	assert.equal( setFallback( undefined, 12 ), undefined, 'nothing to fall back from' );
	assert.deepEqual( setFallback( one, 12 ), { rules: [ GOLD ], fallbackId: 12 } );
	assert.deepEqual( setFallback( one, '12' ), { rules: [ GOLD ], fallbackId: 12 }, 'as a select gives it' );
	assert.deepEqual( setFallback( setFallback( one, 12 ), '' ), { rules: [ GOLD ] }, 'Nothing removes it' );

	for ( const junk of [ 0, -3, 2.5, null, true, [], '0x1', 'abc' ] ) {
		assert.equal( setFallback( one, junk ), one, String( junk ) );
	}
} );

test( 'a full round trip ends with the attribute removed', () => {
	let value = {};

	value = addRule( value, GOLD );
	value = addRule( value, BOARD );
	value = setMatch( value, 'all' );
	value = setInvert( value, true );
	value = setFallback( value, 12 );

	assert.deepEqual( value, { rules: [ GOLD, BOARD ], match: 'all', invert: true, fallbackId: 12 } );

	value = removeRule( value, GOLD );
	assert.deepEqual( value, { rules: [ BOARD ], invert: true, fallbackId: 12 }, 'Match needs two rules, so it goes' );

	value = removeRule( value, BOARD );
	assert.equal( value, undefined, 'the last rule takes Reverse and the replacement with it' );
	assert.equal( serialised( { agendAccess: value } ), '{}', 'a block comment would carry no agendAccess' );
} );

test( 'a key that no longer applies is dropped, so it cannot come back to life', () => {
	const configured = setFallback( setInvert( setMatch( addRule( addRule( undefined, GOLD ), BOARD ), 'all' ), true ), 9 );
	const down = removeRule( removeRule( configured, GOLD ), BOARD );

	assert.equal( down, undefined );
	assert.deepEqual( addRule( down, EDITOR ), { rules: [ EDITOR ] }, 'a new first rule starts clean' );
	assert.deepEqual( addRule( removeRule( configured, BOARD ), SIGNED_IN ), { rules: [ GOLD, SIGNED_IN ], invert: true, fallbackId: 9 }, 'Match went with the second rule and does not return with the next' );
} );

test( 'the stray {} fix: nothing chosen is undefined, never an empty object', () => {
	for ( const untouched of [ [], {}, undefined ] ) {
		assert.equal( removeRule( addRule( untouched, GOLD ), GOLD ), undefined );
		assert.equal( resetAccess( untouched ), undefined );
		assert.equal( removeRule( untouched, GOLD ), untouched, 'removing what is not there is not a write' );
	}
} );

test( 'unknown keys keep the attribute alive: an object with only them, never undefined', () => {
	const stored = { rules: [ GOLD ], match: 'any', newerRelease: { shape: [ 1 ] }, flag: 'x' };
	const back = removeRule( stored, GOLD );

	assert.deepEqual( back, { newerRelease: { shape: [ 1 ] }, flag: 'x' } );
	assert.deepEqual( resetAccess( stored ), { newerRelease: { shape: [ 1 ] }, flag: 'x' } );

	// And once the unknown key is the only thing left, the next edit still keeps it.
	assert.deepEqual( addRule( back, GOLD ), { rules: [ GOLD ], newerRelease: { shape: [ 1 ] }, flag: 'x' } );
} );

test( 'a key the panel does not own is kept, untouched, through every kind of edit', () => {
	const stored = { rules: [ GOLD, BOARD ], flag: 'x', nested: { a: [ 1, 2 ] } };
	const edits = [
		( v ) => addRule( v, EDITOR ),
		( v ) => removeRule( v, GOLD ),
		( v ) => setMatch( v, 'all' ),
		( v ) => setInvert( v, true ),
		( v ) => setFallback( v, 4 ),
	];

	for ( const edit of edits ) {
		const next = edit( stored );

		assert.equal( next.flag, 'x' );
		assert.deepEqual( next.nested, { a: [ 1, 2 ] } );
	}
} );

test( 'nothing the panel writes uses a key outside the contract', () => {
	let value = {};

	[
		( v ) => addRule( v, GOLD ),
		( v ) => addRule( v, BOARD ),
		( v ) => setMatch( v, 'all' ),
		( v ) => setInvert( v, true ),
		( v ) => setFallback( v, 7 ),
	].forEach( ( edit ) => {
		value = edit( value );

		Object.keys( value ).forEach( ( key ) => assert.ok( RULE_KEYS.includes( key ), key ) );
	} );

	assert.deepEqual( Object.keys( value ), [ 'rules', 'match', 'invert', 'fallbackId' ], 'in the contract order' );
} );

test( 'no setter mutates the value it is given', () => {
	const stored = deepFreeze( { rules: [ GOLD, BOARD ], match: 'all', invert: true, fallbackId: 3, flag: [ 1 ] } );
	const shared = deepFreeze( [] );
	const legacy = deepFreeze( { mode: 'selected_tiers', tierIds: [ PLAN_A ], conditionsEnabled: true, conditions: [ SIGNED_IN ] } );

	for ( const value of [ stored, shared, legacy ] ) {
		assert.doesNotThrow( () => {
			addRule( value, EDITOR );
			removeRule( value, GOLD );
			setMatch( value, 'any' );
			setInvert( value, false );
			setFallback( value, 0 );
			resetAccess( value );
		} );
	}
} );

test( 'a change that changes nothing is not a write', () => {
	const stored = { rules: [ GOLD, BOARD ], match: 'all', invert: true, fallbackId: 3 };

	assert.equal( addRule( stored, GOLD ), stored );
	assert.equal( removeRule( stored, EDITOR ), stored );
	assert.equal( setMatch( stored, 'all' ), stored );
	assert.equal( setInvert( stored, true ), stored );
	assert.equal( setFallback( stored, 3 ), stored );
	assert.equal( setFallback( stored, '3' ), stored );
} );

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

test( 'absent, null, {} and [] all read as no rules', () => {
	for ( const stored of [ undefined, null, {}, [] ] ) {
		const state = normalise( stored );

		assert.deepEqual( state.rules, [] );
		assert.equal( state.match, 'any' );
		assert.equal( state.invert, false );
		assert.equal( state.fallbackId, 0 );
		assert.equal( state.unreadable, false );
		assert.equal( state.legacy, false );
		assert.equal( isRestricted( stored ), false );
	}
} );

test( 'an unknown key alone reads as no rules, as the server reads it', () => {
	assert.equal( normalise( { flag: 'x' } ).unreadable, false );
	assert.deepEqual( normalise( { flag: 'x' } ).rules, [] );
	assert.equal( isRestricted( { flag: 'x' } ), false );
} );

test( 'rules are read once each, in order', () => {
	assert.deepEqual( normalise( { rules: [ GOLD, BOARD, GOLD ] } ).rules, [ GOLD, BOARD ] );
} );

test( 'only the match value "all" reads as all, and only a boolean true reverses', () => {
	assert.equal( normalise( { rules: [ GOLD ], match: 'all' } ).match, 'all' );

	for ( const junk of [ 'ALL', 'every', 3, true, null ] ) {
		assert.equal( normalise( { rules: [ GOLD ], match: junk } ).match, 'any', String( junk ) );
	}

	assert.equal( normalise( { rules: [ GOLD ], invert: true } ).invert, true );

	for ( const junk of [ 'yes', 1, 'true', {} ] ) {
		assert.equal( normalise( { rules: [ GOLD ], invert: junk } ).invert, false, String( junk ) );
	}
} );

test( 'the replacement content is a positive whole number, and nothing else', () => {
	const read = ( fallbackId ) => normalise( { rules: [ GOLD ], fallbackId } ).fallbackId;

	assert.equal( read( 7 ), 7 );

	for ( const junk of [ '7', 0, -3, 2.5, null, true, [] ] ) {
		assert.equal( read( junk ), 0, String( junk ) );
	}
} );

test( 'a block with rules is restricted, and so is one the panel cannot read', () => {
	assert.equal( isRestricted( { rules: [ GOLD ] } ), true );
	assert.equal( isRestricted( { rules: [] } ), false );
	assert.equal( isRestricted( 'members' ), true );
	assert.equal( isRestricted( { mode: 'active_member' } ), true, 'a first-shape policy counts' );
	assert.equal( isRestricted( { conditionsEnabled: true } ), false, 'switched on with nothing to check' );
} );

// ---------------------------------------------------------------------------
// A stored value the panel cannot read
// ---------------------------------------------------------------------------

const UNREADABLE = [
	[ 'a string', 'members' ],
	[ 'a number', 42 ],
	[ 'true', true ],
	[ 'false', false ],
	[ 'a non-empty list', [ 1, 2 ] ],
	[ 'a list of objects', [ { rules: [ GOLD ] } ] ],
	[ 'rules that are not a list', { rules: GOLD } ],
	[ 'rules held in an object', { rules: { 0: GOLD } } ],
	[ 'a rule that is not text', { rules: [ GOLD, 7 ] } ],
	[ 'a rule that is null', { rules: [ null ] } ],
	[ 'an empty rule', { rules: [ '' ] } ],
	[ 'a rule that is an object', { rules: [ { value: GOLD } ] } ],
	[ 'reversing nothing', { rules: [], invert: true } ],
	[ 'reversing with no rules key', { invert: true } ],
	[ 'both shapes at once', { rules: [ GOLD ], mode: 'active_member' } ],
	[ 'both shapes at once, conditions', { rules: [ GOLD ], conditionsEnabled: true } ],
	[ 'a first-shape mode this version does not know', { mode: 'wat' } ],
];

for ( const [ label, stored ] of UNREADABLE ) {
	test( `unreadable, hidden from everyone, and offered a reset only: ${ label }`, () => {
		const state = normalise( stored );

		assert.equal( state.unreadable, true );
		assert.deepEqual( state.rules, [] );
		assert.equal( isRestricted( stored ), true, 'shown as restricted on the canvas' );

		// Every write is refused: the value comes back as it was, and the
		// panel never quietly turns "nobody" into "somebody".
		[
			( v ) => addRule( v, GOLD ),
			( v ) => removeRule( v, GOLD ),
			( v ) => setMatch( v, 'all' ),
			( v ) => setInvert( v, true ),
			( v ) => setFallback( v, 3 ),
		].forEach( ( edit ) => assert.equal( edit( stored ), stored ) );

		assert.doesNotThrow( () => resetAccess( stored ) );
	} );
}

test( 'resetting clears an unreadable value, keeps the keys that are not ours, and unlocks the panel', () => {
	assert.equal( resetAccess( 'members' ), undefined );
	assert.equal( resetAccess( { rules: [ 1 ] } ), undefined );
	assert.deepEqual( resetAccess( { rules: [ 1 ], flag: 'x' } ), { flag: 'x' } );
	assert.deepEqual( addRule( resetAccess( { rules: [ 1 ], flag: 'x' } ), GOLD ), { rules: [ GOLD ], flag: 'x' } );
} );

// ---------------------------------------------------------------------------
// A block saved by the first panel
// ---------------------------------------------------------------------------

test( 'a first-shape block is shown as rules and is not rewritten by being opened', () => {
	const stored = deepFreeze( { mode: 'selected_tiers', tierIds: [ PLAN_A ], conditionsEnabled: true, conditions: [ SIGNED_IN ] } );
	const state = normalise( stored );

	assert.equal( state.legacy, true );
	assert.deepEqual( state.rules, [ GOLD, SIGNED_IN ] );

	// No setter that changes nothing returns anything but the stored value.
	assert.equal( setMatch( stored, 'all' ), stored );
	assert.equal( setInvert( stored, false ), stored );
	assert.equal( setFallback( stored, 0 ), stored );
	assert.equal( addRule( stored, GOLD ), stored );
} );

test( 'a first-shape block is rewritten as the current shape only by a real change, and nothing of the old shape survives', () => {
	const stored = { mode: 'active_member', conditionsEnabled: true, conditions: [ EDITOR ], conditionsMatch: 'all', fallbackId: 5, flag: 'x' };

	assert.deepEqual( addRule( stored, BOARD ), { rules: [ 'agend_membership:is_member', EDITOR, BOARD ], match: 'all', fallbackId: 5, flag: 'x' } );
	assert.deepEqual( removeRule( stored, EDITOR ), { rules: [ 'agend_membership:is_member' ], fallbackId: 5, flag: 'x' } );
	assert.deepEqual( removeRule( removeRule( stored, EDITOR ), 'agend_membership:is_member' ), { flag: 'x' }, 'all the old keys go' );
} );

test( 'a first-shape block that holds an audience and conditions is flagged as two-part', () => {
	assert.equal( normalise( { mode: 'active_member', conditionsEnabled: true, conditions: [ SIGNED_IN ] } ).twoPart, true );
	assert.equal( normalise( { mode: 'active_member' } ).twoPart, false );
	assert.equal( normalise( { conditionsEnabled: true, conditions: [ SIGNED_IN ] } ).twoPart, false );
	assert.equal( normalise( { rules: [ GOLD, BOARD ] } ).twoPart, false );
} );

test( 'a current-shape value never reports itself as legacy', () => {
	assert.equal( normalise( { rules: [ GOLD ] } ).legacy, false );
} );

// ---------------------------------------------------------------------------
// Garbage does not crash anything
// ---------------------------------------------------------------------------

test( 'the contract keys include both shapes, so nothing of the old one is ever kept as "somebody else\'s"', () => {
	for ( const key of [ 'rules', 'match', 'invert', 'fallbackId', 'mode', 'tierIds', 'segmentIds', 'entitlementKeys', 'conditionsEnabled', 'conditions', 'conditionsMatch', 'conditionsInvert' ] ) {
		assert.ok( CONTRACT_KEYS.includes( key ), key );
	}
} );

test( 'odd values do not throw anywhere', () => {
	const odd = [ undefined, null, 0, '', 'x', 7, true, [], [ [] ], {}, { rules: null }, { rules: [ [] ] }, { mode: null, tierIds: null }, { conditions: 'logged_in', conditionsEnabled: true }, { __proto__: { rules: [ GOLD ] } } ];

	for ( const stored of odd ) {
		assert.doesNotThrow( () => {
			normalise( stored );
			isRestricted( stored );
			addRule( stored, GOLD );
			removeRule( stored, GOLD );
			setMatch( stored, 'all' );
			setInvert( stored, true );
			setFallback( stored, 2 );
			resetAccess( stored );
		}, serialised( stored ) );
	}
} );
