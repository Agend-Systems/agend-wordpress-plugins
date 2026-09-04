<?php
/**
 * Elementor Memberships Catalogue widget for Agend Elementor Widgets.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a grid of membership tiers with a dynamic signup form.
 *
 * The widget outputs a configured container; the frontend script
 * (assets/js/memberships-catalogue.js) fetches tiers and signup fields from the
 * Agend Apps Core REST proxy and renders the catalogue and signup form
 * client-side. This is SPEC-CRM-20260721-elementor-membership-signup.
 */
class Agend_Elementor_Memberships_Catalogue extends \Elementor\Widget_Base {

	/**
	 * Returns the widget name (unique identifier).
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'agend-memberships-catalogue';
	}

	/**
	 * Returns the widget display title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Agend Memberships', 'agend-elementor' );
	}

	/**
	 * Returns the Elementor icon class for the widget.
	 *
	 * @return string Icon class.
	 */
	public function get_icon(): string {
		return 'eicon-price-table';
	}

	/**
	 * Returns the Elementor categories this widget belongs to.
	 *
	 * @return array List of category slugs.
	 */
	public function get_categories(): array {
		return array( Agend_Elementor::CATEGORY );
	}

	/**
	 * Returns the frontend script handle this widget depends on.
	 *
	 * @return array Script handles.
	 */
	public function get_script_depends(): array {
		return array( 'agend-apps-records-memberships-catalogue' );
	}

	/**
	 * Returns the frontend style handle this widget depends on.
	 *
	 * @return array Style handles.
	 */
	public function get_style_depends(): array {
		return array( 'agend-apps-records-memberships-catalogue' );
	}

	/**
	 * Registers all Elementor controls for this widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers the Content tab controls.
	 */
	private function register_content_controls(): void {
		Agend_Elementor_Schema_Controls::register( $this, agend_apps_records_surface_schema( 'memberships-catalogue' ) );
	}

