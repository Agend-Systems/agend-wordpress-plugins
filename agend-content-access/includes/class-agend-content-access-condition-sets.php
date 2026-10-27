<?php
/**
 * The condition vocabulary offered to an editor.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the grouped list of conditions an editor can choose from
 * (SPEC-CMS-20260727 US-6.1, reframed as ESAC feature parity).
 *
 * Mirrors the reference plugin's `iugo_esac_filter_condition_sets`: a map of
 * provider to a label and its available values. The editor picks
 * `"<provider>:<value>"` strings, which is the same shape already stored in
 * `agend_cp_access_conditions` on every existing site.
 *
 * The four sets come from four different places, and the difference matters
 * for what an editor sees when something is wrong:
 *
 *   - WordPress roles are local and always available.
 *   - Membership plans come from the cached tier catalogue, which degrades to
 *     an empty list on an outage rather than failing the editor screen.
 *   - Segments and entitlements come from the same cached catalogue sections
 *     the page panel and the Elementor controls read, so there is one cache and
 *     one gateway call per list however many surfaces show it. The entitlement
 *     list is read through a Core wrapper that an older Core does not have;
 *     without it the group is simply empty.
 *
 * A set that cannot be loaded is offered EMPTY rather than omitted, so the
 * group heading still appears and the editor can see that the option exists
 * but the list could not be fetched. Dropping the group entirely would read as
 * "this site has no segments", which is a different and misleading claim.
 */
class Agend_Content_Access_Condition_Sets {

	/**
	 * The full grouped vocabulary.
	 *
	 * @return array<string, array{label: string, conditions: array<string, string>}>
	 */
	public static function all(): array {
		$sets = array(
			Agend_Content_Access_Condition_Providers::WORDPRESS    => array(
				'label'      => __( 'WordPress user', 'agend-content-access' ),
				'conditions' => self::wordpress_conditions(),
			),
			Agend_Content_Access_Condition_Providers::MEMBERSHIP   => array(
				'label'      => __( 'Membership', 'agend-content-access' ),
				'conditions' => self::membership_conditions(),
			),
			Agend_Content_Access_Condition_Providers::SEGMENTS     => array(
				'label'      => __( 'Segments', 'agend-content-access' ),
				'conditions' => self::segment_conditions(),
			),
			Agend_Content_Access_Condition_Providers::ENTITLEMENTS => array(
				'label'      => __( 'Entitlements', 'agend-content-access' ),
				'conditions' => self::entitlement_conditions(),
			),
		);

		/**
		 * Filters the condition vocabulary offered to editors.
		 *
		 * A site adding its own provider registers the matching checker through
		 * `agend_content_access_check_condition` as well. Adding a set here
		 * WITHOUT a checker produces conditions that always deny, because an
		 * unrecognised provider is a denial by design.
		 *
		 * @param array $sets Provider key to label and conditions.
		 */
		return (array) apply_filters( 'agend_content_access_condition_sets', $sets );
	}

