/**
 * The logic behind the page's Agend Access panel, with no WordPress imports.
 *
 * It lives apart from the React components so `node --test` can run it
 * (agend-content-access/tests/js/page-model.test.mjs), and so what the panel may
 * write is in one place that can be read top to bottom. The block panel's twin is
 * access-model.mjs; a page differs in two ways. Its value is the whole setting, not
 * an attribute that may be absent, and it carries what happens to somebody who is
 * not allowed.
 *
 * THE VALUE. A page's setting is one object, the same in both editors:
 *
 *   rules    string[]  "<provider>:<value>", as a block's.
 *   match    any or all. `any` below two rules.
 *   invert   boolean.
 *   denied   {action, target_id, fallback_id}
 *              action       placeholder (the default), redirect or back.
 *              target_id    placeholder: public content to show, 0 for the
 *                           built-in one. redirect: the page to send people to.
 *              fallback_id  back: the page to send people to when they did not come
 *                           from this site, 0 for the placeholder.
 *
 * The server also says two things about what it read, which the panel never writes:
 *
 *   legacy      true when the page only has a 0.1.x setting, shown here as rules.
 *   unreadable  true when what is stored cannot be shown as rules. The server hides
 *               the page from everyone, and the panel offers a reset and nothing else.
 *
 * THE RULES THE PANEL KEEPS.
 *
 *  1. Every change returns a new state, and the very same state when it changes
 *     nothing, so a caller can tell an edit from a no-op by identity.
 *  2. A key that no longer applies is dropped, not hidden. The denial setting is
 *     for a page that has rules, and its two numbers belong to one action each: the
 *     page to redirect to is not the placeholder, and neither is the way back's
 *     fallback. Switching action starts that action's number again.
 *  3. A setting that cannot be completed is never written. A redirect with no page
 *     chosen is not a setting, and sending it would only have the server refuse the
 *     whole save, so the panel keeps it as a draft, says what is missing, and leaves
 *     what is stored alone (`validatePage()`).
 *  4. An unreadable setting accepts one change, a reset. The server fails closed on
 *     it, and anything else written over it would be a guess about what it meant.
 *  5. Removing the last rule clears the lot: with nothing to match there is nothing
 *     to reverse and nobody to be denied. That is the author choosing a page open to
 *     everyone, and the server then removes the old setting as well.
 *  6. Inputs are never mutated.
 *
 * Deliberately ES2017 syntax: this file is also bundled for the browser.
 */
import { DEFAULT_STRINGS } from './copy.mjs';

/** The REST field the block editor reads and writes, and the code of a refused save. */
export const REST_FIELD = 'agend_access_rules';
export const ERROR_CODE = 'agend_content_access_invalid_rules';

/** The hidden input the classic editor's meta box reads on save. */
export const INPUT_NAME = 'agend_access_page_rules';

export const ACTIONS = Object.freeze( { PLACEHOLDER: 'placeholder', REDIRECT: 'redirect', BACK: 'back' } );

const ACTION_LIST = Object.freeze( [ ACTIONS.PLACEHOLDER, ACTIONS.REDIRECT, ACTIONS.BACK ] );

/** What a redirect, and the way back's fallback, may go to. A pattern has no address. */
export const PAGE_TYPES = Object.freeze( [ 'page' ] );

