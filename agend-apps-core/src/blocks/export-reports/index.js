import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'export-reports',
		icon: 'download',
		label: __( 'Agend Export Reports', 'agend-apps-core' ),
		instructions: () =>
			__( 'A button or menu that downloads Agend directory export reports. Renders on the published page.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
