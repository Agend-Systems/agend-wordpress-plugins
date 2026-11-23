import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'credential-check',
		icon: 'yes-alt',
		label: __( 'Agend Credential Check', 'agend-apps-core' ),
		instructions: () =>
			__( 'A form where visitors check a certificate or badge code against the Agend learning record. Renders on the published page.', 'agend-apps-core' ),
	} ),
	save: () => null,
} );
