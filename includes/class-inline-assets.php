<?php
/**
 * Inline Assets
 *
 * One place for the small page-level scripts and styles that used to be
 * printed as raw <script>/<style> tags from PHP templates. Everything goes
 * through the WordPress script/style queue instead:
 *
 *  - Page behaviour lives in a static file under assets/js/ and is enqueued
 *    with wp_enqueue_script() (footer, versioned by filemtime).
 *  - Per-request data (nonces, URLs, translated strings, ids) is passed as a
 *    JSON data object via wp_add_inline_script( ..., 'before' ). The JSON is
 *    encoded with JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
 *    so no value can close the surrounding script element.
 *  - Page CSS goes through wp_add_inline_style() on an enqueued handle.
 *
 * Calling these from inside a page render callback (after admin_enqueue_scripts
 * has fired) is fine: footer scripts and late styles are still printed by
 * WordPress' footer script/style printing.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.4.1
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue helpers for page scripts, JSON data objects and inline styles.
 */
class Inline_Assets {

	/**
	 * Flags used for every JSON blob handed to the browser.
	 */
	public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

	/**
	 * Handle of the empty style used to attach inline CSS when no other
	 * plugin stylesheet is enqueued on the page.
	 */
	public const STYLE_HANDLE = 'agentic-inline';

	/**
	 * Encode data for a script context. Safe to place inside a <script>
	 * element: <, >, &, ' and " are emitted as \u00XX escapes.
	 *
	 * @param mixed $data Data to encode.
	 * @return string JSON ('null' if the data cannot be encoded).
	 */
	public static function json( $data ): string {
		$json = wp_json_encode( $data, self::JSON_FLAGS );
		return false === $json ? 'null' : $json;
	}

	/**
	 * Enqueue a page script that lives in the plugin's assets folder.
	 *
	 * @param string $name        Script name; resolves to {$dir}{$name}.js and
	 *                            handle "agentic-page-{$name}".
	 * @param array  $data        Optional data object for the script.
	 * @param string $object_name Global variable the data is assigned to
	 *                            (window.{$object_name}). Required when $data
	 *                            is non-empty.
	 * @param array  $deps        Script dependencies.
	 * @param string $dir         Directory relative to the plugin root.
	 * @return string The script handle.
	 */
	public static function script( string $name, array $data = array(), string $object_name = '', array $deps = array(), string $dir = 'assets/js/admin/' ): string {
		$name     = sanitize_key( $name );
		$handle   = 'agentic-page-' . $name;
		$relative = $dir . $name . '.js';
		$path     = AGENT_BUILDER_DIR . $relative;
		$version  = file_exists( $path ) ? (string) filemtime( $path ) : AGENT_BUILDER_VERSION;

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, AGENT_BUILDER_URL . $relative, $deps, $version, true );
		}
		if ( '' !== $object_name && ! empty( $data ) ) {
			self::data( $handle, $object_name, $data );
		}
		wp_enqueue_script( $handle );

		return $handle;
	}

	/**
	 * Attach a JSON data object to an enqueued/registered script.
	 *
	 * The object is assigned to window[ $object_name ] before the script runs.
	 * Data only: no executable code is ever passed through here.
	 *
	 * @param string $handle      Script handle.
	 * @param string $object_name Global variable name (JS identifier).
	 * @param array  $data        Data to expose.
	 * @return void
	 */
	public static function data( string $handle, string $object_name, array $data ): void {
		$object_name = preg_replace( '/[^A-Za-z0-9_$]/', '', $object_name );
		if ( '' === $object_name ) {
			return;
		}
		wp_add_inline_script(
			$handle,
			'window.' . $object_name . ' = ' . self::json( $data ) . ';',
			'before'
		);
	}

	/**
	 * Add page CSS through the style queue.
	 *
	 * The CSS must be plugin-authored or built only from validated values.
	 * As a last line of defence any "<" is stripped so the string can never
	 * close the surrounding <style> element.
	 *
	 * @param string $css    CSS rules.
	 * @param string $handle Style handle to attach to. Defaults to an empty
	 *                       plugin handle that is registered on demand.
	 * @return void
	 */
	public static function style( string $css, string $handle = self::STYLE_HANDLE ): void {
		$css = self::clean_css( $css );
		if ( '' === $css ) {
			return;
		}
		if ( self::STYLE_HANDLE === $handle && ! wp_style_is( $handle, 'registered' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Source-less handle that only carries inline CSS.
			wp_register_style( $handle, false, array(), AGENT_BUILDER_VERSION );
		}
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Remove anything from a CSS string that could end the <style> element.
	 *
	 * @param string $css CSS rules.
	 * @return string
	 */
	public static function clean_css( string $css ): string {
		// "<" never appears in valid CSS we emit; removing it makes "</style"
		// impossible. ">" stays: it is the child combinator.
		return trim( str_replace( '<', '', $css ) );
	}
}
