import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'community-channels',
		icon: 'groups',
		label: __( 'Agend Community Channels', 'agend-apps-core' ),
		instructions: ( attributes ) =>
			sprintf(
				/* translators: %d: number of channels shown. */
				__( 'Up to %d community channels, each with a link in. Renders on the published page.', 'agend-apps-core' ),
				attributes.count || 8
			),
	} ),
	save: () => null,
} );
