/**
 * The page's Agend Access panel: the controls, for either editor to host.
 *
 * Two questions, answered in plain English:
 *
 *   Who can see this page      the rule picker the block panel uses (rule-editor.js):
 *                              chips, search, Match and Reverse, and a summary line.
 *   If someone isn't allowed   shown once there is a rule. Show a placeholder (the
 *                              default), redirect to a page, or send them back to
 *                              the page they came from.
 *
 * The block editor hosts this in a native document settings panel
 * (page-panel-plugin.js) and the classic editor in the meta box (classic.js). The
 * host gives it the stored value and a function to write the next one, and has no
 * other part in it, so the two editors cannot behave differently.
 *
 * WHAT IS WRITTEN. A change is written the moment it is complete, as the whole value
 * (`buildPayload()` in page-model.mjs). One that is not complete (a redirect with no
 * page chosen yet) is held here as a draft, shown with what is missing, and not
 * written, so it can never be saved half made and what is stored is kept until it is
 * finished. While a draft is held the host is told (`onIncomplete`), and locks saving,
 * so the editor cannot answer "Page updated" for a change it did not save. A stored
 * value that changes from outside, because the page was saved or an edit was undone,
 * replaces the draft, and the lock goes with it.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Notice, RadioControl } from '@wordpress/components';
import ChoiceSelect from './choice-select';
import RuleEditor, { RefreshList, UnreadableSetting, useRuleVocabulary } from './rule-editor';
import { summarise } from './access-summary.mjs';
import { fallbackChoices } from './rule-vocabulary.mjs';
import {
	ACTIONS,
	PAGE_TYPES,
	normalisePage,
	addRule,
	removeRule,
	setMatch,
	setInvert,
	setAction,
	setTarget,
	setFallback,
	resetPage,
	validatePage,
	buildPayload,
	savedRefusal,
} from './page-model.mjs';

/**
 * What happens to somebody who is not allowed.
 *
 * @param {Object}   props
 * @param {Object}   props.state     The panel's state.
 * @param {Object}   props.lists     From `useRuleVocabulary()`.
 * @param {Array}    props.fallbacks `fallbacks` from the vocabulary: public pages, patterns and templates.
 * @param {Object}   props.strings   Copy.
 * @param {Object}   props.errors    From `validatePage()`.
 * @param {Function} props.change    `( nextState ) => void`.
 */
function DeniedControls( { state, lists, fallbacks, strings, errors, change } ) {
	const { denied } = state;

	return (
		<>
			<h3 className="agend-access__heading">{ __( 'If someone isn\'t allowed', 'agend-content-access' ) }</h3>
			<RadioControl
				label={ __( 'If someone isn\'t allowed', 'agend-content-access' ) }
				hideLabelFromVision
				selected={ denied.action }
				options={ [
					{ value: ACTIONS.PLACEHOLDER, label: __( 'Show a placeholder', 'agend-content-access' ) },
					{ value: ACTIONS.REDIRECT, label: __( 'Redirect to a page', 'agend-content-access' ) },
					{ value: ACTIONS.BACK, label: __( 'Send them back to the page they came from', 'agend-content-access' ) },
				] }
				onChange={ ( action ) => change( setAction( state, action ) ) }
			/>

			{ denied.action === ACTIONS.PLACEHOLDER && (
				<ChoiceSelect
					key="placeholder"
					label={ __( 'Placeholder', 'agend-content-access' ) }
					help={ __( 'Shown in place of this page, on the same address. The built-in one explains why and offers sign in or join. Only public content can be chosen.', 'agend-content-access' ) }
					choices={ fallbackChoices( fallbacks, denied.target_id, strings, { none: strings.builtInPlaceholder } ) }
					current={ denied.target_id }
					onChange={ ( id ) => change( setTarget( state, id ) ) }
				/>
			) }

			{ denied.action === ACTIONS.REDIRECT && (
				<>
					<ChoiceSelect
						key="redirect"
						label={ __( 'Page to redirect to', 'agend-content-access' ) }
						choices={ fallbackChoices( fallbacks, denied.target_id, strings, { types: PAGE_TYPES, none: strings.choosePage } ) }
						current={ denied.target_id }
						onChange={ ( id ) => change( setTarget( state, id ) ) }
					/>
					{ errors.target && (
						<Notice status="warning" isDismissible={ false } className="agend-access__notice">
							{ errors.target }
						</Notice>
					) }
				</>
			) }

			{ denied.action === ACTIONS.BACK && (
				<ChoiceSelect
					key="back"
					label={ __( 'Fallback page', 'agend-content-access' ) }
					help={ __( 'If they did not come from this site, they go here instead.', 'agend-content-access' ) }
					choices={ fallbackChoices( fallbacks, denied.fallback_id, strings, { types: PAGE_TYPES, none: strings.noFallbackPage } ) }
					current={ denied.fallback_id }
					onChange={ ( id ) => change( setFallback( state, id ) ) }
				/>
			) }

			<RefreshList lists={ lists } />
		</>
	);
}

