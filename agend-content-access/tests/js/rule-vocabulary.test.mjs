/**
 * The rules an author can choose from (src/rule-vocabulary.mjs): reading the
 * route's response, labelling chips, and the search behind the picker.
 *
 * Run with: node --test agend-content-access/tests/js/rule-vocabulary.test.mjs
 *
 * What these protect: a stored rule the vocabulary does not offer stays on the
 * block and says so, instead of being dropped; the picker never renders more
 * than 50 results however many entitlements a site has, and filters in memory
 * so typing costs no request; and a search finds what an author would expect to
 * find it by, not just a prefix of one word.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { DEFAULT_STRINGS } from '../../src/copy.mjs';
import {
	GROUP_ORDER,
	MAX_RESULTS,
	PREVIEW_SIZE,
	buildIndex,
	describeRules,
	fallbackChoices,
	filterChoices,
	fold,
	normaliseVocabulary,
	ruleLabel,
	searchRules,
} from '../../src/rule-vocabulary.mjs';

const PLAN_GOLD = '11111111-1111-4111-8111-111111111111';
const PLAN_OLD = '22222222-2222-4222-8222-222222222222';
const SEG_BOARD = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

/** The documented response, small. */
function response( overrides = {} ) {
	return Object.assign(
		{
			groups: [
				{
					key: 'agend_membership',
					label: 'Membership',
					stale: false,
					items: [
						{ value: 'agend_membership:is_member', label: 'Any current member', kind: 'member' },
						{ value: 'agend_membership:' + PLAN_GOLD, label: 'Gold', kind: 'plan', aliases: [ 'agend_membership:gold' ] },
						{ value: 'agend_membership:' + PLAN_OLD, label: 'Retired', kind: 'plan', inactive: true },
					],
				},
				{
					key: 'agend_segments',
					label: 'Segments',
					stale: false,
					items: [ { value: 'agend_segments:' + SEG_BOARD, label: 'Board', kind: 'segment', aliases: [ 'agend_segments:board' ] } ],
				},
				{
					key: 'agend_entitlements',
					label: 'Entitlements',
					stale: false,
					items: [
						{ value: 'agend_entitlements:directory.access', label: 'Directory access', kind: 'platform', description: 'Browse the directory' },
						{ value: 'agend_entitlements:premium.directory', label: 'Premium directory', kind: 'entitlement' },
					],
				},
				{
					key: 'agend_wordpress',
					label: 'WordPress',
					stale: false,
					items: [
						{ value: 'agend_wordpress:logged_in', label: 'Signed in', kind: 'login' },
						{ value: 'agend_wordpress:logged_out', label: 'Signed out', kind: 'login' },
						{ value: 'agend_wordpress:role_editor', label: 'Editor', kind: 'role' },
					],
				},
			],
			fallbacks: [ { id: 12, title: 'Join us', type: 'page' } ],
			errors: [],
		},
		overrides
	);
}

const indexOf = ( overrides ) => buildIndex( normaliseVocabulary( response( overrides ) ) );

/** A site with a lot of entitlements. */
function large( count ) {
	const items = [];

	for ( let n = 0; n < count; n++ ) {
		items.push( {
			value: 'agend_entitlements:gate.' + n,
			label: 'Entitlement number ' + n + ( n % 7 === 0 ? ' gold' : '' ),
			kind: 'entitlement',
		} );
	}

	return response( { groups: response().groups.map( ( group ) => ( group.key === 'agend_entitlements' ? Object.assign( {}, group, { items } ) : group ) ) } );
}

const labels = ( result ) => result.groups.map( ( group ) => group.items.map( ( item ) => item.chip ) );

// ---------------------------------------------------------------------------
// Reading the response
// ---------------------------------------------------------------------------

test( 'the response is read as groups, fallbacks and errors', () => {
	const vocabulary = normaliseVocabulary( response() );

	assert.deepEqual( vocabulary.groups.map( ( group ) => group.key ), [ 'agend_membership', 'agend_segments', 'agend_entitlements', 'agend_wordpress' ] );
	assert.equal( vocabulary.groups[ 1 ].items[ 0 ].kind, 'segment' );
	assert.deepEqual( vocabulary.groups[ 1 ].items[ 0 ].aliases, [ 'agend_segments:board' ] );
	assert.deepEqual( vocabulary.fallbacks, [ { id: 12, title: 'Join us', type: 'page' } ], 'the kind is kept, a redirect is offered pages only' );
	assert.deepEqual( vocabulary.errors, [] );
} );

