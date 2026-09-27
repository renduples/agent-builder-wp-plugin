<?php
/**
 * Tool Executor
 *
 * Handles tool dispatch with risk-level gating, approval routing, argument
 * validation, and execution. Extracted from Agent_Controller (M2) so the
 * dispatch logic can be tested independently of the conversation loop.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      2.9.131
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes a single tool call with risk gating, approval routing, and
 * argument validation.
 *
 * Risk enforcement:
 *   allow   → execute immediately
 *   confirm → return a proposal for in-chat approval (medium risk)
 *   queue   → add to admin approval queue (high risk)
 *   block   → refuse execution (extreme risk)
 */
class Tool_Executor {

	/**
	 * Tool loader instance.
	 *
	 * @var Tool_Loader
	 */
	private Tool_Loader $tool_loader;

	/**
	 * Audit log instance.
	 *
	 * @var Audit_Log
	 */
	private Audit_Log $audit;

	/**
	 * WP 6.9+ abilities bridge, null on older installs.
	 *
	 * @var Abilities_Bridge|null
	 */
	private ?Abilities_Bridge $abilities_bridge;

	/**
	 * Constructor.
	 *
	 * @param Tool_Loader           $tool_loader      Tool loader instance.
	 * @param Audit_Log             $audit            Audit log instance.
	 * @param Abilities_Bridge|null $abilities_bridge WP 6.9+ abilities bridge.
	 */
	public function __construct(
		Tool_Loader $tool_loader,
		Audit_Log $audit,
		?Abilities_Bridge $abilities_bridge = null
	) {
		$this->tool_loader      = $tool_loader;
		$this->audit            = $audit;
		$this->abilities_bridge = $abilities_bridge;
	}

	/**
	 * Run a read-only tool without approval routing, backups, or argument logging.
	 *
	 * Site Brief uses this so a dashboard scan can never queue or execute a write.
	 * The caller must pass its own exact allowlist; anything off that list is
	 * refused even if a filter later tries to widen it. Tools whose intrinsic
	 * risk is above low are refused even if they appear on the list.
	 *
	 * @param string   $tool_name  Tool slug.
	 * @param array    $arguments  Tool arguments.
	 * @param string[] $allowlist  Exact slugs this caller may invoke.
	 * @return array Tool result or an error payload.
	 */
	public function observe( string $tool_name, array $arguments, array $allowlist ): array {
		if ( ! in_array( $tool_name, $allowlist, true ) ) {
			return array(
				'error'   => 'not_allowlisted',
				'message' => sprintf( 'Observe mode refused off-list tool: %s', $tool_name ),
			);
		}

		if ( ! Tools_Registry::is_enabled( $tool_name ) ) {
			return array(
				'error'   => 'disabled',
				'message' => sprintf( 'The tool "%s" has been disabled by an administrator.', $tool_name ),
			);
		}

		$tool = $this->tool_loader->get( $tool_name );
		if ( ! $tool ) {
			return array(
				'error'   => 'unknown_tool',
				'message' => sprintf( 'Unknown tool: %s', $tool_name ),
			);
		}

		if ( ! $tool->is_available() ) {
			return array(
				'error'   => 'unavailable',
				'message' => $tool->get_unavailable_reason(),
			);
		}

		$risk = $tool->get_risk_level();
		if ( Risk_Level::weight( $risk ) > Risk_Level::weight( Risk_Level::LOW ) ) {
			return array(
				'error'   => 'risk_too_high',
				'message' => sprintf( 'Observe mode refuses %s-risk tool: %s', $risk, $tool_name ),
			);
		}

		Tool_Base::set_calling_agent( 'site-brief' );
		$result = $this->tool_loader->execute( $tool_name, $arguments );

		return null === $result
			? array(
				'error'   => 'unknown_tool',
				'message' => sprintf( 'Unknown tool: %s', $tool_name ),
			)
			: $result;
	}

	/**
	 * Turn a tool slug into something a site owner can read.
	 *
	 * @param string $tool_name Tool slug.
	 * @return string
	 */
	private static function friendly_tool_name( string $tool_name ): string {
		$label = trim( str_replace( '_', ' ', $tool_name ) );

		return '' === $label ? $tool_name : ucfirst( $label );
	}

	/**
	 * Normalise a manifest reason to exactly one sentence-ending full stop.
	 *
	 * Manifest reasons are hand-written and inconsistent about trailing
	 * punctuation, so interpolating one raw produced a doubled stop in some
	 * places and none in others.
	 *
	 * @param string $reason Declared reason, possibly empty.
	 * @return string Empty string when nothing was declared.
	 */
	private static function as_sentence( string $reason ): string {
		$reason = trim( $reason );

		if ( '' === $reason ) {
			return '';
		}

		return rtrim( $reason, " \t\n\r\0\x0B." ) . '.';
	}

