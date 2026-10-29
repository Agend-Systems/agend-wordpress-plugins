<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\ContentAccess;

use Agend_Content_Access_Condition_Sets as ConditionSets;
use Agend_Content_Access_Fragment_Rules as FragmentRules;
use Agend_Content_Access_Rules as Rules;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/has-elementor-double.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-policy.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-meta-box.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-viewer-facts.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-decision.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-fragment-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-sets.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-block-attributes.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-conditions.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-providers.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-condition-runtime.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-rules.php';
require_once AGEND_TESTS_ROOT . '/agend-content-access/includes/class-agend-content-access-access.php';

/**
 * Pages, synced patterns and Elementor templates as replacement content.
 *
 * One list offers all three and one function renders them, for a hidden block, a
 * hidden Elementor section and a denied page's placeholder alike. Every kind is
 * shown to somebody the rules of the thing it replaces just refused, so each is
 * held to the same two checks as a page: it carries no password, and it carries no
 * rule this visitor fails. The tests are mostly "this renders nothing".
 */
#[CoversClass( FragmentRules::class )]
#[CoversClass( ConditionSets::class )]
final class FallbackKindsTest extends TestCase {

	private const MEMBERS = array( 'rules' => array( 'agend_membership:is_member' ), 'match' => 'any', 'invert' => false );

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['agend_test_current_user_id'] = 0;
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', '' );
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	private function post( int $id, string $type, string $title, string $content = '', array $extra = array() ): void {
		$GLOBALS['agend_test_posts'][ $id ] = (object) array_merge(
			array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => $content,
			),
			$extra
		);
	}

	private function restrict( int $id ): void {
		$GLOBALS['agend_test_post_meta'][ $id ][ Rules::META_KEY ] = self::MEMBERS;
	}

	private function elementorIsActive( ?object $frontend = null ): void {
		Agend_Test_WP::$did_action['elementor/loaded'] = 1;

		\Elementor\Plugin::$instance->frontend = $frontend ?? new class() {
			/** @var array<int, array{0: int, 1: bool}> */
			public array $calls = array();

			public function get_builder_content_for_display( $post_id, $with_css = false ): string {
				$this->calls[] = array( (int) $post_id, (bool) $with_css );

				return '<div class="elementor">TEMPLATE BODY</div>';
			}
		};
	}

	// -----------------------------------------------------------------------
	// A synced pattern
	// -----------------------------------------------------------------------

	#[Test]
	public function a_synced_pattern_renders_its_blocks_and_runs_its_shortcodes(): void {
		add_shortcode(
			'hello',
			static function (): string {
				return 'HELLO FROM A SHORTCODE';
			}
		);
		$this->post( 31, 'wp_block', 'Join banner', '<!-- wp:paragraph --><p>PATTERN BODY</p><!-- /wp:paragraph -->[hello]' );

		$html = FragmentRules::fallback_html( 31 );

		$this->assertStringContainsString( 'PATTERN BODY', $html );
		$this->assertStringContainsString( 'HELLO FROM A SHORTCODE', $html, 'a pattern is not given the content filter that runs shortcodes' );
	}

	#[Test]
	public function a_pattern_with_a_password_renders_nothing(): void {
		$this->post( 31, 'wp_block', 'Join banner', '<p>PATTERN BODY</p>', array( 'post_password' => 'hunter2' ) );

		$this->assertSame( '', FragmentRules::fallback_html( 31 ) );
	}

	#[Test]
	public function an_unpublished_pattern_renders_nothing(): void {
		$this->post( 31, 'wp_block', 'Join banner', '<p>PATTERN BODY</p>', array( 'post_status' => 'draft' ) );

		$this->assertSame( '', FragmentRules::fallback_html( 31 ) );
	}

	#[Test]
	public function a_pattern_carrying_rules_this_visitor_fails_renders_nothing(): void {
		$this->post( 31, 'wp_block', 'Join banner', '<p>PATTERN BODY</p>' );
		$this->restrict( 31 );

		$this->assertSame( '', FragmentRules::fallback_html( 31 ) );
	}

	// -----------------------------------------------------------------------
	// A kind of post that cannot be shown
	// -----------------------------------------------------------------------

	/**
	 * A post type the rule panel is not offered on has never carried a rule, so "no
	 * rules" says nothing about it. An id typed by hand must not print its body.
	 */
	#[Test]
	public function a_post_type_that_cannot_carry_rules_is_not_shown(): void {
		$this->post( 32, 'private_notes', 'Internal notes', 'INTERNAL NOTES' );

		$this->assertSame( '', FragmentRules::fallback_html( 32 ) );
	}

	#[Test]
	public function a_page_of_a_supported_type_still_renders(): void {
		$this->post( 33, 'page', 'Join us', 'JOIN US BODY' );

		$this->assertStringContainsString( 'JOIN US BODY', FragmentRules::fallback_html( 33 ) );
	}

	// -----------------------------------------------------------------------
	// An Elementor template
	// -----------------------------------------------------------------------

	#[Test]
	public function an_elementor_template_renders_through_elementors_frontend_with_its_css(): void {
		$frontend = new class() {
			/** @var array<int, array{0: int, 1: bool}> */
			public array $calls = array();

			public function get_builder_content_for_display( $post_id, $with_css = false ): string {
				$this->calls[] = array( (int) $post_id, (bool) $with_css );

				return '<div class="elementor">TEMPLATE BODY</div>';
			}
		};
		$this->elementorIsActive( $frontend );
		$this->post( 34, 'elementor_library', 'Join banner', 'FLATTENED COPY OF THE TEMPLATE' );

		$html = FragmentRules::fallback_html( 34 );

		$this->assertSame( '<div class="elementor">TEMPLATE BODY</div>', $html );
		$this->assertSame( array( array( 34, true ) ), $frontend->calls );
		$this->assertStringNotContainsString( 'FLATTENED', $html, 'the stored copy is not the render' );
	}

	#[Test]
	public function an_elementor_template_renders_nothing_when_elementor_is_not_active(): void {
		$frontend = new class() {
			public int $calls = 0;

			public function get_builder_content_for_display( $post_id, $with_css = false ): string {
				++$this->calls;

				return 'TEMPLATE BODY';
			}
		};
		\Elementor\Plugin::$instance->frontend = $frontend;
		$this->post( 34, 'elementor_library', 'Join banner', 'FLATTENED COPY OF THE TEMPLATE' );

		$this->assertSame( '', FragmentRules::fallback_html( 34 ) );
		$this->assertSame( 0, $frontend->calls, 'it is not asked to render' );
	}

	#[Test]
	public function an_elementor_template_renders_nothing_when_its_frontend_is_missing_or_has_no_such_method(): void {
		$this->post( 34, 'elementor_library', 'Join banner', 'FLATTENED COPY OF THE TEMPLATE' );

		$this->elementorIsActive();
		\Elementor\Plugin::$instance->frontend = null;
		$this->assertSame( '', FragmentRules::fallback_html( 34 ), 'no frontend' );

		\Elementor\Plugin::$instance->frontend = new class() {};
		$this->assertSame( '', FragmentRules::fallback_html( 34 ), 'a frontend that renamed the method' );
	}

	#[Test]
	public function an_elementor_template_with_a_password_or_rules_renders_nothing(): void {
		$this->elementorIsActive();

		$this->post( 34, 'elementor_library', 'Protected', '', array( 'post_password' => 'hunter2' ) );
		$this->assertSame( '', FragmentRules::fallback_html( 34 ), 'a password' );

		$this->post( 35, 'elementor_library', 'Restricted' );
		$this->restrict( 35 );
		$this->assertSame( '', FragmentRules::fallback_html( 35 ), 'rules this visitor fails' );
	}

	// -----------------------------------------------------------------------
	// What the picker offers
	// -----------------------------------------------------------------------

	#[Test]
	public function the_picker_offers_pages_then_patterns_then_templates_each_labelled_by_kind(): void {
		$this->elementorIsActive();
		$this->post( 41, 'elementor_library', 'Join banner' );
		$this->post( 42, 'wp_block', 'Members only' );
		$this->post( 43, 'page', 'Welcome' );
		$this->post( 44, 'page', 'About' );

		$this->assertSame(
			array(
				array( 'id' => 44, 'type' => 'page', 'title' => 'About', 'label' => 'Page: About' ),
				array( 'id' => 43, 'type' => 'page', 'title' => 'Welcome', 'label' => 'Page: Welcome' ),
				array( 'id' => 42, 'type' => 'pattern', 'title' => 'Members only', 'label' => 'Pattern: Members only' ),
				array( 'id' => 41, 'type' => 'elementor_template', 'title' => 'Join banner', 'label' => 'Elementor template: Join banner' ),
			),
			ConditionSets::fallback_choices()
		);

		$this->assertSame(
			array( '', '44', '43', '42', '41' ),
			array_map( 'strval', array_keys( ConditionSets::fallback_options() ) ),
			'the select takes the same choices, after "Nothing"'
		);
		$this->assertSame( 'Elementor template: Join banner', ConditionSets::fallback_options()['41'] );
	}

	#[Test]
	public function elementor_templates_are_not_offered_when_elementor_is_not_active(): void {
		$this->post( 41, 'elementor_library', 'Join banner' );
		$this->post( 42, 'wp_block', 'Members only' );

		$this->assertSame( array( 42 ), array_column( ConditionSets::fallback_choices(), 'id' ) );
	}

	#[Test]
	public function the_picker_leaves_out_a_pattern_or_template_with_a_password_or_rules_and_an_unsynced_pattern(): void {
		$this->elementorIsActive();

		$this->post( 41, 'wp_block', 'Open pattern' );
		$this->post( 42, 'wp_block', 'Protected pattern', '', array( 'post_password' => 'hunter2' ) );
		$this->post( 43, 'wp_block', 'Restricted pattern' );
		$this->restrict( 43 );
		$this->post( 44, 'wp_block', 'Unsynced pattern' );
		$GLOBALS['agend_test_post_meta'][44]['wp_pattern_sync_status'] = 'unsynced';
		$this->post( 45, 'wp_block', 'Draft pattern', '', array( 'post_status' => 'draft' ) );

		$this->post( 51, 'elementor_library', 'Open template' );
		$this->post( 52, 'elementor_library', 'Protected template', '', array( 'post_password' => 'hunter2' ) );
		$this->post( 53, 'elementor_library', 'Restricted template' );
		$this->restrict( 53 );

		$this->assertSame( array( 41, 51 ), array_column( ConditionSets::fallback_choices(), 'id' ) );
	}

	/** A site that makes its templates public must not see them listed twice, once as a page. */
	#[Test]
	public function a_public_template_or_pattern_type_is_listed_once_as_what_it_is(): void {
		$GLOBALS['agend_test_post_types'] = array( 'page' => 'page', 'wp_block' => 'wp_block', 'elementor_library' => 'elementor_library' );
		$this->elementorIsActive();
		$this->post( 41, 'wp_block', 'Members only' );
		$this->post( 42, 'elementor_library', 'Join banner' );

		$this->assertSame(
			array( array( 41, 'pattern' ), array( 42, 'elementor_template' ) ),
			array_map( static fn ( array $choice ): array => array( $choice['id'], $choice['type'] ), ConditionSets::fallback_choices() )
		);
	}

	#[Test]
	public function a_page_like_type_other_than_page_is_labelled_with_its_own_name(): void {
		$GLOBALS['agend_test_post_types']        = array( 'page' => 'page', 'post' => 'post' );
		$GLOBALS['agend_test_post_type_objects'] = array( 'post' => 'Post' );
		$this->post( 41, 'post', 'News item' );

		$this->assertSame( 'Post: News item', ConditionSets::fallback_choices()[0]['label'] );
		$this->assertSame( 'page', ConditionSets::fallback_choices()[0]['type'] );
	}

	#[Test]
	public function a_post_with_no_title_is_still_a_choice_an_editor_can_tell_apart(): void {
		$this->post( 41, 'page', '' );

		$this->assertSame( 'Page: (no title) #41', ConditionSets::fallback_choices()[0]['label'] );
	}
}
