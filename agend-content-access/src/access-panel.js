/**
 * The "Agend Access" sidebar panel.
 *
 * Mirrors the Elementor panel in the same order and wording
 * (includes/class-agend-content-access-elementor.php): who can see this, the
 * list that goes with it, then display conditions. The block editor's panel
 * additionally offers segments and entitlements.
 *
 * Every decision about what to write is in access-model.mjs. This file renders
 * the state it reads and passes each change through the matching setter.
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, ToggleControl, Notice, Spinner, Button } from '@wordpress/components';
import Checklist from './checklist';
import { useAgendLists } from './session-lists';
import {
	ATTRIBUTE,
	MODE,
	LIST_KEY,
	MATCH,
	UNRECOGNISED,
	normalise,
	indicatorKind,
	setMode,
	setList,
	setConditionsEnabled,
	setConditions,
	setMatch,
	setInvert,
	setFallback,
	toggleValue,
	sectionState,
	planItems,
	segmentItems,
	entitlementItems,
	checklistRows,
	normaliseVocabulary,
	conditionChoices,
	fallbackChoices,
} from './access-model.mjs';

/**
 * Copy composed by the model, translated here so it is extracted for
 * translation: the model has no WordPress imports and cannot call `__()`.
 *
 * @return {Object} Strings in the shape DEFAULT_STRINGS has.
 */
function copy() {
	return {
		/* translators: %s: platform capability name, for example Directory access. */
		platform: __( 'Platform: %s', 'agend-content-access' ),
		/* translators: %s: the stored id or key of a choice that is no longer offered. */
		unavailable: __( '%s (unavailable)', 'agend-content-access' ),
		/* translators: %s: membership plan name. */
		inactivePlan: __( '%s (no longer active)', 'agend-content-access' ),
		/* translators: %s: post id of replacement content that is not in the list, for example #12. */
		fallbackMissing: __( 'Content %s (not in the list)', 'agend-content-access' ),
		nothing: __( 'Nothing', 'agend-content-access' ),
	};
}

/**
 * What each set-mode lists, where it comes from, and what it says about it.
 *
 * @return {Object} Keyed by mode.
 */
function audiences() {
	return {
		[ MODE.TIERS ]: {
			section: 'plans',
			read: planItems,
			label: __( 'Membership plans', 'agend-content-access' ),
			help: __( 'Members on any one of these plans can see this block. Selecting none hides it from everybody.', 'agend-content-access' ),
			empty: __( 'There are no membership plans to choose from.', 'agend-content-access' ),
		},
		[ MODE.SEGMENTS ]: {
			section: 'segments',
			read: segmentItems,
			label: __( 'Segments', 'agend-content-access' ),
			help: __( 'Visitors in any one of these segments can see this block. Selecting none hides it from everybody.', 'agend-content-access' ),
			empty: __( 'There are no segments to choose from.', 'agend-content-access' ),
		},
		[ MODE.ENTITLEMENTS ]: {
			section: 'entitlements',
			read: entitlementItems,
			label: __( 'Entitlements', 'agend-content-access' ),
			help: __( 'Visitors holding any one of these entitlements can see this block. Selecting none hides it from everybody.', 'agend-content-access' ),
			empty: __( 'There are no entitlements to choose from.', 'agend-content-access' ),
		},
	};
}

function isPending( list ) {
	return list.status === 'idle' || list.status === 'loading';
}

/**
 * The failure notice, shared by both lists.
 *
 * @param {Object}   props
 * @param {Function} props.retry Asks the loader again.
 */
function LoadFailed( { retry } ) {
	return (
		<Notice status="warning" isDismissible={ false } className="agend-access__notice">
			{ __( 'The lists could not be loaded. What this block already holds is kept and shown below, and you can still clear it.', 'agend-content-access' ) }{ ' ' }
			<Button variant="link" onClick={ retry }>
				{ __( 'Try again', 'agend-content-access' ) }
			</Button>
		</Notice>
	);
}

