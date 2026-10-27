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
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * What an Elementor element's rule settings decide: the new rule list, and the older
 * display conditions it replaced (US-6.1, ESAC parity).
 *
 * Covers the settings-to-decision path: given what an editor chose, is the element
 * shown? The engine and providers are tested separately; what matters here is that
 * the settings' defaults are safe, that a rule an editor did not write cannot appear,
 * and that the new keys and the older condition keys, which every stored document
 * still holds, decide the same way. Every case is run through both.
 */
#[CoversClass( ElementorControl::class )]
#[CoversClass( FragmentRules::class )]
final class ElementorConditionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
	}

	/** Whether the element's settings admit the visitor: the settings read as rules, judged by the one evaluator. */
	private function passes( array $settings ): bool {
		return \Agend_Content_Access_Access::verdict( \Agend_Content_Access_Rules::from_elementor_settings( $settings ) )['allowed'];
	}

	/**
	 * The same rules as an element stores them in each of the two shapes: the new rule
	 * list, and the older display conditions switched on.
	 *
	 * @param string[] $rules  Rule strings.
	 * @param string   $match  `any` or `all`.
	 * @param bool     $invert Reverse the result.
	 * @return array<string, array<string, mixed>>
	 */
	private function shapes( array $rules, string $match = 'any', bool $invert = false ): array {
		return array(
			'the rule list'      => array(
				ElementorControl::RULES_KEY  => $rules,
				ElementorControl::MATCH_KEY  => $match,
				ElementorControl::INVERT_KEY => $invert ? 'yes' : '',
			),
			'older conditions'   => array(
				ElementorControl::CONDITIONS_ENABLED_KEY => 'yes',
				ElementorControl::CONDITIONS_KEY         => $rules,
				ElementorControl::CONDITIONS_MATCH_KEY   => $match,
				ElementorControl::CONDITIONS_INVERT_KEY  => $invert ? 'yes' : '',
			),
		);
	}

	/** Asserts the verdict is the same from both shapes. */
	private function assertBothShapes( bool $expected, array $rules, string $match = 'any', bool $invert = false, string $why = '' ): void {
		foreach ( $this->shapes( $rules, $match, $invert ) as $shape => $settings ) {
			$this->assertSame( $expected, $this->passes( $settings ), trim( $shape . ' ' . $why ) );
		}
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
		$this->assertSame( FragmentRules::FALLBACK_KEY, ElementorControl::FALLBACK_KEY );
		$this->assertSame( \Agend_Content_Access_Rules::ELEMENTOR_RULES_KEY, ElementorControl::RULES_KEY );
		$this->assertSame( \Agend_Content_Access_Rules::ELEMENTOR_MATCH_KEY, ElementorControl::MATCH_KEY );
		$this->assertSame( \Agend_Content_Access_Rules::ELEMENTOR_INVERT_KEY, ElementorControl::INVERT_KEY );

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
	 * The common case by a wide margin: almost no element carries rules, and those
	 * that do not must cost nothing and never be hidden.
	 */
	#[Test]
	public function an_element_with_nothing_set_always_shows(): void {
		$this->assertTrue( $this->passes( array() ) );
		$this->assertTrue( $this->passes( array( ElementorControl::CONDITIONS_ENABLED_KEY => '' ) ) );
		$this->assertTrue(
			$this->passes(
				array(
					ElementorControl::RULES_KEY  => array(),
					ElementorControl::MATCH_KEY  => 'any',
					ElementorControl::INVERT_KEY => '',
				)
			),
			'the rule controls as Elementor hands them over for an element nobody touched'
		);
	}

	/**
	 * Nothing chosen is not a restriction. It matches the reference implementation and
	 * the control says so ("Leave empty to show this to everyone"), because the
	 * alternative (an element that vanishes the moment the control is touched, before
	 * any rule is picked) would read as a bug to an editor. It is no rules at all.
	 */
	#[Test]
	public function with_no_rules_chosen_the_element_shows_to_everybody(): void {
		$this->assertBothShapes( true, array() );
	}

	#[Test]
	public function a_signed_out_visitor_is_matched_by_the_logged_out_rule(): void {
		$this->assertBothShapes( true, array( 'agend_wordpress:logged_out' ) );
	}

	#[Test]
	public function a_signed_out_visitor_fails_the_logged_in_rule(): void {
		$this->assertBothShapes( false, array( 'agend_wordpress:logged_in' ) );
	}

	/**
	 * Reverse is what makes "signed-out only" content expressible at all, and 25
	 * conditions in the surveyed data depend on that family.
	 */
	#[Test]
	public function reversing_the_result_flips_the_outcome(): void {
		$this->assertBothShapes( false, array( 'agend_wordpress:logged_in' ) );
		$this->assertBothShapes( true, array( 'agend_wordpress:logged_in' ), 'any', true );
	}

	#[Test]
	public function match_all_requires_every_rule(): void {
		// Signed out: `logged_out` passes, `role_editor` does not.
		$rules = array( 'agend_wordpress:logged_out', 'agend_wordpress:role_editor' );

		$this->assertBothShapes( false, $rules, 'all' );
		$this->assertBothShapes( true, $rules, 'any' );
	}

	/**
	 * An unrecognised provider denies. The reference implementation passes in
	 * this case, so a deactivated plugin silently reveals restricted content.
	 */
	#[Test]
	public function an_unrecognised_provider_hides_the_element(): void {
		$this->assertBothShapes( false, array( 'plugin_gone:whatever' ) );
	}

	/**
	 * Reversing must NOT turn an unevaluable rule into a reveal. The engine returns a
	 * hard denial for an unknown provider rather than a false that a reverse could
	 * flip.
	 */
	#[Test]
	public function reversing_an_unrecognised_provider_still_hides_it(): void {
		$this->assertBothShapes( false, array( 'plugin_gone:whatever' ), 'any', true );
	}

	#[Test]
	public function a_malformed_rule_hides_the_element(): void {
		$this->assertBothShapes( false, array( 'no_colon_at_all' ) );
	}

	/**
	 * Legacy prefixes are what existing sites have stored, so an element authored
	 * before this plugin must keep working untouched.
	 */
	#[Test]
	public function legacy_esac_prefixes_are_honoured(): void {
		$this->assertBothShapes( true, array( 'iugo_esac_wordpress:logged_out' ) );
	}

	/**
	 * A reverse switched on over an empty list is not "no rules" to the reader: it is
	 * nobody, as the condition engine has always read it. Whether Elementor ever shows
	 * the reader that state is the controls' business (see ElementorRuleControlsTest).
	 */
	#[Test]
	public function a_reverse_over_no_rules_is_nobody_to_the_reader(): void {
		$this->assertBothShapes( false, array(), 'any', true );
	}
}
