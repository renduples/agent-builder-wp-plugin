<?php
/**
 * Agent lifecycle hooks — cron scheduling and event listeners.
 *
 * Handles binding, executing, and cleaning up scheduled tasks and
 * event listeners for all active agents. Extracted from the Plugin
 * class to keep agent-builder.php focused on bootstrapping.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent_Lifecycle
 *
 * All methods are static so they can be registered directly with
 * add_action() using array( Agent_Lifecycle::class, 'method' ).
 */
class Agent_Lifecycle {

	/**
	 * Option key for admin-created scheduled tasks (Deployment → Scheduled Tasks).
	 */
	const USER_SCHEDULED_TASKS_OPTION = 'agent_builder_user_scheduled_tasks';

	/**
	 * Option key for admin-created event triggers (Deployment → Event Listeners).
	 */
	const USER_EVENT_TRIGGERS_OPTION = 'agent_builder_user_event_triggers';

	/**
	 * Allowed WP-Cron recurrence keys for user-defined scheduled tasks.
	 *
	 * @var array<int, string>
	 */
	const ALLOWED_USER_SCHEDULES = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	/**
	 * Bind cron hooks for all active agents' scheduled tasks.
	 *
	 * Called on 'agentic_agents_loaded' action.
	 *
	 * @return void
	 */
	public static function bind_cron_hooks(): void {
		$registry  = \Agentic_Agent_Registry::get_instance();
		$instances = $registry->get_all_instances();

		foreach ( $instances as $agent ) {
			$tasks = $agent->get_scheduled_tasks();

			foreach ( $tasks as $task ) {
				$hook = $agent->get_cron_hook( $task['id'] );

				add_action(
					$hook,
					static function () use ( $agent, $task ) {
						self::execute_scheduled_task( $agent, $task );
					}
				);
			}
		}

		// User-defined tasks created via Deployment → Scheduled Tasks.
		foreach ( self::get_user_scheduled_tasks() as $user_task ) {
			$agent = $registry->get_agent_instance( $user_task['agent_slug'] ?? '' );
			if ( ! $agent ) {
				continue;
			}

			$task = self::user_task_to_definition( $user_task );
			$hook = $agent->get_cron_hook( $task['id'] );

			add_action(
				$hook,
				static function () use ( $agent, $task ) {
					self::execute_scheduled_task( $agent, $task );
				}
			);
		}
	}

	/**
	 * Read all admin-created scheduled tasks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_user_scheduled_tasks(): array {
		$tasks = get_option( self::USER_SCHEDULED_TASKS_OPTION, array() );
		if ( ! is_array( $tasks ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$tasks,
				static function ( $t ) {
					return is_array( $t ) && ! empty( $t['id'] ) && ! empty( $t['agent_slug'] );
				}
			)
		);
	}

	/**
	 * Find one user-defined scheduled task by id.
	 *
	 * @param string $task_id Task id (e.g. us_…).
	 * @return array<string, mixed>|null
	 */
	public static function find_user_scheduled_task( string $task_id ): ?array {
		foreach ( self::get_user_scheduled_tasks() as $task ) {
			if ( ( $task['id'] ?? '' ) === $task_id ) {
				return $task;
			}
		}

		return null;
	}

	/**
	 * Normalize a stored user task into the shape execute_scheduled_task() expects.
	 *
	 * @param array<string, mixed> $user_task Stored option row.
	 * @return array<string, mixed>
	 */
	public static function user_task_to_definition( array $user_task ): array {
		return array(
			'id'          => (string) ( $user_task['id'] ?? '' ),
			'name'        => (string) ( $user_task['name'] ?? $user_task['id'] ?? 'Scheduled task' ),
			'schedule'    => (string) ( $user_task['schedule'] ?? 'daily' ),
			'description' => (string) ( $user_task['description'] ?? '' ),
			'prompt'      => (string) ( $user_task['prompt'] ?? '' ),
		);
	}

	/**
	 * Resolve a task definition from a built-in agent task or a user-defined task.
	 *
	 * @param string $agent_slug Agent id.
	 * @param string $task_id    Task id.
	 * @return array{agent: \Agentic\Agent_Base, task: array<string, mixed>}|null
	 */
	public static function resolve_task( string $agent_slug, string $task_id ): ?array {
		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $agent_slug );
		if ( ! $agent ) {
			return null;
		}

