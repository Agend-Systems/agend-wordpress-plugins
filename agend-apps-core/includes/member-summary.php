<?php
/**
 * The member summary: what a signed-in member sees of their own membership,
 * CPD, events, certificates and directory listing, read from Agend and reduced
 * to plain values a surface can draw.
 *
 * Three principles hold throughout.
 *
 * Truthful. A section is `ready` (there is something to show), `empty` (Agend
 * answered and there is nothing), `unavailable` (the read failed, or its answer
 * could not be trusted), `no_access` (the key lacks what the read needs) or
 * `hidden` (nothing to draw, and no honest thing to say). "Nothing" is never
 * the answer to a failure: a CPD tile whose read failed does not say 0 points,
 * and a missing directory listing is not "you are not listed", because the
 * gateway sends the same null for a failed read.
 *
 * Defensive. The connected profile is a contract the gateway owns, so every
 * field is optional, unknown fields are ignored, and nothing is assumed to be
 * the type it is documented as. A future additive change cannot break a
 * section, and the shape this was written against is pinned by a fixture in the
 * tests.
 *
 * Cheap. A load costs at most six reads, sent together: the profile (one read
 * serves the CPD, events, certificates, directory and greeting parts), the two
 * membership reads and the tier names, then one each for the courses and the
 * reading lists. A part that is switched off is never read, and the tier names
 * come from a copy the whole site shares once one exists. Nothing about the
 * member is stored: the reads are held for this request only. A per-member cap
 * and a check of what is left of the site key's minute stand in front of the
 * fetch.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The parts of the summary, in the order they are drawn.
 *
 * The membership is the band across the top; the others are tiles.
 *
 * @return string[]
 */
function agend_apps_member_summary_sections(): array {
	return array( 'membership', 'cpd', 'events', 'courses', 'credentials', 'directory', 'reading' );
}

/**
 * The most courses, or reading lists, the summary will list.
 *
 * @var int
 */
const AGEND_APPS_MEMBER_SUMMARY_COURSE_READ_LIMIT = 12;

/**
 * The key permissions each part needs, all with the member's own token.
 *
 * `greeting` is the member's first name, which comes with the profile.
 *
 * @return array<string, string[]>
 */
function agend_apps_member_summary_scopes(): array {
	return array(
		'membership'  => array( 'crm.memberships.browse' ),
		'cpd'         => array( 'crm.contacts.browse' ),
		'events'      => array( 'crm.contacts.browse' ),
		'credentials' => array( 'crm.contacts.browse' ),
		'directory'   => array( 'crm.contacts.browse' ),
		'courses'     => array( 'lms.enrollments.browse' ),
		'reading'     => array( 'atlas.collections.browse' ),
		'greeting'    => array( 'crm.contacts.browse' ),
	);
}

/**
 * Whether the key is known to hold every scope in a list, and to be allowed the
 * apps they belong to.
 *
 * Answers from the stored scope record, never a refresh. Where the scopes are
 * not known, or the site has switched the client's guard off, the answer is yes
 * and the gateway stays the authority, exactly as the client behaves.
 *
 * @param string[] $scopes Scopes to test.
 * @return bool
 */
function agend_apps_member_summary_holds( array $scopes ): bool {
	if ( ! class_exists( 'Agend_Apps_Key_Scopes' ) || Agend_Apps_Key_Scopes::refreshing() || ! Agend_Apps_Key_Scopes::known() ) {
		return true;
	}

	$held    = Agend_Apps_Key_Scopes::stored_scopes();
	$missing = array();

	foreach ( $scopes as $scope ) {
		$app = (string) strtok( $scope, '.' );

		if ( ! Agend_Apps_Key_Scopes::satisfies( $held, $scope ) || ( '' !== $app && ! Agend_Apps_Key_Scopes::has_app( $app ) ) ) {
			$missing[] = $scope;
		}
	}

	if ( array() === $missing ) {
		return true;
	}

	// The switch the request guard and the surface gate honour.
	return ! apply_filters( 'agend_apps_api_block_unauthorised', true, 'GET', '/surfaces/member-summary', $missing );
}

/**
 * Which reads a summary needs, and which parts the key rules out.
 *
 * @param array $want {
 *     @type string[] $sections Parts to show; unknown names are ignored.
 *     @type bool     $greeting Whether the member's first name is wanted.
 * }
 * @return array{reads: string[], sections: string[], no_access: string[], greeting: bool}
 *         `reads` are among `profile`, `entitlements`, `memberships`, `tiers`,
 *         `enrollments`, `collections`.
 */
