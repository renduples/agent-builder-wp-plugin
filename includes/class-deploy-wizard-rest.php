<?php
/**
 * REST controller backing the "Publish your agent" wizard.
 *
 * Exposes two admin-only endpoints under the agentic/v1 namespace:
 *   - GET  /deploy-wizard/options : agents to publish, Ask AI launcher screen
 *     catalog, and current site-wide launcher state.
 *   - POST /deploy-wizard/save    : turn one surface on for one agent.
 *
 * There is no existing REST/AJAX route for any of the four classic
 * Publish tabs (Admin bar, Ask AI launchers, Gutenberg blocks, Frontend
 * modal) — each is saved by a classic form POST handled inline in its own
 * admin/deployment/*.php template. Those forms are also destructive: any
 * agent slug omitted from the submitted array is explicitly disabled. This
 * controller never replays those forms; it calls the exact same storage
 * primitives (Agent_Settings, the same wp_options keys, Deployments
 * bookkeeping) directly, always reading current state first so enabling a
 * surface for one agent never disables it for any other agent already
 * using that surface.
 *
 * @package Agent_Builder
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publish (deploy) Wizard REST controller.
 */
class Deploy_Wizard_REST {

	const NS = 'agentic/v1';

	/**
	 * Surface keys this wizard can turn on, mapped to their classic Publish tab.
	 *
	 * @var array<string,string>
	 */
	const SURFACE_TABS = array(
		'chat_widget'     => 'website',
		'admin_bar'       => 'admin-bar',
		'ask_ai'          => 'admin-bar',
		'gutenberg_block' => 'gutenberg-blocks',
	);

	/**
	 * Register the rest_api_init hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the wizard routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/deploy-wizard/options',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_options' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/deploy-wizard/save',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Publishing changes what runs on every admin screen and the front end,
	 * so this flow is restricted to full administrators (same gate the
	 * classic Publish tabs use).
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Form data for the wizard: which agents can be published, and the Ask
	 * AI launcher screen catalog + current site-wide launcher state.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_options(): \WP_REST_Response {
		$agents = array();
		if ( class_exists( '\Agentic_Agent_Registry' ) ) {
			foreach ( \Agentic_Agent_Registry::get_instance()->get_accessible_instances() as $agent ) {
				$agents[] = array(
					'slug' => $agent->get_id(),
					'name' => $agent->get_name(),
					'icon' => $agent->get_icon(),
				);
			}
		}

		$screens = array();
		if ( class_exists( Admin_Surfaces::class ) ) {
			foreach ( Admin_Surfaces::available_screens() as $key => $info ) {
				$screens[] = array(
					'key'         => $key,
					'label'       => (string) ( $info['label'] ?? $key ),
					'description' => (string) ( $info['description'] ?? '' ),
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'agents'                 => $agents,
				'ask_ai_screens'         => $screens,
				'ask_ai_current_agent'   => (string) get_option( Admin_Surfaces::OPTION_AGENT, 'wordpress-assistant' ),
				'ask_ai_screens_are_set' => null !== get_option( Admin_Surfaces::OPTION_SCREENS, null ),
			),
			200
		);
	}

	/**
	 * Turn one surface on for one agent, using each surface's existing
	 * storage primitives with a read-merge-write so other agents already
	 * configured on that surface are left untouched.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'agent' ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'missing_agent', __( 'Choose an agent to publish.', 'agent-builder' ), array( 'status' => 400 ) );
		}
		$accessible = class_exists( '\Agentic_Agent_Registry' )
			? \Agentic_Agent_Registry::get_instance()->get_accessible_instances()
			: array();
		if ( ! isset( $accessible[ $slug ] ) ) {
			return new \WP_Error( 'invalid_agent', __( 'That agent is not active.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$surface = sanitize_key( (string) $request->get_param( 'surface' ) );
		if ( ! isset( self::SURFACE_TABS[ $surface ] ) ) {
			return new \WP_Error( 'invalid_surface', __( 'Choose where to publish this agent.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$config = (array) $request->get_param( 'config' );

		switch ( $surface ) {
			case 'chat_widget':
				self::enable_modal( $slug, $config );
				break;
			case 'admin_bar':
				self::enable_admin_bar( $slug, $config );
				break;
			case 'ask_ai':
				self::enable_ask_ai( $slug, $config );
				break;
			case 'gutenberg_block':
				self::enable_gutenberg( $slug );
				break;
		}

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'surface'    => $surface,
				'manage_url' => admin_url( 'admin.php?page=agentic-deployment&tab=' . self::SURFACE_TABS[ $surface ] ),
			),
			201
		);
	}

	/**
	 * Enable the floating chat widget (modal) for one agent.
	 *
	 * @param string               $slug   Agent slug.
	 * @param array<string,string> $config Position/pages/require_login.
	 * @return void
	 */
	private static function enable_modal( string $slug, array $config ): void {
		$position      = sanitize_key( (string) ( $config['position'] ?? 'bottom-right' ) );
		$pages         = sanitize_key( (string) ( $config['pages'] ?? 'all' ) );
		$require_login = ! empty( $config['require_login'] ) ? '1' : '0';
		if ( ! in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ) {
			$position = 'bottom-right';
		}
		if ( ! in_array( $pages, array( 'all', 'front', 'singular', 'homepage' ), true ) ) {
			$pages = 'all';
		}

		$agents = (array) get_option( 'agent_builder_modal_agents', array() );
		if ( ! in_array( $slug, $agents, true ) ) {
			$agents[] = $slug;
		}
		update_option( 'agent_builder_modal_agents', array_values( array_unique( $agents ) ), false );

		$all_config          = (array) get_option( 'agent_builder_modal_config', array() );
		$all_config[ $slug ] = array(
			'position'      => $position,
			'pages'         => $pages,
			'require_login' => $require_login,
		);
		update_option( 'agent_builder_modal_config', $all_config, false );

		if ( class_exists( Deployments::class ) ) {
			$id = Deployments::auto_register( Deployments::TYPE_MODAL, $slug, $all_config[ $slug ] );
			Deployments::enable( $id );
		}
	}

