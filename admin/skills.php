<?php
/**
 * Skills Administration
 *
 * CRUD interface for agent skills. Skills contain SKILL.md instructions
 * that teach agents when and how to use channel-specific tools.
 * Supports local creation and import from the WordPress.org and Anthropic
 * skill repositories on GitHub and the curated Agentic AI skill list.
 *
 * @package    Agent_Builder
 * @subpackage Admin
 * @since      2.5.2
 *
 * php version 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Skills_Registry;

// The menu entry already requires this capability; checked again here because
// this template processes create/edit/delete/import submissions below.
if ( ! current_user_can( 'agent_builder_manage_tools' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to manage skills.', 'siteloom' ), 403 );
}
?>
<div class="wrap agentic-admin">
<h1><?php esc_html_e( 'Skills', 'siteloom' ); ?></h1>

<div class="agentic-card">
<?php

// ── Handle actions ────────────────────────────────────────────────────

$agentic_sk_message = '';
$agentic_sk_error   = '';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view param.
$agentic_sk_view = isset( $_GET['skill_view'] ) ? sanitize_key( wp_unslash( $_GET['skill_view'] ) ) : 'list';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$agentic_sk_edit_id = isset( $_GET['skill_id'] ) ? absint( $_GET['skill_id'] ) : 0;

// ── Save / Create ─────────────────────────────────────────────────────
if ( isset( $_POST['agentic_skill_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_skill_nonce'] ) ), 'agentic_skill_save' ) ) {

	$agentic_sk_post = array(
		'name'        => sanitize_text_field( wp_unslash( $_POST['agentic_sk_name'] ?? '' ) ),
		'description' => sanitize_text_field( wp_unslash( $_POST['agentic_sk_description'] ?? '' ) ),
		'content'     => wp_kses_post( wp_unslash( $_POST['agentic_sk_content'] ?? '' ) ),
		'agent_slug'  => isset( $_POST['agentic_sk_agents'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['agentic_sk_agents'] ) ) : array(),
		'author'      => sanitize_text_field( wp_unslash( $_POST['agentic_sk_author'] ?? '' ) ),
		'version'     => sanitize_text_field( wp_unslash( $_POST['agentic_sk_version'] ?? '1.0.0' ) ),
		'enabled'     => isset( $_POST['agentic_sk_enabled'] ),
	);

	if ( '' === $agentic_sk_post['name'] ) {
		$agentic_sk_error = __( 'Skill name is required.', 'siteloom' );
	} else {
		if ( $agentic_sk_edit_id > 0 ) {
			Skills_Registry::update( $agentic_sk_edit_id, $agentic_sk_post );
			$agentic_sk_message = __( 'Skill updated.', 'siteloom' );
		} else {
			$agentic_new_id = Skills_Registry::create( $agentic_sk_post );
			if ( $agentic_new_id ) {
				$agentic_sk_message = __( 'Skill created.', 'siteloom' );
				$agentic_sk_edit_id = $agentic_new_id;
			} else {
				$agentic_sk_error = __( 'Failed to create skill.', 'siteloom' );
			}
		}

		\Agentic\Security_Log::log_system( 'settings_changed', 'skills', array( 'skill_id' => $agentic_sk_edit_id ) );

		// Return to list after save.
		if ( '' === $agentic_sk_error ) {
			$agentic_sk_view    = 'list';
			$agentic_sk_edit_id = 0;
		}
	}
}

// ── Delete ────────────────────────────────────────────────────────────
if ( isset( $_GET['skill_action'], $_GET['skill_id'], $_GET['_wpnonce'] )
	&& 'delete' === $_GET['skill_action']
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_skill_delete_' . absint( $_GET['skill_id'] ) )
) {
	Skills_Registry::delete( absint( $_GET['skill_id'] ) );
	$agentic_sk_message = __( 'Skill deleted.', 'siteloom' );
	$agentic_sk_view    = 'list';
	$agentic_sk_edit_id = 0;

	\Agentic\Security_Log::log_system(
		'settings_changed',
		'skills',
		array(
			'action'   => 'deleted',
			'skill_id' => absint( $_GET['skill_id'] ),
		)
	);
}

// ── Reset a customized core skill back to its shipped version ─────────
if ( isset( $_GET['skill_action'], $_GET['skill_id'], $_GET['_wpnonce'] )
	&& 'reset' === $_GET['skill_action']
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'agentic_skill_reset_' . absint( $_GET['skill_id'] ) )
) {
	$agentic_sk_reset_id = absint( $_GET['skill_id'] );
	$agentic_sk_reset    = Skills_Registry::get( $agentic_sk_reset_id );

	if ( $agentic_sk_reset && 'core' === ( $agentic_sk_reset['source'] ?? '' ) ) {
		$agentic_sk_bundled = Skills_Registry::get_bundled_content( $agentic_sk_reset['source_id'] ?? '' );
		if ( null !== $agentic_sk_bundled ) {
			$agentic_sk_identity = Skills_Registry::parse_front_matter_identity( $agentic_sk_bundled );
			Skills_Registry::update(
				$agentic_sk_reset_id,
				array(
					'description' => $agentic_sk_identity['description'],
					'content'     => $agentic_sk_bundled,
					'source_hash' => hash( 'sha256', $agentic_sk_bundled ),
				)
			);
			$agentic_sk_message = __( 'Skill reset to the shipped version.', 'siteloom' );

			\Agentic\Security_Log::log_system(
				'settings_changed',
				'skills',
				array(
					'action'   => 'reset_to_shipped',
					'skill_id' => $agentic_sk_reset_id,
				)
			);
		} else {
			$agentic_sk_error = __( 'Could not find the shipped SKILL.md file to reset from.', 'siteloom' );
		}
	}
	$agentic_sk_view    = 'list';
	$agentic_sk_edit_id = 0;
}

// ── Import (Agentic AI or a browsable GitHub source) ───────────────────
if ( isset( $_POST['agentic_hub_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_hub_nonce'] ) ), 'agentic_hub_import' ) && current_user_can( 'agent_builder_manage_tools' ) ) {
	// The posted value is a JSON object built by skills-hub.js. It is decoded
	// and every field is sanitized individually right away by
	// Skills_Registry::sanitize_hub_data(). The raw JSON
	// string itself is never used or stored: sanitize_text_field() on the
	// whole blob would corrupt legitimate skill content (Markdown with
	// "<...>" code examples), so sanitization happens per decoded field.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON blob; decoded and sanitized field by field immediately below.
	$agentic_hub_raw  = json_decode( wp_unslash( $_POST['agentic_hub_data'] ?? '' ), true );
	$agentic_hub_data = is_array( $agentic_hub_raw ) ? \Agentic\Skills_Registry::sanitize_hub_data( $agentic_hub_raw ) : null;

	if ( is_array( $agentic_hub_data ) && ! empty( $agentic_hub_data['name'] ) ) {
		$agentic_imported_id = Skills_Registry::import_from_hub( $agentic_hub_data );
		if ( $agentic_imported_id ) {
			$agentic_sk_message = sprintf(
				/* translators: %s: skill name */
				__( 'Skill "%s" imported.', 'siteloom' ),
				$agentic_hub_data['name'] // Sanitized above; the whole message is esc_html()'d on output.
			);
		} else {
			$agentic_sk_error = __( 'Failed to import skill.', 'siteloom' );
		}
	} else {
		$agentic_sk_error = __( 'Invalid skill data.', 'siteloom' );
	}
	$agentic_sk_view = 'list';
}

