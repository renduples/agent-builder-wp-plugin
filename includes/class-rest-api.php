<?php
/**
 * REST API endpoints
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

declare(strict_types=1);

namespace Agentic;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API handler for agent interactions
 */
class REST_API {

	// ── Response helpers ──────────────────────────────────────────────────────
	// Standard envelope: {success, data} on success / {success, error{code, message}} on failure.
	//
	// NOTE: The /chat and /proposals endpoints cannot use this
	// envelope without breaking chat.js (which reads top-level keys like
	// data.response, data.output, data.error). Standardise those only alongside
	// a coordinated frontend update (M3).

	/**
	 * Return a successful API response wrapped in the standard envelope.
	 *
	 * @param mixed $data   Response payload.
	 * @param int   $status HTTP status code.
	 * @return \WP_REST_Response
	 */
	private function api_success( mixed $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Return a failed API response wrapped in the standard envelope.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	private function api_error( string $code, string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Chat endpoint.
		register_rest_route(
			'agentic/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'message'            => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session_id'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'agent_id'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'history'            => array(
						'type'              => 'array',
						'default'           => array(),
						'maxItems'          => 50,
						'validate_callback' => static function ( $val ) {
							// Enforce a hard cap to prevent DoS via huge history payloads.
							return is_array( $val ) && count( $val ) <= 50;
						},
					),
					'image'              => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'turnstile_token'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'page_context'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'default'           => '',
					),
					'deployment_context' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'stream'             => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// List chat sessions for current user. Anonymous chat (when enabled) has
		// no per-visitor identity — every anonymous session is stored under the
		// same user_id (0) — so listing "my sessions" for an anonymous caller
		// would hand back every anonymous visitor's session list. Requires a
		// real logged-in user regardless of agentic_allow_anonymous_chat.
		register_rest_route(
			'agentic/v1',
			'/sessions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sessions' ),
				'permission_callback' => array( $this, 'check_real_user' ),
				'args'                => array(
					'agent_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'limit'    => array(
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get conversation history for a session.
		register_rest_route(
			'agentic/v1',
			'/history/(?P<session_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			)
		);

		// Get agent status.
		register_rest_route(
			'agentic/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		// List available models for a provider.
		register_rest_route(
			'agentic/v1',
			'/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_provider_models' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Test API key.
		register_rest_route(
			'agentic/v1',
			'/test-api',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_api_key' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'provider'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'api_key'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'model'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ollama_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		// Get pending approvals — same capability as the Approvals admin page.
		register_rest_route(
			'agentic/v1',
			'/approvals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_approvals' ),
				'permission_callback' => array( $this, 'check_manage_agents' ),
			)
		);

		// Handle approval action.
		// Note: nonce-based CSRF protection is not used here because WP REST API
		// authentication (cookie + nonce via X-WP-Nonce header, or Application Passwords)
		// already provides CSRF protection for authenticated requests.
		register_rest_route(
			'agentic/v1',
			'/approvals/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_approval' ),
				'permission_callback' => array( $this, 'check_manage_agents' ),
				'args'                => array(
					'action' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'approve', 'reject' ),
					),
				),
			)
		);

