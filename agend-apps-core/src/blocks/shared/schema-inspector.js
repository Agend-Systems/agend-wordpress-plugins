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
import { __, sprintf } from '@wordpress/i18n';
import { useSettings, MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
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
	Button,
	Card,
	CardHeader,
	CardBody,
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

/**
 * Whether a schema select field declares its choices as grouped.
 *
 * A `select` may carry either `options` (a flat value => label map) or
 * `groups` (a list of `{ label, options }`), and the five surfaces whose main
 * control answers "which field is this?" all use `groups`: the Agend Filter's
 * filter picker, and the field or panel picker on Agend Field, Pills, Image
 * and Panel. Reading only `options` renders those as an EMPTY dropdown, which
 * leaves the one control that matters most on each of those blocks unusable.
 *
 * @param {Object} field A schema field.
 * @return {boolean} True when the field declares `groups` with entries.
 */
function hasGroups( field ) {
	return Array.isArray( field.groups ) && field.groups.length > 0;
}

/**
 * A grouped select's `<optgroup>` children.
 *
 * SelectControl renders its children in place of the `options` prop, which is
 * the only way to get real option groups out of it.
 *
 * @param {Array} groups The field's `groups` list.
 * @return {Array} optgroup elements.
 */
function groupChildren( groups ) {
	return groups.map( ( group, index ) => (
		<optgroup key={ group.label ?? index } label={ group.label ?? '' }>
			{ optionsToChoices( group.options ).map( ( choice ) => (
				<option key={ choice.value } value={ choice.value }>
					{ choice.label }
				</option>
			) ) }
		</optgroup>
	) );
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
			// A grouped select passes optgroup children instead of `options`;
			// see hasGroups() for why both shapes have to be handled.
			return hasGroups( field ) ? (
				<SelectControl
					label={ label }
					help={ description }
					value={ value ?? '' }
					onChange={ set }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				>
					{ groupChildren( field.groups ) }
				</SelectControl>
			) : (
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
		case 'url':
			return (
				<TextControl
					label={ label }
					help={ description }
					type="url"
					value={ value ?? '' }
					onChange={ set }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			);
		case 'media': {
			const media = value && 'object' === typeof value ? value : { url: '', id: 0 };
			return (
				<BaseControl label={ label } help={ description } __nextHasNoMarginBottom>
					<div className="agend-schema-media">
						{ media.url ? <img src={ media.url } alt="" className="agend-schema-media__preview" /> : null }
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ ( selected ) => set( { url: selected?.url ?? '', id: selected?.id ?? 0 } ) }
								allowedTypes={ [ 'image' ] }
								value={ media.id }
								render={ ( { open } ) => (
									<Button variant="secondary" onClick={ open }>
										{ media.url ? __( 'Replace image', 'agend-apps-core' ) : __( 'Select image', 'agend-apps-core' ) }
									</Button>
								) }
							/>
						</MediaUploadCheck>
						{ media.url ? (
							<Button variant="link" isDestructive onClick={ () => set( { url: '', id: 0 } ) }>
								{ __( 'Remove', 'agend-apps-core' ) }
							</Button>
						) : null }
					</div>
				</BaseControl>
			);
		}
		case 'repeater': {
			const rows = Array.isArray( value ) ? value : [];
			const nestedFields = field.fields || [];
			const rowLabelKey = field.row_label;

			const updateRow = ( index, nextRow ) => set( rows.map( ( row, i ) => ( i === index ? nextRow : row ) ) );
			const addRow = () => set( [ ...rows, {} ] );
			const removeRow = ( index ) => set( rows.filter( ( _, i ) => i !== index ) );
			const moveRow = ( index, delta ) => {
				const target = index + delta;
				if ( target < 0 || target >= rows.length ) {
					return;
				}
				const next = rows.slice();
				const [ moved ] = next.splice( index, 1 );
				next.splice( target, 0, moved );
				set( next );
			};

			return (
				<BaseControl label={ label } help={ description } __nextHasNoMarginBottom>
					{ rows.map( ( row, index ) => (
						// eslint-disable-next-line react/no-array-index-key -- rows carry no stable id of their own.
						<Card key={ index } className="agend-schema-repeater__row" size="small">
							<CardHeader>
								<span>
									{ rowLabelKey && row[ rowLabelKey ]
										? row[ rowLabelKey ]
										: sprintf( __( 'Row %d', 'agend-apps-core' ), index + 1 ) }
								</span>
								<div className="agend-schema-repeater__row-actions">
									<Button
										icon="arrow-up-alt2"
										label={ __( 'Move up', 'agend-apps-core' ) }
										onClick={ () => moveRow( index, -1 ) }
										disabled={ 0 === index }
									/>
									<Button
										icon="arrow-down-alt2"
										label={ __( 'Move down', 'agend-apps-core' ) }
										onClick={ () => moveRow( index, 1 ) }
										disabled={ index === rows.length - 1 }
									/>
									<Button
										icon="trash"
										label={ __( 'Remove', 'agend-apps-core' ) }
										onClick={ () => removeRow( index ) }
										isDestructive
									/>
								</div>
							</CardHeader>
							<CardBody>
								{ nestedFields.map( ( nestedField ) =>
									// A repeater row is its own little settings object,
									// so a nested field's condition is evaluated against
									// THIS row's values, not the block's top-level
									// attributes: that is why `attributes={ row }` is
									// passed here instead of the outer `attributes`.
									conditionMet( nestedField.condition, row ) ? (
										<Field
											key={ nestedField.name }
											field={ nestedField }
											attributes={ row }
											setAttributes={ ( changed ) => updateRow( index, { ...row, ...changed } ) }
										/>
									) : null
								) }
							</CardBody>
						</Card>
					) ) }
					<Button variant="secondary" onClick={ addRow }>
						{ __( 'Add', 'agend-apps-core' ) }
					</Button>
				</BaseControl>
			);
		}
		case 'adapter':
			// Most adapter fields describe no control at all: they are
			// builder-specific, and the block supplies its own elsewhere if
			// it needs one. One carrying a `block` declaration (see the
			// `adapter` type note in schema.php) opts in, and renders that
			// declaration as an ordinary Field, recursing into this same
			// component rather than a second copy of the switch above.
			return field.block ? (
				<Field field={ { ...field.block, name } } attributes={ attributes } setAttributes={ setAttributes } />
			) : null;
		default:
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
