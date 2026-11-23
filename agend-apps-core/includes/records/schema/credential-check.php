<?php
/**
 * Content-settings schema for the credential check surface.
 *
 * One declaration for both editors: the Elementor widget and the block render
 * their controls from it (see schema.php for the vocabulary).
 *
 * Setting names are persisted in every saved page that uses the surface: never
 * rename one once shipped.
 *
 * Editor wording: association editors read these labels, so they say "code"
 * and "security check", never the technical names for either. Technical
 * reasons live in comments.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The credential check surface's content-settings schema.
 *
 * What a result can honestly show is fixed by what the Agend learning record
 * holds (a certificate has no holder name, nothing carries an expiry), so the
 * result rows here are only the ones that exist. A row is a toggle, on unless
 * said otherwise; the one that is off by default is the badge earner's name.
 *
 * @return array
 */
function agend_apps_records_schema_credential_check(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_heading',
				'label'  => __( 'Heading', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_heading',
						'label'   => __( 'Show heading', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'heading_text',
						'label'     => __( 'Heading', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Check a credential', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'intro_text',
						'label'     => __( 'Introduction', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'Enter the code printed on a certificate, or paste the link from a digital badge, to check it against the Agend learning record.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_form',
				'label'  => __( 'Check Form', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'input_label',
						'label'       => __( 'Label above the code box', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Certificate or badge code', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'input_placeholder',
						'label'       => __( 'Example shown in the code box', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'For example VER-3fa91c0b7d2e48a1b6c5', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'help_text',
						'label'       => __( 'Hint under the code box', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Copy and paste the code rather than retyping it. Codes must match exactly.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'    => 'button_label',
						'label'   => __( 'Button text', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Check', 'agend-apps-core' ),
					),
					array(
						'name'        => 'prefill_param',
						'label'       => __( 'Link code name', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => 'code',
						'description' => __( 'The part of the page address that carries a code, so a printed link or QR code can open this page with the code filled in, for example yoursite.com.au/check/?code=VER-... Links that use agend_code also work.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'auto_check',
						'label'       => __( 'Check straight away when the link has a code', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'The result is ready when the page opens. If the visitor has to tick a box in the security check first, the code is filled in and they press the button.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'security_note',
						'label'   => __( 'Security check', 'agend-apps-core' ),
						'type'    => 'note',
						'content' => __( 'Every check passes a security check first, and each visitor can only make a limited number of checks. The security check is set up under Settings > Agend Apps > Form Captcha. Until it is set up, visitors do not see this widget.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_results',
				'label'  => __( 'Result Details', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'results_note',
						'label'   => __( 'What a result can show', 'agend-apps-core' ),
						'type'    => 'note',
						'content' => __( 'A result always shows the course or badge name. A certificate does not record who holds it, and no credential records an expiry date, so those are never shown.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_number',
						'label'       => __( 'Show the certificate number', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'So the visitor can match it to the number printed on their copy.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_issued',
						'label'   => __( 'Show the date it was issued', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'show_course',
						'label'       => __( 'Show the course a badge was earned in', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'A certificate is named after its course, so this only applies to badges.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_cpd',
						'label'       => __( 'Show CPD points', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Only shown when the credential carries points.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_instructor',
						'label'       => __( 'Show the instructor', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Certificates only, and only when one is recorded.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_topics',
						'label'       => __( 'Show the topics covered', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Certificates only. Up to five are listed.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_image',
						'label'       => __( 'Show the badge picture', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Badges only.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_checked_at',
						'label'       => __( 'Show when it was checked', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
					),
					// Off by default: it is the association that makes a badge public,
					// not the learner, so the learner has not agreed to a name on a
					// public page. The help text says so, in plain words.
					array(
						'name'        => 'show_holder',
						'label'       => __( 'Show the name of the person who earned a badge', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Off by default. Your association decides which badges are public, not the learner, so only turn this on if learners have agreed to their name appearing on a public page. Certificates never show a name.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_wording',
				'label'  => __( 'Wording', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'valid_title',
						'label'       => __( 'Title when a credential is found', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Credential verified', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'checked_text',
						'label'       => __( 'Where the check was made', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Checked against the Agend learning record', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'valid_note',
						'label'       => __( 'What a found credential means', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'This credential is recorded in the Agend learning record. That confirms it exists. It does not say whether the holder is currently accredited.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'holder_note',
						'label'       => __( 'Reminder about the holder\'s name', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'The holder\'s name is not shown. Check that the number and course match the document you were given.', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'Shown under a certificate result. Leave it empty to hide it.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'not_found_text',
						'label'       => __( 'Message when no credential matches', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'No credential found with that code', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'not_found_help',
						'label'       => __( 'Tips when no credential matches', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'Check the code against the document you were given. Copy and paste it rather than retyping it, because codes must match exactly.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'invalid_text',
						'label'       => __( 'Message when the code looks wrong', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'That does not look like a verification code. Use the code printed on the certificate (it starts with VER-) or the badge link.', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'Shown without checking anything, so a mistyped code is never counted.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'busy_text',
						'label'       => __( 'Message when there have been too many checks', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'Too many checks. Please try again in {seconds} seconds.', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( '{seconds} becomes the time to wait.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'captcha_text',
						'label'       => __( 'Message when the security check fails', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'We could not confirm you are not a robot. Please try again.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'        => 'unavailable_text',
						'label'       => __( 'Message when the check cannot be made', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => __( 'We cannot check credentials right now. Please try again later.', 'agend-apps-core' ),
						'label_block' => true,
						'description' => __( 'This is not the same as "no credential found": the credential has not been checked at all.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'help_url',
						'label'       => __( 'Help link', 'agend-apps-core' ),
						'type'        => 'url',
						'description' => __( 'Shown under a "no credential found" result, for example your contact page. Leave empty for no link.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'help_label',
						'label'   => __( 'Help link text', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Contact us', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_style_colours',
				'label'  => __( 'Colours', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'        => 'inherit_colours',
						'label'       => __( 'Inherit theme colours', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Use the site\'s theme colours when the theme sets them, otherwise the connected Agend account\'s colours. Turn off to set them manually below.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'heading_colour',
						'label'     => __( 'Heading colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#1E2A4A',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'body_colour',
						'label'     => __( 'Body text colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#26304D',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'        => 'accent_colour',
						'label'       => __( 'Highlight / accent colour', 'agend-apps-core' ),
						'type'        => 'colour',
						'default'     => '#FF6B55',
						'description' => __( 'Used for the tick on a found credential and the small marks beside messages.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}