test( 'junk in the response is skipped, not repaired', () => {
	const vocabulary = normaliseVocabulary( {
		groups: [
			'nope',
			{ label: 'No key' },
			{ key: 'a', items: [ null, { label: 'No value' }, { value: 'a:one' }, { value: 'a:one', label: 'Twice' }, { value: 'a:two', label: 'Two', aliases: [ 'a:2', 5, '' ], kind: 7, inactive: 'yes' } ] },
			{ key: 'b', label: 'B', items: 'not a list' },
		],
		fallbacks: [ null, { id: 0, title: 'Zero' }, { id: '3', title: 'String id' }, { id: 4 }, { id: 5, title: 'Five', type: 'pattern' }, { id: 6, title: 'Six', type: 7 } ],
		errors: [ null, { message: '' }, { group: 'a', message: 'Scope missing' }, { message: 'No group' } ],
	} );

	assert.deepEqual( vocabulary.groups.map( ( group ) => group.key ), [ 'a', 'b' ] );
	assert.equal( vocabulary.groups[ 0 ].label, 'a', 'labelled by its key when it has none' );
	assert.deepEqual( vocabulary.groups[ 0 ].items.map( ( item ) => item.value ), [ 'a:one', 'a:two' ], 'a repeated rule is offered once' );
	assert.equal( vocabulary.groups[ 0 ].items[ 0 ].label, 'a:one', 'labelled by its value when it has none' );
	assert.equal( vocabulary.groups[ 0 ].items[ 1 ].kind, 'other' );
	assert.deepEqual( vocabulary.groups[ 0 ].items[ 1 ].aliases, [ 'a:2' ] );
	assert.equal( vocabulary.groups[ 0 ].items[ 1 ].inactive, false, 'only a boolean true is inactive' );
	assert.deepEqual( vocabulary.groups[ 1 ].items, [] );
	assert.deepEqual( vocabulary.fallbacks, [ { id: 4, title: '4', type: '' }, { id: 5, title: 'Five', type: 'pattern' }, { id: 6, title: 'Six', type: '' } ] );
	assert.deepEqual( vocabulary.errors, [ { group: 'a', message: 'Scope missing' }, { group: '', message: 'No group' } ] );
} );

test( 'a response that is not the documented shape is a failed load', () => {
	for ( const bad of [ null, undefined, 'x', 3, [], {}, { groups: 'nope' }, { groups: {} }, { fallbacks: [] } ] ) {
		assert.equal( normaliseVocabulary( bad ), null, JSON.stringify( bad ) );
	}

	assert.deepEqual( normaliseVocabulary( { groups: [] } ), { groups: [], fallbacks: [], errors: [] } );
} );

test( 'no vocabulary, no index', () => {
	assert.equal( buildIndex( null ), null );
	assert.deepEqual( searchRules( null, 'gold', [] ).groups, [] );
} );

// ---------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------

test( 'a rule is labelled with its group, in plain words', () => {
	const index = indexOf();
	const chip = ( rule ) => describeRules( [ rule ], index )[ 0 ].label;

	assert.equal( chip( 'agend_membership:' + PLAN_GOLD ), 'Plan: Gold' );
	assert.equal( chip( 'agend_segments:' + SEG_BOARD ), 'Segment: Board' );
	assert.equal( chip( 'agend_entitlements:premium.directory' ), 'Entitlement: Premium directory' );
	assert.equal( chip( 'agend_entitlements:directory.access' ), 'Platform: Directory access' );
	assert.equal( chip( 'agend_wordpress:role_editor' ), 'Role: Editor' );
	assert.equal( chip( 'agend_wordpress:logged_in' ), 'Signed in' );
	assert.equal( chip( 'agend_wordpress:logged_out' ), 'Signed out' );
	assert.equal( chip( 'agend_membership:is_member' ), 'Any current member' );
} );

