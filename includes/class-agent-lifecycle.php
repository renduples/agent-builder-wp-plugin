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
	 * Option-name prefixes owned by Agent Builder. A write to any option or
	 * transient whose name starts with one of these must never fire an event
	 * listener — this is what breaks the updated_option → approval proposal →
	 * updated_option feedback loop.
	 *
	 * @var array<int, string>
	 */
	const INTERNAL_OPTION_PREFIXES = array(
		'_transient_agentic_',
		'_transient_timeout_agentic_',
		'_site_transient_agentic_',
		'_site_transient_timeout_agentic_',
		'agentic_',
		'agent_builder_',
	);

	/**
	 * Default minimum interval (seconds) between two executions of the same
	 * event listener. Overridable per listener via the manifest `min_interval`.
	 */
	const DEFAULT_LISTENER_MIN_INTERVAL = 60;

	/**
	 * Re-entrancy guard: true while a listener is executing (including any tool
	 * it runs synchronously), so an option write by that work never re-dispatches.
	 *
	 * @var bool
	 */
	private static bool $listener_in_flight = false;

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
	 * @param Agent_Base      $agent      Agent instance.
	 * @param array           $task       Task definition from get_scheduled_tasks().
	 * @param Agent_Controller|null $controller Optional controller (tests inject a fake-LLM one).
	 * @return void
	 */
	public static function execute_scheduled_task( Agent_Base $agent, array $task, ?Agent_Controller $controller = null ): void {
		\Agentic\Plugin::get_instance()->load_chat_components();

		$audit    = new Audit_Log();
		$start    = microtime( true );
		$agent_id = $agent->get_id();
		$mode     = ! empty( $task['prompt'] ) ? 'autonomous' : ( ! empty( $task['tool'] ) ? 'tool' : 'direct' );

		// Resolve the Deployments mirror row for this task, if one exists, so the
		// run's source_ref carries that row's integer id (what Routines::history()
		// queries) instead of the option-backed string task id. Built-in /
		// code-sourced tasks have no mirror row and keep the string-id behaviour.
		$deployment_id = class_exists( Routines::class )
			? Routines::deployment_id_for_task( (string) ( $task['id'] ?? '' ) )
			: null;
		$source_ref    = null !== $deployment_id
			? 'routine:' . $deployment_id
			: 'routine:' . (string) ( $task['id'] ?? '' );

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
				$controller = $controller ?? new Agent_Controller();
				$controller->set_invocation_context( 'cron' );
				$result = $controller->run_autonomous_task(
					$agent,
					$task['prompt'],
					$task['id'],
					array(
						'kind'       => 'routine',
						'source_ref' => $source_ref,
					)
				);
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

			// Reflect the outcome back into the routine's Deployments mirror row so
			// Routines::list() shows a fresh last_run / last_status.
			self::record_routine_completion( $deployment_id, $result );
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

			self::record_routine_completion( $deployment_id, null, 'error' );

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
	 * Write a routine's completion outcome back into its Deployments mirror row.
	 *
	 * @param int|null   $deployment_id Deployments row id, or null when the task has no mirror.
	 * @param array|null $result        run_autonomous_task() result (null when no run was begun).
	 * @param string     $status        'completed' or 'error'.
	 * @return void
	 */
	private static function record_routine_completion( ?int $deployment_id, ?array $result, string $status = 'completed' ): void {
		if ( null === $deployment_id ) {
			return;
		}

		Deployments::update_config(
			$deployment_id,
			array(
				'last_run'    => current_time( 'mysql' ),
				'last_status' => $status,
				'last_run_id' => $result['run_id'] ?? null,
			)
		);
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
				'source'   => 'user',
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
		$agent_id = $agent->get_id();

		// Never re-enter: a listener (or a tool it runs) that writes an option and
		// re-fires this same hook must not recurse.
		if ( self::$listener_in_flight ) {
			return;
		}

		// A paused user-defined trigger (Routines::pause()) must not fire. Built-in
		// manifest listeners carry no 'source' => 'user' and pass through untouched.
		// This gate is the real enforcement point for the event-listener flavour of
		// a routine — not a display-only flag.
		if ( 'user' === ( $listener['source'] ?? '' )
			&& class_exists( Routines::class )
			&& Routines::is_event_listener_paused( (string) ( $listener['id'] ?? '' ) ) ) {
			return;
		}

		// Writes made by Agent Builder itself (its own options and transients)
		// never reach a listener.
		if ( self::is_internal_option_event( (string) ( $listener['hook'] ?? '' ), $args[0] ?? null ) ) {
			return;
		}

		// Manifest argument filter — irrelevant events never reach the gate.
		if ( ! self::listener_arg_matches( $listener, $args ) ) {
			return;
		}

		// Per-listener rate limit (skipped fires are counted, not audit-logged).
		if ( ! self::claim_listener_execution( $agent_id, (string) ( $listener['id'] ?? '' ), self::listener_min_interval( $listener ) ) ) {
			self::increment_listener_skip_count( $agent_id, (string) ( $listener['id'] ?? '' ) );
			return;
		}

		self::$listener_in_flight = true;

		$audit = new Audit_Log();
		$start = microtime( true );

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
		} finally {
			self::$listener_in_flight = false;
		}
	}

	/**
	 * Whether a hook firing with this first argument is a write to an option or
	 * transient owned by Agent Builder itself, and must therefore be ignored by
	 * every listener.
	 *
	 * @param string $hook Listener hook name.
	 * @param mixed  $arg  First hook argument (the option name for option hooks).
	 * @return bool
	 */
	private static function is_internal_option_event( string $hook, $arg ): bool {
		if ( ! in_array( $hook, array( 'updated_option', 'added_option', 'deleted_option' ), true ) ) {
			return false;
		}
		if ( ! is_string( $arg ) || '' === $arg ) {
			return false;
		}
		foreach ( self::INTERNAL_OPTION_PREFIXES as $prefix ) {
			if ( 0 === strpos( $arg, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply the manifest-level argument filter, if the listener declares one.
	 *
	 * A listener may declare `arg_filter` in one of two forms — an exact-match
	 * allowlist (`arg` + `in`) or a PCRE pattern (`arg` + `pattern`) — so that
	 * only hook arguments matching it ever reach the gate. High-frequency hooks
	 * (updated_option, init, save_post…) otherwise hit the approval gate for
	 * every irrelevant event.
	 *
	 * @param array $listener Listener definition.
	 * @param array $args     WordPress hook arguments.
	 * @return bool True when the listener should run (no filter, or filter matches).
	 */
	private static function listener_arg_matches( array $listener, array $args ): bool {
		$filter = $listener['arg_filter'] ?? null;
		if ( ! is_array( $filter ) ) {
			return true;
		}

		$index = (int) ( $filter['arg'] ?? 0 );
		$value = $args[ $index ] ?? '';
		if ( is_object( $value ) || is_array( $value ) ) {
			$value = '';
		}
		$value = (string) $value;

		// Exact-match allowlist form: strict string comparison against the listed
		// values. An empty `in` list matches nothing, so the listener never runs.
		if ( array_key_exists( 'in', $filter ) ) {
			$allowlist = is_array( $filter['in'] ) ? $filter['in'] : array();
			return in_array( $value, $allowlist, true );
		}

		// PCRE pattern form. The pattern is validated at manifest-sanitization
		// time; a missing/empty pattern means "no filter" (run on every event),
		// and @ guards against a stray invalid pattern, which we treat as no-match.
		$pattern = (string) ( $filter['pattern'] ?? '' );
		if ( '' === $pattern ) {
			return true;
		}

		$result = @preg_match( '/' . $pattern . '/', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid pattern is "no match", never a fatal.

		return 1 === $result;
	}

	/**
	 * Resolve a listener's rate-limit interval in seconds.
	 *
	 * @param array $listener Listener definition.
	 * @return int Seconds; 0 disables rate limiting.
	 */
	private static function listener_min_interval( array $listener ): int {
		$interval = (int) ( $listener['min_interval'] ?? self::DEFAULT_LISTENER_MIN_INTERVAL );
		if ( $interval <= 0 ) {
			return 0;
		}
		return min( $interval, 86400 );
	}

	/**
	 * Atomically claim this listener's execution window.
	 *
	 * The claim is a single wp_options row written via add_option(), whose
	 * duplicate-key failure is the atomic "someone already claimed this window"
	 * signal — unlike a transient's get-then-set there is no read-modify-write
	 * race. Once the stored timestamp is older than `$min_interval` the claim is
	 * taken over with an update.
	 *
	 * @param string $agent_id    Agent slug.
	 * @param string $listener_id Listener id.
	 * @param int    $min_interval Seconds between executions (0 = no limit).
	 * @return bool True when this fire may proceed.
	 */
	private static function claim_listener_execution( string $agent_id, string $listener_id, int $min_interval ): bool {
		if ( $min_interval <= 0 || '' === $listener_id ) {
			return true;
		}

		$key = 'agentic_listener_rate_' . md5( $agent_id . '|' . $listener_id );
		$now = time();

		// Atomic acquire: add_option() inserts only when the option is absent.
		$added = add_option( $key, $now, '', 'no' );
		if ( $added ) {
			return true;
		}

		$last = (int) get_option( $key, 0 );
		if ( $now - $last >= $min_interval ) {
			// Window elapsed — refresh the claim. Two requests racing to refresh
			// an expired claim may both pass, which is acceptable for a rate limiter.
			update_option( $key, $now, 'no' );
			return true;
		}

		return false;
	}

	/**
	 * Count a rate-limited (skipped) listener fire.
	 *
	 * Skipped fires are aggregated into a single counter option, never written as
	 * individual audit-log rows.
	 *
	 * @param string $agent_id    Agent slug.
	 * @param string $listener_id Listener id.
	 * @return void
	 */
	private static function increment_listener_skip_count( string $agent_id, string $listener_id ): void {
		$key   = 'agentic_listener_skips_' . md5( $agent_id . '|' . $listener_id );
		$count = (int) get_option( $key, 0 );
		update_option( $key, $count + 1, 'no' );
	}

	/**
	 * Read how many times a listener's fire has been skipped by the rate limit.
	 *
	 * @param string $agent_id    Agent slug.
	 * @param string $listener_id Listener id.
	 * @return int Skip count.
	 */
	public static function get_listener_skip_count( string $agent_id, string $listener_id ): int {
		return (int) get_option( 'agentic_listener_skips_' . md5( $agent_id . '|' . $listener_id ), 0 );
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
	 * @param Agent_Controller|null $controller Optional controller (tests inject a fake-LLM one).
	 * @return void
	 */
	public static function handle_async_event( string $agent_id, string $listener_id, string $prompt, array $hook_args, ?Agent_Controller $controller = null ): void {
		\Agentic\Plugin::get_instance()->load_chat_components();

		$registry = \Agentic_Agent_Registry::get_instance();
		$agent    = $registry->get_agent_instance( $agent_id );
		$audit    = new Audit_Log();

		if ( ! $agent ) {
			$audit->log( $agent_id, 'event_listener_error', $listener_id, array( 'error' => 'Agent not found for async event' ) );
			return;
		}

		// Resolve the Deployments mirror row for this trigger, if one exists, so
		// the run's source_ref carries that row's integer id (what
		// Routines::history() queries) instead of the option-backed trigger id.
		// Built-in manifest listeners have no mirror row and keep the listener:
		// prefix.
		$deployment_id = class_exists( Routines::class )
			? Routines::deployment_id_for_trigger( $listener_id )
			: null;
		$source_ref    = null !== $deployment_id
			? 'routine:' . $deployment_id
			: 'listener:' . $listener_id;

		// Build context-enriched prompt.
		$context_json = wp_json_encode( $hook_args, JSON_PRETTY_PRINT );
		$full_prompt  = $prompt . "\n\n[EVENT CONTEXT]\n" . $context_json;

		$start = microtime( true );

		try {
			$controller = $controller ?? new Agent_Controller();
			$controller->set_invocation_context( 'hook' );
			$result = $controller->run_autonomous_task(
				$agent,
				$full_prompt,
				'event_' . $listener_id,
				array(
					'kind'       => 'event',
					'source_ref' => $source_ref,
				)
			);

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

			self::record_routine_completion( $deployment_id, $result );
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

			self::record_routine_completion( $deployment_id, null, 'error' );
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

		// Pass the listener id through so the gate can dedupe repeat confirmation
		// proposals from the same listener + tool (hook context only).
		$listener_id = 'hook' === $context ? (string) ( $spec['id'] ?? '' ) : '';

		$executor = new Tool_Executor( Tool_Loader::get_instance(), new Audit_Log() );
		return $executor->execute( $tool, $arguments, $agent->get_id(), $mode, $context, $agent, '', null, $listener_id );
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
