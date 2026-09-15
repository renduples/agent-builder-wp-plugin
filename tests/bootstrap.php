<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package Agent_Builder
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:ignore PluginCheck.CodeAnalysis.DirectDatabaseQuery.DirectQuery -- Test bootstrap; ABSPATH not yet defined when this file loads.

// Define test mode.
if ( ! defined( 'AGENTIC_TEST_MODE' ) ) {
	define( 'AGENTIC_TEST_MODE', true );
}

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Check if WordPress test suite is available.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found.\n";
	echo "Run: bin/install-wp-tests.sh agentic_wptest root '' localhost latest\n";
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Create plugin database tables for tests.
 *
 * Must be defined before muplugins_loaded fires so it can be called from
 * _manually_load_plugin() before the plugin's own init-time code (e.g.
 * Provider_Registry::maybe_seed()) runs against tables that don't exist yet.
 *
 * The audit_log / approval_queue / tools CREATE TABLE statements below are
 * copied verbatim from Activator::create_tables() (includes/class-activator.php)
 * — kept in sync by hand, not by requiring the activator file directly, since
 * activation also fires a battery of migrations that assume a fully-booted
 * plugin. Job_Manager and Security_Log create their own tables via their
 * public create_table() methods, so those are called directly instead of
 * duplicated here.
 */
function _agentic_create_test_tables() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	if ( class_exists( 'Agentic\\Job_Manager' ) ) {
		\Agentic\Job_Manager::create_table();
	}
	if ( class_exists( 'Agentic\\Security_Log' ) ) {
		\Agentic\Security_Log::create_table();
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_audit_log (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		agent_id varchar(64) NOT NULL,
		action varchar(128) NOT NULL,
		target_type varchar(64),
		target_id varchar(128),
		details longtext,
		reasoning text,
		mode varchar(32) DEFAULT '',
		provider varchar(64) DEFAULT '',
		tokens_used int unsigned DEFAULT 0,
		cost decimal(10,6) DEFAULT 0,
		user_id bigint(20) unsigned,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		agent_author varchar(191) DEFAULT '',
		agent_version varchar(32) DEFAULT '',
		integrity_hash char(64) DEFAULT NULL,
		PRIMARY KEY (id),
		KEY agent_id (agent_id),
		KEY action (action),
		KEY created_at (created_at),
		KEY user_created (user_id, created_at),
		KEY idx_agent_created (agent_id, created_at)
	) {$charset_collate};";
	dbDelta( $sql );

	$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_approval_queue (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		agent_id varchar(64) NOT NULL,
		action varchar(128) NOT NULL,
		params longtext NOT NULL,
		reasoning text,
		risk_level varchar(32) DEFAULT 'none',
		status varchar(32) DEFAULT 'pending',
		approved_by bigint(20) unsigned,
		approved_at datetime,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		expires_at datetime,
		executed_at datetime DEFAULT NULL,
		mode varchar(32) DEFAULT '',
		invocation varchar(32) DEFAULT '',
		PRIMARY KEY (id),
		KEY status (status),
		KEY created_at (created_at),
		KEY idx_status_created (status, created_at),
		KEY idx_expires (expires_at)
	) {$charset_collate};";
	dbDelta( $sql );

	$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}agentic_tools (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(128) NOT NULL,
		description text NOT NULL,
		category varchar(64) NOT NULL DEFAULT 'WordPress',
		source varchar(64) NOT NULL DEFAULT 'core',
		enabled tinyint(1) NOT NULL DEFAULT 1,
		risk_level varchar(32) NOT NULL DEFAULT 'none',
		parameters longtext,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY name (name),
		KEY category (category),
		KEY source (source),
		KEY enabled (enabled)
	) {$charset_collate};";
	dbDelta( $sql );
}

/**
 * Manually load the plugin being tested, and create tables first so that
 * the plugin's init hook (Provider_Registry::maybe_seed, etc.) doesn't fail.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/agent-builder.php';
	_agentic_create_test_tables();
}

// Load the plugin.
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Ensure deferred chat components are loaded for tests.
\Agentic\Plugin::get_instance()->load_chat_components();

// Load test helpers after WordPress is loaded.
require_once __DIR__ . '/helpers/TestCase.php';
require_once __DIR__ . '/helpers/MockWPFunctions.php';
require_once __DIR__ . '/helpers/TestDataFactory.php';
