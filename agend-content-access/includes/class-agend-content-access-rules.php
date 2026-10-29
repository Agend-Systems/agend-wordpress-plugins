<?php
/**
 * The access rule list: one value object for a block, an Elementor section and a page.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may see something, as a list of `"<provider>:<value>"` rules.
 *
 * Until now a block, a section and a page each carried TWO things that did the
 * same job: an access policy (shaped for Agend's `@agend/access-policy`) and
 * display conditions (a rule list ported from the Iugo ESAC plugin). Both are
 * enforced on the server before render, and authors could not tell them apart.
 * This is the one thing they are read into, and `Agend_Content_Access_Access` is
 * the one place it is judged. The shape is
 *
 *   {rules: string[], match: "any"|"all", invert: bool}
 *
 * where a rule is `"agend_membership:is_member"`, `"agend_membership:<tier uuid>"`,
 * `"agend_segments:<segment uuid>"`, `"agend_entitlements:<gate key>"` or a
 * WordPress `"agend_wordpress:logged_in"` / `role_<id>` (and any provider a site
 * registers). A visitor passes when ANY (or ALL) of the rules hold; `invert`
 * turns that around, so a join banner can be shown to non-members only.
 *
 * THREE STATES, AND THE DIFFERENCE BETWEEN THEM IS THE POINT.
 *
 *   - NO RULES (`is_empty()`): nothing restricts the thing. It is the common case
 *     and must cost nothing.
 *   - RULES: judged by the evaluator. An inverted empty list is NOT "no rules"
 *     (see below).
 *   - UNREADABLE (`is_unreadable()`): something was stored that this version
 *     cannot read. It denies everyone, and it is a state of its own so that it can
 *     never be mistaken for "no rules", which is the mistake that publishes a
 *     page: a stored value we cannot read is not evidence that it is unrestricted
 *     (Decision 2.12).
 *
 * CONSTRUCTION NEVER THROWS AND NEVER WIDENS. A value that is not an array, a
 * rule that is not a string, a `match` that is neither `any` nor `all`, an
 * `invert` that is not a boolean: each yields the unreadable state. None is
 * "repaired", because a repair is a guess about what the author meant and the
 * guess that is cheapest to make is the one that grants.
 *
 * REVERSE OVER NOTHING IS NOBODY. `{rules: [], invert: true}` is not empty. The
 * condition engine has always read "the reverse of nothing" as nobody, a block
 * whose author switched Reverse on and has not chosen a rule yet is hidden from
 * everyone rather than shown to everyone, and that reading is kept. An editor
 * that wants "no rule" omits the rule list and the flag.
 *
 * COMPATIBILITY, WITH NO DATA MIGRATION. The readers below also read what the
 * 0.1.x and 0.2.0 releases stored: the page policy `_agend_access_policy`, an
 * Elementor element's `agend_access_mode` and its id lists, the `agend_conditions_*`
 * keys, and a block's v1 `agendAccess` keys (`mode`, `tierIds`, `segmentIds`,
 * `entitlementKeys`, `conditionsEnabled`, `conditions`, `conditionsMatch`,
 * `conditionsInvert`). The conversion is the one the plan agreed:
 *
 *   active_member         -> agend_membership:is_member
 *   selected_tiers        -> one agend_membership:<tier uuid> per tier, match any
 *   segments              -> one agend_segments:<uuid> per segment
 *   selected_entitlements -> one agend_entitlements:<gate key> per key
 *   public, inherit       -> no rules
 *
 * A set-mode that names nothing converts to UNREADABLE, not to an empty list: an
 * empty list is "no rules", and "the editor asked for plans and chose none" has
 * always meant nobody.
 *
 * THE INTERSECTION. An element or block can carry BOTH an old policy and enabled
 * old conditions, and until now both had to pass. Nothing may widen, so a rule set
 * can hold a list of sub-sets that are ANDed (`all_of()`), each one a plain
 * `{rules, match, invert}` set of its own. This exists for compatibility, and for
 * judging a block together with the page it belongs to; nothing writes it, and the
 * editors will only ever produce a single set. A composition is flat (its parts are
 * never compositions), can only narrow, and one unreadable part makes the whole
 * unreadable.
 *
 * Nothing here decides who is allowed. The rule set only says what was asked;
 * `Agend_Content_Access_Access::verdict()` answers.
 */
class Agend_Content_Access_Rules {

	/**
	 * Post meta key of a page's rules. Underscore-prefixed so WordPress treats it as
	 * protected, and never registered for REST, as `_agend_access_policy` is not.
	 *
	 * @var string
	 */
	const META_KEY = '_agend_access_rules';

