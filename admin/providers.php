<?php
/**
 * Providers Tab — CRUD table and add/edit form.
 *
 * Included by admin/settings.php when the active tab is 'providers'.
 * The parent page has already verified manage_options capability.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      1.9.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_all_providers   = \Agentic\Provider_Registry::get_all();
$agentic_current_default = get_option( 'agentic_llm_provider', 'agentic' );
$agentic_prov_saved      = isset( $_GET['saved'] ) && '1' === $_GET['saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Basic/Advanced split (M1 Phase 6). Basic narrows the add/edit form to
// name + API key (plus endpoint/slug when adding, since a brand-new custom
// provider has no sensible inferred value for either); every other field
// is still submitted on save via a hidden input carrying either the
// existing value (editing) or a recommended default (adding), so no field
// is ever silently dropped — see the hidden-field blocks below.
$agentic_providers_advanced = \Agentic\Admin_Menu_Handler::is_advanced_mode( 'providers' );

// Classic PHP page — reuse react-admin.css purely for the shared
// .agentic-screen-mode-toggle styling, same pattern as admin/deployment.php.
wp_enqueue_style( 'agentic-react-admin', AGENT_BUILDER_URL . 'assets/css/react-admin.css', array(), AGENT_BUILDER_VERSION );

// Edit mode: load the provider being edited.
$agentic_edit_slug = isset( $_GET['edit_provider'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? sanitize_key( wp_unslash( $_GET['edit_provider'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';
$agentic_edit_prov = $agentic_edit_slug ? \Agentic\Provider_Registry::get( $agentic_edit_slug ) : null;
$agentic_show_form = isset( $_GET['add_provider'] ) || null !== $agentic_edit_prov; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$agentic_auth_type_labels = array(
	'bearer'    => 'Bearer token (Authorization: Bearer {key})',
	'anthropic' => 'Anthropic (x-api-key header)',
	'url_key'   => 'Key in URL (%KEY% placeholder)',
	'none'      => 'None — no auth header (e.g. Ollama)',
);

$agentic_req_format_labels = array(
	'openai'    => 'OpenAI-compatible (default)',
	'anthropic' => 'Anthropic (messages + system)',
	'google'    => 'Google Gemini (contents/parts)',
	'agentic'   => 'Agentic / Ollama native (/api/chat)',
);

$agentic_resp_format_labels = array(
	'openai'         => 'OpenAI (no normalisation needed)',
	'cohere'         => 'Cohere v2 (auto-normalised)',
	'agentic_ollama' => 'Ollama /api/chat (auto-normalised)',
);

// Nonce for the live model-fetch AJAX call.
$agentic_models_nonce = wp_create_nonce( 'agentic_fetch_provider_models' );

// Whether the edited provider has a stored API key — used to decide whether to auto-fetch on page load.
$agentic_form_has_key    = false;
$agentic_current_api_key = '';
if ( $agentic_edit_slug ) {
	$agentic_at              = $agentic_edit_prov['auth_type'] ?? 'bearer';
	$agentic_current_api_key = $agentic_edit_prov['api_key'] ?? ''; // From DB.
	// Legacy fallback for installs not yet re-saved.
	if ( empty( $agentic_current_api_key ) ) {
		$agentic_api_keys        = get_option( 'agentic_llm_api_keys', array() );
		$agentic_current_api_key = $agentic_api_keys[ $agentic_edit_slug ] ?? '';
	}
	if ( 'none' === $agentic_at ) {
		$agentic_form_has_key = true; // No key needed — always fetchable.
	} else {
		$agentic_form_has_key = ! empty( $agentic_current_api_key );
	}
}
?>

<div class="agentic-react-admin__page-head">
	<div>
	<?php if ( ! $agentic_show_form ) : ?>
		<p class="agentic-settings-lead"><?php esc_html_e( 'Configure the AI providers available on this site.', 'agent-builder' ); ?></p>
		<p class="agentic-settings-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=providers&add_provider=1' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add Provider', 'agent-builder' ); ?></a>
		</p>
	<?php endif; ?>
	</div>
	<span class="agentic-screen-mode-toggle" id="agentic-providers-mode-toggle">
		<button type="button" class="button button-small<?php echo ! $agentic_providers_advanced ? ' button-primary' : ''; ?>" data-mode="basic">
			<?php esc_html_e( 'Basic', 'agent-builder' ); ?>
		</button>
		<button type="button" class="button button-small<?php echo $agentic_providers_advanced ? ' button-primary' : ''; ?>" data-mode="advanced">
			<?php esc_html_e( 'Advanced', 'agent-builder' ); ?>
		</button>
	</span>
</div>

<?php if ( $agentic_prov_saved ) : ?>
<div class="notice notice-success is-dismissible agentic-mt-12"><p>Provider saved successfully.</p></div>
<?php endif; ?>

<?php if ( $agentic_show_form ) : ?>
<div class="agentic-form-card">
	<div class="agentic-form-card-head">
		<h3>
			<?php echo $agentic_edit_prov ? esc_html__( 'Edit Provider', 'agent-builder' ) : esc_html__( 'Add Provider', 'agent-builder' ); ?>
		</h3>
	</div>
	<div class="agentic-form-card-body">
	<?php if ( ! $agentic_edit_prov ) : ?>
	<p class="description agentic-mb-12">
		<?php esc_html_e( 'Use an endpoint that speaks the OpenAI Chat Completions format (many hosts expose this as /v1/chat/completions).', 'agent-builder' ); ?>
	</p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=providers' ) ); ?>">
		<?php wp_nonce_field( 'agentic_provider_nonce' ); ?>
		<input type="hidden" name="agentic_provider_action" value="save">
		<input type="hidden" name="provider_sort_order" value="<?php echo esc_attr( $agentic_edit_prov['sort_order'] ?? 99 ); ?>">
		<?php
		// Basic mode hides the fields below; a new provider still needs a
		// slug + endpoint to be functional (no sensible default exists for
		// either), so those two stay visible when adding even in Basic --
		// everything else falls back to a hidden input carrying either the
		// existing value (editing) or a recommended default (adding), so
		// no field's value is ever silently dropped.
		$agentic_show_identity_fields = $agentic_providers_advanced || ! $agentic_edit_prov;
		?>

		<table class="form-table agentic-table-mt-0">
			<tr>
				<th scope="row" class="agentic-col-180"><label for="provider_name">Name</label></th>
				<td>
					<input type="text" id="provider_name" name="provider_name" class="regular-text"
						value="<?php echo esc_attr( $agentic_edit_prov['name'] ?? '' ); ?>" required>
				</td>
			</tr>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_icon">Icon URL</label></th>
				<td>
					<input type="text" id="provider_icon" name="provider_icon" class="regular-text"
						value="<?php echo esc_attr( $agentic_edit_prov['icon'] ?? '' ); ?>"
						placeholder="https://example.com/icon.png">
					<?php
					$agentic_icon_preview = $agentic_edit_prov['icon'] ?? '';
					if ( ! empty( $agentic_icon_preview ) && ( str_starts_with( $agentic_icon_preview, 'http://' ) || str_starts_with( $agentic_icon_preview, 'https://' ) ) ) :
						?>
					<p class="agentic-mt-6"><img src="<?php echo esc_url( $agentic_icon_preview ); ?>" width="32" height="32" class="agentic-icon-preview-img" alt="Icon preview"></p>
					<?php endif; ?>
					<p class="description">Full URL to the provider's icon image (PNG, SVG, JPG). For built-in providers leave this as-is — it maps to a bundled SVG.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_icon" value="<?php echo esc_attr( $agentic_edit_prov['icon'] ?? '' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_show_identity_fields ) : ?>
			<tr>
				<th scope="row"><label for="provider_slug">Slug</label></th>
				<td>
					<input type="text" id="provider_slug" name="provider_slug" class="regular-text"
						value="<?php echo esc_attr( $agentic_edit_prov['slug'] ?? '' ); ?>"
						pattern="[a-z0-9_-]+"
						<?php
						if ( $agentic_edit_prov && $agentic_edit_prov['is_builtin'] ) :
							?>
							readonly <?php endif; ?>
						required>
					<?php if ( $agentic_edit_prov && $agentic_edit_prov['is_builtin'] ) : ?>
					<p class="description">Built-in slugs are read-only.</p>
					<?php else : ?>
					<p class="description">Lowercase letters, numbers, hyphens, underscores. Cannot be changed after creation.</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_slug" value="<?php echo esc_attr( $agentic_edit_prov['slug'] ?? '' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_show_identity_fields ) : ?>
			<tr>
				<th scope="row"><label for="provider_endpoint">Endpoint URL</label></th>
				<td>
					<input type="text" id="provider_endpoint" name="provider_endpoint" class="large-text code"
						value="<?php echo esc_attr( $agentic_edit_prov['endpoint'] ?? '' ); ?>" required>
					<p class="description">
						Use <code>%MODEL%</code> for model substitution, <code>%KEY%</code> for key-in-URL auth (Google),
						<code>%OLLAMA_URL%</code> for the dynamic Ollama server URL.
					</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_endpoint" value="<?php echo esc_attr( $agentic_edit_prov['endpoint'] ?? '' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_default_model">Default Model</label></th>
				<td>
					<?php
					$agentic_form_slug  = $agentic_edit_prov['slug'] ?? '';
					$agentic_model_cur  = $agentic_edit_prov['default_model'] ?? '';
					$agentic_model_list = ! empty( $agentic_edit_prov['models'] ) ? $agentic_edit_prov['models'] : null;
					// Ensure the saved value is always an option even if it's newer than this list.
					if ( $agentic_model_list && $agentic_model_cur && ! in_array( $agentic_model_cur, $agentic_model_list, true ) ) {
						array_unshift( $agentic_model_list, $agentic_model_cur );
					}
					?>
					<div class="agentic-flex-gap-8">
					<?php if ( $agentic_model_list ) : ?>
					<select id="provider_default_model" name="provider_default_model">
						<?php foreach ( $agentic_model_list as $agentic_m ) : ?>
						<option value="<?php echo esc_attr( $agentic_m ); ?>" <?php selected( $agentic_model_cur, $agentic_m ); ?>>
							<?php echo esc_html( $agentic_m ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<?php else : ?>
					<input type="text" id="provider_default_model" name="provider_default_model" class="regular-text"
						value="<?php echo esc_attr( $agentic_model_cur ); ?>" placeholder="e.g. gpt-4o">
					<?php endif; ?>
					<?php if ( $agentic_form_slug ) : ?>
					<span id="agentic-model-loading" class="agentic-text-dim agentic-text-xs" style="display:none;">⟳ Loading…</span>
					<a href="#" id="agentic-model-refresh" title="Fetch live model list from provider API" class="agentic-text-xs agentic-nowrap">↺ Refresh</a>
					<?php endif; ?>
					</div>
					<span id="agentic-model-status" class="agentic-text-xxs agentic-mt-4" style="display:none;"></span>
					<p class="description">Model string sent to the API when no specific model is chosen.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_default_model" value="<?php echo esc_attr( $agentic_edit_prov['default_model'] ?? '' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_models">Available Models</label></th>
				<td>
					<textarea id="provider_models" name="provider_models" class="large-text" rows="6" placeholder="One model per line&#10;e.g. gpt-4o">
					<?php
						echo esc_textarea( implode( "\n", $agentic_edit_prov['models'] ?? array() ) );
					?>
					</textarea>
					<p class="description">One model identifier per line. Populates the Default Model dropdown above. Use &#8635;&nbsp;Refresh to fill from the provider&#8217;s live API, then save to persist.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_models" value="<?php echo esc_attr( implode( "\n", $agentic_edit_prov['models'] ?? array() ) ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_vision_model">Vision Model</label></th>
				<td>
					<input type="text" id="provider_vision_model" name="provider_vision_model" class="regular-text"
						value="<?php echo esc_attr( $agentic_edit_prov['vision_model'] ?? '' ); ?>"
						placeholder="e.g. gpt-4o (leave blank to use Default Model)">
					<p class="description">Model used when a user attaches an image. Leave blank to fall back to the Default Model.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_vision_model" value="<?php echo esc_attr( $agentic_edit_prov['vision_model'] ?? '' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_auth_type">Auth Type</label></th>
				<td>
					<select id="provider_auth_type" name="provider_auth_type">
						<?php foreach ( $agentic_auth_type_labels as $agentic_at_key => $agentic_at_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_at_key ); ?>" <?php selected( $agentic_edit_prov['auth_type'] ?? 'bearer', $agentic_at_key ); ?>>
							<?php echo esc_html( $agentic_at_label ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_auth_type" value="<?php echo esc_attr( $agentic_edit_prov['auth_type'] ?? 'bearer' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_req_format">Request Format</label></th>
				<td>
					<select id="provider_req_format" name="provider_req_format">
						<?php foreach ( $agentic_req_format_labels as $agentic_rf_key => $agentic_rf_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_rf_key ); ?>" <?php selected( $agentic_edit_prov['req_format'] ?? 'openai', $agentic_rf_key ); ?>>
							<?php echo esc_html( $agentic_rf_label ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<p class="description">How to structure the request body sent to the API.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_req_format" value="<?php echo esc_attr( $agentic_edit_prov['req_format'] ?? 'openai' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_resp_format">Response Format</label></th>
				<td>
					<select id="provider_resp_format" name="provider_resp_format">
						<?php foreach ( $agentic_resp_format_labels as $agentic_respf_key => $agentic_respf_label ) : ?>
						<option value="<?php echo esc_attr( $agentic_respf_key ); ?>" <?php selected( $agentic_edit_prov['resp_format'] ?? 'openai', $agentic_respf_key ); ?>>
							<?php echo esc_html( $agentic_respf_label ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Choose if the API response needs normalisation before use.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_resp_format" value="<?php echo esc_attr( $agentic_edit_prov['resp_format'] ?? 'openai' ); ?>">
			<?php endif; ?>
			<?php if ( $agentic_providers_advanced ) : ?>
			<tr>
				<th scope="row"><label for="provider_key_url">Get Key URL</label></th>
				<td>
					<input type="url" id="provider_key_url" name="provider_key_url" class="regular-text"
						value="<?php echo esc_attr( $agentic_edit_prov['key_url'] ?? '' ); ?>"
						placeholder="https://example.com/api-keys">
					<p class="description">Optional. Link shown to users to help them obtain an API key.</p>
				</td>
			</tr>
			<?php else : ?>
			<input type="hidden" name="provider_key_url" value="<?php echo esc_attr( $agentic_edit_prov['key_url'] ?? '' ); ?>">
			<?php endif; ?>
			<?php
			$agentic_needs_key = 'none' !== ( $agentic_edit_prov['auth_type'] ?? 'bearer' );
			if ( $agentic_needs_key || ! $agentic_edit_prov ) :
				?>
			<tr>
				<th scope="row"><label for="provider_api_key">API Key</label></th>
				<td>
					<input type="password" id="provider_api_key" name="provider_api_key"
						class="regular-text"
						value="<?php echo esc_attr( $agentic_current_api_key ); ?>"
						autocomplete="new-password"
						placeholder="<?php echo $agentic_current_api_key ? esc_attr( '••••••••' . substr( $agentic_current_api_key, -4 ) ) : ''; ?>">
					<p class="description">
						<?php if ( $agentic_current_api_key ) : ?>
						Key is stored. Leave blank to keep the current key, or enter a new value to replace it.
						<?php else : ?>
						Your API key for this provider. Stored securely in the database.
						<?php endif; ?>
						<?php if ( ! empty( $agentic_edit_prov['key_url'] ) ) : ?>
						<a href="<?php echo esc_url( $agentic_edit_prov['key_url'] ); ?>" target="_blank" rel="noopener noreferrer">Get a key ↗</a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<p class="agentic-mt-16">
			<?php submit_button( $agentic_edit_prov ? __( 'Update Provider', 'agent-builder' ) : __( 'Add Provider', 'agent-builder' ), 'primary', 'submit', false ); ?>
			&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=providers' ) ); ?>" class="button">Cancel</a>
		</p>
	</form>
	</div>
</div>
<?php endif; ?>

<?php if ( ! $agentic_show_form ) : ?>
<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th class="agentic-col-status" scope="col">
				<span class="screen-reader-text"><?php esc_html_e( 'Status', 'agent-builder' ); ?></span>
			</th>
			<th class="agentic-col-20">Name</th>
			<th class="agentic-col-10">Slug</th>
			<th class="agentic-col-16">Default Model</th>
			<th class="agentic-col-11">Auth Type</th>
			<th class="agentic-col-9">Format</th>
			<th class="agentic-col-8">Actions</th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $agentic_all_providers as $agentic_prov ) :
			$agentic_is_default   = ( $agentic_prov['slug'] === $agentic_current_default );
			$agentic_is_connected = \Agentic\Admin_Menu_Handler::provider_is_connected( $agentic_prov );
			$agentic_status_label = $agentic_is_connected
				? __( 'Connected', 'agent-builder' )
				: __( 'Not connected', 'agent-builder' );
			?>
		<tr<?php echo $agentic_is_default ? ' class="agentic-row-highlight"' : ''; ?>>
			<td class="agentic-provider-status-cell">
				<span
					class="agentic-provider-led <?php echo $agentic_is_connected ? 'is-on' : 'is-off'; ?>"
					title="<?php echo esc_attr( $agentic_status_label ); ?>"
					aria-label="<?php echo esc_attr( $agentic_status_label ); ?>"
				></span>
			</td>
			<td>
				<span class="agentic-flex-gap-6">
				<?php
				$agentic_icon_html = agentic_setup_provider_icon( $agentic_prov['icon'] ?? '', 20 );
				if ( $agentic_icon_html ) {
					echo wp_kses(
						$agentic_icon_html,
						array(
							'svg'    => array(
								'xmlns'      => array(),
								'viewbox'    => array(),
								'fill'       => array(),
								'width'      => array(),
								'height'     => array(),
								'role'       => array(),
								'aria-label' => array(),
							),
							'circle' => array(
								'cx'           => array(),
								'cy'           => array(),
								'r'            => array(),
								'fill'         => array(),
								'stroke'       => array(),
								'stroke-width' => array(),
							),
							'path'   => array(
								'd'               => array(),
								'fill'            => array(),
								'stroke'          => array(),
								'stroke-width'    => array(),
								'stroke-linecap'  => array(),
								'stroke-linejoin' => array(),
							),
							'rect'   => array(
								'width'  => array(),
								'height' => array(),
								'rx'     => array(),
								'fill'   => array(),
								'x'      => array(),
								'y'      => array(),
							),
							'text'   => array(
								'x'                 => array(),
								'y'                 => array(),
								'font-size'         => array(),
								'font-weight'       => array(),
								'fill'              => array(),
								'font-family'       => array(),
								'dominant-baseline' => array(),
								'text-anchor'       => array(),
							),
							'img'    => array(
								'src'    => array(),
								'width'  => array(),
								'height' => array(),
								'style'  => array(),
								'alt'    => array(),
							),
						)
					);
				}
				?>
				<strong><?php echo esc_html( $agentic_prov['name'] ); ?></strong>
				</span>
					<?php if ( $agentic_prov['is_builtin'] ) : ?>
				<span class="agentic-provider-builtin-note">built-in</span>
				<?php endif; ?>
			</td>
			<td><code><?php echo esc_html( $agentic_prov['slug'] ); ?></code></td>
			<td><small><?php echo esc_html( $agentic_prov['default_model'] ); ?></small></td>
			<td><small><?php echo esc_html( $agentic_prov['auth_type'] ); ?></small></td>
			<td><small><?php echo esc_html( $agentic_prov['req_format'] ); ?></small></td>
			<td>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=providers&edit_provider=' . rawurlencode( $agentic_prov['slug'] ) ) ); ?>">Edit</a>
				<?php if ( $agentic_is_default ) : ?>
				&nbsp;|&nbsp;
				<span class="agentic-badge-primary"><?php esc_html_e( 'Default', 'agent-builder' ); ?></span>
				<?php elseif ( $agentic_is_connected ) : ?>
				&nbsp;|&nbsp;
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=providers' ) ); ?>" class="agentic-form-inline">
					<?php wp_nonce_field( 'agentic_provider_nonce' ); ?>
					<input type="hidden" name="agentic_provider_action" value="set_default">
					<input type="hidden" name="provider_slug" value="<?php echo esc_attr( $agentic_prov['slug'] ); ?>">
					<button type="submit" class="button-link agentic-provider-setdefault-btn"><?php esc_html_e( 'Set Default', 'agent-builder' ); ?></button>
				</form>
				<?php endif; ?>
				<?php if ( ! $agentic_prov['is_builtin'] ) : ?>
				&nbsp;|&nbsp;
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=providers' ) ); ?>" class="agentic-form-inline" data-agentic-confirm="<?php echo esc_attr( sprintf( /* translators: %s: provider name */ __( 'Delete provider “%s”? This cannot be undone.', 'agent-builder' ), $agentic_prov['name'] ) ); ?>" data-agentic-confirm-danger>
					<?php wp_nonce_field( 'agentic_provider_nonce' ); ?>
					<input type="hidden" name="agentic_provider_action" value="delete">
					<input type="hidden" name="provider_slug" value="<?php echo esc_attr( $agentic_prov['slug'] ); ?>">
					<button type="submit" class="button-link agentic-provider-delete-btn"><?php esc_html_e( 'Delete', 'agent-builder' ); ?></button>
				</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<?php if ( $agentic_show_form && $agentic_edit_slug && $agentic_providers_advanced ) : ?>