	/**
	 * Login state and every registered role.
	 *
	 * @return array<string, string>
	 */
	public static function wordpress_conditions(): array {
		$conditions = array(
			'logged_in'  => __( 'Signed in', 'agend-content-access' ),
			'logged_out' => __( 'Signed out', 'agend-content-access' ),
		);

		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->roles as $id => $role ) {
				$name = isset( $role['name'] ) ? (string) $role['name'] : (string) $id;

				$conditions[ 'role_' . $id ] = sprintf(
					/* translators: %s: WordPress role name. */
					__( 'Role: %s', 'agend-content-access' ),
					$name
				);
			}
		}

		return $conditions;
	}

	/**
	 * Member standing and the tenant's plans.
	 *
	 * Plans are offered by SLUG, not by UUID. The stored legacy conditions name
	 * slugs, and a slug is what an editor can recognise in a list; the checker
	 * matches either, so both authoring styles resolve.
	 *
	 * @return array<string, string>
	 */
	public static function membership_conditions(): array {
		$conditions = array(
			'is_member' => __( 'Holds a current membership', 'agend-content-access' ),
		);

		$catalogue = Agend_Content_Access_Catalogue::get();

		foreach ( Agend_Content_Access_Catalogue::selectable( $catalogue['plans'] ) as $plan ) {
			$slug = isset( $plan['slug'] ) && '' !== (string) $plan['slug']
				? (string) $plan['slug']
				: (string) $plan['id'];

			$conditions[ $slug ] = sprintf(
				/* translators: %s: membership plan name. */
				__( 'Plan: %s', 'agend-content-access' ),
				(string) $plan['name']
			);
		}

		return $conditions;
	}

	/**
	 * The tenant's segments, keyed by slug.
	 *
	 * Read from the catalogue's segments section, whose docblock says why the
	 * admin segments endpoint is the right one for an editor screen and not for
	 * deciding what a visitor sees. Segments are keyed by SLUG, not id, for
	 * compatibility with the conditions already stored on every existing site.
	 * A segment with no slug cannot be named by a stored condition and is left
	 * out; the page panel and Elementor controls, which store the id, still
	 * offer it.
	 *
	 * A section that failed is empty or stale, never an error here: the group
	 * still appears so the editor can see the option exists and the list simply
	 * could not be fetched. The reason is shown by the surfaces that have room
	 * for it, and a failure is never cached.
	 *
	 * @return array<string, string> Segment slug to name.
	 */
	public static function segment_conditions(): array {
		$conditions = array();

		foreach ( Agend_Content_Access_Catalogue::segments()['items'] as $segment ) {
			if ( '' === $segment['slug'] ) {
				continue;
			}

			$conditions[ $segment['slug'] ] = $segment['name'];
		}

		return $conditions;
	}

	/**
	 * The tenant's entitlement types, keyed by gate key.
	 *
	 * Read from the catalogue's entitlements section, which probes Core's
	 * `agend_apps_crm_get_entitlement_types()` with `function_exists()`. A Core
	 * that predates the wrapper then leaves the group present but empty, so it
	 * reads as "the list could not be fetched" and not as "this site has no
	 * entitlements".
	 *
	 * A platform gate is labelled "Platform:" so an editor can tell a capability
	 * the platform defines from one an administrator authored in one flat list;
	 * the label is the catalogue's, so the same entitlement reads the same here
	 * as in the page panel. An `unrecognised` key is listed under its own name,
	 * unprefixed, so a holder of it can still be targeted. Rows without a
	 * well-formed gate key never reach this far: the catalogue drops them,
	 * because a key that fails the pattern could never match a held key.
	 *
	 * @return array<string, string> Gate key to label.
	 */
	public static function entitlement_conditions(): array {
		$conditions = array();

		foreach ( Agend_Content_Access_Catalogue::entitlements()['items'] as $entitlement ) {
			$conditions[ $entitlement['gate_key'] ] = Agend_Content_Access_Catalogue::entitlement_label( $entitlement );
		}

		return $conditions;
	}

	/**
	 * One group of the vocabulary.
	 *
	 * @param string $key   Provider key, which is the prefix of every value in it.
	 * @param string $label Heading the picker shows.
	 * @param array  $items Items, already shaped.
	 * @param bool   $stale Whether the list is the last known copy after a failed refresh.
	 * @return array{key: string, label: string, stale: bool, items: array}
	 */
	private static function vocabulary_group( string $key, string $label, array $items, bool $stale = false ): array {
		return array(
			'key'   => $key,
			'label' => $label,
			'stale' => $stale,
			'items' => array_values( $items ),
		);
	}

	/**
	 * The rules an author can choose, grouped, for the block editor's picker and
	 * flattened for Elementor's.
	 *
	 * The one source of the vocabulary: the `/conditions` route serves it to the block
	 * panel, and `rule_options()` reads it into the Elementor control, so the two
	 * editors offer the same rules under the same names.
	 *
	 * Every item is a rule exactly as a block stores it, `"<provider>:<value>"`, so
	 * the script never has to know how a rule is spelled. New rules name plans and
	 * segments by ID, and entitlements by gate key; a plan's or segment's slug is
	 * offered as an alias, because slug rules still evaluate and older blocks and
	 * imported conditions hold them, and the picker then recognises, labels and
	 * removes them like the ID rule itself without offering a second choice.
	 *
	 * ```
	 * {
	 *   "groups": [
	 *     { "key": "agend_membership", "label": "Membership", "stale": false, "items": [
	 *       { "value": "agend_membership:is_member", "label": "Any current member", "kind": "member" },
	 *       { "value": "agend_membership:<tier uuid>", "label": "Gold", "kind": "plan",
	 *         "aliases": [ "agend_membership:gold" ] } ] },
	 *     { "key": "agend_segments", ... "kind": "segment" },
	 *     { "key": "agend_entitlements", ... "kind": "entitlement" or "platform", "description": "..." },
	 *     { "key": "agend_wordpress", ... "kind": "login" or "role" }
	 *   ],
	 *   "fallbacks": [ { "id": 12, "title": "Page: Join us", "type": "page" } ],
	 *   "errors": [ { "group": "agend_entitlements", "message": "..." } ]
	 * }
	 * ```
	 *
	 * `label` is the plain name ("Gold"); the picker composes "Plan: Gold" from the
	 * item's `kind`. `inactive: true` marks a plan that was switched off: it is not
	 * offered for a new rule, but is kept so a block that names one can say which.
	 * Platform gates come first in the entitlements, ahead of the authored ones, so
	 * the few capabilities the platform defines are not buried among hundreds.
	 *
	 * A group whose list could not be loaded is present with its last known items
	 * (possibly none) and `stale: true`, and its reason is in `errors`, so the
	 * picker can show the message and say "this list may be unavailable" and not
	 * "this site has none". `errors` is a list of `{group, message}`: one per list
	 * that failed, in words an editor can act on (for example the API key scope the
	 * entitlement list needs).
	 *
	 * A group a site adds through the `agend_content_access_condition_sets` filter
	 * follows the four built in ones, each of its values joined to the provider key
	 * as `kind: "other"`. The four built-in groups are read from the catalogue and
	 * not from that filter, because the filter's plans and segments are keyed by
	 * slug.
	 *
	 * `fallbacks` is public content only, with no password-protected post either,
	 * from `fallback_choices()`, and does not include the "Nothing" choice: the panel
	 * adds its own. It is the choices for "show instead" and for a page's placeholder:
	 * pages, synced patterns and, when Elementor is active, its templates. `title` is
	 * what an editor reads, labelled with the kind ("Page: Join us", "Pattern:
	 * Members-only content", "Elementor template: Join banner"), and `type` is `page`,
	 * `pattern` or `elementor_template` for a panel that must offer only some of them:
	 * a redirect goes to a page, and a pattern or a template has no address of its own.
	 * Every entry is checked rather than trusted: one that is not the documented shape
	 * is skipped, not repaired.
	 *
	 * @param bool $refresh Optional. Read the lists from the gateway again instead of the cache.
	 * @return array{groups: array<int, array>, fallbacks: array<int, array>, errors: array<int, array>}
	 */
	public static function vocabulary( bool $refresh = false ): array {
		$rules = self::rule_groups( $refresh );

		return array(
			'groups'    => $rules['groups'],
			'fallbacks' => self::fallback_list(),
			'errors'    => $rules['errors'],
		);
	}

	/**
	 * The rule groups and the reasons a list is missing, without the replacement
	 * content, which is a query of its own and not every caller wants it.
	 *
	 * See `vocabulary()` for the shape.
	 *
	 * @param bool $refresh Optional. Read the lists from the gateway again instead of the cache.
	 * @return array{groups: array<int, array>, errors: array<int, array>}
	 */
	public static function rule_groups( bool $refresh = false ): array {
		$sections = Agend_Content_Access_Catalogue::sections( $refresh );
		$groups   = array();
		$errors   = array();

		// Membership: the standing rule, then every plan, by id.
		$membership = array(
			array(
				'value' => Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':is_member',
				'label' => __( 'Any current member', 'agend-content-access' ),
				'kind'  => 'member',
			),
		);

		foreach ( $sections['plans']['items'] as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['id'] ) ) {
				continue;
			}

			$id   = (string) $plan['id'];
			$slug = isset( $plan['slug'] ) ? (string) $plan['slug'] : '';
			$item = array(
				'value'   => Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':' . $id,
				'label'   => isset( $plan['name'] ) && '' !== (string) $plan['name'] ? (string) $plan['name'] : $id,
				'kind'    => 'plan',
				'aliases' => '' !== $slug && $slug !== $id ? array( Agend_Content_Access_Condition_Providers::MEMBERSHIP . ':' . $slug ) : array(),
			);

			if ( empty( $plan['active'] ) ) {
				$item['inactive'] = true;
			}

			$membership[] = $item;
		}

		$groups[] = self::vocabulary_group(
			Agend_Content_Access_Condition_Providers::MEMBERSHIP,
			__( 'Membership', 'agend-content-access' ),
			$membership,
			$sections['plans']['stale']
		);

		// Segments, by id.
		$segments = array();

		foreach ( $sections['segments']['items'] as $segment ) {
			if ( ! is_array( $segment ) || empty( $segment['id'] ) ) {
				continue;
			}

			$id   = (string) $segment['id'];
			$slug = isset( $segment['slug'] ) ? (string) $segment['slug'] : '';

			$segments[] = array(
				'value'   => Agend_Content_Access_Condition_Providers::SEGMENTS . ':' . $id,
				'label'   => isset( $segment['name'] ) && '' !== (string) $segment['name'] ? (string) $segment['name'] : ( '' !== $slug ? $slug : $id ),
				'kind'    => 'segment',
				'aliases' => '' !== $slug && $slug !== $id ? array( Agend_Content_Access_Condition_Providers::SEGMENTS . ':' . $slug ) : array(),
			);
		}

		$groups[] = self::vocabulary_group(
			Agend_Content_Access_Condition_Providers::SEGMENTS,
			__( 'Segments', 'agend-content-access' ),
			$segments,
			$sections['segments']['stale']
		);

		// Entitlements, by gate key, with the platform's own capabilities first.
		$platform     = array();
		$entitlements = array();

		foreach ( $sections['entitlements']['items'] as $entitlement ) {
			if ( ! is_array( $entitlement ) || empty( $entitlement['gate_key'] ) ) {
				continue;
			}

			$key  = (string) $entitlement['gate_key'];
			$gate = isset( $entitlement['kind'] ) && 'platform-gate' === $entitlement['kind'];
			$item = array(
				'value' => Agend_Content_Access_Condition_Providers::ENTITLEMENTS . ':' . $key,
				'label' => isset( $entitlement['name'] ) && '' !== (string) $entitlement['name'] ? (string) $entitlement['name'] : $key,
				'kind'  => $gate ? 'platform' : 'entitlement',
			);

			if ( isset( $entitlement['description'] ) && '' !== (string) $entitlement['description'] ) {
				$item['description'] = (string) $entitlement['description'];
			}

			if ( $gate ) {
				$platform[] = $item;
			} else {
				$entitlements[] = $item;
			}
		}

		$groups[] = self::vocabulary_group(
			Agend_Content_Access_Condition_Providers::ENTITLEMENTS,
			__( 'Entitlements', 'agend-content-access' ),
			array_merge( $platform, $entitlements ),
			$sections['entitlements']['stale']
		);

		// WordPress: signed in or out, and every role.
		$wordpress = array(
			array(
				'value' => Agend_Content_Access_Condition_Providers::WORDPRESS . ':logged_in',
				'label' => __( 'Signed in', 'agend-content-access' ),
				'kind'  => 'login',
			),
			array(
				'value' => Agend_Content_Access_Condition_Providers::WORDPRESS . ':logged_out',
				'label' => __( 'Signed out', 'agend-content-access' ),
				'kind'  => 'login',
			),
		);

		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->roles as $id => $role ) {
				$wordpress[] = array(
					'value' => Agend_Content_Access_Condition_Providers::WORDPRESS . ':role_' . $id,
					'label' => isset( $role['name'] ) && '' !== (string) $role['name'] ? (string) $role['name'] : (string) $id,
					'kind'  => 'role',
				);
			}
		}

		$groups[] = self::vocabulary_group(
			Agend_Content_Access_Condition_Providers::WORDPRESS,
			__( 'WordPress', 'agend-content-access' ),
			$wordpress
		);

		// Anything a site added through the filter.
		$built_in = array(
			Agend_Content_Access_Condition_Providers::WORDPRESS,
			Agend_Content_Access_Condition_Providers::MEMBERSHIP,
			Agend_Content_Access_Condition_Providers::SEGMENTS,
			Agend_Content_Access_Condition_Providers::ENTITLEMENTS,
		);

		foreach ( self::all() as $provider => $set ) {
			$provider = (string) $provider;

			if ( in_array( $provider, $built_in, true ) || ! is_array( $set ) ) {
				continue;
			}

			$items = array();

			foreach ( isset( $set['conditions'] ) && is_array( $set['conditions'] ) ? $set['conditions'] : array() as $value => $label ) {
				$items[] = array(
					'value' => $provider . ':' . $value,
					'label' => is_scalar( $label ) ? (string) $label : (string) $value,
					'kind'  => 'other',
				);
			}

			$groups[] = self::vocabulary_group(
				$provider,
				isset( $set['label'] ) && is_scalar( $set['label'] ) && '' !== (string) $set['label'] ? (string) $set['label'] : $provider,
				$items
			);
		}

		// Why a list is empty or out of date, in words an editor can act on.
		$sources = array(
			Agend_Content_Access_Condition_Providers::MEMBERSHIP   => 'plans',
			Agend_Content_Access_Condition_Providers::SEGMENTS     => 'segments',
			Agend_Content_Access_Condition_Providers::ENTITLEMENTS => 'entitlements',
		);

		foreach ( $sources as $provider => $section ) {
			$message = isset( $sections[ $section ]['error'] ) ? (string) $sections[ $section ]['error'] : '';

			if ( '' !== $message ) {
				$errors[] = array(
					'group'   => $provider,
					'message' => $message,
				);
			}
		}


		return array(
			'groups' => $groups,
			'errors' => $errors,
		);
	}

	/**
	 * The replacement content on offer, as the block panel lists it.
	 *
	 * Every entry is checked rather than trusted: one that is not the documented shape
	 * is skipped, not repaired.
	 *
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	private static function fallback_list(): array {
		$fallbacks = array();

		foreach ( self::fallback_choices() as $choice ) {
			if ( ! is_array( $choice ) || empty( $choice['id'] ) || ! isset( $choice['label'], $choice['type'] ) || ! is_scalar( $choice['label'] ) ) {
				continue;
			}

			$fallbacks[] = array(
				'id'    => (int) $choice['id'],
				'title' => (string) $choice['label'],
				'type'  => (string) $choice['type'],
			);
		}

		return $fallbacks;
	}

	/**
	 * The rules flattened for an Elementor SELECT2 control: rule to label.
	 *
	 * Elementor's multi-select takes a flat value-to-label map, so each label says what
	 * kind of rule it is, as a block's chip does: "Plan: Gold" and "Segment: Gold" never
	 * read alike, a platform capability reads "Platform: Directory access" and a bare
	 * "Signed in" stands as it is. Groups keep the order the vocabulary lists them in.
	 * A plan that was switched off is left out, as it is from a block's results: it can
	 * no longer be chosen, and only the rule list a block already holds names one.
	 *
	 * Only the rule itself is offered, never a slug alias of it: one choice per rule.
	 *
	 * @param bool $refresh Optional. Read the lists from the gateway again instead of the cache.
	 * @return array<string, string>
	 */
	public static function rule_options( bool $refresh = false ): array {
		$options = array();

		foreach ( self::rule_groups( $refresh )['groups'] as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( ! empty( $item['inactive'] ) ) {
					continue;
				}

				$options[ $item['value'] ] = self::rule_label( $item, $group['label'] );
			}
		}

		return $options;
	}

	/**
	 * The label a rule carries in a list that has no groups: what kind it is, then its
	 * name, the same words the block panel's chips use.
	 *
	 * @param array  $item        An item of a vocabulary group.
	 * @param string $group_label The heading of the group it is in, for a rule a site's
	 *                            own provider added.
	 * @return string
	 */
	public static function rule_label( array $item, string $group_label ): string {
		$name = (string) $item['label'];

		switch ( isset( $item['kind'] ) ? (string) $item['kind'] : '' ) {
			case 'plan':
				/* translators: %s: membership plan name. */
				return sprintf( __( 'Plan: %s', 'agend-content-access' ), $name );
			case 'segment':
				/* translators: %s: segment name. */
				return sprintf( __( 'Segment: %s', 'agend-content-access' ), $name );
			case 'entitlement':
				/* translators: %s: entitlement name. */
				return sprintf( __( 'Entitlement: %s', 'agend-content-access' ), $name );
			case 'platform':
				/* translators: %s: platform capability name, for example Directory access. */
				return sprintf( __( 'Platform: %s', 'agend-content-access' ), $name );
			case 'role':
				/* translators: %s: WordPress role name, for example Editor. */
				return sprintf( __( 'Role: %s', 'agend-content-access' ), $name );
			case 'member':
			case 'login':
				return $name;
			default:
				/* translators: 1: name of the group a rule belongs to, 2: the rule's name. */
				return sprintf( __( '%1$s: %2$s', 'agend-content-access' ), $group_label, $name );
		}
	}

	/**
	 * Pages, synced patterns and Elementor templates an editor may choose as
	 * replacement content, with the kind of each.
	 *
	 * Offers PUBLIC content only. A restricted page chosen here would be shown
	 * to exactly the visitors the conditions just excluded, which is the
	 * disclosure the reference implementation walks straight into by rendering
	 * whatever was picked.
	 *
	 * Password-protected posts are left out as well, for the same reason: the
	 * replacement is rendered without the password prompt, so offering one would
	 * invite an editor to publish it. `fallback_html()` refuses them regardless.
	 * Both editors read this list, the Elementor control directly and the block
	 * editor panel through the conditions route, so neither is offered one.
	 *
	 * The picker is the first line and the render-time check is the second: a
	 * page restricted AFTER being chosen must still not leak, and this list
	 * cannot know about that.
	 *
	 * Three kinds, in this order and by title within each: pages (every post type
	 * the rule panel is offered on), synced patterns, and Elementor templates when
	 * Elementor is active. Each is labelled with its kind, "Page: Join us",
	 * "Pattern: Members-only content", "Elementor template: Join banner", because
	 * the three are chosen from one list and a title alone does not say which it
	 * is. An unsynced pattern is left out: it is copied into a page and has nothing
	 * to render in a place on its own.
	 *
	 * @return array<int, array{id: int, type: string, title: string, label: string}> `title` is the
	 *         plain title, `label` is the one shown to an editor.
	 */
	public static function fallback_choices(): array {
		$choices = array();

		// A type the page query would otherwise list twice. They are labelled for what
		// they are below, never as a page.
		$pages = array_values( array_diff( Agend_Content_Access_Meta_Box::supported_post_types(), array( 'wp_block', 'elementor_library' ) ) );

		foreach ( self::public_posts( $pages ) as $post ) {
			$choices[] = self::choice( $post, Agend_Content_Access_Fragment_Rules::KIND_PAGE, self::page_kind_label( (string) $post->post_type ) );
		}

		foreach ( self::public_posts( array( 'wp_block' ) ) as $post ) {
			if ( 'unsynced' === (string) get_post_meta( (int) $post->ID, 'wp_pattern_sync_status', true ) ) {
				continue;
			}

			$choices[] = self::choice( $post, Agend_Content_Access_Fragment_Rules::KIND_PATTERN, __( 'Pattern', 'agend-content-access' ) );
		}

		if ( Agend_Content_Access_Fragment_Rules::elementor_is_active() ) {
			foreach ( self::public_posts( array( 'elementor_library' ) ) as $post ) {
				$choices[] = self::choice( $post, Agend_Content_Access_Fragment_Rules::KIND_ELEMENTOR_TEMPLATE, __( 'Elementor template', 'agend-content-access' ) );
			}
		}

		return $choices;
	}

	/**
	 * The same choices as a value-to-label map, for a select that takes one.
	 *
	 * The Elementor control reads this, and starts it with "Nothing", which is
	 * what an empty value means there.
	 *
	 * @return array<string, string>
	 */
	public static function fallback_options(): array {
		$options = array( '' => __( 'Nothing', 'agend-content-access' ) );

		foreach ( self::fallback_choices() as $choice ) {
			$options[ (string) $choice['id'] ] = $choice['label'];
		}

		return $options;
	}

	/**
	 * Published posts of the given types that anybody may be shown: no password,
	 * and no rules of any kind.
	 *
	 * Rules of any kind are a restriction, including stored rules this version
	 * cannot read, which deny everyone: the replacement is rendered for exactly the
	 * visitors the rules of the thing it replaces just refused, so it must be
	 * readable by anyone.
	 *
	 * @param string[] $post_types Post types.
	 * @return WP_Post[]
	 */
	private static function public_posts( array $post_types ): array {
		if ( array() === $post_types ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_values(
			array_filter(
				$posts,
				static function ( $post ): bool {
					return $post instanceof WP_Post
						&& Agend_Content_Access_Rules::from_post( (int) $post->ID )->is_empty();
				}
			)
		);
	}

	/**
	 * One choice.
	 *
	 * @param WP_Post $post  The post.
	 * @param string  $type  A `Fragment_Rules::KIND_*` constant.
	 * @param string  $kind_label What the kind is called to an editor.
	 * @return array{id: int, type: string, title: string, label: string}
	 */
	private static function choice( WP_Post $post, string $type, string $kind_label ): array {
		$title = (string) get_the_title( $post );

		if ( '' === trim( $title ) ) {
			$title = sprintf(
				/* translators: %d: post id, shown for a post with no title. */
				__( '(no title) #%d', 'agend-content-access' ),
				(int) $post->ID
			);
		}

		return array(
			'id'    => (int) $post->ID,
			'type'  => $type,
			'title' => $title,
			'label' => sprintf(
				/* translators: 1: the kind of content (Page, Pattern, Elementor template), 2: its title. */
				__( '%1$s: %2$s', 'agend-content-access' ),
				$kind_label,
				$title
			),
		);
	}

	/**
	 * What a page-like post type is called to an editor: "Page" for a page, and
	 * the type's own singular name for any other (a post, a product).
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function page_kind_label( string $post_type ): string {
		if ( 'page' !== $post_type && function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $post_type );
			$name   = is_object( $object ) && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : '';

			if ( '' !== $name ) {
				return $name;
			}
		}

		return __( 'Page', 'agend-content-access' );
	}
}
