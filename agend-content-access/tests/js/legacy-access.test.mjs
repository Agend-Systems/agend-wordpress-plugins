/**
 * Reading the first shape of the attribute as rules (src/legacy-access.mjs).
 *
 * Run with: node --test agend-content-access/tests/js/legacy-access.test.mjs
 *
 * The property that matters is that this never widens. A block saved by the
 * first panel is shown as rules for an author to read, and a flat rule list
 * that showed it more loosely than the server enforces it would mean an author
 * saving the block would publish it to people it was hidden from. So every
 * case that one list cannot say exactly is `unreadable` (hidden from everyone,
 * reset only) or, for an audience and conditions together, matched with All
 * and flagged for the author.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { ENTITLEMENT_KEY_PATTERN, LEGACY_KEYS, MEMBER_RULE, UUID_PATTERN, hasLegacyKeys, readLegacy } from '../../src/legacy-access.mjs';
import { normalise } from '../../src/access-model.mjs';

const PLAN_A = '11111111-1111-4111-8111-111111111111';
const PLAN_B = '22222222-2222-4222-8222-222222222222';
const SEG_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

const SIGNED_IN = 'agend_wordpress:logged_in';
const SIGNED_OUT = 'agend_wordpress:logged_out';
const EDITOR = 'agend_wordpress:role_editor';

test( 'which keys belong to the first shape', () => {
	assert.deepEqual( [ ...LEGACY_KEYS ], [ 'mode', 'tierIds', 'segmentIds', 'entitlementKeys', 'conditionsEnabled', 'conditions', 'conditionsMatch', 'conditionsInvert' ] );
	assert.equal( LEGACY_KEYS.includes( 'fallbackId' ), false, 'shared with the current shape' );
} );

test( 'a value is first-shape when it sets any of those keys, and a null or missing one does not count', () => {
	assert.equal( hasLegacyKeys( { mode: 'active_member' } ), true );
	assert.equal( hasLegacyKeys( { conditions: [] } ), true );
	assert.equal( hasLegacyKeys( { mode: null, conditions: undefined } ), false );
	assert.equal( hasLegacyKeys( { rules: [ 'a:b' ], fallbackId: 3 } ), false );
	assert.equal( hasLegacyKeys( {} ), false );
	assert.equal( hasLegacyKeys( 'mode' ), false );
	assert.equal( hasLegacyKeys( [ 'mode' ] ), false );
	assert.equal( hasLegacyKeys( null ), false );
} );

// ---------------------------------------------------------------------------
// The conversion table
// ---------------------------------------------------------------------------

const TABLE = [
	[ 'inherit', { mode: 'inherit' }, [] ],
	[ 'no mode at all', { conditionsEnabled: false }, [] ],
	[ 'an empty mode', { mode: '' }, [] ],
	[ 'active_member', { mode: 'active_member' }, [ MEMBER_RULE ] ],
	[ 'selected_tiers, one per plan', { mode: 'selected_tiers', tierIds: [ PLAN_A, PLAN_B ] }, [ 'agend_membership:' + PLAN_A, 'agend_membership:' + PLAN_B ] ],
	[ 'segments', { mode: 'segments', segmentIds: [ SEG_A ] }, [ 'agend_segments:' + SEG_A ] ],
	[ 'selected_entitlements', { mode: 'selected_entitlements', entitlementKeys: [ 'premium.directory', 'directory.access' ] }, [ 'agend_entitlements:premium.directory', 'agend_entitlements:directory.access' ] ],
	[ 'conditions, as stored', { conditionsEnabled: true, conditions: [ SIGNED_IN, EDITOR ] }, [ SIGNED_IN, EDITOR ] ],
	[ 'conditions with the switch off do nothing', { conditionsEnabled: false, conditions: [ SIGNED_IN ] }, [] ],
	[ 'conditions with the switch missing do nothing', { conditions: [ SIGNED_IN ] }, [] ],
	[ 'switched on with nothing to check is no rule', { conditionsEnabled: true }, [] ],
	[ 'switched on with an empty list is no rule', { conditionsEnabled: true, conditions: [] }, [] ],
	[ 'repeats are read once', { mode: 'selected_tiers', tierIds: [ PLAN_A, PLAN_A ] }, [ 'agend_membership:' + PLAN_A ] ],
	[ 'a slug condition is kept as it is', { conditionsEnabled: true, conditions: [ 'agend_membership:gold' ] }, [ 'agend_membership:gold' ] ],
];

for ( const [ label, stored, rules ] of TABLE ) {
	test( `converts: ${ label }`, () => {
		const read = readLegacy( stored );

		assert.equal( read.unreadable, false );
		assert.deepEqual( read.rules, rules );
		assert.equal( read.twoPart, false );

		// And through the panel's own reading.
		assert.deepEqual( normalise( stored ).rules, rules );
	} );
}

test( 'an audience in a set-mode matches any of its rules, so Match is Any', () => {
	const read = readLegacy( { mode: 'selected_tiers', tierIds: [ PLAN_A, PLAN_B ] } );

	assert.equal( read.match, 'any' );
	assert.equal( read.invert, false );
} );

// ---------------------------------------------------------------------------
// An entry the server's pattern refuses
// ---------------------------------------------------------------------------

/**
 * The server drops an id that is not a UUID (a plan or a segment) or a gate key
 * (an entitlement), and reads the rest. A panel that showed such an id would have
 * the first edit write it into the new shape, where the server ACTS on it: a slug
 * evaluates. So any entry the patterns refuse makes the list unreadable, and the
 * panel never shows a list it cannot show exactly.
 */
