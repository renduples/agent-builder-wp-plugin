<?php
/**
 * Per-Role Usage Limits
 *
 * Stores, checks, and records daily query and token limits per WordPress role.
 * Limits are configured on the Settings → Users tab and enforced in Chat_Security
 * (pre-request) and Agent_Controller (post-completion for token accounting).
 *
 * Storage:
 *  - Option `agent_builder_usage_limits` holds the configured limits per role/anonymous.
 *  - Counters are kept in transients keyed by user_id (or IP hash) + date so they
 *    automatically expire at midnight.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.6.0
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-role daily query and token limit management.
 */
class Usage_Limits {

	/** Option key for stored limits. */
	const OPTION_KEY = 'agent_builder_usage_limits';

	/** Transient prefix for daily query counts. */
	const TRANSIENT_QUERIES = 'agentic_ul_q_';

	/** Transient prefix for daily token counts. */
	const TRANSIENT_TOKENS = 'agentic_ul_t_';

	// -------------------------------------------------------------------------
	// Limit configuration
	// -------------------------------------------------------------------------

	/**
	 * Sensible out-of-the-box limits for each built-in WordPress role.
	 * Keyed by role slug; any role not listed falls back to the anonymous tier.
	 *
	 * @var array<string, array{queries: int, tokens: int}>
	 */
	private const ROLE_DEFAULTS = array(
		'anonymous'     => array(
			'queries' => 20,
			'tokens'  => 50000,
		),
		'subscriber'    => array(
			'queries' => 50,
			'tokens'  => 100000,
		),
		'contributor'   => array(
			'queries' => 100,
			'tokens'  => 200000,
		),
		'author'        => array(
			'queries' => 100,
			'tokens'  => 200000,
		),
		'editor'        => array(
			'queries' => 200,
			'tokens'  => 500000,
		),
		'administrator' => array(
			'queries' => 0,
			'tokens'  => 0,
		),  // always unlimited.
	);

	/**
	 * Return zero-value defaults (0 = unlimited) for every known role + anonymous.
	 * Used as a merge fallback so newly registered custom roles always have an entry.
	 *
	 * @return array<string, array{queries: int, tokens: int}>
	 */
	public static function get_defaults(): array {
		$defaults = array(
			'anonymous' => array(
				'queries' => 0,
				'tokens'  => 0,
			),
		);
		foreach ( array_keys( (array) wp_roles()->roles ) as $slug ) {
			$defaults[ $slug ] = array(
				'queries' => 0,
				'tokens'  => 0,
			);
		}
		return $defaults;
	}

	/**
	 * Return sensible first-install defaults for all current roles.
	 * Known built-in roles use ROLE_DEFAULTS; custom/unknown roles default to the
	 * anonymous tier so they are never accidentally left unlimited on a live site.
	 *
	 * @return array<string, array{queries: int, tokens: int}>
	 */
	public static function get_install_defaults(): array {
		$out = array(
			'anonymous' => self::ROLE_DEFAULTS['anonymous'],
		);
		foreach ( array_keys( (array) wp_roles()->roles ) as $slug ) {
			$out[ $slug ] = self::ROLE_DEFAULTS[ $slug ] ?? self::ROLE_DEFAULTS['anonymous'];
		}
		return $out;
	}

