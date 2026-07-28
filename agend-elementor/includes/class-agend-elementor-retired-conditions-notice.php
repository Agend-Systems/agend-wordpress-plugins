<?php
/**
 * Upgrade notice for the retired usermeta display conditions.
 *
 * @package Agend_Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells an administrator which pages lost a usermeta display condition.
 *
 * The usermeta conditions were a second entitlement authority: they read
 * arbitrary user metadata, keyed on mutable slugs, and FAILED OPEN, so an
 * enabled condition with no rules or a blank meta key rendered the element to
 * everybody. They are replaced by the typed, fail-closed policies in Agend
 * Content Access (SPEC-CMS-20260727 US-1.1).
 *
 * Removing the class is what makes the site safe, but it is also silent: the
 * stored `agend_conditions_*` settings simply stop being read, so any element
 * that WAS conditioned now renders for every visitor. On a site that used the
 * feature to hide something, that is a disclosure the administrator would
 * otherwise discover by accident.
 *
 * So this scans for the leftover settings once and names the affected posts.
 * It deliberately reports rather than guesses: it cannot know whether a given
 * condition was protecting something sensitive or merely personalising a
 * greeting, and silently converting the old rules into access policies would
 * be worse, because the old ones could not express an audience reliably in the
 * first place.
 */
class Agend_Elementor_Retired_Conditions_Notice {

	/** Option holding the ids we already reported, so the notice can be dismissed. */
	const DISMISSED_OPTION = 'agend_elementor_conditions_notice_dismissed';

	/** Cached scan result, so the query runs once per day rather than per pageload. */
	const SCAN_TRANSIENT = 'agend_elementor_retired_conditions_scan';

	/** Query action for the dismiss link. */
	const DISMISS_ACTION = 'agend_elementor_dismiss_conditions_notice';

	/**
	 * Registers the notice and its dismiss handler.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss' ) );
	}

	/**
	 * Handles the dismiss link.
	 */
	public static function maybe_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::DISMISS_ACTION );

		update_option( self::DISMISSED_OPTION, true, false );
		delete_transient( self::SCAN_TRANSIENT );
	}

	/**
	 * Post ids whose Elementor document still carries a usermeta condition.
	 *
	 * Matched on the raw `_elementor_data` JSON rather than by walking the
	 * document tree: the settings can sit on any element at any depth, and a
	 * substring match on the stored key is both cheaper and impossible to get
	 * wrong by mis-modelling the tree. `agend_conditions_enabled` is the key
	 * the retired class gated everything else on, so its presence is the
	 * precise signal.
	 *
	 * @return int[]
	 */
	public static function affected_post_ids(): array {
		$cached = get_transient( self::SCAN_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value LIKE %s
				 ORDER BY post_id ASC
				 LIMIT 200",
				'_elementor_data',
				'%' . $wpdb->esc_like( 'agend_conditions_enabled' ) . '%'
			)
		);

		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		set_transient( self::SCAN_TRANSIENT, $ids, DAY_IN_SECONDS );

		return $ids;
	}

	/**
	 * Renders the notice.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( self::DISMISSED_OPTION ) ) {
			return;
		}

		$ids = self::affected_post_ids();

		if ( array() === $ids ) {
			return;
		}

		$links = array();

		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$title = get_the_title( $id );
			$label = '' === trim( (string) $title ) ? sprintf( '#%d', $id ) : sprintf( '%s (#%d)', $title, $id );

			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( $label )
			);
		}

		$remaining = count( $ids ) - count( $links );

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ACTION, '1' ),
			self::DISMISS_ACTION
		);

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Agend Elementor: usermeta display conditions have been removed.', 'agend-elementor' ); ?></strong>
			</p>
			<p>
				<?php
				esc_html_e(
					'They could not reliably protect content: a condition with no rules, or a rule with a blank meta key, showed the element to everyone. The following pages had a condition configured, and those elements now render for every visitor. Re-apply the restriction using Agend Access on the element, then dismiss this notice.',
					'agend-elementor'
				);
				?>
			</p>
			<p>
				<?php
				// Each entry is an escaped anchor built above; the join is markup.
				echo wp_kses_post( implode( ', ', $links ) );

				if ( $remaining > 0 ) {
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %d: number of further affected pages. */
							_n( 'and %d more page.', 'and %d more pages.', $remaining, 'agend-elementor' ),
							$remaining
						)
					);
				}
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button">
					<?php esc_html_e( 'Dismiss', 'agend-elementor' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
