/**
 * A searchable select of public content, for the places a panel asks for one page,
 * pattern or template: a block's "Show instead", and a page's placeholder, redirect
 * and fallback.
 *
 * One control everywhere, whatever the length of the list: a combobox, so an author
 * who knows the name types it, and a site with hundreds of pages can still find one.
 * (It used to be a plain dropdown for a short list and a combobox for a long one,
 * which made the same question look like two different controls.) The choices are
 * built by `fallbackChoices()` in rule-vocabulary.mjs, which keeps a stored choice
 * that is no longer offered in the list, selected, so the control says what is stored.
 */
import { useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import { filterChoices } from './rule-vocabulary.mjs';

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