		foreach ( $agent->get_scheduled_tasks() as $task ) {
			if ( ( $task['id'] ?? '' ) === $task_id ) {
				return array(
					'agent' => $agent,
					'task'  => $task,
				);
			}
		}

		$user_task = self::find_user_scheduled_task( $task_id );
		if ( $user_task && ( $user_task['agent_slug'] ?? '' ) === $agent_slug ) {
			return array(
				'agent' => $agent,
				'task'  => self::user_task_to_definition( $user_task ),
			);
		}

		return null;
	}

	/**
	 * Cron hook name for a user-defined task (same convention as Agent_Base).
	 *
	 * @param string $agent_slug Agent id.
	 * @param string $task_id    Task id.
	 * @return string
	 */
	public static function user_task_cron_hook( string $agent_slug, string $task_id ): string {
		return 'agentic_task_' . $agent_slug . '_' . $task_id;
	}

	/**
	 * Create or update a user-defined scheduled task — the single place this
	 * happens, shared by the classic Deployment → Scheduled Tasks AJAX
	 * handler (Admin_Ajax::save_user_scheduled_task()) and the
	 * manage_scheduled_task tool, so both write through the exact same
	 * option + cron + Deployments dual-write.
	 *
	 * @param array $args {
	 *     @type string $id          Existing task id to update, or '' for a new task.
	 *     @type string $agent_slug  Required. Agent to run the task.
	 *     @type string $name        Optional, defaults to "{schedule} — {agent name}".
	 *     @type string $prompt      Required.
	 *     @type string $description Optional.
	 *     @type string $schedule    One of ALLOWED_USER_SCHEDULES, defaults to 'daily'.
	 * }
	 * @return array{ok:bool,id?:string,name?:string,error?:string}
	 */
	public static function save_user_scheduled_task( array $args ): array {
		$id          = sanitize_key( (string) ( $args['id'] ?? '' ) );
		$agent_slug  = sanitize_key( (string) ( $args['agent_slug'] ?? '' ) );
		$name        = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
		$prompt      = sanitize_textarea_field( (string) ( $args['prompt'] ?? '' ) );
		$description = sanitize_text_field( (string) ( $args['description'] ?? '' ) );
		$schedule    = sanitize_key( (string) ( $args['schedule'] ?? 'daily' ) );

		if ( empty( $agent_slug ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Agent is required.', 'agent-builder' ),
			);
		}

		if ( '' === trim( $prompt ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Prompt is required.', 'agent-builder' ),
			);
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $agent_slug );
		if ( ! $agent ) {
			return array(
				'ok'    => false,
				'error' => __( 'Agent not found or not active.', 'agent-builder' ),
			);
		}

		if ( ! in_array( $schedule, self::ALLOWED_USER_SCHEDULES, true ) ) {
			$schedule = 'daily';
		}

		if ( empty( $name ) ) {
			$name = sprintf(
				/* translators: 1: schedule label, 2: agent name */
				__( '%1$s — %2$s', 'agent-builder' ),
				ucfirst( $schedule ),
				$agent->get_name()
			);
		}

		$tasks = self::get_user_scheduled_tasks();
		$found = false;

		if ( ! empty( $id ) ) {
			foreach ( $tasks as &$row ) {
				if ( ( $row['id'] ?? '' ) === $id ) {
					$old_hook = self::user_task_cron_hook( (string) ( $row['agent_slug'] ?? '' ), $id );
					wp_clear_scheduled_hook( $old_hook );

					$row['agent_slug']  = $agent_slug;
					$row['name']        = $name;
					$row['prompt']      = $prompt;
					$row['description'] = $description;
					$row['schedule']    = $schedule;
					$found              = true;
					break;
				}
			}
			unset( $row );
		}

		if ( ! $found ) {
			$id      = $id ? $id : ( 'us_' . uniqid() );
			$tasks[] = array(
				'id'          => $id,
				'agent_slug'  => $agent_slug,
				'name'        => $name,
				'prompt'      => $prompt,
				'description' => $description,
				'schedule'    => $schedule,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		update_option( self::USER_SCHEDULED_TASKS_OPTION, array_values( $tasks ), false );

		// Register WP-Cron event immediately.
		$hook = self::user_task_cron_hook( $agent_slug, $id );
		wp_clear_scheduled_hook( $hook );
		$next_ts = time();
		wp_schedule_event( $next_ts, $schedule, $hook );

		// Dual-write Deployments row.
		if ( class_exists( Deployments::class ) ) {
			$existing_id = 0;
			foreach ( Deployments::all( Deployments::TYPE_SCHEDULED_TASK, $agent_slug ) as $st_row ) {
				if ( ( $st_row['config']['task_id'] ?? '' ) === $id ) {
					$existing_id = (int) $st_row['id'];
					break;
				}
			}

			$st_save = array(
				'type'       => Deployments::TYPE_SCHEDULED_TASK,
				'agent_slug' => $agent_slug,
				'label'      => $name,
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'task_id'     => $id,
					'schedule'    => $schedule,
					'mode'        => 'autonomous',
					'description' => $description,
					'prompt'      => $prompt,
					'source'      => 'user',
					'next_run'    => gmdate( 'Y-m-d H:i:s', $next_ts ),
					'last_run'    => null,
					'last_status' => null,
				),
			);
			if ( $existing_id ) {
				$st_save['id'] = $existing_id;
			}
			Deployments::save( $st_save );
		}

		return array(
			'ok'   => true,
			'id'   => $id,
			'name' => $name,
		);
	}

	/**
	 * Delete a user-defined scheduled task by id — shared by the classic AJAX
	 * handler and the manage_scheduled_task tool.
	 *
	 * @param string $id Task id.
	 * @return array{ok:bool,error?:string}
	 */
	public static function delete_user_scheduled_task( string $id ): array {
		$id = sanitize_key( $id );
		if ( empty( $id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Missing task ID.', 'agent-builder' ),
			);
		}

		$tasks      = self::get_user_scheduled_tasks();
		$agent_slug = '';
		foreach ( $tasks as $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) {
				$agent_slug = (string) ( $row['agent_slug'] ?? '' );
				break;
			}
		}

		$tasks = array_values(
			array_filter(
				$tasks,
				static function ( $t ) use ( $id ) {
					return ( $t['id'] ?? '' ) !== $id;
				}
			)
		);
		update_option( self::USER_SCHEDULED_TASKS_OPTION, $tasks, false );

		if ( $agent_slug ) {
			wp_clear_scheduled_hook( self::user_task_cron_hook( $agent_slug, $id ) );
		}

		if ( class_exists( Deployments::class ) ) {
			foreach ( Deployments::all( Deployments::TYPE_SCHEDULED_TASK ) as $st_row ) {
				if ( ( $st_row['config']['task_id'] ?? '' ) === $id ) {
					Deployments::delete( (int) $st_row['id'] );
					break;
				}
			}
		}

		return array( 'ok' => true );
	}

	/**
	 * Execute a scheduled task with outcome logging and optional LLM routing.
	 *
	 * If the task defines a 'prompt' field and the LLM is configured, the task
	 * runs through Agent_Controller::run_autonomous_task() (full AI reasoning
	 * with tool calls). Otherwise it falls back to calling the agent's callback
	 * method directly.
	 *
	 * Every execution is wrapped with start/complete/error audit logging including
	 * duration timing, so admins can see exactly what happened and how long it took.
	 *
	 * @param Agent_Base $agent Agent instance.
	 * @param array      $task  Task definition from get_scheduled_tasks().
	 * @return void
	 */
	public static function execute_scheduled_task( Agent_Base $agent, array $task ): void {
		\Agentic\Plugin::get_instance()->load_chat_components();

		$audit    = new Audit_Log();
		$start    = microtime( true );
		$agent_id = $agent->get_id();
		$mode     = ! empty( $task['prompt'] ) ? 'autonomous' : ( ! empty( $task['tool'] ) ? 'tool' : 'direct' );

		// Log task start.
		$audit->log(
			$agent_id,
			'scheduled_task_start',
			$task['id'],
			array(
				'task_name' => $task['name'],
				'schedule'  => $task['schedule'],
				'mode'      => $mode,
			)
		);

		try {
			$result = null;

			// If task has a prompt, route through LLM for autonomous execution.
			if ( ! empty( $task['prompt'] ) ) {
				$controller = new Agent_Controller();
				$controller->set_invocation_context( 'cron' );
				$result = $controller->run_autonomous_task( $agent, $task['prompt'], $task['id'] );
			}

			// Fallback 1: declarative tool mode — run one reviewed tool directly
			// (no LLM), through the same risk/permission gate as a chat turn.
			if ( null === $result && ! empty( $task['tool'] ) ) {
				$result = self::run_automation_tool( $agent, $task, array(), 'cron' );
			}

			// Fallback 2: bundled-PHP-agent callback method. Manifests never carry
			// a callback (the validator strips it), so this only fires for reviewed
			// PHP agents.
			if ( null === $result && ! empty( $task['callback'] ) && method_exists( $agent, $task['callback'] ) ) {
				call_user_func( array( $agent, $task['callback'] ) );
				$result = array(
					'mode'   => 'direct',
					'status' => 'completed',
				);
			}

			$duration = round( microtime( true ) - $start, 3 );

			// Log task completion.
			$audit->log(
				$agent_id,
				'scheduled_task_complete',
				$task['id'],
				array(
					'task_name'  => $task['name'],
					'duration_s' => $duration,
					'mode'       => $mode,
					'result'     => is_array( $result ) ? substr( wp_json_encode( $result ), 0, 1000 ) : null,
				)
			);
		} catch ( \Throwable $e ) {
			$duration = round( microtime( true ) - $start, 3 );

			// Log task error.
			$audit->log(
				$agent_id,
				'scheduled_task_error',
				$task['id'],
				array(
					'task_name'  => $task['name'],
					'duration_s' => $duration,
					'error'      => $e->getMessage(),
					'file'       => $e->getFile() . ':' . $e->getLine(),
				)
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
				error_log(
					sprintf(
						'Agentic scheduled task error (%s/%s): %s',
						$agent_id,
						$task['id'],
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Bind WordPress action hooks for all active agents' event listeners.
	 *
	 * Called on 'agentic_agents_loaded' action.
	 *
	 * @return void
	 */
	public static function bind_event_listeners(): void {
		$registry  = \Agentic_Agent_Registry::get_instance();
		$instances = $registry->get_all_instances();

		foreach ( $instances as $agent ) {
			$listeners = $agent->get_event_listeners();

			foreach ( $listeners as $listener ) {
				$priority      = $listener['priority'] ?? 10;
				$accepted_args = $listener['accepted_args'] ?? 1;

				add_action(
					$listener['hook'],
					static function () use ( $agent, $listener ) {
						$args = func_get_args();
						self::execute_event_listener( $agent, $listener, $args );
					},
					$priority,
					$accepted_args
				);
			}
		}

		// Bind user-defined triggers created via the Deployment → Event Listeners form.
		$user_triggers = self::get_user_event_triggers();

		foreach ( $user_triggers as $trigger ) {
			$agent = $registry->get_agent_instance( $trigger['agent_slug'] ?? '' );
			if ( ! $agent ) {
				continue;
			}

			$listener = array(
				'id'       => $trigger['id'],
				'name'     => $trigger['name'],
				'hook'     => $trigger['hook'],
				'prompt'   => $trigger['prompt'],
				'priority' => $trigger['priority'] ?? 10,
			);

			add_action(
				$trigger['hook'],
				static function () use ( $agent, $listener ) {
					$args = func_get_args();
					self::execute_event_listener( $agent, $listener, $args );
				},
				$trigger['priority'] ?? 10,
				1
			);
		}
	}

	/**
	 * Read all admin-created event triggers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_user_event_triggers(): array {
		$triggers = get_option( self::USER_EVENT_TRIGGERS_OPTION, array() );
		return is_array( $triggers ) ? $triggers : array();
	}

	/**
	 * Create or update a user-defined event trigger — shared by the classic
	 * Deployment → Event Listeners AJAX handler (Admin_Ajax::save_user_trigger())
	 * and the manage_event_listener tool, so both write through the exact
	 * same option + Deployments dual-write.
	 *
	 * @param array $args {
	 *     @type string $id         Existing trigger id to update, or '' for a new trigger.
	 *     @type string $agent_slug Required. Agent to run when the hook fires.
	 *     @type string $hook       Required. WordPress action hook name.
	 *     @type string $name       Optional, defaults to "{hook} → {agent slug}".
	 *     @type string $prompt     Instructions for the agent when the hook fires.
	 *     @type int    $priority   add_action() priority, defaults to 10.
	 * }
	 * @return array{ok:bool,id?:string,name?:string,error?:string}
	 */
	public static function save_user_trigger( array $args ): array {
		$id         = sanitize_text_field( (string) ( $args['id'] ?? '' ) );
		$agent_slug = sanitize_text_field( (string) ( $args['agent_slug'] ?? '' ) );
		$hook       = sanitize_text_field( (string) ( $args['hook'] ?? '' ) );
		$name       = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
		$prompt     = sanitize_textarea_field( (string) ( $args['prompt'] ?? '' ) );
		$priority   = absint( $args['priority'] ?? 10 );

		if ( empty( $agent_slug ) || empty( $hook ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Agent and hook are required.', 'agent-builder' ),
			);
		}

		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $agent_slug );
		if ( ! $agent ) {
			return array(
				'ok'    => false,
				'error' => __( 'Agent not found or not active.', 'agent-builder' ),
			);
		}

		if ( empty( $name ) ) {
			$name = ucfirst( str_replace( array( '_', '-' ), ' ', $hook ) ) . ' → ' . $agent_slug;
		}

		$triggers = self::get_user_event_triggers();

		if ( ! empty( $id ) ) {
			foreach ( $triggers as &$t ) {
				if ( $t['id'] === $id ) {
					$t['agent_slug'] = $agent_slug;
					$t['hook']       = $hook;
					$t['name']       = $name;
					$t['prompt']     = $prompt;
					$t['priority']   = $priority;
					break;
				}
			}
			unset( $t );
		} else {
			$id         = 'ut_' . uniqid();
			$triggers[] = array(
				'id'         => $id,
				'agent_slug' => $agent_slug,
				'hook'       => $hook,
				'name'       => $name,
				'prompt'     => $prompt,
				'priority'   => $priority,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		update_option( self::USER_EVENT_TRIGGERS_OPTION, $triggers, false );

		// Dual-write to Deployments table.
		if ( class_exists( Deployments::class ) ) {
			$existing_id = 0;
			foreach ( Deployments::all( Deployments::TYPE_EVENT_LISTENER, $agent_slug ) as $row ) {
				if ( ( $row['config']['trigger_id'] ?? '' ) === $id ) {
					$existing_id = (int) $row['id'];
					break;
				}
			}

			$save = array(
				'type'       => Deployments::TYPE_EVENT_LISTENER,
				'agent_slug' => $agent_slug,
				'label'      => $name,
				'enabled'    => 1,
				'source'     => Deployments::SOURCE_ADMIN,
				'config'     => array(
					'hook'       => $hook,
					'prompt'     => $prompt,
					'priority'   => $priority,
					'source'     => 'user',
					'trigger_id' => $id,
				),
			);
			if ( $existing_id ) {
				$save['id'] = $existing_id;
			}
			Deployments::save( $save );
		}

		return array(
			'ok'   => true,
			'id'   => $id,
			'name' => $name,
		);
	}

	/**
	 * Delete a user-defined event trigger by id — shared by the classic AJAX
	 * handler and the manage_event_listener tool.
	 *
	 * @param string $id Trigger id.
	 * @return array{ok:bool,error?:string}
	 */
	public static function delete_user_trigger( string $id ): array {
		$id = sanitize_text_field( $id );
		if ( empty( $id ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Missing trigger ID.', 'agent-builder' ),
			);
		}

		$triggers = self::get_user_event_triggers();
		$triggers = array_values(
			array_filter( $triggers, fn( $t ) => $t['id'] !== $id )
		);
		update_option( self::USER_EVENT_TRIGGERS_OPTION, $triggers, false );

		if ( class_exists( Deployments::class ) ) {
			foreach ( Deployments::all( Deployments::TYPE_EVENT_LISTENER ) as $row ) {
				if ( ( $row['config']['trigger_id'] ?? '' ) === $id ) {
					Deployments::delete( (int) $row['id'] );
					break;
				}
			}
		}

		return array( 'ok' => true );
	}

	/**
	 * Execute an event listener with outcome logging.
	 *
	 * For direct mode: calls the agent callback synchronously.
	 * For autonomous mode (prompt defined): queues an async LLM task via
	 * wp_schedule_single_event so it doesn't block the current request.
	 *
	 * @param Agent_Base $agent    Agent instance.
	 * @param array      $listener Listener definition.
	 * @param array      $args     WordPress hook arguments.
	 * @return void
	 */
	public static function execute_event_listener( Agent_Base $agent, array $listener, array $args ): void {
		$audit    = new Audit_Log();
		$start    = microtime( true );
		$agent_id = $agent->get_id();

		try {
			if ( ! empty( $listener['prompt'] ) ) {
				// Queue async LLM execution so we don't block the current request.
				wp_schedule_single_event(
					time(),
					'agentic_async_event',
					array(
						$agent_id,
						$listener['id'],
						$listener['prompt'],
						self::sanitize_hook_args( $args ),
					)
				);

				$audit->log(
					$agent_id,
					'event_listener_triggered',
					$listener['id'],
					array(
						'listener_name' => $listener['name'],
						'hook'          => $listener['hook'],
						'mode'          => 'autonomous',
					)
				);
				return; // Actual execution happens in handle_async_event.
			}

			// Declarative tool mode: run one reviewed tool synchronously, through
			// the same risk/permission gate as chat. The tool receives the hook
			// arguments mapped to named parameters (see the listener's "args").
			if ( ! empty( $listener['tool'] ) ) {
				$result   = self::run_automation_tool( $agent, $listener, $args, 'hook' );
				$duration = round( microtime( true ) - $start, 3 );

				$audit->log(
					$agent_id,
					'event_listener_complete',
					$listener['id'],
					array(
						'listener_name' => $listener['name'],
						'hook'          => $listener['hook'],
						'duration_s'    => $duration,
						'mode'          => 'tool',
						'result'        => is_array( $result ) ? substr( wp_json_encode( $result ), 0, 1000 ) : null,
					)
				);
				return;
			}

			// Direct mode: call the agent callback synchronously. Manifests never
			// carry a callback (the validator strips it), so this only fires for
			// reviewed PHP agents. If the callback returns false the event was not
			// relevant — skip logging.
			$result = false;
			if ( ! empty( $listener['callback'] ) && method_exists( $agent, $listener['callback'] ) ) {
				$result = call_user_func_array( array( $agent, $listener['callback'] ), $args );
			}

			if ( false === $result ) {
				return; // Callback determined the event was not relevant.
			}

			$duration = round( microtime( true ) - $start, 3 );

			$audit->log(
				$agent_id,
				'event_listener_complete',
				$listener['id'],
				array(
					'listener_name' => $listener['name'],
					'hook'          => $listener['hook'],
					'duration_s'    => $duration,
					'mode'          => 'direct',
				)
			);
		} catch ( \Throwable $e ) {
			$duration = round( microtime( true ) - $start, 3 );

			$audit->log(
				$agent_id,
				'event_listener_error',
				$listener['id'],
				array(
					'listener_name' => $listener['name'],
					'hook'          => $listener['hook'],
					'duration_s'    => $duration,
					'error'         => $e->getMessage(),
					'file'          => $e->getFile() . ':' . $e->getLine(),
				)
			);
		}
	}

	/**
	 * Handle async event processing via WP-Cron single event.
	 *
	 * Runs the LLM with the event prompt and serialized hook arguments.
	 *
	 * @param string $agent_id    Agent ID.
	 * @param string $listener_id Listener ID.
	 * @param string $prompt      Base prompt.
	 * @param array  $hook_args   Sanitized hook arguments.
	 * @return void
	 */
	public static function handle_async_event( string $agent_id, string $listener_id, string $prompt, array $hook_args ): void {
		\Agentic\Plugin::get_instance()->load_chat_components();

		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $agent_id );
		$audit    = new Audit_Log();

		if ( ! $agent ) {
			$audit->log( $agent_id, 'event_listener_error', $listener_id, array( 'error' => 'Agent not found for async event' ) );
			return;
		}

		// Build context-enriched prompt.
		$context_json = wp_json_encode( $hook_args, JSON_PRETTY_PRINT );
		$full_prompt  = $prompt . "\n\n[EVENT CONTEXT]\n" . $context_json;

		$start = microtime( true );

		try {
			$controller = new Agent_Controller();
			$controller->set_invocation_context( 'hook' );
			$result = $controller->run_autonomous_task( $agent, $full_prompt, 'event_' . $listener_id );

			// If LLM not configured, try direct fallback.
			if ( null === $result ) {
				$listeners = $agent->get_event_listeners();
				foreach ( $listeners as $listener ) {
					if ( $listener['id'] === $listener_id && method_exists( $agent, $listener['callback'] ) ) {
						call_user_func( array( $agent, $listener['callback'] ), ...$hook_args );
						$result = array(
							'mode'   => 'direct_fallback',
							'status' => 'completed',
						);
						break;
					}
				}
			}

			$duration = round( microtime( true ) - $start, 3 );

			$audit->log(
				$agent_id,
				'event_listener_complete',
				$listener_id,
				array(
					'duration_s' => $duration,
					'mode'       => 'autonomous',
					'result'     => is_array( $result ) ? substr( wp_json_encode( $result ), 0, 1000 ) : null,
				)
			);
		} catch ( \Throwable $e ) {
			$duration = round( microtime( true ) - $start, 3 );

			$audit->log(
				$agent_id,
				'event_listener_error',
				$listener_id,
				array(
					'duration_s' => $duration,
					'error'      => $e->getMessage(),
					'file'       => $e->getFile() . ':' . $e->getLine(),
				)
			);
		}
	}

	/**
	 * Register cron events when an agent is activated.
	 *
	 * @param string     $slug  Agent slug.
	 * @param array|null $agent Agent data (unused, required by hook signature).
	 * @return void
	 */
	public static function on_agent_activated( string $slug, $agent ): void {
		unset( $agent ); // Unused parameter required by hook signature.
		$registry = \Agentic_Agent_Registry::get_instance();
		$instance = $registry->get_agent_instance( $slug );

		if ( $instance ) {
			$instance->register_scheduled_tasks();
			// Seed default settings once — does not overwrite existing admin customisations.
			\Agentic\Agent_Settings::seed_defaults( $slug, $instance->get_default_settings() );
		}

		\Agentic\Security_Log::log_system(
			'agent_activated',
			'agents',
			array( 'slug' => $slug )
		);
	}

	/**
	 * Unregister cron events when an agent is deactivated.
	 *
	 * @param string     $slug  Agent slug.
	 * @param array|null $agent Agent data (unused, required by hook signature).
	 * @return void
	 */
	public static function on_agent_deactivated( string $slug, $agent ): void {
		unset( $agent ); // Unused parameter required by hook signature.
		$registry = \Agentic_Agent_Registry::get_instance();
		$instance = $registry->get_agent_instance( $slug );

		if ( $instance ) {
			$instance->unregister_scheduled_tasks();
		}

		\Agentic\Security_Log::log_system(
			'agent_deactivated',
			'agents',
			array( 'slug' => $slug )
		);
	}

	/**
	 * Log when an agent is installed (uploaded).
	 *
	 * @param string     $slug  Agent slug.
	 * @param array|null $agent Agent data.
	 * @return void
	 */
	public static function on_agent_installed( string $slug, $agent ): void {
		unset( $agent );
		\Agentic\Security_Log::log_system(
			'agent_installed',
			'agents',
			array( 'slug' => $slug )
		);
	}

	/**
	 * Log when an agent is deleted.
	 *
	 * @param string $slug Agent slug.
	 * @return void
	 */
	public static function on_agent_deleted( string $slug ): void {
		\Agentic\Security_Log::log_system(
			'agent_deleted',
			'agents',
			array( 'slug' => $slug )
		);
	}

	/**
	 * Run a declarative automation "tool" action through the standard tool gate.
	 *
	 * Used by both scheduled tasks and event listeners when they declare a
	 * `tool` instead of (or as a fallback to) a `prompt`. Execution goes through
	 * Tool_Executor exactly like a chat tool call, so tool enable/disable state
	 * and risk-level enforcement apply identically: a disabled or extreme-risk
	 * tool is refused, a high-risk tool is queued for admin approval, and only
	 * permitted tools run unattended. Which agent scheduled the action is
	 * irrelevant — the tool's own permission is the boundary.
	 *
	 * @param Agent_Base $agent     Agent instance.
	 * @param array      $spec      Task/listener spec (must include 'tool'; may include 'args').
	 * @param array      $hook_args Positional hook arguments (empty for cron tasks).
	 * @param string     $context   Invocation context ('cron' or 'hook').
	 * @return array Tool result (or a status/error array from the gate).
	 */
	private static function run_automation_tool( Agent_Base $agent, array $spec, array $hook_args, string $context ): array {
		$tool = (string) ( $spec['tool'] ?? '' );
		if ( '' === $tool ) {
			return array( 'error' => 'No tool specified for automation action.' );
		}

		// Map positional hook arguments onto named tool parameters when the spec
		// provides a name list (e.g. ['option','old_value','new_value']).
		$arguments = array();
		if ( ! empty( $spec['args'] ) && is_array( $spec['args'] ) ) {
			$values = array_values( $hook_args );
			foreach ( $spec['args'] as $index => $name ) {
				$arguments[ (string) $name ] = $values[ $index ] ?? null;
			}
		}

		$mode = self::resolve_agent_mode( $agent );
		if ( 'disabled' === $mode ) {
			return array( 'error' => 'Agent is disabled; automation tool not run.' );
		}

		$executor = new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log() );
		return $executor->execute( $tool, $arguments, $agent->get_id(), $mode, $context, $agent );
	}

	/**
	 * Resolve an agent's effective operating mode for unattended execution.
	 *
	 * Mirrors the chat resolver: per-agent override → agent default → global
	 * setting. Determines how Tool_Executor gates medium/high-risk tools.
	 *
	 * @param Agent_Base $agent Agent instance.
	 * @return string 'disabled'|'supervised'|'autonomous'.
	 */
	private static function resolve_agent_mode( Agent_Base $agent ): string {
		$slug = $agent->get_id();
		$mode = (string) Agent_Settings::get( $slug, 'override_mode' );
		if ( '' === $mode ) {
			$mode = (string) $agent->get_default_mode();
		}
		if ( '' === $mode ) {
			$mode = (string) get_option( 'agent_builder_agent_mode', 'supervised' );
		}
		return in_array( $mode, array( 'disabled', 'supervised', 'autonomous' ), true ) ? $mode : 'supervised';
	}

	/**
	 * Sanitize hook arguments for safe serialization.
	 *
	 * Converts WP objects to arrays, truncates large values, removes non-serializable data.
	 *
	 * @param array $args Raw hook arguments.
	 * @return array Sanitized arguments.
	 */
	private static function sanitize_hook_args( array $args ): array {
		$sanitized = array();

		foreach ( $args as $key => $value ) {
			if ( $value instanceof \WP_Post ) {
				$sanitized[ $key ] = array(
					'_type'       => 'WP_Post',
					'ID'          => $value->ID,
					'post_title'  => $value->post_title,
					'post_type'   => $value->post_type,
					'post_status' => $value->post_status,
					'post_author' => $value->post_author,
				);
			} elseif ( $value instanceof \WP_Comment ) {
				$sanitized[ $key ] = array(
					'_type'           => 'WP_Comment',
					'comment_ID'      => $value->comment_ID,
					'comment_post_ID' => $value->comment_post_ID,
					'comment_author'  => $value->comment_author,
					'comment_content' => substr( $value->comment_content, 0, 500 ),
				);
			} elseif ( $value instanceof \WP_User ) {
				$sanitized[ $key ] = array(
					'_type'        => 'WP_User',
					'ID'           => $value->ID,
					'user_login'   => $value->user_login,
					'display_name' => $value->display_name,
					'roles'        => $value->roles,
				);
			} elseif ( is_object( $value ) ) {
				$sanitized[ $key ] = array(
					'_type' => get_class( $value ),
					'_note' => 'Object serialized to class name only',
				);
			} elseif ( is_string( $value ) && strlen( $value ) > 1000 ) {
				$sanitized[ $key ] = substr( $value, 0, 1000 ) . '... [truncated]';
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}
}
