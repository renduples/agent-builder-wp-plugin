<?php
/**
 * Output-hardening tests (WordPress.org review, workstream C).
 *
 * Covers the places where stored data reaches a <script> or <style> context:
 * native-form conditional-logic rules, per-form CSS, the global chat
 * appearance overrides and the Inline_Assets helper.
 *
 * @package Agentic\Tests
 */

namespace Agentic\Tests;

use Agentic\Chat_Assets;
use Agentic\Inline_Assets;

/**
 * Test case for script/style output hardening.
 */
class Test_Output_Hardening extends TestCase {

	/**
	 * Reset option and script state between tests.
	 */
	public function tearDown(): void {
		delete_option( 'agent_builder_global_accent' );
		delete_option( 'agent_builder_global_font' );
		parent::tearDown();
	}

	/**
	 * Inline_Assets::json() never emits a literal "<", ">" or quote, so a
	 * value can never close a script element.
	 */
	public function test_json_cannot_break_out_of_script(): void {
		$json = Inline_Assets::json( array( 'v' => '</script><script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<', $json );
		$this->assertStringNotContainsString( '>', $json );
		$this->assertStringContainsString( '\\u003C/script\\u003E', $json );
		$this->assertSame( '</script><script>alert(1)</script>', json_decode( $json, true )['v'] );
	}

	/**
	 * Stored conditions are re-validated: bad operators/actions are dropped,
	 * names are sanitize_key()'d and the value is plain text.
	 */
	public function test_conditions_are_validated(): void {
		$stored = wp_json_encode(
			array(
				array(
					'field'    => 'topic',
					'operator' => 'equals',
					'value'    => '</script><script>alert(1)</script>',
					'target'   => 'details',
					'action'   => 'show',
				),
				array(
					'field'    => 'x"]<b>',
					'operator' => 'equals',
					'value'    => 'a',
					'target'   => 'y',
					'action'   => 'show',
				),
				array(
					'field'    => 'a',
					'operator' => 'eval',
					'target'   => 'b',
					'action'   => 'show',
				),
				array(
					'field'    => 'a',
					'operator' => 'empty',
					'target'   => 'b',
					'action'   => 'explode',
				),
				'not-an-object',
			)
		);

		$rules = \Agentic_Native_Forms::sanitize_conditions( $stored );

		$this->assertCount( 2, $rules );
		$this->assertSame( 'topic', $rules[0]['field'] );
		$this->assertStringNotContainsString( '<', $rules[0]['value'] );
		$this->assertSame( 'xb', $rules[1]['field'] );
		$this->assertSame( array(), \Agentic_Native_Forms::sanitize_conditions( '{not json' ) );
		$this->assertSame( array(), \Agentic_Native_Forms::sanitize_conditions( '' ) );
	}

	/**
	 * The conditions reach the page through the script queue, hex-encoded,
	 * and never as a raw <script type="application/json"> island.
	 */
	public function test_shortcode_conditions_cannot_break_out_of_script(): void {
		$form_id = self::factory()->post->create(
			array(
				'post_type'   => \Agentic_Native_Forms::FORM_CPT,
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$form_id,
			\Agentic_Native_Forms::META_DEFINITION,
			wp_json_encode(
				array(
					'fields' => array(
						array(
							'name'  => 'topic',
							'label' => 'Topic',
							'type'  => 'text',
						),
					),
				)
			)
		);
		update_post_meta(
			$form_id,
			\Agentic_Native_Forms::META_CONDITIONS,
			'[{"field":"topic","operator":"equals","value":"</script><img src=x onerror=alert(1)>","target":"topic","action":"show"}]'
		);

		$html = \Agentic_Native_Forms::get_instance()->render_shortcode( array( 'id' => $form_id ) );
		if ( false !== strpos( $html, 'class="agentic-form-error"' ) ) {
			$this->fail( 'Form did not render: ' . $html );
		}

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '</script', $html );

		$inline = (string) wp_scripts()->get_inline_script_data( 'agentic-native-forms', 'before' );
		$this->assertStringContainsString( 'agenticFormConditions', $inline );
		$this->assertStringNotContainsString( '</script', $inline );
		$this->assertStringNotContainsString( '<img', $inline );
	}

	/**
	 * Per-form CSS only contains validated values.
	 */
	public function test_form_css_rejects_invalid_values(): void {
		$css = \Agentic_Native_Forms::build_form_css(
			7,
			array(
				'label_color'       => '#ff0000',
				'button_background' => 'red;}</style><script>alert(1)</script>',
				'font_family'       => 'Arial; background:url(x)',
				'max_width'         => '600px',
				'form_padding'      => '8px 16px',
				'input_padding'     => 'expression(alert(1))',
				'form_border'       => '1px solid #ccc',
				'input_focus_color' => '#2271b1',
			)
		);

		$this->assertStringContainsString( 'color:#ff0000', $css );
		$this->assertStringContainsString( 'max-width:600px', $css );
		$this->assertStringContainsString( 'padding:8px 16px', $css );
		$this->assertStringContainsString( 'border:1px solid #ccc', $css );
		$this->assertStringContainsString( '#2271b133', $css );
		$this->assertStringNotContainsString( '<', $css );
		$this->assertStringNotContainsString( 'script', $css );
		$this->assertStringNotContainsString( 'url(', $css );
		$this->assertStringNotContainsString( 'expression', $css );
		$this->assertSame( '', \Agentic_Native_Forms::build_form_css( 7, array( 'label_color' => 'javascript:1' ) ) );
	}

	/**
	 * Global appearance overrides accept only a hex accent and an
	 * allow-listed font; anything else produces no CSS.
	 */
	public function test_appearance_overrides_reject_injection(): void {
		update_option( 'agent_builder_global_accent', '#123456;}</style><script>alert(1)</script>' );
		update_option( 'agent_builder_global_font', 'Comic Sans; } body { display:none' );
		$this->assertSame( '', Chat_Assets::global_appearance_overrides( 'frontend' ) );

		update_option( 'agent_builder_global_accent', '#123456' );
		update_option( 'agent_builder_global_font', 'Georgia, serif' );
		$css = Chat_Assets::global_appearance_overrides( 'admin' );
		$this->assertStringContainsString( '--ac-accent:#123456', $css );
		$this->assertStringContainsString( '--ac-accent-rgb:18,52,86', $css );
		$this->assertStringContainsString( 'font-family:Georgia, serif', $css );
		$this->assertStringNotContainsString( '<', $css );
	}

	/**
	 * clean_css() makes "</style" impossible while keeping child combinators.
	 */
	public function test_clean_css_strips_style_close(): void {
		$this->assertSame( '.a > .b{color:red}/style', Inline_Assets::clean_css( '.a > .b{color:red}</style' ) );
	}
}
