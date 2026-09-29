<?php
/**
 * Tool Grants
 *
 * Single home for the tool-grant store and the one decision point Tool_Executor
 * consults when a tool call needs approval. Consolidates the grant-checking and
 * grant-writing logic that previously lived inline in Tool_Executor::execute()
 * and class-rest-api.php's proposal handler.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      4.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool grant scopes and their storage.
 *
 * Three scopes:
 *   always  — user meta `agentic_tool_grants_always`; a bare tool name grants
 *             every agent, a `tool@agent` key grants only that agent.
 *   session — transient `agentic_session_grants_{session_id}`, browser-tab
 *             scoped, storing `{ user, grants: [ tool@agent, … ] }` so a grant
 *             is bound to the user who granted it and the agent it names.
 *   run     — transient `agentic_run_grants_{run_id}`, keyed on the exact run
 *             id (never sanitize_key()) and storing `[ tool@agent, … ]`, scoped
 *             to a single run and the agent it was granted to.
 *
 * The `once` scope is deliberately not here: it remains
 * Approval_Queue::find_approved()'s concern, which Tool_Executor keeps calling
 * separately. This class only decides whether a persisted always/session/run
 * grant downgrades an enforcement decision to `allow`.
 */
class Tool_Grants {

	/**
	 * User-meta key backing the `always` scope.
	 *
	 * @var string
	 */
	const ALWAYS_META_KEY = 'agentic_tool_grants_always';

	/**
	 * Resolve a pending enforcement decision against the persisted grants.
	 *
	 * The single decision point Tool_Executor::execute() calls instead of its
	 * former inline always/session blocks. Checks the `always`, `session`, and
	 * `run` scopes in that order and, if any grants the tool, downgrades the
	 * decision to `allow`; otherwise the decision is returned unchanged.
	 *
	 * `allow` and `block` are terminal and never downgraded (a grant cannot
	 * resurrect an extreme-risk `block`). The `always` scope keeps its historic
	 * `manage_options` guard so existing behaviour is unchanged for that scope.
	 *
	 * @param string $enforcement Current enforcement ('allow'|'confirm'|'queue'|'block').
	 * @param array  $ctx         Gate context — see Tool_Executor::execute(); reads
	 *                            'tool', 'agent_id', 'user_id', 'session_id',
	 *                            'run_id', and 'risk'.
	 * @return string Final enforcement.
	 */
	public static function resolve( string $enforcement, array $ctx ): string {
		if ( 'allow' === $enforcement || 'block' === $enforcement ) {
			return $enforcement;
		}

		$tool       = (string) ( $ctx['tool'] ?? '' );
		$agent_id   = (string) ( $ctx['agent_id'] ?? '' );
		$user_id    = (int) ( $ctx['user_id'] ?? 0 );
		$session_id = (string) ( $ctx['session_id'] ?? '' );
		$run_id     = (string) ( $ctx['run_id'] ?? '' );

		// always — persisted user meta, only ever authoritative for admins
		// (unchanged from the historic inline check). Accepts both a bare tool
		// name and a `tool@agent` key so a grant can be agent-scoped.
		if ( ( 'queue' === $enforcement || 'confirm' === $enforcement ) && $user_id && user_can( $user_id, 'manage_options' ) ) {
			$always = get_user_meta( $user_id, self::ALWAYS_META_KEY, true );
			if ( is_array( $always ) && self::grant_matches( $always, $tool, $agent_id, true ) ) {
				self::log_grant( 'tool_grant_always', $ctx, 'queue' === $enforcement ? array( 'bypassed' => 'approval_queue' ) : array() );
				return 'allow';
			}
		}

		// session — transient, browser-tab scoped, bound to the user who granted
		// it and the agent it was granted to. Historically only ever loosened a
		// `confirm` decision, never the approval queue. The transient now stores
		// `array( 'user' => int, 'grants' => string[] )` so one user's session
		// grant can never authorise a different user, and a `tool@agent` grant
		// never authorises a different agent.
		if ( 'confirm' === $enforcement && '' !== $session_id ) {
			$session = get_transient( 'agentic_session_grants_' . sanitize_key( $session_id ) );
			if ( is_array( $session ) && is_array( $session['grants'] ?? null ) ) {
				$session_user = (int) ( $session['user'] ?? 0 );
				if ( $session_user > 0 && $session_user === $user_id && self::grant_matches( $session['grants'], $tool, $agent_id ) ) {
					self::log_grant( 'tool_grant_session', $ctx );
					return 'allow';
				}
			}
		}

		// run — transient, scoped to a single run and keyed on the exact,
		// validated run id (never sanitize_key(), which could collide two
		// distinct ids). Applies to both `queue` and `confirm`: a run-scoped
		// grant is "allow this tool for the whole run", for the granted agent.
		if ( '' !== $run_id ) {
			$run = get_transient( 'agentic_run_grants_' . $run_id );
			if ( is_array( $run ) && self::grant_matches( $run, $tool, $agent_id ) ) {
				self::log_grant( 'tool_grant_run', $ctx );
				return 'allow';
			}
		}

		return $enforcement;
	}

