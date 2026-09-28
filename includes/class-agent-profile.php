<?php
/**
 * Agent Profile — per-agent identity and roster metadata.
 *
 * Gives every agent a display name, title, standing description, avatar and
 * pinned/hidden/order flags, stored as overrides in the Agent_Settings KV
 * store. The base name/icon/description still come from the agent's own
 * manifest (agent.json) or PHP file header; the profile layer only ever
 * overrides them, so an agent with no profile still presents its defaults.
 *
 * Usage:
 *   $profile = Agent_Profile::get( 'content-writer' );        // merged array
 *   Agent_Profile::save( 'content-writer', array(
 *       'profile_display_name' => 'Editor',
 *       'profile_title'        => 'Blog editor',
 *   ) );
 *   Agent_Profile::order( array( 'seo', 'content' ) );        // pin order
 *   Agent_Profile::identity_line( 'content-writer' );         // "You are …"
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.1.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static read/write layer for per-agent profile metadata.
 */
class Agent_Profile {

	/**
	 * Per-request cache of merged profiles, keyed by agent slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $cache = array();

	/**
	 * Profile override keys that hold free-text values.
	 *
	 * @var string[]
	 */
	private const TEXT_KEYS = array(
		'profile_display_name',
		'profile_title',
		'profile_avatar_emoji',
	);

	/**
	 * Profile override keys that hold a boolean flag (stored as '1' / '0').
	 *
	 * @var string[]
	 */
	private const BOOL_KEYS = array(
		'profile_pinned',
		'profile_hidden',
	);

	/**
	 * Every key save() will persist, in the order profile.json exports them.
	 *
	 * @var string[]
	 */
	private const EXPORT_KEYS = array(
		'profile_display_name',
		'profile_title',
		'profile_avatar_emoji',
		'persona_notes',
		'profile_pinned',
		'profile_hidden',
		'profile_order',
	);

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Merge an agent's manifest identity with its saved profile overrides.
	 *
	 * @param string $slug Agent slug.
	 * @return array<string, mixed> Merged profile.
	 */
	public static function get( string $slug ): array {
		if ( isset( self::$cache[ $slug ] ) ) {
			return self::$cache[ $slug ];
		}

		$base     = self::base_fields( $slug );
		$settings = Agent_Settings::get_all( $slug );

		$display_name = trim( (string) ( $settings['profile_display_name'] ?? '' ) );
		if ( '' === $display_name ) {
			$display_name = $base['name'];
		}

		$avatar_id  = absint( $settings['profile_avatar_id'] ?? 0 );
		$avatar_url = $avatar_id > 0
			? (string) wp_get_attachment_image_url( $avatar_id, 'thumbnail' )
			: '';

		$profile = array(
			'slug'                 => $slug,
			'display_name'         => $display_name,
			'title'                => trim( (string) ( $settings['profile_title'] ?? '' ) ),
			'icon'                 => $base['icon'],
			'avatar_id'            => $avatar_id,
			'avatar_url'           => $avatar_url,
			'avatar_emoji'         => trim( (string) ( $settings['profile_avatar_emoji'] ?? '' ) ),
			'description'          => $base['description'],
			'standing_description' => trim( (string) ( $settings['persona_notes'] ?? '' ) ),
			'pinned'               => '1' === ( $settings['profile_pinned'] ?? '' ),
			'hidden'               => '1' === ( $settings['profile_hidden'] ?? '' ),
			'order'                => absint( $settings['profile_order'] ?? 0 ),
		);

		self::$cache[ $slug ] = $profile;
		return $profile;
	}

	/**
	 * Save profile overrides for an agent.
	 *
	 * Sanitises each field, ignores unknown keys, and persists only the known
	 * ones to Agent_Settings. An avatar id is kept only when it points at an
	 * existing image attachment; anything else clears it.
	 *
	 * @param string               $slug   Agent slug.
	 * @param array<string, mixed> $fields Profile fields keyed by the profile_* and persona_notes keys.
	 * @return array<string, string> The sanitised values actually written, keyed by setting key.
	 */
	public static function save( string $slug, array $fields ): array {
		$written = array();

		foreach ( $fields as $key => $value ) {
			$sanitized = self::sanitize_field( (string) $key, $value );
			if ( null === $sanitized ) {
				continue; // Unknown key — never persisted.
			}
			Agent_Settings::update( $slug, (string) $key, $sanitized );
			$written[ (string) $key ] = $sanitized;
		}

		unset( self::$cache[ $slug ] );
		return $written;
	}

