/**
 * The logic behind the Agend Access block panel, with no WordPress imports.
 *
 * It lives apart from the React components so `node --test` can run it
 * (agend-content-access/tests/js/access-model.test.mjs) and so the rules about
 * what the panel may write are in one place that can be read top to bottom.
 *
 * THE CONTRACT. Every block may carry one object attribute, `agendAccess`:
 *
 *   rules       string[]  "<provider>:<value>". Providers are agend_wordpress
 *                         (logged_in, logged_out, role_<role id>),
 *                         agend_membership (is_member, or a plan's id),
 *                         agend_segments (a segment's id) and
 *                         agend_entitlements (an entitlement's gate key).
 *   match       any (default) or all. Written only when it is all.
 *   invert      boolean. Written only when it is true.
 *   fallbackId  positive integer, a post id. Written only when it is not zero.
 *   meta        anything. RESERVED for data that has no effect on who can see the
 *               block: it is ignored, and kept as found.
 *
 * Absent means "everyone who can see the page". The PHP side reads the same
 * object. Blocks saved by the first panel used another shape (legacy-access.mjs
 * says what it meant); they are read, never written.
 *
 * NO OTHER TOP-LEVEL KEY IS READABLE. A value with a key outside the contract, the
 * first shape's keys and `meta` is unreadable, here and on the server
 * (`Agend_Content_Access_Rules::BLOCK_KEYS`), and the block is hidden from
 * everyone. A later release that adds a key which narrows access must add it at the
 * top level, so that a version that does not know it fails closed instead of
 * reading the keys it knows and dropping the narrowing.
 *
 * THE RULES THE PANEL KEEPS.
 *
 *  1. It writes those keys and nothing else, and writes the smallest object
 *     that says what the author chose. A default is never written out. Nothing
 *     chosen at all is `undefined`, which removes the attribute, so a block an
 *     author only looked at, or set and then set back, saves with no
 *     `agendAccess` in its comment. (Not `{}`: the server registers an empty
 *     array as the default, `{}` does not equal it, and WordPress would
 *     serialise `{"agendAccess":{}}`.)
 *  2. A key that no longer applies is dropped, not hidden. Match needs two
 *     rules, and Reverse and the replacement content need one. The stored
 *     object always matches what the panel shows, so a stale value cannot sit
 *     in the post and come back to life after a later edit.
 *  3. `meta` is kept exactly as found: it is the one key that is not ours and is
 *     still readable, because it has no effect on access. (If nothing but `meta`
 *     remains the object is still written, with only that key: it is not empty.)
 *     Any other key makes the value unreadable (rule 4), so it is never kept
 *     through an edit: the only write is the reset, which clears it.
 *  4. A stored value it cannot read never crashes the panel and never loosens
 *     anything by accident. The server fails closed on such a value, so the
 *     panel shows that state, offers only a reset, and refuses every other
 *     write until the author resets it.
 *  5. Opening a block never rewrites it. A block saved by the first panel is
 *     shown as rules and is only saved as rules when the author changes
 *     something; a change that changes nothing is not a write.
 *  6. Inputs are never mutated. A block's default attribute value is one shared
 *     object, so mutating it would change every block at once.
 *
 * Deliberately ES2017 syntax: this file is also bundled for the browser.
 */
import { LEGACY_KEYS, hasLegacyKeys, phpTrim, readLegacy } from './legacy-access.mjs';

export const ATTRIBUTE = 'agendAccess';

/**
 * Block types that cannot carry the attribute, and why.
 *
 * Both are serialised WITHOUT a comment delimiter, so an attribute has nowhere
 * to be stored and an author would set a rule the post then forgets:
 *
 *   core/missing   The "unsupported block" placeholder. It writes back the
 *                  original markup it could not parse.
 *   core/freeform  The Classic block, which is raw HTML between blocks. The
 *                  server reads it as a block with no name and no attributes.
 *
 * Kept equal to `Agend_Content_Access_Block_Editor::EXCLUDED_BLOCKS` in PHP;
 * a test reads that file and compares.
 */
export const EXCLUDED_BLOCKS = Object.freeze( [ 'core/missing', 'core/freeform' ] );

export const MATCH = Object.freeze( { ANY: 'any', ALL: 'all' } );

/** The keys of the current shape, in the order they are written. */
export const RULE_KEYS = Object.freeze( [ 'rules', 'match', 'invert', 'fallbackId' ] );