test( 'a rule a site adds through its own provider says whose it is', () => {
	const index = buildIndex( normaliseVocabulary( { groups: [ { key: 'acme_crm', label: 'Acme CRM', items: [ { value: 'acme_crm:vip', label: 'VIP', kind: 'other' } ] } ] } ) );

	assert.equal( describeRules( [ 'acme_crm:vip' ], index )[ 0 ].label, 'Acme CRM: VIP' );
} );

test( 'a plan that was switched off is still named, and marked', () => {
	const chip = describeRules( [ 'agend_membership:' + PLAN_OLD ], indexOf() )[ 0 ];

	assert.deepEqual( chip, { value: 'agend_membership:' + PLAN_OLD, label: 'Plan: Retired (no longer active)', status: 'inactive' } );
} );

test( 'a stored slug is the same rule as its id, labelled the same, and removable as itself', () => {
	const chips = describeRules( [ 'agend_membership:gold', 'agend_segments:board' ], indexOf() );

	assert.deepEqual( chips, [
		{ value: 'agend_membership:gold', label: 'Plan: Gold', status: 'available' },
		{ value: 'agend_segments:board', label: 'Segment: Board', status: 'available' },
	] );
} );

test( 'a stored rule the vocabulary does not offer is kept, and says so', () => {
	const chips = describeRules( [ 'agend_segments:deleted', 'agend_wordpress:role_ghost', 'garbage', 'agend_membership:' + PLAN_GOLD ], indexOf() );

	assert.deepEqual( chips.map( ( chip ) => [ chip.label, chip.status ] ), [
		[ 'Unavailable: agend_segments:deleted', 'unavailable' ],
		[ 'Unavailable: agend_wordpress:role_ghost', 'unavailable' ],
		[ 'Unavailable: garbage', 'unavailable' ],
		[ 'Plan: Gold', 'available' ],
	] );
	assert.deepEqual( chips.map( ( chip ) => chip.value ), [ 'agend_segments:deleted', 'agend_wordpress:role_ghost', 'garbage', 'agend_membership:' + PLAN_GOLD ], 'in the order the block holds them, none dropped' );
} );

test( 'before the vocabulary arrives, or when it fails, a rule is shown as stored and not called unavailable', () => {
	const chips = describeRules( [ 'agend_segments:' + SEG_BOARD, 'agend_wordpress:logged_in', 'agend_membership:is_member', 'agend_wordpress:logged_out' ], null );

	assert.deepEqual( chips.map( ( chip ) => [ chip.label, chip.status ] ), [
		[ 'agend_segments:' + SEG_BOARD, 'unverified' ],
		[ 'Signed in', 'available' ],
		[ 'Any current member', 'available' ],
		[ 'Signed out', 'available' ],
	] );
} );

test( 'a group that failed with nothing to show says nothing about what exists, so its rules are unverified, not unavailable', () => {
	const failed = response( {
		groups: response().groups.map( ( group ) => ( group.key === 'agend_entitlements' ? Object.assign( {}, group, { items: [] } ) : group ) ),
		errors: [ { group: 'agend_entitlements', message: 'The API key needs the crm.entitlements.browse scope.' } ],
	} );
	const chips = describeRules( [ 'agend_entitlements:premium.directory', 'agend_segments:gone' ], buildIndex( normaliseVocabulary( failed ) ) );

	assert.deepEqual( chips.map( ( chip ) => chip.status ), [ 'unverified', 'unavailable' ], 'the failed group is unverified, a healthy group is still authoritative' );
	assert.equal( chips[ 0 ].label, 'agend_entitlements:premium.directory' );
} );

test( 'a group that failed but still has its last known list is authoritative', () => {
	const stale = response( {
		groups: response().groups.map( ( group ) => ( group.key === 'agend_entitlements' ? Object.assign( {}, group, { stale: true } ) : group ) ),
		errors: [ { group: 'agend_entitlements', message: 'Down.' } ],
	} );

	assert.equal( describeRules( [ 'agend_entitlements:gone' ], buildIndex( normaliseVocabulary( stale ) ) )[ 0 ].status, 'unavailable' );
} );

