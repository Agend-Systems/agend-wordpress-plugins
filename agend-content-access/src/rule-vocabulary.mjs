/**
 * The rules an author can choose from, as the panel lists, labels and searches
 * them, with no WordPress imports.
 *
 * The vocabulary comes from `GET /agend-content-access/v1/conditions` once per
 * editor session (lists-loader.mjs):
 *
 *   {
 *     groups: [ { key, label, stale, items: [
 *       { value, label, kind, description?, aliases?, inactive? } ] } ],
 *     fallbacks: [ { id, title, type } ],
 *     errors: [ { group, message } ]
 *   }
 *
 * `value` is the rule exactly as the block stores it. `kind` says what sort of
 * rule it is (member, plan, segment, entitlement, platform, role, login), which
 * is what a chip is labelled with. `aliases` are other rule strings that mean
 * the same thing, such as a plan's slug, which older blocks and imported
 * conditions hold: they are recognised, labelled and removable like the rule
 * itself, but never offered as a second choice.
 *
 * THE PICKER AT SCALE. PCA's staging account has about 800 entitlements, so
 * the whole vocabulary is read once into an index and every keystroke filters
 * that in memory: no request, and at most MAX_RESULTS results ever rendered.
 *
 * Deliberately ES2017 syntax: this file is also bundled for the browser.
 */
import { DEFAULT_STRINGS, format } from './copy.mjs';

/** The most results rendered at once. */
export const MAX_RESULTS = 50;

/** With nothing typed, how many each long group shows. */
export const PREVIEW_SIZE = 5;

/**
 * The order groups are listed in, and the two shown in full with nothing typed.
 * A group a site adds through the filter follows, in the order the server gave.
 */
export const GROUP_ORDER = Object.freeze( [ 'agend_membership', 'agend_segments', 'agend_entitlements', 'agend_wordpress' ] );
const SHOWN_IN_FULL = Object.freeze( [ 'agend_membership', 'agend_wordpress' ] );

/** The rules every site has, labelled even before the vocabulary arrives. */
const BUILT_IN = Object.freeze( {
	'agend_wordpress:logged_in': 'signedIn',
	'agend_wordpress:logged_out': 'signedOut',
	'agend_membership:is_member': 'anyMember',
} );

