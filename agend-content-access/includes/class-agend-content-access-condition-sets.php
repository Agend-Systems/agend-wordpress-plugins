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
	 * Pages and posts an editor may choose as replacement content.
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
	 * @return array<string, string>
	 */
	public static function fallback_options(): array {
		$options = array( '' => __( 'Nothing', 'agend-content-access' ) );

		$posts = get_posts(
			array(
				'post_type'      => Agend_Content_Access_Meta_Box::supported_post_types(),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$policy = Agend_Content_Access_Policy::enforced_for_post( (int) $post->ID );

			// Null means no policy is stored, which reads as public. Anything
			// else is a restriction and is not offered, including a stored
			// policy this version cannot read: that one is enforced as nobody.
			if ( null !== $policy
				&& Agend_Content_Access_Policy::MODE_PUBLIC !== ( $policy['mode'] ?? '' ) ) {
				continue;
			}

			$options[ (string) $post->ID ] = (string) get_the_title( $post );
		}

		return $options;
	}
}
