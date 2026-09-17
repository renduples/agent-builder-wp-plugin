<?php
/**
 * Tool Base Class
 *
 * Abstract base for all standalone tools. Each tool lives in its own directory
 * under tools/<tool-name>/ and extends this class.
 *
 * @package    Agent_Builder
 * @subpackage Tools
 * @since      2.0.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for standalone tools.
 *
 * Tools are first-class entities that exist independently of agents.
 * Agents declare which tools they use; tools don't know about agents.
 */
abstract class Tool_Base {

	/**
	 * Slug of the agent currently executing a tool.
	 *
	 * Set by Agent_Controller immediately before tool execution so that
	 * tools that need agent-context (e.g. run_wp_cli privilege check) can
	 * read it via get_calling_agent_slug() without polluting execute() signatures.
	 *
	 * @var string
	 */
	private static string $calling_agent = '';

	/**
	 * Set the calling agent slug for the current tool execution.
	 *
	 * Called by Agent_Controller before each tool call.
	 *
	 * @param string $slug Agent ID / slug.
	 * @return void
	 */
	public static function set_calling_agent( string $slug ): void {
		self::$calling_agent = $slug;
	}

	/**
	 * Get the slug of the agent that triggered the current tool execution.
	 *
	 * @return string Agent slug, or empty string if called outside agent context.
	 */
	protected function get_calling_agent_slug(): string {
		return self::$calling_agent;
	}

	/**
	 * Get the tool's unique name (slug).
	 *
	 * Must match the directory name under tools/.
	 * Convention: snake_case (e.g., 'analyze_internal_links').
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	/**
	 * Get a human-readable description of what this tool does.
	 *
	 * Sent to the LLM as the function description.
	 *
	 * @return string
	 */
	abstract public function get_description(): string;

	/**
	 * Get the tool's category for admin display.
	 *
	 * @return string Category slug (e.g., 'seo', 'database', 'plugins', 'WordPress').
	 */
	abstract public function get_category(): string;

	/**
	 * Get the parameter schema in OpenAI function-calling format.
	 *
	 * Return an associative array with 'type', 'properties', and optionally 'required'.
	 * For tools with no parameters, return: ['type' => 'object', 'properties' => new \stdClass()]
	 *
	 * @return array Parameter schema.
	 */
	abstract public function get_parameters(): array;

	/**
	 * Execute the tool with the given arguments.
	 *
	 * @param array $arguments Validated arguments from the LLM.
	 * @return array Result data to return to the LLM.
	 */
	abstract public function execute( array $arguments ): array;

	/**
	 * Whether this tool can actually run on this installation.
	 *
	 * Tools that depend on an optional PHP library or server extension override
	 * this so the tool is hidden from agents entirely rather than being offered
	 * and then failing at call time. An agent that cannot see a tool will not
	 * promise the user a capability the site does not have.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Why this tool is unavailable, for admin-facing surfaces.
	 *
	 * Only meaningful when is_available() returns false.
	 *
	 * @return string
	 */
	public function get_unavailable_reason(): string {
		return '';
	}

