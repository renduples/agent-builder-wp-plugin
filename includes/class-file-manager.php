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
	 * Apache: deny every request into a private storage directory
	 * (the data root's PRIVATE_SUBDIRS). Nothing in those directories is
	 * ever meant to be fetched over HTTP — the plugin only reads them from
	 * disk — so unlike PROTECTIVE_HTACCESS this also denies static files
	 * such as .json, .md and .txt. Defense-in-depth only: nginx ignores
	 * .htaccess, which is why backups are additionally stored as
	 * exit-guarded .php files with unguessable names (see
	 * put_guarded_contents()).
	 */
	private const PRIVATE_HTACCESS = <<<'HTACCESS'
# AgentStructor private storage — never served over the web.
Options -Indexes
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order allow,deny
	Deny from all
</IfModule>
HTACCESS;

	/**
	 * IIS equivalent of PRIVATE_HTACCESS: no PHP handler, and request
	 * filtering rejects every file extension (allowUnlisted="false").
	 */
	private const PRIVATE_WEB_CONFIG = <<<'WEBCONFIG'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
	<system.webServer>
		<handlers>
			<remove name="PHP_via_FastCGI" />
			<remove name="PHP" />
		</handlers>
		<security>
			<requestFiltering>
				<fileExtensions allowUnlisted="false" />
			</requestFiltering>
		</security>
		<directoryBrowse enabled="false" />
	</system.webServer>
</configuration>
WEBCONFIG;

	/**
	 * First line of every guarded storage file (see put_guarded_contents()).
	 *
	 * If a web server ever executes the file, PHP stops before the payload;
	 * if it refuses to execute it (Apache/IIS rules above), the request is
	 * denied outright. Either way the payload after this line is never sent.
	 */
	public const GUARD_HEADER = "<?php exit; ?>\n";

	/**
	 * Name of the plugin's data folder inside the uploads directory.
	 *
	 * The ONE place the on-disk folder name is defined: every data path the
	 * plugin uses is built from data_dir(), which is
	 * wp_upload_dir()['basedir'] . '/' . DATA_SLUG. Set to the plugin's
	 * WordPress.org slug (data goes in a folder named after the plugin), so
	 * existing data only ever has to be moved once.
	 */
	public const DATA_SLUG = 'agentstructor';

	/**
	 * Subfolders of the data root that must never be served over HTTP. Each
	 * gets the deny-all .htaccess/web.config and an index.php.
	 *
	 * - agents:    user-created agents (agent.json, abilities.json, prompts).
	 * - knowledge: knowledge files and the knowledge wiki.
	 * - backups:   automatic pre-change backups (exit-guarded .php files).
	 * - docs:      generated documentation for read-only (bundled) agents.
	 * - tmp:       short-lived chat image uploads (cleaned after an hour).
	 *
	 * `exports` (documents the user downloads by URL) is public by design and
	 * only gets the execution-denying rules.
	 *
	 * @var string[]
	 */
	public const PRIVATE_SUBDIRS = array( 'agents', 'knowledge', 'backups', 'docs', 'tmp' );

	/**
	 * Pre-4.5 storage locations directly inside wp-content, keyed by the data
	 * root subfolder that replaced each one. Only read as a migration source
	 * (Storage_Migration) and, while a migration is incomplete, as a
	 * read-only fallback. Never written to.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_DIRS = array(
		'agents'    => 'agentic-agents',
		'knowledge' => 'agentic-knowledge',
		'backups'   => 'agentic-backups',
	);

	/**
	 * Earlier names of the data root folder inside the uploads directory,
	 * from before the plugin was renamed. Only read as a migration source
	 * (Storage_Migration moves each one into data_dir()) and, while a move
	 * is incomplete, as a read-only fallback. Never written to.
	 *
	 * @var string[]
	 */
	public const PREVIOUS_DATA_SLUGS = array( 'siteloom' );

	/**
	 * Absolute path of the plugin's data root:
	 * {uploads basedir}/agentstructor (no trailing slash).
	 *
	 * Resolved at call time with wp_upload_dir() — never hard-coded — so it
	 * follows the upload_path option, the upload_dir filter and, on
	 * multisite, the current site. Does not create anything.
	 *
	 * @return string
	 */
	public static function data_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::DATA_SLUG;
	}

	/**
	 * Public URL of the data root (only the exports subfolder is ever linked).
	 *
	 * @return string
	 */
	public static function data_url(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) $uploads['baseurl'] ) . '/' . self::DATA_SLUG;
	}

	/**
	 * Absolute path of one data subfolder, e.g. data_path( 'agents' ).
	 *
	 * @param string $sub Subfolder name ('' for the root).
	 * @return string
	 */
	public static function data_path( string $sub = '' ): string {
		$sub = trim( $sub, '/' );
		return '' === $sub ? self::data_dir() : self::data_dir() . '/' . $sub;
	}

	/**
	 * User-created agents: {data root}/agents.
	 *
	 * @return string
	 */
	public static function agents_dir(): string {
		return self::data_path( 'agents' );
	}

	/**
	 * Knowledge files and the knowledge wiki: {data root}/knowledge.
	 *
	 * @return string
	 */
	public static function knowledge_dir(): string {
		return self::data_path( 'knowledge' );
	}

	/**
	 * Automatic pre-change backups: {data root}/backups.
	 *
	 * @return string
	 */
	public static function backups_dir(): string {
		return self::data_path( 'backups' );
	}

	/**
	 * Generated documents the user downloads (PDF, DOCX, XLSX…): {data root}/exports.
	 *
	 * @return string
	 */
	public static function exports_dir(): string {
		return self::data_path( 'exports' );
	}

	/**
	 * Public URL of exports_dir().
	 *
	 * @return string
	 */
	public static function exports_url(): string {
		return self::data_url() . '/exports';
	}

	/**
	 * Path of an export relative to the uploads basedir, as stored in tool
	 * results and checked by Result_Card ("agentstructor/exports/<name>").
	 *
	 * @param string $filename Bare file name.
	 * @return string
	 */
	public static function export_relative_path( string $filename ): string {
		return self::DATA_SLUG . '/exports/' . $filename;
	}

	/**
	 * Old wp-content/agentic-* location that a data subfolder replaced.
	 *
	 * @param string $kind 'agents', 'knowledge' or 'backups'.
	 * @return string Absolute path ('' for an unknown kind). May not exist.
	 */
	public static function legacy_dir( string $kind ): string {
		if ( ! isset( self::LEGACY_DIRS[ $kind ] ) ) {
			return '';
		}
		return untrailingslashit( WP_CONTENT_DIR ) . '/' . self::LEGACY_DIRS[ $kind ];
	}

	/**
	 * Earlier data roots in the uploads directory (see PREVIOUS_DATA_SLUGS)
	 * that still exist on this site.
	 *
	 * @return array<string, string> Absolute paths, keyed by folder name.
	 */
	public static function previous_data_dirs(): array {
		$base = untrailingslashit( (string) wp_upload_dir( null, false )['basedir'] );
		$dirs = array();
		foreach ( self::PREVIOUS_DATA_SLUGS as $slug ) {
			if ( self::DATA_SLUG === $slug ) {
				continue;
			}
			$dir = $base . '/' . $slug;
			if ( is_dir( $dir ) ) {
				$dirs[ $slug ] = $dir;
			}
		}
		return $dirs;
	}

	/**
	 * The legacy directory to keep READING as a fallback, or null.
	 *
	 * Non-null only while the old folder still exists — i.e. the one-time
	 * move into the uploads data root has not run yet or could not move
	 * everything (a permissions problem). Storage_Migration removes the old
	 * folder once it is empty, after which this returns null and costs one
	 * stat() per request. Nothing is ever written to the returned path.
	 *
	 * On multisite the old (shared) folder is copied and kept, so once this
	 * site's copy completed it is no longer read for this site.
	 *
	 * @param string $kind 'agents', 'knowledge' or 'backups'.
	 * @return string|null
	 */
	public static function legacy_fallback_dir( string $kind ): ?string {
		$dir = self::legacy_dir( $kind );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return null;
		}
		if ( Storage_Migration::uses_copy() && Storage_Migration::is_complete() ) {
			return null;
		}
		return $dir;
	}

	/**
	 * Directories to READ a kind of data from, highest priority first: the
	 * data root subfolder, then an earlier data root (see
	 * PREVIOUS_DATA_SLUGS) and the legacy folder while they still exist.
	 *
	 * @param string $kind 'agents', 'knowledge' or 'backups'.
	 * @return string[]
	 */
	public static function read_dirs( string $kind ): array {
		$dirs = array( self::data_path( $kind ) );
		foreach ( self::previous_data_dirs() as $previous ) {
			if ( is_dir( $previous . '/' . $kind ) ) {
				$dirs[] = $previous . '/' . $kind;
			}
		}
		$legacy = self::legacy_fallback_dir( $kind );
		if ( null !== $legacy ) {
			$dirs[] = $legacy;
		}
		return $dirs;
	}

	/**
	 * Locate an existing data file by its path relative to a data kind's
	 * folder, checking the data root first and the legacy folder second.
	 *
	 * @param string $kind     'agents', 'knowledge' or 'backups'.
	 * @param string $relative Relative path (no traversal segments).
	 * @return string|null Absolute path, or null when no folder holds it.
	 */
	public static function find_data_file( string $kind, string $relative ): ?string {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || in_array( '..', explode( '/', $relative ), true ) ) {
			return null;
		}
		foreach ( self::read_dirs( $kind ) as $dir ) {
			if ( file_exists( $dir . '/' . $relative ) ) {
				return $dir . '/' . $relative;
			}
		}
		return null;
	}

	/**
	 * Create a data subfolder on demand, with its web-access protection.
	 *
	 * The data root itself only gets the execution-denying rules (its
	 * exports/ subfolder must stay downloadable); every folder in
	 * PRIVATE_SUBDIRS gets the deny-all rules.
	 *
	 * @param string $sub Subfolder name ('agents', 'knowledge', 'backups', 'docs', 'tmp', 'exports').
	 * @return bool True if the folder exists afterwards.
	 */
	public static function ensure_data_dir( string $sub ): bool {
		if ( ! self::ensure_protected_dir( self::data_dir() ) ) {
			return false;
		}
		$first = explode( '/', trim( $sub, '/' ) )[0];
		return self::ensure_protected_dir( self::data_path( $sub ), in_array( $first, self::PRIVATE_SUBDIRS, true ) );
	}

	/**
	 * Whether $path is inside the uploads data root (the only place the
	 * plugin writes its own files). Symlinks in existing parents are resolved.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_in_data_dir( string $path ): bool {
		$root = self::real_or_normalized( self::data_dir() );
		$real = self::real_or_normalized( $path );
		return str_starts_with( trailingslashit( $real ), trailingslashit( $root ) );
	}

	/**
	 * Resolve the deepest existing ancestor of $path with realpath() and
	 * re-append the rest; normalized to forward slashes.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function real_or_normalized( string $path ): string {
		$path   = wp_normalize_path( untrailingslashit( $path ) );
		$suffix = '';
		$probe  = $path;
		while ( '' !== $probe && '/' !== $probe && '.' !== $probe && ! file_exists( $probe ) ) {
			$suffix = '/' . basename( $probe ) . $suffix;
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				break;
			}
			$probe = $parent;
		}
		$real = realpath( $probe );
		$base = false !== $real ? wp_normalize_path( $real ) : $probe;
		return untrailingslashit( $base ) . $suffix;
	}

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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $path, self::SAFE_DIR_MODE );
		}
		return $result;
	}

	/**
	 * Create one of the plugin's own writable directories (the uploads data
	 * root and its subfolders — see ensure_data_dir(); never the shared
	 * uploads directory itself, which this plugin does not own) and seed it
	 * with execution-denying protection, the first time it is created.
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
	 * With $deny_all set (the PRIVATE_SUBDIRS of the data root) the .htaccess
	 * and web.config deny every request into the directory, not only script
	 * execution — those directories are only ever read from disk. An
	 * existing file still wins, except an unmodified copy of the older
	 * execution-only rules, which is upgraded in place.
	 *
	 * @param string $path     Absolute directory path.
	 * @param bool   $deny_all Deny all web access, not just script execution.
	 * @return bool True if the directory exists (or was created) afterward.
	 */
	public static function ensure_protected_dir( string $path, bool $deny_all = false ): bool {
		if ( ! self::mkdir( $path ) ) {
			return false;
		}

		$path = untrailingslashit( $path );

		$protections = array(
			'index.php'   => "<?php\n// Silence is golden.\n",
			'.htaccess'   => $deny_all ? self::PRIVATE_HTACCESS : self::PROTECTIVE_HTACCESS,
			'web.config'  => $deny_all ? self::PRIVATE_WEB_CONFIG : self::PROTECTIVE_WEB_CONFIG,
		);
		$superseded  = array(
			'.htaccess'  => self::PROTECTIVE_HTACCESS,
			'web.config' => self::PROTECTIVE_WEB_CONFIG,
		);

		foreach ( $protections as $filename => $content ) {
			$file = $path . '/' . $filename;
			if ( file_exists( $file ) ) {
				// Upgrade the plugin's own older execution-only rules to the
				// deny-all ones; anything the site owner edited is left alone.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( ! $deny_all || ! isset( $superseded[ $filename ] ) || file_get_contents( $file ) !== $superseded[ $filename ] ) {
					continue;
				}
			}
			// Native write, not self::put_contents(): .htaccess is on this
			// class's own write denylist by design (no *tool* may ever write
			// one), but this is the plugin's own trusted, hardcoded content,
			// written only from this one internal method — never from a
			// tool argument.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $file, $content ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
				chmod( $file, self::SAFE_FILE_MODE );
			}
		}

		return true;
	}

	/**
	 * Write a private storage file as an exit-guarded .php file.
	 *
	 * The file starts with GUARD_HEADER, so a direct HTTP request either
	 * executes it (PHP exits before the payload) or is denied by the
	 * directory's .htaccess/web.config — on any server, nginx included, the
	 * payload is never served. Callers also give each file an unguessable
	 * random name component.
	 *
	 * Only ever writes inside backups_dir() or knowledge_dir(), only a plain
	 * "name.php" file name, and
	 * only with the hardcoded guard first — so, unlike put_contents(), the
	 * .php extension is allowed here: this is the plugin's own storage
	 * format, never code and never a tool-chosen path.
	 *
	 * @param string $path    Absolute file path (must end in .php).
	 * @param string $payload Data to store after the guard line.
	 * @return bool True on success.
	 */
	public static function put_guarded_contents( string $path, string $payload ): bool {
		if ( ! self::is_private_storage_path( $path ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Agentic File_Manager: blocked guarded write outside private storage: ' . $path );
			return false;
		}

		// Native write, like ensure_protected_dir(): put_contents() denies
		// .php by design, and this is the one trusted internal writer of the
		// guarded format.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = false !== file_put_contents( $path, self::GUARD_HEADER . $payload );
		if ( $result ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $path, self::SAFE_FILE_MODE );
		}
		return $result;
	}

	/**
	 * Read a file written by put_guarded_contents(), without the guard line.
	 *
	 * @param string   $path   Absolute file path.
	 * @param int|null $length Read at most this many payload bytes (null = all).
	 * @return string|false Payload, or false if missing or not guarded.
	 */
	public static function get_guarded_contents( string $path, ?int $length = null ): string|false {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		$guard_len = strlen( self::GUARD_HEADER );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = null === $length ? file_get_contents( $path ) : file_get_contents( $path, false, null, 0, $guard_len + max( 0, $length ) );
		if ( false === $raw || ! str_starts_with( $raw, self::GUARD_HEADER ) ) {
			return false;
		}

		return (string) substr( $raw, $guard_len );
	}

	/**
	 * Whether $path is a plain "*.php" file directly inside (or below) one of
	 * the plugin's private storage directories.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	private static function is_private_storage_path( string $path ): bool {
		$name = basename( $path );
		if ( ! preg_match( '/^[A-Za-z0-9_-]+\.php$/', $name ) || 'index.php' === $name ) {
			return false;
		}

		$dir = realpath( dirname( $path ) );
		if ( false === $dir ) {
			return false;
		}
		$dir = trailingslashit( wp_normalize_path( $dir ) );

		foreach ( array( self::backups_dir(), self::knowledge_dir() ) as $storage_dir ) {
			$root = realpath( $storage_dir );
			if ( false !== $root && str_starts_with( $dir, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				return true;
			}
		}

		return false;
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
	 * Write operations are restricted to the uploads directory
	 * (wp_upload_dir() basedir), which contains the plugin's own data root
	 * (data_dir(): user agents, knowledge, backups, exports), plus the two
	 * named site-root files below.
	 *
	 * Nothing inside the plugin's own directory is writable — not even a
	 * bundled agent's abilities.json: bundled agents are read-only, and the
	 * settings that used to be written into them (WebMCP exposure, knowledge
	 * files, integrity signatures) are stored in the database instead (see
	 * Abilities_Manifest::persist()). Nor is anything else under wp-content
	 * (the pre-4.5 wp-content/agentic-* folders are only read, as a
	 * migration source).
	 *
	 * Throws nothing — returns false so callers can surface the failure cleanly.
	 *
	 * @param string $path Absolute path being written to.
	 * @return bool True if the path is within an allowed directory.
	 */
	public static function is_allowed_path( string $path ): bool {
		$allowed_roots = array();

		$upload_dir = wp_upload_dir( null, false );
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$allowed_roots[] = trailingslashit( $upload_dir['basedir'] );
			$real_base       = realpath( $upload_dir['basedir'] );
			if ( false !== $real_base ) {
				$allowed_roots[] = trailingslashit( $real_base );
			}
		}

		// Resolve symlinks to get the real path for comparison.
		$real = realpath( dirname( $path ) );
		if ( false === $real ) {
			// Parent directory doesn't exist yet — check the declared path directly.
			$real = dirname( $path );
		}
		$real = trailingslashit( $real ) . basename( $path );

		// Never inside the plugin directory, even if uploads were somehow
		// configured to live under it.
		if ( defined( 'AGENT_BUILDER_DIR' ) ) {
			$plugin_root = realpath( AGENT_BUILDER_DIR );
			if ( false !== $plugin_root && str_starts_with( $real, trailingslashit( $plugin_root ) ) ) {
				return false;
			}
		}

		foreach ( $allowed_roots as $root ) {
			if ( str_starts_with( $real, $root ) ) {
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
	 * Wrapped in a try/catch: RecursiveDirectoryIterator throws (rather than
	 * returning false) when it cannot open a directory — an unreadable or
	 * inaccessible entry inside a tree being swept (e.g. the one-time legacy
	 * agent-export cleanup) would otherwise propagate up and fatal the whole
	 * request. Degrades to "nothing further deleted" instead.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool
	 */
	private static function rmdir_recursive( string $path ): bool {
		try {
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
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
