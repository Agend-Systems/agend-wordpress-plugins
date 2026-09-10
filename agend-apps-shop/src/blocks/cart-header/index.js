import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import { createSurfaceEdit } from '../../../../agend-apps-core/src/blocks/shared/surface-edit';

// Attributes come from the server: agend-apps-core derives them from the
// surface schema (agend_apps_records_schema_cart_header()) and registers
// them in PHP, so nothing is declared twice.
//
// Allows several instances on one page (no supports.multiple: false): unlike
// agend-apps-core's member-login block, the cart-header script already binds
// every `.agend-apps-shop-cart-header` element it finds and keeps each one's
// badge in sync independently, so a site placing one in a desktop nav and
// another in a mobile menu is a real, already-supported layout.
registerBlockType( metadata.name, {
	edit: createSurfaceEdit( {
		surface: 'cart-header',
		icon: 'cart',
		label: __( 'Agend Cart Header', 'agend-apps-shop' ),
		instructions: () =>
			__( 'Cart icon with a live item-count badge. Renders on the published page.', 'agend-apps-shop' ),
	} ),
	save: () => null,
} );