	/**
	 * Enable the admin-bar toolbar chat menu item for one agent.
	 *
	 * @param string               $slug   Agent slug.
	 * @param array<string,string> $config Position/pages.
	 * @return void
	 */
	private static function enable_admin_bar( string $slug, array $config ): void {
		$position = sanitize_key( (string) ( $config['position'] ?? 'bottom-right' ) );
		$pages    = sanitize_key( (string) ( $config['pages'] ?? 'all' ) );
		if ( ! in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ) {
			$position = 'bottom-right';
		}
		if ( ! in_array( $pages, array( 'all', 'admin', 'front' ), true ) ) {
			$pages = 'all';
		}

		Agent_Settings::update( $slug, 'admin_bar_display', '1' );
		Agent_Settings::update( $slug, 'admin_bar_position', $position );
		Agent_Settings::update( $slug, 'admin_bar_pages', $pages );

		if ( class_exists( Deployments::class ) ) {
			$id = Deployments::auto_register(
				Deployments::TYPE_ADMIN_BAR,
				$slug,
				array(
					'position' => $position,
					'pages'    => $pages,
				)
			);
			Deployments::enable( $id );
		}
	}

	/**
	 * Enable contextual "Ask AI" launchers on admin screens, with this
	 * agent as the one that answers them.
	 *
	 * The screen list is site-wide, not per-agent: only initialize it here
	 * on a genuinely first-time setup (option never saved) so we never
	 * narrow a site's existing screen selection. Un-set defaults to every
	 * screen (Admin_Surfaces::enabled_screens()), so leaving it alone is safe.
	 *
	 * @param string  $slug   Agent slug.
	 * @param mixed[] $config Optional 'screens' array (first-time only).
	 * @return void
	 */
	private static function enable_ask_ai( string $slug, array $config ): void {
		update_option( Admin_Surfaces::OPTION_ENABLED, '1' );
		update_option( Admin_Surfaces::OPTION_AGENT, $slug, false );

		if ( null === get_option( Admin_Surfaces::OPTION_SCREENS, null ) ) {
			$allowed = array_keys( Admin_Surfaces::available_screens() );
			$screens = array_values(
				array_intersect(
					array_map( 'sanitize_key', (array) ( $config['screens'] ?? array() ) ),
					$allowed
				)
			);
			if ( empty( $screens ) ) {
				$screens = $allowed;
			}
			update_option( Admin_Surfaces::OPTION_SCREENS, $screens, false );
		}

		// Unlike the other three surfaces, Ask AI launchers are plain global
		// options, not a Deployments row — so it doesn't get Deployments::
		// enable()'s automatic 'deployment_enabled' audit entry. Log it
		// directly so this surface isn't the one silent path.
		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin(
				'deployment_enabled',
				'ask-ai',
				array(
					'agent' => $slug,
					'via'   => 'deploy_wizard',
				)
			);
		}
	}

	/**
	 * Enable this agent as a selectable Gutenberg block.
	 *
	 * @param string $slug Agent slug.
	 * @return void
	 */
	private static function enable_gutenberg( string $slug ): void {
		$agents = (array) get_option( 'agent_builder_gutenberg_block_agents', array() );
		if ( ! in_array( $slug, $agents, true ) ) {
			$agents[] = $slug;
		}
		update_option( 'agent_builder_gutenberg_block_agents', array_values( array_unique( $agents ) ) );

		if ( class_exists( Deployments::class ) ) {
			$id = Deployments::auto_register( Deployments::TYPE_GUTENBERG, $slug, array() );
			Deployments::enable( $id );
		}
	}
}
