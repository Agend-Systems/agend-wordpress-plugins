/**
 * The "Agend Access" sidebar panel: ONE control, a searchable rule picker.
 *
 * A block is shown to everyone who can see the page until it has rules. Rules
 * are chosen from one search box (a plan, a segment, an entitlement, a
 * WordPress role, signed in or out, any current member) and kept as chips; with
 * two or more the author picks Any or All, and with one or more they can
 * reverse the result and choose public content to show instead. The server
 * checks the rules before the block renders, so a visitor who is not allowed
 * never receives it.
 *
 * Every decision about what to write is in access-model.mjs, and what to list,
 * label and search is in rule-vocabulary.mjs. This file renders the state it
 * reads and passes each change through the matching setter.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';
import { PanelBody, SelectControl, ComboboxControl, ToggleControl, Notice, Spinner, Button } from '@wordpress/components';
import RuleChips from './rule-chips';
import RulePicker from './rule-picker';
import { useAgendVocabulary } from './session-lists';
import { copy } from './strings';
import { summarise } from './access-summary.mjs';
import {
	ATTRIBUTE,
	MATCH,
	normalise,
	isRestricted,
	addRule,
	removeRule,
	setMatch,
	setInvert,
	setFallback,
	resetAccess,
} from './access-model.mjs';
import { normaliseVocabulary, buildIndex, describeRules, fallbackChoices, filterChoices } from './rule-vocabulary.mjs';

/** Past this many choices "Show instead" becomes a searchable select. */
const LONG_LIST = 10;

/**
 * The vocabulary and its index, read once per response and kept for the editor
 * session. The panel mounts afresh for each block selected, and indexing about
 * 800 entitlements on every selection would be work with nothing new to show.
 */
const readings = new WeakMap();

function readVocabulary( data, strings ) {
	if ( data === null || typeof data !== 'object' ) {
		return { vocabulary: null, index: null };
	}

	if ( ! readings.has( data ) ) {
		const vocabulary = normaliseVocabulary( data );

		readings.set( data, { vocabulary, index: buildIndex( vocabulary, strings ) } );
	}

	return readings.get( data );
}

/**
 * The failure notice.
 *
 * @param {Object}   props
 * @param {Function} props.retry Asks the loader again.
 */
function LoadFailed( { retry } ) {
	return (
		<Notice status="warning" isDismissible={ false } className="agend-access__notice">
			{ __( 'The list of rules could not be loaded. Rules this block already holds are kept and shown above, and you can still remove them.', 'agend-content-access' ) }{ ' ' }
			<Button variant="link" onClick={ retry }>
				{ __( 'Try again', 'agend-content-access' ) }
			</Button>
		</Notice>
	);
}

/**
 * What the vocabulary says about itself, for under the search box: a list that
 * failed (for example the entitlements scope message) and a list that is out of
 * date.
 *
 * @param {Object} props
 * @param {Object} props.vocabulary From `normaliseVocabulary()`.
 */
function VocabularyNotices( { vocabulary } ) {
	const failed = {};

	vocabulary.errors.forEach( ( error ) => {
		failed[ error.group ] = true;
	} );

	return (
		<>
			{ vocabulary.errors.map( ( error, position ) => (
				<Notice key={ 'error-' + position } status="warning" isDismissible={ false } className="agend-access__notice">
					{ error.message }
				</Notice>
			) ) }
			{ vocabulary.groups
				.filter( ( group ) => group.stale && ! failed[ group.key ] )
				.map( ( group ) => (
					<Notice key={ 'stale-' + group.key } status="info" isDismissible={ false } className="agend-access__notice">
						{ sprintf(
							/* translators: %s: the name of a list of rules, for example Segments. */
							__( 'The %s list could not be refreshed and may be out of date.', 'agend-content-access' ),
							group.label
						) }
					</Notice>
				) ) }
		</>
	);
}

/**
 * Show instead: a plain select, or a searchable one when the list is long.
 */
