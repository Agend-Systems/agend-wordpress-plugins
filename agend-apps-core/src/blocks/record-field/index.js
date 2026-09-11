/**
 * Edit component for the Agend Field block.
 *
 * The record field surface has no meaning outside a card or detail template
 * (Agend_Apps_Records_Record_Context carries no frame there), the same
 * "renders nothing without context" problem the filter block has, so like
 * that block this does not use the generic shared/surface-edit.js
 * placeholder factory: it fetches the core renderer's own stand-in preview
 * markup from a dedicated block-editor route instead, so an author styles the
 * actual rendered field rather than a bare icon-and-label placeholder. See
 * shared/surface-preview.js for why this is a dedicated route and not
 * ServerSideRender.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Placeholder, Spinner, Notice } from '@wordpress/components';
import metadata from './block.json';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';
import { useSurfacePreview } from '../shared/surface-preview';

/**
 * Translated text for each agend_apps_records_record_field_render_reason()
 * code, reusing the wording Agend_Elementor_Record_Field::render() already
 * shows via render_editor_notice().
 */
const RECORD_FIELD_REASON_MESSAGES = {
	field_not_applicable: __( 'This field does not exist on the record type this template renders.', 'agend-apps-core' ),
};

const ICON = 'text';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( 'record-field' );
		// Which record type the preview uses is resolved server-side from the
		// field key (e.g. 'listing:name' names its own type) and, failing that,
		// from the template being edited. See
		// agend_apps_records_surface_preview_type().
		const preview = useSurfacePreview( 'record-field', attributes );
		const blockProps = useBlockProps();
		const message = preview.reason ? RECORD_FIELD_REASON_MESSAGES[ preview.reason ] : null;

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
						// The core renderer's own stand-in field markup, the same
						// nodes a visitor gets, class for class.
						<div dangerouslySetInnerHTML={ { __html: preview.html } } />
					) : ! message ? (
						<Placeholder
							icon={ ICON }
							label={ __( 'Agend Field', 'agend-apps-core' ) }
							instructions={ __( 'Renders one value from the record a card or detail template is rendering. Renders nothing on an ordinary page.', 'agend-apps-core' ) }
						/>
					) : null }
				</div>
			</>
		);
	},
	save: () => null,
} );
