/**
 * A select of public content, for the places a panel asks for one page, pattern or
 * template: a block's "Show instead", and a page's placeholder, redirect and
 * fallback.
 *
 * A plain select while the list is short, a searchable one when it is long, so a
 * site with hundreds of pages can still find one. The choices are built by
 * `fallbackChoices()` in rule-vocabulary.mjs, which keeps a stored choice that is
 * no longer offered in the list, selected, so the control says what is stored.
 */
import { useState } from '@wordpress/element';
import { SelectControl, ComboboxControl } from '@wordpress/components';
import { filterChoices } from './rule-vocabulary.mjs';

/** Past this many choices the select becomes a searchable one. */
const LONG_LIST = 10;

/**
 * @param {Object}   props
 * @param {string}   props.label    The control's label.
 * @param {string}   [props.help]   The line under it.
 * @param {Array}    props.choices  `{value, label}`, the empty choice first.
 * @param {number}   props.current  The stored post id, 0 for none.
 * @param {Function} props.onChange `( id: string ) => void`, an empty string for none.
 */
export default function ChoiceSelect( { label, help, choices, current, onChange } ) {
	const [ filter, setFilter ] = useState( '' );
	const value = current > 0 ? String( current ) : '';

	if ( choices.length <= LONG_LIST ) {
		return (
			<SelectControl
				label={ label }
				help={ help }
				value={ value }
				options={ choices }
				onChange={ onChange }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		);
	}

	const shown = filterChoices( choices, filter );
	const selected = choices.find( ( choice ) => choice.value === value );

	// The chosen one stays in the list whatever was typed, so the control can
	// always say what is stored.
	if ( selected && ! shown.some( ( choice ) => choice.value === value ) ) {
		shown.unshift( selected );
	}

	return (
		<ComboboxControl
			label={ label }
			help={ help }
			value={ value }
			options={ shown }
			onFilterValueChange={ setFilter }
			onChange={ ( id ) => onChange( id || '' ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}
