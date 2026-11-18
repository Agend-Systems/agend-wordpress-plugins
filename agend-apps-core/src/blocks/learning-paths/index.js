import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'learning-paths',
		icon: 'editor-ol',
		label: __( 'Agend Learning Pathways', 'agend-apps-core' ),
		instructions: ( attributes ) =>
			'selected' === attributes.path_source
				? __( 'The learning pathways you chose, each with its courses in order. Renders on the published page.', 'agend-apps-core' )
				: sprintf(
						/* translators: %d: number of paths shown. */
						__( 'Up to %d learning pathways, each with its courses in order. Renders on the published page.', 'agend-apps-core' ),
						attributes.max_paths || 6
				  ),
	} ),
	save: () => null,
} );