// ── Load data ─────────────────────────────────────────────────────────
$agentic_sk_all  = Skills_Registry::get_all();
$agentic_sk_edit = ( 'edit' === $agentic_sk_view && $agentic_sk_edit_id > 0 ) ? Skills_Registry::get( $agentic_sk_edit_id ) : null;

// New-skill template gallery: 'new' with no template chosen yet shows the
// gallery instead of the form. Choosing one (or "Start from scratch")
// re-requests with &template=KEY, which falls through to the normal form.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selector, no state change.
$agentic_sk_template_key = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
$agentic_sk_templates    = Skills_Registry::get_templates();
$agentic_sk_show_gallery = ( 'new' === $agentic_sk_view && '' === $agentic_sk_template_key && ! isset( $_POST['agentic_skill_nonce'] ) );

// Basic/Advanced split happens on this page rather than by hiding the menu
// item (same principle as Settings' advanced-only tab cluster) — Skills is
// always reachable, but technical details (source/version columns, file
// import/export, spec validation, the prompt preview) only show in
// Advanced mode.
$agentic_sk_is_advanced = \Agentic\Admin_Menu_Handler::is_advanced_mode( 'skills' );

// Active agents for the dropdown.
$agentic_sk_registry  = \Agentic_Agent_Registry::get_instance();
$agentic_sk_agents    = $agentic_sk_registry->get_active_agents();
$agentic_sk_instances = $agentic_sk_registry->get_all_instances();

