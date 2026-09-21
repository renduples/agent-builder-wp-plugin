<?php
/**
 * Admin Notice Manager
 *
 * Owns all wp-admin banner notices for the plugin.
 * Extracted from the Plugin class to keep notice logic in one place.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @since      2.9.115
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders all admin banner notices.
 */
class Admin_Notice_Manager {

	/**
	 * Whether the current request is an Agent Builder wp-admin screen.
	 *
	 * Used to keep setup / product notices off core Dashboard, Posts, Plugins,
	 * etc. (WP.org Guideline-style non-intrusive admin UX; UI/UX plan suite 2.1).
	 *
	 * @return bool
	 */
	private function is_plugin_admin_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identification.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'agent-builder' === $page || str_starts_with( $page, 'agentic-' ) ) {
			return true;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && is_string( $screen->id ) ) {
				// toplevel_page_agent-builder, agent-builder_page_agentic-*, etc.
				if ( str_contains( $screen->id, 'agent-builder' ) || str_contains( $screen->id, 'agentic-' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Show a one-time banner to finish plugin setup.
	 *
	 * Shown only on Agent Builder admin screens until onboarding is complete
	 * or the user dismisses it - never on core WP admin pages.
	 *
	 * @return void
	 */
	public function show_setup_needed_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'agent_builder_onboarding_complete' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'agentic_setup_notice_dismissed', true ) ) {
			return;
		}

		// Show on Agent Builder screens and on the Plugins list — where the admin
		// lands right after activation — so the one-time, dismissible "finish setup"
		// reminder is actually seen. Still kept off Dashboard / Posts / etc.
		$agentic_screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_plugins_page = $agentic_screen && is_string( $agentic_screen->id ) && 'plugins' === $agentic_screen->id;
		if ( ! $this->is_plugin_admin_screen() && ! $on_plugins_page ) {
			return;
		}

		// Don't show the banner on the signup / setup wizard pages themselves.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identification.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( in_array( $current_page, array( 'agentic-signup', 'agentic-setup' ), true ) ) {
			return;
		}

		$setup_url = admin_url( 'admin.php?page=agentic-signup' );
		$nonce     = wp_create_nonce( 'agentic_dismiss_setup_notice' );
		?>
		<div class="notice notice-info is-dismissible" id="agentic-setup-notice">
			<p>
				<strong><?php esc_html_e( 'Agent Builder is almost ready!', 'agent-builder' ); ?></strong>
				<?php esc_html_e( 'Finish setup to start using your AI agents.', 'agent-builder' ); ?>
				&nbsp;
				<a href="<?php echo esc_url( $setup_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Finish Setup', 'agent-builder' ); ?>
				</a>
			</p>
		</div>
		<script>
		( function () {
			var notice = document.getElementById( 'agentic-setup-notice' );
			if ( ! notice ) { return; }
			notice.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				wp.ajax.post( 'agentic_dismiss_setup_notice', { nonce: <?php echo wp_json_encode( $nonce ); ?> } );
			} );
		} () );
		</script>
		<?php
	}

	/**
	 * Show admin notice when a user has reached their free Agentic AI quota.
	 *
	 * @return void
	 */
	public function show_quota_reached_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( 'agentic_quota_reached_notice' ) ) {
			return;
		}

		// Dismiss handler.
		if ( isset( $_GET['agentic_dismiss_quota_notice'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['agentic_dismiss_quota_notice'] ) ), 'agentic_dismiss_quota' ) ) {
			delete_transient( 'agentic_quota_reached_notice' );
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'agentic_dismiss_quota_notice', '' ), 'agentic_dismiss_quota', 'agentic_dismiss_quota_notice' );
		?>
		<div class="notice notice-warning is-dismissible" data-dismiss-url="<?php echo esc_url( $dismiss_url ); ?>">
			<p>
				<strong><?php esc_html_e( 'AI Rate Limit Reached', 'agent-builder' ); ?></strong><br>
				<?php esc_html_e( 'A user has hit a rate limit with your configured AI provider. You can switch providers or review your usage in Settings.', 'agent-builder' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agentic-settings' ) ); ?>" class="button"><?php esc_html_e( 'Settings', 'agent-builder' ); ?></a>
			</p>
		</div>
		<script>
		(function(){
			var n = document.querySelector('[data-dismiss-url]');
			if (!n) return;
			n.querySelector('.notice-dismiss').addEventListener('click', function(){
				var xhr = new XMLHttpRequest();
				xhr.open('GET', n.dataset.dismissUrl);
				xhr.send();
			});
		})();
		</script>
		<?php
	}

	/**
	 * Warn when a directory in agentic-agents/ is shadowing a bundled agent.
	 *
	 * The registry loads agentic-agents/ before any plugin library, so a copy
	 * there pins that agent to whatever is on disk: prompt, tool and risk-level
	 * changes from plugin updates never reach it. This is reported however the
	 * copy got there — a .uploaded marker records the route in, not consent.
	 *
	 * We never touch the files. agentic-agents/ belongs to the site owner.
	 */
	public function show_shadowed_agent_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identification.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'agentic-agents' !== $current_page ) {
			return;
		}

		$shadowed = \Agentic_Agent_Registry::get_instance()->get_shadowed_bundled_agents();

		if ( empty( $shadowed ) ) {
			return;
		}

		// Dismissal is scoped to the exact current slug set (sorted, so key
		// order never causes a spurious mismatch) — resolving today's warning
		// must not silently suppress a different agent getting shadowed later.
		$slugs = array_keys( $shadowed );
		sort( $slugs );
		$slug_key  = implode( ',', $slugs );
		$dismissed = (string) get_user_meta( get_current_user_id(), 'agentic_shadowed_agent_notice_dismissed', true );
		if ( $dismissed === $slug_key ) {
			return;
		}

		$nonce = wp_create_nonce( 'agentic_dismiss_shadowed_agent_notice' );
		?>
		<div class="notice notice-warning is-dismissible" id="agentic-shadowed-agent-notice" data-slugs="<?php echo esc_attr( $slug_key ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'Some bundled agents are not receiving updates.', 'agent-builder' ); ?></strong>
			</p>
			<p>
				<?php
				$slug_list = '<code>' . implode( '</code>, <code>', array_map( 'esc_html', array_keys( $shadowed ) ) ) . '</code>';

				echo wp_kses(
					sprintf(
						/* translators: %s: comma-separated list of agent slugs. */
						esc_html__( 'A copy of each of these agents exists in wp-content/agentic-agents/ and is loaded instead of the version bundled with the plugin: %s', 'agent-builder' ),
						$slug_list
					),
					array( 'code' => array() )
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Those copies will keep running their current prompts, tools and risk levels — plugin updates will not reach them. If you did not create them on purpose, rename or remove the directories and the bundled versions will load again. Nothing is changed for you: that folder is yours.', 'agent-builder' ); ?>
			</p>
			<?php if ( ! empty( array_filter( wp_list_pluck( $shadowed, 'uploaded' ) ) ) ) : ?>
				<p>
					<?php esc_html_e( 'At least one of them was installed by an earlier version of the agent updater, which should never have offered it. That is fixed — the updater no longer touches agents that ship with a plugin.', 'agent-builder' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var notice = document.getElementById( 'agentic-shadowed-agent-notice' );
			if ( ! notice ) { return; }
			notice.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				wp.ajax.post( 'agentic_dismiss_shadowed_agent_notice', {
					nonce: notice.dataset.nonce,
					slugs: notice.dataset.slugs,
				} );
			} );
		} () );
		</script>
		<?php
	}
}
