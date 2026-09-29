<?php
/**
 * Routines_Payload builder for admin page REST payload.
 *
 * Static page chrome only — the routines list itself is fetched client-side by
 * RoutinesView.js via GET /agentic/v1/routines, matching how the page shell
 * and the data it renders stay separate.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Routines_Payload {

	public static function build(): array {
		return array(
			'page'        => 'routines',
			'title'       => __( 'Routines', 'agent-builder' ),
			'panel_title' => __( 'Routines', 'agent-builder' ),
			'description' => __( 'Scheduled tasks and event listeners that run your agents automatically. Pause, resume, test, or delete them here.', 'agent-builder' ),
			'docs_url'    => 'https://agentic-plugin.com/documentation/routines/',
		);
	}
}
