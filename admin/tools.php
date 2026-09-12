<?php
/**
 * Agentic Tools Administration Page
 *
 * Displays all available tools across all agents: core tools and
 * agent-specific tools. Provides visibility into what each agent can do.
 * Administrators can enable or disable individual tools via toggle switches.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      1.4.0
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agent-builder' ) );
}

wp_enqueue_style( 'agentic-ui' );
wp_enqueue_script( 'agentic-ui' );

use Agentic\Tools_Registry;

if ( ! function_exists( 'agentic_render_tool_description' ) ) {
	/**
	 * Render a tool/ability description, visually clamped to a couple of lines.
	 *
	 * Long descriptions are truncated via CSS line-clamp and the full text is
	 * exposed through a native tooltip (the `title` attribute) so nothing is lost.
	 *
	 * @param string $agentic_desc The full description text.
	 * @return void
	 */
	function agentic_render_tool_description( $agentic_desc ) {
		$agentic_desc = trim( (string) $agentic_desc );
		if ( '' === $agentic_desc ) {
			echo '<span class="agentic-text-muted">&mdash;</span>';
			return;
		}
		$agentic_is_long = mb_strlen( $agentic_desc ) > 90;
		printf(
			'<span class="agentic-desc-clamp%1$s"%2$s>%3$s</span>',
			$agentic_is_long ? ' is-truncated' : '',
			$agentic_is_long ? ' title="' . esc_attr( $agentic_desc ) . '"' : '',
			esc_html( $agentic_desc )
		);
	}
}

// Sync tool files to the registry first (discovers new/removed tool.php files),
// then seed ensures categories and descriptions are up to date.
$agentic_tool_loader_sync = \Agentic\Tool_Loader::get_instance();
$agentic_tool_loader_sync->sync_to_registry();

\Agentic\Tools_Registry::seed_core_tools(
	array(
		'db_update_option' => 'database',
		'db_create_post'   => 'database',
		'db_update_post'   => 'database',
		'db_delete_post'   => 'database',
		'run_wp_cli'       => 'cli',
	)
);

// Sync agent-contributed tools.
$agentic_registry  = \Agentic_Agent_Registry::get_instance();
$agentic_instances = $agentic_registry->get_all_instances();

// Category map is no longer needed — sync_agent_tools reads each tool's
// get_category() directly from the Tool_Base instance.

// Track which agents provide which tools.
$agentic_tool_agents = array();
$agentic_tool_loader = \Agentic\Tool_Loader::get_instance();
$agentic_tool_loader->load();
foreach ( $agentic_instances as $agentic_agent ) {
	$agentic_names       = $agentic_agent->get_tool_names();
	$agentic_agent_tools = $agentic_tool_loader->get_definitions_for( $agentic_names );
	Tools_Registry::sync_agent_tools( $agentic_agent->get_id(), $agentic_agent_tools );
	foreach ( $agentic_names as $agentic_fname ) {
		$agentic_tool_agents[ $agentic_fname ][] = $agentic_agent->get_name();
	}
}

// Load all tools from the database.
$agentic_all_tools = Tools_Registry::get_all();

// Category labels.
$agentic_category_tabs = array(
	'all'               => __( 'All', 'agent-builder' ),
	'content'           => __( 'Content', 'agent-builder' ),
	'database'          => __( 'Database', 'agent-builder' ),
	'seo'               => __( 'SEO', 'agent-builder' ),
	'site-audit'        => __( 'Site Audit', 'agent-builder' ),
	'site-health'       => __( 'Site Health', 'agent-builder' ),
	'caching'           => __( 'Caching', 'agent-builder' ),
	'ai-visibility'     => __( 'AI Visibility', 'agent-builder' ),
	'security'          => __( 'Security', 'agent-builder' ),
	'assistant-trainer' => __( 'Agents', 'agent-builder' ),
	'analytics'         => __( 'Analytics', 'agent-builder' ),
	'wordpress'         => __( 'WordPress', 'agent-builder' ),
	'cli'               => __( 'CLI', 'agent-builder' ),
);

