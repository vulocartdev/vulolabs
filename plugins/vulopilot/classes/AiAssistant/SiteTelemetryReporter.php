<?php
namespace VuloPilot\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Reports this site's WordPress, PHP, theme and plugin details.
 *
 * @class       SiteTelemetryReporter class
 * @version     1.0.0
 * @author      VuloLabs
 */
class SiteTelemetryReporter {

	private const CRON_HOOK = 'vulopilot_site_telemetry_daily';

	/**
	 * Registers the daily cron report alongside the immediate,
	 * connect-time report - Services\SecurityScoreSnapshotRecorder's own
	 * "wp_next_scheduled()-guarded wp_schedule_event() on init" pattern.
	 * The immediate report itself isn't triggered from here - it's called
	 * directly by AiCreditsConnection::exchange_broker_code() right after
	 * its own successful connect, since only it knows the connection just
	 * became real.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'ensure_daily_report_scheduled' ) );
		add_action( self::CRON_HOOK, array( $this, 'report_all_connections' ) );
	}

	/**
	 * @return void
	 */
	public function ensure_daily_report_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * The daily cron callback - reports every currently-connected
	 * connection this plugin holds. Best-effort: a failed report just
	 * means the detail page keeps showing stale/blank telemetry until the
	 * next successful attempt, never surfaced to the site owner as an
	 * error.
	 *
	 * @return void
	 */
	public function report_all_connections(): void {
		$ai_credits = ( new AiCreditsConnection() )->get_site_credential();
		if ( null !== $ai_credits ) {
			$this->report( $ai_credits['site_id'], $ai_credits['secret'] );
		}
	}

	/**
	 * Sends the daily site telemetry ping.
	 *
	 * @param string $site_id This connection's own ConnectedSite id.
	 * @param string $secret  This connection's own decrypted secret.
	 * @return bool True if the ping was accepted (2xx).
	 */
	public function report( string $site_id, string $secret ): bool {
		if ( '' === trim( VULOPILOT_VULOCLOUD_URL ) ) {
			return false;
		}

		$response = wp_remote_post(
			untrailingslashit( VULOPILOT_VULOCLOUD_URL ) . '/connected-sites/ingest',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'siteId'  => $site_id,
						'secret'  => $secret,
						'payload' => $this->build_payload(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return (int) wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * The tracker payload itself.
	 *
	 * @return array<string, mixed>
	 */
	private function build_payload(): array {
		global $wpdb;
		$theme = wp_get_theme();

		return array(
			'Site Name'      => get_bloginfo( 'name' ),
			'Site Version'   => get_bloginfo( 'version' ),
			'Site Language'  => get_bloginfo( 'language' ),
			'Charset'        => get_bloginfo( 'charset' ),
			'Php Version'    => phpversion(),
			'Multisite'      => is_multisite(),
			'File Location'  => ABSPATH,
			'Email'          => get_bloginfo( 'admin_email' ),
			'Server'         => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-reported environment info, not user input; sanitized regardless.
			'Text Direction' => is_rtl() ? 'rtl' : 'ltr',
			'Plugin'         => VULOPILOT_PLUGIN_NAME,
			'Version'        => VULOPILOT_PLUGIN_VERSION,
			'Status'         => 'active',
			'Theme'          => $theme->get( 'Name' ),
			'Theme Version'  => $theme->get( 'Version' ),
			'Platform'       => 'WordPress',
			// $wpdb->db_version() is the server's raw MySQL/MariaDB protocol
			// version (e.g. "5.7.44-log") - real and always available,
			// unlike Commerce/LMS Platform below.
			'Database'       => $wpdb->db_version(),
			// Core since WP 5.5 ('production' unless the host/wp-config.php
			// explicitly sets WP_ENVIRONMENT_TYPE otherwise) - real, not a
			// guess, so worth sending even though most sites report the
			// same default value.
			'Environment'    => wp_get_environment_type(),
			'Hosting Type'   => $this->detect_hosting_type(),
			// Commerce/LMS Platform, Framework Version and Site Type are
			// deliberately omitted - this plugin has no generic, honest way
			// to determine "does this site run a commerce/LMS platform" or
			// "what's its cart-framework version" (that was MultiVendorX's
			// own tracker reporting on itself, not something a generic
			// tracker for a security/management plugin can infer). Sending
			// a guess here would be worse than leaving the console's own
			// "-" placeholder. Country is likewise left for the server
			// side to resolve from the request's own IP at ingest time,
			// not something this site can determine about itself.
		);
	}

	/**
	 * Best-effort recognition of a handful of hosts that identify
	 * themselves via a well-known constant/function in wp-config.php or
	 * an mu-plugin - never a network call, and '' (shown as "-") rather
	 * than a guess when none match, same honesty posture the rest of this
	 * payload follows.
	 *
	 * @return string
	 */
	private function detect_hosting_type(): string {
		if ( defined( 'WPE_APIKEY' ) ) {
			return 'WP Engine';
		}
		if ( defined( 'KINSTAMYSQLTUNNEL' ) || function_exists( 'kinsta_cache_purge' ) ) {
			return 'Kinsta';
		}
		if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
			return 'Pantheon';
		}
		if ( defined( 'IS_PRESSABLE' ) && IS_PRESSABLE ) {
			return 'Pressable';
		}
		if ( defined( 'FLYWHEEL_CONFIG_DIR' ) ) {
			return 'Flywheel';
		}
		if ( defined( 'GD_SYSTEM_PLUGIN_DIR' ) ) {
			return 'GoDaddy';
		}
		if ( defined( 'WPCOMSH__FILE__' ) ) {
			return 'WordPress.com';
		}
		return '';
	}
}
