/**
 * The page panel's logic (src/page-model.mjs).
 *
 * Run with: node --test agend-content-access/tests/js/page-model.test.mjs
 *
 * What a page's panel writes decides who reads a members page, so these are about
 * what must NOT happen: a change that changes nothing is not an edit, a half made
 * choice is never written, an unreadable setting is never overwritten by a guess,
 * and the value sent is only ever the four keys the server reads.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { DEFAULT_STRINGS } from '../../src/copy.mjs';
import {
	ACTIONS,
	ERROR_CODE,
	INPUT_NAME,
	PAGE_TYPES,
	REST_FIELD,
	addRule,
	buildPayload,
	defaultDenied,
	normalisePage,
	removeRule,
	resetPage,
	savedRefusal,
	setAction,
	setFallback,
	setInvert,
	setMatch,
	setTarget,
	validatePage,
} from '../../src/page-model.mjs';

const MEMBER = 'agend_membership:is_member';
const SIGNED_IN = 'agend_wordpress:logged_in';
const SEGMENT = 'agend_segments:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

/** A page with these rules and nothing else set. */
const page = ( rules = [ MEMBER ], extra = {} ) => normalisePage( Object.assign( { rules }, extra ) );

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

test( 'no value, null and an empty list are a page with no rules, shown with the default denial', () => {
	for ( const stored of [ undefined, null, [] ] ) {
		const state = normalisePage( stored );

		assert.deepEqual( state.rules, [] );
		assert.equal( state.match, 'any' );
		assert.equal( state.invert, false );
		assert.deepEqual( state.denied, { action: 'placeholder', target_id: 0, fallback_id: 0 } );
		assert.equal( state.legacy, false );
		assert.equal( state.unreadable, false );
	}
} );

test( 'what the server stored is read as it is', () => {
	const state = normalisePage( {
		rules: [ MEMBER, SEGMENT ],
		match: 'all',
		invert: true,
		denied: { action: 'redirect', target_id: 12, fallback_id: 0 },
	} );

	assert.deepEqual( state.rules, [ MEMBER, SEGMENT ] );
	assert.equal( state.match, 'all' );
	assert.equal( state.invert, true );
	assert.deepEqual( state.denied, { action: 'redirect', target_id: 12, fallback_id: 0 } );
} );

test( 'a repeated rule is shown once, and an unknown match is any', () => {
	const state = normalisePage( { rules: [ MEMBER, MEMBER ], match: 'some' } );

	assert.deepEqual( state.rules, [ MEMBER ] );
	assert.equal( state.match, 'any' );
} );

test( 'the legacy flag is the server saying the page only has a 0.1.x setting', () => {
	assert.equal( page( [ MEMBER ], { legacy: true } ).legacy, true );
	assert.equal( page( [ MEMBER ], { legacy: 'yes' } ).legacy, false, 'only a boolean true' );
} );

test( 'a setting that cannot be shown as rules is unreadable, never repaired into fewer', () => {
	const unreadable = [
		'members',
		7,
		true,
		[ MEMBER ],
		{ rules: 'members' },
		{ rules: [ MEMBER, 7 ] },
		{ rules: [ MEMBER, '' ] },
		{ rules: [ MEMBER, ' ' ] },
		{ rules: [ ' \t' ] },
		{ rules: [ MEMBER, null ] },
		{ rules: [], invert: true },
		{ unreadable: true, rules: [] },
		{ unreadable: true, rules: [ MEMBER ] },
	];

	for ( const stored of unreadable ) {
		const state = normalisePage( stored );

		assert.equal( state.unreadable, true, JSON.stringify( stored ) );
		assert.deepEqual( state.rules, [], 'nothing is shown as chosen: ' + JSON.stringify( stored ) );
	}
} );

test( 'a denial setting nobody can make sense of is the default, not a guess', () => {
	for ( const denied of [ undefined, null, 'redirect', [], { action: 'block', target_id: 3 }, { action: null }, { target_id: 3 } ] ) {
		assert.deepEqual( page( [ MEMBER ], { denied } ).denied, defaultDenied(), JSON.stringify( denied ) );
	}
} );

