<?php
/**
 * Deployment Tab: Shortcodes
 *
 * Reads and writes from wp_agentic_deployments (type=shortcode).
 * Auto-detected (source=auto/code) rows are shown read-only with an
 * enable/disable toggle — they cannot be deleted from here since they
 * live in theme or plugin files.
 *
 * Included by admin/deployment.php — do not load directly.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Deployments;

// ── Helpers ───────────────────────────────────────────────────────────────────

$agentic_valid_sc_styles = array( 'inline', 'popup', 'sidebar' );

/**
 * Flatten a Deployments row into the shape the form partial expects.
 *
 * @param array $row Deployment row from Deployments::get() or all().
 * @return array
 */
function agentic_sc_form_data( array $row ): array {
	$cfg = $row['config'] ?? array();
	return array(
		'label'       => $row['label'],
		'agent'       => $row['agent_slug'],
		'style'       => $cfg['style'] ?? 'inline',
		'height'      => $cfg['height'] ?? '500px',
		'show_header' => ( $cfg['show_header'] ?? true ) ? 'true' : 'false',
		'placeholder' => $cfg['placeholder'] ?? '',
	);
}

// ── Actions ───────────────────────────────────────────────────────────────────

if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'agentic_manage_shortcodes' ) ) {

	// Delete (admin-created rows only).
	if ( isset( $_POST['agentic_delete_shortcode'] ) ) {
		$agentic_delete_id = absint( wp_unslash( $_POST['agentic_shortcode_id'] ?? 0 ) );
		$agentic_row       = Deployments::get( $agentic_delete_id );
		if ( $agentic_row && Deployments::SOURCE_ADMIN === $agentic_row['source'] ) {
			Deployments::delete( $agentic_delete_id );
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'deployments',
				array(
					'setting' => 'shortcode_deleted',
					'changes' => array( 'id' => $agentic_delete_id ),
				)
			);
			$agentic_notice = __( 'Shortcode deployment removed.', 'agent-builder' );
		}
	}

	// Enable / disable.
	if ( isset( $_POST['agentic_toggle_shortcode'] ) ) {
		$agentic_toggle_id = absint( wp_unslash( $_POST['agentic_shortcode_id'] ?? 0 ) );
		$agentic_row       = Deployments::get( $agentic_toggle_id );
		if ( $agentic_row ) {
			$agentic_row['enabled'] ? Deployments::disable( $agentic_toggle_id ) : Deployments::enable( $agentic_toggle_id );
			$agentic_notice = $agentic_row['enabled']
				? __( 'Shortcode deployment disabled.', 'agent-builder' )
				: __( 'Shortcode deployment enabled.', 'agent-builder' );
		}
	}

	// Create.
	if ( isset( $_POST['agentic_create_shortcode'] ) ) {
		$agentic_sc_style_in = sanitize_key( $_POST['agentic_sc_style'] ?? 'inline' );
		$agentic_new_label   = sanitize_text_field( wp_unslash( $_POST['agentic_sc_label'] ?? '' ) );
		$agentic_new_agent   = sanitize_key( wp_unslash( $_POST['agentic_sc_agent'] ?? '' ) );

		if ( ! $agentic_new_label || ! $agentic_new_agent ) {
			$agentic_error = __( 'Label and Agent are required.', 'agent-builder' );
		} else {
			$agentic_style  = in_array( $agentic_sc_style_in, $agentic_valid_sc_styles, true ) ? $agentic_sc_style_in : 'inline';
			$agentic_new_id = Deployments::save(
				array(
					'type'       => Deployments::TYPE_SHORTCODE,
					'agent_slug' => $agentic_new_agent,
					'label'      => $agentic_new_label,
					'enabled'    => 1,
					'source'     => Deployments::SOURCE_ADMIN,
					'config'     => array(
						'style'       => $agentic_style,
						'height'      => sanitize_text_field( wp_unslash( $_POST['agentic_sc_height'] ?? '500px' ) ),
						'show_header' => isset( $_POST['agentic_sc_show_header'] ),
						'placeholder' => sanitize_text_field( wp_unslash( $_POST['agentic_sc_placeholder'] ?? '' ) ),
					),
				)
			);
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'deployments',
				array(
					'setting' => 'shortcode_created',
					'changes' => array(
						'id'    => $agentic_new_id,
						'label' => $agentic_new_label,
						'agent' => $agentic_new_agent,
					),
				)
			);
			$agentic_created_row = Deployments::get( $agentic_new_id );
			$agentic_sc_string   = $agentic_created_row ? Deployments::build_shortcode_string( $agentic_created_row ) : '';
			$agentic_notice      = sprintf(
				/* translators: %s: shortcode string */
				__( 'Shortcode created. Copy and paste it into any page, post, or widget: %s', 'agent-builder' ),
				$agentic_sc_string
			);
		}
	}

	// Update (admin-created rows only).
	if ( isset( $_POST['agentic_update_shortcode'] ) ) {
		$agentic_upd_id      = absint( wp_unslash( $_POST['agentic_shortcode_id'] ?? 0 ) );
		$agentic_upd_label   = sanitize_text_field( wp_unslash( $_POST['agentic_sc_label'] ?? '' ) );
		$agentic_upd_agent   = sanitize_key( wp_unslash( $_POST['agentic_sc_agent'] ?? '' ) );
		$agentic_sc_style_in = sanitize_key( $_POST['agentic_sc_style'] ?? 'inline' );
		$agentic_row         = Deployments::get( $agentic_upd_id );

		if ( ! $agentic_upd_label || ! $agentic_upd_agent ) {
			$agentic_error = __( 'Label and Agent are required.', 'agent-builder' );
		} elseif ( $agentic_row && Deployments::SOURCE_ADMIN === $agentic_row['source'] ) {
			$agentic_style = in_array( $agentic_sc_style_in, $agentic_valid_sc_styles, true ) ? $agentic_sc_style_in : 'inline';
			Deployments::save(
				array(
					'id'         => $agentic_upd_id,
					'agent_slug' => $agentic_upd_agent,
					'label'      => $agentic_upd_label,
					'config'     => array(
						'style'       => $agentic_style,
						'height'      => sanitize_text_field( wp_unslash( $_POST['agentic_sc_height'] ?? '500px' ) ),
						'show_header' => isset( $_POST['agentic_sc_show_header'] ),
						'placeholder' => sanitize_text_field( wp_unslash( $_POST['agentic_sc_placeholder'] ?? '' ) ),
					),
				)
			);
			\Agentic\Security_Log::log_system(
				'settings_changed',
				'deployments',
				array(
					'setting' => 'shortcode_updated',
					'changes' => array( 'id' => $agentic_upd_id ),
				)
			);
			$agentic_notice  = __( 'Shortcode deployment updated.', 'agent-builder' );
			$agentic_edit_sc = 0;
		}
	}
}

