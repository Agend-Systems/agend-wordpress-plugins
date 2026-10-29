/**
 * The page's Agend Access panel in the classic editor, and on any edit screen that
 * is not the block editor.
 *
 * The meta box (`Agend_Content_Access_Meta_Box`) renders an empty mount point with
 * the page's setting on it, and an empty hidden input. This mounts the same
 * components the block editor's panel is built from, and writes what the author
 * chooses to that input as JSON. The input is empty until they change something, so
 * a save made without touching the panel, or while this script has not started,
 * submits nothing for it and keeps what is stored. The meta box reads the input when
 * the post is saved.
 *
 * It is its own small entry rather than part of the block editor's script because
 * the block editor's script needs the whole block editor, and a classic edit screen
 * has none of it.
 */
import { createRoot, render, useState } from '@wordpress/element';
import PageEditor from './page-editor';
import { INPUT_NAME } from './page-model.mjs';
import './editor.css';

const ROOT_ID = 'agend-access-page-root';

/** Whether a choice in the panel is half made, so the form is not submitted. */
let incompleteChoice = false;

/**
 * @param {Object}      props
 * @param {*}           props.initial The setting the page loaded with.
 * @param {HTMLElement} props.input   The hidden input the meta box reads.
 */
function ClassicPanel( { initial, input } ) {
	const [ stored, setStored ] = useState( initial );

	return (
		<PageEditor
			stored={ stored }
			onIncomplete={ ( incomplete ) => {
				incompleteChoice = incomplete;
			} }
			onCommit={ ( payload ) => {
				setStored( payload );
				input.value = JSON.stringify( payload );
			} }
		/>
	);
}

function start() {
	const root = document.getElementById( ROOT_ID );
	const input = document.querySelector( 'input[name="' + INPUT_NAME + '"]' );

	if ( ! root || ! input ) {
		return;
	}

	let config = null;

	try {
		config = JSON.parse( root.getAttribute( 'data-agend-access-page' ) || 'null' );
	} catch ( error ) {
		config = null;
	}

	// Without the setting the panel would show a page with no rules, and an author
	// could save that over what the page really holds. Leave the page's own
	// message in place and submit nothing.
	if ( ! config || typeof config !== 'object' || ! config.state ) {
		return;
	}

	const element = <ClassicPanel initial={ config.state } input={ input } />;

	// A half made choice is not written, so submitting now would save the old setting
	// and say the page was updated. The form is held until the choice is finished, and
	// the panel, which says what is missing, is brought into view.
	if ( input.form ) {
		input.form.addEventListener( 'submit', ( event ) => {
			if ( incompleteChoice ) {
				event.preventDefault();
				root.scrollIntoView( { block: 'center' } );
			}
		} );
	}

	// Enter in a text box submits the post form on a classic edit screen, and the
	// search box is one. The picker already uses Enter to add the first result; this
	// stops it saving the page when it has nothing to add.
	root.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Enter' && event.target && event.target.tagName === 'INPUT' ) {
			event.preventDefault();
		}
	} );

	// `createRoot` from WordPress 6.2, `render` before it.
	root.textContent = '';

	if ( typeof createRoot === 'function' ) {
		createRoot( root ).render( element );
	} else {
		render( element, root );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
