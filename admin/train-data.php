<?php
/**
 * Siteloom — Knowledge hub
 *
 * Free: Open Knowledge Format (OKF) wiki — local markdown concepts, no cloud.
 * Pro: Vector Store / RAG training (hosted embeddings) on the Vector tab.
 *
 * WordPress.org: free wiki works fully without Pro; Vector tab is an upgrade
 * surface only when Pro is not active (no paywall on free features).
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'agent_builder_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'siteloom' ) );
}

// Basic/Advanced split (M1 Phase 5). Basic is a landing into the existing
// knowledge-wizard guided flow — no duplicated wizard logic. Advanced keeps
// today's wiki editor + Instructions/Memory/Vector tabs unchanged. Screen
// key 'knowledge' uses the unmapped set_screen_mode fallback
// (agent_builder_manage_settings), which matches this page's capability — same
// as Phase 6 'providers', unlike Phase 4 'agents' which had to be mapped
// because that screen requires agent_builder_manage_agents.
$agentic_knowledge_advanced = \Agentic\Admin_Menu_Handler::is_advanced_mode( 'knowledge' );

// Siteloom Pro (which owns the Vector Store admin UI) can never be
// installed alongside this standalone free build.
$agentic_is_pro = false;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab param.
$agentic_td_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wiki';
if ( ! in_array( $agentic_td_tab, array( 'wiki', 'instructions', 'memory', 'vector' ), true ) ) {
	$agentic_td_tab = 'wiki';
}

$agentic_td_tabs = array(
	'wiki'         => __( 'Wiki', 'siteloom' ),
	'instructions' => __( 'Instructions', 'siteloom' ),
	'memory'       => __( 'Memory', 'siteloom' ),
	'vector'       => __( 'Vector Store', 'siteloom' ) . ( $agentic_is_pro ? '' : ' · Pro' ),
);

// One-line "why does this exist" tooltip per tab — these four are the ones
// new users most often confuse with each other.
$agentic_td_tab_titles = array(
	'wiki'         => __( 'Facts, policies, and FAQs every agent can look up — the site\'s shared source of truth.', 'siteloom' ),
	'instructions' => __( 'How one specific agent talks: its tone, greeting, and persona. Not shared facts — that\'s the Wiki.', 'siteloom' ),
	'memory'       => __( 'Short notes an agent keeps between chats, e.g. things a user told it. Optional and site-owned.', 'siteloom' ),
	'vector'       => __( 'Hosted semantic search over large documents (PDFs, long pages) for faster, more precise recall.', 'siteloom' ),
);

$agentic_scopes = array( 'site' => __( 'Site-wide wiki', 'siteloom' ) );
if ( class_exists( '\Agentic_Agent_Registry' ) ) {
	$agentic_reg = \Agentic_Agent_Registry::get_instance();
	foreach ( $agentic_reg->get_installed_agents() as $agentic_slug => $agentic_info ) {
		$agentic_scopes[ $agentic_slug ] = sprintf(
			/* translators: %s: agent name */
			__( 'Agent: %s', 'siteloom' ),
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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Knowledge', 'siteloom' ); ?></h1>
		</div>
		<span class="agentic-screen-mode-toggle" id="agentic-knowledge-mode-toggle">
			<button type="button" class="button button-small button-primary" data-mode="basic">
				<?php esc_html_e( 'Basic', 'siteloom' ); ?>
			</button>
			<button type="button" class="button button-small" data-mode="advanced">
				<?php esc_html_e( 'Advanced', 'siteloom' ); ?>
			</button>
		</span>
	</div>
	<hr class="wp-header-end">

	<div class="agentic-knowledge-basic">
		<div class="agentic-knowledge-basic__card">
			<h2><?php esc_html_e( 'What do you want your agents to know?', 'siteloom' ); ?></h2>
			<p><?php esc_html_e( 'Teach them a fact, policy, or FAQ in a few steps. Paste text, upload a file, or pick pages that already exist on this site.', 'siteloom' ); ?></p>
			<p class="agentic-knowledge-basic__actions">
				<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-knowledge-wizard' ) ); ?>">
					<?php esc_html_e( 'Add knowledge', 'siteloom' ); ?>
				</a>
			</p>
		</div>
	</div>