	/**
	 * Execute a tool call with full risk-level enforcement.
	 *
	 * Builds a `$ctx` array describing the call before the enforcement
	 * decision is finalised, then — unless baseline enforcement is already
	 * 'block' — runs it through the `agent_builder_tool_enforcement` filter
	 * and `Risk_Level::clamp_enforcement()`. This is the seam M12's rules
	 * layer and Pro's governance hook into: a filter can tighten enforcement
	 * (e.g. force `queue` on a normally-`allow` call) but can never resurrect
	 * a call baseline already blocked, and can only loosen a HIGH-risk
	 * decision to `allow` via the explicit grant flag `clamp_enforcement()`
	 * documents. `agent_builder_tool_gate_decision` then fires with the final
	 * decision, and `agent_builder_tool_executed` fires exactly once, after
	 * execution actually happens (never for a blocked/queued/confirm-pending
	 * return).
	 *
	 * @param string          $tool_name          Tool name.
	 * @param array           $arguments          Decoded tool arguments from the LLM.
	 * @param string          $agent_id           Calling agent identifier.
	 * @param string          $mode               Agent operating mode ('disabled'|'supervised'|'autonomous').
	 * @param string          $invocation_context How this run was triggered ('chat'|'cron'|'hook'|'cli'|'mcp').
	 * @param Agent_Base|null $agent              Agent instance for inline tool fallback, or null.
	 * @param string          $session_id         Browser-tab session ID for session-scoped grants.
	 * @param Agent_Run|null  $run                Owning run, when this call happened inside one (background
	 *                                            task, routine, delegation, …). Null for interactive chat
	 *                                            calls with no run.
	 * @return array Tool result.
	 */
	public function execute(
		string $tool_name,
		array $arguments,
		string $agent_id,
		string $mode,
		string $invocation_context,
		?Agent_Base $agent = null,
		string $session_id = '',
		?Agent_Run $run = null
	): array {
		// Block disabled tools (defense-in-depth: LLM may hallucinate calls to tools
		// that were already filtered from definitions sent to the LLM).
		if ( ! Tools_Registry::is_enabled( $tool_name ) ) {
			$this->audit->log( $agent_id, 'tool_blocked', $tool_name, array( 'reason' => 'Tool is disabled by administrator' ) );
			return array( 'error' => sprintf( 'The tool "%s" has been disabled by an administrator.', $tool_name ) );
		}

		// --- Risk-level gating ---
		$tool_instance = $this->tool_loader->get( $tool_name );
		$call_action   = is_string( $arguments['action'] ?? null ) ? $arguments['action'] : '';
		$risk          = Abilities_Manifest::get_effective_risk( $agent_id, $tool_name, $tool_instance, $call_action );
		$baseline      = Risk_Level::enforcement( $risk, $mode );

		$ctx = array(
			'tool'       => $tool_name,
			'action'     => $call_action,
			'arguments'  => $arguments,
			'agent_id'   => $agent_id,
			'mode'       => $mode,
			'invocation' => $invocation_context,
			'session_id' => $session_id,
			'run_id'     => $run instanceof Agent_Run ? $run->get_run_id() : '',
			'run_kind'   => $run instanceof Agent_Run ? $run->get_kind() : '',
			'user_id'    => $run instanceof Agent_Run ? $run->get_user_id() : get_current_user_id(),
			'risk'       => $risk,
			'baseline'   => $baseline,
		);

		// Never let a filter resurrect a call baseline enforcement already
		// blocked — the seam is skipped entirely in that case.
		if ( 'block' === $baseline ) {
			$this->audit->log(
				$agent_id,
				'tool_blocked',
				$tool_name,
				array(
					'reason'     => 'Extreme risk — execution not permitted',
					'risk_level' => $risk,
				)
			);
			return array( 'error' => 'This action is classified as extreme risk and cannot be performed.' );
		}

		/**
		 * Filter the tool enforcement decision before it is acted on.
		 *
		 * Reserved for M12's rules layer and Pro's governance: a callback can
		 * tighten enforcement (e.g. queue a normally-allowed call) freely.
		 * Loosening is bounded by Risk_Level::clamp_enforcement(), which runs
		 * immediately after this filter and cannot be bypassed.
		 *
		 * @param string $enforcement Enforcement decision ('allow'|'confirm'|'queue'|'block').
		 * @param array  $ctx         Gate context — see Tool_Executor::execute().
		 */
		$enforcement = apply_filters( 'agent_builder_tool_enforcement', $baseline, $ctx );
		$enforcement = Risk_Level::clamp_enforcement( $enforcement, $ctx );

		// A filter (or a bug in one) can return anything — a typo, a stray
		// value, null coerced to ''. Never let an unrecognized value fall
		// through the checks below to the 'allow' path; treat it as the
		// pre-filter baseline instead.
		if ( ! Risk_Level::is_valid_enforcement( $enforcement ) ) {
			$enforcement = $baseline;
		}

		/**
		 * Fires once the gate decision for this tool call is final.
		 *
		 * @param string $enforcement Final enforcement decision.
		 * @param array  $ctx         Gate context — see Tool_Executor::execute().
		 */
		do_action( 'agent_builder_tool_gate_decision', $enforcement, $ctx );

		if ( 'block' === $enforcement ) {
			$this->audit->log(
				$agent_id,
				'tool_blocked',
				$tool_name,
				array(
					'reason'     => 'Blocked by tool gate policy',
					'risk_level' => $risk,
				)
			);
			return array( 'error' => 'This action was blocked by policy and cannot be performed.' );
		}

		if ( 'queue' === $enforcement ) {
			// --- Always-grant fast-path for admin users ---
			// If the admin has declared "Always Allow" for this tool in the chat,
			// they are authoritative: skip the approval queue entirely.
			$grant_user_id = $ctx['user_id'];
			if ( $grant_user_id && user_can( $grant_user_id, 'manage_options' ) ) {
				$always_grants = get_user_meta( $grant_user_id, 'agentic_tool_grants_always', true );
				if ( is_array( $always_grants ) && in_array( $tool_name, $always_grants, true ) ) {
					$this->audit->log(
						$agent_id,
						'tool_grant_always',
						$tool_name,
						array(
							'risk_level' => $risk,
							'bypassed'   => 'approval_queue',
						)
					);
					$enforcement = 'allow';
				}
			}
		}

		if ( 'queue' === $enforcement ) {
			$queue    = new Approval_Queue();
			$approved = $queue->find_approved( $agent_id, $tool_name, $arguments );

			if ( $approved ) {
				$queue->mark_executed( (int) $approved['id'] );
				// Execute the approved params blob, not a later differing call.
				$stored = json_decode( (string) ( $approved['params'] ?? '' ), true );
				if ( is_array( $stored ) ) {
					$arguments = $stored;
				}
				$this->audit->log(
					$agent_id,
					'tool_approval_consumed',
					$tool_name,
					array(
						'approval_id' => $approved['id'],
						'risk_level'  => $risk,
					)
				);
				// Fall through to execution.
			} else {
				$manifest = Abilities_Manifest::load( $agent_id );
				$reason   = $manifest['abilities'][ $tool_name ]['reason'] ?? 'High-risk operation requires admin approval.';
				$queue_id = $queue->add( $agent_id, $tool_name, $arguments, $reason, 7, $risk, $mode, $invocation_context, $ctx['run_id'], $ctx['user_id'] );

				$this->audit->log(
					$agent_id,
					'tool_queued',
					$tool_name,
					array(
						'risk_level'  => $risk,
						'approval_id' => $queue_id,
						'reason'      => $reason,
					)
				);

				return array(
					'status'      => 'queued_for_approval',
					'approval_id' => $queue_id,
					'message'     => sprintf(
						'This action (%s) is high-risk and needs admin approval. Review it below, or in the Approval Queue page later.',
						$tool_name
					),
					'reason'      => $reason,
				);
			}
		}

		if ( 'confirm' === $enforcement ) {
			// --- Grant fast-path: "Always Allow" (persisted in admin user_meta) ---
			$grant_user_id = $ctx['user_id'];
			if ( $grant_user_id && user_can( $grant_user_id, 'manage_options' ) ) {
				$always_grants = get_user_meta( $grant_user_id, 'agentic_tool_grants_always', true );
				if ( is_array( $always_grants ) && in_array( $tool_name, $always_grants, true ) ) {
					$this->audit->log(
						$agent_id,
						'tool_grant_always',
						$tool_name,
						array( 'risk_level' => $risk )
					);
					$enforcement = 'allow';
				}
			}
		}

		if ( 'confirm' === $enforcement && '' !== $session_id ) {
			// --- Grant fast-path: "Session Allow" (transient, browser-tab scoped) ---
			$session_grants = get_transient( 'agentic_session_grants_' . sanitize_key( $session_id ) );
			if ( is_array( $session_grants ) && in_array( $tool_name, $session_grants, true ) ) {
				$this->audit->log(
					$agent_id,
					'tool_grant_session',
					$tool_name,
					array( 'risk_level' => $risk )
				);
				$enforcement = 'allow';
			}
		}

		if ( 'confirm' === $enforcement ) {
			$manifest = Abilities_Manifest::load( $agent_id );
			$label    = self::friendly_tool_name( $tool_name );

			// A tool the manifest does not describe (report_issue, for one, is
			// declared in no bundled agent's abilities.json) used to fall back to
			// "This action requires your confirmation before proceeding." — which
			// the message below then quoted back as its own Reason, producing a
			// tautology with a doubled full stop. Say what the tool is instead.
			$reason      = self::as_sentence( (string) ( $manifest['abilities'][ $tool_name ]['reason'] ?? '' ) );
			$description = '' === $reason
				? sprintf( '%s — can change your site, so it needs your approval.', $label )
				: sprintf( '%s — %s', $label, $reason );
			$proposal    = Agent_Proposals::create( $tool_name, $arguments, $agent_id, $description, '', $ctx['run_id'], $ctx['user_id'] );

			$this->audit->log(
				$agent_id,
				'tool_confirm',
				$tool_name,
				array(
					'risk_level'  => $risk,
					'proposal_id' => $proposal['id'],
					'reason'      => $reason,
				)
			);

			return array(
				'status'      => 'confirmation_required',
				'proposal_id' => $proposal['id'],
				'message'     => sprintf(
					'%s Approve or reject it below.',
					'' === $reason
						? sprintf( '“%s” can change your site, so it needs your approval first.', $label )
						: sprintf( '“%s” needs your approval first: %s', $label, $reason )
				),
				'reason'      => $reason,
			);
		}

		// --- Enforcement is 'allow' — proceed with execution ---
		$is_readonly = $tool_instance ? ( $tool_instance->get_annotations()['readonly'] ?? false ) : true;

		$this->audit->log(
			$agent_id,
			'tool_call',
			$tool_name,
			array_merge( $arguments, array( '_risk_level' => $risk ) )
		);

		Tool_Base::set_calling_agent( $agent_id );

		if ( ! $is_readonly ) {
			Tool_Helpers::backup_tables_for_tool( $tool_name, $arguments );
		}

		// Validate arguments against the tool's declared parameter schema.
		if ( $tool_instance ) {
			$arg_error = $tool_instance->validate_args( $arguments );
			if ( null !== $arg_error ) {
				$this->audit->log( $agent_id, 'tool_blocked', $tool_name, array( 'reason' => $arg_error ) );
				return array(
					'success'    => false,
					'error_code' => 'invalid_args',
					'message'    => $arg_error,
				);
			}
		}

		// Execute via Tool_Loader (all standalone tools), falling back to
		// agent-inline tools, then third-party abilities (WP 6.9+). Exactly
		// one of these produces the result — $resolved tracks whether any of
		// them actually did, so the synthesized "Unknown tool" case below
		// never gets reported as a real execution.
		$result = $this->tool_loader->execute( $tool_name, $arguments );

		if ( null !== $result ) {
			if ( ! $is_readonly ) {
				$queue = new Approval_Queue();
				$queue->log_executed( $agent_id, $tool_name, $arguments, $risk, $mode, $invocation_context );
			}
		} else {
			$result = $agent ? $agent->execute_tool( $tool_name, $arguments ) : null;

			if ( null === $result && $this->abilities_bridge ) {
				$result = $this->abilities_bridge->execute_ability( $tool_name, $arguments );
			}
		}

		$resolved = null !== $result;

		if ( ! $resolved ) {
			$result = array( 'error' => sprintf( 'Unknown tool: %s', $tool_name ) );
		}

		/**
		 * Fires once, after a tool call actually executed (never for a
		 * blocked/queued/confirm-pending return, and never for an unresolved
		 * "Unknown tool" call that no dispatcher handled).
		 *
		 * @param string $tool_name Tool name.
		 * @param array  $arguments Arguments the tool actually ran with (may differ
		 *                          from the LLM's call if an approval-queue entry
		 *                          substituted its stored params).
		 * @param array  $result    Tool result.
		 * @param array  $ctx       Gate context — see Tool_Executor::execute().
		 */
		if ( $resolved ) {
			do_action( 'agent_builder_tool_executed', $tool_name, $arguments, $result, $ctx );
		}

		return $result;
	}
}
