/**
 * The one line at the top of the panel, in plain English, with no WordPress
 * imports.
 *
 *   Everyone who can see the page
 *   Only people who match any of these 2 rules
 *   Everyone except people who match all of these 3 rules
 *
 * It says what the panel's current state means, for a state from
 * `normalise()` in access-model.mjs, so it is also what an author reads to
 * check what they just did.
 *
 * Deliberately ES2017 syntax: this file is also bundled for the browser.
 */
import { DEFAULT_STRINGS, format } from './copy.mjs';

/**
 * @param {Object} state   A state from `normalise()`.
 * @param {Object} strings Copy.
 * @return {string} One line.
 */
export function summarise( state, strings = DEFAULT_STRINGS ) {
	if ( state.unreadable ) {
		return strings.summaryUnreadable;
	}

	const count = state.rules.length;

	if ( count === 0 ) {
		return strings.summaryEveryone;
	}

	const prefix = state.invert ? 'summaryExcept' : 'summaryOnly';

	if ( count === 1 ) {
		return strings[ prefix + 'One' ];
	}

	return format( strings[ prefix + ( state.match === 'all' ? 'All' : 'Any' ) ], count );
}