test( 'a plan name is not read as a replacement pattern', () => {
	const item = { kind: 'plan', label: 'Gold $& $1 %s', value: 'agend_membership:x', inactive: false };

	assert.equal( ruleLabel( item ), 'Plan: Gold $& $1 %s' );
} );

test( 'translated copy is used for the labels', () => {
	const copy = Object.assign( {}, DEFAULT_STRINGS, { plan: 'Plan : %s', unavailable: 'Indisponible : %s', signedIn: 'Connecte' } );
	const index = buildIndex( normaliseVocabulary( response() ), copy );

	assert.equal( describeRules( [ 'agend_membership:' + PLAN_GOLD ], index, copy )[ 0 ].label, 'Plan : Gold' );
	assert.equal( describeRules( [ 'agend_segments:gone' ], index, copy )[ 0 ].label, 'Indisponible : agend_segments:gone' );
	assert.equal( describeRules( [ 'agend_wordpress:logged_in' ], null, copy )[ 0 ].label, 'Connecte' );
} );

// ---------------------------------------------------------------------------
// Searching
// ---------------------------------------------------------------------------

test( 'with nothing typed, Membership and WordPress are shown and the others show their first few', () => {
	const result = searchRules( indexOf(), '', [] );

	assert.equal( result.mode, 'preview' );
	assert.deepEqual( result.groups.map( ( group ) => group.key ), [ 'agend_membership', 'agend_segments', 'agend_entitlements', 'agend_wordpress' ] );
	assert.deepEqual( labels( result ), [
		[ 'Any current member', 'Plan: Gold' ],
		[ 'Segment: Board' ],
		[ 'Platform: Directory access', 'Entitlement: Premium directory' ],
		[ 'Signed in', 'Signed out', 'Role: Editor' ],
	] );
	assert.equal( result.capped, false );
} );

test( 'with nothing typed, a long group shows only its first few and says how many more', () => {
	const result = searchRules( buildIndex( normaliseVocabulary( large( 800 ) ) ), '   ', [] );
	const entitlements = result.groups.find( ( group ) => group.key === 'agend_entitlements' );

	assert.equal( PREVIEW_SIZE, 5 );
	assert.equal( entitlements.items.length, 5 );
	assert.equal( entitlements.more, 800 - 5 );
	assert.equal( result.capped, false, 'the cap message is for a search' );
	assert.deepEqual( result.groups.find( ( group ) => group.key === 'agend_wordpress' ).items.length, 3, 'WordPress in full' );
	assert.equal( result.groups.find( ( group ) => group.key === 'agend_membership' ).more, 0 );
} );

test( 'a search filters across every group as you type', () => {
	const index = indexOf();

	assert.deepEqual( labels( searchRules( index, 'dir', [] ) ), [ [ 'Platform: Directory access', 'Entitlement: Premium directory' ] ] );
	assert.deepEqual( labels( searchRules( index, 'gold', [] ) ), [ [ 'Plan: Gold' ] ] );
	assert.deepEqual( labels( searchRules( index, 'SIGNED', [] ) ), [ [ 'Signed in', 'Signed out' ] ], 'case does not matter' );
	assert.deepEqual( searchRules( index, 'zzz', [] ).groups, [] );
	assert.equal( searchRules( index, 'zzz', [] ).total, 0 );
} );

test( 'the group word finds the group: "plan", "role", "segment", "platform"', () => {
	const index = indexOf();

	assert.deepEqual( labels( searchRules( index, 'plan', [] ) ), [ [ 'Plan: Gold' ] ] );
	assert.deepEqual( labels( searchRules( index, 'role', [] ) ), [ [ 'Role: Editor' ] ] );
	assert.deepEqual( labels( searchRules( index, 'segment', [] ) ), [ [ 'Segment: Board' ] ] );
	assert.deepEqual( labels( searchRules( index, 'platform', [] ) ), [ [ 'Platform: Directory access' ] ] );
} );

