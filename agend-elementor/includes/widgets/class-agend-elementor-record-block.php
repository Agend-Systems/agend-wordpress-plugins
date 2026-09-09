<?php
/**
 * Elementor "Agend Panel" widget: the composite panels of the built-in detail
 * (tickets, sponsors, facts, outcomes, reviews ...) as single blocks for a
 * detail template.
 *
 * A panel is several values with their own headings and layout, sometimes
 * their own live data. Anything that is one value -- a description, a set of
 * categories, opening hours, one custom field -- is a FIELD, and belongs on
 * Agend Field or Agend Pills, which can label, format and truncate it. This
 * widget used to offer both, which taught authors to reach for a whole panel
 * to print one line of text; those keys are no longer offered, but they still
 * render, so templates built on them are untouched.
 *
 * The widget's name stays `agend-record-block`: it is the id every saved
 * template refers to, and renaming it would orphan all of them.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each block is one of the fragments in fragments.php,
 * so a detail template and the built-in detail render identical panels.
 */
class Agend_Elementor_Record_Block extends \Elementor\Widget_Base {

	use Agend_Elementor_Field_Widget_Trait;

	public function get_name(): string {
		return 'agend-record-block';
	}

	public function get_title(): string {
		return __( 'Agend Panel', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-post-content';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'panel', 'block', 'tickets', 'sponsors', 'outcomes', 'reviews', 'detail' );
	}

	public function get_style_depends(): array {
		return array( 'agend-elementor-record-fields', 'agend-apps-records-events-catalogue', 'agend-apps-records-courses-catalogue' );
	}

	/**
	 * Panel key => [ label, record type, fragment function ].
	 *
	 * Kept here (not in the schema) because the record type and fragment
	 * function are render-time concerns; the schema lists the pickable keys
	 * (see agend_apps_records_block_options()).
	 *
	 * This map is deliberately WIDER than the picker: it still carries the
	 * single-value keys that moved to Agend Field and Agend Pills, so a
	 * template saved against one of them keeps rendering exactly as before.
	 * See agend_apps_records_retired_block_field().
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	private function blocks(): array {
		return array(
			'event_facts'        => array( __( 'Event facts (date, location, format)', 'agend-elementor' ), 'event', 'agend_apps_records_fragment_event_facts' ),
			'event_registration' => array( __( 'Event registration panel', 'agend-elementor' ), 'event', 'agend_apps_records_fragment_event_registration' ),
			'event_tickets'      => array( __( 'Event tickets and pricing', 'agend-elementor' ), 'event', 'agend_apps_records_fragment_event_tickets' ),
			'event_sponsors'     => array( __( 'Event sponsors', 'agend-elementor' ), 'event', 'agend_apps_records_fragment_event_sponsors' ),
			'course_meta'        => array( __( 'Course details (level, format, duration)', 'agend-elementor' ), 'course', 'agend_apps_records_fragment_course_meta' ),
			'course_outcomes'    => array( __( 'Course learning outcomes', 'agend-elementor' ), 'course', 'agend_apps_records_fragment_course_outcomes' ),
			'course_enrolment'   => array( __( 'Course pricing and enrolment', 'agend-elementor' ), 'course', 'agend_apps_records_fragment_course_enrolment' ),
			'listing_about'         => array( __( 'Listing about', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_about' ),
			'listing_contact'       => array( __( 'Listing contact and links', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_contact' ),
			'listing_categories'    => array( __( 'Listing categories', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_categories' ),
			'listing_tags'          => array( __( 'Listing tags', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_tags' ),
			'listing_gallery'       => array( __( 'Listing gallery', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_gallery' ),
			'listing_locations'     => array( __( 'Listing locations', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_locations' ),
			'listing_hours'         => array( __( 'Listing business hours', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_hours' ),
			'listing_custom_fields' => array( __( 'Listing custom fields', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_custom_fields' ),
			'listing_achievements'  => array( __( 'Listing badges and credentials', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_achievements' ),
			'listing_reviews'       => array( __( 'Listing reviews', 'agend-elementor' ), 'listing', 'agend_apps_records_fragment_listing_reviews' ),
		);
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'record-block' ) );
	}

	/**
	 * Ticket types for an event, from the render context or fetched once per
	 * slug per request.
	 *
	 * @param string $slug  Event slug.
	 * @param array  $extra Render context.
	 * @return array
	 */
	private function tickets_for( string $slug, array $extra ): array {
		if ( isset( $extra['tickets'] ) && is_array( $extra['tickets'] ) ) {
			return $extra['tickets'];
		}
		static $memo = array();
		if ( '' === $slug || ! function_exists( 'agend_apps_events_get_tickets' ) ) {
			return array();
		}
		if ( ! isset( $memo[ $slug ] ) ) {
			$response      = agend_apps_events_get_tickets( $slug );
			$memo[ $slug ] = ( ! is_wp_error( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) )
				? $response['data']
				: array();
		}
		return $memo[ $slug ];
	}

	/**
	 * Editor-only notice for a panel key that is now a field.
	 *
	 * The panel still renders, so this says where the value moved to rather
	 * than warning about something broken.
	 *
	 * @param string $key The panel key.
	 * @return void
	 */
	private function render_retired_notice( string $key ): void {
		if ( ! function_exists( 'agend_apps_records_retired_block_field' ) ) {
			return;
		}

		$field = agend_apps_records_retired_block_field( $key );
		if ( '' === $field ) {
			return;
		}

		$this->render_editor_notice(
			sprintf(
				/* translators: %s: the field key this panel became, e.g. listing:description. */
				__( 'This is now a field. It still renders, but new templates should use Agend Field or Agend Pills set to "%s", which can label and format the value.', 'agend-elementor' ),
				$field
			)
		);
	}

	protected function render(): void {
		$s   = $this->get_settings_for_display();
		$ctx = $this->resolve_context();

		if ( '' === $ctx['type'] ) {
			return;
		}

		$blocks = $this->blocks();
		$key    = (string) ( $s['block'] ?? '' );
		if ( ! isset( $blocks[ $key ] ) ) {
			return;
		}
		list( , $block_type, $fragment ) = $blocks[ $key ];
		if ( $block_type !== $ctx['type'] ) {
			$this->render_editor_notice(
				sprintf(
					/* translators: 1: the panel's record type, 2: the record type the template renders. */
					__( 'This panel is a %1$s panel, but this template renders a %2$s. Nothing will show here on the live site.', 'agend-elementor' ),
					$block_type,
					$ctx['type']
				)
			);
			return;
		}
		$this->render_retired_notice( $key );
		if ( ! function_exists( $fragment ) ) {
			return;
		}

		$record = $ctx['record'];
		$slug   = isset( $record['slug'] ) ? (string) $record['slug'] : (string) ( $ctx['extra']['slug'] ?? '' );
		$extra  = $ctx['extra'];
		if ( ! isset( $extra['host'] ) && ! empty( $extra['host_page_id'] ) ) {
			$extra['host'] = get_post( (int) $extra['host_page_id'] );
		}

		switch ( $key ) {
			case 'event_tickets':
				if ( $ctx['is_preview'] ) {
					$this->render_editor_notice( __( 'The tickets panel renders from the live ticket list on the detail page.', 'agend-elementor' ) );
					return;
				}
				$html = $fragment( $record, $this->tickets_for( $slug, $extra ) );
				break;
			case 'event_registration':
			case 'course_enrolment':
			case 'listing_reviews':
				$html = $fragment( $record, $slug, $extra );
				break;
			default:
				$html = $fragment( $record );
		}

		if ( '' === trim( $html ) ) {
			$this->render_editor_notice( __( 'This record has nothing to show for this block.', 'agend-elementor' ) );
			return;
		}

		// The fragments carry the built-in detail's BEM classes, which are
		// styled under the catalogue root class and its colour variables.
		$roots  = array( 'course' => 'agend-courses-catalogue', 'listing' => 'agend-directory-catalogue', 'event' => 'agend-events-catalogue' );
		$prefix = array( 'course' => 'agend-lms', 'listing' => 'agend-dir', 'event' => 'agend-ev' );
		$root   = $roots[ $ctx['type'] ] ?? 'agend-events-catalogue';
		$style  = agend_apps_records_ssr_colour_style( $prefix[ $ctx['type'] ] ?? 'agend-ev' );
		echo '<div class="agend-record-block agend-record-block--' . esc_attr( $key ) . ' ' . esc_attr( $root ) . ' ' . esc_attr( $root ) . '--fragment" style="' . esc_attr( $style ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragments escape internally.
	}
}