function agend_apps_member_summary_plan( array $want ): array {
	$scopes   = agend_apps_member_summary_scopes();
	$sections = array_values( array_intersect( agend_apps_member_summary_sections(), array_map( 'strval', (array) ( $want['sections'] ?? array() ) ) ) );

	$allowed   = array();
	$no_access = array();

	foreach ( $sections as $section ) {
		if ( agend_apps_member_summary_holds( $scopes[ $section ] ) ) {
			$allowed[] = $section;
		} else {
			$no_access[] = $section;
		}
	}

	$greeting = ! empty( $want['greeting'] ) && agend_apps_member_summary_holds( $scopes['greeting'] );
	$reads    = array();

	if ( $greeting || array() !== array_intersect( $allowed, array( 'cpd', 'events', 'credentials', 'directory' ) ) ) {
		$reads[] = 'profile';
	}

	if ( in_array( 'membership', $allowed, true ) ) {
		$reads[] = 'entitlements';
		$reads[] = 'memberships';

		// Tier names are public catalogue data and are shared by every visitor.
		// Without the permission the membership still shows, without a name.
		if ( agend_apps_member_summary_holds( array( 'crm.tiers.browse' ) ) ) {
			$reads[] = 'tiers';
		}
	}

	if ( in_array( 'courses', $allowed, true ) ) {
		$reads[] = 'enrollments';
	}

	if ( in_array( 'reading', $allowed, true ) ) {
		$reads[] = 'collections';
	}

	return array(
		'reads'     => $reads,
		'sections'  => $sections,
		'no_access' => $no_access,
		'greeting'  => $greeting,
	);
}

// ---------------------------------------------------------------------------
// Reading what Agend answered
// ---------------------------------------------------------------------------

/**
 * The payload of a decoded gateway answer: `data` when the answer has one, else
 * the answer without the status the client adds.
 *
 * @param mixed $response A read's result.
 * @return array|null Null for a failed read or an answer that is not an object.
 */
function agend_apps_member_summary_payload( $response ): ?array {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return null;
	}

	if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
		return $response['data'];
	}

	unset( $response['status_code'], $response['success'] );

	return $response;
}

/**
 * What a failed read means for the part that wanted it: `no_access` when the
 * client itself refused the call for want of a permission, else `unavailable`.
 *
 * @param mixed $response A read's result.
 * @return string `no_access` or `unavailable`.
 */
function agend_apps_member_summary_failure( $response ): string {
	if ( is_wp_error( $response ) && in_array( $response->get_error_code(), array( 'agend_apps_scope_missing', 'agend_apps_app_not_allowed' ), true ) ) {
		return 'no_access';
	}

	return 'unavailable';
}

/**
 * Plain text for display: tags and control characters removed, whitespace
 * folded. Gateway strings are drawn escaped in any case; this keeps stray
 * markup from showing as text too.
 *
 * @param mixed $value Anything.
 * @param int   $max   Most characters kept.
 * @return string
 */
function agend_apps_member_summary_text( $value, int $max = 200 ): string {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );

	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

/**
 * A finite number from a value that should be one, or null.
 *
 * @param mixed $value Anything.
 * @return float|null
 */
function agend_apps_member_summary_number( $value ): ?float {
	return is_numeric( $value ) && is_finite( (float) $value ) ? (float) $value : null;
}

/**
 * A whole count from a value that should be one, or null when it is not.
 *
 * @param mixed $value Anything.
 * @return int|null
 */
function agend_apps_member_summary_count( $value ): ?int {
	$number = agend_apps_member_summary_number( $value );

	return null !== $number && $number >= 0 ? (int) $number : null;
}

/**
 * The rows of a list read: a plain list of arrays, whether the answer put them
 * in `data` or sent them bare.
 *
 * @param mixed $response A list read's result.
 * @return array[]|null Null for a failed or unreadable answer.
 */
function agend_apps_member_summary_rows( $response ): ?array {
	$payload = agend_apps_member_summary_payload( $response );

	if ( null === $payload ) {
		return null;
	}

	return array_values( array_filter( $payload, 'is_array' ) );
}

/**
 * The member's first name, from the profile.
 *
 * @param array|null $profile The profile payload.
 * @return string
 */
function agend_apps_member_summary_first_name( ?array $profile ): string {
	$contact = is_array( $profile['contact'] ?? null ) ? $profile['contact'] : array();

	return agend_apps_member_summary_text( $contact['first_name'] ?? '', 60 );
}

/**
 * Up to two initials from a first and last name, upper case, or '' when there is
 * no first name to take one from.
 *
 * @param string $first First name.
 * @param string $last  Last name.
 * @return string
 */
function agend_apps_member_summary_initials( string $first, string $last ): string {
	$take = static function ( string $name ): string {
		$letter = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letter ) : strtoupper( $letter );
	};

	return '' === $first ? '' : $take( $first ) . ( '' !== $last ? $take( $last ) : '' );
}

/**
 * Who the member is, for the welcome band: first name, initials, and the role
 * line (job title and organisation) when the contact record has them.
 *
 * Every field is optional. The member's own name and role are shown to the member
 * only, in the answer to their own request.
 *
 * @param array|null $profile The profile payload.
 * @return array{first_name: string, initials: string, title: string, company: string}
 */