// ── Load data ─────────────────────────────────────────────────────────────────

$agentic_registry    = \Agentic_Agent_Registry::get_instance();
$agentic_all_agents  = $agentic_registry->get_all_instances();
$agentic_deployments = Deployments::all( Deployments::TYPE_SHORTCODE );

// ── Edit mode ─────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$agentic_edit_sc     = isset( $agentic_edit_sc ) ? (int) $agentic_edit_sc : ( isset( $_GET['edit_sc'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['edit_sc'] ) ) : 0 );
$agentic_editing_dep = null;
if ( $agentic_edit_sc ) {
	$agentic_editing_dep = Deployments::get( $agentic_edit_sc );
	if ( ! $agentic_editing_dep || Deployments::SOURCE_ADMIN !== $agentic_editing_dep['source'] ) {
		$agentic_edit_sc     = 0;
		$agentic_editing_dep = null;
	}
}

// ── Source badge labels ───────────────────────────────────────────────────────

$agentic_source_labels = array(
	Deployments::SOURCE_ADMIN => array(
		'label' => __( 'Admin', 'agent-builder' ),
		'color' => '#2271b1',
	),
	Deployments::SOURCE_AUTO  => array(
		'label' => __( 'Auto-detected', 'agent-builder' ),
		'color' => '#996800',
	),
	Deployments::SOURCE_CODE  => array(
		'label' => __( 'Hardcoded', 'agent-builder' ),
		'color' => '#6d3f8a',
	),
);