test( 'a denial keeps only the number its action uses', () => {
	assert.deepEqual( page( [ MEMBER ], { denied: { action: 'redirect', target_id: 4, fallback_id: 9 } } ).denied, { action: 'redirect', target_id: 4, fallback_id: 0 } );
	assert.deepEqual( page( [ MEMBER ], { denied: { action: 'back', target_id: 4, fallback_id: 9 } } ).denied, { action: 'back', target_id: 0, fallback_id: 9 } );
	assert.deepEqual( page( [ MEMBER ], { denied: { action: 'placeholder', target_id: 4, fallback_id: 9 } } ).denied, { action: 'placeholder', target_id: 4, fallback_id: 0 } );
	assert.deepEqual( page( [ MEMBER ], { denied: { action: 'redirect', target_id: '4' } } ).denied, { action: 'redirect', target_id: 0, fallback_id: 0 }, 'a string is not a post id here' );
	assert.deepEqual( page( [ MEMBER ], { denied: { action: 'redirect', target_id: -4 } } ).denied.target_id, 0 );
	assert.deepEqual( page( [ MEMBER ], { denied: { action: 'redirect', target_id: 1.5 } } ).denied.target_id, 0 );
} );

test( 'a page with no rules has no denial setting to show', () => {
	assert.deepEqual( normalisePage( { rules: [], denied: { action: 'redirect', target_id: 4 } } ).denied, defaultDenied() );
} );

test( 'reading never mutates what it is given', () => {
	const stored = Object.freeze( { rules: Object.freeze( [ MEMBER ] ), denied: Object.freeze( { action: 'redirect', target_id: 4, fallback_id: 0 } ) } );

	assert.doesNotThrow( () => normalisePage( stored ) );
} );

// ---------------------------------------------------------------------------
// The default
// ---------------------------------------------------------------------------

test( 'the default is the built-in placeholder', () => {
	assert.deepEqual( defaultDenied(), { action: 'placeholder', target_id: 0, fallback_id: 0 } );
	assert.equal( ACTIONS.PLACEHOLDER, 'placeholder' );
	assert.deepEqual( page().denied, defaultDenied(), 'a page given its first rule starts there' );
	assert.deepEqual( addRule( normalisePage( undefined ), MEMBER ).denied, defaultDenied() );
} );

// ---------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------

test( 'a rule is added last, and a repeat is not an edit', () => {
	const one = page( [ MEMBER ] );
	const two = addRule( one, SIGNED_IN );

	assert.deepEqual( two.rules, [ MEMBER, SIGNED_IN ] );
	assert.deepEqual( one.rules, [ MEMBER ], 'the original is untouched' );
	assert.equal( addRule( two, SIGNED_IN ), two, 'the very same state' );
	assert.equal( addRule( two, '' ), two );
	assert.equal( addRule( two, 7 ), two );
	assert.equal( addRule( two, null ), two );
} );

test( 'a rule is removed, and one that is not there is not an edit', () => {
	const two = page( [ MEMBER, SIGNED_IN ] );

	assert.deepEqual( removeRule( two, MEMBER ).rules, [ SIGNED_IN ] );
	assert.equal( removeRule( two, SEGMENT ), two );
} );

test( 'removing the last rule clears the whole setting, including Reverse and the denial', () => {
	const full = page( [ MEMBER ], { invert: true, denied: { action: 'redirect', target_id: 12 }, legacy: true } );
	const cleared = removeRule( full, MEMBER );

	assert.deepEqual( cleared.rules, [] );
	assert.equal( cleared.invert, false );
	assert.equal( cleared.match, 'any' );
	assert.deepEqual( cleared.denied, defaultDenied() );
	assert.equal( cleared.legacy, false, 'what is saved is no longer the old setting' );
} );

