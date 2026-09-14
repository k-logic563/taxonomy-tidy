<?php
/**
 * Read-only taxonomy inventory query.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Infrastructure\Taxonomy;

use TaxonomyTidy\Domain\Operation\Taxonomy;
use wpdb;

/**
 * Reads accurate term relationship counts without trusting term_taxonomy.count.
 */
final class TermInventoryQuery {
	/**
	 * Maximum supported page size.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * WordPress database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Creates the read-only query service.
	 *
	 * @param \wpdb $database WordPress database connection.
	 */
	public function __construct( wpdb $database ) {
		$this->database = $database;
	}

	/**
	 * Returns a filtered and paginated inventory for one core taxonomy.
	 *
	 * @param Taxonomy $taxonomy        Category or post-tag inventory to read.
	 * @param string   $search          Optional name-or-slug search value.
	 * @param int      $page            One-based result page.
	 * @param int      $per_page        Number of rows to return, capped at 100.
	 * @param string   $orderby         Name or published-post count ordering.
	 * @param string   $order           Ascending or descending direction.
	 * @param bool     $globally_unused Whether to include only relationship-free terms.
	 * @return array{items: list<array<string, int|string|null>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function find(
		Taxonomy $taxonomy,
		string $search = '',
		int $page = 1,
		int $per_page = 50,
		string $orderby = 'name',
		string $order = 'asc',
		bool $globally_unused = false
	): array {
		$page       = max( 1, $page );
		$per_page   = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
		$offset     = ( $page - 1 ) * $per_page;
		$search     = trim( $search );
		$order_sql  = 'desc' === strtolower( $order ) ? 'DESC' : 'ASC';
		$sort_sql   = 'published_count' === $orderby
			? "published_post_count {$order_sql}, t.name ASC, t.term_id ASC"
			: "t.name {$order_sql}, t.term_id ASC";
		$where_sql  = 'WHERE tt.taxonomy = %s';
		$having_sql = $globally_unused ? 'HAVING COUNT(tr.object_id) = 0' : '';

		$filter_values = array( $taxonomy->value );

		if ( '' !== $search ) {
			$like            = '%' . $this->database->esc_like( $search ) . '%';
			$where_sql      .= ' AND (t.name LIKE %s OR t.slug LIKE %s)';
			$filter_values[] = $like;
			$filter_values[] = $like;
		}

		$list_sql    = "SELECT
				t.term_id,
				t.name,
				t.slug,
				tt.term_taxonomy_id,
				tt.taxonomy,
				tt.parent AS parent_id,
				parent.name AS parent_name,
				COUNT(DISTINCT published_posts.ID) AS published_post_count,
				COUNT(tr.object_id) AS total_relationship_count
			FROM %i AS t
			INNER JOIN %i AS tt ON tt.term_id = t.term_id
			LEFT JOIN %i AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN %i AS published_posts
				ON published_posts.ID = tr.object_id
				AND published_posts.post_type = %s
				AND published_posts.post_status = %s
			LEFT JOIN %i AS parent ON parent.term_id = tt.parent
			{$where_sql}
			GROUP BY t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.parent, parent.name
			{$having_sql}
			ORDER BY {$sort_sql}
			LIMIT %d OFFSET %d";
		$list_values = array_merge(
			array(
				$this->database->terms,
				$this->database->term_taxonomy,
				$this->database->term_relationships,
				$this->database->posts,
				'post',
				'publish',
				$this->database->terms,
			),
			$filter_values,
			array( $per_page, $offset )
		);

		$count_sql    = "SELECT COUNT(*) FROM (
			SELECT tt.term_taxonomy_id
			FROM %i AS t
			INNER JOIN %i AS tt ON tt.term_id = t.term_id
			LEFT JOIN %i AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			{$where_sql}
			GROUP BY tt.term_taxonomy_id
			{$having_sql}
		) AS taxonomy_tidy_inventory";
		$count_values = array_merge(
			array(
				$this->database->terms,
				$this->database->term_taxonomy,
				$this->database->term_relationships,
			),
			$filter_values
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL placeholders are prepared below; dynamic clauses are selected from fixed local values.
		$prepared_list = $this->database->prepare( $list_sql, $list_values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL placeholders are prepared below; the optional HAVING clause is fixed.
		$prepared_count = $this->database->prepare( $count_sql, $count_values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only reporting query requires counts outside the core term API.
		$rows = $this->database->get_results( $prepared_list, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only reporting query requires an independently filtered total.
		$total = (int) $this->database->get_var( $prepared_count );
		$items = array_map( array( $this, 'normalize_row' ), is_array( $rows ) ? $rows : array() );

		return array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Converts database scalar values and derives the display classification.
	 *
	 * @param array<string, mixed> $row Raw database result.
	 * @return array<string, int|string|null>
	 */
	private function normalize_row( array $row ): array {
		$row['term_id']                  = (int) $row['term_id'];
		$row['term_taxonomy_id']         = (int) $row['term_taxonomy_id'];
		$row['parent_id']                = (int) $row['parent_id'];
		$row['parent_name']              = null === $row['parent_name'] ? null : (string) $row['parent_name'];
		$row['published_post_count']     = (int) $row['published_post_count'];
		$row['total_relationship_count'] = (int) $row['total_relationship_count'];
		$row['usage']                    = $this->usage(
			$row['published_post_count'],
			$row['total_relationship_count']
		);

		return $row;
	}

	/**
	 * Classifies published use, excluded-only use, and global non-use.
	 *
	 * @param int $published_count Number of published standard posts.
	 * @param int $total_count     Number of all object relationships.
	 */
	private function usage( int $published_count, int $total_count ): string {
		if ( 0 < $published_count ) {
			return 'published';
		}

		return 0 < $total_count ? 'excluded_only' : 'globally_unused';
	}
}
