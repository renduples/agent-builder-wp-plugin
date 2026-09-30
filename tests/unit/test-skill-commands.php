<?php
/**
 * Unit tests for Skill_Commands (M15-c) and its chat/REST wiring.
 *
 * The REST-level tests drive the real `/agentic/v1/chat` route through
 * `rest_get_server()->dispatch()` (the repo's established pattern for REST
 * behaviour), intercepting the outbound LLM request via `pre_http_request` to
 * inspect the exact prompt and user message the model sees. This proves three
 * end-to-end properties the unit `parse()` test cannot: an unknown `/<slug>`
 * reaches the model verbatim, a matched `/<slug>` injects the SKILL.md body
 * exactly once (and audits `skill_invoked`), and a hostile skill body is
 * stripped of raw HTML before it can reach the prompt.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Chat_Assets;
use Agentic\Manifest_Agent;
use Agentic\Provider_Registry;
use Agentic\Skill_Commands;
use Agentic\Skills_Registry;

/**
 * Covers the skill slash-command parser, palette entries, and chat injection.
 */
class Test_Skill_Commands extends TestCase {

	/**
	 * Agent slug the chat tests run under.
	 */
	private const AGENT = 'skill-commands-agent';

	/**
	 * Raw request bodies captured by the pre_http_request filter.
	 *
	 * @var string[]
	 */
	private static array $captured_bodies = array();

	/**
	 * The active capture-filter callback, so tearDown() can remove it.
	 *
	 * @var callable|null
	 */
	private static $capture_filter = null;

	/**
	 * Register the agent and clear any leaked capture state.
	 */
	public function setUp(): void {
		parent::setUp();
		self::$captured_bodies = array();
		self::$capture_filter = null;
		\Agentic\Skills_Registry::bust_cache();
		\Agentic_Agent_Registry::get_instance()->register( $this->make_agent( self::AGENT ) );
	}

	/**
	 * Drop the agent, provider/LLM config, and the capture filter.
	 */
	public function tearDown(): void {
		global $wpdb;

		\Agentic_Agent_Registry::get_instance()->unregister( self::AGENT );

		if ( null !== self::$capture_filter ) {
			remove_filter( 'pre_http_request', self::$capture_filter, 10 );
			self::$capture_filter = null;
		}
		MockWPFunctions::reset();

		delete_option( 'agent_builder_llm_provider' );
		delete_option( 'agent_builder_model' );
		delete_option( 'agent_builder_skills_default_shared' );
		Provider_Registry::save_api_key( 'openai', '' );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	// ── parse() ───────────────────────────────────────────────────────────

	/**
	 * parse() splits a `/slug args` message into slug + remainder.
	 */
	public function test_parse_splits_slug_and_args(): void {
		$this->assertSame(
			array( 'slug' => 'my-skill', 'args' => 'about pricing' ),
			Skill_Commands::parse( '/my-skill about pricing' )
		);
	}

	/**
	 * parse() returns empty args for a bare `/slug`.
	 */
	public function test_parse_slug_only_has_empty_args(): void {
		$this->assertSame(
			array( 'slug' => 'my-skill', 'args' => '' ),
			Skill_Commands::parse( '/my-skill' )
		);
	}

	/**
	 * parse() returns null for a plain (non-slash) message and never throws.
	 */
	public function test_parse_returns_null_for_plain_message(): void {
		$this->assertNull( Skill_Commands::parse( 'plain message' ) );
		$this->assertNull( Skill_Commands::parse( '' ) );
		$this->assertNull( Skill_Commands::parse( '   ' ) );
	}

	// ── slash palette without Pro ─────────────────────────────────────────

	/**
	 * get_slash_commands_for_js() lists an enabled skill even though the Pro
	 * Slash_Commands class is absent (free installs still get skill commands).
	 */
	public function test_get_slash_commands_for_js_includes_skill_without_pro(): void {
		$this->assertFalse(
			class_exists( '\\Agentic\\Slash_Commands' ),
			'precondition: this suite must not load the Pro Slash_Commands class'
		);

		Skills_Registry::create(
			array(
				'name'        => 'Pricing Skill',
				'description' => 'Works out prices',
				'content'     => '# Pricing',
				'agent_slug'  => '',
				'enabled'     => true,
			)
		);

		$commands = Chat_Assets::get_slash_commands_for_js( self::AGENT );
		$names    = wp_list_pluck( $commands, 'name' );

		$this->assertContains( 'pricing-skill', $names );

		// The entry must carry the server-side shape the JS palette expects.
		foreach ( $commands as $command ) {
			if ( 'pricing-skill' === $command['name'] ) {
				$this->assertFalse( $command['client_side'] );
				$this->assertTrue( $command['has_args'] );
				$this->assertSame( array( 'backend', 'frontend' ), $command['contexts'] );
			}
		}
	}

	// ── REST wiring ───────────────────────────────────────────────────────

	/**
	 * An unknown `/<slug>` prefix is left untouched: the message reaches the
	 * model verbatim and no `skill_invoked` audit is written.
	 */
	public function test_unknown_slug_leaves_message_untouched_via_rest(): void {
		$this->configure_llm_and_capture();
		$this->login_admin();

		$message = '/no-such-skill hello world';
		$this->request( 'POST', '/chat', array( 'message' => $message, 'agent_id' => self::AGENT ) );

		$body = $this->captured_body();
		$this->assertStringContainsString( $message, $body, 'an unknown /slug must not be consumed' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s AND agent_id = %s",
				'skill_invoked',
				self::AGENT
			)
		);
		$this->assertSame( 0, $count, 'an unknown /slug must not audit skill_invoked' );
	}

