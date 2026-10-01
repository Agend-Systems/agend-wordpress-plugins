import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'directory-map',
		icon: 'location-alt',
		label: __( 'Agend Map', 'agend-apps-core' ),
		instructions: () =>
			__( 'A map of the listings the Agend Directory Catalogue on this page is showing. Renders on the published page.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
