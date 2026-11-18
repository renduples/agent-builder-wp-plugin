<?php
/**
 * Legacy relay Application Passwords.
 *
 * Older versions connected a site to the since-removed Agentic Relay (and the
 * old Settings > MCP "Create" button) by creating a WordPress Application
 * Password named "Agent Builder Relay". Nothing in this version uses them, so
 * they are revoked once on upgrade and again on uninstall. Passwords a user
 * created under any other name are never touched.
 *
 * This file must stay loadable on its own: uninstall.php requires it directly
 * while the plugin itself is not loaded.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds and revokes leftover relay Application Passwords.
 */
class Legacy_Relay_Passwords {

	/**
	 * Option set once the one-time upgrade revocation has run.
	 */
	public const DONE_OPTION = 'agent_builder_relay_passwords_revoked';

	/**
	 * Option holding the revoked count until an administrator dismisses the notice.
	 */
	public const NOTICE_OPTION = 'agent_builder_relay_passwords_notice';

	/**
	 * Name prefix older versions used. Matches the original name and renamed
	 * copies such as "Agent Builder Relay (rotated 2026-10-05)".
	 */
	public const NAME_PREFIX = 'Agent Builder Relay';

	/**
	 * Other exact names older versions (and their test tooling) created.
	 */
	public const EXTRA_NAMES = array( 'TestMCPrelay' );

	/**
	 * Whether an Application Password name belongs to the old relay.
	 *
	 * @param string $name Application Password name.
	 * @return bool
	 */
	public static function is_relay_name( string $name ): bool {
		return str_starts_with( $name, self::NAME_PREFIX ) || in_array( $name, self::EXTRA_NAMES, true );
	}

	/**
	 * Revoke every relay Application Password, for every user.
	 *
	 * @return int Number of passwords revoked.
	 */
	public static function revoke_all(): int {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return 0;
		}

		$user_ids = get_users(
			array(
				'blog_id'  => 0,
				'meta_key' => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off cleanup, only users that have Application Passwords.
				'fields'   => 'ID',
			)
		);

		$revoked = 0;
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $password ) {
				if ( ! self::is_relay_name( (string) ( $password['name'] ?? '' ) ) ) {
					continue;
				}
				if ( true === \WP_Application_Passwords::delete_application_password( $user_id, (string) $password['uuid'] ) ) {
					++$revoked;
				}
			}
		}

		return $revoked;
	}

	/**
	 * Revoke relay passwords once per site (option-gated, idempotent).
	 *
	 * Logs the count to the audit log and queues a one-time admin notice when
	 * anything was removed.
	 *
	 * @return int Number revoked by this call (0 when it already ran).
	 */
	public static function maybe_revoke_on_upgrade(): int {
		if ( false !== get_option( self::DONE_OPTION, false ) ) {
			return 0;
		}
		// Mark first so a fatal mid-run cannot loop on every request.
		update_option( self::DONE_OPTION, time(), false );

		$revoked = self::revoke_all();
		if ( $revoked > 0 ) {
			update_option( self::NOTICE_OPTION, $revoked, false );
			if ( class_exists( Audit_Log::class ) ) {
				( new Audit_Log() )->log(
					'system',
					'relay_passwords_revoked',
					'application_password',
					array( 'count' => $revoked )
				);
			}
		}

		return $revoked;
	}

	/**
	 * One-time, dismissible notice on Siteloom screens after a revocation.
	 *
	 * @return void
	 */
	public static function show_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Admin_Notice_Manager::is_plugin_admin_screen() ) {
			return;
		}

		$count = (int) get_option( self::NOTICE_OPTION, 0 );
		if ( $count < 1 ) {
			return;
		}

		if ( isset( $_GET['agentic_dismiss_relay_passwords_notice'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['agentic_dismiss_relay_passwords_notice'] ) ), 'agentic_dismiss_relay_passwords_notice' ) ) {
			delete_option( self::NOTICE_OPTION );
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'agentic_dismiss_relay_passwords_notice', '' ), 'agentic_dismiss_relay_passwords_notice', 'agentic_dismiss_relay_passwords_notice' );
		?>
		<div class="notice notice-info is-dismissible" data-dismiss-url="<?php echo esc_url( $dismiss_url ); ?>">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of Application Passwords removed. */
						_n(
							'Siteloom removed %d old relay connection password. If you connect AI apps over MCP, create a new Application Password in your profile.',
							'Siteloom removed %d old relay connection passwords. If you connect AI apps over MCP, create a new Application Password in your profile.',
							$count,
							'siteloom'
						),
						$count
					)
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Open your profile', 'siteloom' ); ?></a>
			</p>
		</div>
		<?php
		Inline_Assets::script( 'notices', array(), '', array( 'wp-util' ) );
	}
}
