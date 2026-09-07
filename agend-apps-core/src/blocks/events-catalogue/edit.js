import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Placeholder, Spinner } from '@wordpress/components';
import { SchemaInspector, useSurfaceSchema } from '../shared/schema-inspector';

const SURFACE = 'events-catalogue';

export default function Edit( { attributes, setAttributes } ) {
	const { schema, error } = useSurfaceSchema( SURFACE );
	const blockProps = useBlockProps();

	const summary = sprintf(
		/* translators: 1: desktop column count, 2: pagination style */
		__( '%1$s columns, %2$s pagination. The catalogue renders on the published page.', 'agend-apps-core' ),
		attributes.columns_desktop || '3',
		attributes.pagination_style || 'numbered'
	);

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
				<Placeholder icon="calendar-alt" label={ __( 'Agend Events Catalogue', 'agend-apps-core' ) } instructions={ summary } />
			</div>
		</>
	);
}
