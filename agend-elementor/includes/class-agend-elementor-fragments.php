<?php
/**
 * Composite HTML fragments shared by the SSR catalogue detail pages and the
 * Elementor "field" widgets.
 *
 * Each fragment function renders one panel or section of the Events or
 * Courses detail (Tickets, Sponsors, Facts, Registration, Course Outcomes,
 * Course Meta, Course Enrolment) and returns it as a string. Pure value
 * formatters live in class-agend-elementor-format.php.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a ticket's first pricing-tier value for a group key.
 *
 * Mirrors ticketTierValue() in assets/js/events-catalogue.js.
 *
 * @param array  $entry The ticket entry (gateway shape).
 * @param string $key   The tier price key (member_price / non_member_price).
 * @return float|null The price, or null when the group has no explicit price.
 */
function agend_elementor_ssr_ev_ticket_tier_value( array $entry, string $key ): ?float {
	$tiers = ( isset( $entry['pricingTiers'] ) && is_array( $entry['pricingTiers'] ) ) ? $entry['pricingTiers'] : array();
	if ( empty( $tiers ) ) {
		return null;
	}
	$first = $tiers[0];
	$tier  = ( isset( $first['tier'] ) && is_array( $first['tier'] ) ) ? $first['tier'] : $first;
	if ( ! isset( $tier[ $key ] ) || null === $tier[ $key ] ) {
		return null;
	}
	return is_numeric( $tier[ $key ] ) ? (float) $tier[ $key ] : null;
}

/**
 * Builds a single `.agend-ev-price` row (escaped) for the Tickets panel.
 *
 * @param string $label     The tier label.
 * @param string $price     The formatted price.
 * @param bool   $is_active Whether this is the viewer's applicable price.
 * @return string The row HTML.
 */
function agend_elementor_ssr_ev_price_row( string $label, string $price, bool $is_active ): string {
	$is_free = ( __( 'FREE', 'agend-elementor' ) === $price );
	return sprintf(
		'<div class="agend-ev-price%1$s"><span class="agend-ev-price__label">%2$s</span><span class="agend-ev-price__value%3$s">%4$s</span></div>',
		$is_active ? ' is-yours' : '',
		esc_html( $label ),
		$is_free ? ' is-free' : '',
		esc_html( $price )
	);
}

/**
 * Builds the member/non-member price rows for one ticket (US-2.3).
 *
 * Mirrors ticketPriceRows() in assets/js/events-catalogue.js: the viewer's
 * applicable tier is highlighted via `.is-yours`; a flat-priced ticket (no
 * member/non-member split) shows a single highlighted price row.
 *
 * @param array $entry     The ticket entry (gateway shape).
 * @param bool  $is_member Whether the viewer's applicable price is the member tier.
 * @return string The concatenated, escaped price-row HTML (may be empty).
 */
function agend_elementor_ssr_ev_ticket_price_rows( array $entry, bool $is_member ): string {
	$member_val     = agend_elementor_ssr_ev_ticket_tier_value( $entry, 'member_price' );
	$non_member_val = agend_elementor_ssr_ev_ticket_tier_value( $entry, 'non_member_price' );

	// Flat price (no tiered split): a single applicable row.
	if ( null === $member_val && null === $non_member_val ) {
		$ticket = ( isset( $entry['ticket'] ) && is_array( $entry['ticket'] ) ) ? $entry['ticket'] : $entry;
		$flat   = $ticket['price'] ?? ( $ticket['base_price'] ?? null );
		$price  = agend_elementor_ssr_ev_format_price( $flat );
		return null === $price ? '' : agend_elementor_ssr_ev_price_row( __( 'Price', 'agend-elementor' ), $price, true );
	}

	$html          = '';
	$member_price  = agend_elementor_ssr_ev_format_price( $member_val );
	if ( null !== $member_price ) {
		$html .= agend_elementor_ssr_ev_price_row( __( 'Members', 'agend-elementor' ), $member_price, $is_member );
	}
	$non_member_price = agend_elementor_ssr_ev_format_price( $non_member_val );
	if ( null !== $non_member_price ) {
		$html .= agend_elementor_ssr_ev_price_row( __( 'Non-Members', 'agend-elementor' ), $non_member_price, ! $is_member );
	}
	return $html;
}