test( 'every word typed must be found, in any order', () => {
	const index = indexOf();

	assert.deepEqual( labels( searchRules( index, 'access directory', [] ) ), [ [ 'Platform: Directory access' ] ] );
	assert.deepEqual( labels( searchRules( index, 'premium directory', [] ) ), [ [ 'Entitlement: Premium directory' ] ] );
	assert.deepEqual( labels( searchRules( index, 'premium access', [] ) ), [] );
	assert.deepEqual( labels( searchRules( index, '  directory   access  ', [] ) ), [ [ 'Platform: Directory access' ] ], 'extra spaces do not matter' );
} );

test( 'a description and an entitlement key are searchable; a plan id is not', () => {
	const index = indexOf();

	assert.deepEqual( labels( searchRules( index, 'browse the', [] ) ), [ [ 'Platform: Directory access' ] ], 'the description' );
	assert.deepEqual( labels( searchRules( index, 'premium.dir', [] ) ), [ [ 'Entitlement: Premium directory' ] ], 'the gate key' );
	assert.deepEqual( searchRules( index, '11111111', [] ).groups, [], 'a UUID is noise, not a name' );
} );

test( 'accents and case are folded', () => {
	const index = buildIndex( normaliseVocabulary( { groups: [ { key: 'agend_segments', label: 'Segments', items: [ { value: 'agend_segments:1', label: 'Caf\u00e9 volunteers', kind: 'segment' } ] } ] } ) );

	assert.equal( fold( 'Caf\u00e9' ), 'cafe' );
	assert.equal( searchRules( index, 'cafe', [] ).total, 1 );
	assert.equal( searchRules( index, 'CAF\u00c9', [] ).total, 1 );
} );

test( 'a name that starts with what was typed comes before one that merely contains it', () => {
	const index = buildIndex(
		normaliseVocabulary( {
			groups: [
				{
					key: 'agend_segments',
					label: 'Segments',
					items: [
						{ value: 'agend_segments:1', label: 'Past board members', kind: 'segment' },
						{ value: 'agend_segments:2', label: 'Aboard', kind: 'segment' },
						{ value: 'agend_segments:3', label: 'Board', kind: 'segment' },
						{ value: 'agend_segments:4', label: 'Board observers', kind: 'segment' },
					],
				},
			],
		} )
	);

	assert.deepEqual( labels( searchRules( index, 'board', [] ) )[ 0 ], [ 'Segment: Board', 'Segment: Board observers', 'Segment: Past board members', 'Segment: Aboard' ], 'prefix, then a word that starts with it, then contains, each in the site order' );
} );

test( 'rules the block already has are not offered again, by id or by alias', () => {
	const index = indexOf();
	const have = [ 'agend_membership:' + PLAN_GOLD, 'agend_segments:board' ];
	const all = labels( searchRules( index, '', have ) ).flat();

	assert.equal( all.includes( 'Plan: Gold' ), false, 'chosen by id' );
	assert.equal( all.includes( 'Segment: Board' ), false, 'chosen by its slug' );
	assert.equal( all.includes( 'Signed in' ), true );
	assert.equal( searchRules( index, 'gold', have ).total, 0 );
} );

test( 'a plan that was switched off is never offered', () => {
	const index = indexOf();

	assert.equal( labels( searchRules( index, '', [] ) ).flat().some( ( label ) => label.includes( 'Retired' ) ), false );
	assert.equal( searchRules( index, 'retired', [] ).total, 0 );
} );

test( 'a group left with nothing to offer is left out', () => {
	const result = searchRules( indexOf(), '', [ 'agend_segments:' + SEG_BOARD ] );

	assert.equal( result.groups.some( ( group ) => group.key === 'agend_segments' ), false );
} );

test( 'groups are listed Membership, Segments, Entitlements, WordPress, then any a site added', () => {
	assert.deepEqual( [ ...GROUP_ORDER ], [ 'agend_membership', 'agend_segments', 'agend_entitlements', 'agend_wordpress' ] );

	const shuffled = response();

	shuffled.groups = [ shuffled.groups[ 3 ], { key: 'acme', label: 'Acme', items: [ { value: 'acme:one', label: 'One', kind: 'other' } ] }, shuffled.groups[ 2 ], shuffled.groups[ 0 ], shuffled.groups[ 1 ] ];

	assert.deepEqual( searchRules( buildIndex( normaliseVocabulary( shuffled ) ), 'o', [] ).groups.map( ( group ) => group.key ), [ 'agend_membership', 'agend_segments', 'agend_entitlements', 'agend_wordpress', 'acme' ] );
} );

