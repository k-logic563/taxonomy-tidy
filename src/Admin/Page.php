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
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Taxonomy views', 'taxonomy-tidy' ); ?>">
				<?php $this->render_tab( Taxonomy::CATEGORY, $taxonomy, __( 'Categories', 'taxonomy-tidy' ) ); ?>
				<?php $this->render_tab( Taxonomy::POST_TAG, $taxonomy, __( 'Tags', 'taxonomy-tidy' ) ); ?>
			</nav>

			<h2><?php echo esc_html( $taxonomy_label ); ?></h2>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy->value ); ?>">

				<p class="search-box">
					<label class="screen-reader-text" for="taxonomy-tidy-search">
						<?php echo esc_html__( 'Search terms', 'taxonomy-tidy' ); ?>
					</label>
					<input id="taxonomy-tidy-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>">
					<?php submit_button( __( 'Search terms', 'taxonomy-tidy' ), 'secondary', '', false ); ?>
				</p>

				<div class="tablenav top">
					<div class="alignleft actions">
						<label for="taxonomy-tidy-orderby" class="screen-reader-text">
							<?php echo esc_html__( 'Sort terms by', 'taxonomy-tidy' ); ?>
						</label>
						<select id="taxonomy-tidy-orderby" name="orderby">
							<option value="name" <?php selected( $orderby, 'name' ); ?>><?php echo esc_html__( 'Name', 'taxonomy-tidy' ); ?></option>
							<option value="published_count" <?php selected( $orderby, 'published_count' ); ?>><?php echo esc_html__( 'Published posts', 'taxonomy-tidy' ); ?></option>
						</select>

						<label for="taxonomy-tidy-order" class="screen-reader-text">
							<?php echo esc_html__( 'Sort direction', 'taxonomy-tidy' ); ?>
						</label>
						<select id="taxonomy-tidy-order" name="order">
							<option value="asc" <?php selected( $order, 'asc' ); ?>><?php echo esc_html__( 'Ascending', 'taxonomy-tidy' ); ?></option>
							<option value="desc" <?php selected( $order, 'desc' ); ?>><?php echo esc_html__( 'Descending', 'taxonomy-tidy' ); ?></option>
						</select>

						<label>
							<input type="checkbox" name="unused" value="1" <?php checked( $unused ); ?>>
							<?php echo esc_html__( 'Globally unused only', 'taxonomy-tidy' ); ?>
						</label>
						<?php submit_button( __( 'Filter', 'taxonomy-tidy' ), 'secondary', 'filter_action', false ); ?>
					</div>
				</div>
			</form>

			<table class="wp-list-table widefat fixed striped">
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
						<tr><td colspan="7"><?php echo esc_html__( 'No terms found.', 'taxonomy-tidy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $inventory['items'] as $term ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $term['name'] ); ?></strong></td>
								<td><code><?php echo esc_html( (string) $term['slug'] ); ?></code></td>
								<td><?php echo esc_html( $type_label ); ?></td>
								<td><?php echo esc_html( $this->parent_label( $taxonomy, $term['parent_name'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $term['published_post_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $term['total_relationship_count'] ) ); ?></td>
								<td><?php echo esc_html( $this->usage_label( (string) $term['usage'] ) ); ?></td>
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
