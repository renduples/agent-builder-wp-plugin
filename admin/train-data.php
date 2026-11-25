<?php
/**
 * AgentStructor — Knowledge hub
 *
 * Open Knowledge Format (OKF) wiki — local markdown concepts, no cloud.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'agent_builder_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'agentstructor' ) );
}

// Basic/Advanced split (M1 Phase 5). Basic is a landing into the existing
// knowledge-wizard guided flow — no duplicated wizard logic. Advanced keeps
// today's wiki editor + Instructions/Memory/Vector tabs unchanged. Screen
// key 'knowledge' uses the unmapped set_screen_mode fallback
// (agent_builder_manage_settings), which matches this page's capability — same
// as Phase 6 'providers', unlike Phase 4 'agents' which had to be mapped
// because that screen requires agent_builder_manage_agents.
$agentic_knowledge_advanced = \Agentic\Admin_Menu_Handler::is_advanced_mode( 'knowledge' );

// AgentStructor Pro (which owns the Vector Store admin UI) can never be
// installed alongside this standalone free build.
$agentic_is_pro = false;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab param.
$agentic_td_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wiki';
// The Vector Store tab exists only when Pro provides it; the free plugin
// shows no tab for a feature it does not include.
$agentic_td_allowed = $agentic_is_pro ? array( 'wiki', 'instructions', 'memory', 'vector' ) : array( 'wiki', 'instructions', 'memory' );
if ( ! in_array( $agentic_td_tab, $agentic_td_allowed, true ) ) {
	$agentic_td_tab = 'wiki';
}

$agentic_td_tabs = array(
	'wiki'         => __( 'Wiki', 'agentstructor' ),
	'instructions' => __( 'Instructions', 'agentstructor' ),
	'memory'       => __( 'Memory', 'agentstructor' ),
);
if ( $agentic_is_pro ) {
	$agentic_td_tabs['vector'] = __( 'Vector Store', 'agentstructor' );
}

// One-line "why does this exist" tooltip per tab — these four are the ones
// new users most often confuse with each other.
$agentic_td_tab_titles = array(
	'wiki'         => __( 'Facts, policies, and FAQs every agent can look up — the site\'s shared source of truth.', 'agentstructor' ),
	'instructions' => __( 'How one specific agent talks: its tone, greeting, and persona. Not shared facts — that\'s the Wiki.', 'agentstructor' ),
	'memory'       => __( 'Short notes an agent keeps between chats, e.g. things a user told it. Optional and site-owned.', 'agentstructor' ),
	'vector'       => __( 'Hosted semantic search over large documents (PDFs, long pages) for faster, more precise recall.', 'agentstructor' ),
);

$agentic_scopes = array( 'site' => __( 'Site-wide wiki', 'agentstructor' ) );
if ( class_exists( '\Agentic_Agent_Registry' ) ) {
	$agentic_reg = \Agentic_Agent_Registry::get_instance();
	foreach ( $agentic_reg->get_installed_agents() as $agentic_slug => $agentic_info ) {
		$agentic_scopes[ $agentic_slug ] = sprintf(
			/* translators: %s: agent name */
			__( 'Agent: %s', 'agentstructor' ),
			$agentic_info['name'] ?? $agentic_slug
		);
	}
}

if ( $agentic_knowledge_advanced ) {
	\Agentic\Okf_Store::ensure_bundle( 'site' );
}
?>
<div class="wrap agentic-admin agentic-knowledge">
<?php if ( ! $agentic_knowledge_advanced ) : ?>

	<div class="agentic-react-admin__page-head">
		<div>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Knowledge', 'agentstructor' ); ?></h1>
		</div>
		<span class="agentic-screen-mode-toggle" id="agentic-knowledge-mode-toggle">
			<button type="button" class="button button-small button-primary" data-mode="basic">
				<?php esc_html_e( 'Basic', 'agentstructor' ); ?>
			</button>
			<button type="button" class="button button-small" data-mode="advanced">
				<?php esc_html_e( 'Advanced', 'agentstructor' ); ?>
			</button>
		</span>
	</div>
	<hr class="wp-header-end">

	<div class="agentic-knowledge-basic">
		<div class="agentic-knowledge-basic__card">
			<h2><?php esc_html_e( 'What do you want your agents to know?', 'agentstructor' ); ?></h2>
			<p><?php esc_html_e( 'Teach them a fact, policy, or FAQ in a few steps. Paste text, upload a file, or pick pages that already exist on this site.', 'agentstructor' ); ?></p>
			<p class="agentic-knowledge-basic__actions">
				<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-knowledge-wizard' ) ); ?>">
					<?php esc_html_e( 'Add knowledge', 'agentstructor' ); ?>
				</a>
			</p>
		</div>
	</div>

