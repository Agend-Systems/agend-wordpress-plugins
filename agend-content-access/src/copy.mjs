/**
 * The English copy the panel's logic composes, with no WordPress imports.
 *
 * The modules that build a label or a summary take their copy as a parameter
 * and fall back to this, which is what the tests read. The panel passes
 * translated strings of the same shape (src/strings.js). A test compares the
 * two files, so a string added here cannot be left untranslated there.
 *
 * Placeholders are `%s`, `%d` and the numbered `%1$s` form, as `sprintf()` in
 * @wordpress/i18n reads them.
 *
 * Kept free of apostrophes and double quotes so a test can find each string
 * in the panel's source as a literal.
 */

export const DEFAULT_STRINGS = Object.freeze( {
	// One line of plain English about who sees the block.
	summaryEveryone: 'Everyone who can see the page',
	summaryOnlyOne: 'Only people who match this rule',
	summaryOnlyAny: 'Only people who match any of these %d rules',
	summaryOnlyAll: 'Only people who match all of these %d rules',
	summaryExceptOne: 'Everyone except people who match this rule',
	summaryExceptAny: 'Everyone except people who match any of these %d rules',
	summaryExceptAll: 'Everyone except people who match all of these %d rules',
	summaryUnreadable: 'Hidden from everyone, because the setting cannot be read',

	// A rule as a chip, and as a search result.
	signedIn: 'Signed in',
	signedOut: 'Signed out',
	anyMember: 'Any current member',
	plan: 'Plan: %s',
	segment: 'Segment: %s',
	entitlement: 'Entitlement: %s',
	platform: 'Platform: %s',
	role: 'Role: %s',
	other: '%1$s: %2$s',
	inactive: '%s (no longer active)',
	unavailable: 'Unavailable: %s',
	unreadable: 'Unreadable setting (hidden from everyone)',

	// The search results.
	addRule: 'Add %s',
	removeRule: 'Remove %s',
	resultsCount: '%d results',
	resultsNone: 'No rules match that search.',
	resultsAdded: 'Already added.',
	resultsCapped: 'Showing %1$d of %2$d. Keep typing to narrow it down.',
	resultsMore: '%d more. Type to search them.',

	// Show instead.
	nothing: 'Nothing',
	fallbackMissing: 'Content %s (not in the list)',

	// The page panel. A page says Everyone where a block says everyone who can
	// see the page.
	summaryEveryonePage: 'Everyone',
	builtInPlaceholder: 'Built-in placeholder',
	choosePage: 'Choose a page',
	noFallbackPage: 'No page (show the placeholder instead)',
	redirectNeedsPage: 'Choose the page to send people to. Until you do, this change is not saved.',
} );

/**
 * Fills `%s`, `%d` and `%1$s` placeholders, in order.
 *
 * A replacer function, so a `$&` inside a plan name is not read as a
 * replacement pattern.
 *
 * @param {string} template Copy with placeholders.
 * @param {...*}   values   Values, in order.
 * @return {string} The filled text.
 */
export function format( template, ...values ) {
	let next = 0;

	return String( template ).replace( /%(?:(\d+)\$)?[sd]/g, ( match, position ) => {
		const index = position ? parseInt( position, 10 ) - 1 : next++;

		return index < values.length ? String( values[ index ] ) : match;
	} );
}