function isPlainObject( value ) {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

function isText( value ) {
	return typeof value === 'string' && value !== '';
}

/**
 * Lower case with accents removed, so a search for "cafe" finds an accented spelling.
 *
 * @param {*} text Anything.
 * @return {string} The text, normalised for matching.
 */
export function fold( text ) {
	return String( text )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' );
}

// ---------------------------------------------------------------------------
// Reading the response
// ---------------------------------------------------------------------------

function readItem( raw ) {
	if ( ! isPlainObject( raw ) || ! isText( raw.value ) ) {
		return null;
	}

	return {
		value: raw.value,
		label: isText( raw.label ) ? raw.label : raw.value,
		kind: isText( raw.kind ) ? raw.kind : 'other',
		description: isText( raw.description ) ? raw.description : '',
		aliases: Array.isArray( raw.aliases ) ? raw.aliases.filter( isText ) : [],
		inactive: raw.inactive === true,
	};
}

/**
 * Reads the `/conditions` response.
 *
 * Junk is skipped, not repaired: an item with no value is not a rule, and a
 * rule that appears twice is offered once.
 *
 * @param {*} response The response body.
 * @return {{groups: Array, fallbacks: Array, errors: Array}|null} Null when it
 *         is not the documented shape, which the panel treats as a failed load.
 */
export function normaliseVocabulary( response ) {
	if ( ! isPlainObject( response ) || ! Array.isArray( response.groups ) ) {
		return null;
	}

	const seen = {};
	const groups = [];

	response.groups.forEach( ( raw ) => {
		if ( ! isPlainObject( raw ) || ! isText( raw.key ) ) {
			return;
		}

		const items = [];

		( Array.isArray( raw.items ) ? raw.items : [] ).forEach( ( entry ) => {
			const item = readItem( entry );

			if ( item && ! Object.prototype.hasOwnProperty.call( seen, item.value ) ) {
				seen[ item.value ] = true;
				items.push( item );
			}
		} );

		groups.push( {
			key: raw.key,
			label: isText( raw.label ) ? raw.label : raw.key,
			stale: raw.stale === true,
			items,
		} );
	} );

	const fallbacks = [];

	( Array.isArray( response.fallbacks ) ? response.fallbacks : [] ).forEach( ( raw ) => {
		if ( isPlainObject( raw ) && Number.isInteger( raw.id ) && raw.id > 0 ) {
			fallbacks.push( {
				id: raw.id,
				title: isText( raw.title ) ? raw.title : String( raw.id ),
				// page, pattern or elementor_template. A page's redirect is offered
				// pages only, so the kind is kept.
				type: isText( raw.type ) ? raw.type : '',
			} );
		}
	} );

	const errors = [];

	( Array.isArray( response.errors ) ? response.errors : [] ).forEach( ( raw ) => {
		if ( isPlainObject( raw ) && isText( raw.message ) ) {
			errors.push( { group: isText( raw.group ) ? raw.group : '', message: raw.message } );
		}
	} );

	return { groups, fallbacks, errors };
}

// ---------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------

/**
 * The label a rule carries as a chip and as a search result: its group in
 * plain words, then its name, so "Plan: Gold" and "Segment: Gold" never read
 * alike. The login and member rules say all they need to by themselves.
 *
 * @param {Object} item    An item of the index.
 * @param {Object} strings Copy.
 * @return {string} The label.
 */
export function ruleLabel( item, strings = DEFAULT_STRINGS ) {
	let label;

	switch ( item.kind ) {
		case 'plan':
			label = format( strings.plan, item.label );
			break;
		case 'segment':
			label = format( strings.segment, item.label );
			break;
		case 'entitlement':
			label = format( strings.entitlement, item.label );
			break;
		case 'platform':
			label = format( strings.platform, item.label );
			break;
		case 'role':
			label = format( strings.role, item.label );
			break;
		case 'member':
		case 'login':
			label = item.label;
			break;
		default:
			// A rule a site's own provider adds: say whose.
			label = format( strings.other, item.groupLabel || '', item.label );
	}

	return item.inactive ? format( strings.inactive, label ) : label;
}

// ---------------------------------------------------------------------------
// The index
// ---------------------------------------------------------------------------

function groupRank( key, position ) {
	const rank = GROUP_ORDER.indexOf( key );

	return rank === -1 ? GROUP_ORDER.length + position : rank;
}

/**
 * Reads the vocabulary into what the picker needs on every keystroke, once.
 *
 * @param {{groups: Array, errors: Array}|null} vocabulary From `normaliseVocabulary()`.
 * @param {Object} [strings] Copy, for the search text of each rule.
 * @return {Object|null} The index, or null with no vocabulary.
 */
export function buildIndex( vocabulary, strings = DEFAULT_STRINGS ) {
	if ( ! vocabulary ) {
		return null;
	}

	const errored = {};

	vocabulary.errors.forEach( ( error ) => {
		errored[ error.group ] = true;
	} );

	const groups = vocabulary.groups
		.map( ( group, position ) => ( { group, rank: groupRank( group.key, position ), position } ) )
		.sort( ( a, b ) => a.rank - b.rank || a.position - b.position )
		.map( ( entry ) => ( {
			key: entry.group.key,
			label: entry.group.label,
			stale: entry.group.stale,
			items: [],
			// A list that failed with nothing to show says nothing about what
			// exists, so a stored rule of that group is not called unavailable.
			known: ! errored[ entry.group.key ] || entry.group.items.length > 0,
		} ) );

	const byGroup = {};

	groups.forEach( ( group ) => {
		byGroup[ group.key ] = group;
	} );

	const byRule = new Map();
	let order = 0;

	vocabulary.groups.forEach( ( raw ) => {
		const group = byGroup[ raw.key ];

		raw.items.forEach( ( source ) => {
			const item = Object.assign( {}, source, { group: group.key, groupLabel: group.label, order: order++ } );
			const colon = item.value.indexOf( ':' );
			const key = colon === -1 ? '' : item.value.slice( colon + 1 );
			const label = ruleLabel( item, strings );
			const keyed = item.kind === 'entitlement' || item.kind === 'platform' || item.kind === 'role' || item.kind === 'other';

			item.chip = label;
			item.haystack = fold( [ label, item.groupLabel, item.description, keyed ? key : '' ].join( ' ' ) );
			item.folded = fold( item.label );

			group.items.push( item );

			[ item.value ].concat( item.aliases ).forEach( ( rule ) => {
				if ( ! byRule.has( rule ) ) {
					byRule.set( rule, item );
				}
			} );
		} );
	} );

	return { groups, byGroup, byRule };
}

/**
 * The chips for a block's rules, in the order the block holds them.
 *
 * A rule the vocabulary does not offer is KEPT and says so: dropping it would
 * quietly change who can see the block. `status` is `available`, `inactive`
 * (a plan that was switched off), `unavailable` (the vocabulary is known and
 * does not list it) or `unverified` (the vocabulary, or that group of it,
 * could not be loaded, so nothing can be said either way and the rule is shown
 * as it is stored).
 *
 * @param {string[]}    rules   The block's rules.
 * @param {Object|null} index   From `buildIndex()`, null while it is not loaded.
 * @param {Object}      strings Copy.
 * @return {Array} Chips `{value, label, status}`.
 */
export function describeRules( rules, index, strings = DEFAULT_STRINGS ) {
	return rules.map( ( rule ) => {
		const item = index ? index.byRule.get( rule ) : undefined;

		if ( item ) {
			return { value: rule, label: item.chip, status: item.inactive ? 'inactive' : 'available' };
		}

		const builtIn = Object.prototype.hasOwnProperty.call( BUILT_IN, rule ) ? strings[ BUILT_IN[ rule ] ] : '';

		if ( builtIn ) {
			return { value: rule, label: builtIn, status: 'available' };
		}

		const provider = rule.indexOf( ':' ) === -1 ? '' : rule.slice( 0, rule.indexOf( ':' ) );
		const group = index && provider ? index.byGroup[ provider ] : undefined;

		if ( ! index || ( group && ! group.known ) ) {
			return { value: rule, label: rule, status: 'unverified' };
		}

		return { value: rule, label: format( strings.unavailable, rule ), status: 'unavailable' };
	} );
}

// ---------------------------------------------------------------------------
// Searching
// ---------------------------------------------------------------------------

function isChosen( item, chosen ) {
	return chosen.has( item.value ) || item.aliases.some( ( alias ) => chosen.has( alias ) );
}

/**
 * How well a rule's name fits what was typed: 0 starts with it, 1 has a word
 * that does, 2 merely contains it. Within a score the vocabulary's own order
 * stands.
 */
function score( item, needle, first ) {
	if ( item.folded.indexOf( needle ) === 0 ) {
		return 0;
	}

	return item.folded.indexOf( ' ' + first ) !== -1 ? 1 : 2;
}

/**
 * The rules to offer for what has been typed, grouped, and never more than
 * MAX_RESULTS of them.
 *
 * Rules the block already has are left out, and so are plans that are switched
 * off. With nothing typed the Membership and WordPress groups are shown in
 * full and every other group shows its first few, with how many more there are.
 * Typed text is matched against the whole label, the group word ("plan"), the
 * description, and the key of an entitlement or role, a word at a time, so
 * "directory access" finds an item that has both words anywhere.
 *
 * @param {Object|null} index  From `buildIndex()`.
 * @param {string}      query  What was typed.
 * @param {string[]}    rules  The block's rules.
 * @return {{mode: string, groups: Array, total: number, rendered: number, capped: boolean, alreadyAdded: number}}
 *         `groups` are `{key, label, items, more}`, empty groups left out.
 *         `total` is every match, `rendered` how many are in `groups`,
 *         `capped` whether a search had more than it rendered, and
 *         `alreadyAdded` how many rules the search matched that the block
 *         already has, which are not offered again.
 */
export function searchRules( index, query, rules ) {
	const empty = { mode: 'preview', groups: [], total: 0, rendered: 0, capped: false, alreadyAdded: 0 };

	if ( ! index ) {
		return empty;
	}

	const chosen = new Set( rules );
	const needle = fold( String( query ).trim().replace( /\s+/g, ' ' ) );
	const tokens = needle === '' ? [] : needle.split( ' ' );
	const searching = tokens.length > 0;
	const picks = [];
	let total = 0;
	let alreadyAdded = 0;

	index.groups.forEach( ( group ) => {
		let matches = group.items.filter( ( item ) => ! item.inactive && ! isChosen( item, chosen ) );

		if ( searching ) {
			// What the search found that is already a rule, so a search that finds only
			// those can say so instead of saying nothing matches.
			alreadyAdded += group.items.filter( ( item ) => isChosen( item, chosen ) && tokens.every( ( token ) => item.haystack.indexOf( token ) !== -1 ) ).length;

			matches = matches
				.filter( ( item ) => tokens.every( ( token ) => item.haystack.indexOf( token ) !== -1 ) )
				.map( ( item ) => ( { item, score: score( item, needle, tokens[ 0 ] ) } ) )
				.sort( ( a, b ) => a.score - b.score || a.item.order - b.item.order )
				.map( ( entry ) => entry.item );
		}

		total += matches.length;

		const visible = searching || SHOWN_IN_FULL.indexOf( group.key ) !== -1 ? matches : matches.slice( 0, PREVIEW_SIZE );

		picks.push( { group, matches: matches.length, visible } );
	} );

	let room = MAX_RESULTS;
	const groups = [];

	picks.forEach( ( pick ) => {
		const items = pick.visible.slice( 0, room );

		room -= items.length;

		if ( items.length > 0 ) {
			groups.push( { key: pick.group.key, label: pick.group.label, items, more: pick.matches - items.length } );
		}
	} );

	const rendered = MAX_RESULTS - room;

	return {
		mode: searching ? 'search' : 'preview',
		groups,
		total,
		rendered,
		capped: searching && total > rendered,
		alreadyAdded,
	};
}

// ---------------------------------------------------------------------------
// Show instead
// ---------------------------------------------------------------------------

/**
 * The "Show instead" choices, and a page's placeholder and redirect choices.
 *
 * A chosen post that is not offered (restricted since, or past the server's
 * list limit) is kept in the list and selected, so the control shows what is
 * stored instead of silently displaying "Nothing".
 *
 * @param {Array}  fallbacks `fallbacks` from `normaliseVocabulary()`.
 * @param {number} current   The stored post id, 0 for none.
 * @param {Object} strings   Copy.
 * @param {Object} [options]
 * @param {string[]} [options.types] Only these kinds (page, pattern,
 *                          elementor_template). A redirect goes to a page, and a
 *                          pattern or a template has no address of its own.
 * @param {string} [options.none]    What the empty choice says, "Nothing" by default.
 * @return {Array} `{value, label}` choices, the empty one first.
 */
export function fallbackChoices( fallbacks, current, strings = DEFAULT_STRINGS, options = {} ) {
	const types = Array.isArray( options.types ) ? options.types : null;
	const list = ( Array.isArray( fallbacks ) ? fallbacks : [] ).filter( ( entry ) => types === null || types.indexOf( entry.type ) !== -1 );
	const choices = [ { value: '', label: typeof options.none === 'string' ? options.none : strings.nothing } ];

	if ( current > 0 && ! list.some( ( entry ) => entry.id === current ) ) {
		choices.push( { value: String( current ), label: format( strings.fallbackMissing, '#' + current ) } );
	}

	list.forEach( ( entry ) => {
		choices.push( { value: String( entry.id ), label: entry.title } );
	} );

	return choices;
}

/**
 * The choices that contain what was typed, for a searchable select. The chosen
 * one is not special-cased: a select that hid its own value would be confusing.
 *
 * @param {Array}  choices From `fallbackChoices()`.
 * @param {string} query   What was typed.
 * @return {Array} The matching choices, in order.
 */
export function filterChoices( choices, query ) {
	const needle = fold( String( query ).trim() );

	return needle === '' ? choices : choices.filter( ( choice ) => fold( choice.label ).indexOf( needle ) !== -1 );
}
