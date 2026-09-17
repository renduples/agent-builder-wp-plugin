<?php
/**
 * Chat Security Filter
 *
 * MU-plugin style security layer that scans all chat messages for malicious
 * payloads before they reach the LLM. Designed for speed (<1ms overhead).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.1.0
 *
 * php version 8.1
 */

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Security Scanner
 *
 * Fast, in-memory security checks for chat messages:
 * - Ban phrase detection
 * - Prompt injection patterns
 * - PII flagging
 * - Rate limiting
 */
class Chat_Security {

	/**
	 * Banned phrases that indicate prompt injection attempts.
	 * Uses strpos for O(n) fast lookup.
	 *
	 * @var array<string>
	 */
	private const BAN_PHRASES = array(
		// Prompt injection attempts.
		'ignore previous instructions',
		'ignore all previous',
		'disregard your instructions',
		'disregard all previous',
		'forget your instructions',
		'forget everything above',
		'you are now',
		'pretend you are',
		'act as if you are',
		'simulate being',
		'roleplay as',
		'jailbreak',
		'dan mode',
		'developer mode enabled',
		'ignore your programming',
		'bypass your restrictions',
		'override your safety',

		// System prompt extraction.
		'what is your system prompt',
		'show me your instructions',
		'reveal your prompt',
		'print your system message',
		'output your initial prompt',
		'display your configuration',
		'show your hidden prompt',
		'what were you told to do',

		// Code execution attempts.
		'execute this code',
		'run this script',
		'eval(',
		'base64_decode(',
		'exec(',
		'system(',
		'shell_exec(',
		'passthru(',

		// Social engineering.
		'i am the developer',
		'i am your creator',
		'this is a test from openai',
		'this is a security test',
	);

	/**
	 * Regex patterns for injection detection.
	 * Catches structured injection attempts.
	 *
	 * @var array<string>
	 */
	private const INJECTION_PATTERNS = array(
		// Llama-style injection.
		'/\[INST\].*\[\/INST\]/is',
		'/\[SYS\].*\[\/SYS\]/is',

		// ChatML injection.
		'/<\|im_start\|>.*<\|im_end\|>/is',
		'/<\|system\|>/i',
		'/<\|assistant\|>/i',

		// Markdown-based injection.
		'/###\s*(system|instruction|prompt)/i',
		'/```\s*(system|prompt|config)/i',

		// XML-style injection.
		'/<system>.*<\/system>/is',
		'/<instruction>.*<\/instruction>/is',

		// Role override attempts.
		'/^(system|assistant|user):\s/im',

		// Encoding evasion.
		'/\\\\u[0-9a-f]{4}/i',  // Unicode escapes.
	);

