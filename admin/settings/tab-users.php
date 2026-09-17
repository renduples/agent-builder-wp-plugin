<?php
/**
 * Settings — Users Tab
 *
 * User role access control and per-role daily limits.
 * Variables from parent settings.php scope:
 *   $agentic_allow_anon_chat, $agentic_rate_limit_auth, $agentic_rate_limit_anon
 *
 * @package    Agent_Builder
 * @subpackage Admin/Settings
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_ur_settings     = \Agentic\User_Roles::get_settings();
$agentic_ur_plugin_privs = \Agentic\User_Roles::get_plugin_privileges();
$agentic_ur_agent_privs  = \Agentic\User_Roles::get_agent_privileges();
$agentic_ur_wp_roles     = \Agentic\User_Roles::get_all_wp_roles();
$agentic_ul_limits       = \Agentic\Usage_Limits::get_limits();
?>
<p class="agentic-settings-lead"><?php esc_html_e( 'Control which WordPress user roles can administer the plugin and interact with AI agents. Administrators always retain full access.', 'agent-builder' ); ?></p>

<div class="agentic-callout-warning">
	<p class="agentic-mt-0"><strong>Note:</strong> These rules apply site-wide. A user needs at least one ticked box to access the corresponding feature. Removing a role from <em>all</em> plugin privileges will hide the Agent Builder menu from that role entirely.</p>
</div>

	<?php
	// Helper: render a privilege table.
	$agentic_render_privilege_table = function (
		string $section_key,
		string $section_label,
		string $section_desc,
		array $privileges,
		array $settings,
		array $wp_roles
	): void {
		$col_count = count( $wp_roles ) + 1; // +1 for the privilege label column.
		echo '<h3>' . esc_html( $section_label ) . '</h3>';
		echo '<p class="agentic-mb-12">' . esc_html( $section_desc ) . '</p>';
		echo '<div class="agentic-overflow-x-auto">'; // Responsive scroll on small screens.
		echo '<table class="widefat agentic-tbl-auto">';
		echo '<thead><tr>';
		echo '<th class="agentic-col-min-200">Permission</th>';
		foreach ( $wp_roles as $role_slug => $role_data ) {
			$role_name = translate_user_role( $role_data['name'] );
			echo '<th class="agentic-col-min-90 agentic-text-center">' . esc_html( $role_name ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$odd = true;
		foreach ( $privileges as $priv_key => $priv_info ) {
			$allowed_roles = $settings[ $section_key ][ $priv_key ] ?? array();
			$bg            = $odd ? '' : ' class="agentic-row-alt"';
			echo '<tr' . $bg . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded style string, no user input.
			echo '<td><strong>' . esc_html( $priv_info['label'] ) . '</strong><br>';
			echo '<span class="description agentic-text-xs">' . esc_html( $priv_info['description'] ) . '</span></td>';
			foreach ( array_keys( $wp_roles ) as $role_slug ) {
				$is_admin   = 'administrator' === $role_slug;
				$is_checked = $is_admin || in_array( $role_slug, $allowed_roles, true );
				$field_name = 'agentic_user_roles[' . esc_attr( $section_key ) . '][' . esc_attr( $priv_key ) . '][]';
				$field_id   = 'agentic_ur_' . esc_attr( $section_key ) . '_' . esc_attr( $priv_key ) . '_' . esc_attr( $role_slug );
				echo '<td class="agentic-td-center">';
				if ( $is_admin ) {
					// Administrators are always checked and cannot be unchecked.
					echo '<input type="checkbox" checked disabled title="Administrators always have full access" />';
					// Hidden field so the value is still submitted.
					echo '<input type="hidden" name="' . $field_name . '" value="administrator" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_name built with esc_attr().
				} else {
					echo '<input type="checkbox" name="' . $field_name . '" id="' . $field_id . '" value="' . esc_attr( $role_slug ) . '"' . ( $is_checked ? ' checked' : '' ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_name/$field_id built with esc_attr().
				}
				echo '</td>';
			}
			echo '</tr>';
			$odd = ! $odd;
		}
		echo '</tbody></table></div><br>';
	};
	?>

	<?php
	$agentic_render_privilege_table(
		'plugin',
		'Plugin Administration',
		'Who can access and configure the Agent Builder admin pages.',
		$agentic_ur_plugin_privs,
		$agentic_ur_settings,
		$agentic_ur_wp_roles
	);
	?>

	<h3>AI Agents</h3>
	<p class="agentic-mb-12">Who can chat with and interact with the installed AI agents, and how much they may use per day.</p>
	<div class="agentic-overflow-x-auto">
	<table class="widefat agentic-tbl-auto">
		<thead>
			<tr>
				<th class="agentic-col-min-200">Permission / Limit</th>
				<th class="agentic-td-center agentic-col-min-110">Visitor<br><span class="agentic-fw-normal agentic-text-xxs agentic-text-muted">Not logged in</span></th>
				<?php foreach ( $agentic_ur_wp_roles as $agentic_ag_role_slug => $agentic_ag_role_data ) : ?>
				<th class="agentic-td-center agentic-col-min-90"><?php echo esc_html( translate_user_role( $agentic_ag_role_data['name'] ) ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php
			$agentic_ag_odd = true;
			foreach ( $agentic_ur_agent_privs as $agentic_ag_priv_key => $agentic_ag_priv_info ) :
				$agentic_ag_allowed = $agentic_ur_settings['agents'][ $agentic_ag_priv_key ] ?? array();
				$agentic_ag_bg      = $agentic_ag_odd ? '' : ' class="agentic-row-alt"';
				$agentic_ag_is_chat = 'chat_frontend' === $agentic_ag_priv_key;
				?>
			<tr<?php echo $agentic_ag_bg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded style string ?>>
				<td><strong><?php echo esc_html( $agentic_ag_priv_info['label'] ); ?></strong><br>
					<span class="description agentic-text-xs"><?php echo esc_html( $agentic_ag_priv_info['description'] ); ?></span></td>
				<td class="agentic-td-center">
					<?php if ( $agentic_ag_is_chat ) : ?>
						<input type="checkbox" name="agent_builder_allow_anonymous_chat" value="1"<?php checked( $agentic_allow_anon_chat ); ?> title="Allow non-logged-in visitors to use frontend chat" />
					<?php else : ?>
						<span title="Not applicable for anonymous visitors" class="agentic-na-dash">—</span>
					<?php endif; ?>
				</td>
				<?php
				foreach ( array_keys( $agentic_ur_wp_roles ) as $agentic_ag_role_slug ) :
					$agentic_ag_is_admin   = 'administrator' === $agentic_ag_role_slug;
					$agentic_ag_is_checked = $agentic_ag_is_admin || in_array( $agentic_ag_role_slug, $agentic_ag_allowed, true );
					$agentic_ag_fname      = 'agentic_user_roles[agents][' . esc_attr( $agentic_ag_priv_key ) . '][]';
					$agentic_ag_fid        = 'agentic_ur_agents_' . esc_attr( $agentic_ag_priv_key ) . '_' . esc_attr( $agentic_ag_role_slug );
					?>
				<td class="agentic-td-center">
					<?php if ( $agentic_ag_is_admin ) : ?>
						<input type="checkbox" checked disabled title="Administrators always have full access" />
						<input type="hidden" name="<?php echo $agentic_ag_fname; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr() ?>" value="administrator" />
					<?php else : ?>
						<input type="checkbox" name="<?php echo $agentic_ag_fname; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr() ?>" id="<?php echo $agentic_ag_fid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr() ?>" value="<?php echo esc_attr( $agentic_ag_role_slug ); ?>"<?php echo $agentic_ag_is_checked ? ' checked' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute string ?> />
					<?php endif; ?>
				</td>
				<?php endforeach; ?>
			</tr>
				<?php
				$agentic_ag_odd = ! $agentic_ag_odd;
endforeach;
			?>

			<tr class="agentic-tr-hl-blue">
				<td colspan="<?php echo (int) ( count( $agentic_ur_wp_roles ) + 2 ); ?>" class="agentic-limits-section-td">Daily Limits <span class="agentic-fw-normal agentic-text-dim agentic-text-transform-none agentic-ls-0">&nbsp;— 0 = unlimited, resets at midnight UTC. Administrators are never limited.</span></td>
			</tr>

			<?php $agentic_ag_anon_q = (int) ( $agentic_ul_limits['anonymous']['queries'] ?? 0 ); ?>
			<tr>
				<td><strong>Queries / day</strong><br>
					<span class="description agentic-text-xs">Max AI chat messages per user per day.</span></td>
				<td class="agentic-td-center">
					<input type="number" name="agentic_usage_limits[anonymous][queries]" value="<?php echo esc_attr( $agentic_ag_anon_q ); ?>" min="0" step="1" style="width:80px;" />
				</td>
				<?php
				foreach ( array_keys( $agentic_ur_wp_roles ) as $agentic_ag_role_slug ) :
					$agentic_ag_is_admin = 'administrator' === $agentic_ag_role_slug;
					$agentic_ag_q_val    = (int) ( $agentic_ul_limits[ $agentic_ag_role_slug ]['queries'] ?? 0 );
					?>
				<td class="agentic-td-center">
					<?php if ( $agentic_ag_is_admin ) : ?>
						<input type="number" value="0" disabled class="agentic-input-num-sm agentic-opacity-50" title="Administrators are never limited" />
						<input type="hidden" name="agentic_usage_limits[administrator][queries]" value="0" />
					<?php else : ?>
						<input type="number" name="agentic_usage_limits[<?php echo esc_attr( $agentic_ag_role_slug ); ?>][queries]" value="<?php echo esc_attr( $agentic_ag_q_val ); ?>" min="0" step="1" style="width:80px;" />
					<?php endif; ?>
				</td>
				<?php endforeach; ?>
			</tr>

			<?php $agentic_ag_anon_t = (int) ( $agentic_ul_limits['anonymous']['tokens'] ?? 0 ); ?>
			<tr class="agentic-row-alt">
				<td><strong>Tokens / day</strong><br>
					<span class="description agentic-text-xs">Max AI tokens consumed per user per day.</span></td>
				<td class="agentic-td-center">
					<input type="number" name="agentic_usage_limits[anonymous][tokens]" value="<?php echo esc_attr( $agentic_ag_anon_t ); ?>" min="0" step="1000" style="width:80px;" />
				</td>
				<?php
				foreach ( array_keys( $agentic_ur_wp_roles ) as $agentic_ag_role_slug ) :
					$agentic_ag_is_admin = 'administrator' === $agentic_ag_role_slug;
					$agentic_ag_t_val    = (int) ( $agentic_ul_limits[ $agentic_ag_role_slug ]['tokens'] ?? 0 );
					?>
				<td class="agentic-td-center">
					<?php if ( $agentic_ag_is_admin ) : ?>
						<input type="number" value="0" disabled class="agentic-input-num-sm agentic-opacity-50" title="Administrators are never limited" />
						<input type="hidden" name="agentic_usage_limits[administrator][tokens]" value="0" />
					<?php else : ?>
						<input type="number" name="agentic_usage_limits[<?php echo esc_attr( $agentic_ag_role_slug ); ?>][tokens]" value="<?php echo esc_attr( $agentic_ag_t_val ); ?>" min="0" step="1000" style="width:80px;" />
					<?php endif; ?>
				</td>
				<?php endforeach; ?>
			</tr>
		</tbody>
	</table>
	</div><br>

<div class="agentic-callout-blue">
	<p class="agentic-mt-0 agentic-mb-0">
		<strong>How enforcement works:</strong> These settings control WordPress admin menu visibility, admin bar chat access, the REST chat API, and AJAX task triggers.
		Rules are applied at every entry point — unchecked roles are blocked at the server, not just hidden in the UI.
	</p>
</div>

<!-- Per-Minute Rate Limiting -->
<h3 class="agentic-settings-section-h3">Request Rate Limits</h3>
<p class="agentic-mb-12 agentic-text-dim">
	IP-based throttle applied before role-based daily quotas. Protects against rapid-fire requests and denial-of-service.
</p>
<table class="form-table">
	<tr>
		<th scope="row"><label for="agent_builder_rate_limit_authenticated">Authenticated Users</label></th>
		<td>
			<input type="number" name="agent_builder_rate_limit_authenticated" id="agent_builder_rate_limit_authenticated"
				value="<?php echo esc_attr( $agentic_rate_limit_auth ); ?>" min="5" max="300" class="small-text" />
			requests per minute
			<p class="description">Maximum chat requests per minute for logged-in users (tracked per user ID).</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="agent_builder_rate_limit_anonymous">Anonymous Visitors</label></th>
		<td>
			<input type="number" name="agent_builder_rate_limit_anonymous" id="agent_builder_rate_limit_anonymous"
				value="<?php echo esc_attr( $agentic_rate_limit_anon ); ?>" min="1" max="60" class="small-text" />
			requests per minute
			<p class="description">Maximum chat requests per minute for non-logged-in visitors (tracked per hashed IP).</p>
		</td>
	</tr>
</table>

<p class="agentic-mt-8"><a href="#" class="agentic-reset-defaults" data-section="users">Reset user settings to defaults</a></p>
