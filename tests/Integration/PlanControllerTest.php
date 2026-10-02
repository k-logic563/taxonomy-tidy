<?php
/**
 * Phase 4 planning request security tests.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Tests\Integration;

use TermSteward\Admin\PlanController;
use TermSteward\Application\Planning\PlanService;
use TermSteward\Application\Planning\PlanWorkflow;
use TermSteward\Domain\Operation\Status;
use TermSteward\Domain\Operation\Taxonomy;
use TermSteward\Infrastructure\Database\Schema;
use TermSteward\Infrastructure\Database\Tables;
use TermSteward\Infrastructure\Persistence\OperationRepository;
use WP_Term;
use WP_UnitTestCase;

/**
 * Verifies capability, nonce, taxonomy, identity, and sanitization defenses.
 */
final class PlanControllerTest extends WP_UnitTestCase {
	/**
	 * Original POST values.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_post;

	/**
	 * Original request method.
	 *
	 * @var string|null
	 */
	private ?string $original_method;

	/**
	 * Controller under test.
	 *
	 * @var PlanController
	 */
	private PlanController $controller;

	/**
	 * Prepares isolated persistence and request globals.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		Schema::install();
		$this->delete_operations();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test setup preserves request state without processing it.
		$this->original_post = $_POST;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test setup preserves a server value and never renders it.
		$this->original_method     = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->controller          = new PlanController( new PlanWorkflow( new OperationRepository( $wpdb ), new PlanService() ) );
	}

	/**
	 * Restores request and user state.
	 */
	public function tear_down(): void {
		$_POST = $this->original_post;
		if ( null === $this->original_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_method;
		}
		wp_set_current_user( 0 );
		$this->delete_operations();
		parent::tear_down();
	}

	/**
	 * All required capabilities are enforced before request processing.
	 */
	public function test_request_requires_complete_capability_set(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->post( array( PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ) ) );

		$state = $this->controller->handle( Taxonomy::CATEGORY );

		$this->assertSame( array( 'permission_denied' ), $state['errors'] );
		$this->assertNull( $state['operation'] );
	}

	/**
	 * Invalid nonce and taxonomy values are rejected.
	 */
	public function test_request_rejects_invalid_nonce_and_taxonomy(): void {
		$this->login_admin();
		$this->post(
			array(
				'taxonomy'                  => 'category',
				PlanController::NONCE_FIELD => 'invalid',
			)
		);
		$this->assertSame( array( 'invalid_nonce' ), $this->controller->handle( Taxonomy::CATEGORY )['errors'] );

		$this->post(
			array(
				'taxonomy'                  => 'invalid_taxonomy',
				PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
			)
		);
		$this->assertSame( array( 'taxonomy_mismatch' ), $this->controller->handle( Taxonomy::CATEGORY )['errors'] );
	}

	/**
	 * A mismatched taxonomy-row identity cannot enter a plan.
	 */
	public function test_request_rejects_term_taxonomy_id_mismatch(): void {
		$this->login_admin();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Identity source',
			)
		);
		$this->post(
			array(
				'taxonomy'                  => 'post_tag',
				'plan_command'              => 'add',
				'operation_action'          => 'rename',
				'selected_terms'            => array( (string) $term_id ),
				'term_taxonomy_ids'         => array( $term_id => '999999' ),
				'new_name'                  => 'Changed',
				PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
			)
		);

		$state = $this->controller->handle( Taxonomy::POST_TAG );
		$this->assertContains( 'taxonomy_mismatch', $state['errors'] );
		$this->assertNull( $state['operation'] );
	}

	/**
	 * Redisplayed input is sanitized while invalid values remain rejected.
	 */
	public function test_request_sanitizes_redisplayed_input_and_rejects_invalid_action(): void {
		$this->login_admin();
		$this->post(
			array(
				'taxonomy'                  => 'category',
				'plan_command'              => 'add',
				'operation_action'          => 'not-an-action<script>',
				'new_name'                  => '<script>alert(1)</script>Safe',
				PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
			)
		);

		$state = $this->controller->handle( Taxonomy::CATEGORY );
		$this->assertContains( 'operation_required', $state['errors'] );
		$this->assertContains( 'selection_required', $state['errors'] );
		$this->assertSame( array( 'operation_required' ), $state['field_errors']['operation'] );
		$this->assertSame( array( 'selection_required' ), $state['field_errors']['selection'] );
		$this->assertSame( 'Safe', $state['input']['new_name'] );
		$this->assertStringNotContainsString( '<', (string) $state['input']['operation_action'] );
		$this->assertNull( $state['operation'] );
	}

	/**
	 * Adding a draft item does not preview or change the term.
	 */
	public function test_valid_add_creates_draft_without_changing_term(): void {
		$this->login_admin();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Safe source',
				'slug'     => 'safe-source',
			)
		);
		$term    = get_term( $term_id, 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->post(
			array(
				'taxonomy'                  => 'post_tag',
				'plan_command'              => 'add',
				'operation_action'          => 'rename',
				'selected_terms'            => array( (string) $term_id ),
				'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
				'new_name'                  => 'Safe renamed',
				PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
			)
		);

		$state  = $this->controller->handle( Taxonomy::POST_TAG );
		$stored = get_term( $term_id, 'post_tag' );
		$this->assertSame( array(), $state['errors'] );
		$this->assertSame( 'plan_item_added', $state['notice'] );
		$this->assertSame( Status::DRAFT->value, $state['operation']['status'] );
		$this->assertSame( $term_id, $state['operation']['requested_data']['plan'][0]['sources'][0]['term_id'] );
		$this->assertNull( $state['operation']['plan_hash'] );
		$this->assertNull( $state['operation']['state_fingerprint'] );
		$this->assertInstanceOf( WP_Term::class, $stored );
		$this->assertSame( 'Safe source', $stored->name );
	}

	/** The taxonomy tab cannot create a preview or start execution directly. */
	public function test_taxonomy_tab_rejects_old_preview_and_run_commands(): void {
		$this->login_admin();
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Blocked direct action',
			)
		);
		$term    = get_term( $term_id, 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $term );
		foreach ( array( 'execute', 'preview', 'run' ) as $command ) {
			$this->post(
				array(
					'taxonomy'                  => 'post_tag',
					'plan_command'              => $command,
					'operation_action'          => 'rename',
					'selected_terms'            => array( (string) $term_id ),
					'term_taxonomy_ids'         => array( $term_id => (string) $term->term_taxonomy_id ),
					'new_name'                  => 'Forbidden change',
					PlanController::NONCE_FIELD => wp_create_nonce( PlanController::NONCE_ACTION ),
				)
			);
			$this->assertSame( array( 'unknown_error' ), $this->controller->handle( Taxonomy::POST_TAG )['errors'] );
			$this->assertSame( 'Blocked direct action', get_term( $term_id )->name );
		}
	}

	/**
	 * Changes the simulated request to POST.
	 *
	 * @param array<string, mixed> $values Posted values.
	 */
	private function post( array $values ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $values;
	}

	/**
	 * Logs in a user with all required administrative capabilities.
	 */
	private function login_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clears plugin persistence in the isolated test database.
	 */
	private function delete_operations(): void {
		global $wpdb;

		foreach ( array( Tables::changes( $wpdb ), Tables::items( $wpdb ), Tables::operations( $wpdb ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Isolated custom test tables are intentionally cleared.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}
