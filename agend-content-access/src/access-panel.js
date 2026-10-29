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
 * The picker itself is rule-editor.js, which the page's panel uses too. Every
 * decision about what to write is in access-model.mjs, and what to list, label and
 * search is in rule-vocabulary.mjs. This file renders the state it reads and passes
 * each change through the matching setter.
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, Notice } from '@wordpress/components';
import ChoiceSelect from './choice-select';
import RuleEditor, { RefreshList, UnreadableSetting, useRuleVocabulary } from './rule-editor';
import { summarise } from './access-summary.mjs';
import {
	ATTRIBUTE,
	normalise,
	isRestricted,
	addRule,
	removeRule,
	setMatch,
	setInvert,
	setFallback,
	resetAccess,
} from './access-model.mjs';
import { fallbackChoices } from './rule-vocabulary.mjs';

/**
 * @param {Object}   props
 * @param {Object}   props.attributes    The selected block's attributes.
 * @param {Function} props.setAttributes The selected block's setter.
 */
export default function AccessPanel( { attributes, setAttributes } ) {
	const lists = useRuleVocabulary();
	const { vocabulary, strings } = lists;
	const stored = attributes[ ATTRIBUTE ];
	const state = normalise( stored );
	// `next` is `undefined` when nothing is left to store. Passing it through is
	// what removes the attribute, so the block saves with no `agendAccess`.
	const write = ( next ) => setAttributes( { [ ATTRIBUTE ]: next } );

	const words = {
		heading: __( 'Who can see this block', 'agend-content-access' ),
		emptyHelp: __( 'No rules yet, so everyone who can see the page can see this block. Search below to add one.', 'agend-content-access' ),
		loadFailed: __( 'The list of rules could not be loaded. Rules this block already holds are kept and shown above, and you can still remove them.', 'agend-content-access' ),
		invertHelp: __( 'Useful for a join banner that members should not see.', 'agend-content-access' ),
	};

	return (
		<PanelBody title={ __( 'Agend Access', 'agend-content-access' ) } initialOpen={ isRestricted( stored ) }>
			<p className="agend-access__summary">{ summarise( state, strings ) }</p>

			{ state.unreadable && (
				<UnreadableSetting
					text={ __( 'This block holds an access setting this editor cannot read, so the site hides it from everyone. Reset it to start again.', 'agend-content-access' ) }
					strings={ strings }
					onReset={ () => write( resetAccess( stored ) ) }
				/>
			) }

			{ ! state.unreadable && (
				<>
					{ state.twoPart && (
						<Notice status="warning" isDismissible={ false } className="agend-access__notice">
							{ __( 'This block uses an older two-part setting. Saving changes here will replace it with the rules shown.', 'agend-content-access' ) }
						</Notice>
					) }

					<RuleEditor
						lists={ lists }
						state={ state }
						words={ words }
						onAdd={ ( rule ) => write( addRule( stored, rule ) ) }
						onRemove={ ( rule ) => write( removeRule( stored, rule ) ) }
						onMatch={ ( match ) => write( setMatch( stored, match ) ) }
						onInvert={ ( on ) => write( setInvert( stored, on ) ) }
					>
						<ChoiceSelect
							label={ __( 'Show instead', 'agend-content-access' ) }
							help={ __( 'Optional. Shown in place of this block to people who are not allowed. Only public content can be chosen.', 'agend-content-access' ) }
							choices={ fallbackChoices( vocabulary ? vocabulary.fallbacks : [], state.fallbackId, strings ) }
							current={ state.fallbackId }
							onChange={ ( id ) => write( setFallback( stored, id ) ) }
						/>
						<RefreshList lists={ lists } />
						<p className="agend-access__status">{ __( 'Visitors who are not allowed never receive this block.', 'agend-content-access' ) }</p>
					</RuleEditor>
				</>
			) }
		</PanelBody>
	);
}
