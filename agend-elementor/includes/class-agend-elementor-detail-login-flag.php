<?php
/**
 * Mirrors the Directory Catalogue's "Require sign-in to view details" switch
 * from a saved Elementor page to the page's sign-in flag.
 *
 * A request for a listing detail URL is decided before anything renders, and a
 * server-rendered detail page never renders the widget, so the switch cannot be
 * read where it is set. Agend Apps Core keeps one flag per page for that
 * (see agend-apps-core/includes/records/detail-login.php); this class records
 * the Elementor side of it, because core must not read another builder's data.
 *
 * Only a catalogue in the page's own element tree is seen. One inside a saved
 * template, a template widget, a global widget or an Elementor Pro theme-builder
 * template is not, and the switch has no effect on direct detail URLs for it.
 * When a page holds several catalogues, any one with the switch on flags it.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static mirror of the switch for Elementor documents.
 */
final class Agend_Elementor_Detail_Login_Flag {

	/**
	 * This builder's slug in the page flag's list of sources.
	 */
	const SOURCE = 'elementor';

	/**
	 * The Directory Catalogue's Elementor widget name.
	 */
	const WIDGET_TYPE = 'agend-directory-catalogue';

	/**
	 * The schema field that holds the switch.
	 */
	const SETTING = 'require_login_for_detail';

	/**
	 * The post meta Elementor stores a document's element tree in.
	 *
	 * Read directly rather than through `Document::get_elements_data()`, as
	 * Agend_Elementor_Preview_Type does: in edit mode that converts a document
	 * with no element data into one, and saves it. Mirroring a flag must never
	 * write to the document.
	 */
	const DATA_META_KEY = '_elementor_data';

	/**
	 * Option recording the backfill version that last ran for this plugin.
	 */
	const BACKFILL_OPTION = 'agend_elementor_login_flag_backfill';

	/**
	 * The current backfill version. Bump it whenever what is mirrored changes,
	 * so the backfill runs once more on the first request after that update.
	 */
	const BACKFILL_VERSION = '1';

	/**
	 * Registers the save hook and the backfill.
	 *
	 * Called from the plugin bootstrap, which runs before `init`.
	 */
	public static function init(): void {
		add_action( 'elementor/document/after_save', array( __CLASS__, 'on_after_save' ) );

		// Core re-derives a page's flag when the dedicated directory page
		// changes, and asks every builder to record its own source.
		add_action( 'agend_apps_records_directory_backfill_login_flag', array( __CLASS__, 'on_core_backfill' ) );

		// Core's own backfill runs at priority 12. This plugin stamps its own
		// version, because the two plugins are updated independently: when
		// core backfills before this plugin is updated, nothing would ever ask
		// again.
		add_action( 'init', array( __CLASS__, 'maybe_backfill' ), 13 );
	}

	/**
	 * Whether an element tree holds a Directory Catalogue with the switch on.
	 *
	 * Pure, so it is testable without Elementor or WordPress. Any one
	 * catalogue with the switch on, at any depth, is enough.
	 *
	 * @param array $elements Elementor element data (the `_elementor_data` tree).
	 * @return bool
	 */
	public static function from_elements( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( 'widget' === ( $element['elType'] ?? '' ) && self::WIDGET_TYPE === ( $element['widgetType'] ?? '' ) ) {
				$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();

				if ( 'yes' === ( $settings[ self::SETTING ] ?? '' ) ) {
					return true;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && self::from_elements( $element['elements'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a saved document has a catalogue with the switch on.
	 *
	 * @param int $post_id The document's post id.
	 * @return bool
	 */
	public static function for_post( int $post_id ): bool {
		$data = get_post_meta( $post_id, self::DATA_META_KEY, true );

		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
		}

		return is_array( $data ) && self::from_elements( $data );
	}

	/**
	 * Records this page's Elementor source in the page flag.
	 *
	 * Only a page can host a directory detail URL (the rewrite rule resolves a
	 * `pagename`), so a saved template or any other post type is left alone.
	 * An Agend Apps Core that predates the flag has nothing to record into.
	 *
	 * @param int $post_id The saved document's post id.
	 */
	public static function sync( int $post_id ): void {
		if ( ! function_exists( 'agend_apps_records_directory_set_login_source' ) ) {
			return;
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! ( $post instanceof WP_Post ) || 'page' !== $post->post_type ) {
			return;
		}

		agend_apps_records_directory_set_login_source( $post_id, self::SOURCE, self::for_post( $post_id ) );
	}

	/**
	 * `elementor/document/after_save`: the element tree has just been written.
	 *
	 * @param object $document The saved Elementor document.
	 */
	public static function on_after_save( $document ): void {
		if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
			self::sync( (int) $document->get_main_id() );
		}
	}

	/**
	 * Core asked every builder to re-derive a page's flag.
	 *
	 * @param int $page_id The page.
	 */
	public static function on_core_backfill( $page_id ): void {
		self::sync( (int) $page_id );
	}

	/**
	 * One-time backfill of the dedicated directory page, per backfill version.
	 *
	 * A page saved before this shipped has no Elementor source recorded.
	 */
	public static function maybe_backfill(): void {
		if ( self::BACKFILL_VERSION === get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		if ( ! class_exists( 'Agend_Apps_Records_Pages' ) || ! function_exists( 'agend_apps_records_directory_set_login_source' ) ) {
			// Core is not ready; try again once it is, rather than stamping.
			return;
		}

		self::sync( Agend_Apps_Records_Pages::page_id( 'listing' ) );

		update_option( self::BACKFILL_OPTION, self::BACKFILL_VERSION );
	}
}
