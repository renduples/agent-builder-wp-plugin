<?php
/**
 * Storage Migration
 *
 * One-time move of the plugin's data out of the pre-4.5 folders directly
 * inside wp-content (agentic-agents, agentic-knowledge, agentic-backups)
 * into the plugin's data root in the uploads directory
 * (File_Manager::data_dir(): agents/, knowledge/, backups/).
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      4.5.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves legacy wp-content/agentic-* data into the uploads data root.
 *
 * Guarantees:
 *  - Never loses data. A file is only removed from the old folder after it
 *    exists at the new location with the same size; a name that is already
 *    taken at the new location with different content is left where it is.
 *  - Idempotent. Safe to run twice, safe when the old folders do not exist
 *    or are not writable. Gated by MIGRATED_OPTION once everything moved.
 *  - Nothing left behind is lost to the plugin: while an old folder still
 *    exists, File_Manager::legacy_fallback_dir() keeps it readable, and the
 *    Site Health "storage location" test tells the administrator what to do.
 */
class Storage_Migration {

	/**
	 * Option set to VERSION once every legacy folder has been moved and removed.
	 */
	public const MIGRATED_OPTION = 'agent_builder_storage_migrated';

	/**
	 * Option holding the outcome of the last attempt (see run()).
	 */
	public const STATUS_OPTION = 'agent_builder_storage_migration_status';

	/**
	 * Transient throttling retries of an incomplete migration.
	 */
	private const RETRY_TRANSIENT = 'agent_builder_storage_migration_retry';

	/**
	 * Current migration version.
	 */
	public const VERSION = 1;

	/**
	 * Guard files the plugin itself seeds into every storage folder. A copy
	 * left in the old folder is deleted when the new folder has its own.
	 */
	private const GUARD_FILES = array( 'index.php', '.htaccess', 'web.config' );

	/**
	 * Run the migration if it has not completed yet.
	 *
	 * Called from Activator::maybe_upgrade() (every request that reaches
	 * it; one autoloaded option read once done). An incomplete migration is
	 * retried at most once an hour.
	 *
	 * @param bool $force Run even if recorded as done or recently attempted.
	 * @return array<string, array{moved:int, left:int, error:string}>|null Result per kind, or null when skipped.
	 */
	public static function maybe_run( bool $force = false ): ?array {
		if ( ! $force ) {
			if ( (int) get_option( self::MIGRATED_OPTION, 0 ) >= self::VERSION ) {
				return null;
			}
			if ( get_transient( self::RETRY_TRANSIENT ) ) {
				return null;
			}
		}

		// The legacy folders were shared by every site of a network; only
		// the main site moves them (into its own uploads folder).
		if ( is_multisite() && ! is_main_site() ) {
			return null;
		}

		return self::run();
	}

	/**
	 * Move every legacy folder into the data root.
	 *
	 * @return array<string, array{moved:int, left:int, error:string}>
	 */
	public static function run(): array {
		$results  = array();
		$complete = true;

		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$results[ $kind ] = self::migrate_kind( $kind );
			if ( null !== File_Manager::legacy_fallback_dir( $kind ) ) {
				$complete = false;
			}
		}

