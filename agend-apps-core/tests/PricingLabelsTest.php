<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pricing-labels.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';

/**
 * The wording for the price that is not the member price is a per-site
 * setting with a filter. A site that sets nothing renders exactly what it did
 * before; a site that sets "Public" never shows "non-member" on event pricing.
 */
final class PricingLabelsTest extends TestCase {

	private const TICKET = array(
		'ticket'       => array( 'name' => 'General' ),
		'pricingTiers' => array(
			array(
				'tier' => array(
					'member_price'     => 50,
					'non_member_price' => 80,
				),
			),
		),
	);

	private function detail_registration(): string {
		return agend_apps_records_fragment_event_registration( array(), 'conference' );
	}

	#[Test]
	public function should_keep_the_built_in_wording_when_nothing_is_set(): void {
		self::assertSame( 'Non-Members', agend_apps_records_non_member_label() );
		self::assertSame( 'non-member', agend_apps_records_non_member_rate_label() );
		self::assertSame( 'The non-member rate is shown where available. Final pricing is confirmed at checkout.', agend_apps_records_non_member_rate_note() );
		self::assertSame( 'Free for members and non-members.', agend_apps_records_free_for_all_note() );

		self::assertSame( array(), agend_apps_records_pricing_labels_for_js(), 'the script keeps its own wording' );

		$rows = agend_apps_records_ssr_ev_ticket_price_rows( self::TICKET, false );
		self::assertStringContainsString( '>Members<', $rows );
		self::assertStringContainsString( '>Non-Members<', $rows );
		self::assertStringContainsString( 'The non-member rate is shown where available.', $this->detail_registration() );
	}

	#[Test]
	public function should_treat_a_blank_or_whitespace_setting_as_the_default(): void {
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, '   ' );
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_RATE_LABEL_OPTION, '' );

		self::assertSame( 'Non-Members', agend_apps_records_non_member_label() );
		self::assertSame( 'non-member', agend_apps_records_non_member_rate_label() );
	}

	#[Test]
	public function should_use_the_site_wording_everywhere_when_it_is_set(): void {
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, 'Public' );
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_RATE_LABEL_OPTION, 'public' );

		self::assertSame( 'Public', agend_apps_records_non_member_label() );
		self::assertSame( 'The public rate is shown where available. Final pricing is confirmed at checkout.', agend_apps_records_non_member_rate_note() );
		self::assertSame( 'Free for everyone.', agend_apps_records_free_for_all_note() );

		$rows = agend_apps_records_ssr_ev_ticket_price_rows( self::TICKET, false );
		self::assertStringContainsString( '>Public<', $rows );
		self::assertStringNotContainsStringIgnoringCase( 'non-member', $rows );

		$registration = $this->detail_registration();
		self::assertStringContainsString( 'The public rate is shown where available.', $registration );
		self::assertStringNotContainsStringIgnoringCase( 'non-member', $registration );

		self::assertSame(
			array(
				'nonMember'     => 'Public',
				'nonMemberRate' => 'public',
				'nonMemberNote' => 'The public rate is shown where available. Final pricing is confirmed at checkout.',
				'freeForAll'    => 'Free for everyone.',
			),
			agend_apps_records_pricing_labels_for_js()
		);
	}

	#[Test]
	public function should_label_the_non_member_price_record_field_with_the_site_wording(): void {
		self::assertSame( 'Non-member price from', agend_apps_records_field_label( 'event:price_non_member_from', array() ) );

		update_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, 'Public' );
		self::assertSame( 'Public price from', agend_apps_records_field_label( 'event:price_non_member_from', array() ) );
	}

	#[Test]
	public function should_send_only_the_changed_label_to_the_script(): void {
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, 'Public' );

		self::assertSame( array( 'nonMember' => 'Public' ), agend_apps_records_pricing_labels_for_js() );
	}

	#[Test]
	public function should_let_a_filter_override_the_setting(): void {
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, 'Public' );
		add_filter( 'agend_apps_non_member_label', static fn( string $label ): string => 'Guests' );
		add_filter( 'agend_apps_non_member_rate_label', static fn( string $label ): string => 'guest' );

		self::assertSame( 'Guests', agend_apps_records_non_member_label() );
		self::assertSame( 'guest', agend_apps_records_non_member_rate_label() );
		self::assertStringContainsString( 'The guest rate is shown', agend_apps_records_non_member_rate_note() );
	}

	#[Test]
	public function should_pass_the_setting_to_the_filter_and_ignore_an_empty_result(): void {
		update_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, 'Public' );
		$seen = null;
		add_filter(
			'agend_apps_non_member_label',
			static function ( string $label ) use ( &$seen ) {
				$seen = $label;
				return '';
			}
		);

		self::assertSame( 'Public', agend_apps_records_non_member_label() );
		self::assertSame( 'Public', $seen );
	}

	#[Test]
	public function should_escape_the_wording_where_it_is_printed(): void {
		add_filter( 'agend_apps_non_member_label', static fn(): string => '<b>Public</b> & co' );
		add_filter( 'agend_apps_non_member_rate_label', static fn(): string => '<script>x</script>' );

		$rows = agend_apps_records_ssr_ev_ticket_price_rows( self::TICKET, false );
		self::assertStringNotContainsString( '<b>', $rows );
		self::assertStringContainsString( '&lt;b&gt;Public&lt;/b&gt; &amp; co', $rows );
		self::assertStringNotContainsString( '<script>', $this->detail_registration() );
	}

	#[Test]
	public function should_sanitise_a_submitted_label_to_short_plain_text(): void {
		self::assertSame( 'Public', agend_apps_records_sanitize_pricing_label( '  <em>Public</em>  ' ) );
		self::assertSame( '', agend_apps_records_sanitize_pricing_label( array( 'Public' ) ) );
		self::assertSame( AGEND_APPS_RECORDS_PRICING_LABEL_MAX, strlen( agend_apps_records_sanitize_pricing_label( str_repeat( 'a', 200 ) ) ) );
	}
}
