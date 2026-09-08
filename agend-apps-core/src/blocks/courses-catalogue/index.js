import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'courses-catalogue',
		icon: 'welcome-learn-more',
		label: __( 'Agend Courses Catalogue', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