test( 'Match needs two rules, and a change that changes nothing is not an edit', () => {
	const one = page( [ MEMBER ] );
	const two = page( [ MEMBER, SIGNED_IN ] );

	assert.equal( setMatch( one, 'all' ), one );
	assert.equal( setMatch( two, 'any' ), two );
	assert.equal( setMatch( two, 'all' ).match, 'all' );
	assert.equal( setMatch( two, 'banana' ), two, 'anything but all is any' );
} );

test( 'Reverse needs a rule, and a change that changes nothing is not an edit', () => {
	const none = normalisePage( undefined );
	const one = page( [ MEMBER ] );

	assert.equal( setInvert( none, true ), none, 'reversing nothing would be nobody' );
	assert.equal( setInvert( one, false ), one );
	assert.equal( setInvert( one, true ).invert, true );
	assert.equal( setInvert( setInvert( one, true ), true ).invert, true );
	assert.equal( setInvert( one, 'yes' ), one, 'only a boolean true turns it on' );
} );

// ---------------------------------------------------------------------------
// If someone is not allowed
// ---------------------------------------------------------------------------

test( 'the action needs a rule, and choosing the one already chosen is not an edit', () => {
	const none = normalisePage( undefined );
	const one = page();

	assert.equal( setAction( none, 'redirect' ), none );
	assert.equal( setAction( one, 'placeholder' ), one );
	assert.equal( setAction( one, 'block' ), one );
	assert.equal( setAction( one, undefined ), one );
	assert.equal( setAction( one, 'redirect' ).denied.action, 'redirect' );
} );

test( 'switching action starts that action with nothing chosen', () => {
	const chosen = setTarget( setAction( page(), 'placeholder' ), 7 );

	assert.equal( chosen.denied.target_id, 7 );

	const redirect = setAction( chosen, 'redirect' );

	assert.deepEqual( redirect.denied, { action: 'redirect', target_id: 0, fallback_id: 0 }, 'the pattern chosen as a placeholder is not a page to redirect to' );

	const back = setFallback( setAction( redirect, 'back' ), 9 );

	assert.deepEqual( back.denied, { action: 'back', target_id: 0, fallback_id: 9 } );
	assert.deepEqual( setAction( back, 'placeholder' ).denied, defaultDenied() );
} );

test( 'the target is for a placeholder and a redirect, and the fallback is for the way back', () => {
	const placeholder = page();
	const redirect = setAction( page(), 'redirect' );
	const back = setAction( page(), 'back' );

	assert.equal( setTarget( placeholder, 5 ).denied.target_id, 5 );
	assert.equal( setTarget( redirect, 5 ).denied.target_id, 5 );
	assert.equal( setTarget( back, 5 ), back, 'the way back has no target' );
	assert.equal( setFallback( back, 5 ).denied.fallback_id, 5 );
	assert.equal( setFallback( placeholder, 5 ), placeholder );
	assert.equal( setFallback( redirect, 5 ), redirect );
} );

test( 'a page is chosen by a whole number, from a select that gives a string', () => {
	const redirect = setAction( page(), 'redirect' );

	assert.equal( setTarget( redirect, '12' ).denied.target_id, 12 );
	assert.equal( setTarget( redirect, 12 ).denied.target_id, 12 );
	assert.equal( setTarget( setTarget( redirect, 12 ), '' ).denied.target_id, 0, 'the empty choice is none' );

	for ( const bad of [ 'abc', '-3', '1.5', '12abc', 1.5, -3, null, undefined, NaN, {} ] ) {
		assert.equal( setTarget( redirect, bad ), redirect, String( bad ) + ' changes nothing when none was chosen' );
	}
} );

test( 'choosing the page already chosen is not an edit', () => {
	const redirect = setTarget( setAction( page(), 'redirect' ), 4 );
	const back = setFallback( setAction( page(), 'back' ), 4 );

	assert.equal( setTarget( redirect, '4' ), redirect );
	assert.equal( setFallback( back, 4 ), back );
} );

// ---------------------------------------------------------------------------
// An unreadable setting accepts one change
// ---------------------------------------------------------------------------