// ── Notices ───────────────────────────────────────────────────────────────────
?>

<?php if ( ! empty( $agentic_notice ) ) : ?>
<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $agentic_notice ); ?></p></div>
<?php endif; ?>

<?php if ( ! empty( $agentic_error ) ) : ?>
<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $agentic_error ); ?></p></div>
<?php endif; ?>

<!-- Existing Deployments -->
<h2><?php esc_html_e( 'Shortcode Deployments', 'agent-builder' ); ?></h2>

<?php if ( empty( $agentic_deployments ) ) : ?>
<div class="notice notice-info agentic-table-mt-0">
	<p><?php esc_html_e( 'No shortcode deployments yet. Use the form below to create one, or place [agentic_chat] in a page — it will be auto-detected here on first load.', 'agent-builder' ); ?></p>
</div>
<?php else : ?>
<div class="agentic-table-scroll">
<table class="widefat striped agentic-table-min-900">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Label', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Style', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Source', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Status', 'agent-builder' ); ?></th>
			<th class="agentic-col-200"><?php esc_html_e( 'Shortcode', 'agent-builder' ); ?></th>
			<th><?php esc_html_e( 'Created', 'agent-builder' ); ?></th>
			<th class="agentic-col-120"><?php esc_html_e( 'Actions', 'agent-builder' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $agentic_deployments as $agentic_dep ) :
			$agentic_sc_string    = Deployments::build_shortcode_string( $agentic_dep );
			$agentic_cfg          = $agentic_dep['config'];
			$agentic_style_str    = $agentic_cfg['style'] ?? 'inline';
			$agentic_style_labels = array(
				'inline'  => __( 'Inline', 'agent-builder' ),
				'popup'   => __( 'Popup', 'agent-builder' ),
				'sidebar' => __( 'Sidebar', 'agent-builder' ),
			);
			$agentic_agent_name   = $agentic_dep['agent_slug'];
			if ( isset( $agentic_all_agents[ $agentic_dep['agent_slug'] ] ) ) {
				$agentic_agent_obj  = $agentic_all_agents[ $agentic_dep['agent_slug'] ];
				$agentic_agent_name = $agentic_agent_obj->get_icon() . ' ' . $agentic_agent_obj->get_name();
			}
			$agentic_src      = $agentic_dep['source'] ?? Deployments::SOURCE_ADMIN;
			$agentic_src_info = $agentic_source_labels[ $agentic_src ] ?? $agentic_source_labels[ Deployments::SOURCE_ADMIN ];
			$agentic_is_admin = Deployments::SOURCE_ADMIN === $agentic_src;
			?>
		<tr <?php echo ! $agentic_dep['enabled'] ? 'style="opacity:0.6;"' : ''; ?>>
			<td><strong><?php echo esc_html( $agentic_dep['label'] ); ?></strong></td>
			<td><?php echo esc_html( $agentic_agent_name ); ?></td>
			<td><?php echo esc_html( $agentic_style_labels[ $agentic_style_str ] ?? ucfirst( $agentic_style_str ) ); ?></td>
			<td>
				<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:<?php echo esc_attr( $agentic_src_info['color'] ); ?>;color:#fff;">
					<?php echo esc_html( $agentic_src_info['label'] ); ?>
				</span>
			</td>
			<td>
				<?php if ( $agentic_dep['enabled'] ) : ?>
					<span style="color:#00a32a;font-weight:600;">&#x25CF; <?php esc_html_e( 'Enabled', 'agent-builder' ); ?></span>
				<?php else : ?>
					<span style="color:#787c82;">&#x25CF; <?php esc_html_e( 'Disabled', 'agent-builder' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<code class="agentic-sc-copyable" style="cursor:pointer;"
					title="<?php esc_attr_e( 'Click to copy', 'agent-builder' ); ?>"
					data-shortcode="<?php echo esc_attr( $agentic_sc_string ); ?>">
					<?php echo esc_html( $agentic_sc_string ); ?>
				</code>
			</td>
			<td><small><?php echo esc_html( $agentic_dep['created_at'] ?? '' ); ?></small></td>
			<td>
				<!-- Enable / Disable toggle (all sources) -->
				<form method="post" class="agentic-form-inline">
					<?php wp_nonce_field( 'agentic_manage_shortcodes' ); ?>
					<input type="hidden" name="agentic_shortcode_id" value="<?php echo esc_attr( $agentic_dep['id'] ); ?>">
					<button type="submit" name="agentic_toggle_shortcode" value="1" class="button button-small">
						<?php echo $agentic_dep['enabled'] ? esc_html__( 'Disable', 'agent-builder' ) : esc_html__( 'Enable', 'agent-builder' ); ?>
					</button>
				</form>
				<?php if ( $agentic_is_admin ) : ?>
				<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'    => 'agentic-deployment',
								'tab'     => 'shortcodes',
								'edit_sc' => $agentic_dep['id'],
							),
							admin_url( 'admin.php' )
						)
					);
					?>
							" class="button button-small">
					<?php esc_html_e( 'Edit', 'agent-builder' ); ?>
				</a>
				<form method="post" class="agentic-form-inline" data-agentic-confirm="<?php esc_attr_e( 'Remove this shortcode deployment?', 'agent-builder' ); ?>" data-agentic-confirm-danger>
					<?php wp_nonce_field( 'agentic_manage_shortcodes' ); ?>
					<input type="hidden" name="agentic_shortcode_id" value="<?php echo esc_attr( $agentic_dep['id'] ); ?>">
					<button type="submit" name="agentic_delete_shortcode" value="1" class="button button-small button-link-delete">
						<?php esc_html_e( 'Delete', 'agent-builder' ); ?>
					</button>
				</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>
