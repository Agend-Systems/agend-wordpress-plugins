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
 * Registers the policy meta and renders the editor panel.
 *
 * The panel is the only place a document policy is authored, so its save path
 * is the narrow gate everything else depends on. It refuses rather than guesses:
 * a submission that does not produce a valid policy leaves the stored one alone
 * and reports why (US-3.2 criterion 4).
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
	 * Form field names.
	 *
	 * @var string
	 */
	const FIELD_MODE  = 'agend_access_mode';
	const FIELD_TIERS = 'agend_access_tier_ids';

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
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'agend-content-access',
			__( 'Agend Access', 'agend-content-access' ),
			array( $this, 'render' ),
			self::supported_post_types(),
			'side',
			'default'
		);
	}

	/**
	 * Builds the data the panel renders from.
	 *
	 * Pure, and the reason it is separate: it is the single place that decides
	 * what crosses into the editor page, so "no secret is rendered" is a
	 * property that can be asserted rather than reviewed. Every value here comes
	 * from the reduced catalogue or the stored policy. The tenant API key, the
	 * gateway base URL and the raw tier payload are all absent by construction.
	 *
	 * @param array|null $policy    Stored policy, or null.
	 * @param array      $catalogue Result of Agend_Content_Access_Catalogue::get().
	 * @return array{
	 *     mode: string,
	 *     selected: array<int, array{id: string, name: string, available: bool, active: bool}>,
	 *     plans: array<int, array{id: string, name: string, slug: string, type: string, active: bool}>,
	 *     can_select: bool,
	 *     stale: bool,
	 *     unavailable_count: int
	 * }
	 */
	public static function payload( ?array $policy, array $catalogue ): array {
		$tier_ids = Agend_Content_Access_Policy::tier_ids( $policy );
		$selected = Agend_Content_Access_Catalogue::annotate_selection( $tier_ids, $catalogue['plans'] );

		$unavailable = 0;
		foreach ( $selected as $entry ) {
			if ( empty( $entry['available'] ) ) {
				++$unavailable;
			}
		}

		return array(
			'mode'              => null === $policy
				? ''
				: (string) $policy['mode'],
			'selected'          => $selected,
			'plans'             => Agend_Content_Access_Catalogue::selectable( $catalogue['plans'] ),
			'can_select'        => Agend_Content_Access_Catalogue::can_select_plans( $catalogue ),
			'stale'             => ! empty( $catalogue['stale'] ),
			'unavailable_count' => $unavailable,
		);
	}

	/**
	 * Renders the panel.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( $post ): void {
		$data = self::payload(
			Agend_Content_Access_Policy::get_for_post( (int) $post->ID ),
			Agend_Content_Access_Catalogue::get()
		);

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		require AGEND_CONTENT_ACCESS_DIR . 'admin/views/policy-panel.php';
	}

	/**
	 * Persists the submitted policy.
	 *
	 * Guard order matters and is deliberate: cheap structural checks first, then
	 * the nonce, then the capability, and only then any interpretation of user
	 * input. Nothing is read from `$_POST` until the request has been proven to
	 * be a genuine, authorised save of a supported post.
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

		// An absent nonce means this save did not come from the panel, for
		// example a quick edit or a programmatic wp_update_post. Leave the
		// stored policy alone rather than treating "no fields submitted" as
		// "the editor cleared the policy".
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$mode = isset( $_POST[ self::FIELD_MODE ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_MODE ] ) )
			: '';

		// An empty mode is the editor choosing not to set a policy, which is a
		// legitimate state (Decision 2.9) and distinct from an invalid one.
		if ( '' === $mode ) {
			Agend_Content_Access_Policy::save_for_post( (int) $post_id, null );
			return;
		}

		$submitted_tiers = isset( $_POST[ self::FIELD_TIERS ] ) && is_array( $_POST[ self::FIELD_TIERS ] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::FIELD_TIERS ] ) )
			: array();

		$result = Agend_Content_Access_Policy::from_input( $mode, $submitted_tiers );

		if ( null !== $result['error'] ) {
			// Keep the previous policy and carry the reason across the redirect.
			set_transient(
				self::ERROR_TRANSIENT . $post_id,
				$result['error'],
				60
			);
			return;
		}

		delete_transient( self::ERROR_TRANSIENT . $post_id );
		Agend_Content_Access_Policy::save_for_post( (int) $post_id, $result['policy'] );
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
