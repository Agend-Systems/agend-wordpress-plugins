/**
 * The logic behind the Agend Access block panel (src/access-model.mjs).
 *
 * Run with: node --test agend-content-access/tests/js/access-model.test.mjs
 *
 * What these protect is what ends up in a post. The panel is the only thing
 * standing between an author's clicks and the stored `agendAccess` object, so
 * the cases are about that object: a block that was only looked at stays
 * clean, a stale id cannot linger after a mode change, a key the panel does not
 * own survives an edit, and a stored value the panel cannot read neither
 * crashes it nor quietly loosens anything.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import {
	ATTRIBUTE,
	CONTRACT_KEYS,
	DEFAULT_STRINGS,
	EXCLUDED_BLOCKS,
	MODE,
	UNRECOGNISED,
	carriesAttribute,
	checklistRows,
	conditionChoices,
	entitlementItems,
	fallbackChoices,
	indicatorKind,
	isExcluded,
	normalise,
	normaliseVocabulary,
	planItems,
	sectionState,
	segmentItems,
	setConditions,
	setConditionsEnabled,
	setFallback,
	setInvert,
	setList,
	setMatch,
	setMode,
	toggleValue,
	withAccessAttribute,
} from '../../src/access-model.mjs';

const PLAN_A = '11111111-1111-4111-8111-111111111111';
const PLAN_B = '22222222-2222-4222-8222-222222222222';
const SEG_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

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
// Round trips: what is written, and that nothing lingers
// ---------------------------------------------------------------------------

test( 'inherit to a mode and back is {}, which serialises exactly like an untouched block', () => {
	const untouched = {};

	for ( const mode of [ MODE.MEMBERS, MODE.TIERS, MODE.SEGMENTS, MODE.ENTITLEMENTS ] ) {
		const there = setMode( untouched, mode );
		const back = setMode( there, MODE.INHERIT );

		assert.notDeepEqual( there, {}, `${ mode } is written` );
		assert.deepEqual( back, {}, `${ mode } and back is empty` );
		assert.equal( serialised( back ), serialised( untouched ) );
	}
} );

test( 'a full round trip through lists and conditions ends at {}', () => {
	let value = {};

	value = setMode( value, MODE.TIERS );
	value = setList( value, [ PLAN_A, PLAN_B ] );
	value = setConditionsEnabled( value, true );
	value = setConditions( value, [ 'agend_wordpress:logged_in' ] );
	value = setMatch( value, 'all' );
	value = setInvert( value, true );
	value = setFallback( value, '12' );

	assert.deepEqual( value, {
		mode: 'selected_tiers',
		tierIds: [ PLAN_A, PLAN_B ],
		conditionsEnabled: true,
		conditions: [ 'agend_wordpress:logged_in' ],
		conditionsMatch: 'all',
		conditionsInvert: true,
		fallbackId: 12,
	} );

	value = setConditionsEnabled( value, false );
	value = setMode( value, MODE.INHERIT );

	assert.deepEqual( value, {} );
} );

test( 'choosing a set-mode writes its list, empty, so the restriction is explicit', () => {
	assert.deepEqual( setMode( {}, MODE.TIERS ), { mode: 'selected_tiers', tierIds: [] } );
	assert.deepEqual( setMode( {}, MODE.SEGMENTS ), { mode: 'segments', segmentIds: [] } );
	assert.deepEqual( setMode( {}, MODE.ENTITLEMENTS ), { mode: 'selected_entitlements', entitlementKeys: [] } );
	assert.deepEqual( setMode( {}, MODE.MEMBERS ), { mode: 'active_member' }, 'no list for a mode that has none' );
} );

test( 'a default is never written out', () => {
	const on = setConditionsEnabled( {}, true );

	assert.deepEqual( on, { conditionsEnabled: true }, 'no empty conditions, no match, no invert, no fallback' );
	assert.deepEqual( setMatch( on, 'any' ), { conditionsEnabled: true } );
	assert.deepEqual( setInvert( on, false ), { conditionsEnabled: true } );
	assert.deepEqual( setFallback( on, 0 ), { conditionsEnabled: true } );
	assert.deepEqual( setFallback( on, '' ), { conditionsEnabled: true } );
	assert.deepEqual( setConditions( setConditions( on, [ 'a:b' ] ), [] ), { conditionsEnabled: true }, 'emptied list is removed' );
} );

test( 'switching mode drops the lists of every other mode', () => {
	const segments = setList( setMode( {}, MODE.SEGMENTS ), [ SEG_A ] );

	assert.deepEqual( segments, { mode: 'segments', segmentIds: [ SEG_A ] } );

	const tiers = setMode( segments, MODE.TIERS );

	assert.deepEqual( tiers, { mode: 'selected_tiers', tierIds: [] }, 'segmentIds cleared and tiers start empty' );
	assert.equal( 'segmentIds' in tiers, false );

	const members = setMode( segments, MODE.MEMBERS );

	assert.deepEqual( members, { mode: 'active_member' } );
} );

test( 'a stale list written by hand for another mode is dropped, not revived, when the mode changes', () => {
	const handWritten = { mode: 'selected_tiers', tierIds: [ PLAN_A ], segmentIds: [ SEG_A ] };

	assert.deepEqual( normalise( handWritten ).segmentIds, [], 'not shown while the mode is plans' );
	assert.deepEqual( setList( handWritten, [ PLAN_B ] ), { mode: 'selected_tiers', tierIds: [ PLAN_B ] }, 'dropped on the next edit' );
	assert.deepEqual( setMode( handWritten, MODE.SEGMENTS ), { mode: 'segments', segmentIds: [] }, 'not revived by switching to segments' );
} );

test( 'choosing the mode the block already has changes nothing', () => {
	const stored = { mode: 'selected_tiers', tierIds: [ PLAN_A ] };

	assert.deepEqual( setMode( stored, MODE.TIERS ), stored );
} );

test( 'switching conditions off drops every condition key; on again starts clean', () => {
	const full = { conditionsEnabled: true, conditions: [ 'x:y' ], conditionsMatch: 'all', conditionsInvert: true, fallbackId: 9 };
	const off = setConditionsEnabled( full, false );

	assert.deepEqual( off, {} );
	assert.deepEqual( setConditionsEnabled( off, true ), { conditionsEnabled: true } );
} );

test( 'condition controls do nothing while conditions are off', () => {
	assert.deepEqual( setConditions( {}, [ 'a:b' ] ), {} );
	assert.deepEqual( setMatch( {}, 'all' ), {} );
	assert.deepEqual( setInvert( {}, true ), {} );
	assert.deepEqual( setFallback( {}, 5 ), {} );
} );

test( 'conditions left in a block with the switch off are stale, and are dropped on the next write', () => {
	const stale = { mode: 'active_member', conditions: [ 'x:y' ], conditionsMatch: 'all' };

	assert.deepEqual( normalise( stale ).conditions, [] );
	assert.deepEqual( setMode( stale, MODE.TIERS ), { mode: 'selected_tiers', tierIds: [] } );
} );

test( 'a list takes strings only, once each, and a checkbox toggle keeps order', () => {
	const tiers = setMode( {}, MODE.TIERS );

	assert.deepEqual( setList( tiers, [ PLAN_A, 7, null, PLAN_A, PLAN_B ] ).tierIds, [ PLAN_A, PLAN_B ] );
	assert.deepEqual( toggleValue( [ 'a', 'b' ], 'c', true ), [ 'a', 'b', 'c' ] );
	assert.deepEqual( toggleValue( [ 'a', 'b', 'c' ], 'b', false ), [ 'a', 'c' ] );
	assert.deepEqual( toggleValue( [ 'a' ], 'a', true ), [ 'a' ], 'ticked twice is still once' );
	assert.deepEqual( toggleValue( undefined, 'a', true ), [ 'a' ] );
} );

test( 'a list change while the block has no set-mode is ignored', () => {
	assert.deepEqual( setList( {}, [ PLAN_A ] ), {} );
	assert.deepEqual( setList( { mode: 'active_member' }, [ PLAN_A ] ), { mode: 'active_member' } );
} );

test( 'the fallback accepts a post id, as a number or as the select gives it', () => {
	const on = setConditionsEnabled( {}, true );

	assert.equal( setFallback( on, 12 ).fallbackId, 12 );
	assert.equal( setFallback( on, '12' ).fallbackId, 12 );

	for ( const junk of [ -1, 1.5, 'abc', '12abc', null, undefined, NaN ] ) {
		assert.equal( 'fallbackId' in setFallback( on, junk ), false, String( junk ) );
	}
} );

test( 'nothing the panel writes uses a key outside the contract', () => {
	let value = {};

	value = setMode( value, MODE.ENTITLEMENTS );
	value = setList( value, [ 'platform.directory' ] );
	value = setConditionsEnabled( value, true );
	value = setConditions( value, [ 'agend_segments:vip' ] );
	value = setMatch( value, 'all' );
	value = setInvert( value, true );
	value = setFallback( value, 3 );

	Object.keys( value ).forEach( ( key ) => assert.ok( CONTRACT_KEYS.includes( key ), key ) );
} );

test( 'no setter mutates the value it is given', () => {
	const stored = deepFreeze( { mode: 'segments', segmentIds: [ SEG_A ], conditionsEnabled: true, conditions: [ 'a:b' ], extra: { keep: true } } );

	assert.doesNotThrow( () => {
		setMode( stored, MODE.TIERS );
		setList( stored, [ 'x' ] );
		setConditionsEnabled( stored, false );
		setConditions( stored, [ 'c:d' ] );
		setMatch( stored, 'all' );
		setInvert( stored, true );
		setFallback( stored, 4 );
		normalise( stored );
		indicatorKind( stored );
	} );
} );

// ---------------------------------------------------------------------------
// Keys the panel does not own
// ---------------------------------------------------------------------------

test( 'a key the panel does not own is kept, untouched, through every kind of edit', () => {
	const future = { shape: [ 1, { deep: true } ] };
	const stored = { mode: 'segments', segmentIds: [ SEG_A ], conditionsEnabled: true, newerRelease: future, flag: 'x' };

	const edits = [
		setMode( stored, MODE.TIERS ),
		setList( stored, [] ),
		setConditionsEnabled( stored, false ),
		setConditions( stored, [ 'a:b' ] ),
		setMatch( stored, 'all' ),
		setInvert( stored, true ),
		setFallback( stored, 2 ),
	];

	edits.forEach( ( next ) => {
		assert.equal( next.newerRelease, future, 'same value, not a copy' );
		assert.equal( next.flag, 'x' );
	} );
} );

test( 'collapsing to inherit leaves an object only when somebody else\'s key is still in it', () => {
	assert.deepEqual( setMode( { mode: 'active_member', newerRelease: 1 }, MODE.INHERIT ), { newerRelease: 1 } );
	assert.deepEqual( setMode( { mode: 'active_member' }, MODE.INHERIT ), {} );
} );

test( 'an unknown key alone reads as inherit with no conditions, as the server reads it', () => {
	const state = normalise( { newerRelease: 1 } );

	assert.equal( state.mode, MODE.INHERIT );
	assert.equal( state.unreadable, false );
	assert.equal( indicatorKind( { newerRelease: 1 } ), '' );
} );

// ---------------------------------------------------------------------------
// Reading what is stored
// ---------------------------------------------------------------------------

test( 'absent, null and {} all read as inherit with nothing on', () => {
	for ( const stored of [ undefined, null, {}, [] ] ) {
		const state = normalise( stored );

		assert.equal( state.mode, MODE.INHERIT );
		assert.equal( state.conditionsEnabled, false );
		assert.equal( state.unreadable, false );
		assert.equal( indicatorKind( stored ), '' );
	}
} );

test( 'a stored mode of inherit, or an empty string, is inherit', () => {
	assert.equal( normalise( { mode: 'inherit' } ).mode, MODE.INHERIT );
	assert.equal( normalise( { mode: '' } ).mode, MODE.INHERIT );
	assert.deepEqual( setMode( { mode: 'inherit' }, MODE.INHERIT ), {}, 'written back as the default, which is nothing' );
} );

test( 'a list is read for the mode that uses it, as strings only', () => {
	assert.deepEqual( normalise( { mode: 'selected_tiers', tierIds: [ PLAN_A, 5, null, PLAN_B, PLAN_A ] } ).tierIds, [ PLAN_A, PLAN_B ] );
	assert.deepEqual( normalise( { mode: 'selected_tiers', tierIds: 'not a list' } ).tierIds, [] );
	assert.deepEqual( normalise( { mode: 'selected_tiers' } ).tierIds, [], 'missing is an empty list, which restricts to nobody' );
} );

test( 'conditions are read only when the switch is the boolean true', () => {
	assert.equal( normalise( { conditionsEnabled: true } ).conditionsEnabled, true );

	for ( const off of [ 'yes', 'true', 1, 'on', false, null ] ) {
		assert.equal( normalise( { conditionsEnabled: off, conditions: [ 'a:b' ] } ).conditionsEnabled, false, String( off ) );
	}
} );

test( 'only the match value "all" reads as all', () => {
	assert.equal( normalise( { conditionsEnabled: true, conditionsMatch: 'all' } ).conditionsMatch, 'all' );
	assert.equal( normalise( { conditionsEnabled: true, conditionsMatch: 'ALL' } ).conditionsMatch, 'any' );
	assert.equal( normalise( { conditionsEnabled: true, conditionsMatch: 3 } ).conditionsMatch, 'any' );
} );

test( 'a fallback is a positive whole number, and nothing else', () => {
	const read = ( fallbackId ) => normalise( { conditionsEnabled: true, fallbackId } ).fallbackId;

	assert.equal( read( 7 ), 7 );

	for ( const junk of [ '7', 0, -3, 2.5, null, true, [] ] ) {
		assert.equal( read( junk ), 0, String( junk ) );
	}
} );

// ---------------------------------------------------------------------------
// Garbage
// ---------------------------------------------------------------------------

const GARBAGE = [
	[ 'a string', 'members' ],
	[ 'a number', 42 ],
	[ 'true', true ],
	[ 'false', false ],
	[ 'a non-empty list', [ 1, 2 ] ],
	[ 'a list of objects', [ { mode: 'active_member' } ] ],
	[ 'a numeric mode', { mode: 5 } ],
	[ 'an unknown mode', { mode: 'wat' } ],
	[ 'the public mode, which blocks do not have', { mode: 'public' } ],
	[ 'a mode object', { mode: { x: 1 } } ],
	[ 'an upper case mode', { mode: 'ACTIVE_MEMBER' } ],
	[ 'a list that is not a list', { mode: 'selected_tiers', tierIds: { 0: 'a' } } ],
	[ 'malformed conditions', { conditionsEnabled: true, conditions: [ 1, null, { x: 1 }, 'ok:fine' ] } ],
	[ 'conditions that are not a list', { conditionsEnabled: true, conditions: 'logged_in' } ],
	[ 'a deeply odd object', { mode: undefined, tierIds: undefined, conditions: undefined, fallbackId: {} } ],
];

for ( const [ label, stored ] of GARBAGE ) {
	test( `garbage does not crash the panel: ${ label }`, () => {
		const state = normalise( stored );

		for ( const field of [ 'mode', 'tierIds', 'segmentIds', 'entitlementKeys', 'conditionsEnabled', 'conditions', 'conditionsMatch', 'conditionsInvert', 'fallbackId', 'unreadable' ] ) {
			assert.ok( field in state, `state.${ field }` );
		}

		assert.ok( Array.isArray( state.tierIds ) && Array.isArray( state.conditions ) );

		assert.doesNotThrow( () => {
			indicatorKind( stored );
			checklistRows( [], state.tierIds );
			conditionChoices( null, state.conditions );
			conditionChoices( [], state.conditions );
			fallbackChoices( [], state.fallbackId );
			[
				( v ) => setMode( v, MODE.TIERS ),
				( v ) => setList( v, [ 'a' ] ),
				( v ) => setConditionsEnabled( v, true ),
				( v ) => setConditions( v, [ 'a:b' ] ),
				( v ) => setMatch( v, 'all' ),
				( v ) => setInvert( v, true ),
				( v ) => setFallback( v, 3 ),
			].forEach( ( edit ) => edit( stored ) );
		} );
	} );
}

test( 'an unreadable mode shows as the members-only state the server enforces, and says so', () => {
	for ( const stored of [ 'members', 42, true, false, [ 1 ], { mode: 'wat' }, { mode: 5 }, { mode: 'public' } ] ) {
		const state = normalise( stored );

		assert.equal( state.mode, UNRECOGNISED );
		assert.equal( state.unreadable, true );
		assert.equal( indicatorKind( stored ), 'policy', 'it restricts, so the canvas marks it' );
	}
} );

test( 'while the mode is unreadable every write is refused except choosing a mode', () => {
	for ( const stored of [ 'members', { mode: 'wat' }, { mode: 'wat', conditionsEnabled: true } ] ) {
		assert.equal( setList( stored, [ 'a' ] ), stored );
		assert.equal( setConditionsEnabled( stored, true ), stored );
		assert.equal( setConditions( stored, [ 'a:b' ] ), stored );
		assert.equal( setMatch( stored, 'all' ), stored );
		assert.equal( setInvert( stored, true ), stored );
		assert.equal( setFallback( stored, 3 ), stored );
	}
} );

test( 'choosing a mode repairs an unreadable one, and a string is replaced by an object', () => {
	assert.deepEqual( setMode( 'members', MODE.MEMBERS ), { mode: 'active_member' } );
	assert.deepEqual( setMode( { mode: 'wat' }, MODE.INHERIT ), {} );
	assert.deepEqual( setMode( 42, MODE.TIERS ), { mode: 'selected_tiers', tierIds: [] } );
	assert.deepEqual( setMode( [ 1, 2 ], MODE.INHERIT ), {} );
} );

test( 'a mode that is not one of the five is refused rather than written', () => {
	for ( const junk of [ 'wat', 'public', '', null, undefined, 5, UNRECOGNISED ] ) {
		const stored = { mode: 'active_member' };

		assert.equal( setMode( stored, junk ), stored, String( junk ) );
	}
} );

test( 'malformed conditions are flagged, and the next write replaces them', () => {
	const stored = { conditionsEnabled: true, conditions: [ 1, 'agend_wordpress:logged_in', null ] };
	const state = normalise( stored );

	assert.equal( state.unreadable, true );
	assert.deepEqual( state.conditions, [ 'agend_wordpress:logged_in' ] );
	assert.deepEqual( setMatch( stored, 'all' ), { conditionsEnabled: true, conditions: [ 'agend_wordpress:logged_in' ], conditionsMatch: 'all' } );
} );

test( 'duplicate conditions are not malformed, only repeated', () => {
	assert.equal( normalise( { conditionsEnabled: true, conditions: [ 'a:b', 'a:b' ] } ).unreadable, false );
} );

// ---------------------------------------------------------------------------
// The canvas indicator
// ---------------------------------------------------------------------------

test( 'the indicator says whether a block carries a policy, conditions, or both', () => {
	assert.equal( indicatorKind( {} ), '' );
	assert.equal( indicatorKind( undefined ), '' );
	assert.equal( indicatorKind( { mode: 'active_member' } ), 'policy' );
	assert.equal( indicatorKind( { mode: 'selected_tiers', tierIds: [] } ), 'policy' );
	assert.equal( indicatorKind( { conditionsEnabled: true } ), 'conditions' );
	assert.equal( indicatorKind( { mode: 'segments', conditionsEnabled: true } ), 'both' );
	assert.equal( indicatorKind( { mode: 'inherit' } ), '' );
	assert.equal( indicatorKind( { conditions: [ 'a:b' ] } ), '', 'conditions with the switch off do nothing' );
} );

// ---------------------------------------------------------------------------
// The catalogue as the panel lists it
// ---------------------------------------------------------------------------

const plans = {
	items: [
		{ id: PLAN_A, name: 'Full Member', slug: 'full', type: 'individual', active: true },
		{ id: PLAN_B, name: 'Retired', slug: 'retired', type: 'individual', active: false },
	],
	stale: false,
	error: null,
};

test( 'a section is known when it has a list, even a stale one, and not when it failed empty', () => {
	assert.equal( sectionState( plans ).known, true );
	assert.equal( sectionState( { items: [], stale: false, error: null } ).known, true, 'a site with none is still known' );
	assert.equal( sectionState( { items: [ { id: 'x' } ], stale: true, error: 'could not refresh' } ).known, true );
	assert.equal( sectionState( { items: [], stale: false, error: 'missing the crm.entitlements.browse scope' } ).known, false );
	assert.equal( sectionState( undefined ).known, false );
	assert.equal( sectionState( 'nope' ).known, false );
	assert.equal( sectionState( { items: 'nope' } ).known, false );
	assert.equal( sectionState( { items: [], error: 'x' } ).error, 'x' );
	assert.equal( sectionState( { items: [], error: null } ).error, '' );
} );

test( 'plans, segments and entitlements are read by name, and malformed entries are skipped', () => {
	assert.deepEqual( planItems( plans ).map( ( item ) => [ item.value, item.name, item.offered ] ), [
		[ PLAN_A, 'Full Member', true ],
		[ PLAN_B, 'Retired', false ],
	] );

	assert.deepEqual(
		segmentItems( { items: [ { id: SEG_A, slug: 'vip', name: 'VIP' }, { id: 'b', slug: 'no-name' }, { slug: 'no-id' }, null, 'x' ] } ).map( ( item ) => [ item.value, item.name ] ),
		[ [ SEG_A, 'VIP' ], [ 'b', 'no-name' ] ]
	);

	const entitlements = entitlementItems( {
		items: [
			{ gate_key: 'platform.directory', name: 'Directory access', description: 'Browse the directory', kind: 'platform-gate' },
			{ gate_key: 'agend.vip', name: 'VIP', description: '', kind: 'entitlement' },
			{ gate_key: 'legacy.thing', name: 'Legacy', kind: 'unrecognised' },
			{ gate_key: 'weird.kind', name: 'Weird', kind: 'something-new' },
			{ name: 'no key' },
		],
	} );

	assert.deepEqual( entitlements.map( ( item ) => [ item.value, item.kind ] ), [
		[ 'platform.directory', 'platform-gate' ],
		[ 'agend.vip', 'entitlement' ],
		[ 'legacy.thing', 'unrecognised' ],
		[ 'weird.kind', 'entitlement' ],
	] );
} );

test( 'a checklist lists what is offered, in the catalogue order, and ticks what is chosen', () => {
	const rows = checklistRows( planItems( { items: [ { id: 'a', name: 'A', active: true }, { id: 'b', name: 'B', active: true }, { id: 'c', name: 'C', active: true } ] } ), [ 'c', 'a' ] );

	assert.deepEqual( rows.map( ( row ) => [ row.value, row.checked, row.status ] ), [
		[ 'a', true, 'available' ],
		[ 'b', false, 'available' ],
		[ 'c', true, 'available' ],
	] );
} );

test( 'a stored choice the catalogue no longer offers comes first, ticked, and marked unavailable', () => {
	const rows = checklistRows( planItems( plans ), [ PLAN_A, 'deleted-plan-id' ] );

	assert.deepEqual( rows.map( ( row ) => [ row.value, row.label, row.checked, row.status ] ), [
		[ 'deleted-plan-id', 'deleted-plan-id (unavailable)', true, 'unavailable' ],
		[ PLAN_A, 'Full Member', true, 'available' ],
	] );
} );

test( 'an inactive plan is not offered, but a block that names one shows it by name, first and ticked', () => {
	const offered = checklistRows( planItems( plans ), [] );

	assert.deepEqual( offered.map( ( row ) => row.value ), [ PLAN_A ], 'not offered for a new choice' );

	const rows = checklistRows( planItems( plans ), [ PLAN_B ] );

	assert.deepEqual( rows[ 0 ], { value: PLAN_B, label: 'Retired (no longer active)', help: '', checked: true, status: 'unavailable' } );
	assert.equal( rows.length, 2 );
} );

test( 'several unavailable choices keep their stored order, ahead of every offered one', () => {
	const rows = checklistRows( planItems( plans ), [ 'z-gone', PLAN_A, 'a-gone' ] );

	assert.deepEqual( rows.map( ( row ) => row.value ), [ 'z-gone', 'a-gone', PLAN_A ] );
} );

test( 'a platform gate is labelled, an unrecognised key keeps its own name, and the description is help', () => {
	const rows = checklistRows(
		entitlementItems( {
			items: [
				{ gate_key: 'platform.directory', name: 'Directory access', description: 'Browse the directory', kind: 'platform-gate' },
				{ gate_key: 'legacy.thing', name: 'Legacy thing', kind: 'unrecognised' },
				{ gate_key: 'agend.vip', name: 'VIP', kind: 'entitlement' },
			],
		} ),
		[]
	);

	assert.deepEqual( rows.map( ( row ) => row.label ), [ 'Platform: Directory access', 'Legacy thing', 'VIP' ] );
	assert.equal( rows[ 0 ].help, 'Browse the directory' );
} );

test( 'a platform gate that is no longer offered is still labelled when it is stored', () => {
	const rows = checklistRows( [], [ 'platform.gone' ] );

	assert.equal( rows[ 0 ].label, 'platform.gone (unavailable)' );
} );

test( 'when the list could not be loaded a stored choice is shown as it is, not called unavailable', () => {
	const rows = checklistRows( [], [ PLAN_A, SEG_A ], { known: false } );

	assert.deepEqual( rows.map( ( row ) => [ row.value, row.label, row.checked, row.status ] ), [
		[ PLAN_A, PLAN_A, true, 'unverified' ],
		[ SEG_A, SEG_A, true, 'unverified' ],
	] );
} );

test( 'checklist rows survive junk in the catalogue and the selection', () => {
	assert.doesNotThrow( () => checklistRows( undefined, undefined ) );
	assert.doesNotThrow( () => checklistRows( null, 'nope', { known: false } ) );
	assert.deepEqual( checklistRows( [], [ 'a', 'a', 1, null ] ).map( ( row ) => row.value ), [ 'a' ] );
} );

test( 'labels built from catalogue text are not read as replacement patterns', () => {
	const rows = checklistRows( [ { value: 'p', name: 'Price $& more $1', offered: true, kind: 'platform-gate' } ], [] );

	assert.equal( rows[ 0 ].label, 'Platform: Price $& more $1' );
} );

test( 'translated copy is used for the labels the model composes', () => {
	const strings = { ...DEFAULT_STRINGS, unavailable: 'Nicht verfuegbar: %s' };

	assert.equal( checklistRows( [], [ 'x' ], { strings } )[ 0 ].label, 'Nicht verfuegbar: x' );
} );

// ---------------------------------------------------------------------------
// The condition vocabulary
// ---------------------------------------------------------------------------

const vocabulary = normaliseVocabulary( {
	groups: [
		{
			provider: 'agend_wordpress',
			label: 'WordPress user',
			conditions: [
				{ value: 'agend_wordpress:logged_in', label: 'Signed in' },
				{ value: 'agend_wordpress:logged_out', label: 'Signed out' },
			],
		},
		{ provider: 'agend_segments', label: 'Segments', conditions: [] },
	],
	fallbacks: [ { id: 12, title: 'Join us' } ],
} );

test( 'the vocabulary is read as groups and fallbacks, and junk in it is skipped', () => {
	assert.equal( vocabulary.groups.length, 2 );
	assert.equal( vocabulary.groups[ 0 ].options[ 0 ].value, 'agend_wordpress:logged_in' );
	assert.deepEqual( vocabulary.groups[ 1 ].options, [], 'an empty group is kept' );

	const messy = normaliseVocabulary( {
		groups: [ null, 'x', { label: 'no provider' }, { provider: 'p', conditions: [ null, { value: 5 }, { value: 'p:a' } ] } ],
		fallbacks: [ { id: '3', title: 'string id' }, { id: -1 }, { id: 4 }, null ],
	} );

	assert.deepEqual( messy.groups, [ { provider: 'p', label: 'p', options: [ { value: 'p:a', label: 'p:a' } ] } ] );
	assert.deepEqual( messy.fallbacks, [ { id: 4, title: '4' } ] );
} );

test( 'a vocabulary response that is not the documented shape is a failed load', () => {
	for ( const junk of [ null, undefined, 'x', 3, [], {}, { groups: 'nope' } ] ) {
		assert.equal( normaliseVocabulary( junk ), null, String( junk ) );
	}
} );

test( 'a stored condition the vocabulary does not offer is pulled out first, ticked and marked', () => {
	const choices = conditionChoices( vocabulary.groups, [ 'agend_wordpress:logged_in', 'agend_segments:deleted-slug' ] );

	assert.deepEqual( choices.stray, [
		{ value: 'agend_segments:deleted-slug', label: 'agend_segments:deleted-slug (unavailable)', help: '', checked: true, status: 'unavailable' },
	] );
	assert.deepEqual( choices.groups.map( ( group ) => group.provider ), [ 'agend_wordpress', 'agend_segments' ], 'grouped by provider, in order' );
	assert.deepEqual( choices.groups[ 0 ].rows.map( ( row ) => [ row.value, row.checked ] ), [
		[ 'agend_wordpress:logged_in', true ],
		[ 'agend_wordpress:logged_out', false ],
	] );
} );

test( 'when the vocabulary could not be loaded every stored condition is shown as it is', () => {
	const choices = conditionChoices( null, [ 'agend_wordpress:logged_in' ] );

	assert.deepEqual( choices.groups, [] );
	assert.deepEqual( choices.stray.map( ( row ) => [ row.label, row.status ] ), [ [ 'agend_wordpress:logged_in', 'unverified' ] ] );
} );

test( '"Show instead" offers Nothing first, then the pages, and keeps a stored page that is not on the list', () => {
	assert.deepEqual( fallbackChoices( vocabulary.fallbacks, 0 ), [
		{ value: '', label: 'Nothing' },
		{ value: '12', label: 'Join us' },
	] );

	assert.deepEqual( fallbackChoices( vocabulary.fallbacks, 99 ), [
		{ value: '', label: 'Nothing' },
		{ value: '99', label: 'Content #99 (not in the list)' },
		{ value: '12', label: 'Join us' },
	] );

	assert.deepEqual( fallbackChoices( [], 0 ), [ { value: '', label: 'Nothing' } ] );
} );
