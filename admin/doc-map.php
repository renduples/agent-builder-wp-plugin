<?php
/**
 * Admin page → documentation URL map.
 *
 * Returned as an array with two keys:
 *   'tabs'  — keyed by 'page:tab' or 'page:section', checked first.
 *   'pages' — keyed by page slug, used as fallback.
 *
 * To add or change a mapping, edit this file only — no need to touch agentstructor.php.
 *
 * @package Agent_Builder
 * @since   2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	// -------------------------------------------------------------------------
	// Page level (page slug => url)
	// Used when no tab match is found.
	// -------------------------------------------------------------------------
	'pages' => array(
		'agent-builder'        => 'https://agentstructor.com/documentation/dashboard/',
		'agentic-chat'         => 'https://agentstructor.com/documentation/chat/',
		'agentic-agents'       => 'https://agentstructor.com/documentation/agents/',
		'agentic-deployment'   => 'https://agentstructor.com/documentation/publish/',
		'agentic-tools'        => 'https://agentstructor.com/documentation/tools/',
		'agentic-skills'       => 'https://agentstructor.com/documentation/skills/',
		'agentic-integrations' => 'https://agentstructor.com/channels/',
		'agentic-audit-log'    => 'https://agentstructor.com/documentation/activity/',
		'agentic-approvals'      => 'https://agentstructor.com/documentation/approvals/',
		'agentic-safety-center'  => 'https://agentstructor.com/documentation/safety-center/',
		'agentic-settings'       => 'https://agentstructor.com/documentation/settings/',
		'agentic-train-data'   => 'https://agentstructor.com/documentation/knowledge/',
		'agentic-run-task'     => 'https://agentstructor.com/scheduled-tasks/',
		'agentic-agent-ready'  => 'https://agentstructor.com/documentation/passport/',
		'agentic-agent-wizard' => 'https://agentstructor.com/documentation/agent-wizard/',
		// Pro admin surfaces.
		'agentic-costs'        => 'https://agentstructor.com/api-credits/',
		'agentic-connectors'   => 'https://agentstructor.com/channels/',
		'agentic-governance'   => 'https://agentstructor.com/permissions-and-safety/',
		'agentic-workflows'    => 'https://agentstructor.com/event-automation/',
		'agentic-health'       => 'https://agentstructor.com/troubleshooting/',
		'agentic-cloudflare'   => 'https://agentstructor.com/important-security-settings/',
		'agentic-wp-ai'        => 'https://agentstructor.com/capabilities/',
	),

	// -------------------------------------------------------------------------
	// Tab / section level  (page:tab => url)
	// Checked before the page-level fallback.
	// -------------------------------------------------------------------------
	'tabs'  => array(

		// Settings tabs (current IA).
		'agentic-settings:interface'          => 'https://agentstructor.com/documentation/settings/',
		'agentic-settings:agents'             => 'https://agentstructor.com/installed-agents/',
		'agentic-settings:providers'          => 'https://agentstructor.com/manage-llm-providers/',
		'agentic-settings:users'              => 'https://agentstructor.com/user-roles-and-privileges/',
		'agentic-settings:security'           => 'https://agentstructor.com/important-security-settings/',
		'agentic-settings:apis'               => 'https://agentstructor.com/connecting-an-ai-provider/',
		'agentic-settings:endpoints'          => 'https://agentstructor.com/connecting-an-ai-provider/',
		// Legacy settings tabs (redirected in UI; keep docs for bookmarks).
		'agentic-settings:general'            => 'https://agentstructor.com/chat-styles-and-themes/',
		'agentic-settings:global'             => 'https://agentstructor.com/global-chat-settings/',
		'agentic-settings:instructions'       => 'https://agentstructor.com/agent-instructions/',
		'agentic-settings:memory'             => 'https://agentstructor.com/agent-memory/',
		'agentic-settings:health'             => 'https://agentstructor.com/troubleshooting/',
		'agentic-settings:styles'             => 'https://agentstructor.com/chat-styles-and-themes/',
		'agentic-settings:mcp'                => 'https://agentstructor.com/mcp-integration/',

		// Knowledge page tabs.
		'agentic-train-data:wiki'             => 'https://agentstructor.com/knowledge-wiki-okf/',
		'agentic-train-data:instructions'     => 'https://agentstructor.com/agent-instructions/',
		'agentic-train-data:memory'           => 'https://agentstructor.com/agent-memory/',
		'agentic-train-data:vector'           => 'https://agentstructor.com/ai-data-training/',

		// Deployment tabs.
		'agentic-deployment:admin-ui'         => 'https://agentstructor.com/admin-sidebar-deployment/',
		'agentic-deployment:shortcodes'       => 'https://agentstructor.com/agent-deployment/',
		'agentic-deployment:gutenberg-blocks' => 'https://agentstructor.com/gutenberg-blocks/',
		'agentic-deployment:website'          => 'https://agentstructor.com/frontend-chat-security/',
		'agentic-deployment:admin-bar'        => 'https://agentstructor.com/admin-bar-deployment/',
		'agentic-deployment:scheduled-tasks'  => 'https://agentstructor.com/scheduled-tasks/',
		'agentic-deployment:event-listeners'  => 'https://agentstructor.com/event-automation/',

		// Tools sections.
		'agentic-tools:tools'                 => 'https://agentstructor.com/agent-tools/',
		'agentic-tools:all'                   => 'https://agentstructor.com/agent-tools/',
		'agentic-tools:custom'                => 'https://agentstructor.com/agent-tools/',
		'agentic-tools:abilities'             => 'https://agentstructor.com/capabilities/',

		// Integrations sections.
		'agentic-integrations:channels'       => 'https://agentstructor.com/channels/',
		'agentic-integrations:mcp'            => 'https://agentstructor.com/mcp-integration/',

		// Logs tabs.
		'agentic-audit-log:audit'             => 'https://agentstructor.com/audit-log/',
		'agentic-audit-log:conversations'     => 'https://agentstructor.com/audit-log/',
		'agentic-audit-log:security'          => 'https://agentstructor.com/important-security-settings/',

		// Approvals tabs.
		'agentic-approvals:approvals'         => 'https://agentstructor.com/approval-queue/',
		'agentic-approvals:backups'           => 'https://agentstructor.com/backups/',
	),
);
