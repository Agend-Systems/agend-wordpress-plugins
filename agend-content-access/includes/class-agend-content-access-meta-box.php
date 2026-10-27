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
	const FIELD_MODE         = 'agend_access_mode';
	const FIELD_TIERS        = 'agend_access_tier_ids';
	const FIELD_SEGMENTS     = 'agend_access_segment_ids';
	const FIELD_ENTITLEMENTS = 'agend_access_entitlement_keys';

	/**
	 * The three set-mode lists the panel offers, each with the catalogue section
	 * it is built from and the form field its checkboxes submit under.
	 *
	 * One table drives the payload, the view and the save, so a fourth list is
	 * one entry here rather than a branch in each of them.
	 *
	 * @var array<string, array{section: string, field: string}>
	 */
	const LISTS = array(
		Agend_Content_Access_Policy::MODE_TIERS        => array(
			'section' => 'plans',
			'field'   => self::FIELD_TIERS,
		),
		Agend_Content_Access_Policy::MODE_SEGMENTS     => array(
			'section' => 'segments',
			'field'   => self::FIELD_SEGMENTS,
		),
		Agend_Content_Access_Policy::MODE_ENTITLEMENTS => array(
			'section' => 'entitlements',
			'field'   => self::FIELD_ENTITLEMENTS,
		),
	);

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
	 *
	 * Declared block editor compatible: the panel is plain form fields saved
	 * through `save_post`, which the block editor submits from its meta box area
	 * once the post itself has saved, so it needs no classic editor fallback and
	 * works the same in both.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'agend-content-access',
			__( 'Agend Access', 'agend-content-access' ),
			array( $this, 'render' ),
			self::supported_post_types(),
			'side',
			'default',
			array( '__block_editor_compatible_meta_box' => true )
		);
	}

	/**
	 * Builds the data the panel renders from.
	 *
	 * Pure, and the reason it is separate: it is the single place that decides
	 * what crosses into the editor page, so "no secret is rendered" is a
	 * property that can be asserted rather than reviewed. Every value here comes
	 * from the reduced catalogue or the stored policy. The tenant API key, the
	 * gateway base URL and the raw gateway payloads are all absent by
	 * construction.
	 *
	 * Each of the three set-mode audiences (plans, segments, entitlements) gets
	 * one entry under `lists`, keyed by its mode, in one shape. A selection that
	 * is no longer offered is returned in `selected` with `available` false, so
	 * the panel can show and repair it, and is never dropped from the list.
	 *
	 * @param array|null $policy           Stored policy, or null.
	 * @param array      $catalogue        Result of Agend_Content_Access_Catalogue::sections().
	 * @param array      $unmodelled_types Elementor element types that cannot take the control.
	 * @param bool       $unreadable       Whether a policy IS stored but could not be read.
	 *                                     `$policy` is null in that case too, so only this
	 *                                     says the page is hidden rather than unset.
	 * @return array{
	 *     mode: string,
	 *     lists: array<string, array{
	 *         field: string,
	 *         selected: array<int, array{value: string, label: string, available: bool}>,
	 *         options: array<int, array{value: string, label: string, description: string, available: bool}>,
	 *         can_select: bool,
	 *         stale: bool,
	 *         error: string,
	 *         unavailable_count: int
	 *     }>,
	 *     unmodelled_types: string[],
	 *     unreadable: bool
	 * }
	 */
	public static function payload(
		?array $policy,
		array $catalogue,
		array $unmodelled_types = array(),
		bool $unreadable = false
	): array {
		$lists = array();

		foreach ( self::LISTS as $mode => $list ) {
			$lists[ $mode ] = self::list_payload(
				$mode,
				$list['field'],
				self::stored_selection( $policy, $mode ),
				isset( $catalogue[ $list['section'] ] ) && is_array( $catalogue[ $list['section'] ] )
					? $catalogue[ $list['section'] ]
					: array()
			);
		}

		return array(
			'mode'             => null === $policy
				? ''
				: (string) $policy['mode'],
			'lists'            => $lists,
			// Editor V4 atomic elements cannot receive the Agend Access
			// control, so an editor on such a page needs telling why there is
			// no per-section option, and that the page policy still covers the
			// whole page (US-4.4 criterion 12).
			'unmodelled_types' => array_values( $unmodelled_types ),
			// A stored policy that could not be read is enforced as nobody, so
			// the editor has to say so: an empty panel reads as "nothing set",
			// which is the opposite of what visitors are experiencing.
			'unreadable'       => $unreadable,
		);
	}

	/**
	 * What a stored policy selects in one set-mode, or nothing for another mode.
	 *
	 * @param array|null $policy Stored policy, or null.
	 * @param string     $mode   A key of self::LISTS.
	 * @return string[]
	 */
	private static function stored_selection( ?array $policy, string $mode ): array {
		switch ( $mode ) {
			case Agend_Content_Access_Policy::MODE_SEGMENTS:
				return Agend_Content_Access_Policy::segment_ids( $policy );
			case Agend_Content_Access_Policy::MODE_ENTITLEMENTS:
				return Agend_Content_Access_Policy::entitlement_keys( $policy );
			default:
				return Agend_Content_Access_Policy::tier_ids( $policy );
		}
	}

	/**
	 * One list's data for the panel.
	 *
	 * `can_select` is false only when the section has no items at all, which
	 * means either the list could not be read or the tenant has none. Either
	 * way a NEW selection cannot be made, so the audience's radio is disabled,
	 * while Public and all-active-members stay saveable and an outage never
	 * blocks editing outright. The stored selection is returned regardless, so
	 * it is shown whatever state the list is in.
	 *
	 * @param string   $mode    A key of self::LISTS.
	 * @param string   $field   Form field the checkboxes submit under.
	 * @param string[] $stored  What the stored policy selects in this mode.
	 * @param array    $section The catalogue section: `{items, stale, error}`.
	 * @return array{field: string, selected: array, options: array, can_select: bool, stale: bool, error: string, unavailable_count: int}
	 */
	private static function list_payload( string $mode, string $field, array $stored, array $section ): array {
		$items    = isset( $section['items'] ) && is_array( $section['items'] ) ? $section['items'] : array();
		$options  = Agend_Content_Access_Catalogue::options( $mode, $items );
		$selected = Agend_Content_Access_Catalogue::annotate_options( $stored, $options );

		$unavailable = 0;

		foreach ( $selected as $index => $entry ) {
			if ( empty( $entry['available'] ) ) {
				++$unavailable;
			}

			// An entitlement key is readable on its own (`directory.access`), so it
			// stands in for a name the list cannot give. A plan or segment id is an
			// opaque UUID, so the view says "unknown" for those instead.
			if ( '' === $entry['label'] && Agend_Content_Access_Policy::MODE_ENTITLEMENTS === $mode ) {
				$selected[ $index ]['label'] = $entry['value'];
			}
		}

		return array(
			'field'             => $field,
			'selected'          => $selected,
			'options'           => array_values(
				array_filter(
					$options,
					static function ( $option ) {
						return ! empty( $option['available'] );
					}
				)
			),
			'can_select'        => array() !== $items,
			'stale'             => ! empty( $section['stale'] ),
			'error'             => isset( $section['error'] ) ? (string) $section['error'] : '',
			'unavailable_count' => $unavailable,
		);
	}

	/**
	 * Renders the panel.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( $post ): void {
		// The editor's reading, not the enforced one: it shows "not set" for an
		// unreadable policy so an audience can be chosen, and the stored state
		// is passed alongside so the panel can also say the page is hidden.
		// Rendering never writes, so the stored value is left as it was found.
		$data = self::payload(
			Agend_Content_Access_Policy::get_for_post( (int) $post->ID ),
			Agend_Content_Access_Catalogue::sections(),
			Agend_Content_Access_Elementor_Parser::unmodelled_element_types(
				(string) get_post_meta( (int) $post->ID, '_elementor_data', true )
			),
			Agend_Content_Access_Policy::STORED_INVALID === Agend_Content_Access_Policy::stored_state( (int) $post->ID )
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
	 * The stored policy is only ever REPLACED by a submitted mode that produces a
	 * valid policy. A missing, empty, non-string or unrecognised mode, and a mode
	 * whose selections are invalid, all leave it exactly as it was, whatever
	 * state it was in: readable, unreadable or absent. Only an explicit,
	 * recognised audience writes, and clearing a restriction is choosing Public.
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

		// A non-string value (`agend_access_mode[]=...`) is not a mode. Treated as
		// missing, rather than handed to the sanitiser, which expects a string.
		$mode = isset( $_POST[ self::FIELD_MODE ] ) && is_string( $_POST[ self::FIELD_MODE ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_MODE ] ) )
			: '';

		// No mode submitted means the panel had nothing to say, so the stored
		// policy is kept, whatever its state. It must NOT be read as "the editor
		// cleared the policy", because the panel can render with no mode checked
		// and a browser submits nothing for a radio that is not checked:
		//
		//  - a set-mode radio is disabled while its list is unavailable (the plan,
		//    segment or entitlement list unreachable, a Core too old to list it,
		//    an API key without its scope), and a disabled control is not
		//    submitted, so a stored policy in that mode comes back with no mode;
		//  - an unreadable policy preselects nothing;
		//  - a hand-built request, or a later release's panel, can name a mode this
		//    version has no radio for.
		//
		// Clearing any of those on an ordinary save would turn a restricted page
		// into a public one without anybody choosing that. A mode the panel does
		// not recognise is refused by `from_input()` below with the stored policy
		// left alone, so the two kinds of unusable mode end the same way.
		// Removing a restriction is still possible: choose Public.
		if ( '' === $mode ) {
			return;
		}

		// Every list is read, and `from_input()` uses only the one for the chosen
		// mode: a checked box in a list the editor has since switched away from
		// is ignored, exactly as the plan list always was.
		$result = Agend_Content_Access_Policy::from_input(
			$mode,
			self::submitted_list( self::FIELD_TIERS ),
			array(
				Agend_Content_Access_Policy::SET_MODE_KEYS[ Agend_Content_Access_Policy::MODE_SEGMENTS ]     => self::submitted_list( self::FIELD_SEGMENTS ),
				Agend_Content_Access_Policy::SET_MODE_KEYS[ Agend_Content_Access_Policy::MODE_ENTITLEMENTS ] => self::submitted_list( self::FIELD_ENTITLEMENTS ),
			)
		);

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
	 * The submitted entries of one list's checkboxes, sanitised for reading.
	 *
	 * Only called after `save()` has verified the nonce and the capability.
	 * Whatever comes back is still unvalidated: `from_input()` drops what is not
	 * well formed for the mode, which can only make a selection narrower. A
	 * field that is not an array (`field=x`) is nothing selected.
	 *
	 * @param string $field Form field name.
	 * @return string[]
	 */
	private static function submitted_list( string $field ): array {
		return isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $field ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();
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
