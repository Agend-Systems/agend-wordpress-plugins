/**
 * Edit component for the Agend Image block.
 *
 * Like the Agend Filter block, this surface has no meaning outside a card or
 * detail template: agend_apps_records_record_image_render_reason() and
 * agend_apps_records_render_record_image() both read whichever record
 * Agend_Apps_Records_Record_Context has in scope, and there is none on an
 * ordinary page or in the block editor itself. So this does not use the
 * generic shared/surface-edit.js placeholder factory the catalogue blocks
 * use: a bare icon-and-label placeholder would tell a template author
 * nothing about what they are placing. Instead it fetches the core
 * renderer's own stand-in preview markup from the shared block-editor
 * preview route, so a designer styles the real image or background element.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Spinner, Notice } from '@wordpress/components';
import metadata from './block.json';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';
import { useSurfacePreview } from '../shared/surface-preview';

/**
 * Translated text for agend_apps_records_record_image_render_reason()'s one
 * code, reusing the Agend Image widget's own wording
 * (Agend_Elementor_Record_Image::render()'s render_editor_notice() call).
 */
const RECORD_IMAGE_REASON_MESSAGES = {
	no_image: __( 'This record has no image and no fallback image is set.', 'agend-apps-core' ),
};

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( 'record-image' );
		// Shared with the templated record surfaces and the filter block,
		// which all have the same "renders nothing without context" problem.
		// See surface-preview.js for why this is a dedicated route and not
		// ServerSideRender. previewType comes from the same shared resolver
		// Agend Field/Pills/Link use (the block's own `field` setting, then
		// the open wp_block template's recorded type), so this block's
		// preview is as accurate as theirs rather than always falling back
		// to the server's own 'event' default.
		const preview = useSurfacePreview( 'record-image', attributes );
		const blockProps = useBlockProps();
		const message = preview.reason ? RECORD_IMAGE_REASON_MESSAGES[ preview.reason ] : null;

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
						// The core renderer's own stand-in markup: the same
						// <img> or background element a visitor gets.
						<div dangerouslySetInnerHTML={ { __html: preview.html } } />
					) : ! message ? (
						<Notice status="info" isDismissible={ false }>
							{ __( 'This image only renders inside a card or detail template, from the record it is showing.', 'agend-apps-core' ) }
						</Notice>
					) : null }
				</div>
			</>
		);
	},
	save: () => null,
} );