	/**
	 * PII patterns for flagging (not blocking).
	 *
	 * @var array<string, string>
	 */
	private const PII_PATTERNS = array(
		'credit_card'   => '/\b(?:\d{4}[\s\-]?){3}\d{4}\b/',
		'ssn'           => '/\b\d{3}[\-\s]?\d{2}[\-\s]?\d{4}\b/',
		'email'         => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Z|a-z]{2,}\b/',
		'phone_us'      => '/\b(?:\+1[\s\-]?)?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{4}\b/',
		'api_key'       => '/\b(?:sk|pk|api)[\-_][a-zA-Z0-9]{20,}\b/i',
		'password_hint' => '/(?:password|passwd|pwd)\s*[:=]\s*\S+/i',
	);

		/**
		 * Default rate limit: requests per minute per authenticated user.
		 *
		 * @var int
		 */
	private const DEFAULT_RATE_LIMIT_AUTH = 30;

		/**
		 * Default rate limit for anonymous users (by IP).
		 *
		 * @var int
		 */
	private const DEFAULT_RATE_LIMIT_ANON = 10;

		/**
		 * Default daily limit for anonymous users (by IP).
		 *
		 * @var int
		 */
	private const DEFAULT_DAILY_LIMIT_ANON = 50;

		/**
		 * Default site-wide daily limit for anonymous messages.
		 *
		 * @var int
		 */
	private const DEFAULT_DAILY_LIMIT_ANON_GLOBAL = 500;

		/**
		 * Check if security filter is enabled.
		 *
		 * @return bool
		 */
	public static function is_enabled(): bool {
		return (bool) get_option( 'agent_builder_security_enabled', true );
	}

		/**
		 * Get rate limit for authenticated users.
		 *
		 * @return int
		 */
	private static function get_auth_rate_limit(): int {
		return (int) get_option( 'agent_builder_rate_limit_authenticated', self::DEFAULT_RATE_LIMIT_AUTH );
	}

		/**
		 * Get rate limit for anonymous users.
		 *
		 * @return int
		 */
	private static function get_anon_rate_limit(): int {
		return (int) get_option( 'agent_builder_rate_limit_anonymous', self::DEFAULT_RATE_LIMIT_ANON );
	}

	/**
	 * Get rate-limit header values for the current user.
	 *
	 * Returns the data needed to populate X-RateLimit-* response headers.
	 * The window is 1 minute (MINUTE_IN_SECONDS); 'reset' reflects the
	 * current window's actual expiry when one is stored, else now + 60 s.
	 *
	 * @param int $user_id Logged-in user ID, or 0 for anonymous.
	 * @return array{limit: int, remaining: int, reset: int}
	 */
	public static function get_rate_limit_headers( int $user_id ): array {
		if ( $user_id > 0 ) {
			$limit  = self::get_auth_rate_limit();
			$window = get_transient( 'agentic_rate_user_' . $user_id );
		} else {
			$limit  = self::get_anon_rate_limit();
			$window = get_transient( 'agentic_rate_ip_' . md5( self::get_client_ip() ) );
		}

		$count = is_array( $window ) ? (int) ( $window['count'] ?? 0 ) : 0;
		$reset = is_array( $window ) && isset( $window['expires'] ) ? (int) $window['expires'] : time() + MINUTE_IN_SECONDS;

		return array(
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $count ),
			'reset'     => $reset,
		);
	}

		/**
		 * Scan message for security issues.
		 *
		 * @param string $message User message to scan.
		 * @param int    $user_id User ID for rate limiting (0 for anonymous).
		 * @return array{pass: bool, reason?: string, code?: string, pii_warning?: array}
		 */
	public static function scan( string $message, int $user_id = 0 ): array {
		// If security is disabled, just do basic rate limiting.
		if ( ! self::is_enabled() ) {
			$rate_result = self::check_rate_limit( $user_id );
			if ( null !== $rate_result ) {
				return $rate_result;
			}
			return array( 'pass' => true );
		}

		// Normalize for comparison.
		$message_lower = strtolower( trim( $message ) );

		// Empty message check.
		if ( empty( $message_lower ) ) {
			return array(
				'pass'   => false,
				'reason' => 'Message cannot be empty.',
				'code'   => 'empty_message',
			);
		}

		// 1. Ban phrase check (fastest, ~0.1ms).
		$ban_result = self::check_ban_phrases( $message_lower, $user_id );
		if ( null !== $ban_result ) {
			return $ban_result;
		}

		// 2. Injection pattern check (~0.2ms).
		$injection_result = self::check_injection_patterns( $message, $user_id );
		if ( null !== $injection_result ) {
			return $injection_result;
		}

		// 3. Rate limit check (~0.1ms).
		$rate_result = self::check_rate_limit( $user_id );
		if ( null !== $rate_result ) {
			return $rate_result;
		}

		// 4. Daily limit check (anonymous only).
		$daily_result = self::check_daily_limit( $user_id );
		if ( null !== $daily_result ) {
			return $daily_result;
		}

		// 5. Per-role daily query and token limits.
		$user_roles   = $user_id > 0 ? (array) ( get_userdata( $user_id )->roles ?? array() ) : array();
		$query_result = \Agentic\Usage_Limits::check_query_limit( $user_id, $user_roles );
		if ( null !== $query_result ) {
			return $query_result;
		}
		$token_result = \Agentic\Usage_Limits::check_token_limit( $user_id, $user_roles );
		if ( null !== $token_result ) {
			return $token_result;
		}

		// 6. PII scan (non-blocking, just flags).
		$pii_found = self::scan_pii( $message );

		// Build success response.
		$response = array( 'pass' => true );

		if ( ! empty( $pii_found ) ) {
			$response['pii_warning'] = $pii_found;
			self::log_pii_warning( $user_id, $pii_found );
		}

		return $response;
	}

		/**
		 * Check for banned phrases.
		 *
		 * @param string $message_lower Lowercased message.
		 * @param int    $user_id       User ID.
		 * @return array|null Failure result or null if passed.
		 */
	private static function check_ban_phrases( string $message_lower, int $user_id ): ?array {
		foreach ( self::BAN_PHRASES as $phrase ) {
			if ( strpos( $message_lower, $phrase ) !== false ) {
				self::log_blocked( $message_lower, $user_id, 'ban_phrase', $phrase );

				return array(
					'pass'   => false,
					'reason' => 'Your message contains content that cannot be processed.',
					'code'   => 'banned_content',
				);
			}
		}

		return null;
	}

		/**
		 * Check for injection patterns.
		 *
		 * @param string $message Original message (case preserved).
		 * @param int    $user_id User ID.
		 * @return array|null Failure result or null if passed.
		 */
	private static function check_injection_patterns( string $message, int $user_id ): ?array {
		foreach ( self::INJECTION_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				self::log_blocked( $message, $user_id, 'injection_pattern', $pattern );

				return array(
					'pass'   => false,
					'reason' => 'Message format is not supported.',
					'code'   => 'invalid_format',
				);
			}
		}

		return null;
	}

		/**
		 * Check rate limit.
		 *
		 * @param int $user_id User ID (0 for anonymous).
		 * @return array|null Failure result or null if passed.
		 */
	private static function check_rate_limit( int $user_id ): ?array {
		// Build rate limit key.
		if ( $user_id > 0 ) {
			$key   = 'agentic_rate_user_' . $user_id;
			$limit = self::get_auth_rate_limit();
		} else {
			// Anonymous: use IP.
			$ip    = self::get_client_ip();
			$key   = 'agentic_rate_ip_' . md5( $ip );
			$limit = self::get_anon_rate_limit();
		}

		$now    = time();
		$window = get_transient( $key );

		// Start a fresh rolling window if none is stored, or the stored one
		// has already lapsed (belt-and-suspenders — the transient itself
		// should have expired by then too).
		if ( ! is_array( $window ) || ! isset( $window['count'], $window['expires'] ) || $window['expires'] <= $now ) {
			$window = array(
				'count'   => 0,
				'expires' => $now + MINUTE_IN_SECONDS,
			);
		}

		if ( $window['count'] >= $limit ) {
			self::log_rate_limited( $user_id );

			return array(
				'pass'   => false,
				'reason' => 'Too many requests. Please wait a moment before trying again.',
				'code'   => 'rate_limited',
			);
		}

		// Increment within the existing window without extending it. Calling
		// set_transient() with a fresh MINUTE_IN_SECONDS on every request
		// would push the expiration out each time (that's how WP transients
		// work), so a steady chat pace faster than one message per minute
		// would keep the window alive indefinitely — turning a "N per
		// minute" cap into "N total since the last 60-second gap in
		// activity" and eventually blocking any sustained, non-abusive
		// conversation. Persisting the window's own 'expires' and reusing
		// only the time remaining until it keeps the cap tied to the actual
		// rolling minute instead.
		++$window['count'];
		set_transient( $key, $window, max( 1, $window['expires'] - $now ) );

		return null;
	}

	/**
	 * Check daily limits for anonymous users.
	 *
	 * @param int $user_id User ID (0 for anonymous).
	 * @return array|null Failure result or null if passed.
	 */
	private static function check_daily_limit( int $user_id ): ?array {
		// Daily limits only apply to anonymous users.
		if ( $user_id > 0 ) {
			return null;
		}

		$ip = self::get_client_ip();

		// Per-IP daily limit.
		$ip_key   = 'agentic_daily_ip_' . md5( $ip );
		$ip_limit = (int) get_option( 'agent_builder_daily_limit_anonymous', self::DEFAULT_DAILY_LIMIT_ANON );
		$ip_count = (int) get_transient( $ip_key );

		if ( $ip_count >= $ip_limit ) {
			self::log_rate_limited( $user_id );
			return array(
				'pass'   => false,
				'reason' => 'Daily message limit reached. Please try again tomorrow.',
				'code'   => 'daily_limit',
			);
		}

		// Site-wide daily limit for all anonymous messages.
		$global_key   = 'agentic_daily_anon_global';
		$global_limit = (int) get_option( 'agent_builder_daily_limit_anonymous_global', self::DEFAULT_DAILY_LIMIT_ANON_GLOBAL );
		$global_count = (int) get_transient( $global_key );

		if ( $global_count >= $global_limit ) {
			self::log_rate_limited( $user_id );
			return array(
				'pass'   => false,
				'reason' => 'Our AI agent is currently at capacity. Please try again later or log in for priority access.',
				'code'   => 'daily_limit_global',
			);
		}

		// Increment both counters (expire at end of day).
		$seconds_until_midnight = strtotime( 'tomorrow' ) - time();
		set_transient( $ip_key, $ip_count + 1, $seconds_until_midnight );
		set_transient( $global_key, $global_count + 1, $seconds_until_midnight );

		return null;
	}

	/**
	 * Verify Turnstile token. Returns null (pass) or an array with 'pass' => false
	 * and a human-readable 'reason' on failure.
	 *
	 * @param string $token  Turnstile response token.
	 * @param int    $user_id User ID (0 for anonymous).
	 * @return array|null
	 */
	public static function verify_turnstile( string $token, int $user_id = 0 ): ?array {
		return \Agentic\Turnstile::verify( $token, $user_id );
	}

	/**
	 * Check if Turnstile is required.
	 *
	 * @return bool
	 */
	public static function is_turnstile_required(): bool {
		return \Agentic\Turnstile::is_required();
	}

	/**
	 * Get Turnstile site key.
	 *
	 * @return string
	 */
	public static function get_turnstile_site_key(): string {
		return \Agentic\Turnstile::get_site_key();
	}

		/**
		 * Scan for PII patterns.
		 *
		 * @param string $message Message to scan.
		 * @return array<string> List of PII types found.
		 */
	private static function scan_pii( string $message ): array {
		$found = array();

		foreach ( self::PII_PATTERNS as $type => $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				$found[] = $type;
			}
		}

		return $found;
	}

		/**
		 * Get client IP address.
		 *
		 * @return string IP address.
		 */
	public static function get_client_ip(): string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',  // Cloudflare.
			'HTTP_X_FORWARDED_FOR',   // Proxy.
			'HTTP_X_REAL_IP',         // Nginx.
			'REMOTE_ADDR',            // Direct.
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// Handle comma-separated list (X-Forwarded-For).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

		/**
		 * Log blocked message.
		 *
		 * @param string $message  The message that was blocked.
		 * @param int    $user_id  User ID.
		 * @param string $type     Block type (ban_phrase, injection_pattern).
		 * @param string $pattern  What triggered the block.
		 */
	private static function log_blocked( string $message, int $user_id, string $type, string $pattern = '' ): void {
		// Log to database.
		if ( class_exists( '\Agentic\Security_Log' ) ) {
			\Agentic\Security_Log::log(
				'blocked',
				$user_id,
				self::get_client_ip(),
				$message,
				$pattern
			);
		}

		/**
			 * Fires when a message is blocked by security filter.
			 *
			 * @param string $message  The blocked message.
			 * @param int    $user_id  User ID (0 for anonymous).
			 * @param string $type     Block type.
			 * @param string $pattern  Pattern that matched.
			 */
		do_action( 'agentic_security_blocked', $message, $user_id, $type, $pattern );
	}

	/**
	 * Log rate limit hit.
	 *
	 * @param int $user_id User ID.
	 */
	private static function log_rate_limited( int $user_id ): void {
		// Log to database.
		if ( class_exists( '\Agentic\Security_Log' ) ) {
			\Agentic\Security_Log::log(
				'rate_limited',
				$user_id,
				self::get_client_ip()
			);
		}

		/**
		 * Fires when a user hits the rate limit.
		 *
		 * @param int    $user_id User ID (0 for anonymous).
		 * @param string $ip      Client IP address.
		 */
		do_action( 'agentic_security_rate_limited', $user_id, self::get_client_ip() );
	}

	/**
	 * Log PII warning.
	 *
	 * @param int   $user_id User ID.
	 * @param array $types   PII types detected.
	 */
	private static function log_pii_warning( int $user_id, array $types ): void {
		// Log to database.
		if ( class_exists( '\Agentic\Security_Log' ) ) {
			\Agentic\Security_Log::log(
				'pii_warning',
				$user_id,
				self::get_client_ip(),
				'',
				'',
				$types
			);
		}

		/**
		 * Fires when PII is detected in a message.
		 *
		 * @param int   $user_id User ID.
		 * @param array $types   PII types detected.
		 */
		do_action( 'agentic_security_pii_detected', $user_id, $types );
	}

	/**
	 * Add custom ban phrases at runtime.
	 *
	 * @param array<string> $phrases Additional phrases to ban.
	 */
	public static function add_ban_phrases( array $phrases ): void {
		// This would require making BAN_PHRASES non-const.
		// For now, use the filter hook instead.
		add_filter(
			'agentic_security_ban_phrases',
			function ( $existing ) use ( $phrases ) {
				return array_merge( $existing, $phrases );
			}
		);
	}

	/**
	 * Get all ban phrases (allows filtering).
	 *
	 * @return array<string> Ban phrases.
	 */
	public static function get_ban_phrases(): array {
		/**
		 * Filter the list of banned phrases.
		 *
		 * @param array<string> $phrases Default ban phrases.
		 */
		return apply_filters( 'agentic_security_ban_phrases', self::BAN_PHRASES );
	}

	/**
	 * Sanitize message by removing detected PII.
	 *
	 * @param string $message Message to sanitize.
	 * @return string Sanitized message.
	 */
	public static function sanitize_pii( string $message ): string {
		foreach ( self::PII_PATTERNS as $type => $pattern ) {
			$replacement = '[' . strtoupper( $type ) . '_REDACTED]';
			$message     = preg_replace( $pattern, $replacement, $message );
		}

		return $message;
	}
}