<?php endif; ?>

<?php if ( $agentic_edit_sc && $agentic_editing_dep ) : ?>

<!-- Edit Deployment -->
<h2 class="agentic-shortcodes-h2">
	<?php esc_html_e( 'Edit Deployment', 'agent-builder' ); ?>
	<a href="
	<?php
	echo esc_url(
		add_query_arg(
			array(
				'page' => 'agentic-deployment',
				'tab'  => 'shortcodes',
			),
			admin_url( 'admin.php' )
		)
	);
	?>
				" class="page-title-action agentic-ml-8"><?php esc_html_e( '&larr; Back', 'agent-builder' ); ?></a>
</h2>

	<?php if ( empty( $agentic_all_agents ) ) : ?>
<div class="notice notice-warning"><p><?php esc_html_e( 'No active agents.', 'agent-builder' ); ?></p></div>
	<?php else : ?>
<form method="post">
		<?php wp_nonce_field( 'agentic_manage_shortcodes' ); ?>
	<input type="hidden" name="agentic_shortcode_id" value="<?php echo esc_attr( $agentic_editing_dep['id'] ); ?>">
		<?php $agentic_form_data = agentic_sc_form_data( $agentic_editing_dep ); ?>
		<?php include __DIR__ . '/deployment-shortcodes-form.php'; ?>
		<?php submit_button( __( 'Save Changes', 'agent-builder' ), 'primary', 'agentic_update_shortcode' ); ?>
</form>
	<?php endif; ?>

<?php else : ?>

<!-- Create New Deployment -->
<h2 class="agentic-shortcodes-h2"><?php esc_html_e( 'Create New Deployment', 'agent-builder' ); ?></h2>

	<?php if ( empty( $agentic_all_agents ) ) : ?>
