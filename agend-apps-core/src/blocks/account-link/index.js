import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'account-link',
		icon: 'id-alt',
		label: __( 'Agend Account Link', 'agend-apps-core' ),
		instructions: () =>
			__( "Shows whether a member's account is linked to Agend. Renders on the published page.", 'agend-apps-core' ),
	} ),
	save: () => null,
} );
