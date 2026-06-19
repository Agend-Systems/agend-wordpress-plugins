<?php
/**
 * Plugin controller.
 *
 * @package Agend_Loop_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together. Registers the sync hooks (and admin screen) only
 * when the required sibling plugins are active; otherwise shows an admin
 * notice and stays dormant.
 */
class Agend_Loop_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var Agend_Loop_Sync|null
	 */
	private static $instance = null;

	/**
	 * User sync handler.
	 *
	 * @var Agend_Loop_Sync_User_Sync|null
	 */
	private $user_sync = null;

	/**
	 * Committee sync handler.
	 *
	 * @var Agend_Loop_Sync_Committee_Sync|null
	 */
	private $committee_sync = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Agend_Loop_Sync
	 */
	public static function instance(): Agend_Loop_Sync {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! Agend_Loop_Sync_Dependencies::are_met() ) {
			add_action( 'admin_notices', array( 'Agend_Loop_Sync_Dependencies', 'render_notice' ) );
			return;
		}

		$this->user_sync      = new Agend_Loop_Sync_User_Sync();
		$this->committee_sync = new Agend_Loop_Sync_Committee_Sync();

		$this->user_sync->register();
		$this->committee_sync->register();

		if ( is_admin() && class_exists( 'Agend_Loop_Sync_Admin' ) ) {
			$admin = new Agend_Loop_Sync_Admin( $this->committee_sync );
			$admin->register();
		}
	}

	/**
	 * @return Agend_Loop_Sync_Committee_Sync|null
	 */
	public function committee_sync() {
		return $this->committee_sync;
	}

	/**
	 * @return Agend_Loop_Sync_User_Sync|null
	 */
	public function user_sync() {
		return $this->user_sync;
	}
}
