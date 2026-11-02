<?php
/**
 * Test fixture: the few WordPress functions the listing enquiry route, captcha
 * and form need that the shared stubs do not declare.
 *
 * `wp_mail()` is declared as a recorder, so a test can prove WordPress sent no
 * email: nothing in the enquiry path may call it.
 *
 * @package Agend\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ): string {
		$email = trim( (string) $email );

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ): bool {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( $value ), array( 'false', '0', '' ), true );
		}

		return (bool) $value;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, bool $display = true ): string {
		$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';

		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $result;
	}
}

if ( ! class_exists( 'Agend_Test_Mail' ) ) {
	final class Agend_Test_Mail {
		/** @var array<int, array<string, mixed>> */
		public static array $sent = array();
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ): bool {
		Agend_Test_Mail::$sent[] = array( 'to' => $to, 'subject' => $subject );

		return true;
	}
}
