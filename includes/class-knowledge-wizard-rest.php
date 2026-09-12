<?php
/**
 * REST controller backing the Knowledge wizard (guided "Add knowledge" flow).
 *
 * Exposes two admin-only endpoints under the agentic/v1 namespace:
 *   - GET  /knowledge-wizard/search-pages : published posts/pages for the
 *     "pick existing pages" source step.
 *   - POST /knowledge-wizard/save         : resolve the chosen source into a
 *     plain-text body and save it as one site-wide OKF concept.
 *
 * This controller never talks to the filesystem directly — all writes go
 * through Okf_Store::save_concept(), the same path the classic Wiki editor
 * (admin/train-data.php + assets/js/okf-knowledge.js) already uses, so
 * wizard-created concepts are indistinguishable from hand-written ones.
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
 * Knowledge Wizard REST controller.
 */
class Knowledge_Wizard_REST {

	const NS = 'agentic/v1';

	/**
	 * Max characters pulled from a picked page's content, and from a pasted /
	 * uploaded body. Keeps a single concept file (and the prompt context it
	 * eventually feeds) to a sane size; long source material should be split
	 * into a few concepts instead of one giant one.
	 */
	const MAX_BODY_CHARS = 20000;

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
			'/knowledge-wizard/search-pages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search_pages' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/knowledge-wizard/save',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Same gate as the classic Wiki editor (agentic_manage_settings or
	 * manage_options — see admin/train-data.php).
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'agentic_manage_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Search published posts/pages for the "pick existing pages" step.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function search_pages( \WP_REST_Request $request ): \WP_REST_Response {
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );

		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => '' === $query ? 'date' : 'relevance',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		$results = array();
		foreach ( get_posts( $args ) as $post ) {
			$results[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title ?: __( '(no title)', 'agent-builder' ),
				'type'    => $post->post_type,
				'excerpt' => wp_trim_words( wp_strip_all_tags( html_entity_decode( $post->post_content, ENT_QUOTES, 'UTF-8' ) ), 20, '…' ),
			);
		}

		return new \WP_REST_Response( array( 'pages' => $results ), 200 );
	}

	/**
	 * Resolve the wizard's chosen source into a plain-text body, then save it
	 * as one new site-wide OKF concept via Okf_Store::save_concept().
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		if ( ! in_array( $source, array( 'paste', 'upload', 'pages' ), true ) ) {
			return new \WP_Error( 'invalid_source', __( 'Choose where the knowledge comes from.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === trim( $title ) ) {
			return new \WP_Error( 'missing_title', __( 'Give this knowledge a short title.', 'agent-builder' ), array( 'status' => 400 ) );
		}

		$tags = array();
		foreach ( (array) $request->get_param( 'tags' ) as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		if ( 'pages' === $source ) {
			$page_ids = array_map( 'absint', (array) $request->get_param( 'page_ids' ) );
			$page_ids = array_values( array_filter( $page_ids ) );
			if ( empty( $page_ids ) ) {
				return new \WP_Error( 'missing_pages', __( 'Pick at least one page or post.', 'agent-builder' ), array( 'status' => 400 ) );
			}

			$parts = array();
			foreach ( $page_ids as $page_id ) {
				$post = get_post( $page_id );
				if ( ! $post || 'publish' !== $post->post_status ) {
					continue;
				}
				$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
				$text = trim( preg_replace( '/\n{3,}/', "\n\n", $text ) );
				if ( '' === $text ) {
					continue;
				}
				$parts[] = '## ' . $post->post_title . "\n\n" . $text;
			}
			if ( empty( $parts ) ) {
				return new \WP_Error( 'empty_pages', __( 'The pages you picked have no readable text content.', 'agent-builder' ), array( 'status' => 400 ) );
			}
			$body        = implode( "\n\n---\n\n", $parts );
			$type        = __( 'Page reference', 'agent-builder' );
			$description = __( 'Imported from existing site pages.', 'agent-builder' );
		} else {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Markdown/plain body; stored as a local file, never SQL-interpolated. Only null bytes stripped, same as the classic Wiki editor's ajax_save().
			$body = (string) $request->get_param( 'text' );
			$body = str_replace( "\0", '', $body );
			$body = trim( $body );
			if ( '' === $body ) {
				return new \WP_Error(
					'missing_text',
					'upload' === $source
						? __( 'The file you uploaded appears to be empty.', 'agent-builder' )
						: __( 'Paste some text first.', 'agent-builder' ),
					array( 'status' => 400 )
				);
			}
			$type        = 'upload' === $source ? __( 'Document', 'agent-builder' ) : __( 'Note', 'agent-builder' );
			$description = 'upload' === $source
				? __( 'Imported from an uploaded file.', 'agent-builder' )
				: __( 'Added from pasted text.', 'agent-builder' );
		}

		if ( function_exists( 'mb_substr' ) ) {
			$body = mb_substr( $body, 0, self::MAX_BODY_CHARS );
		} else {
			$body = substr( $body, 0, self::MAX_BODY_CHARS );
		}

		$concept_id = self::unique_concept_id( sanitize_title( $title ) );

		$result = Okf_Store::save_concept(
			$concept_id,
			array(
				'type'        => $type,
				'title'       => $title,
				'description' => $description,
				'status'      => 'stable',
				'tags'        => $tags,
			),
			$body,
			''
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( Audit_Log::class ) ) {
			Audit_Log::log_admin(
				'knowledge_added',
				$concept_id,
				array(
					'title'  => $title,
					'source' => $source,
					'tags'   => $tags,
					'via'    => 'knowledge_wizard',
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'concept_id'    => $concept_id,
				'knowledge_url' => admin_url( 'admin.php?page=agentic-train-data' ),
			),
			201
		);
	}

	/**
	 * Append -2, -3, … to a candidate concept id until it doesn't collide with
	 * an existing site-wide concept, so a wizard save never silently
	 * overwrites unrelated knowledge that happens to share a title.
	 *
	 * @param string $base Candidate id (already sanitize_title()'d).
	 * @return string Unique concept id.
	 */
	private static function unique_concept_id( string $base ): string {
		if ( '' === $base ) {
			$base = 'knowledge';
		}
		$id  = $base;
		$dir = Okf_Store::bundle_dir( '' );
		$n   = 2;
		while ( file_exists( trailingslashit( $dir ) . $id . '.md' ) ) {
			$id = $base . '-' . $n;
			++$n;
		}
		return $id;
	}
}