	/**
	 * Set the roster order for a list of agent slugs.
	 *
	 * The first slug gets order 1, the second 2, and so on. Slugs not listed
	 * keep their existing order.
	 *
	 * @param string[] $slugs Ordered agent slugs.
	 * @return void
	 */
	public static function order( array $slugs ): void {
		$position = 1;
		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			Agent_Settings::update( $slug, 'profile_order', (string) $position );
			unset( self::$cache[ $slug ] );
			++$position;
		}
	}

	/**
	 * The prompt identity line for an agent, or '' when no display name is set.
	 *
	 * Returns a sentence like "You are Editor, Blog editor." (title optional),
	 * emitted only when a profile display name has been configured — a bare
	 * manifest name does not count, so agents without a custom identity stay
	 * exactly as they were.
	 *
	 * @param string $slug Agent slug.
	 * @return string Identity sentence (with trailing newline), or ''.
	 */
	public static function identity_line( string $slug ): string {
		$display_name = trim( Agent_Settings::get( $slug, 'profile_display_name' ) );
		if ( '' === $display_name ) {
			return '';
		}

		$title = trim( Agent_Settings::get( $slug, 'profile_title' ) );
		if ( '' !== $title ) {
			return sprintf( 'You are %1$s, %2$s.', $display_name, $title ) . "\n";
		}
		return sprintf( 'You are %s.', $display_name ) . "\n";
	}

	/**
	 * The portable profile override payload for export/duplicate.
	 *
	 * Returns only the known override keys that are currently set, ready to be
	 * written to profile.json and later re-applied via save(). The avatar
	 * attachment id is deliberately excluded: it is an id on *this* site and
	 * meaningless (or wrong) on another install.
	 *
	 * @param string $slug Agent slug.
	 * @return array<string, string> Set override keys => raw stored values.
	 */
	public static function export_fields( string $slug ): array {
		$settings = Agent_Settings::get_all( $slug );
		$out      = array();
		foreach ( self::EXPORT_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
				$out[ $key ] = $settings[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Bust the per-request profile cache.
	 *
	 * @param string|null $slug Bust one slug, or all when null.
	 * @return void
	 */
	public static function bust( ?string $slug = null ): void {
		if ( null === $slug ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ $slug ] );
		}
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Base identity from the agent's manifest or file header.
	 *
	 * @param string $slug Agent slug.
	 * @return array{name: string, icon: string, description: string}
	 */
	private static function base_fields( string $slug ): array {
		$name        = $slug;
		$icon        = '🤖';
		$description = '';

		if ( class_exists( '\\Agentic_Agent_Registry' ) ) {
			$info = \Agentic_Agent_Registry::get_instance()->get_installed_agents()[ $slug ] ?? array();
			if ( is_array( $info ) ) {
				$name        = (string) ( $info['name'] ?? $slug );
				$icon        = (string) ( $info['icon'] ?? '🤖' );
				$description = (string) ( $info['description'] ?? '' );
			}
		}

		return array(
			'name'        => '' !== $name ? $name : $slug,
			'icon'        => '' !== $icon ? $icon : '🤖',
			'description' => $description,
		);
	}

	/**
	 * Sanitise a single profile field to its stored string form.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return string|null Sanitised value, or null to skip the key entirely.
	 */
	private static function sanitize_field( string $key, $value ): ?string {
		if ( in_array( $key, self::TEXT_KEYS, true ) ) {
			return sanitize_text_field( (string) $value );
		}

		if ( in_array( $key, self::BOOL_KEYS, true ) ) {
			return self::to_bool_string( $value );
		}

		if ( 'profile_order' === $key ) {
			return (string) absint( $value );
		}

		if ( 'persona_notes' === $key ) {
			return sanitize_textarea_field( (string) $value );
		}

		if ( 'profile_avatar_id' === $key ) {
			$id = absint( $value );
			return self::is_image_attachment( $id ) ? (string) $id : '0';
		}

		return null; // Unknown key.
	}

	/**
	 * Normalise a loosely-typed boolean input to the stored '1' / '0' string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function to_bool_string( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_numeric( $value ) ) {
			return ( (int) $value ) !== 0 ? '1' : '0';
		}
		$normalized = strtolower( trim( (string) $value ) );
		return in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0';
	}

	/**
	 * Whether an id points at an existing image attachment.
	 *
	 * @param int $id Attachment id.
	 * @return bool
	 */
	private static function is_image_attachment( int $id ): bool {
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return false;
		}
		$mime = get_post_mime_type( $id );
		return is_string( $mime ) && 0 === strpos( $mime, 'image/' );
	}
}
