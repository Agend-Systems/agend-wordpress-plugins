/**
 * Reads the first shape of the `agendAccess` attribute as rules, with no
 * WordPress imports.
 *
 * The panel that shipped first stored two settings side by side, an audience
 * ("Who can see this") and display conditions, in these keys:
 *
 *   mode               inherit (default), active_member, selected_tiers,
 *                      segments, selected_entitlements
 *   tierIds            string[]  for selected_tiers
 *   segmentIds         string[]  for segments
 *   entitlementKeys    string[]  for selected_entitlements
 *   conditionsEnabled  boolean   display conditions switched on
 *   conditions         string[]  "<provider>:<value>"
 *   conditionsMatch    any (default) or all
 *   conditionsInvert   boolean
 *   fallbackId         positive integer, a post id
 *
 * The current shape is one rule list (access-model.mjs). A block saved under
 * the old one is never rewritten by opening it: this only says what the old
 * value MEANS, in rules, so the panel can show it, and the panel rewrites it
 * only when an author changes something.
 *
 * THE CONVERSION, as the server reads it:
 *
 *   mode inherit, absent or empty          no rules
 *   mode active_member                     agend_membership:is_member
 *   mode selected_tiers                    one agend_membership:<id> per plan
 *   mode segments                          one agend_segments:<id> per segment
 *   mode selected_entitlements             one agend_entitlements:<key> per key
 *   conditionsEnabled true                 its conditions, as stored, with its
 *                                          match, reverse and fallback
 *   conditionsEnabled true, no conditions  no rules (a block with nothing to
 *                                          check is shown to everyone)
 *
 * WHAT CANNOT BE SHOWN AS ONE LIST. Anything whose meaning one flat list would
 * change is `unreadable`, never converted by guessing, because showing it more
 * loosely than the server enforces it would quietly widen who can see it:
 *
 *   - a set-mode with nothing valid selected. The server hides the block from
 *     everyone, and an empty rule list shows it to everyone.
 *   - conditions switched on, empty and reversed. The server hides the block
 *     from everyone, for the same reason.
 *   - a mode this version does not recognise, a list that is not a list, a
 *     condition that is not a text, which the server fails closed on.
 *   - an audience AND reversed conditions. Reversing the pair is not the same
 *     as reversing the conditions, and one flat list can only reverse all of it.
 *
 * AN AUDIENCE AND CONDITIONS TOGETHER. The server needs BOTH to pass. A flat
 * list cannot say "any of these plans AND this role", so this is `twoPart`: the
 * rules are shown joined, with Match set to All, which can only ever be as
 * strict or stricter than the pair (every rule must pass, so the audience
 * passes and so do the conditions), and the panel says plainly that the block
 * uses an older two-part setting. It is exact when the audience is one rule
 * (all active members) and the conditions are matched with All.
 *
 * Deliberately ES2017 syntax: this file is also bundled for the browser.
 */

/** The keys only the first shape used. `fallbackId` is shared, so it is not here. */
export const LEGACY_KEYS = Object.freeze( [
	'mode',
	'tierIds',
	'segmentIds',
	'entitlementKeys',
	'conditionsEnabled',
	'conditions',
	'conditionsMatch',
	'conditionsInvert',
] );

/** The attribute key and rule provider of each set-mode. */
const SET_MODES = Object.freeze( {
	selected_tiers: { key: 'tierIds', provider: 'agend_membership' },
	segments: { key: 'segmentIds', provider: 'agend_segments' },
	selected_entitlements: { key: 'entitlementKeys', provider: 'agend_entitlements' },
} );

export const MEMBER_RULE = 'agend_membership:is_member';

function isPlainObject( value ) {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

function isText( value ) {
	return typeof value === 'string' && value !== '';
}

function unique( list ) {
	return list.filter( ( entry, index ) => list.indexOf( entry ) === index );
}

function present( stored, key ) {
	return stored[ key ] !== undefined && stored[ key ] !== null;
}

/**
 * Whether a stored value carries any key only the first shape used.
 *
 * @param {*} stored The stored attribute value.
 * @return {boolean} True for an object with at least one such key set.
 */
export function hasLegacyKeys( stored ) {
	return isPlainObject( stored ) && LEGACY_KEYS.some( ( key ) => present( stored, key ) );
}

function unreadable() {
	return { rules: [], match: 'any', invert: false, fallbackId: 0, twoPart: false, unreadable: true };
}

/**
 * The audience as rules.
 *
 * @param {Object} stored A first-shape value.
 * @return {string[]|null} The rules, empty for "everyone", or null when the
 *         audience cannot be read.
 */
function audienceRules( stored ) {
	const mode = stored.mode;

	if ( mode === undefined || mode === null || mode === '' || mode === 'inherit' ) {
		return [];
	}

	if ( mode === 'active_member' ) {
		return [ MEMBER_RULE ];
	}

	if ( typeof mode !== 'string' || ! Object.prototype.hasOwnProperty.call( SET_MODES, mode ) ) {
		return null;
	}

	const set = SET_MODES[ mode ];
	const chosen = stored[ set.key ];

	// A list that is not a list, or one with nothing usable in it, is a
	// restriction nobody satisfies: the server's reading, and one a flat list
	// cannot express.
	if ( ! Array.isArray( chosen ) ) {
		return null;
	}

	const rules = unique( chosen.filter( isText ) ).map( ( id ) => set.provider + ':' + id );

	return rules.length > 0 ? rules : null;
}

/**
 * Reads a first-shape value as rules.
 *
 * @param {Object} stored A plain object for which `hasLegacyKeys()` is true.
 * @return {{rules: string[], match: string, invert: boolean, fallbackId: number, twoPart: boolean, unreadable: boolean}}
 *         What it means. When `unreadable` is true the other fields are empty.
 */
export function readLegacy( stored ) {
	const audience = audienceRules( stored );

	if ( audience === null ) {
		return unreadable();
	}

	let conditions = [];
	let match = 'any';
	let invert = false;
	let fallbackId = 0;

	if ( stored.conditionsEnabled === true ) {
		const listed = stored.conditions;

		if ( Array.isArray( listed ) ) {
			if ( ! listed.every( isText ) ) {
				return unreadable();
			}

			conditions = unique( listed );
		} else if ( present( stored, 'conditions' ) ) {
			return unreadable();
		}

		match = stored.conditionsMatch === 'all' ? 'all' : 'any';
		invert = stored.conditionsInvert === true;

		const id = stored.fallbackId;

		fallbackId = typeof id === 'number' && Number.isInteger( id ) && id > 0 ? id : 0;
	}

	// Switched on, with nothing to check, and reversed: nobody passes.
	if ( stored.conditionsEnabled === true && conditions.length === 0 && invert ) {
		return unreadable();
	}

	// Replacement content was only ever shown to people the conditions
	// excluded, so with no conditions to exclude anyone it never showed.
	if ( conditions.length === 0 ) {
		fallbackId = 0;
	}

	if ( audience.length > 0 && conditions.length > 0 ) {
		if ( invert ) {
			return unreadable();
		}

		return {
			rules: unique( audience.concat( conditions ) ),
			match: 'all',
			invert: false,
			fallbackId,
			twoPart: true,
			unreadable: false,
		};
	}

	if ( audience.length > 0 ) {
		return { rules: audience, match: 'any', invert: false, fallbackId, twoPart: false, unreadable: false };
	}

	return { rules: conditions, match, invert, fallbackId, twoPart: false, unreadable: false };
}
