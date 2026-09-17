<?php
/**
 * Regression test: every tool this build actually loads must have a
 * deliberate risk decision behind it.
 *
 * Tool_Base::get_risk_level() defaults to Risk_Level::get_tool_default(),
 * which is registry-value-or-NONE, floored by Risk_Level::BASELINE_RISKS.
 * A tool that neither overrides get_risk_level() itself NOR has a
 * BASELINE_RISKS entry seeds itself as risk 'none' the moment it exists with
 * no row in wp_agent_builder_tools — which is exactly what runs with zero
 * confirmation, even in supervised mode (Risk_Level::enforcement()).
 *
 * This is the regression net for the ~70-tool gap the #116/#117 risk-gate
 * audit found: a *write* tool silently defaulting to risk 'none' because
 * nothing had ever asserted otherwise. Genuine read-only tools (declared via
 * get_annotations()['readonly'], or the legacy 'read_only' key some earlier
 * tools shipped with — see the BASELINE_RISKS comment on list_okf_concepts
 * et al. for that history) are exempt: NONE is the *correct*, deliberate
 * risk for something that only ever reads. The failure mode this guards
 * against is a tool that both (a) is not declared read-only and (b) has no
 * risk decision at all — get_risk_level() override or BASELINE_RISKS entry
 * — which is exactly how a write tool ends up executing with zero
 * confirmation, even in supervised mode.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Risk_Level;
use Agentic\Tool_Base;
use Agentic\Tool_Loader;

/**
 * Test case enforcing a declared risk floor for every loaded tool.
 */
class Test_Tool_Risk_Floor_Coverage extends TestCase {

	/**
	 * Every standalone tool this build loads must either override
	 * get_risk_level() itself, or be listed in Risk_Level::BASELINE_RISKS.
	 */
	public function test_every_loaded_tool_has_a_declared_risk_floor(): void {
		$loader = Tool_Loader::get_instance();
		$loader->load();
		$tools = $loader->get_all();

		$this->assertNotEmpty( $tools, 'precondition: the tool loader must have loaded at least one tool' );

		$baseline   = Risk_Level::get_baseline_risks();
		$undeclared = array();

		foreach ( $tools as $name => $tool ) {
			if ( $this->is_declared_readonly( $tool ) ) {
				continue;
			}
			if ( $this->overrides_get_risk_level( $tool ) ) {
				continue;
			}
			if ( array_key_exists( $name, $baseline ) ) {
				continue;
			}
			$undeclared[] = $name;
		}

		sort( $undeclared );

		$this->assertSame(
			array(),
			$undeclared,
			"The following tools have no get_risk_level() override and no Risk_Level::BASELINE_RISKS entry, " .
			"so they silently default to risk 'none' with zero confirmation: " . implode( ', ', $undeclared )
		);
	}

	/**
	 * Every tool actually present under library/tools/ is either loaded by
	 * Tool_Loader (and therefore covered by the assertion above) or is
	 * explicitly Pro-only (is_pro_only() === true, excluded from this free
	 * build's runtime entirely). This closes the gap the first test can't
	 * see on its own: a tool directory that fails to load at all (e.g. a
	 * fatal typo in its class name) would otherwise vanish from coverage
	 * silently instead of failing loudly.
	 */
	public function test_every_tool_directory_is_either_loaded_or_pro_only(): void {
		$loader = Tool_Loader::get_instance();
		$loader->load();
		$loaded_names = array_keys( $loader->get_all() );

		$tools_dir = AGENT_BUILDER_DIR . 'library/tools/';
		$dirs      = glob( $tools_dir . '*', GLOB_ONLYDIR );
		$this->assertNotEmpty( $dirs, 'precondition: library/tools/ must contain tool directories' );

		$missing = array();

		foreach ( $dirs as $dir ) {
			$slug      = basename( $dir );
			$tool_file = $dir . '/tool.php';
			if ( ! file_exists( $tool_file ) ) {
				continue;
			}
			if ( in_array( $slug, $loaded_names, true ) ) {
				continue;
			}
			// Not loaded — the only acceptable reason is that the tool
			// declares itself Pro-only. Instantiate a fresh copy via
			// reflection on the include return value to check, without
			// re-triggering "cannot redeclare class" (the class is already
			// declared from Tool_Loader::load()'s own include_once above).
			$instance = $this->get_already_declared_instance( $tool_file );
			if ( $instance instanceof Tool_Base && $instance->is_pro_only() ) {
				continue;
			}
			$missing[] = $slug;
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			'The following tool directories exist under library/tools/ but did not load and are not ' .
			'marked Pro-only — a fatal error or naming mismatch is silently hiding them from risk-floor ' .
			'coverage: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Whether a tool declares itself read-only via get_annotations().
	 *
	 * Checks both 'readonly' (the key Tool_Executor actually reads) and the
	 * legacy 'read_only' key a handful of earlier tools shipped with — see
	 * the BASELINE_RISKS comment on list_okf_concepts et al.
	 *
	 * @param Tool_Base $tool Tool instance.
	 * @return bool
	 */
	private function is_declared_readonly( Tool_Base $tool ): bool {
		$annotations = $tool->get_annotations();
		return ! empty( $annotations['readonly'] ) || ! empty( $annotations['read_only'] );
	}

	/**
	 * Whether $tool's class (or any parent up to but excluding Tool_Base)
	 * declares its own get_risk_level().
	 *
	 * @param Tool_Base $tool Tool instance.
	 * @return bool
	 */
	private function overrides_get_risk_level( Tool_Base $tool ): bool {
		$method = new \ReflectionMethod( $tool, 'get_risk_level' );
		return Tool_Base::class !== $method->getDeclaringClass()->getName();
	}

	/**
	 * Re-run a tool.php file's final `return new Class_Name();` expression
	 * without re-declaring the class (already declared via include_once in
	 * Tool_Loader::load()). Parses the class name out of the file's last
	 * `return new X();` statement and instantiates it directly.
	 *
	 * @param string $tool_file Path to tool.php.
	 * @return Tool_Base|null
	 */
	private function get_already_declared_instance( string $tool_file ): ?Tool_Base {
		$contents = file_get_contents( $tool_file );
		if ( false === $contents ) {
			return null;
		}
		if ( ! preg_match( '/return\s+new\s+([\\\\A-Za-z0-9_]+)\s*\(/', $contents, $m ) ) {
			return null;
		}
		$class = $m[1];
		if ( '\\' !== $class[0] ) {
			// tool.php files declare `namespace Agentic\Tools;`.
			$class = 'Agentic\\Tools\\' . ltrim( $class, '\\' );
		} else {
			$class = ltrim( $class, '\\' );
		}
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$instance = new $class();
		return $instance instanceof Tool_Base ? $instance : null;
	}
}