	/**
	 * Record a grant for a scope.
	 *
	 * @param string $scope 'always', 'session', or 'run'.
	 * @param string $tool  Tool slug. For the `always` scope a bare name grants every
	 *                      agent and a `tool@agent` key grants only that agent; the
	 *                      `session` and `run` scopes are agent-scoped and must be
	 *                      passed as a `tool@agent` key.
	 * @param array  $ctx   Context carrying where to write: 'user_id' (always and
	 *                      session), 'session_id' (session), or 'run_id' (run).
	 * @return void
	 */
	public static function grant( string $scope, string $tool, array $ctx ): void {
		switch ( $scope ) {
			case 'always':
				$user_id = (int) ( $ctx['user_id'] ?? 0 );
				if ( 0 === $user_id ) {
					return;
				}
				$grants = get_user_meta( $user_id, self::ALWAYS_META_KEY, true );
				if ( ! is_array( $grants ) ) {
					$grants = array();
				}
				if ( ! in_array( $tool, $grants, true ) ) {
					$grants[] = $tool;
					update_user_meta( $user_id, self::ALWAYS_META_KEY, $grants );
				}
				return;

			case 'session':
				$session_id = (string) ( $ctx['session_id'] ?? '' );
				if ( '' === $session_id ) {
					return;
				}
				$key     = 'agentic_session_grants_' . sanitize_key( $session_id );
				$session = get_transient( $key );
				if ( ! is_array( $session ) || ! is_array( $session['grants'] ?? null ) ) {
					$session = array(
						'user'   => (int) ( $ctx['user_id'] ?? 0 ),
						'grants' => array(),
					);
				}
				if ( ! in_array( $tool, $session['grants'], true ) ) {
					$session['grants'][] = $tool;
				}
				set_transient( $key, $session, DAY_IN_SECONDS );
				return;

			case 'run':
				$run_id = (string) ( $ctx['run_id'] ?? '' );
				if ( '' === $run_id ) {
					return;
				}
				// Key on the exact run id, never sanitize_key(): two distinct ids
				// (e.g. `run_abc.DEF` vs `run_abcdef`) must not share a grant.
				$key    = 'agentic_run_grants_' . $run_id;
				$grants = get_transient( $key );
				if ( ! is_array( $grants ) ) {
					$grants = array();
				}
				if ( ! in_array( $tool, $grants, true ) ) {
					$grants[] = $tool;
				}
				// A run should never legitimately outlive a day; the transient is
				// the run grant's lifetime, not the run row's `state` column.
				set_transient( $key, $grants, DAY_IN_SECONDS );
				return;
		}
	}

