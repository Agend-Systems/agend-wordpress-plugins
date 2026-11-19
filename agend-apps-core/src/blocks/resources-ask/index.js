import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'resources-ask',
		icon: 'format-chat',
		label: __( 'Agend Resources Ask', 'agend-apps-core' ),
		instructions: () => __( 'A question box for signed-in members, with answers drawn from your library. Visitors see a sign-in prompt, or nothing. Renders on the published page.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