/**
 * Every key the panel owns: the current shape and the first one. Anything else
 * on a stored value is somebody else's and is kept.
 */
export const CONTRACT_KEYS = Object.freeze( RULE_KEYS.concat( LEGACY_KEYS ) );

/** The one key that is not ours and is still readable: data with no access effect. */
export const META_KEY = 'meta';

/** Every top-level key a stored value may carry and still be read. Equal to the server's `BLOCK_KEYS`. */
export const READABLE_KEYS = Object.freeze( CONTRACT_KEYS.concat( [ META_KEY ] ) );

export function isPlainObject( value ) {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

/**
 * A rule is a string with something in it besides whitespace, as the server counts
 * whitespace (PHP's `trim()`). Whether it is on offer is the vocabulary's business.
 */
export function isRule( value ) {
	return typeof value === 'string' && phpTrim( value ) !== '';
}

function unique( list ) {
	return list.filter( ( entry, index ) => list.indexOf( entry ) === index );
}

// ---------------------------------------------------------------------------
// Which blocks carry the attribute
// ---------------------------------------------------------------------------

/**
 * Whether a block type is one that cannot carry the attribute.
 *
 * @param {*} name Block name.
 * @return {boolean} True for an excluded name, and for anything that is not a name.
 */
export function isExcluded( name ) {
	return typeof name !== 'string' || EXCLUDED_BLOCKS.indexOf( name ) !== -1;
}

/**
 * The `blocks.registerBlockType` filter, as a pure function.
 *
 * Adds the attribute unless the block cannot carry it or already declares one
 * of its own (which is then left alone: it is somebody else's contract).
 * Never touches `save`.
 *
 * @param {Object} settings Block settings.
 * @param {string} name     Block name.
 * @return {Object} The settings, a new object when the attribute was added.
 */
export function withAccessAttribute( settings, name ) {
	if ( ! isPlainObject( settings ) || isExcluded( name ) ) {
		return settings;
	}

	const attributes = isPlainObject( settings.attributes ) ? settings.attributes : {};

	if ( Object.prototype.hasOwnProperty.call( attributes, ATTRIBUTE ) ) {
		return settings;
	}

	const added = {};
	added[ ATTRIBUTE ] = { type: 'object', default: {} };

	return Object.assign( {}, settings, { attributes: Object.assign( {}, attributes, added ) } );
}

/**
 * Whether a registered block type actually has the attribute.
 *
 * A block registered before this script ran never went through the filter. An
 * attribute set on such a block is accepted by the editor and then dropped when
 * the post is saved, so the panel is only offered where the attribute will stick.
 *
 * @param {Object|undefined} blockType A block type as `getBlockType()` returns it.
 * @return {boolean} Whether the panel can be offered.
 */
export function carriesAttribute( blockType ) {
	return !! blockType
		&& ! isExcluded( blockType.name )
		&& isPlainObject( blockType.attributes )
		&& Object.prototype.hasOwnProperty.call( blockType.attributes, ATTRIBUTE );
}

// ---------------------------------------------------------------------------
// Reading a stored value
// ---------------------------------------------------------------------------

function emptyState() {
	return {
		rules: [],
		match: MATCH.ANY,
		invert: false,
		fallbackId: 0,
		legacy: false,
		twoPart: false,
		unreadable: false,
	};
}

function unreadableState() {
	return Object.assign( emptyState(), { unreadable: true } );
}

function has( stored, key ) {
	return stored[ key ] !== undefined && stored[ key ] !== null;
}

function positiveInteger( value ) {
	return typeof value === 'number' && Number.isInteger( value ) && value > 0 ? value : 0;
}

/**
 * Reads the current shape. A value with none of its keys is "no rules".
 *
 * @param {Object} stored A plain object.
 * @return {Object} The state.
 */
function readRules( stored ) {
	const state = emptyState();

	if ( has( stored, 'rules' ) ) {
		// A list with anything in it that is not a rule is malformed, and the
		// server denies a malformed rule list. Dropping the entry would shrink
		// the list, which is the one direction that widens who can see the block.
		if ( ! Array.isArray( stored.rules ) || ! stored.rules.every( isRule ) ) {
			return unreadableState();
		}

		state.rules = unique( stored.rules );
	}

	// Match is `any` or `all` and Reverse is a boolean, and nothing else. The server
	// reads anything else as unreadable and denies everyone, so a panel that read
	// `"ALL"` as `any` or `"yes"` as off would show a block as open, and the first
	// edit would write that, opening a block the server had hidden from everyone.
	if ( has( stored, 'match' ) && ( typeof stored.match !== 'string' || ( stored.match !== MATCH.ANY && stored.match !== MATCH.ALL ) ) ) {
		return unreadableState();
	}

	if ( has( stored, 'invert' ) && typeof stored.invert !== 'boolean' ) {
		return unreadableState();
	}

	state.match = stored.match === MATCH.ALL ? MATCH.ALL : MATCH.ANY;
	state.invert = stored.invert === true;
	state.fallbackId = positiveInteger( stored.fallbackId );

	// Reversing nothing: a list with no rule in it never excludes anybody, and
	// reversing that excludes everybody. Not something this panel writes.
	if ( state.rules.length === 0 && state.invert ) {
		return unreadableState();
	}

	return state;
}

/**
 * Reads a stored attribute value the way the server reads it.
 *
 * `unreadable` is true where what the panel shows would differ from what the
 * server acts on, or where the server fails closed: a value that is not an
 * object at all, a rule list with something other than a rule in it (a rule that
 * is only whitespace is not one), a Match that is not `any` or `all`, a Reverse
 * that is not a boolean, a mix of the two shapes, a key outside the contract (only
 * `meta` is ignorable), and whatever `readLegacy()` cannot express as rules. The enums are the
 * server's, and reading a bad one as its default would show a block the server
 * hides from everyone as open to whoever the rules name.
 *
 * `legacy` is true for a value written by the first panel, and `twoPart` for
 * one of those that holds an audience AND conditions, which the rules shown
 * only approximate (legacy-access.mjs says how).
 *
 * @param {*} stored The stored attribute value.
 * @return {Object} The state, with every field present.
 */
export function normalise( stored ) {
	// Absent. The server reads only a missing or null attribute as "none".
	if ( stored === undefined || stored === null ) {
		return emptyState();
	}

	// An empty list is `{}` as far as PHP is concerned (both decode to an empty
	// array), so it is "none". A non-empty list, a string, a number or a
	// boolean is present and unreadable, which the server fails closed on.
	if ( Array.isArray( stored ) ) {
		return stored.length === 0 ? emptyState() : unreadableState();
	}

	if ( ! isPlainObject( stored ) ) {
		return unreadableState();
	}

	// A key the server does not know makes the whole value unreadable there, so the
	// panel must not read the keys it does know and show the block as less limited.
	if ( Object.keys( stored ).some( ( key ) => READABLE_KEYS.indexOf( key ) === -1 ) ) {
		return unreadableState();
	}

	const legacy = hasLegacyKeys( stored );
	const current = [ 'rules', 'match', 'invert' ].some( ( key ) => has( stored, key ) );

	// Which of the two the server would obey is not something to guess at.
	if ( legacy && current ) {
		return unreadableState();
	}

	if ( legacy ) {
		const read = readLegacy( stored );

		return read.unreadable
			? unreadableState()
			: Object.assign( emptyState(), {
				rules: read.rules,
				match: read.match,
				invert: read.invert,
				fallbackId: read.fallbackId,
				legacy: true,
				twoPart: read.twoPart,
			} );
	}

	return readRules( stored );
}

// ---------------------------------------------------------------------------
// Writing a change
// ---------------------------------------------------------------------------

/**
 * The smallest object that says what a state says, plus the keys the panel
 * does not own. When that is nothing at all, `undefined`, so Gutenberg removes
 * the attribute rather than saving an empty one.
 *
 * @param {*}      stored The stored value, for the keys that are not ours.
 * @param {Object} state  A state from `normalise()`, changed.
 * @return {Object|undefined} The next attribute value.
 */
function build( stored, state ) {
	const next = {};

	// With no rule there is nothing to match, reverse or fall back from, and
	// those controls are not shown, so none of them is kept.
	if ( state.rules.length > 0 ) {
		next.rules = state.rules.slice();

		// Needs two rules to mean anything.
		if ( state.rules.length > 1 && state.match === MATCH.ALL ) {
			next.match = MATCH.ALL;
		}

		if ( state.invert ) {
			next.invert = true;
		}

		if ( state.fallbackId > 0 ) {
			next.fallbackId = state.fallbackId;
		}
	}

	// `meta` is kept as found. No other key is: a value with one is unreadable, so
	// nothing but a reset reaches here with it, and a reset clears it.
	if ( isPlainObject( stored ) && Object.prototype.hasOwnProperty.call( stored, META_KEY ) ) {
		next[ META_KEY ] = stored[ META_KEY ];
	}

	// Nothing chosen and nothing of somebody else's: no attribute at all. An
	// empty object would not equal the server's default (an empty array), so it
	// would be saved as `{"agendAccess":{}}` in the block's comment.
	return Object.keys( next ).length === 0 ? undefined : next;
}

function fingerprint( state ) {
	return JSON.stringify( [ state.rules, state.match, state.invert, state.fallbackId ] );
}

/**
 * Applies a change to the stored value.
 *
 * @param {*}        stored Stored attribute value.
 * @param {Function} change Mutates the (private) state.
 * @return {*} The next attribute value, `undefined` when nothing is left to
 *             store. The stored value itself when the panel refuses the change
 *             or the change changes nothing, so a block is only ever rewritten
 *             by an edit that edits something.
 */
function apply( stored, change ) {
	const state = normalise( stored );

	if ( state.unreadable ) {
		return stored;
	}

	const before = fingerprint( state );

	change( state );

	return fingerprint( state ) === before ? stored : build( stored, state );
}

/**
 * Adds a rule, last. A rule the block already has is not added twice.
 *
 * @param {*}      stored Stored attribute value.
 * @param {string} rule   `"<provider>:<value>"`.
 * @return {*} The next attribute value.
 */
export function addRule( stored, rule ) {
	return apply( stored, ( state ) => {
		if ( isRule( rule ) && state.rules.indexOf( rule ) === -1 ) {
			state.rules = state.rules.concat( [ rule ] );
		}
	} );
}

/**
 * Removes a rule. Removing the last one removes everything that depended on it.
 *
 * @param {*}      stored Stored attribute value.
 * @param {string} rule   The rule as it is stored.
 * @return {*} The next attribute value, `undefined` when nothing is left to store.
 */
export function removeRule( stored, rule ) {
	return apply( stored, ( state ) => {
		state.rules = state.rules.filter( ( entry ) => entry !== rule );
	} );
}

/**
 * Sets whether any or all rules must match. Ignored below two rules.
 *
 * @param {*}      stored Stored attribute value.
 * @param {string} match  `any` or `all`.
 * @return {*} The next attribute value.
 */
export function setMatch( stored, match ) {
	return apply( stored, ( state ) => {
		if ( state.rules.length > 1 ) {
			state.match = match === MATCH.ALL ? MATCH.ALL : MATCH.ANY;
		}
	} );
}

/**
 * Sets whether the result is reversed. Ignored with no rules.
 *
 * @param {*}       stored Stored attribute value.
 * @param {boolean} on     Whether to reverse.
 * @return {*} The next attribute value.
 */
export function setInvert( stored, on ) {
	return apply( stored, ( state ) => {
		if ( state.rules.length > 0 ) {
			state.invert = on === true;
		}
	} );
}

/**
 * Sets the replacement content. Ignored with no rules.
 *
 * @param {*}             stored Stored attribute value.
 * @param {number|string} id     A post id. Zero, empty, or anything that is
 *                               not a positive whole number means none.
 * @return {*} The next attribute value.
 */
export function setFallback( stored, id ) {
	return apply( stored, ( state ) => {
		if ( state.rules.length === 0 ) {
			return;
		}

		const number = typeof id === 'string' && /^[0-9]+$/.test( id ) ? parseInt( id, 10 ) : id;

		state.fallbackId = positiveInteger( number );
	} );
}

/**
 * Clears the block's access setting, whatever it holds. The one write allowed
 * on a value the panel cannot read. `meta` is kept, and every other key goes.
 *
 * @param {*} stored Stored attribute value.
 * @return {*} The next attribute value, `undefined` when nothing is left to store.
 */
export function resetAccess( stored ) {
	return build( stored, emptyState() );
}

/**
 * Whether the block is limited at all, for the mark on the canvas.
 *
 * A value that cannot be read counts: the server hides such a block from
 * everyone, so an author should see it at a glance.
 *
 * @param {*} stored Stored attribute value.
 * @return {boolean} Whether the block carries a rule, or a setting that hides it.
 */
export function isRestricted( stored ) {
	// Nearly every block in a post carries nothing. Answer for those without
	// building a state, because this runs for each block on every render.
	if ( stored === undefined || stored === null || ( isPlainObject( stored ) && Object.keys( stored ).length === 0 ) ) {
		return false;
	}

	const state = normalise( stored );

	return state.unreadable || state.rules.length > 0;
}
