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
		return array( 'agend-apps-records-record-fields', 'agend-apps-records-events-catalogue', 'agend-apps-records-courses-catalogue' );
	}

	protected function register_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'record-block' ) );
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

	/**
	 * Echoes the panel, rendered by Agend Apps Core from this widget's
	 * settings and whichever record is in context.
	 *
	 * The "does this panel render, and if not why" decision now lives in
	 * agend_apps_records_record_block_render_reason() (render/record-block.php),
	 * reachable by a caller other than this widget; this method only maps
	 * each reason code to the translated notice it has always shown.
	 *
	 * The retired-key notice is the one case not driven by that reason: it is
	 * independent of whether the panel goes on to render or ends up with
	 * nothing to show for this record (both can be true at once -- a retired
	 * key whose record happens to have nothing to show), so it is called here
	 * unconditionally, exactly where it always ran, rather than folded into
	 * the reason switch below.
	 */
	protected function render(): void {
		$s         = $this->get_settings_for_display();
		$is_editor = $this->is_editor();
		$opts      = array(
			'preview'      => $is_editor,
			'preview_type' => $is_editor ? $this->preview_type() : '',
		);

		$reason = agend_apps_records_record_block_render_reason( $s, $opts );

		if ( 'wrong_type' === $reason ) {
			$ctx        = agend_apps_records_resolve_record_context( $opts );
			$blocks     = agend_apps_records_record_block_blocks();
			$key        = (string) ( $s['block'] ?? '' );
			$block_type = isset( $blocks[ $key ][1] ) ? $blocks[ $key ][1] : '';

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

		$this->render_retired_notice( (string) ( $s['block'] ?? '' ) );

		if ( 'tickets_live_only' === $reason ) {
			$this->render_editor_notice( __( 'The tickets panel renders from the live ticket list on the detail page.', 'agend-elementor' ) );
			return;
		}

		if ( 'empty_fragment' === $reason ) {
			$this->render_editor_notice( __( 'This record has nothing to show for this block.', 'agend-elementor' ) );
			return;
		}

		echo agend_apps_records_render_record_block( $s, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the core renderer.
	}
}
