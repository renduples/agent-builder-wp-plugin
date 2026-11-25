<?php
/**
 * WP Extended Abilities — Common WordPress functions exposed as abilities
 *
 * Registers abilities that wrap frequently used WordPress functions which
 * WordPress core hasn't covered yet. Uses the `wp-extended/` namespace so
 * they appear as third-party abilities and are automatically available to
 * all agents via the Abilities Bridge.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentstructor.com
 * @since      2.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers common WordPress functions as abilities under the wp-extended/ namespace.
 */
class WP_Extended_Abilities {

	private const NS = 'wp-extended/';

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register ability categories.
	 */
	public function register_categories(): void {
		Abilities_API::register_category(
			'wp-content',
			array(
				'label'       => __( 'WordPress Content', 'agentstructor' ),
				'description' => __( 'Abilities for reading and managing WordPress content.', 'agentstructor' ),
			)
		);

		Abilities_API::register_category(
			'wp-taxonomy',
			array(
				'label'       => __( 'WordPress Taxonomies', 'agentstructor' ),
				'description' => __( 'Abilities for reading WordPress taxonomies and terms.', 'agentstructor' ),
			)
		);

		Abilities_API::register_category(
			'wp-media',
			array(
				'label'       => __( 'WordPress Media', 'agentstructor' ),
				'description' => __( 'Abilities for working with the WordPress media library.', 'agentstructor' ),
			)
		);

		Abilities_API::register_category(
			'wp-admin',
			array(
				'label'       => __( 'WordPress Administration', 'agentstructor' ),
				'description' => __( 'Abilities for reading WordPress admin data: plugins, themes, options.', 'agentstructor' ),
			)
		);

		Abilities_API::register_category(
			'wp-comments',
			array(
				'label'       => __( 'WordPress Comments', 'agentstructor' ),
				'description' => __( 'Abilities for working with WordPress comments.', 'agentstructor' ),
			)
		);
	}

	/**
	 * Register all abilities.
	 */
	public function register_abilities(): void {
		$this->register_content_abilities();
		$this->register_taxonomy_abilities();
		$this->register_media_abilities();
		$this->register_admin_abilities();
		$this->register_comment_abilities();
		$this->register_advanced_abilities();
	}

	// ── Content ────────────────────────────────────────────────────────