// ---------------------------------------------------------------------------
// At scale
// ---------------------------------------------------------------------------

test( 'a search never renders more than 50 results, and says how many there were', () => {
	const index = buildIndex( normaliseVocabulary( large( 800 ) ) );
	const result = searchRules( index, 'entitlement', [] );

	assert.equal( MAX_RESULTS, 50 );
	assert.equal( result.rendered, 50 );
	assert.equal( result.groups.reduce( ( sum, group ) => sum + group.items.length, 0 ), 50 );
	assert.equal( result.total, 800 );
	assert.equal( result.capped, true );
} );

test( 'the 50 are the best 50 in group order: earlier groups fill first', () => {
	const result = searchRules( buildIndex( normaliseVocabulary( large( 800 ) ) ), 'gold', [] );

	assert.equal( result.groups[ 0 ].key, 'agend_membership', 'Plan: Gold comes first' );
	assert.equal( result.groups[ 0 ].items[ 0 ].chip, 'Plan: Gold' );
	assert.ok( result.rendered <= 50 );
	assert.equal( result.total, 1 + 115, 'the plan and every 7th of 800 (0, 7, ... 798)' );
	assert.equal( result.capped, true );
} );

test( 'a search with 50 matches or fewer is not capped', () => {
	const index = buildIndex( normaliseVocabulary( large( 800 ) ) );
	const exactly = searchRules( index, 'number 79', [] );
	const expected = Array.from( { length: 800 }, ( _, n ) => n ).filter( ( n ) => String( n ).includes( '79' ) ).length;

	assert.ok( expected > 1 && expected <= 50 );
	assert.equal( exactly.total, expected );
	assert.equal( exactly.rendered, expected );
	assert.equal( exactly.capped, false );

	const fifty = buildIndex( normaliseVocabulary( large( 50 ) ) );
	const all = searchRules( fifty, 'entitlement', [] );

	assert.equal( all.total, 50 );
	assert.equal( all.rendered, 50 );
	assert.equal( all.capped, false, 'exactly 50 is not "showing 50 of 50"' );
} );

test( 'with 800 entitlements a keystroke is a quick in-memory filter', () => {
	const vocabulary = normaliseVocabulary( large( 800 ) );
	const built = process.hrtime.bigint();
	const index = buildIndex( vocabulary );
	const buildMs = Number( process.hrtime.bigint() - built ) / 1e6;
	const started = process.hrtime.bigint();

	for ( const typed of [ 'e', 'en', 'ent', 'enti', 'entit', 'entitl', 'entitle', 'entitlem', 'entitleme', 'entitlemen', 'entitlement', 'entitlement n', 'entitlement nu', 'entitlement num' ] ) {
		searchRules( index, typed, [ 'agend_entitlements:gate.1' ] );
	}

	const perKeystroke = Number( process.hrtime.bigint() - started ) / 1e6 / 14;

	// Generous: a loaded CI machine is slower than a laptop. What is being
	// guarded is a scan that is orders of magnitude slower, not a few ms.
	assert.ok( buildMs < 500, `indexing took ${ buildMs }ms` );
	assert.ok( perKeystroke < 50, `a keystroke took ${ perKeystroke }ms` );
} );

test( 'searching reads the index and nothing else: no request is possible from here', () => {
	// The module has no fetch and no WordPress import; this pins that.
	const index = indexOf();
	const before = JSON.stringify( index.groups.map( ( group ) => group.items.map( ( item ) => item.value ) ) );

	searchRules( index, 'gold', [] );
	searchRules( index, '', [ 'agend_membership:' + PLAN_GOLD ] );

	assert.equal( JSON.stringify( index.groups.map( ( group ) => group.items.map( ( item ) => item.value ) ) ), before, 'the index is not changed by a search' );
} );

// ---------------------------------------------------------------------------
// Show instead
// ---------------------------------------------------------------------------

