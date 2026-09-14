<?php
/**
 * Taxonomy Tidy admin page.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

use TaxonomyTidy\Domain\Operation\Taxonomy;
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

		$taxonomy       = Taxonomy::POST_TAG->value === $this->request_string( 'taxonomy' )
			? Taxonomy::POST_TAG
			: Taxonomy::CATEGORY;
		$search         = $this->request_string( 's' );
		$orderby        = 'published_count' === $this->request_string( 'orderby' ) ? 'published_count' : 'name';
		$order          = 'desc' === $this->request_string( 'order' ) ? 'desc' : 'asc';
		$page           = max( 1, absint( $this->request_string( 'paged' ) ) );
		$unused         = '1' === $this->request_string( 'unused' );
		$query          = new TermInventoryQuery( $wpdb );
		$inventory      = $query->find( $taxonomy, $search, $page, 50, $orderby, $order, $unused );
		$taxonomy_label = Taxonomy::CATEGORY === $taxonomy
			? __( 'Categories', 'taxonomy-tidy' )
			: __( 'Tags', 'taxonomy-tidy' );
		$type_label     = Taxonomy::CATEGORY === $taxonomy
			? __( 'Category', 'taxonomy-tidy' )
			: __( 'Tag', 'taxonomy-tidy' );
		$conditions     = $this->active_conditions( $search, $orderby, $order, $unused );
		?>
		<div class="wrap taxonomy-tidy-screen">
			<h1><?php echo esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Taxonomy views', 'taxonomy-tidy' ); ?>">
				<?php $this->render_tab( Taxonomy::CATEGORY, $taxonomy, __( 'Categories', 'taxonomy-tidy' ) ); ?>
				<?php $this->render_tab( Taxonomy::POST_TAG, $taxonomy, __( 'Tags', 'taxonomy-tidy' ) ); ?>
			</nav>

			<h2><?php echo esc_html( $taxonomy_label ); ?></h2>

			<details class="taxonomy-tidy-panel taxonomy-tidy-filter-panel" open>
				<summary class="taxonomy-tidy-panel__summary">
					<span class="taxonomy-tidy-panel__heading">
						<span class="taxonomy-tidy-panel__icon" aria-hidden="true"></span>
						<span><?php echo esc_html__( 'Search and filter', 'taxonomy-tidy' ); ?></span>
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
						<a class="button button-secondary" href="<?php echo esc_url( $this->reset_url( $taxonomy ) ); ?>">
							<?php echo esc_html__( 'Reset conditions', 'taxonomy-tidy' ); ?>
						</a>
					</div>
				</form>
			</details>

			<table class="wp-list-table widefat fixed striped taxonomy-tidy-inventory-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Name', 'taxonomy-tidy' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Slug', 'taxonomy-tidy' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Type', 'taxonomy-tidy' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Parent category', 'taxonomy-tidy' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Published posts', 'taxonomy-tidy' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Total relationships', 'taxonomy-tidy' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Usage', 'taxonomy-tidy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $inventory['items'] ) : ?>
						<tr><td class="taxonomy-tidy-inventory-table__empty" colspan="7"><?php echo esc_html__( 'No terms found.', 'taxonomy-tidy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $inventory['items'] as $term ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr__( 'Name', 'taxonomy-tidy' ); ?>"><strong><?php echo esc_html( (string) $term['name'] ); ?></strong></td>
								<td data-label="<?php echo esc_attr__( 'Slug', 'taxonomy-tidy' ); ?>"><code><?php echo esc_html( (string) $term['slug'] ); ?></code></td>
								<td data-label="<?php echo esc_attr__( 'Type', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $type_label ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Parent category', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $this->parent_label( $taxonomy, $term['parent_name'] ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Published posts', 'taxonomy-tidy' ); ?>"><?php echo esc_html( number_format_i18n( (int) $term['published_post_count'] ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Total relationships', 'taxonomy-tidy' ); ?>"><?php echo esc_html( number_format_i18n( (int) $term['total_relationship_count'] ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Usage', 'taxonomy-tidy' ); ?>"><?php echo esc_html( $this->usage_label( (string) $term['usage'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $taxonomy, $search, $orderby, $order, $unused, $inventory ); ?>
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

	/**
	 * Renders one category or tag navigation tab.
	 *
	 * @param Taxonomy $tab     Taxonomy represented by the tab.
	 * @param Taxonomy $current Currently displayed taxonomy.
	 * @param string   $label   Translated tab label.
	 */
	private function render_tab( Taxonomy $tab, Taxonomy $current, string $label ): void {
		$url   = add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $tab->value,
			),
			admin_url( 'tools.php' )
		);
		$class = $current === $tab ? ' nav-tab-active' : '';
		?>
		<a class="nav-tab<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
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
	 */
	private function reset_url( Taxonomy $taxonomy ): string {
		return add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $taxonomy->value,
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Renders server-side pagination while preserving current filters.
	 *
	 * @param Taxonomy             $taxonomy  Current taxonomy.
	 * @param string               $search    Search value.
	 * @param string               $orderby   Sort field.
	 * @param string               $order     Sort direction.
	 * @param bool                 $unused    Global-unused filter.
	 * @param array<string, mixed> $inventory Paginated query result.
	 */
	private function render_pagination(
		Taxonomy $taxonomy,
		string $search,
		string $orderby,
		string $order,
		bool $unused,
		array $inventory
	): void {
		if ( $inventory['total_pages'] < 2 ) {
			return;
		}

		$base  = add_query_arg(
			array(
				'page'     => self::SLUG,
				'taxonomy' => $taxonomy->value,
				's'        => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'unused'   => $unused ? '1' : '0',
				'paged'    => 999999999,
			),
			admin_url( 'tools.php' )
		);
		$base  = str_replace( '999999999', '%#%', $base );
		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $inventory['page'],
				'total'     => $inventory['total_pages'],
				'prev_text' => __( 'Previous', 'taxonomy-tidy' ),
				'next_text' => __( 'Next', 'taxonomy-tidy' ),
			)
		);

		if ( is_string( $links ) ) {
			?>
			<div class="tablenav bottom"><div class="tablenav-pages"><?php echo wp_kses_post( $links ); ?></div></div>
			<?php
		}
	}
}