/**
 * The checklist for the block's current set-mode.
 */
function AudienceList( { mode, state, lists, strings, write, stored } ) {
	const audience = audiences()[ mode ];
	const key = LIST_KEY[ mode ];
	const catalogue = lists.catalogue;
	const ready = catalogue.status === 'ready';
	const section = sectionState( ready && catalogue.data ? catalogue.data[ audience.section ] : null );
	const rows = checklistRows( audience.read( ready && catalogue.data ? catalogue.data[ audience.section ] : null ), state[ key ], {
		known: ready && section.known,
		strings,
	} );

	return (
		<>
			{ isPending( catalogue ) && <Spinner /> }
			{ section.error !== '' && (
				<Notice status="warning" isDismissible={ false } className="agend-access__notice">
					{ section.error }
				</Notice>
			) }
			{ section.stale && section.error === '' && (
				<Notice status="info" isDismissible={ false } className="agend-access__notice">
					{ __( 'This list could not be refreshed and may be out of date.', 'agend-content-access' ) }
				</Notice>
			) }
			<Checklist
				label={ audience.label }
				help={ audience.help }
				rows={ rows }
				empty={ ready && section.known ? audience.empty : undefined }
				onToggle={ ( value, on ) => write( setList( stored, toggleValue( state[ key ], value, on ) ) ) }
			/>
		</>
	);
}

/**
 * Display conditions: the switch and, when it is on, everything it controls.
 */