const REFUSED = [
	[ 'a plan slug instead of its id', { mode: 'selected_tiers', tierIds: [ 'gold' ] } ],
	[ 'a plan slug beside a plan id', { mode: 'selected_tiers', tierIds: [ 'gold', PLAN_A ] } ],
	[ 'a segment slug, which the new shape would evaluate', { mode: 'segments', segmentIds: [ 'vip' ] } ],
	[ 'a segment id that is one character short', { mode: 'segments', segmentIds: [ SEG_A.slice( 1 ) ] } ],
	[ 'an id with a character that is not hex', { mode: 'selected_tiers', tierIds: [ PLAN_A.replace( '1111111', 'zzzzzzz' ) ] } ],
	[ 'an id in braces', { mode: 'selected_tiers', tierIds: [ '{' + PLAN_A + '}' ] } ],
	[ 'an id with a newline inside it', { mode: 'selected_tiers', tierIds: [ PLAN_A.slice( 0, 8 ) + '\n' + PLAN_A.slice( 8 ) ] } ],
	[ 'an id with a no-break space at its end, which PHP does not trim', { mode: 'selected_tiers', tierIds: [ PLAN_A + '\u00a0' ] } ],
	[ 'a plan id beside an entry that is not text', { mode: 'selected_tiers', tierIds: [ PLAN_A, 7 ] } ],
	[ 'a segment id beside null', { mode: 'segments', segmentIds: [ SEG_A, null ] } ],
	[ 'a segment id beside an empty text', { mode: 'segments', segmentIds: [ SEG_A, '' ] } ],
	[ 'an entitlement key in upper case', { mode: 'selected_entitlements', entitlementKeys: [ 'Directory.access' ] } ],
	[ 'an entitlement key that starts with a digit', { mode: 'selected_entitlements', entitlementKeys: [ '1directory.access' ] } ],
	[ 'an entitlement key that is two characters', { mode: 'selected_entitlements', entitlementKeys: [ 'ab' ] } ],
	[ 'an entitlement key that ends with a dot', { mode: 'selected_entitlements', entitlementKeys: [ 'directory.' ] } ],
	[ 'an entitlement key with a hyphen', { mode: 'selected_entitlements', entitlementKeys: [ 'directory-access' ] } ],
	[ 'an entitlement key that is 65 characters', { mode: 'selected_entitlements', entitlementKeys: [ 'a'.repeat( 65 ) ] } ],
	[ 'a plan id given as an entitlement key', { mode: 'selected_entitlements', entitlementKeys: [ PLAN_A ] } ],
	[ 'an entitlement key given as a plan id', { mode: 'selected_tiers', tierIds: [ 'directory.access' ] } ],
];

for ( const [ label, stored ] of REFUSED ) {
	test( `an audience with ${ label } is unreadable, never shown as a rule`, () => {
		const read = readLegacy( stored );

		assert.equal( read.unreadable, true );
		assert.deepEqual( read.rules, [] );
		assert.equal( normalise( stored ).unreadable, true );
	} );
}

