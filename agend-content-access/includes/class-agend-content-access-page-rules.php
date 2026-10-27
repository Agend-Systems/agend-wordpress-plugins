<?php
/**
 * A page's access rules: how they are read for the editor, validated and saved.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The server half of the page panel, for both editors.
 *
 * A page's rules are stored in the post meta `_agend_access_rules` as
 * `{rules, match, invert, denied: {action, target_id, fallback_id}}`, which
 * `Agend_Content_Access_Rules::from_post()` reads. Two editors write it:
 *
 *   - THE BLOCK EDITOR through a REST field, `agend_access_rules`, that exists in
 *     the `edit` context only. The panel there is a native document settings panel.
 *   - THE CLASSIC EDITOR, and any other edit screen, through the meta box, which
 *     submits the same value as JSON in one hidden input
 *     (`Agend_Content_Access_Meta_Box::save()`).
 *
 * Both end in `save()` below, so the rules cannot be written in a way one of them
 * checks and the other does not.
 *
 * WHY A REST FIELD AND NOT REGISTERED META. Meta registered with `show_in_rest` is
 * in every response of the post, `view` context included, and WordPress does not
 * restrict who may READ a registered meta value: its `auth_callback` guards
 * writes only. Plan, segment and entitlement ids are the association's own
 * configuration, so that would be a leak, and the response filter would have to
 * remember to remove it in every state a page is in. A field with `context =>
 * array( 'edit' )` is never in a `view` response at all. It also solves a second
 * problem: the block editor sends EVERY registered meta key back on any save that
 * edits one, and an unset object meta comes out of REST as `[]`, so each save of
 * an untouched legacy page would have stored an empty rule set. The new meta wins
 * over the 0.1.x policy outright, so that would have published the page. A field
 * is only sent back when an author edited it.
 *
 * THE FIELD IS NEVER READABLE OR WRITABLE BY SOMEBODY WHO CANNOT EDIT THE POST.
 * Its getter and its setter both check `edit_post` for that post. The response
 * filter (`Agend_Content_Access_Frontend::filter_rest_response()`) removes it from
 * every response that is not an edit of the post by somebody who may edit it, and
 * the meta itself is registered without `show_in_rest`, so it cannot be written
 * through the generic `meta` parameter either.
 *
 * VALIDATION REFUSES AND KEEPS. A value that does not validate is never repaired:
 * the save is refused, with a message an author can act on, and what was stored is
 * left as it was. Narrowing is the only repair that is ever made (a number the
 * action does not use is dropped), and it never widens who may see the page. The
 * rules are checked in REST before the post itself is written
 * (`rest_pre_insert_{type}`), so a refused value does not half-save a page.
 *
 * SAVING REPLACES THE 0.1.x POLICY. The new meta wins outright over
 * `_agend_access_policy`, so an author who saves new rules has replaced the old
 * setting, and one who clears every rule has made the page open to everyone,
 * which they chose. Either way the old key is deleted, so it can never come back
 * after the new one is removed.
 */
class Agend_Content_Access_Page_Rules {

	/**
	 * The REST field the block editor reads and writes.
	 *
	 * @var string
	 */
	const FIELD = Agend_Content_Access_Rules::REST_FIELD;

	/**
	 * The error code of a refused value, in REST and in the editor's save error.
	 *
	 * @var string
	 */
	const ERROR_CODE = 'agend_content_access_invalid_rules';

	/**
	 * The most rules a page may hold. A list is chosen by hand from a picker, so
	 * this is a ceiling against abuse and not a limit anybody meets.
	 *
	 * @var int
	 */
	const MAX_RULES = 200;

	/**
	 * The longest a single rule may be.
	 *
	 * @var int
	 */
	const MAX_RULE_LENGTH = 255;

	/**
	 * Keys a value may carry that are read-out only. The editor is given them and a
	 * client that sends its whole record back may send them again; they are ignored,
	 * never stored.
	 *
	 * @var string[]
	 */
	const OUTPUT_ONLY_KEYS = array( 'legacy', 'unreadable' );