function Conditions( { state, lists, vocabulary, strings, write, stored } ) {
	const choices = conditionChoices( vocabulary ? vocabulary.groups : null, state.conditions, { strings } );

	return (
		<>
			<ToggleControl
				label={ __( 'Add display conditions', 'agend-content-access' ) }
				help={ __(
					'Show or hide this block based on the visitor: their WordPress role, their membership, a segment or an entitlement. This is presentation, applied by this site. For content that must be protected, use the setting above, which Agend enforces.',
					'agend-content-access'
				) }
				checked={ state.conditionsEnabled }
				onChange={ ( on ) => write( setConditionsEnabled( stored, on ) ) }
				__nextHasNoMarginBottom
			/>
			{ state.conditionsEnabled && (
				<>
					{ isPending( lists.conditions ) && <Spinner /> }
					{ choices.stray.length > 0 && (
						<Checklist
							label={ __( 'Chosen conditions', 'agend-content-access' ) }
							rows={ choices.stray }
							onToggle={ ( value, on ) => write( setConditions( stored, toggleValue( state.conditions, value, on ) ) ) }
						/>
					) }
					{ choices.groups.map( ( group ) => (
						<Checklist
							key={ group.provider }
							label={ group.label }
							rows={ group.rows }
							empty={ __( 'Nothing to choose from. This list may be unavailable.', 'agend-content-access' ) }
							onToggle={ ( value, on ) => write( setConditions( stored, toggleValue( state.conditions, value, on ) ) ) }
						/>
					) ) }
					<p className="agend-access__help">{ __( 'Choosing none shows the block to everybody.', 'agend-content-access' ) }</p>
					<SelectControl
						label={ __( 'Match', 'agend-content-access' ) }
						value={ state.conditionsMatch }
						options={ [
							{ value: MATCH.ANY, label: __( 'Any of these', 'agend-content-access' ) },
							{ value: MATCH.ALL, label: __( 'All of these', 'agend-content-access' ) },
						] }
						onChange={ ( match ) => write( setMatch( stored, match ) ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Reverse the result', 'agend-content-access' ) }
						help={ __(
							'Show the block to everyone the conditions do NOT match. Use this for content aimed at signed-out visitors, or at people who are not yet members.',
							'agend-content-access'
						) }
						checked={ state.conditionsInvert }
						onChange={ ( on ) => write( setInvert( stored, on ) ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Show instead', 'agend-content-access' ) }
						help={ __(
							'Optional. Content to show to visitors the conditions exclude, for example a sign-in prompt. Only public content can be chosen: anything restricted would be shown to exactly the people who were just refused.',
							'agend-content-access'
						) }
						value={ state.fallbackId > 0 ? String( state.fallbackId ) : '' }
						options={ fallbackChoices( vocabulary ? vocabulary.fallbacks : [], state.fallbackId, { strings } ) }
						onChange={ ( id ) => write( setFallback( stored, id ) ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</>
			) }
		</>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.attributes    The selected block's attributes.
 * @param {Function} props.setAttributes The selected block's setter.
 */
export default function AccessPanel( { attributes, setAttributes } ) {
	const { lists, retry } = useAgendLists();
	const stored = attributes[ ATTRIBUTE ];
	const state = normalise( stored );
	const strings = copy();
	// `next` is `undefined` when nothing is left to store. Passing it through is
	// what removes the attribute, so the block saves with no `agendAccess`.
	const write = ( next ) => setAttributes( { [ ATTRIBUTE ]: next } );
	const unreadableMode = state.mode === UNRECOGNISED;

	// The conditions vocabulary, read once here so the panel can say in one
	// place that a list failed. A response that is not the documented shape is a
	// failed load, the same as a 404.
	const vocabulary = lists.conditions.status === 'ready' ? normaliseVocabulary( lists.conditions.data ) : null;
	const needsCatalogue = ! unreadableMode && !! LIST_KEY[ state.mode ];
	const needsConditions = ! unreadableMode && state.conditionsEnabled;
	const loadFailed =
		( needsCatalogue && lists.catalogue.status === 'failed' ) ||
		( needsConditions && ( lists.conditions.status === 'failed' || ( lists.conditions.status === 'ready' && vocabulary === null ) ) );

	const modeOptions = [
		{ value: MODE.INHERIT, label: __( 'Same as the page', 'agend-content-access' ) },
		{ value: MODE.MEMBERS, label: __( 'All active members', 'agend-content-access' ) },
		{ value: MODE.TIERS, label: __( 'Selected membership plans', 'agend-content-access' ) },
		{ value: MODE.SEGMENTS, label: __( 'Selected segments', 'agend-content-access' ) },
		{ value: MODE.ENTITLEMENTS, label: __( 'Selected entitlements', 'agend-content-access' ) },
	];

	if ( unreadableMode ) {
		modeOptions.unshift( {
			value: UNRECOGNISED,
			label: __( 'Unreadable setting (members only)', 'agend-content-access' ),
			disabled: true,
		} );
	}

	return (
		<PanelBody title={ __( 'Agend Access', 'agend-content-access' ) } initialOpen={ indicatorKind( stored ) !== '' }>
			{ state.unreadable && (
				<Notice status="warning" isDismissible={ false } className="agend-access__notice">
					{ unreadableMode
						? __(
							'This block holds an access setting this editor cannot read, so the site treats it as members only. Choose an option below to replace it.',
							'agend-content-access'
						)
						: __(
							'Some display conditions on this block could not be read, and the site hides the block because of them. Changing a setting here replaces them.',
							'agend-content-access'
						) }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Who can see this', 'agend-content-access' ) }
				help={ __(
					'A block can only narrow the page\'s audience, never widen it. If the page is already restricted, this block stays restricted too.',
					'agend-content-access'
				) }
				value={ state.mode }
				options={ modeOptions }
				onChange={ ( mode ) => write( setMode( stored, mode ) ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ loadFailed && <LoadFailed retry={ retry } /> }
			{ needsCatalogue && (
				<AudienceList mode={ state.mode } state={ state } lists={ lists } strings={ strings } write={ write } stored={ stored } />
			) }
			{ ! unreadableMode && (
				<Conditions state={ state } lists={ lists } vocabulary={ vocabulary } strings={ strings } write={ write } stored={ stored } />
			) }
		</PanelBody>
	);
}