		// Approve or reject a user-space proposal. This is a personal in-chat
		// confirmation card (not the admin approval queue), so anyone who can
		// chat may reach it — handle_proposal() itself enforces that only the
		// proposal's own creator (or an admin) can resolve it.
		register_rest_route(
			'agentic/v1',
			'/proposals/(?P<id>[a-f0-9-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_proposal' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'action'     => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'once', 'session', 'always', 'reject' ),
					),
					'session_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Revoke an always-allow tool grant for the current admin user.
		register_rest_route(
			'agentic/v1',
			'/tool-grants/(?P<tool>[a-zA-Z0-9_]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_tool_grant_revoke' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		// Restore a file backup.
		register_rest_route(
			'agentic/v1',
			'/backups/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_backup_restore' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'file' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Delete a backup file.
		register_rest_route(
			'agentic/v1',
			'/backups/(?P<file>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_backup_delete' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		// Restore a database table from backup.
		register_rest_route(
			'agentic/v1',
			'/backups/restore-table',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_table_restore' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'file' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Delete a database table backup.
		register_rest_route(
			'agentic/v1',
			'/backups/table/(?P<file>[^/]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_table_backup_delete' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		// TTS synthesis — returns binary audio/mpeg, not JSON.
		register_rest_route(
			'agentic/v1',
			'/tts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_tts' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'text'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $val ) {
							return is_string( $val ) && mb_strlen( $val ) >= 1 && mb_strlen( $val ) <= 4096;
						},
					),
					'voice' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'journey-f',
						'enum'     => array( 'journey-f', 'journey-d', 'journey-o', 'neural2-c', 'neural2-d', 'standard-f', 'standard-b' ),
					),
				),
			)
		);

		// Conversation feedback (thumbs up / down) from the chat UI.
		register_rest_route(
			'agentic/v1',
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_feedback' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $val ) {
							return is_string( $val ) && strlen( $val ) >= 4 && strlen( $val ) <= 64;
						},
					),
					'thumb'      => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'up', 'down' ),
					),
				),
			)
		);
	}

	/**
	 * Handle chat request
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message            = $request->get_param( 'message' );
		$session_id         = $request->get_param( 'session_id' ) ? $request->get_param( 'session_id' ) : wp_generate_uuid4();
		$history            = $request->get_param( 'history' ) ? $request->get_param( 'history' ) : array();
		$page_context       = sanitize_textarea_field( $request->get_param( 'page_context' ) ?? '' );
		$deployment_context = sanitize_key( $request->get_param( 'deployment_context' ) ?? '' );
		$user_id            = get_current_user_id();

		// P0 Basic Multi-Agent Orchestration handoff support.
		$handoff_from    = sanitize_key( $request->get_param( 'handoff_from' ) ?? '' );
		$handoff_context = sanitize_textarea_field( $request->get_param( 'handoff_context' ) ?? '' );

		// Validate optional image attachment (base64 data URL, max 5 MB).
		$image_data = null;
		$raw_image  = $request->get_param( 'image' );
		if ( $raw_image ) {
			if ( preg_match( '/^data:(image\/(jpeg|png|gif|webp));base64,/', $raw_image, $matches ) ) {
				$base64_part = substr( $raw_image, strlen( $matches[0] ) );
				// Rough size check: base64 is ~4/3 of original, so 5 MB encoded ≈ 6.7 MB base64 chars.
				if ( strlen( $base64_part ) <= 7 * 1024 * 1024 ) {
					// Save as a temporary upload so we have a public URL (required by some LLM providers).
					$temp_url = $this->save_temp_image( $base64_part, $matches[1] );
					if ( $temp_url ) {
						$image_data = array(
							'url'       => $temp_url,
							'data_url'  => $raw_image,
							'mime_type' => $matches[1],
							'base64'    => $base64_part,
						);
					}
				}
			}
		}

		// Validate and sanitize history messages.
		foreach ( $history as &$msg ) {
			// Enforce allowed roles to prevent prompt-injection via system-role messages.
			if ( ! isset( $msg['role'] ) || ! in_array( $msg['role'], array( 'user', 'assistant' ), true ) ) {
				$msg['role'] = 'user';
			}
			// Ensure all messages have a content field (required by some LLM providers).
			if ( ! isset( $msg['content'] ) || null === $msg['content'] ) {
				$msg['content'] = '';
			}
			// Sanitize content.
			$msg['content'] = sanitize_textarea_field( (string) $msg['content'] );
		}
		unset( $msg );

		// Turnstile bot verification, when configured and required (before security scan to save CPU).
		if ( Turnstile::is_required() ) {
			$turnstile_token  = (string) ( $request->get_param( 'turnstile_token' ) ?? '' );
			$turnstile_result = Turnstile::verify( $turnstile_token, $user_id );

			if ( null !== $turnstile_result && ! $turnstile_result['pass'] ) {
				return new \WP_REST_Response(
					array(
						'error'    => true,
						'response' => $turnstile_result['reason'],
						'code'     => 'turnstile_failed',
					),
					403
				);
			}
		}

		// Consent gate — block if consent notice is enabled and user hasn't accepted.
		$consent_result = \Agentic\GDPR::check_consent();
		if ( null !== $consent_result && ! $consent_result['pass'] ) {
			return new \WP_REST_Response(
				array(
					'error'    => true,
					'response' => $consent_result['reason'],
					'code'     => $consent_result['code'],
				),
				403
			);
		}

		// Security check - fast, in-memory scan.
		$security_result = \Agentic\Chat_Security::scan( $message, $user_id );

		if ( ! $security_result['pass'] ) {
			$status_code = ( $security_result['code'] ?? '' ) === 'rate_limited' ? 429 : 403;

			return new \WP_REST_Response(
				array(
					'error'    => true,
					'response' => $security_result['reason'],
					'code'     => $security_result['code'] ?? 'security_block',
				),
				$status_code
			);
		}

		// Get agent ID — fall back to first registered agent when none specified.
		$agent_id = $request->get_param( 'agent_id' ) ?? '';
		if ( ! $agent_id ) {
			$instances = \Agentic_Agent_Registry::get_instance()->get_accessible_instances();
			$agent_id  = $instances ? array_key_first( $instances ) : '';
		}
		$is_stream = (bool) $request->get_param( 'stream' );

		// Process with potential tool calls.
		$response   = null;
		$controller = new Agent_Controller();

		// SSE streaming — bypass WP REST response system and output events directly.
		if ( $is_stream ) {
			// Flush any existing output buffers so we own the connection.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'X-Accel-Buffering: no' ); // Prevent nginx from buffering the stream.
			ob_implicit_flush( true );
			if ( ob_get_level() > 0 ) {
				ob_end_flush();
			}

			$controller->enable_streaming(
				static function ( string $type, mixed $data ): void {
					if ( 'live' === $type ) {
						$payload = array(
							'type'  => 'live',
							'token' => (string) $data,
						);
					} elseif ( is_array( $data ) ) {
						$payload = array_merge( array( 'type' => $type ), $data );
					} else {
						$payload = array(
							'type' => $type,
							'data' => $data,
						);
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
					if ( ob_get_level() > 0 ) {
						ob_end_flush();
					}
					flush();
				}
			);
		}

		try {
			$response = $controller->chat( $message, $history, $user_id, $session_id, $agent_id, $image_data, $page_context, $deployment_context, $handoff_from, $handoff_context );
		} catch ( \Throwable $e ) {
			\Agentic\Security_Log::log_system(
				'chat_exception',
				'rest_api',
				array(
					'agent_id'   => $agent_id,
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'error'      => $e->getMessage(),
					'file'       => $e->getFile() . ':' . $e->getLine(),
				)
			);
			if ( $is_stream ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo 'data: ' . wp_json_encode(
					array(
						'type'    => 'error',
						'message' => __( 'Something went wrong.', 'agent-builder' ),
					)
				) . "\n\n";
				flush();
				exit;
			}
			return new \WP_REST_Response(
				array(
					'error'    => true,
					'response' => __( 'Something went wrong. The issue has been reported to your site administrator.', 'agent-builder' ),
				),
				500
			);
		}

		// Persist conversation turns to the dedicated conversations table.
		if ( empty( $response['error'] ) && ! empty( $response['response'] ) ) {
			$this->log_conversation_turn( $session_id, $user_id, $agent_id, 'user', $message );
			$this->log_conversation_turn( $session_id, $user_id, $agent_id, 'assistant', $response['response'], $response['tools_used'] ?? array() );
		}

		// Log errors to audit log.
		if ( ! empty( $response['error'] ) ) {
			$audit = new Audit_Log();
			$audit->log(
				$agent_id ? $agent_id : 'unknown',
				'chat_error',
				'error',
				array(
					'error_message' => $response['response'] ?? 'Unknown error',
					'user_message'  => substr( $message, 0, 200 ),
					'session_id'    => $session_id,
				)
			);
			// User-facing errors (quota limits, free tier) are shown as-is.
			// Technical errors are replaced with a safe generic message.
			if ( empty( $response['user_facing'] ) ) {
				$response['response'] = __( 'Something went wrong. The issue has been reported to your site administrator.', 'agent-builder' );
			} else {
				// Notify admin that a user hit their quota (throttled to once per day).
				self::maybe_notify_admin_quota_reached( $user_id );
			}
		}

		// Add PII warning to response if detected (non-blocking).
		if ( ! empty( $security_result['pii_warning'] ) ) {
			$response['pii_warning'] = $security_result['pii_warning'];
		}

		// SSE streaming — emit 'end' event with full metadata and exit.
		if ( $is_stream ) {
			$end_payload = array(
				'type'             => 'end',
				'response'         => $response['response'] ?? '',
				'agent_id'         => $response['agent_id'] ?? $agent_id,
				'agent_name'       => $response['agent_name'] ?? '',
				'session_id'       => $session_id,
				'tokens_used'      => $response['tokens_used'] ?? 0,
				'cost'             => $response['cost'] ?? 0.0,
				'tools_used'       => $response['tools_used'] ?? array(),
				'iterations'       => $response['iterations'] ?? 0,
				'reasoning'        => $response['reasoning'] ?? '',
				'error'            => ! empty( $response['error'] ),
				// Surface a pending confirmation so the chat renders approve/reject
				// buttons in streaming mode (parity with the non-streaming path).
				'pending_proposal' => ! empty( $response['pending_proposal'] ),
				'proposal'         => $response['proposal'] ?? null,
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo 'data: ' . wp_json_encode( $end_payload ) . "\n\n";
			flush();
			exit;
		}

		// Return appropriate HTTP status for errors.
		if ( ! empty( $response['error'] ) ) {
			$http_status = ! empty( $response['user_facing'] ) ? 429 : 500;
		} else {
			$http_status = 200;
		}

		$rest_response = new \WP_REST_Response( $response, $http_status );

		// Expose rate-limit state so clients can throttle themselves (L7).
		$rl = \Agentic\Chat_Security::get_rate_limit_headers( $user_id );
		$rest_response->header( 'X-RateLimit-Limit', (string) $rl['limit'] );
		$rest_response->header( 'X-RateLimit-Remaining', (string) $rl['remaining'] );
		$rest_response->header( 'X-RateLimit-Reset', (string) $rl['reset'] );

		return $rest_response;
	}

	/**
	 * Get list of chat sessions for the current user
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_sessions( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$user_id  = get_current_user_id();
		$agent_id = $request->get_param( 'agent_id' );
		$limit    = min( (int) $request->get_param( 'limit' ), 100 );

		$history_days_limit = null;

		$conv_table = $wpdb->prefix . 'agentic_conversations';

		// Use the dedicated conversations table when available.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, checked per-request.
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_table ) );

		if ( $table_exists ) {
			$where = $wpdb->prepare( 'WHERE user_id = %d AND role = %s', $user_id, 'user' );
			if ( $agent_id ) {
				$where .= $wpdb->prepare( ' AND agent_id = %s', $agent_id );
			}
			if ( $history_days_limit ) {
				$where .= $wpdb->prepare( ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $history_days_limit );
			}

			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE clause built with prepare() above.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT session_id, agent_id, content, created_at FROM {$conv_table} {$where} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				),
				ARRAY_A
			);

			$sessions = array();
			$seen     = array();
			foreach ( $rows as $row ) {
				$sid = $row['session_id'];
				if ( isset( $seen[ $sid ] ) ) {
					continue;
				}
				$seen[ $sid ] = true;
				$sessions[]   = array(
					'session_id' => $sid,
					'agent_id'   => $row['agent_id'],
					'preview'    => mb_substr( $row['content'], 0, 100 ),
					'created_at' => $row['created_at'],
				);
			}

			if ( ! empty( $sessions ) ) {
				return new \WP_REST_Response(
					array(
						'sessions'           => $sessions,
						'history_days_limit' => $history_days_limit,
					),
					200
				);
			}
		}

		// Legacy fallback: read from audit log (pre-conversations-table sessions).
		$audit_table = $wpdb->prefix . 'agentic_audit_log';
		$where       = $wpdb->prepare( 'WHERE user_id = %d AND action = %s', $user_id, 'chat_start' );
		if ( $agent_id ) {
			$where .= $wpdb->prepare( ' AND agent_id = %s', $agent_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent_id, details, created_at FROM {$audit_table} {$where} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		$sessions = array();
		$seen     = array();
		foreach ( $rows as $row ) {
			$details = json_decode( $row['details'], true );
			$sid     = $details['session_id'] ?? '';
			if ( ! $sid || isset( $seen[ $sid ] ) ) {
				continue;
			}
			$seen[ $sid ] = true;
			$sessions[]   = array(
				'session_id' => $sid,
				'agent_id'   => $row['agent_id'],
				'preview'    => mb_substr( $details['message'] ?? '', 0, 100 ),
				'created_at' => $row['created_at'],
			);
		}

		return new \WP_REST_Response( array( 'sessions' => $sessions ), 200 );
	}

	/**
	 * Get conversation history for a specific session
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_history( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		$user_id    = get_current_user_id();
		$is_admin   = current_user_can( 'manage_options' );
		$conv_table = $wpdb->prefix . 'agentic_conversations';

		// Use dedicated conversations table when results exist for this session.
		$history = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, checked per-request.
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_table ) );

		if ( $table_exists ) {
			// Admins can view any user's conversation; regular users only see their own.
			if ( $is_admin ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT role, content, tools_used, feedback, created_at FROM {$conv_table} WHERE session_id = %s ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$session_id
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT role, content, tools_used, feedback, created_at FROM {$conv_table} WHERE session_id = %s AND user_id = %d ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$session_id,
						$user_id
					),
					ARRAY_A
				);
			}
			foreach ( $rows as $row ) {
				$entry = array(
					'role'    => $row['role'],
					'content' => $row['content'],
					'time'    => $row['created_at'],
				);
				if ( ! empty( $row['tools_used'] ) ) {
					$entry['tools_used'] = json_decode( $row['tools_used'], true ) ?? array();
				}
				if ( null !== $row['feedback'] ) {
					$entry['feedback'] = (int) $row['feedback'];
				}
				$history[] = $entry;
			}
		}

		if ( empty( $history ) ) {
			// Legacy fallback: reconstruct history from audit log entries.
			$audit_table = $wpdb->prefix . 'agentic_audit_log';
			if ( $is_admin ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT action, details, created_at FROM {$audit_table} WHERE action IN ('chat_start','chat_complete') AND details LIKE %s ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'%' . $wpdb->esc_like( $session_id ) . '%'
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT action, details, created_at FROM {$audit_table} WHERE user_id = %d AND action IN ('chat_start','chat_complete') AND details LIKE %s ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$user_id,
						'%' . $wpdb->esc_like( $session_id ) . '%'
					),
					ARRAY_A
				);
			}
			foreach ( $rows as $row ) {
				$details = json_decode( $row['details'], true );
				if ( ( $details['session_id'] ?? '' ) !== $session_id ) {
					continue;
				}
				if ( 'chat_start' === $row['action'] ) {
					$history[] = array(
						'role'    => 'user',
						'content' => $details['message'] ?? '',
						'time'    => $row['created_at'],
					);
				} elseif ( 'chat_complete' === $row['action'] ) {
					$history[] = array(
						'role'       => 'assistant',
						'content'    => $details['response'] ?? '',
						'tools_used' => $details['tools_used'] ?? array(),
						'time'       => $row['created_at'],
					);
				}
			}
		}

		// Rich trace for impressive Conversations UI (P0 observability).
		$trace       = array();
		$audit_table = $wpdb->prefix . 'agentic_audit_log';

		// Pull decision + completion events that carry reasoning and tool choices for this session.
		// These power the beautiful per-turn reasoning trace in the admin Conversations drawer.
		if ( $is_admin ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query for admin trace view.
			$trace_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action, details, reasoning, tokens_used, cost, provider, created_at FROM %i WHERE (action IN ('tool_choice', 'chat_complete', 'autonomous_chat_complete') AND details LIKE %s) ORDER BY created_at ASC, id ASC LIMIT 50",
					$audit_table,
					'%' . $wpdb->esc_like( $session_id ) . '%'
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query for admin trace view.
			$trace_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action, details, reasoning, tokens_used, cost, provider, created_at FROM %i WHERE user_id = %d AND (action IN ('tool_choice', 'chat_complete', 'autonomous_chat_complete') AND details LIKE %s) ORDER BY created_at ASC, id ASC LIMIT 50",
					$audit_table,
					$user_id,
					'%' . $wpdb->esc_like( $session_id ) . '%'
				),
				ARRAY_A
			);
		}

		foreach ( $trace_rows as $row ) {
			$decoded = json_decode( $row['details'], true );
			$details = is_array( $decoded ) ? $decoded : array();
			if ( ( $details['session_id'] ?? '' ) !== $session_id && strpos( $row['details'], $session_id ) === false ) {
				continue;
			}
			$trace[] = array(
				'action'     => $row['action'],
				'reasoning'  => ! empty( $row['reasoning'] ) ? $row['reasoning'] : '',
				'tokens'     => (int) ( $row['tokens_used'] ?? 0 ),
				'cost'       => (float) ( $row['cost'] ?? 0 ),
				'provider'   => ! empty( $row['provider'] ) ? $row['provider'] : '',
				'time'       => $row['created_at'],
				'tools'      => ! empty( $details['tools'] ) ? $details['tools'] : ( ! empty( $details['tools_used'] ) ? $details['tools_used'] : array() ),
				'iterations' => ! empty( $details['iterations'] ) ? $details['iterations'] : null,
			);
		}

		return new \WP_REST_Response(
			array(
				'session_id' => $session_id,
				'history'    => $history,
				'trace'      => $trace,   // Rich reasoning + decision data for impressive UI.
			),
			200
		);
	}

	/**
	 * Get agent status
	 *
	 * @param \WP_REST_Request $_request Request object (unused - no parameters needed).
	 * @return \WP_REST_Response
	 */
	public function get_status( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$llm = new LLM_Client();

		$active_slugs = get_option( 'agentic_active_agents', array() );
		if ( ! is_array( $active_slugs ) ) {
			$active_slugs = array();
		}

		return new \WP_REST_Response(
			array(
				'version'       => AGENT_BUILDER_VERSION,
				'configured'    => $llm->is_configured(),
				'provider'      => $llm->get_provider(),
				'model'         => $llm->get_model(),
				'mode'          => get_option( 'agentic_agent_mode', 'supervised' ),
				'active_agents' => array_values( $active_slugs ),
				'capabilities'  => array(
					'chat'         => true,
					'read_files'   => true,
					'search_code'  => true,
					'update_docs'  => true,
					'code_changes' => 'approval_required',
				),
			),
			200
		);
	}

	/**
	 * Get pending approvals
	 *
	 * @param \WP_REST_Request $_request Request object (unused - no parameters needed).
	 * @return \WP_REST_Response
	 */
	public function get_approvals( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		$approvals = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agentic_approval_queue WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50",
			ARRAY_A
		);

		foreach ( $approvals as &$approval ) {
			$approval['params'] = json_decode( $approval['params'], true );
		}

		return $this->api_success( array( 'approvals' => $approvals ) );
	}

	/**
	 * Handle approval action
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_approval( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$id     = (int) $request->get_param( 'id' );
		$action = $request->get_param( 'action' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
		$approval = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agentic_approval_queue WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $approval ) {
			return $this->api_error( 'not_found', 'Approval not found', 404 );
		}

		if ( 'pending' !== $approval['status'] ) {
			return $this->api_error( 'already_processed', 'Approval already processed' );
		}

		$new_status   = 'approve' === $action ? 'approved' : 'rejected';
		$action_label = str_replace( '_', ' ', (string) ( $approval['action'] ?? 'action' ) );
		$agent_label  = str_replace( '-', ' ', (string) ( $approval['agent_id'] ?? '' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update.
		$wpdb->update(
			$wpdb->prefix . 'agentic_approval_queue',
			array(
				'status'      => $new_status,
				'approved_by' => get_current_user_id(),
				'approved_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);

		$execution = null;
		if ( 'approve' === $action ) {
			$execution = $this->execute_approved_action( $approval );
			$queue     = new Approval_Queue();
			// Only mark executed when the tool actually ran (or code write succeeded).
			if ( ! empty( $execution['ran'] ) ) {
				$queue->mark_executed( $id );
			}
		}

		$audit = new Audit_Log();
		$audit->log( 'human', "approval_{$new_status}", 'approval', array( 'request_id' => $id ) );

		$ok_message = 'reject' === $action
			? sprintf(
				/* translators: %s: action name */
				__( 'Rejected — “%s” will not run.', 'agent-builder' ),
				$action_label
			)
			: sprintf(
				/* translators: 1: action name, 2: agent name */
				__( 'Approved “%1$s” for %2$s.', 'agent-builder' ),
				$action_label,
				$agent_label ? $agent_label : __( 'agent', 'agent-builder' )
			);

		return $this->api_success(
			array(
				'status'       => $new_status,
				'id'           => $id,
				'action'       => (string) ( $approval['action'] ?? '' ),
				'action_label' => $action_label,
				'agent_id'     => (string) ( $approval['agent_id'] ?? '' ),
				'message'      => $ok_message,
				'execution'    => $execution,
			)
		);
	}

	/**
	 * Handle a user-space proposal (once / session / always / reject).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_proposal( \WP_REST_Request $request ): \WP_REST_Response {
		$proposal_id = sanitize_text_field( $request->get_param( 'id' ) );
		$action      = $request->get_param( 'action' );
		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		// A proposal is a personal in-chat confirmation, not an admin queue item —
		// only the user it was created for (or an administrator) may act on it.
		// The route itself is open to anyone who can chat, so this ownership check
		// is what stops one logged-in user from resolving another user's proposal.
		$owned_proposal = Agent_Proposals::get( $proposal_id );
		if ( $owned_proposal
			&& (int) ( $owned_proposal['created_by'] ?? 0 ) !== get_current_user_id()
			&& ! current_user_can( 'manage_options' )
		) {
			return new \WP_REST_Response( array( 'error' => 'Insufficient permissions.' ), 403 );
		}

		if ( 'reject' === $action ) {
			$result = Agent_Proposals::reject( $proposal_id );
			if ( ! empty( $result['error'] ) ) {
				return new \WP_REST_Response( $result, 400 );
			}
			return new \WP_REST_Response( $result, 200 );
		}

		// For session / always grants, record the grant before executing.
		if ( 'always' === $action ) {
			// Server-side cap: only admins may persist always-allow grants.
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_REST_Response( array( 'error' => 'Insufficient permissions.' ), 403 );
			}
			$proposal = Agent_Proposals::get( $proposal_id );
			if ( ! $proposal ) {
				return new \WP_REST_Response( array( 'error' => 'Proposal not found or expired.' ), 400 );
			}
			$user_id       = get_current_user_id();
			$always_grants = get_user_meta( $user_id, 'agentic_tool_grants_always', true );
			if ( ! is_array( $always_grants ) ) {
				$always_grants = array();
			}
			if ( ! in_array( $proposal['tool'], $always_grants, true ) ) {
				$always_grants[] = $proposal['tool'];
				update_user_meta( $user_id, 'agentic_tool_grants_always', $always_grants );
			}
		} elseif ( 'session' === $action && '' !== $session_id ) {
			$proposal = Agent_Proposals::get( $proposal_id );
			if ( ! $proposal ) {
				return new \WP_REST_Response( array( 'error' => 'Proposal not found or expired.' ), 400 );
			}
			$transient_key  = 'agentic_session_grants_' . sanitize_key( $session_id );
			$session_grants = get_transient( $transient_key );
			if ( ! is_array( $session_grants ) ) {
				$session_grants = array();
			}
			if ( ! in_array( $proposal['tool'], $session_grants, true ) ) {
				$session_grants[] = $proposal['tool'];
			}
			set_transient( $transient_key, $session_grants, DAY_IN_SECONDS );
		}

		// 'once' falls through straight to approve; session/always also approve after storing grant.
		$result = Agent_Proposals::approve( $proposal_id );

		if ( ! empty( $result['error'] ) ) {
			return new \WP_REST_Response( $result, 400 );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Revoke an always-allow tool grant for the current admin user.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_tool_grant_revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$tool_name     = sanitize_key( $request->get_param( 'tool' ) );
		$user_id       = get_current_user_id();
		$always_grants = get_user_meta( $user_id, 'agentic_tool_grants_always', true );

		if ( is_array( $always_grants ) ) {
			$always_grants = array_values( array_diff( $always_grants, array( $tool_name ) ) );
			update_user_meta( $user_id, 'agentic_tool_grants_always', $always_grants );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Execute an approved action and return a structured outcome for the UI.
	 *
	 * SECURITY: Git commit operations disabled. File changes are written but not auto-committed.
	 * Administrators should commit changes manually via a secure terminal.
	 *
	 * @param array $approval Approval record.
	 * @return array{ran:bool,success:bool,message:string,detail?:string,result?:mixed}
	 */
	private function execute_approved_action( array $approval ): array {
		$params    = json_decode( $approval['params'], true );
		$tool_name = $approval['action'];
		$label     = str_replace( '_', ' ', (string) $tool_name );

		// Special handling for code_change actions (file writes).
		if ( 'code_change' === $tool_name && ! empty( $params['path'] ) ) {
			$repo_path      = Tool_Helpers::get_allowed_repo_base();
			$target_subpath = ltrim( str_replace( '..', '', $params['path'] ), '/\\' );

			if ( ! Tool_Helpers::is_allowed_subpath( $target_subpath ) ) {
				return array(
					'ran'     => false,
					'success' => false,
					'message' => __( 'Could not write file — path is not allowed.', 'agent-builder' ),
				);
			}

			$full_path = realpath( $repo_path . '/' . $target_subpath );

			if ( ! $full_path || ! str_starts_with( $full_path, trailingslashit( realpath( $repo_path ) ) ) ) {
				return array(
					'ran'     => false,
					'success' => false,
					'message' => __( 'Could not write file — invalid path.', 'agent-builder' ),
				);
			}

			if ( ! empty( $params['content'] ) ) {
				$dir = dirname( $full_path );
				if ( File_Manager::is_writable( $dir ) ) {
					$written = File_Manager::put_contents( $full_path, $params['content'] );
					if ( false === $written ) {
						return array(
							'ran'     => true,
							'success' => false,
							'message' => __( 'File write failed.', 'agent-builder' ),
							'detail'  => $target_subpath,
						);
					}
					return array(
						'ran'     => true,
						'success' => true,
						'message' => __( 'File updated successfully.', 'agent-builder' ),
						'detail'  => $target_subpath,
					);
				}
			}
			return array(
				'ran'     => false,
				'success' => false,
				'message' => __( 'File was not writable.', 'agent-builder' ),
			);
		}

		// Generic tool execution — run the tool directly with stored params.
		$tool_loader = Tool_Loader::get_instance();
		$tool        = $tool_loader->get( $tool_name );
		$safe_params = is_array( $params ) ? $params : array();

		try {
			if ( $tool ) {
				$result = $tool->execute( $safe_params );
			} else {
				// Try agent-inline tools (defined in the agent's execute_tool() method).
				$agent_id = $approval['agent_id'] ?? '';
				$registry = \Agentic_Agent_Registry::get_instance();
				$agent    = $registry->get_agent_instance( $agent_id );
				$result   = $agent ? $agent->execute_tool( $tool_name, $safe_params ) : null;

				if ( null === $result ) {
					return array(
						'ran'     => false,
						'success' => false,
						/* translators: %s: tool name */
						'message' => sprintf( __( 'Tool “%s” is not available to run.', 'agent-builder' ), $label ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			\Agentic\Security_Log::log_system(
				'approval_execution_exception',
				'approvals',
				array(
					'approval_id' => $approval['id'] ?? '',
					'tool'        => $tool_name,
					'agent_id'    => $approval['agent_id'] ?? '',
					'error'       => $e->getMessage(),
					'file'        => $e->getFile() . ':' . $e->getLine(),
				)
			);
			return array(
				'ran'     => true,
				'success' => false,
				/* translators: %s: tool name */
				'message' => sprintf( __( '“%s” failed while running.', 'agent-builder' ), $label ),
				'detail'  => $e->getMessage(),
			);
		}

		$success = true;
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) ) {
				$success = (bool) $result['success'];
			} elseif ( isset( $result['error'] ) && $result['error'] ) {
				$success = false;
			}
		}

		$detail = '';
		if ( is_array( $result ) ) {
			if ( ! empty( $result['message'] ) && is_string( $result['message'] ) ) {
				$detail = $result['message'];
			} elseif ( ! empty( $result['error'] ) && is_string( $result['error'] ) ) {
				$detail = $result['error'];
			} elseif ( isset( $result['post_id'] ) ) {
				$detail = sprintf(
					/* translators: %d: post ID */
					__( 'Post ID %d', 'agent-builder' ),
					(int) $result['post_id']
				);
			}
		}

		$audit = new Audit_Log();
		$audit->log(
			$approval['agent_id'],
			'tool_executed_on_approval',
			$tool_name,
			array(
				'approval_id' => $approval['id'],
				'success'     => $success,
			)
		);

		return array(
			'ran'     => true,
			'success' => $success,
			'message' => $success
				? sprintf(
					/* translators: %s: tool name */
					__( '“%s” completed successfully.', 'agent-builder' ),
					$label
				)
				: sprintf(
					/* translators: %s: tool name */
					__( '“%s” ran but reported a problem.', 'agent-builder' ),
					$label
				),
			'detail'  => $detail,
			'result'  => is_array( $result ) ? self::summarize_tool_result( $result ) : $result,
		);
	}

	/**
	 * Cap tool result size for the admin UI.
	 *
	 * @param array<string,mixed> $result Tool result.
	 * @return array<string,mixed>
	 */
	private static function summarize_tool_result( array $result ): array {
		$json = wp_json_encode( $result );
		if ( is_string( $json ) && strlen( $json ) > 800 ) {
			return array(
				'_truncated' => true,
				'preview'    => substr( $json, 0, 800 ) . '…',
			);
		}
		return $result;
	}

	/**
	 * Test an API key with the LLM provider
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function test_api_key( \WP_REST_Request $request ): \WP_REST_Response {
		$provider   = sanitize_text_field( $request->get_param( 'provider' ) );
		$api_key    = sanitize_text_field( $request->get_param( 'api_key' ) ?? '' );
		$model      = sanitize_text_field( $request->get_param( 'model' ) ?? '' );
		$ollama_url = esc_url_raw( $request->get_param( 'ollama_url' ) ?? '' );

		if ( empty( $provider ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Provider is required.',
				),
				400
			);
		}

		// Ollama and Agentic AI don't require a user-supplied API key.
		if ( ! in_array( $provider, array( 'ollama', 'agentic' ), true ) && empty( $api_key ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'API key is required.',
				),
				400
			);
		}

		// For Ollama, temporarily override the stored URL if a URL was passed.
		if ( 'ollama' === $provider && ! empty( $ollama_url ) ) {
			update_option( 'agentic_ollama_url', $ollama_url );
		}

		// Create a temporary LLM_Client with the test values.
		$llm = new LLM_Client();

		// Test by making a simple API call.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, please respond with OK.',
			),
		);

		// Temporarily override the provider and API key for testing.
		try {
			// For Agentic AI and Ollama, a lightweight GET health/tags check is faster
			// and more reliable than a full chat completion round-trip.
			if ( in_array( $provider, array( 'agentic', 'ollama' ), true ) ) {
				if ( 'agentic' === $provider ) {
					$tags_url = Service_Registry::url( 'agentic-chat', '/health' );
				} else {
					$tags_url = rtrim( get_option( 'agentic_ollama_url', 'http://localhost:11434' ), '/' ) . '/api/tags';
				}
				$headers  = array( 'Content-Type' => 'application/json' );
				$response = wp_remote_get(
					$tags_url,
					array(
						'timeout' => 10,
						'headers' => $headers,
					)
				);
				if ( is_wp_error( $response ) ) {
					return new \WP_REST_Response(
						array(
							'success' => false,
							'message' => 'Connection failed: ' . $response->get_error_message(),
						),
						400
					);
				}
				$status = wp_remote_retrieve_response_code( $response );
				if ( $status >= 200 && $status < 300 ) {
					return new \WP_REST_Response(
						array(
							'success' => true,
							'message' => 'API key is valid and working!',
						),
						200
					);
				}
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => sprintf( 'Connection failed (HTTP %d).', $status ),
					),
					$status
				);
			}

			$response = wp_remote_post(
				$llm->get_endpoint_for_provider( $provider ),
				array(
					'timeout' => 15,
					'headers' => $llm->get_headers_for_provider( $provider, $api_key ),
					'body'    => wp_json_encode( $llm->format_request_for_provider( $provider, $messages, $model ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Connection failed: ' . $response->get_error_message(),
					),
					400
				);
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $status || 201 === $status ) {
				return new \WP_REST_Response(
					array(
						'success' => true,
						'message' => 'API key is valid and working!',
					),
					200
				);
			} else {
				$error_msg = $body['error']['message'] ?? $body['error'] ?? 'Unknown error';
				if ( is_array( $error_msg ) ) {
					$error_msg = wp_json_encode( $error_msg );
				}
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => 'API Error: ' . $error_msg,
						'status'  => $status,
					),
					$status
				);
			}
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Test failed: ' . $e->getMessage(),
				),
				400
			);
		}
	}

	/**
	 * Check if user is logged in and has permission to use agent chat.
	 *
	 * Enforces User Roles settings for frontend and admin-bar chat.
	 * When the allow_anonymous_chat option is enabled, anonymous users
	 * are permitted (subject to Turnstile, rate limits, and daily caps).
	 *
	 * @return bool
	 */
	public function check_logged_in(): bool {
		if ( ! is_user_logged_in() ) {
			return (bool) get_option( 'agentic_allow_anonymous_chat', false );
		}
		return \Agentic\User_Roles::current_user_can( 'chat_frontend' )
			|| \Agentic\User_Roles::current_user_can( 'chat_admin_bar' );
	}

	/**
	 * Same as check_logged_in(), but never allows the anonymous-chat carve-out.
	 *
	 * For any route that identifies "my" data by the current user_id — e.g.
	 * listing a user's own session history — anonymous access is unsafe:
	 * get_current_user_id() is 0 for every anonymous visitor, so "my sessions"
	 * would mean "every anonymous visitor's sessions" with no real per-visitor
	 * identity to scope by.
	 *
	 * @return bool
	 */
	public function check_real_user(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return \Agentic\User_Roles::current_user_can( 'chat_frontend' )
			|| \Agentic\User_Roles::current_user_can( 'chat_admin_bar' );
	}

	/**
	 * Check if the user can manage the admin approval queue.
	 *
	 * Same capability the Approvals admin page is registered under
	 * (agentic_manage_agents), so a role granted that privilege via
	 * Settings > Users can actually act on it, not just view the page.
	 *
	 * @return bool
	 */
	public function check_manage_agents(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'agentic_manage_agents' );
	}

	/**
	 * Check if user is admin
	 *
	 * @return bool
	 */
	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Notify the admin when a user hits their free quota limit.
	 *
	 * Throttled to one notification per day via a transient. Sets an admin
	 * notice transient and sends a branded email to the site admin.
	 *
	 * @param int $wp_user_id The WordPress user ID who hit the limit (0 for anonymous).
	 */
	private static function maybe_notify_admin_quota_reached( int $wp_user_id ): void {
		$transient_key = 'agentic_quota_notify_' . gmdate( 'Y-m-d' );

		// Already notified today.
		if ( get_transient( $transient_key ) ) {
			return;
		}

		// Mark as notified (expires end of day).
		set_transient( $transient_key, true, DAY_IN_SECONDS );

		// Set admin banner notice (shown once).
		set_transient( 'agentic_quota_reached_notice', true, 3 * DAY_IN_SECONDS );

		// Build email.
		$site         = get_bloginfo( 'name' );
		$admin_email  = get_option( 'admin_email' );
		$user_label   = $wp_user_id > 0
			? get_userdata( $wp_user_id )->display_name ?? "User #{$wp_user_id}"
			: 'An anonymous visitor';
		$settings_url = admin_url( 'admin.php?page=agentic-settings' );

		$subject = sprintf( '[%s] Agent Builder — AI Rate Limit Reached', $site );

		$body  = '<p style="margin:0 0 16px;">A user on <strong>' . esc_html( $site ) . '</strong> has hit a rate limit with your configured AI provider.</p>';
		$body .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px;">';
		$body .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;color:#1d2327;">User</td><td style="padding:4px 0;">' . esc_html( $user_label ) . '</td></tr>';
		$body .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;color:#1d2327;">Date</td><td style="padding:4px 0;">' . esc_html( wp_date( 'F j, Y' ) ) . '</td></tr>';
		$body .= '</table>';
		$body .= '<p style="margin:0 0 8px;">To keep the experience seamless, consider switching AI providers or reviewing your current provider\'s plan in Settings.</p>';
		$body .= '<p style="margin:0;font-size:12px;color:#787c82;">This notification is sent at most once per day. Manage your AI provider in <a href="' . esc_url( $settings_url ) . '" style="color:#787c82;">Agent Builder → Settings</a>.</p>';

		Email_Helper::send(
			$admin_email,
			$subject,
			array(
				'heading' => 'AI Rate Limit Reached',
				'body'    => $body,
				'footer'  => 'Automated notification from ' . $site,
			)
		);
	}

	/**
	 * Insert a single conversation turn into the dedicated conversations table.
	 *
	 * Silently no-ops if the table does not exist (pre-migration installs).
	 *
	 * @param string $session_id Session UUID.
	 * @param int    $user_id    WordPress user ID.
	 * @param string $agent_id   Agent slug.
	 * @param string $role       'user' or 'assistant'.
	 * @param string $content    Message content.
	 * @param array  $tools_used Optional list of tool names used in this turn.
	 * @return void
	 */
	private function log_conversation_turn( string $session_id, int $user_id, string $agent_id, string $role, string $content, array $tools_used = array() ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agentic_conversations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
		$wpdb->insert(
			$table,
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'agent_id'   => sanitize_key( $agent_id ),
				'role'       => in_array( $role, array( 'user', 'assistant' ), true ) ? $role : 'user',
				'content'    => $content,
				'tools_used' => ! empty( $tools_used ) ? wp_json_encode( $tools_used ) : '',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Save a base64-encoded image as a temporary file in uploads and return its public URL.
	 *
	 * The file is placed in /uploads/agentic-tmp/ and prefixed with a unique ID.
	 * Temporary files older than 1 hour are cleaned up on each call.
	 *
	 * @param string $base64    Base64-encoded image data (no header/prefix).
	 * @param string $mime_type MIME type (e.g., 'image/png').
	 * @return string|false Public URL on success, false on failure.
	 */
	private function save_temp_image( string $base64, string $mime_type ): string|false {
		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		$ext     = $ext_map[ $mime_type ] ?? 'png';

		$upload_dir = wp_upload_dir();
		$tmp_dir    = $upload_dir['basedir'] . '/agentic-tmp';
		$tmp_url    = $upload_dir['baseurl'] . '/agentic-tmp';

		// Ensure directory exists.
		if ( ! is_dir( $tmp_dir ) ) {
			wp_mkdir_p( $tmp_dir );
			// Add an index.php to prevent directory listing.
			file_put_contents( $tmp_dir . '/index.php', "<?php\n/**\n * Silence is golden.\n *\n * @package Agent_Builder\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			// Deny direct HTTP access to protect against image hotlinking / directory browsing.
			file_put_contents( $tmp_dir . '/.htaccess', "Options -Indexes\ndeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Cleanup files older than 1 hour.
		$this->cleanup_temp_images( $tmp_dir );

		$filename = 'agentic-' . wp_generate_uuid4() . '.' . $ext;
		$filepath = $tmp_dir . '/' . $filename;

		$decoded = base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return false;
		}

		File_Manager::put_contents( $filepath, $decoded );

		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		return $tmp_url . '/' . $filename;
	}

	/**
	 * Fetch available models for a given provider using its stored API key.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_provider_models( \WP_REST_Request $request ): \WP_REST_Response {
		$provider                = sanitize_text_field( $request->get_param( 'provider' ) );
				$provider_record = \Agentic\Provider_Registry::get( $provider );
				$api_key         = $provider_record['api_key'] ?? '';

		if ( empty( $api_key ) && ! in_array( $provider, array( 'ollama', 'agentic' ), true ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		switch ( $provider ) {
			case 'openai':
				return $this->fetch_openai_models( $api_key );
			case 'anthropic':
				return $this->fetch_anthropic_models( $api_key );
			case 'xai':
				return $this->fetch_xai_models( $api_key );
			case 'mistral':
				return $this->fetch_mistral_models( $api_key );
			case 'google':
				return $this->fetch_google_models( $api_key );
			case 'ollama':
				return $this->fetch_ollama_models();
			case 'llama':
				return $this->fetch_llama_models( $api_key );
			case 'cohere':
				return $this->fetch_cohere_models( $api_key );
			case 'agentic':
				return $this->fetch_agentic_models();
			default:
				return new \WP_REST_Response(
					array(
						'success' => false,
						'models'  => array(),
					),
					200
				);
		}
	}

	/**
	 * Fetch models from Anthropic.
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_anthropic_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $model['display_name'] ?? $id,
				'vision' => str_contains( $id, 'claude-3' ) || str_contains( $id, 'claude-opus' ) || str_contains( $id, 'claude-sonnet' ) || str_contains( $id, 'claude-haiku' ),
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch models from OpenAI.
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_openai_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		$skip   = array( 'dall-e', 'whisper', 'tts', 'text-embedding', 'text-moderation', 'audio', 'realtime', 'transcribe', 'babbage', 'davinci', 'curie', 'ada-', 'computer-use', 'omni-mini', 'search' );

		foreach ( $data['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( empty( $id ) || str_starts_with( $id, 'ft:' ) ) {
				continue;
			}
			$excluded = false;
			foreach ( $skip as $pattern ) {
				if ( str_contains( $id, $pattern ) ) {
					$excluded = true;
					break;
				}
			}
			if ( $excluded ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => $this->model_supports_vision( $id ),
			);
		}

		usort(
			$models,
			function ( $a, $b ) {
				// Sort: undated aliases before dated snapshots, then reverse-alpha.
				$a_dated = (bool) preg_match( '/\d{4}-\d{2}-\d{2}/', $a['id'] );
				$b_dated = (bool) preg_match( '/\d{4}-\d{2}-\d{2}/', $b['id'] );
				if ( $a_dated !== $b_dated ) {
					return $a_dated ? 1 : -1;
				}
				return strcmp( $b['id'], $a['id'] );
			}
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch models from xAI.
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_xai_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://api.x.ai/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => str_contains( $id, 'vision' ),
			);
		}

		usort( $models, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch models from Mistral AI.
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_mistral_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://api.mistral.ai/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( empty( $id ) || str_contains( $id, 'embed' ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => str_contains( $id, 'pixtral' ) || str_contains( $id, 'vision' ),
			);
		}

		usort( $models, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch models from Google Gemini.
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_google_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $api_key ),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['models'] ?? array() as $model ) {
			$name    = $model['name'] ?? '';
			$methods = $model['supportedGenerationMethods'] ?? array();
			if ( ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$id = str_replace( 'models/', '', $name );
			if ( ! str_starts_with( $id, 'gemini-' ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => true, // All recent Gemini models support vision.
			);
		}

		usort( $models, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch locally running Ollama models.
	 */
	private function fetch_ollama_models(): \WP_REST_Response {
		$response = wp_remote_get(
			'http://localhost:11434/api/tags',
			array( 'timeout' => 5 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['models'] ?? array() as $model ) {
			$id = $model['name'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => false,
			);
		}

		return new \WP_REST_Response(
			empty( $models )
				? array(
					'success' => false,
					'models'  => array(),
				)
				: array(
					'success' => true,
					'models'  => $models,
				),
			200
		);
	}

	/**
	 * Fetch models from Meta Llama API (OpenAI-compatible).
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_llama_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://api.llama.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => str_contains( strtolower( $id ), 'vision' ),
			);
		}

		usort( $models, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Fetch models from Cohere AI (v1 models endpoint, filter for chat capability).
	 *
	 * @param string $api_key Provider API key.
	 * @return \WP_REST_Response
	 */
	private function fetch_cohere_models( string $api_key ): \WP_REST_Response {
		$response = wp_remote_get(
			'https://api.cohere.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'models'  => array(),
				),
				200
			);
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		foreach ( $data['models'] ?? array() as $model ) {
			$id        = $model['name'] ?? '';
			$endpoints = $model['endpoints'] ?? array();
			// Only include models that support the chat endpoint.
			if ( empty( $id ) || ! in_array( 'chat', $endpoints, true ) ) {
				continue;
			}
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => false,
			);
		}

		usort( $models, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Return Agentic AI models from the provider table (single source of truth).
	 */
	private function fetch_agentic_models(): \WP_REST_Response {
		$provider = Provider_Registry::get( 'agentic' );
		$models   = array();

		foreach ( $provider['models'] ?? array() as $id ) {
			$models[] = array(
				'id'     => $id,
				'label'  => $id,
				'vision' => false,
			);
		}

		// Fallback: use the provider's default_model when the models list is empty.
		if ( empty( $models ) ) {
			$default  = $provider['default_model'] ?? 'gemini-2.5-flash';
			$models[] = array(
				'id'     => $default,
				'label'  => $default,
				'vision' => false,
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'models'  => $models,
			),
			200
		);
	}

	/**
	 * Handle TTS synthesis request.
	 *
	 * Proxies to tts.agentic-plugin.com and returns binary audio/mpeg directly,
	 * bypassing the WP_REST_Response JSON wrapper intentionally.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return void
	 */
	public function handle_tts( \WP_REST_Request $request ): void {
		$stored_key = (string) get_option( 'agentic_rag_api_secret', '' );
		$api_key    = $stored_key ? $stored_key : (string) ( \Agentic\Provider_Registry::get( 'agentic' )['api_key'] ?? '' );
		$user_id    = $api_key;

		if ( empty( $api_key ) ) {
			wp_send_json_error(
				array( 'error' => 'TTS requires connecting an Agentic AI account — go to Agent Builder → Settings → Providers and connect Agentic AI.' ),
				503
			);
			return;
		}

		$text  = (string) $request->get_param( 'text' );
		$voice = (string) $request->get_param( 'voice' );

		$response = wp_remote_post(
			Service_Registry::url( 'agentic-tts', '/synthesize' ),
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization'    => 'Bearer ' . $api_key,
					'Content-Type'     => 'application/json',
					'X-Plugin-Version' => AGENT_BUILDER_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'user_id'  => $user_id,
						'text'     => $text,
						'voice'    => $voice,
						'site_url' => home_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'error' => 'TTS service unavailable: ' . $response->get_error_message() ), 503 );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			$raw  = isset( $data['detail'] ) ? (string) $data['detail'] : 'TTS service error (HTTP ' . $code . ')';
			if ( 429 === (int) $code || 402 === (int) $code ) {
				// Pass through the server's error message; fall back to a generic one.
				$msg = ! empty( $raw ) ? $raw : __( 'Text-to-speech rate limit reached. Please try again later.', 'agent-builder' );
			} else {
				$msg = $raw;
			}
			wp_send_json_error(
				array(
					'error'       => $msg,
					'user_facing' => ( 429 === (int) $code || 402 === (int) $code ),
				),
				(int) $code
			);
			return;
		}

		$audio = wp_remote_retrieve_body( $response );
		if ( empty( $audio ) ) {
			wp_send_json_error( array( 'error' => 'TTS service returned no audio.' ), 502 );
			return;
		}

		// Output binary MP3 directly — bypasses WP REST JSON wrapper intentionally.
		header( 'Content-Type: audio/mpeg' );
		header( 'Content-Length: ' . strlen( $audio ) );
		header( 'Cache-Control: private, max-age=300' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary audio output.
		echo $audio;
		exit;
	}

	/**
	 * Determine if a model supports vision/image input based on its ID.
	 *
	 * @param string $id Model identifier.
	 * @return bool
	 */
	private function model_supports_vision( string $id ): bool {
		$patterns = array( 'vision', 'gpt-4o', 'gpt-4-turbo', 'claude-3', 'gemini', 'pixtral', 'grok-2-vision' );
		foreach ( $patterns as $pattern ) {
			if ( str_contains( $id, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove temp images older than 1 hour.
	 *
	 * @param string $dir Path to agentic-tmp directory.
	 * @return void
	 */
	private function cleanup_temp_images( string $dir ): void {
		$files = glob( $dir . '/agentic-*.{jpg,png,gif,webp}', GLOB_BRACE );
		if ( ! $files ) {
			return;
		}
		$cutoff = time() - HOUR_IN_SECONDS;
		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Restore a backup file to its original location.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_backup_restore( \WP_REST_Request $request ): \WP_REST_Response {
		$file   = sanitize_file_name( $request->get_param( 'file' ) );
		$result = Tool_Helpers::restore_backup( $file );

		if ( $result['success'] ) {
			( new Audit_Log() )->log( 'backup_restored', 'system', $file, array( 'original' => $result['original'] ?? '' ) );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * Delete a backup file.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_backup_delete( \WP_REST_Request $request ): \WP_REST_Response {
		$file       = sanitize_file_name( $request->get_param( 'file' ) );
		$backup_dir = AGENTIC_BACKUPS_DIR;
		$path       = $backup_dir . '/' . $file;

		if ( basename( $file ) !== $file || ! file_exists( $path ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Backup not found.',
				),
				404
			);
		}

		wp_delete_file( $path );

		( new Audit_Log() )->log( 'backup_deleted', 'system', $file );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Backup deleted.',
			)
		);
	}

	/**
	 * Restore a database table from a JSON backup.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_table_restore( \WP_REST_Request $request ): \WP_REST_Response {
		$file   = sanitize_file_name( $request->get_param( 'file' ) );
		$result = Tool_Helpers::restore_table_backup( $file );

		if ( $result['success'] ) {
			( new Audit_Log() )->log(
				'table_restored',
				'system',
				$file,
				array(
					'table'         => $result['table'] ?? '',
					'rows_restored' => $result['rows_restored'] ?? 0,
				)
			);
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * Delete a database table backup file.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_table_backup_delete( \WP_REST_Request $request ): \WP_REST_Response {
		$file       = sanitize_file_name( $request->get_param( 'file' ) );
		$backup_dir = AGENTIC_BACKUPS_DIR . '/' . Tool_Helpers::DB_BACKUP_SUBDIR;
		$path       = $backup_dir . '/' . $file;

		if ( basename( $file ) !== $file || ! file_exists( $path ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Backup not found.',
				),
				404
			);
		}

		wp_delete_file( $path );

		( new Audit_Log() )->log( 'table_backup_deleted', 'system', $file );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Table backup deleted.',
			)
		);
	}

	/**
	 * Handle conversation feedback (thumbs up / down).
	 *
	 * Updates the most recent assistant message for the given session_id.
	 * Requires the same permission as chat. Logged-in users may only rate
	 * their own sessions; anonymous ratings are allowed only when anonymous
	 * chat is enabled and the session belongs to user_id 0.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_feedback( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$session_id = $request->get_param( 'session_id' );
		$thumb      = $request->get_param( 'thumb' );
		$feedback   = 'up' === $thumb ? 1 : -1;
		$user_id    = get_current_user_id();

		$conv_table = $wpdb->prefix . 'agentic_conversations';

		// Find the most recent assistant row for this session owned by this user.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE session_id = %s AND role = %s AND user_id = %d ORDER BY id DESC LIMIT 1',
				$conv_table,
				$session_id,
				'assistant',
				$user_id
			)
		);

		if ( ! $row_id ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Not found.', 'agent-builder' ),
				),
				404
			);
		}

		$wpdb->update(
			$conv_table,
			array( 'feedback' => $feedback ),
			array( 'id' => $row_id ),
			array( '%d' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}
}
