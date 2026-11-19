<?php
/**
 * Settings the three Community surfaces share: channel choice, authors, links,
 * the empty state and colours.
 *
 * The surfaces are announcements, channels and popular posts. Each declares its
 * own schema in its own file, built from these sections so the wording, the
 * defaults and the setting names cannot drift apart. Setting names are
 * persisted in every saved page: never rename one once shipped.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The option list below makes no gateway call for a key the surface gate has
// already ruled out.
require_once __DIR__ . '/../surface-gate.php';

/**
 * The heading section.
 *
 * @param string $title    Default heading.
 * @param string $subtitle Default subheading.
 * @return array
 */
function agend_apps_records_community_section_heading( string $title, string $subtitle ): array {
	return array(
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
				'default'   => $title,
				'condition' => array( 'show_heading' => 'yes' ),
			),
			array(
				'name'      => 'subheading_text',
				'label'     => __( 'Subheading', 'agend-apps-core' ),
				'type'      => 'textarea',
				'default'   => $subtitle,
				'condition' => array( 'show_heading' => 'yes' ),
			),
		),
	);
}

/**
 * The control that narrows a surface to some of the channels the site allows.
 *
 * @param string $extra Sentence added to the help text, or ''.
 * @return array
 */
function agend_apps_records_community_field_channels( string $extra = '' ): array {
	return array(
		'name'        => 'channels',
		'label'       => __( 'Channels to show', 'agend-apps-core' ),
		'type'        => 'multiselect',
		'label_block' => true,
		'options'     => 'agend_apps_records_community_channel_options',
		'description' => trim(
			__( 'Leave empty to use every channel allowed on the website. An administrator allows channels under Settings > Agend Apps > Community.', 'agend-apps-core' ) . ' ' . $extra
		),
	);
}

/**
 * The switch that lets posts written by the workflow bot or the shared system
 * user through.
 *
 * Off by default: a site publishes only what members wrote unless the editor
 * says otherwise. System messages (who joined or left, a pinned notice) are
 * never shown whatever this says, so the help text does not promise them.
 *
 * @return array
 */
function agend_apps_records_community_field_automated(): array {
	return array(
		'name'        => 'include_automated',
		'label'       => __( 'Include automated posts', 'agend-apps-core' ),
		'type'        => 'toggle',
		'default'     => false,
		'description' => __( 'Posts created automatically, such as a notice when an event is published. Messages about who joined or left a channel are never shown.', 'agend-apps-core' ),
	);
}

/**
 * The author section: how much of a member's name to publish.
 *
 * @return array
 */
function agend_apps_records_community_section_author(): array {
	return array(
		'id'     => 'section_author',
		'label'  => __( 'Author', 'agend-apps-core' ),
		'fields' => array(
			array(
				'name'        => 'author_display',
				'label'       => __( 'Show the author', 'agend-apps-core' ),
				'type'        => 'select',
				'options'     => agend_apps_community_author_modes(),
				'default'     => 'none',
				'description' => __( 'Names come from members\' community profiles and are shown to anyone who visits your website. Show as little as you need.', 'agend-apps-core' ),
			),
			array(
				'name'        => 'show_avatar',
				'label'       => __( 'Show profile photos', 'agend-apps-core' ),
				'type'        => 'toggle',
				'default'     => false,
				'description' => __( 'Shows each author\'s profile photo instead of their initials. Photos are personal, so leave this off unless your members expect it.', 'agend-apps-core' ),
				'condition'   => array( 'author_display!' => 'none' ),
			),
		),
	);
}

/**
 * The links section.
 *
 * @param string $item_noun  What an item is called in the help text: "post" or "channel".
 * @param bool   $item_label Whether each item has its own link label (channel cards).
 * @return array
 */
