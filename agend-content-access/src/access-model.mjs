/**
 * The logic behind the Agend Access block panel, with no WordPress imports.
 *
 * It lives apart from the React components so `node --test` can run it
 * (agend-content-access/tests/js/access-model.test.mjs) and so the rules about
 * what the panel may write are in one place that can be read top to bottom.
 *
 * THE CONTRACT. Every block may carry one object attribute, `agendAccess`.
 * Absent or `{}` means "same as the page, no display conditions". The PHP side
 * reads it with `Agend_Content_Access_Block_Attributes::to_settings()`, and the
 * keys are:
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
 * THE RULES THE PANEL KEEPS.
 *
 *  1. It writes those keys and nothing else, and writes the smallest object
 *     that says what the author chose. A default is never written out: no
 *     `mode` for "same as the page", no `conditionsMatch` for "any". Nothing
 *     chosen at all is `{}`, which a block serialises as no attribute, so a
 *     block an author only looked at stays byte-identical.
 *  2. A key that no longer applies is dropped, not hidden. Changing the mode
 *     drops the other modes' lists, and switching conditions off drops every
 *     condition key. The stored object always matches what the panel shows, so
 *     a stale id cannot sit in the post and come back to life after a later edit.
 *  3. Keys this panel does not own are kept exactly as found. A newer release,
 *     a script, or another tool may have written them, and silently deleting
 *     somebody else's data because an author nudged a toggle is worse than
 *     carrying a key the server ignores. (If nothing but such keys remains the
 *     object is still not `{}`, which is correct: it is not empty.)
 *  4. A stored value it cannot read never crashes the panel and never loosens
 *     anything by accident. The server fails closed on an unreadable mode (a
 *     member-only block), so the panel shows that state, offers only the
 *     audience control until an author picks a real option, and refuses every
 *     other write while it is in that state.
 *  5. Inputs are never mutated. A block's default attribute value is one shared
 *     object, so mutating it would change every block at once.
 *
 * Deliberately ES2017 syntax: this file is also bundled for the browser.
 */

export const ATTRIBUTE = 'agendAccess';

/**
 * Block types that cannot carry the attribute, and why.
 *
 * Both are serialised WITHOUT a comment delimiter, so an attribute has nowhere
 * to be stored and an author would set a policy the post then forgets:
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

export const MODE = Object.freeze( {
	INHERIT: 'inherit',
	MEMBERS: 'active_member',
	TIERS: 'selected_tiers',
	SEGMENTS: 'segments',
	ENTITLEMENTS: 'selected_entitlements',
} );

/**
 * What the panel shows for a stored mode it does not recognise. Never written.
 */
export const UNRECOGNISED = 'unrecognised';

/** The attribute key that holds each set-mode's list. */
export const LIST_KEY = Object.freeze( {
	selected_tiers: 'tierIds',
	segments: 'segmentIds',
	selected_entitlements: 'entitlementKeys',
} );

export const MATCH = Object.freeze( { ANY: 'any', ALL: 'all' } );

/** Every key the panel owns, in the order it writes them. */
export const CONTRACT_KEYS = Object.freeze( [
	'mode',
	'tierIds',
	'segmentIds',
	'entitlementKeys',
	'conditionsEnabled',
	'conditions',
	'conditionsMatch',
	'conditionsInvert',
	'fallbackId',
] );

/**
 * English copy for the labels this module composes. The components pass in
 * translated strings of the same shape; this default is what the tests read.
 */
export const DEFAULT_STRINGS = Object.freeze( {
	platform: 'Platform: %s',
	unavailable: '%s (unavailable)',
	inactivePlan: '%s (no longer active)',
	fallbackMissing: 'Content %s (not in the list)',
	nothing: 'Nothing',
} );

function format( template, value ) {
	// A replacer function, so a `$&` inside a plan name is not read as a
	// replacement pattern.
	return String( template ).replace( '%s', () => String( value ) );
}