function agend_apps_member_summary_contact( ?array $profile ): array {
	$contact = is_array( $profile['contact'] ?? null ) ? $profile['contact'] : array();
	$first   = agend_apps_member_summary_first_name( $profile );
	$last    = agend_apps_member_summary_text( $contact['last_name'] ?? '', 60 );
	$company = agend_apps_member_summary_text( $contact['primary_company_name'] ?? '', 120 );

	if ( '' === $company ) {
		$company = agend_apps_member_summary_text( $contact['company'] ?? '', 120 );
	}

	return array(
		'first_name' => $first,
		'initials'   => agend_apps_member_summary_initials( $first, $last ),
		'title'      => agend_apps_member_summary_text( $contact['job_title'] ?? '', 120 ),
		'company'    => $company,
	);
}

/**
 * The CPD part, from the profile's learning block.
 *
 * Four honest forms: a target that resolved (earned, target, pace, time left),
 * a total with no target (not set up, or not applicable), nothing earned yet,
 * and unavailable. An invented target is never drawn: where Agend gives none,
 * only what the member has earned is shown. A `cpd_resolution` this code does
 * not know makes the part unavailable rather than guessed at.
 *
 * @param array|null $lms The profile's `lms` block, null when it failed.
 * @return array
 */
function agend_apps_member_summary_cpd( ?array $lms ): array {
	if ( null === $lms ) {
		return array( 'status' => 'unavailable' );
	}

	$resolution = isset( $lms['cpd_resolution'] ) && is_string( $lms['cpd_resolution'] ) ? $lms['cpd_resolution'] : '';

	if ( ! in_array( $resolution, array( '', 'resolved', 'not_configured', 'not_applicable', 'failed' ), true ) || 'failed' === $resolution ) {
		return array( 'status' => 'unavailable' );
	}

	$total  = agend_apps_member_summary_number( $lms['total_cpd_points'] ?? null );
	$cpd    = is_array( $lms['cpd'] ?? null ) ? $lms['cpd'] : null;
	$target = null !== $cpd ? agend_apps_member_summary_number( $cpd['effective_target'] ?? null ) : null;

	if ( in_array( $resolution, array( '', 'resolved' ), true ) && null !== $cpd && null !== $target && $target > 0 ) {
		$earned = agend_apps_member_summary_number( $cpd['earned'] ?? null ) ?? $total ?? 0.0;
		$pace   = isset( $cpd['status'] ) && is_string( $cpd['status'] ) && in_array( $cpd['status'], array( 'met', 'on_track', 'behind' ), true ) ? $cpd['status'] : '';
		$days   = isset( $cpd['days_remaining'] ) && is_numeric( $cpd['days_remaining'] ) ? (int) $cpd['days_remaining'] : null;
		$adjust = is_array( $cpd['adjustment'] ?? null ) ? agend_apps_member_summary_text( $cpd['adjustment']['reason_label'] ?? '', 80 ) : '';

		return array(
			'status'     => 'ready',
			'form'       => 'target',
			'earned'     => max( 0.0, $earned ),
			'target'     => $target,
			'pace'       => $pace,
			'days_left'  => null !== $days && $days >= 0 ? $days : null,
			'period_end' => agend_apps_member_summary_text( $cpd['period_end'] ?? '', 40 ),
			'adjustment' => $adjust,
			'completed'  => agend_apps_member_summary_count( $lms['courses_completed'] ?? null ),
		);
	}

	$total = max( 0.0, $total ?? ( null !== $cpd ? (float) ( agend_apps_member_summary_number( $cpd['earned'] ?? null ) ?? 0 ) : 0.0 ) );

	return array(
		'status'    => $total > 0 ? 'ready' : 'empty',
		'form'      => 'total',
		'earned'    => $total,
		// Why there is no target, so the card can say so plainly.
		'why'       => 'not_applicable' === $resolution ? 'not_applicable' : 'not_set',
		'completed' => agend_apps_member_summary_count( $lms['courses_completed'] ?? null ),
	);
}

/**
 * The upcoming events part, from the profile's events block.
 *
 * @param array|null $events The profile's `events` block, null when it failed.
 * @param int        $max    Most events listed.
 * @return array
 */
