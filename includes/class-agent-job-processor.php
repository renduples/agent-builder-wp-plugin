<?php
/**
 * Agent Builder Job Processor
 *
 * Processes agent building jobs asynchronously.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Agent Builder Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Builder Job Processor
 */
class Agent_Builder_Job_Processor implements Job_Processor_Interface {

	/**
	 * Execute the agent building job
	 *
	 * @param array    $request_data      Job input data.
	 * @param callable $progress_callback Progress update callback.
	 * @return array Job result data.
	 * @throws \Exception If job execution fails.
	 */
	public function execute( array $request_data, callable $progress_callback ): array {
		$progress_callback( 5, 'Initializing agent builder...' );

		// Get the agent builder agent.
		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( 'assistant-trainer' );

		if ( ! $agent ) {
			throw new \Exception( 'Assistant Trainer agent not found' );
		}

		$progress_callback( 10, 'Loading conversation history...' );

		// Build message array from history.
		$messages = array();

		// Add system prompt.
		$messages[] = array(
			'role'    => 'system',
			'content' => $agent->get_system_prompt(),
		);

		// Add history if provided.
		if ( ! empty( $request_data['history'] ) ) {
			foreach ( $request_data['history'] as $entry ) {
				if ( isset( $entry['role'], $entry['content'] ) ) {
					$messages[] = array(
						'role'    => $entry['role'],
						'content' => $entry['content'],
					);
				}
			}
		}

		// Add current message.
		$messages[] = array(
			'role'    => 'user',
			'content' => $request_data['message'],
		);

		$progress_callback( 20, 'Analyzing request...' );

		// Create LLM client.
		$llm = new LLM_Client();

		if ( ! $llm->is_configured() ) {
			throw new \Exception( 'LLM API is not configured. Please add your API key in settings.' );
		}

		// Get tools via Tool_Loader.
		$tool_loader = Tool_Loader::get_instance();
		$tool_loader->load();
		$tools = $tool_loader->get_definitions_for( $agent->get_tool_names() );

		$progress_callback( 30, 'Generating agent specification...' );

		// Make initial LLM call.
		$llm_response = $llm->chat( $messages, $tools );

		if ( is_wp_error( $llm_response ) ) {
			throw new \Exception( 'LLM request failed: ' . esc_html( $llm_response->get_error_message() ) );
		}

		// Extract message from response.
		$choice = $llm_response['choices'][0]['message'] ?? array();

		$progress_callback( 50, 'Executing agent tools...' );

		// Handle tool calls iteratively.
		$max_iterations     = 5;
		$iteration          = 0;
		$created_agent_slug = null;

		while ( isset( $choice['tool_calls'] ) && ! empty( $choice['tool_calls'] ) && $iteration < $max_iterations ) {
			++$iteration;

			$progress_percent = 50 + ( $iteration * 10 );
			$progress_callback( $progress_percent, "Processing tools (iteration {$iteration})..." );

			// Add assistant message with tool calls.
			$messages[] = array(
				'role'       => 'assistant',
				'content'    => $choice['content'] ?? '',
				'tool_calls' => $choice['tool_calls'],
			);

			// Execute each tool call.
			foreach ( $choice['tool_calls'] as $tool_call ) {
				$tool_name = $tool_call['function']['name'];
				$tool_args = json_decode( $tool_call['function']['arguments'], true ) ? json_decode( $tool_call['function']['arguments'], true ) : array();

				// Execute tool via Tool_Loader.
				try {
					$tool_result = $tool_loader->execute( $tool_name, $tool_args );
				} catch ( \Throwable $e ) {
					\Agentic\Security_Log::log_system(
						'job_tool_exception',
						'tools',
						array(
							'tool'  => $tool_name,
							'error' => $e->getMessage(),
							'file'  => $e->getFile() . ':' . $e->getLine(),
						)
					);
					$tool_result = array( 'error' => sprintf( 'Tool "%s" encountered an unexpected error.', $tool_name ) );
				}

				// Track if an agent was created successfully.
				if ( 'create_agent_files' === $tool_name && is_array( $tool_result ) && ! empty( $tool_result['success'] ) ) {
					$created_agent_slug = $tool_result['slug'] ?? null;
				}

				// Add tool result.
				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $tool_call['id'],
					'content'      => is_array( $tool_result ) ? wp_json_encode( $tool_result ) : (string) $tool_result,
				);
			}

			// Get next LLM response.
			$llm_response = $llm->chat( $messages, $tools );

			if ( is_wp_error( $llm_response ) ) {
				break;
			}

			// Update choice from new response.
			$choice = $llm_response['choices'][0]['message'] ?? array();
		}

		$progress_callback( 95, 'Finalizing response...' );

		$response_content = $choice['content'] ?? 'No response generated.';

		$progress_callback( 100, 'Completed' );

		$result = array(
			'response'   => $response_content,
			'agent_id'   => 'assistant-trainer',
			'iterations' => $iteration,
		);

		// Include created agent slug if an agent was built.
		if ( $created_agent_slug ) {
			$result['created_agent_slug'] = $created_agent_slug;
		}

		// Log conversation turns so they appear in admin Conversations page.
		$session_id = $request_data['session_id'] ?? '';
		$user_id    = (int) ( $request_data['user_id'] ?? 0 );
		if ( $session_id ) {
			global $wpdb;
			$table = $wpdb->prefix . 'agent_builder_conversations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
			$wpdb->insert(
				$table,
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'agent_id'   => 'assistant-trainer',
					'role'       => 'user',
					'content'    => $request_data['message'],
					'tools_used' => '',
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert.
			$wpdb->insert(
				$table,
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'agent_id'   => 'assistant-trainer',
					'role'       => 'assistant',
					'content'    => $response_content,
					'tools_used' => '',
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}

		return $result;
	}
}