	/**
	 * The REST field the block editor reads and writes a page's rules through. It
	 * lives in the edit context only, and the response filter removes it from every
	 * other (`Agend_Content_Access_Page_Rules`). Named here so the code that removes
	 * it need not load the code that registers it.
	 *
	 * @var string
	 */
	const REST_FIELD = 'agend_access_rules';

	/**
	 * How the rules combine: any one is enough, or every one is needed.
	 *
	 * The same values as `Agend_Content_Access_Conditions::MATCH_*`, restated so this
	 * class can be read without that engine loaded.
	 *
	 * @var string
	 */
	const MATCH_ANY = 'any';
	const MATCH_ALL = 'all';

	/**
	 * The keys of the new Elementor settings. The fallback keeps its existing key,
	 * `Agend_Content_Access_Fragment_Rules::FALLBACK_KEY`.
	 *
	 * @var string
	 */
	const ELEMENTOR_RULES_KEY  = 'agend_access_rules';
	const ELEMENTOR_MATCH_KEY  = 'agend_access_match';
	const ELEMENTOR_INVERT_KEY = 'agend_access_invert';

	/**
	 * What a page does with someone it does not admit. Read and exposed here; the
	 * page gate (`Agend_Content_Access_Frontend::gate_request()`) acts on it, and
	 * nothing in this class or the evaluator does.
	 *
	 * @var string
	 */
	const DENIED_PLACEHOLDER = 'placeholder';
	const DENIED_REDIRECT    = 'redirect';
	const DENIED_BACK        = 'back';

	/**
	 * The keys a stored rule set may carry, and the only ones: `rules`, `match`, `invert`
	 * and `fallbackId` for a plain set, `all_of` for a composition and `denied` for a
	 * page's denial settings (read apart, by `from_post()`). Anything else makes the
	 * value unreadable: see `from_array()`.
	 *
	 * @var string[]
	 */
	private const STORED_KEYS = array( 'rules', 'match', 'invert', 'fallbackId', 'all_of', 'denied' );

	/**
	 * The only top-level keys a block's `agendAccess` may carry and still be read: the
	 * current shape, the v1 keys the first panel wrote (`Block_Attributes::to_settings()`
	 * reads them), and `meta`, which is reserved for data with NO access effect and is
	 * ignored. The editor panel keeps the same list (`access-model.mjs`).
	 *
	 * A key outside it makes the block unreadable, so it is hidden from everyone. A later
	 * release that adds a key which narrows access (a `groups` list, say) must add it at the
	 * top level, and then an older version of this plugin, which cannot read it, denies the
	 * block and does not show it with the narrowing silently dropped.
	 */
	private const BLOCK_KEYS = array(
		'rules',
		'match',
		'invert',
		'fallbackId',
		'meta',
		'mode',
		'tierIds',
		'segmentIds',
		'entitlementKeys',
		'conditionsEnabled',
		'conditions',
		'conditionsMatch',
		'conditionsInvert',
	);

	/**
	 * This set's rules, trimmed and without blanks or duplicates. A composition has
	 * none of its own: see `leaves()`.
	 *
	 * @var string[]
	 */
	private array $rules = array();

	/**
	 * `MATCH_ANY` or `MATCH_ALL`.
	 *
	 * @var string
	 */
	private string $match = self::MATCH_ANY;

	/**
	 * Whether the result of the rules is reversed.
	 *
	 * @var bool
	 */
	private bool $invert = false;

	/**
	 * Whether something was stored that could not be read. Denies everyone.
	 *
	 * @var bool
	 */
	private bool $unreadable = false;

	/**
	 * Post id of the public content shown in this set's place when it denies, 0 for
	 * none.
	 *
	 * @var int
	 */
	private int $fallback_id = 0;

	/**
	 * The sub-sets that must ALL pass, when this is a composition. Empty for a plain
	 * set.
	 *
	 * @var Agend_Content_Access_Rules[]
	 */
	private array $parts = array();

	/**
	 * A page's denial settings: `{action, target_id, fallback_id}`.
	 *
	 * @var array{action: string, target_id: int, fallback_id: int}
	 */
	private array $denied = array(
		'action'      => self::DENIED_PLACEHOLDER,
		'target_id'   => 0,
		'fallback_id' => 0,
	);

	/**
	 * Built through the readers below, so no caller can construct a set that skipped
	 * the checks.
	 */
	private function __construct() {}

	// -----------------------------------------------------------------------
	// States
	// -----------------------------------------------------------------------

	/**
	 * A rule set with no rules: nothing restricts the thing.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * A rule set nobody satisfies, because what was stored could not be read.
	 *
	 * @return self
	 */
	public static function unreadable(): self {
		$set             = new self();
		$set->unreadable = true;

		return $set;
	}

