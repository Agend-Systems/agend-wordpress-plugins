/**
 * Loads the editor's lists once per session, with no WordPress imports.
 *
 * The panel is rendered for whichever block is selected, so a component that
 * fetched for itself would fetch on every selection. This holds one request per
 * list for the life of the editor and hands every panel the same answer.
 *
 * Any number of lists, named by the fetchers it is given and loaded
 * independently, so one failing does not hide another. The panel has one today,
 * `vocabulary`: every rule an author can choose, with its group, and the
 * "Show instead" choices.
 *
 * A list is fetched the first time a panel asks for it (`ensure()`), not when
 * the editor opens, so an author who never touches the panel never costs the
 * gateway a request. A failure is remembered rather than retried on every
 * selection; `retry()` is what an author's "Try again" calls, and asks the
 * server to refresh from the gateway instead of answering from cache.
 *
 * The fetchers are injected so a test can count calls. Each takes one boolean,
 * `refresh`, and returns a promise.
 */

function idle() {
	return { status: 'idle', data: null };
}

/**
 * @param {Object<string, Function>} fetchers One `( refresh ) => Promise<*>` per list, by name.
 * @return {{ensure: Function, retry: Function, refresh: Function, getState: Function, subscribe: Function}} The loader.
 */
export function createListsLoader( fetchers ) {
	const keys = Object.keys( fetchers );
	const listeners = [];
	const inFlight = {};
	let state = {};

	keys.forEach( ( key ) => {
		state[ key ] = idle();
		inFlight[ key ] = false;
	} );

	function set( key, next ) {
		const patch = {};
		patch[ key ] = next;
		state = Object.assign( {}, state, patch );
		listeners.slice().forEach( ( listener ) => listener() );
	}

	function run( key, refresh ) {
		if ( inFlight[ key ] ) {
			return;
		}

		// A refresh of a list that has loaded keeps showing what it has while the new
		// answer is on its way, and keeps it if the new answer does not come: a list
		// an author is choosing from must not vanish because they asked for a newer one.
		const previous = state[ key ];
		const keep = refresh && previous.status === 'ready';

		inFlight[ key ] = true;
		set( key, keep ? { status: 'ready', data: previous.data, refreshing: true } : { status: 'loading', data: null } );

		// Called now, not on a later tick, and a fetcher that throws before it
		// returns a promise is a failure like any other.
		let request;

		try {
			request = Promise.resolve( fetchers[ key ]( refresh ) );
		} catch ( error ) {
			request = Promise.reject( error );
		}

		request
			.then(
				( data ) => ( { status: 'ready', data } ),
				() => ( keep ? { status: 'ready', data: previous.data, refreshFailed: true } : { status: 'failed', data: null } )
			)
			.then( ( next ) => {
				inFlight[ key ] = false;
				set( key, next );
			} );
	}

	return {
		/** Starts whichever list has never been asked for. A no-op afterwards. */
		ensure() {
			keys.forEach( ( key ) => {
				if ( state[ key ].status === 'idle' ) {
					run( key, false );
				}
			} );
		},

		/** Asks again for whichever list failed. A list that loaded is left alone. */
		retry() {
			keys.forEach( ( key ) => {
				if ( state[ key ].status === 'failed' ) {
					run( key, true );
				}
			} );
		},

		/**
		 * Asks again, with a refresh, for every list that has loaded, so a page created
		 * since the editor opened is offered. What is on screen stays until the answer
		 * arrives, and stays if it does not (`refreshFailed`). A list already being
		 * fetched is left to finish. The once-per-session cache is otherwise untouched.
		 */
		refresh() {
			keys.forEach( ( key ) => {
				if ( state[ key ].status === 'ready' ) {
					run( key, true );
				}
			} );
		},

		getState() {
			return state;
		},

		subscribe( listener ) {
			listeners.push( listener );

			return () => {
				const index = listeners.indexOf( listener );

				if ( index !== -1 ) {
					listeners.splice( index, 1 );
				}
			};
		},
	};
}