<?php else : ?>

	<header class="agentic-kn-hero">
		<div class="agentic-kn-hero-text">
			<p class="agentic-kn-eyebrow"><?php esc_html_e( 'Knowledge', 'agentstructor' ); ?></p>
			<h1><?php esc_html_e( 'Teach your agents what is true', 'agentstructor' ); ?></h1>
			<p class="agentic-kn-lede">
				<?php esc_html_e( 'The free Knowledge Wiki uses Google’s Open Knowledge Format (OKF): plain markdown concepts you can version, search, and maintain like a document library — entirely on your server.', 'agentstructor' ); ?>
			</p>
		</div>
		<div class="agentic-kn-hero-aside">
			<span class="agentic-screen-mode-toggle" id="agentic-knowledge-mode-toggle">
				<button type="button" class="button button-small" data-mode="basic">
					<?php esc_html_e( 'Basic', 'agentstructor' ); ?>
				</button>
				<button type="button" class="button button-small button-primary" data-mode="advanced">
					<?php esc_html_e( 'Advanced', 'agentstructor' ); ?>
				</button>
			</span>
			<div class="agentic-kn-pill">OKF v<?php echo esc_html( \Agentic\Okf_Store::OKF_VERSION ); ?></div>
			<a class="agentic-kn-docs" href="https://agentic-plugin.com/knowledge-wiki-okf/" target="_blank" rel="noopener">
				<?php esc_html_e( 'Docs →', 'agentstructor' ); ?>
			</a>
		</div>
	</header>

	<?php
	$agentic_td_items = array();
	foreach ( $agentic_td_tabs as $agentic_td_slug => $agentic_td_label ) {
		$agentic_td_items[] = array(
			'slug'  => $agentic_td_slug,
			'label' => $agentic_td_label,
			'url'   => admin_url( 'admin.php?page=agentic-train-data&tab=' . $agentic_td_slug ),
			'title' => $agentic_td_tab_titles[ $agentic_td_slug ] ?? '',
		);
	}
	\Agentic\Admin_Vnav::open(
		array(
			'active'     => $agentic_td_tab,
			'items'      => $agentic_td_items,
			'aria_label' => __( 'Knowledge sections', 'agentstructor' ),
			'id'         => 'agentic-knowledge-nav',
		)
	);
	?>

	<?php if ( in_array( $agentic_td_tab, array( 'instructions', 'memory' ), true ) ) : ?>
		<?php
		// React panels (same as former Settings tabs).
		if ( class_exists( '\Agentic\React_Admin' ) && \Agentic\React_Admin::enqueue( 'settings-app' ) ) {
			$agentic_footer = class_exists( '\Agentic\Admin_Menu_Handler' )
				? ( new \Agentic\Admin_Menu_Handler() )->get_admin_footer_data( 'agentic-train-data', $agentic_td_tab )
				: array();
			wp_localize_script(
				'agentic-settings-app',
				'agenticSettingsBoot',
				array(
					'knowledgeEmbed' => true,
					'forceTab'       => $agentic_td_tab,
					'restUrl'        => rest_url( 'agentic/v1/' ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'footer'         => $agentic_footer,
					'footerByTab'    => array(
						'instructions' => class_exists( '\Agentic\Admin_Menu_Handler' )
							? ( new \Agentic\Admin_Menu_Handler() )->get_admin_footer_data( 'agentic-train-data', 'instructions' )
							: array(),
						'memory'       => class_exists( '\Agentic\Admin_Menu_Handler' )
							? ( new \Agentic\Admin_Menu_Handler() )->get_admin_footer_data( 'agentic-train-data', 'memory' )
							: array(),
					),
				)
			);
			echo '<div class="agentic-kn-panel agentic-kn-panel--settings">';
			\Agentic\React_Admin::mount( 'agentic-settings-app-root' );
			echo '</div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Settings UI is unavailable. Rebuild agent-builder assets.', 'agentstructor' ) . '</p>';
		}
		?>

	<?php elseif ( 'wiki' === $agentic_td_tab ) : ?>
	<div class="agentic-kn-wiki" id="agentic-kn-wiki"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'agentic_okf' ) ); ?>"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

		<div class="agentic-kn-toolbar">
			<label class="agentic-kn-scope">
				<span><?php esc_html_e( 'Wiki', 'agentstructor' ); ?></span>
				<select id="agentic-okf-scope">
					<?php foreach ( $agentic_scopes as $agentic_scope_key => $agentic_scope_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_scope_key ); ?>"><?php echo esc_html( $agentic_scope_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="agentic-kn-search-wrap">
				<input type="search" id="agentic-okf-search" placeholder="<?php esc_attr_e( 'Search concepts…', 'agentstructor' ); ?>" />
			</div>
			<div class="agentic-kn-toolbar-actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-knowledge-wizard' ) ); ?>"><?php esc_html_e( 'Guided setup', 'agentstructor' ); ?></a>
				<button type="button" class="button" id="agentic-okf-export"><?php esc_html_e( 'Export', 'agentstructor' ); ?></button>
				<button type="button" class="button" id="agentic-okf-import-persona"><?php esc_html_e( 'Import persona text', 'agentstructor' ); ?></button>
				<button type="button" class="button button-primary" id="agentic-okf-new"><?php esc_html_e( 'New concept', 'agentstructor' ); ?></button>
			</div>
		</div>

		<div class="agentic-kn-layout">
			<aside class="agentic-kn-list-panel">
				<div class="agentic-kn-list-head">
					<strong id="agentic-okf-count">0</strong>
					<span><?php esc_html_e( 'concepts', 'agentstructor' ); ?></span>
				</div>
				<ul id="agentic-okf-list" class="agentic-kn-list" aria-label="<?php esc_attr_e( 'Concepts', 'agentstructor' ); ?>"></ul>
				<p class="agentic-kn-empty" id="agentic-okf-empty" hidden>
					<?php esc_html_e( 'No concepts yet. Create a FAQ, policy, or product fact to get started.', 'agentstructor' ); ?>
				</p>
				<p class="agentic-kn-empty" id="agentic-okf-empty-filtered" hidden>
					<?php esc_html_e( 'No concepts match your search. Try a different keyword or clear the search.', 'agentstructor' ); ?>
				</p>
			</aside>

			<section class="agentic-kn-editor-panel" id="agentic-okf-editor" hidden>
				<div class="agentic-kn-editor-head">
					<h2 id="agentic-okf-editor-title"><?php esc_html_e( 'Concept', 'agentstructor' ); ?></h2>
					<div class="agentic-kn-editor-actions">
						<button type="button" class="button button-link-delete" id="agentic-okf-delete"><?php esc_html_e( 'Delete', 'agentstructor' ); ?></button>
						<button type="button" class="button button-primary" id="agentic-okf-save"><?php esc_html_e( 'Save concept', 'agentstructor' ); ?></button>
					</div>
				</div>

				<div class="agentic-kn-fields">
					<div class="agentic-kn-field">
						<label for="agentic-okf-title"><?php esc_html_e( 'Title', 'agentstructor' ); ?></label>
						<input type="text" id="agentic-okf-title" class="regular-text" placeholder="<?php esc_attr_e( 'Returns policy', 'agentstructor' ); ?>" />
					</div>
					<div class="agentic-kn-field agentic-kn-field-id">
						<label for="agentic-okf-id">
							<?php esc_html_e( 'ID', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About ID', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'Short name for this concept (e.g. returns-policy). Filled in for you from the title if left blank.', 'agentstructor' ); ?>"></span>
						</label>
						<input type="text" id="agentic-okf-id" class="regular-text code" placeholder="returns-policy" autocomplete="off" />
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-type">
							<?php esc_html_e( 'Type', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About type', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'What kind of knowledge this is. Choose Custom to enter your own.', 'agentstructor' ); ?>"></span>
						</label>
						<?php
						$agentic_okf_types = array(
							'FAQ',
							'Policy',
							'Playbook',
							'Brand voice',
							'Product',
							'Service',
							'Pricing',
							'Shipping',
							'Returns',
							'Hours & location',
							'Contact',
							'Team',
							'Page brief',
							'Post outline',
							'SEO notes',
							'Schema notes',
							'Menu & navigation',
							'Form',
							'WooCommerce',
							'Membership',
							'Support reply',
							'Escalation',
							'Onboarding',
							'Runbook',
							'Incident',
							'Security',
							'Plugin notes',
							'Theme notes',
							'Integrations',
							'API',
							'Metric',
							'Glossary',
							'Reference',
						);
						?>
						<select id="agentic-okf-type" class="agentic-kn-type-select">
							<?php foreach ( $agentic_okf_types as $agentic_okf_type ) : ?>
								<option value="<?php echo esc_attr( $agentic_okf_type ); ?>" <?php selected( $agentic_okf_type, 'FAQ' ); ?>>
									<?php echo esc_html( $agentic_okf_type ); ?>
								</option>
							<?php endforeach; ?>
							<option value="__custom__"><?php esc_html_e( 'Custom…', 'agentstructor' ); ?></option>
						</select>
						<input
							type="text"
							id="agentic-okf-type-custom"
							class="regular-text agentic-kn-type-custom"
							hidden
							placeholder="<?php esc_attr_e( 'Your type', 'agentstructor' ); ?>"
							autocomplete="off"
						/>
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-tags">
							<?php esc_html_e( 'Keywords', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About keywords', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'Optional keywords, comma-separated. Not WordPress post tags.', 'agentstructor' ); ?>"></span>
						</label>
						<input type="text" id="agentic-okf-tags" class="regular-text" placeholder="<?php esc_attr_e( 'shipping, returns', 'agentstructor' ); ?>" />
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-status">
							<?php esc_html_e( 'Status', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About status', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'Draft = work in progress. Stable = ready. Deprecated = keep for history, prefer not to use.', 'agentstructor' ); ?>"></span>
						</label>
						<select id="agentic-okf-status">
							<option value="draft"><?php esc_html_e( 'Draft', 'agentstructor' ); ?></option>
							<option value="stable" selected><?php esc_html_e( 'Stable', 'agentstructor' ); ?></option>
							<option value="deprecated"><?php esc_html_e( 'Deprecated', 'agentstructor' ); ?></option>
						</select>
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-stale">
							<?php esc_html_e( 'Review by', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About review date', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'Optional. After this date the concept is marked as needing a refresh.', 'agentstructor' ); ?>"></span>
						</label>
						<input type="date" id="agentic-okf-stale" />
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label class="agentic-kn-example-label">
							<input type="checkbox" id="agentic-okf-example" value="1" />
							<span class="agentic-kn-example-badge"><?php esc_html_e( 'Example', 'agentstructor' ); ?></span>
							<?php esc_html_e( 'Demo only — agents cannot use this until you uncheck Example and adapt it for your site.', 'agentstructor' ); ?>
						</label>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label class="agentic-kn-example-label">
							<input type="checkbox" id="agentic-okf-always-on" value="1" />
							<strong><?php esc_html_e( 'Always include in prompts', 'agentstructor' ); ?></strong>
							<?php esc_html_e( '— inject this concept into every agent’s system prompt (best for site overview, tone, standing policies). Keep short.', 'agentstructor' ); ?>
						</label>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label for="agentic-okf-description">
							<?php esc_html_e( 'Summary', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About summary', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'One sentence for the list and search results.', 'agentstructor' ); ?>"></span>
						</label>
						<textarea id="agentic-okf-description" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'One sentence that sums this up.', 'agentstructor' ); ?>"></textarea>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label for="agentic-okf-body">
							<?php esc_html_e( 'Content', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About content', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'What agents should know. Write plainly; Markdown is optional.', 'agentstructor' ); ?>"></span>
						</label>
						<textarea id="agentic-okf-body" rows="14" class="large-text code" placeholder="<?php esc_attr_e( 'We accept returns within 30 days with proof of purchase…', 'agentstructor' ); ?>"></textarea>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label for="agentic-okf-resource">
							<?php esc_html_e( 'Related link (optional)', 'agentstructor' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About related link', 'agentstructor' ); ?>" title="<?php esc_attr_e( 'Link to a page or doc this is about. Content above is still the main knowledge.', 'agentstructor' ); ?>"></span>
						</label>
						<input type="url" id="agentic-okf-resource" class="large-text" placeholder="https://" />
					</div>
				</div>
				<p class="agentic-kn-status" id="agentic-okf-status-msg" role="status" aria-live="polite"></p>
			</section>

			<section class="agentic-kn-empty-editor" id="agentic-okf-placeholder">
				<div class="agentic-kn-empty-card">
					<span class="dashicons dashicons-book-alt"></span>
					<h2><?php esc_html_e( 'Your knowledge wiki', 'agentstructor' ); ?></h2>
					<p><?php esc_html_e( 'Add facts, policies, and FAQs your agents can use.', 'agentstructor' ); ?></p>
					<button type="button" class="button button-primary" id="agentic-okf-new-2"><?php esc_html_e( 'Add knowledge', 'agentstructor' ); ?></button>
				</div>
			</section>
		</div>
	</div>

	<?php endif; ?>

	<?php \Agentic\Admin_Vnav::close(); ?>

<?php endif; ?>
</div>

<?php
\Agentic\Inline_Assets::script(
	'train-data',
	array(
		'restUrl' => esc_url_raw( rest_url( 'agentic/v1/admin-page' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
	),
	'agenticTrainData'
);
?>
