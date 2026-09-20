<?php
/**
 * Guards against paid-upgrade promotion in anything an agent can read.
 *
 * Pro upsell surfaces and pricing URLs were removed from the free build in
 * c19d845, but five instructions telling agents to recommend Agent Builder Pro
 * survived in content the UI never renders: bundled system prompts, the shared
 * knowledge file, and two SKILL.md availability notes. A full prompt-test sweep
 * caught one of them in the act — asked to add a chat widget, Agent Orchestrator
 * reported "no agents available" on a site running twelve and suggested buying
 * Pro.
 *
 * That is a WordPress.org guideline risk the admin screens cannot show, because
 * the promotion is in the model's instructions rather than in any template. This
 * test reads the same files the prompt builder does.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

/**
 * Test case for upsell-free agent content.
 */
class Test_No_Upsell_In_Agent_Content extends TestCase {

	/**
	 * Phrases that promote a paid tier or another product.
	 *
	 * Matched case-insensitively against agent-readable content only. Source
	 * comments and developer docs are out of scope — the model never sees them.
	 *
	 * @var string[]
	 */
	private const UPSELL_PATTERNS = array(
		'agent builder pro',
		'upgrade to pro',
		'pro version',
		'premium plan',
		'paid plan',
	);

	/**
	 * Every file whose contents can reach a model's context.
	 *
	 * @return array<int, array{0:string}>
	 */
	public function agent_readable_files(): array {
		$files = array_merge(
			(array) glob( AGENT_BUILDER_DIR . 'library/agents/*/templates/*.txt' ),
			(array) glob( AGENT_BUILDER_DIR . 'library/agents/*/agent.json' ),
			(array) glob( AGENT_BUILDER_DIR . 'library/agents/*/abilities.json' ),
			(array) glob( AGENT_BUILDER_DIR . 'library/knowledge/*.txt' ),
			(array) glob( AGENT_BUILDER_DIR . 'library/skills/*/SKILL.md' )
		);

		$cases = array();

		foreach ( array_filter( $files, 'is_file' ) as $file ) {
			$cases[ str_replace( AGENT_BUILDER_DIR, '', $file ) ] = array( $file );
		}

		return $cases;
	}

	/**
	 * No agent-readable file promotes a paid upgrade.
	 *
	 * @dataProvider agent_readable_files
	 *
	 * @param string $file Absolute path.
	 */
	public function test_file_does_not_promote_a_paid_upgrade( string $file ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled file read as data.
		$contents = strtolower( (string) file_get_contents( $file ) );
		$relative = str_replace( AGENT_BUILDER_DIR, '', $file );

		foreach ( self::UPSELL_PATTERNS as $pattern ) {
			$this->assertStringNotContainsString(
				$pattern,
				$contents,
				sprintf(
					'%s tells an agent about "%s". Agents repeat what their instructions say, so a paid-upgrade '
					. 'mention here becomes an unprompted upsell to a site owner — the surface c19d845 removed.',
					$relative,
					$pattern
				)
			);
		}
	}

	/**
	 * Agent Orchestrator can see the agents installed on the site.
	 *
	 * Its manifest declared agents_available (a catalogue of agents available to
	 * *download*, with pricing) but not get_agent_list (what is installed here),
	 * so it concluded a fully populated site had no agents at all.
	 */
	public function test_agent_orchestrator_can_list_installed_agents(): void {
		$manifest = json_decode(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled manifest read as data.
			(string) file_get_contents( AGENT_BUILDER_DIR . 'library/agents/agent-orchestrator/agent.json' ),
			true
		);

		$this->assertContains(
			'get_agent_list',
			(array) ( $manifest['tools'] ?? array() ),
			'Agent Orchestrator deploys agents, so it has to be able to see which ones exist. '
			. 'agents_available is the download catalogue and cannot answer that.'
		);
	}

	/**
	 * Content Writer owns the media tools it declares.
	 *
	 * It refused to set a featured image as "outside my scope" while holding
	 * both tools for the job, so the scope wording has to name them.
	 */
	public function test_content_writer_scope_covers_its_media_tools(): void {
		$manifest = json_decode(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled manifest read as data.
			(string) file_get_contents( AGENT_BUILDER_DIR . 'library/agents/content-writer/agent.json' ),
			true
		);
		$tools    = (array) ( $manifest['tools'] ?? array() );

		$this->assertContains( 'set_featured_image', $tools, 'precondition' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled prompt read as data.
		$prompt = (string) file_get_contents( AGENT_BUILDER_DIR . 'library/agents/content-writer/templates/system-prompt.txt' );

		$this->assertStringContainsString(
			'set_featured_image',
			$prompt,
			"Content Writer holds set_featured_image but called featured images out of scope. "
			. 'The prompt must say the media tools it declares are its job.'
		);
	}
}
