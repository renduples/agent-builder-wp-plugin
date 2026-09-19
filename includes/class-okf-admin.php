<?php
/**
 * OKF admin AJAX handlers and migration helpers.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      3.1.0
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin surface for the free knowledge wiki (OKF).
 */
class Okf_Admin {

	/**
	 * Capability required to manage the wiki.
	 */
	const CAP = 'agent_builder_manage_settings';

	/**
	 * Register AJAX actions (optional; dispatcher also maps handlers).
	 */
	public static function register_ajax(): void {
		$actions = array(
			'agentic_okf_list',
			'agentic_okf_get',
			'agentic_okf_save',
			'agentic_okf_delete',
			'agentic_okf_search',
			'agentic_okf_import_persona',
			'agentic_okf_export',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( self::class, str_replace( 'agentic_okf_', 'ajax_', $action ) ) );
		}
	}

	/**
	 * Verify nonce + capability. Call before reading $_POST in each AJAX handler.
	 */
	private static function guard(): void {
		check_ajax_referer( 'agentic_okf' );
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'agent-builder' ), 403 );
		}
	}

	/**
	 * Read sanitized scope from POST after nonce verification.
	 */
	private static function post_scope(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() / check_ajax_referer called by each ajax_* method.
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'site';
		return '' !== $scope ? $scope : 'site';
	}

	/**
	 * AJAX: list concepts.
	 */
	public static function ajax_list(): void {
		self::guard();
		$scope = self::post_scope();
		Okf_Store::ensure_bundle( $scope );
		if ( 'site' === $scope ) {
			Okf_Store::seed_examples();
		}
		wp_send_json_success(
			array(
				'scope'    => $scope,
				'concepts' => Okf_Store::list_concepts( $scope, true ),
				'root'     => Okf_Store::bundle_dir( $scope ),
			)
		);
	}

	/**
	 * AJAX: get one concept.
	 */
	public static function ajax_get(): void {
		self::guard();
		$scope = self::post_scope();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id  = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$doc = Okf_Store::get_concept( $id, $scope );
		if ( is_wp_error( $doc ) ) {
			wp_send_json_error( $doc->get_error_message() );
		}
		wp_send_json_success( $doc );
	}

	/**
	 * AJAX: save concept.
	 */
	public static function ajax_save(): void {
		self::guard();
		$scope = self::post_scope();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		// Markdown body: strip only null bytes; content is stored as a local file, never SQL-interpolated.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$body_raw = isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$body = is_string( $body_raw ) ? str_replace( "\0", '', $body_raw ) : '';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$type          = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'FAQ';
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : $id;
		$description   = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		$status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'stable';
		$tags_raw      = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
		$example_raw   = isset( $_POST['example'] ) ? sanitize_text_field( wp_unslash( $_POST['example'] ) ) : '';
		$always_on_raw = isset( $_POST['always_on'] ) ? sanitize_text_field( wp_unslash( $_POST['always_on'] ) ) : '';
		$stale         = isset( $_POST['stale_after'] ) ? sanitize_text_field( wp_unslash( $_POST['stale_after'] ) ) : '';
		$resource      = isset( $_POST['resource'] ) ? esc_url_raw( wp_unslash( $_POST['resource'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$frontmatter = array(
			'type'        => $type,
			'title'       => $title,
			'description' => $description,
			'status'      => $status,
			'tags'        => array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', $tags_raw ) ) ) ),
		);

		if ( '1' === $example_raw ) {
			$frontmatter['example'] = true;
		}

		// Always-on concepts are injected into every agent system prompt.
		if ( '1' === $always_on_raw && '1' !== $example_raw ) {
			$frontmatter['always_on'] = true;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stale ) ) {
			$frontmatter['stale_after'] = $stale;
		}

		if ( '' !== $resource ) {
			$frontmatter['resource'] = $resource;
		}

		if ( '' === $id ) {
			$id = sanitize_title( $frontmatter['title'] );
		}

		$result = Okf_Store::save_concept( $id, $frontmatter, $body, $scope );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'id'       => $id,
				'concepts' => Okf_Store::list_concepts( $scope, true ),
			)
		);
	}

	/**
	 * AJAX: delete concept.
	 */
	public static function ajax_delete(): void {
		self::guard();
		$scope = self::post_scope();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$result = Okf_Store::delete_concept( $id, $scope );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success(
			array(
				'concepts' => Okf_Store::list_concepts( $scope, true ),
			)
		);
	}

	/**
	 * AJAX: search.
	 */
	public static function ajax_search(): void {
		self::guard();
		$scope = self::post_scope();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		wp_send_json_success(
			array(
				'hits' => Okf_Store::search( $query, $scope, 20, true ),
			)
		);
	}

	/**
	 * AJAX: import persona knowledge .txt into OKF.
	 */
	public static function ajax_import_persona(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$agent = isset( $_POST['agent'] ) ? sanitize_key( wp_unslash( $_POST['agent'] ) ) : '';
		if ( '' === $agent ) {
			wp_send_json_error( __( 'Choose an agent to import from.', 'agent-builder' ) );
		}

		$file = trailingslashit( AGENT_BUILDER_KNOWLEDGE_DIR ) . $agent . '-knowledge.txt';
		if ( ! file_exists( $file ) ) {
			wp_send_json_error( __( 'No persona knowledge file found for that agent.', 'agent-builder' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$text = trim( (string) file_get_contents( $file ) );
		if ( '' === $text ) {
			wp_send_json_error( __( 'The knowledge file is empty.', 'agent-builder' ) );
		}

		$result = Okf_Store::import_plain_text(
			sprintf(
				/* translators: %s: agent slug */
				__( 'Imported knowledge (%s)', 'agent-builder' ),
				$agent
			),
			$text,
			$agent
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Imported into the agent knowledge wiki.', 'agent-builder' ),
				'concepts' => Okf_Store::list_concepts( $agent, true ),
			)
		);
	}

	/**
	 * AJAX: export bundle as a concatenated markdown download payload.
	 */
	public static function ajax_export(): void {
		self::guard();
		$scope    = self::post_scope();
		$concepts = Okf_Store::list_concepts( $scope, true );
		$parts    = array(
			'# OKF export — ' . $scope,
			'okf_version: ' . Okf_Store::OKF_VERSION,
			'exported: ' . gmdate( 'c' ),
			'',
		);
		foreach ( $concepts as $c ) {
			$doc = Okf_Store::get_concept( $c['id'], $scope );
			if ( is_wp_error( $doc ) ) {
				continue;
			}
			$parts[] = '<!-- concept:' . $c['id'] . ' -->';
			$parts[] = $doc['raw'];
			$parts[] = '';
		}
		wp_send_json_success(
			array(
				'filename' => 'okf-' . $scope . '-' . gmdate( 'Ymd' ) . '.md',
				'content'  => implode( "\n", $parts ),
			)
		);
	}
}
