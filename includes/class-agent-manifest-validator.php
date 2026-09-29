<?php
/**
 * Agent Manifest Validator — sanitizes and validates declarative agent.json data.
 *
 * Runtime-created agents are stored as plain-data manifests (never executable
 * PHP). This validator is the trust boundary: it accepts a raw manifest array
 * (typically assembled from LLM-supplied fields) and returns a strictly
 * sanitized manifest, or a WP_Error. Every field is coerced to a safe scalar
 * or list of scalars — there is no field through which code can be injected,
 * and the resulting JSON is only ever read as data by Manifest_Agent.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      2.10.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and sanitizes agent manifests.
 */
final class Agent_Manifest_Validator {

	/**
	 * Manifest schema version written into every manifest.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Allowed agent categories.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CATEGORIES = array(
		'content',
		'admin',
		'ecommerce',
		'frontend',
		'developer',
		'seo',
		'security',
		'media',
		'support',
	);

	/**
	 * Maximum lengths for free-text fields (defence-in-depth against bloat).
	 */
	private const MAX_NAME          = 100;
	private const MAX_DESCRIPTION   = 500;
	private const MAX_PROMPT        = 200;
	private const MAX_PROMPTS       = 6;
	private const MAX_CAPS          = 20;
	private const MAX_ICON          = 16;
	private const MAX_SYSTEM_PROMPT = 20000;
	private const MAX_WELCOME       = 2000;
	private const MAX_URI           = 300;

	/**
	 * Automation limits.
	 *
	 * scheduled_tasks / event_listeners let a declarative agent react to WP-Cron
	 * schedules and action hooks with no chat present. They are honoured for any
	 * agent, but every action they trigger is still gated by the same tool
	 * permission + risk enforcement as a chat turn (Tool_Executor): a disabled or
	 * extreme tool never runs, a high-risk tool is queued for admin approval, etc.
	 * These caps bound how much automation a single manifest can register.
	 */
	private const MAX_TASKS             = 20;
	private const MAX_LISTENERS         = 20;
	private const MAX_AUTOMATION_PROMPT = 2000;
	private const MAX_ARG_MAP           = 10;
	private const MAX_ARG_FILTER_LEN    = 500;

	/**
	 * WP-Cron recurrences a task may declare.
	 *
	 * @var string[]
	 */
	private const ALLOWED_SCHEDULES = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	/**
	 * Validate and sanitize a raw manifest.
	 *
	 * @param array<string, mixed> $raw Raw manifest fields.
	 * @return array<string, mixed>|\WP_Error Sanitized manifest, or error.
	 */
	public static function validate( array $raw ) {
		$slug = sanitize_key( (string) ( $raw['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) ( $raw['name'] ?? '' ) );
		}
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'A valid agent slug or name is required.', 'agent-builder' ) );
		}

