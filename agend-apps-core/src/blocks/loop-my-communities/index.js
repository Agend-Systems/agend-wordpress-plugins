/**
 * Edit component for the Agend My Communities block.
 *
 * The surface shows a member's own communities, so the editor has no member to
 * show. Instead of a bare placeholder it fetches the core renderer's own
 * labelled example (made-up communities, drawn from the same markup a member
 * sees), so the editor styles and judges the real thing. The example makes no call to Agend. See
 * shared/surface-preview.js for why this is a dedicated route and not
 * ServerSideRender.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Spinner, Notice } from '@wordpress/components';
import metadata from './block.json';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';
import { useSurfacePreview } from '../shared/surface-preview';

// Attributes come from the server: core derives them from the surface schema
// and registers them in PHP, so nothing is declared twice.
registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( 'loop-my-communities' );
		const preview = useSurfacePreview( 'loop-my-communities', attributes );
		const blockProps = useBlockProps();
		const notices = schema?.notices ?? [];

		return (
			<>
				<InspectorControls>
					{ schema ? (
						<SchemaInspector schema={ schema } attributes={ attributes } setAttributes={ setAttributes } tab="content" />
					) : (
						<div style={ { padding: '16px' } }>{ error ? error : <Spinner /> }</div>
					) }
				</InspectorControls>
				{ schema && (
					<InspectorControls group="styles">
						<SchemaInspector schema={ schema } attributes={ attributes } setAttributes={ setAttributes } tab="style" />
					</InspectorControls>
				) }
				<div { ...blockProps }>
					{ notices.map( ( notice, index ) => (
						<Notice key={ index } status={ notice.status } isDismissible={ false }>
							{ notice.text }
						</Notice>
					) ) }
					{ preview.loading ? (
						<Spinner />
					) : preview.html ? (
						// The core renderer's own labelled example: the same nodes a
						// member gets, with every link switched off by the stylesheet.
						<div dangerouslySetInnerHTML={ { __html: preview.html } } />
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __( 'Members see the communities they belong to here once they sign in.', 'agend-apps-core' ) }
						</Notice>
					) }
				</div>
			</>
		);
	},
	save: () => null,
} );
