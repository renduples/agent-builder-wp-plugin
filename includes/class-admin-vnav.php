<?php
/**
 * Vertical settings-style navigation helper.
 *
 * Emits the same two-pane "shell" markup used by the Settings page so every
 * admin page can present a consistent left vertical navigation instead of the
 * classic horizontal WordPress nav tabs. The markup reuses the
 * `agentic-settings-*` CSS already shipped in assets/css/admin.css, and the
 * search box is wired by the shared assets/js/admin-vnav.js.
 *
 * Usage:
 *   \Agentic\Admin_Vnav::open( array(
 *       'active' => $active_slug,
 *       'items'  => array(
 *           array( 'slug' => 'audit', 'label' => 'Audit', 'url' => '?page=…&tab=audit' ),
 *           …
 *       ),
 *   ) );
 *   // … page body for the active tab …
 *   \Agentic\Admin_Vnav::close();
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.17.0
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a vertical navigation shell for admin pages.
 */
class Admin_Vnav {

	/**
	 * Open the shell: render the sidebar (optional search + grouped nav) and
	 * open the body pane. The caller echoes the active tab's content next, then
	 * calls {@see self::close()}.
	 *
	 * @param array $args {
	 *     Navigation configuration.
	 *
	 *     @type string $active             Active item slug.
	 *     @type array  $items              Flat list of item arrays (one unlabelled group).
	 *     @type array  $groups             Grouped list: array of [ 'label' => string, 'items' => array ].
	 *                                      Use either `items` or `groups`, not both.
	 *     @type bool   $search             Show the filter box. Default: auto (true when > 6 items).
	 *     @type string $search_placeholder Placeholder for the filter box.
	 *     @type string $aria_label         Accessible label for the <nav>.
	 *     @type string $id                 Unique id base for this shell. Default 'agentic-vnav'.
	 *     @type string $empty_text         Message shown when the filter matches nothing.
	 *
	 *     Each item array supports:
	 *       'slug'   (string) item slug, compared against `active`.
	 *       'label'  (string) visible label (plain text, escaped here).
	 *       'url'    (string) href.
	 *       'badge'  (string) optional pre-escaped HTML appended inside the link (e.g. a count badge).
	 *       'suffix' (string) optional pre-escaped HTML appended after the label (e.g. a status dot).
	 *       'active' (bool)   optional explicit active flag (overrides slug comparison).
	 *       'title'  (string) optional one-line tooltip (native title attribute) explaining what this section is for.
	 * }
	 * @return void
	 */
	public static function open( array $args ): void {
		$defaults = array(
			'active'             => '',
			'items'              => array(),
			'groups'             => array(),
			'search'             => null,
			'search_placeholder' => __( 'Search…', 'agentstructor' ),
			'aria_label'         => __( 'Sections', 'agentstructor' ),
			'id'                 => '',
			'empty_text'         => __( 'No sections match your search.', 'agentstructor' ),
		);
		$args     = array_merge( $defaults, $args );

		// Normalise to a list of groups: [ [ 'label' => '', 'items' => [...] ], … ].
		$groups = array();
		if ( ! empty( $args['groups'] ) ) {
			$groups = $args['groups'];
		} elseif ( ! empty( $args['items'] ) ) {
			$groups = array(
				array(
					'label' => '',
					'items' => $args['items'],
				),
			);
		}

		// Count items to decide whether the search box is worthwhile.
		$total = 0;
		foreach ( $groups as $group ) {
			$total += count( $group['items'] ?? array() );
		}
		$show_search = is_null( $args['search'] ) ? ( $total > 6 ) : (bool) $args['search'];

		$id = '' !== $args['id'] ? sanitize_html_class( $args['id'] ) : wp_unique_id( 'agentic-vnav-' );
		?>
		<div class="agentic-settings-shell">
			<aside class="agentic-settings-sidebar">
				<?php if ( $show_search ) : ?>
					<div class="agentic-settings-search">
						<input type="search" id="<?php echo esc_attr( $id ); ?>-filter" autocomplete="off"
							placeholder="<?php echo esc_attr( $args['search_placeholder'] ); ?>"
							aria-label="<?php echo esc_attr( $args['search_placeholder'] ); ?>" />
					</div>
				<?php endif; ?>
				<nav class="agentic-settings-nav" aria-label="<?php echo esc_attr( $args['aria_label'] ); ?>">
					<?php foreach ( $groups as $group ) : ?>
						<?php
						$group_items = $group['items'] ?? array();
						if ( empty( $group_items ) ) {
							continue;
						}
						$group_label = (string) ( $group['label'] ?? '' );
						?>
						<div class="agentic-settings-nav-group">
							<?php if ( '' !== $group_label ) : ?>
								<span class="agentic-settings-nav-group__label"><?php echo esc_html( $group_label ); ?></span>
							<?php endif; ?>
							<ul class="agentic-settings-nav-list">
								<?php
								foreach ( $group_items as $item ) :
									$slug      = (string) ( $item['slug'] ?? '' );
									$label     = (string) ( $item['label'] ?? '' );
									$url       = (string) ( $item['url'] ?? '#' );
									$title     = (string) ( $item['title'] ?? '' );
									$is_active = isset( $item['active'] ) ? (bool) $item['active'] : ( '' !== $slug && $slug === $args['active'] );
									?>
									<li>
										<a href="<?php echo esc_url( $url ); ?>"
											class="agentic-settings-nav-item <?php echo $is_active ? 'is-active' : ''; ?>"
											data-filter-label="<?php echo esc_attr( $label ); ?>"
											<?php echo '' !== $title ? 'title="' . esc_attr( $title ) . '"' : ''; ?>
											<?php echo $is_active ? 'aria-current="page"' : ''; ?>>
											<?php
											echo esc_html( $label );
											if ( ! empty( $item['badge'] ) ) {
												echo ' ' . wp_kses_post( $item['badge'] );
											}
											if ( ! empty( $item['suffix'] ) ) {
												echo ' ' . wp_kses_post( $item['suffix'] );
											}
											?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
					<?php if ( $show_search ) : ?>
						<p class="agentic-settings-nav-empty" hidden><?php echo esc_html( $args['empty_text'] ); ?></p>
					<?php endif; ?>
				</nav>
			</aside>
			<div class="agentic-settings-body">
		<?php
	}

	/**
	 * Close the body pane and shell opened by {@see self::open()}.
	 *
	 * @return void
	 */
	public static function close(): void {
		echo '</div><!-- .agentic-settings-body --></div><!-- .agentic-settings-shell -->';
	}
}
