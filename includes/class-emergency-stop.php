<?php
/**
 * Emergency stop — Disable All Agents.
 *
 * Site-wide kill switch for when something seriously goes wrong. When enabled:
 * - Snapshots each active agent + job state into the system log (and a restore snapshot)
 * - Deactivates all agents
 * - Cancels pending/processing jobs
 * - Disconnects LLM providers (keys cleared into encrypted snapshot for restore)
 *
 * While active, chat, new jobs, and agent activation are blocked.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.2.5
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
 * Emergency kill switch for agents and LLM providers.
 */
class Emergency_Stop {

	/**
	 * Option: '0' | '1'.
	 */
	public const OPTION_ENABLED = 'agent_builder_disable_all_agents';

	/**
	 * Snapshot used to restore agents + provider keys when the switch is turned off.
	 */
	public const OPTION_SNAPSHOT = 'agent_builder_disable_all_agents_snapshot';

	/**
	 * Whether the emergency stop is currently active.
	 */
	public static function is_active(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Human-readable reason string for blocked actions.
	 */
	public static function blocked_message(): string {
		return __(
			'Emergency stop is active: all agents and LLM providers are disabled. An administrator can turn this off under Interface Settings.',
			'agent-builder'
		);
	}

	/**
	 * Enable the kill switch (idempotent if already on).
	 *
	 * @return array{ok:bool,snapshot?:array<string,mixed>,error?:string}
	 */
	public static function enable(): array {
		if ( self::is_active() ) {
			return array(
				'ok'       => true,
				'snapshot' => (array) get_option( self::OPTION_SNAPSHOT, array() ),
			);
		}

		$snapshot = self::build_snapshot();

		// Persist snapshot before any disruption so recovery is possible.
		update_option( self::OPTION_SNAPSHOT, $snapshot, false );

		Security_Log::log_system(
			'emergency_stop_enabled',
			'emergency_stop',
			array(
				'user_id'          => get_current_user_id(),
				'active_agents'    => $snapshot['active_agents'] ?? array(),
				'agent_count'      => count( $snapshot['agents'] ?? array() ),
				'provider_count'   => count( $snapshot['providers'] ?? array() ),
				'jobs_pending'     => count( $snapshot['jobs_pending'] ?? array() ),
				'jobs_processing'  => count( $snapshot['jobs_processing'] ?? array() ),
				'default_provider' => $snapshot['default_provider'] ?? '',
			)
		);

		// Log each agent individually for the system log trail.
		foreach ( (array) ( $snapshot['agents'] ?? array() ) as $agent_state ) {
			Security_Log::log_system(
				'emergency_stop_agent_state',
				'emergency_stop',
				$agent_state
			);
		}

		// Deactivate agents.
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$registry = \Agentic_Agent_Registry::get_instance();
			foreach ( (array) ( $snapshot['active_agents'] ?? array() ) as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}
				$result = $registry->deactivate_agent( $slug );
				Security_Log::log_system(
					'emergency_stop_agent_deactivated',
					'emergency_stop',
					array(
						'slug'    => $slug,
						'success' => ! is_wp_error( $result ),
						'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
					)
				);
			}
		}

		// Cancel running work.
		$cancelled = Job_Manager::emergency_cancel_all();
		Security_Log::log_system(
			'emergency_stop_jobs_cancelled',
			'emergency_stop',
			$cancelled
		);

		// Disconnect LLM providers (keys → snapshot already has encrypted copies).
		self::disconnect_providers( $snapshot );

		// Clear default LLM provider selection so nothing residual is selected.
		update_option( 'agent_builder_llm_provider', 'none', false );

		update_option( self::OPTION_ENABLED, '1', false );

		do_action( 'agentic_emergency_stop_enabled', $snapshot );

