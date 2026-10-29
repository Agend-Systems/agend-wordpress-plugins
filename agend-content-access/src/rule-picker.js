/**
 * The searchable rule picker: a search box and, under it, the rules that match,
 * grouped, as buttons.
 *
 * What to show for what was typed is decided by `searchRules()` in
 * rule-vocabulary.mjs, over an index built once, so typing filters in memory
 * and never asks the server. This file renders the answer and handles the
 * keyboard:
 *
 *   in the search box    Down moves to the first result. Enter adds the first
 *                        result, when something has been typed.
 *   on a result          Enter or Space adds it (it is a button). Down and Up
 *                        move between results; Up from the first returns to
 *                        the search box.
 *
 * After a rule is added the search box is emptied, because what was typed has
 * found what it was looking for, and keeps focus (the button that had it has gone
 * from the list), so the next rule can be searched for at once. A search that finds
 * only rules that are already added says so, rather than that nothing matches.
 */
import { __ } from '@wordpress/i18n';
import { useMemo, useRef, useState } from '@wordpress/element';
import { SearchControl, VisuallyHidden } from '@wordpress/components';
import { format } from './copy.mjs';
import { searchRules } from './rule-vocabulary.mjs';

/**
 * @param {Object}   props
 * @param {Object}   props.index    From `buildIndex()`.
 * @param {string[]} props.rules    The block's rules.
 * @param {Object}   props.strings  Translated copy.
 * @param {Function} props.onAdd    `( rule ) => void`.
 * @param {*}        [props.notices] Rendered between the search box and the
 *                                  results, for what the vocabulary says.
 * @param {Object}   [props.searchRef] Receives the picker's root element, so a
 *                                  caller can put focus back in the search box.
 */
export default function RulePicker( { index, rules, strings, onAdd, notices, searchRef } ) {
	const [ query, setQuery ] = useState( '' );
	const rootRef = useRef( null );
	const signature = rules.join( '\n' );
	const result = useMemo( () => searchRules( index, query, rules ), [ index, query, signature ] );
	const searching = result.mode === 'search';
	const first = result.groups.length > 0 ? result.groups[ 0 ].items[ 0 ] : null;

	const setRoot = ( element ) => {
		rootRef.current = element;

		if ( searchRef ) {
			searchRef.current = element;
		}
	};

	const searchBox = () => ( rootRef.current ? rootRef.current.querySelector( 'input' ) : null );

	const add = ( rule ) => {
		onAdd( rule );
		setQuery( '' );

		const box = searchBox();

		if ( box ) {
			box.focus();
		}
	};

	const onKeyDown = ( event ) => {
		const root = rootRef.current;

		if ( ! root ) {
			return;
		}

		const buttons = Array.prototype.slice.call( root.querySelectorAll( '.agend-access__result' ) );
		const box = searchBox();

		if ( event.target === box ) {
			if ( event.key === 'ArrowDown' && buttons.length > 0 ) {
				event.preventDefault();
				buttons[ 0 ].focus();
			} else if ( event.key === 'Enter' && searching && first ) {
				event.preventDefault();
				add( first.value );
			}

			return;
		}

		const at = buttons.indexOf( event.target );

		if ( at === -1 ) {
			return;
		}

		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			buttons[ Math.min( at + 1, buttons.length - 1 ) ].focus();
		} else if ( event.key === 'ArrowUp' ) {
			event.preventDefault();

			if ( at === 0 && box ) {
				box.focus();
			} else if ( at > 0 ) {
				buttons[ at - 1 ].focus();
			}
		}
	};

	// Nothing found, and why: everything that matched is already a rule, or nothing does.
	const none = result.alreadyAdded > 0 ? strings.resultsAdded : strings.resultsNone;
	let status = '';

	if ( searching ) {
		status = result.total === 0 ? none : format( strings.resultsCount, result.total );
	}

	return (
		// The handler is on the wrapper because the search control does not
		// forward key events; it reads them as they bubble up from its input.
		<div className="agend-access__picker" ref={ setRoot } onKeyDown={ onKeyDown }>
			<SearchControl
				label={ __( 'Search plans, segments, entitlements and roles', 'agend-content-access' ) }
				placeholder={ __( 'Search plans, segments, entitlements and roles', 'agend-content-access' ) }
				value={ query }
				onChange={ setQuery }
				__nextHasNoMarginBottom
			/>
			{ notices }
			<VisuallyHidden>
				<span role="status" aria-live="polite">
					{ status }
				</span>
			</VisuallyHidden>
			{ searching && result.groups.length === 0 && <p className="agend-access__empty">{ none }</p> }
			{ result.groups.length > 0 && (
				<div className="agend-access__results">
					{ result.groups.map( ( group ) => (
						<div key={ group.key } className="agend-access__group">
							<h4 className="agend-access__group-heading">{ group.label }</h4>
							<ul className="agend-access__group-list" aria-label={ group.label }>
								{ group.items.map( ( item ) => (
									<li key={ item.value }>
										<button
											type="button"
											className="agend-access__result"
											aria-label={ format( strings.addRule, item.chip ) }
											onClick={ () => add( item.value ) }
										>
											<span className="agend-access__result-label">{ item.chip }</span>
											{ item.description && <span className="agend-access__result-note">{ item.description }</span> }
										</button>
									</li>
								) ) }
							</ul>
							{ ! searching && group.more > 0 && <p className="agend-access__more">{ format( strings.resultsMore, group.more ) }</p> }
						</div>
					) ) }
				</div>
			) }
			{ result.capped && <p className="agend-access__help">{ format( strings.resultsCapped, result.rendered, result.total ) }</p> }
		</div>
	);
}
