<?php
/**
 * Login Monitor
 *
 * Records failed WordPress login attempts to the security log so the
 * Site Health Sentinel and get_failed_logins tool have real data to report on.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     AgentStructor Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentstructor.com
 * @since      3.3.1
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks WordPress's login-failure event into the security log.
 */
class Login_Monitor {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_login_failed', array( self::class, 'handle_failed_login' ) );
	}

	/**
	 * Record a failed login attempt.
	 *
	 * @param string $username Username or email address that was attempted.
	 * @return void
	 */
	public static function handle_failed_login( string $username ): void {
		if ( ! class_exists( '\Agentic\Security_Log' ) ) {
			return;
		}

		\Agentic\Security_Log::log(
			'failed_login',
			0,
			Chat_Security::get_client_ip(),
			sanitize_text_field( wp_unslash( $username ) )
		);
	}
}