	/**
	 * Whether an optional library class can be loaded.
	 *
	 * class_exists() runs the autoloader, and a half-installed dependency can
	 * make that autoload raise a fatal Error (for example mPDF's Mpdf class
	 * pulls in a trait from FPDI). Treat any failure to load as "not
	 * available" rather than letting it take down the request.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return bool
	 */
	protected function library_available( string $class_name ): bool {
		try {
			return class_exists( $class_name );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Return a standardised success result.
	 *
	 * Use inside execute() to ensure every tool speaks the same contract:
	 *   return $this->success( ['posts' => $posts] );
	 *
	 * @param array $data Result payload.
	 * @return array{success: true, data: array}
	 */
	protected function success( array $data ): array {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Fetch a post the current caller is actually allowed to see, or null.
	 *
	 * Every tool that takes a post_id argument and returns that post's own
	 * content/data must use this instead of a bare get_post( $post_id ) —
	 * a plain get_post() has no concept of caller identity at all, so
	 * without this a get_post_content-shaped tool would hand back any
	 * draft, private, or pending post's full content to whichever
	 * authenticated user happens to guess its ID, regardless of whether
	 * they wrote it or have any capability over it. current_user_can(
	 * 'read_post', $id ) is WordPress's own meta-capability for exactly
	 * this: published/public content is visible to anyone, private
	 * content requires read_private_posts (editor+) or being the post's
	 * own author. Returns null for both "no such post" and "not visible
	 * to you" — deliberately not distinguishing the two in the caller's
	 * response, so a tool can't be used to probe whether a given ID exists.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|null
	 */
	protected function get_viewable_post( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'read_post', $post_id ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Return a standardised error result.
	 *
	 * Use inside execute() instead of returning ad-hoc ['error' => '...'] arrays:
	 *   return $this->tool_error( 'not_found', 'Post ID 123 does not exist.' );
	 *
	 * @param string $code    Machine-readable error code (snake_case).
	 * @param string $message Human-readable message surfaced to the LLM.
	 * @return array{success: false, error_code: string, message: string}
	 */
	protected function tool_error( string $code, string $message ): array {
		return array(
			'success'    => false,
			'error_code' => $code,
			'message'    => $message,
		);
	}

	/**
	 * Validate arguments against the tool's declared parameter schema.
	 *
	 * Checks required fields and basic JSON-Schema type compatibility.
	 * Called by Agent_Controller before execute() so tools never receive
	 * malformed input from a hallucinating LLM.
	 *
	 * @param array $arguments Arguments to validate.
	 * @return string|null Error message, or null on success.
	 */
	public function validate_args( array $arguments ): ?string {
		$schema     = $this->get_parameters();
		$required   = $schema['required'] ?? array();
		$properties = $schema['properties'] ?? array();

		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $arguments ) ) {
				return "Missing required parameter: {$field}";
			}
		}

		foreach ( $arguments as $key => $value ) {
			if ( ! isset( $properties[ $key ]['type'] ) ) {
				continue;
			}
			if ( ! $this->is_schema_type( $value, $properties[ $key ]['type'] ) ) {
				return "Parameter '{$key}' must be of type {$properties[ $key ]['type']}";
			}
		}

		return null;
	}

	/**
	 * Whether an array is a list (sequential integer keys starting at 0).
	 *
	 * Equivalent to PHP 8.1's array_is_list(). Reimplemented so the
	 * WordPress.org Plugin Check does not flag array_is_list() as requiring a
	 * newer WordPress version — it is a native PHP 8.1 function, which this
	 * plugin already requires.
	 *
	 * @param array $value Array to test.
	 * @return bool
	 */
	private static function is_list_array( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	/**
	 * Check whether a value matches a JSON-Schema primitive type string.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $type  JSON-Schema type name.
	 * @return bool
	 */
	private function is_schema_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string'           => is_string( $value ),
			'integer'          => is_int( $value ) || ( is_numeric( $value ) && (int) $value == $value ), // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			'number'           => is_numeric( $value ),
			'boolean'          => is_bool( $value ),
			'array'            => is_array( $value ) && self::is_list_array( $value ),
			'object'           => is_array( $value ) || is_object( $value ),
			'null'             => is_null( $value ),
			default            => true,
		};
	}

	/**
	 * Get the full OpenAI function-calling tool definition.
	 *
	 * Agents and the controller use this format. Built automatically from
	 * get_name(), get_description(), and get_parameters().
	 *
	 * @return array Tool definition in OpenAI format.
	 */
	public function get_definition(): array {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $this->get_name(),
				'description' => $this->get_description(),
				'parameters'  => $this->get_parameters(),
			),
		);
	}

	/**
	 * Get the default risk level for this tool.
	 *
	 * Reads from the wp_agent_builder_tools database table via Risk_Level.
	 * Subclasses can override.
	 * Agent abilities.json can escalate but never downgrade this value.
	 *
	 * @return string One of Risk_Level constants (none, low, medium, high, extreme).
	 */
	public function get_risk_level(): string {
		return Risk_Level::get_tool_default( $this->get_name() );
	}

	/**
	 * Whether this tool is only available when Agent Builder Pro is active.
	 *
	 * Pro-only tools are skipped by the loader on free installs and are
	 * excluded from the WordPress.org package (see .distignore). Used for
	 * capabilities that rely on server shell access (git deploy) which the
	 * WordPress.org guidelines do not permit in the free plugin.
	 *
	 * @return bool
	 */
	public function is_pro_only(): bool {
		return false;
	}

	/**
	 * Get tool annotations for the WP Abilities bridge.
	 *
	 * Override to provide capability metadata (readonly, destructive, idempotent).
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * Resolve a file path relative to the WordPress uploads directory.
	 *
	 * Prevents path traversal — the resolved path must stay inside uploads.
	 *
	 * @param string $rel Path relative to the uploads basedir.
	 * @return string|\WP_Error Absolute path on success, WP_Error on failure.
	 */
	protected function resolve_upload_path( string $rel ): string|\WP_Error {
		if ( '' === $rel ) {
			return new \WP_Error( 'missing', 'file_path is required.' );
		}
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$path    = realpath( $base . ltrim( $rel, '/\\' ) );
		if ( ! $path || ! str_starts_with( $path, $base ) || ! file_exists( $path ) ) {
			return new \WP_Error( 'not_found', "File not found: {$rel}" );
		}
		return $path;
	}

	/**
	 * Call a wp-extended ability by name.
	 *
	 * Allows tools to delegate core WordPress queries to registered abilities
	 * instead of reimplementing the same function calls. Falls back to null
	 * on WordPress < 6.9 or if the ability isn't registered.
	 *
	 * @param string     $ability_name Full ability name (e.g. 'wp-extended/get-posts').
	 * @param array|null $input        Input arguments for the ability.
	 * @return array|null Result array from the ability, or null if unavailable.
	 */
	protected function call_ability( string $ability_name, ?array $input = null ): ?array {
		if ( ! WP_Optional_API::has( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = Abilities_API::get( $ability_name );

		if ( ! $ability ) {
			return null;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array( 'result' => $result );
	}
}
