/**
 * A labelled group of checkboxes, with a filter once the list is long.
 *
 * Used for the audience lists and, one per provider, for display conditions.
 * Rows come from access-model.mjs already ordered, with any stored choice that
 * is no longer on offer first.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { CheckboxControl, TextControl } from '@wordpress/components';

/** Past this many rows a filter box is offered. */
const FILTER_FROM = 10;

/**
 * @param {Object}   props
 * @param {string}   props.label    Group heading.
 * @param {string}   [props.help]   Help text under the group.
 * @param {Array}    props.rows     Rows `{value, label, help, checked, status}`.
 * @param {Function} props.onToggle `( value, checked ) => void`.
 * @param {string}   [props.empty]  Shown when there are no rows.
 */
export default function Checklist( { label, help, rows, onToggle, empty } ) {
	const [ filter, setFilter ] = useState( '' );
	const needle = filter.trim().toLowerCase();

	// A ticked row is always shown, whatever the filter says: hiding a choice
	// the block holds would hide part of who can see it.
	const visible = rows.filter( ( row ) => row.checked || needle === '' || row.label.toLowerCase().indexOf( needle ) !== -1 );

	return (
		<fieldset className="agend-access__fieldset">
			<legend className="agend-access__legend">{ label }</legend>
			{ rows.length > FILTER_FROM && (
				<TextControl
					label={ __( 'Filter', 'agend-content-access' ) }
					hideLabelFromVision
					placeholder={ __( 'Filter', 'agend-content-access' ) }
					value={ filter }
					onChange={ setFilter }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }
			{ rows.length === 0 && empty && <p className="agend-access__empty">{ empty }</p> }
			<div className="agend-access__list">
				{ visible.map( ( row ) => (
					<div key={ row.value } className={ 'agend-access__row agend-access__row--' + row.status }>
						<CheckboxControl
							label={ row.label }
							help={ row.help || undefined }
							checked={ row.checked }
							onChange={ ( on ) => onToggle( row.value, on ) }
							__nextHasNoMarginBottom
						/>
					</div>
				) ) }
			</div>
			{ help && <p className="agend-access__help">{ help }</p> }
		</fieldset>
	);
}
