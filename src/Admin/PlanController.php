<?php
/**
 * Phase 4 admin plan request handling.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Application\Planning\PlanValidationException;
use TaxonomyTidy\Application\Planning\PlanErrorCode;
use TaxonomyTidy\Application\Planning\PlanWorkflow;
use TaxonomyTidy\Application\Execution\ExecutionException;
use TaxonomyTidy\Application\Execution\ExecutionWorkflow;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\PersistenceException;

/**
 * Sanitizes authenticated form requests and delegates to the plan workflow.
 */
final class PlanController {
	/**
	 * Form nonce action.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'taxonomy_tidy_plan';

	/**
	 * Form nonce field.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = 'taxonomy_tidy_nonce';

	/**
	 * Planning workflow.
	 *
	 * @var PlanWorkflow
	 */
	private PlanWorkflow $workflow;

	/**
	 * Batched execution workflow.
	 *
	 * @var ExecutionWorkflow|null
	 */
	private ?ExecutionWorkflow $execution;

	/**
	 * Creates the request handler.
	 *
	 * @param PlanWorkflow           $workflow  Planning workflow.
	 * @param ExecutionWorkflow|null $execution Batched execution workflow.
	 */
	public function __construct( PlanWorkflow $workflow, ?ExecutionWorkflow $execution = null ) {
		$this->workflow  = $workflow;
		$this->execution = $execution;
	}