	/**
	 * Return stored limits merged with defaults for any missing roles.
	 *
	 * @return array<string, array{queries: int, tokens: int}>
	 */
	public static function get_limits(): array {
		$saved    = get_option( self::OPTION_KEY, array() );
		$defaults = self::get_defaults();
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}
		foreach ( $defaults as $role => $def ) {
			if ( ! isset( $saved[ $role ] ) ) {
				$saved[ $role ] = $def;
			}
		}
		return $saved;
	}

	/**
	 * Validate and persist limits from a form submission.
	 * Logs any changes to the audit log so there is a permanent record of who
	 * changed which role limits and what the previous values were.
	 *
	 * @param array<string, mixed> $raw Unsanitised POST data.
	 */
	public static function save_limits( array $raw ): void {
		$valid_roles = array_merge( array( 'anonymous' ), array_keys( (array) wp_roles()->roles ) );
		$clean       = array();
		foreach ( $valid_roles as $role ) {
			$clean[ $role ] = array(
				'queries' => max( 0, (int) ( $raw[ $role ]['queries'] ?? 0 ) ),
				'tokens'  => max( 0, (int) ( $raw[ $role ]['tokens'] ?? 0 ) ),
			);
		}

		$previous = self::get_limits();
		update_option( self::OPTION_KEY, $clean );
		self::log_changes( $previous, $clean );
	}

	/**
	 * Compare old and new limit arrays and write a single audit log entry
	 * listing every role whose values changed.
	 *
	 * @param array<string, array{queries: int, tokens: int}> $old Previous limits.
	 * @param array<string, array{queries: int, tokens: int}> $new Updated limits.
	 */
	private static function log_changes( array $old, array $new ): void {
		$changed = array();
		foreach ( $new as $role => $limits ) {
			$prev_q = (int) ( $old[ $role ]['queries'] ?? 0 );
			$prev_t = (int) ( $old[ $role ]['tokens'] ?? 0 );
			if ( $prev_q !== $limits['queries'] || $prev_t !== $limits['tokens'] ) {
				$changed[ $role ] = array(
					'before' => array(
						'queries' => $prev_q,
						'tokens'  => $prev_t,
					),
					'after'  => array(
						'queries' => $limits['queries'],
						'tokens'  => $limits['tokens'],
					),
				);
			}
		}

		if ( empty( $changed ) ) {
			return;
		}

		$audit = new Audit_Log();
		$audit->log(
			'system',
			'settings_changed',
			'usage_limits',
			array(
				'setting' => self::OPTION_KEY,
				'changes' => $changed,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Transient helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the transient key suffix for a given user/anonymous request.
	 * Uses user ID for logged-in users, hashed IP for anonymous.
	 *
	 * @param int $user_id 0 for anonymous.
	 * @return string
	 */
	private static function counter_suffix( int $user_id ): string {
		if ( $user_id > 0 ) {
			return 'u' . $user_id . '_' . gmdate( 'Ymd' );
		}
		// Use a hash of IP so it can't be reverse-engineered from the transient key.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'a' . substr( md5( $ip ), 0, 12 ) . '_' . gmdate( 'Ymd' );
	}

	/**
	 * Return seconds until the next midnight (UTC) — used as transient TTL.
	 */
	private static function seconds_until_midnight(): int {
		return (int) strtotime( 'tomorrow' ) - time();
	}

	// -------------------------------------------------------------------------
	// Pre-request limit checks
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current user/anonymous request is within the query limit.
	 *
	 * Returns an error array (compatible with Chat_Security scan return format) or
	 * null if the request is allowed.
	 *
	 * @param int      $user_id 0 for anonymous.
	 * @param string[] $roles   User's WP role slugs (empty for anonymous).
	 * @return array{pass: false, reason: string, code: string}|null
	 */
	public static function check_query_limit( int $user_id, array $roles ): ?array {
		$limit = self::get_effective_limit( $user_id, $roles, 'queries' );
		if ( $limit <= 0 ) {
			return null; // 0 = unlimited.
		}
		$key   = self::TRANSIENT_QUERIES . self::counter_suffix( $user_id );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return array(
				'pass'   => false,
				'reason' => sprintf(
					/* translators: %d: daily query limit */
					__( 'You have reached your daily limit of %d queries. Please try again tomorrow.', 'agent-builder' ),
					$limit
				),
				'code'   => 'query_limit_reached',
			);
		}
		return null;
	}

	/**
	 * Check whether the current user has already exhausted their daily token budget.
	 *
	 * Token enforcement is post-hoc: we record after completion and block the *next*
	 * request once the budget is used. This is standard practice — pre-estimating
	 * completion tokens is unreliable.
	 *
	 * @param int      $user_id 0 for anonymous.
	 * @param string[] $roles   User's WP role slugs.
	 * @return array{pass: false, reason: string, code: string}|null
	 */
	public static function check_token_limit( int $user_id, array $roles ): ?array {
		$limit = self::get_effective_limit( $user_id, $roles, 'tokens' );
		if ( $limit <= 0 ) {
			return null; // 0 = unlimited.
		}
		$key  = self::TRANSIENT_TOKENS . self::counter_suffix( $user_id );
		$used = (int) get_transient( $key );
		if ( $used >= $limit ) {
			return array(
				'pass'   => false,
				'reason' => sprintf(
					/* translators: %d: daily token limit */
					__( 'You have reached your daily token limit of %s. Please try again tomorrow.', 'agent-builder' ),
					number_format( $limit )
				),
				'code'   => 'token_limit_reached',
			);
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Post-completion recording
	// -------------------------------------------------------------------------

	/**
	 * Increment the daily query counter after a successful chat completion.
	 *
	 * @param int $user_id 0 for anonymous.
	 */
	public static function record_query( int $user_id ): void {
		$key   = self::TRANSIENT_QUERIES . self::counter_suffix( $user_id );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::seconds_until_midnight() );
	}

	/**
	 * Add token usage to the daily counter after a successful chat completion.
	 *
	 * @param int $user_id     0 for anonymous.
	 * @param int $tokens_used Total tokens consumed (prompt + completion).
	 */
	public static function record_tokens( int $user_id, int $tokens_used ): void {
		if ( $tokens_used <= 0 ) {
			return;
		}
		$key  = self::TRANSIENT_TOKENS . self::counter_suffix( $user_id );
		$used = (int) get_transient( $key );
		set_transient( $key, $used + $tokens_used, self::seconds_until_midnight() );
	}

	// -------------------------------------------------------------------------
	// Usage stats (for the admin table display)
	// -------------------------------------------------------------------------

	/**
	 * Return today's query and token counts for a given user (or anonymous IP).
	 *
	 * @param int $user_id 0 for current anonymous IP.
	 * @return array{queries: int, tokens: int}
	 */
	public static function get_today_usage( int $user_id ): array {
		$suffix = self::counter_suffix( $user_id );
		return array(
			'queries' => (int) get_transient( self::TRANSIENT_QUERIES . $suffix ),
			'tokens'  => (int) get_transient( self::TRANSIENT_TOKENS . $suffix ),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the most restrictive non-zero limit for the user's role set.
	 * Anonymous users always use the 'anonymous' bucket.
	 * Logged-in users take the **lowest** non-zero limit across their roles
	 * (i.e. if any role has a limit set for them, that limit applies).
	 * If all roles are 0 (unlimited) the effective limit is 0 (unlimited).
	 *
	 * @param int      $user_id 0 for anonymous.
	 * @param string[] $roles   Role slugs.
	 * @param string   $field   'queries' or 'tokens'.
	 * @return int 0 = unlimited.
	 */
	private static function get_effective_limit( int $user_id, array $roles, string $field ): int {
		$limits = self::get_limits();

		if ( 0 === $user_id || empty( $roles ) ) {
			return (int) ( $limits['anonymous'][ $field ] ?? 0 );
		}

		// Administrators are never limited.
		if ( in_array( 'administrator', $roles, true ) ) {
			return 0;
		}

		$lowest = null;
		foreach ( $roles as $role ) {
			$val = (int) ( $limits[ $role ][ $field ] ?? 0 );
			if ( $val > 0 ) {
				$lowest = ( null === $lowest ) ? $val : min( $lowest, $val );
			}
		}
		return $lowest ?? 0;
	}
}