test( 'an unreadable setting refuses every change but a reset', () => {
	const bad = normalisePage( { rules: [ MEMBER, 7 ] } );

	assert.equal( addRule( bad, MEMBER ), bad );
	assert.equal( removeRule( bad, MEMBER ), bad );
	assert.equal( setMatch( bad, 'all' ), bad );
	assert.equal( setInvert( bad, true ), bad );
	assert.equal( setAction( bad, 'redirect' ), bad );
	assert.equal( setTarget( bad, 3 ), bad );
	assert.equal( setFallback( bad, 3 ), bad );

	const fresh = resetPage();

	assert.equal( fresh.unreadable, false );
	assert.deepEqual( fresh.rules, [] );
	assert.deepEqual( buildPayload( bad ), buildPayload( fresh ), 'saving an unreadable one is only ever the cleared setting' );
} );

// ---------------------------------------------------------------------------
// Whether it can be written
// ---------------------------------------------------------------------------

test( 'a redirect with no page chosen is not a setting, and says what is missing', () => {
	const redirect = setAction( page(), 'redirect' );
	const check = validatePage( redirect );

	assert.equal( check.ok, false );
	assert.equal( check.errors.target, DEFAULT_STRINGS.redirectNeedsPage );
	assert.equal( validatePage( redirect, { redirectNeedsPage: 'Choisissez une page.' } ).errors.target, 'Choisissez une page.' );
	assert.equal( validatePage( setTarget( redirect, 12 ) ).ok, true );
} );

test( 'every other setting can be written as it is', () => {
	assert.equal( validatePage( normalisePage( undefined ) ).ok, true, 'no rules' );
	assert.equal( validatePage( page() ).ok, true, 'the placeholder, built in' );
	assert.equal( validatePage( setTarget( page(), 7 ) ).ok, true, 'a chosen placeholder' );
	assert.equal( validatePage( setAction( page(), 'back' ) ).ok, true, 'the way back needs no fallback: a visitor with nowhere to come back from sees the placeholder' );
	assert.equal( validatePage( normalisePage( { rules: 'x' } ) ).ok, true, 'an unreadable setting has nothing to complete' );
} );

test( 'a page with no rules never needs a page chosen', () => {
	const state = Object.assign( {}, normalisePage( undefined ), { denied: { action: 'redirect', target_id: 0, fallback_id: 0 } } );

	assert.equal( validatePage( state ).ok, true );
} );

// ---------------------------------------------------------------------------
// What is written
// ---------------------------------------------------------------------------

test( 'the value written is the four keys the server reads and nothing it only says', () => {
	const payload = buildPayload( page( [ MEMBER, SIGNED_IN ], { match: 'all', invert: true, legacy: true, denied: { action: 'redirect', target_id: 12 } } ) );

	assert.deepEqual( Object.keys( payload ), [ 'rules', 'match', 'invert', 'denied' ] );
	assert.deepEqual( payload, {
		rules: [ MEMBER, SIGNED_IN ],
		match: 'all',
		invert: true,
		denied: { action: 'redirect', target_id: 12, fallback_id: 0 },
	} );
} );

test( 'match is any below two rules, whatever the state held', () => {
	const state = Object.assign( {}, page( [ MEMBER ] ), { match: 'all' } );

	assert.equal( buildPayload( state ).match, 'any' );
} );

test( 'no rules is the cleared setting, whatever else the state held', () => {
	const cleared = { rules: [], match: 'any', invert: false, denied: { action: 'placeholder', target_id: 0, fallback_id: 0 } };

	assert.deepEqual( buildPayload( normalisePage( undefined ) ), cleared );
	assert.deepEqual( buildPayload( Object.assign( {}, page(), { rules: [], invert: true, denied: { action: 'redirect', target_id: 4, fallback_id: 0 } } ) ), cleared );
	assert.deepEqual( buildPayload( resetPage() ), cleared );
} );

test( 'the payload is a copy, so a later change cannot alter what was sent', () => {
	const state = page( [ MEMBER ] );
	const payload = buildPayload( state );

	payload.rules.push( 'x:y' );

	assert.deepEqual( state.rules, [ MEMBER ] );
} );

