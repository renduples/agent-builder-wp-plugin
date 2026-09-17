<?php
/**
 * GDPR Compliance
 *
 * Handles WordPress Privacy tools integration (data export / erasure),
 * automatic data-retention cleanup, and chat consent gate enforcement.
 *
 * Registered options:
 *  - agent_builder_ip_anonymize            bool   Hash IPs in security log (default true)
 *  - agent_builder_retention_conversations int    Days to keep chat history (0 = indefinitely)
 *  - agent_builder_retention_audit_log     int    Days to keep audit/security log (0 = indefinitely)
 *  - agent_builder_chat_consent_enabled    bool   Show consent notice before first chat message
 *  - agent_builder_chat_consent_text       string Text of consent notice
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.8.0
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GDPR compliance class.
 */
class GDPR {

	/** Cron hook for data retention cleanup. */
	const CRON_HOOK = 'agent_builder_gdpr_cleanup';

	/** Nonce action for consent dismissal. */
	const CONSENT_NONCE = 'agentic_consent';

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Register all hooks.
	 */
	public static function init(): void {
		// WordPress Privacy Tools integration.
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );

		// Suggested privacy policy content (admin-only — wp_add_privacy_policy_content requires is_admin()).
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		}

		// Daily retention cleanup cron.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Register suggested privacy policy content with WordPress.
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<h2>' . __( 'AI Chat & Agent Data', 'agent-builder' ) . '</h2>'
			. '<p>' . __( 'This site uses the Agent Builder plugin to provide AI-powered chat agents. When you interact with the chat interface, the following data is collected and processed:', 'agent-builder' ) . '</p>'
			. '<ul>'
			. '<li>' . __( '<strong>Chat messages</strong> — Your messages and AI responses are stored in our database for conversation history and audit purposes. Messages are automatically deleted after the configured retention period (default: 30 days).', 'agent-builder' ) . '</li>'
			. '<li>' . __( '<strong>User identity</strong> — If you are logged in, your WordPress user ID is associated with your chat sessions. Anonymous visitors are identified by session ID only.', 'agent-builder' ) . '</li>'
			. '<li>' . __( '<strong>Security events</strong> — IP addresses and security-related actions (login attempts, permission checks) may be logged. IP addresses are anonymized by default.', 'agent-builder' ) . '</li>'
			. '<li>' . __( '<strong>Audit trail</strong> — Actions performed by AI agents on your behalf (content edits, settings changes) are logged with your user ID for accountability.', 'agent-builder' ) . '</li>'
			. '</ul>'
			. '<h2>' . __( 'Third-Party AI Services', 'agent-builder' ) . '</h2>'
			. '<p>' . __( 'Chat messages are sent to the configured AI provider for processing. The site administrator chooses which provider is used. Possible providers include Agentic AI, OpenAI, Anthropic, Google, Mistral, and others. Each provider has its own privacy policy governing how your messages are processed.', 'agent-builder' ) . '</p>'
			. '<h2>' . __( 'Agentic Plugin Services', 'agent-builder' ) . '</h2>'
			. '<p>' . __( 'With user consent, the plugin can register for an API key with agentic-plugin.com. The following data is sent: site URL, site name, and admin email address. This data is used for API key provisioning and quota metering.', 'agent-builder' ) . '</p>'
			. '<h2>' . __( 'Cookies', 'agent-builder' ) . '</h2>'
			. '<p>' . __( 'The plugin sets two cookies on the frontend:', 'agent-builder' ) . '</p>'
			. '<ul>'
			. '<li>' . __( '<strong>agentic_consent_given</strong> — Records that you accepted the chat privacy notice (1 year).', 'agent-builder' ) . '</li>'
			. '<li>' . __( '<strong>agentic_last_agent</strong> — Remembers your last-used AI agent for convenience (1 year).', 'agent-builder' ) . '</li>'
			. '</ul>'
			. '<h2>' . __( 'Data Retention & Deletion', 'agent-builder' ) . '</h2>'
			. '<p>' . __( 'Chat history and audit logs are automatically deleted after 30 days (configurable by the site administrator). You can request export or deletion of your personal data through the WordPress privacy tools (Settings → Privacy).', 'agent-builder' ) . '</p>';

		wp_add_privacy_policy_content( 'Agent Builder', wp_kses_post( wpautop( $content, false ) ) );
	}

	// -------------------------------------------------------------------------
	// WordPress Privacy Tools – Exporters
	// -------------------------------------------------------------------------

	/**
	 * Register personal data exporters.
	 *
	 * @param array<int, array<string, mixed>> $exporters Existing exporters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_exporters( array $exporters ): array {
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Agent Builder – Chat History', 'agent-builder' ),
			'callback'               => array( __CLASS__, 'export_chat_history' ),
		);
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Agent Builder – Security Log', 'agent-builder' ),
			'callback'               => array( __CLASS__, 'export_security_log' ),
		);
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Agent Builder – Audit Log', 'agent-builder' ),
			'callback'               => array( __CLASS__, 'export_audit_log' ),
		);
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Agent Builder – Jobs', 'agent-builder' ),
			'callback'               => array( __CLASS__, 'export_jobs' ),
		);
		return $exporters;
	}

	/**
	 * Export chat history for a user email.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  Page number (1-indexed) for pagination.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_chat_history( string $email, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'agent_builder_conversations';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, agent_id, role, content, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user->ID,
				$per_page,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'agentic_chat',
				'group_label' => __( 'AI Chat History', 'agent-builder' ),
				'item_id'     => 'chat-' . $row->session_id . '-' . $row->created_at,
				'data'        => array(
					array(
						'name'  => __( 'Session ID', 'agent-builder' ),
						'value' => $row->session_id,
					),
					array(
						'name'  => __( 'Agent', 'agent-builder' ),
						'value' => $row->agent_id,
					),
					array(
						'name'  => __( 'Role', 'agent-builder' ),
						'value' => $row->role,
					),
					array(
						'name'  => __( 'Message', 'agent-builder' ),
						'value' => $row->content,
					),
					array(
						'name'  => __( 'Date', 'agent-builder' ),
						'value' => $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Export security log entries for a user.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  Page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_security_log( string $email, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'agent_builder_security_log';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, ip_address, message, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user->ID,
				$per_page,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'agentic_security',
				'group_label' => __( 'AI Security Log', 'agent-builder' ),
				'item_id'     => 'sec-' . $row->created_at,
				'data'        => array(
					array(
						'name'  => __( 'Event', 'agent-builder' ),
						'value' => $row->event_type,
					),
					array(
						'name'  => __( 'IP Address', 'agent-builder' ),
						'value' => $row->ip_address,
					),
					array(
						'name'  => __( 'Message', 'agent-builder' ),
						'value' => $row->message,
					),
					array(
						'name'  => __( 'Date', 'agent-builder' ),
						'value' => $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < $per_page,
		);
	}

	// -------------------------------------------------------------------------
	// WordPress Privacy Tools – Erasers
	// -------------------------------------------------------------------------

	/**
	 * Register personal data erasers.
	 *
	 * @param array<int, array<string, mixed>> $erasers Existing erasers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_erasers( array $erasers ): array {
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Agent Builder – Chat History', 'agent-builder' ),
			'callback'             => array( __CLASS__, 'erase_chat_history' ),
		);
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Agent Builder – Security Log', 'agent-builder' ),
			'callback'             => array( __CLASS__, 'erase_security_log' ),
		);
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Agent Builder – Audit Log', 'agent-builder' ),
			'callback'             => array( __CLASS__, 'erase_audit_log' ),
		);
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Agent Builder – Jobs', 'agent-builder' ),
			'callback'             => array( __CLASS__, 'erase_jobs' ),
		);
		return $erasers;
	}

	/**
	 * Erase chat history for a user email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused — we delete all in one pass).
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public static function erase_chat_history( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP eraser callback signature.
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table = $wpdb->prefix . 'agent_builder_conversations';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->delete( $table, array( 'user_id' => $user->ID ), array( '%d' ) );

		// Also erase any locally stored conversational memory for this user.
		if ( class_exists( '\Agentic\Local_Memory' ) ) {
			$deleted += \Agentic\Local_Memory::forget_user( (int) $user->ID );
		}

		return array(
			'items_removed'  => $deleted,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase security log entries linked to a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused).
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public static function erase_security_log( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP eraser callback signature.
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table = $wpdb->prefix . 'agent_builder_security_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->delete( $table, array( 'user_id' => $user->ID ), array( '%d' ) );

		return array(
			'items_removed'  => $deleted,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Export audit log entries for a user.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  Page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_audit_log( string $email, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'agent_builder_audit_log';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent_id, action, target_type, target_id, details, reasoning, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user->ID,
				$per_page,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'agentic_audit',
				'group_label' => __( 'AI Audit Log', 'agent-builder' ),
				'item_id'     => 'audit-' . $row->created_at,
				'data'        => array(
					array(
						'name'  => __( 'Agent', 'agent-builder' ),
						'value' => $row->agent_id,
					),
					array(
						'name'  => __( 'Action', 'agent-builder' ),
						'value' => $row->action,
					),
					array(
						'name'  => __( 'Target Type', 'agent-builder' ),
						'value' => $row->target_type ?? '',
					),
					array(
						'name'  => __( 'Target ID', 'agent-builder' ),
						'value' => $row->target_id ?? '',
					),
					array(
						'name'  => __( 'Details', 'agent-builder' ),
						'value' => $row->details ?? '',
					),
					array(
						'name'  => __( 'Reasoning', 'agent-builder' ),
						'value' => $row->reasoning ?? '',
					),
					array(
						'name'  => __( 'Date', 'agent-builder' ),
						'value' => $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Export jobs for a user.
	 *
	 * @param string $email Email address of the data subject.
	 * @param int    $page  Page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_jobs( string $email, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'agent_builder_jobs';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, agent_id, status, message, request_data, response_data, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user->ID,
				$per_page,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'agent_builder_jobs',
				'group_label' => __( 'AI Jobs', 'agent-builder' ),
				'item_id'     => 'job-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Job ID', 'agent-builder' ),
						'value' => $row->id,
					),
					array(
						'name'  => __( 'Agent', 'agent-builder' ),
						'value' => $row->agent_id ?? '',
					),
					array(
						'name'  => __( 'Status', 'agent-builder' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'Message', 'agent-builder' ),
						'value' => $row->message ?? '',
					),
					array(
						'name'  => __( 'Request', 'agent-builder' ),
						'value' => $row->request_data ?? '',
					),
					array(
						'name'  => __( 'Response', 'agent-builder' ),
						'value' => $row->response_data ?? '',
					),
					array(
						'name'  => __( 'Date', 'agent-builder' ),
						'value' => $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Erase audit log entries linked to a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused).
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public static function erase_audit_log( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP eraser callback signature.
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table = $wpdb->prefix . 'agent_builder_audit_log';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->delete( $table, array( 'user_id' => $user->ID ), array( '%d' ) );

		return array(
			'items_removed'  => $deleted,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase jobs linked to a user.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused).
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public static function erase_jobs( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP eraser callback signature.
		global $wpdb;

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table = $wpdb->prefix . 'agent_builder_jobs';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->delete( $table, array( 'user_id' => $user->ID ), array( '%d' ) );

		return array(
			'items_removed'  => $deleted,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	// -------------------------------------------------------------------------
	// Data Retention
	// -------------------------------------------------------------------------

	/**
	 * Cron callback: delete records older than the configured retention period.
	 */
	public static function run_cleanup(): void {
		global $wpdb;

		$conv_days  = (int) get_option( 'agent_builder_retention_conversations', 30 );
		$audit_days = (int) get_option( 'agent_builder_retention_audit_log', 30 );

		if ( $conv_days > 0 ) {
			$table = $wpdb->prefix . 'agent_builder_conversations';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$conv_days
					)
				);
			}

			// Local conversational memory follows the same retention window.
			$mem_table = $wpdb->prefix . 'agent_builder_memory';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$mem_table}'" ) === $mem_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->prepare(
						"DELETE FROM {$mem_table} WHERE memory_type = %s AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'conversation',
						$conv_days
					)
				);
			}
		}

		if ( $audit_days > 0 ) {
			foreach ( array( 'agent_builder_audit_log', 'agent_builder_security_log' ) as $suffix ) {
				$table = $wpdb->prefix . $suffix;
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
						$wpdb->prepare(
							"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$audit_days
						)
					);
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Chat Consent REST Gate
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current request should be blocked pending consent.
	 *
	 * Called from Chat_Security::scan() before any LLM call.
	 * Returns an error array (same format as other scan checks) or null if consent
	 * is not required or has already been given.
	 *
	 * For frontend chats the client is expected to set a cookie
	 * `agentic_consent_given=1` after the user acknowledges the notice.
	 * Admin / internal calls bypass this check entirely.
	 *
	 * @return array{pass: false, reason: string, code: string}|null
	 */
	public static function check_consent(): ?array {
		if ( ! get_option( 'agent_builder_chat_consent_enabled', false ) ) {
			return null; // Consent gate disabled.
		}

		// Admin users (and internal WP-CLI / REST calls by admins) are exempt.
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}

		// Check cookie set by the frontend consent banner.
		$consent_cookie = isset( $_COOKIE['agentic_consent_given'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['agentic_consent_given'] ) )
			: '';

		if ( '1' === $consent_cookie ) {
			return null;
		}

		return array(
			'pass'   => false,
			'reason' => 'Consent required. Please acknowledge the privacy notice before chatting.',
			'code'   => 'consent_required',
		);
	}

	/**
	 * Return the configured consent notice text (with a safe fallback).
	 */
	public static function get_consent_text(): string {
		$text = get_option( 'agent_builder_chat_consent_text', '' );
		if ( empty( trim( (string) $text ) ) ) {
			return 'By chatting you agree to your messages being processed by an AI. We do not share your data with third parties.';
		}
		return (string) $text;
	}
}
