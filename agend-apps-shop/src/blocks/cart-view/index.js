import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../../../../agend-apps-core/src/blocks/shared/surface-edit';

// Attributes come from the server: agend-apps-core derives them from the
// surface schema (agend_apps_records_schema_cart_view()) and registers them
// in PHP, so nothing is declared twice.
//
// supports.multiple is false (block.json): this surface renders the whole
// cart -- items, totals, checkout actions, and a single clear-cart
// confirmation modal with a static id -- and is meant for one dedicated cart
// page, the same "not a real layout" reasoning agend-apps-core's member-login
// block uses for its own multiple: false.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'cart-view',
		icon: 'editor-table',
		label: __( 'Agend Cart View', 'agend-apps-shop' ),
		instructions: () =>
			__( 'Full shopping cart, for a dedicated cart page. Renders on the published page.', 'agend-apps-shop' ),
	} ),
	save: () => null,
} );
