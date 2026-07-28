<?php
/**
 * The Agend Protected File Elementor widget.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A download that only eligible members can retrieve
 * (SPEC-CMS-20260727 US-5.1 criterion 2).
 *
 * Built as an Elementor WIDGET rather than a page-level attachment list because
 * a widget IS a fragment. That gets the per-section Agend Access control, the
 * ordering, and the projection for free, and means a protected download is
 * governed by exactly the same policy machinery as protected text rather than a
 * parallel one that could disagree with it.
 *
 * The editor picks the file with the ordinary WordPress media picker. On save
 * the file is PROMOTED into Agend private storage and the WordPress copy is
 * deleted, because a file left in `wp-content/uploads` is retrievable by anyone
 * with the URL and would make the policy decorative.
 *
 * The frontend renders a link to the authorising download endpoint, never to
 * the file. There is no URL to leak: the asset id is useless without a bearer
 * that satisfies the governing policy at the moment of the click.
 */
class Agend_Content_Access_Protected_File_Widget extends \Elementor\Widget_Base {

	public function get_name(): string {
		return 'agend-protected-file';
	}

	public function get_title(): string {
		return __( 'Agend Protected File', 'agend-content-access' );
	}

	public function get_icon(): string {
		return 'eicon-lock-user';
	}

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'agend', 'download', 'member', 'protected', 'file' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'agend_protected_file_section',
			array(
				'label' => __( 'Protected File', 'agend-content-access' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'agend_protected_file_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => __(
					'When you save, this file is moved into Agend private storage and removed from your WordPress media library. Visitors never receive a link to the file itself, only a request that Agend checks before it serves anything.',
					'agend-content-access'
				),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			Agend_Content_Access_Assets::ATTACHMENT_KEY,
			array(
				'label'       => __( 'File', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'media_types' => array( 'image', 'video', 'application' ),
				'description' => __(
					'Choose the file to protect. It is moved to Agend when you save.',
					'agend-content-access'
				),
			)
		);

		$this->add_control(
			'agend_protected_file_label',
			array(
				'label'       => __( 'Link text', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Download', 'agend-content-access' ),
				'description' => __(
					'Shown to members who can access the file. This text is public, so keep anything sensitive out of it.',
					'agend-content-access'
				),
			)
		);

		// Written by the promotion step, not by the editor. Shown read-only so
		// an editor can confirm the file made it across and quote the id to
		// support, which is the only thing the id is useful for on its own.
		$this->add_control(
			Agend_Content_Access_Assets::ASSET_ID_KEY,
			array(
				'label'       => __( 'Agend asset id', 'agend-content-access' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Assigned when you save', 'agend-content-access' ),
				'description' => __(
					'Filled in automatically. Safe to share with support: it cannot be used to fetch the file.',
					'agend-content-access'
				),
			)
		);

		$this->add_control(
			Agend_Content_Access_Assets::FILE_NAME_KEY,
			array(
				'type'    => \Elementor\Controls_Manager::HIDDEN,
				'default' => '',
			)
		);

		$this->add_control(
			Agend_Content_Access_Assets::MIME_TYPE_KEY,
			array(
				'type'    => \Elementor\Controls_Manager::HIDDEN,
				'default' => '',
			)
		);

		$this->add_control(
			Agend_Content_Access_Assets::SIZE_KEY,
			array(
				'type'    => \Elementor\Controls_Manager::HIDDEN,
				'default' => 0,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the download prompt.
	 *
	 * Deliberately emits NO file URL. The href points at the plugin's own
	 * download route, which re-checks the policy with Agend on every click, so
	 * a page cached by a CDN or saved to disk carries nothing retrievable.
	 *
	 * Suppression is handled upstream: if the effective policy denies this
	 * viewer, `should_render` removes the whole widget before this runs. This
	 * method therefore only ever renders for somebody the policy admits, and
	 * still hands them a link that is re-authorised rather than a file.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$asset_id = isset( $settings[ Agend_Content_Access_Assets::ASSET_ID_KEY ] )
			? trim( (string) $settings[ Agend_Content_Access_Assets::ASSET_ID_KEY ] )
			: '';

		if ( '' === $asset_id ) {
			if ( Agend_Content_Access_Frontend::is_authoring_context() ) {
				printf(
					'<p class="agend-protected-file agend-protected-file--empty">%s</p>',
					esc_html__(
						'No file yet. Choose one and save, and it will be moved into Agend storage.',
						'agend-content-access'
					)
				);
			}

			return;
		}

		$label = isset( $settings['agend_protected_file_label'] ) && '' !== trim( (string) $settings['agend_protected_file_label'] )
			? (string) $settings['agend_protected_file_label']
			: __( 'Download', 'agend-content-access' );

		$file_name = isset( $settings[ Agend_Content_Access_Assets::FILE_NAME_KEY ] )
			? (string) $settings[ Agend_Content_Access_Assets::FILE_NAME_KEY ]
			: '';

		printf(
			'<p class="agend-protected-file"><a href="%s" rel="nofollow noopener" data-agend-asset="%s">%s</a>%s</p>',
			esc_url( Agend_Content_Access_Assets::download_url( $asset_id ) ),
			esc_attr( $asset_id ),
			esc_html( $label ),
			'' === $file_name
				? ''
				: sprintf(
					' <span class="agend-protected-file-name">(%s)</span>',
					esc_html( $file_name )
				)
		);
	}
}
