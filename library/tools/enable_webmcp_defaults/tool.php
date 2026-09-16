<?php
/**
 * Tool: enable_webmcp_defaults
 *
 * Free fix for the Agent-Ready Score's webmcp_tools_registered check.
 *
 * Deliberately NOT "expose every readonly, NONE/LOW-risk tool" — that risk
 * taxonomy was designed for the trusted wp-admin chat context (Risk_Level::LOW
 * is explicitly documented as "read operations that MAY expose personal
 * information," which is a completely different threat model than "safe to
 * hand to any anonymous visitor on the public internet"). An earlier version
 * of this tool did exactly that and, on a real test site, exposed things like
 * get_security_overview (failed logins, admin count), list_privileged_users
 * (admin usernames), get_recent_registrations, check_plugin_updates (version
 * fingerprinting), and get_form_entries (can contain PII) — to anyone, and
 * exposed tools belonging to purely admin-facing agents (user-assistant,
 * wordpress-assistant, assistant-trainer) that have no business being
 * visitor-facing at all.
 *
 * Instead this exposes only from Webmcp_Bridge::ANONYMOUS_SAFE_TOOLS, the
 * same single source of truth that class-webmcp-bridge.php's own
 * permission_execute() independently enforces as a fail-closed backstop —
 * so even if a site owner manually sets webmcp_expose:true on something
 * else via the Advanced tab's per-tool matrix (a deliberate, one-at-a-time
 * choice, unlike this automatic sweep), an anonymous visitor still can't
 * reach it.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      3.3.90
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets webmcp_expose:true on a small, curated allowlist of tool names only.
 */
class Enable_Webmcp_Defaults extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'enable_webmcp_defaults';
	}

	public function get_description(): string {
		return 'Expose a small, curated set of genuinely public-safe, readonly tools (search_content, and the WooCommerce browsing/cart-reading tools where WooCommerce is active) to the WebMCP frontend surface. Never overwrites a tool the site owner already explicitly opted out (webmcp_expose:false), and never expands this list to arbitrary readonly/low-risk tools.';
	}

	public function get_category(): string {
		return 'diagnostics';
	}

	public function get_risk_level(): string {
		return 'low';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	public function execute( array $arguments ): array {
		$slugs = class_exists( '\\Agentic_Agent_Registry' )
			? array_keys( \Agentic_Agent_Registry::get_instance()->get_all_instances() )
			: array();

		$updated_agents = array();
		$exposed_tools  = array();
		$skipped        = array();

		foreach ( $slugs as $slug ) {
			$manifest = \Agentic\Abilities_Manifest::load( $slug );
			if ( ! $manifest || empty( $manifest['abilities'] ) || ! is_array( $manifest['abilities'] ) ) {
				continue;
			}

			$path = \Agentic\Abilities_Manifest::resolve_path( $slug );
			if ( ! $path || ! wp_is_writable( $path ) ) {
				$skipped[] = $slug;
				continue;
			}

			$changed = false;
			foreach ( $manifest['abilities'] as $tool_name => &$entry ) {
				if ( ! in_array( $tool_name, \Agentic\Webmcp_Bridge::ANONYMOUS_SAFE_TOOLS, true ) ) {
					continue;
				}
				if ( array_key_exists( 'webmcp_expose', $entry ) ) {
					continue; // Site owner already made an explicit choice either way.
				}

				$tool = \Agentic\Tool_Loader::get_instance()->get( (string) $tool_name );
				if ( ! $tool ) {
					continue;
				}

				$readonly = (bool) ( $tool->get_annotations()['readonly'] ?? false );
				$risk     = \Agentic\Abilities_Manifest::get_effective_risk( $slug, (string) $tool_name, $tool );

				if ( ! $readonly || ! in_array( $risk, array( \Agentic\Risk_Level::NONE, \Agentic\Risk_Level::LOW ), true ) ) {
					continue;
				}

				$entry['webmcp_expose']  = true;
				$entry['webmcp_context'] = 'frontend';
				$changed                 = true;
				$exposed_tools[]         = "{$slug}:{$tool_name}";
			}
			unset( $entry );

			if ( ! $changed ) {
				continue;
			}

			$written = \Agentic\File_Manager::put_contents(
				$path,
				wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			);
			if ( false === $written ) {
				$skipped[] = $slug;
				continue;
			}

			\Agentic\Abilities_Manifest::clear_cache( $slug );
			\Agentic\Abilities_Manifest::save_integrity_hash( $slug );
			$updated_agents[] = $slug;
		}

		return $this->success(
			array(
				'updated_agents' => $updated_agents,
				'exposed_tools'  => $exposed_tools,
				'skipped_agents' => $skipped,
			)
		);
	}

	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}
}

return new Enable_Webmcp_Defaults();
