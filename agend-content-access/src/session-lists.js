/**
 * The editor session's lists, wired to the REST API.
 *
 * The rules (one request per list per session, a failure remembered, a retry
 * that refreshes) are in lists-loader.mjs, which has no WordPress imports so it
 * can be tested. This only supplies the fetchers and a hook.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { createListsLoader } from './lists-loader.mjs';

const NAMESPACE = '/agend-content-access/v1';

const loader = createListsLoader( {
	// A 404 (the route does not exist yet) or a network failure rejects, which
	// the loader records as `failed`. The panel then says the lists could not
	// be loaded and keeps showing what the block already holds.
	catalogue: ( refresh ) => apiFetch( { path: NAMESPACE + '/catalogue' + ( refresh ? '?refresh=1' : '' ) } ),
	conditions: () => apiFetch( { path: NAMESPACE + '/conditions' } ),
} );

/**
 * The shared lists and a way to ask again.
 *
 * Starts the first load the first time any panel mounts; every later panel,
 * for any block, reads the same state without a request.
 *
 * @return {{lists: Object, retry: Function}} `lists.catalogue` and
 *         `lists.conditions`, each `{status, data}` with status one of
 *         idle, loading, ready or failed.
 */
export function useAgendLists() {
	const [ lists, setLists ] = useState( loader.getState() );

	useEffect( () => {
		const unsubscribe = loader.subscribe( () => setLists( loader.getState() ) );

		// Anything that settled between the render and this effect.
		setLists( loader.getState() );
		loader.ensure();

		return unsubscribe;
	}, [] );

	return { lists, retry: loader.retry };
}
