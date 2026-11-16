<?php
/**
 * Storage Migration
 *
 * One-time move of the plugin's data out of the pre-4.5 folders directly
 * inside wp-content (agentic-agents, agentic-knowledge, agentic-backups)
 * into the plugin's data root in the uploads directory
 * (File_Manager::data_dir(): agents/, knowledge/, backups/).
 *
 * On multisite the old folders were shared by every site of the network,
 * while the new data root is per site. There the data is COPIED, never
 * moved, into each site's own data root on that site's first
 * Activator::maybe_upgrade(), and the old folders are never deleted: other
 * sites may not have copied them yet. Site Health tells the administrator
 * they can remove them by hand once every site has migrated.
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
 * Moves (single site) or copies (multisite) legacy wp-content/agentic-* data
 * into the uploads data root.
 *
 * Guarantees:
 *  - Never loses data. A file is only removed from the old folder after it
 *    exists at the new location with the same size; a name that is already
 *    taken at the new location with different content is left where it is.
 *    On multisite nothing is ever removed from the old folders.
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
	 * Strategy: move the legacy folders into the data root and delete them.
	 */
	public const STRATEGY_MOVE = 'move';

	/**
	 * Strategy: copy the legacy folders into this site's data root, keep them.
	 */
	public const STRATEGY_COPY = 'copy';

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

		// Every site runs this once against its own options and data root.
		// On multisite each site copies the shared legacy folders (see
		// strategy()), so no site loses the data another site moved away.
		return self::run();
	}

	/**
	 * Which strategy applies to an install.
	 *
	 * The pre-4.5 folders sat directly in wp-content, so on multisite every
	 * site of the network read the same folders, while the new data root
	 * (wp_upload_dir()) is per site. Moving them on one site would take the
	 * data away from all the others, so multisite copies.
	 *
	 * @param bool $is_multisite Whether the install is a multisite network.
	 * @return string STRATEGY_MOVE or STRATEGY_COPY.
	 */
	public static function strategy( bool $is_multisite ): string {
		return $is_multisite ? self::STRATEGY_COPY : self::STRATEGY_MOVE;
	}

	/**
	 * Whether this install copies (multisite) rather than moves the legacy
	 * folders.
	 *
	 * Filterable through `agent_builder_storage_migration_copy` so the copy
	 * path can be exercised on a single-site test install.
	 *
	 * @return bool
	 */
	public static function uses_copy(): bool {
		return (bool) apply_filters(
			'agent_builder_storage_migration_copy',
			self::STRATEGY_COPY === self::strategy( is_multisite() )
		);
	}

	/**
	 * Whether this site's migration has completed.
	 *
	 * @return bool
	 */
	public static function is_complete(): bool {
		return (int) get_option( self::MIGRATED_OPTION, 0 ) >= self::VERSION;
	}

	/**
	 * Move (or, on multisite, copy) every legacy folder into the data root.
	 *
	 * Single site: complete once every old folder is gone. Multisite: the
	 * old folders always stay, so complete once every file in them exists in
	 * this site's data root.
	 *
	 * @return array<string, array{moved:int, left:int, error:string}>
	 */
	public static function run(): array {
		$results  = array();
		$complete = true;
		$copy     = self::uses_copy();

		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$results[ $kind ] = self::migrate_kind( $kind, $copy );
			if ( $copy ) {
				if ( $results[ $kind ]['left'] > 0 || '' !== $results[ $kind ]['error'] ) {
					$complete = false;
				}
			} elseif ( null !== File_Manager::legacy_fallback_dir( $kind ) ) {
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
				'strategy' => $copy ? self::STRATEGY_COPY : self::STRATEGY_MOVE,
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
	 * Legacy folders this site still depends on: they hold files that are
	 * not in this site's data root yet.
	 *
	 * @return string[] Legacy folders, keyed by kind.
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
	 * Old shared folders that still exist after this site copied them
	 * (multisite only). They are kept for the other sites of the network;
	 * the administrator may delete them once every site has migrated.
	 *
	 * @return string[] Legacy folders, keyed by kind.
	 */
	public static function shared_legacy_dirs(): array {
		if ( ! self::uses_copy() || ! self::is_complete() ) {
			return array();
		}
		$dirs = array();
		foreach ( array_keys( File_Manager::LEGACY_DIRS ) as $kind ) {
			$dir = File_Manager::legacy_dir( $kind );
			if ( '' !== $dir && is_dir( $dir ) ) {
				$dirs[ $kind ] = $dir;
			}
		}
		return $dirs;
	}

	/**
	 * Move (or copy) one legacy folder into its data root subfolder.
	 *
	 * @param string $kind 'agents', 'knowledge' or 'backups'.
	 * @param bool   $copy Copy and keep the old folder (multisite).
	 * @return array{moved:int, left:int, error:string}
	 */
	private static function migrate_kind( string $kind, bool $copy = false ): array {
		$result = array(
			'moved' => 0,
			'left'  => 0,
			'error' => '',
		);

		$src = File_Manager::legacy_dir( $kind );
		if ( '' === $src || ! is_dir( $src ) ) {
			return $result;
		}

		if ( is_link( $src ) && ! $copy ) {
			// A symlinked legacy folder points somewhere the site owner chose;
			// leave it alone and keep reading through it. (Copying only
			// reads, so the multisite copy may read through the link.)
			$result['error'] = 'symlink';
			return $result;
		}

		if ( ! File_Manager::ensure_data_dir( $kind ) ) {
			$result['error'] = 'data_dir_not_writable';
			return $result;
		}

		$dest  = File_Manager::data_path( $kind );
		$moved = array();
		if ( $copy ) {
			self::copy_tree( $src, $dest, $result, $moved );
		} else {
			self::move_tree( $src, $dest, $result, $moved );
		}

		if ( 'agents' === $kind ) {
			self::after_agents_moved( $moved );
		}

		// Single site: remove the old folder only once nothing but empty
		// folders is left. Multisite: never — other sites still copy from it.
		if ( ! $copy ) {
			self::remove_empty_tree( $src );
		}
		clearstatcache();

		return $result;
	}

	/**
	 * Recursively copy the contents of $src into $dest, leaving $src as is.
	 *
	 * A name already present at the destination with the same content (or a
	 * guard file the new folder has its own copy of) counts as done; one with
	 * different content is this site's own newer data and is kept, counted
	 * in "left" so the old copy stays readable for this site.
	 *
	 * @param string                                   $src    Source directory.
	 * @param string                                   $dest   Destination directory (exists).
	 * @param array{moved:int, left:int, error:string} $result Running totals ("moved" counts copies).
	 * @param string[]                                 $copied Top-level names copied (for agents).
	 * @param int                                      $depth  Recursion depth.
	 * @return void
	 */
	private static function copy_tree( string $src, string $dest, array &$result, array &$copied, int $depth = 0 ): void {
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
				// Never follow symlinks inside the old folders.
				++$result['left'];
				continue;
			}

			if ( is_dir( $from ) ) {
				if ( ! file_exists( $to ) && ! wp_mkdir_p( $to ) ) {
					++$result['left'];
					$result['error'] = 'mkdir_failed';
					continue;
				}
				if ( ! is_dir( $to ) ) {
					++$result['left'];
					continue;
				}
				self::copy_tree( $from, $to, $result, $copied, $depth + 1 );
				if ( 0 === $depth ) {
					$copied[] = $name;
				}
				continue;
			}

			if ( file_exists( $to ) ) {
				if ( ! in_array( $name, self::GUARD_FILES, true ) && ! self::same_file( $from, $to ) ) {
					++$result['left'];
				}
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying the plugin's own data out of the shared legacy folder; File_Manager::copy() refuses .php, but guarded backups are .php by design.
			if ( @copy( $from, $to ) && self::same_file( $from, $to ) ) {
				++$result['moved'];
			} else {
				if ( file_exists( $to ) ) {
					wp_delete_file( $to );
				}
				++$result['left'];
				$result['error'] = 'copy_failed';
			}
		}
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
