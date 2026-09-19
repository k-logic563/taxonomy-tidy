<?php
/**
 * Phase 4 planning controls and preview output.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Domain\Operation\Action;
use TaxonomyTidy\Domain\Operation\Status;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Application\Planning\PlanErrorCode;
use WP_Term;

/**
 * Renders operation planning state without changing taxonomy data.
 */
final class PlanningPanel {
	/**
	 * Renders the selectable list, processing panel, plan, and preview.
	 *
	 * @param Taxonomy             $taxonomy  Current taxonomy.
	 * @param string               $type_label Translated taxonomy label.
	 * @param array<string, mixed> $inventory Current inventory page.
	 * @param array<string, mixed> $state     Request state.
	 * @param callable             $render_pagination Renders controls around the inventory table.
	 */
	public function render( Taxonomy $taxonomy, string $type_label, array $inventory, array $state, callable $render_pagination ): void {
		$operation    = is_array( $state['operation'] ) ? $state['operation'] : null;
		$plan         = is_array( $operation['requested_data']['plan'] ?? null ) ? $operation['requested_data']['plan'] : array();
		$errors       = is_array( $state['errors'] ) ? $state['errors'] : array();
		$field_errors = is_array( $state['field_errors'] ?? null ) ? $state['field_errors'] : array();
		$selected     = is_array( $state['selected_ids'] ) ? array_map( 'intval', $state['selected_ids'] ) : array();
		$input        = is_array( $state['input'] ) ? $state['input'] : array();

		$this->render_notice( $state['notice'] ?? null, $errors );
		?>
		<form id="taxonomy-tidy-planning-form" class="taxonomy-tidy-planning-form" method="post">
			<?php wp_nonce_field( PlanController::NONCE_ACTION, PlanController::NONCE_FIELD ); ?>
			<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">
			<?php if ( null === $operation || in_array( $operation['status'], array( Status::DRAFT->value, Status::PREVIEWED->value ), true ) ) : ?>
				<?php $this->render_process_panel( $taxonomy, $inventory, $selected, $plan, $input, $errors, $field_errors ); ?>
			<?php endif; ?>
			<?php $this->render_table( $taxonomy, $type_label, $inventory, $selected, $render_pagination ); ?>
			<?php // The shared operation-plan tab owns saved-plan review and preview. ?>
			<?php if ( null !== $operation && in_array( $operation['status'], array( Status::RUNNING->value, Status::COMPLETED->value, Status::PARTIAL_FAILED->value, Status::FAILED->value ), true ) ) : ?>
				<?php $this->render_execution( $operation ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Renders selection controls with accessible term labels.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param string               $type_label Translated taxonomy label.
	 * @param array<string, mixed> $inventory Current inventory page.
	 * @param array                $selected Selected term IDs after an error.
	 * @param callable             $render_pagination Renders table navigation.
	 */
	private function render_table( Taxonomy $taxonomy, string $type_label, array $inventory, array $selected, callable $render_pagination ): void {
		$default_category = (int) get_option( 'default_category' );
		$parent_ids       = array();
		if ( Taxonomy::CATEGORY === $taxonomy ) {
			$term_parents = get_terms(
				array(
					'taxonomy'   => $taxonomy->value,
					'hide_empty' => false,
					'fields'     => 'id=>parent',
				)
			);
			if ( is_array( $term_parents ) ) {
				$parent_ids = array_map( 'intval', array_values( $term_parents ) );
			}
		}
		?>
		<?php $render_pagination( 'top' ); ?>
		<table class="wp-list-table widefat fixed striped taxonomy-tidy-inventory-table">
			<thead><tr>
				<td class="manage-column check-column"><input type="checkbox" class="taxonomy-tidy-select-page" aria-label="<?php echo esc_attr__( 'Select all terms on this page', 'taxonomy-tidy' ); ?>"></td>
				<th scope="col"><?php echo esc_html__( 'Name', 'taxonomy-tidy' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Slug', 'taxonomy-tidy' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Type', 'taxonomy-tidy' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Parent category', 'taxonomy-tidy' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Published posts', 'taxonomy-tidy' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Total relationships', 'taxonomy-tidy' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Usage', 'taxonomy-tidy' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( array() === $inventory['items'] ) : ?>
				<tr><td class="taxonomy-tidy-inventory-table__empty" colspan="8"><?php echo esc_html__( 'No terms found.', 'taxonomy-tidy' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $inventory['items'] as $term ) : ?>
					<?php $deletion_available = 0 === (int) $term['total_relationship_count'] && ! ( Taxonomy::CATEGORY === $taxonomy && $default_category === (int) $term['term_id'] ); ?>
					<?php $has_children = in_array( (int) $term['term_id'], $parent_ids, true ); ?>
					<?php $excluded_use = (int) $term['total_relationship_count'] > (int) $term['published_post_count']; ?>
					<tr data-term-key="<?php echo esc_attr( (string) $term['term_id'] ); ?>" data-term-name="<?php echo esc_attr( (string) $term['name'] ); ?>" data-term-slug="<?php echo esc_attr( (string) $term['slug'] ); ?>" data-published-count="<?php echo esc_attr( (string) $term['published_post_count'] ); ?>" data-total-count="<?php echo esc_attr( (string) $term['total_relationship_count'] ); ?>" data-deletion-available="<?php echo $deletion_available ? '1' : '0'; ?>" data-merge-retained="<?php echo $excluded_use || $has_children ? '1' : '0'; ?>" data-excluded-use="<?php echo $excluded_use ? '1' : '0'; ?>" data-has-children="<?php echo $has_children ? '1' : '0'; ?>" data-default-category="<?php echo Taxonomy::CATEGORY === $taxonomy && $default_category === (int) $term['term_id'] ? '1' : '0'; ?>">
						<th scope="row" class="check-column">
							<label class="screen-reader-text" for="taxonomy-tidy-term-<?php echo esc_attr( (string) $term['term_id'] ); ?>">
								<?php
								printf(
									/* translators: %s: taxonomy term name. */
									esc_html__( 'Select %s', 'taxonomy-tidy' ),
									esc_html( (string) $term['name'] )
								);
								?>
							</label>
							<input id="taxonomy-tidy-term-<?php echo esc_attr( (string) $term['term_id'] ); ?>" class="taxonomy-tidy-term-select" type="checkbox" name="selected_terms[]" value="<?php echo esc_attr( (string) $term['term_id'] ); ?>" <?php checked( in_array( (int) $term['term_id'], $selected, true ) ); ?>>
							<input type="hidden" name="term_taxonomy_ids[<?php echo esc_attr( (string) $term['term_id'] ); ?>]" value="<?php echo esc_attr( (string) $term['term_taxonomy_id'] ); ?>">
						</th>
						<td data-label="<?php echo esc_attr__( 'Name', 'taxonomy-tidy' ); ?>"><strong><?php echo esc_html( (string) $term['name'] ); ?></strong></td>
						<td data-label="<?php echo esc_attr__( 'Slug', 'taxonomy-tidy' ); ?>"><code><?php echo esc_html( (string) $term['slug'] ); ?></code></td>
						<td data-label="<?php echo esc_attr__( 'Type', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $type_label ); ?></td>
						<td data-label="<?php echo esc_attr__( 'Parent category', 'taxonomy-tidy' ); ?>"><?php echo esc_html( is_string( $term['parent_name'] ) ? $term['parent_name'] : '—' ); ?></td>
						<td data-label="<?php echo esc_attr__( 'Published posts', 'taxonomy-tidy' ); ?>"><?php echo esc_html( number_format_i18n( (int) $term['published_post_count'] ) ); ?></td>
						<td data-label="<?php echo esc_attr__( 'Total relationships', 'taxonomy-tidy' ); ?>"><?php echo esc_html( number_format_i18n( (int) $term['total_relationship_count'] ) ); ?></td>
						<td data-label="<?php echo esc_attr__( 'Usage', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $this->usage_label( (string) $term['usage'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php $render_pagination( 'bottom' ); ?>
		<?php
	}

	/**
	 * Renders grouped operation inputs; errors are the only automatic-open reason.
	 *
	 * @param Taxonomy                    $taxonomy Current taxonomy.
	 * @param array<string, mixed>        $inventory Current inventory page.
	 * @param array                       $selected Selected term IDs.
	 * @param list<array<string, mixed>>  $plan Saved normalized plan.
	 * @param array<string, mixed>        $input Redisplayed sanitized input.
	 * @param array                       $errors Validation errors.
	 * @param array<string, list<string>> $field_errors Validation errors grouped by field.
	 */
	private function render_process_panel( Taxonomy $taxonomy, array $inventory, array $selected, array $plan, array $input, array $errors, array $field_errors ): void {
		$action              = is_string( $input['operation_action'] ?? null ) ? $input['operation_action'] : '';
		$focus               = $this->error_focus( $field_errors );
		$items               = $this->selected_items( $inventory, $selected );
		$destination_removed = Action::MERGE->value === $action && $this->destination_is_selected_source( (string) ( $input['destination'] ?? '' ), $selected );
		if ( $destination_removed ) {
			$input['destination'] = '';
		}
		/* translators: %d: number of selected terms. */
		$selected_format = __( '%d terms selected', 'taxonomy-tidy' );
		?>
		<details class="taxonomy-tidy-panel taxonomy-tidy-process-panel" <?php echo array() !== $errors ? 'open' : ''; ?>>
			<summary class="taxonomy-tidy-panel__summary">
				<span class="taxonomy-tidy-panel__heading"><span class="taxonomy-tidy-panel__icon" aria-hidden="true"></span><span><?php echo esc_html__( 'Action panel', 'taxonomy-tidy' ); ?></span></span>
				<span class="taxonomy-tidy-filter-summary" aria-live="polite">
					<span class="taxonomy-tidy-selection-summary" data-none="<?php echo esc_attr__( 'No terms selected', 'taxonomy-tidy' ); ?>" data-selected="<?php echo esc_attr( $selected_format ); ?>">
						<?php echo esc_html( $this->selected_count_label( count( $selected ) ) ); ?>
					</span>
					<span><?php echo esc_html( $this->plan_count_label( count( $plan ) ) ); ?></span>
				</span>
			</summary>
			<div class="taxonomy-tidy-process-content">
				<section id="taxonomy-tidy-selection-section" class="taxonomy-tidy-process-group" aria-labelledby="taxonomy-tidy-target-heading">
					<h3 id="taxonomy-tidy-target-heading"><?php echo esc_html__( 'Selected targets', 'taxonomy-tidy' ); ?></h3>
					<p class="taxonomy-tidy-selected-count" aria-live="polite"><?php echo esc_html( $this->selected_count_label( count( $selected ) ) ); ?></p>
					<div class="taxonomy-tidy-selected-terms" aria-live="polite" data-selection-error="<?php echo isset( $field_errors['selection'] ) ? '1' : '0'; ?>" data-empty="<?php echo esc_attr__( 'Select a category or tag to process from the list.', 'taxonomy-tidy' ); ?>" data-published="<?php echo esc_attr__( 'Published posts', 'taxonomy-tidy' ); ?>" data-total="<?php echo esc_attr__( 'Total relationships', 'taxonomy-tidy' ); ?>" data-more="<?php /* translators: %d: number of additional selected terms. */ echo esc_attr__( '%d more', 'taxonomy-tidy' ); ?>">
						<?php if ( ! isset( $field_errors['selection'] ) || array() !== $items ) : ?>
							<?php $this->render_selected_targets( $taxonomy, $items ); ?>
						<?php endif; ?>
					</div>
					<?php if ( Action::MERGE->value !== $action ) : ?>
						<?php $this->render_field_errors( $field_errors, 'selection', 'taxonomy-tidy-selection-error' ); ?>
					<?php endif; ?>
				</section>

				<section class="taxonomy-tidy-process-group" aria-labelledby="taxonomy-tidy-operation-heading">
					<h3 id="taxonomy-tidy-operation-heading"><?php echo esc_html__( 'Action method', 'taxonomy-tidy' ); ?></h3>
					<fieldset class="taxonomy-tidy-operation-choices" <?php echo isset( $field_errors['operation'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'operation', 'taxonomy-tidy-operation-error' ) ) . '"' : ''; ?>>
						<legend class="screen-reader-text"><?php echo esc_html__( 'Action method', 'taxonomy-tidy' ); ?></legend>
						<?php $this->render_action_choice( Action::RENAME, __( 'Rename', 'taxonomy-tidy' ), __( 'Change the name and, if needed, the slug.', 'taxonomy-tidy' ), $action, 'operation' === $focus ); ?>
						<?php $this->render_action_choice( Action::MERGE, __( 'Merge', 'taxonomy-tidy' ), __( 'Move published-post assignments into an existing term.', 'taxonomy-tidy' ), $action, false ); ?>
						<?php $this->render_action_choice( Action::DELETE, __( 'Delete', 'taxonomy-tidy' ), __( 'Make a globally unused term a deletion target.', 'taxonomy-tidy' ), $action, false ); ?>
						<?php $this->render_field_errors( $field_errors, 'operation', 'taxonomy-tidy-operation-error' ); ?>
					</fieldset>
				</section>

				<section class="taxonomy-tidy-process-group taxonomy-tidy-changes-section" aria-labelledby="taxonomy-tidy-change-heading" <?php echo null === Action::tryFrom( $action ) ? 'hidden' : ''; ?>>
					<h3 id="taxonomy-tidy-change-heading"><?php echo esc_html__( 'Changes', 'taxonomy-tidy' ); ?></h3>
					<div class="taxonomy-tidy-action-fields" data-action-fields="rename" <?php echo Action::RENAME->value !== $action ? 'hidden' : ''; ?>>
						<div class="taxonomy-tidy-related-fields">
							<div class="taxonomy-tidy-field-group taxonomy-tidy-readonly-field"><span class="taxonomy-tidy-field-label"><?php echo esc_html__( 'Current name', 'taxonomy-tidy' ); ?></span><span class="taxonomy-tidy-field-display taxonomy-tidy-current-name" data-fallback="<?php echo esc_attr__( 'Select one term.', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $this->single_selected_value( $items, 'name' ) ); ?></span></div>
							<div class="taxonomy-tidy-field-group"><label class="taxonomy-tidy-field-label" for="taxonomy-tidy-new-name"><?php echo esc_html__( 'New name', 'taxonomy-tidy' ); ?></label><input class="taxonomy-tidy-field-control" id="taxonomy-tidy-new-name" type="text" name="new_name" value="<?php echo esc_attr( (string) ( $input['new_name'] ?? '' ) ); ?>" <?php echo isset( $field_errors['new_name'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'new_name', 'taxonomy-tidy-new-name-error' ) ) . '"' : ''; ?> <?php echo 'new_name' === $focus ? 'data-error-focus="true"' : ''; ?>><?php $this->render_field_errors( $field_errors, 'new_name', 'taxonomy-tidy-new-name-error' ); ?></div>
						</div>
						<div class="taxonomy-tidy-related-fields">
							<div class="taxonomy-tidy-field-group taxonomy-tidy-readonly-field"><span class="taxonomy-tidy-field-label"><?php echo esc_html__( 'Current slug', 'taxonomy-tidy' ); ?></span><span class="taxonomy-tidy-field-display taxonomy-tidy-current-slug" data-fallback="<?php echo esc_attr__( 'Select one term.', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $this->single_selected_value( $items, 'slug' ) ); ?></span></div>
							<div class="taxonomy-tidy-field-group"><label class="taxonomy-tidy-field-label" for="taxonomy-tidy-new-slug"><?php echo esc_html__( 'New slug (optional)', 'taxonomy-tidy' ); ?></label><input class="taxonomy-tidy-field-control" id="taxonomy-tidy-new-slug" type="text" name="new_slug" value="<?php echo esc_attr( (string) ( $input['new_slug'] ?? '' ) ); ?>" aria-describedby="taxonomy-tidy-new-slug-help<?php echo isset( $field_errors['new_slug'] ) ? ' ' . esc_attr( $this->field_error_ids( $field_errors, 'new_slug', 'taxonomy-tidy-new-slug-error' ) ) : ''; ?>" <?php echo isset( $field_errors['new_slug'] ) ? 'aria-invalid="true"' : ''; ?> <?php echo 'new_slug' === $focus ? 'data-error-focus="true"' : ''; ?>><p id="taxonomy-tidy-new-slug-help" class="description taxonomy-tidy-field-help"><?php echo esc_html__( 'Leave blank to keep the current slug.', 'taxonomy-tidy' ); ?></p><?php $this->render_field_errors( $field_errors, 'new_slug', 'taxonomy-tidy-new-slug-error' ); ?></div>
						</div>
					</div>

					<div class="taxonomy-tidy-action-fields" data-action-fields="merge" <?php echo Action::MERGE->value !== $action ? 'hidden' : ''; ?>>
						<div id="taxonomy-tidy-merge-source-group" class="taxonomy-tidy-field-group taxonomy-tidy-readonly-field" <?php echo Action::MERGE->value === $action && isset( $field_errors['selection'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'selection', 'taxonomy-tidy-merge-source-error' ) ) . '"' : ''; ?>>
							<span class="taxonomy-tidy-field-label"><?php echo esc_html__( 'Merge sources', 'taxonomy-tidy' ); ?></span>
							<span class="taxonomy-tidy-field-display taxonomy-tidy-merge-sources" data-empty="<?php echo esc_attr__( 'No terms selected', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $this->selected_name_summary( $items ) ); ?></span>
							<div class="taxonomy-tidy-field-help taxonomy-tidy-merge-outcome" data-heading="<?php echo esc_attr__( 'Source term outcome after merge', 'taxonomy-tidy' ); ?>" data-delete="<?php echo esc_attr__( 'Planned for deletion', 'taxonomy-tidy' ); ?>" data-retain="<?php echo esc_attr__( 'Planned for retention', 'taxonomy-tidy' ); ?>"><?php $this->render_merge_outcomes( $taxonomy, $items ); ?></div>
							<?php if ( Action::MERGE->value === $action ) : ?>
								<?php $this->render_field_errors( $field_errors, 'selection', 'taxonomy-tidy-merge-source-error' ); ?>
							<?php endif; ?>
						</div>
						<div id="taxonomy-tidy-merge-destination-group" class="taxonomy-tidy-field-group">
							<label class="taxonomy-tidy-field-label" for="taxonomy-tidy-destination"><?php echo esc_html__( 'Merge destination', 'taxonomy-tidy' ); ?></label>
							<input class="taxonomy-tidy-field-control" id="taxonomy-tidy-destination" type="search" name="destination" list="taxonomy-tidy-destinations" value="<?php echo esc_attr( (string) ( $input['destination'] ?? '' ) ); ?>" autocomplete="off" aria-describedby="taxonomy-tidy-destination-help taxonomy-tidy-destination-selection-notice<?php echo isset( $field_errors['destination'] ) ? ' ' . esc_attr( $this->field_error_ids( $field_errors, 'destination', 'taxonomy-tidy-destination-error' ) ) : ''; ?>" <?php echo isset( $field_errors['destination'] ) ? 'aria-invalid="true"' : ''; ?> <?php echo 'destination' === $focus ? 'data-error-focus="true"' : ''; ?>>
							<datalist id="taxonomy-tidy-destinations"><?php $this->render_destinations( $taxonomy, $selected ); ?></datalist>
							<template class="taxonomy-tidy-destination-options"><?php $this->render_destinations( $taxonomy, array() ); ?></template>
							<p id="taxonomy-tidy-destination-help" class="description taxonomy-tidy-field-help"><?php echo esc_html__( 'Search and select an existing term in the same taxonomy.', 'taxonomy-tidy' ); ?></p>
							<p id="taxonomy-tidy-destination-selection-notice" class="description taxonomy-tidy-field-help" role="status" <?php echo $destination_removed ? '' : 'hidden'; ?>><?php echo esc_html__( '選択していた統合先が統合元に含まれたため、選択を解除しました。', 'taxonomy-tidy' ); ?></p>
							<?php $this->render_field_errors( $field_errors, 'destination', 'taxonomy-tidy-destination-error' ); ?>
						</div>
					</div>

					<div class="taxonomy-tidy-action-fields" data-action-fields="delete" <?php echo Action::DELETE->value !== $action ? 'hidden' : ''; ?>>
						<div class="taxonomy-tidy-field-group taxonomy-tidy-readonly-field" <?php echo isset( $field_errors['delete'] ) ? 'aria-invalid="true" aria-describedby="' . esc_attr( $this->field_error_ids( $field_errors, 'delete', 'taxonomy-tidy-delete-error' ) ) . '"' : ''; ?>>
							<span class="taxonomy-tidy-field-label"><?php echo esc_html__( 'Deletion targets', 'taxonomy-tidy' ); ?></span>
							<div class="taxonomy-tidy-field-display taxonomy-tidy-delete-targets" data-empty="<?php echo esc_attr__( 'No deletion targets selected.', 'taxonomy-tidy' ); ?>" data-published="<?php echo esc_attr__( 'Published posts', 'taxonomy-tidy' ); ?>" data-total="<?php echo esc_attr__( 'Total relationships', 'taxonomy-tidy' ); ?>" data-available="<?php echo esc_attr__( 'Deletion available', 'taxonomy-tidy' ); ?>" data-unavailable="<?php echo esc_attr__( 'Deletion unavailable', 'taxonomy-tidy' ); ?>"><?php $this->render_delete_targets( $taxonomy, $items ); ?></div>
							<?php $this->render_field_errors( $field_errors, 'delete', 'taxonomy-tidy-delete-error' ); ?>
						</div>
					</div>
				</section>

				<section class="taxonomy-tidy-process-group taxonomy-tidy-validation" aria-labelledby="taxonomy-tidy-validation-heading" data-server-errors="<?php echo isset( $field_errors['plan'] ) ? '1' : '0'; ?>" data-error="<?php echo esc_attr__( 'Error', 'taxonomy-tidy' ); ?>" data-warning="<?php echo esc_attr__( 'Warning', 'taxonomy-tidy' ); ?>" data-information="<?php echo esc_attr__( 'Information', 'taxonomy-tidy' ); ?>" data-child-warning="<?php echo esc_attr__( 'The source has child categories and will be retained.', 'taxonomy-tidy' ); ?>" data-excluded-warning="<?php echo esc_attr__( 'The source is used outside published posts and will be retained.', 'taxonomy-tidy' ); ?>" data-impact="<?php /* translators: %d: published-post relationship count. */ echo esc_attr__( '%d published-post relationships are currently associated with the selection.', 'taxonomy-tidy' ); ?>" <?php echo ! isset( $field_errors['plan'] ) && array() === $items ? 'hidden' : ''; ?>>
					<h3 id="taxonomy-tidy-validation-heading"><?php echo esc_html__( 'Notices and validation results', 'taxonomy-tidy' ); ?></h3>
					<div class="taxonomy-tidy-validation-messages" aria-live="polite">
					<?php if ( isset( $field_errors['plan'] ) ) : ?>
						<?php $this->render_field_errors( $field_errors, 'plan', 'taxonomy-tidy-plan-error' ); ?>
					<?php elseif ( array() !== $items ) : ?>
						<?php $this->render_selection_messages( $taxonomy, $action, $items ); ?>
					<?php endif; ?>
					</div>
				</section>

				<div class="taxonomy-tidy-process-actions">
					<button type="submit" class="button button-primary" name="plan_command" value="add"><?php echo esc_html__( '計画に追加', 'taxonomy-tidy' ); ?></button>
				</div>
			</div>
		</details>
		<?php
	}

	/**
	 * Renders the saved plan and an optional immutable preview.
	 *
	 * @param Taxonomy                  $taxonomy Current taxonomy.
	 * @param array<string, mixed>|null $operation Stored operation.
	 * @param array                     $plan Normalized plan.
	 */
	private function render_plan( Taxonomy $taxonomy, ?array $operation, array $plan ): void {
		if ( array() === $plan || null === $operation ) {
			return;
		}
		?>
		<section class="taxonomy-tidy-plan" aria-labelledby="taxonomy-tidy-plan-heading" aria-live="polite">
			<h2 id="taxonomy-tidy-plan-heading"><?php echo esc_html__( 'Operation plan', 'taxonomy-tidy' ); ?></h2>
			<p><?php echo esc_html__( 'To edit an item, remove it and add a corrected process.', 'taxonomy-tidy' ); ?></p>
			<div class="taxonomy-tidy-plan-items">
			<?php foreach ( $plan as $index => $item ) : ?>
				<article class="taxonomy-tidy-plan-item">
					<h3><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></h3>
					<p><strong><?php echo esc_html__( 'Targets', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( $this->plan_source_names( $taxonomy, $item ) ); ?></p>
					<p><strong><?php echo esc_html__( 'Planned change', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( $this->planned_change( $taxonomy, $item ) ); ?></p>
					<p><strong><?php echo esc_html__( 'Current estimated scope', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( $this->estimated_scope( $taxonomy, $item ) ); ?></p>
					<?php $draft_warnings = $this->draft_warnings( $taxonomy, $item ); ?>
					<?php if ( '' !== $draft_warnings ) : ?>
						<p class="taxonomy-tidy-message taxonomy-tidy-message--warning"><strong><?php echo esc_html__( 'Warning', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( $draft_warnings ); ?></p>
					<?php endif; ?>
					<?php if ( Status::DRAFT->value === $operation['status'] ) : ?>
						<button type="submit" class="button-link-delete" name="remove_index" value="<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Remove from plan', 'taxonomy-tidy' ); ?></button>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
			</div>

			<?php if ( Status::DRAFT->value === $operation['status'] ) : ?>
				<p class="taxonomy-tidy-preview-action"><button type="submit" class="button button-primary button-hero" name="plan_command" value="preview"><?php echo esc_html__( 'Review changes', 'taxonomy-tidy' ); ?></button></p>
			<?php elseif ( Status::PREVIEWED->value === $operation['status'] ) : ?>
				<p class="taxonomy-tidy-preview-action"><button type="button" class="button taxonomy-tidy-reopen-preview"><?php echo esc_html__( 'Review changes', 'taxonomy-tidy' ); ?></button></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders the execution progress and continuation control.
	 *
	 * @param array<string, mixed> $operation Execution record.
	 */
	private function render_execution( array $operation ): void {
		$progress = is_array( $operation['progress'] ?? null ) ? $operation['progress'] : array();
		?>
		<section class="taxonomy-tidy-preview" aria-labelledby="taxonomy-tidy-progress-heading" aria-live="polite">
			<h2 id="taxonomy-tidy-progress-heading"><?php echo esc_html__( 'Execution progress', 'taxonomy-tidy' ); ?></h2>
			<p><?php echo esc_html( $this->execution_status_label( (string) $operation['status'] ) ); ?></p>
			<ul>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Total items: %d', 'taxonomy-tidy' ), (int) ( $progress['total'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Completed items: %d', 'taxonomy-tidy' ), (int) ( $progress['completed'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Pending items: %d', 'taxonomy-tidy' ), (int) ( $progress['pending'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Failed items: %d', 'taxonomy-tidy' ), (int) ( $progress['failed'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: item count. */ echo esc_html( sprintf( __( 'Skipped items: %d', 'taxonomy-tidy' ), (int) ( $progress['skipped'] ?? 0 ) ) ); ?></li>
				<li><?php /* translators: %d: retained source-term count. */ echo esc_html( sprintf( __( 'Retained source terms: %d', 'taxonomy-tidy' ), (int) ( $progress['skipped'] ?? 0 ) ) ); ?></li>
			</ul>
			<?php if ( 0 < (int) ( $progress['skipped'] ?? 0 ) ) : ?>
				<p class="taxonomy-tidy-message taxonomy-tidy-message--warning"><strong><?php echo esc_html__( 'Warning', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html__( 'Some source terms were retained because they were not safe to delete.', 'taxonomy-tidy' ); ?></p>
			<?php endif; ?>
			<?php if ( 0 < (int) ( $progress['failed'] ?? 0 ) ) : ?>
				<p class="taxonomy-tidy-message taxonomy-tidy-message--error"><strong><?php echo esc_html__( 'Error', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html__( 'Some items could not be processed. No failed item is reported as completed.', 'taxonomy-tidy' ); ?></p>
			<?php endif; ?>
			<?php if ( Status::RUNNING->value === $operation['status'] ) : ?>
				<input type="hidden" name="operation_id" value="<?php echo esc_attr( (string) $operation['id'] ); ?>">
				<p><button type="submit" class="button button-primary" name="plan_command" value="continue"><?php echo esc_html__( 'Continue next batch', 'taxonomy-tidy' ); ?></button></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Returns a translated execution status.
	 *
	 * @param string $status Persisted operation status.
	 * @return string
	 */
	private function execution_status_label( string $status ): string {
		return match ( $status ) {
			Status::RUNNING->value => __( 'Running; more items remain.', 'taxonomy-tidy' ),
			Status::COMPLETED->value => __( 'All changes completed.', 'taxonomy-tidy' ),
			Status::PARTIAL_FAILED->value => __( 'Some items failed. The operation is partially complete.', 'taxonomy-tidy' ),
			default => __( 'The operation failed without completing changes.', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Renders the persisted preview summary and per-operation effects.
	 *
	 * @param array<string, mixed> $operation Previewed operation.
	 * @param bool                 $auto_open Whether this request just created the preview.
	 */
	private function render_preview( array $operation, bool $auto_open ): void {
		$preview = $operation['requested_data']['preview'] ?? null;
		if ( ! is_array( $preview ) ) {
			return;
		}
		?>
		<div class="taxonomy-tidy-modal" data-auto-open="<?php echo $auto_open ? '1' : '0'; ?>" hidden>
			<div class="taxonomy-tidy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="taxonomy-tidy-preview-heading" tabindex="-1">
			<header class="taxonomy-tidy-modal__header"><h2 id="taxonomy-tidy-preview-heading"><?php echo esc_html__( 'Change preview', 'taxonomy-tidy' ); ?></h2><button type="button" class="taxonomy-tidy-modal__close" aria-label="<?php echo esc_attr__( '閉じる', 'taxonomy-tidy' ); ?>">&times;</button></header>
			<div class="taxonomy-tidy-modal__body">
			<?php if ( false === ( $operation['preview_current'] ?? true ) ) : ?>
				<p class="taxonomy-tidy-message taxonomy-tidy-message--error" role="alert"><strong><?php echo esc_html__( 'Error', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( ErrorMessages::label( PlanErrorCode::STALE_PREVIEW ) ); ?></p>
			<?php endif; ?>
			<?php foreach ( (array) ( $preview['items'] ?? array() ) as $index => $item ) : ?>
				<article class="taxonomy-tidy-preview-item">
					<h3><?php echo esc_html( $this->action_label( (string) $item['action'] ) ); ?></h3>
					<p><strong><?php echo esc_html__( 'Targets', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( implode( '、', array_column( $item['sources'], 'name' ) ) ); ?></p>
					<?php if ( Action::DELETE->value !== $item['action'] ) : ?>
						<p><strong><?php echo esc_html__( '変更後', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( Action::MERGE->value === $item['action'] ? (string) ( $item['destination']['name'] ?? '' ) : (string) $item['new_name'] ); ?></p>
					<?php endif; ?>
					<?php if ( Action::RENAME->value === $item['action'] && null !== $item['new_slug'] && (string) ( $item['sources'][0]['slug'] ?? '' ) !== (string) $item['new_slug'] ) : ?>
						<p><strong><?php echo esc_html__( '変更後のslug', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( (string) $item['new_slug'] ); ?></p>
					<?php endif; ?>
					<p><?php /* translators: %d: number of affected published posts. */ echo esc_html( sprintf( __( '影響を受ける公開済み投稿：%d件', 'taxonomy-tidy' ), count( $item['affected_posts'] ) ) ); ?></p>
					<?php if ( Action::MERGE->value === $item['action'] ) : ?>
						<?php foreach ( $item['sources'] as $source ) : ?>
							<p><?php echo esc_html( (string) $source['name'] ); ?>：<?php echo esc_html( $source['delete_source'] ? __( '処理後に削除', 'taxonomy-tidy' ) : __( '削除せず保持', 'taxonomy-tidy' ) ); ?></p>
							<?php if ( ! $source['delete_source'] ) : ?>
								<p><?php echo esc_html__( '理由', 'taxonomy-tidy' ); ?>：<?php echo esc_html( implode( ' ', array_map( array( $this, 'reason_label' ), $source['reasons'] ) ) ); ?></p>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php $blocking_warnings = array_diff( $item['warnings'], array( 'has_child_categories', 'used_by_excluded_objects' ) ); ?>
					<?php if ( array() !== $blocking_warnings ) : ?>
						<p class="taxonomy-tidy-message taxonomy-tidy-message--warning"><strong><?php echo esc_html__( 'Warning', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( implode( ' ', array_map( array( $this, 'warning_label' ), $blocking_warnings ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( array() !== $item['affected_posts'] ) : ?>
						<details class="taxonomy-tidy-preview-posts" data-item="<?php echo esc_attr( (string) $index ); ?>" data-operation="<?php echo esc_attr( (string) $operation['id'] ); ?>" data-taxonomy="<?php echo esc_attr( (string) $operation['taxonomy'] ); ?>" data-error="<?php echo esc_attr__( '対象投稿を取得できませんでした。', 'taxonomy-tidy' ); ?>"><summary><?php /* translators: %d: number of affected published posts. */ echo esc_html( sprintf( __( '対象投稿を確認（%d件）', 'taxonomy-tidy' ), count( $item['affected_posts'] ) ) ); ?></summary><ul></ul></details>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
			</div>
			<footer class="taxonomy-tidy-modal__footer"><button type="button" class="button taxonomy-tidy-modal__cancel"><?php echo esc_html__( 'キャンセル', 'taxonomy-tidy' ); ?></button><button type="submit" form="taxonomy-tidy-planning-form" class="button button-primary taxonomy-tidy-modal__run" name="plan_command" value="run" <?php disabled( false === ( $operation['preview_current'] ?? true ) ); ?>><?php echo esc_html__( 'Execute', 'taxonomy-tidy' ); ?></button><input type="hidden" name="operation_id" form="taxonomy-tidy-planning-form" value="<?php echo esc_attr( (string) $operation['id'] ); ?>"></footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a radio choice and description.
	 *
	 * @param Action $action      Action value.
	 * @param string $label       Translated label.
	 * @param string $description Translated description.
	 * @param string $current     Current action.
	 * @param bool   $autofocus   Whether JavaScript should focus this input.
	 */
	private function render_action_choice( Action $action, string $label, string $description, string $current, bool $autofocus ): void {
		$id = 'taxonomy-tidy-action-' . $action->value;
		?>
		<label class="taxonomy-tidy-operation-choice" for="<?php echo esc_attr( $id ); ?>">
			<input class="taxonomy-tidy-operation-choice__control" id="<?php echo esc_attr( $id ); ?>" type="radio" name="operation_action" value="<?php echo esc_attr( $action->value ); ?>" <?php checked( $current, $action->value ); ?> <?php echo $autofocus ? 'data-error-focus="true"' : ''; ?>>
			<span class="taxonomy-tidy-operation-choice__text"><strong class="taxonomy-tidy-operation-choice__title"><?php echo esc_html( $label ); ?></strong><span class="description"><?php echo esc_html( $description ); ?></span></span>
		</label>
		<?php
	}

	/**
	 * Renders searchable destination values.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param array    $excluded Selected source term IDs.
	 */
	private function render_destinations( Taxonomy $taxonomy, array $excluded ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy->value,
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 0,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && ! in_array( $term->term_id, $excluded, true ) ) {
				$value = sprintf( '%d:%d — %s', $term->term_id, $term->term_taxonomy_id, $term->name );
				?>
				<option value="<?php echo esc_attr( $value ); ?>" data-term-key="<?php echo esc_attr( (string) $term->term_id ); ?>" data-term-taxonomy-key="<?php echo esc_attr( (string) $term->term_taxonomy_id ); ?>"><?php echo esc_html( $term->slug ); ?></option>
				<?php
			}
		}
	}

	/**
	 * Checks a destination value against selected sources by stable term ID.
	 *
	 * @param string $destination Submitted datalist value.
	 * @param array  $selected    Selected source term IDs.
	 */
	private function destination_is_selected_source( string $destination, array $selected ): bool {
		return 1 === preg_match( '/^(\d+):/', $destination, $matches ) && in_array( (int) $matches[1], $selected, true );
	}

	/**
	 * Returns selected inventory rows from the current page.
	 *
	 * @param array<string, mixed> $inventory Current inventory page.
	 * @param array                $selected  Selected term IDs.
	 * @return list<array<string, mixed>>
	 */
	private function selected_items( array $inventory, array $selected ): array {
		$items = array();
		foreach ( $inventory['items'] as $term ) {
			if ( in_array( (int) $term['term_id'], $selected, true ) ) {
				$items[] = $term;
			}
		}
		return $items;
	}

	/**
	 * Renders representative selected terms and their current counts.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $items    Selected inventory rows.
	 */
	private function render_selected_targets( Taxonomy $taxonomy, array $items ): void {
		if ( array() === $items ) {
			?>
			<p class="taxonomy-tidy-selected-empty"><?php echo esc_html__( 'Select a category or tag to process from the list.', 'taxonomy-tidy' ); ?></p>
			<?php
			return;
		}
		$type = Taxonomy::CATEGORY === $taxonomy ? __( 'Category', 'taxonomy-tidy' ) : __( 'Tag', 'taxonomy-tidy' );
		?>
		<ul class="taxonomy-tidy-selected-list">
		<?php foreach ( array_slice( $items, 0, 5 ) as $item ) : ?>
			<li><strong><?php echo esc_html( (string) $item['name'] ); ?></strong><span><?php echo esc_html( $type ); ?> · <?php echo esc_html__( 'Published posts', 'taxonomy-tidy' ); ?>: <?php echo esc_html( number_format_i18n( (int) $item['published_post_count'] ) ); ?> · <?php echo esc_html__( 'Total relationships', 'taxonomy-tidy' ); ?>: <?php echo esc_html( number_format_i18n( (int) $item['total_relationship_count'] ) ); ?></span></li>
		<?php endforeach; ?>
		<?php if ( 5 < count( $items ) ) : ?>
			<li><?php /* translators: %d: number of additional selected terms. */ echo esc_html( sprintf( __( '%d more', 'taxonomy-tidy' ), count( $items ) - 5 ) ); ?></li>
		<?php endif; ?>
		</ul>
		<?php
	}

	/**
	 * Returns one selected term field or the translated fallback.
	 *
	 * @param list<array<string, mixed>> $items Selected inventory rows.
	 * @param string                     $key   Inventory field name.
	 * @return string
	 */
	private function single_selected_value( array $items, string $key ): string {
		return 1 === count( $items ) ? (string) $items[0][ $key ] : __( 'Select one term.', 'taxonomy-tidy' );
	}

	/**
	 * Returns representative selected names.
	 *
	 * @param list<array<string, mixed>> $items Selected inventory rows.
	 * @return string
	 */
	private function selected_name_summary( array $items ): string {
		if ( array() === $items ) {
			return __( 'No terms selected', 'taxonomy-tidy' );
		}
		$names = array_map(
			static fn( array $item ): string => (string) $item['name'],
			array_slice( $items, 0, 5 )
		);
		if ( 5 < count( $items ) ) {
			/* translators: %d: number of additional selected terms. */
			$names[] = sprintf( __( '%d more', 'taxonomy-tidy' ), count( $items ) - 5 );
		}
		return implode( ', ', $names );
	}

	/**
	 * Renders whether each merge source is expected to be deleted or retained.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $items    Selected inventory rows.
	 */
	private function render_merge_outcomes( Taxonomy $taxonomy, array $items ): void {
		if ( array() === $items ) {
			return;
		}
		?>
		<p class="taxonomy-tidy-field-label"><?php echo esc_html__( 'Source term outcome after merge', 'taxonomy-tidy' ); ?></p>
		<ul class="taxonomy-tidy-outcome-list">
		<?php foreach ( $items as $item ) : ?>
			<?php
			$has_children = Taxonomy::CATEGORY === $taxonomy && get_terms(
				array(
					'taxonomy'   => $taxonomy->value,
					'parent'     => (int) $item['term_id'],
					'fields'     => 'ids',
					'hide_empty' => false,
				)
			);
			$retained     = (int) $item['total_relationship_count'] > (int) $item['published_post_count'] || ( is_array( $has_children ) && array() !== $has_children );
			$reasons      = array();
			if ( (int) $item['total_relationship_count'] > (int) $item['published_post_count'] ) {
				$reasons[] = $this->warning_label( 'used_by_excluded_objects' );
			}
			if ( is_array( $has_children ) && array() !== $has_children ) {
				$reasons[] = $this->warning_label( 'has_child_categories' );
			}
			?>
			<li><?php echo esc_html( (string) $item['name'] ); ?> — <?php echo esc_html( $retained ? __( 'Planned for retention', 'taxonomy-tidy' ) : __( 'Planned for deletion', 'taxonomy-tidy' ) ); ?><?php echo array() !== $reasons ? ' — ' . esc_html( implode( ' ', $reasons ) ) : ''; ?></li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders current selection warnings, errors, and impact information.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param string                     $action   Selected action value.
	 * @param list<array<string, mixed>> $items    Selected inventory rows.
	 */
	private function render_selection_messages( Taxonomy $taxonomy, string $action, array $items ): void {
		$published = array_sum( array_map( static fn( array $item ): int => (int) $item['published_post_count'], $items ) );
		if ( Action::MERGE->value === $action ) {
			foreach ( $items as $item ) {
				if ( (int) $item['total_relationship_count'] > (int) $item['published_post_count'] ) {
					$this->render_status_message( 'warning', __( 'Warning', 'taxonomy-tidy' ), $this->warning_label( 'used_by_excluded_objects' ) );
				}
				if ( Taxonomy::CATEGORY === $taxonomy && $this->has_children( $taxonomy, (int) $item['term_id'] ) ) {
					$this->render_status_message( 'warning', __( 'Warning', 'taxonomy-tidy' ), $this->warning_label( 'has_child_categories' ) );
				}
			}
		}
		$this->render_status_message(
			'info',
			__( 'Information', 'taxonomy-tidy' ),
			sprintf(
				/* translators: %d: published-post relationship count. */
				__( '%d published-post relationships are currently associated with the selection.', 'taxonomy-tidy' ),
				$published
			)
		);
	}

	/**
	 * Renders one labelled validation-status message.
	 *
	 * @param string $type    Message type: error, warning, or info.
	 * @param string $heading Translated status heading.
	 * @param string $message Translated message text.
	 */
	private function render_status_message( string $type, string $heading, string $message ): void {
		$icon = 'info' === $type ? 'info-outline' : 'warning';
		?>
		<p class="taxonomy-tidy-message taxonomy-tidy-message--<?php echo esc_attr( $type ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $heading ); ?>:</strong> <?php echo esc_html( $message ); ?></p>
		<?php
	}

	/**
	 * Reports whether a category currently has child categories.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param int      $term_id  Parent term ID.
	 */
	private function has_children( Taxonomy $taxonomy, int $term_id ): bool {
		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy->value,
				'parent'     => $term_id,
				'fields'     => 'ids',
				'hide_empty' => false,
			)
		);
		return is_array( $children ) && array() !== $children;
	}

	/**
	 * Renders deletion eligibility and counts for selected targets.
	 *
	 * @param Taxonomy                   $taxonomy Current taxonomy.
	 * @param list<array<string, mixed>> $items    Selected inventory rows.
	 */
	private function render_delete_targets( Taxonomy $taxonomy, array $items ): void {
		if ( array() === $items ) {
			?>
			<p><?php echo esc_html__( 'No deletion targets selected.', 'taxonomy-tidy' ); ?></p>
			<?php
			return;
		}
		$default_category = (int) get_option( 'default_category' );
		?>
		<ul class="taxonomy-tidy-delete-list">
		<?php foreach ( $items as $item ) : ?>
			<?php $available = 0 === (int) $item['total_relationship_count'] && ! ( Taxonomy::CATEGORY === $taxonomy && $default_category === (int) $item['term_id'] ); ?>
			<li><strong><?php echo esc_html( (string) $item['name'] ); ?></strong><span><?php echo esc_html__( 'Published posts', 'taxonomy-tidy' ); ?>: <?php echo esc_html( number_format_i18n( (int) $item['published_post_count'] ) ); ?> · <?php echo esc_html__( 'Total relationships', 'taxonomy-tidy' ); ?>: <?php echo esc_html( number_format_i18n( (int) $item['total_relationship_count'] ) ); ?> · <?php echo esc_html( $available ? __( 'Deletion available', 'taxonomy-tidy' ) : __( 'Deletion unavailable', 'taxonomy-tidy' ) ); ?></span></li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Returns the selected-term summary.
	 *
	 * @param int $count Selected count.
	 * @return string
	 */
	private function selected_count_label( int $count ): string {
		/* translators: %d: number of selected terms. */
		return 0 === $count ? __( 'No terms selected', 'taxonomy-tidy' ) : sprintf( __( '%d terms selected', 'taxonomy-tidy' ), $count );
	}

	/**
	 * Returns the configured-process summary.
	 *
	 * @param int $count Plan item count.
	 * @return string
	 */
	private function plan_count_label( int $count ): string {
		/* translators: %d: number of configured plan processes. */
		return sprintf( __( '%d processes configured', 'taxonomy-tidy' ), $count );
	}

	/**
	 * Returns the current names of all source terms.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function plan_source_names( Taxonomy $taxonomy, array $item ): string {
		$names = array();
		foreach ( $item['sources'] as $source ) {
			$term    = get_term( (int) $source['term_id'], $taxonomy->value );
			$names[] = $term instanceof WP_Term ? $term->name : '#' . (int) $source['term_id'];
		}
		return implode( ', ', $names );
	}

	/**
	 * Returns the planned destination or new value.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function planned_change( Taxonomy $taxonomy, array $item ): string {
		if ( Action::RENAME->value === $item['action'] ) {
			return (string) $item['new_name'] . ( null !== $item['new_slug'] ? ' / ' . $item['new_slug'] : '' );
		}
		if ( Action::MERGE->value === $item['action'] ) {
			$term = get_term( (int) $item['destination']['term_id'], $taxonomy->value );
			return $term instanceof WP_Term ? $term->name : __( 'Missing destination', 'taxonomy-tidy' );
		}
		return __( 'Delete after preview validation', 'taxonomy-tidy' );
	}

	/**
	 * Returns current relationship estimates for a draft item.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function estimated_scope( Taxonomy $taxonomy, array $item ): string {
		$published = array();
		$total     = array();
		foreach ( $item['sources'] as $source ) {
			$relationships = get_objects_in_term( (int) $source['term_id'], $taxonomy->value );
			if ( ! is_array( $relationships ) ) {
				continue;
			}
			foreach ( $relationships as $object_id ) {
				$total[] = (int) $object_id;
				$post    = get_post( (int) $object_id );
				if ( $post instanceof \WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status ) {
					$published[] = $post->ID;
				}
			}
		}
		/* translators: 1: published post count, 2: total relationship count. */
		return sprintf( __( '%1$d published posts; %2$d total relationships', 'taxonomy-tidy' ), count( array_unique( $published ) ), count( array_unique( $total ) ) );
	}

	/**
	 * Returns current merge warnings for a draft item.
	 *
	 * @param Taxonomy             $taxonomy Current taxonomy.
	 * @param array<string, mixed> $item     Plan item.
	 * @return string
	 */
	private function draft_warnings( Taxonomy $taxonomy, array $item ): string {
		if ( Action::MERGE->value !== $item['action'] ) {
			return '';
		}
		$warnings = array();
		foreach ( $item['sources'] as $source ) {
			$relationships = get_objects_in_term( (int) $source['term_id'], $taxonomy->value );
			foreach ( is_array( $relationships ) ? $relationships : array() as $object_id ) {
				$post = get_post( (int) $object_id );
				if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
					$warnings['excluded'] = $this->warning_label( 'used_by_excluded_objects' );
				}
			}
			if ( Taxonomy::CATEGORY === $taxonomy ) {
				$children = get_terms(
					array(
						'taxonomy'   => $taxonomy->value,
						'parent'     => (int) $source['term_id'],
						'fields'     => 'ids',
						'hide_empty' => false,
					)
				);
				if ( is_array( $children ) && array() !== $children ) {
					$warnings['children'] = $this->warning_label( 'has_child_categories' );
				}
			}
		}
		return implode( ' ', $warnings );
	}

	/**
	 * Returns a translated action label.
	 *
	 * @param string $action Internal action value.
	 * @return string
	 */
	private function action_label( string $action ): string {
		return match ( $action ) {
			'rename' => __( 'Rename', 'taxonomy-tidy' ),
			'merge'  => __( 'Merge', 'taxonomy-tidy' ),
			default  => __( 'Delete', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Renders the request-level success or error summary.
	 *
	 * @param mixed $notice Notice code or null.
	 * @param array $errors Validation error codes.
	 */
	private function render_notice( mixed $notice, array $errors ): void {
		if ( array() !== $errors ) {
			?>
			<div class="notice notice-error inline taxonomy-tidy-error-summary" role="alert"><p><strong><?php echo esc_html__( 'The request could not be completed.', 'taxonomy-tidy' ); ?></strong></p></div>
			<?php
		} elseif ( is_string( $notice ) && '' !== $notice && 'preview_created' !== $notice ) {
			?>
			<div class="notice notice-success inline" role="status"><p><?php echo esc_html( $this->notice_label( $notice ) ); ?></p></div>
			<?php
		}
	}

	/**
	 * Returns a translated success notice.
	 *
	 * @param string $notice Notice code.
	 * @return string
	 */
	private function notice_label( string $notice ): string {
		return match ( $notice ) {
			'execution_updated' => __( 'Execution progress was updated.', 'taxonomy-tidy' ),
			'plan_item_added'   => __( '操作計画に追加しました。', 'taxonomy-tidy' ),
			'plan_item_removed' => __( 'The process was removed from the plan.', 'taxonomy-tidy' ),
			'preview_created'   => __( 'The preview was created without changing WordPress data.', 'taxonomy-tidy' ),
			'preview_invalidated' => __( 'The previous preview was invalidated. You can now revise the plan.', 'taxonomy-tidy' ),
			default             => __( 'The plan was discarded.', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Renders validation errors beside the section or input that can resolve them.
	 *
	 * @param array<string, list<string>> $field_errors Errors grouped by stable field key.
	 * @param string                      $field        Field key to render.
	 * @param string                      $id_prefix    Unique message ID prefix.
	 */
	private function render_field_errors( array $field_errors, string $field, string $id_prefix ): void {
		foreach ( $field_errors[ $field ] ?? array() as $index => $error ) {
			?>
			<p id="<?php echo esc_attr( $id_prefix . '-' . $index ); ?>" class="taxonomy-tidy-field-error" tabindex="-1" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong><?php echo esc_html__( 'Error', 'taxonomy-tidy' ); ?>:</strong> <?php echo esc_html( ErrorMessages::label( $error ) ); ?></p>
			<?php
		}
	}

	/**
	 * Returns every generated error ID for an aria-describedby relationship.
	 *
	 * @param array<string, list<string>> $field_errors Errors grouped by stable field key.
	 * @param string                      $field Field key.
	 * @param string                      $id_prefix Error element ID prefix.
	 * @return string
	 */
	private function field_error_ids( array $field_errors, string $field, string $id_prefix ): string {
		$ids = array();
		foreach ( array_keys( $field_errors[ $field ] ?? array() ) as $index ) {
			$ids[] = $id_prefix . '-' . $index;
		}
		return implode( ' ', $ids );
	}

	/**
	 * Selects the first input that can correct a validation error.
	 *
	 * @param array<string, list<string>> $field_errors Errors grouped by stable field key.
	 * @return string
	 */
	private function error_focus( array $field_errors ): string {
		foreach ( array( 'selection', 'operation', 'new_name', 'new_slug', 'destination', 'delete', 'plan' ) as $field ) {
			if ( isset( $field_errors[ $field ] ) ) {
				return $field;
			}
		}
		return '';
	}

	/**
	 * Returns a translated preview warning.
	 *
	 * @param string $warning Warning code.
	 * @return string
	 */
	private function warning_label( string $warning ): string {
		return 'has_child_categories' === $warning ? __( 'The source has child categories and will be retained.', 'taxonomy-tidy' ) : __( 'The source is used outside published posts and will be retained.', 'taxonomy-tidy' );
	}

	/**
	 * Returns a translated deletion or retention reason.
	 *
	 * @param string $reason Preview reason code.
	 * @return string
	 */
	private function reason_label( string $reason ): string {
		return match ( $reason ) {
			'globally_unused'                  => __( 'It has no relationships to any WordPress object.', 'taxonomy-tidy' ),
			'safe_after_published_reassignment' => __( 'It can be removed after its published-post assignments are moved.', 'taxonomy-tidy' ),
			'rename_preserves_term'             => __( 'Rename preserves the term and all relationships.', 'taxonomy-tidy' ),
			'has_child_categories'              => __( 'It has child categories.', 'taxonomy-tidy' ),
			default                             => __( 'It is used by excluded objects.', 'taxonomy-tidy' ),
		};
	}

	/**
	 * Returns a translated relationship-usage label.
	 *
	 * @param string $usage Internal usage value.
	 * @return string
	 */
	private function usage_label( string $usage ): string {
		return match ( $usage ) {
			'published'     => __( 'Used by published posts', 'taxonomy-tidy' ),
			'excluded_only' => __( 'Used outside published posts', 'taxonomy-tidy' ),
			default         => __( 'Globally unused', 'taxonomy-tidy' ),
		};
	}
}
