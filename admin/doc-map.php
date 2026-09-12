<?php
/**
 * Admin page → documentation URL map.
 *
 * Returned as an array with two keys:
 *   'tabs'  — keyed by 'page:tab' or 'page:section', checked first.
 *   'pages' — keyed by page slug, used as fallback.
 *
 * To add or change a mapping, edit this file only — no need to touch agent-builder.php.
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
		'agent-builder'        => 'https://agentic-plugin.com/the-dashboard/',
		'agentic-chat'         => 'https://agentic-plugin.com/chat-interface/',
		'agentic-agents'       => 'https://agentic-plugin.com/installed-agents/',
		'agentic-deployment'   => 'https://agentic-plugin.com/agent-deployment/',
		'agentic-tools'        => 'https://agentic-plugin.com/agent-tools/',
		'agentic-skills'       => 'https://agentic-plugin.com/skills/',
		'agentic-integrations' => 'https://agentic-plugin.com/channels/',
		'agentic-audit-log'    => 'https://agentic-plugin.com/audit-log/',
		'agentic-approvals'      => 'https://agentic-plugin.com/approval-queue/',
		'agentic-safety-center'  => 'https://agentic-plugin.com/permissions-and-safety/',
		'agentic-costs'          => 'https://agentic-plugin.com/api-credits/',
		'agentic-settings'       => 'https://agentic-plugin.com/settings/',
		'agentic-train-data'   => 'https://agentic-plugin.com/knowledge-wiki-okf/',
		'agentic-run-task'     => 'https://agentic-plugin.com/scheduled-tasks/',
		'agentic-upgrade-pro'  => 'https://agentic-plugin.com/licensing-and-pricing/',
		// Pro admin surfaces.
		'agentic-connectors'   => 'https://agentic-plugin.com/channels/',
		'agentic-governance'   => 'https://agentic-plugin.com/permissions-and-safety/',
		'agentic-marketplace'  => 'https://agentic-plugin.com/community-agents/',
		'agentic-workflows'    => 'https://agentic-plugin.com/event-automation/',
		'agentic-health'       => 'https://agentic-plugin.com/troubleshooting/',
		'agentic-cloudflare'   => 'https://agentic-plugin.com/important-security-settings/',
		'agentic-wp-ai'        => 'https://agentic-plugin.com/capabilities/',
	),

	// -------------------------------------------------------------------------
	// Tab / section level  (page:tab => url)
	// Checked before the page-level fallback.
	// -------------------------------------------------------------------------
	'tabs'  => array(

		// Settings tabs (current IA).
		'agentic-settings:interface'          => 'https://agentic-plugin.com/chat-styles-and-themes/',
		'agentic-settings:agents'             => 'https://agentic-plugin.com/installed-agents/',
		'agentic-settings:providers'          => 'https://agentic-plugin.com/manage-llm-providers/',
		'agentic-settings:users'              => 'https://agentic-plugin.com/user-roles-and-privileges/',
		'agentic-settings:security'           => 'https://agentic-plugin.com/important-security-settings/',
		'agentic-settings:apis'               => 'https://agentic-plugin.com/connecting-an-ai-provider/',
		'agentic-settings:endpoints'          => 'https://agentic-plugin.com/connecting-an-ai-provider/',
		// Legacy settings tabs (redirected in UI; keep docs for bookmarks).
		'agentic-settings:general'            => 'https://agentic-plugin.com/chat-styles-and-themes/',
		'agentic-settings:global'             => 'https://agentic-plugin.com/global-chat-settings/',
		'agentic-settings:instructions'       => 'https://agentic-plugin.com/agent-instructions/',
		'agentic-settings:memory'             => 'https://agentic-plugin.com/agent-memory/',
		'agentic-settings:health'             => 'https://agentic-plugin.com/troubleshooting/',
		'agentic-settings:styles'             => 'https://agentic-plugin.com/chat-styles-and-themes/',
		'agentic-settings:mcp'                => 'https://agentic-plugin.com/mcp-integration/',

		// Knowledge page tabs.
		'agentic-train-data:wiki'             => 'https://agentic-plugin.com/knowledge-wiki-okf/',
		'agentic-train-data:instructions'     => 'https://agentic-plugin.com/agent-instructions/',
		'agentic-train-data:memory'           => 'https://agentic-plugin.com/agent-memory/',
		'agentic-train-data:vector'           => 'https://agentic-plugin.com/ai-data-training/',

		// Deployment tabs.
		'agentic-deployment:admin-ui'         => 'https://agentic-plugin.com/admin-sidebar-deployment/',
		'agentic-deployment:shortcodes'       => 'https://agentic-plugin.com/agent-deployment/',
		'agentic-deployment:gutenberg-blocks' => 'https://agentic-plugin.com/gutenberg-blocks/',
		'agentic-deployment:website'          => 'https://agentic-plugin.com/frontend-chat-security/',
		'agentic-deployment:admin-bar'        => 'https://agentic-plugin.com/admin-bar-deployment/',
		'agentic-deployment:scheduled-tasks'  => 'https://agentic-plugin.com/scheduled-tasks/',
		'agentic-deployment:event-listeners'  => 'https://agentic-plugin.com/event-automation/',

		// Tools sections.
		'agentic-tools:tools'                 => 'https://agentic-plugin.com/agent-tools/',
		'agentic-tools:all'                   => 'https://agentic-plugin.com/agent-tools/',
		'agentic-tools:custom'                => 'https://agentic-plugin.com/agent-tools/',
		'agentic-tools:abilities'             => 'https://agentic-plugin.com/capabilities/',

		// Integrations sections.
		'agentic-integrations:channels'       => 'https://agentic-plugin.com/channels/',
		'agentic-integrations:mcp'            => 'https://agentic-plugin.com/mcp-integration/',

		// Logs tabs.
		'agentic-audit-log:audit'             => 'https://agentic-plugin.com/audit-log/',
		'agentic-audit-log:conversations'     => 'https://agentic-plugin.com/audit-log/',
		'agentic-audit-log:security'          => 'https://agentic-plugin.com/important-security-settings/',

		// Approvals tabs.
		'agentic-approvals:approvals'         => 'https://agentic-plugin.com/approval-queue/',
		'agentic-approvals:backups'           => 'https://agentic-plugin.com/backups/',
	),
);
