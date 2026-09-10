import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'header-auth',
		icon: 'external',
		label: __( 'Agend Header Login', 'agend-apps-core' ),
		instructions: () =>
			__( 'A single Log In / My Portal link that adapts to the session. Renders on the published page.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