	/**
	 * Register content abilities (get/create/update/delete posts and pages).
	 */
	private function register_content_abilities(): void {

		// 1. get-posts
		Abilities_API::register(
			self::NS . 'get-posts',
			array(
				'label'               => __( 'Get Posts', 'agentstructor' ),
				'description'         => __( 'Retrieves a list of posts matching the given criteria.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type'      => array(
							'type'        => 'string',
							'description' => __( 'Post type slug.', 'agentstructor' ),
							'default'     => 'post',
						),
						'post_status'    => array(
							'type'        => 'string',
							'description' => __( 'Post status.', 'agentstructor' ),
							'default'     => 'publish',
						),
						'posts_per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Number of posts to return (max 100).', 'agentstructor' ),
							'default'     => 10,
						),
						'orderby'        => array(
							'type'        => 'string',
							'description' => __( 'Field to order by.', 'agentstructor' ),
							'default'     => 'date',
						),
						'order'          => array(
							'type'    => 'string',
							'enum'    => array( 'ASC', 'DESC' ),
							'default' => 'DESC',
						),
						's'              => array(
							'type'        => 'string',
							'description' => __( 'Search keyword.', 'agentstructor' ),
						),
						'category_name'  => array(
							'type'        => 'string',
							'description' => __( 'Category slug to filter by.', 'agentstructor' ),
						),
						'tag'            => array(
							'type'        => 'string',
							'description' => __( 'Tag slug to filter by.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'ID'          => array( 'type' => 'integer' ),
							'post_title'  => array( 'type' => 'string' ),
							'post_date'   => array( 'type' => 'string' ),
							'post_status' => array( 'type' => 'string' ),
							'post_type'   => array( 'type' => 'string' ),
							'guid'        => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$allowed_args = array( 'post_type', 'post_status', 'posts_per_page', 'orderby', 'order', 's', 'category_name', 'tag' );
					$args = array_intersect_key( $input, array_flip( $allowed_args ) );
					$args['posts_per_page'] = min( (int) ( $args['posts_per_page'] ?? 10 ), 100 );

					$posts = get_posts( $args );
					return array_map(
						static function ( $p ) {
							return array(
								'ID'          => $p->ID,
								'post_title'  => $p->post_title,
								'post_date'   => $p->post_date,
								'post_status' => $p->post_status,
								'post_type'   => $p->post_type,
								'guid'        => $p->guid,
							);
						},
						$posts
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'instructions' => 'Use this to retrieve basic post lists when the user asks about recent or filtered content. Respect post status and permissions.',
				),
			)
		);

		// 2. get-post
		Abilities_API::register(
			self::NS . 'get-post',
			array(
				'label'               => __( 'Get Single Post', 'agentstructor' ),
				'description'         => __( 'Retrieves a single post by ID, including its content and metadata.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ID'           => array( 'type' => 'integer' ),
						'post_title'   => array( 'type' => 'string' ),
						'post_content' => array( 'type' => 'string' ),
						'post_excerpt' => array( 'type' => 'string' ),
						'post_status'  => array( 'type' => 'string' ),
						'post_type'    => array( 'type' => 'string' ),
						'post_date'    => array( 'type' => 'string' ),
						'post_author'  => array( 'type' => 'string' ),
						'permalink'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input ): array|\WP_Error {
					$post = get_post( (int) $input['post_id'] );
					if ( ! $post ) {
						return new \WP_Error( 'not_found', __( 'Post not found.', 'agentstructor' ) );
					}
					return array(
						'ID'           => $post->ID,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_status'  => $post->post_status,
						'post_type'    => $post->post_type,
						'post_date'    => $post->post_date,
						'post_author'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
						'permalink'    => get_permalink( $post ),
					);
				},
				'permission_callback' => static function ( $input ): bool {
					return current_user_can( 'read_post', (int) $input['post_id'] );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 3. get-post-meta
		Abilities_API::register(
			self::NS . 'get-post-meta',
			array(
				'label'               => __( 'Get Post Meta', 'agentstructor' ),
				'description'         => __( 'Retrieves metadata for a post. Returns a specific key or all public meta.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'properties'           => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'agentstructor' ),
						),
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Schema definition, not a DB query argument.
						'meta_key' => array(
							'type'        => 'string',
							'description' => __( 'Specific meta key to retrieve. Omit for all public meta.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input ): array|\WP_Error {
					$post_id = (int) $input['post_id'];
					if ( ! get_post( $post_id ) ) {
						return new \WP_Error( 'not_found', __( 'Post not found.', 'agentstructor' ) );
					}
					if ( ! empty( $input['meta_key'] ) ) {
						$key = sanitize_key( $input['meta_key'] );
						if ( Tool_Helpers::is_sensitive_name( $key ) ) {
							return new \WP_Error( 'blocked', __( 'Access to sensitive meta keys is not allowed.', 'agentstructor' ) );
						}
						return array( $key => get_post_meta( $post_id, $key, true ) );
					}
					$all_meta = get_post_meta( $post_id );
					// Filter out internal/protected meta and sensitive keys.
					$public = array();
					foreach ( $all_meta as $key => $values ) {
						if ( ! str_starts_with( $key, '_' ) && ! Tool_Helpers::is_sensitive_name( $key ) ) {
							$public[ $key ] = count( $values ) === 1 ? $values[0] : $values;
						}
					}
					return $public;
				},
				'permission_callback' => static function ( $input ): bool {
					return current_user_can( 'read_post', (int) $input['post_id'] );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 4. get-post-types
		Abilities_API::register(
			self::NS . 'get-post-types',
			array(
				'label'               => __( 'Get Post Types', 'agentstructor' ),
				'description'         => __( 'Lists all registered public post types and their properties.', 'agentstructor' ),
				'category'            => 'wp-content',
				'execute_callback'    => static function (): array {
					$types  = get_post_types( array( 'public' => true ), 'objects' );
					$result = array();
					foreach ( $types as $slug => $type ) {
						$result[] = array(
							'name'         => $slug,
							'label'        => $type->label,
							'hierarchical' => $type->hierarchical,
							'has_archive'  => (bool) $type->has_archive,
							'supports'     => get_all_post_type_supports( $slug ),
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 5. search-content
		Abilities_API::register(
			self::NS . 'search-content',
			array(
				'label'               => __( 'Search Content', 'agentstructor' ),
				'description'         => __( 'Full-text search across posts, pages, and custom post types.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'query' ),
					'properties'           => array(
						'query'     => array(
							'type'        => 'string',
							'description' => __( 'Search query string.', 'agentstructor' ),
							'minLength'   => 1,
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Limit to a specific post type. Default: any.', 'agentstructor' ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => __( 'Max results (max 50).', 'agentstructor' ),
							'default'     => 10,
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input ): array {
					$args = array(
						's'              => sanitize_text_field( $input['query'] ),
						'posts_per_page' => min( (int) ( $input['limit'] ?? 10 ), 50 ),
						'post_status'    => 'publish',
					);
					if ( ! empty( $input['post_type'] ) ) {
						$args['post_type'] = sanitize_key( $input['post_type'] );
					} else {
						$args['post_type'] = 'any';
					}

					$posts = get_posts( $args );
					return array_map(
						static function ( $p ) {
							return array(
								'ID'         => $p->ID,
								'post_title' => $p->post_title,
								'post_type'  => $p->post_type,
								'post_date'  => $p->post_date,
								'excerpt'    => wp_trim_words( $p->post_content, 30, '...' ),
								'permalink'  => get_permalink( $p ),
							);
						},
						$posts
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 6. get-recent-posts
		Abilities_API::register(
			self::NS . 'get-recent-posts',
			array(
				'label'               => __( 'Get Recent Posts', 'agentstructor' ),
				'description'         => __( 'Returns the most recently published posts.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'count'     => array(
							'type'        => 'integer',
							'description' => __( 'Number of posts (max 50).', 'agentstructor' ),
							'default'     => 5,
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Post type slug.', 'agentstructor' ),
							'default'     => 'post',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$posts = wp_get_recent_posts(
						array(
							'numberposts' => min( (int) ( $input['count'] ?? 5 ), 50 ),
							'post_type'   => sanitize_key( $input['post_type'] ?? 'post' ),
							'post_status' => 'publish',
						),
						OBJECT
					);
					return array_map(
						static function ( $p ) {
							return array(
								'ID'         => $p->ID,
								'post_title' => $p->post_title,
								'post_date'  => $p->post_date,
								'permalink'  => get_permalink( $p ),
							);
						},
						$posts
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ── Taxonomies ────────────────────────────────────────────────────

	/**
	 * Register taxonomy abilities (terms, categories, tags).
	 */
	private function register_taxonomy_abilities(): void {

		// 7. get-taxonomies
		Abilities_API::register(
			self::NS . 'get-taxonomies',
			array(
				'label'               => __( 'Get Taxonomies', 'agentstructor' ),
				'description'         => __( 'Lists all registered public taxonomies.', 'agentstructor' ),
				'category'            => 'wp-taxonomy',
				'execute_callback'    => static function (): array {
					$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
					return array_map(
						static function ( $t ) {
							return array(
								'name'         => $t->name,
								'label'        => $t->label,
								'hierarchical' => $t->hierarchical,
								'object_types' => $t->object_type,
							);
						},
						array_values( $taxonomies )
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 8. get-terms
		Abilities_API::register(
			self::NS . 'get-terms',
			array(
				'label'               => __( 'Get Terms', 'agentstructor' ),
				'description'         => __( 'Retrieves terms from a taxonomy (categories, tags, or custom).', 'agentstructor' ),
				'category'            => 'wp-taxonomy',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'taxonomy' ),
					'properties'           => array(
						'taxonomy'   => array(
							'type'        => 'string',
							'description' => __( 'Taxonomy slug (e.g., category, post_tag).', 'agentstructor' ),
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => __( 'Hide terms with no posts.', 'agentstructor' ),
							'default'     => true,
						),
						'number'     => array(
							'type'        => 'integer',
							'description' => __( 'Max terms to return (max 200).', 'agentstructor' ),
							'default'     => 50,
						),
						'parent'     => array(
							'type'        => 'integer',
							'description' => __( 'Parent term ID to filter by.', 'agentstructor' ),
						),
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Search terms by name.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input ): array|\WP_Error {
					if ( ! taxonomy_exists( $input['taxonomy'] ) ) {
						return new \WP_Error( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'agentstructor' ) );
					}
					$args = array(
						'taxonomy'   => sanitize_key( $input['taxonomy'] ),
						'hide_empty' => $input['hide_empty'] ?? true,
						'number'     => min( (int) ( $input['number'] ?? 50 ), 200 ),
					);
					if ( isset( $input['parent'] ) ) {
						$args['parent'] = (int) $input['parent'];
					}
					if ( ! empty( $input['search'] ) ) {
						$args['search'] = sanitize_text_field( $input['search'] );
					}
					$terms = get_terms( $args );
					if ( is_wp_error( $terms ) ) {
						return $terms;
					}
					return array_map(
						static function ( $t ) {
							return array(
								'term_id' => $t->term_id,
								'name'    => $t->name,
								'slug'    => $t->slug,
								'count'   => $t->count,
								'parent'  => $t->parent,
							);
						},
						$terms
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 9. get-categories
		Abilities_API::register(
			self::NS . 'get-categories',
			array(
				'label'               => __( 'Get Categories', 'agentstructor' ),
				'description'         => __( 'Returns all post categories with their hierarchy.', 'agentstructor' ),
				'category'            => 'wp-taxonomy',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hide_empty' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$cats  = get_categories( array( 'hide_empty' => $input['hide_empty'] ?? false ) );
					return array_map(
						static function ( $c ) {
							return array(
								'term_id' => $c->term_id,
								'name'    => $c->name,
								'slug'    => $c->slug,
								'count'   => $c->count,
								'parent'  => $c->parent,
							);
						},
						$cats
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 10. get-tags
		Abilities_API::register(
			self::NS . 'get-tags',
			array(
				'label'               => __( 'Get Tags', 'agentstructor' ),
				'description'         => __( 'Returns all post tags, sorted by usage count.', 'agentstructor' ),
				'category'            => 'wp-taxonomy',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'number' => array(
							'type'        => 'integer',
							'description' => __( 'Max tags to return.', 'agentstructor' ),
							'default'     => 50,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$tags  = get_tags(
						array(
							'number'     => min( (int) ( $input['number'] ?? 50 ), 200 ),
							'orderby'    => 'count',
							'order'      => 'DESC',
							'hide_empty' => false,
						)
					);
					if ( ! is_array( $tags ) ) {
						return array();
					}
					return array_map(
						static function ( $t ) {
							return array(
								'term_id' => $t->term_id,
								'name'    => $t->name,
								'slug'    => $t->slug,
								'count'   => $t->count,
							);
						},
						$tags
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ── Media ─────────────────────────────────────────────────────────

	/**
	 * Register media abilities (attachments, image sizes).
	 */
	private function register_media_abilities(): void {

		// 11. get-media
		Abilities_API::register(
			self::NS . 'get-media',
			array(
				'label'               => __( 'Get Media Items', 'agentstructor' ),
				'description'         => __( 'Lists media library attachments with their URLs and metadata.', 'agentstructor' ),
				'category'            => 'wp-media',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'mime_type' => array(
							'type'        => 'string',
							'description' => __( 'Filter by MIME type (e.g., image, image/jpeg, application/pdf).', 'agentstructor' ),
						),
						'count'     => array(
							'type'        => 'integer',
							'description' => __( 'Number of items (max 50).', 'agentstructor' ),
							'default'     => 10,
						),
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Search by filename or title.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$args  = array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => min( (int) ( $input['count'] ?? 10 ), 50 ),
						'orderby'        => 'date',
						'order'          => 'DESC',
					);
					if ( ! empty( $input['mime_type'] ) ) {
						$args['post_mime_type'] = sanitize_mime_type( $input['mime_type'] );
					}
					if ( ! empty( $input['search'] ) ) {
						$args['s'] = sanitize_text_field( $input['search'] );
					}
					$items = get_posts( $args );
					return array_map(
						static function ( $p ) {
							return array(
								'ID'        => $p->ID,
								'title'     => $p->post_title,
								'url'       => wp_get_attachment_url( $p->ID ),
								'mime_type' => $p->post_mime_type,
								'date'      => $p->post_date,
								'alt_text'  => get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
								'filesize'  => ( static function ( $f ) {
									$s = ( false !== $f ) ? filesize( (string) $f ) : false;
									return false !== $s ? $s : 0; } )( get_attached_file( $p->ID ) ),
							);
						},
						$items
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'upload_files' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 12. get-image-sizes
		Abilities_API::register(
			self::NS . 'get-image-sizes',
			array(
				'label'               => __( 'Get Image Sizes', 'agentstructor' ),
				'description'         => __( 'Lists all registered image sizes and their dimensions.', 'agentstructor' ),
				'category'            => 'wp-media',
				'execute_callback'    => static function (): array {
					$sizes      = wp_get_registered_image_subsizes();
					$result     = array();
					foreach ( $sizes as $name => $data ) {
						$result[] = array(
							'name'   => $name,
							'width'  => $data['width'],
							'height' => $data['height'],
							'crop'   => $data['crop'],
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'upload_files' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ── Admin ─────────────────────────────────────────────────────────

	/**
	 * Register admin abilities (plugins, themes, users).
	 */
	private function register_admin_abilities(): void {

		// 13. get-plugins
		Abilities_API::register(
			self::NS . 'get-plugins',
			array(
				'label'               => __( 'Get Plugins', 'agentstructor' ),
				'description'         => __( 'Lists all installed plugins with their status (active/inactive) and version.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$plugins   = get_plugins();
					$active    = (array) get_option( 'active_plugins', array() );
					$result    = array();
					foreach ( $plugins as $file => $data ) {
						$result[] = array(
							'file'        => $file,
							'name'        => $data['Name'],
							'version'     => $data['Version'],
							'active'      => in_array( $file, $active, true ),
							'description' => wp_trim_words( $data['Description'] ?? '', 15, '...' ),
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'activate_plugins' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 14. get-themes
		Abilities_API::register(
			self::NS . 'get-themes',
			array(
				'label'               => __( 'Get Themes', 'agentstructor' ),
				'description'         => __( 'Lists installed themes and identifies the active theme.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					$themes       = wp_get_themes();
					$active_theme = get_stylesheet();
					return array_map(
						static function ( $theme ) use ( $active_theme ) {
							return array(
								'slug'    => $theme->get_stylesheet(),
								'name'    => $theme->get( 'Name' ),
								'version' => $theme->get( 'Version' ),
								'active'  => $theme->get_stylesheet() === $active_theme,
								'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
							);
						},
						array_values( $themes )
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'switch_themes' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 15. get-option
		Abilities_API::register(
			self::NS . 'get-option',
			array(
				'label'               => __( 'Get Option', 'agentstructor' ),
				'description'         => __( 'Retrieves a WordPress option value by name.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'name' ),
					'properties'           => array(
						'name' => array(
							'type'        => 'string',
							'description' => __( 'Option name (e.g., blogname, permalink_structure).', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input ): array|\WP_Error {
					$name = sanitize_key( $input['name'] );
					if ( Tool_Helpers::is_sensitive_name( $name ) ) {
						return new \WP_Error( 'blocked', __( 'Access to sensitive options is not allowed.', 'agentstructor' ) );
					}
					$value = get_option( $name );
					return array(
						'name'  => $name,
						'value' => $value,
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 16. get-users
		Abilities_API::register(
			self::NS . 'get-users',
			array(
				'label'               => __( 'Get Users', 'agentstructor' ),
				'description'         => __( 'Lists WordPress users with their roles. No sensitive data is returned.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'role'   => array(
							'type'        => 'string',
							'description' => __( 'Filter by role (e.g., administrator, editor, subscriber).', 'agentstructor' ),
						),
						'number' => array(
							'type'        => 'integer',
							'description' => __( 'Max users to return (max 100).', 'agentstructor' ),
							'default'     => 20,
						),
						'search' => array(
							'type'        => 'string',
							'description' => __( 'Search by name, email, or login.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$args  = array(
						'number' => min( (int) ( $input['number'] ?? 20 ), 100 ),
						'fields' => array( 'ID', 'user_login', 'display_name', 'user_registered' ),
					);
					if ( ! empty( $input['role'] ) ) {
						$args['role'] = sanitize_key( $input['role'] );
					}
					if ( ! empty( $input['search'] ) ) {
						$args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
						$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
					}
					$users = get_users( $args );
					return array_map(
						static function ( $u ) {
							$user_obj = new \WP_User( $u->ID );
							return array(
								'ID'              => $u->ID,
								'user_login'      => $u->user_login,
								'display_name'    => $u->display_name,
								'user_registered' => $u->user_registered,
								'roles'           => $user_obj->roles,
							);
						},
						$users
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'list_users' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 17. get-menus
		Abilities_API::register(
			self::NS . 'get-menus',
			array(
				'label'               => __( 'Get Navigation Menus', 'agentstructor' ),
				'description'         => __( 'Lists registered navigation menus and their assigned locations.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					$menus     = wp_get_nav_menus();
					$locations = get_nav_menu_locations();
					$loc_names = get_registered_nav_menus();
					return array_map(
						static function ( $menu ) use ( $locations, $loc_names ) {
							$assigned = array();
							foreach ( $locations as $loc => $menu_id ) {
								if ( $menu_id === $menu->term_id ) {
									$assigned[] = array(
										'slug'  => $loc,
										'label' => $loc_names[ $loc ] ?? $loc,
									);
								}
							}
							return array(
								'term_id'   => $menu->term_id,
								'name'      => $menu->name,
								'count'     => $menu->count,
								'locations' => $assigned,
							);
						},
						$menus
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 18. get-sidebars
		Abilities_API::register(
			self::NS . 'get-sidebars',
			array(
				'label'               => __( 'Get Sidebars & Widget Areas', 'agentstructor' ),
				'description'         => __( 'Lists all registered widget areas (sidebars) and their active widgets.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					global $wp_registered_sidebars;
					$sidebars_widgets = wp_get_sidebars_widgets(); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- core WP API, no alternative exists.
					$result           = array();
					foreach ( $wp_registered_sidebars as $id => $sidebar ) {
						$result[] = array(
							'id'           => $id,
							'name'         => $sidebar['name'],
							'description'  => $sidebar['description'] ?? '',
							'widget_count' => count( $sidebars_widgets[ $id ] ?? array() ),
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 19. get-roles
		Abilities_API::register(
			self::NS . 'get-roles',
			array(
				'label'               => __( 'Get User Roles', 'agentstructor' ),
				'description'         => __( 'Lists all registered user roles and their capabilities.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					$roles  = wp_roles();
					$result = array();
					foreach ( $roles->role_names as $slug => $name ) {
						$caps = $roles->get_role( $slug )->capabilities;
						$result[] = array(
							'slug'             => $slug,
							'name'             => $name,
							'capability_count' => count( array_filter( $caps ) ),
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'list_users' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 20. get-permalink-structure
		Abilities_API::register(
			self::NS . 'get-permalink-structure',
			array(
				'label'               => __( 'Get Permalink Structure', 'agentstructor' ),
				'description'         => __( 'Returns the current permalink structure, rewrite rules status, and front page settings.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					return array(
						'permalink_structure' => get_option( 'permalink_structure' ),
						'show_on_front'       => get_option( 'show_on_front' ),
						'page_on_front'       => (int) get_option( 'page_on_front' ),
						'page_for_posts'      => (int) get_option( 'page_for_posts' ),
						'category_base'       => get_option( 'category_base' ),
						'tag_base'            => get_option( 'tag_base' ),
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ── Comments ──────────────────────────────────────────────────────

	/**
	 * Register comment abilities (list and moderate comments).
	 */
	private function register_comment_abilities(): void {

		// Bonus: get-comments.
		Abilities_API::register(
			self::NS . 'get-comments',
			array(
				'label'               => __( 'Get Comments', 'agentstructor' ),
				'description'         => __( 'Retrieves comments, optionally filtered by post, status, or author.', 'agentstructor' ),
				'category'            => 'wp-comments',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Filter by post ID.', 'agentstructor' ),
						),
						'status'  => array(
							'type'        => 'string',
							'description' => __( 'Comment status: approve, hold, spam, trash, all.', 'agentstructor' ),
							'default'     => 'approve',
						),
						'number'  => array(
							'type'        => 'integer',
							'description' => __( 'Max comments (max 100).', 'agentstructor' ),
							'default'     => 20,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$args  = array(
						'status' => sanitize_key( $input['status'] ?? 'approve' ),
						'number' => min( (int) ( $input['number'] ?? 20 ), 100 ),
					);
					if ( ! empty( $input['post_id'] ) ) {
						$args['post_id'] = (int) $input['post_id'];
					}
					$comments = get_comments( $args );
					return array_map(
						static function ( $c ) {
							return array(
								'comment_ID' => (int) $c->comment_ID,
								'post_id'    => (int) $c->comment_post_ID,
								'author'     => $c->comment_author,
								'date'       => $c->comment_date,
								'content'    => wp_trim_words( $c->comment_content, 30, '...' ),
								'status'     => $c->comment_approved,
							);
						},
						$comments
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'moderate_comments' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ── Advanced ──────────────────────────────────────────────────────

	/**
	 * Register advanced abilities (menus, widgets, cron).
	 */
	private function register_advanced_abilities(): void {

		// 22. get-menu-items
		Abilities_API::register(
			self::NS . 'get-menu-items',
			array(
				'label'               => __( 'Get Menu Items', 'agentstructor' ),
				'description'         => __( 'Returns all items in a specific navigation menu with their URLs, labels, and hierarchy.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id' ),
					'properties'           => array(
						'menu_id' => array(
							'type'        => 'integer',
							'description' => __( 'The menu term_id (get it from get-menus).', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input ): array|\WP_Error {
					$items = wp_get_nav_menu_items( (int) $input['menu_id'] );
					if ( false === $items ) {
						return new \WP_Error( 'not_found', __( 'Menu not found.', 'agentstructor' ) );
					}
					return array_map(
						static function ( $item ) {
							return array(
								'ID'        => (int) $item->ID,
								'title'     => $item->title,
								'url'       => $item->url,
								'parent'    => (int) $item->menu_item_parent,
								'type'      => $item->type,
								'object'    => $item->object,
								'object_id' => (int) $item->object_id,
								'order'     => (int) $item->menu_order,
							);
						},
						$items
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 23. get-registered-blocks
		Abilities_API::register(
			self::NS . 'get-registered-blocks',
			array(
				'label'               => __( 'Get Registered Blocks', 'agentstructor' ),
				'description'         => __( 'Lists all registered Gutenberg block types with their categories and attributes.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'namespace' => array(
							'type'        => 'string',
							'description' => __( 'Filter by block namespace (e.g., core, woocommerce).', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input  = is_array( $input ) ? $input : array();
					$registry = \WP_Block_Type_Registry::get_instance();
					$blocks   = $registry->get_all_registered();
					$result   = array();
					foreach ( $blocks as $name => $block ) {
						if ( ! empty( $input['namespace'] ) ) {
							$parts = explode( '/', $name, 2 );
							if ( ( $parts[0] ?? '' ) !== $input['namespace'] ) {
								continue;
							}
						}
						$result[] = array(
							'name'     => $name,
							'title'    => $block->title ?? $name,
							'category' => $block->category ?? '',
							'parent'   => $block->parent ?? null,
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 24. get-cron-events
		Abilities_API::register(
			self::NS . 'get-cron-events',
			array(
				'label'               => __( 'Get Cron Events', 'agentstructor' ),
				'description'         => __( 'Lists all scheduled WP-Cron events with their next run time and recurrence.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					$crons  = _get_cron_array();
					$events = array();
					foreach ( $crons as $timestamp => $cron_hooks ) {
						foreach ( $cron_hooks as $hook => $runs ) {
							foreach ( $runs as $run ) {
								$events[] = array(
									'hook'       => $hook,
									'next_run'   => gmdate( 'Y-m-d H:i:s', $timestamp ),
									'recurrence' => $run['schedule'] ?? 'once',
									'interval'   => $run['interval'] ?? null,
								);
							}
						}
					}
					usort( $events, static fn( $a, $b ) => strcmp( $a['next_run'], $b['next_run'] ) );
					return $events;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 25. get-post-revisions
		Abilities_API::register(
			self::NS . 'get-post-revisions',
			array(
				'label'               => __( 'Get Post Revisions', 'agentstructor' ),
				'description'         => __( 'Returns the revision history for a specific post.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post ID to get revisions for.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input ): array|\WP_Error {
					$post = get_post( (int) $input['post_id'] );
					if ( ! $post ) {
						return new \WP_Error( 'not_found', __( 'Post not found.', 'agentstructor' ) );
					}
					$revisions = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 20 ) );
					return array_map(
						static function ( $rev ) {
							return array(
								'ID'          => $rev->ID,
								'post_date'   => $rev->post_date,
								'post_author' => get_the_author_meta( 'display_name', (int) $rev->post_author ),
								'title'       => $rev->post_title,
								'excerpt'     => wp_trim_words( $rev->post_content, 20, '...' ),
							);
						},
						array_values( $revisions )
					);
				},
				'permission_callback' => static function ( $input ): bool {
					return current_user_can( 'edit_post', (int) $input['post_id'] );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 26. get-site-health
		Abilities_API::register(
			self::NS . 'get-site-health',
			array(
				'label'               => __( 'Get Site Health', 'agentstructor' ),
				'description'         => __( 'Returns the Site Health status — overall rating and individual test results.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					if ( ! class_exists( 'WP_Site_Health' ) ) {
						require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
					}
					$health    = \WP_Site_Health::get_instance();
					$tests_run = get_transient( 'health-check-site-status-result' );
					$result    = array(
						'php_version'   => phpversion(),
						'wp_version'    => get_bloginfo( 'version' ),
						'https'         => is_ssl(),
						'debug_mode'    => defined( 'WP_DEBUG' ) && WP_DEBUG,
						'cron_enabled'  => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
						'object_cache'  => wp_using_ext_object_cache(),
						'autoload_size' => $health->get_test_autoloaded_options()['status'] ?? 'unknown',
					);
					if ( $tests_run ) {
						$decoded = json_decode( $tests_run, true );
						if ( is_array( $decoded ) ) {
							$result['good']        = $decoded['good'] ?? 0;
							$result['recommended'] = $decoded['recommended'] ?? 0;
							$result['critical']    = $decoded['critical'] ?? 0;
						}
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'view_site_health_checks' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 27. get-rewrite-rules
		Abilities_API::register(
			self::NS . 'get-rewrite-rules',
			array(
				'label'               => __( 'Get Rewrite Rules', 'agentstructor' ),
				'description'         => __( 'Returns current URL rewrite rules with their regex patterns and query matches.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'        => 'string',
							'description' => __( 'Filter rules matching this string in pattern or query.', 'agentstructor' ),
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => __( 'Max rules to return (max 200).', 'agentstructor' ),
							'default'     => 50,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					$rules = get_option( 'rewrite_rules', array() );
					if ( ! is_array( $rules ) ) {
						return array();
					}
					$search = $input['search'] ?? '';
					$limit  = min( (int) ( $input['limit'] ?? 50 ), 200 );
					$result = array();
					foreach ( $rules as $pattern => $query ) {
						if ( $search && stripos( $pattern, $search ) === false && stripos( $query, $search ) === false ) {
							continue;
						}
						$result[] = array(
							'pattern' => $pattern,
							'query'   => $query,
						);
						if ( count( $result ) >= $limit ) {
							break;
						}
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 28. get-shortcodes
		Abilities_API::register(
			self::NS . 'get-shortcodes',
			array(
				'label'               => __( 'Get Registered Shortcodes', 'agentstructor' ),
				'description'         => __( 'Lists all registered shortcode tags.', 'agentstructor' ),
				'category'            => 'wp-content',
				'execute_callback'    => static function (): array {
					global $shortcode_tags;
					$result = array();
					foreach ( array_keys( $shortcode_tags ) as $tag ) {
						$callback = $shortcode_tags[ $tag ];
						$source   = 'unknown';
						if ( is_string( $callback ) ) {
							$source = $callback;
						} elseif ( is_array( $callback ) && isset( $callback[0] ) ) {
							$source = is_object( $callback[0] ) ? get_class( $callback[0] ) . '::' . $callback[1] : $callback[0] . '::' . $callback[1];
						}
						$result[] = array(
							'tag'      => $tag,
							'callback' => $source,
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 29. get-active-widgets
		Abilities_API::register(
			self::NS . 'get-active-widgets',
			array(
				'label'               => __( 'Get Active Widgets', 'agentstructor' ),
				'description'         => __( 'Lists active widget instances across all sidebars, including their settings.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'execute_callback'    => static function (): array {
					global $wp_registered_widgets;
					$sidebars = wp_get_sidebars_widgets(); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- core WP API, no alternative exists.
					$result   = array();
					foreach ( $sidebars as $sidebar_id => $widget_ids ) {
						if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
							continue;
						}
						foreach ( $widget_ids as $widget_id ) {
							$name = $wp_registered_widgets[ $widget_id ]['name'] ?? $widget_id;
							$result[] = array(
								'sidebar'   => $sidebar_id,
								'widget_id' => $widget_id,
								'name'      => $name,
							);
						}
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 30. get-post-counts
		Abilities_API::register(
			self::NS . 'get-post-counts',
			array(
				'label'               => __( 'Get Post Counts', 'agentstructor' ),
				'description'         => __( 'Returns post counts grouped by status for one or all post types.', 'agentstructor' ),
				'category'            => 'wp-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Post type slug. Default: all public types.', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input = is_array( $input ) ? $input : array();
					if ( ! empty( $input['post_type'] ) ) {
						$types = array( sanitize_key( $input['post_type'] ) );
					} else {
						$types = get_post_types( array( 'public' => true ) );
					}
					$result = array();
					foreach ( $types as $type ) {
						$counts = (array) wp_count_posts( $type );
						$result[ $type ] = array_map( 'intval', $counts );
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);

		// 31. get-rest-routes
		Abilities_API::register(
			self::NS . 'get-rest-routes',
			array(
				'label'               => __( 'Get REST API Routes', 'agentstructor' ),
				'description'         => __( 'Lists all registered REST API routes with their methods and namespaces.', 'agentstructor' ),
				'category'            => 'wp-admin',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'namespace' => array(
							'type'        => 'string',
							'description' => __( 'Filter by namespace (e.g., wp/v2, wc/v3).', 'agentstructor' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = array() ): array {
					$input  = is_array( $input ) ? $input : array();
					$server = rest_get_server();
					$routes = $server->get_routes();
					$result = array();
					foreach ( $routes as $route => $handlers ) {
						if ( ! empty( $input['namespace'] ) ) {
							$matches = false;
							foreach ( $handlers as $handler ) {
								if ( isset( $handler['namespace'] ) && $handler['namespace'] === $input['namespace'] ) {
									$matches = true;
									break;
								}
							}
							if ( ! $matches ) {
								continue;
							}
						}
						$methods = array();
						foreach ( $handlers as $handler ) {
							if ( isset( $handler['methods'] ) ) {
								$methods = array_merge( $methods, array_keys( (array) $handler['methods'] ) );
							}
						}
						$result[] = array(
							'route'   => $route,
							'methods' => array_unique( $methods ),
						);
					}
					return $result;
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}
}
