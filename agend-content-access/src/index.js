/**
 * Agend Access for every block: the attribute, the sidebar panel, and a mark
 * on the canvas for blocks that carry a policy or display conditions.
 *
 * The three cooperating filters the block editor offers for this (see the
 * review in docs/REVIEW-content-access-editor-agnostic.md, section 4):
 *
 *   blocks.registerBlockType   adds the `agendAccess` attribute to a block type.
 *   editor.BlockEdit           renders the panel for the selected block.
 *   editor.BlockListBlock      marks restricted blocks on the canvas.
 *
 * `save()` is never touched. The attribute is a non-sourced object whose
 * default is `{}`, so it is written into the block's comment delimiter only
 * when an author gave it a value, and every block that predates it parses and
 * saves exactly as before.
 *
 * The PHP twin, `Agend_Content_Access_Block_Editor`, registers the same
 * attribute on the server so dynamic blocks and the block renderer accept it.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import AccessPanel from './access-panel';
import { ATTRIBUTE, withAccessAttribute, carriesAttribute, indicatorKind } from './access-model.mjs';
import './editor.css';

addFilter( 'blocks.registerBlockType', 'agend-content-access/attribute', withAccessAttribute );

/**
 * The panel, for the selected block only.
 *
 * `editor.BlockEdit` runs for every block, so nothing here may cost anything
 * for a block that is not selected: the check is first and returns the block
 * untouched. The panel is also only offered where the attribute is declared. A
 * block registered before this script ran never received it, and an attribute
 * set on such a block would be accepted by the editor and then dropped on save.
 */
const withAccessPanel = createHigherOrderComponent(
	( BlockEdit ) =>
		function WithAgendAccessPanel( props ) {
			if ( ! props.isSelected || ! carriesAttribute( getBlockType( props.name ) ) ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<>
					<BlockEdit { ...props } />
					<InspectorControls>
						<AccessPanel attributes={ props.attributes } setAttributes={ props.setAttributes } />
					</InspectorControls>
				</>
			);
		},
	'withAgendAccessPanel'
);

addFilter( 'editor.BlockEdit', 'agend-content-access/panel', withAccessPanel );

/**
 * A class on blocks that carry a policy or conditions, so an author can see
 * restricted blocks at a glance. The CSS is in editor.css.
 */
const withAccessIndicator = createHigherOrderComponent(
	( BlockListBlock ) =>
		function WithAgendAccessIndicator( props ) {
			const kind = indicatorKind( props.attributes ? props.attributes[ ATTRIBUTE ] : undefined );

			if ( kind === '' ) {
				return <BlockListBlock { ...props } />;
			}

			const className = [ props.className, 'has-agend-access', 'has-agend-access--' + kind ].filter( Boolean ).join( ' ' );

			return <BlockListBlock { ...props } className={ className } />;
		},
	'withAgendAccessIndicator'
);

addFilter( 'editor.BlockListBlock', 'agend-content-access/indicator', withAccessIndicator );
