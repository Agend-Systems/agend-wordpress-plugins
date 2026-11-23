import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'community-announcements',
		icon: 'megaphone',
		label: __( 'Agend Community Announcements', 'agend-apps-core' ),
		instructions: ( attributes ) =>
			sprintf(
				/* translators: %d: number of announcements shown. */
				__( 'Up to %d announcements from your community, pinned first. Renders on the published page.', 'agend-apps-core' ),
				attributes.count || 5
			),
	} ),
	save: () => null,
} );
