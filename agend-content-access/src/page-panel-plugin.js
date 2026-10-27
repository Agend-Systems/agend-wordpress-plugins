/**
 * The page's Agend Access panel in the block editor: a native document settings
 * panel, built from the same components as the block's sidebar panel.
 *
 * The setting travels as the REST field `agend_access_rules`, which exists in the
 * `edit` context only (`Agend_Content_Access_Page_Rules`). It is read from the
 * edited post, written with `editPost()` and sent with the post when the author
 * clicks Update, so it is saved, undone and shown as unsaved like anything else about
 * the page. It is sent only when the author edited it: opening a page, and saving
 * one without touching the panel, never rewrite it.
 *
 * A post type the server does not offer the panel on has no such field in its
 * record, and gets no panel. The meta box that is the same panel in the classic
 * editor is hidden here (`__back_compat_meta_box`), so an author sees one.
 */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import PageEditor from './page-editor';
import { REST_FIELD } from './page-model.mjs';

/**
 * The panel component WordPress offers to a plugin for the document's settings.
 * `@wordpress/editor` has had it since 6.6; before that it was in the edit-post
 * package, which is still on the page. Neither is imported by name, so a site on
 * either gets the panel and the script loads nothing it does not need.
 */
function documentPanel() {
	if ( PluginDocumentSettingPanel ) {
		return PluginDocumentSettingPanel;
	}

	const editPost = typeof window !== 'undefined' && window.wp ? window.wp.editPost : null;

	return editPost && editPost.PluginDocumentSettingPanel ? editPost.PluginDocumentSettingPanel : null;
}

function PageAccessDocumentPanel() {
	const { value, saveError } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const core = select( 'core' );
		const type = editor.getCurrentPostType();
		const id = editor.getCurrentPostId();

		return {
			value: editor.getEditedPostAttribute( REST_FIELD ),
			// The error object itself, which is the same object until the next save,
			// so the panel can tell a new refusal from one it has already shown.
			saveError: type && id && core.getLastEntitySaveError ? core.getLastEntitySaveError( 'postType', type, id ) : null,
		};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );
	const Panel = documentPanel();

	if ( ! Panel || value === undefined ) {
		return null;
	}

	return (
		<Panel name="agend-access" title={ __( 'Agend Access', 'agend-content-access' ) } className="agend-access-page-panel">
			<PageEditor stored={ value } saveError={ saveError } onCommit={ ( payload ) => editPost( { [ REST_FIELD ]: payload } ) } />
		</Panel>
	);
}

registerPlugin( 'agend-content-access-page', { render: PageAccessDocumentPanel } );
