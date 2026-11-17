<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Member Jobs surface: a signed-out visitor gets a sign-in link drawn on
 * the server, and a member's form follows the site's choice of required and
 * hidden fields and its tag questions.
 */
final class MemberJobsRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/member-jobs.php';
	}

	#[Test]
	public function a_signed_out_visitor_is_asked_to_sign_in_without_a_script(): void {
		wp_set_current_user( 0 );

		$html = agend_apps_records_render_member_jobs( array() );

		self::assertStringContainsString( 'agend-member-jobs--signed-out', $html );
		self::assertStringContainsString( 'Please sign in to post and manage your jobs.', $html );
		self::assertStringNotContainsString( 'data-agend-member-jobs-config', $html );
	}

	#[Test]
	public function an_empty_signed_out_message_renders_nothing(): void {
		wp_set_current_user( 0 );

		self::assertSame( '', agend_apps_records_render_member_jobs( array( 'logged_out_message' => '' ) ) );
	}

	#[Test]
	public function a_member_gets_the_shell_and_its_config(): void {
		wp_set_current_user( 7 );

		$html = agend_apps_records_render_member_jobs( array( 'heading' => 'Your jobs' ) );

		self::assertMatchesRegularExpression( '/data-agend-member-jobs-config="([^"]+)"/', $html );
		preg_match( '/data-agend-member-jobs-config="([^"]+)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );

		self::assertSame( 'Your jobs', $config['text']['heading'] );
		self::assertStringEndsWith( 'agend-apps/v1/jobs/me', $config['endpoint'] );
		self::assertSame( AGEND_APPS_RECORDS_MEMBER_JOBS_REQUIRED_DEFAULT, $config['form']['required'] );
	}

	#[Test]
	public function a_hidden_field_is_never_required_and_unknown_keys_are_dropped(): void {
		$rules = agend_apps_records_member_jobs_field_rules(
			array(
				'required_fields' => array( 'description', 'salary', 'made_up' ),
				'hidden_fields'   => array( 'salary', 'location_country', 'made_up' ),
			)
		);

		self::assertSame( array( 'description' ), $rules['required'] );
		self::assertSame( array( 'location_country', 'salary' ), $rules['hidden'] );
	}

	#[Test]
	public function tag_questions_resolve_to_tag_ids_and_only_a_dropdown_can_be_required(): void {
		$tags = array(
			array( 'id' => 'id-under', 'slug' => 'under-80k', 'name' => 'Under $80k' ),
			array( 'id' => 'id-over', 'slug' => '120k-plus', 'name' => '$120k plus' ),
			array( 'id' => 'id-acc', 'slug' => 'accreditation-required', 'name' => 'Accreditation required' ),
		);

		$groups = agend_apps_records_member_jobs_tag_groups(
			array(
				array( 'label' => 'Salary band', 'tags' => array( 'under-80k', '120k-plus', 'gone' ), 'style' => 'select', 'required' => 'yes' ),
				array( 'label' => 'Accreditation', 'tags' => array( 'accreditation-required' ), 'style' => 'checkboxes', 'required' => 'yes' ),
				array( 'label' => 'Empty', 'tags' => array( 'gone' ), 'style' => 'select' ),
			),
			$tags
		);

		self::assertCount( 2, $groups, 'a question whose tags are all gone is dropped' );
		self::assertSame( array( 'id-under', 'id-over' ), array_column( $groups[0]['tags'], 'id' ) );
		self::assertTrue( $groups[0]['required'] );
		self::assertFalse( $groups[1]['required'], 'tick boxes are never required' );
	}
}
