<?php
/**
 * Plugin bootstrap.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward;

use TermSteward\Admin\Page;
use TermSteward\Infrastructure\Database\Schema;

/**
 * Registers the plugin's WordPress hooks.
 */
final class Plugin {
	/**
	 * Shared plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Admin page service.
	 *
	 * @var Page
	 */
	private Page $admin_page;

	/**
	 * Creates the plugin services.
	 */
	private function __construct() {
		$this->admin_page = new Page();
	}

	/**
	 * Returns the shared plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks once.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );
		add_action( 'wp_ajax_term_steward_preview_posts', array( $this->admin_page, 'preview_posts' ) );
		add_action( 'wp_ajax_term_steward_undo_batch', array( $this->admin_page, 'undo_batch' ) );
		add_action( 'wp_ajax_term_steward_history_logs', array( $this->admin_page, 'history_logs' ) );
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_upgrade' ) );
		$this->registered = true;
	}

	/**
	 * Returns the admin page service.
	 */
	public function admin_page(): Page {
		return $this->admin_page;
	}
}
