<?php
/**
 * React admin surfaces — enqueue and mount helpers.
 *
 * Central place for shipping @wordpress/scripts builds on Agent Builder
 * admin screens so pages share one pattern (asset.php + localize + root).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.3.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue compiled React admin bundles.
 */
class React_Admin {

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		// Reserved for future global admin body classes / shared CSS.
	}

	/**
	 * Enqueue a named webpack entry from build/{handle}.js + .asset.php.
	 *
	 * @param string               $handle      Script handle / entry name.
	 * @param array<string,mixed>  $localize    Data for wp_localize_script.
	 * @param string               $object_name JS global name.
	 * @return bool True when assets exist and were enqueued.
	 */
	public static function enqueue( string $handle, array $localize = array(), string $object_name = '' ): bool {
		$asset_file = AGENT_BUILDER_DIR . 'build/' . $handle . '.asset.php';
		$script_rel = 'build/' . $handle . '.js';
		$script     = AGENT_BUILDER_DIR . $script_rel;

		if ( ! file_exists( $asset_file ) || ! file_exists( $script ) ) {
			return false;
		}

		$asset = require $asset_file;
		$deps  = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = $asset['version'] ?? AGENT_BUILDER_VERSION;

		wp_enqueue_script(
			'agentic-' . $handle,
			AGENT_BUILDER_URL . $script_rel,
			$deps,
			$ver,
			true
		);
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'agentic-react-admin',
			AGENT_BUILDER_URL . 'assets/css/react-admin.css',
			array( 'wp-components' ),
			AGENT_BUILDER_VERSION
		);

		wp_set_script_translations(
			'agentic-' . $handle,
			'siteloom',
			AGENT_BUILDER_DIR . 'languages'
		);

		if ( '' !== $object_name && ! empty( $localize ) ) {
			wp_localize_script( 'agentic-' . $handle, $object_name, $localize );
		}

		return true;
	}

	/**
	 * Print a React mount root.
	 *
	 * @param string $id    Element id.
	 * @param string $class Optional class.
	 */
	public static function mount( string $id, string $class = '' ): void {
		printf(
			'<div id="%s" class="%s"></div>',
			esc_attr( $id ),
			esc_attr( trim( 'agentic-react-root ' . $class ) )
		);
	}

	/**
	 * Missing-build notice for admins.
	 *
	 * @param string $handle Entry name.
	 */
	public static function missing_build_notice( string $handle ): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: webpack entry name */
				__( 'React admin assets for “%s” are missing. Run npm install && npm run build in the plugin directory.', 'siteloom' ),
				$handle
			)
		);
		echo '</p></div>';
	}
}