?>

<?php if ( $agentic_sk_message ) : ?>
<div class="notice notice-success"><p><?php echo esc_html( $agentic_sk_message ); ?></p></div>
<?php endif; ?>

<?php if ( $agentic_sk_error ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $agentic_sk_error ); ?></p></div>
<?php endif; ?>

<?php if ( ! $agentic_sk_is_advanced ) : ?>

	<?php include AGENT_BUILDER_DIR . 'admin/skills-basic.php'; ?>

<?php elseif ( $agentic_sk_show_gallery ) : ?>

	<div class="agentic-max-900">
		<h2 class="agentic-h2-flex">
			<?php esc_html_e( 'Create Skill', 'siteloom' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills' ) ); ?>" class="button agentic-ml-auto">&larr; <?php esc_html_e( 'Back to Skills', 'siteloom' ); ?></a>
		</h2>
		<p><?php esc_html_e( 'Start from a template close to what you need, or from a blank skill.', 'siteloom' ); ?></p>

		<div class="agentic-skills-template-grid">
			<?php foreach ( $agentic_sk_templates as $agentic_sk_tpl_key => $agentic_sk_tpl ) : ?>
				<a class="agentic-skill-card-js agentic-skill-template-card" href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=new&template=' . $agentic_sk_tpl_key ) ); ?>">
					<span class="agentic-badge-indigo-sm"><?php echo esc_html( $agentic_sk_tpl['category'] ); ?></span>
					<strong class="agentic-skill-card-title"><?php echo esc_html( $agentic_sk_tpl['label'] ); ?></strong>
					<p class="agentic-skill-card-desc"><?php echo esc_html( $agentic_sk_tpl['description'] ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( $agentic_sk_is_advanced ) : ?>
		<h3 class="agentic-th-mt-32"><?php esc_html_e( 'Or import an existing SKILL.md', 'siteloom' ); ?></h3>
		<p class="agentic-mb-12-text-dim"><?php esc_html_e( 'Any spec-compliant skill file — from Android Skills, Claude Skills, or elsewhere — can be dropped in directly.', 'siteloom' ); ?></p>
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag, no state change. ?>
		<?php if ( isset( $_GET['import_error'] ) ) : ?>
			<div class="notice notice-error agentic-notice-mt-0"><p><?php esc_html_e( 'Could not import that file — make sure it is a valid SKILL.md (YAML frontmatter followed by Markdown).', 'siteloom' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="agentic-flex-gap-8-mb-20">
			<?php wp_nonce_field( 'agentic_import_skill', 'agentic_skill_import_nonce' ); ?>
			<input type="hidden" name="action" value="agentic_import_skill" />
			<input type="file" name="agentic_skill_file" accept=".md,text/markdown,text/plain" required />
			<button type="submit" class="button"><?php esc_html_e( 'Import File', 'siteloom' ); ?></button>
		</form>
		<?php endif; ?>
	</div>

<?php elseif ( 'edit' === $agentic_sk_view || 'new' === $agentic_sk_view ) : ?>

	<?php
	$agentic_sk_from_template = ( 'new' === $agentic_sk_view && ! $agentic_sk_edit && '' !== $agentic_sk_template_key && isset( $agentic_sk_templates[ $agentic_sk_template_key ] ) )
		? $agentic_sk_templates[ $agentic_sk_template_key ]
		: null;

	$agentic_sk_name    = $agentic_sk_edit['name'] ?? '';
	$agentic_sk_desc    = $agentic_sk_edit['description'] ?? '';
	$agentic_sk_content = $agentic_sk_edit['content'] ?? ( $agentic_sk_from_template['content'] ?? '' );
	$agentic_sk_agents_selected = Skills_Registry::decode_agent_slugs( (string) ( $agentic_sk_edit['agent_slug'] ?? '' ) );
	$agentic_sk_author  = $agentic_sk_edit['author'] ?? '';
	$agentic_sk_version = $agentic_sk_edit['version'] ?? '1.0.0';
	$agentic_sk_enabled = $agentic_sk_edit ? ( '1' === ( $agentic_sk_edit['enabled'] ?? '0' ) ) : true;
	$agentic_sk_source  = $agentic_sk_edit['source'] ?? 'local';

	// Spec + tool-existence check against the content's own front matter,
	// not the DB columns — this is what actually ships in the SKILL.md body.
	$agentic_sk_identity        = Skills_Registry::parse_front_matter_identity( $agentic_sk_content );
	$agentic_sk_spec_errors     = Skills_Registry::validate_spec_fields( $agentic_sk_identity['name'], $agentic_sk_identity['description'] );
	$agentic_sk_declared_tools  = Skills_Registry::parse_front_matter_tools( $agentic_sk_content );
	$agentic_sk_known_tools     = array_keys( \Agentic\Tools_Registry::get_all() );
	$agentic_sk_unknown_tools   = array_values( array_diff( $agentic_sk_declared_tools, $agentic_sk_known_tools ) );
	$agentic_sk_is_customized   = $agentic_sk_edit ? Skills_Registry::is_customized( $agentic_sk_edit ) : false;

	// Preview: exactly the line this skill contributes to the [SKILLS] prompt
	// index (progressive-disclosure "metadata" tier — always injected), plus
	// a body-size check against the spec's ~500-line / ~5000-token guidance.
	$agentic_sk_preview_name  = '' !== $agentic_sk_identity['name'] ? $agentic_sk_identity['name'] : ( '' !== $agentic_sk_name ? $agentic_sk_name : '(unnamed)' );
	$agentic_sk_preview_desc  = '' !== $agentic_sk_identity['description'] ? $agentic_sk_identity['description'] : $agentic_sk_desc;
	$agentic_sk_preview_line  = sprintf( '- %s — %s [slug: %s]', $agentic_sk_preview_name, '' !== $agentic_sk_preview_desc ? $agentic_sk_preview_desc : __( 'No description', 'siteloom' ), $agentic_sk_edit['slug'] ?? sanitize_title( $agentic_sk_preview_name ) );
	$agentic_sk_body_lines    = substr_count( $agentic_sk_content, "\n" ) + 1;
	$agentic_sk_body_est_tok  = (int) ceil( strlen( $agentic_sk_content ) / 4 );
	?>

	<div class="agentic-max-800">
		<h2 class="agentic-h2-flex">
			<?php echo $agentic_sk_edit_id ? esc_html__( 'Edit Skill', 'siteloom' ) : esc_html__( 'Create Skill', 'siteloom' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills' ) ); ?>" class="button agentic-ml-auto">&larr; <?php esc_html_e( 'Back to Skills', 'siteloom' ); ?></a>
		</h2>

		<?php if ( 'core' === $agentic_sk_source ) : ?>
			<div class="notice notice-info agentic-notice-mt-0">
				<p>
					<?php if ( $agentic_sk_is_customized ) : ?>
						<strong><?php esc_html_e( 'This bundled skill has local edits.', 'siteloom' ); ?></strong>
						<?php esc_html_e( 'Plugin updates will not overwrite your changes.', 'siteloom' ); ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-skills&skill_action=reset&skill_id=' . $agentic_sk_edit_id ), 'agentic_skill_reset_' . $agentic_sk_edit_id ) ); ?>" data-agentic-confirm="<?php echo esc_attr( __( 'Discard your edits and restore the shipped version of this skill?', 'siteloom' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Reset', 'siteloom' ) ); ?>"><?php esc_html_e( 'Reset to shipped version', 'siteloom' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'This is a bundled skill. Editing it will fork a local copy — your changes will be preserved across plugin updates.', 'siteloom' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $agentic_sk_is_advanced && ( ! empty( $agentic_sk_spec_errors ) || ! empty( $agentic_sk_unknown_tools ) ) ) : ?>
			<div class="notice notice-warning agentic-notice-mt-0">
				<p><strong><?php esc_html_e( 'Spec check — not blocking, but fixing these keeps the skill portable to other agent tools:', 'siteloom' ); ?></strong></p>
				<ul class="agentic-list-disc-pl20">
					<?php foreach ( $agentic_sk_spec_errors as $agentic_sk_spec_err ) : ?>
						<li><?php echo esc_html( $agentic_sk_spec_err ); ?></li>
					<?php endforeach; ?>
					<?php if ( ! empty( $agentic_sk_unknown_tools ) ) : ?>
						<li>
							<?php
							printf(
								/* translators: %s: comma-separated tool names */
								esc_html__( 'allowed-tools references unknown tool(s): %s', 'siteloom' ),
								esc_html( implode( ', ', $agentic_sk_unknown_tools ) )
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=edit&skill_id=' . $agentic_sk_edit_id ) ); ?>">
			<?php wp_nonce_field( 'agentic_skill_save', 'agentic_skill_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="agentic_sk_name"><?php esc_html_e( 'Name', 'siteloom' ); ?></label></th>
					<td>
						<input type="text" id="agentic_sk_name" name="agentic_sk_name" value="<?php echo esc_attr( $agentic_sk_name ); ?>" class="regular-text" required />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="agentic_sk_description"><?php esc_html_e( 'Description', 'siteloom' ); ?></label></th>
					<td>
						<input type="text" id="agentic_sk_description" name="agentic_sk_description" value="<?php echo esc_attr( $agentic_sk_desc ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Brief summary of what this skill teaches the agent to do.', 'siteloom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="agentic_sk_content"><?php esc_html_e( 'SKILL.md Content', 'siteloom' ); ?></label></th>
					<td>
						<textarea id="agentic_sk_content" name="agentic_sk_content" rows="16" class="large-text code agentic-textarea-mono"><?php echo esc_textarea( $agentic_sk_content ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Markdown instructions that get injected into the agent\'s system prompt. Teaches the agent when and how to use specific tools.', 'siteloom' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Assign to Agents', 'siteloom' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Assign to Agents', 'siteloom' ); ?></legend>
							<?php foreach ( $agentic_sk_agents as $agentic_sk_slug ) : ?>
								<?php
								$agentic_sk_inst  = $agentic_sk_instances[ $agentic_sk_slug ] ?? null;
								$agentic_sk_label = $agentic_sk_inst ? $agentic_sk_inst->get_name() : ucwords( str_replace( '-', ' ', $agentic_sk_slug ) );
								?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="agentic_sk_agents[]" value="<?php echo esc_attr( $agentic_sk_slug ); ?>" <?php checked( in_array( $agentic_sk_slug, $agentic_sk_agents_selected, true ) ); ?> />
									<?php echo esc_html( $agentic_sk_label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Leave every box unchecked to make this skill available to all agents, or check one or more to limit it.', 'siteloom' ); ?></p>
					</td>
				</tr>
				<?php if ( $agentic_sk_is_advanced ) : ?>
				<tr>
					<th scope="row"><label for="agentic_sk_author"><?php esc_html_e( 'Author', 'siteloom' ); ?> <small>(<?php esc_html_e( 'optional', 'siteloom' ); ?>)</small></label></th>
					<td>
						<input type="text" id="agentic_sk_author" name="agentic_sk_author" value="<?php echo esc_attr( $agentic_sk_author ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="agentic_sk_version"><?php esc_html_e( 'Version', 'siteloom' ); ?></label></th>
					<td>
						<input type="text" id="agentic_sk_version" name="agentic_sk_version" value="<?php echo esc_attr( $agentic_sk_version ); ?>" class="small-text" />
					</td>
				</tr>
				<?php else : ?>
				<input type="hidden" name="agentic_sk_author" value="<?php echo esc_attr( $agentic_sk_author ); ?>" />
				<input type="hidden" name="agentic_sk_version" value="<?php echo esc_attr( $agentic_sk_version ); ?>" />
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'siteloom' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="agentic_sk_enabled" value="1" <?php checked( $agentic_sk_enabled ); ?> />
							<?php esc_html_e( 'Active — inject this skill into agent system prompts', 'siteloom' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php if ( $agentic_sk_is_advanced ) : ?>
			<details class="agentic-skill-preview">
				<summary><?php esc_html_e( 'Preview what this skill sends to the agent', 'siteloom' ); ?></summary>
				<div class="agentic-mb-12-text-dim">
					<p>
						<?php esc_html_e( 'Always in context (progressive-disclosure "metadata" tier — every enabled skill\'s index line):', 'siteloom' ); ?>
					</p>
					<code class="agentic-code-block-sm"><?php echo esc_html( $agentic_sk_preview_line ); ?></code>
					<p class="agentic-mt-12">
						<?php esc_html_e( 'Loaded only when the agent calls load_skill for this skill (the full body below):', 'siteloom' ); ?>
						<?php
						printf(
							/* translators: 1: line count, 2: estimated token count */
							esc_html__( '%1$d lines, ~%2$d tokens.', 'siteloom' ),
							(int) $agentic_sk_body_lines,
							(int) $agentic_sk_body_est_tok
						);
						?>
						<?php if ( $agentic_sk_body_lines > 500 ) : ?>
							<strong class="agentic-text-warning"><?php esc_html_e( 'Over the spec\'s ~500-line guidance — consider moving reference material out.', 'siteloom' ); ?></strong>
						<?php endif; ?>
					</p>
				</div>
			</details>
			<?php endif; ?>

			<?php if ( 'clawhub' === $agentic_sk_source ) : ?>
				<p class="agentic-text-dim-xs">
					<?php esc_html_e( 'This skill was imported from ClawHub, which is no longer an import source. It is now a local copy you can edit freely.', 'siteloom' ); ?>
				</p>
			<?php endif; ?>

			<p class="submit">
				<input type="submit" class="button-primary" value="<?php echo $agentic_sk_edit_id ? esc_attr__( 'Update Skill', 'siteloom' ) : esc_attr__( 'Create Skill', 'siteloom' ); ?>" />
			</p>
		</form>
	</div>

<?php elseif ( 'hub' === $agentic_sk_view ) : ?>

	<?php
	// WordPress and Anthropic are browsed directly from their official GitHub
	// repos (small, human-reviewed catalogs — no search API needed, just list
	// everything). Basic mode only ever sees WordPress.org.
	$agentic_sk_hub_sources = array(
		'wordpress' => array(
			'label'  => __( 'WordPress.org', 'siteloom' ),
			'type'   => 'github',
			'owner'  => 'WordPress',
			'repo'   => 'agent-skills',
			'branch' => 'trunk',
		),
	);
	if ( $agentic_sk_is_advanced ) {
		$agentic_sk_hub_sources['anthropic'] = array(
			'label'  => __( 'Anthropic', 'siteloom' ),
			'type'   => 'github',
			'owner'  => 'anthropics',
			'repo'   => 'skills',
			'branch' => 'main',
		);
	}
	?>

	<div class="agentic-max-800">
		<h2 class="agentic-h2-flex">
			<?php esc_html_e( 'Browse Community Skills', 'siteloom' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills' ) ); ?>" class="button agentic-ml-auto">&larr; <?php esc_html_e( 'Back to Skills', 'siteloom' ); ?></a>
		</h2>

		<?php if ( $agentic_sk_is_advanced ) : ?>
		<div id="agentic-hub-source-switcher" class="agentic-hub-source-switcher" role="tablist" aria-label="<?php esc_attr_e( 'Skill source', 'siteloom' ); ?>">
			<?php foreach ( $agentic_sk_hub_sources as $agentic_sk_src_key => $agentic_sk_src_cfg ) : ?>
				<button type="button" class="agentic-hub-source-tab<?php echo 'wordpress' === $agentic_sk_src_key ? ' is-active' : ''; ?>" data-source="<?php echo esc_attr( $agentic_sk_src_key ); ?>" role="tab"><?php echo esc_html( $agentic_sk_src_cfg['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php else : ?>
		<p class="agentic-mb-12-text-dim"><?php esc_html_e( 'Browsing skills from the official WordPress.org repository.', 'siteloom' ); ?></p>
		<?php endif; ?>

		<div id="agentic-hub-results" class="agentic-hub-results">
			<p class="agentic-text-dim"><?php esc_html_e( 'Loading...', 'siteloom' ); ?></p>
		</div>

		<!-- Hidden import form -->
		<form method="post" id="agentic-hub-import-form" style="display:none;">
			<?php wp_nonce_field( 'agentic_hub_import', 'agentic_hub_nonce' ); ?>
			<input type="hidden" name="agentic_hub_data" id="agentic-hub-data" value="" />
		</form>
	</div>

	<?php
	\Agentic\Inline_Assets::script(
		'skills-hub',
		array(
			'sources' => array_map( static fn( $s ) => array_intersect_key( $s, array_flip( array( 'label', 'type', 'owner', 'repo', 'branch' ) ) ), $agentic_sk_hub_sources ),
			'i18n' => array(
				'importThisSkill'        => __( 'Import this skill?', 'siteloom' ),
				'import'                 => __( 'Import', 'siteloom' ),
				'importing'              => __( 'Importing...', 'siteloom' ),
				'noSkillsFoundInThis'    => __( 'No skills found in this repository.', 'siteloom' ),
				'name'                   => __( 'Name', 'siteloom' ),
				'description'            => __( 'Description', 'siteloom' ),
				'action'                 => __( 'Action', 'siteloom' ),
				'loading'                => __( 'Loading...', 'siteloom' ),
				'failedToLoadSkillsFrom' => __( 'Failed to load skills from', 'siteloom' ),
			),
		),
		'agenticSkillsHub',
		array( 'agentic-ui' )
	);
	?>

<?php else : ?>

	<div class="agentic-max-900">
		<h2 class="agentic-h2-flex">
			<?php esc_html_e( 'Skills', 'siteloom' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=new' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Create Skill', 'siteloom' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=hub' ) ); ?>" class="button"><?php esc_html_e( 'Browse Community', 'siteloom' ); ?></a>
		</h2>
		<p><?php esc_html_e( 'Skills contain SKILL.md instructions that teach agents when and how to use specific tools. They are injected into the system prompt at runtime.', 'siteloom' ); ?></p>

		<!-- Recommended Skills from agentic-plugin.com -->
		<div id="agentic-recommended-skills" class="agentic-recommended-grid">
			<h3 class="agentic-h3-mb-8"><?php esc_html_e( 'Recommended Skills', 'siteloom' ); ?></h3>
			<p class="agentic-mb-12-text-dim"><?php esc_html_e( 'Curated skills verified to work with Siteloom. The list is fetched from agentic-plugin.com only when you click the button below.', 'siteloom' ); ?></p>
			<div id="agentic-recommended-grid" class="agentic-min-h-60">
				<button type="button" class="button" id="agentic-load-recommended"><?php esc_html_e( 'Show recommended skills', 'siteloom' ); ?></button>
			</div>
		</div>

		<!-- Hidden import form for recommended skills -->
		<form method="post" id="agentic-rec-import-form" style="display:none;">
			<?php wp_nonce_field( 'agentic_hub_import', 'agentic_hub_nonce' ); ?>
			<input type="hidden" name="agentic_hub_data" id="agentic-rec-data" value="" />
		</form>

		<?php
		\Agentic\Inline_Assets::script(
			'skills-recommended',
			array(
				'i18n' => array(
					'importThisSkill'                 => __( 'Import this skill?', 'siteloom' ),
					'import'                          => __( 'Import', 'siteloom' ),
					'importing'                       => __( 'Importing...', 'siteloom' ),
					'loading'                         => __( 'Loading...', 'siteloom' ),
					'noRecommendedSkillsAvailableYet' => __( 'No recommended skills available yet. Create your own or browse the community.', 'siteloom' ),
					'couldNotLoadRecommendedSkills'   => __( 'Could not load recommended skills. Create your own or browse the community.', 'siteloom' ),
				),
			),
			'agenticSkillsRecommended',
			array( 'agentic-ui' )
		);
		?>

		<?php if ( ! empty( $agentic_sk_all ) ) : ?>
		<h3 class="agentic-th-mt-32"><?php esc_html_e( 'Your Skills', 'siteloom' ); ?></h3>
		<?php endif; ?>

		<?php if ( empty( $agentic_sk_all ) ) : ?>
			<h3 class="agentic-th-mt-32"><?php esc_html_e( 'Your Skills', 'siteloom' ); ?></h3>
			<div class="notice notice-info agentic-notice-mt-0">
				<p>
					<strong><?php esc_html_e( 'No skills installed yet.', 'siteloom' ); ?></strong>
					<?php esc_html_e( 'Import a recommended skill above, create your own, or browse the community.', 'siteloom' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="widefat striped agentic-table-mt-16">
				<thead>
					<tr>
						<th class="agentic-col-60"><?php esc_html_e( 'Status', 'siteloom' ); ?></th>
						<th class="agentic-col-200"><?php esc_html_e( 'Name', 'siteloom' ); ?></th>
						<th><?php esc_html_e( 'Description', 'siteloom' ); ?></th>
						<th class="agentic-col-140"><?php esc_html_e( 'Agent', 'siteloom' ); ?></th>
						<?php if ( $agentic_sk_is_advanced ) : ?>
						<th class="agentic-col-80"><?php esc_html_e( 'Source', 'siteloom' ); ?></th>
						<th class="agentic-col-80"><?php esc_html_e( 'Version', 'siteloom' ); ?></th>
						<?php endif; ?>
						<th class="agentic-col-140"><?php esc_html_e( 'Actions', 'siteloom' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agentic_sk_all as $agentic_sk_item ) : ?>
					<tr<?php echo '1' !== ( $agentic_sk_item['enabled'] ?? '0' ) ? ' class="agentic-opacity-60"' : ''; ?>>
						<td class="agentic-text-center">
							<?php if ( '1' === ( $agentic_sk_item['enabled'] ?? '0' ) ) : ?>
								<span class="agentic-status-dot-active" title="<?php esc_attr_e( 'Active', 'siteloom' ); ?>"></span>
							<?php else : ?>
								<span class="agentic-status-dot-inactive" title="<?php esc_attr_e( 'Disabled', 'siteloom' ); ?>"></span>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( $agentic_sk_item['name'] ); ?></strong>
						</td>
						<td>
							<?php echo esc_html( $agentic_sk_item['description'] ); ?>
						</td>
						<td>
							<?php
							$agentic_sk_a_slugs = Skills_Registry::decode_agent_slugs( (string) ( $agentic_sk_item['agent_slug'] ?? '' ) );
							if ( empty( $agentic_sk_a_slugs ) ) {
								echo '<span class="agentic-text-dim">' . esc_html__( 'All', 'siteloom' ) . '</span>';
							} else {
								$agentic_sk_a_names = array_map(
									static function ( $agentic_sk_a_slug ) use ( $agentic_sk_instances ) {
										$agentic_sk_a_inst = $agentic_sk_instances[ $agentic_sk_a_slug ] ?? null;
										return $agentic_sk_a_inst ? $agentic_sk_a_inst->get_name() : ucwords( str_replace( '-', ' ', $agentic_sk_a_slug ) );
									},
									$agentic_sk_a_slugs
								);
								echo esc_html( implode( ', ', $agentic_sk_a_names ) );
							}
							?>
						</td>
						<?php if ( $agentic_sk_is_advanced ) : ?>
						<td>
							<?php
							$agentic_sk_src = $agentic_sk_item['source'] ?? 'local';
							if ( 'core' === $agentic_sk_src ) :
								?>
								<span class="agentic-badge-local"><?php esc_html_e( 'Core', 'siteloom' ); ?></span>
							<?php elseif ( 'agentic' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-agentic">Agentic</span>
							<?php elseif ( 'clawhub' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-clhub">ClawHub</span>
							<?php elseif ( 'wordpress' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-wordpress">WordPress.org</span>
							<?php elseif ( 'anthropic' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-anthropic">Anthropic</span>
							<?php else : ?>
								<span class="agentic-badge-local"><?php esc_html_e( 'Local', 'siteloom' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<code class="agentic-code-sm"><?php echo esc_html( $agentic_sk_item['version'] ?? '1.0.0' ); ?></code>
						</td>
						<?php endif; ?>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=edit&skill_id=' . $agentic_sk_item['id'] ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'siteloom' ); ?></a>
							<?php if ( $agentic_sk_is_advanced ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentic_export_skill&skill_id=' . $agentic_sk_item['id'] ), 'agentic_export_skill' ) ); ?>" class="button button-small"><?php esc_html_e( 'Export', 'siteloom' ); ?></a>
							<?php endif; ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-skills&skill_action=delete&skill_id=' . $agentic_sk_item['id'] ), 'agentic_skill_delete_' . $agentic_sk_item['id'] ) ); ?>" class="button button-small agentic-link-danger" data-agentic-confirm="<?php echo esc_attr( __( 'Delete this skill?', 'siteloom' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Delete', 'siteloom' ) ); ?>"><?php esc_html_e( 'Delete', 'siteloom' ); ?></a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div class="agentic-about-skills-box">
			<h3 class="agentic-about-skills-h3"><?php esc_html_e( 'About Skills', 'siteloom' ); ?></h3>
			<ul class="agentic-list-disc-pl20">
				<li><?php esc_html_e( 'Skills contain SKILL.md instructions — markdown that gets injected into the agent\'s system prompt.', 'siteloom' ); ?></li>
				<li><?php esc_html_e( 'They teach agents when and how to use channel-specific tools (e.g., "when a user asks to speak to a human, use the Slack handoff tool").', 'siteloom' ); ?></li>
				<li><?php esc_html_e( 'Skills never contain API keys or direct HTTP calls — they operate above channels in the stack: agent → skill → channel → tool.', 'siteloom' ); ?></li>
				<li><?php esc_html_e( 'Assign a skill to a specific agent, or leave it as "All" to make it available globally.', 'siteloom' ); ?></li>
				<li><?php esc_html_e( 'Import curated skills from the Recommended section, browse community skills, or create your own.', 'siteloom' ); ?></li>
			</ul>
		</div>
	</div>
</div><!-- /.agentic-card -->

<?php endif; ?>
