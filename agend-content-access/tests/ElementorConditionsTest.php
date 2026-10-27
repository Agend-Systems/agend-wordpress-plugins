<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Elementor as ElementorControl;
use Agend_Content_Access_Fragment_Rules as FragmentRules;
use Agend_Content_Access_Policy;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-frontend.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-elementor.php';

/**
 * The Elementor display-condition control (US-6.1, ESAC parity).
 *
 * Covers the settings-to-decision path: given what an editor chose, is the
 * element shown? The engine and providers are tested separately; what matters
 * here is that the control's own defaults are safe and that a rule an editor
 * did not write cannot appear.
 */
#[CoversClass( ElementorControl::class )]
#[CoversClass( FragmentRules::class )]
final class ElementorConditionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	private function settings( array $over = array() ): array {
		return array_merge(
			array(
				ElementorControl::CONDITIONS_ENABLED_KEY => 'yes',
				ElementorControl::CONDITIONS_KEY         => array(),
				ElementorControl::CONDITIONS_MATCH_KEY   => 'any',
				ElementorControl::CONDITIONS_INVERT_KEY  => '',
			),
			$over
		);
	}

	/**
	 * The settings keys are declared once, on neutral classes, so a second
	 * editor writing the same keys cannot drift from what Elementor writes. The
	 * Elementor constants exist for its control ids and must stay aliases.
	 */
	#[Test]
	public function the_elementor_keys_are_aliases_of_the_neutral_ones(): void {
		$this->assertSame( Agend_Content_Access_Policy::FRAGMENT_MODE_KEY, ElementorControl::MODE_KEY );
		$this->assertSame( Agend_Content_Access_Policy::FRAGMENT_TIERS_KEY, ElementorControl::TIERS_KEY );
		$this->assertSame( FragmentRules::ENABLED_KEY, ElementorControl::CONDITIONS_ENABLED_KEY );
		$this->assertSame( FragmentRules::CONDITIONS_KEY, ElementorControl::CONDITIONS_KEY );
		$this->assertSame( FragmentRules::MATCH_KEY, ElementorControl::CONDITIONS_MATCH_KEY );
		$this->assertSame( FragmentRules::INVERT_KEY, ElementorControl::CONDITIONS_INVERT_KEY );
		$this->assertSame( FragmentRules::FALLBACK_KEY, ElementorControl::CONDITIONS_FALLBACK_KEY );

		// The on-disk names every stored document uses.
		$this->assertSame( 'agend_access_mode', Agend_Content_Access_Policy::FRAGMENT_MODE_KEY );
		$this->assertSame( 'agend_access_tier_ids', Agend_Content_Access_Policy::FRAGMENT_TIERS_KEY );
		$this->assertSame( 'agend_conditions_enabled', FragmentRules::ENABLED_KEY );
		$this->assertSame( 'agend_conditions', FragmentRules::CONDITIONS_KEY );
		$this->assertSame( 'agend_conditions_match', FragmentRules::MATCH_KEY );
		$this->assertSame( 'agend_conditions_invert', FragmentRules::INVERT_KEY );
		$this->assertSame( 'agend_conditions_fallback_id', FragmentRules::FALLBACK_KEY );
	}

	#[Test]
	public function conditions_are_enabled_only_by_the_yes_toggle(): void {
		$this->assertTrue( FragmentRules::conditions_enabled( array( FragmentRules::ENABLED_KEY => 'yes' ) ) );
		$this->assertFalse( FragmentRules::conditions_enabled( array() ) );
		$this->assertFalse( FragmentRules::conditions_enabled( array( FragmentRules::ENABLED_KEY => '' ) ) );
		$this->assertFalse( FragmentRules::conditions_enabled( array( FragmentRules::ENABLED_KEY => true ) ) );
		$this->assertFalse( FragmentRules::conditions_enabled( array( FragmentRules::ENABLED_KEY => 'no' ) ) );
	}

	/**
	 * The common case by a wide margin: almost no element carries conditions,
	 * and those that do not must cost nothing and never be hidden.
	 */
	#[Test]
	public function an_element_with_conditions_switched_off_always_shows(): void {
		$this->assertTrue(
			FragmentRules::conditions_pass(
				array( ElementorControl::CONDITIONS_ENABLED_KEY => '' )
			)
		);

		$this->assertTrue( FragmentRules::conditions_pass( array() ) );
	}

	/**
	 * Switched on but with nothing chosen is not a restriction. It matches the
	 * reference implementation and the control says so, because the alternative
	 * (an element that vanishes the moment the switch is flipped, before any
	 * condition is picked) would read as a bug to an editor.
	 */
	#[Test]
	public function enabled_with_no_conditions_chosen_shows_to_everybody(): void {
		$this->assertTrue( FragmentRules::conditions_pass( $this->settings() ) );
	}

	#[Test]
	public function a_signed_out_visitor_is_matched_by_the_logged_out_condition(): void {
		$this->assertTrue(
			FragmentRules::conditions_pass(
				$this->settings(
					array(
						ElementorControl::CONDITIONS_KEY => array( 'agend_wordpress:logged_out' ),
					)
				)
			)
		);
	}

	#[Test]
	public function a_signed_out_visitor_fails_the_logged_in_condition(): void {
		$this->assertFalse(
			FragmentRules::conditions_pass(
				$this->settings(
					array(
						ElementorControl::CONDITIONS_KEY => array( 'agend_wordpress:logged_in' ),
					)
				)
			)
		);
	}

	/**
	 * Invert is what makes "signed-out only" content expressible at all, and
	 * 25 conditions in the surveyed data depend on that family.
	 */
	#[Test]
	public function reversing_the_result_flips_the_outcome(): void {
		$base = array(
			ElementorControl::CONDITIONS_KEY => array( 'agend_wordpress:logged_in' ),
		);

		$this->assertFalse(
			FragmentRules::conditions_pass( $this->settings( $base ) )
		);

		$this->assertTrue(
			FragmentRules::conditions_pass(
				$this->settings(
					$base + array( ElementorControl::CONDITIONS_INVERT_KEY => 'yes' )
				)
			)
		);
	}

	#[Test]
	public function match_all_requires_every_condition(): void {
		// Signed out: `logged_out` passes, `role_editor` does not.
		$settings = $this->settings(
			array(
				ElementorControl::CONDITIONS_KEY       => array(
					'agend_wordpress:logged_out',
					'agend_wordpress:role_editor',
				),
				ElementorControl::CONDITIONS_MATCH_KEY => 'all',
			)
		);

		$this->assertFalse( FragmentRules::conditions_pass( $settings ) );

		$settings[ ElementorControl::CONDITIONS_MATCH_KEY ] = 'any';

		$this->assertTrue( FragmentRules::conditions_pass( $settings ) );
	}

	/**
	 * An unrecognised provider denies. The reference implementation passes in
	 * this case, so a deactivated plugin silently reveals restricted content.
	 */
	#[Test]
	public function an_unrecognised_provider_hides_the_element(): void {
		$this->assertFalse(
			FragmentRules::conditions_pass(
				$this->settings(
					array(
						ElementorControl::CONDITIONS_KEY => array( 'plugin_gone:whatever' ),
					)
				)
			)
		);
	}

	/**
	 * Inverting must NOT turn an unevaluable rule into a reveal. The engine
	 * returns a hard denial for an unknown provider rather than a false that
	 * inversion could flip.
	 */
	#[Test]
	public function reversing_an_unrecognised_provider_still_hides_it(): void {
		$this->assertFalse(
			FragmentRules::conditions_pass(
				$this->settings(
					array(
						ElementorControl::CONDITIONS_KEY        => array( 'plugin_gone:whatever' ),
						ElementorControl::CONDITIONS_INVERT_KEY => 'yes',
					)
				)
			)
		);
	}

	#[Test]
	public function a_malformed_condition_hides_the_element(): void {
		$this->assertFalse(
			FragmentRules::conditions_pass(
				$this->settings(
					array(
						ElementorControl::CONDITIONS_KEY => array( 'no_colon_at_all' ),
					)
				)
			)
		);
	}

	/**
	 * Legacy prefixes are what existing sites have stored, so an element
	 * authored before this plugin must keep working untouched.
	 */
	#[Test]
	public function legacy_esac_prefixes_are_honoured(): void {
		$this->assertTrue(
			FragmentRules::conditions_pass(
				$this->settings(
					array(
						ElementorControl::CONDITIONS_KEY => array( 'iugo_esac_wordpress:logged_out' ),
					)
				)
			)
		);
	}
}