	/**
	 * Registers hooks.
	 *
	 * On every request, REST included: a REST request is not `is_admin()`, and the
	 * block editor saves through one.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Registers the meta, without REST.
	 *
	 * `show_in_rest` is false so the rules are not in any `meta` object and cannot
	 * be written through one. The auth callback requires edit rights on the
	 * specific post for the writes that do go through WordPress's meta
	 * capabilities.
	 */
	public function register_meta(): void {
		foreach ( Agend_Content_Access_Meta_Box::supported_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				Agend_Content_Access_Rules::META_KEY,
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
	 * Registers the REST field and the check that runs before a post is written.
	 */
	public function register_rest(): void {
		foreach ( Agend_Content_Access_Meta_Box::supported_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				self::FIELD,
				array(
					'get_callback'    => array( $this, 'rest_get' ),
					'update_callback' => array( $this, 'rest_update' ),
					'schema'          => self::rest_schema(),
				)
			);

			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'rest_pre_insert' ), 10, 2 );
		}
	}

	/**
	 * The field's schema.
	 *
	 * `context` is `edit` only, which is what keeps the field out of every public
	 * response. The shape is strict so WordPress refuses a malformed value before
	 * any of this code runs; `validate()` is the check that matters and does not
	 * depend on it.
	 *
	 * @return array
	 */
	public static function rest_schema(): array {
		return array(
			'description'          => __( 'Who can see this page, and what happens to someone who cannot.', 'agend-content-access' ),
			'type'                 => 'object',
			'context'              => array( 'edit' ),
			'additionalProperties' => false,
			'properties'           => array(
				'rules'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'match'      => array(
					'type' => 'string',
					'enum' => array( Agend_Content_Access_Rules::MATCH_ANY, Agend_Content_Access_Rules::MATCH_ALL ),
				),
				'invert'     => array( 'type' => 'boolean' ),
				'denied'     => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'action'      => array(
							'type' => 'string',
							'enum' => self::actions(),
						),
						'target_id'   => array( 'type' => 'integer' ),
						'fallback_id' => array( 'type' => 'integer' ),
					),
				),
				'legacy'     => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'unreadable' => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * What the denial actions are called in a stored value.
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		return array(
			Agend_Content_Access_Rules::DENIED_PLACEHOLDER,
			Agend_Content_Access_Rules::DENIED_REDIRECT,
			Agend_Content_Access_Rules::DENIED_BACK,
		);
	}

	// -----------------------------------------------------------------------
	// Reading, for the editor
	// -----------------------------------------------------------------------

	/**
	 * A page's setting as the panel shows it.
	 *
	 * The new meta when the page has one, otherwise the 0.1.x policy read as rules
	 * (`Agend_Content_Access_Rules::from_post()` does both), with `legacy` set so the
	 * panel can say it is showing an older setting. A setting this version cannot
	 * express in the panel is `unreadable`: it is enforced as hiding the page from
	 * everyone, the panel shows that and offers a reset and nothing else. That is
	 * a stored value that is not readable, a set of rules reversed with nothing to
	 * match, and a set made of several parts (which nothing the panel writes is).
	 *
	 * Reading never writes: opening a page does not convert it.
	 *
	 * @param int $post_id Post id.
	 * @return array{rules: string[], match: string, invert: bool, denied: array{action: string, target_id: int, fallback_id: int}, legacy: bool, unreadable: bool}
	 */
	public static function state( int $post_id ): array {
		$rules   = Agend_Content_Access_Rules::from_post( $post_id );
		$stored  = self::has_stored_rules( $post_id );
		$legacy  = ! $stored
			&& Agend_Content_Access_Policy::STORED_ABSENT !== Agend_Content_Access_Policy::stored_state( $post_id )
			&& ! $rules->is_empty();
		$parts   = count( $rules->leaves() );
		$nobody  = $rules->is_inverted() && array() === $rules->rules();
		$unread  = $rules->is_unreadable() || $parts > 1 || $nobody;
		$denied  = $rules->denied();

		if ( $unread ) {
			return array(
				'rules'      => array(),
				'match'      => Agend_Content_Access_Rules::MATCH_ANY,
				'invert'     => false,
				'denied'     => self::default_denied(),
				'legacy'     => false,
				'unreadable' => true,
			);
		}

		return array(
			'rules'      => $rules->rules(),
			'match'      => $rules->match(),
			'invert'     => $rules->is_inverted(),
			'denied'     => array(
				'action'      => (string) $denied['action'],
				'target_id'   => (int) $denied['target_id'],
				'fallback_id' => (int) $denied['fallback_id'],
			),
			'legacy'     => $legacy,
			'unreadable' => false,
		);
	}

	/**
	 * Whether the page holds the new meta, with something in it.
	 *
	 * A cleared value (an empty string) is how WordPress reports a meta row that was
	 * deleted but is still listed, and counts as none, as in `Rules::from_post()`.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function has_stored_rules( int $post_id ): bool {
		return metadata_exists( 'post', $post_id, Agend_Content_Access_Rules::META_KEY )
			&& '' !== get_post_meta( $post_id, Agend_Content_Access_Rules::META_KEY, true );
	}

	/**
	 * The denial setting a page has when nothing says otherwise.
	 *
	 * @return array{action: string, target_id: int, fallback_id: int}
	 */
	private static function default_denied(): array {
		return array(
			'action'      => Agend_Content_Access_Rules::DENIED_PLACEHOLDER,
			'target_id'   => 0,
			'fallback_id' => 0,
		);
	}

	// -----------------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------------

	/**
	 * Checks a submitted setting and puts it in its stored shape.
	 *
	 * Returns the value, or an error, and exactly one of them is not null. A `null`
	 * value with no error is a CLEAR: every rule was removed, which is the author's
	 * explicit choice to make the page open to everyone. Nothing here is ever
	 * repaired into something else, so a value that is wrong is refused whole.
	 *
	 * The stored shape is `{rules, match, invert, denied: {action, target_id,
	 * fallback_id}}`: `match` is `any` below two rules, and an id the chosen action
	 * does not use is 0.
	 *
	 * @param mixed $input   The submitted value: a decoded JSON object.
	 * @param int   $post_id The page it is for, 0 for one that is not saved yet.
	 * @return array{value: array|null, error: string|null}
	 */
	public static function validate( $input, int $post_id = 0 ): array {
		if ( ! is_array( $input ) || self::is_list( $input ) ) {
			return self::refused( __( 'The access setting was not in a form that can be saved, so nothing was changed.', 'agend-content-access' ) );
		}

		$known = array_merge( array( 'rules', 'match', 'invert', 'denied' ), self::OUTPUT_ONLY_KEYS );

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				return self::refused( __( 'The access setting held something this version does not know, so nothing was changed.', 'agend-content-access' ) );
			}
		}

		// A setting the editor flagged as unreadable has no rules to save. Sent back
		// as it was read, it would clear the rules and open the page to everyone, so
		// it is refused: resetting one is a deliberate, plain empty list.
		if ( ! empty( $input['unreadable'] ) ) {
			return self::refused( __( 'The access setting on this page cannot be read, so it was not changed. Reset it to start again.', 'agend-content-access' ) );
		}

		if ( ! array_key_exists( 'rules', $input ) ) {
			return self::refused( __( 'The access setting had no list of rules, so nothing was changed.', 'agend-content-access' ) );
		}

		$rules = self::clean_rules( $input['rules'] );

		if ( is_string( $rules ) ) {
			return self::refused( $rules );
		}

		$match = array_key_exists( 'match', $input ) ? $input['match'] : Agend_Content_Access_Rules::MATCH_ANY;

		if ( ! in_array( $match, array( Agend_Content_Access_Rules::MATCH_ANY, Agend_Content_Access_Rules::MATCH_ALL ), true ) ) {
			return self::refused( __( 'Match must be any of the rules or all of them, so nothing was changed.', 'agend-content-access' ) );
		}

		$invert = array_key_exists( 'invert', $input ) ? $input['invert'] : false;

		if ( ! is_bool( $invert ) ) {
			return self::refused( __( 'Reverse must be on or off, so nothing was changed.', 'agend-content-access' ) );
		}

		if ( array() === $rules ) {
			// A reversed list with nothing in it would hide the page from everyone,
			// which is not what an author who removed the last rule meant.
			if ( $invert ) {
				return self::refused( __( 'Choose at least one rule before reversing who can see the page, so nothing was changed.', 'agend-content-access' ) );
			}

			return array(
				'value' => null,
				'error' => null,
			);
		}

		$denied = self::clean_denied( array_key_exists( 'denied', $input ) ? $input['denied'] : null, $post_id );

		if ( is_string( $denied ) ) {
			return self::refused( $denied );
		}

		return array(
			'value' => array(
				'rules'  => $rules,
				'match'  => count( $rules ) > 1 ? $match : Agend_Content_Access_Rules::MATCH_ANY,
				'invert' => $invert,
				'denied' => $denied,
			),
			'error' => null,
		);
	}

	/**
	 * An error result.
	 *
	 * @param string $message What to tell the author.
	 * @return array{value: null, error: string}
	 */
	private static function refused( string $message ): array {
		return array(
			'value' => null,
			'error' => $message,
		);
	}

	/**
	 * The rule list, checked.
	 *
	 * A rule is `"<provider>:<value>"` for a provider this site knows, with a value
	 * that is plain text of a sensible length. An unknown provider is refused here
	 * and, if one is stored some other way, denies at render: the evaluator fails
	 * closed on it. Whether the VALUE is something the site offers is not checked:
	 * the lists are somebody else's, and a plan that is gone is shown as
	 * unavailable and denies, it is not a reason to refuse a save.
	 *
	 * @param mixed $rules The submitted list.
	 * @return string[]|string The rules, in order and without repeats, or a message.
	 */
	private static function clean_rules( $rules ) {
		if ( ! is_array( $rules ) || ( array() !== $rules && ! self::is_list( $rules ) ) ) {
			return __( 'The rules were not a list, so nothing was changed.', 'agend-content-access' );
		}

		if ( count( $rules ) > self::MAX_RULES ) {
			return sprintf(
				/* translators: %d: the most rules a page can hold. */
				__( 'A page can hold at most %d rules, so nothing was changed.', 'agend-content-access' ),
				self::MAX_RULES
			);
		}

		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_string( $rule ) ) {
				return __( 'A rule was not text, so nothing was changed.', 'agend-content-access' );
			}

			$rule = trim( $rule );

			if (
				'' === $rule
				|| strlen( $rule ) > self::MAX_RULE_LENGTH
				|| 1 !== preg_match( '/^([A-Za-z][A-Za-z0-9_]*):(\S.*)$/s', $rule, $parts )
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $rule )
			) {
				return sprintf(
					/* translators: %s: the rule that was refused, shortened. */
					__( 'This is not a rule: %s. Nothing was changed.', 'agend-content-access' ),
					self::excerpt( $rule )
				);
			}

			if ( ! self::is_known_provider( $parts[1] ) ) {
				return sprintf(
					/* translators: %s: the rule that was refused, shortened. */
					__( 'This site does not know the kind of rule in %s, so nothing was changed.', 'agend-content-access' ),
					self::excerpt( $rule )
				);
			}

			if ( ! in_array( $rule, $clean, true ) ) {
				$clean[] = $rule;
			}
		}

		return $clean;
	}

	/**
	 * Whether a provider is one this site evaluates: the built in ones, the old
	 * ESAC names the engine still reads, or one a site added through the
	 * `agend_content_access_condition_sets` filter.
	 *
	 * The vocabulary is only asked for a provider that is not built in. Building it
	 * reads the plan, segment and entitlement lists, which is a gateway call on a
	 * cold cache, and an ordinary save should not wait for one.
	 *
	 * @param string $provider Provider key.
	 * @return bool
	 */
	private static function is_known_provider( string $provider ): bool {
		if ( null !== Agend_Content_Access_Condition_Providers::canonical( $provider ) ) {
			return true;
		}

		return class_exists( 'Agend_Content_Access_Condition_Sets', false )
			&& array_key_exists( $provider, Agend_Content_Access_Condition_Sets::all() );
	}

	/**
	 * The denial setting, checked and narrowed to what its action uses.
	 *
	 * Absent is the default, a placeholder. The action decides which id counts:
	 * a placeholder uses `target_id` (0 is the built-in one), a redirect needs one,
	 * and "back" uses `fallback_id` (0 is the placeholder when nobody came from this
	 * site). The other id is dropped.
	 *
	 * @param mixed $denied  The submitted `denied`, or null.
	 * @param int   $post_id The page, so it is never sent to itself.
	 * @return array{action: string, target_id: int, fallback_id: int}|string The setting, or a message.
	 */
	private static function clean_denied( $denied, int $post_id ) {
		if ( null === $denied || array() === $denied ) {
			return self::default_denied();
		}

		if ( ! is_array( $denied ) || ( array() !== $denied && self::is_list( $denied ) ) ) {
			return __( 'What happens to someone who is not allowed was not in a form that can be saved, so nothing was changed.', 'agend-content-access' );
		}

		foreach ( array_keys( $denied ) as $key ) {
			if ( ! in_array( $key, array( 'action', 'target_id', 'fallback_id' ), true ) ) {
				return __( 'What happens to someone who is not allowed held something this version does not know, so nothing was changed.', 'agend-content-access' );
			}
		}

		$action = array_key_exists( 'action', $denied ) ? $denied['action'] : Agend_Content_Access_Rules::DENIED_PLACEHOLDER;

		if ( ! is_string( $action ) || ! in_array( $action, self::actions(), true ) ) {
			return __( 'Choose to show a placeholder, redirect to a page, or send people back, so nothing was changed.', 'agend-content-access' );
		}

		$target   = self::whole_number( $denied['target_id'] ?? 0 );
		$fallback = self::whole_number( $denied['fallback_id'] ?? 0 );

		if ( null === $target || null === $fallback ) {
			return __( 'The page chosen was not a page number, so nothing was changed.', 'agend-content-access' );
		}

		$message = __( 'The page you chose to send people to is not available. Choose a published page that is not this one, so nothing was changed.', 'agend-content-access' );

		switch ( $action ) {
			case Agend_Content_Access_Rules::DENIED_REDIRECT:
				if ( 0 === $target || ! self::is_destination( $target, $post_id ) ) {
					return $message;
				}

				return array(
					'action'      => $action,
					'target_id'   => $target,
					'fallback_id' => 0,
				);

			case Agend_Content_Access_Rules::DENIED_BACK:
				if ( $fallback > 0 && ! self::is_destination( $fallback, $post_id ) ) {
					return $message;
				}

				return array(
					'action'      => $action,
					'target_id'   => 0,
					'fallback_id' => $fallback,
				);

			default:
				if ( $target > 0 && ! self::is_placeholder( $target, $post_id ) ) {
					return __( 'The placeholder you chose is not available. Choose a published page, pattern or template that is open to everyone, so nothing was changed.', 'agend-content-access' );
				}

				return array(
					'action'      => $action,
					'target_id'   => $target,
					'fallback_id' => 0,
				);
		}
	}

	/**
	 * Whether a post can be where a denied visitor is sent: published, with no
	 * password, a page of a type the rule panel is offered on, and not the page
	 * they are leaving.
	 *
	 * Whether THIS visitor may open it is judged when they are sent
	 * (`Agend_Content_Access_Destination::page_url()`), not now: a dashboard that is
	 * for members is a good place to send members.
	 *
	 * @param int $id      The chosen post.
	 * @param int $post_id The page being saved.
	 * @return bool
	 */
	private static function is_destination( int $id, int $post_id ): bool {
		$target = $id !== $post_id ? get_post( $id ) : null;

		return $target instanceof WP_Post
			&& 'publish' === $target->post_status
			&& '' === (string) $target->post_password
			&& Agend_Content_Access_Destination::is_page_type( (string) $target->post_type );
	}

	/**
	 * Whether a post can be shown in place of a page: published, with no password,
	 * a kind that can be shown (a page, a synced pattern, or an Elementor template
	 * when Elementor is active), and open to everyone, as the panel's own list is.
	 *
	 * @param int $id      The chosen post.
	 * @param int $post_id The page being saved.
	 * @return bool
	 */
	private static function is_placeholder( int $id, int $post_id ): bool {
		$target = $id !== $post_id ? get_post( $id ) : null;

		return $target instanceof WP_Post
			&& 'publish' === $target->post_status
			&& '' === (string) $target->post_password
			&& '' !== Agend_Content_Access_Fragment_Rules::kind_of( $target )
			&& Agend_Content_Access_Rules::from_post( $id )->is_empty();
	}

	/**
	 * A non-negative whole number from an int or a string of digits, or null.
	 *
	 * @param mixed $value The submitted number.
	 * @return int|null
	 */
	private static function whole_number( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		return is_string( $value ) && 1 === preg_match( '/^[0-9]{1,10}$/', $value ) ? (int) $value : null;
	}

	/**
	 * A rule, shortened for a message.
	 *
	 * @param string $text The rule.
	 * @return string
	 */
	private static function excerpt( string $text ): string {
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/', ' ', $text );

		return strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '...' : $text;
	}

	/**
	 * Whether an array is a list: keys 0, 1, 2 and so on. An empty array is one.
	 *
	 * @param array $value The array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	// -----------------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------------

	/**
	 * Saves a submitted setting for a page.
	 *
	 * Valid rules replace the stored value and delete the 0.1.x policy. An empty
	 * list clears both, so the page is open to everyone. An invalid value changes
	 * nothing and comes back as a message. The caller has already checked who is
	 * saving and for what: this checks the value, and writes.
	 *
	 * @param int   $post_id Post id.
	 * @param mixed $input   The submitted value.
	 * @return string|null A message when the value was refused and nothing changed, null when it was saved.
	 */
	public static function save( int $post_id, $input ): ?string {
		$result = self::validate( $input, $post_id );

		if ( null !== $result['error'] ) {
			return $result['error'];
		}

		if ( null === $result['value'] ) {
			delete_post_meta( $post_id, Agend_Content_Access_Rules::META_KEY );
			delete_post_meta( $post_id, Agend_Content_Access_Policy::META_KEY );

			return null;
		}

		update_post_meta( $post_id, Agend_Content_Access_Rules::META_KEY, wp_slash( $result['value'] ) );
		delete_post_meta( $post_id, Agend_Content_Access_Policy::META_KEY );

		return null;
	}

	// -----------------------------------------------------------------------
	// REST
	// -----------------------------------------------------------------------

	/**
	 * The field's value for a response.
	 *
	 * Only for somebody who may edit THAT post. The `edit` context already needs
	 * that for a single item, but WordPress lets anybody who can edit posts at all
	 * ask for the `edit` context of a collection, so the check is made here for
	 * each page.
	 *
	 * @param array $object The response data so far, with the post's `id`.
	 * @return array|null
	 */
	public function rest_get( $object ): ?array {
		$post_id = is_array( $object ) && isset( $object['id'] ) ? (int) $object['id'] : 0;

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		return self::state( $post_id );
	}

	/**
	 * Saves the field when the post is updated.
	 *
	 * The value was checked before the post was written (`rest_pre_insert()`), so a
	 * refusal here is only a second line of defence. Permission is checked again
	 * rather than trusted from the route.
	 *
	 * @param mixed   $value The submitted value.
	 * @param WP_Post $post  The post.
	 * @return true|WP_Error
	 */
	public function rest_update( $value, $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return new WP_Error(
				'agend_content_access_forbidden',
				__( 'You are not allowed to change who can see this page.', 'agend-content-access' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$error = self::save( (int) $post->ID, $value );

		if ( null !== $error ) {
			return new WP_Error( self::ERROR_CODE, $error, array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Refuses a bad value before the post is written.
	 *
	 * Without this the post would be saved and the rules refused after it, so an
	 * author would be told their save failed on a page that had in fact saved. It
	 * does nothing when the request does not carry the field, which is every save
	 * that did not edit the panel.
	 *
	 * @param stdClass|WP_Error $prepared The post, as it will be written.
	 * @param WP_REST_Request   $request  The request.
	 * @return stdClass|WP_Error
	 */
	public function rest_pre_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! is_object( $request ) ) {
			return $prepared;
		}

		$value = $request->get_param( self::FIELD );

		if ( null === $value ) {
			return $prepared;
		}

		$id    = $request->get_param( 'id' );
		$check = self::validate( $value, is_numeric( $id ) ? (int) $id : 0 );

		if ( null !== $check['error'] ) {
			return new WP_Error( self::ERROR_CODE, $check['error'], array( 'status' => 400 ) );
		}

		return $prepared;
	}
}
