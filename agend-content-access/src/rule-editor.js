/**
 * The rule picker half of every Agend Access panel: the chips, the search, Match and
 * Reverse, and the notices about the list of rules.
 *
 * The block's sidebar panel (access-panel.js) and the page's panel (page-editor.js)
 * are both built from this, so a rule is chosen, shown and removed the same way
 * wherever it is set. What differs between them is passed in: the words (a block and
 * a page are not the same thing to an author) and what sits below the rule list,
 * which is a block's replacement content and a page's denial setting.
 *
 * Every decision about what to write is in access-model.mjs or page-model.mjs, and
 * what to list, label and search is in rule-vocabulary.mjs. This renders the state
 * it is given and passes each change to the handler it is handed.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useRef } from '@wordpress/element';
import { SelectControl, ToggleControl, Notice, Spinner, Button } from '@wordpress/components';
import RuleChips from './rule-chips';
import RulePicker from './rule-picker';
import { useAgendVocabulary } from './session-lists';
import { copy } from './strings';
import { normaliseVocabulary, buildIndex, describeRules } from './rule-vocabulary.mjs';

/**
 * The vocabulary and its index, read once per response and kept for the editor
 * session. A panel mounts afresh for each block selected, and indexing about 800
 * entitlements on every selection would be work with nothing new to show.
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
 * The session's list of rules, read, with what a panel needs to know about it.
 *
 * A response that is not the documented shape is a failed load, the same as a 404.
 *
 * @return {{vocabulary: ?Object, index: ?Object, pending: boolean, failed: boolean, retry: Function, strings: Object}}
 *         `vocabulary` and `index` are null until the list has loaded.
 */
export function useRuleVocabulary() {
	const { vocabulary: list, retry, refresh } = useAgendVocabulary();
	const strings = copy();
	const { vocabulary, index } = list.status === 'ready' ? readVocabulary( list.data, strings ) : { vocabulary: null, index: null };

	return {
		vocabulary,
		index,
		pending: list.status === 'idle' || list.status === 'loading',
		failed: list.status === 'failed' || ( list.status === 'ready' && vocabulary === null ),
		retry,
		refresh,
		refreshing: list.refreshing === true,
		refreshFailed: list.refreshFailed === true,
		strings,
	};
}

/**
 * "Refresh list", for under a select of pages: the lists are read once when the editor
 * opens, so a page created since is not in them until this asks the server again.
 *
 * @param {Object} props
 * @param {Object} props.lists From `useRuleVocabulary()`.
 */
export function RefreshList( { lists } ) {
	if ( ! lists.vocabulary ) {
		return null;
	}

	return (
		<p className="agend-access__refresh">
			<Button variant="link" onClick={ lists.refresh } disabled={ lists.refreshing }>
				{ lists.refreshing ? __( 'Refreshing list', 'agend-content-access' ) : __( 'Refresh list', 'agend-content-access' ) }
			</Button>
			{ lists.refreshFailed && ! lists.refreshing && (
				<span className="agend-access__help">{ ' ' + __( 'The list could not be refreshed.', 'agend-content-access' ) }</span>
			) }
		</p>
	);
}

/**
 * The failure notice.
 *
 * @param {Object}   props
 * @param {string}   props.text  What went wrong, for this kind of panel.
 * @param {Function} props.retry Asks the loader again.
 */
export function LoadFailed( { text, retry } ) {
	return (
		<Notice status="warning" isDismissible={ false } className="agend-access__notice">
			{ text }{ ' ' }
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
 * The panel shown for a setting it cannot read: what it is, and one way out.
 *
 * @param {Object}   props
 * @param {string}   props.text    Why the setting is hidden from everyone, for this kind of panel.
 * @param {Object}   props.strings Copy.
 * @param {Function} props.onReset Clears the setting.
 */
export function UnreadableSetting( { text, strings, onReset } ) {
	return (
		<>
			<Notice status="warning" isDismissible={ false } className="agend-access__notice">
				{ text }
			</Notice>
			<ul className="agend-access__chips">
				<li className="agend-access__chip agend-access__chip--unavailable">
					<span className="agend-access__chip-label">{ strings.unreadable }</span>
				</li>
			</ul>
			<Button variant="secondary" onClick={ onReset }>
				{ __( 'Reset access setting', 'agend-content-access' ) }
			</Button>
		</>
	);
}

/**
 * The rules, and the choices that depend on there being some.
 *
 * @param {Object}   props
 * @param {Object}   props.lists      From `useRuleVocabulary()`.
 * @param {Object}   props.state      The panel's state: `rules`, `match`, `invert`.
 * @param {Object}   props.words      What this kind of panel says:
 *                                    `heading`, `emptyHelp`, `loadFailed`, `invertHelp`.
 * @param {Function} props.onAdd      `( rule ) => void`.
 * @param {Function} props.onRemove   `( rule ) => void`.
 * @param {Function} props.onMatch    `( 'any' | 'all' ) => void`.
 * @param {Function} props.onInvert   `( boolean ) => void`.
 * @param {*}        [props.children] Rendered once there is at least one rule, under Reverse.
 */
export default function RuleEditor( { lists, state, words, onAdd, onRemove, onMatch, onInvert, children } ) {
	const pickerRef = useRef( null );
	const { vocabulary, index, pending, failed, retry, strings } = lists;
	const count = state.rules.length;

	const focusSearch = () => {
		const box = pickerRef.current ? pickerRef.current.querySelector( 'input' ) : null;

		if ( box ) {
			box.focus();
		}
	};

	return (
		<>
			<h3 className="agend-access__heading">{ words.heading }</h3>
			<RuleChips chips={ describeRules( state.rules, index, strings ) } strings={ strings } onRemove={ onRemove } onEmptied={ focusSearch } />
			{ count === 0 && ! pending && ! failed && <p className="agend-access__help">{ words.emptyHelp }</p> }
			{ pending && (
				<p className="agend-access__loading">
					<Spinner /> { __( 'Loading rules', 'agend-content-access' ) }
				</p>
			) }
			{ failed && <LoadFailed text={ words.loadFailed } retry={ retry } /> }
			{ index && vocabulary && (
				<RulePicker
					index={ index }
					rules={ state.rules }
					strings={ strings }
					onAdd={ onAdd }
					notices={ <VocabularyNotices vocabulary={ vocabulary } /> }
					searchRef={ pickerRef }
				/>
			) }

			{ count > 1 && (
				<SelectControl
					label={ __( 'Match', 'agend-content-access' ) }
					value={ state.match }
					options={ [
						{ value: 'any', label: __( 'Any of these', 'agend-content-access' ) },
						{ value: 'all', label: __( 'All of these', 'agend-content-access' ) },
					] }
					onChange={ onMatch }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }

			{ count > 0 && (
				<>
					<ToggleControl
						label={ __( 'Show to everyone except these people', 'agend-content-access' ) }
						help={ words.invertHelp }
						checked={ state.invert }
						onChange={ onInvert }
						__nextHasNoMarginBottom
					/>
					{ children }
				</>
			) }
		</>
	);
}
