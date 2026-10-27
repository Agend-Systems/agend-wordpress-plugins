/**
 * The once-per-session loader behind the Agend Access panel (src/lists-loader.mjs).
 *
 * Run with: node --test agend-content-access/tests/js/lists-loader.test.mjs
 *
 * The panel is rendered for whichever block is selected, so the property that
 * matters is that selecting forty blocks costs one request per list, not
 * forty, and that a list which failed is neither retried on every selection nor
 * stuck failed for good.
 *
 * The loader serves whatever lists it is given fetchers for. The panel gives
 * it one, `vocabulary`; most of these cases use two so that "one failing does
 * not hide another" has something to show.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createListsLoader } from '../../src/lists-loader.mjs';

/** Lets pending promise callbacks run. */
const settle = () => new Promise( ( resolve ) => setImmediate( resolve ) );

function fixture( overrides = {} ) {
	const calls = { catalogue: [], conditions: [] };
	const loader = createListsLoader( {
		catalogue: ( refresh ) => {
			calls.catalogue.push( refresh );
			return ( overrides.catalogue || ( () => Promise.resolve( { plans: { items: [] } } ) ) )( refresh );
		},
		conditions: ( refresh ) => {
			calls.conditions.push( refresh );
			return ( overrides.conditions || ( () => Promise.resolve( { groups: [], fallbacks: [] } ) ) )( refresh );
		},
	} );

	return { loader, calls };
}

test( 'nothing is fetched until a panel asks', async () => {
	const { loader, calls } = fixture();

	await settle();

	assert.deepEqual( calls, { catalogue: [], conditions: [] } );
	assert.equal( loader.getState().catalogue.status, 'idle' );
} );

test( 'each list is fetched once however many panels ask, however many blocks are selected', async () => {
	const { loader, calls } = fixture();

	for ( let selected = 0; selected < 40; selected++ ) {
		loader.ensure();
	}

	await settle();

	for ( let selected = 0; selected < 40; selected++ ) {
		loader.ensure();
	}

	await settle();

	assert.equal( calls.catalogue.length, 1 );
	assert.equal( calls.conditions.length, 1 );
	assert.equal( loader.getState().catalogue.status, 'ready' );
	assert.deepEqual( loader.getState().catalogue.data, { plans: { items: [] } } );
	assert.deepEqual( calls.catalogue, [ false ], 'the first load does not force a refresh' );
} );

test( 'asking again while the first request is in flight does not start a second', async () => {
	let release;
	const { loader, calls } = fixture( { catalogue: () => new Promise( ( resolve ) => ( release = resolve ) ) } );

	loader.ensure();
	loader.ensure();

	assert.equal( loader.getState().catalogue.status, 'loading' );
	assert.equal( calls.catalogue.length, 1 );

	release( {} );
	await settle();

	assert.equal( loader.getState().catalogue.status, 'ready' );
	assert.equal( calls.catalogue.length, 1 );
} );

test( 'a failure is remembered and not retried by later selections', async () => {
	const { loader, calls } = fixture( { catalogue: () => Promise.reject( new Error( 'rest_no_route' ) ) } );

	loader.ensure();
	await settle();

	assert.equal( loader.getState().catalogue.status, 'failed' );
	assert.equal( loader.getState().catalogue.data, null );

	loader.ensure();
	loader.ensure();
	await settle();

	assert.equal( calls.catalogue.length, 1, 'a missing route is not hammered on every selection' );
} );

test( 'a fetcher that throws synchronously is a failure, not a crash', async () => {
	const { loader } = fixture( {
		catalogue: () => {
			throw new Error( 'boom' );
		},
	} );

	assert.doesNotThrow( () => loader.ensure() );
	await settle();

	assert.equal( loader.getState().catalogue.status, 'failed' );
} );

test( 'one list failing does not hide the other', async () => {
	const { loader } = fixture( { conditions: () => Promise.reject( new Error( '404' ) ) } );

	loader.ensure();
	await settle();

	assert.equal( loader.getState().catalogue.status, 'ready' );
	assert.equal( loader.getState().conditions.status, 'failed' );
} );

test( 'retry asks again, with a refresh, for the list that failed and leaves the one that loaded', async () => {
	let attempt = 0;
	const { loader, calls } = fixture( {
		catalogue: () => ( ++attempt === 1 ? Promise.reject( new Error( 'down' ) ) : Promise.resolve( { plans: { items: [ 1 ] } } ) ),
	} );

	loader.ensure();
	await settle();
	loader.retry();
	await settle();

	assert.deepEqual( calls.catalogue, [ false, true ], 'the retry forces a refresh' );
	assert.equal( calls.conditions.length, 1, 'the list that loaded is not asked again' );
	assert.equal( loader.getState().catalogue.status, 'ready' );
	assert.deepEqual( loader.getState().catalogue.data, { plans: { items: [ 1 ] } } );

	loader.retry();
	await settle();

	assert.equal( calls.catalogue.length, 2, 'nothing failed, so nothing is retried' );
} );

test( 'retry while a retry is already running does not start another', async () => {
	let release;
	let attempt = 0;
	const { loader, calls } = fixture( {
		catalogue: () => ( ++attempt === 1 ? Promise.reject( new Error( 'down' ) ) : new Promise( ( resolve ) => ( release = resolve ) ) ),
	} );

	loader.ensure();
	await settle();
	loader.retry();
	loader.retry();

	assert.equal( calls.catalogue.length, 2 );

	release( {} );
	await settle();

	assert.equal( calls.catalogue.length, 2 );
	assert.equal( loader.getState().catalogue.status, 'ready' );
} );

test( 'listeners hear every change until they unsubscribe', async () => {
	const { loader } = fixture();
	const seen = [];
	const unsubscribe = loader.subscribe( () => {
		const status = loader.getState().catalogue.status;

		// Both lists notify; this follows the catalogue only.
		if ( seen[ seen.length - 1 ] !== status ) {
			seen.push( status );
		}
	} );

	loader.ensure();
	await settle();

	assert.deepEqual( seen, [ 'loading', 'ready' ] );

	const count = seen.length;

	unsubscribe();
	loader.retry();
	loader.ensure();
	await settle();

	assert.equal( seen.length, count );
} );

test( 'each state change is a new object, so a component comparing by identity re-renders', async () => {
	const { loader } = fixture();
	const before = loader.getState();

	loader.ensure();
	await settle();

	assert.notEqual( loader.getState(), before );
	assert.notEqual( loader.getState().catalogue, before.catalogue );
} );

test( 'a loader with the panel\'s one list, named by its fetcher', async () => {
	const calls = [];
	const loader = createListsLoader( {
		vocabulary: ( refresh ) => {
			calls.push( refresh );

			return calls.length === 1 ? Promise.reject( new Error( 'down' ) ) : Promise.resolve( { groups: [], fallbacks: [], errors: [] } );
		},
	} );

	assert.deepEqual( Object.keys( loader.getState() ), [ 'vocabulary' ], 'no list it was not given' );
	assert.equal( loader.getState().vocabulary.status, 'idle' );

	for ( let selected = 0; selected < 40; selected++ ) {
		loader.ensure();
	}

	await settle();

	assert.equal( loader.getState().vocabulary.status, 'failed' );
	assert.deepEqual( calls, [ false ] );

	loader.retry();
	await settle();

	assert.deepEqual( calls, [ false, true ], 'the retry asks the server to refresh' );
	assert.equal( loader.getState().vocabulary.status, 'ready' );
	assert.deepEqual( loader.getState().vocabulary.data, { groups: [], fallbacks: [], errors: [] } );
} );
