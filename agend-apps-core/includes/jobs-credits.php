<?php
/**
 * Member job credits, as Agend counts them.
 *
 * A member pays for each job they post from the website with a job credit
 * held in the association's CRM. Agend is the credit authority: it checks for
 * an unused credit when a job is posted, records the spend, and frees the
 * credit when the job is rejected or withdrawn. The website never reads or
 * writes credits itself. It only asks Agend how many unused credits a member
 * has, to decide whether to offer the posting form, and offers the "Has an
 * unused job credit" condition to Agend Content Personalisation (ESAC) rules.
 *
 * A member is named to Agend by their contact pair: the contact source this
 * site's members come from (`upbeat-contacts` by default) and their external
 * id there ({@see agend_apps_user_external_id()}, the membership number on an
 * Upbeat site).
 *
 * The count is cached per member for a few minutes, because an ESAC rule can
 * be checked on every page a member views and the site's API key is shared by
 * every member. It is forgotten when the member posts a job and when a
 * `jobs.submission.rejected` or `jobs.submission.withdrawn` webhook frees a
 * credit.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Apps_Jobs_Credits' ) ) :

	/**
	 * Counts members' unused job credits through Agend.
	 */
	final class Agend_Apps_Jobs_Credits {

		/**
		 * Option holding the contact source this site's members come from.
		 */
		const SOURCE_OPTION = 'agend_apps_jobs_contact_source';

		/**
		 * The contact source when none is set: members synced from Upbeat.
		 */
		const DEFAULT_SOURCE = 'upbeat-contacts';

		/**
		 * The scope the count needs.
		 */
		const SCOPE = 'jobs.submissions.browse';

		/**
		 * Transient prefix for cached counts, followed by a hash of the pair.
		 */
		const CACHE_PREFIX = 'agend_apps_jobs_credits_';

		/**
		 * How long a count is kept.
		 */
		const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * How long a count that could not be read is remembered as unreadable,
		 * so a slow or failing count does not hold up every page a member views.
		 */
		const FAILURE_TTL = 30;

		/**
		 * How long an answer that the site cannot count credits at all is
		 * remembered: the key lacks a scope, or Agend has no credit setting
		 * or has member job submissions switched off.
		 */
		const REFUSED_TTL = 5 * MINUTE_IN_SECONDS;

		/**
		 * Webhook events that free a credit.
		 */
		const FREEING_EVENTS = array( 'jobs.submission.rejected', 'jobs.submission.withdrawn' );

		/**
		 * Gateway codes meaning the site cannot count credits at all.
		 */
		const REFUSED_CODES = array( 'SOURCE_NOT_ALLOWED', 'JOB_CREDITS_NOT_CONFIGURED', 'JOB_SUBMISSIONS_DISABLED', 'INSUFFICIENT_SCOPES' );

		/**
		 * Registers the webhook listener and the ESAC condition.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( 'agend_apps_webhook_received', array( __CLASS__, 'handle_webhook' ), 10, 2 );
			add_filter( 'iugo_esac_filter_condition_sets', array( __CLASS__, 'esac_condition_sets' ) );
			add_filter( 'iugo_esac_filter_check_condition_agend_jobs', array( __CLASS__, 'esac_check' ), 10, 2 );
		}

		/**
		 * The contact source this site's members come from.
		 *
		 * @return string
		 */
		public static function source(): string {
			$source = trim( (string) get_option( self::SOURCE_OPTION, '' ) );

			if ( '' === $source ) {
				$source = self::DEFAULT_SOURCE;
			}

			/**
			 * Filters the contact source a site's members are named by.
			 *
			 * @param string $source Contact source, such as `upbeat-contacts`.
			 */
			return trim( (string) apply_filters( 'agend_apps_jobs_contact_source', $source ) );
		}

		/**
		 * A member's contact pair, or null when they have no external id.
		 *
		 * @param int $user_id WordPress user id.
		 * @return array{external_source: string, external_id: string}|null
		 */
		public static function contact( int $user_id ): ?array {
			if ( $user_id <= 0 || ! function_exists( 'agend_apps_user_external_id' ) ) {
				return null;
			}

			$source = self::source();
			$id     = trim( agend_apps_user_external_id( $user_id ) );

			// The gateway refuses longer values.
			if ( '' === $source || '' === $id || strlen( $source ) > 100 || strlen( $id ) > 200 ) {
				return null;
			}

			return array(
				'external_source' => $source,
				'external_id'     => $id,
			);
		}

		/**
		 * Whether the site's key is known to lack the scope the count needs.
		 * Only a scope list the site has actually read can rule it out.
		 *
		 * @return bool
		 */
		public static function lacks_scope(): bool {
			return class_exists( 'Agend_Apps_Key_Scopes' ) && Agend_Apps_Key_Scopes::known() && ! Agend_Apps_Key_Scopes::has( self::SCOPE );
		}

		/**
		 * How many unused job credits a member has.
		 *
		 * Errors:
		 * - `agend_jobs_credits_no_member`: signed out, or no external id.
		 * - `agend_jobs_credits_not_available`: the site cannot count credits
		 *   (a missing scope, an unlisted source, no credit setting, or
		 *   member job submissions switched off).
		 * - `agend_jobs_credits_unavailable`: the count could not be read just
		 *   now. Try again shortly.
		 *
		 * @param int $user_id WordPress user id.
		 * @return int|WP_Error
		 */
		public static function count( int $user_id ) {
			$contact = self::contact( $user_id );

			if ( null === $contact ) {
				return new WP_Error( 'agend_jobs_credits_no_member', __( 'Please sign in with your member account to post a job.', 'agend-apps-core' ) );
			}

			if ( self::lacks_scope() ) {
				return self::not_available();
			}

			$key    = self::cache_key( $contact );
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return self::from_cache( $cached );
			}

			$response = agend_apps_jobs_get_credits( $contact );

			if ( is_wp_error( $response ) ) {
				$refused = in_array( self::gateway_code( $response ), self::REFUSED_CODES, true );
				$entry   = array( 'error' => $refused ? 'not_available' : 'unavailable' );

				set_transient( $key, $entry, $refused ? self::REFUSED_TTL : self::FAILURE_TTL );

				return self::from_cache( $entry );
			}

			$available = $response['data']['available'] ?? null;

			if ( ! is_int( $available ) || $available < 0 ) {
				set_transient( $key, array( 'error' => 'unavailable' ), self::FAILURE_TTL );

				return self::unavailable();
			}

			set_transient( $key, array( 'available' => $available ), self::CACHE_TTL );

			return $available;
		}

		/**
		 * Forgets a member's cached count, so the next read asks Agend.
		 *
		 * @param array $contact The member's contact pair.
		 * @return void
		 */
		public static function forget( array $contact ): void {
			delete_transient( self::cache_key( $contact ) );
		}

		/**
		 * Forgets the cached count of the member a rejected or withdrawn job
		 * belonged to, since Agend has freed its credit. The delivery has
		 * already been signature checked by the receiver.
		 *
		 * @param string $type     Event type.
		 * @param array  $envelope Decoded delivery envelope.
		 * @return void
		 */
		public static function handle_webhook( $type, $envelope ): void {
			if ( ! in_array( (string) $type, self::FREEING_EVENTS, true ) || ! is_array( $envelope ) ) {
				return;
			}

			$data   = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
			$source = isset( $data['contact_external_source'] ) && is_scalar( $data['contact_external_source'] ) ? trim( (string) $data['contact_external_source'] ) : '';
			$id     = isset( $data['contact_external_id'] ) && is_scalar( $data['contact_external_id'] ) ? trim( (string) $data['contact_external_id'] ) : '';

			if ( '' === $source || '' === $id ) {
				return;
			}

			self::forget(
				array(
					'external_source' => $source,
					'external_id'     => $id,
				)
			);
		}

		/**
		 * Adds the Agend Jobs condition set to ESAC, unless the site's key is
		 * known to lack the scope, so an editor never picks a rule that can
		 * never pass.
		 *
		 * @param mixed $sets Condition sets.
		 * @return array
		 */
		public static function esac_condition_sets( $sets ): array {
			$sets = is_array( $sets ) ? $sets : array();

			if ( self::lacks_scope() ) {
				return $sets;
			}

			$sets['agend_jobs'] = array(
				'label'      => __( 'Agend Jobs', 'agend-apps-core' ),
				'conditions' => array(
					'has_job_credit' => __( 'Has an unused job credit', 'agend-apps-core' ),
				),
			);

			return $sets;
		}

		/**
		 * Checks an Agend Jobs ESAC condition for the current visitor.
		 *
		 * A visitor who is signed out, or whose credits cannot be counted just
		 * now, does not pass: the section offering "Post a job" stays hidden
		 * and the inverse section (buy credits) shows. This is only what the
		 * site offers: Agend checks for a credit again when a job is posted.
		 *
		 * @param mixed  $check     The result so far.
		 * @param string $condition Condition id within the set.
		 * @return bool
		 */
		public static function esac_check( $check, $condition ): bool {
			if ( 'has_job_credit' !== $condition ) {
				return (bool) $check;
			}

			if ( ! $check ) {
				return false;
			}

			$count = self::count( get_current_user_id() );

			return ! is_wp_error( $count ) && $count > 0;
		}

		/**
		 * The transient a member's count is cached under.
		 *
		 * @param array $contact The member's contact pair.
		 * @return string
		 */
		private static function cache_key( array $contact ): string {
			return self::CACHE_PREFIX . md5( (string) ( $contact['external_source'] ?? '' ) . '|' . (string) ( $contact['external_id'] ?? '' ) );
		}

		/**
		 * A cached entry as the count or its error.
		 *
		 * @param array $entry Cached entry.
		 * @return int|WP_Error
		 */
		private static function from_cache( array $entry ) {
			if ( isset( $entry['available'] ) && is_int( $entry['available'] ) ) {
				return $entry['available'];
			}

			return 'not_available' === ( $entry['error'] ?? '' ) ? self::not_available() : self::unavailable();
		}

		/**
		 * The gateway's own error code, upper-cased, or ''.
		 *
		 * @param WP_Error $error Gateway error.
		 * @return string
		 */
		private static function gateway_code( WP_Error $error ): string {
			if ( 'agend_apps_scope_missing' === $error->get_error_code() ) {
				return 'INSUFFICIENT_SCOPES';
			}

			$data = $error->get_error_data();

			return is_array( $data ) && isset( $data['body']['error']['code'] ) && is_scalar( $data['body']['error']['code'] )
				? strtoupper( (string) $data['body']['error']['code'] )
				: '';
		}

		/**
		 * The error when the site cannot count credits at all.
		 *
		 * @return WP_Error
		 */
		private static function not_available(): WP_Error {
			return new WP_Error( 'agend_jobs_credits_not_available', __( 'Job posting is not available on this site.', 'agend-apps-core' ) );
		}

		/**
		 * The error when the count could not be read just now.
		 *
		 * @return WP_Error
		 */
		private static function unavailable(): WP_Error {
			return new WP_Error( 'agend_jobs_credits_unavailable', __( 'We could not check your job credits just now. Please try again in a minute.', 'agend-apps-core' ) );
		}
	}

endif;