	/**
	 * A matched `/<slug>` strips the prefix, injects the SKILL.md body exactly
	 * once, and writes a `skill_invoked` audit row.
	 */
	public function test_matched_slug_injects_body_once_and_audits_via_rest(): void {
		Skills_Registry::create(
			array(
				'name'        => 'Pricing Skill',
				'description' => 'Works out prices',
				'content'     => "# Pricing\n\nUse FORTY_TWO_UNIQUE_MARKER.\n",
				'agent_slug'  => '',
				'enabled'     => true,
			)
		);

		$this->configure_llm_and_capture();
		$this->login_admin();

		$this->request( 'POST', '/chat', array( 'message' => '/pricing-skill about pricing', 'agent_id' => self::AGENT ) );

		$body = $this->captured_body();

		// The prefix is stripped, so the model sees only the remainder.
		$this->assertStringContainsString( 'about pricing', $body );
		$this->assertStringNotContainsString( 'pricing-skill about pricing', $body, 'the /slug prefix must be stripped before reaching the model' );

		// The body is injected exactly once (the index block carries only name + description).
		$this->assertSame( 1, substr_count( $body, 'FORTY_TWO_UNIQUE_MARKER' ), 'the SKILL.md body must be injected exactly once' );

		// The audit row is written.
		global $wpdb;
		$details = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT details FROM {$wpdb->prefix}agent_builder_audit_log WHERE action = %s AND agent_id = %s ORDER BY id DESC LIMIT 1",
				'skill_invoked',
				self::AGENT
			)
		);
		$this->assertStringContainsString( 'pricing-skill', $details );
	}

	/**
	 * A hostile SKILL.md body is stripped of raw HTML before it reaches the
	 * prompt (wp_strip_all_tags() removes the tags but keeps the text).
	 */
	public function test_hostile_skill_body_has_no_raw_html_via_rest(): void {
		Skills_Registry::create(
			array(
				'name'        => 'Hostile Skill',
				'description' => 'Misbehaves',
				'content'     => "<script>alert('xss')</script>\n\nXSS_UNIQUE_MARKER\n",
				'agent_slug'  => '',
				'enabled'     => true,
			)
		);

		$this->configure_llm_and_capture();
		$this->login_admin();

		$this->request( 'POST', '/chat', array( 'message' => '/hostile-skill do something', 'agent_id' => self::AGENT ) );

		$body = $this->captured_body();

		$this->assertStringNotContainsString( '<script>', $body, 'raw HTML must not reach the prompt' );
		$this->assertStringContainsString( 'XSS_UNIQUE_MARKER', $body, 'the sanitised body text should still be present' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Set the current user to a fresh administrator.
	 */
	private function login_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	/**
	 * Configure a real OpenAI provider and intercept the outbound completion,
	 * recording each request body so tests can assert on the prompt.
	 */
	private function configure_llm_and_capture(): void {
		self::$captured_bodies = array();

		update_option( 'agent_builder_llm_provider', 'openai' );
		update_option( 'agent_builder_model', 'gpt-4.1-mini' );
		Provider_Registry::upsert( array( 'slug' => 'openai', 'api_key' => 'test-key' ) );

		self::$capture_filter = static function ( $preempt, $args, $url ) {
			if ( isset( $args['body'] ) ) {
				self::$captured_bodies[] = (string) $args['body'];
			}
			return array(
				'body'     => wp_json_encode( Fake_LLM_Client::text_response( 'Done.' ) ),
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', self::$capture_filter, 10, 3 );
	}

	/**
	 * Concatenate every captured request body into one searchable string.
	 */
	private function captured_body(): string {
		return implode( "\n", self::$captured_bodies );
	}

	/**
	 * Dispatch a REST request against the agentic/v1 namespace.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route path (relative to /agentic/v1).
	 * @param array<string, mixed> $json   JSON body to send.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $json = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/agentic/v1' . $route );
		if ( ! empty( $json ) ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $json ) );
		}

		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Minimal Manifest_Agent test double, registered with the shared registry.
	 *
	 * @param string $slug Agent slug.
	 * @return Manifest_Agent
	 */
	private function make_agent( string $slug ): Manifest_Agent {
		return new Manifest_Agent(
			array(
				'slug' => $slug,
				'name' => 'Test Agent',
			),
			''
		);
	}
}
