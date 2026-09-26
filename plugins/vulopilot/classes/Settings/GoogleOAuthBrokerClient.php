<?php
namespace VuloPilot\Settings;


defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the Google connect broker.
 *
 * @class       GoogleOAuthBrokerClient class
 * @version     1.0.0
 * @author      VuloLabs
 */
class GoogleOAuthBrokerClient {

	/** @var string e.g. https://cloud.vulolabs.com (no trailing slash) */
	private $broker_url;

	public function __construct( string $broker_url ) {
		$this->broker_url = untrailingslashit( $broker_url );
	}

	/**
	 * Builds the URL that starts the Google connect flow.
	 *
	 * @return string
	 */
	public function get_authorize_url( string $application_id, string $domain, string $return_uri, string $state ): string {
		return $this->broker_url . '/plugin/google/authorize?' . http_build_query(
			array(
				'applicationId' => $application_id,
				'domain'        => $domain,
				'returnUri'     => $return_uri,
				'state'         => $state,
			)
		);
	}

	/**
	 * Exchanges a single-use code for Google tokens.
	 *
	 * @return array{access_token: string, refresh_token: string, expires_in: int}|\WP_Error
	 */
	public function exchange( string $domain, string $code ) {
		return $this->post(
			'/plugin/google/exchange',
			array(
				'domain' => $domain,
				'code'   => $code,
			),
			'vulopilot_google_broker_exchange_failed',
			__( 'Google connect broker could not complete the token exchange.', 'vulopilot' )
		);
	}

	/**
	 * Real `POST {broker}/plugin/google/refresh` - used instead of a
	 * direct `grant_type=refresh_token` call to Google whenever the
	 * stored connection's tokens were originally issued via this broker
	 * (GoogleServicesConnection::refresh_access_token()'s own `via`
	 * check): a refresh token is only valid against the OAuth Client that
	 * issued it, and a broker-issued one belongs to the Organization's own
	 * Google Client that `$application_id` resolves to, not this build's
	 * embedded VULOPILOT_GOOGLE_CLIENT_ID/SECRET. `$application_id` is
	 * required here for the same reason it's required by
	 * get_authorize_url() - a bare refresh token doesn't say which
	 * Organization's Client it belongs to.
	 *
	 * @return array{access_token: string, expires_in: int}|\WP_Error
	 */
	public function refresh( string $application_id, string $domain, string $refresh_token ) {
		return $this->post(
			'/plugin/google/refresh',
			array(
				'applicationId' => $application_id,
				'domain'        => $domain,
				'refreshToken'  => $refresh_token,
			),
			'vulopilot_google_broker_refresh_failed',
			__( 'Google connect broker could not refresh the access token.', 'vulopilot' )
		);
	}

	/**
	 * Sends a JSON POST to the broker and decodes the response.
	 *
	 * @param string $path             e.g. '/plugin/google/exchange'.
	 * @param array  $body              JSON-encoded request body.
	 * @param string $error_code        WP_Error code on a completed-but-unsuccessful response.
	 * @param string $default_message   WP_Error message when the broker's own response carries none.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function post( string $path, array $body, string $error_code, string $default_message ) {
		$response = wp_remote_post(
			$this->broker_url . $path,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// DNS failure, connection refused, timeout, ... - the request
			// never got a response at all.
			return $response;
		}

		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $response_body ) ) {
			return new \WP_Error(
				'vulopilot_google_broker_unparseable_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google connect broker returned a non-JSON response (HTTP %d).', 'vulopilot' ),
					$status
				)
			);
		}

		if ( $status < 200 || $status >= 300 || empty( $response_body['access_token'] ) ) {
			return new \WP_Error(
				$error_code,
				$response_body['message'] ?? $response_body['error'] ?? $default_message
			);
		}

		return array(
			'access_token'  => $response_body['access_token'],
			'refresh_token' => $response_body['refresh_token'] ?? '',
			'expires_in'    => (int) ( $response_body['expires_in'] ?? 3600 ),
		);
	}
}