	/**
	 * Registers a Content-tab control this widget declares itself because the
	 * shared schema vocabulary cannot describe it.
	 *
	 * @param string $name Schema field name.
	 * @return void
	 */
	public function register_adapter_control( string $name ): void {
		switch ( $name ) {
			case 'tier_mode_overrides':
				$this->add_control(
					'tier_mode_overrides',
					array(
						'label'       => __( 'Tier-specific overrides', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::REPEATER,
						'fields'      => array(
							array(
								'name'        => 'tier_slug',
								'label'       => __( 'Tier slug', 'agend-elementor' ),
								'type'        => \Elementor\Controls_Manager::TEXT,
								'placeholder' => 'professional',
							),
							array(
								'name'    => 'tier_mode',
								'label'   => __( 'Mode for this tier', 'agend-elementor' ),
								'type'    => \Elementor\Controls_Manager::SELECT,
								'options' => array(
									'application' => __( 'Application', 'agend-elementor' ),
									'direct'      => __( 'Direct purchase', 'agend-elementor' ),
								),
								'default' => 'application',
							),
						),
						'default'     => array(),
						'title_field' => '{{{ "undefined" !== typeof tier_slug && tier_slug ? tier_slug : "Tier override" }}}',
					)
				);
				break;

			case 'success_url':
				$this->add_control(
					'success_url',
					array(
						'label'       => __( 'Success page URL (optional)', 'agend-elementor' ),
						'type'        => \Elementor\Controls_Manager::URL,
						'placeholder' => 'https://example.com/thank-you',
						'description' => __( 'URL to redirect to after successful signup. Defaults to the current page.', 'agend-elementor' ),
					)
				);
				break;
		}
	}

	/**
	 * Registers the Style tab controls.
	 */
	private function register_style_controls(): void {
		// Colours section.
		$this->start_controls_section(
			'section_style_colours',
			array(
				'label' => __( 'Colours', 'agend-elementor' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'accent_colour',
			array(
				'label'   => __( 'Accent colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#F76B4F',
				'description' => __( 'Used for buttons, selected card borders, and highlights.', 'agend-elementor' ),
			)
		);

		$this->add_control(
			'heading_colour',
			array(
				'label'   => __( 'Heading colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#1E2A4A',
			)
		);

		$this->add_control(
			'body_colour',
			array(
				'label'   => __( 'Body text colour', 'agend-elementor' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#26304D',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Normalises a repeater value to a clean object keyed by tier slug.
	 *
	 * @param array $repeater_data Raw repeater setting value.
	 * @return array Keyed by tier slug, values are 'application' or 'direct'.
	 */
	private function build_tier_mode_overrides( array $repeater_data ): array {
		$result = array();
		foreach ( $repeater_data as $row ) {
			$slug = isset( $row['tier_slug'] ) ? (string) $row['tier_slug'] : '';
			$mode = isset( $row['tier_mode'] ) ? (string) $row['tier_mode'] : '';
			if ( '' !== $slug && ( 'application' === $mode || 'direct' === $mode ) ) {
				$result[ $slug ] = $mode;
			}
		}
		return $result;
	}

	/**
	 * Builds the client-side config object from the widget settings.
	 *
	 * @param array $s Settings for display.
	 * @return array Config passed to the frontend script as JSON.
	 */
	private function build_config( array $s ): array {
		$success_url_parts = isset( $s['success_url'] ) && is_array( $s['success_url'] )
			? $s['success_url']
			: array( 'url' => '' );
		$success_url       = (string) ( $success_url_parts['url'] ?? '' );

		return array(
			'heading'          => (string) ( $s['heading_text'] ?? '' ),
			// '' (both), 'individual', or 'corporate' — forwarded to the tiers
			// API as the tierType query param (SPEC-CORE-20260722).
			'membershipType'   => (string) ( $s['membership_type'] ?? '' ),
			'columns'          => array(
				'desktop' => (int) ( $s['columns_desktop'] ?? 3 ),
				'tablet'  => (int) ( $s['columns_tablet'] ?? 2 ),
				'mobile'  => (int) ( $s['columns_mobile'] ?? 1 ),
			),
			'cardRadius'       => (int) ( $s['card_radius'] ?? 10 ),
			'fields'           => array(
				'description' => 'yes' === ( $s['show_description'] ?? 'yes' ),
				'benefits'    => 'yes' === ( $s['show_benefits'] ?? 'yes' ),
				'price'       => 'yes' === ( $s['show_price'] ?? 'yes' ),
			),
			'signupMode'       => (string) ( $s['global_signup_mode'] ?? 'application' ),
			'tierModeOverrides' => $this->build_tier_mode_overrides( (array) ( $s['tier_mode_overrides'] ?? array() ) ),
			'successUrl'       => $success_url,
			'colours'          => array(
				'accent'  => (string) ( $s['accent_colour'] ?? '#F76B4F' ),
				'heading' => (string) ( $s['heading_colour'] ?? '#1E2A4A' ),
				'body'    => (string) ( $s['body_colour'] ?? '#26304D' ),
			),
		);
	}

	/**
	 * Renders the widget container on the frontend.
	 *
	 * The catalogue and form are rendered client-side by
	 * assets/js/memberships-catalogue.js.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$config   = $this->build_config( $settings );

		$style = sprintf(
			'--agend-mem-accent:%1$s;--agend-mem-heading:%2$s;--agend-mem-body:%3$s;--agend-mem-radius:%4$dpx;--agend-mem-cols-desktop:%5$d;--agend-mem-cols-tablet:%6$d;--agend-mem-cols-mobile:%7$d;',
			esc_attr( $config['colours']['accent'] ),
			esc_attr( $config['colours']['heading'] ),
			esc_attr( $config['colours']['body'] ),
			(int) $config['cardRadius'],
			(int) $config['columns']['desktop'],
			(int) $config['columns']['tablet'],
			(int) $config['columns']['mobile']
		);
		?>
		<div class="agend-memberships" style="<?php echo esc_attr( $style ); ?>" data-agend-memberships-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<span class="agend-visually-hidden" role="status"><?php esc_html_e( 'Loading membership options…', 'agend-elementor' ); ?></span>
			<?php if ( '' !== $config['heading'] ) : ?>
				<h2 class="agend-mem-heading"><?php echo esc_html( $config['heading'] ); ?></h2>
			<?php endif; ?>
			<div class="agend-mem-container">
				<div class="agend-mem-grid">
					<?php for ( $i = 0; $i < 3; $i++ ) : ?>
						<article class="agend-mem-card agend-mem-skeleton" aria-hidden="true">
							<div class="agend-skel-line" style="width:60%"></div>
							<div class="agend-skel-line" style="width:40%"></div>
							<div class="agend-skel-line" style="width:85%"></div>
						</article>
					<?php endfor; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
