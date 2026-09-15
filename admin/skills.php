<?php
/**
 * Skills Administration
 *
 * CRUD interface for agent skills. Skills contain SKILL.md instructions
 * that teach agents when and how to use channel-specific tools.
 * Supports local creation and ClawHub import.
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
?>
<div class="wrap agentic-admin">
<h1><?php esc_html_e( 'Skills', 'agent-builder' ); ?></h1>

<div class="agentic-card">
<?php

// ── Handle actions ────────────────────────────────────────────────────

$agentic_sk_message = '';
$agentic_sk_error   = '';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view param.
$agentic_sk_view = isset( $_GET['skill_view'] ) ? sanitize_key( $_GET['skill_view'] ) : 'list';
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
		$agentic_sk_error = __( 'Skill name is required.', 'agent-builder' );
	} else {
		if ( $agentic_sk_edit_id > 0 ) {
			Skills_Registry::update( $agentic_sk_edit_id, $agentic_sk_post );
			$agentic_sk_message = __( 'Skill updated.', 'agent-builder' );
		} else {
			$agentic_new_id = Skills_Registry::create( $agentic_sk_post );
			if ( $agentic_new_id ) {
				$agentic_sk_message = __( 'Skill created.', 'agent-builder' );
				$agentic_sk_edit_id = $agentic_new_id;
			} else {
				$agentic_sk_error = __( 'Failed to create skill.', 'agent-builder' );
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
	$agentic_sk_message = __( 'Skill deleted.', 'agent-builder' );
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
			$agentic_sk_message = __( 'Skill reset to the shipped version.', 'agent-builder' );

			\Agentic\Security_Log::log_system(
				'settings_changed',
				'skills',
				array(
					'action'   => 'reset_to_shipped',
					'skill_id' => $agentic_sk_reset_id,
				)
			);
		} else {
			$agentic_sk_error = __( 'Could not find the shipped SKILL.md file to reset from.', 'agent-builder' );
		}
	}
	$agentic_sk_view    = 'list';
	$agentic_sk_edit_id = 0;
}

// ── Import (Agentic AI, ClawHub, or a browsable GitHub source) ─────────
if ( isset( $_POST['agentic_hub_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_hub_nonce'] ) ), 'agentic_hub_import' ) ) {
	// Note: NOT sanitize_text_field() — it strips anything that looks like an
	// HTML tag, which corrupts real skill content (WordPress/PHP/block-markup
	// examples routinely contain "<...>") once it's embedded in this JSON
	// blob. json_decode() below fails safely on malformed input either way,
	// and every individual field is sanitized on its own terms downstream in
	// Skills_Registry::import_from_hub()/create().
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see comment above: sanitize_text_field() would corrupt legitimate skill content; downstream code sanitizes each field individually.
	$agentic_hub_json = wp_unslash( $_POST['agentic_hub_data'] ?? '' );
	$agentic_hub_data = json_decode( $agentic_hub_json, true );

	// Normalise field names (ClawHub uses displayName/summary).
	if ( is_array( $agentic_hub_data ) && empty( $agentic_hub_data['name'] ) && ! empty( $agentic_hub_data['displayName'] ) ) {
		$agentic_hub_data['name'] = $agentic_hub_data['displayName'];
	}

	// Determine source. 'wordpress' and 'anthropic' are browsed straight from
	// their official GitHub repos; 'agentic' is the curated Recommended list;
	// anything else falls back to 'clawhub'.
	$agentic_import_source = sanitize_key( $agentic_hub_data['source'] ?? 'clawhub' );
	if ( ! in_array( $agentic_import_source, array( 'agentic', 'clawhub', 'wordpress', 'anthropic' ), true ) ) {
		$agentic_import_source = 'clawhub';
	}
	$agentic_hub_data['source'] = $agentic_import_source;

	if ( is_array( $agentic_hub_data ) && ! empty( $agentic_hub_data['name'] ) ) {
		$agentic_imported_id = Skills_Registry::import_from_hub( $agentic_hub_data );
		if ( $agentic_imported_id ) {
			$agentic_sk_message = sprintf(
				/* translators: %s: skill name */
				__( 'Skill "%s" imported.', 'agent-builder' ),
				esc_html( $agentic_hub_data['name'] )
			);
		} else {
			$agentic_sk_error = __( 'Failed to import skill.', 'agent-builder' );
		}
	} else {
		$agentic_sk_error = __( 'Invalid skill data.', 'agent-builder' );
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
$agentic_sk_template_key = isset( $_GET['template'] ) ? sanitize_key( $_GET['template'] ) : '';
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
			<?php esc_html_e( 'Create Skill', 'agent-builder' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills' ) ); ?>" class="button agentic-ml-auto">&larr; <?php esc_html_e( 'Back to Skills', 'agent-builder' ); ?></a>
		</h2>
		<p><?php esc_html_e( 'Start from a template close to what you need, or from a blank skill.', 'agent-builder' ); ?></p>

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
		<h3 class="agentic-th-mt-32"><?php esc_html_e( 'Or import an existing SKILL.md', 'agent-builder' ); ?></h3>
		<p class="agentic-mb-12-text-dim"><?php esc_html_e( 'Any spec-compliant skill file — from ClawHub, Android Skills, Claude Skills, or elsewhere — can be dropped in directly.', 'agent-builder' ); ?></p>
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag, no state change. ?>
		<?php if ( isset( $_GET['import_error'] ) ) : ?>
			<div class="notice notice-error agentic-notice-mt-0"><p><?php esc_html_e( 'Could not import that file — make sure it is a valid SKILL.md (YAML frontmatter followed by Markdown).', 'agent-builder' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="agentic-flex-gap-8-mb-20">
			<?php wp_nonce_field( 'agentic_import_skill', 'agentic_skill_import_nonce' ); ?>
			<input type="hidden" name="action" value="agentic_import_skill" />
			<input type="file" name="agentic_skill_file" accept=".md,text/markdown,text/plain" required />
			<button type="submit" class="button"><?php esc_html_e( 'Import File', 'agent-builder' ); ?></button>
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
	$agentic_sk_preview_line  = sprintf( '- %s — %s [slug: %s]', $agentic_sk_preview_name, '' !== $agentic_sk_preview_desc ? $agentic_sk_preview_desc : __( 'No description', 'agent-builder' ), $agentic_sk_edit['slug'] ?? sanitize_title( $agentic_sk_preview_name ) );
	$agentic_sk_body_lines    = substr_count( $agentic_sk_content, "\n" ) + 1;
	$agentic_sk_body_est_tok  = (int) ceil( strlen( $agentic_sk_content ) / 4 );
	?>

	<div class="agentic-max-800">
		<h2 class="agentic-h2-flex">
			<?php echo $agentic_sk_edit_id ? esc_html__( 'Edit Skill', 'agent-builder' ) : esc_html__( 'Create Skill', 'agent-builder' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills' ) ); ?>" class="button agentic-ml-auto">&larr; <?php esc_html_e( 'Back to Skills', 'agent-builder' ); ?></a>
		</h2>

		<?php if ( 'core' === $agentic_sk_source ) : ?>
			<div class="notice notice-info agentic-notice-mt-0">
				<p>
					<?php if ( $agentic_sk_is_customized ) : ?>
						<strong><?php esc_html_e( 'This bundled skill has local edits.', 'agent-builder' ); ?></strong>
						<?php esc_html_e( 'Plugin updates will not overwrite your changes.', 'agent-builder' ); ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-skills&skill_action=reset&skill_id=' . $agentic_sk_edit_id ), 'agentic_skill_reset_' . $agentic_sk_edit_id ) ); ?>" data-agentic-confirm="<?php echo esc_attr( __( 'Discard your edits and restore the shipped version of this skill?', 'agent-builder' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Reset', 'agent-builder' ) ); ?>"><?php esc_html_e( 'Reset to shipped version', 'agent-builder' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'This is a bundled skill. Editing it will fork a local copy — your changes will be preserved across plugin updates.', 'agent-builder' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $agentic_sk_is_advanced && ( ! empty( $agentic_sk_spec_errors ) || ! empty( $agentic_sk_unknown_tools ) ) ) : ?>
			<div class="notice notice-warning agentic-notice-mt-0">
				<p><strong><?php esc_html_e( 'Spec check — not blocking, but fixing these keeps the skill portable to other agent tools:', 'agent-builder' ); ?></strong></p>
				<ul class="agentic-list-disc-pl20">
					<?php foreach ( $agentic_sk_spec_errors as $agentic_sk_spec_err ) : ?>
						<li><?php echo esc_html( $agentic_sk_spec_err ); ?></li>
					<?php endforeach; ?>
					<?php if ( ! empty( $agentic_sk_unknown_tools ) ) : ?>
						<li>
							<?php
							printf(
								/* translators: %s: comma-separated tool names */
								esc_html__( 'allowed-tools references unknown tool(s): %s', 'agent-builder' ),
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
					<th scope="row"><label for="agentic_sk_name"><?php esc_html_e( 'Name', 'agent-builder' ); ?></label></th>
					<td>
						<input type="text" id="agentic_sk_name" name="agentic_sk_name" value="<?php echo esc_attr( $agentic_sk_name ); ?>" class="regular-text" required />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="agentic_sk_description"><?php esc_html_e( 'Description', 'agent-builder' ); ?></label></th>
					<td>
						<input type="text" id="agentic_sk_description" name="agentic_sk_description" value="<?php echo esc_attr( $agentic_sk_desc ); ?>" class="large-text" />
						<p class="description"><?php esc_html_e( 'Brief summary of what this skill teaches the agent to do.', 'agent-builder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="agentic_sk_content"><?php esc_html_e( 'SKILL.md Content', 'agent-builder' ); ?></label></th>
					<td>
						<textarea id="agentic_sk_content" name="agentic_sk_content" rows="16" class="large-text code agentic-textarea-mono"><?php echo esc_textarea( $agentic_sk_content ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Markdown instructions that get injected into the agent\'s system prompt. Teaches the agent when and how to use specific tools.', 'agent-builder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Assign to Agents', 'agent-builder' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Assign to Agents', 'agent-builder' ); ?></legend>
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
						<p class="description"><?php esc_html_e( 'Leave every box unchecked to make this skill available to all agents, or check one or more to limit it.', 'agent-builder' ); ?></p>
					</td>
				</tr>
				<?php if ( $agentic_sk_is_advanced ) : ?>
				<tr>
					<th scope="row"><label for="agentic_sk_author"><?php esc_html_e( 'Author', 'agent-builder' ); ?> <small>(<?php esc_html_e( 'optional', 'agent-builder' ); ?>)</small></label></th>
					<td>
						<input type="text" id="agentic_sk_author" name="agentic_sk_author" value="<?php echo esc_attr( $agentic_sk_author ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="agentic_sk_version"><?php esc_html_e( 'Version', 'agent-builder' ); ?></label></th>
					<td>
						<input type="text" id="agentic_sk_version" name="agentic_sk_version" value="<?php echo esc_attr( $agentic_sk_version ); ?>" class="small-text" />
					</td>
				</tr>
				<?php else : ?>
				<input type="hidden" name="agentic_sk_author" value="<?php echo esc_attr( $agentic_sk_author ); ?>" />
				<input type="hidden" name="agentic_sk_version" value="<?php echo esc_attr( $agentic_sk_version ); ?>" />
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'agent-builder' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="agentic_sk_enabled" value="1" <?php checked( $agentic_sk_enabled ); ?> />
							<?php esc_html_e( 'Active — inject this skill into agent system prompts', 'agent-builder' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php if ( $agentic_sk_is_advanced ) : ?>
			<details class="agentic-skill-preview">
				<summary><?php esc_html_e( 'Preview what this skill sends to the agent', 'agent-builder' ); ?></summary>
				<div class="agentic-mb-12-text-dim">
					<p>
						<?php esc_html_e( 'Always in context (progressive-disclosure "metadata" tier — every enabled skill\'s index line):', 'agent-builder' ); ?>
					</p>
					<code class="agentic-code-block-sm"><?php echo esc_html( $agentic_sk_preview_line ); ?></code>
					<p class="agentic-mt-12">
						<?php esc_html_e( 'Loaded only when the agent calls load_skill for this skill (the full body below):', 'agent-builder' ); ?>
						<?php
						printf(
							/* translators: 1: line count, 2: estimated token count */
							esc_html__( '%1$d lines, ~%2$d tokens.', 'agent-builder' ),
							(int) $agentic_sk_body_lines,
							(int) $agentic_sk_body_est_tok
						);
						?>
						<?php if ( $agentic_sk_body_lines > 500 ) : ?>
							<strong class="agentic-text-warning"><?php esc_html_e( 'Over the spec\'s ~500-line guidance — consider moving reference material out.', 'agent-builder' ); ?></strong>
						<?php endif; ?>
					</p>
				</div>
			</details>
			<?php endif; ?>

			<?php if ( 'clawhub' === $agentic_sk_source ) : ?>
				<p class="agentic-text-dim-xs">
					<?php esc_html_e( 'This skill was imported from ClawHub. Local edits will be preserved unless you re-import.', 'agent-builder' ); ?>
				</p>
			<?php endif; ?>

			<p class="submit">
				<input type="submit" class="button-primary" value="<?php echo $agentic_sk_edit_id ? esc_attr__( 'Update Skill', 'agent-builder' ) : esc_attr__( 'Create Skill', 'agent-builder' ); ?>" />
			</p>
		</form>
	</div>

<?php elseif ( 'hub' === $agentic_sk_view ) : ?>

	<?php
	// WordPress and Anthropic are browsed directly from their official GitHub
	// repos (small, human-reviewed catalogs — no search API needed, just list
	// everything). ClawHub is a large open-publish registry, kept behind its
	// existing search-box flow. Basic mode only ever sees WordPress.org.
	$agentic_sk_hub_sources = array(
		'wordpress' => array(
			'label'  => __( 'WordPress.org', 'agent-builder' ),
			'type'   => 'github',
			'owner'  => 'WordPress',
			'repo'   => 'agent-skills',
			'branch' => 'trunk',
		),
	);
	if ( $agentic_sk_is_advanced ) {
		$agentic_sk_hub_sources['anthropic'] = array(
			'label'  => __( 'Anthropic', 'agent-builder' ),
			'type'   => 'github',
			'owner'  => 'anthropics',
			'repo'   => 'skills',
			'branch' => 'main',
		);
		$agentic_sk_hub_sources['clawhub'] = array(
			'label' => __( 'OpenClaw / ClawHub', 'agent-builder' ),
			'type'  => 'clawhub',
		);
	}
	?>

	<div class="agentic-max-800">
		<h2 class="agentic-h2-flex">
			<?php esc_html_e( 'Browse Community Skills', 'agent-builder' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills' ) ); ?>" class="button agentic-ml-auto">&larr; <?php esc_html_e( 'Back to Skills', 'agent-builder' ); ?></a>
		</h2>

		<?php if ( $agentic_sk_is_advanced ) : ?>
		<div id="agentic-hub-source-switcher" class="agentic-hub-source-switcher" role="tablist" aria-label="<?php esc_attr_e( 'Skill source', 'agent-builder' ); ?>">
			<?php foreach ( $agentic_sk_hub_sources as $agentic_sk_src_key => $agentic_sk_src_cfg ) : ?>
				<button type="button" class="agentic-hub-source-tab<?php echo 'wordpress' === $agentic_sk_src_key ? ' is-active' : ''; ?>" data-source="<?php echo esc_attr( $agentic_sk_src_key ); ?>" role="tab"><?php echo esc_html( $agentic_sk_src_cfg['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php else : ?>
		<p class="agentic-mb-12-text-dim"><?php esc_html_e( 'Browsing skills from the official WordPress.org repository.', 'agent-builder' ); ?></p>
		<?php endif; ?>

		<div id="agentic-hub-search-wrap" class="agentic-flex-gap-8-mb-20" style="display:none" hidden>
			<input type="text" id="agentic-hub-search" class="regular-text agentic-flex-1" placeholder="<?php esc_attr_e( 'Search skills...', 'agent-builder' ); ?>" />
			<button type="button" id="agentic-hub-search-btn" class="button button-primary"><?php esc_html_e( 'Search', 'agent-builder' ); ?></button>
		</div>

		<div id="agentic-hub-results" class="agentic-hub-results">
			<p class="agentic-text-dim"><?php esc_html_e( 'Loading...', 'agent-builder' ); ?></p>
		</div>

		<!-- Hidden import form -->
		<form method="post" id="agentic-hub-import-form" style="display:none;">
			<?php wp_nonce_field( 'agentic_hub_import', 'agentic_hub_nonce' ); ?>
			<input type="hidden" name="agentic_hub_data" id="agentic-hub-data" value="" />
		</form>
	</div>

	<script>
	(function() {
		'use strict';

		var SOURCES = <?php echo wp_json_encode( array_map( static fn( $s ) => array_intersect_key( $s, array_flip( array( 'label', 'type', 'owner', 'repo', 'branch' ) ) ), $agentic_sk_hub_sources ) ); ?>;

		var switcherEl      = document.getElementById('agentic-hub-source-switcher');
		var searchWrap       = document.getElementById('agentic-hub-search-wrap');
		var searchInput      = document.getElementById('agentic-hub-search');
		var searchBtn        = document.getElementById('agentic-hub-search-btn');
		var resultsDiv       = document.getElementById('agentic-hub-results');
		var importForm       = document.getElementById('agentic-hub-import-form');
		var importDataField  = document.getElementById('agentic-hub-data');
		var CLAWHUB_API      = 'https://wry-manatee-359.convex.site/api/v1';
		var cache            = {};

		function escHtml(str) {
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}

		function escAttr(str) {
			return String(str).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		}

		// Minimal client-side reader for the two YAML frontmatter fields we
		// need, mirroring Skills_Registry::parse_front_matter_identity().
		// Handles both a plain single-line description and YAML's block
		// scalar form ("description: |" / "description: |-" / "description: >"
		// followed by indented lines) — real skills in the wild use both;
		// Anthropic's own claude-api skill is block-scalar.
		function parseFrontMatter(raw) {
			var out = { name: '', description: '' };
			var m = raw.match(/^---\s*\n([\s\S]*?)\n---\s*\n/);
			if (!m) return out;
			var fm = m[1];
			var nm = fm.match(/^name:\s*(.+)$/m);
			if (nm) out.name = nm[1].trim().replace(/^["']|["']$/g, '');

			var block = fm.match(/^description:\s*[|>][-+]?[ \t]*\n((?:[ \t]+.*\n?)+)/m);
			if (block) {
				out.description = block[1].split('\n')
					.filter(function (l) { return l.trim() !== ''; })
					.map(function (l) { return l.trim(); })
					.join(' ');
			} else {
				var dm = fm.match(/^description:\s*["']?(.*?)["']?\s*$/m);
				if (dm) out.description = dm[1].trim();
			}
			return out;
		}

		function renderResults(items, emptyMessage) {
			if (!items.length) {
				resultsDiv.innerHTML = '<p class="agentic-text-dim">' + escHtml(emptyMessage) + '</p>';
				return;
			}
			var html = '<table class="widefat striped"><thead><tr>' +
				'<th><?php echo esc_js( __( 'Name', 'agent-builder' ) ); ?></th>' +
				'<th><?php echo esc_js( __( 'Description', 'agent-builder' ) ); ?></th>' +
				'<th class="agentic-col-min-90"><?php echo esc_js( __( 'Action', 'agent-builder' ) ); ?></th>' +
				'</tr></thead><tbody>';
			items.forEach(function(item) {
				html += '<tr>';
				html += '<td><strong>' + escHtml(item.name) + '</strong><br><code class="agentic-code-xs">' + escHtml(item.slug || '') + '</code></td>';
				html += '<td>' + escHtml(item.description || '') + '</td>';
				html += '<td><button type="button" class="button agentic-hub-import-btn" data-skill=\'' + escAttr(JSON.stringify(item)) + '\'><?php echo esc_js( __( 'Import', 'agent-builder' ) ); ?></button></td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			resultsDiv.innerHTML = html;
			wireImportButtons();
		}

		function wireImportButtons() {
			resultsDiv.querySelectorAll('.agentic-hub-import-btn').forEach(function(btn) {
				btn.addEventListener('click', async function() {
					if (!await agenticUI.confirm('<?php echo esc_js( __( 'Import this skill?', 'agent-builder' ) ); ?>', { confirmText: '<?php echo esc_js( __( 'Import', 'agent-builder' ) ); ?>' })) return;
					var thisBtn = this;
					var skillData = JSON.parse(thisBtn.dataset.skill);
					thisBtn.disabled = true;
					thisBtn.textContent = '<?php echo esc_js( __( 'Importing...', 'agent-builder' ) ); ?>';

					if ('clawhub' === skillData.source) {
						finishClawhubImport(skillData);
					} else {
						submitImport(skillData);
					}
				});
			});
		}

		function submitImport(skillData) {
			importDataField.value = JSON.stringify(skillData);
			importForm.submit();
		}

		// ClawHub search results only carry summary metadata — fetch full
		// detail + the actual SKILL.md body (from the openclaw/skills GitHub
		// repo ClawHub indexes) only once the user commits to importing.
		function finishClawhubImport(skillData) {
			var slug = skillData.slug;
			fetch(CLAWHUB_API + '/skills/' + encodeURIComponent(slug))
			.then(function(r) { return r.ok ? r.json() : null; })
			.then(function(detail) {
				if (detail && detail.skill) {
					skillData.description = detail.skill.summary || skillData.description;
					if (detail.latestVersion) {
						skillData.version = detail.latestVersion.version || skillData.version;
					}
					if (detail.owner) {
						skillData.author = detail.owner.displayName || detail.owner.handle || '';
					}
				}
				var ownerHandle = (detail && detail.owner) ? (detail.owner.handle || '') : '';
				var canonicalSlug = (detail && detail.skill) ? (detail.skill.slug || slug) : slug;
				if (ownerHandle) {
					<?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Browser-side text fetch (a Markdown doc, not a script/asset) for the explicit, user-initiated "import a community skill" action. ?>
					return fetch('https://raw.githubusercontent.com/openclaw/skills/main/skills/' + encodeURIComponent(ownerHandle) + '/' + encodeURIComponent(canonicalSlug) + '/SKILL.md')
					.then(function(r) { return r.ok ? r.text() : ''; })
					.then(function(md) { skillData.content = md || ''; })
					.catch(function() {});
				}
			})
			.then(function() { submitImport(skillData); })
			.catch(function() { submitImport(skillData); }); // Import with search data only.
		}

		function doClawhubSearch() {
			var query = searchInput.value.trim();
			if (!query) return;

			resultsDiv.innerHTML = '<p class="agentic-text-dim"><?php echo esc_js( __( 'Searching...', 'agent-builder' ) ); ?></p>';
			searchBtn.disabled = true;

			fetch(CLAWHUB_API + '/search?q=' + encodeURIComponent(query), {
				method: 'GET',
				headers: { 'Accept': 'application/json' },
			})
			.then(function(r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function(data) {
				searchBtn.disabled = false;
				var skills = data.results || data.items || [];
				var items = skills.map(function(skill) {
					return {
						name: skill.displayName || skill.slug || 'Untitled',
						description: skill.summary || '',
						slug: skill.slug || '',
						version: skill.version || '1.0.0',
						source: 'clawhub',
					};
				});
				renderResults(items, '<?php echo esc_js( __( 'No skills found. Try a different search term.', 'agent-builder' ) ); ?>');
			})
			.catch(function(err) {
				searchBtn.disabled = false;
				resultsDiv.innerHTML = '<div class="notice notice-error"><p><?php echo esc_js( __( 'Failed to connect to ClawHub. Please try again.', 'agent-builder' ) ); ?> (' + escHtml(err.message || '') + ')</p></div>';
			});
		}

		function loadGithubSource(key, src) {
			if (cache[key]) {
				renderResults(cache[key], '<?php echo esc_js( __( 'No skills found in this repository.', 'agent-builder' ) ); ?>');
				return;
			}
			resultsDiv.innerHTML = '<p class="agentic-text-dim"><?php echo esc_js( __( 'Loading...', 'agent-builder' ) ); ?></p>';

			fetch('https://api.github.com/repos/' + src.owner + '/' + src.repo + '/git/trees/' + src.branch + '?recursive=1', {
				headers: { 'Accept': 'application/vnd.github+json' },
			})
			.then(function(r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function(data) {
				var slugs = (data.tree || [])
					.map(function(e) { return e.path || ''; })
					.filter(function(p) { return /^skills\/[^\/]+\/SKILL\.md$/.test(p); })
					.map(function(p) { return p.split('/')[1]; });

				return Promise.all(slugs.map(function(slug) {
					<?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Browser-side text fetch (a Markdown doc, not a script/asset) for the explicit, user-initiated "browse community skills" action. ?>
					var rawUrl = 'https://raw.githubusercontent.com/' + src.owner + '/' + src.repo + '/' + src.branch + '/skills/' + slug + '/SKILL.md';
					return fetch(rawUrl)
						.then(function(r) { return r.ok ? r.text() : ''; })
						.then(function(raw) {
							if (!raw) return null;
							var fm = parseFrontMatter(raw);
							return {
								slug: slug,
								name: fm.name || slug,
								description: fm.description || '',
								content: raw,
								version: '1.0.0',
								author: src.label,
								source: key,
							};
						});
				}));
			})
			.then(function(items) {
				items = items.filter(Boolean);
				items.sort(function(a, b) { return a.name.localeCompare(b.name); });
				cache[key] = items;
				renderResults(items, '<?php echo esc_js( __( 'No skills found in this repository.', 'agent-builder' ) ); ?>');
			})
			.catch(function(err) {
				resultsDiv.innerHTML = '<div class="notice notice-error"><p>' +
					'<?php echo esc_js( __( 'Failed to load skills from', 'agent-builder' ) ); ?> ' + escHtml(src.label) + '. (' + escHtml(err.message || '') + ')</p></div>';
			});
		}

		function switchSource(key) {
			var src = SOURCES[key];
			if (!src) return;
			if (switcherEl) {
				switcherEl.querySelectorAll('[data-source]').forEach(function(b) {
					b.classList.toggle('is-active', b.dataset.source === key);
				});
			}
			if ('github' === src.type) {
				// .hidden alone isn't enough here — agentic-flex-gap-8-mb-20
				// sets display:flex in an author stylesheet, which beats the
				// browser's default [hidden]{display:none} UA rule regardless
				// of the hidden attribute/property. Toggle display directly.
				searchWrap.hidden = true;
				searchWrap.style.display = 'none';
				loadGithubSource(key, src);
			} else {
				searchWrap.hidden = false;
				searchWrap.style.display = '';
				resultsDiv.innerHTML = '<p class="agentic-text-dim"><?php echo esc_js( __( 'Enter a search term above to find skills on ClawHub.', 'agent-builder' ) ); ?></p>';
			}
		}

		if (switcherEl) {
			switcherEl.querySelectorAll('[data-source]').forEach(function(btn) {
				btn.addEventListener('click', function() { switchSource(this.dataset.source); });
			});
		}

		searchBtn.addEventListener('click', doClawhubSearch);
		searchInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') { e.preventDefault(); doClawhubSearch(); }
		});

		switchSource('wordpress');
	})();
	</script>

<?php else : ?>

	<div class="agentic-max-900">
		<h2 class="agentic-h2-flex">
			<?php esc_html_e( 'Skills', 'agent-builder' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=new' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Create Skill', 'agent-builder' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=hub' ) ); ?>" class="button"><?php esc_html_e( 'Browse Community', 'agent-builder' ); ?></a>
		</h2>
		<p><?php esc_html_e( 'Skills contain SKILL.md instructions that teach agents when and how to use specific tools. They are injected into the system prompt at runtime.', 'agent-builder' ); ?></p>

		<!-- Recommended Skills from agentic-plugin.com -->
		<div id="agentic-recommended-skills" class="agentic-recommended-grid">
			<h3 class="agentic-h3-mb-8"><?php esc_html_e( 'Recommended Skills', 'agent-builder' ); ?></h3>
			<p class="agentic-mb-12-text-dim"><?php esc_html_e( 'Curated skills verified to work with Agent Builder.', 'agent-builder' ); ?></p>
			<div id="agentic-recommended-grid" class="agentic-min-h-60">
				<p class="agentic-text-dim"><?php esc_html_e( 'Loading...', 'agent-builder' ); ?></p>
			</div>
		</div>

		<!-- Hidden import form for recommended skills -->
		<form method="post" id="agentic-rec-import-form" style="display:none;">
			<?php wp_nonce_field( 'agentic_hub_import', 'agentic_hub_nonce' ); ?>
			<input type="hidden" name="agentic_hub_data" id="agentic-rec-data" value="" />
		</form>

		<script>
		(function() {
			'use strict';
			var SKILLS_API = 'https://agentic-plugin.com/wp-json/agentic/v1/skills';
			var grid = document.getElementById('agentic-recommended-grid');
			var importForm = document.getElementById('agentic-rec-import-form');
			var importField = document.getElementById('agentic-rec-data');

			fetch(SKILLS_API + '/featured')
			.then(function(r) { return r.ok ? r.json() : []; })
			.then(function(skills) {
				if (!skills.length) {
					// Fallback: try search endpoint
					return fetch(SKILLS_API + '?per_page=12')
					.then(function(r) { return r.ok ? r.json() : { skills: [] }; })
					.then(function(d) { return d.skills || []; });
				}
				return skills;
			})
			.then(function(skills) {
				if (!skills || !skills.length) {
					grid.innerHTML = '<p class="agentic-text-dim"><?php echo esc_js( __( 'No recommended skills available yet. Create your own or browse the community.', 'agent-builder' ) ); ?></p>';
					return;
				}

				var html = '<div class="agentic-skills-js-grid">';
				skills.forEach(function(skill) {
					var channels = (skill.channels || []).map(function(c) {
						return '<span class="agentic-badge-indigo-sm">' + escHtml(c) + '</span>';
					}).join(' ');

					html += '<div class="agentic-skill-card-js">';
					html += '<div class="agentic-skill-card-head">';
					html += '<strong class="agentic-skill-card-title">' + escHtml(skill.name) + '</strong>';
					if (skill.featured) html += '<span class="agentic-badge-featured">Featured</span>';
					html += '</div>';
					html += '<p class="agentic-skill-card-desc">' + escHtml(skill.description || '') + '</p>';
					html += '<div class="agentic-skill-card-tags">';
					html += channels;
					html += '<span class="agentic-skill-card-ver">v' + escHtml(skill.version || '1.0.0') + '</span>';
					html += '</div>';
					html += '<div class="agentic-skill-card-btns">';
					html += '<button type="button" class="button button-small agentic-rec-import" data-slug="' + escAttr(skill.slug) + '" data-skill=\'' + escAttr(JSON.stringify(skill)) + '\'><?php echo esc_js( __( 'Import', 'agent-builder' ) ); ?></button>';
					html += '</div>';
					html += '</div>';
				});
				html += '</div>';
				grid.innerHTML = html;

				grid.querySelectorAll('.agentic-rec-import').forEach(function(btn) {
					btn.addEventListener('click', async function() {
						if (!await agenticUI.confirm('<?php echo esc_js( __( 'Import this skill?', 'agent-builder' ) ); ?>', { confirmText: '<?php echo esc_js( __( 'Import', 'agent-builder' ) ); ?>' })) return;
						var slug = this.dataset.slug;
						var skillData = JSON.parse(this.dataset.skill);
						this.disabled = true;
						this.textContent = '<?php echo esc_js( __( 'Importing...', 'agent-builder' ) ); ?>';

						// Fetch full content from our API.
						fetch(SKILLS_API + '/' + encodeURIComponent(slug))
						.then(function(r) { return r.ok ? r.json() : null; })
						.then(function(detail) {
							var importData = {
								name: detail ? detail.name : skillData.name,
								description: detail ? detail.description : skillData.description,
								content: detail ? (detail.content || '') : '',
								version: detail ? detail.version : skillData.version,
								author: detail ? detail.author : (skillData.author || ''),
								slug: slug,
								source: 'agentic',
							};
							importField.value = JSON.stringify(importData);
							importForm.submit();
						})
						.catch(function() {
							importField.value = JSON.stringify(skillData);
							importForm.submit();
						});

						// Track the import.
						fetch(SKILLS_API + '/' + encodeURIComponent(slug) + '/import', { method: 'POST' }).catch(function(){});
					});
				});
			})
			.catch(function() {
				grid.innerHTML = '<p class="agentic-text-dim"><?php echo esc_js( __( 'Could not load recommended skills. Create your own or browse the community.', 'agent-builder' ) ); ?></p>';
			});

			function escHtml(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
			function escAttr(str) { return str.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
		})();
		</script>

		<?php if ( ! empty( $agentic_sk_all ) ) : ?>
		<h3 class="agentic-th-mt-32"><?php esc_html_e( 'Your Skills', 'agent-builder' ); ?></h3>
		<?php endif; ?>

		<?php if ( empty( $agentic_sk_all ) ) : ?>
			<h3 class="agentic-th-mt-32"><?php esc_html_e( 'Your Skills', 'agent-builder' ); ?></h3>
			<div class="notice notice-info agentic-notice-mt-0">
				<p>
					<strong><?php esc_html_e( 'No skills installed yet.', 'agent-builder' ); ?></strong>
					<?php esc_html_e( 'Import a recommended skill above, create your own, or browse the community.', 'agent-builder' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="widefat striped agentic-table-mt-16">
				<thead>
					<tr>
						<th class="agentic-col-60"><?php esc_html_e( 'Status', 'agent-builder' ); ?></th>
						<th class="agentic-col-200"><?php esc_html_e( 'Name', 'agent-builder' ); ?></th>
						<th><?php esc_html_e( 'Description', 'agent-builder' ); ?></th>
						<th class="agentic-col-140"><?php esc_html_e( 'Agent', 'agent-builder' ); ?></th>
						<?php if ( $agentic_sk_is_advanced ) : ?>
						<th class="agentic-col-80"><?php esc_html_e( 'Source', 'agent-builder' ); ?></th>
						<th class="agentic-col-80"><?php esc_html_e( 'Version', 'agent-builder' ); ?></th>
						<?php endif; ?>
						<th class="agentic-col-140"><?php esc_html_e( 'Actions', 'agent-builder' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agentic_sk_all as $agentic_sk_item ) : ?>
					<tr<?php echo '1' !== ( $agentic_sk_item['enabled'] ?? '0' ) ? ' class="agentic-opacity-60"' : ''; ?>>
						<td class="agentic-text-center">
							<?php if ( '1' === ( $agentic_sk_item['enabled'] ?? '0' ) ) : ?>
								<span class="agentic-status-dot-active" title="<?php esc_attr_e( 'Active', 'agent-builder' ); ?>"></span>
							<?php else : ?>
								<span class="agentic-status-dot-inactive" title="<?php esc_attr_e( 'Disabled', 'agent-builder' ); ?>"></span>
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
								echo '<span class="agentic-text-dim">' . esc_html__( 'All', 'agent-builder' ) . '</span>';
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
								<span class="agentic-badge-local"><?php esc_html_e( 'Core', 'agent-builder' ); ?></span>
							<?php elseif ( 'agentic' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-agentic">Agentic</span>
							<?php elseif ( 'clawhub' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-clhub">ClawHub</span>
							<?php elseif ( 'wordpress' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-wordpress">WordPress.org</span>
							<?php elseif ( 'anthropic' === $agentic_sk_src ) : ?>
								<span class="agentic-badge-anthropic">Anthropic</span>
							<?php else : ?>
								<span class="agentic-badge-local"><?php esc_html_e( 'Local', 'agent-builder' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<code class="agentic-code-sm"><?php echo esc_html( $agentic_sk_item['version'] ?? '1.0.0' ); ?></code>
						</td>
						<?php endif; ?>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-skills&skill_view=edit&skill_id=' . $agentic_sk_item['id'] ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'agent-builder' ); ?></a>
							<?php if ( $agentic_sk_is_advanced ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agentic_export_skill&skill_id=' . $agentic_sk_item['id'] ), 'agentic_export_skill' ) ); ?>" class="button button-small"><?php esc_html_e( 'Export', 'agent-builder' ); ?></a>
							<?php endif; ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=agentic-skills&skill_action=delete&skill_id=' . $agentic_sk_item['id'] ), 'agentic_skill_delete_' . $agentic_sk_item['id'] ) ); ?>" class="button button-small agentic-link-danger" data-agentic-confirm="<?php echo esc_attr( __( 'Delete this skill?', 'agent-builder' ) ); ?>" data-agentic-confirm-danger data-agentic-confirm-ok="<?php echo esc_attr( __( 'Delete', 'agent-builder' ) ); ?>"><?php esc_html_e( 'Delete', 'agent-builder' ); ?></a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div class="agentic-about-skills-box">
			<h3 class="agentic-about-skills-h3"><?php esc_html_e( 'About Skills', 'agent-builder' ); ?></h3>
			<ul class="agentic-list-disc-pl20">
				<li><?php esc_html_e( 'Skills contain SKILL.md instructions — markdown that gets injected into the agent\'s system prompt.', 'agent-builder' ); ?></li>
				<li><?php esc_html_e( 'They teach agents when and how to use channel-specific tools (e.g., "when a user asks to speak to a human, use the Slack handoff tool").', 'agent-builder' ); ?></li>
				<li><?php esc_html_e( 'Skills never contain API keys or direct HTTP calls — they operate above channels in the stack: agent → skill → channel → tool.', 'agent-builder' ); ?></li>
				<li><?php esc_html_e( 'Assign a skill to a specific agent, or leave it as "All" to make it available globally.', 'agent-builder' ); ?></li>
				<li><?php esc_html_e( 'Import curated skills from the Recommended section, browse community skills, or create your own.', 'agent-builder' ); ?></li>
			</ul>
		</div>
	</div>
</div><!-- /.agentic-card -->

<?php endif; ?>
