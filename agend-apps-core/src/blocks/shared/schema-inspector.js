/**
 * Renders a surface's content-settings schema as block inspector controls.
 *
 * The schema comes from core over REST (see agend_apps_records_block_editor_schema)
 * and is the same declaration the Elementor widget builds its controls from.
 * Conditions are evaluated against Elementor-shaped values ('yes'/'') because
 * that is how the schema states them.
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { useSettings } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	TextControl,
	TextareaControl,
	SelectControl,
	CheckboxControl,
	BaseControl,
	ColorPalette,
	Notice,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';

export function useSurfaceSchema( surface ) {
	const [ state, setState ] = useState( { schema: null, error: null } );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: `/agend-apps/v1/surfaces/${ surface }/schema` } )
			.then( ( schema ) => ! cancelled && setState( { schema, error: null } ) )
			.catch( () => ! cancelled && setState( { schema: null, error: __( 'Could not load the settings for this block.', 'agend-apps-core' ) } ) );
		return () => {
			cancelled = true;
		};
	}, [ surface ] );

	return state;
}

function elementorShaped( value ) {
	if ( typeof value === 'boolean' ) {
		return value ? 'yes' : '';
	}
	return value === undefined || value === null ? '' : String( value );
}

export function conditionMet( condition, attributes ) {
	if ( ! condition ) {
		return true;
	}
	return Object.entries( condition ).every( ( [ key, expected ] ) => {
		const negate = key.endsWith( '!' );
		const name = negate ? key.slice( 0, -1 ) : key;
		const actual = elementorShaped( attributes[ name ] );
		const expectedList = Array.isArray( expected ) ? expected.map( String ) : [ String( expected ) ];
		const matches = expectedList.includes( actual );
		return negate ? ! matches : matches;
	} );
}

function optionsToChoices( options ) {
	return Object.entries( options || {} ).map( ( [ value, label ] ) => ( { value, label: String( label ) } ) );
}

function Field( { field, attributes, setAttributes } ) {
	const { name, type, label, description } = field;
	const set = ( value ) => setAttributes( { [ name ]: value } );
	const value = attributes[ name ];
	// Called unconditionally so the colour branch below stays within the
	// rules of hooks; unused for every other field type.
	const [ paletteColours ] = useSettings( 'color.palette' );

	switch ( type ) {
		case 'colour': {
			const colours = paletteColours || [];
			const onChange = ( next ) => {
				if ( ! next ) {
					set( '' );
					return;
				}
				const preset = colours.find( ( colour ) => colour.color === next );
				set( preset ? `var(--wp--preset--color--${ preset.slug })` : next );
			};
			return (
				<BaseControl label={ label } help={ description } __nextHasNoMarginBottom>
					<ColorPalette
						colors={ colours }
						value={ value ?? field.default ?? '' }
						onChange={ onChange }
						enableAlpha={ false }
						clearable
					/>
				</BaseControl>
			);
		}
		case 'toggle':
			return <ToggleControl label={ label } help={ description } checked={ !! value } onChange={ set } __nextHasNoMarginBottom />;
		case 'text':
			return <TextControl label={ label } help={ description } value={ value ?? '' } onChange={ set } __nextHasNoMarginBottom __next40pxDefaultSize />;
		case 'textarea':
			return <TextareaControl label={ label } help={ description } value={ value ?? '' } onChange={ set } __nextHasNoMarginBottom />;
		case 'number':
			return (
				<NumberControl
					label={ label }
					help={ description }
					value={ value ?? field.default ?? 0 }
					min={ field.min }
					max={ field.max }
					step={ field.step }
					onChange={ ( next ) => set( next === '' ? '' : Number( next ) ) }
					__next40pxDefaultSize
				/>
			);
		case 'select':
		case 'template':
			return (
				<SelectControl
					label={ label }
					help={ description }
					value={ value ?? '' }
					options={ optionsToChoices( field.options ) }
					onChange={ set }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			);
		case 'multiselect': {
			const selected = Array.isArray( value ) ? value : [];
			return (
				<BaseControl label={ label } help={ description } __nextHasNoMarginBottom>
					{ optionsToChoices( field.options ).map( ( choice ) => (
						<CheckboxControl
							key={ choice.value }
							label={ choice.label }
							checked={ selected.includes( choice.value ) }
							onChange={ ( on ) => set( on ? [ ...selected, choice.value ] : selected.filter( ( v ) => v !== choice.value ) ) }
							__nextHasNoMarginBottom
						/>
					) ) }
				</BaseControl>
			);
		}
		case 'note':
			return <Notice status="info" isDismissible={ false }>{ field.content }</Notice>;
		case 'heading':
			return <BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>;
		default:
			// 'adapter' fields are builder-specific; the block supplies its own
			// control for those if it needs one, elsewhere.
			return null;
	}
}

/**
 * Renders one tab's worth of a surface schema's sections. `tab` selects
 * `'content'` (the default, every section without `tab: 'style'`) or
 * `'style'` (only sections with `tab: 'style'`), so an edit component can
 * place each half in its own `InspectorControls` slot.
 */
export function SchemaInspector( { schema, attributes, setAttributes, tab = 'content' } ) {
	const sections = ( schema.sections || [] ).filter(
		( section ) => ( 'style' === section.tab ? 'style' : 'content' ) === tab
	);

	return sections.map( ( section, index ) =>
		conditionMet( section.condition, attributes ) ? (
			<PanelBody key={ section.id } title={ section.label } initialOpen={ index === 0 }>
				{ ( section.fields || [] ).map( ( field ) =>
					conditionMet( field.condition, attributes ) ? (
						<Field key={ field.name } field={ field } attributes={ attributes } setAttributes={ setAttributes } />
					) : null
				) }
			</PanelBody>
		) : null
	);
}
