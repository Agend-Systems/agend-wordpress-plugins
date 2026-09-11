import { registerBlockType } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../shared/surface-edit';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'memberships-catalogue',
		icon: 'money-alt',
		label: __( 'Agend Memberships Catalogue', 'agend-apps-core' ),
		// This surface has no pagination control (it is a plain tier grid), so
		// it needs its own summary rather than the shared columns/pagination one.
		instructions: ( attributes ) =>
			sprintf(
				/* translators: %s: desktop column count */
				__( '%s columns. The catalogue renders on the published page.', 'agend-apps-core' ),
				attributes.columns_desktop || '3'
			),
	} ),
	save: () => null,
} );
