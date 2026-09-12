<?php
/**
 * Chat Playground side panel (Advanced mode only).
 *
 * Read-only: model, instructions, tools, and the last plugin Chat API
 * exchange. Expects $agentic_pg from Admin_Menu_Handler.
 *
 * @package    Agent_Builder
 * @subpackage Admin/Partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agentic_pg              = isset( $agentic_pg ) && is_array( $agentic_pg ) ? $agentic_pg : array();
$agentic_pg_name         = (string) ( $agentic_pg['agent_name'] ?? '' );
$agentic_pg_id           = (string) ( $agentic_pg['agent_id'] ?? '' );
$agentic_pg_effective    = is_array( $agentic_pg['effective'] ?? null ) ? $agentic_pg['effective'] : array();
$agentic_pg_provider     = (string) ( $agentic_pg_effective['provider_label'] ?? $agentic_pg_effective['provider'] ?? '' );
$agentic_pg_model        = (string) ( $agentic_pg_effective['model'] ?? '' );
$agentic_pg_vision       = (string) ( $agentic_pg_effective['vision_model'] ?? '' );
$agentic_pg_source       = (string) ( $agentic_pg['model_source'] ?? '' );
$agentic_pg_prompt       = (string) ( $agentic_pg['system_prompt'] ?? '' );
$agentic_pg_notes        = (string) ( $agentic_pg['persona_notes'] ?? '' );
$agentic_pg_style        = (string) ( $agentic_pg['response_style'] ?? '' );
$agentic_pg_tools        = is_array( $agentic_pg['tools'] ?? null ) ? $agentic_pg['tools'] : array();
$agentic_pg_instructions = (string) ( $agentic_pg['instructions_url'] ?? '' );

$agentic_pg_risk_class = array(
	'none'    => 'agentic-badge-green',
	'low'     => 'agentic-badge-blue',
	'medium'  => 'agentic-badge-amber',
	'high'    => 'agentic-badge-indigo',
	'extreme' => 'agentic-badge-amber',
);

$agentic_pg_style_labels = array(
	'concise'   => __( 'Concise', 'agent-builder' ),
	'detailed'  => __( 'Detailed', 'agent-builder' ),
	'technical' => __( 'Technical', 'agent-builder' ),
	'friendly'  => __( 'Friendly', 'agent-builder' ),
);
?>
<aside class="agentic-playground-panel agentic-react-panel" id="agentic-playground-panel">
	<header class="agentic-playground-panel__header">
		<h2 class="agentic-react-panel__title"><?php esc_html_e( 'Playground', 'agent-builder' ); ?></h2>
		<?php if ( $agentic_pg_name ) : ?>
			<p class="agentic-playground-panel__sub">
				<?php echo esc_html( $agentic_pg_name ); ?>
				<?php if ( $agentic_pg_id ) : ?>
					<code><?php echo esc_html( $agentic_pg_id ); ?></code>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</header>

	<p class="agentic-playground-panel__lead">
		<?php esc_html_e( 'Read-only view of the model, instructions, and tools this agent actually uses. Per-request model or temperature overrides are not wired in the chat backend — changing them here would not change the run.', 'agent-builder' ); ?>
	</p>

	<section class="agentic-playground-section">
		<h3><?php esc_html_e( 'Model', 'agent-builder' ); ?></h3>
		<dl class="agentic-playground-dl">
			<div>
				<dt><?php esc_html_e( 'Provider', 'agent-builder' ); ?></dt>
				<dd><?php echo esc_html( '' !== $agentic_pg_provider ? $agentic_pg_provider : '—' ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Model', 'agent-builder' ); ?></dt>
				<dd><code><?php echo esc_html( '' !== $agentic_pg_model ? $agentic_pg_model : '—' ); ?></code></dd>
			</div>
			<?php if ( $agentic_pg_vision && $agentic_pg_vision !== $agentic_pg_model ) : ?>
			<div>
				<dt><?php esc_html_e( 'Vision model', 'agent-builder' ); ?></dt>
				<dd><code><?php echo esc_html( $agentic_pg_vision ); ?></code></dd>
			</div>
			<?php endif; ?>
			<div>
				<dt><?php esc_html_e( 'Source', 'agent-builder' ); ?></dt>
				<dd><?php echo esc_html( $agentic_pg_source ); ?></dd>
			</div>
		</dl>
	</section>

	<section class="agentic-playground-section">
		<h3><?php esc_html_e( 'Instructions', 'agent-builder' ); ?></h3>
		<p class="description">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: URL to Knowledge → Instructions. */
					__( 'Persona notes from <a href="%s">Knowledge → Instructions</a>. Appended to the system prompt; they do not replace it.', 'agent-builder' ),
					esc_url( $agentic_pg_instructions )
				),
				array( 'a' => array( 'href' => array() ) )
			);
			?>
		</p>
		<?php if ( $agentic_pg_style && isset( $agentic_pg_style_labels[ $agentic_pg_style ] ) ) : ?>
			<p class="agentic-playground-meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: response style label. */
						__( 'Response style: %s', 'agent-builder' ),
						$agentic_pg_style_labels[ $agentic_pg_style ]
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( '' !== trim( $agentic_pg_notes ) ) : ?>
			<pre class="agentic-playground-pre" tabindex="0"><?php echo esc_html( $agentic_pg_notes ); ?></pre>
		<?php else : ?>
			<p class="agentic-playground-empty"><?php esc_html_e( 'No persona notes saved for this agent.', 'agent-builder' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="agentic-playground-section">
		<h3><?php esc_html_e( 'System prompt', 'agent-builder' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'The agent’s base prompt. At send time, knowledge, skills, site context, and the notes above are assembled around it.', 'agent-builder' ); ?>
		</p>
		<?php if ( '' !== trim( $agentic_pg_prompt ) ) : ?>
			<details class="agentic-playground-details">
				<summary>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: character count. */
							__( 'Preview (%s characters)', 'agent-builder' ),
							number_format_i18n( strlen( $agentic_pg_prompt ) )
						)
					);
					?>
				</summary>
				<pre class="agentic-playground-pre" tabindex="0"><?php echo esc_html( $agentic_pg_prompt ); ?></pre>
			</details>
		<?php else : ?>
			<p class="agentic-playground-empty"><?php esc_html_e( 'This agent has no base system prompt on file.', 'agent-builder' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="agentic-playground-section">
		<h3><?php esc_html_e( 'Tool access', 'agent-builder' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Tools this agent may call, from the same inventory used by the Safety Center. Risk is the effective tier Tool_Executor enforces. After you send a message, tools actually called this turn appear in the Chat API response below.', 'agent-builder' ); ?>
		</p>
		<?php if ( ! empty( $agentic_pg_tools ) ) : ?>
			<ul class="agentic-playground-tools">
				<?php foreach ( $agentic_pg_tools as $agentic_pg_tool ) : ?>
					<?php
					$agentic_pg_tool_name = (string) ( $agentic_pg_tool['name'] ?? '' );
					$agentic_pg_tool_risk = (string) ( $agentic_pg_tool['risk'] ?? '' );
					$agentic_pg_badge     = $agentic_pg_risk_class[ $agentic_pg_tool_risk ] ?? 'agentic-badge-pill-grey';
					if ( '' === $agentic_pg_tool_name ) {
						continue;
					}
					?>
					<li>
						<code><?php echo esc_html( $agentic_pg_tool_name ); ?></code>
						<?php if ( $agentic_pg_tool_risk ) : ?>
							<span class="agentic-badge <?php echo esc_attr( $agentic_pg_badge ); ?>"><?php echo esc_html( $agentic_pg_tool_risk ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="agentic-playground-empty"><?php esc_html_e( 'No tools declared for this agent.', 'agent-builder' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="agentic-playground-section" id="agentic-playground-inspector">
		<h3><?php esc_html_e( 'Last Chat API exchange', 'agent-builder' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Real JSON for POST agentic/v1/chat — the plugin REST request and the stream’s end event (or JSON body). The raw LLM provider payload is assembled server-side and is not returned by this endpoint.', 'agent-builder' ); ?>
		</p>
		<h4><?php esc_html_e( 'Request', 'agent-builder' ); ?></h4>
		<pre class="agentic-playground-pre agentic-playground-pre--json" id="agentic-playground-request" tabindex="0"><?php esc_html_e( 'Send a message to capture the request.', 'agent-builder' ); ?></pre>
		<h4><?php esc_html_e( 'Response', 'agent-builder' ); ?></h4>
		<pre class="agentic-playground-pre agentic-playground-pre--json" id="agentic-playground-response" tabindex="0"><?php esc_html_e( 'Waiting for a reply…', 'agent-builder' ); ?></pre>
	</section>
</aside>
