<?php
/**
 * Admin-ajax endpoints backing the Dataverse guided secondary filter builder.
 *
 * Separate from Agend_Directory_Sync_Job_Controller because these talk to
 * Dataverse field metadata, not to a sync job's progress state, and one of
 * the two endpoints here makes no network request at all: it only re-runs the
 * fragment builder the run path itself uses, so the admin page can show the
 * exact FetchXML that will be sent without a second copy of that builder in
 * JavaScript.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Dataverse_Metadata_Controller' ) ) :
	final class Agend_Directory_Sync_Dataverse_Metadata_Controller {

		public const NONCE_ACTION = 'agend_directory_sync_dataverse_metadata';

		public static function setup_hooks(): void {
			add_action( 'wp_ajax_agend_directory_sync_dataverse_field_values', array( __CLASS__, 'handle_field_values' ) );
			add_action( 'wp_ajax_agend_directory_sync_dataverse_filter_preview', array( __CLASS__, 'handle_filter_preview' ) );
		}

		/**
		 * Fetch the possible values for a Dataverse field (values in use on
		 * records and/or the field's metadata options), for the admin to pick
		 * among by label.
		 */
		public static function handle_field_values(): void {
			self::assert_request();

			$field   = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['field'] ) ) : '';
			$search  = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['search'] ) ) : '';
			$refresh = ! empty( $_POST['refresh'] );

			$sources = Agend_Directory_Sync_Source_Registry::all();
			$source  = $sources[ Agend_Directory_Sync_Dataverse_Source::SOURCE_KEY ] ?? null;

			if ( ! ( $source instanceof Agend_Directory_Sync_Dataverse_Source ) ) {
				wp_send_json_error( array( 'message' => __( 'The Microsoft Dataverse source is not registered.', 'agend-directory-sync' ) ), 400 );
			}

			if ( ! $source->is_available() ) {
				wp_send_json_error( array( 'message' => $source->get_unavailable_reason() ), 400 );
			}

			try {
				wp_send_json_success( $source->fetch_field_values( $field, $search, $refresh ) );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
			}
		}

		/**
		 * Build the FetchXML fragment and the plain-language description the
		 * guided builder would actually send, without a request to
		 * Dataverse. Reuses `build_guided_filter_fragment()` and
		 * `describe_secondary_filter()`, the same functions the run path
		 * calls, so this preview and the run can never disagree about what
		 * gets sent.
		 */
		public static function handle_filter_preview(): void {
			self::assert_request();

			$field      = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['field'] ) ) : '';
			$field_type = isset( $_POST['field_type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['field_type'] ) ) : '';
			$raw_values = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : array();
			$raw_labels = isset( $_POST['value_labels'] ) ? wp_unslash( (string) $_POST['value_labels'] ) : '';

			$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( $raw_values, $raw_labels );

			$settings = array(
				'secondary_filter_mode'       => Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_GUIDED,
				'secondary_filter_field'      => $field,
				'secondary_filter_field_type' => $field_type,
				'secondary_filter_values'     => $values,
			);

			wp_send_json_success(
				array(
					'fragment'    => Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( $field, $values, $field_type ),
					'description' => Agend_Directory_Sync_Dataverse_Source::describe_secondary_filter( $settings ),
				)
			);
		}

		/**
		 * Same gate as the job controller and the settings page: the
		 * capability first, then the nonce.
		 */
		private static function assert_request(): void {
			if ( ! current_user_can( Agend_Directory_Sync_Admin_Page::CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'agend-directory-sync' ) ), 403 );
			}

			if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'agend-directory-sync' ) ), 403 );
			}
		}
	}
endif;
