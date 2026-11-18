import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf, _n } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'resources-collections',
		icon: 'portfolio',
		label: __( 'Agend Resources Collections', 'agend-apps-core' ),
		instructions: ( attributes ) =>
			( attributes.collection_ids || [] ).length
				? sprintf(
						/* translators: %d: number of collections chosen. */
						_n( '%d collection you chose, shown to everyone. Signed-in members also see their own. Renders on the published page.', '%d collections you chose, shown to everyone. Signed-in members also see their own. Renders on the published page.', ( attributes.collection_ids || [] ).length, 'agend-apps-core' ),
						( attributes.collection_ids || [] ).length
				  )
				: __( 'Choose the collections to show in the block settings. Visitors see nothing until you do.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