	/**
	 * Handles a possible POST and returns render state.
	 *
	 * @param Taxonomy $taxonomy Current URL taxonomy.
	 * @return array{operation: array<string, mixed>|null, errors: list<string>, field_errors: array<string, list<string>>, notice: string|null, selected_ids: list<int>, input: array<string, mixed>}
	 */
	public function handle( Taxonomy $taxonomy ): array {
		$user_id = get_current_user_id();
		$state   = array(
			'operation'    => null,
			'errors'       => array(),
			'field_errors' => array(),
			'notice'       => null,
			'selected_ids' => array(),
			'input'        => array(),
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Server request method is compared with a fixed HTTP verb and never rendered.
		$is_post = 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );

		if ( $is_post && ! Access::current_user_can_access() ) {
			$state['errors'][]     = PlanErrorCode::PERMISSION_DENIED;
			$state['field_errors'] = $this->classify_errors( $state['errors'] );
			return $state;
		}

		try {
			$state['operation'] = $this->workflow->current( $user_id, $taxonomy );
			if ( null === $state['operation'] && null !== $this->execution ) {
				$state['operation'] = $this->execution->latest( $user_id, $taxonomy );
			}
		} catch ( \Throwable $exception ) {
			$this->log_internal_error( $exception );
			$state['errors']       = array( PlanErrorCode::UNKNOWN_ERROR );
			$state['field_errors'] = $this->classify_errors( $state['errors'] );
			return $state;
		}

		if ( ! $is_post ) {
			return $state;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- The nonce is type-checked, unslashed, sanitized, and verified immediately below.
		$posted_nonce = $_POST[ self::NONCE_FIELD ] ?? '';
		$nonce        = is_string( $posted_nonce ) ? sanitize_text_field( wp_unslash( $posted_nonce ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$state['errors'][]     = PlanErrorCode::INVALID_NONCE;
			$state['field_errors'] = $this->classify_errors( $state['errors'] );
			return $state;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above; every value is sanitized below.
		$request         = wp_unslash( $_POST );
		$posted_taxonomy = is_string( $request['taxonomy'] ?? null ) ? sanitize_key( $request['taxonomy'] ) : '';
		if ( $taxonomy->value !== $posted_taxonomy ) {
			$state['errors'][]     = PlanErrorCode::TAXONOMY_MISMATCH;
			$state['field_errors'] = $this->classify_errors( $state['errors'] );
			return $state;
		}

		$command = is_string( $request['plan_command'] ?? null ) ? sanitize_key( $request['plan_command'] ) : '';
		if ( isset( $request['remove_index'] ) ) {
			$command = 'remove';
		}
		$selected_ids          = array_values( array_filter( array_map( 'absint', (array) ( $request['selected_terms'] ?? array() ) ) ) );
		$state['selected_ids'] = $selected_ids;
		$state['input']        = $this->sanitize_input( $request );

		try {
			if ( 'run' === $command || 'continue' === $command ) {
				if ( null === $this->execution ) {
					$state['errors'][] = PlanErrorCode::UNKNOWN_ERROR;
				} else {
					$state['operation'] = $this->execution->run_batch( absint( $request['operation_id'] ?? 0 ), $user_id, $taxonomy );
					$state['notice']    = 'execution_updated';
				}
			} elseif ( 'execute' === $command ) {
				$state['operation']    = $this->workflow->preview_with_item( $user_id, $taxonomy, $this->build_item( $request, $selected_ids ) );
				$state['notice']       = 'preview_created';
				$state['selected_ids'] = array();
			} elseif ( 'add' === $command ) {
				$state['operation']    = $this->workflow->add( $user_id, $taxonomy, $this->build_item( $request, $selected_ids ) );
				$state['notice']       = 'plan_item_added';
				$state['selected_ids'] = array();
			} elseif ( 'remove' === $command ) {
				$state['operation'] = $this->workflow->remove( $user_id, $taxonomy, absint( $request['remove_index'] ?? -1 ) );
				$state['notice']    = 'plan_item_removed';
			} elseif ( 'preview' === $command ) {
				$state['operation'] = $this->workflow->preview( $user_id, $taxonomy );
				$state['notice']    = 'preview_created';
			} elseif ( 'revise' === $command ) {
				$state['operation'] = $this->workflow->revise( $user_id, $taxonomy );
				$state['notice']    = 'preview_invalidated';
			} elseif ( 'discard' === $command ) {
				$this->workflow->discard( $user_id, $taxonomy );
				$state['operation'] = null;
				$state['notice']    = 'plan_discarded';
			} else {
				$state['errors'][] = PlanErrorCode::UNKNOWN_ERROR;
			}
		} catch ( PlanValidationException $exception ) {
			$state['errors'] = $exception->codes();
		} catch ( ExecutionException $exception ) {
			$state['errors'] = array( $exception->error_code() );
		} catch ( PersistenceException | \JsonException $exception ) {
			$this->log_internal_error( $exception );
			$state['errors'] = array( PlanErrorCode::UNKNOWN_ERROR );
		} catch ( \Throwable $exception ) {
			$this->log_internal_error( $exception );
			$state['errors'] = array( PlanErrorCode::UNKNOWN_ERROR );
		}
		$state['field_errors'] = $this->classify_errors( $state['errors'] );

		return $state;
	}

	/**
	 * Builds one untrusted item shape for server-side validation.
	 *
	 * @param array<string, mixed> $request      Unslashed request.
	 * @param array                $selected_ids Selected term IDs.
	 * @return array<string, mixed>
	 */
	private function build_item( array $request, array $selected_ids ): array {
		$source_tt_ids = array();
		foreach ( $selected_ids as $term_id ) {
			$source_tt_ids[ $term_id ] = absint( ( (array) ( $request['term_taxonomy_ids'] ?? array() ) )[ $term_id ] ?? 0 );
		}

		$destination    = is_string( $request['destination'] ?? null ) ? sanitize_text_field( $request['destination'] ) : '';
		$destination_id = 0;
		$destination_tt = 0;
		if ( preg_match( '/^(\d+):(\d+)\b/', $destination, $matches ) ) {
			$destination_id = absint( $matches[1] );
			$destination_tt = absint( $matches[2] );
		}

		return array(
			'action'            => is_string( $request['operation_action'] ?? null ) ? sanitize_key( $request['operation_action'] ) : '',
			'source_ids'        => $selected_ids,
			'source_tt_ids'     => $source_tt_ids,
			'new_name'          => is_string( $request['new_name'] ?? null ) ? $request['new_name'] : '',
			'new_slug'          => is_string( $request['new_slug'] ?? null ) ? $request['new_slug'] : '',
			'destination_id'    => $destination_id,
			'destination_tt_id' => $destination_tt,
		);
	}

	/**
	 * Returns sanitized values that may be redisplayed after validation errors.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 * @return array<string, mixed>
	 */
	private function sanitize_input( array $request ): array {
		return array(
			'operation_action' => is_string( $request['operation_action'] ?? null ) ? sanitize_key( $request['operation_action'] ) : '',
			'new_name'         => is_string( $request['new_name'] ?? null ) ? sanitize_text_field( $request['new_name'] ) : '',
			'new_slug'         => is_string( $request['new_slug'] ?? null ) ? sanitize_title( $request['new_slug'] ) : '',
			'destination'      => is_string( $request['destination'] ?? null ) ? sanitize_text_field( $request['destination'] ) : '',
		);
	}

	/**
	 * Groups stable validation codes by the UI field or section that can resolve them.
	 *
	 * @param array $errors Stable list of validation codes.
	 * @return array<string, list<string>>
	 */
	private function classify_errors( array $errors ): array {
		$groups = array();
		foreach ( $errors as $error ) {
			$field = match ( $error ) {
				PlanErrorCode::SELECTION_REQUIRED, PlanErrorCode::SELECTION_INVALID, PlanErrorCode::SINGLE_TERM_REQUIRED => 'selection',
				PlanErrorCode::OPERATION_REQUIRED => 'operation',
				PlanErrorCode::NEW_NAME_REQUIRED, PlanErrorCode::INVALID_NAME, PlanErrorCode::NAME_CONFLICT, PlanErrorCode::NAME_UNCHANGED => 'new_name',
				PlanErrorCode::INVALID_SLUG, PlanErrorCode::SLUG_CONFLICT => 'new_slug',
				PlanErrorCode::DESTINATION_REQUIRED, PlanErrorCode::SAME_SOURCE_DESTINATION, PlanErrorCode::TAXONOMY_MISMATCH, PlanErrorCode::DESCENDANT_DESTINATION, PlanErrorCode::CIRCULAR_HIERARCHY => 'destination',
				PlanErrorCode::TERM_IN_USE, PlanErrorCode::DEFAULT_CATEGORY => 'delete',
				default => 'plan',
			};
			$groups[ $field ][] = $error;
		}
		return $groups;
	}

	/**
	 * Records technical details without exposing them in the administration UI.
	 *
	 * @param \Throwable $exception Internal failure.
	 */
	private function log_internal_error( \Throwable $exception ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Technical details belong in the configured debug log, never in rendered notices.
			error_log( sprintf( 'Taxonomy Tidy planning error: %s: %s', $exception::class, $exception->getMessage() ) );
		}
	}
}
