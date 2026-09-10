/**
 * Edit component for the Agend Panel block.
 *
 * Like the Agend Filter block, this surface has no meaning outside a card or
 * detail template: agend_apps_records_record_block_render_reason() and
 * agend_apps_records_render_record_block() both read whichever record
 * Agend_Apps_Records_Record_Context has in scope, and there is none on an
 * ordinary page or in the block editor itself. So this does not use the
 * generic shared/surface-edit.js placeholder factory the catalogue blocks
 * use: a bare icon-and-label placeholder would tell a template author
 * nothing about what they are placing. Instead it fetches the core
 * renderer's own stand-in preview markup from the shared block-editor
 * preview route, so a designer styles the real panel fragment.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Spinner, Notice } from '@wordpress/components';
import metadata from './block.json';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';
import { useSurfacePreview } from '../shared/surface-preview';

/**
 * Translated text for each agend_apps_records_record_block_render_reason()
 * code, reusing the Agend Panel widget's own wording
 * (Agend_Elementor_Record_Block::render()'s render_editor_notice() and
 * render_retired_notice() calls). 'wrong_type' and 'retired' drop the
 * interpolated record/field-type names the widget's own messages carry (the
 * panel's own type, the template's type, and the specific field a retired
 * key became): the widget reads those directly off itself and off
 * agend_apps_records_retired_block_field(), neither of which the block's
 * preview route (agend_apps_records_register_surface_preview_route(), a
 * route shared by every templated surface) exposes, so this keeps the rest
 * of each message rather than only a generic one.
 */
const RECORD_BLOCK_REASON_MESSAGES = {
	wrong_type: __( "This panel's record type does not match the template it is placed in. Nothing will show here on the live site.", 'agend-apps-core' ),
	tickets_live_only: __( 'The tickets panel renders from the live ticket list on the detail page.', 'agend-apps-core' ),
	empty_fragment: __( 'This record has nothing to show for this block.', 'agend-apps-core' ),
	retired: __( 'This is now a field. It still renders, but new templates should use Agend Field or Agend Pills instead, which can label and format the value.', 'agend-apps-core' ),
};

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( 'record-block' );
		// Shared with the templated record surfaces and the filter block,
		// which all have the same "renders nothing without context" problem.
		// See surface-preview.js for why this is a dedicated route and not
		// ServerSideRender. previewType comes from the same shared resolver
		// Agend Field/Pills/Link use: this surface's own `block` key follows
		// the same event_/course_/listing_ prefix typeFromKey() reads
		// (agend_apps_records_record_block_blocks(), render/record-block.php),
		// so the chosen panel resolves the right kind of preview record
		// instead of always falling back to the server's own 'event' default.
		const preview = useSurfacePreview( 'record-block', attributes );
		const blockProps = useBlockProps();
		const message = preview.reason ? RECORD_BLOCK_REASON_MESSAGES[ preview.reason ] : null;

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
						// 'retired' is the one reason that does not mean
						// "renders nothing": the panel still renders its
						// fragment (agend_apps_records_record_block_render_reason()'s
						// docblock), so this notice appears ALONGSIDE the
						// preview below rather than instead of it, exactly as
						// Agend_Elementor_Record_Block::render() shows its own
						// retired notice unconditionally, independent of
						// whether the panel goes on to render.
						<Notice status="warning" isDismissible={ false }>
							{ message }
						</Notice>
					) }
					{ preview.loading ? (
						<Spinner />
					) : preview.html ? (
						// The core renderer's own stand-in markup: the same
						// panel fragment a visitor gets.
						<div dangerouslySetInnerHTML={ { __html: preview.html } } />
					) : ! message ? (
						<Notice status="info" isDismissible={ false }>
							{ __( 'This panel only renders inside a card or detail template, from the record it is showing.', 'agend-apps-core' ) }
						</Notice>
					) : null }
				</div>
			</>
		);
	},
	save: () => null,
} );
