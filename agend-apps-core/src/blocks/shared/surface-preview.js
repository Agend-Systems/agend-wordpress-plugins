/**
 * Fetches a surface's server-rendered editor preview.
 *
 * Why a dedicated route rather than ServerSideRender: a block's registered
 * render callback (its render.php) is also the LIVE front-end path, and
 * agend_apps_records_render_block() forwards only the attributes, with no way
 * to say "this particular call is a preview". A surface that renders nothing
 * on a page where it has no record or catalogue context, which is every
 * templated surface, therefore cannot tell the two apart from inside that
 * callback. Widening the shared render contract so every block carried a
 * preview flag it never uses was rejected; one editor-only route that passes
 * the extra opt is the smaller change.
 *
 * The markup returned is the core renderer's own stand-in output, so an editor
 * styles the same nodes and classes a visitor will get, with no preview markup
 * duplicated in JS.
 *
 * See agend_apps_records_register_surface_preview_route() in
 * includes/records/blocks.php.
 */
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Which record type the preview renders against is decided by the SERVER, in
 * agend_apps_records_surface_preview_type(): from the surface's own field or
 * panel key, which names its type by itself, and failing that from the
 * template being edited, which records the type its record surfaces imply.
 * Both are PHP rules over PHP data, so the only thing the editor contributes
 * is which post is open.
 *
 * @param {string} surface    Surface id, e.g. 'record-field'.
 * @param {Object} attributes Current block attributes.
 * @return {{html: string, reason: string, loading: boolean}} The preview
 *   markup, the render-nothing reason code (or ''), and whether a request is
 *   in flight.
 */
export function useSurfacePreview( surface, attributes ) {
	const [ state, setState ] = useState( { html: '', reason: '', loading: true } );

	// 0 outside a post-editing context (the widgets screen, say), which the
	// server reads as "no template to ask".
	const postId = useSelect( ( select ) => {
		const editor = select( 'core/editor' );

		return editor?.getCurrentPostId?.() ?? 0;
	}, [] );

	// Attributes are serialised for the dependency list rather than compared by
	// reference: the editor hands `edit` a new object on every keystroke, and
	// depending on the reference alone would refetch the preview when nothing
	// this surface reads had actually changed.
	const key = JSON.stringify( attributes );

	useEffect( () => {
		let cancelled = false;
		setState( ( previous ) => ( { ...previous, loading: true } ) );
		apiFetch( {
			path: `/agend-apps/v1/surfaces/${ surface }/preview`,
			method: 'POST',
			data: { attributes, post_id: postId },
		} )
			.then(
				( result ) =>
					! cancelled &&
					setState( { html: result?.html ?? '', reason: result?.reason ?? '', loading: false } )
			)
			.catch( () => ! cancelled && setState( { html: '', reason: '', loading: false } ) );
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ surface, key, postId ] );

	return state;
}
