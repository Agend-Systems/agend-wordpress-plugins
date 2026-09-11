/**
 * Edit component for the Agend Link / Button block.
 *
 * The record link surface has no meaning outside a card or detail template
 * (Agend_Apps_Records_Record_Context carries no frame there), the same
 * "renders nothing without context" problem the filter block has, so like
 * that block this does not use the generic shared/surface-edit.js
 * placeholder factory: it fetches the core renderer's own stand-in preview
 * markup from a dedicated block-editor route instead, so an author styles the
 * actual rendered link or button rather than a bare icon-and-label
 * placeholder. See shared/surface-preview.js for why this is a dedicated
 * route and not ServerSideRender.
 *
 * Unlike record-field and record-pills, this surface's own settings name no
 * field key (its 'action' control picks an action, not a record field), so
 * there is no instance-level type hint to read; the preview type comes from
 * the template being edited alone, resolved server-side by
 * agend_apps_records_surface_preview_type().
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Placeholder, Spinner, Notice } from '@wordpress/components';
import metadata from './block.json';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';
import { useSurfacePreview } from '../shared/surface-preview';

/**
 * Translated text for each agend_apps_records_record_link_render_reason()
 * code, reusing the wording Agend_Elementor_Record_Link::render() already
 * shows via render_editor_notice().
 */
const RECORD_LINK_REASON_MESSAGES = {
	ical_wrong_type: __( 'Add to calendar is only available for events.', 'agend-apps-core' ),
	enrol_wrong_type: __( 'Enrol is only available for courses.', 'agend-apps-core' ),
	register_wrong_type: __( 'Register is only available for events.', 'agend-apps-core' ),
};

const ICON = 'button';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( 'record-link' );
		const preview = useSurfacePreview( 'record-link', attributes );
		const blockProps = useBlockProps();
		const message = preview.reason ? RECORD_LINK_REASON_MESSAGES[ preview.reason ] : null;

		return (
			<>
				<InspectorControls>
					{ schema ? (
						<SchemaInspector schema={ schema } attributes={ attributes } setAttributes={ setAttributes } tab="content" />
					) : (
						<div style={ { padding: '16px' } }>{ error ? error : <Spinner /> }</div>
					) }
				</InspectorControls>
				<div { ...blockProps }>
					{ message && (
						<Notice status="warning" isDismissible={ false }>
							{ message }
						</Notice>
					) }
					{ preview.loading ? (
						<Spinner />
					) : preview.html ? (
						// The core renderer's own stand-in link/button markup, the
						// same nodes a visitor gets, class for class.
						<div dangerouslySetInnerHTML={ { __html: preview.html } } />
					) : ! message ? (
						<Placeholder
							icon={ ICON }
							label={ __( 'Agend Link / Button', 'agend-apps-core' ) }
							instructions={ __( 'Renders an action link or button for the record a card or detail template is rendering. Renders nothing on an ordinary page.', 'agend-apps-core' ) }
						/>
					) : null }
				</div>
			</>
		);
	},
	save: () => null,
} );
