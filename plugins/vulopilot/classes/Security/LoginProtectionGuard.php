<?php
namespace VuloPilot\Security;

use VuloPilot\Security\LoginAttemptRepository;
use VuloPilot\Utill;
use VuloPilot\Utill\ServerRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Real, always-on brute-force login protection - Protect My Site's "Login Protection"
 * tile.
 *
 * @class       LoginProtectionGuard class
 * @version     1.0.0
 * @author      VuloLabs
 */
class LoginProtectionGuard {

	/**
	 * LoginProtectionGuard constructor.
	 */
	public function __construct() {
		add_filter( 'authenticate', array( $this, 'block_if_locked_out' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'record_failure' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'record_success' ), 10, 1 );
	}

	/**
	 * Real settings, parsed with defaults - same `wp_parse_args()` shape
	 * every scanner in this codebase already reads settings with.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		return wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
	}

	/**
	 * The `authenticate` filter callback - see class docblock.
	 *
	 * @param \WP_User|\WP_Error|null $user     Current authentication result.
	 * @param string                  $username Real username/email being attempted.
	 * @param string                  $password Real password being attempted (never stored).
	 * @return \WP_User|\WP_Error|null
	 */
	public function block_if_locked_out( $user, string $username, string $password ) {
		// No credentials submitted yet (e.g. the login form's first load) -
		// nothing to check.
		if ( '' === $username && '' === $password ) {
			return $user;
		}

		$settings = $this->get_settings();

		if ( empty( $settings['enable_login_protection'] ) ) {
			return $user;
		}

		$max_attempts    = max( 1, absint( $settings['login_max_attempts'] ) ? absint( $settings['login_max_attempts'] ) : 5 );
		$lockout_minutes = max( 1, absint( $settings['login_lockout_minutes'] ) ? absint( $settings['login_lockout_minutes'] ) : 15 );

		$repository = new LoginAttemptRepository();
		$ip_address = ServerRequest::client_ip();

		if ( $repository->count_recent_failures( $ip_address, $lockout_minutes ) < $max_attempts ) {
			return $user;
		}

		return new \WP_Error(
			'vulopilot_locked_out',
			sprintf(
				/* translators: %d is how many minutes until this IP can try again. */
				__( '<strong>Error:</strong> Too many failed login attempts. Please try again in %d minutes.', 'vulopilot' ),
				$lockout_minutes
			)
		);
	}

	/**
	 * `wp_login_failed` callback - records one real failed attempt.
	 *
	 * @param string $username Real username/email that was attempted.
	 * @return void
	 */
	public function record_failure( string $username ): void {
		$settings = $this->get_settings();

		if ( empty( $settings['enable_login_protection'] ) ) {
			return;
		}

		( new LoginAttemptRepository() )->insert(
			array(
				'ip_address'         => ServerRequest::client_ip(),
				'username_attempted' => sanitize_user( $username ),
				'success'            => 0,
				'created_at'         => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * `wp_login` callback - records one real successful attempt.
	 *
	 * @param string $user_login Real username that logged in.
	 * @return void
	 */
	public function record_success( string $user_login ): void {
		$settings = $this->get_settings();

		if ( empty( $settings['enable_login_protection'] ) ) {
			return;
		}

		( new LoginAttemptRepository() )->insert(
			array(
				'ip_address'         => ServerRequest::client_ip(),
				'username_attempted' => sanitize_user( $user_login ),
				'success'            => 1,
				'created_at'         => current_time( 'mysql' ),
			)
		);
	}
}
