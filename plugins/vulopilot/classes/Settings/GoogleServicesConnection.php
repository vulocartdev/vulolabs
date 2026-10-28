<?php
namespace VuloPilot\Settings;

use VuloPilot\AiAssistant\CredentialEncryption;

defined( 'ABSPATH' ) || exit;

/**
 * Google OAuth 2.0 connection shared by Search Console, Analytics (GA4), and AdSense.
 *
 * @class       GoogleServicesConnection class
 * @version     1.0.0
 * @author      VuloLabs
 */
class GoogleServicesConnection {

	private const OPTION_KEY = 'vulopilot_google_connection';

	private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

	/**
	 * Google access tokens are typically valid ~3600s - refreshed a minute
	 * early so a request never races an in-flight expiry.
	 */
	private const EXPIRY_SAFETY_MARGIN = 60;

	/**
	 * SPA destinations the OAuth redirect is allowed to land back on.
	 */
	private const RETURN_TARGETS = array( 'settings', 'keywords' );

	/**
	 * @return array<string, mixed>
	 */
	private function get_connection(): array {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'access_token_enc'     => '',
				'refresh_token_enc'    => '',
				'token_expires_at'     => 0,
				'search_console_site'  => '',
				'ga4_account_id'       => '',
				'ga4_account_name'     => '',
				'ga4_property_id'      => '',
				'ga4_property_name'    => '',
				'ga4_measurement_id'   => '',
				'adsense_account_id'   => '',
				'adsense_account_name' => '',
				'connected_at'         => '',
			)
		);
	}

	/**
	 * @param array<string, mixed> $data Partial fields to merge into the stored connection.
	 * @return void
	 */
	private function save_connection( array $data ): void {
		update_option( self::OPTION_KEY, array_merge( $this->get_connection(), $data ), false );
	}

	/**
	 * The redirect_uri registered with Google must be EXACTLY this URL (down to trailing
	 * slashes/scheme).
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=vulopilot_gsc_oauth_callback' );
	}

	/**
	 * Whether the broker-based Google connect flow is configured. This is the only way the
	 * plugin ever obtains Google OAuth tokens - the Client ID/Secret live solely in VuloCloud.
	 *
	 * @return bool
	 */
	public function has_broker(): bool {
		return defined( 'VULOPILOT_VULOCLOUD_URL' ) && '' !== VULOPILOT_VULOCLOUD_URL
			&& defined( 'VULOPILOT_APPLICATION_ID' ) && '' !== VULOPILOT_APPLICATION_ID;
	}

	/**
	 * Builds the Google OAuth URL (offline access, forced consent) with a `state` carrying
	 * a nonce and the tab to return to.
	 *
	 * @param string $return_to One of self::RETURN_TARGETS; anything else silently falls back to 'settings'.
	 * @return string|null Null if the broker isn't configured for this build yet.
	 */
	public function get_authorization_url( string $return_to = 'settings' ): ?string {
		if ( ! in_array( $return_to, self::RETURN_TARGETS, true ) ) {
			$return_to = 'settings';
		}

		if ( ! $this->has_broker() ) {
			return null;
		}

		$state = self::encode_state( $return_to );

		return ( new GoogleOAuthBrokerClient( VULOPILOT_VULOCLOUD_URL ) )
			->get_authorize_url( VULOPILOT_APPLICATION_ID, home_url(), $this->get_redirect_uri(), $state );
	}

	/**
	 * @param string $return_to Already validated against self::RETURN_TARGETS by the caller.
	 * @return string Base64'd JSON - a real WP nonce plus the plain, allow-listed return target.
	 */
	private static function encode_state( string $return_to ): string {
		return base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding for an opaque `state` value, not obfuscation; every field inside is either a real WP nonce (verified below) or an allow-listed plain string.
			(string) wp_json_encode(
				array(
					'nonce'     => wp_create_nonce( 'vulopilot_gsc_oauth' ),
					'return_to' => $return_to,
				)
			)
		);
	}

	/**
	 * @param string $state The `state` query param Google's redirect carried back.
	 * @return array{nonce: string, return_to: string}
	 */
	private static function decode_state( string $state ): array {
		$decoded = json_decode( (string) base64_decode( $state, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding this class's own encode_state(), not obfuscation.

		$nonce     = is_array( $decoded ) && is_string( $decoded['nonce'] ?? null ) ? $decoded['nonce'] : '';
		$return_to = is_array( $decoded ) && is_string( $decoded['return_to'] ?? null ) ? $decoded['return_to'] : '';

		return array(
			'nonce'     => $nonce,
			'return_to' => in_array( $return_to, self::RETURN_TARGETS, true ) ? $return_to : 'settings',
		);
	}

	/**
	 * @param string $state The `state` query param Google's redirect carried back.
	 * @return string The nonce inside it, or '' when it has none.
	 */
	public function get_state_nonce( string $state ): string {
		return self::decode_state( $state )['nonce'];
	}

	/**
	 * @param string $state The `state` query param Google's redirect carried back.
	 * @return bool
	 */
	public function verify_state( string $state ): bool {
		return false !== wp_verify_nonce( self::decode_state( $state )['nonce'], 'vulopilot_gsc_oauth' );
	}

	/**
	 * Read independently of `verify_state()` - deliberately NOT gated on nonce validity.
	 *
	 * @param string $state The `state` query param Google's redirect carried back.
	 * @return string One of self::RETURN_TARGETS.
	 */
	public function get_return_to_from_state( string $state ): string {
		return self::decode_state( $state )['return_to'];
	}

	/**
	 * Exchanges the broker's single-use code for tokens and stores them.
	 *
	 * @param string $code The `code` query param the redirect carried back.
	 * @return true|\WP_Error
	 */
	public function exchange_broker_code_for_tokens( string $code ) {
		$result = ( new GoogleOAuthBrokerClient( VULOPILOT_VULOCLOUD_URL ) )->exchange( home_url(), $code );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = array(
			'access_token_enc' => CredentialEncryption::encrypt( $result['access_token'] ),
			'token_expires_at' => time() + $result['expires_in'],
			'connected_at'     => current_time( 'mysql' ),
			'via'              => 'broker',
		);

		if ( '' !== $result['refresh_token'] ) {
			$update['refresh_token_enc'] = CredentialEncryption::encrypt( $result['refresh_token'] );
		}

		$this->save_connection( $update );

		return true;
	}

	/**
	 * Runs the refresh_token grant when the stored access token is expired or about to be.
	 *
	 * @return bool
	 */
	private function refresh_access_token(): bool {
		$connection    = $this->get_connection();
		$refresh_token = '' !== $connection['refresh_token_enc']
			? CredentialEncryption::decrypt( $connection['refresh_token_enc'] )
			: null;

		if ( ! $refresh_token ) {
			return false;
		}

		if ( ! $this->has_broker() ) {
			return false;
		}

		$result = ( new GoogleOAuthBrokerClient( VULOPILOT_VULOCLOUD_URL ) )->refresh( VULOPILOT_APPLICATION_ID, home_url(), $refresh_token );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		$this->save_connection(
			array(
				'access_token_enc' => CredentialEncryption::encrypt( $result['access_token'] ),
				'token_expires_at' => time() + $result['expires_in'],
			)
		);

		return true;
	}

	/**
	 * @return string|null A real, currently-valid access token, refreshing first if needed. Null if not connected or refresh failed.
	 */
	public function get_valid_access_token(): ?string {
		$connection = $this->get_connection();

		if ( '' === $connection['access_token_enc'] ) {
			return null;
		}

		if ( (int) $connection['token_expires_at'] <= ( time() + self::EXPIRY_SAFETY_MARGIN ) ) {
			if ( ! $this->refresh_access_token() ) {
				return null;
			}

			$connection = $this->get_connection();
		}

		return CredentialEncryption::decrypt( $connection['access_token_enc'] );
	}

	/**
	 * Whether a refresh token is on file, the durable signal the OAuth handshake completed.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return '' !== $this->get_connection()['refresh_token_enc'];
	}

	/**
	 * GET https://www.googleapis.com/webmasters/v3/sites call.
	 *
	 * @return array<int, array{site_url: string, permission_level: string}>|\WP_Error
	 */
	public function list_search_console_sites() {
		$token = $this->get_valid_access_token();

		if ( ! $token ) {
			return new \WP_Error( 'vulopilot_gsc_not_connected', __( 'Not connected to Google.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			self::SITES_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'vulopilot_gsc_sites_failed', __( 'Could not fetch your Search Console properties.', 'vulopilot' ), array( 'status' => 502 ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array_map(
			static fn( $site ) => array(
				'site_url'         => $site['siteUrl'] ?? '',
				'permission_level' => $site['permissionLevel'] ?? '',
			),
			$body['siteEntry'] ?? array()
		);
	}

	/**
	 * @param string $site_url One of `list_search_console_sites()`'s own real `site_url` values.
	 * @return void
	 */
	public function select_search_console_site( string $site_url ): void {
		$this->save_connection( array( 'search_console_site' => $site_url ) );
	}

	/**
	 * @param array{account_id: string, account_name: string, property_id: string, property_name: string, measurement_id: string} $property One of GoogleAnalyticsClient::list_account_summaries()'s own real data-stream rows.
	 * @return void
	 */
	public function select_ga4_property( array $property ): void {
		$this->save_connection(
			array(
				'ga4_account_id'     => $property['account_id'],
				'ga4_account_name'   => $property['account_name'],
				'ga4_property_id'    => $property['property_id'],
				'ga4_property_name'  => $property['property_name'],
				'ga4_measurement_id' => $property['measurement_id'],
			)
		);
	}

	/**
	 * @param string $account_id   One of GoogleAdSenseClient::list_accounts()'s own real `account_id` values.
	 * @param string $account_name Same row's display name.
	 * @return void
	 */
	public function select_adsense_account( string $account_id, string $account_name ): void {
		$this->save_connection(
			array(
				'adsense_account_id'   => $account_id,
				'adsense_account_name' => $account_name,
			)
		);
	}

	/**
	 * Clears tokens/selected properties but keeps the saved Client ID/Secret.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->save_connection(
			array(
				'access_token_enc'     => '',
				'refresh_token_enc'    => '',
				'token_expires_at'     => 0,
				'search_console_site'  => '',
				'ga4_account_id'       => '',
				'ga4_account_name'     => '',
				'ga4_property_id'      => '',
				'ga4_property_name'    => '',
				'ga4_measurement_id'   => '',
				'adsense_account_id'   => '',
				'adsense_account_name' => '',
				'connected_at'         => '',
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$connection = $this->get_connection();

		return array(
			'connected'            => $this->is_connected(),
			'has_broker'           => $this->has_broker(),
			'search_console_site'  => $connection['search_console_site'],
			'ga4_account_id'       => $connection['ga4_account_id'],
			'ga4_account_name'     => $connection['ga4_account_name'],
			'ga4_property_id'      => $connection['ga4_property_id'],
			'ga4_property_name'    => $connection['ga4_property_name'],
			'ga4_measurement_id'   => $connection['ga4_measurement_id'],
			'adsense_account_id'   => $connection['adsense_account_id'],
			'adsense_account_name' => $connection['adsense_account_name'],
			'connected_at'         => $connection['connected_at'],
			// URL to register as the "Authorized redirect URI" on the Google Cloud OAuth Client.
			'redirect_uri'         => $this->get_redirect_uri(),
		);
	}
}
