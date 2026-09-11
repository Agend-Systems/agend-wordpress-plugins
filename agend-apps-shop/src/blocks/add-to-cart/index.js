import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../../../../agend-apps-core/src/blocks/shared/surface-edit';

// Attributes come from the server: agend-apps-core derives them from the
// surface schema (agend_apps_records_schema_add_to_cart()) and registers
// them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'add-to-cart',
		icon: 'cart',
		label: __( 'Agend Add to Cart', 'agend-apps-shop' ),
		instructions: () =>
			__( 'Quantity stepper and Add to Cart button for one product. Renders on the published page.', 'agend-apps-shop' ),
	} ),
	save: () => null,
} );
