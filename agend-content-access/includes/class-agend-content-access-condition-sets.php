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
	 * The vocabulary flattened for an Elementor SELECT2 control.
	 *
	 * Elementor's multi-select takes a flat value-to-label map, so the group
	 * name is folded into each label. Without it a bare "Signed in" alongside a
	 * bare plan name gives an editor no way to tell which family a condition
	 * belongs to.
	 *
	 * @return array<string, string>
	 */
	public static function as_options(): array {
		$options = array();

		foreach ( self::all() as $provider => $set ) {
			$label = isset( $set['label'] ) ? (string) $set['label'] : $provider;

			foreach ( (array) ( $set['conditions'] ?? array() ) as $value => $text ) {
				$options[ $provider . ':' . $value ] = $label . ' / ' . $text;
			}
		}

		return $options;
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