function ShowInstead( { choices, current, onChange } ) {
	const [ filter, setFilter ] = useState( '' );
	const help = __( 'Optional. Shown in place of this block to people who are not allowed. Only public content can be chosen.', 'agend-content-access' );
	const label = __( 'Show instead', 'agend-content-access' );
	const value = current > 0 ? String( current ) : '';

	if ( choices.length <= LONG_LIST ) {
		return (
			<SelectControl
				label={ label }
				help={ help }
				value={ value }
				options={ choices }
				onChange={ onChange }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		);
	}

	const shown = filterChoices( choices, filter );
	const selected = choices.find( ( choice ) => choice.value === value );

	// The chosen one stays in the list whatever was typed, so the control can
	// always say what is stored.
	if ( selected && ! shown.some( ( choice ) => choice.value === value ) ) {
		shown.unshift( selected );
	}

	return (
		<ComboboxControl
			label={ label }
			help={ help }
			value={ value }
			options={ shown }
			onFilterValueChange={ setFilter }
			onChange={ ( id ) => onChange( id || '' ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.attributes    The selected block's attributes.
 * @param {Function} props.setAttributes The selected block's setter.
 */
export default function AccessPanel( { attributes, setAttributes } ) {
	const { vocabulary: list, retry } = useAgendVocabulary();
	const pickerRef = useRef( null );
	const strings = copy();
	const stored = attributes[ ATTRIBUTE ];
	const state = normalise( stored );
	// `next` is `undefined` when nothing is left to store. Passing it through is
	// what removes the attribute, so the block saves with no `agendAccess`.
	const write = ( next ) => setAttributes( { [ ATTRIBUTE ]: next } );

	// A response that is not the documented shape is a failed load, the same as
	// a 404.
	const { vocabulary, index } = list.status === 'ready' ? readVocabulary( list.data, strings ) : { vocabulary: null, index: null };
	const pending = list.status === 'idle' || list.status === 'loading';
	const failed = list.status === 'failed' || ( list.status === 'ready' && vocabulary === null );
	const count = state.rules.length;

	const focusSearch = () => {
		const box = pickerRef.current ? pickerRef.current.querySelector( 'input' ) : null;

		if ( box ) {
			box.focus();
		}
	};

	return (
		<PanelBody title={ __( 'Agend Access', 'agend-content-access' ) } initialOpen={ isRestricted( stored ) }>
			<p className="agend-access__summary">{ summarise( state, strings ) }</p>

			{ state.unreadable && (
				<>
					<Notice status="warning" isDismissible={ false } className="agend-access__notice">
						{ __( 'This block holds an access setting this editor cannot read, so the site hides it from everyone. Reset it to start again.', 'agend-content-access' ) }
					</Notice>
					<ul className="agend-access__chips">
						<li className="agend-access__chip agend-access__chip--unavailable">
							<span className="agend-access__chip-label">{ strings.unreadable }</span>
						</li>
					</ul>
					<Button variant="secondary" onClick={ () => write( resetAccess( stored ) ) }>
						{ __( 'Reset access setting', 'agend-content-access' ) }
					</Button>
				</>
			) }

			{ ! state.unreadable && (
				<>
					{ state.twoPart && (
						<Notice status="warning" isDismissible={ false } className="agend-access__notice">
							{ __( 'This block uses an older two-part setting. Saving changes here will replace it with the rules shown.', 'agend-content-access' ) }
						</Notice>
					) }

					<h3 className="agend-access__heading">{ __( 'Who can see this block', 'agend-content-access' ) }</h3>
					<RuleChips
						chips={ describeRules( state.rules, index, strings ) }
						strings={ strings }
						onRemove={ ( rule ) => write( removeRule( stored, rule ) ) }
						onEmptied={ focusSearch }
					/>
					{ count === 0 && ! pending && ! failed && (
						<p className="agend-access__help">{ __( 'No rules yet, so everyone who can see the page can see this block. Search below to add one.', 'agend-content-access' ) }</p>
					) }
					{ pending && (
						<p className="agend-access__loading">
							<Spinner /> { __( 'Loading rules', 'agend-content-access' ) }
						</p>
					) }
					{ failed && <LoadFailed retry={ retry } /> }
					{ index && vocabulary && (
						<RulePicker
							index={ index }
							rules={ state.rules }
							strings={ strings }
							onAdd={ ( rule ) => write( addRule( stored, rule ) ) }
							notices={ <VocabularyNotices vocabulary={ vocabulary } /> }
							searchRef={ pickerRef }
						/>
					) }

					{ count > 1 && (
						<SelectControl
							label={ __( 'Match', 'agend-content-access' ) }
							value={ state.match }
							options={ [
								{ value: MATCH.ANY, label: __( 'Any of these', 'agend-content-access' ) },
								{ value: MATCH.ALL, label: __( 'All of these', 'agend-content-access' ) },
							] }
							onChange={ ( match ) => write( setMatch( stored, match ) ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }

					{ count > 0 && (
						<>
							<ToggleControl
								label={ __( 'Show to everyone except these people', 'agend-content-access' ) }
								help={ __( 'Useful for a join banner that members should not see.', 'agend-content-access' ) }
								checked={ state.invert }
								onChange={ ( on ) => write( setInvert( stored, on ) ) }
								__nextHasNoMarginBottom
							/>
							<ShowInstead
								choices={ fallbackChoices( vocabulary ? vocabulary.fallbacks : [], state.fallbackId, strings ) }
								current={ state.fallbackId }
								onChange={ ( id ) => write( setFallback( stored, id ) ) }
							/>
							<p className="agend-access__status">{ __( 'Visitors who are not allowed never receive this block.', 'agend-content-access' ) }</p>
						</>
					) }
				</>
			) }
		</PanelBody>
	);
}
