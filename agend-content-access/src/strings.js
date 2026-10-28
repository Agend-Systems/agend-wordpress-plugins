/**
 * The copy composed by the panel's logic, translated.
 *
 * The logic modules have no WordPress imports and cannot call `__()`, so they
 * take their copy as a parameter. This is that copy, and it lives in a file of
 * its own so each string is a literal the translation tools can extract. It
 * must match DEFAULT_STRINGS in copy.mjs, key for key and word for word, and
 * a test checks that it does.
 */
import { __ } from '@wordpress/i18n';

let translated = null;

/**
 * Built once: the same object every time, so what is derived from it (the
 * picker's index) can be kept across block selections.
 *
 * @return {Object} Strings in the shape DEFAULT_STRINGS has.
 */
export function copy() {
	if ( translated ) {
		return translated;
	}

	translated = {
		summaryEveryone: __( 'Everyone who can see the page', 'agend-content-access' ),
		summaryOnlyOne: __( 'Only people who match this rule', 'agend-content-access' ),
		/* translators: %d: number of rules, two or more. */
		summaryOnlyAny: __( 'Only people who match any of these %d rules', 'agend-content-access' ),
		/* translators: %d: number of rules, two or more. */
		summaryOnlyAll: __( 'Only people who match all of these %d rules', 'agend-content-access' ),
		summaryExceptOne: __( 'Everyone except people who match this rule', 'agend-content-access' ),
		/* translators: %d: number of rules, two or more. */
		summaryExceptAny: __( 'Everyone except people who match any of these %d rules', 'agend-content-access' ),
		/* translators: %d: number of rules, two or more. */
		summaryExceptAll: __( 'Everyone except people who match all of these %d rules', 'agend-content-access' ),
		summaryUnreadable: __( 'Hidden from everyone, because the setting cannot be read', 'agend-content-access' ),

		signedIn: __( 'Signed in', 'agend-content-access' ),
		signedOut: __( 'Signed out', 'agend-content-access' ),
		anyMember: __( 'Any current member', 'agend-content-access' ),
		/* translators: %s: membership plan name. */
		plan: __( 'Plan: %s', 'agend-content-access' ),
		/* translators: %s: segment name. */
		segment: __( 'Segment: %s', 'agend-content-access' ),
		/* translators: %s: entitlement name. */
		entitlement: __( 'Entitlement: %s', 'agend-content-access' ),
		/* translators: %s: platform capability name, for example Directory access. */
		platform: __( 'Platform: %s', 'agend-content-access' ),
		/* translators: %s: WordPress role name, for example Editor. */
		role: __( 'Role: %s', 'agend-content-access' ),
		/* translators: 1: name of the group a rule belongs to, 2: the rule's name. */
		other: __( '%1$s: %2$s', 'agend-content-access' ),
		/* translators: %s: the label of a membership plan that has been switched off. */
		inactive: __( '%s (no longer active)', 'agend-content-access' ),
		/* translators: %s: the stored rule, for example agend_segments:abc, which is no longer on offer. */
		unavailable: __( 'Unavailable: %s', 'agend-content-access' ),
		unreadable: __( 'Unreadable setting (hidden from everyone)', 'agend-content-access' ),

		/* translators: %s: the label of a rule, for example Plan: Gold. */
		addRule: __( 'Add %s', 'agend-content-access' ),
		/* translators: %s: the label of a rule, for example Plan: Gold. */
		removeRule: __( 'Remove %s', 'agend-content-access' ),
		/* translators: %d: number of search results. */
		resultsCount: __( '%d results', 'agend-content-access' ),
		resultsNone: __( 'No rules match that search.', 'agend-content-access' ),
		resultsAdded: __( 'Already added.', 'agend-content-access' ),
		/* translators: 1: number of results shown, 2: number of results found. */
		resultsCapped: __( 'Showing %1$d of %2$d. Keep typing to narrow it down.', 'agend-content-access' ),
		/* translators: %d: number of rules not listed. */
		resultsMore: __( '%d more. Type to search them.', 'agend-content-access' ),

		nothing: __( 'Nothing', 'agend-content-access' ),
		/* translators: %s: post id of replacement content that is not in the list, for example #12. */
		fallbackMissing: __( 'Content %s (not in the list)', 'agend-content-access' ),

		summaryEveryonePage: __( 'Everyone', 'agend-content-access' ),
		builtInPlaceholder: __( 'Built-in placeholder', 'agend-content-access' ),
		choosePage: __( 'Choose a page', 'agend-content-access' ),
		noFallbackPage: __( 'No page (show the placeholder instead)', 'agend-content-access' ),
		redirectNeedsPage: __( 'Choose the page to send people to. Until you do, this change is not saved.', 'agend-content-access' ),
	};

	return translated;
}