/**
 * Renders the sidebar enrolment panel for a signed-in member's course.
 *
 * Mirrors renderEnrollmentPanel() in assets/js/courses-catalogue.js: a
 * completed enrolment shows the completed state (semantic green), an in-progress
 * enrolment shows a progress bar, and neither ever shows a price
 * (SPEC-CORE-20260722 US-2.4, Decision 2.9).
 *
 * @param array $enrollment The viewer's my_enrollment record (gateway shape).
 * @return string Panel HTML with all dynamic values escaped.
 */
function agend_elementor_ssr_lms_enrollment_panel( array $enrollment ): string {
	$progress = ( ! empty( $enrollment['progress'] ) && is_array( $enrollment['progress'] ) )
		? $enrollment['progress']
		: array();

	ob_start();

	if ( ! empty( $progress['completed'] ) ) {
		$when = '';
		if ( ! empty( $progress['completed_at'] ) ) {
			$ts = strtotime( (string) $progress['completed_at'] );
			if ( false !== $ts ) {
				$when = ' ' . sprintf(
					/* translators: %s: completion date. */
					__( 'on %s', 'agend-elementor' ),
					wp_date( (string) get_option( 'date_format', 'j M Y' ), $ts )
				);
			}
		}
		?>
		<div class="agend-lms-detail__panel agend-lms-detail__panel--enrollment is-completed">
			<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Course Completed', 'agend-elementor' ); ?></h2>
			<div class="agend-lms-enrol__completed">
				<span class="agend-lms-enrol__tick">&#10003;</span>
				<span class="agend-lms-enrol__completed-text">
					<?php
					/* translators: %s: optional " on <date>" suffix. */
					echo esc_html( sprintf( __( 'You completed this course%s.', 'agend-elementor' ), $when ) );
					?>
				</span>
			</div>
			<p class="agend-lms-detail__note"><?php esc_html_e( 'Your certificate is available in your learning portal.', 'agend-elementor' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	$pct       = max( 0, min( 100, (int) round( (float) ( $progress['percentage'] ?? 0 ) ) ) );
	$done      = (int) ( $progress['lessons_completed'] ?? 0 );
	$total     = (int) ( $progress['total_lessons'] ?? 0 );
	?>
	<div class="agend-lms-detail__panel agend-lms-detail__panel--enrollment">
		<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Your Progress', 'agend-elementor' ); ?></h2>
		<div class="agend-lms-enrol__bar">
			<div class="agend-lms-enrol__bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
		</div>
		<div class="agend-lms-enrol__meta">
			<span class="agend-lms-enrol__lessons">
				<?php
				/* translators: 1: completed module count, 2: total module count. */
				echo esc_html( sprintf( __( '%1$d of %2$d modules complete', 'agend-elementor' ), $done, $total ) );
				?>
			</span>
			<span class="agend-lms-enrol__pct"><?php echo esc_html( $pct . '%' ); ?></span>
		</div>
		<p class="agend-lms-detail__note"><?php esc_html_e( 'Continue learning in your member portal.', 'agend-elementor' ); ?></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Events detail Sponsors section.
 *
 * @param array $item Public event detail (gateway shape).
 * @return string Section HTML, or empty string when the event has no sponsors.
 */
function agend_elementor_fragment_event_sponsors( array $item ): string {
	ob_start();
	?>
					<?php if ( ! empty( $item['sponsors'] ) && is_array( $item['sponsors'] ) ) : ?>
						<section class="agend-ev-detail__section">
							<h2 class="agend-ev-detail__section-title"><?php esc_html_e( 'Sponsors', 'agend-elementor' ); ?></h2>
							<div class="agend-ev-detail__sponsors">
								<?php foreach ( $item['sponsors'] as $sponsor ) : ?>
									<?php if ( ! empty( $sponsor['logo_url'] ) ) : ?>
										<img class="agend-ev-detail__sponsor-logo" src="<?php echo esc_url( $sponsor['logo_url'] ); ?>" alt="<?php echo esc_attr( $sponsor['name'] ?? '' ); ?>" loading="lazy" />
									<?php elseif ( ! empty( $sponsor['name'] ) ) : ?>
										<span class="agend-ev-detail__sponsor-name"><?php echo esc_html( $sponsor['name'] ); ?></span>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>
	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Events detail Registration panel (registered notice, Register
 * Now / Sold Out CTA, iCal link, member/non-member note).
 *
 * @param array  $item  Public event detail (gateway shape).
 * @param string $slug  Event slug.
 * @param array  $extra Unused; reserved for future pass-through context.
 * @return string Panel HTML.
 */
function agend_elementor_fragment_event_registration( array $item, string $slug, array $extra = array() ): string {
	$sold_out = ! empty( $item['sold_out'] );
	$ical_url = rest_url( 'agend-apps/v1/events/' . rawurlencode( $slug ) . '/ical' );

	ob_start();
	?>
					<div class="agend-ev-detail__panel agend-ev-detail__panel--register">
						<h2 class="agend-ev-detail__panel-title"><?php esc_html_e( 'Registration', 'agend-elementor' ); ?></h2>
						<?php
						// Member enrichment (SPEC-CORE-20260722 US-2.3): the fetch is
						// bearer-attended and cache-bypassed for signed-in members, so
						// the viewer's registration state and price group arrive on
						// $item. Absent fields resolve to the anonymous baseline.
						$viewer_group   = isset( $item['viewer_price_group'] ) ? (string) $item['viewer_price_group'] : '';
						$is_member      = ( 'member' === $viewer_group || 'corporate' === $viewer_group );
						$is_registered  = ! empty( $item['my_registration'] );
						$register_label = $sold_out
							? __( 'Sold Out', 'agend-elementor' )
							: ( $is_registered ? __( 'Register Another Attendee', 'agend-elementor' ) : __( 'Register Now', 'agend-elementor' ) );
						?>
						<?php if ( $is_registered ) : ?>
							<div class="agend-ev-detail__registered"><?php esc_html_e( '✓ You’re registered for this event', 'agend-elementor' ); ?></div>
						<?php endif; ?>
						<button type="button" class="agend-ev-detail__cta" data-agend-event-slug="<?php echo esc_attr( $slug ); ?>"<?php echo $sold_out ? ' disabled' : ''; ?>>
							<?php echo esc_html( $register_label ); ?>
						</button>
						<a class="agend-ev-detail__calendar" href="<?php echo esc_url( $ical_url ); ?>"><?php esc_html_e( 'Add to Calendar', 'agend-elementor' ); ?></a>
						<?php if ( $is_member ) : ?>
							<p class="agend-ev-detail__note"><?php esc_html_e( 'Member pricing applies to your registration.', 'agend-elementor' ); ?></p>
						<?php else : ?>
							<p class="agend-ev-detail__note"><?php esc_html_e( 'Not a member? Join for discounted pricing.', 'agend-elementor' ); ?></p>
						<?php endif; ?>
					</div>

	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Events detail Tickets panel (member/non-member price rows per
 * ticket type, with the viewer's applicable price highlighted).
 *
 * @param array $item    Public event detail (gateway shape).
 * @param array $tickets Ticket-type list (gateway shape).
 * @return string Panel HTML, or empty string when there are no priced tickets.
 */
function agend_elementor_fragment_event_tickets( array $item, array $tickets ): string {
	$viewer_group = isset( $item['viewer_price_group'] ) ? (string) $item['viewer_price_group'] : '';
	$is_member    = ( 'member' === $viewer_group || 'corporate' === $viewer_group );

	ob_start();
	?>
					<?php
					// Tickets panel (SPEC-CORE-20260722 US-2.3): all ticket types with
					// the viewer's applicable price highlighted. Ticket prices are the
					// public list; $is_member (from the event's bearer-enriched
					// viewer_price_group) selects which row is active.
					if ( ! empty( $tickets ) ) :
						?>
						<div class="agend-ev-detail__panel agend-ev-detail__panel--tickets">
							<h2 class="agend-ev-detail__panel-title"><?php esc_html_e( 'Tickets', 'agend-elementor' ); ?></h2>
							<div class="agend-ev-detail__tickets">
								<?php
								foreach ( $tickets as $entry ) :
									if ( ! is_array( $entry ) ) {
										continue;
									}
									$ticket      = ( isset( $entry['ticket'] ) && is_array( $entry['ticket'] ) ) ? $entry['ticket'] : $entry;
									$ticket_name = isset( $ticket['name'] ) ? (string) $ticket['name'] : __( 'Ticket', 'agend-elementor' );
									$rows        = agend_elementor_ssr_ev_ticket_price_rows( $entry, $is_member );
									if ( '' === $rows ) {
										continue;
									}
									?>
									<div class="agend-ev-detail__ticket">
										<span class="agend-ev-detail__ticket-name"><?php echo esc_html( $ticket_name ); ?></span>
										<div class="agend-ev-detail__ticket-prices"><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rows escaped in agend_elementor_ssr_ev_price_row(). ?></div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
						<?php
					endif;
					?>

	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Events detail Details facts panel (Date & Time, Location,
 * Format).
 *
 * @param array $item Public event detail (gateway shape).
 * @return string Panel HTML.
 */
function agend_elementor_fragment_event_facts( array $item ): string {
	$event_tz   = agend_elementor_record_timezone( $item );
	$venue_type = isset( $item['venue_type'] ) ? (string) $item['venue_type'] : '';
	$type_label = agend_elementor_ssr_ev_type_label( $venue_type );

	$location = implode(
		', ',
		array_filter(
			array( $item['venue_name'] ?? '', $item['venue_address'] ?? '', $item['venue_city'] ?? '' ),
			static fn( $part ) => '' !== (string) $part
		)
	);
	if ( '' === $location ) {
		$location = 'virtual' === $venue_type ? __( 'Online', 'agend-elementor' ) : __( 'TBA', 'agend-elementor' );
	}

	ob_start();
	?>
					<div class="agend-ev-detail__panel">
						<h2 class="agend-ev-detail__panel-title"><?php esc_html_e( 'Details', 'agend-elementor' ); ?></h2>
						<?php
						$facts = array(
							array( __( 'Date & Time', 'agend-elementor' ), agend_elementor_ssr_ev_date_time( $item['start_date'] ?? '', $item['end_date'] ?? '', $event_tz ) ),
							array( __( 'Location', 'agend-elementor' ), $location ),
							array( __( 'Format', 'agend-elementor' ), $type_label ),
						);
						foreach ( $facts as $pair ) :
							if ( '' === (string) $pair[1] ) {
								continue;
							}
							?>
							<div class="agend-ev-detail__fact">
								<span class="agend-ev-detail__fact-label"><?php echo esc_html( $pair[0] ); ?></span>
								<span class="agend-ev-detail__fact-value"><?php echo esc_html( $pair[1] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Courses detail "What You'll Learn" outcomes section.
 *
 * @param array $item Course detail (gateway shape).
 * @return string Section HTML, or empty string when the course has no
 *                learning outcomes.
 */
function agend_elementor_fragment_course_outcomes( array $item ): string {
	ob_start();
	?>
					<?php
					$outcomes = ( ! empty( $item['learning_outcomes'] ) && is_array( $item['learning_outcomes'] ) )
						? $item['learning_outcomes']
						: array();
					if ( ! empty( $outcomes ) ) :
						?>
						<section class="agend-lms-detail__section">
							<h2 class="agend-lms-detail__section-title"><?php esc_html_e( "What You'll Learn", 'agend-elementor' ); ?></h2>
							<ul class="agend-lms-detail__outcomes">
								<?php
								foreach ( $outcomes as $outcome ) :
									$text = is_string( $outcome ) ? $outcome : (string) ( $outcome['text'] ?? '' );
									if ( '' === $text ) {
										continue;
									}
									?>
									<li class="agend-lms-detail__outcome"><?php echo esc_html( $text ); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endif; ?>

	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Courses detail sidebar enrolment/pricing panel: a signed-in
 * member's progress panel when enrolled, otherwise the anonymous pricing
 * panel with a member sign-in CTA (SPEC-CORE-20260722 US-2.4, Decision 2.9).
 *
 * @param array  $item  Course detail (gateway shape; bearer-enriched for members).
 * @param string $slug  Course slug.
 * @param array  $extra { host?: WP_Post } The catalogue (host) page, for the
 *                       sign-in redirect URL.
 * @return string Panel HTML.
 */
function agend_elementor_fragment_course_enrolment( array $item, string $slug, array $extra = array() ): string {
	$price       = agend_elementor_ssr_lms_price( $item );
	$host        = ( isset( $extra['host'] ) && $extra['host'] instanceof WP_Post ) ? $extra['host'] : null;
	$host_url    = $host instanceof WP_Post ? get_permalink( $host->ID ) : '';
	$detail_url  = trailingslashit( is_string( $host_url ) ? $host_url : '' ) . 'course/' . $slug . '/';
	$sign_in_url = wp_login_url( $detail_url );

	ob_start();
	?>
					<?php
					// Member enrichment (SPEC-CORE-20260722 US-2.4): the LMS
					// single-course fetch bypasses the shared cache for a signed-in
					// member, so my_enrollment arrives on $item. An enrolled member
					// gets their progress panel and never the "Sign in to enrol"
					// pricing shell (Decision 2.9); everyone else keeps the
					// anonymous pricing panel and the client hydrates the
					// identity-aware CTA.
					$enrollment = ( ! empty( $item['my_enrollment'] ) && is_array( $item['my_enrollment'] ) )
						? $item['my_enrollment']
						: null;
					if ( null !== $enrollment ) {
						echo agend_elementor_ssr_lms_enrollment_panel( $enrollment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Panel escapes internally.
					} else {
						?>
						<div class="agend-lms-detail__panel agend-lms-detail__panel--pricing">
							<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Course Pricing', 'agend-elementor' ); ?></h2>
							<div class="agend-lms-detail__price-row">
								<span class="agend-lms-detail__price-label"><?php esc_html_e( 'Price', 'agend-elementor' ); ?></span>
								<span class="agend-lms-detail__price-value<?php echo ( __( 'Free', 'agend-elementor' ) === $price ) ? ' is-free' : ''; ?>"><?php echo esc_html( $price ); ?></span>
							</div>
							<a class="agend-lms-detail__cta" href="<?php echo esc_url( $sign_in_url ); ?>"><?php esc_html_e( 'Enrol Now', 'agend-elementor' ); ?></a>
							<p class="agend-lms-detail__note"><?php esc_html_e( 'Sign in to enrol and track your progress.', 'agend-elementor' ); ?></p>
						</div>
						<?php
					}
					?>

	<?php
	return (string) ob_get_clean();
}

/**
 * Renders the Courses detail Details facts panel (Level, Format, Duration,
 * Modules, Category, Instructor).
 *
 * @param array $item Course detail (gateway shape).
 * @return string Panel HTML.
 */
function agend_elementor_fragment_course_meta( array $item ): string {
	$difficulty = isset( $item['difficulty'] ) ? (string) $item['difficulty'] : '';
	$mode       = isset( $item['delivery_mode'] ) ? (string) $item['delivery_mode'] : '';
	$category   = isset( $item['category'] ) ? (string) $item['category'] : '';
	$instructor = isset( $item['instructor_name'] ) ? (string) $item['instructor_name'] : '';
	$duration   = agend_elementor_ssr_lms_duration( $item['total_duration_minutes'] ?? null );
	$lessons    = (int) ( $item['lessons_count'] ?? 0 );

	ob_start();
	?>
					<div class="agend-lms-detail__panel">
						<h2 class="agend-lms-detail__panel-title"><?php esc_html_e( 'Details', 'agend-elementor' ); ?></h2>
						<?php
						$facts = array(
							array( __( 'Level', 'agend-elementor' ), agend_elementor_ssr_lms_difficulty( $difficulty ) ),
							array( __( 'Format', 'agend-elementor' ), agend_elementor_ssr_lms_mode( $mode ) ),
							array( __( 'Duration', 'agend-elementor' ), $duration ),
							array( __( 'Modules', 'agend-elementor' ), $lessons > 0 ? (string) $lessons : '' ),
							array( __( 'Category', 'agend-elementor' ), $category ),
							array( __( 'Instructor', 'agend-elementor' ), $instructor ),
						);
						foreach ( $facts as $pair ) :
							if ( '' === (string) $pair[1] ) {
								continue;
							}
							?>
							<div class="agend-lms-detail__fact">
								<span class="agend-lms-detail__fact-label"><?php echo esc_html( $pair[0] ); ?></span>
								<span class="agend-lms-detail__fact-value"><?php echo esc_html( $pair[1] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
	<?php
	return (string) ob_get_clean();
}