function agend_apps_records_community_section_links( string $item_noun, bool $item_label ): array {
	$fields = array(
		array(
			'name'        => 'link_mode',
			'label'       => 'channel' === $item_noun ? __( 'Where each channel opens', 'agend-apps-core' ) : __( 'Where each post opens', 'agend-apps-core' ),
			'type'        => 'select',
			'options'     => array(
				'auto'   => __( 'The channel in your community', 'agend-apps-core' ),
				'custom' => __( 'One page I choose', 'agend-apps-core' ),
				'none'   => __( 'Nowhere, no link', 'agend-apps-core' ),
			),
			'default'     => 'auto',
			'description' => 'channel' === $item_noun
				? __( 'Visitors who are not signed in are asked to sign in first.', 'agend-apps-core' )
				: __( 'A post opens the channel it was written in, not the post itself. Visitors who are not signed in are asked to sign in first.', 'agend-apps-core' ),
		),
		array(
			'name'        => 'link_url',
			'label'       => __( 'Page to open', 'agend-apps-core' ),
			'type'        => 'url',
			'placeholder' => 'https://',
			'condition'   => array( 'link_mode' => 'custom' ),
		),
	);

	if ( $item_label ) {
		$fields[] = array(
			'name'      => 'card_link_label',
			'label'     => __( 'Link text', 'agend-apps-core' ),
			'type'      => 'text',
			'default'   => __( 'Open channel', 'agend-apps-core' ),
			'condition' => array( 'link_mode!' => 'none' ),
		);
	}

	$fields[] = array(
		'name'      => 'links_new_tab',
		'label'     => __( 'Open links in a new tab', 'agend-apps-core' ),
		'type'      => 'toggle',
		'default'   => false,
		'condition' => array( 'link_mode!' => 'none' ),
	);
	$fields[] = array(
		'name'    => 'show_cta',
		'label'   => __( 'Show a link to the community', 'agend-apps-core' ),
		'type'    => 'toggle',
		'default' => true,
	);
	$fields[] = array(
		'name'      => 'cta_label',
		'label'     => __( 'Community link text', 'agend-apps-core' ),
		'type'      => 'text',
		'default'   => __( 'Open the community', 'agend-apps-core' ),
		'condition' => array( 'show_cta' => 'yes' ),
	);
	$fields[] = array(
		'name'        => 'cta_url',
		'label'       => __( 'Community link address', 'agend-apps-core' ),
		'type'        => 'url',
		'placeholder' => 'https://',
		'description' => __( 'Leave empty to open the community itself. Use this to send visitors to a page that explains how to join.', 'agend-apps-core' ),
		'condition'   => array( 'show_cta' => 'yes' ),
	);

	return array(
		'id'     => 'section_links',
		'label'  => __( 'Links', 'agend-apps-core' ),
		'fields' => $fields,
	);
}

/**
 * The empty state section.
 *
 * @param string $message Default message.
 * @param string $what    What is missing, for the help text: "announcements".
 * @return array
 */
function agend_apps_records_community_section_empty( string $message, string $what ): array {
	return array(
		'id'     => 'section_empty',
		'label'  => __( 'Empty State', 'agend-apps-core' ),
		'fields' => array(
			array(
				'name'        => 'empty_message',
				/* translators: %s: what is missing, such as "announcements". */
				'label'       => sprintf( __( 'Message when there are no %s', 'agend-apps-core' ), $what ),
				'type'        => 'text',
				'default'     => $message,
				'label_block' => true,
			),
			array(
				'name'        => 'hide_when_empty',
				/* translators: %s: what is missing, such as "announcements". */
				'label'       => sprintf( __( 'Hide the widget when there are no %s', 'agend-apps-core' ), $what ),
				'type'        => 'toggle',
				'default'     => false,
				'description' => __( 'Visitors then see nothing at all, not even the heading. If the community cannot be loaded, a message is still shown.', 'agend-apps-core' ),
			),
		),
	);
}

/**
 * The colours section on the Style tab.
 *
 * @param string $accent_help What the accent colour is used for.
 * @return array
 */
function agend_apps_records_community_section_colours( string $accent_help ): array {
	return array(
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
				'description' => $accent_help,
				'condition'   => array( 'inherit_colours!' => 'yes' ),
			),
		),
	);
}

/**
 * Channel options for the "Channels to show" picker: the channels the site
 * allows, by name.
 *
 * The picker can only offer what the site allows, so an editor cannot publish
 * a channel the administrator has not. Empty outside an editor
 * {@see agend_apps_records_is_editor_request()}, and while the surface is gated.
 *
 * @return array Options keyed by channel id.
 */
function agend_apps_records_community_channel_options(): array {
	if ( ! function_exists( 'agend_apps_loop_get_channels' ) || ! agend_apps_records_is_editor_request() || agend_apps_records_surface_gated( 'community-channels' ) ) {
		return array();
	}

	$channels = agend_apps_loop_get_channels();

	if ( is_wp_error( $channels ) ) {
		return array();
	}

	usort(
		$channels,
		static function ( array $a, array $b ): int {
			return strcasecmp( $a['name'], $b['name'] );
		}
	);

	$options = array();

	foreach ( $channels as $channel ) {
		$options[ $channel['id'] ] = $channel['name'];
	}

	return $options;
}
