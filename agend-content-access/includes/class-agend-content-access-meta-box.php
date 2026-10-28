<?php
/**
 * Agend Access panel on the post editor.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Agend Access panel on a post's edit screen, for every editor that is not the
 * block editor.
 *
 * ONE PANEL, TWO HOSTS. The panel is the rule picker the block editor's sidebar
 * uses, writing a page's rules (`Agend_Content_Access_Page_Rules`). In the block
 * editor it is a native document settings panel (`src/page-panel-plugin.js`), and
 * this meta box is declared `__back_compat_meta_box`, which hides it there so an
 * author sees one panel. In the classic editor, and on any edit screen that is not
 * the block editor, this meta box is the panel: it renders a mount point, the same
 * React components are mounted on it (`src/classic.js`), and they write their
 * setting to one hidden input that `save()` reads.
 *
 * The panel used to be a form of radio buttons and check boxes for the five 0.1.x
 * audiences. That form is gone: a page's setting is rules, like a block's. A page
 * that still holds only the old setting is shown as the rules it reads as.
 *
 * Its save path is the narrow gate everything depends on, and it refuses rather
 * than guesses: a submission that does not validate leaves the stored setting
 * alone and says why (US-3.2 criterion 4). A save that submits nothing for this
 * panel, which is every save made while the script has not run, never clears a
 * stored setting.
 */
class Agend_Content_Access_Meta_Box {

	/**
	 * Nonce action and field name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'agend_content_access_save_policy';
	const NONCE_FIELD  = 'agend_content_access_nonce';

	/**
	 * The hidden input the panel writes its setting to, as JSON, and the element it
	 * is mounted on. The input is empty until an author changes something, and an
	 * empty input is a save with nothing to say, which keeps what is stored.
	 *
	 * @var string
	 */
	const FIELD_RULES = 'agend_access_page_rules';
	const ROOT_ID     = 'agend-access-page-root';
	const INPUT_ID    = 'agend-access-page-input';

	/**
	 * Handle shared by the classic editor's script and its stylesheet.
	 *
	 * @var string
	 */
	const HANDLE = 'agend-content-access-classic';