/**
 * @param {Object}   props
 * @param {*}        props.stored      The stored value: what the server gave, or what this panel last wrote.
 * @param {Function} props.onCommit    `( payload ) => void`, the whole value to save.
 * @param {*}        [props.saveError] The error the editor holds for the last save of the page. The
 *                                     server's refusal of this panel's value is shown from it, until
 *                                     the author changes the value again.
 * @param {Function} [props.onIncomplete] `( incomplete: boolean ) => void`, true while a choice
 *                                     is half made, false when it is finished, undone or the panel
 *                                     goes away.
 */
export default function PageEditor( { stored, onCommit, saveError = null, onIncomplete = null } ) {
	const lists = useRuleVocabulary();
	const { vocabulary, strings } = lists;
	const base = useMemo( () => normalisePage( stored ), [ stored ] );
	const [ draft, setDraft ] = useState( null );
	const [ seen, setSeen ] = useState( { error: null, signature: '' } );
	const state = draft || base;
	const check = validatePage( state, strings );
	const signature = JSON.stringify( stored === undefined ? null : stored );

	// The stored value changed from outside, or this panel changed it: either way
	// it is the truth, and a half made choice is not.
	useEffect( () => {
		setDraft( null );
	}, [ signature ] );

	// Tell the host while a choice is half made, so it can stop the page being saved
	// without it. The panel goes away when it is collapsed, and its draft with it, so
	// leaving counts as finished.
	const incomplete = draft !== null;
	const tell = useRef( onIncomplete );

	tell.current = onIncomplete;

	useEffect( () => {
		if ( tell.current ) {
			tell.current( incomplete );
		}

		return () => {
			if ( tell.current ) {
				tell.current( false );
			}
		};
	}, [ incomplete ] );

	// A refusal is about the value that was sent. Once the author has changed it the
	// message is about something that is gone, so it is only shown for the value
	// that was current when the error arrived.
	useEffect( () => {
		setSeen( { error: saveError, signature } );
	}, [ saveError ] );

	const refusalText = savedRefusal( saveError );
	const refusal = refusalText && seen.error === saveError && seen.signature === signature ? refusalText : '';

	const change = ( next ) => {
		if ( next === state ) {
			return;
		}

		if ( validatePage( next, strings ).ok ) {
			setDraft( null );
			onCommit( buildPayload( next ) );
		} else {
			setDraft( next );
		}
	};

	const words = {
		heading: __( 'Who can see this page', 'agend-content-access' ),
		emptyHelp: __( 'No rules yet, so everyone can see this page. Search below to add one.', 'agend-content-access' ),
		loadFailed: __( 'The list of rules could not be loaded. Rules this page already holds are kept and shown above, and you can still remove them.', 'agend-content-access' ),
		invertHelp: __( 'Useful for a join page that members should be redirected away from.', 'agend-content-access' ),
	};

	return (
		<div className="agend-access-page">
			<p className="agend-access__summary">{ summarise( state, strings, true ) }</p>

			{ refusal && (
				<Notice status="error" isDismissible={ false } className="agend-access__notice">
					{ refusal }
				</Notice>
			) }

			{ state.unreadable && (
				<UnreadableSetting
					text={ __( 'This page holds an access setting this editor cannot read, so the site hides it from everyone. Reset it to start again.', 'agend-content-access' ) }
					strings={ strings }
					onReset={ () => {
						setDraft( null );
						onCommit( buildPayload( resetPage() ) );
					} }
				/>
			) }

			{ ! state.unreadable && (
				<>
					{ state.legacy && (
						<Notice status="info" isDismissible={ false } className="agend-access__notice">
							{ __( 'This page uses an older setting, shown here as rules. Saving will update it.', 'agend-content-access' ) }
						</Notice>
					) }

					<RuleEditor
						lists={ lists }
						state={ state }
						words={ words }
						onAdd={ ( rule ) => change( addRule( state, rule ) ) }
						onRemove={ ( rule ) => change( removeRule( state, rule ) ) }
						onMatch={ ( match ) => change( setMatch( state, match ) ) }
						onInvert={ ( on ) => change( setInvert( state, on ) ) }
					>
						<DeniedControls
							state={ state }
							lists={ lists }
							fallbacks={ vocabulary ? vocabulary.fallbacks : [] }
							strings={ strings }
							errors={ check.errors }
							change={ change }
						/>
					</RuleEditor>
				</>
			) }
		</div>
	);
}