		return array(
			'ok'       => true,
			'snapshot' => $snapshot,
		);
	}

	/**
	 * Disable the kill switch and restore the pre-stop snapshot when available.
	 *
	 * @return array{ok:bool,restored?:bool,warnings?:string[],error?:string}
	 */
	public static function disable(): array {
		if ( ! self::is_active() ) {
			return array(
				'ok'       => true,
				'restored' => false,
			);
		}

		$snapshot = get_option( self::OPTION_SNAPSHOT, array() );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$warnings = array();

		Security_Log::log_system(
			'emergency_stop_disabled',
			'emergency_stop',
			array(
				'user_id'           => get_current_user_id(),
				'has_snapshot'      => ! empty( $snapshot ),
				'agents_to_restore' => $snapshot['active_agents'] ?? array(),
			)
		);

		// Clear the flag first so activate_agent() is not blocked by is_active().
		update_option( self::OPTION_ENABLED, '0', false );

		// Restore providers first so agents can use them again.
		$warnings = array_merge( $warnings, self::restore_providers( $snapshot ) );

		if ( ! empty( $snapshot['default_provider'] ) ) {
			update_option( 'agent_builder_llm_provider', sanitize_key( (string) $snapshot['default_provider'] ), false );
		}
		if ( ! empty( $snapshot['default_model'] ) ) {
			update_option( 'agent_builder_model', sanitize_text_field( (string) $snapshot['default_model'] ), false );
		}

		// Re-activate agents that were active at stop time.
		if ( class_exists( '\Agentic_Agent_Registry' ) && ! empty( $snapshot['active_agents'] ) ) {
			$registry = \Agentic_Agent_Registry::get_instance();
			foreach ( (array) $snapshot['active_agents'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug || $registry->is_agent_active( $slug ) ) {
					continue;
				}
				$result = $registry->activate_agent( $slug );
				Security_Log::log_system(
					'emergency_stop_agent_restored',
					'emergency_stop',
					array(
						'slug'    => $slug,
						'success' => ! is_wp_error( $result ),
						'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
					)
				);
				if ( is_wp_error( $result ) ) {
					$warnings[] = sprintf(
						/* translators: 1: agent slug, 2: error message */
						__( 'Could not reactivate agent "%1$s": %2$s', 'agent-builder' ),
						$slug,
						$result->get_error_message()
					);
				}
			}
		}

		// Keep snapshot for audit trail; mark restored_at.
		$snapshot['restored_at'] = gmdate( 'Y-m-d H:i:s' );
		$snapshot['restored_by'] = get_current_user_id();
		update_option( self::OPTION_SNAPSHOT, $snapshot, false );

		do_action( 'agentic_emergency_stop_disabled', $snapshot );

		return array(
			'ok'       => true,
			'restored' => ! empty( $snapshot ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Build a restore + audit snapshot of current agent / provider / job state.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_snapshot(): array {
		$active_agents = array();
		$agents_state  = array();

		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			$registry      = \Agentic_Agent_Registry::get_instance();
			$active_agents = array_values(
				array_filter(
					array_map( 'sanitize_key', (array) $registry->get_active_agents() )
				)
			);
			$installed     = $registry->get_installed_agents();
			foreach ( $active_agents as $slug ) {
				$info           = $installed[ $slug ] ?? array();
				$agents_state[] = array(
					'slug'        => $slug,
					'name'        => (string) ( $info['name'] ?? $slug ),
					'version'     => (string) ( $info['version'] ?? '' ),
					'bundled'     => ! empty( $info['bundled'] ),
					'directory'   => (string) ( $info['directory'] ?? '' ),
					'was_active'  => true,
					'snapshot_at' => gmdate( 'Y-m-d H:i:s' ),
				);
			}
		}

		$providers = array();
		foreach ( Provider_Registry::get_all() as $p ) {
			$slug = (string) ( $p['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			// Only LLM providers matter for chat; services keep keys (RAG etc. still blocked by is_active gates).
			$type = (string) ( $p['provider_type'] ?? 'llm' );
			if ( 'llm' !== $type && 'none' !== ( $p['auth_type'] ?? '' ) ) {
				// Still snapshot LLM-ish and ollama.
				if ( ! in_array( $slug, array( 'ollama', 'agentic', 'openai', 'google', 'anthropic', 'xai', 'mistral', 'llama', 'cohere', 'kimi', 'deepseek' ), true )
					&& 'llm' !== $type ) {
					continue;
				}
			}
			$encrypted          = self::get_encrypted_api_key( $slug );
			$providers[ $slug ] = array(
				'slug'          => $slug,
				'name'          => (string) ( $p['name'] ?? $slug ),
				'had_key'       => ! empty( $p['api_key'] ),
				'encrypted_key' => $encrypted,
				'default_model' => (string) ( $p['default_model'] ?? '' ),
				'auth_type'     => (string) ( $p['auth_type'] ?? '' ),
			);
		}

		$jobs_pending    = Job_Manager::list_by_statuses( array( Job_Manager::STATUS_PENDING ) );
		$jobs_processing = Job_Manager::list_by_statuses( array( Job_Manager::STATUS_PROCESSING ) );

		return array(
			'enabled_at'       => gmdate( 'Y-m-d H:i:s' ),
			'enabled_by'       => get_current_user_id(),
			'site_url'         => home_url(),
			'active_agents'    => $active_agents,
			'agents'           => $agents_state,
			'providers'        => $providers,
			'ollama_url'       => (string) get_option( 'agent_builder_ollama_url', '' ),
			'default_provider' => (string) get_option( 'agent_builder_llm_provider', '' ),
			'default_model'    => (string) get_option( 'agent_builder_model', '' ),
			'jobs_pending'     => $jobs_pending,
			'jobs_processing'  => $jobs_processing,
		);
	}

	/**
	 * Clear LLM provider keys / Ollama URL using the snapshot's provider list.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	private static function disconnect_providers( array $snapshot ): void {
		foreach ( (array) ( $snapshot['providers'] ?? array() ) as $slug => $info ) {
			$slug = sanitize_key( (string) ( is_array( $info ) ? ( $info['slug'] ?? $slug ) : $slug ) );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! empty( $info['had_key'] ) || ! empty( $info['encrypted_key'] ) ) {
				Provider_Registry::save_api_key( $slug, '' );
				Security_Log::log_system(
					'emergency_stop_provider_disconnected',
					'emergency_stop',
					array(
						'slug' => $slug,
						'name' => is_array( $info ) ? ( $info['name'] ?? $slug ) : $slug,
					)
				);
			}
		}

		if ( ! empty( $snapshot['ollama_url'] ) ) {
			update_option( 'agent_builder_ollama_url', '', false );
			Security_Log::log_system(
				'emergency_stop_ollama_disconnected',
				'emergency_stop',
				array( 'had_url' => true )
			);
		}
	}

	/**
	 * Restore provider keys / Ollama URL from snapshot.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return string[] Human-readable warnings for any provider that failed to restore.
	 */
	private static function restore_providers( array $snapshot ): array {
		$warnings = array();

		foreach ( (array) ( $snapshot['providers'] ?? array() ) as $slug => $info ) {
			if ( ! is_array( $info ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $info['slug'] ?? $slug ) );
			$enc  = (string) ( $info['encrypted_key'] ?? '' );
			if ( '' === $slug || '' === $enc ) {
				continue;
			}
			$restored = Provider_Registry::restore_encrypted_api_key( $slug, $enc );
			if ( ! $restored ) {
				$warnings[] = sprintf(
					/* translators: %s: provider name */
					__( 'Could not restore the API key for "%s" — the provider no longer exists.', 'agent-builder' ),
					(string) ( $info['name'] ?? $slug )
				);
				continue;
			}
			Security_Log::log_system(
				'emergency_stop_provider_restored',
				'emergency_stop',
				array(
					'slug' => $slug,
					'name' => (string) ( $info['name'] ?? $slug ),
				)
			);
		}

		if ( ! empty( $snapshot['ollama_url'] ) ) {
			update_option( 'agent_builder_ollama_url', esc_url_raw( (string) $snapshot['ollama_url'] ), false );
		}

		return $warnings;
	}

	/**
	 * Read the encrypted api_key column for a provider slug.
	 *
	 * @param string $slug Provider slug.
	 */
	private static function get_encrypted_api_key( string $slug ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$val = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT api_key FROM ' . $wpdb->prefix . 'agent_builder_providers WHERE slug = %s',
				$slug
			)
		);
		return is_string( $val ) ? $val : '';
	}
}
