/**
 * The rules a block holds, as removable chips.
 *
 * Every rule the block holds is a chip, including ones the vocabulary no
 * longer offers: those say so and stay until an author removes them, because
 * dropping one would quietly change who can see the block. The labels come
 * from rule-vocabulary.mjs already composed.
 */
import { useEffect, useRef } from '@wordpress/element';
import { format } from './copy.mjs';

/**
 * @param {Object}   props
 * @param {Array}    props.chips      Chips `{value, label, status}`.
 * @param {Object}   props.strings    Translated copy.
 * @param {Function} props.onRemove   `( rule ) => void`.
 * @param {Function} [props.onEmptied] Called after the last chip is removed, to
 *                                    put focus somewhere sensible.
 */
export default function RuleChips( { chips, strings, onRemove, onEmptied } ) {
	const listRef = useRef( null );
	const focusAfter = useRef( null );
	const signature = chips.map( ( chip ) => chip.value ).join( '\n' );

	// A removed chip takes the focus with it. Hand it to the chip that took its
	// place, or to the caller when there is none.
	useEffect( () => {
		if ( focusAfter.current === null ) {
			return;
		}

		const buttons = listRef.current ? listRef.current.querySelectorAll( 'button' ) : [];
		const target = buttons[ Math.min( focusAfter.current, buttons.length - 1 ) ];

		focusAfter.current = null;

		if ( target ) {
			target.focus();
		} else if ( onEmptied ) {
			onEmptied();
		}
	}, [ signature ] );

	if ( chips.length === 0 ) {
		return null;
	}

	return (
		<ul className="agend-access__chips" ref={ listRef }>
			{ chips.map( ( chip, position ) => (
				<li key={ chip.value } className={ 'agend-access__chip agend-access__chip--' + chip.status }>
					<span className="agend-access__chip-label">{ chip.label }</span>
					<button
						type="button"
						className="agend-access__chip-remove"
						aria-label={ format( strings.removeRule, chip.label ) }
						onClick={ () => {
							focusAfter.current = position;
							onRemove( chip.value );
						} }
					>
						<span aria-hidden="true">&times;</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}