/** The patterns are the server's own, so they are read from it: they cannot drift apart unseen. */
test( 'the id and gate key patterns are the ones the server uses', () => {
	const php = readFileSync( new URL( '../../includes/class-agend-content-access-policy.php', import.meta.url ), 'utf8' );
	const server = ( name ) => {
		const match = php.match( new RegExp( 'const ' + name + " = '/(.+)/D';" ) );

		assert.notEqual( match, null, name + ' is declared' );

		return match[ 1 ];
	};

	assert.equal( UUID_PATTERN.source, server( 'UUID_PATTERN' ) );
	assert.equal( ENTITLEMENT_KEY_PATTERN.source, server( 'ENTITLEMENT_KEY_PATTERN' ) );
	assert.equal( UUID_PATTERN.flags, '', 'no multiline flag, so $ is the end of the text, as the D modifier has it' );
	assert.equal( ENTITLEMENT_KEY_PATTERN.flags, '' );
} );

test( 'what the patterns accept is read, trimmed as PHP trims, and the hex digits may be either case', () => {
	assert.deepEqual( readLegacy( { mode: 'selected_tiers', tierIds: [ PLAN_A.toUpperCase() ] } ).rules, [ 'agend_membership:' + PLAN_A.toUpperCase() ] );
	assert.deepEqual( readLegacy( { mode: 'selected_tiers', tierIds: [ ' \t' + PLAN_A + '\n\r\0\x0B' ] } ).rules, [ 'agend_membership:' + PLAN_A ] );
	assert.deepEqual( readLegacy( { mode: 'selected_entitlements', entitlementKeys: [ 'a.b', 'a' + '_x'.repeat( 31 ) + '1' ] } ).rules.length, 2 );
	assert.equal( readLegacy( { mode: 'selected_entitlements', entitlementKeys: [ 'a' + 'b'.repeat( 62 ) + 'c' ] } ).unreadable, false, '64 characters is the longest' );
} );

test( 'a value that is padded with a character only JavaScript trims is not read as the id', () => {
	// String.prototype.trim would strip this and the id would match. PHP's trim() leaves
	// it, the pattern refuses it and the server drops it.
	assert.equal( readLegacy( { mode: 'segments', segmentIds: [ '\u00a0' + SEG_A ] } ).unreadable, true );
} );

test( 'conditions carry their Match, Reverse and replacement content with them', () => {
	const read = readLegacy( { conditionsEnabled: true, conditions: [ SIGNED_OUT, EDITOR ], conditionsMatch: 'all', conditionsInvert: true, fallbackId: 12 } );

	assert.deepEqual( read, { rules: [ SIGNED_OUT, EDITOR ], match: 'all', invert: true, fallbackId: 12, twoPart: false, unreadable: false } );
} );

test( 'Match, Reverse and the replacement are read only while conditions are switched on', () => {
	const read = readLegacy( { mode: 'active_member', conditionsEnabled: false, conditionsMatch: 'all', conditionsInvert: true, fallbackId: 12 } );

	assert.deepEqual( read, { rules: [ MEMBER_RULE ], match: 'any', invert: false, fallbackId: 0, twoPart: false, unreadable: false } );
} );

test( 'only the boolean true reverses, only "all" is all, and a replacement is a positive whole number', () => {
	const read = ( extra ) => readLegacy( Object.assign( { conditionsEnabled: true, conditions: [ SIGNED_IN ] }, extra ) );

	assert.equal( read( { conditionsInvert: 'yes' } ).invert, false );
	assert.equal( read( { conditionsMatch: 'ALL' } ).match, 'any' );

	for ( const junk of [ '7', 0, -1, 2.5, null, true ] ) {
		assert.equal( read( { fallbackId: junk } ).fallbackId, 0, String( junk ) );
	}

	assert.equal( read( { fallbackId: 7 } ).fallbackId, 7 );
} );

test( 'a replacement that was never shown is not carried: it only ever showed to people the conditions excluded', () => {
	assert.equal( readLegacy( { mode: 'active_member', conditionsEnabled: true, fallbackId: 5 } ).fallbackId, 0 );
	assert.equal( readLegacy( { conditionsEnabled: true, conditions: [], fallbackId: 5 } ).fallbackId, 0 );
	assert.equal( readLegacy( { mode: 'active_member', fallbackId: 5 } ).fallbackId, 0 );
} );

// ---------------------------------------------------------------------------
// An audience and conditions together
// ---------------------------------------------------------------------------