export function isPlainObject( value ) {
	return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

function isNonEmptyString( value ) {
	return typeof value === 'string' && value !== '';
}

function strings( value ) {
	return Array.isArray( value ) ? value.filter( ( entry ) => typeof entry === 'string' ) : [];
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

const RECOGNISED_MODES = Object.keys( LIST_KEY ).concat( [ MODE.INHERIT, MODE.MEMBERS ] );

function emptyState() {
	return {
		mode: MODE.INHERIT,
		tierIds: [],
		segmentIds: [],
		entitlementKeys: [],
		conditionsEnabled: false,
		conditions: [],
		conditionsMatch: MATCH.ANY,
		conditionsInvert: false,
		fallbackId: 0,
		unreadable: false,
	};
}

function unreadableState() {
	return Object.assign( emptyState(), { mode: UNRECOGNISED, unreadable: true } );
}

/**
 * Reads a stored attribute value the way the server reads it.
 *
 * Only what applies is returned: a list is read for the mode that uses it and
 * is empty otherwise, and the condition fields are read only when conditions are
 * switched on. That matches the server, which ignores the rest, and it is what
 * stops a stale key coming back to life when a mode or a toggle changes.
 *
 * `unreadable` is true where what the panel shows would differ from what the
 * server acts on, or where the server fails closed: a mode this version does
 * not recognise, a value that is not an object at all, and display conditions
 * the server would deny as malformed. Everything else the server shrugs off
 * (a wrong-typed list, an unknown match value) is read the same way here and is
 * not worth alarming an author about.
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
	// array), so it is inherit. A non-empty list, a string, a number or a
	// boolean is present and unreadable, which the server fails closed on.
	if ( Array.isArray( stored ) ) {
		return stored.length === 0 ? emptyState() : unreadableState();
	}

	if ( ! isPlainObject( stored ) ) {
		return unreadableState();
	}

	const state = emptyState();
	const mode = stored.mode;

	if ( mode === undefined || mode === null || mode === '' ) {
		state.mode = MODE.INHERIT;
	} else if ( typeof mode === 'string' && RECOGNISED_MODES.indexOf( mode ) !== -1 ) {
		state.mode = mode;
	} else {
		state.mode = UNRECOGNISED;
		state.unreadable = true;
	}

	const listKey = LIST_KEY[ state.mode ];

	if ( listKey ) {
		state[ listKey ] = unique( strings( stored[ listKey ] ) );
	}

	if ( stored.conditionsEnabled === true ) {
		state.conditionsEnabled = true;

		if ( Array.isArray( stored.conditions ) ) {
			const readable = strings( stored.conditions );

			state.conditions = unique( readable );
			state.unreadable = state.unreadable || readable.length !== stored.conditions.length;
		} else if ( stored.conditions !== undefined && stored.conditions !== null ) {
			state.unreadable = true;
		}

		state.conditionsMatch = stored.conditionsMatch === MATCH.ALL ? MATCH.ALL : MATCH.ANY;
		state.conditionsInvert = stored.conditionsInvert === true;
		state.fallbackId = typeof stored.fallbackId === 'number' && Number.isInteger( stored.fallbackId ) && stored.fallbackId > 0
			? stored.fallbackId
			: 0;
	}

	return state;
}

// ---------------------------------------------------------------------------
// Writing a change
// ---------------------------------------------------------------------------

/**
 * The smallest object that says what a state says, plus the keys the panel
 * does not own.
 *
 * @param {*}      stored The stored value, for the keys that are not ours.
 * @param {Object} state  A state from `normalise()`, changed.
 * @return {Object} The next attribute value.
 */
function build( stored, state ) {
	const next = {};

	if ( state.mode !== MODE.INHERIT ) {
		next.mode = state.mode;
	}

	const listKey = LIST_KEY[ state.mode ];

	if ( listKey ) {
		// Always written for a set-mode, even empty. An empty list is a
		// restriction nobody satisfies, and that is worth saying out loud in the
		// stored block rather than leaving it to be inferred from a missing key.
		next[ listKey ] = state[ listKey ].slice();
	}

	if ( state.conditionsEnabled ) {
		next.conditionsEnabled = true;

		// An empty conditions list means the same as none, so it is not written.
		if ( state.conditions.length > 0 ) {
			next.conditions = state.conditions.slice();
		}

		if ( state.conditionsMatch === MATCH.ALL ) {
			next.conditionsMatch = MATCH.ALL;
		}

		if ( state.conditionsInvert ) {
			next.conditionsInvert = true;
		}

		if ( state.fallbackId > 0 ) {
			next.fallbackId = state.fallbackId;
		}
	}

	if ( isPlainObject( stored ) ) {
		Object.keys( stored ).forEach( ( key ) => {
			if ( CONTRACT_KEYS.indexOf( key ) === -1 ) {
				next[ key ] = stored[ key ];
			}
		} );
	}

	return next;
}

/**
 * Applies a change to the stored value.
 *
 * @param {*}        stored    Stored attribute value.
 * @param {Function} change    Mutates the (private) state.
 * @param {boolean}  [repairs] Whether this change may be made while the mode is
 *                             unreadable. Only choosing a mode may.
 * @return {*} The next attribute value. The stored value itself when the panel
 *             refuses the change.
 */
function apply( stored, change, repairs ) {
	const state = normalise( stored );

	if ( state.mode === UNRECOGNISED && ! repairs ) {
		return stored;
	}

	change( state );

	return build( stored, state );
}

/**
 * Chooses who can see the block.
 *
 * Choosing a different mode drops the lists of every mode the block is no
 * longer in, and starts the new one empty. Choosing the mode it already has
 * changes nothing.
 *
 * @param {*}      stored Stored attribute value.
 * @param {string} mode   One of `MODE`.
 * @return {Object} The next attribute value.
 */
export function setMode( stored, mode ) {
	if ( RECOGNISED_MODES.indexOf( mode ) === -1 ) {
		return stored;
	}

	return apply(
		stored,
		( state ) => {
			if ( state.mode !== mode ) {
				state.tierIds = [];
				state.segmentIds = [];
				state.entitlementKeys = [];
			}

			state.mode = mode;
			state.unreadable = false;
		},
		true
	);
}

/**
 * Sets the chosen list for the block's current set-mode.
 *
 * @param {*}        stored Stored attribute value.
 * @param {string[]} values Chosen ids or keys.
 * @return {*} The next attribute value. Unchanged when the mode has no list.
 */
export function setList( stored, values ) {
	return apply( stored, ( state ) => {
		const key = LIST_KEY[ state.mode ];

		if ( key ) {
			state[ key ] = unique( strings( values ) );
		}
	} );
}

/**
 * Switches display conditions on or off. Off drops every condition key.
 *
 * @param {*}       stored Stored attribute value.
 * @param {boolean} on     Whether conditions are on.
 * @return {*} The next attribute value.
 */
export function setConditionsEnabled( stored, on ) {
	return apply( stored, ( state ) => {
		state.conditionsEnabled = on === true;

		if ( ! state.conditionsEnabled ) {
			state.conditions = [];
			state.conditionsMatch = MATCH.ANY;
			state.conditionsInvert = false;
			state.fallbackId = 0;
		}
	} );
}

/**
 * Sets the chosen conditions. Ignored while conditions are off.
 *
 * @param {*}        stored Stored attribute value.
 * @param {string[]} values `"<provider>:<value>"` strings.
 * @return {*} The next attribute value.
 */
export function setConditions( stored, values ) {
	return apply( stored, ( state ) => {
		if ( state.conditionsEnabled ) {
			state.conditions = unique( strings( values ) );
		}
	} );
}

/**
 * Sets whether any or all conditions must match. Ignored while conditions are off.
 *
 * @param {*}      stored Stored attribute value.
 * @param {string} match  `any` or `all`.
 * @return {*} The next attribute value.
 */
export function setMatch( stored, match ) {
	return apply( stored, ( state ) => {
		if ( state.conditionsEnabled ) {
			state.conditionsMatch = match === MATCH.ALL ? MATCH.ALL : MATCH.ANY;
		}
	} );
}

/**
 * Sets whether the conditions' result is reversed. Ignored while conditions are off.
 *
 * @param {*}       stored Stored attribute value.
 * @param {boolean} on     Whether to reverse.
 * @return {*} The next attribute value.
 */
export function setInvert( stored, on ) {
	return apply( stored, ( state ) => {
		if ( state.conditionsEnabled ) {
			state.conditionsInvert = on === true;
		}
	} );
}

/**
 * Sets the replacement content. Ignored while conditions are off.
 *
 * @param {*}               stored Stored attribute value.
 * @param {number|string}   id     A post id. Zero, empty, or anything that is
 *                                 not a positive whole number means none.
 * @return {*} The next attribute value.
 */
export function setFallback( stored, id ) {
	return apply( stored, ( state ) => {
		if ( ! state.conditionsEnabled ) {
			return;
		}

		const number = typeof id === 'string' && /^[0-9]+$/.test( id ) ? parseInt( id, 10 ) : id;

		state.fallbackId = typeof number === 'number' && Number.isInteger( number ) && number > 0 ? number : 0;
	} );
}

/**
 * One checkbox ticked or cleared in a list.
 *
 * @param {string[]} list    Current list.
 * @param {string}   value   The checkbox's value.
 * @param {boolean}  checked Whether it is now ticked.
 * @return {string[]} A new list, in the original order with additions last.
 */
export function toggleValue( list, value, checked ) {
	const current = strings( list );
	const without = current.filter( ( entry ) => entry !== value );

	return checked ? without.concat( [ value ] ) : without;
}

/**
 * What the canvas indicator should say about a block.
 *
 * An unreadable mode counts as a policy: the server restricts such a block, so
 * an author should be able to see it at a glance.
 *
 * @param {*} stored Stored attribute value.
 * @return {string} '', 'policy', 'conditions' or 'both'.
 */
export function indicatorKind( stored ) {
	// Nearly every block in a post carries nothing. Answer for those without
	// building a state, because this runs for each block on every render.
	if ( stored === undefined || stored === null || ( isPlainObject( stored ) && Object.keys( stored ).length === 0 ) ) {
		return '';
	}

	const state = normalise( stored );
	const policy = state.mode !== MODE.INHERIT;

	if ( policy && state.conditionsEnabled ) {
		return 'both';
	}

	if ( policy ) {
		return 'policy';
	}

	return state.conditionsEnabled ? 'conditions' : '';
}

// ---------------------------------------------------------------------------
// The catalogue, as the panel lists it
// ---------------------------------------------------------------------------

/**
 * Reads one `{items, stale, error}` section of the catalogue response.
 *
 * `known` says whether the list can be trusted to be complete enough to call a
 * stored choice "unavailable". A section that failed with nothing to show
 * tells us nothing about what exists, so a stored choice is then only
 * unverified. A stale list, kept after a failed refresh, still counts.
 *
 * @param {*} section A section, or anything.
 * @return {{items: Array, error: string, stale: boolean, known: boolean}} Reading.
 */
export function sectionState( section ) {
	const object = isPlainObject( section ) ? section : {};
	const items = Array.isArray( object.items ) ? object.items : null;
	const error = isNonEmptyString( object.error ) ? object.error : '';

	return {
		items: items || [],
		error,
		stale: object.stale === true,
		known: items !== null && ( error === '' || items.length > 0 ),
	};
}

function itemsFrom( section, read ) {
	const seen = {};
	const items = [];

	sectionState( section ).items.forEach( ( raw ) => {
		const item = isPlainObject( raw ) ? read( raw ) : null;

		if ( item && ! seen[ item.value ] ) {
			seen[ item.value ] = true;
			items.push( item );
		}
	} );

	return items;
}

/**
 * Membership plans. An inactive plan is not offered, but it is kept so a block
 * that names one can still say which.
 *
 * @param {*} section The `plans` section.
 * @return {Array} Items `{value, name, offered}`.
 */
export function planItems( section ) {
	return itemsFrom( section, ( raw ) => {
		if ( ! isNonEmptyString( raw.id ) ) {
			return null;
		}

		return {
			value: raw.id,
			name: isNonEmptyString( raw.name ) ? raw.name : raw.id,
			offered: raw.active !== false,
		};
	} );
}

/**
 * Segments, by id.
 *
 * @param {*} section The `segments` section.
 * @return {Array} Items `{value, name, offered}`.
 */
export function segmentItems( section ) {
	return itemsFrom( section, ( raw ) => {
		if ( ! isNonEmptyString( raw.id ) ) {
			return null;
		}

		return {
			value: raw.id,
			name: isNonEmptyString( raw.name ) ? raw.name : ( isNonEmptyString( raw.slug ) ? raw.slug : raw.id ),
			offered: true,
		};
	} );
}

/**
 * Entitlement types, by gate key.
 *
 * @param {*} section The `entitlements` section.
 * @return {Array} Items `{value, name, kind, description, offered}`.
 */
export function entitlementItems( section ) {
	return itemsFrom( section, ( raw ) => {
		if ( ! isNonEmptyString( raw.gate_key ) ) {
			return null;
		}

		return {
			value: raw.gate_key,
			name: isNonEmptyString( raw.name ) ? raw.name : raw.gate_key,
			kind: raw.kind === 'platform-gate' || raw.kind === 'unrecognised' ? raw.kind : 'entitlement',
			description: isNonEmptyString( raw.description ) ? raw.description : '',
			offered: true,
		};
	} );
}

/**
 * The label an item is offered under. A platform gate is a capability the
 * platform defines rather than one an administrator authored, so it says so.
 * An unrecognised key keeps its own name, unprefixed, as the server's list does.
 *
 * @param {Object} item    An item.
 * @param {Object} strings Copy.
 * @return {string} Label.
 */
export function itemLabel( item, strings = DEFAULT_STRINGS ) {
	return item.kind === 'platform-gate' ? format( strings.platform, item.name ) : item.name;
}

/**
 * The rows of one audience checklist.
 *
 * A stored choice that is not on offer comes FIRST, checked and marked, so an
 * author sees it and can clear it. It is never dropped here: removing it would
 * quietly change who can see the block. After those come the offered items in
 * the catalogue's order.
 *
 * `status` is `available`, `unavailable` (the catalogue is known and does not
 * offer it) or `unverified` (the catalogue could not be read, so nothing can be
 * said either way and the stored value is shown as it is).
 *
 * @param {Array}    items    Items from `planItems()` and friends.
 * @param {string[]} selected The stored choices.
 * @param {Object}   options  `known` (default true) and `strings`.
 * @return {Array} Rows `{value, label, help, checked, status}`.
 */
export function checklistRows( items, selected, options ) {
	const known = ! options || options.known !== false;
	const copy = options && options.strings ? options.strings : DEFAULT_STRINGS;
	const chosen = unique( strings( selected ) );
	const byValue = {};

	( Array.isArray( items ) ? items : [] ).forEach( ( item ) => {
		if ( ! Object.prototype.hasOwnProperty.call( byValue, item.value ) ) {
			byValue[ item.value ] = item;
		}
	} );

	const stray = [];

	chosen.forEach( ( value ) => {
		const item = Object.prototype.hasOwnProperty.call( byValue, value ) ? byValue[ value ] : null;

		if ( item && item.offered !== false ) {
			return;
		}

		if ( ! known ) {
			stray.push( { value, label: value, help: '', checked: true, status: 'unverified' } );
			return;
		}

		stray.push( {
			value,
			label: item ? format( copy.inactivePlan, itemLabel( item, copy ) ) : format( copy.unavailable, value ),
			help: '',
			checked: true,
			status: 'unavailable',
		} );
	} );

	const offered = ( Array.isArray( items ) ? items : [] )
		.filter( ( item ) => item.offered !== false )
		.map( ( item ) => ( {
			value: item.value,
			label: itemLabel( item, copy ),
			help: item.description || '',
			checked: chosen.indexOf( item.value ) !== -1,
			status: 'available',
		} ) );

	return stray.concat( offered );
}

// ---------------------------------------------------------------------------
// The condition vocabulary, as the panel lists it
// ---------------------------------------------------------------------------

/**
 * Reads the `/conditions` response.
 *
 * @param {*} response The response body.
 * @return {{groups: Array, fallbacks: Array}|null} Null when it is not the
 *         documented shape, which the panel treats as a failed load.
 */
export function normaliseVocabulary( response ) {
	if ( ! isPlainObject( response ) || ! Array.isArray( response.groups ) ) {
		return null;
	}

	const groups = [];

	response.groups.forEach( ( raw ) => {
		if ( ! isPlainObject( raw ) || ! isNonEmptyString( raw.provider ) ) {
			return;
		}

		const options = [];

		( Array.isArray( raw.conditions ) ? raw.conditions : [] ).forEach( ( condition ) => {
			if ( isPlainObject( condition ) && isNonEmptyString( condition.value ) ) {
				options.push( {
					value: condition.value,
					label: typeof condition.label === 'string' && condition.label !== '' ? condition.label : condition.value,
				} );
			}
		} );

		groups.push( {
			provider: raw.provider,
			label: isNonEmptyString( raw.label ) ? raw.label : raw.provider,
			options,
		} );
	} );

	const fallbacks = [];

	( Array.isArray( response.fallbacks ) ? response.fallbacks : [] ).forEach( ( raw ) => {
		if ( isPlainObject( raw ) && Number.isInteger( raw.id ) && raw.id > 0 ) {
			fallbacks.push( { id: raw.id, title: typeof raw.title === 'string' && raw.title !== '' ? raw.title : String( raw.id ) } );
		}
	} );

	return { groups, fallbacks };
}

/**
 * The conditions multi-select: one group per provider, with any stored
 * condition the vocabulary does not offer pulled out FIRST.
 *
 * `known` is false when the vocabulary could not be loaded, in which case every
 * stored condition is shown as it is, unverified, and none is called unavailable.
 *
 * @param {Array|null} groups   `groups` from `normaliseVocabulary()`, or null.
 * @param {string[]}   selected The stored conditions.
 * @param {Object}     options  `strings`.
 * @return {{stray: Array, groups: Array}} Rows outside any group, and the groups.
 */
export function conditionChoices( groups, selected, options ) {
	const known = Array.isArray( groups );
	const copy = options && options.strings ? options.strings : DEFAULT_STRINGS;
	const chosen = unique( strings( selected ) );
	const offered = {};

	( known ? groups : [] ).forEach( ( group ) => {
		group.options.forEach( ( option ) => {
			offered[ option.value ] = true;
		} );
	} );

	const stray = chosen
		.filter( ( value ) => ! Object.prototype.hasOwnProperty.call( offered, value ) )
		.map( ( value ) => ( {
			value,
			label: known ? format( copy.unavailable, value ) : value,
			help: '',
			checked: true,
			status: known ? 'unavailable' : 'unverified',
		} ) );

	return {
		stray,
		groups: ( known ? groups : [] ).map( ( group ) => ( {
			provider: group.provider,
			label: group.label,
			rows: group.options.map( ( option ) => ( {
				value: option.value,
				label: option.label,
				help: '',
				checked: chosen.indexOf( option.value ) !== -1,
				status: 'available',
			} ) ),
		} ) ),
	};
}

/**
 * The "Show instead" choices.
 *
 * A chosen post that is not offered (restricted since, or past the server's
 * list limit) is kept in the list and selected, so the control shows what is
 * stored instead of silently displaying "Nothing".
 *
 * @param {Array}  fallbacks `fallbacks` from `normaliseVocabulary()`.
 * @param {number} current   The stored post id, 0 for none.
 * @param {Object} options   `strings`.
 * @return {Array} `{value, label}` choices, "Nothing" first.
 */
export function fallbackChoices( fallbacks, current, options ) {
	const copy = options && options.strings ? options.strings : DEFAULT_STRINGS;
	const list = Array.isArray( fallbacks ) ? fallbacks : [];
	const choices = [ { value: '', label: copy.nothing } ];

	if ( current > 0 && ! list.some( ( entry ) => entry.id === current ) ) {
		choices.push( { value: String( current ), label: format( copy.fallbackMissing, '#' + current ) } );
	}

	list.forEach( ( entry ) => {
		choices.push( { value: String( entry.id ), label: entry.title } );
	} );

	return choices;
}