// Filter by source type if requested.
$agentic_filter_type = sanitize_text_field( wp_unslash( $_GET['tool_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter, not a form submission.
if ( $agentic_filter_type ) {
	$agentic_filter_src = strtolower( $agentic_filter_type );
	$agentic_all_tools  = array_filter(
		$agentic_all_tools,
		fn( $t ) => ( 'core' === $agentic_filter_src ) ? ( 'core' === $t['source'] ) : ( 'core' !== $t['source'] )
	);
}

// Top-level section: tools (default) or slash-commands. The former separate
// "WP Abilities" section is now folded into the Tools view as the special
// "inbound-abilities" category (see below), so the menu is a single list.
$agentic_active_section = sanitize_key( wp_unslash( $_GET['section'] ?? 'tools' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $agentic_active_section, array( 'tools', 'slash-commands' ), true ) ) {
	$agentic_active_section = 'tools';
}

// Whether the WordPress Abilities API (WP 6.9+) is available. Inbound abilities
// only exist when it is.
$agentic_abilities_api = \Agentic\WP_Optional_API::has( 'wp_register_ability' );

// Pseudo-category that shows third-party (inbound) abilities instead of the
// tools table.
$agentic_inbound_cat = 'inbound-abilities';

// Active category. Accepts any real tool category plus the inbound-abilities
// pseudo-category. The retired ?section=abilities URLs map to it so old
// bookmarks keep working.
$agentic_active_cat = sanitize_text_field( wp_unslash( $_GET['category'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
if ( 'abilities' === sanitize_key( wp_unslash( $_GET['section'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Backward-compatible redirect.
	$agentic_active_cat = $agentic_inbound_cat;
}
if ( $agentic_inbound_cat !== $agentic_active_cat && ! isset( $agentic_category_tabs[ $agentic_active_cat ] ) ) {
	$agentic_active_cat = 'all';
}
if ( $agentic_inbound_cat === $agentic_active_cat && ! $agentic_abilities_api ) {
	$agentic_active_cat = 'all';
}

// Pre-compute the inbound abilities dataset (third-party abilities exposed to
// agents as tools). Needed for the category badge count on every Tools view and
// for the table body when the inbound-abilities category is active. Outbound
// abilities are simply the tools themselves, so they are shown inline in the
// tools table rather than as a separate list.
$agentic_inbound_tools    = array();
$agentic_disabled_inbound = array();
if ( 'tools' === $agentic_active_section && $agentic_abilities_api ) {
	$agentic_ab_bridge = new \Agentic\Abilities_Bridge();
	// Include disabled inbound abilities so the table lists every one with a
	// toggle reflecting its current state.
	$agentic_inbound_tools    = $agentic_ab_bridge->get_third_party_abilities_as_tools( true );
	$agentic_disabled_inbound = $agentic_ab_bridge->get_disabled_inbound_abilities();
}

// Count tools per category (before filtering).
$agentic_cat_counts = array( 'all' => count( $agentic_all_tools ) );
foreach ( array_keys( $agentic_category_tabs ) as $agentic_cat_key ) {
	if ( 'all' !== $agentic_cat_key ) {
		$agentic_cat_counts[ $agentic_cat_key ] = count(
			array_filter( $agentic_all_tools, fn( $t ) => $agentic_cat_key === $t['category'] )
		);
	}
}

// Filter tools by active category (skip for the inbound-abilities pseudo-category,
// which renders its own table instead of the tools list).
if ( 'all' !== $agentic_active_cat && $agentic_inbound_cat !== $agentic_active_cat ) {
	$agentic_all_tools = array_filter(
		$agentic_all_tools,
		fn( $t ) => $agentic_active_cat === $t['category']
	);
}

// Query tool usage counts from the audit log.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table aggregate.
$agentic_usage_rows   = $wpdb->get_results(
	"SELECT target_type AS tool_name, COUNT(*) AS call_count FROM {$wpdb->prefix}agentic_audit_log WHERE action = 'tool_call' GROUP BY target_type",
	ARRAY_A
);
$agentic_usage_counts = array();
if ( is_array( $agentic_usage_rows ) ) {
	foreach ( $agentic_usage_rows as $agentic_row ) {
		$agentic_usage_counts[ $agentic_row['tool_name'] ] = (int) $agentic_row['call_count'];
	}
}
?>
<div class="wrap agentic-admin">
	<h1 class="agentic-h1-flex">
		<?php esc_html_e( 'Tools', 'agent-builder' ); ?>
	</h1>

	<?php
	$agentic_tools_items = array(
		array(
			'slug'  => 'tools',
			'label' => __( 'Tools', 'agent-builder' ),
			'url'   => admin_url( 'admin.php?page=agentic-tools' ),
		),
	);
	if ( class_exists( '\\Agentic\\Slash_Commands' ) ) {
		$agentic_tools_items[] = array(
			'slug'  => 'slash-commands',
			'label' => __( 'Slash Commands', 'agent-builder' ),
			'url'   => admin_url( 'admin.php?page=agentic-tools&section=slash-commands' ),
		);
	}

	// Vertical nav groups: the top-level sections, plus (when viewing the
	// Tools section) the tool categories as a second group. WP Abilities are
	// folded into this Categories list — "Inbound Abilities" is appended as a
	// category, and outbound abilities are shown inline in the tools table.
	$agentic_vnav_groups = array(
		array(
			'label' => '',
			'items' => $agentic_tools_items,
		),
	);
	if ( 'tools' === $agentic_active_section ) {
		$agentic_category_items = array();
		foreach ( $agentic_category_tabs as $agentic_cat_key => $agentic_cat_label ) {
			$agentic_cat_url = admin_url( 'admin.php?page=agentic-tools' );
			if ( 'all' !== $agentic_cat_key ) {
				$agentic_cat_url = add_query_arg( 'category', $agentic_cat_key, $agentic_cat_url );
			}
			$agentic_category_items[] = array(
				'slug'   => 'cat-' . $agentic_cat_key,
				'label'  => $agentic_cat_label,
				'url'    => $agentic_cat_url,
				'active' => ( $agentic_active_cat === $agentic_cat_key ),
				'badge'  => '<span class="count agentic-count-badge">(' . esc_html( (string) ( $agentic_cat_counts[ $agentic_cat_key ] ?? 0 ) ) . ')</span>',
			);
		}
		if ( $agentic_abilities_api ) {
			$agentic_category_items[] = array(
				'slug'   => 'cat-' . $agentic_inbound_cat,
				'label'  => __( 'Inbound Abilities', 'agent-builder' ),
				'url'    => add_query_arg( 'category', $agentic_inbound_cat, admin_url( 'admin.php?page=agentic-tools' ) ),
				'active' => ( $agentic_active_cat === $agentic_inbound_cat ),
				'badge'  => '<span class="count agentic-count-badge">(' . esc_html( (string) count( $agentic_inbound_tools ) ) . ')</span>',
				'suffix' => '<span class="agentic-nav-dot" title="' . esc_attr__( 'WordPress Abilities API available', 'agent-builder' ) . '"></span>',
			);
		}
		$agentic_vnav_groups[] = array(
			'label' => __( 'Categories', 'agent-builder' ),
			'items' => $agentic_category_items,
		);
	}

	\Agentic\Admin_Vnav::open(
		array(
			'active'     => $agentic_active_section,
			'groups'     => $agentic_vnav_groups,
			'aria_label' => __( 'Tools sections', 'agent-builder' ),
			'id'         => 'agentic-tools-nav',
		)
	);
	?>

<?php
if ( 'slash-commands' === $agentic_active_section ) :
	do_action( 'agentic_tools_slash_commands_tab' );
elseif ( $agentic_inbound_cat === $agentic_active_cat ) :
	$agentic_wp_version = get_bloginfo( 'version' );
	?>

<div class="notice notice-success agentic-mt-16" style="border-left-color:#00a32a;">
<p style="font-size:13px;">
<span class="dashicons dashicons-yes-alt" style="color:#00a32a;vertical-align:text-bottom;"></span>
<strong><?php esc_html_e( 'Connected to the WordPress Abilities API.', 'agent-builder' ); ?></strong>
	<?php
	printf(
	/* translators: %s: WordPress version number */
		esc_html__( 'Your site is running WordPress %s. Agent Builder turns abilities registered by other plugins into tools your agents can use, and publishes its own tools as abilities for WordPress core AI, MCP clients, and the Command Palette.', 'agent-builder' ),
		esc_html( $agentic_wp_version )
	);
	?>
</p>
</div>

	<?php if ( empty( $agentic_inbound_tools ) ) : ?>
<div class="notice notice-info agentic-mt-16">
<p>
<strong><?php esc_html_e( 'No third-party abilities detected.', 'agent-builder' ); ?></strong>
		<?php esc_html_e( 'When other plugins register WordPress abilities, they will appear here as inbound tools that your agents can use.', 'agent-builder' ); ?>
</p>
</div>
<?php else : ?>
<p class="agentic-mt-16 agentic-mb-12 agentic-text-dimmed">
	<?php
	printf(
		esc_html(
		/* translators: %d: number of third-party abilities */
			_n(
				'%d third-party ability available as an inbound tool for your agents.',
				'%d third-party abilities available as inbound tools for your agents.',
				count( $agentic_inbound_tools ),
				'agent-builder'
			)
		),
		(int) count( $agentic_inbound_tools )
	);
	?>
</p>
<table class="widefat striped agentic-table-mt-0">
<thead>
<tr>
<th class="agentic-col-80"><?php esc_html_e( 'Enabled', 'agent-builder' ); ?></th>
<th class="agentic-col-200"><?php esc_html_e( 'Ability Name', 'agent-builder' ); ?></th>
<th class="agentic-col-200"><?php esc_html_e( 'Tool Name', 'agent-builder' ); ?></th>
<th><?php esc_html_e( 'Description', 'agent-builder' ); ?></th>
<th class="agentic-col-200"><?php esc_html_e( 'Parameters', 'agent-builder' ); ?></th>
</tr>
</thead>
<tbody>
	<?php
	foreach ( $agentic_inbound_tools as $agentic_ability_tool ) :
		$agentic_ab_fn      = $agentic_ability_tool['function']['name'] ?? '';
		$agentic_ab_desc    = $agentic_ability_tool['function']['description'] ?? '';
		$agentic_ab_orig    = $agentic_ability_tool['_ability_name'] ?? '';
		$agentic_ab_params  = (array) ( $agentic_ability_tool['function']['parameters']['properties'] ?? array() );
		$agentic_ab_enabled = ! in_array( $agentic_ab_orig, $agentic_disabled_inbound, true );
		?>
<tr<?php echo $agentic_ab_enabled ? '' : ' style="opacity: 0.6;"'; ?> data-ability="<?php echo esc_attr( $agentic_ab_orig ); ?>">
<td class="agentic-td-center">
<label class="agentic-toggle" title="<?php echo $agentic_ab_enabled ? esc_attr__( 'Click to disable this ability', 'agent-builder' ) : esc_attr__( 'Click to enable this ability', 'agent-builder' ); ?>">
<input type="checkbox" class="agentic-inbound-toggle"
data-ability="<?php echo esc_attr( $agentic_ab_orig ); ?>"
		<?php checked( $agentic_ab_enabled ); ?> />
<span class="agentic-toggle-slider"></span>
</label>
</td>
<td><code><?php echo esc_html( $agentic_ab_orig ); ?></code></td>
<td><strong><code><?php echo esc_html( $agentic_ab_fn ); ?></code></strong></td>
<td><?php agentic_render_tool_description( $agentic_ab_desc ); ?></td>
<td>
		<?php if ( ! empty( $agentic_ab_params ) ) : ?>
<code class="agentic-text-xxs"><?php echo esc_html( implode( ', ', array_keys( $agentic_ab_params ) ) ); ?></code>
<?php else : ?>
<span class="agentic-text-muted"><?php esc_html_e( 'None', 'agent-builder' ); ?></span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<div class="agentic-embed-note">
<h3><?php esc_html_e( 'About WP Abilities', 'agent-builder' ); ?></h3>
<ul>
<li><?php esc_html_e( 'Inbound abilities from other plugins are automatically converted to the OpenAI function-calling format and made available to all agents.', 'agent-builder' ); ?></li>
<li><?php esc_html_e( 'Use the Enabled toggle to switch an individual third-party ability on or off. A disabled ability is hidden from every agent and cannot be called through the abilities bridge. (Built-in tools that use an ability internally are controlled by their own toggle in the tools list.)', 'agent-builder' ); ?></li>
<li><?php esc_html_e( 'The "Ability Name" column shows the original WordPress ability identifier (e.g., woocommerce/get-orders); the "Tool Name" column shows the function name your agents use to call it.', 'agent-builder' ); ?></li>
<li><?php esc_html_e( 'Outbound abilities are your own Agent Builder tools, published for MCP, the REST API, and core AI. Each tool in the list shows its ability name underneath — toggle the tool off to unpublish it.', 'agent-builder' ); ?></li>
</ul>
</div>

<?php else : ?>

	<?php if ( empty( $agentic_all_tools ) ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'No tools found. Activate agents to see their available tools.', 'agent-builder' ); ?></p>
		</div>
	<?php else : ?>
		<div class="agentic-table-scroll">
		<table class="widefat striped agentic-table-mt-0 agentic-table-no-top-border">
			<thead>
				<tr>
					<th class="agentic-col-80"><?php esc_html_e( 'Enabled', 'agent-builder' ); ?></th>
					<th class="agentic-col-200"><?php esc_html_e( 'Tool Name', 'agent-builder' ); ?></th>
					<th><?php esc_html_e( 'Description', 'agent-builder' ); ?></th>
					<th class="agentic-col-110"><?php esc_html_e( 'Security', 'agent-builder' ); ?></th>
					<th class="agentic-col-100"><?php esc_html_e( 'Source', 'agent-builder' ); ?></th>
					<th class="agentic-col-100"><?php esc_html_e( 'Category', 'agent-builder' ); ?></th>
					<th class="agentic-col-100"><?php esc_html_e( 'Usage', 'agent-builder' ); ?></th>
					<th class="agentic-col-200"><?php esc_html_e( 'Parameters', 'agent-builder' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $agentic_all_tools as $agentic_tool ) : ?>
				<tr<?php echo $agentic_tool['enabled'] ? '' : ' style="opacity: 0.6;"'; ?> data-tool="<?php echo esc_attr( $agentic_tool['name'] ); ?>">
					<td class="agentic-td-center">
						<label class="agentic-toggle" title="<?php echo $agentic_tool['enabled'] ? esc_attr__( 'Click to disable this tool', 'agent-builder' ) : esc_attr__( 'Click to enable this tool', 'agent-builder' ); ?>">
							<input type="checkbox" class="agentic-tool-toggle"
								data-tool="<?php echo esc_attr( $agentic_tool['name'] ); ?>"
								data-risk="<?php echo esc_attr( $agentic_tool['risk_level'] ?? 'none' ); ?>"
								data-title="<?php echo esc_attr( $agentic_tool['name'] ); ?>"
								<?php checked( $agentic_tool['enabled'] ); ?> />
							<span class="agentic-toggle-slider"></span>
						</label>
					</td>
					<td>
						<strong><code><?php echo esc_html( $agentic_tool['name'] ); ?></code></strong>
						<?php if ( $agentic_abilities_api ) : ?>
							<br />
							<span class="agentic-text-muted agentic-text-xxs" title="<?php esc_attr_e( 'Published as a WordPress ability (MCP, REST, core AI) while this tool is enabled.', 'agent-builder' ); ?>">
								<?php echo esc_html( 'agent-builder/' . str_replace( '_', '-', $agentic_tool['name'] ) ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php agentic_render_tool_description( $agentic_tool['description'] ); ?>
					</td>
					<td>
						<?php
						$agentic_risk        = $agentic_tool['risk_level'] ?? 'none';
						$agentic_risk_styles = array(
							'none'    => 'background: #f0fdf4; color: #166534;',
							'low'     => 'background: #fefce8; color: #854d0e;',
							'medium'  => 'background: #fff7ed; color: #9a3412;',
							'high'    => 'background: #fef2f2; color: #991b1b;',
							'extreme' => 'background: #faf5ff; color: #6b21a8;',
						);
						$agentic_risk_labels = array(
							'none'    => __( 'Read Only', 'agent-builder' ),
							'low'     => __( 'Low', 'agent-builder' ),
							'medium'  => __( 'Write', 'agent-builder' ),
							'high'    => __( 'Bulk Write', 'agent-builder' ),
							'extreme' => __( 'Blocked', 'agent-builder' ),
						);
						$agentic_risk_icons  = array(
							'none'    => '🟢',
							'low'     => '🟡',
							'medium'  => '🟠',
							'high'    => '🔴',
							'extreme' => '⛔',
						);
						$agentic_risk_style  = $agentic_risk_styles[ $agentic_risk ] ?? $agentic_risk_styles['none'];
						$agentic_risk_label  = $agentic_risk_labels[ $agentic_risk ] ?? __( 'Read Only', 'agent-builder' );
						$agentic_risk_icon   = $agentic_risk_icons[ $agentic_risk ] ?? '🟢';
						?>
						<span style="<?php echo esc_attr( $agentic_risk_style ); ?> padding: 2px 8px; border-radius: 3px; font-size: 12px; white-space: nowrap;"
							title="<?php echo esc_attr( ucfirst( $agentic_risk ) . ' risk' ); ?>">
							<?php echo esc_html( $agentic_risk_icon . ' ' . $agentic_risk_label ); ?>
						</span>
					</td>
					<td>
						<?php if ( 'core' === $agentic_tool['source'] ) : ?>
							<span class="agentic-badge-local-sm">
								<?php esc_html_e( 'Core', 'agent-builder' ); ?>
							</span>
						<?php else : ?>
							<?php
							$agentic_agents_for_tool = $agentic_tool_agents[ $agentic_tool['name'] ] ?? array();
							$agentic_source_label    = ! empty( $agentic_agents_for_tool ) ? implode( ', ', $agentic_agents_for_tool ) : ucwords( str_replace( array( '-', '_' ), ' ', $agentic_tool['source'] ) );
							?>
							<span class="agentic-badge-amber">
								<?php echo esc_html( $agentic_source_label ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$agentic_cat_raw = $agentic_tool['category'];
						echo esc_html( $agentic_category_tabs[ $agentic_cat_raw ] ?? ucwords( str_replace( '-', ' ', $agentic_cat_raw ) ) );
						?>
					</td>
					<td>
						<?php
						$agentic_tool_uses = $agentic_usage_counts[ $agentic_tool['name'] ] ?? 0;
						if ( $agentic_tool_uses > 0 ) {
							printf(
								'<span class="agentic-badge-green-sm agentic-fw600">%s</span>',
								/* translators: %s: number of times a tool was called */
								esc_html( sprintf( _n( '%s call', '%s calls', $agentic_tool_uses, 'agent-builder' ), number_format_i18n( $agentic_tool_uses ) ) )
							);
						} else {
							echo '<span class="agentic-text-dim agentic-text-xs">' . esc_html__( 'No calls yet', 'agent-builder' ) . '</span>';
						}
						?>
					</td>
					<td>
						<?php
						$agentic_param_names = is_array( $agentic_tool['parameters'] ) ? array_keys( $agentic_tool['parameters'] ) : array();
						if ( ! empty( $agentic_param_names ) ) {
							echo '<code class="agentic-text-xxs">' . esc_html( implode( ', ', $agentic_param_names ) ) . '</code>';
						} else {
							echo '<span class="agentic-text-muted">' . esc_html__( 'None', 'agent-builder' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>

	<div class="agentic-callout-warning-mt20">
		<h3><?php esc_html_e( 'Tool Permission System', 'agent-builder' ); ?></h3>
		<p class="agentic-mb-8"><?php esc_html_e( 'Use the toggle switches above to control which tools agents can access. When a tool is disabled:', 'agent-builder' ); ?></p>
		<ul class="agentic-mb-10 agentic-list-disc">
			<li><?php esc_html_e( 'The tool is hidden from the AI model — agents will not know it exists and cannot request it.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Even if an agent attempts to call a disabled tool directly, execution is blocked and an error is returned.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Disabled tool calls are logged in the Audit Log for security visibility.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Changes take effect immediately — no restart or reload required.', 'agent-builder' ); ?></li>
		</ul>
		<p class="agentic-mt-0 agentic-text-xxs agentic-text-amber-warn">
			<strong><?php esc_html_e( 'Tip:', 'agent-builder' ); ?></strong>
			<?php esc_html_e( 'Database read tools are enabled by default. Write tools (update option, create/update/delete post) are disabled by default — enable them here when needed.', 'agent-builder' ); ?>
		</p>
	</div>

	<div class="agentic-embed-note">
		<h3><?php esc_html_e( 'About Tools', 'agent-builder' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Core tools are available to all agents and provide file operations, WordPress data access, and schedule management.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'Agent tools are defined by individual agents for their specific functionality (e.g., security scans, content analysis).', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'When chatting with an agent, the LLM decides which tools to call based on the conversation context.', 'agent-builder' ); ?></li>
			<li><?php esc_html_e( 'All tool executions are logged in the Audit Log for transparency and debugging.', 'agent-builder' ); ?></li>
			<?php if ( $agentic_abilities_api ) : ?>
				<li><?php esc_html_e( 'On WordPress 6.9+, every enabled tool is also published as a WordPress ability (shown as agent-builder/… under each tool name) — discoverable by core AI, MCP clients, and the Command Palette. Disable a tool to unpublish its ability. Abilities from other plugins appear under the "Inbound Abilities" category.', 'agent-builder' ); ?></li>
			<?php endif; ?>
		</ul>
	</div>

<?php endif; // End else (section = tools). ?>

<?php \Agentic\Admin_Vnav::close(); ?>
</div>


<!-- Toggle AJAX handler -->
<script>
(function() {
	'use strict';
	var COPY = {
		highTitle: <?php echo wp_json_encode( __( 'Enable high-risk tool?', 'agent-builder' ) ); ?>,
		extremeTitle: <?php echo wp_json_encode( __( 'This tool cannot be enabled', 'agent-builder' ) ); ?>,
		cancel: <?php echo wp_json_encode( __( 'Cancel', 'agent-builder' ) ); ?>,
		enable: <?php echo wp_json_encode( __( 'Enable tool', 'agent-builder' ) ); ?>,
		close: <?php echo wp_json_encode( __( 'Close', 'agent-builder' ) ); ?>,
		highLead: <?php echo wp_json_encode( __( 'can make a significant change to your site.', 'agent-builder' ) ); ?>,
		<?php /* translators: %s: plain-language reason this tool is high-risk, filled in client-side. */ ?>
		highWhy: <?php echo wp_json_encode( __( 'Why this is high-risk: %s', 'agent-builder' ) ); ?>,
		highGuard: <?php echo wp_json_encode( __( 'If an agent uses this tool later, the action will still wait for human review in the Approvals queue before it runs.', 'agent-builder' ) ); ?>,
		highNote: <?php echo wp_json_encode( __( 'Enabling this tool makes it available to eligible agents. It does not run the tool immediately.', 'agent-builder' ) ); ?>,
		extremeLead: <?php echo wp_json_encode( __( 'is marked Extreme Risk.', 'agent-builder' ) ); ?>,
		extremeBody: <?php echo wp_json_encode( __( 'Extreme-risk tools are hidden from agents entirely and blocked from running, because they are too risky for normal use.', 'agent-builder' ) ); ?>,
		highReasonDefault: <?php echo wp_json_encode( __( 'A significant or bulk change — waits in the Approvals queue for you to allow it.', 'agent-builder' ) ); ?>,
		clickDisable: <?php echo wp_json_encode( __( 'Click to disable this tool', 'agent-builder' ) ); ?>,
		clickEnable: <?php echo wp_json_encode( __( 'Click to enable this tool', 'agent-builder' ) ); ?>
	};
	var HIGH_REASONS = <?php
	echo wp_json_encode(
		array(
			'install_plugin_from_url' => __( 'installs code on your site', 'agent-builder' ),
			'force_password_reset'    => __( 'can affect account access', 'agent-builder' ),
			'wc_create_refund'        => __( 'moves money', 'agent-builder' ),
			'delete_form'             => __( 'can permanently remove data', 'agent-builder' ),
			'git_push'                => __( 'changes the deployed codebase', 'agent-builder' ),
			'git_pull'                => __( 'changes the deployed codebase', 'agent-builder' ),
			'git_commit'              => __( 'changes the deployed codebase', 'agent-builder' ),
		)
	);
	?>;

	function highReason( name ) {
		return HIGH_REASONS[ name ] || COPY.highReasonDefault;
	}

	function highBody( title, name ) {
		return title + ' ' + COPY.highLead + '\n\n'
			+ COPY.highWhy.replace( '%s', highReason( name ) ) + '\n\n'
			+ COPY.highGuard + '\n\n'
			+ COPY.highNote;
	}

	function extremeBody( title ) {
		return title + ' ' + COPY.extremeLead + '\n\n' + COPY.extremeBody;
	}

	document.querySelectorAll('.agentic-tool-toggle').forEach(function(toggle) {
		toggle.addEventListener('change', function() {
			var toolName = this.dataset.tool;
			var enabled  = this.checked;
			var risk     = ( this.dataset.risk || 'none' ).toLowerCase();
			var title    = this.dataset.title || toolName;
			var row      = this.closest('tr');
			var label    = this.closest('.agentic-toggle');
			var el       = this;

			function postToggle( nextEnabled ) {
				label.classList.add('is-saving');

				var data = new FormData();
				data.append('action', 'agentic_toggle_tool');
				data.append('tool', toolName);
				data.append('enabled', nextEnabled ? '1' : '0');
				data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'agentic_toggle_tool' ) ); ?>');

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				})
				.then(function(response) { return response.json(); })
				.then(function(result) {
					label.classList.remove('is-saving');
					if (result.success) {
						row.style.opacity = nextEnabled ? '1' : '0.6';
						label.title = nextEnabled ? COPY.clickDisable : COPY.clickEnable;
					} else {
						el.checked = !nextEnabled;
						if ( window.agenticUI && typeof agenticUI.toast === 'function' ) {
							agenticUI.toast(result.data || 'Failed to update tool status.', 'error');
						}
					}
				})
				.catch(function() {
					label.classList.remove('is-saving');
					el.checked = !nextEnabled;
				});
			}

			// Gate HIGH/EXTREME off→on before any POST so the checkbox never
			// flashes on. Disable (on→off) and low/medium never prompt.
			if ( enabled && ( 'high' === risk || 'extreme' === risk ) ) {
				el.checked = false;
				if ( 'extreme' === risk ) {
					if ( window.agenticUI && typeof agenticUI.alert === 'function' ) {
						agenticUI.alert( extremeBody( title ), {
							title: COPY.extremeTitle,
							confirmText: COPY.close
						} );
					}
					return;
				}
				var ask = ( window.agenticUI && typeof agenticUI.confirm === 'function' )
					? agenticUI.confirm( highBody( title, toolName ), {
						title: COPY.highTitle,
						confirmText: COPY.enable,
						cancelText: COPY.cancel
					} )
					: Promise.resolve( false );
				ask.then( function( ok ) {
					if ( ! ok ) {
						return;
					}
					el.checked = true;
					postToggle( true );
				} );
				return;
			}

			postToggle( enabled );
		});
	});

	// Inbound third-party ability toggles (block-list backed).
	var inboundNonce = '<?php echo esc_js( wp_create_nonce( 'agentic_toggle_inbound_ability' ) ); ?>';
	document.querySelectorAll('.agentic-inbound-toggle').forEach(function(toggle) {
			toggle.addEventListener('change', function() {
				var ability = this.dataset.ability;
				var enabled = this.checked;
				var row     = this.closest('tr');
				var label   = this.closest('.agentic-toggle');

				label.classList.add('is-saving');

				var data = new FormData();
				data.append('action', 'agentic_toggle_inbound_ability');
				data.append('ability', ability);
				data.append('enabled', enabled ? '1' : '0');
				data.append('_wpnonce', inboundNonce);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				})
				.then(function(response) { return response.json(); })
				.then(function(result) {
					label.classList.remove('is-saving');
					if (result.success) {
						row.style.opacity = enabled ? '1' : '0.6';
						label.title = enabled
							? '<?php echo esc_js( __( 'Click to disable this ability', 'agent-builder' ) ); ?>'
							: '<?php echo esc_js( __( 'Click to enable this ability', 'agent-builder' ) ); ?>';
					} else {
						toggle.checked = !enabled;
						agenticUI.toast(result.data || 'Failed to update ability status.', 'error');
					}
				})
				.catch(function() {
					label.classList.remove('is-saving');
					toggle.checked = !enabled;
				});
			});
	});
})();
</script>
