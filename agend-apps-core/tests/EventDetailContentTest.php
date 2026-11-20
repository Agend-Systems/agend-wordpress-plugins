<?php
/** @package Agend\Tests */
declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/assets.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';

final class EventDetailContentTest extends TestCase {
	private function render( array $extra = array() ): string {
		$event = array_merge( array(
			'name' => 'Annual Conference',
			'description' => '<p>About the event.</p>',
			'timezone' => 'Australia/Adelaide',
			'start_date' => '2027-02-28T14:00:00+10:30',
			'end_date' => '2027-03-03T16:00:00+10:30',
		), $extra );
		return agend_apps_records_render_events_detail( $event, 'conference', new WP_Post( array( 'ID' => 1 ) ) );
	}

	#[Test]
	public function should_render_presenters_after_description_when_speakers_are_present(): void {
		$html = $this->render( array( 'speakers' => array(
			array( 'name' => 'Alex <Example>', 'title' => 'Director & Founder', 'bio' => '<p>Presenter biography.</p>', 'photo_url' => 'https://example.test/photo.jpg' ),
			array( 'name' => 'Sam Example' ),
		) ) );
		self::assertStringContainsString( 'Presenters', $html );
		self::assertStringContainsString( 'Alex &lt;Example&gt;', $html );
		self::assertStringContainsString( 'Director &amp; Founder', $html );
		self::assertStringContainsString( '<p>Presenter biography.</p>', $html );
		self::assertStringContainsString( 'src="https://example.test/photo.jpg"', $html );
		self::assertStringContainsString( 'alt="Alex &lt;Example&gt;"', $html );
		self::assertStringContainsString( 'Sam Example', $html );
		self::assertSame( 1, substr_count( $html, 'class="agend-ev-detail__presenter-photo"' ) );
		self::assertLessThan( strpos( $html, '>Presenters<' ), strpos( $html, 'About the event.' ) );
	}

	#[Test]
	public function should_render_gallery_when_images_are_present(): void {
		$html = $this->render( array( 'gallery_images' => array( 'https://example.test/one.jpg', 'https://example.test/two.jpg' ) ) );
		self::assertStringContainsString( '>Gallery<', $html );
		self::assertStringContainsString( 'src="https://example.test/one.jpg"', $html );
		self::assertStringContainsString( 'src="https://example.test/two.jpg"', $html );
		self::assertStringContainsString( 'alt="Annual Conference"', $html );
	}

	#[Test]
	public function should_omit_optional_sections_when_speakers_and_gallery_are_empty(): void {
		$html = $this->render( array( 'speakers' => array(), 'gallery_images' => array() ) );
		self::assertStringNotContainsString( '>Presenters<', $html );
		self::assertStringNotContainsString( '>Gallery<', $html );
	}

	#[Test]
	public function should_show_full_range_when_event_ends_on_another_day(): void {
		self::assertStringContainsString( 'Sunday, 28 February 2027, 2:00 pm - Wednesday, 3 March 2027, 4:00 pm', $this->render() );
	}

	#[Test]
	public function should_keep_single_day_format_when_event_ends_on_the_same_local_day(): void {
		self::assertSame( 'Sunday, 28 February 2027, 2:00 pm – 4:00 pm ACDT', agend_apps_records_ssr_ev_date_time( '2027-02-28T14:00:00+10:30', '2027-02-28T16:00:00+10:30', new DateTimeZone( 'Australia/Adelaide' ) ) );
	}

	#[Test]
	public function should_use_event_timezone_when_utc_dates_differ_but_local_day_matches(): void {
		self::assertSame( 'Monday, 1 March 2027, 10:00 am – 11:00 am ACDT', agend_apps_records_ssr_ev_date_time( '2027-02-28T23:30:00Z', '2027-03-01T00:30:00Z', new DateTimeZone( 'Australia/Adelaide' ) ) );
	}
}
