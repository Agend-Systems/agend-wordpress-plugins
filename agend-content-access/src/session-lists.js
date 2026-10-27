/**
 * The editor session's rule vocabulary, wired to the REST API.
 *
 * The rules (one request per list per session, a failure remembered, a retry
 * that refreshes) are in lists-loader.mjs, which has no WordPress imports so it
 * can be tested. This only supplies the fetcher and a hook.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { createListsLoader } from './lists-loader.mjs';

const NAMESPACE = '/agend-content-access/v1';

const loader = createListsLoader( {
	// One request for everything the picker offers, plans and segments by id.
	// A 404 (the route does not exist yet) or a network failure rejects, which
	// the loader records as `failed`. The panel then says the rules could not be
	// loaded and keeps showing what the block already holds. A retry asks the
	// server to read the lists again rather than answer from its cache.
	vocabulary: ( refresh ) => apiFetch( { path: NAMESPACE + '/conditions' + ( refresh ? '?refresh=1' : '' ) } ),
} );

/**
 * The shared vocabulary and a way to ask again.
 *
 * Starts the first load the first time any panel mounts; every later panel,
 * for any block, reads the same state without a request.
 *
 * @return {{vocabulary: {status: string, data: *}, retry: Function}} The state,
 *         with status one of idle, loading, ready or failed.
 */
export function useAgendVocabulary() {
	const [ state, setState ] = useState( loader.getState() );

	useEffect( () => {
		const unsubscribe = loader.subscribe( () => setState( loader.getState() ) );

		// Anything that settled between the render and this effect.
		setState( loader.getState() );
		loader.ensure();

		return unsubscribe;
	}, [] );

	return { vocabulary: state.vocabulary, retry: loader.retry };
}