test( '"Show instead" offers Nothing first, then the pages, and keeps a stored page that is not on the list', () => {
	const pages = [ { id: 12, title: 'Join us' }, { id: 13, title: 'Sign in' } ];

	assert.deepEqual( fallbackChoices( pages, 0 ), [ { value: '', label: 'Nothing' }, { value: '12', label: 'Join us' }, { value: '13', label: 'Sign in' } ] );
	assert.deepEqual( fallbackChoices( pages, 99 ).slice( 0, 2 ), [ { value: '', label: 'Nothing' }, { value: '99', label: 'Content #99 (not in the list)' } ] );
	assert.deepEqual( fallbackChoices( pages, 12 ).map( ( choice ) => choice.value ), [ '', '12', '13' ], 'a listed page is not repeated' );
	assert.deepEqual( fallbackChoices( null, 0 ), [ { value: '', label: 'Nothing' } ] );
} );

test( 'a search that finds only rules already added says so, and one that finds nothing says that', () => {
	const index = indexOf();
	const open = searchRules( index, 'gold', [] );

	assert.equal( open.total > 0, true );
	assert.equal( open.alreadyAdded, 0 );

	const added = searchRules( index, 'gold', [ 'agend_membership:' + PLAN_GOLD ] );

	assert.equal( added.total, 0, 'the rule is not offered again' );
	assert.deepEqual( added.groups, [] );
	assert.equal( added.alreadyAdded, 1, 'but the search is known to have found it' );

	assert.equal( searchRules( index, 'zzz', [ 'agend_membership:' + PLAN_GOLD ] ).alreadyAdded, 0 );
	assert.equal( searchRules( index, '', [ 'agend_membership:' + PLAN_GOLD ] ).alreadyAdded, 0, 'only a search counts' );
	assert.equal( searchRules( null, 'gold', [] ).alreadyAdded, 0 );
} );

test( 'a page panel list can be limited to one kind and name its empty choice', () => {
	const mixed = [
		{ id: 12, title: 'Page: Join us', type: 'page' },
		{ id: 13, title: 'Pattern: Members only', type: 'pattern' },
		{ id: 14, title: 'Elementor template: Banner', type: 'elementor_template' },
		{ id: 15, title: 'Page: Sign in', type: 'page' },
	];

	assert.deepEqual( fallbackChoices( mixed, 0 ).map( ( choice ) => choice.value ), [ '', '12', '13', '14', '15' ], 'no limit offers all three kinds' );
	assert.deepEqual( fallbackChoices( mixed, 0, undefined, { types: [ 'page' ] } ).map( ( choice ) => choice.value ), [ '', '12', '15' ], 'a redirect is offered pages only' );
	assert.deepEqual( fallbackChoices( mixed, 0, undefined, { types: [ 'page' ], none: 'Choose a page' } )[ 0 ], { value: '', label: 'Choose a page' } );
	assert.deepEqual( fallbackChoices( mixed, 0, undefined, { types: [] } ), [ { value: '', label: 'Nothing' } ], 'an empty limit offers nothing but the empty choice' );

	// A stored pattern is not a page: as a redirect target it is not in the list, and the control says so.
	assert.deepEqual( fallbackChoices( mixed, 13, undefined, { types: [ 'page' ] } ).slice( 0, 2 ), [ { value: '', label: 'Nothing' }, { value: '13', label: 'Content #13 (not in the list)' } ] );
} );

test( 'a long "Show instead" list is searchable by what the title contains', () => {
	const choices = fallbackChoices( [ { id: 1, title: 'Join us today' }, { id: 2, title: 'Members area' }, { id: 3, title: 'JOIN the board' } ], 0 );

	assert.deepEqual( filterChoices( choices, 'join' ).map( ( choice ) => choice.value ), [ '1', '3' ] );
	assert.deepEqual( filterChoices( choices, '  ' ), choices, 'nothing typed is everything' );
	assert.deepEqual( filterChoices( choices, 'zzz' ), [] );
	assert.deepEqual( filterChoices( choices, 'AREA' ).map( ( choice ) => choice.value ), [ '2' ] );
} );
