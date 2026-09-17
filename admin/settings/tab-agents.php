<?php
/**
 * Settings — Agents Tab
 *
 * Per-agent LLM provider overrides and per-agent chat feature flags.
 * Variables from parent settings.php scope:
 *   $agentic_llm_provider_val, $agentic_model_val, $agentic_agent_mode_val,
 *   $agentic_ollama_url_val
 *
 * @package    Agent_Builder
 * @subpackage Admin/Settings
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Per-Agent Overrides ──────────────────────────────────────────────────
$agentic_registry      = \Agentic_Agent_Registry::get_instance();
$agentic_active_slugs  = $agentic_registry->get_active_agents(); // array of slugs.
$agentic_all_installed = $agentic_registry->get_installed_agents();

// Build a list of all providers and which ones are configured.
$agentic_all_providers        = \Agentic\Provider_Registry::get_slug_name_map();
$agentic_configured_providers = array();
foreach ( \Agentic\Provider_Registry::get_all() as $agentic_prov_entry ) {
	$agentic_prov_slug_key = $agentic_prov_entry['slug'];
	if ( 'none' === $agentic_prov_entry['auth_type'] ) {
		if ( ! empty( $agentic_ollama_url_val ) ) {
			$agentic_configured_providers[ $agentic_prov_slug_key ] = $agentic_prov_entry['name'];
		}
	} elseif ( ! $agentic_prov_entry['requires_key'] || ! empty( $agentic_prov_entry['api_key'] ) ) {
		$agentic_configured_providers[ $agentic_prov_slug_key ] = $agentic_prov_entry['name'];
	}
}

// Collect active agents that have full metadata.
$agent_builder_active_agents = array();
foreach ( $agentic_active_slugs as $agentic_slug ) {
	if ( isset( $agentic_all_installed[ $agentic_slug ] ) ) {
		$agent_builder_active_agents[ $agentic_slug ] = $agentic_all_installed[ $agentic_slug ];
	}
}

if ( ! empty( $agent_builder_active_agents ) ) :
	// Labels for the "use global default" option in each dropdown.
	$agentic_default_provider_label = \Agentic\Provider_Registry::get( $agentic_llm_provider_val )['name'] ?? ucfirst( $agentic_llm_provider_val );
	$agentic_default_model_label    = ! empty( $agentic_model_val ) ? $agentic_model_val : 'default';
	$agentic_default_mode_map       = array(
		'disabled'   => 'Disabled',
		'supervised' => 'Supervised',
		'autonomous' => 'Autonomous',
	);
	$agentic_default_mode_label     = $agentic_default_mode_map[ $agentic_agent_mode_val ] ?? 'Supervised';

	// Global feature flags — used to disable per-agent overrides.
	$agentic_global_vision_llm = get_option( 'agent_builder_chat_vision', '1' );
	$agentic_global_cache_llm  = get_option( 'agent_builder_response_cache_enabled', true ) ? '1' : '0';

	// Build provider → models map for use in PHP rendering and JS.
	$agentic_provider_models = array();
	foreach ( \Agentic\Provider_Registry::get_all() as $agentic_pm_entry ) {
		if ( ! empty( $agentic_pm_entry['models'] ) ) {
			$agentic_provider_models[ $agentic_pm_entry['slug'] ] = $agentic_pm_entry['models'];
		}
	}
	?>

<p class="agentic-settings-lead"><?php esc_html_e( 'Choose the default LLM provider and optionally override it per agent.', 'agent-builder' ); ?></p>
<h3 class="agentic-settings-section-title"><?php esc_html_e( 'LLM provider configuration', 'agent-builder' ); ?></h3>
<p>Configure the LLM provider, model, and mode for your agents. Not sure where to start? — <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-setup' ) ); ?>">Use the setup Wizard</a>.</p>

<table class="wp-list-table widefat fixed striped" id="agentic-agent-overrides-table">
	<thead>
		<tr>
			<th class="agentic-col-18">Agent</th>
			<th class="agentic-col-16">Provider</th>
			<th class="agentic-col-20">Chat / Reasoning</th>
			<th class="agentic-col-20">Vision Model</th>
			<th class="agentic-col-18">Security Mode</th>
			<th class="agentic-col-12" title="Enable extra tool guidance + examples for weaker models on this agent">Guidance</th>
			<th class="agentic-col-10" title="Maximum automatic retries when tool calls fail (for weaker models)">Retries</th>
			<th class="agentic-col-8">Reset</th>
		</tr>
	</thead>
	<tbody>
			<?php
			foreach ( $agent_builder_active_agents as $agentic_slug => $agentic_agent_data ) :
				$agentic_ov_provider     = \Agentic\Agent_Settings::get( $agentic_slug, 'override_provider' );
				$agentic_ov_model        = \Agentic\Agent_Settings::get( $agentic_slug, 'override_model' );
				$agentic_ov_vision_model = \Agentic\Agent_Settings::get( $agentic_slug, 'override_vision_model' );
				$agentic_ov_mode         = \Agentic\Agent_Settings::get( $agentic_slug, 'override_mode' );
				$agentic_agent_name      = $agentic_agent_data['name'] ?? $agentic_slug;
				?>
		<tr data-agent-slug="<?php echo esc_attr( $agentic_slug ); ?>">
			<td>
				<strong><?php echo esc_html( $agentic_agent_name ); ?></strong>
				<br><small class="agentic-text-muted"><?php echo esc_html( $agentic_slug ); ?></small>
			</td>
			<td>
				<select
					name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][provider]"
					class="agentic-agent-provider-select"
					data-slug="<?php echo esc_attr( $agentic_slug ); ?>"
					class="agentic-select-full"
				>
					<option value=""><?php echo esc_html( $agentic_default_provider_label ); ?> (default)</option>
					<?php foreach ( $agentic_configured_providers as $agentic_prov_key => $agentic_prov_label ) : ?>
					<option value="<?php echo esc_attr( $agentic_prov_key ); ?>" <?php selected( $agentic_ov_provider, $agentic_prov_key ); ?>>
						<?php echo esc_html( $agentic_prov_label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php
				$agentic_row_provider = ! empty( $agentic_ov_provider ) ? $agentic_ov_provider : $agentic_llm_provider_val;
				$agentic_row_models   = $agentic_provider_models[ $agentic_row_provider ] ?? array();
				if ( ! empty( $agentic_ov_model ) && ! in_array( $agentic_ov_model, $agentic_row_models, true ) ) {
					array_unshift( $agentic_row_models, $agentic_ov_model );
				}
				?>
				<select
					name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][model]"
					class="agentic-agent-model-select"
					data-slug="<?php echo esc_attr( $agentic_slug ); ?>"
					data-saved-model="<?php echo esc_attr( $agentic_ov_model ); ?>"
					class="agentic-select-full"
				>
					<option value=""><?php echo esc_html( $agentic_default_model_label ); ?> (default)</option>
					<?php foreach ( $agentic_row_models as $agentic_row_m ) : ?>
					<option value="<?php echo esc_attr( $agentic_row_m ); ?>" <?php selected( $agentic_ov_model, $agentic_row_m ); ?>>
						<?php echo esc_html( $agentic_row_m ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php if ( '1' !== $agentic_global_vision_llm ) : ?>
					<small class="agentic-text-dim">Disabled globally</small>
					<br><small><a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=global' ) ); ?>">Enable</a></small>
				<?php else : ?>
					<?php
					$agentic_row_vision_models = $agentic_row_models;
					if ( ! empty( $agentic_ov_vision_model ) && ! in_array( $agentic_ov_vision_model, $agentic_row_vision_models, true ) ) {
						array_unshift( $agentic_row_vision_models, $agentic_ov_vision_model );
					}
					?>
				<select
					name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][vision_model]"
					class="agentic-agent-vision-model-select"
					data-slug="<?php echo esc_attr( $agentic_slug ); ?>"
					data-saved-model="<?php echo esc_attr( $agentic_ov_vision_model ); ?>"
					class="agentic-select-full"
				>
					<option value="">Same as chat</option>
					<?php foreach ( $agentic_row_vision_models as $agentic_row_vm ) : ?>
					<option value="<?php echo esc_attr( $agentic_row_vm ); ?>" <?php selected( $agentic_ov_vision_model, $agentic_row_vm ); ?>>
						<?php echo esc_html( $agentic_row_vm ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
			</td>
			<td class="agentic-td-nowrap">
				<select
					name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][mode]"
					class="agentic-agent-mode-select"
					class="agentic-select-minus-24"
				>
					<?php
					$agentic_mode_options   = array(
						'disabled'   => 'Disabled',
						'supervised' => 'Supervised',
						'autonomous' => 'Autonomous',
					);
					$agentic_effective_mode = '' !== $agentic_ov_mode ? $agentic_ov_mode : $agentic_agent_mode_val;
					foreach ( $agentic_mode_options as $agentic_mode_key => $agentic_mode_text ) :
						$agentic_mode_suffix = ( $agentic_mode_key === $agentic_agent_mode_val ) ? ' (Global)' : '';
						?>
					<option value="<?php echo esc_attr( $agentic_mode_key ); ?>" <?php selected( $agentic_effective_mode, $agentic_mode_key ); ?>>
						<?php echo esc_html( $agentic_mode_text . $agentic_mode_suffix ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=security' ) ); ?>" title="<?php esc_attr_e( 'Security settings', 'agent-builder' ); ?>" style="text-decoration:none;vertical-align:middle;"><span class="dashicons dashicons-admin-generic" style="font-size:16px;width:16px;height:16px;color:#787c82;"></span></a>
			</td>
			<td class="agentic-td-center">
				<?php
				$agentic_ov_weak        = \Agentic\Agent_Settings::get( $agentic_slug, 'weak_model_tool_guidance', '' );
				$agentic_effective_weak = $agentic_ov_weak !== '' ? $agentic_ov_weak : '1';
				?>
				<input type="hidden" name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][weak_model_tool_guidance]" value="">
				<input type="checkbox"
					name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][weak_model_tool_guidance]"
					value="1"
					<?php checked( $agentic_effective_weak, '1' ); ?>
					title="Enable enhanced tool guidance for weaker models on this agent">
			</td>
			<td>
				<?php
				$agentic_ov_retries = \Agentic\Agent_Settings::get( $agentic_slug, 'max_tool_retries', '' );
				?>
				<input type="number" min="1" max="10" step="1" style="width:60px;"
					name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][max_tool_retries]"
					value="<?php echo esc_attr( $agentic_ov_retries ); ?>"
					placeholder="3"
					title="Max retries when tool calls fail (for weaker models)">
			</td>
			<td>
				<button
					type="button"
					class="button button-small agentic-reset-agent-row"
					data-slug="<?php echo esc_attr( $agentic_slug ); ?>"
					title="Reset to global defaults"
				>Reset</button>
			</td>
		</tr>
			<?php endforeach; ?>
	</tbody>
</table>

<p class="description agentic-mt-8">
	<strong>Tool Reliability columns</strong> (Item 3): Control behavior for agents using smaller or local models. 
	These settings improve reliability when the chosen model struggles with tool calling.
</p>

<script>
(function () {
	var agenticProviderModels = <?php echo wp_json_encode( $agentic_provider_models ); ?>;
	var agenticDefaultProvider = <?php echo wp_json_encode( $agentic_llm_provider_val ); ?>;
	var agenticDefaultModel    = <?php echo wp_json_encode( $agentic_model_val ); ?>;

	function agenticPopulateModels( providerSel ) {
		var row    = providerSel.closest( 'tr' );
		var slug   = providerSel.value || agenticDefaultProvider;
		var models = agenticProviderModels[ slug ] || [];

		[
			{ sel: row.querySelector( '.agentic-agent-model-select' ),        firstLabel: agenticDefaultModel + ' (default)' },
			{ sel: row.querySelector( '.agentic-agent-vision-model-select' ), firstLabel: 'Same as chat' },
		].forEach( function( cfg ) {
			if ( ! cfg.sel ) return;
			var saved = cfg.sel.getAttribute( 'data-saved-model' ) || '';
			var list  = models.slice();
			if ( saved && list.indexOf( saved ) === -1 ) list.unshift( saved );
			var options = '<option value="">' + cfg.firstLabel + '</option>';
			list.forEach( function( m ) {
				options += '<option value="' + m + '"' + ( m === saved ? ' selected' : '' ) + '>' + m + '</option>';
			} );
			cfg.sel.innerHTML = options;
			cfg.sel.setAttribute( 'data-saved-model', '' );
		} );
	}

	document.querySelectorAll( '.agentic-agent-provider-select' ).forEach( function( sel ) {
		sel.addEventListener( 'change', function () { agenticPopulateModels( this ); } );
	} );
}());
</script>

<p class="agentic-mt-8"><a href="#" class="agentic-reset-defaults" data-section="agents_llm">Reset LLM overrides to defaults</a></p>

<h3 class="agentic-settings-section-title"><?php esc_html_e( 'Chat features per agent', 'agent-builder' ); ?></h3>
<p>Override which chat features are available per agent. Unchecked features fall back to the global <a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=global' ) ); ?>">Chat Settings</a>.</p>

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th class="agentic-col-22">Agent</th>
			<th class="agentic-col-16 agentic-td-center">Audio In (Enable Microphone)</th>
			<th class="agentic-col-16 agentic-td-center">Audio Out (Enable Speaker)</th>
			<th class="agentic-col-16 agentic-td-center">Vision (Enable Uploads)</th>
			<th class="agentic-col-14 agentic-td-center">Cache (Response Cache)</th>
		</tr>
	</thead>
	<tbody>
		<?php
		$agentic_global_audio  = get_option( 'agent_builder_chat_audio', '1' );
		$agentic_global_tts    = get_option( 'agent_builder_chat_tts', '1' );
		$agentic_global_vision = get_option( 'agent_builder_chat_vision', '1' );
		$agentic_global_costs  = get_option( 'agent_builder_chat_costs', '1' );
		$agentic_global_cache  = get_option( 'agent_builder_response_cache_enabled', true ) ? '1' : '0';
		foreach ( $agent_builder_active_agents as $agentic_slug => $agentic_agent_data ) :
			$agentic_agent_name = $agentic_agent_data['name'] ?? $agentic_slug;
			$agentic_cf_audio   = \Agentic\Agent_Settings::get( $agentic_slug, 'override_audio' );
			$agentic_cf_tts     = \Agentic\Agent_Settings::get( $agentic_slug, 'override_tts' );
			$agentic_cf_vision  = \Agentic\Agent_Settings::get( $agentic_slug, 'override_vision' );
			$agentic_cf_costs   = \Agentic\Agent_Settings::get( $agentic_slug, 'override_costs' );
			$agentic_cf_cache   = \Agentic\Agent_Settings::get( $agentic_slug, 'override_cache' );
			?>
		<tr>
			<td>
				<strong><?php echo esc_html( $agentic_agent_name ); ?></strong>
				<br><small class="agentic-text-muted"><?php echo esc_html( $agentic_slug ); ?></small>
			</td>
			<?php
			$agentic_feature_cells = array(
				'audio'  => array(
					'value'  => $agentic_cf_audio,
					'global' => $agentic_global_audio,
				),
				'tts'    => array(
					'value'  => $agentic_cf_tts,
					'global' => $agentic_global_tts,
				),
				'vision' => array(
					'value'  => $agentic_cf_vision,
					'global' => $agentic_global_vision,
				),
			);
			$agentic_feature_cells['cache'] = array(
				'value'  => $agentic_cf_cache,
				'global' => $agentic_global_cache,
			);
			foreach ( $agentic_feature_cells as $agentic_feat_key => $agentic_feat ) :
				?>
			<td class="agentic-td-center">
				<?php if ( '1' !== $agentic_feat['global'] ) : ?>
					<small class="agentic-text-dim">Disabled globally</small>
					<br><small><a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings&tab=global' ) ); ?>">Enable</a></small>
				<?php else : ?>
					<input type="checkbox" name="agent_builder_agent_overrides[<?php echo esc_attr( $agentic_slug ); ?>][<?php echo esc_attr( $agentic_feat_key ); ?>]" value="1" <?php checked( $agentic_feat['value'], '1' ); ?> />
				<?php endif; ?>
			</td>
			<?php endforeach; ?>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p class="agentic-mt-8"><a href="#" class="agentic-reset-defaults" data-section="agents_features">Reset chat feature overrides to defaults</a></p>

<p class="agentic-mt-1em">
	<a href="https://agentic-plugin.com/agent-performance/" target="_blank" rel="noopener">
		Optimising Performance and Managing Costs →
	</a>
</p>
	<?php endif; // active agents. ?>
