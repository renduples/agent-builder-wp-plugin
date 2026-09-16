<?php
/**
 * File Manager
 *
 * Centralised file I/O for the plugin. Tries WP_Filesystem_Direct when
 * available (satisfies WP.org coding standards), but detects non-direct
 * transports (FTP/SSH) that silently return false and falls back to
 * native PHP I/O so writes never fail silently on production.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.9.103
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper for all plugin file-system operations.
 *
 * Usage:
 *   File_Manager::put_contents( $path, $content );
 *   File_Manager::copy( $src, $dest );
 *   File_Manager::delete( $path );
 *   File_Manager::rmdir( $path, true );  // recursive
 *   File_Manager::mkdir( $path );
 */
class File_Manager {

	/**
	 * Extensions no tool may ever write or copy to, under any allowed root.
	 *
	 * WordPress.org Plugin Developer FAQ: a plugin must not generate code
	 * intended to be executed on the site. Blocking only `.php` is not
	 * enough — a classic uploads-dir RCE bypass writes a permissive
	 * `.htaccess` first (enabling PHP execution, or overriding an existing
	 * deny rule) rather than the payload itself, so both are denied here.
	 *
	 * @var string[]
	 */
	private const DENYLISTED_EXTENSIONS = array(
		'php',
		'php2',
		'php3',
		'php4',
		'php5',
		'php6',
		'php7',
		'php8',
		'phtml',
		'pht',
		'phps',
		'phar',
		'htaccess',
		'htpasswd',
	);

	/**
	 * Safe default permissions for files and directories this class creates,
	 * applied explicitly rather than trusting the host's umask — a
	 * misconfigured umask (e.g. 0000) would otherwise leave a freshly
	 * written file world-writable regardless of the extension denylist.
	 */
	private const SAFE_FILE_MODE = 0644;
	private const SAFE_DIR_MODE  = 0755;

	/**
	 * Apache: deny execution of every scripting extension this class denies
	 * as a write target, and disable listing. Covers both the Apache 2.4
	 * (mod_authz_core) and 2.2-and-earlier (mod_access_compat) authorization
	 * syntax, since the plugin cannot know which one a given host runs.
	 * Defense-in-depth only — the real control is the write-time denylist
	 * above; this just means a write that somehow lands here anyway still
	 * cannot execute.
	 */
	private const PROTECTIVE_HTACCESS = <<<'HTACCESS'
# Silence is golden — no directory listing, no script execution.
Options -Indexes
<IfModule mod_authz_core.c>
	<FilesMatch "\.(?:php[0-9]?|phtml|pht|phps|phar)$">
		Require all denied
	</FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
	<FilesMatch "\.(?:php[0-9]?|phtml|pht|phps|phar)$">
		Order allow,deny
		Deny from all
	</FilesMatch>
</IfModule>
HTACCESS;

	/**
	 * IIS/Windows equivalent of the .htaccess above — Apache-only hosts
	 * ignore this file entirely, so shipping both is harmless. Denies the
	 * PHP handler and the same scripting extensions at the request-filtering
	 * level, which does not depend on a specific handler module being
	 * present or named a particular way.
	 */
	private const PROTECTIVE_WEB_CONFIG = <<<'WEBCONFIG'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
	<system.webServer>
		<handlers>
			<remove name="PHP_via_FastCGI" />
			<remove name="PHP" />
		</handlers>
		<security>
			<requestFiltering>
				<fileExtensions>
					<add fileExtension=".php" allowed="false" />
					<add fileExtension=".phtml" allowed="false" />
					<add fileExtension=".phar" allowed="false" />
					<add fileExtension=".pht" allowed="false" />
					<add fileExtension=".phps" allowed="false" />
				</fileExtensions>
			</requestFiltering>
		</security>
		<directoryBrowse enabled="false" />
	</system.webServer>
</configuration>
WEBCONFIG;

	/**
	 * Cached direct filesystem instance, false when unavailable.
	 *
	 * @var \WP_Filesystem_Direct|false|null  null = not yet initialised.
	 */
	private static \WP_Filesystem_Direct|false|null $fs = null;