	/**
	 * Members only: the reading the plugin has always given an audience it cannot
	 * make sense of, a block's attribute that is not an object or a mode this version
	 * does not know.
	 *
	 * @return self
	 */
	private static function members_only(): self {
		return self::set( array( Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':is_member' ), self::MATCH_ANY, false );
	}

	/**
	 * A plain set. Blank rules and repeats are dropped: a blank was never a rule
	 * (the engine skips it) and a repeat adds nothing.
	 *
	 * @param string[] $rules       Rule strings.
	 * @param string   $match       MATCH_ANY or MATCH_ALL.
	 * @param bool     $invert      Reverse the result.
	 * @param int      $fallback_id Replacement content, 0 for none.
	 * @return self
	 */
	private static function set( array $rules, string $match, bool $invert, int $fallback_id = 0 ): self {
		$set              = new self();
		$set->rules       = array_values( array_unique( $rules ) );
		$set->match       = self::MATCH_ALL === $match ? self::MATCH_ALL : self::MATCH_ANY;
		$set->invert      = $invert;
		$set->fallback_id = $fallback_id > 0 ? $fallback_id : 0;

		return $set;
	}

	/**
	 * Whether nothing restricts the thing.
	 *
	 * No rules, and not reversed: see "Reverse over nothing is nobody". An
	 * unreadable set is not empty.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		if ( $this->unreadable || array() !== $this->parts ) {
			return false;
		}

		return array() === $this->rules && ! $this->invert;
	}

	/**
	 * Whether something was stored that could not be read, which denies everyone.
	 *
	 * @return bool
	 */
	public function is_unreadable(): bool {
		return $this->unreadable;
	}

	// -----------------------------------------------------------------------
	// Accessors
	// -----------------------------------------------------------------------

	/**
	 * This set's own rules. A composition has none: ask `leaves()`.
	 *
	 * @return string[]
	 */
	public function rules(): array {
		return $this->rules;
	}

	/**
	 * `MATCH_ANY` or `MATCH_ALL`.
	 *
	 * @return string
	 */
	public function match(): string {
		return $this->match;
	}

	/**
	 * Whether the result is reversed.
	 *
	 * @return bool
	 */
	public function is_inverted(): bool {
		return $this->invert;
	}

	/**
	 * The plain sets that must ALL pass: this one, or the parts of a composition.
	 * An unreadable set has none, because it is never evaluated.
	 *
	 * @return Agend_Content_Access_Rules[]
	 */
	public function leaves(): array {
		if ( array() !== $this->parts ) {
			return $this->parts;
		}

		return $this->unreadable ? array() : array( $this );
	}

	/**
	 * The set that owns the replacement content, or null when none does.
	 *
	 * A block that carries only an audience policy has never shown replacement
	 * content, and one that carries conditions as well showed it only when the
	 * CONDITIONS excluded it. Keeping the content on the part it came from preserves
	 * exactly that: the caller asks whether THIS part denies, not whether the
	 * whole thing does.
	 *
	 * @return Agend_Content_Access_Rules|null
	 */
	public function fallback_owner(): ?self {
		foreach ( $this->leaves() as $leaf ) {
			if ( $leaf->fallback_id > 0 ) {
				return $leaf;
			}
		}

		return null;
	}

	/**
	 * The post id of the replacement content, 0 for none.
	 *
	 * @return int
	 */
	public function fallback_id(): int {
		$owner = $this->fallback_owner();

		return null === $owner ? 0 : $owner->fallback_id;
	}

	/**
	 * What a page does with someone it does not admit.
	 *
	 * Read and exposed for the layer that acts on it. Never consulted when deciding
	 * whether to allow: a malformed value here falls back to the placeholder, which
	 * is the safe action, and cannot make a page more or less restricted.
	 *
	 * @return array{action: string, target_id: int, fallback_id: int}
	 */
	public function denied(): array {
		return $this->denied;
	}

	/**
	 * The set as an array, in the stored shape.
	 *
	 * An unreadable set comes back as the nobody-set `{rules: [], invert: true}`,
	 * so the result reads back as something that still denies everyone rather than
	 * as no rules. A composition adds `all_of`, a list of its parts in the same
	 * shape, and `from_array()` reads it back.
	 *
	 * @return array{rules: string[], match: string, invert: bool, all_of?: array[]}
	 */
	public function to_array(): array {
		if ( $this->unreadable ) {
			return array(
				'rules'  => array(),
				'match'  => self::MATCH_ANY,
				'invert' => true,
			);
		}

		if ( array() !== $this->parts ) {
			return array(
				'rules'  => array(),
				'match'  => self::MATCH_ANY,
				'invert' => false,
				'all_of' => array_map(
					static function ( Agend_Content_Access_Rules $part ): array {
						return $part->to_array();
					},
					$this->parts
				),
			);
		}

		return array(
			'rules'  => $this->rules,
			'match'  => $this->match,
			'invert' => $this->invert,
		);
	}