<script>
(function () {
	var slug      = <?php echo wp_json_encode( $agentic_edit_slug ); ?>;
	var nonce     = <?php echo wp_json_encode( $agentic_models_nonce ); ?>;
	var autoFetch = <?php echo $agentic_form_has_key ? 'true' : 'false'; ?>;

	function getField() { return document.getElementById( 'provider_default_model' ); }

	function setLoading( on ) {
		var spinner = document.getElementById( 'agentic-model-loading' );
		var field   = getField();
		if ( spinner ) spinner.style.display = on ? 'inline' : 'none';
		if ( field )   field.disabled = on;
	}

	function setStatus( msg, isError ) {
		var el = document.getElementById( 'agentic-model-status' );
		if ( ! el ) return;
		el.textContent   = msg;
		el.style.color   = isError ? '#b32d2e' : '#646970';
		el.style.display = msg ? 'block' : 'none';
	}

	function populateSelect( models, currentVal ) {
		var field = getField();
		if ( ! field ) return;
		if ( currentVal && models.indexOf( currentVal ) < 0 ) models.unshift( currentVal );
		// Sync the Available Models textarea so saving the form persists the fetched list.
		var ta = document.getElementById( 'provider_models' );
		if ( ta ) ta.value = models.join( '\n' );
		var sel  = document.createElement( 'select' );
		sel.id   = 'provider_default_model';
		sel.name = 'provider_default_model';
		models.forEach( function ( m ) {
			var opt         = document.createElement( 'option' );
			opt.value       = m;
			opt.textContent = m;
			if ( m === currentVal ) opt.selected = true;
			sel.appendChild( opt );
		} );
		field.parentNode.replaceChild( sel, field );
	}

	function fetchModels( force ) {
		var field      = getField();
		var currentVal = field ? field.value : '';
		setLoading( true );
		setStatus( '' );
		var data = new FormData();
		data.append( 'action',   'agentic_fetch_provider_models' );
		data.append( 'nonce',    nonce );
		data.append( 'provider', slug );
		if ( force ) data.append( 'force', '1' );
		fetch( ajaxurl, { method: 'POST', body: data } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				setLoading( false );
				if ( res.success && res.data && res.data.models && res.data.models.length ) {
					populateSelect( res.data.models, currentVal );
					setStatus( res.data.cached ? 'Cached — click ↺ Refresh for latest.' : '' );
				} else {
					setStatus( ( res.data && res.data.message ) || 'Could not load models from API.', true );
				}
			} )
			.catch( function () {
				setLoading( false );
				setStatus( 'Request failed. Is your API key saved?', true );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn = document.getElementById( 'agentic-model-refresh' );
		if ( btn ) btn.addEventListener( 'click', function ( e ) { e.preventDefault(); fetchModels( true ); } );
		if ( autoFetch ) fetchModels( false );
	} );
}());
</script>
<?php endif; ?>

<script>
( function () {
	'use strict';
	var toggle = document.getElementById( 'agentic-providers-mode-toggle' );
	if ( ! toggle ) {
		return;
	}
	Array.prototype.forEach.call( toggle.querySelectorAll( 'button' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( btn.disabled ) {
				return;
			}
			Array.prototype.forEach.call( toggle.querySelectorAll( 'button' ), function ( b ) {
				b.disabled = true;
			} );
			fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'agentic/v1/admin-page' ) ) ); ?>, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
				},
				body: JSON.stringify( {
					action_name: 'set_screen_mode',
					screen: 'providers',
					mode: btn.getAttribute( 'data-mode' )
				} )
			} ).then( function () {
				window.location.reload();
			} );
		} );
	} );
} )();
</script>