test( 'an audience and conditions are two sets that must both pass: shown joined, matched with All, flagged', () => {
	const read = readLegacy( { mode: 'active_member', conditionsEnabled: true, conditions: [ EDITOR ], conditionsMatch: 'all', fallbackId: 3 } );

	assert.deepEqual( read, { rules: [ MEMBER_RULE, EDITOR ], match: 'all', invert: false, fallbackId: 3, twoPart: true, unreadable: false } );
} );

test( 'the joined rules are never looser than the pair: All needs every one of them', () => {
	// "any of these two plans" AND "signed in" is what the server enforces. The
	// list shown asks for both plans and the sign-in, which is stricter, never
	// more open.
	const read = readLegacy( { mode: 'selected_tiers', tierIds: [ PLAN_A, PLAN_B ], conditionsEnabled: true, conditions: [ SIGNED_IN ] } );

	assert.equal( read.match, 'all' );
	assert.deepEqual( read.rules, [ 'agend_membership:' + PLAN_A, 'agend_membership:' + PLAN_B, SIGNED_IN ] );
	assert.equal( read.twoPart, true );
} );

test( 'an audience with conditions that were switched on but empty is not two-part', () => {
	const read = readLegacy( { mode: 'active_member', conditionsEnabled: true, conditions: [] } );

	assert.deepEqual( read.rules, [ MEMBER_RULE ] );
	assert.equal( read.twoPart, false );
} );

test( 'an audience and the same rule in the conditions is one rule', () => {
	const read = readLegacy( { mode: 'active_member', conditionsEnabled: true, conditions: [ MEMBER_RULE, EDITOR ] } );

	assert.deepEqual( read.rules, [ MEMBER_RULE, EDITOR ] );
} );

// ---------------------------------------------------------------------------
// What one list cannot say, so is never shown loosely
// ---------------------------------------------------------------------------

const CANNOT = [
	[ 'a set-mode with no list', { mode: 'selected_tiers' } ],
	[ 'a set-mode with an empty list, which hides it from everyone', { mode: 'selected_tiers', tierIds: [] } ],
	[ 'a set-mode whose list holds nothing usable', { mode: 'segments', segmentIds: [ 1, null, '' ] } ],
	[ 'a set-mode whose list is not a list', { mode: 'selected_entitlements', entitlementKeys: { 0: 'a' } } ],
	[ 'a list for the wrong mode only', { mode: 'segments', tierIds: [ PLAN_A ] } ],
	[ 'a mode this version does not know', { mode: 'wat' } ],
	[ 'the public mode, which blocks do not have', { mode: 'public' } ],
	[ 'a mode that is not text', { mode: 5 } ],
	[ 'a mode that is an object', { mode: { x: 1 } } ],
	[ 'an upper case mode', { mode: 'ACTIVE_MEMBER' } ],
	[ 'conditions that hold a number', { conditionsEnabled: true, conditions: [ SIGNED_IN, 1 ] } ],
	[ 'conditions that hold null', { conditionsEnabled: true, conditions: [ null ] } ],
	[ 'conditions that hold an empty text', { conditionsEnabled: true, conditions: [ '' ] } ],
	[ 'conditions that are not a list', { conditionsEnabled: true, conditions: 'logged_in' } ],
	[ 'conditions that are an object', { conditionsEnabled: true, conditions: { 0: SIGNED_IN } } ],
	[ 'switched on, empty and reversed, which hides it from everyone', { conditionsEnabled: true, conditionsInvert: true } ],
	[ 'switched on, empty list and reversed', { conditionsEnabled: true, conditions: [], conditionsInvert: true } ],
	[ 'an audience and conditions that are reversed, which one list cannot say', { mode: 'active_member', conditionsEnabled: true, conditions: [ EDITOR ], conditionsInvert: true } ],
	[ 'an audience, and reversed conditions that are empty', { mode: 'active_member', conditionsEnabled: true, conditionsInvert: true } ],
];

for ( const [ label, stored ] of CANNOT ) {
	test( `never shown loosely, so unreadable: ${ label }`, () => {
		const read = readLegacy( stored );

		assert.equal( read.unreadable, true );
		assert.deepEqual( read.rules, [] );
		assert.equal( normalise( stored ).unreadable, true );
	} );
}

test( 'reversed conditions with nothing else are fine: they are a rule list', () => {
	const read = readLegacy( { conditionsEnabled: true, conditions: [ 'agend_membership:is_member' ], conditionsInvert: true } );

	assert.deepEqual( read, { rules: [ 'agend_membership:is_member' ], match: 'any', invert: true, fallbackId: 0, twoPart: false, unreadable: false } );
} );
