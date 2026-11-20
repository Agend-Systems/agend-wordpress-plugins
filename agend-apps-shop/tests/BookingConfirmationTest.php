<?php

declare( strict_types=1 );

namespace Agend\Tests\Shop;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname( __DIR__ ) . '/includes/booking-confirmation.php';

final class BookingConfirmationTest extends TestCase {
	#[Test]
	public function should_render_the_gateway_receipt_when_an_event_checkout_is_confirmed(): void {
		$html = agend_apps_shop_render_confirmed_booking(
			array(
				'order_number' => 'CRM-2026-00026',
				'total_cents' => 46200,
				'currency' => 'AUD',
				'events' => array(
					array(
						'name' => 'Project Management',
						'slug' => 'project-management',
						'venue_name' => 'Meeting Room',
						'attendees' => array( 'Taylor Example' ),
					),
				),
			),
			false,
			'',
			''
		);
		self::assertStringContainsString( 'Booking confirmed', $html );
		self::assertStringContainsString( 'CRM-2026-00026', $html );
		self::assertStringContainsString( 'AUD 462.00', $html );
		self::assertStringContainsString( 'Meeting Room', $html );
		self::assertStringContainsString( 'Taylor Example', $html );
		self::assertStringContainsString( '/events/project-management/ical', $html );
	}

	#[Test]
	public function should_render_an_order_confirmation_when_a_paid_receipt_has_no_events(): void {
		$html = agend_apps_shop_render_confirmed_booking( array(), false, '', '' );
		self::assertStringContainsString( 'Order confirmed', $html );
		self::assertStringNotContainsString( 'your place is booked', $html );
		self::assertStringNotContainsString( 'Add to Calendar', $html );
	}
}
