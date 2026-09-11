/**
 * Edit component for the Agend Filter block.
 *
 * The filter surface has no meaning outside a catalogue's filters template
 * (Agend_Apps_Records_Filter_Context::type() is '' anywhere else), so on a
 * live page it renders nothing until it sits inside one. The block editor has
 * no such context either, so this block does not reuse the generic
 * shared/surface-edit.js placeholder factory the other surfaces use: a bare
 * icon-and-label placeholder would tell a template author nothing about what
 * they are styling. Instead it fetches the core renderer's own stand-in
 * preview markup (the same nodes assets/js/filters.js would otherwise build
 * at runtime, class for class) from a dedicated block-editor route, so a
 * designer styles the real thing.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Spinner, Notice } from '@wordpress/components';
import metadata from './block.json';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';
import { useSurfacePreview } from '../shared/surface-preview';

/**
 * Translated text for each agend_apps_records_filter_render_reason() code.
 *
 * Not routed through agend_apps_records_block_surface_notices(): that
 * function is keyed by surface id alone, with no per-instance attributes, and
 * every one of these reasons depends on THIS instance's own settings (which
 * filter is chosen, its custom field key, its defined choices). It has no way
 * to express a per-instance reason, so the diagnostic lives here instead.
 */
const FILTER_REASON_MESSAGES = {
	wrong_catalogue: __( 'This filter belongs to a different catalogue, so it renders nothing here.', 'agend-apps-core' ),
	unconfigured: __( 'This filter is not configured yet. A custom field filter needs its field key.', 'agend-apps-core' ),
	missing_field_key: __( 'Enter the custom field key this filter targets.', 'agend-apps-core' ),
	no_choices: __( 'This filter needs the choices you define: its values cannot be listed from the API yet. Add choices under Values.', 'agend-apps-core' ),
};

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( 'filter' );
		// Shared with the templated record surfaces, which have the same
		// "renders nothing without context" problem. See surface-preview.js
		// for why this is a dedicated route and not ServerSideRender.
		const preview = useSurfacePreview( 'filter', attributes );
		const blockProps = useBlockProps();
		const message = preview.reason ? FILTER_REASON_MESSAGES[ preview.reason ] : null;

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
						// The core renderer's own stand-in control markup, the same
						// nodes assets/js/filters.js would otherwise build at runtime.
						// See agend_apps_records_render_filter_preview_control().
						<div dangerouslySetInnerHTML={ { __html: preview.html } } />
					) : ! message ? (
						<Notice status="info" isDismissible={ false }>
							{ __( 'This filter only renders inside the filters template of a catalogue.', 'agend-apps-core' ) }
						</Notice>
					) : null }
				</div>
			</>
		);
	},
	save: () => null,
} );