	// -----------------------------------------------------------------------
	// Readers: the new shape
	// -----------------------------------------------------------------------

	/**
	 * Reads the new stored shape, `{rules, match, invert}`.
	 *
	 * Strict. `rules` must be a list of non-blank strings, `match` `any` or `all`, `invert` a
	 * boolean, and a key that is null is as good as absent. Anything else is
	 * unreadable. An empty array is no rules, and a non-empty list (which has no
	 * string keys) is not an object and is unreadable.
	 *
	 * AN OBJECT IS READ ONLY IF IT IS ONE THIS VERSION WROTE. It may carry the keys in
	 * `STORED_KEYS` and no others, and it must carry `rules` or `all_of`. An object with a
	 * key this version does not know, a later release's shape, a hand-written value, a
	 * stray write, used to be read for the keys it did know, and with none of them that is
	 * "no rules": a page with a value it could not read became public over its 0.1.x
	 * policy. Not being able to tell what a value means is not evidence that it means
	 * nothing, so it denies, as every other value this cannot read does.
	 *
	 * @param mixed $value Candidate rule set.
	 * @return self
	 */
	public static function from_array( $value ): self {
		if ( ! is_array( $value ) ) {
			return self::unreadable();
		}

		if ( array() === $value ) {
			return self::none();
		}

		if ( ! self::is_object_like( $value ) ) {
			return self::unreadable();
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, self::STORED_KEYS, true ) ) {
				return self::unreadable();
			}
		}

		if ( ! array_key_exists( 'rules', $value ) && ! isset( $value['all_of'] ) ) {
			return self::unreadable();
		}

		$leaf = self::read_leaf(
			$value['rules'] ?? null,
			$value['match'] ?? null,
			$value['invert'] ?? null,
			isset( $value['fallbackId'] ) && is_int( $value['fallbackId'] ) ? $value['fallbackId'] : 0
		);

		if ( ! isset( $value['all_of'] ) ) {
			return $leaf;
		}

		if ( ! is_array( $value['all_of'] ) || ! self::is_list( $value['all_of'] ) ) {
			return self::unreadable();
		}

		$sets = array( $leaf );

		foreach ( $value['all_of'] as $part ) {
			// A part is a plain set. A part that is itself a composition is read as
			// one too, and flattened by `all_of()`, which can only narrow.
			$sets[] = self::from_array( $part );
		}

		return self::all_of( ...$sets );
	}

	/**
	 * Reads one plain set from its three fields, strictly.
	 *
	 * @param mixed $rules       The rule list, or null.
	 * @param mixed $match       The match mode, or null.
	 * @param mixed $invert      The reverse flag, or null.
	 * @param int   $fallback_id Replacement content, 0 for none.
	 * @return self
	 */
	private static function read_leaf( $rules, $match, $invert, int $fallback_id ): self {
		$list = array();

		if ( null !== $rules ) {
			if ( ! is_array( $rules ) || ! self::is_list( $rules ) ) {
				return self::unreadable();
			}

			foreach ( $rules as $rule ) {
				if ( ! is_string( $rule ) ) {
					return self::unreadable();
				}

				$rule = trim( $rule );

				// A rule that is nothing but whitespace is not a rule and is not nothing: the
				// panel would show it as one, and a list this cannot say what it means is
				// unreadable, not shorter.
				if ( '' === $rule ) {
					return self::unreadable();
				}

				$list[] = $rule;
			}
		}

		if ( null !== $match && ( ! is_string( $match ) || ! in_array( $match, array( self::MATCH_ANY, self::MATCH_ALL ), true ) ) ) {
			return self::unreadable();
		}

		if ( null !== $invert && ! is_bool( $invert ) ) {
			return self::unreadable();
		}

		return self::set( $list, null === $match ? self::MATCH_ANY : $match, true === $invert, $fallback_id );
	}

	/**
	 * Every set in the list has to pass.
	 *
	 * What a block is judged by together with the page it belongs to, and what an
	 * element carrying both an old policy and old conditions is read as. Empty sets
	 * are dropped; one unreadable set makes the whole thing unreadable; what is left
	 * is flattened, so a composition never holds a composition.
	 *
	 * @param Agend_Content_Access_Rules ...$sets Sets that must all pass.
	 * @return self
	 */
	public static function all_of( self ...$sets ): self {
		$parts = array();

		foreach ( $sets as $set ) {
			if ( $set->unreadable ) {
				return self::unreadable();
			}

			foreach ( $set->leaves() as $leaf ) {
				if ( ! $leaf->is_empty() ) {
					$parts[] = $leaf;
				}
			}
		}

		if ( array() === $parts ) {
			return self::none();
		}

		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}

		$all        = new self();
		$all->parts = $parts;

		return $all;
	}

	// -----------------------------------------------------------------------
	// Readers: a page
	// -----------------------------------------------------------------------

	/**
	 * A page's rules.
	 *
	 * The new meta `_agend_access_rules` when it holds something, otherwise the
	 * 0.1.x `_agend_access_policy`, converted. The new meta wins outright when both
	 * exist: an author who chose "no rules" in the new panel has changed their mind
	 * about what the old policy said, and the two must not be intersected or that
	 * page could never be made public again. A cleared value (an empty string, which
	 * is how WordPress reports a deleted meta row it still lists) counts as absent.
	 *
	 * Absent means no rules and unreadable means nobody, as `Policy::enforced_for_post()`
	 * has always had it, so a rollback never publishes a page whose stored value this
	 * version cannot parse. The denial settings are parsed and exposed, and
	 * not acted on here.
	 *
	 * @param int $post_id Post id.
	 * @return self
	 */
	public static function from_post( int $post_id ): self {
		if ( $post_id <= 0 ) {
			return self::none();
		}

		if ( metadata_exists( 'post', $post_id, self::META_KEY ) ) {
			$stored = get_post_meta( $post_id, self::META_KEY, true );

			if ( '' !== $stored ) {
				$rules         = self::from_array( $stored );
				$rules->denied = self::read_denied( is_array( $stored ) ? ( $stored['denied'] ?? null ) : null );

				return $rules;
			}
		}

		// The enforced reading: no policy is no rules, a policy that cannot be read
		// comes back as the one nobody satisfies, and converts to unreadable.
		return self::from_policy( Agend_Content_Access_Policy::enforced_for_post( $post_id ) );
	}

	/**
	 * A page's denial settings, with the safe action for anything unreadable.
	 *
	 * @param mixed $value The stored `denied` value.
	 * @return array{action: string, target_id: int, fallback_id: int}
	 */
	private static function read_denied( $value ): array {
		$denied = array(
			'action'      => self::DENIED_PLACEHOLDER,
			'target_id'   => 0,
			'fallback_id' => 0,
		);

		if ( ! is_array( $value ) ) {
			return $denied;
		}

		if ( isset( $value['action'] ) && in_array( $value['action'], array( self::DENIED_PLACEHOLDER, self::DENIED_REDIRECT, self::DENIED_BACK ), true ) ) {
			$denied['action'] = $value['action'];
		}

		foreach ( array( 'target_id', 'fallback_id' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_int( $value[ $key ] ) && $value[ $key ] > 0 ) {
				$denied[ $key ] = $value[ $key ];
			}
		}

		return $denied;
	}

	// -----------------------------------------------------------------------
	// Readers: a block
	// -----------------------------------------------------------------------

	/**
	 * A block's rules, from its `agendAccess` attribute.
	 *
	 * Reads the new shape, `{rules, match, invert, fallbackId}`, AND the v1 shape the
	 * editor panel still writes (`mode`, `tierIds`, `segmentIds`, `entitlementKeys`,
	 * `conditionsEnabled`, `conditions`, `conditionsMatch`, `conditionsInvert`,
	 * `fallbackId`). An attribute carrying both has to satisfy both.
	 *
	 * The v1 keys are converted by `Block_Attributes::to_settings()` and read as an
	 * Elementor element's settings are, so a block and a section cannot disagree
	 * about what the same audience means. A present attribute that is not an object
	 * keeps the rule it has always had: it reads as members only, because somebody
	 * who wrote `"agendAccess":"members"` meant to restrict the block. Absent or
	 * null is no rules.
	 *
	 * AN OBJECT IS READ ONLY IF EVERY TOP-LEVEL KEY OF IT IS ONE THIS VERSION KNOWS
	 * (`BLOCK_KEYS`). One that is not, a later release's key, a hand-written value, is
	 * unreadable and hides the block from everyone, including `{rules: [A], groups: [...]}`:
	 * reading it for the keys it knows would drop a narrowing it does not know about.
	 * `meta` is the one reserved key that is ignored.
	 *
	 * @param array $parsed_block The block, as `parse_blocks()` returns it.
	 * @return self
	 */
	public static function from_block( array $parsed_block ): self {
		$attrs = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		$value = isset( $attrs[ Agend_Content_Access_Block_Attributes::ATTRIBUTE ] ) ? $attrs[ Agend_Content_Access_Block_Attributes::ATTRIBUTE ] : null;

		// Nearly every block on every page: no attribute is no rules, before anything
		// is read or built.
		if ( null === $value ) {
			return self::none();
		}

		// A list or a scalar keeps the reading above (members only); this is about objects.
		if ( is_array( $value ) && self::is_object_like( $value ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( ! in_array( $key, self::BLOCK_KEYS, true ) ) {
					return self::unreadable();
				}
			}
		}

		// Neutral settings for the v1 keys. For a value that is not an object this is
		// an unrecognised mode, which the policy reader turns into members only.
		$settings = Agend_Content_Access_Block_Attributes::settings_for_block( $parsed_block );
		$current  = null;

		if ( is_array( $value ) && self::is_object_like( $value ) && self::has_any_key( $value, array( 'rules', 'match', 'invert' ) ) ) {
			$current = self::read_leaf(
				$value['rules'] ?? null,
				$value['match'] ?? null,
				$value['invert'] ?? null,
				isset( $value['fallbackId'] ) && is_int( $value['fallbackId'] ) ? $value['fallbackId'] : 0
			);

			// The replacement content belongs to the new set, not to a v1 condition
			// list that happens to sit beside it.
			unset( $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ] );
		}

		return self::all_of( null === $current ? self::none() : $current, self::from_elementor_settings( $settings ) );
	}

	// -----------------------------------------------------------------------
	// Readers: an Elementor element, and the neutral settings array
	// -----------------------------------------------------------------------

	/**
	 * An Elementor element's rules, from its settings.
	 *
	 * The new keys (`agend_access_rules`, `agend_access_match`, `agend_access_invert`)
	 * and the keys the earlier releases wrote: `agend_access_mode` with its id lists,
	 * and the `agend_conditions_*` keys. Every part that is present has to pass, so an
	 * element that carries an old policy and enabled old conditions is judged by
	 * both, exactly as before.
	 *
	 * This is also the reader of the neutral settings array a block's v1 attribute is
	 * converted to, which is why it is the one place the old keys are understood.
	 *
	 * @param array $settings Element settings.
	 * @return self
	 */
	public static function from_elementor_settings( array $settings ): self {
		if ( array() === $settings ) {
			return self::none();
		}

		$fallback_id = isset( $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ] ) && is_scalar( $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ] )
			? (int) $settings[ Agend_Content_Access_Fragment_Rules::FALLBACK_KEY ]
			: 0;

		$current = self::elementor_current_set( $settings, $fallback_id );
		$policy  = self::from_policy( Agend_Content_Access_Policy::from_fragment_settings( $settings ) );

		$conditions = Agend_Content_Access_Fragment_Rules::conditions_enabled( $settings )
			? self::elementor_conditions_set( $settings, null === $current ? $fallback_id : 0 )
			: self::none();

		return self::all_of( null === $current ? self::none() : $current, $policy, $conditions );
	}

	/**
	 * The set the new Elementor keys describe, or null when none of them is present.
	 *
	 * Elementor stores an unset control as an empty string, so an empty string is
	 * absence here. A switch is `'yes'` when on; anything else that is not empty is
	 * not a switch this reads, and is unreadable.
	 *
	 * A set that says nothing is absence too. Elementor reads a control nobody touched
	 * as its default, an empty rule list, and a registered control always has one, so
	 * "present but says nothing" is what every element with the controls looks like. It
	 * must not take the replacement content: an element that has only old display
	 * conditions would lose its "Show instead" to an empty set that is then dropped.
	 * An unreadable set, and a reverse of nothing (which is nobody), say something.
	 *
	 * @param array $settings    Element settings.
	 * @param int   $fallback_id Replacement content, 0 for none.
	 * @return self|null
	 */
	private static function elementor_current_set( array $settings, int $fallback_id ): ?self {
		$rules  = $settings[ self::ELEMENTOR_RULES_KEY ] ?? null;
		$match  = $settings[ self::ELEMENTOR_MATCH_KEY ] ?? null;
		$invert = $settings[ self::ELEMENTOR_INVERT_KEY ] ?? null;

		$rules  = '' === $rules ? null : $rules;
		$match  = '' === $match ? null : $match;
		$invert = '' === $invert || false === $invert ? null : $invert;

		if ( null === $rules && null === $match && null === $invert ) {
			return null;
		}

		$set = self::read_leaf( $rules, $match, 'yes' === $invert ? true : $invert, $fallback_id );

		return $set->is_empty() ? null : $set;
	}

	/**
	 * The set the old display-condition keys describe. Only asked when they are
	 * switched on.
	 *
	 * Reads them as the condition engine did. A condition that is not a string is
	 * unreadable, as the engine reported it malformed and denied whatever the match
	 * and reverse said. A match that is not `all` is `any`, and only `'yes'` reverses.
	 *
	 * @param array $settings    Element settings.
	 * @param int   $fallback_id Replacement content, 0 for none.
	 * @return self
	 */
	private static function elementor_conditions_set( array $settings, int $fallback_id ): self {
		$raw  = $settings[ Agend_Content_Access_Fragment_Rules::CONDITIONS_KEY ] ?? null;
		$list = array();

		if ( null !== $raw && '' !== $raw ) {
			if ( ! is_array( $raw ) ) {
				return self::unreadable();
			}

			foreach ( $raw as $condition ) {
				if ( ! is_string( $condition ) ) {
					return self::unreadable();
				}

				$condition = trim( $condition );

				if ( '' !== $condition ) {
					$list[] = $condition;
				}
			}
		}

		$match  = isset( $settings[ Agend_Content_Access_Fragment_Rules::MATCH_KEY ] ) && self::MATCH_ALL === $settings[ Agend_Content_Access_Fragment_Rules::MATCH_KEY ]
			? self::MATCH_ALL
			: self::MATCH_ANY;
		$invert = isset( $settings[ Agend_Content_Access_Fragment_Rules::INVERT_KEY ] ) && 'yes' === $settings[ Agend_Content_Access_Fragment_Rules::INVERT_KEY ];

		return self::set( $list, $match, $invert, $fallback_id );
	}

	// -----------------------------------------------------------------------
	// Compatibility: an access policy as rules, and rules as a policy
	// -----------------------------------------------------------------------

	/**
	 * An access policy, read as rules.
	 *
	 * See the class docblock for the conversion. `null`, `public` and `inherit` are
	 * no rules. A set-mode with nothing in its list, an entry that does not have the
	 * shape of what the mode names, or a mode this version does not know, is
	 * unreadable: a rule built from a malformed id would be read as a slug.
	 *
	 * @param array|null $policy Canonical policy, or null for none.
	 * @return self
	 */
	public static function from_policy( ?array $policy ): self {
		if ( null === $policy ) {
			return self::none();
		}

		$mode = $policy['mode'] ?? null;

		if ( Agend_Content_Access_Policy::MODE_PUBLIC === $mode || Agend_Content_Access_Policy::MODE_INHERIT === $mode ) {
			return self::none();
		}

		if ( Agend_Content_Access_Policy::MODE_MEMBERS === $mode ) {
			return self::members_only();
		}

		if ( ! is_string( $mode ) || ! isset( Agend_Content_Access_Policy::SET_MODE_KEYS[ $mode ] ) ) {
			return self::unreadable();
		}

		$list = $policy[ Agend_Content_Access_Policy::SET_MODE_KEYS[ $mode ] ] ?? null;

		if ( ! is_array( $list ) || array() === $list ) {
			return self::unreadable();
		}

		$provider = self::provider_for_mode( $mode );
		$pattern  = Agend_Content_Access_Policy::MODE_ENTITLEMENTS === $mode
			? Agend_Content_Access_Policy::ENTITLEMENT_KEY_PATTERN
			: Agend_Content_Access_Policy::UUID_PATTERN;
		$rules    = array();

		foreach ( $list as $entry ) {
			if ( ! is_string( $entry ) || 1 !== preg_match( $pattern, $entry ) ) {
				return self::unreadable();
			}

			$rules[] = $provider . ':' . $entry;
		}

		return self::set( $rules, self::MATCH_ANY, false );
	}

	/**
	 * The provider a set-mode's entries belong to.
	 *
	 * @param string $mode A key of `Policy::SET_MODE_KEYS`.
	 * @return string
	 */
	private static function provider_for_mode( string $mode ): string {
		switch ( $mode ) {
			case Agend_Content_Access_Policy::MODE_SEGMENTS:
				return Agend_Content_Access_Condition_Providers::SEGMENTS;
			case Agend_Content_Access_Policy::MODE_ENTITLEMENTS:
				return Agend_Content_Access_Condition_Providers::ENTITLEMENTS;
			default:
				return Agend_Content_Access_Condition_Providers::MEMBERSHIP;
		}
	}

	/**
	 * The set as an `@agend/access-policy` arm, for export to Agend.
	 *
	 * Null for no rules. Otherwise the policy the rules say exactly, when one does,
	 * and the unsatisfiable `{"mode":"selected_tiers","tier_ids":[]}` for everything
	 * else, so what Agend serves is never MORE than the rules allow. It is fail
	 * closed and invisible to authors: a rule set that has no policy form (a role, a
	 * reverse, a custom provider, "all of" two plans) hides the content from everyone
	 * in the Agend projection until the projection can say it.
	 *
	 * A plain set is expressible when it names one kind of thing and Agend defines
	 * that kind: the rules are all `agend_membership` (either `is_member` alone, or
	 * tier ids), all `agend_segments` (ids), or all `agend_entitlements` (gate keys);
	 * the match is `any`, or there is just the one rule; and it is not reversed. Slugs
	 * are not expressible, because a policy names plans and segments by id, and the
	 * legacy ESAC provider names are not recognised here.
	 *
	 * A composition is expressible when every part is, and is folded with
	 * `Policy::effective_policy()`, as a fragment has always been folded into its
	 * document. Whatever comes out is checked with `Policy::parse()`, so this can
	 * only ever return a policy the gateway will accept, or the unsatisfiable one.
	 * An unreadable set exports as the unsatisfiable policy.
	 *
	 * @return array|null
	 */
	public function to_policy(): ?array {
		if ( $this->unreadable ) {
			return Agend_Content_Access_Policy::UNSATISFIABLE;
		}

		if ( $this->is_empty() ) {
			return null;
		}

		$policy = null;

		foreach ( $this->leaves() as $leaf ) {
			$own = $leaf->plain_policy();

			if ( false === $own ) {
				return Agend_Content_Access_Policy::UNSATISFIABLE;
			}

			$policy = Agend_Content_Access_Policy::effective_policy( $policy, $own );
		}

		// A non-empty set that folded to nothing is not something to export as public.
		return null === $policy ? Agend_Content_Access_Policy::UNSATISFIABLE : $policy;
	}

	/**
	 * The policy one plain set says exactly.
	 *
	 * @return array|false A valid policy, or false when the set has no policy form.
	 */
	private function plain_policy() {
		if ( $this->invert || array() === $this->rules ) {
			return false;
		}

		if ( count( $this->rules ) > 1 && self::MATCH_ALL === $this->match ) {
			return false;
		}

		$provider = null;
		$values   = array();

		foreach ( $this->rules as $rule ) {
			$at = strpos( $rule, ':' );

			if ( false === $at || 0 === $at || $at === strlen( $rule ) - 1 ) {
				return false;
			}

			$rule_provider = substr( $rule, 0, $at );

			if ( null !== $provider && $provider !== $rule_provider ) {
				return false;
			}

			$provider = $rule_provider;
			$values[] = substr( $rule, $at + 1 );
		}

		switch ( $provider ) {
			case Agend_Content_Access_Condition_Providers::MEMBERSHIP:
				$candidate = array( 'is_member' ) === $values
					? array( 'mode' => Agend_Content_Access_Policy::MODE_MEMBERS )
					: array(
						'mode'     => Agend_Content_Access_Policy::MODE_TIERS,
						'tier_ids' => $values,
					);
				break;
			case Agend_Content_Access_Condition_Providers::SEGMENTS:
				$candidate = array(
					'mode'        => Agend_Content_Access_Policy::MODE_SEGMENTS,
					'segment_ids' => $values,
				);
				break;
			case Agend_Content_Access_Condition_Providers::ENTITLEMENTS:
				$candidate = array(
					'mode'             => Agend_Content_Access_Policy::MODE_ENTITLEMENTS,
					'entitlement_keys' => $values,
				);
				break;
			default:
				// A WordPress rule, a legacy alias, or a provider a site registered:
				// not something Agend defines.
				return false;
		}

		// The parser is the arbiter of what is a valid id or key, so a slug that
		// stands where a plan id belongs, or `is_member` beside a plan, is refused.
		$parsed = Agend_Content_Access_Policy::parse( $candidate );

		return null === $parsed ? false : $parsed;
	}

	// -----------------------------------------------------------------------
	// Shapes
	// -----------------------------------------------------------------------

	/**
	 * Whether an array could have come from a JSON object.
	 *
	 * An empty array might, and is handled by the callers before this. A non-empty
	 * one does only if it has a string key: a JSON list decodes to integer keys alone.
	 *
	 * @param array $value Decoded value.
	 * @return bool
	 */
	private static function is_object_like( array $value ): bool {
		foreach ( array_keys( $value ) as $key ) {
			if ( is_string( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an array is a list: keys 0..n-1 in order. An empty array is.
	 * (`array_is_list()` needs PHP 8.1.)
	 *
	 * @param array $value Candidate list.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether the array has at least one of the keys, with a value that is not null.
	 *
	 * @param array    $value Array.
	 * @param string[] $keys  Keys.
	 * @return bool
	 */
	private static function has_any_key( array $value, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( isset( $value[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}
}