	/**
	 * Initialise WP_Filesystem and return the Direct transport, or null.
	 *
	 * Returns null (not false) when the transport is not direct so callers
	 * can simply do `if ( self::fs() ) { ... }` without worrying about
	 * truthy-but-broken FTP/SSH objects.
	 */
	private static function fs(): ?\WP_Filesystem_Direct {
		if ( null !== self::$fs ) {
			return self::$fs instanceof \WP_Filesystem_Direct ? self::$fs : null;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		global $wp_filesystem;

		// Only use WP_Filesystem when it resolved to the direct transport.
		// FTP/SSH transports initialise successfully but their put_contents/
		// copy/delete methods silently return false without credentials —
		// making them indistinguishable from success until something is missing.
		self::$fs = ( $wp_filesystem instanceof \WP_Filesystem_Direct )
			? $wp_filesystem
			: false;

		return self::$fs instanceof \WP_Filesystem_Direct ? self::$fs : null;
	}

	/**
	 * Write content to a file, creating it if it does not exist.
	 *
	 * @param string $path    Absolute file path.
	 * @param string $content File content.
	 * @return bool True on success.
	 */
	public static function put_contents( string $path, string $content ): bool {
		if ( ! self::is_allowed_path( $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agentic File_Manager: blocked write outside allowed directory: ' . $path );
			return false;
		}
		if ( self::has_denylisted_extension( $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agentic File_Manager: blocked write of disallowed file type: ' . $path );
			return false;
		}
		$fs = self::fs();
		if ( $fs ) {
			$result = $fs->put_contents( $path, $content, FS_CHMOD_FILE );
			if ( false !== $result ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = false !== file_put_contents( $path, $content );
		if ( $result ) {
			// WP_Filesystem was unavailable, so this went through PHP's own
			// file_put_contents() above, which inherits whatever the host's
			// umask produces — a misconfigured umask (e.g. 0000) would
			// otherwise leave the new file world-writable. Set it explicitly
			// rather than trust the environment.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
			chmod( $path, self::SAFE_FILE_MODE );
		}
		return $result;
	}

	/**
	 * Read a file and return its contents, or false on failure.
	 *
	 * @param string $path Absolute file path.
	 * @return string|false
	 */
	public static function get_contents( string $path ): string|false {
		if ( ! file_exists( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $path );
	}

	/**
	 * Copy a file.
	 *
	 * @param string $src       Source path.
	 * @param string $dest      Destination path.
	 * @param bool   $overwrite Overwrite destination if it exists.
	 * @return bool
	 */
	public static function copy( string $src, string $dest, bool $overwrite = false ): bool {
		if ( ! file_exists( $src ) ) {
			return false;
		}
		if ( ! self::is_allowed_path( $dest ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agentic File_Manager: blocked copy outside allowed directory: ' . $dest );
			return false;
		}
		if ( self::has_denylisted_extension( $dest ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agentic File_Manager: blocked copy of disallowed file type: ' . $dest );
			return false;
		}
		$fs = self::fs();
		if ( $fs ) {
			$result = $fs->copy( $src, $dest, $overwrite );
			if ( false !== $result ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		$result = copy( $src, $dest );
		if ( $result ) {
			// Same umask concern as put_contents()'s native fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
			chmod( $dest, self::SAFE_FILE_MODE );
		}
		return $result;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path Absolute path to file.
	 * @return bool True on success or if the file did not exist.
	 */
	public static function delete( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! self::is_allowed_path( $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agentic File_Manager: blocked delete outside allowed directory: ' . $path );
			return false;
		}
		$fs = self::fs();
		if ( $fs ) {
			$result = $fs->delete( $path );
			if ( false !== $result ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return unlink( $path );
	}

	/**
	 * Remove a directory, optionally recursively.
	 *
	 * @param string $path      Absolute directory path.
	 * @param bool   $recursive Delete contents first.
	 * @return bool
	 */
	public static function rmdir( string $path, bool $recursive = false ): bool {
		if ( ! is_dir( $path ) ) {
			return true;
		}
		if ( $recursive ) {
			return self::rmdir_recursive( $path );
		}
		$fs = self::fs();
		if ( $fs ) {
			$result = $fs->rmdir( $path );
			if ( false !== $result ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return rmdir( $path );
	}

	/**
	 * Create a directory (and parents) if it does not exist.
	 *
	 * Delegates to wp_mkdir_p which handles both direct and indirect
	 * filesystem methods and is universally safe.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool
	 */
	public static function mkdir( string $path ): bool {
		if ( is_dir( $path ) ) {
			return true;
		}
		$result = wp_mkdir_p( $path );
		if ( $result ) {
			// wp_mkdir_p() already applies FS_CHMOD_DIR when it goes through
			// WP_Filesystem, but its plain-mkdir() fallback inherits the
			// host's umask — set the mode explicitly either way.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
			chmod( $path, self::SAFE_DIR_MODE );
		}
		return $result;
	}

	/**
	 * Create one of the plugin's own writable directories (agentic-agents,
	 * agentic-knowledge, agentic-backups — never the shared uploads
	 * directory, which this plugin does not own) and seed it with
	 * execution-denying protection, the first time it is created.
	 *
	 * This is defense-in-depth against a misconfigured webserver, not the
	 * primary control: the extension denylist above is what actually stops
	 * a tool from writing an executable file here in the first place. This
	 * exists for the case that assumption is ever wrong — a future bug, a
	 * host that executes an extension this class doesn't know about, or a
	 * directory an admin copies files into by hand outside the plugin
	 * entirely — so the directory itself cannot execute anything regardless.
	 *
	 * `index.php` ("Silence is golden") blocks directory-listing fallback on
	 * servers with no other protection; `.htaccess` covers Apache (both the
	 * 2.4 and pre-2.4 authorization syntax, since the host's version isn't
	 * known); `web.config` covers IIS, which a WordPress site can genuinely
	 * run under on Windows and which ignores `.htaccess` entirely. Existing
	 * files are never overwritten, so a site owner's own customisation of
	 * any of these is left alone.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True if the directory exists (or was created) afterward.
	 */
	public static function ensure_protected_dir( string $path ): bool {
		if ( ! self::mkdir( $path ) ) {
			return false;
		}

		$path = untrailingslashit( $path );

		$protections = array(
			'index.php'   => "<?php\n// Silence is golden.\n",
			'.htaccess'   => self::PROTECTIVE_HTACCESS,
			'web.config'  => self::PROTECTIVE_WEB_CONFIG,
		);

		foreach ( $protections as $filename => $content ) {
			$file = $path . '/' . $filename;
			if ( file_exists( $file ) ) {
				continue;
			}
			// Native write, not self::put_contents(): .htaccess is on this
			// class's own write denylist by design (no *tool* may ever write
			// one), but this is the plugin's own trusted, hardcoded content,
			// written only from this one internal method — never from a
			// tool argument.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $file, $content ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
				chmod( $file, self::SAFE_FILE_MODE );
			}
		}

		return true;
	}

	/**
	 * Check whether a path exists.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function exists( string $path ): bool {
		return file_exists( $path );
	}

	/**
	 * Check whether a path is a directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_dir( string $path ): bool {
		return is_dir( $path );
	}

	/**
	 * Check whether a path is writable.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_writable( string $path ): bool {
		$fs = self::fs();
		if ( $fs ) {
			return $fs->is_writable( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return is_writable( $path );
	}

	/**
	 * Check whether $path is rooted inside one of the plugin's allowed write roots.
	 *
	 * Write operations are restricted to:
	 *   - The uploads directory (wp_upload_dir basedir)
	 *   - The agentic-agents user directory (AGENTIC_AGENTS_DIR)
	 *   - The agentic-knowledge directory (AGENTIC_KNOWLEDGE_DIR)
	 *   - The agentic-backups directory (AGENTIC_BACKUPS_DIR)
	 *   - `abilities.json` specifically, under the plugin's own bundled
	 *     library/agents/ tree (AGENT_BUILDER_DIR) — see the narrow
	 *     exception below; nothing else in the plugin directory is writable.
	 *
	 * The plugin directory itself is deliberately NOT a general allowed
	 * root: WordPress.org Plugin Developer FAQ / Guideline 8 treat a tool
	 * that can write into the plugin's own tree as generating code intended
	 * to run on the site. The one narrow exception exists because
	 * configure_approval_gate/enable_webmcp_defaults rewrite a bundled
	 * agent's abilities.json (e.g. turning off WebMCP exposure on a risky
	 * ability) — both take no tool parameters at all, so neither the path
	 * nor the content is ever attacker-influenced.
	 *
	 * Throws nothing — returns false so callers can surface the failure cleanly.
	 *
	 * @param string $path Absolute path being written to.
	 * @return bool True if the path is within an allowed directory.
	 */
	public static function is_allowed_path( string $path ): bool {
		$allowed_roots = array();

		if ( defined( 'AGENTIC_AGENTS_DIR' ) ) {
			$allowed_roots[] = trailingslashit( AGENTIC_AGENTS_DIR );
		}

		// Knowledge Wiki (OKF) and persona knowledge files.
		if ( defined( 'AGENTIC_KNOWLEDGE_DIR' ) ) {
			$allowed_roots[] = trailingslashit( AGENTIC_KNOWLEDGE_DIR );
		}

		if ( defined( 'AGENTIC_BACKUPS_DIR' ) ) {
			$allowed_roots[] = trailingslashit( AGENTIC_BACKUPS_DIR );
		}

		$upload_dir = wp_upload_dir( null, false );
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$allowed_roots[] = trailingslashit( $upload_dir['basedir'] );
		}

		// Resolve symlinks to get the real path for comparison.
		$real = realpath( dirname( $path ) );
		if ( false === $real ) {
			// Parent directory doesn't exist yet — check the declared path directly.
			$real = dirname( $path );
		}
		$real = trailingslashit( $real ) . basename( $path );

		foreach ( $allowed_roots as $root ) {
			if ( str_starts_with( $real, $root ) ) {
				return true;
			}
		}

		if ( defined( 'AGENT_BUILDER_DIR' ) ) {
			$agents_library_root = trailingslashit( AGENT_BUILDER_DIR ) . 'library/agents/';
			if ( str_starts_with( $real, $agents_library_root ) && 'abilities.json' === basename( $real ) ) {
				return true;
			}
		}

		// robots.txt/llms.txt live at the site web root, one level above every
		// other root above — required reading location for search/AI crawlers,
		// not a plugin choice. Named-file exception only: Tool_Helpers::
		// restore_backup() can reconstruct a path anywhere under ABSPATH or
		// WP_CONTENT_DIR from a backup filename, but nothing in this plugin
		// ever creates a backup of anything else at the site root, or of
		// anything under wp-content/plugins/ or wp-content/themes/ — so nothing
		// outside these two exact filenames should ever be reachable here.
		if ( defined( 'ABSPATH' ) && in_array( basename( $real ), array( 'robots.txt', 'llms.txt' ), true ) ) {
			$site_root = trailingslashit( realpath( ABSPATH ) ?: ABSPATH );
			if ( str_starts_with( $real, $site_root ) && substr_count( substr( $real, strlen( $site_root ) ), '/' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $path ends in an extension no tool may ever write.
	 *
	 * Matches on a trailing ".ext", not PHP's own single last-extension
	 * parsing, so a double extension like "shell.jpg.php" — which still
	 * executes as PHP on a stock Apache/Nginx config — is caught the same
	 * as a plain "shell.php". Public so other write paths that don't go
	 * through put_contents()/copy() (e.g. Rest_Api's code_change handler)
	 * can check the same denylist explicitly, before attempting the write.
	 *
	 * Two Windows-specific bypasses are normalised away before matching:
	 *  - NTFS silently strips trailing dots and spaces from a filename at
	 *    creation time, so a check for exactly "shell.php" can be evaded by
	 *    asking to write "shell.php." or "shell.php " — which Windows then
	 *    creates as plain "shell.php" anyway. Trimmed here so the match sees
	 *    what the filesystem will actually end up with.
	 *  - An NTFS Alternate Data Stream reference ("shell.php::$DATA") does
	 *    not end in a denylisted extension by simple string matching, so any
	 *    filename containing "::" is rejected outright rather than trying to
	 *    parse the stream name — this class has no legitimate reason to ever
	 *    write one.
	 *
	 * @param string $path Absolute or relative path.
	 * @return bool
	 */
	public static function has_denylisted_extension( string $path ): bool {
		$filename = strtolower( basename( $path ) );

		if ( str_contains( $filename, '::' ) ) {
			return true;
		}

		$filename = rtrim( $filename, ". \t\n\r\0\x0B" );

		foreach ( self::DENYLISTED_EXTENSIONS as $ext ) {
			if ( str_ends_with( $filename, '.' . $ext ) || $filename === $ext ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively delete a directory and all its contents using native PHP.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool
	 */
	private static function rmdir_recursive( string $path ): bool {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return rmdir( $path );
	}
}
