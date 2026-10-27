<?php
/**
 * The block editor's Agend Access attribute and the assets that edit it.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares the `agendAccess` attribute on every block type and loads the
 * editor script that gives authors a panel for it.
 *
 * Adding an attribute to every block is done in two places that have to agree,
 * and this is the PHP half. The editor half is `src/index.js`, which adds the
 * same attribute through the `blocks.registerBlockType` filter.
 *
 * WHY THE SERVER DECLARES IT TOO. A block the editor renders on the server (a
 * dynamic block, anything through the block renderer) sends all its attributes
 * in the request, and the renderer rejects an attribute the block type does not
 * declare. Declaring it here is what lets a restricted dynamic block render in
 * the editor at all. It is also what the editor's own block definitions are
 * built from for blocks registered in PHP.
 *
 * Nothing here is an enforcement point. The attribute is read from the parsed
 * block's own attributes by `Agend_Content_Access_Block_Attributes`, so a block
 * whose attribute fails this schema (a string where an object belongs) is still
 * read, and fails closed, there.
 *
 * Two block types are left out, because neither is serialised with a comment
 * delimiter and so an attribute would have nowhere to be stored. The author
 * would set a policy and the post would forget it:
 *
 *   core/missing   the "unsupported block" placeholder, which writes back the
 *                  original markup it could not parse
 *   core/freeform  the Classic block, which is raw HTML between blocks
 *
 * `EXCLUDED_BLOCKS` in `src/access-model.mjs` is the same list, and a node test
 * compares the two so they cannot drift.
 */
class Agend_Content_Access_Block_Editor {

	/**
	 * The attribute name. Defined once, in the class that reads it.
	 *
	 * @var string
	 */
	const ATTRIBUTE = Agend_Content_Access_Block_Attributes::ATTRIBUTE;

	/**
	 * Block types that cannot carry the attribute.
	 *
	 * @var string[]
	 */
	const EXCLUDED_BLOCKS = array( 'core/missing', 'core/freeform' );

	/**
	 * Handle shared by the editor script and its stylesheet.
	 *
	 * @var string
	 */
	const HANDLE = 'agend-content-access-block-editor';

	/**
	 * Plugin directory, with trailing slash.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Plugin URL, with trailing slash.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Registers hooks.
	 *
	 * The directory and URL default to the plugin's own and are parameters only
	 * so a test can point the asset loading at a fixture build.
	 *
	 * @param string $dir Plugin directory, with trailing slash. Defaults to the plugin's.
	 * @param string $url Plugin URL, with trailing slash. Defaults to the plugin's.
	 */
	public function __construct( string $dir = '', string $url = '' ) {
		$this->dir = '' !== $dir ? $dir : AGEND_CONTENT_ACCESS_DIR;
		$this->url = '' !== $url ? $url : AGEND_CONTENT_ACCESS_URL;

		// On every request, not only in the editor: the block renderer is a
		// REST request and reads a block type's attributes.
		add_filter( 'register_block_type_args', array( $this, 'add_attribute' ), 10, 2 );

		// Priority 1 so the script is queued ahead of other plugins' editor
		// scripts. It adds the attribute to each block as that block is
		// registered in the browser, so a block type registered by a script
		// that runs BEFORE this one never gets it. The panel declines to show
		// for such a block rather than offering a setting that would not be
		// saved, and running early keeps that set as small as it can be.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_script' ), 1 );

		// `enqueue_block_assets` rather than `enqueue_block_editor_assets` for
		// the stylesheet: it is the hook whose styles are also copied into the
		// editor canvas iframe, and the canvas is where the indicator lives.
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_style' ) );
	}

	/**
	 * Adds the attribute to a block type's arguments.
	 *
	 * Left alone when the block cannot carry it, and when the block already
	 * declares an `agendAccess` of its own: that is somebody else's contract and
	 * replacing it would break whatever reads it.
	 *
	 * The default stays an empty array, never an object: server code reads
	 * WordPress's prepared block attributes, an object there would be a stdClass,
	 * and `Block_Attributes::settings_for_block()` deliberately treats any
	 * non-array value as a restriction, so the editor removes the attribute
	 * instead of writing an empty `{}`.
	 *
	 * @param array  $args       Block type arguments.
	 * @param string $block_name Block type name.
	 * @return array
	 */
	public function add_attribute( $args, $block_name ) {
		if ( ! is_array( $args ) || in_array( $block_name, self::EXCLUDED_BLOCKS, true ) ) {
			return $args;
		}

		$attributes = isset( $args['attributes'] ) && is_array( $args['attributes'] )
			? $args['attributes']
			: array();

		if ( array_key_exists( self::ATTRIBUTE, $attributes ) ) {
			return $args;
		}

		// An object, defaulting to empty: no restriction and no conditions. A
		// non-sourced attribute equal to its default is not written into the
		// block's comment, so a block nobody touched saves as it always did.
		$attributes[ self::ATTRIBUTE ] = array(
			'type'    => 'object',
			'default' => array(),
		);

		$args['attributes'] = $attributes;

		return $args;
	}

	/**
	 * Queues the editor script.
	 *
	 * Dependencies and version come from the asset file the build writes next
	 * to the script, so they cannot fall out of step with the code. A missing
	 * asset file (a checkout that was never built) queues nothing: the editor
	 * simply has no panel, which is better than a script that fails to load.
	 */
	public function enqueue_script(): void {
		$asset = $this->asset();

		if ( null === $asset ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$this->url . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			false
		);

		wp_set_script_translations( self::HANDLE, 'agend-content-access', $this->dir . 'languages' );
	}

	/**
	 * Queues the editor stylesheet, in editor contexts only.
	 *
	 * `enqueue_block_assets` also fires on the front end, where none of this
	 * belongs.
	 */
	public function enqueue_style(): void {
		if ( ! is_admin() ) {
			return;
		}

		$asset = $this->asset();

		if ( null === $asset || ! is_readable( $this->dir . 'build/index.css' ) ) {
			return;
		}

		// The asset's version covers the stylesheet as well as the script: the
		// build hashes extracted styles into it, so a style-only change still
		// produces a new version.
		wp_enqueue_style(
			self::HANDLE,
			$this->url . 'build/index.css',
			array(),
			$asset['version']
		);

		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * Reads the build's asset file.
	 *
	 * @return array{dependencies: string[], version: string}|null Null when the
	 *         build is missing or not in the shape the build writes.
	 */
	private function asset(): ?array {
		$file = $this->dir . 'build/index.asset.php';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$asset = include $file;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) ) {
			return null;
		}

		return array(
			'dependencies' => array_values( array_map( 'strval', $asset['dependencies'] ) ),
			'version'      => (string) $asset['version'],
		);
	}
}