	/**
	 * Transient prefix carrying a validation error across the save redirect.
	 *
	 * @var string
	 */
	const ERROR_TRANSIENT = 'agend_content_access_error_';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_save_error' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Post types that receive the panel.
	 *
	 * Every public post type, which is the set whose URLs a visitor can reach
	 * and therefore the set that can need protecting. Attachments are excluded:
	 * a media item's protection is the protected-asset flow (US-5.1), not a
	 * document policy, and offering a policy here would imply a guarantee this
	 * panel cannot make.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		/**
		 * Filters the post types offered an Agend Access policy.
		 *
		 * Narrowing this is safe. WIDENING it to a type the connector does not
		 * export means an editor can set a policy that is never enforced
		 * downstream, so add a type here only alongside connector support.
		 *
		 * @param string[] $types Post type names.
		 */
		return (array) apply_filters(
			'agend_content_access_post_types',
			array_values( $types )
		);
	}

	/**
	 * Registers the policy meta.
	 *
	 * `show_in_rest` is false and the auth callback requires edit rights on the
	 * specific post, so the policy is not readable through the public REST
	 * representation of a record (US-3.2 criterion 3, US-3.4 criterion 8).
	 * An underscore prefix additionally keeps it out of the custom-fields UI,
	 * where it could be hand-edited into a shape the gateway rejects.
	 */
	public function register_meta(): void {
		foreach ( self::supported_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				Agend_Content_Access_Policy::META_KEY,
				array(
					'type'          => 'object',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Adds the panel to every supported post type.
	 *
	 * `__back_compat_meta_box` hides it in the block editor, which has its own
	 * panel for the same setting, and keeps it in the classic editor. A meta box
	 * with that flag is also left out of the block editor's compatibility check, so
	 * it does not send a post type back to the classic editor.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'agend-content-access',
			__( 'Agend Access', 'agend-content-access' ),
			array( $this, 'render' ),
			self::supported_post_types(),
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Queues the classic editor's script and stylesheet.
	 *
	 * On a post edit screen of a supported type that is not the block editor.
	 * Dependencies and version come from the asset file the build writes beside the
	 * script. A checkout that was never built queues nothing, and the meta box then
	 * says the panel could not start, rather than loading a script that fails.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( is_object( $screen ) ) {
			if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return;
			}

			if ( isset( $screen->post_type ) && ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
				return;
			}
		}

		$file = AGEND_CONTENT_ACCESS_DIR . 'build/classic.asset.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		$asset = include $file;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			AGEND_CONTENT_ACCESS_URL . 'build/classic.js',
			array_values( array_map( 'strval', $asset['dependencies'] ) ),
			(string) $asset['version'],
			true
		);

		wp_set_script_translations( self::HANDLE, 'agend-content-access', AGEND_CONTENT_ACCESS_DIR . 'languages' );

		// The components are WordPress's own, and their stylesheet is not loaded on
		// a classic edit screen unless something asks for it.
		wp_enqueue_style( 'wp-components' );

		if ( is_readable( AGEND_CONTENT_ACCESS_DIR . 'build/classic.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				AGEND_CONTENT_ACCESS_URL . 'build/classic.css',
				array( 'wp-components' ),
				(string) $asset['version']
			);
		}
	}

	/**
	 * Renders the panel: a mount point, the hidden input and the nonce.
	 *
	 * What crosses into the page is the page's own setting, read the way the editor
	 * shows it (`Agend_Content_Access_Page_Rules::state()`), and nothing else: no
	 * API key, gateway address or gateway payload is in scope. The picker's lists
	 * are fetched by the script from the conditions route, as in the block editor.
	 * Rendering never writes, so a setting that is unreadable, or an older one, is
	 * left exactly as it was found.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( $post ): void {
		$post_id = (int) $post->ID;
		$config  = array(
			'state' => Agend_Content_Access_Page_Rules::state( $post_id ),
		);

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// Editor V4 atomic elements cannot receive the Agend Access control, so an
		// editor on such a page needs telling why there is no per-section option, and
		// that the page setting still covers the whole page (US-4.4 criterion 12).
		$unmodelled = Agend_Content_Access_Elementor_Parser::unmodelled_element_types(
			(string) get_post_meta( $post_id, '_elementor_data', true )
		);

		if ( array() !== $unmodelled ) {
			?>
			<p class="notice notice-info notice-alt agend-access-page-notice">
				<?php
				esc_html_e(
					'This page uses Elementor\'s new editor. Individual sections on it cannot be restricted separately, because those elements do not accept the Agend Access control. The setting below still applies to the WHOLE page.',
					'agend-content-access'
				);
				?>
			</p>
			<?php
		}
		?>
		<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" class="agend-access-page-root" data-agend-access-page="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
			<p class="description">
				<?php esc_html_e( 'Loading the access settings. If this message stays, the settings could not start, and the setting this page already has is kept.', 'agend-content-access' ); ?>
			</p>
		</div>
		<input type="hidden" id="<?php echo esc_attr( self::INPUT_ID ); ?>" name="<?php echo esc_attr( self::FIELD_RULES ); ?>" value="" />
		<?php
	}

	/**
	 * Persists the submitted setting.
	 *
	 * Guard order matters and is deliberate: cheap structural checks first, then
	 * the nonce, then the capability, and only then any interpretation of user
	 * input. Nothing is read from `$_POST` until the request has been proven to
	 * be a genuine, authorised save of a supported post.
	 *
	 * The stored setting is only ever REPLACED by a submission that validates
	 * (`Agend_Content_Access_Page_Rules::save()`). An empty input, one that is not
	 * JSON and one that does not validate all leave it exactly as it was, whatever
	 * state it was in: readable, unreadable, an older policy, or absent. Only an
	 * explicit, valid setting writes, and clearing a restriction is removing every
	 * rule.
	 *
	 * THE POST BEING SAVED MUST BE THE POST THE FORM IS FOR. `save_post` fires for every
	 * post written during a request, and `$_POST` is the whole request's. Another plugin
	 * that updates a second post while the form's own is saved (a related-post sync, a
	 * parent that updates its children, an import) would otherwise make this write the
	 * form's setting to THAT post as well, with the nonce still valid, which is exactly
	 * how one page's rules overwrite another's. The edit form carries the id of the post it
	 * edits as `post_ID`, so a save for any other post is not the form's save.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
			return;
		}

		// The form's own post, and no other. Whole digits only: `(int)` reads '501abc' and
		// '501.5' as 501 and a non-empty array as 1, and none of those is the form's id.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified below, and this only narrows what that is allowed to write.
		$sent      = isset( $_POST['post_ID'] ) ? $_POST['post_ID'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$form_post = is_int( $sent ) || ( is_string( $sent ) && ctype_digit( $sent ) ) ? (int) $sent : 0;

		if ( $form_post <= 0 || $form_post !== (int) $post_id ) {
			return;
		}

		// An absent nonce means this save did not come from the panel, for
		// example a quick edit, the block editor's own save or a programmatic
		// wp_update_post. Leave the stored setting alone rather than treating "no
		// fields submitted" as "the editor cleared it".
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// A non-string value (`agend_access_page_rules[]=...`) is not a setting.
		// Treated as missing, rather than handed to the decoder, which expects a string.
		$raw = isset( $_POST[ self::FIELD_RULES ] ) && is_string( $_POST[ self::FIELD_RULES ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? trim( wp_unslash( $_POST[ self::FIELD_RULES ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '';

		// Nothing submitted means the panel had nothing to say, so what is stored is
		// kept, whatever its state. It must NOT be read as "the editor cleared it".
		// The input is empty until an author changes something, so a save made
		// without touching the panel, one made while the script had not started, and
		// one from a screen the panel is not on, all arrive here, and clearing any of
		// them would turn a restricted page into a public one without anybody
		// choosing that.
		if ( '' === $raw ) {
			return;
		}

		$input = json_decode( $raw, true );

		$error = JSON_ERROR_NONE === json_last_error()
			? Agend_Content_Access_Page_Rules::save( (int) $post_id, $input )
			: __( 'The access setting could not be read, so nothing was changed.', 'agend-content-access' );

		if ( null !== $error ) {
			// Keep the previous setting and carry the reason across the redirect.
			set_transient(
				self::ERROR_TRANSIENT . $post_id,
				$error,
				60
			);
			return;
		}

		delete_transient( self::ERROR_TRANSIENT . $post_id );
	}

	/**
	 * Shows a refused save inline on the next editor load.
	 */
	public function render_save_error(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'post' !== $screen->base ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( 0 === $post_id ) {
			return;
		}

		$error = get_transient( self::ERROR_TRANSIENT . $post_id );

		if ( ! is_string( $error ) || '' === $error ) {
			return;
		}

		delete_transient( self::ERROR_TRANSIENT . $post_id );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Agend Access:', 'agend-content-access' ),
			esc_html( $error )
		);
	}
}