<?php else : ?>

	<header class="agentic-kn-hero">
		<div class="agentic-kn-hero-text">
			<p class="agentic-kn-eyebrow"><?php esc_html_e( 'Knowledge', 'siteloom' ); ?></p>
			<h1><?php esc_html_e( 'Teach your agents what is true', 'siteloom' ); ?></h1>
			<p class="agentic-kn-lede">
				<?php esc_html_e( 'The free Knowledge Wiki uses Google’s Open Knowledge Format (OKF): plain markdown concepts you can version, search, and maintain like a document library — entirely on your server.', 'siteloom' ); ?>
			</p>
		</div>
		<div class="agentic-kn-hero-aside">
			<span class="agentic-screen-mode-toggle" id="agentic-knowledge-mode-toggle">
				<button type="button" class="button button-small" data-mode="basic">
					<?php esc_html_e( 'Basic', 'siteloom' ); ?>
				</button>
				<button type="button" class="button button-small button-primary" data-mode="advanced">
					<?php esc_html_e( 'Advanced', 'siteloom' ); ?>
				</button>
			</span>
			<div class="agentic-kn-pill">OKF v<?php echo esc_html( \Agentic\Okf_Store::OKF_VERSION ); ?></div>
			<a class="agentic-kn-docs" href="https://agentic-plugin.com/knowledge-wiki-okf/" target="_blank" rel="noopener">
				<?php esc_html_e( 'Docs →', 'siteloom' ); ?>
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
			'aria_label' => __( 'Knowledge sections', 'siteloom' ),
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
			echo '<p class="description">' . esc_html__( 'Settings UI is unavailable. Rebuild agent-builder assets.', 'siteloom' ) . '</p>';
		}
		?>

	<?php elseif ( 'wiki' === $agentic_td_tab ) : ?>
	<div class="agentic-kn-wiki" id="agentic-kn-wiki"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'agentic_okf' ) ); ?>"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

		<div class="agentic-kn-toolbar">
			<label class="agentic-kn-scope">
				<span><?php esc_html_e( 'Wiki', 'siteloom' ); ?></span>
				<select id="agentic-okf-scope">
					<?php foreach ( $agentic_scopes as $agentic_scope_key => $agentic_scope_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_scope_key ); ?>"><?php echo esc_html( $agentic_scope_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="agentic-kn-search-wrap">
				<input type="search" id="agentic-okf-search" placeholder="<?php esc_attr_e( 'Search concepts…', 'siteloom' ); ?>" />
			</div>
			<div class="agentic-kn-toolbar-actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-knowledge-wizard' ) ); ?>"><?php esc_html_e( 'Guided setup', 'siteloom' ); ?></a>
				<button type="button" class="button" id="agentic-okf-export"><?php esc_html_e( 'Export', 'siteloom' ); ?></button>
				<button type="button" class="button" id="agentic-okf-import-persona"><?php esc_html_e( 'Import persona text', 'siteloom' ); ?></button>
				<button type="button" class="button button-primary" id="agentic-okf-new"><?php esc_html_e( 'New concept', 'siteloom' ); ?></button>
			</div>
		</div>

		<div class="agentic-kn-layout">
			<aside class="agentic-kn-list-panel">
				<div class="agentic-kn-list-head">
					<strong id="agentic-okf-count">0</strong>
					<span><?php esc_html_e( 'concepts', 'siteloom' ); ?></span>
				</div>
				<ul id="agentic-okf-list" class="agentic-kn-list" aria-label="<?php esc_attr_e( 'Concepts', 'siteloom' ); ?>"></ul>
				<p class="agentic-kn-empty" id="agentic-okf-empty" hidden>
					<?php esc_html_e( 'No concepts yet. Create a FAQ, policy, or product fact to get started.', 'siteloom' ); ?>
				</p>
				<p class="agentic-kn-empty" id="agentic-okf-empty-filtered" hidden>
					<?php esc_html_e( 'No concepts match your search. Try a different keyword or clear the search.', 'siteloom' ); ?>
				</p>
			</aside>

			<section class="agentic-kn-editor-panel" id="agentic-okf-editor" hidden>
				<div class="agentic-kn-editor-head">
					<h2 id="agentic-okf-editor-title"><?php esc_html_e( 'Concept', 'siteloom' ); ?></h2>
					<div class="agentic-kn-editor-actions">
						<button type="button" class="button button-link-delete" id="agentic-okf-delete"><?php esc_html_e( 'Delete', 'siteloom' ); ?></button>
						<button type="button" class="button button-primary" id="agentic-okf-save"><?php esc_html_e( 'Save concept', 'siteloom' ); ?></button>
					</div>
				</div>

				<div class="agentic-kn-fields">
					<div class="agentic-kn-field">
						<label for="agentic-okf-title"><?php esc_html_e( 'Title', 'siteloom' ); ?></label>
						<input type="text" id="agentic-okf-title" class="regular-text" placeholder="<?php esc_attr_e( 'Returns policy', 'siteloom' ); ?>" />
					</div>
					<div class="agentic-kn-field agentic-kn-field-id">
						<label for="agentic-okf-id">
							<?php esc_html_e( 'ID', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About ID', 'siteloom' ); ?>" title="<?php esc_attr_e( 'Short name for this concept (e.g. returns-policy). Filled in for you from the title if left blank.', 'siteloom' ); ?>"></span>
						</label>
						<input type="text" id="agentic-okf-id" class="regular-text code" placeholder="returns-policy" autocomplete="off" />
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-type">
							<?php esc_html_e( 'Type', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About type', 'siteloom' ); ?>" title="<?php esc_attr_e( 'What kind of knowledge this is. Choose Custom to enter your own.', 'siteloom' ); ?>"></span>
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
							<option value="__custom__"><?php esc_html_e( 'Custom…', 'siteloom' ); ?></option>
						</select>
						<input
							type="text"
							id="agentic-okf-type-custom"
							class="regular-text agentic-kn-type-custom"
							hidden
							placeholder="<?php esc_attr_e( 'Your type', 'siteloom' ); ?>"
							autocomplete="off"
						/>
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-tags">
							<?php esc_html_e( 'Keywords', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About keywords', 'siteloom' ); ?>" title="<?php esc_attr_e( 'Optional keywords, comma-separated. Not WordPress post tags.', 'siteloom' ); ?>"></span>
						</label>
						<input type="text" id="agentic-okf-tags" class="regular-text" placeholder="<?php esc_attr_e( 'shipping, returns', 'siteloom' ); ?>" />
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-status">
							<?php esc_html_e( 'Status', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About status', 'siteloom' ); ?>" title="<?php esc_attr_e( 'Draft = work in progress. Stable = ready. Deprecated = keep for history, prefer not to use.', 'siteloom' ); ?>"></span>
						</label>
						<select id="agentic-okf-status">
							<option value="draft"><?php esc_html_e( 'Draft', 'siteloom' ); ?></option>
							<option value="stable" selected><?php esc_html_e( 'Stable', 'siteloom' ); ?></option>
							<option value="deprecated"><?php esc_html_e( 'Deprecated', 'siteloom' ); ?></option>
						</select>
					</div>
					<div class="agentic-kn-field">
						<label for="agentic-okf-stale">
							<?php esc_html_e( 'Review by', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About review date', 'siteloom' ); ?>" title="<?php esc_attr_e( 'Optional. After this date the concept is marked as needing a refresh.', 'siteloom' ); ?>"></span>
						</label>
						<input type="date" id="agentic-okf-stale" />
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label class="agentic-kn-example-label">
							<input type="checkbox" id="agentic-okf-example" value="1" />
							<span class="agentic-kn-example-badge"><?php esc_html_e( 'Example', 'siteloom' ); ?></span>
							<?php esc_html_e( 'Demo only — agents cannot use this until you uncheck Example and adapt it for your site.', 'siteloom' ); ?>
						</label>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label class="agentic-kn-example-label">
							<input type="checkbox" id="agentic-okf-always-on" value="1" />
							<strong><?php esc_html_e( 'Always include in prompts', 'siteloom' ); ?></strong>
							<?php esc_html_e( '— inject this concept into every agent’s system prompt (best for site overview, tone, standing policies). Keep short.', 'siteloom' ); ?>
						</label>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label for="agentic-okf-description">
							<?php esc_html_e( 'Summary', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About summary', 'siteloom' ); ?>" title="<?php esc_attr_e( 'One sentence for the list and search results.', 'siteloom' ); ?>"></span>
						</label>
						<textarea id="agentic-okf-description" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'One sentence that sums this up.', 'siteloom' ); ?>"></textarea>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label for="agentic-okf-body">
							<?php esc_html_e( 'Content', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About content', 'siteloom' ); ?>" title="<?php esc_attr_e( 'What agents should know. Write plainly; Markdown is optional.', 'siteloom' ); ?>"></span>
						</label>
						<textarea id="agentic-okf-body" rows="14" class="large-text code" placeholder="<?php esc_attr_e( 'We accept returns within 30 days with proof of purchase…', 'siteloom' ); ?>"></textarea>
					</div>
					<div class="agentic-kn-field agentic-kn-field-full">
						<label for="agentic-okf-resource">
							<?php esc_html_e( 'Related link (optional)', 'siteloom' ); ?>
							<span class="agentic-kn-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About related link', 'siteloom' ); ?>" title="<?php esc_attr_e( 'Link to a page or doc this is about. Content above is still the main knowledge.', 'siteloom' ); ?>"></span>
						</label>
						<input type="url" id="agentic-okf-resource" class="large-text" placeholder="https://" />
					</div>
				</div>
				<p class="agentic-kn-status" id="agentic-okf-status-msg" role="status" aria-live="polite"></p>
			</section>

			<section class="agentic-kn-empty-editor" id="agentic-okf-placeholder">
				<div class="agentic-kn-empty-card">
					<span class="dashicons dashicons-book-alt"></span>
					<h2><?php esc_html_e( 'Your knowledge wiki', 'siteloom' ); ?></h2>
					<p><?php esc_html_e( 'Add facts, policies, and FAQs your agents can use.', 'siteloom' ); ?></p>
					<button type="button" class="button button-primary" id="agentic-okf-new-2"><?php esc_html_e( 'Add knowledge', 'siteloom' ); ?></button>
				</div>
			</section>
		</div>
	</div>

	<?php else : /* Vector tab */ ?>

		<?php if ( $agentic_is_pro ) : ?>
			<?php
			// Vector Store admin UI lives only in Siteloom Pro (filter).
			// Free never ships Pro markup — see bin/strip-free-pro-paths.sh.
			$agentic_vector_partial = apply_filters( 'agentic_vector_store_admin_partial', '' );
			if ( is_string( $agentic_vector_partial ) && '' !== $agentic_vector_partial && file_exists( $agentic_vector_partial ) ) {
				include $agentic_vector_partial;
			} else {
				?>
				<div class="notice notice-warning inline" style="margin:12px 0;">
					<p>
						<?php esc_html_e( 'Pro is active but the Vector Store admin UI was not found. Reinstall or update Siteloom Pro.', 'siteloom' ); ?>
					</p>
				</div>
				<?php
			}
			?>
		<?php else : ?>
			<p class="agentic-kn-pro-note">
				<?php
				esc_html_e( 'The free Knowledge Wiki stays fully available without Pro. Need large-scale semantic search across big document sets instead? Hosted vector RAG is available separately in Siteloom Pro.', 'siteloom' );
				?>
			</p>
		<?php endif; ?>

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
