/**
 * Builds a block editor edit component for a surface, over the same schema
 * seam every surface's Elementor widget uses (see schema-inspector.js).
 *
 * Every catalogue and form surface needs the same shape of edit component:
 * content settings in the default inspector slot, style settings in the
 * Styles tab, and a placeholder standing in for the front-end render. This
 * factory is that shape, parameterised by the surface id and the
 * placeholder's icon, label and instructions text.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Placeholder, Spinner, Notice } from '@wordpress/components';
import { SchemaInspector, useSurfaceSchema } from './schema-inspector';

function defaultInstructions( attributes ) {
	return sprintf(
		/* translators: 1: desktop column count, 2: pagination style */
		__( '%1$s columns, %2$s pagination. The catalogue renders on the published page.', 'agend-apps-core' ),
		attributes.columns_desktop || '3',
		attributes.pagination_style || 'numbered'
	);
}

/**
 * @param {Object}   config              Configuration.
 * @param {string}   config.surface      Surface id, e.g. 'events-catalogue'.
 * @param {string}   config.icon         Placeholder icon.
 * @param {string}   config.label        Placeholder label.
 * @param {Function} [config.instructions] `( attributes ) => string`. Defaults
 *   to the column/pagination summary shared by the catalogue surfaces.
 * @return {Function} A block edit component.
 */
export function createSurfaceEdit( { surface, icon, label, instructions = defaultInstructions } ) {
	return function Edit( { attributes, setAttributes } ) {
		const { schema, error } = useSurfaceSchema( surface );
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
					<Placeholder icon={ icon } label={ label } instructions={ instructions( attributes ) } />
				</div>
			</>
		);
	};
}