<div class="notice notice-warning">
	<p><?php esc_html_e( 'No active agents. Activate at least one agent before creating a shortcode deployment.', 'agent-builder' ); ?></p>
</div>
	<?php else : ?>
<form method="post">
		<?php wp_nonce_field( 'agentic_manage_shortcodes' ); ?>
		<?php
		$agentic_form_data = array(
			'label'       => '',
			'agent'       => '',
			'style'       => 'inline',
			'height'      => '500px',
			'show_header' => 'true',
			'placeholder' => '',
		);
		?>
		<?php include __DIR__ . '/deployment-shortcodes-form.php'; ?>
		<?php submit_button( __( 'Create Shortcode', 'agent-builder' ), 'primary', 'agentic_create_shortcode' ); ?>
</form>
	<?php endif; ?>

<?php endif; ?>

<div class="agentic-embed-note">
	<h3><?php esc_html_e( 'How Shortcodes Work', 'agent-builder' ); ?></h3>
	<ul>
		<li><?php esc_html_e( 'Paste the shortcode into any page, post, or text widget to embed an agent chat interface.', 'agent-builder' ); ?></li>
		<li>
			<?php esc_html_e( 'Two shortcodes are available:', 'agent-builder' ); ?>
			<ul class="agentic-list-circle">
				<li><code>[agentic_chat]</code> — <?php esc_html_e( 'Full interactive chat with conversation history', 'agent-builder' ); ?></li>
				<li><code>[agentic_ask]</code> — <?php esc_html_e( 'One-shot query that displays a single response inline', 'agent-builder' ); ?></li>
			</ul>
		</li>
		<li><?php esc_html_e( 'Shortcodes placed directly in theme or plugin files are auto-detected on first page load and shown here with source "Auto-detected" or "Hardcoded".', 'agent-builder' ); ?></li>
		<li><?php esc_html_e( 'All agent interactions via shortcodes are logged in the Audit Log.', 'agent-builder' ); ?></li>
	</ul>
</div>

<script>
(function() {
	'use strict';
	var agent       = document.getElementById('agentic_sc_agent');
	var placeholder = document.getElementById('agentic_sc_placeholder');
	var style       = document.getElementById('agentic_sc_style');
	var height      = document.getElementById('agentic_sc_height');
	var showHeader  = document.getElementById('agentic_sc_show_header');
	var preview     = document.getElementById('agentic-sc-preview-code');

	function updatePreview() {
		if (!agent || !preview) return;
		var parts = [];
		if (agent.value) parts.push('agent="' + agent.value + '"');
		if (style && style.value && style.value !== 'inline') parts.push('style="' + style.value + '"');
		if (height && height.value && height.value !== '500px') parts.push('height="' + height.value + '"');
		if (placeholder && placeholder.value) parts.push('placeholder="' + placeholder.value + '"');
		if (showHeader && !showHeader.checked) parts.push('show_header="false"');
		preview.textContent = '[agentic_chat' + (parts.length ? ' ' + parts.join(' ') : '') + ']';
	}

	[agent, placeholder, style, height, showHeader].filter(Boolean).forEach(function(el) {
		el.addEventListener('input', updatePreview);
		el.addEventListener('change', updatePreview);
	});
	if (agent) updatePreview();

	document.querySelectorAll('.agentic-sc-copyable').forEach(function(el) {
		el.addEventListener('click', function() {
			var sc = this.dataset.shortcode;
			if (navigator.clipboard) {
				navigator.clipboard.writeText(sc).then(function() {
					el.style.background = '#d1fae5';
					setTimeout(function() { el.style.background = ''; }, 1000);
				});
			} else {
				var ta = document.createElement('textarea');
				ta.value = sc; document.body.appendChild(ta); ta.select();
				document.execCommand('copy'); document.body.removeChild(ta);
				el.style.background = '#d1fae5';
				setTimeout(function() { el.style.background = ''; }, 1000);
			}
		});
	});
})();
</script>
