<?php
/**
 * Elementor "Agend Content Block" widget: the composite panels of the
 * built-in detail (tickets, sponsors, facts, outcomes ...) as single blocks
 * for a detail template.
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
		return __( 'Agend Content Block', 'agend-elementor' );
	}

	public function get_icon(): string {
		return 'eicon-post-content';
	}

	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	public function get_keywords(): array {
		return array( 'agend', 'tickets', 'sponsors', 'outcomes', 'progress', 'detail' );
	}

	public function get_style_depends(): array {
		return array( 'agend-elementor-record-fields', 'agend-apps-records-events-catalogue', 'agend-apps-records-courses-catalogue' );
	}

	/**
	 * Block key => [ label, record type, fragment function ].
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
		$this->start_controls_section(
			'section_block',
			array(
				'label' => __( 'Block', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->record_type_control();

		$options = array();
		foreach ( $this->blocks() as $key => $block ) {
			$options[ $key ] = $block[0];
		}

		$this->add_control(
			'block',
			array(
				'label'       => __( 'Block', 'agend-elementor' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'event_facts',
				'options'     => $options,
				'label_block' => true,
				'description' => __( 'Intended for detail templates. The tickets block loads the ticket list per event, so avoid it on cards.', 'agend-elementor' ),
			)
		);

		$this->end_controls_section();
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

	protected function render(): void {
		$s   = $this->get_settings_for_display();
		$ctx = $this->resolve_context();

		if ( $ctx['mismatch'] ) {
			$this->render_mismatch_notice( (string) $s['record_type'], $ctx['type'] );
			return;
		}
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
			$this->render_editor_notice( __( 'This block belongs to the other record type and will not show in this template.', 'agend-elementor' ) );
			return;
		}
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