test( 'what is written reads back as the same page, without the flags', () => {
	const state = setFallback( setAction( setInvert( addRule( page( [ MEMBER ], { legacy: true } ), SIGNED_IN ), true ), 'back' ), 21 );
	const again = normalisePage( buildPayload( state ) );

	assert.deepEqual( again.rules, state.rules );
	assert.equal( again.match, state.match );
	assert.equal( again.invert, true );
	assert.deepEqual( again.denied, { action: 'back', target_id: 0, fallback_id: 21 } );
	assert.equal( again.legacy, false, 'it is no longer the older setting' );
	assert.equal( again.unreadable, false );
} );

test( 'the value for a hidden input is JSON that comes back unchanged', () => {
	const payload = buildPayload( setTarget( page( [ MEMBER ] ), 7 ) );

	assert.deepEqual( JSON.parse( JSON.stringify( payload ) ), payload );
} );

// ---------------------------------------------------------------------------
// The server's refusal
// ---------------------------------------------------------------------------

test( 'the server refusing this panel s value is shown, and nothing else is', () => {
	assert.equal( savedRefusal( { code: ERROR_CODE, message: 'This is not a rule: nonsense.' } ), 'This is not a rule: nonsense.' );
	assert.equal( savedRefusal( { code: 'rest_forbidden', message: 'Nope' } ), '' );
	assert.equal( savedRefusal( { code: ERROR_CODE } ), '' );
	assert.equal( savedRefusal( { code: ERROR_CODE, message: 7 } ), '' );
	assert.equal( savedRefusal( null ), '' );
	assert.equal( savedRefusal( undefined ), '' );
	assert.equal( savedRefusal( 'error' ), '' );
	assert.equal( savedRefusal( [] ), '' );
} );

// ---------------------------------------------------------------------------
// Agreement with the server
// ---------------------------------------------------------------------------

const php = ( file ) => readFileSync( new URL( '../../includes/' + file, import.meta.url ), 'utf8' );

test( 'the names this file shares with the server are the server s', () => {
	const rules = php( 'class-agend-content-access-rules.php' );
	const pageRules = php( 'class-agend-content-access-page-rules.php' );
	const metaBox = php( 'class-agend-content-access-meta-box.php' );

	assert.match( rules, new RegExp( `const REST_FIELD = '${ REST_FIELD }';` ) );
	assert.match( pageRules, new RegExp( `const ERROR_CODE = '${ ERROR_CODE }';` ) );
	assert.match( metaBox, new RegExp( `const FIELD_RULES = '${ INPUT_NAME }';` ) );
} );

test( 'the actions are the ones the server stores and acts on', () => {
	const rules = php( 'class-agend-content-access-rules.php' );

	for ( const [ name, value ] of [ [ 'PLACEHOLDER', ACTIONS.PLACEHOLDER ], [ 'REDIRECT', ACTIONS.REDIRECT ], [ 'BACK', ACTIONS.BACK ] ] ) {
		assert.match( rules, new RegExp( `const DENIED_${ name }\\s+= '${ value }';` ), name );
	}

	assert.deepEqual( Object.values( ACTIONS ).sort(), [ 'back', 'placeholder', 'redirect' ] );
} );

test( 'the keys the server ignores on the way in are the ones it says on the way out', () => {
	const pageRules = php( 'class-agend-content-access-page-rules.php' );

	assert.match( pageRules, /const OUTPUT_ONLY_KEYS = array\( 'legacy', 'unreadable' \);/ );

	const keys = Object.keys( normalisePage( undefined ) ).filter( ( key ) => ! [ 'rules', 'match', 'invert', 'denied' ].includes( key ) );

	assert.deepEqual( keys.sort(), [ 'legacy', 'unreadable' ] );
} );

test( 'a redirect and the way back are offered pages only', () => {
	assert.deepEqual( [ ...PAGE_TYPES ], [ 'page' ] );

	const fallbacks = php( 'class-agend-content-access-fragment-rules.php' );

	assert.match( fallbacks, /const KIND_PAGE\s+= 'page';/, 'the server calls them pages too' );
} );