	/**
	 * Remove a grant for a scope.
	 *
	 * Inverse of grant() for the `always` and `session` scopes. The `run` scope
	 * is not revocable — it expires with its transient when the run ends.
	 *
	 * @param string $scope 'always' or 'session'.
	 * @param string $tool  Tool slug (or `tool@agent` key) to remove.
	 * @param array  $ctx   Context carrying 'user_id' (always) or 'session_id' (session).
	 * @return void
	 */
	public static function revoke( string $scope, string $tool, array $ctx ): void {
		if ( 'always' === $scope ) {
			$user_id = (int) ( $ctx['user_id'] ?? 0 );
			if ( 0 === $user_id ) {
				return;
			}
			$grants = get_user_meta( $user_id, self::ALWAYS_META_KEY, true );
			if ( is_array( $grants ) ) {
				update_user_meta( $user_id, self::ALWAYS_META_KEY, array_values( array_diff( $grants, array( $tool ) ) ) );
			}
			return;
		}

		if ( 'session' === $scope ) {
			$session_id = (string) ( $ctx['session_id'] ?? '' );
			if ( '' === $session_id ) {
				return;
			}
			$key     = 'agentic_session_grants_' . sanitize_key( $session_id );
			$session = get_transient( $key );
			if ( is_array( $session ) && is_array( $session['grants'] ?? null ) ) {
				$session['grants'] = array_values( array_diff( $session['grants'], array( $tool ) ) );
				set_transient( $key, $session, DAY_IN_SECONDS );
			}
			return;
		}

		// 'run' is intentionally not revocable: it expires with the run.
	}

	/**
	 * The current user's always-grants, for a later Grants-tab UI.
	 *
	 * @param int $user_id User whose always-grants to list.
	 * @return string[] Bare tool names and/or `tool@agent` keys.
	 */
	public static function list_for_user( int $user_id ): array {
		$grants = get_user_meta( $user_id, self::ALWAYS_META_KEY, true );

		return is_array( $grants ) ? array_values( $grants ) : array();
	}

	/**
	 * Whether a run id is well-formed enough to key a run grant on verbatim.
	 *
	 * Real run ids are either a lowercase v4 UUID (`wp_generate_uuid4()`) or the
	 * `uniqid('run_', true)` fallback used before that function existed. The
	 * run-grant transient is keyed on this id without sanitize_key() (to avoid
	 * colliding two distinct ids), so the REST grant route must reject anything
	 * that does not match one of those two shapes before it can reach a
	 * transient key.
	 *
	 * @param string $run_id Candidate run id.
	 * @return bool True when the id conforms to a generated run id.
	 */
	public static function is_valid_run_id( string $run_id ): bool {
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $run_id ) ) {
			return true;
		}

		return 1 === preg_match( '/^run_[0-9a-f]+\.[0-9a-f]+$/i', $run_id );
	}

	/**
	 * Whether a stored grant list covers the tool.
	 *
	 * A `tool@agent` key grants only the named agent. For the `always` scope a
	 * bare tool name grants every agent (set `$bare_matches_any` true); the
	 * `session` and `run` scopes are agent-scoped and only ever match a
	 * `tool@agent` key, so a bare tool there never authorises an agent.
	 *
	 * @param string[] $grants           Stored grant entries.
	 * @param string   $tool             Tool slug being checked.
	 * @param string   $agent_id         Calling agent identifier.
	 * @param bool     $bare_matches_any Whether a bare tool name grants every agent.
	 * @return bool
	 */
	private static function grant_matches( array $grants, string $tool, string $agent_id, bool $bare_matches_any = false ): bool {
		if ( $bare_matches_any && in_array( $tool, $grants, true ) ) {
			return true;
		}

		return '' !== $agent_id && in_array( $tool . '@' . $agent_id, $grants, true );
	}

	/**
	 * Write the grant event to the audit trail.
	 *
	 * @param string $action Audit action slug.
	 * @param array  $ctx    Gate context carrying 'agent_id', 'tool', and 'risk'.
	 * @param array  $extra  Extra detail keys (e.g. 'bypassed' => 'approval_queue').
	 * @return void
	 */
	private static function log_grant( string $action, array $ctx, array $extra = array() ): void {
		$details = array( 'risk_level' => $ctx['risk'] ?? '' );
		if ( ! empty( $extra ) ) {
			$details = array_merge( $details, $extra );
		}

		$audit = new Audit_Log();
		$audit->log(
			(string) ( $ctx['agent_id'] ?? '' ),
			$action,
			(string) ( $ctx['tool'] ?? '' ),
			$details
		);
	}
}