		$name = self::clean_text( (string) ( $raw['name'] ?? '' ), self::MAX_NAME );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_name', __( 'Agent name is required.', 'agent-builder' ) );
		}

		$description = self::clean_text( (string) ( $raw['description'] ?? '' ), self::MAX_DESCRIPTION );
		if ( '' === $description ) {
			return new \WP_Error( 'invalid_description', __( 'Agent description is required.', 'agent-builder' ) );
		}

		$category = strtolower( sanitize_key( (string) ( $raw['category'] ?? 'admin' ) ) );
		if ( ! in_array( $category, self::ALLOWED_CATEGORIES, true ) ) {
			$category = 'admin';
		}

		$manifest = array(
			'schema'            => self::SCHEMA_VERSION,
			'slug'              => $slug,
			'name'              => $name,
			'description'       => $description,
			'category'          => $category,
			'icon'              => self::clean_icon( (string) ( $raw['icon'] ?? '🤖' ) ),
			'version'           => self::clean_version( (string) ( $raw['version'] ?? '1.0.0' ) ),
			'author'            => self::clean_text( (string) ( $raw['author'] ?? '' ), self::MAX_NAME ),
			'capabilities'      => self::clean_capabilities( $raw['capabilities'] ?? array() ),
			'tools'             => self::clean_tool_names( $raw['tools'] ?? array() ),
			'suggested_prompts' => self::clean_prompts( $raw['suggested_prompts'] ?? array() ),
			'team'              => ! empty( $raw['team'] ),
		);

		if ( isset( $raw['welcome_message'] ) ) {
			// Welcome messages are multi-line markdown — keep newlines (unlike
			// sanitize_text_field) so a converted agent's greeting survives intact.
			$welcome = sanitize_textarea_field( (string) $raw['welcome_message'] );
			if ( strlen( $welcome ) > self::MAX_WELCOME ) {
				$welcome = substr( $welcome, 0, self::MAX_WELCOME );
			}
			$welcome = trim( $welcome );
			if ( '' !== $welcome ) {
				$manifest['welcome_message'] = $welcome;
			}
		}

		if ( isset( $raw['author_uri'] ) ) {
			$uri = esc_url_raw( trim( (string) $raw['author_uri'] ) );
			if ( '' !== $uri ) {
				$manifest['author_uri'] = substr( $uri, 0, self::MAX_URI );
			}
		}

		// Preserve an inline system prompt so a manifest can fully describe an
		// agent without a filesystem template. Newlines are kept (unlike
		// sanitize_text_field) since prompts are multi-line. This is what lets a
		// database-backed agent (no directory on disk) carry its own behaviour.
		if ( isset( $raw['system_prompt'] ) ) {
			$prompt = sanitize_textarea_field( (string) $raw['system_prompt'] );
			if ( strlen( $prompt ) > self::MAX_SYSTEM_PROMPT ) {
				$prompt = substr( $prompt, 0, self::MAX_SYSTEM_PROMPT );
			}
			$prompt = trim( $prompt );
			if ( '' !== $prompt ) {
				$manifest['system_prompt'] = $prompt;
			}
		}

		$scheduled_tasks = self::clean_scheduled_tasks( $raw['scheduled_tasks'] ?? array() );
		if ( ! empty( $scheduled_tasks ) ) {
			$manifest['scheduled_tasks'] = $scheduled_tasks;
		}

		$event_listeners = self::clean_event_listeners( $raw['event_listeners'] ?? array() );
		if ( ! empty( $event_listeners ) ) {
			$manifest['event_listeners'] = $event_listeners;
		}

		return $manifest;
	}

	/**
	 * Sanitize the scheduled-tasks list.
	 *
	 * Each task fires on a WP-Cron recurrence. It must name what to run — either
	 * a `prompt` (autonomous LLM run) or a `tool` (direct, reviewed-tool call).
	 * A manifest can NEVER name a PHP `callback`: that is a bundled-PHP-agent-only
	 * mechanism, so any `callback` field is dropped here. Tasks lacking both a
	 * prompt and a tool are discarded.
	 *
	 * @param mixed $tasks Raw scheduled_tasks input.
	 * @return array<int, array<string, mixed>>
	 */
	private static function clean_scheduled_tasks( $tasks ): array {
		if ( ! is_array( $tasks ) ) {
			return array();
		}

		$clean = array();
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $task['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}

			$schedule = strtolower( sanitize_key( (string) ( $task['schedule'] ?? 'daily' ) ) );
			if ( ! in_array( $schedule, self::ALLOWED_SCHEDULES, true ) ) {
				$schedule = 'daily';
			}

			$clean_name = self::clean_text( (string) ( $task['name'] ?? $id ), self::MAX_NAME );

			$entry = array(
				'id'          => $id,
				'name'        => '' !== $clean_name ? $clean_name : $id,
				'schedule'    => $schedule,
				'description' => self::clean_text( (string) ( $task['description'] ?? '' ), self::MAX_DESCRIPTION ),
			);

			$entry = self::apply_automation_action( $entry, $task );
			if ( null === $entry ) {
				continue; // Neither prompt nor tool — nothing to run.
			}

			$clean[ $id ] = $entry; // Key by id so duplicates collapse.
			if ( count( $clean ) >= self::MAX_TASKS ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Sanitize the event-listeners list.
	 *
	 * Each listener binds a WordPress action hook. Like scheduled tasks it must
	 * declare a `prompt` or a `tool` (never a PHP `callback`), and is discarded
	 * without one or without a hook name.
	 *
	 * @param mixed $listeners Raw event_listeners input.
	 * @return array<int, array<string, mixed>>
	 */
	private static function clean_event_listeners( $listeners ): array {
		if ( ! is_array( $listeners ) ) {
			return array();
		}

		$clean = array();
		foreach ( $listeners as $listener ) {
			if ( ! is_array( $listener ) ) {
				continue;
			}

			$id   = sanitize_key( (string) ( $listener['id'] ?? '' ) );
			$hook = self::clean_hook_name( (string) ( $listener['hook'] ?? '' ) );
			if ( '' === $id || '' === $hook ) {
				continue;
			}

			$priority      = (int) ( $listener['priority'] ?? 10 );
			$accepted_args = (int) ( $listener['accepted_args'] ?? 1 );

			$clean_name = self::clean_text( (string) ( $listener['name'] ?? $id ), self::MAX_NAME );

			$entry = array(
				'id'            => $id,
				'name'          => '' !== $clean_name ? $clean_name : $id,
				'hook'          => $hook,
				'priority'      => max( 1, min( 9999, $priority ) ),
				'accepted_args' => max( 0, min( 10, $accepted_args ) ),
				'description'   => self::clean_text( (string) ( $listener['description'] ?? '' ), self::MAX_DESCRIPTION ),
			);

			$entry = self::apply_automation_action( $entry, $listener );
			if ( null === $entry ) {
				continue;
			}

			// Rate limit: at most one execution per listener per `min_interval`
			// seconds (0 disables; negative coerced to the default of 60).
			$min_interval = (int) ( $listener['min_interval'] ?? 60 );
			if ( $min_interval < 0 ) {
				$min_interval = 60;
			}
			$entry['min_interval'] = min( $min_interval, 86400 );

			// Argument filter: run the listener only when hook argument `arg`
			// matches `pattern` (PCRE) or is listed in `in` (exact allowlist).
			// Malformed filters are dropped, so a typo can't silently disable the
			// listener — it falls back to every event.
			$arg_filter = self::clean_arg_filter( $listener['arg_filter'] ?? null );
			if ( null !== $arg_filter ) {
				$entry['arg_filter'] = $arg_filter;
			}

			$clean[ $id ] = $entry;
			if ( count( $clean ) >= self::MAX_LISTENERS ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Sanitize a listener's `arg_filter` — a positional hook argument plus either
	 * a PCRE `pattern` or an exact-match `in` allowlist.
	 *
	 * Exactly one of `pattern` / `in` must be present: a filter with neither, or
	 * with both, is dropped. A `pattern` that fails to compile or exceeds the
	 * length cap is dropped; an `in` list that is not an array of strings is
	 * dropped. Dropping the filter makes the listener run on every event, never
	 * silently on none.
	 *
	 * @param mixed $filter Raw arg_filter input.
	 * @return array{arg:int, pattern:string}|array{arg:int, in:string[]}|null Sanitized filter, or null when absent/invalid.
	 */
	private static function clean_arg_filter( $filter ): ?array {
		if ( ! is_array( $filter ) ) {
			return null;
		}

		$arg = max( 0, min( 10, (int) ( $filter['arg'] ?? 0 ) ) );

		$has_pattern = array_key_exists( 'pattern', $filter );
		$has_in      = array_key_exists( 'in', $filter );

		// Exactly one of the two forms may be present.
		if ( $has_pattern === $has_in ) {
			return null;
		}

		if ( $has_in ) {
			$in = $filter['in'];
			if ( ! is_array( $in ) ) {
				return null;
			}
			$allowlist = array();
			foreach ( $in as $item ) {
				if ( ! is_string( $item ) ) {
					return null;
				}
				$allowlist[] = substr( $item, 0, 500 );
			}
			return array(
				'arg' => $arg,
				'in'  => $allowlist,
			);
		}

		$pattern = trim( (string) ( $filter['pattern'] ?? '' ) );
		if ( '' === $pattern ) {
			return null;
		}

		// Enforce the length cap before compiling. An over-length pattern is
		// rejected (dropping the filter, so the listener falls back to running on
		// every event) rather than silently truncated: a pattern that already
		// compiled could be truncated into a different, invalid one whose runtime
		// @preg_match failure would be misread as "no match", silently disabling
		// the listener instead of running it.
		if ( strlen( $pattern ) > self::MAX_ARG_FILTER_LEN ) {
			return null;
		}

		// Drop patterns that don't compile — the listener then runs on every
		// event rather than being silently broken by an invalid regex.
		if ( false === @preg_match( '/' . $pattern . '/', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A pattern that fails to compile is "drop it", never a fatal.
			return null;
		}

		return array(
			'arg'     => $arg,
			'pattern' => $pattern,
		);
	}

	/**
	 * Attach the sanitized action fields (prompt and/or tool + arg map) to an
	 * automation entry, or return null if the entry names no runnable action.
	 *
	 * `prompt` routes through the LLM autonomously; `tool` invokes a single
	 * reviewed tool directly (no LLM). At least one must be present. A `callback`
	 * is never accepted from a manifest.
	 *
	 * @param array<string, mixed> $entry Partially built entry (id/name/etc.).
	 * @param array<string, mixed> $raw   Raw automation entry.
	 * @return array<string, mixed>|null
	 */
	private static function apply_automation_action( array $entry, array $raw ): ?array {
		$prompt = '';
		if ( isset( $raw['prompt'] ) ) {
			$prompt = trim( sanitize_textarea_field( (string) $raw['prompt'] ) );
			if ( strlen( $prompt ) > self::MAX_AUTOMATION_PROMPT ) {
				$prompt = substr( $prompt, 0, self::MAX_AUTOMATION_PROMPT );
			}
		}

		$tool = sanitize_key( (string) ( $raw['tool'] ?? '' ) );

		if ( '' === $prompt && '' === $tool ) {
			return null;
		}

		if ( '' !== $prompt ) {
			$entry['prompt'] = $prompt;
		}
		if ( '' !== $tool ) {
			$entry['tool'] = $tool;

			// Optional positional-hook-arg → named-tool-arg map. Values are the
			// tool parameter names to assign, in hook-argument order.
			$args = array();
			if ( isset( $raw['args'] ) && is_array( $raw['args'] ) ) {
				foreach ( $raw['args'] as $name ) {
					$name = sanitize_key( (string) $name );
					if ( '' !== $name ) {
						$args[] = $name;
					}
					if ( count( $args ) >= self::MAX_ARG_MAP ) {
						break;
					}
				}
			}
			if ( ! empty( $args ) ) {
				$entry['args'] = $args;
			}
		}

		return $entry;
	}

	/**
	 * Sanitize a WordPress hook name.
	 *
	 * Hook tags are restricted to the characters WordPress core and plugins use
	 * for action names, so a manifest cannot smuggle anything but a plain tag.
	 *
	 * @param string $hook Raw hook name.
	 * @return string Clean hook name, or '' if nothing valid remains.
	 */
	private static function clean_hook_name( string $hook ): string {
		$hook = preg_replace( '/[^a-zA-Z0-9_\/\-]/', '', trim( $hook ) ) ?? '';
		return substr( $hook, 0, 100 );
	}

	/**
	 * Read and validate a manifest from a JSON file.
	 *
	 * @param string $file Absolute path to agent.json.
	 * @return array<string, mixed>|null Sanitized manifest, or null on failure.
	 */
	public static function from_file( string $file ): ?array {
		$contents = File_Manager::get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			return null;
		}
		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$result = self::validate( $decoded );
		return is_wp_error( $result ) ? null : $result;
	}

	/**
	 * Collapse whitespace and strip tags/control chars from a free-text field.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private static function clean_text( string $value, int $max ): string {
		$value = sanitize_text_field( $value );
		if ( strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}
		return trim( $value );
	}

	/**
	 * Keep only a short, safe icon string (emoji or dashicons-* slug).
	 *
	 * @param string $icon Raw icon.
	 * @return string
	 */
	private static function clean_icon( string $icon ): string {
		$icon = trim( wp_strip_all_tags( $icon ) );
		if ( strlen( $icon ) > self::MAX_ICON ) {
			$icon = substr( $icon, 0, self::MAX_ICON );
		}
		return '' !== $icon ? $icon : '🤖';
	}

	/**
	 * Normalize a semver-ish version string.
	 *
	 * @param string $version Raw version.
	 * @return string
	 */
	private static function clean_version( string $version ): string {
		$version = preg_replace( '/[^0-9.]/', '', $version ) ?? '';
		return '' !== $version ? $version : '1.0.0';
	}

	/**
	 * Sanitize capabilities to a list of capability slugs.
	 *
	 * @param mixed $caps Raw capabilities (array or comma list).
	 * @return string[]
	 */
	private static function clean_capabilities( $caps ): array {
		if ( is_string( $caps ) ) {
			$caps = explode( ',', $caps );
		}
		if ( ! is_array( $caps ) ) {
			return array( 'read' );
		}
		$clean = array();
		foreach ( $caps as $cap ) {
			$cap = sanitize_key( (string) $cap );
			if ( '' !== $cap ) {
				$clean[] = $cap;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( empty( $clean ) ) {
			return array( 'read' );
		}
		return array_slice( $clean, 0, self::MAX_CAPS );
	}

	/**
	 * Extract a clean list of tool-name slugs from the tools input.
	 *
	 * Accepts either a list of strings or a list of {name: ...} objects.
	 *
	 * @param mixed $tools Raw tools input.
	 * @return string[]
	 */
	private static function clean_tool_names( $tools ): array {
		if ( ! is_array( $tools ) ) {
			return array();
		}
		$names = array();
		foreach ( $tools as $tool ) {
			if ( is_array( $tool ) ) {
				$name = (string) ( $tool['name'] ?? '' );
			} else {
				$name = (string) $tool;
			}
			$name = sanitize_key( $name );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Sanitize the suggested-prompts list.
	 *
	 * @param mixed $prompts Raw prompts.
	 * @return string[]
	 */
	private static function clean_prompts( $prompts ): array {
		if ( ! is_array( $prompts ) ) {
			return array();
		}
		$clean = array();
		foreach ( $prompts as $prompt ) {
			$prompt = self::clean_text( (string) $prompt, self::MAX_PROMPT );
			if ( '' !== $prompt ) {
				$clean[] = $prompt;
			}
		}
		return array_slice( $clean, 0, self::MAX_PROMPTS );
	}
}
