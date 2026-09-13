<?php
/**
 * Taxonomy Tidy admin page.
 *
 * @package TaxonomyTidy
 */

declare(strict_types=1);

namespace TaxonomyTidy\Admin;

/**
 * Registers and renders the minimal Phase 1 admin screen.
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
	 * Renders the placeholder screen after repeating the full access check.
	 */
	public function render(): void {
		if ( ! Access::current_user_can_access() ) {
			wp_die(
				esc_html__( 'You are not allowed to access Taxonomy Tidy.', 'taxonomy-tidy' ),
				esc_html__( 'Access denied', 'taxonomy-tidy' ),
				array( 'response' => 403 )
			);
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Taxonomy Tidy', 'taxonomy-tidy' ); ?></h1>
			<p>
				<?php
				echo esc_html__(
					'Taxonomy management tools will be available in a later phase.',
					'taxonomy-tidy'
				);
				?>
			</p>
		</div>
		<?php
	}
}