function isPlainObject( value ) {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

function isRule( value ) {
	return typeof value === 'string' && value !== '';
}

function unique( list ) {
	return list.filter( ( entry, index ) => list.indexOf( entry ) === index );
}

function positiveInteger( value ) {
	return typeof value === 'number' && Number.isInteger( value ) && value > 0 ? value : 0;
}

// ---------------------------------------------------------------------------
// The denial setting
// ---------------------------------------------------------------------------

/**
 * The denial setting with only what its action uses.
 *
 * @param {string} action      A key of ACTIONS.
 * @param {number} targetId    Placeholder content, or the page to redirect to.
 * @param {number} fallbackId  The way back's fallback.
 * @return {{action: string, target_id: number, fallback_id: number}} The setting.
 */
function denial( action, targetId, fallbackId ) {
	return {
		action,
		target_id: action === ACTIONS.BACK ? 0 : positiveInteger( targetId ),
		fallback_id: action === ACTIONS.BACK ? positiveInteger( fallbackId ) : 0,
	};
}

/** The default: the built-in placeholder. */
export function defaultDenied() {
	return denial( ACTIONS.PLACEHOLDER, 0, 0 );
}

function readDenied( stored ) {
	if ( ! isPlainObject( stored ) || ACTION_LIST.indexOf( stored.action ) === -1 ) {
		return defaultDenied();
	}

	return denial( stored.action, stored.target_id, stored.fallback_id );
}

// ---------------------------------------------------------------------------
// Reading a stored value
// ---------------------------------------------------------------------------

function emptyState() {
	return { rules: [], match: 'any', invert: false, denied: defaultDenied(), legacy: false, unreadable: false };
}

function unreadableState() {
	return Object.assign( emptyState(), { unreadable: true } );
}

/**
 * Reads what the server gave, or what the panel last wrote, as the state the panel
 * shows.
 *
 * Never throws and never loosens: anything it cannot read is unreadable, and the
 * only thing that clears that is a reset. An absent value, and an empty list that
 * is what PHP makes of an empty object, are a page with no rules.
 *
 * @param {*} stored The field's value.
 * @return {Object} The state, with every field present.
 */
export function normalisePage( stored ) {
	if ( stored === undefined || stored === null ) {
		return emptyState();
	}

	if ( Array.isArray( stored ) ) {
		return stored.length === 0 ? emptyState() : unreadableState();
	}

	if ( ! isPlainObject( stored ) || stored.unreadable === true ) {
		return unreadableState();
	}

	let rules = [];

	if ( stored.rules !== undefined && stored.rules !== null ) {
		// A list with anything in it that is not a rule is not one the panel can
		// show, and dropping the entry would shrink it, which is the one direction
		// that widens who can see the page.
		if ( ! Array.isArray( stored.rules ) || ! stored.rules.every( isRule ) ) {
			return unreadableState();
		}

		rules = unique( stored.rules );
	}

	const invert = stored.invert === true;

	// Reversing nothing is nobody, which is not something this panel writes.
	if ( rules.length === 0 && invert ) {
		return unreadableState();
	}

	return {
		rules,
		match: stored.match === 'all' ? 'all' : 'any',
		invert,
		denied: rules.length > 0 ? readDenied( stored.denied ) : defaultDenied(),
		legacy: stored.legacy === true,
		unreadable: false,
	};
}

// ---------------------------------------------------------------------------
// Changes
// ---------------------------------------------------------------------------

/**
 * Adds a rule, last. A rule the page already has is not added twice.
 *
 * @param {Object} state A state from `normalisePage()`.
 * @param {string} rule  `"<provider>:<value>"`.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function addRule( state, rule ) {
	if ( state.unreadable || ! isRule( rule ) || state.rules.indexOf( rule ) !== -1 ) {
		return state;
	}

	return Object.assign( {}, state, { rules: state.rules.concat( [ rule ] ) } );
}

/**
 * Removes a rule. Removing the last one clears everything that depended on it.
 *
 * @param {Object} state A state from `normalisePage()`.
 * @param {string} rule  The rule as it is stored.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function removeRule( state, rule ) {
	if ( state.unreadable || state.rules.indexOf( rule ) === -1 ) {
		return state;
	}

	const rules = state.rules.filter( ( entry ) => entry !== rule );

	if ( rules.length === 0 ) {
		return emptyState();
	}

	return Object.assign( {}, state, { rules } );
}

/**
 * Sets whether any or all rules must match. Ignored below two rules.
 *
 * @param {Object} state A state from `normalisePage()`.
 * @param {string} match `any` or `all`.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function setMatch( state, match ) {
	const next = match === 'all' ? 'all' : 'any';

	if ( state.unreadable || state.rules.length < 2 || state.match === next ) {
		return state;
	}

	return Object.assign( {}, state, { match: next } );
}

/**
 * Sets whether the result is reversed. Ignored with no rules.
 *
 * @param {Object}  state A state from `normalisePage()`.
 * @param {boolean} on    Whether to reverse.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function setInvert( state, on ) {
	if ( state.unreadable || state.rules.length === 0 || state.invert === ( on === true ) ) {
		return state;
	}

	return Object.assign( {}, state, { invert: on === true } );
}

/**
 * Chooses what happens to somebody who is not allowed. The new action starts with
 * no page chosen: the number the old one held was for something else.
 *
 * @param {Object} state  A state from `normalisePage()`.
 * @param {string} action A key of ACTIONS.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function setAction( state, action ) {
	if ( state.unreadable || state.rules.length === 0 || ACTION_LIST.indexOf( action ) === -1 || state.denied.action === action ) {
		return state;
	}

	return Object.assign( {}, state, { denied: denial( action, 0, 0 ) } );
}

/**
 * Chooses the page to redirect to, or the public content a placeholder shows.
 * Ignored for the way back, which has a fallback and not a target.
 *
 * @param {Object}        state A state from `normalisePage()`.
 * @param {number|string} id    A post id. Zero, empty, or anything that is not a
 *                              positive whole number means none.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function setTarget( state, id ) {
	if ( state.unreadable || state.rules.length === 0 || state.denied.action === ACTIONS.BACK ) {
		return state;
	}

	const next = positiveInteger( toNumber( id ) );

	return next === state.denied.target_id ? state : Object.assign( {}, state, { denied: denial( state.denied.action, next, 0 ) } );
}

/**
 * Chooses where the way back goes when the visitor did not come from this site.
 * Ignored for any other action.
 *
 * @param {Object}        state A state from `normalisePage()`.
 * @param {number|string} id    A post id, or none.
 * @return {Object} The next state, or this one when nothing changes.
 */
export function setFallback( state, id ) {
	if ( state.unreadable || state.rules.length === 0 || state.denied.action !== ACTIONS.BACK ) {
		return state;
	}

	const next = positiveInteger( toNumber( id ) );

	return next === state.denied.fallback_id ? state : Object.assign( {}, state, { denied: denial( ACTIONS.BACK, 0, next ) } );
}

function toNumber( id ) {
	return typeof id === 'string' && /^[0-9]+$/.test( id ) ? parseInt( id, 10 ) : id;
}

/**
 * The state of a page with nothing set: the one write allowed on a setting the
 * panel cannot read.
 *
 * @return {Object} The empty state.
 */
export function resetPage() {
	return emptyState();
}

// ---------------------------------------------------------------------------
// Saving
// ---------------------------------------------------------------------------

/**
 * Whether the state can be saved as it is, and if not, what is missing.
 *
 * Only a redirect can be incomplete: it needs somewhere to go. The placeholder
 * has the built-in one, and the way back has the placeholder for a visitor who has
 * nowhere to come back from. The server checks the same and more (the page is
 * published, is a page, is not this one), and says so if it must.
 *
 * @param {Object} state   A state from `normalisePage()`.
 * @param {Object} strings Copy.
 * @return {{ok: boolean, errors: {target?: string}}} The answer.
 */
export function validatePage( state, strings = DEFAULT_STRINGS ) {
	const errors = {};

	if ( ! state.unreadable && state.rules.length > 0 && state.denied.action === ACTIONS.REDIRECT && state.denied.target_id === 0 ) {
		errors.target = strings.redirectNeedsPage;
	}

	return { ok: Object.keys( errors ).length === 0, errors };
}

/**
 * The value to save: the four keys the server reads, and nothing it only says.
 *
 * With no rules it is the cleared setting, which the server reads as "remove the
 * rules, and the old setting with them".
 *
 * @param {Object} state A state from `normalisePage()`.
 * @return {{rules: string[], match: string, invert: boolean, denied: Object}} The payload.
 */
export function buildPayload( state ) {
	if ( state.unreadable || state.rules.length === 0 ) {
		return { rules: [], match: 'any', invert: false, denied: defaultDenied() };
	}

	return {
		rules: state.rules.slice(),
		match: state.rules.length > 1 && state.match === 'all' ? 'all' : 'any',
		invert: state.invert === true,
		denied: denial( state.denied.action, state.denied.target_id, state.denied.fallback_id ),
	};
}

/**
 * The server's reason for refusing a save, when it was this panel's value it
 * refused, so the panel can show it where the author is looking.
 *
 * @param {*} error What the editor holds as the last save error.
 * @return {string} The message, or an empty string for anything else.
 */
export function savedRefusal( error ) {
	if ( isPlainObject( error ) && error.code === ERROR_CODE && typeof error.message === 'string' ) {
		return error.message;
	}

	return '';
}