function agend_apps_member_summary_events( ?array $events, int $max ): array {
	if ( null === $events ) {
		return array( 'status' => 'unavailable' );
	}

	$items = array();

	foreach ( (array) ( $events['upcoming'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$title = agend_apps_member_summary_text( $row['event_title'] ?? '' );

		if ( '' === $title ) {
			continue;
		}

		$date = agend_apps_member_summary_text( $row['date'] ?? '', 40 );
		$time = '' !== $date ? strtotime( $date ) : false;

		$items[] = array(
			'title'  => $title,
			'slug'   => sanitize_title( agend_apps_member_summary_text( $row['event_slug'] ?? '', 200 ) ),
			'date'   => false === $time ? '' : $date,
			'time'   => false === $time ? PHP_INT_MAX : (int) $time,
			'status' => strtolower( agend_apps_member_summary_text( $row['status'] ?? '', 30 ) ),
		);
	}

	usort(
		$items,
		static function ( array $a, array $b ): int {
			return $a['time'] <=> $b['time'];
		}
	);

	$total = count( $items );
	$items = array_map(
		static function ( array $item ): array {
			unset( $item['time'] );

			return $item;
		},
		array_slice( $items, 0, max( 1, $max ) )
	);

	return array(
		'status' => array() === $items ? 'empty' : 'ready',
		'items'  => $items,
		'total'  => $total,
	);
}

/**
 * The certificates and badges part, from the profile's learning block.
 *
 * A certificate's verification code is kept only if it is the shape of one, so
 * it can go into a link; the code is the member's own and goes nowhere else.
 *
 * @param array|null $lms        The profile's `lms` block, null when it failed.
 * @param int        $max        Most certificates listed.
 * @param int        $max_badges Most badges listed.
 * @return array
 */
function agend_apps_member_summary_credentials( ?array $lms, int $max, int $max_badges = 8 ): array {
	if ( null === $lms ) {
		return array( 'status' => 'unavailable' );
	}

	$certificates = array();

	foreach ( (array) ( $lms['certificates'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$title = agend_apps_member_summary_text( $row['course_title'] ?? '' );

		if ( '' === $title ) {
			continue;
		}

		$code   = isset( $row['verification_code'] ) && is_string( $row['verification_code'] ) ? trim( $row['verification_code'] ) : '';
		$issued = agend_apps_member_summary_text( $row['issued_at'] ?? '', 40 );

		$certificates[] = array(
			'title'  => $title,
			'code'   => 1 === preg_match( '/^[A-Za-z0-9_-]{8,100}$/', $code ) ? $code : '',
			'issued' => '' !== $issued && false !== strtotime( $issued ) ? $issued : '',
			'cpd'    => agend_apps_member_summary_number( $row['cpd_points'] ?? null ),
			'time'   => '' !== $issued && false !== strtotime( $issued ) ? (int) strtotime( $issued ) : 0,
		);
	}

	$badges = array();

	foreach ( (array) ( $lms['badges'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$name = agend_apps_member_summary_text( $row['badge_name'] ?? '', 120 );

		if ( '' === $name ) {
			continue;
		}

		$colour = isset( $row['badge_color'] ) && is_string( $row['badge_color'] ) ? trim( $row['badge_color'] ) : '';
		$earned = agend_apps_member_summary_text( $row['earned_at'] ?? '', 40 );

		$badges[] = array(
			'name'   => $name,
			'colour' => 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', $colour ) ? $colour : '',
			'course' => agend_apps_member_summary_text( $row['course_title'] ?? '', 120 ),
			'time'   => '' !== $earned && false !== strtotime( $earned ) ? (int) strtotime( $earned ) : 0,
		);
	}

	$newest_first = static function ( array $a, array $b ): int {
		return $b['time'] <=> $a['time'];
	};

	usort( $certificates, $newest_first );
	usort( $badges, $newest_first );

	$strip = static function ( array $item ): array {
		unset( $item['time'] );

		return $item;
	};

	return array(
		'status'             => array() === $certificates && array() === $badges ? 'empty' : 'ready',
		'certificates'       => array_map( $strip, array_slice( $certificates, 0, max( 1, $max ) ) ),
		'certificates_total' => count( $certificates ),
		'badges'             => array_map( $strip, array_slice( $badges, 0, max( 1, $max_badges ) ) ),
		'badges_total'       => count( $badges ),
	);
}

/**
 * The directory listing part, from the profile's directory block.
 *
 * The gateway sends null both for a member with no listing and for a failed
 * read of it, so null is never reported as "not listed": the part is `hidden`
 * and the surface may offer a link to manage a listing without claiming either.
 *
 * @param array|null $directory The profile's `directory` block.
 * @return array
 */
function agend_apps_member_summary_directory( ?array $directory ): array {
	if ( null === $directory ) {
		return array( 'status' => 'hidden' );
	}

	$name = agend_apps_member_summary_text( $directory['listing_name'] ?? '', 160 );
	$slug = sanitize_title( agend_apps_member_summary_text( $directory['slug'] ?? '', 200 ) );
	$logo = isset( $directory['logo_url'] ) && is_string( $directory['logo_url'] ) ? trim( $directory['logo_url'] ) : '';

	if ( '' === $name && '' === $slug ) {
		return array( 'status' => 'hidden' );
	}

	return array(
		'status'   => 'ready',
		'name'     => '' !== $name ? $name : $slug,
		'slug'     => $slug,
		'verified' => ! empty( $directory['is_verified'] ),
		// Only an https address is ever loaded into a page.
		'logo'     => 1 === preg_match( '#^https://#i', $logo ) ? esc_url_raw( $logo ) : '',
	);
}

/**
 * The courses in progress part, from the member's active enrolments.
 *
 * Only what Agend answered is drawn: a percentage that is missing is not drawn as
 * a bar (and is never turned into 0), a completed enrolment is not "in progress"
 * and is left out, and a read that failed is `unavailable`, never "no courses".
 * The most recently opened come first, because that is what a member resumes.
 *
 * @param mixed $response The enrolments read's result.
 * @param int   $max      Most courses listed.
 * @return array
 */
function agend_apps_member_summary_courses( $response, int $max ): array {
	$rows = agend_apps_member_summary_rows( $response );

	if ( null === $rows ) {
		return array( 'status' => agend_apps_member_summary_failure( $response ) );
	}

	$items = array();

	foreach ( $rows as $row ) {
		$course = is_array( $row['course'] ?? null ) ? $row['course'] : array();
		$title  = agend_apps_member_summary_text( $course['title'] ?? '' );

		if ( '' === $title || ! empty( $row['completed'] ) ) {
			continue;
		}

		$percent = agend_apps_member_summary_number( $row['progress_percentage'] ?? null );
		$done    = agend_apps_member_summary_count( $row['lessons_completed'] ?? null );
		$lessons = agend_apps_member_summary_count( $course['lessons_count'] ?? null );
		$opened  = agend_apps_member_summary_text( $row['last_accessed_at'] ?? '', 40 );

		$items[] = array(
			'title'   => $title,
			'slug'    => sanitize_title( agend_apps_member_summary_text( $course['slug'] ?? '', 200 ) ),
			'percent' => null === $percent ? null : max( 0.0, min( 100.0, $percent ) ),
			'done'    => $done,
			'lessons' => null !== $lessons && $lessons > 0 ? $lessons : null,
			'time'    => '' !== $opened && false !== strtotime( $opened ) ? (int) strtotime( $opened ) : 0,
		);
	}

	usort(
		$items,
		static function ( array $a, array $b ): int {
			return $b['time'] <=> $a['time'];
		}
	);

	$meta  = is_array( $response ) && is_array( $response['meta']['pagination'] ?? null ) ? $response['meta']['pagination'] : array();
	$total = max( count( $items ), (int) ( agend_apps_member_summary_count( $meta['total'] ?? null ) ?? 0 ) );

	$items = array_map(
		static function ( array $item ): array {
			unset( $item['time'] );

			return $item;
		},
		array_slice( $items, 0, max( 1, $max ) )
	);

	return array(
		'status' => array() === $items ? 'empty' : 'ready',
		'items'  => $items,
		'total'  => $total,
	);
}

/**
 * The reading lists part, from the member's own Atlas collections.
 *
 * Each list is its title and how many pages are in it. A list with no title is
 * left out. A read that failed is `unavailable`, never "no lists".
 *
 * @param mixed $response The collections read's result.
 * @param int   $max      Most lists shown.
 * @return array
 */
function agend_apps_member_summary_reading( $response, int $max ): array {
	$rows = agend_apps_member_summary_rows( $response );

	if ( null === $rows ) {
		return array( 'status' => agend_apps_member_summary_failure( $response ) );
	}

	$lists = array();

	foreach ( $rows as $row ) {
		$title = agend_apps_member_summary_text( $row['title'] ?? '' );

		if ( '' === $title ) {
			continue;
		}

		$lists[] = array(
			'title' => $title,
			'pages' => is_array( $row['items'] ?? null ) ? count( array_filter( $row['items'], 'is_array' ) ) : 0,
		);
	}

	$total = count( $lists );

	return array(
		'status' => 0 === $total ? 'empty' : 'ready',
		'items'  => array_slice( $lists, 0, max( 1, $max ) ),
		'total'  => $total,
	);
}

/**
 * Tier id to name, from the tiers read.
 *
 * @param mixed $response The tiers read's result.
 * @return array<string, string>
 */
function agend_apps_member_summary_tier_names( $response ): array {
	$names = array();

	foreach ( (array) agend_apps_member_summary_rows( $response ) as $tier ) {
		$id   = isset( $tier['id'] ) && is_scalar( $tier['id'] ) ? (string) $tier['id'] : '';
		$name = agend_apps_member_summary_text( $tier['name'] ?? '', 120 );

		if ( '' !== $id && '' !== $name ) {
			$names[ $id ] = $name;
		}
	}

	return $names;
}

/**
 * How a membership row ranks as "the one to show": active first, then pending,
 * then whatever ended, and the latest end date within a rank.
 *
 * @param array $row A membership row.
 * @return array{0: int, 1: int}
 */
function agend_apps_member_summary_row_rank( array $row ): array {
	$status = strtolower( agend_apps_member_summary_text( $row['status'] ?? '', 30 ) );
	$rank   = 'active' === $status ? 3 : ( 'pending' === $status ? 2 : 1 );
	$date   = strtotime( agend_apps_member_summary_text( $row['expiry_date'] ?? ( $row['renewal_date'] ?? ( $row['created_at'] ?? '' ) ), 40 ) );

	return array( $rank, false === $date ? 0 : (int) $date );
}

/**
 * The membership part: standing, tier, and renewal.
 *
 * Standing is the entitlements read's `is_member`, which already accounts for
 * corporate seats and grace periods. The rows give the tier, whether it is held
 * through an organisation seat, and the date. Either read may have failed: with
 * only the rows, standing is read from them; with only standing, the card shows
 * that and no tier or date. A member with no membership is `empty` and the
 * surface says how to join; the word "member" is never used as a tier name.
 *
 * @param mixed $entitlements The entitlements read's result.
 * @param mixed $memberships  The memberships read's result.
 * @param mixed $tiers        The tiers read's result.
 * @param int   $now          The time to measure days from (a test seam).
 * @return array
 */
function agend_apps_member_summary_membership( $entitlements, $memberships, $tiers, int $now = 0 ): array {
	$now = $now > 0 ? $now : time();

	$standing = agend_apps_member_summary_payload( $entitlements );
	$rows     = agend_apps_member_summary_rows( $memberships );

	if ( null === $standing && null === $rows ) {
		$failures = array( agend_apps_member_summary_failure( $entitlements ), agend_apps_member_summary_failure( $memberships ) );

		return array( 'status' => array( 'no_access' ) === array_values( array_unique( $failures ) ) ? 'no_access' : 'unavailable' );
	}

	$is_member = null !== $standing && array_key_exists( 'is_member', $standing ) ? (bool) $standing['is_member'] : null;
	$chosen    = null;

	foreach ( (array) $rows as $row ) {
		if ( null === $chosen || agend_apps_member_summary_row_rank( $row ) > agend_apps_member_summary_row_rank( $chosen ) ) {
			$chosen = $row;
		}
	}

	$status = null !== $chosen ? strtolower( agend_apps_member_summary_text( $chosen['status'] ?? '', 30 ) ) : '';

	if ( null === $is_member ) {
		$is_member = 'active' === $status;
	}

	if ( $is_member ) {
		$label = 'active';
	} elseif ( 'pending' === $status ) {
		$label = 'pending';
	} elseif ( null !== $chosen ) {
		$label = 'ended';
	} else {
		return array( 'status' => 'empty' );
	}

	$tier = '';

	if ( null !== $chosen ) {
		$id   = isset( $chosen['tier_id'] ) && is_scalar( $chosen['tier_id'] ) ? (string) $chosen['tier_id'] : '';
		$tier = agend_apps_member_summary_tier_names( $tiers )[ $id ] ?? '';
	}

	// The date to show: when an active membership renews, else when it ends.
	$date = '';
	$kind = '';

	if ( null !== $chosen ) {
		$renewal = agend_apps_member_summary_text( $chosen['renewal_date'] ?? '', 40 );
		$expiry  = agend_apps_member_summary_text( $chosen['expiry_date'] ?? '', 40 );

		if ( 'active' === $label && '' !== $renewal && false !== strtotime( $renewal ) ) {
			$date = $renewal;
			$kind = 'renews';
		} elseif ( '' !== $expiry && false !== strtotime( $expiry ) ) {
			$date = $expiry;
			$kind = 'ended' === $label ? 'ended' : 'expires';
		}
	}

	$days = null;

	if ( '' !== $date ) {
		$gateway = null !== $chosen && isset( $chosen['days_until_renewal'] ) && is_numeric( $chosen['days_until_renewal'] ) ? (int) $chosen['days_until_renewal'] : null;
		$days    = null !== $gateway ? $gateway : (int) floor( ( (int) strtotime( $date ) - $now ) / 86400 );
	}

	return array(
		'status'   => 'ready',
		'label'    => $label,
		'tier'     => $tier,
		'via_seat' => null !== $chosen && ! empty( $chosen['via_seat'] ),
		'date'     => $date,
		'kind'     => $kind,
		'days'     => $days,
	);
}

// ---------------------------------------------------------------------------
// Fetching
// ---------------------------------------------------------------------------

/**
 * The reads, in the form the client sends together.
 *
 * Each takes its arguments through the same filter as the wrapper that already
 * exists for it, so a site's filters apply. The member reads keep nothing but a
 * copy for this request (a lifetime of 0 stores nothing); the tier names are
 * public catalogue data, read as a visitor would and shared by the whole site.
 *
 * @param string[] $names Reads wanted, from the plan.
 * @return array<string, array{path: string, args: array, cache_key: string, ttl: int}>
 */
function agend_apps_member_summary_reads( array $names ): array {
	$reads = array();

	if ( in_array( 'profile', $names, true ) ) {
		$reads['profile'] = array(
			'path'      => '/crm/me/connected-profile',
			'args'      => (array) apply_filters( 'agend_apps_crm_get_my_connected_profile_args', array() ),
			'cache_key' => 'member_summary_profile',
			'ttl'       => 0,
		);
	}

	if ( in_array( 'entitlements', $names, true ) ) {
		$reads['entitlements'] = array(
			'path'      => '/crm/me/entitlements',
			'args'      => (array) apply_filters( 'agend_apps_crm_get_my_entitlements_args', array() ),
			'cache_key' => 'member_summary_entitlements',
			'ttl'       => 0,
		);
	}

	if ( in_array( 'memberships', $names, true ) ) {
		$reads['memberships'] = array(
			'path'      => '/crm/me/memberships',
			'args'      => (array) apply_filters( 'agend_apps_crm_get_my_memberships_args', array( 'query' => array() ), array() ),
			'cache_key' => 'member_summary_memberships',
			'ttl'       => 0,
		);
	}

	if ( in_array( 'tiers', $names, true ) ) {
		$query = array();

		$reads['tiers'] = array(
			'path'      => '/crm/tiers',
			'args'      => (array) apply_filters( 'agend_apps_crm_get_tiers_args', array( 'query' => $query ), $query ) + array( 'anonymous' => true ),
			'cache_key' => Agend_Apps_Cache::build_key( 'crm_tiers', $query ),
			'ttl'       => Agend_Apps_Settings::get_cache_ttl( 'crm_tiers' ),
		);

		// Anonymous whatever a filter said: a member's token would take the
		// read out of the shared copy and make it a live call on every load.
		$reads['tiers']['args']['anonymous'] = true;
	}

	if ( in_array( 'enrollments', $names, true ) ) {
		$query = array(
			'status' => 'active',
			'limit'  => AGEND_APPS_MEMBER_SUMMARY_COURSE_READ_LIMIT,
		);

		$reads['enrollments'] = array(
			'path'      => '/lms/me/enrollments',
			'args'      => (array) apply_filters( 'agend_apps_lms_get_my_enrollments_args', array( 'query' => $query ), $query ),
			'cache_key' => 'member_summary_enrollments',
			'ttl'       => 0,
		);
	}

	if ( in_array( 'collections', $names, true ) ) {
		// Atlas answers a member's own view; the wrapper keeps an Atlas outage
		// from counting against the rest of the site's calls, and so do we.
		$reads['collections'] = array(
			'path'      => '/atlas/me/collections',
			'args'      => (array) apply_filters( 'agend_apps_atlas_get_my_collections_args', array( 'isolated_rate_limit' => true ) ),
			'cache_key' => 'member_summary_collections',
			'ttl'       => 0,
		);
	}

	return $reads;
}

/**
 * Fetches and reduces the member's summary.
 *
 * Call it for a visitor whose access state is `ready`. The reads go out
 * together; each part is then reduced on its own, so one that fails does not
 * take the others down. The access state comes back too, because the reads
 * themselves can show it was not what it seemed: a 404 means Agend has no
 * member for this user, and the classification is
 * {@see agend_apps_member_access_from_error()}'s.
 *
 * Returned `state` follows the access vocabulary: `ready` when at least one
 * part could be read; otherwise the state the failures mean. A 404 anywhere is
 * `no_member_record` for the whole summary.
 *
 * @param array $want   {
 *     @type string[] $sections Parts to show.
 *     @type bool     $greeting Whether the first name is wanted.
 * }
 * @param array $access The access state, from {@see agend_apps_member_access_state()}.
 * @param array $limits {
 *     @type int $events       Most events listed.
 *     @type int $certificates Most certificates listed.
 *     @type int $courses      Most courses listed.
 *     @type int $reading      Most reading lists listed.
 * }
 * @return array{access: array, contact: array{first_name: string, initials: string, title: string, company: string}, sections: array<string, array>, reads: string[]}
 */
function agend_apps_member_summary_fetch( array $want, array $access, array $limits = array() ): array {
	$plan    = agend_apps_member_summary_plan( $want );
	$reads   = agend_apps_member_summary_reads( $plan['reads'] );
	$results = array() === $reads ? array() : agend_apps_api()->get_cached_many( $reads );

	$profile = agend_apps_member_summary_payload( $results['profile'] ?? null );
	$lms     = null !== $profile && is_array( $profile['lms'] ?? null ) ? $profile['lms'] : null;
	$events  = null !== $profile && is_array( $profile['events'] ?? null ) ? $profile['events'] : null;
	$dir     = null !== $profile && is_array( $profile['directory'] ?? null ) ? $profile['directory'] : null;

	// A profile that failed outright is a failure for every part drawn from it;
	// a profile that answered with a null block is that block's own failure.
	$from_profile = static function ( callable $reduce ) use ( $results ): array {
		if ( array_key_exists( 'profile', $results ) && is_wp_error( $results['profile'] ) ) {
			return array( 'status' => agend_apps_member_summary_failure( $results['profile'] ) );
		}

		return $reduce();
	};

	$sections = array();

	foreach ( $plan['sections'] as $section ) {
		if ( in_array( $section, $plan['no_access'], true ) ) {
			$sections[ $section ] = array( 'status' => 'no_access' );
			continue;
		}

		switch ( $section ) {
			case 'membership':
				$sections[ $section ] = agend_apps_member_summary_membership( $results['entitlements'] ?? null, $results['memberships'] ?? null, $results['tiers'] ?? null );
				break;
			case 'cpd':
				$sections[ $section ] = $from_profile( static fn(): array => agend_apps_member_summary_cpd( $lms ) );
				break;
			case 'events':
				$sections[ $section ] = $from_profile( static fn(): array => agend_apps_member_summary_events( $events, (int) ( $limits['events'] ?? 3 ) ) );
				break;
			case 'credentials':
				$sections[ $section ] = $from_profile( static fn(): array => agend_apps_member_summary_credentials( $lms, (int) ( $limits['certificates'] ?? 3 ) ) );
				break;
			case 'directory':
				$sections[ $section ] = $from_profile( static fn(): array => agend_apps_member_summary_directory( $dir ) );
				break;
			case 'courses':
				$sections[ $section ] = agend_apps_member_summary_courses( $results['enrollments'] ?? null, (int) ( $limits['courses'] ?? 3 ) );
				break;
			case 'reading':
				$sections[ $section ] = agend_apps_member_summary_reading( $results['collections'] ?? null, (int) ( $limits['reading'] ?? 4 ) );
				break;
		}
	}

	$out = array(
		'access'   => $access,
		'contact'  => $plan['greeting'] ? agend_apps_member_summary_contact( $profile ) : agend_apps_member_summary_contact( null ),
		'sections' => $sections,
		'reads'    => array_keys( $reads ),
	);

	// Failures of the member's own reads (the tiers are public and say nothing
	// about the member; the reading lists are Atlas's and a refusal there says
	// nothing about whether Agend knows the member).
	$failed = array();

	foreach ( array( 'profile', 'entitlements', 'memberships', 'enrollments' ) as $name ) {
		if ( array_key_exists( $name, $results ) && is_wp_error( $results[ $name ] ) ) {
			$failed[ $name ] = agend_apps_member_access_from_error( $results[ $name ], $access );
		}
	}

	$attempted = count( array_intersect( array( 'profile', 'entitlements', 'memberships', 'enrollments' ), array_keys( $reads ) ) );

	// Nothing was read at all: every part wanted something the key lacks.
	if ( 0 === $attempted && array() !== $plan['no_access'] ) {
		$missing = array();

		foreach ( $plan['no_access'] as $section ) {
			$missing = array_merge( $missing, agend_apps_member_summary_scopes()[ $section ] );
		}

		$out['access'] = agend_apps_member_access_from_error(
			new WP_Error( 'agend_apps_scope_missing', 'Not sent.', array( 'missing' => array_values( array_unique( $missing ) ) ) ),
			$access
		);

		return $out;
	}

	foreach ( $failed as $classified ) {
		if ( 'no_member_record' === $classified['state'] ) {
			$out['access'] = $classified;

			return $out;
		}
	}

	if ( array() !== $failed && count( $failed ) === $attempted ) {
		foreach ( array( 'not_linked', 'unavailable', 'not_configured' ) as $state ) {
			foreach ( $failed as $classified ) {
				if ( $state === $classified['state'] ) {
					$out['access'] = $classified;

					return $out;
				}
			}
		}
	}

	return $out;
}

// ---------------------------------------------------------------------------
// Protecting the site key
// ---------------------------------------------------------------------------

/**
 * Whether a fetch may go ahead now, and counts it if so.
 *
 * Two checks stand in front of the gateway. A member may start only a few
 * fetches a minute, so a page left refreshing, or a script, cannot spend the
 * site key's allowance on one account. And before fetching, what is left of the
 * key's minute (the client records the gateway's `X-RateLimit-Remaining`) is
 * compared with a floor: below it, the member is asked to wait rather than the
 * rest of the site being starved. A request that finds no bearer never gets
 * here, so connecting polls cost nothing and are not counted.
 *
 * The counters hold no member data, only a number and a time.
 *
 * @param int $user_id The WordPress user id.
 * @param int $now     The time (a test seam).
 * @return array|null Null to go ahead; otherwise `array( 'retry_after' => int, 'why' => 'member'|'site' )`.
 */
function agend_apps_member_summary_guard( int $user_id, int $now = 0 ): ?array {
	$now = $now > 0 ? $now : time();

	/**
	 * Filters the most fetches a member may start in one minute.
	 *
	 * @param int $max Default 6; a full view is at most six reads, so about 36.
	 */
	$max = max( 1, (int) apply_filters( 'agend_apps_member_summary_fetches_per_minute', 6 ) );

	/**
	 * Filters the fewest calls left in the site key's minute that a fetch needs.
	 *
	 * @param int $floor Default 15.
	 */
	$floor = max( 0, (int) apply_filters( 'agend_apps_member_summary_min_headroom', 15 ) );

	$key    = 'agend_apps_ms_fetches_' . $user_id;
	$window = get_transient( $key );
	$window = is_array( $window ) && isset( $window['count'], $window['started'] ) ? $window : array(
		'count'   => 0,
		'started' => $now,
	);

	if ( $now - (int) $window['started'] >= 60 ) {
		$window = array(
			'count'   => 0,
			'started' => $now,
		);
	}

	if ( (int) $window['count'] >= $max ) {
		return array(
			'retry_after' => max( 1, 60 - ( $now - (int) $window['started'] ) ),
			'why'         => 'member',
		);
	}

	$remaining = get_transient( 'agend_apps_rate_limit_remaining' );

	if ( false !== $remaining && is_numeric( $remaining ) && (int) $remaining < $floor ) {
		$reset = get_transient( 'agend_apps_rate_limit_reset' );
		$wait  = false !== $reset && is_numeric( $reset ) ? (int) $reset - $now : 0;

		return array(
			'retry_after' => $wait > 0 ? min( $wait, 300 ) : 30,
			'why'         => 'site',
		);
	}

	++$window['count'];
	set_transient( $key, $window, 60 );

	return null;
}
