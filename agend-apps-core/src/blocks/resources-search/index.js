import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'resources-search',
		icon: 'search',
		label: __( 'Agend Resources Search', 'agend-apps-core' ),
		instructions: ( attributes ) =>
			'live' === attributes.visitor_mode
				? __( 'A search box. Signed-in members and visitors see results as they type. Renders on the published page.', 'agend-apps-core' )
				: __( 'A search box. Visitors are sent to your public search page; signed-in members see results as they type. Renders on the published page.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
