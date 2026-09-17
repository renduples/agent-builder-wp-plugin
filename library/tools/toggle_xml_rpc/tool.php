<?php
/**
 * Tool: toggle_xml_rpc
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agentic\Tool_Base;

class Toggle_Xml_Rpc extends Tool_Base {
	public function get_name(): string {
		return 'toggle_xml_rpc';
	}

	public function get_description(): string {
		return 'Enable or disable the WordPress XML-RPC endpoint. XML-RPC is a legacy API sometimes exploited for brute-force attacks; disabling it is recommended for most sites.';
	}

	public function get_category(): string {
		return 'maintenance';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'enabled' => array(
					'type'        => 'boolean',
					'description' => 'True to enable XML-RPC, false to disable it.',
				),
			),
			'required'   => array( 'enabled' ),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => false,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		if ( ! isset( $args['enabled'] ) ) {
			return array( 'error' => 'enabled (boolean) is required.' );
		}

		$enabled = (bool) $args['enabled'];

		// Store: 1 = disabled, 0 = enabled (inverted for legacy reasons).
		update_option( 'agent_builder_disable_xmlrpc', $enabled ? 0 : 1 );

		return array(
			'xmlrpc_enabled' => $enabled,
			'enforced_by'    => 'xmlrpc_enabled filter in Agent Builder (active on every request)',
		);
	}
}

return new Toggle_Xml_Rpc();
