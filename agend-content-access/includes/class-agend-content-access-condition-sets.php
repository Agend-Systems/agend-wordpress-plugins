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
 * The three sets come from three different places, and the difference matters
 * for what an editor sees when something is wrong:
 *
 *   - WordPress roles are local and always available.
 *   - Membership plans come from the cached tier catalogue, which degrades to
 *     an empty list on an outage rather than failing the editor screen.
 *   - Segments come from the tenant's own segment definitions.
 *
 * A set that cannot be loaded is offered EMPTY rather than omitted, so the
 * group heading still appears and the editor can see that the option exists
 * but the list could not be fetched. Dropping the group entirely would read as
 * "this site has no segments", which is a different and misleading claim.
 */
class Agend_Content_Access_Condition_Sets {

	/** Cache key for the segment vocabulary. */
	const SEGMENTS_TRANSIENT = 'agend_content_access_segment_vocabulary';

	/**
	 * The full grouped vocabulary.
	 *
	 * @return array<string, array{label: string, conditions: array<string, string>}>
	 */
	public static function all(): array {
		$sets = array(
			Agend_Content_Access_Condition_Providers::WORDPRESS  => array(
				'label'      => __( 'WordPress user', 'agend-content-access' ),
				'conditions' => self::wordpress_conditions(),
			),
			Agend_Content_Access_Condition_Providers::MEMBERSHIP => array(
				'label'      => __( 'Membership', 'agend-content-access' ),
				'conditions' => self::membership_conditions(),
			),
			Agend_Content_Access_Condition_Providers::SEGMENTS   => array(
				'label'      => __( 'Segments', 'agend-content-access' ),
				'conditions' => self::segment_conditions(),
			),
		);

		/**
		 * Filters the condition vocabulary offered to editors.
		 *
		 * A site adding its own provider registers the matching checker through
		 * `agend_content_access_condition_providers` as well. Adding a set here
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
	 * The tenant's segments.
	 *
	 * Read through the admin segments endpoint, which is the right one HERE:
	 * the editor screen legitimately needs the list of segments that exist. It
	 * is emphatically not the endpoint used to decide what a visitor can see,
	 * which asks only about the caller.
	 *
	 * @return array<string, string>
	 */
	public static function segment_conditions(): array {
		$cached = get_transient( self::SEGMENTS_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'agend_apps_crm_get_segments' ) ) {
			return array();
		}

		$response = agend_apps_crm_get_segments();

		if ( is_wp_error( $response ) ) {
			// Empty, not cached. The group still appears so the editor can see
			// the option exists and the list simply could not be fetched.
			return array();
		}

		$rows = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;

		$conditions = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
				continue;
			}

			$conditions[ (string) $row['slug'] ] = isset( $row['name'] )
				? (string) $row['name']
				: (string) $row['slug'];
		}

		set_transient(
			self::SEGMENTS_TRANSIENT,
			$conditions,
			Agend_Content_Access_Condition_Runtime::cache_ttl()
		);

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
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$policy = Agend_Content_Access_Policy::get_for_post( (int) $post->ID );

			// Null means no policy, which reads as public. Anything else is a
			// restriction and is not offered.
			if ( null !== $policy
				&& Agend_Content_Access_Policy::MODE_PUBLIC !== ( $policy['mode'] ?? '' ) ) {
				continue;
			}

			$options[ (string) $post->ID ] = (string) get_the_title( $post );
		}

		return $options;
	}
}
