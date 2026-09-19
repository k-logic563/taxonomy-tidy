<?php
/**
 * Taxonomy Tidy admin page.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Application\Planning\PlanService;
use TaxonomyTidy\Application\Planning\PlanWorkflow;
use TaxonomyTidy\Application\Execution\ExecutionWorkflow;
use TaxonomyTidy\Application\Execution\ItemExecutor;
use TaxonomyTidy\Domain\Operation\Taxonomy;
use TaxonomyTidy\Infrastructure\Persistence\OperationRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationItemRepository;
use TaxonomyTidy\Infrastructure\Persistence\OperationLock;
use TaxonomyTidy\Infrastructure\Persistence\ChangeJournalRepository;
use TaxonomyTidy\Infrastructure\Persistence\DatabaseTransaction;
use TaxonomyTidy\Infrastructure\Taxonomy\TermInventoryQuery;

/**
 * Registers and renders the read-only taxonomy inventory screen.
 */
final class Page {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'taxonomy-tidy';

	/**
	 * Adds the page below the Tools menu for authorized users.
	 */
	public function register_menu(): void {
		if ( ! Access::current_user_can_access() ) {
			return;
		}

		add_management_page(
			esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ),
			esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ),
			'manage_categories',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the Phase 3 screen styles only on the Taxonomy Tidy admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'taxonomy-tidy-admin',
			plugins_url( 'assets/css/admin.css', TAXONOMY_TIDY_PLUGIN_FILE ),
			array(),
			TAXONOMY_TIDY_VERSION
		);
		wp_enqueue_script(
			'taxonomy-tidy-admin',
			plugins_url( 'assets/js/admin.js', TAXONOMY_TIDY_PLUGIN_FILE ),
			array(),
			TAXONOMY_TIDY_VERSION,
			true
		);
		wp_enqueue_script(
			'taxonomy-tidy-plan-board',
			plugins_url( 'assets/js/plan-board.js', TAXONOMY_TIDY_PLUGIN_FILE ),
			array(),
			TAXONOMY_TIDY_VERSION,
			true
		);
	}

	/**
	 * Loads preview post titles only when the administrator expands the list.
	 */
	public function preview_posts(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_send_json_error( array( 'message' => __( 'アクセスできません。', 'taxonomy-tidy' ) ), 403 );
		}
		check_ajax_referer( PlanController::NONCE_ACTION, PlanController::NONCE_FIELD );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$request      = wp_unslash( $_POST );
		$taxonomy     = Taxonomy::tryFrom( sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) );
		$operation_id = absint( $request['operation_id'] ?? 0 );
		$item_index   = isset( $request['item_index'] ) && is_scalar( $request['item_index'] ) && ctype_digit( (string) $request['item_index'] ) ? (int) $request['item_index'] : -1;
		if ( null === $taxonomy || 0 === $operation_id || 0 > $item_index ) {
			wp_send_json_error( array( 'message' => __( 'プレビューを確認できません。', 'taxonomy-tidy' ) ), 400 );
		}
		$operation = ( new OperationRepository( $GLOBALS['wpdb'] ) )->find( $operation_id );
		if ( null === $operation || get_current_user_id() !== (int) $operation['user_id'] || $operation['taxonomy'] !== $taxonomy->value || 'previewed' !== $operation['status'] ) {
			wp_send_json_error( array( 'message' => __( 'プレビューを確認できません。', 'taxonomy-tidy' ) ), 403 );
		}
		$posts = $operation['requested_data']['preview']['items'][ $item_index ]['affected_posts'] ?? null;
		if ( ! is_array( $posts ) ) {
			wp_send_json_error( array( 'message' => __( '対象投稿を確認できません。', 'taxonomy-tidy' ) ), 400 );
		}
		wp_send_json_success( array( 'titles' => array_values( array_map( static fn( array $post ): string => (string) $post['title'], $posts ) ) ) );
	}

	/**
	 * Renders the taxonomy inventory after repeating the full access check.
	 */
	public function render(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_die(
				esc_html__( 'You are not allowed to access Taxonomy Tidy.', 'taxonomy-tidy' ),
				esc_html__( 'Access denied', 'taxonomy-tidy' ),
				array( 'response' => 403 )
			);
		}

		global $wpdb;
		$operations = new OperationRepository( $wpdb );
		$items      = new OperationItemRepository( $wpdb );
		$plans      = new PlanService();
		$workflow   = new PlanWorkflow( $operations, $plans );
		$execution  = new ExecutionWorkflow(
			$operations,
			$items,
			new OperationLock( $wpdb ),
			$plans,
			new ItemExecutor( new ChangeJournalRepository( $wpdb ) ),
			new DatabaseTransaction( $wpdb )
		);
		$board      = new PlanBoard( $operations, $workflow, $plans, $execution );
		$view       = $this->request_string( 'view' );
		if ( 'plan' === $view || 'history' === $view ) {
			$board_state = 'plan' === $view ? $board->handle() : null;
			?>
			<div class="wrap taxonomy-tidy-screen">
				<h1><?php echo esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ); ?></h1>
				<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( '表示タブ', 'taxonomy-tidy' ); ?>">
					<?php $this->render_tab( Taxonomy::CATEGORY, null, __( 'カテゴリー', 'taxonomy-tidy' ) ); ?>
					<?php $this->render_tab( Taxonomy::POST_TAG, null, __( 'タグ', 'taxonomy-tidy' ) ); ?>
					<?php $this->render_aux_tab( 'plan', $view, __( '操作計画', 'taxonomy-tidy' ), $board->draft_count( get_current_user_id() ) ); ?>
					<?php $this->render_aux_tab( 'history', $view, __( '操作履歴', 'taxonomy-tidy' ) ); ?>
				</nav>
				<?php if ( null !== $board_state ) : ?>
					<?php $board->render( $board_state ); ?>
				<?php else : ?>
					<h2><?php echo esc_html__( '操作履歴', 'taxonomy-tidy' ); ?></h2>
					<p><?php echo esc_html__( '操作履歴は今後のフェーズで表示します。', 'taxonomy-tidy' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		$taxonomy       = Taxonomy::POST_TAG->value === $this->request_string( 'taxonomy' )
			? Taxonomy::POST_TAG
			: Taxonomy::CATEGORY;
		$search         = $this->request_string( 's' );
		$orderby        = 'published_count' === $this->request_string( 'orderby' ) ? 'published_count' : 'name';
		$order          = 'desc' === $this->request_string( 'order' ) ? 'desc' : 'asc';
		$page           = $this->requested_page();
		$per_page       = $this->requested_per_page();
		$unused         = '1' === $this->request_string( 'unused' );
		$query          = new TermInventoryQuery( $wpdb );
		$inventory      = $query->find( $taxonomy, $search, $page, $per_page, $orderby, $order, $unused );
		$taxonomy_label = Taxonomy::CATEGORY === $taxonomy
			? __( 'Categories', 'taxonomy-tidy' )
			: __( 'Tags', 'taxonomy-tidy' );
		$type_label     = Taxonomy::CATEGORY === $taxonomy
			? __( 'Category', 'taxonomy-tidy' )
			: __( 'Tag', 'taxonomy-tidy' );
		$conditions     = $this->active_conditions( $search, $orderby, $order, $unused );
		$plan_state     = ( new PlanController( $workflow, $execution ) )->handle( $taxonomy );
		?>
		<div class="wrap taxonomy-tidy-screen">
			<h1><?php echo esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Taxonomy views', 'taxonomy-tidy' ); ?>">
				<?php $this->render_tab( Taxonomy::CATEGORY, $taxonomy, __( 'カテゴリー', 'taxonomy-tidy' ) ); ?>
				<?php $this->render_tab( Taxonomy::POST_TAG, $taxonomy, __( 'タグ', 'taxonomy-tidy' ) ); ?>
				<?php $this->render_aux_tab( 'plan', '', __( '操作計画', 'taxonomy-tidy' ), $board->draft_count( get_current_user_id() ) ); ?>
				<?php $this->render_aux_tab( 'history', '', __( '操作履歴', 'taxonomy-tidy' ) ); ?>
			</nav>

			<h2><?php echo esc_html( $taxonomy_label ); ?></h2>

			<details class="taxonomy-tidy-panel taxonomy-tidy-filter-panel">
				<summary class="taxonomy-tidy-panel__summary">
					<span class="taxonomy-tidy-panel__heading">
						<span class="taxonomy-tidy-panel__icon" aria-hidden="true"></span>
						<span><?php echo esc_html__( 'Search panel', 'taxonomy-tidy' ); ?></span>
					</span>
					<span class="taxonomy-tidy-filter-summary">
						<span class="taxonomy-tidy-filter-summary__count">
							<?php echo esc_html( $this->condition_count_label( count( $conditions ) ) ); ?>
						</span>
						<?php if ( array() !== $conditions ) : ?>
							<span class="taxonomy-tidy-filter-summary__conditions" aria-label="<?php echo esc_attr__( 'Active conditions', 'taxonomy-tidy' ); ?>">
								<?php foreach ( $conditions as $condition ) : ?>
									<span class="taxonomy-tidy-filter-summary__condition"><?php echo esc_html( $condition ); ?></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
					</span>
				</summary>

				<form class="taxonomy-tidy-filter-form" method="get" aria-label="<?php echo esc_attr__( 'Search and filter terms', 'taxonomy-tidy' ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">
					<input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>">

					<div class="taxonomy-tidy-filter-form__groups">
						<section class="taxonomy-tidy-filter-group taxonomy-tidy-filter-group--keyword" aria-labelledby="taxonomy-tidy-keyword-heading">
							<h3 id="taxonomy-tidy-keyword-heading"><?php echo esc_html__( 'Find by keyword', 'taxonomy-tidy' ); ?></h3>
							<label for="taxonomy-tidy-search"><?php echo esc_html__( 'Keyword', 'taxonomy-tidy' ); ?></label>
							<input id="taxonomy-tidy-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" aria-describedby="taxonomy-tidy-search-description">
							<p id="taxonomy-tidy-search-description" class="description">
								<?php echo esc_html__( 'Search by name or slug.', 'taxonomy-tidy' ); ?>
							</p>
						</section>

						<section class="taxonomy-tidy-filter-group taxonomy-tidy-filter-group--scope" aria-labelledby="taxonomy-tidy-scope-heading">
							<h3 id="taxonomy-tidy-scope-heading"><?php echo esc_html__( 'Filter displayed terms', 'taxonomy-tidy' ); ?></h3>
							<label class="taxonomy-tidy-checkbox-label" for="taxonomy-tidy-unused">
								<input id="taxonomy-tidy-unused" type="checkbox" name="unused" value="1" <?php checked( $unused ); ?>>
								<span><?php echo esc_html__( 'Globally unused only', 'taxonomy-tidy' ); ?></span>
							</label>
						</section>

						<section class="taxonomy-tidy-filter-group taxonomy-tidy-filter-group--sort" aria-labelledby="taxonomy-tidy-sort-heading">
							<h3 id="taxonomy-tidy-sort-heading"><?php echo esc_html__( 'Sort order', 'taxonomy-tidy' ); ?></h3>
							<div class="taxonomy-tidy-sort-fields">
								<div class="taxonomy-tidy-field">
									<label for="taxonomy-tidy-orderby"><?php echo esc_html__( 'Sort by', 'taxonomy-tidy' ); ?></label>
									<select id="taxonomy-tidy-orderby" name="orderby">
										<option value="name" <?php selected( $orderby, 'name' ); ?>><?php echo esc_html__( 'Name', 'taxonomy-tidy' ); ?></option>
										<option value="published_count" <?php selected( $orderby, 'published_count' ); ?>><?php echo esc_html__( 'Published posts', 'taxonomy-tidy' ); ?></option>
									</select>
								</div>
								<div class="taxonomy-tidy-field">
									<label for="taxonomy-tidy-order"><?php echo esc_html__( 'Direction', 'taxonomy-tidy' ); ?></label>
									<select id="taxonomy-tidy-order" name="order">
										<option value="asc" <?php selected( $order, 'asc' ); ?>><?php echo esc_html__( 'Ascending', 'taxonomy-tidy' ); ?></option>
										<option value="desc" <?php selected( $order, 'desc' ); ?>><?php echo esc_html__( 'Descending', 'taxonomy-tidy' ); ?></option>
									</select>
								</div>
							</div>
						</section>
					</div>

					<div class="taxonomy-tidy-filter-actions">
						<button type="submit" class="button button-primary">
							<?php echo esc_html__( 'Apply conditions', 'taxonomy-tidy' ); ?>
						</button>
						<a class="button button-secondary" href="<?php echo esc_url( $this->reset_url( $taxonomy, $per_page ) ); ?>">
							<?php echo esc_html__( 'Reset conditions', 'taxonomy-tidy' ); ?>
						</a>
					</div>
				</form>
			</details>

			<?php $this->render_page_size_forms( $taxonomy, $search, $orderby, $order, $unused ); ?>
			<?php ( new PlanningPanel() )->render( $taxonomy, $type_label, $inventory, $plan_state, fn( string $position ) => $this->render_pagination( $position, $taxonomy, $search, $orderby, $order, $unused, $inventory ) ); ?>
		</div>
		<?php
	}

	/**
	 * Reads and sanitizes one read-only list parameter.
	 *
	 * @param string $key Query-string key.
	 */
	private function request_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed below, type-checked, and sanitized before use; no state changes occur.
		$value = wp_unslash( $_GET[ $key ] ?? '' );

		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/** Reads only the three supported inventory page sizes. */
	private function requested_per_page(): int {
		$value = $this->request_string( 'per_page' );
		return in_array( $value, array( '20', '50', '100' ), true ) ? (int) $value : 20;
	}

	/** Reads a positive page number without converting negative values to positive ones. */
	private function requested_page(): int {
		$value = $this->request_string( 'paged' );
		return ctype_digit( $value ) && 0 < (int) $value ? (int) $value : 1;
	}

	/**
	 * Renders one category or tag navigation tab.
	 *
	 * @param Taxonomy $tab     Taxonomy represented by the tab.
	 * @param Taxonomy $current Currently displayed taxonomy.
	 * @param string   $label   Translated tab label.
	 */
	private function render_tab( Taxonomy $tab, ?Taxonomy $current, string $label ): void {
		$url   = add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $tab->value,
			),
			admin_url( 'tools.php' )
		);
		$class = $current === $tab ? ' nav-tab-active' : '';
		?>
		<a class="nav-tab<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $current === $tab ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( $label ); ?>
		</a>
		<?php
	}

	/**
	 * Renders the shared plan or future history tab.
	 *
	 * @param string   $tab     Tab key.
	 * @param string   $current Current tab key.
	 * @param string   $label   Localized label.
	 * @param int|null $count   Current administrator's draft-item count.
	 */
	private function render_aux_tab( string $tab, string $current, string $label, ?int $count = null ): void {
		$url        = add_query_arg(
			array(
				'page' => self::SLUG,
				'view' => $tab,
			),
			admin_url( 'tools.php' )
		);
		$accessible = null === $count ? $label : sprintf(
			/* translators: 1: tab name, 2: number of draft items. */
			__( '%1$s（%2$d件）', 'taxonomy-tidy' ),
			$label,
			$count
		);
		?>
		<a class="nav-tab<?php echo $tab === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $accessible ); ?>" <?php echo $tab === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?>
		<?php
		if ( null !== $count && 0 < $count ) :
			?>
			<span class="taxonomy-tidy-tab-count"><?php echo esc_html( (string) $count ); ?></span><?php endif; ?></a>
		<?php
	}

	/**
	 * Returns a parent name only for categories.
	 *
	 * @param Taxonomy        $taxonomy    Current taxonomy.
	 * @param int|string|null $parent_name Stored parent name.
	 */
	private function parent_label( Taxonomy $taxonomy, int|string|null $parent_name ): string {
		if ( Taxonomy::CATEGORY === $taxonomy && is_string( $parent_name ) && '' !== $parent_name ) {
			return $parent_name;
		}

		return __( '—', 'taxonomy-tidy' );
	}

	/**
	 * Returns the translated usage classification.
	 *
	 * @param string $usage Internal usage classification.
	 */
	private function usage_label( string $usage ): string {
		if ( 'published' === $usage ) {
			return __( 'Used by published posts', 'taxonomy-tidy' );
		}

		if ( 'excluded_only' === $usage ) {
			return __( 'Used outside published posts', 'taxonomy-tidy' );
		}

		return __( 'Globally unused', 'taxonomy-tidy' );
	}

	/**
	 * Returns translated summaries for conditions that differ from defaults.
	 *
	 * @param string $search  Search value.
	 * @param string $orderby Sort field.
	 * @param string $order   Sort direction.
	 * @param bool   $unused  Global-unused filter.
	 * @return list<string>
	 */
	private function active_conditions( string $search, string $orderby, string $order, bool $unused ): array {
		$conditions = array();

		if ( '' !== $search ) {
			$conditions[] = sprintf(
				/* translators: %s: taxonomy search keyword. */
				__( 'Keyword: %s', 'taxonomy-tidy' ),
				$search
			);
		}

		if ( $unused ) {
			$conditions[] = __( 'Globally unused', 'taxonomy-tidy' );
		}

		if ( 'name' !== $orderby || 'asc' !== $order ) {
			$sort_field   = 'published_count' === $orderby
				? __( 'Published posts', 'taxonomy-tidy' )
				: __( 'Name', 'taxonomy-tidy' );
			$direction    = 'desc' === $order
				? __( 'Descending', 'taxonomy-tidy' )
				: __( 'Ascending', 'taxonomy-tidy' );
			$conditions[] = $sort_field . ' · ' . $direction;
		}

		return $conditions;
	}

	/**
	 * Returns the translated active-condition count.
	 *
	 * @param int $count Number of active conditions.
	 */
	private function condition_count_label( int $count ): string {
		if ( 0 === $count ) {
			return __( 'No active conditions', 'taxonomy-tidy' );
		}

		return sprintf(
			/* translators: %d: number of active search, filter, or sort conditions. */
			_n( '%d active condition', '%d active conditions', $count, 'taxonomy-tidy' ),
			$count
		);
	}

	/**
	 * Returns the current taxonomy URL without search, filter, sort, or page state.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param int      $per_page Current page size, which is not a search condition.
	 */
	private function reset_url( Taxonomy $taxonomy, int $per_page ): string {
		return add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $taxonomy->value,
				'per_page' => $per_page,
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Creates two GET forms outside the existing selection and action POST form.
	 *
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param string   $search   Current keyword.
	 * @param string   $orderby  Current sort field.
	 * @param string   $order    Current sort direction.
	 * @param bool     $unused   Current usage filter.
	 */
	private function render_page_size_forms( Taxonomy $taxonomy, string $search, string $orderby, string $order, bool $unused ): void {
		foreach ( array( 'top', 'bottom' ) as $position ) {
			?>
			<form id="taxonomy-tidy-page-size-<?php echo esc_attr( $position ); ?>-form" method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">
				<?php
				if ( '' !== $search ) :
					?>
					<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
				<?php
				if ( 'name' !== $orderby ) :
					?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>"><?php endif; ?>
				<?php
				if ( 'asc' !== $order ) :
					?>
					<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>"><?php endif; ?>
				<?php
				if ( $unused ) :
					?>
					<input type="hidden" name="unused" value="1"><?php endif; ?>
			</form>
			<?php
		}
	}

	/**
	 * Renders matching controls directly above or below the inventory table.
	 *
	 * @param string               $position  Top or bottom controls.
	 * @param Taxonomy             $taxonomy  Current taxonomy.
	 * @param string               $search    Search value.
	 * @param string               $orderby   Sort field.
	 * @param string               $order     Sort direction.
	 * @param bool                 $unused    Global-unused filter.
	 * @param array<string, mixed> $inventory Paginated query result.
	 */
	private function render_pagination(
		string $position,
		Taxonomy $taxonomy,
		string $search,
		string $orderby,
		string $order,
		bool $unused,
		array $inventory
	): void {
		$form_id   = 'taxonomy-tidy-page-size-' . $position . '-form';
		$select_id = 'taxonomy-tidy-page-size-' . $position;
		$total     = (int) $inventory['total'];
		$page      = (int) $inventory['page'];
		$pages     = (int) $inventory['total_pages'];
		$per_page  = (int) $inventory['per_page'];
		$start     = 0 === $total ? 0 : ( $page - 1 ) * $per_page + 1;
		$end       = min( $total, $page * $per_page );
		?>
		<div class="tablenav taxonomy-tidy-table-nav taxonomy-tidy-table-nav--<?php echo esc_attr( $position ); ?>">
			<div class="taxonomy-tidy-page-size"><label for="<?php echo esc_attr( $select_id ); ?>"><?php echo esc_html__( '表示件数', 'taxonomy-tidy' ); ?></label><select id="<?php echo esc_attr( $select_id ); ?>" name="per_page" form="<?php echo esc_attr( $form_id ); ?>">
				<?php foreach ( array( 20, 50, 100 ) as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php /* translators: %d: number of terms per page. */ echo esc_html( sprintf( __( '%d件', 'taxonomy-tidy' ), $option ) ); ?></option>
				<?php endforeach; ?>
			</select><button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button"><?php echo esc_html__( '適用', 'taxonomy-tidy' ); ?></button></div>
			<span class="taxonomy-tidy-page-range">
			<?php
			if ( 0 === $total ) {
				echo esc_html__( '該当する項目はありません', 'taxonomy-tidy' );
			} else {
				/* translators: 1: filtered total, 2: first visible item, 3: last visible item. */
				echo esc_html( sprintf( __( '全%1$s件中 %2$s〜%3$s件を表示', 'taxonomy-tidy' ), number_format_i18n( $total ), number_format_i18n( $start ), number_format_i18n( $end ) ) );
			}
			?>
			</span>
			<?php if ( 1 < $pages ) : ?>
				<nav class="tablenav-pages taxonomy-tidy-pagination" aria-label="<?php echo esc_attr( 'top' === $position ? __( '一覧上部のページ移動', 'taxonomy-tidy' ) : __( '一覧下部のページ移動', 'taxonomy-tidy' ) ); ?>">
					<?php $this->render_page_button( '<<', 1, __( '最初のページへ', 'taxonomy-tidy' ), 1 === $page, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
					<?php $this->render_page_button( '<', $page - 1, __( '前のページへ', 'taxonomy-tidy' ), 1 === $page, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
					<?php
					$previous = 0;
					foreach ( $this->visible_pages( $page, $pages ) as $number ) {
						if ( 1 < $number - $previous ) {
							?>
							<span class="taxonomy-tidy-page-ellipsis" aria-hidden="true">…</span>
							<?php
						}
						if ( $number === $page ) {
							?>
							<span class="button taxonomy-tidy-page-link taxonomy-tidy-page-current" aria-current="page" aria-label="<?php /* translators: %d: current page number. */ echo esc_attr( sprintf( __( '%dページ目', 'taxonomy-tidy' ), $number ) ); ?>"><?php echo esc_html( (string) $number ); ?></span>
							<?php
						} else {
							/* translators: %d: target page number. */
							$this->render_page_button( (string) $number, $number, sprintf( __( '%dページへ', 'taxonomy-tidy' ), $number ), false, $taxonomy, $search, $orderby, $order, $unused, $per_page );
						}
						$previous = $number;
					}
					?>
					<?php $this->render_page_button( '>', $page + 1, __( '次のページへ', 'taxonomy-tidy' ), $page === $pages, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
					<?php $this->render_page_button( '>>', $pages, __( '最後のページへ', 'taxonomy-tidy' ), $page === $pages, $taxonomy, $search, $orderby, $order, $unused, $per_page ); ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Returns the first, last, current, and adjacent page numbers.
	 *
	 * @param int $current Current page number.
	 * @param int $total   Total number of pages.
	 * @return list<int>
	 */
	private function visible_pages( int $current, int $total ): array {
		if ( 7 >= $total ) {
			return range( 1, $total );
		}
		$numbers = array( 1, $total, $current - 1, $current, $current + 1 );
		if ( 3 >= $current ) {
			$numbers = array_merge( $numbers, array( 2, 3 ) );
		}
		if ( $current >= $total - 2 ) {
			$numbers = array_merge( $numbers, array( $total - 2, $total - 1 ) );
		}
		$numbers = array_values( array_unique( array_filter( $numbers, static fn( int $number ): bool => 0 < $number && $number <= $total ) ) );
		sort( $numbers );
		return $numbers;
	}

	/**
	 * Renders an enabled link or a noninteractive boundary control.
	 *
	 * @param string   $text     Visible symbol or page number.
	 * @param int      $target   Target page number.
	 * @param string   $label    Accessible control label.
	 * @param bool     $disabled Whether the control is unavailable.
	 * @param Taxonomy $taxonomy Current taxonomy.
	 * @param string   $search   Current keyword.
	 * @param string   $orderby  Current sort field.
	 * @param string   $order    Current sort direction.
	 * @param bool     $unused   Current usage filter.
	 * @param int      $per_page Current page size.
	 */
	private function render_page_button( string $text, int $target, string $label, bool $disabled, Taxonomy $taxonomy, string $search, string $orderby, string $order, bool $unused, int $per_page ): void {
		if ( $disabled ) {
			?>
			<span class="button taxonomy-tidy-page-link taxonomy-tidy-page-disabled" aria-disabled="true" aria-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $text ); ?></span>
			<?php
			return;
		}
		$args = array(
			'page'     => self::SLUG,
			'taxonomy' => $taxonomy->value,
			'per_page' => $per_page,
			'paged'    => $target,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( 'name' !== $orderby ) {
			$args['orderby'] = $orderby;
		}
		if ( 'asc' !== $order ) {
			$args['order'] = $order;
		}
		if ( $unused ) {
			$args['unused'] = '1';
		}
		$url = add_query_arg( $args, admin_url( 'tools.php' ) );
		?>
		<a class="button taxonomy-tidy-page-link" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $text ); ?></a>
		<?php
	}
}
