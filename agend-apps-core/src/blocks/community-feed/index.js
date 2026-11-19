import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'community-feed',
		icon: 'format-chat',
		label: __( 'Agend Community Popular Posts', 'agend-apps-core' ),
		instructions: ( attributes ) =>
			'recent' === attributes.order
				? sprintf(
						/* translators: %d: number of posts shown. */
						__( 'The newest %d community posts. Renders on the published page.', 'agend-apps-core' ),
						attributes.count || 5
				  )
				: sprintf(
						/* translators: %d: number of posts shown. */
						__( 'Up to %d popular community posts: featured first, then the most replies. Renders on the published page.', 'agend-apps-core' ),
						attributes.count || 5
				  ),
	} ),
	save: () => null,
} );