		// Short-lived chat images from older versions (uploads/agentic-tmp):
		// temporary by design (deleted after an hour), so removed rather
		// than moved.
		$old_tmp = untrailingslashit( (string) wp_upload_dir( null, false )['basedir'] ) . '/agentic-tmp';
		if ( is_dir( $old_tmp ) && ! is_link( $old_tmp ) ) {
			File_Manager::rmdir( $old_tmp, true );
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'time'     => time(),
				'complete' => $complete,
				'results'  => $results,
			),
			false
		);

		if ( $complete ) {
			update_option( self::MIGRATED_OPTION, self::VERSION );
			delete_transient( self::RETRY_TRANSIENT );
		} else {
			set_transient( self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
		}

		return $results;
	}

	/**
	 * Whether the last attempt left anything in a legacy folder.
	 *
	 * @return string[] Legacy folders that still exist.
	 */
	public static function remaining_legacy_dirs(): array {
		$left = array();
		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$dir = File_Manager::legacy_fallback_dir( $kind );
			if ( null !== $dir ) {
				$left[ $kind ] = $dir;
			}
		}
		return $left;
	}

	/**
	 * Move one legacy folder into its data root subfolder.
	 *
	 * @param string $kind 'agents', 'knowledge' or 'backups'.
	 * @return array{moved:int, left:int, error:string}
	 */
	private static function migrate_kind( string $kind ): array {
		$result = array(
			'moved' => 0,
			'left'  => 0,
			'error' => '',
		);

		$src = File_Manager::legacy_dir( $kind );
		if ( '' === $src || ! is_dir( $src ) ) {
			return $result;
		}

		if ( is_link( $src ) ) {
			// A symlinked legacy folder points somewhere the site owner chose;
			// leave it alone and keep reading through it.
			$result['error'] = 'symlink';
			return $result;
		}

		if ( ! File_Manager::ensure_data_dir( $kind ) ) {
			$result['error'] = 'data_dir_not_writable';
			return $result;
		}

		$dest  = File_Manager::data_path( $kind );
		$moved = array();
		self::move_tree( $src, $dest, $result, $moved );

		if ( 'agents' === $kind ) {
			self::after_agents_moved( $moved );
		}

		// Remove the old folder only once nothing but empty folders is left.
		self::remove_empty_tree( $src );
		clearstatcache();

		return $result;
	}

	/**
	 * Recursively move the contents of $src into $dest.
	 *
	 * @param string                                   $src    Source directory.
	 * @param string                                   $dest   Destination directory (exists).
	 * @param array{moved:int, left:int, error:string} $result Running totals.
	 * @param string[]                                 $moved  Top-level names moved (for agents).
	 * @param int                                      $depth  Recursion depth.
	 * @return void
	 */
	private static function move_tree( string $src, string $dest, array &$result, array &$moved, int $depth = 0 ): void {
		$entries = scandir( $src );
		if ( ! is_array( $entries ) ) {
			++$result['left'];
			$result['error'] = 'unreadable';
			return;
		}

		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$from = $src . '/' . $name;
			$to   = $dest . '/' . $name;

			if ( is_link( $from ) ) {
				// Never follow or move symlinks; leave them for the owner.
				++$result['left'];
				continue;
			}

			if ( is_dir( $from ) ) {
				if ( ! file_exists( $to ) ) {
					// Whole folder free at the destination: one rename.
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem move of the plugin's own data folder; falls back to a per-file copy below.
					if ( @rename( $from, $to ) ) {
						++$result['moved'];
						if ( 0 === $depth ) {
							$moved[] = $name;
						}
						continue;
					}
					if ( ! wp_mkdir_p( $to ) ) {
						++$result['left'];
						$result['error'] = 'mkdir_failed';
						continue;
					}
				} elseif ( ! is_dir( $to ) ) {
					++$result['left'];
					continue;
				}

				self::move_tree( $from, $to, $result, $moved, $depth + 1 );
				if ( 0 === $depth ) {
					$moved[] = $name;
				}
				continue;
			}

			if ( file_exists( $to ) ) {
				// Same content already there, or a guard file the new folder
				// has its own copy of: the old copy is redundant.
				if ( in_array( $name, self::GUARD_FILES, true ) || self::same_file( $from, $to ) ) {
					wp_delete_file( $from );
				} else {
					++$result['left'];
				}
				continue;
			}

			if ( self::move_file( $from, $to ) ) {
				++$result['moved'];
			} else {
				++$result['left'];
				$result['error'] = 'move_failed';
			}
		}
	}

	/**
	 * Move one file: rename(), or copy + verify + delete across filesystems.
	 *
	 * @param string $from Source file.
	 * @param string $to   Destination file (does not exist).
	 * @return bool
	 */
	private static function move_file( string $from, string $to ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Moving the plugin's own data file out of the legacy folder.
		if ( @rename( $from, $to ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Cross-filesystem fallback; File_Manager::copy() refuses .php, but guarded backups are .php by design.
		if ( ! @copy( $from, $to ) ) {
			return false;
		}

		if ( ! self::same_file( $from, $to ) ) {
			wp_delete_file( $to );
			return false;
		}

		wp_delete_file( $from );
		return ! file_exists( $from );
	}

	/**
	 * Whether two files have identical content.
	 *
	 * @param string $a File path.
	 * @param string $b File path.
	 * @return bool
	 */
	private static function same_file( string $a, string $b ): bool {
		if ( ! is_file( $a ) || ! is_file( $b ) || filesize( $a ) !== filesize( $b ) ) {
			return false;
		}
		return hash_file( 'sha256', $a ) === hash_file( 'sha256', $b );
	}

	/**
	 * Remove $dir and its sub-folders bottom-up, but only folders that are
	 * empty (or hold nothing but the plugin's own guard files).
	 *
	 * @param string $dir Directory.
	 * @return bool True if $dir no longer exists.
	 */
	private static function remove_empty_tree( string $dir ): bool {
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return false;
		}

		$empty = true;
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				if ( ! self::remove_empty_tree( $path ) ) {
					$empty = false;
				}
			} elseif ( ! in_array( $name, self::GUARD_FILES, true ) ) {
				$empty = false;
			}
		}

		if ( ! $empty ) {
			return false;
		}

		foreach ( self::GUARD_FILES as $guard ) {
			if ( is_file( $dir . '/' . $guard ) ) {
				wp_delete_file( $dir . '/' . $guard );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return @rmdir( $dir ) || ! is_dir( $dir );
	}

	/**
	 * Re-sign moved user agents and refresh caches.
	 *
	 * A moved agent keeps its abilities-signature file (an HMAC of the
	 * manifest's content, independent of its location), so a manifest that
	 * verified before the move still verifies after it. One without a
	 * signature is signed now. A manifest whose signature does NOT match is
	 * left unsigned-over: it stays blocked, exactly as it was before the move.
	 *
	 * @param string[] $slugs Agent folder names that were moved.
	 * @return void
	 */
	private static function after_agents_moved( array $slugs ): void {
		foreach ( array_unique( $slugs ) as $slug ) {
			Abilities_Manifest::clear_cache( $slug );
			$manifest = File_Manager::agents_dir() . '/' . $slug . '/abilities.json';
			if ( is_file( $manifest ) && ! is_file( dirname( $manifest ) . '/abilities-signature' ) ) {
				Abilities_Manifest::save_integrity_hash( $slug );
			}
		}

		if ( class_exists( '\\Agentic_Agent_Registry' ) ) {
			\Agentic_Agent_Registry::get_instance()->get_installed_agents( true );
		}
	}
}
