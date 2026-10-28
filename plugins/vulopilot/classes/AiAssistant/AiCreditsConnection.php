<?php
namespace VuloPilot\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and manages this site's AI credits connection.
 *
 * @class       AiCreditsConnection class
 * @version     1.0.0
 * @author      VuloLabs
 */
class AiCreditsConnection {

	private const OPTION_KEY = 'vulopilot_ai_credits_connection';

	/**
	 * Seconds to wait for an AI reply. A long reply can take several generation steps on the
	 * server, so this is well above a single step's time.
	 */
	private const AI_REQUEST_TIMEOUT = 300;


	/**
	 * The stored connection, merged with defaults for any field never yet saved.
	 *
	 * @return array<string, mixed>
	 */
	private function get_connection(): array {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'site_id'         => '',
				'secret_enc'      => '',
				'credits'         => 0,
				'lifetime_earned' => 0,
				'lifetime_used'   => 0,
				'buy_credits_url' => '',
				'connected_at'    => '',
				'last_synced_at'  => '',
			)
		);
	}

	/**
	 * Merges `$data` into the stored connection.
	 *
	 * @param array<string, mixed> $data Partial fields to merge into the stored connection.
	 * @return void
	 */
	private function save_connection( array $data ): void {
		update_option( self::OPTION_KEY, array_merge( $this->get_connection(), $data ), false );
	}

	/**
	 * Whether this site has a real ConnectedSite credential.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return '' !== $this->get_connection()['site_id'] && '' !== $this->get_connection()['secret_enc'];
	}

	/**
	 * Connection status; never includes the secret.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$connection = $this->get_connection();

		return array(
			'connected'                   => $this->is_connected(),
			'credits'                     => round( (float) $connection['credits'], 3 ),
			'lifetime_earned'             => round( (float) $connection['lifetime_earned'], 3 ),
			'lifetime_used'               => round( (float) $connection['lifetime_used'], 3 ),
			'buy_credits_url'             => (string) $connection['buy_credits_url'],
			'connected_at'                => $connection['connected_at'],
			'last_synced_at'              => $connection['last_synced_at'],
			'vulocloud_account_connected' => ( new VuloCloudAccountConnection() )->is_connected(),
			'vulocloud_account_email'     => ( new VuloCloudAccountConnection() )->get_status()['email'],
		);
	}

	/**
	 * The decrypted site credential, ready to authenticate an AI Gateway call.
	 *
	 * @return array{site_id: string, secret: string}|null Null if not connected.
	 */
	public function get_site_credential(): ?array {
		$connection = $this->get_connection();

		if ( '' === $connection['site_id'] || '' === $connection['secret_enc'] ) {
			return null;
		}

		$secret = CredentialEncryption::decrypt( $connection['secret_enc'] );

		return null !== $secret ? array(
			'site_id' => $connection['site_id'],
			'secret'  => $secret,
		) : null;
	}

	/**
	 * Re-syncs the cached credit balance.
	 *
	 * @return array<string, mixed>|\WP_Error Same shape as get_status().
	 */
	public function refresh_balance() {
		$credential = $this->get_site_credential();

		if ( ! $credential ) {
			return new \WP_Error( 'vulopilot_ai_credits_not_connected', __( 'This site is not connected to VuloCloud AI Credits.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$result = $this->post_credits( VULOPILOT_VULOCLOUD_URL, '/plugin/ai/status', $credential['site_id'], $credential['secret'] );

		if ( is_wp_error( $result ) ) {
			// Never destroy the cached balance on a failed sync, just report the error.
			return new \WP_Error(
				'vulopilot_ai_credits_unreachable',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Could not reach VuloCloud: %s', 'vulopilot' ),
					$result->get_error_message()
				),
				array( 'status' => 503 )
			);
		}

		if ( $result['http_status'] < 200 || $result['http_status'] >= 300 ) {
			return new \WP_Error(
				'vulopilot_ai_credits_sync_failed',
				__( 'Could not refresh your AI credit balance.', 'vulopilot' ),
				array( 'status' => $result['http_status'] )
			);
		}

		$this->cache_status( $result['body'] );

		return $this->get_status();
	}

	/**
	 * Caches the balance fields of a `/plugin/ai/status` response.
	 *
	 * @param array<string, mixed> $body Decoded response.
	 * @return void
	 */
	private function cache_status( array $body ): void {
		$this->save_connection(
			array(
				'credits'         => (float) ( $body['credits'] ?? 0 ),
				'lifetime_earned' => (float) ( $body['lifetimeEarned'] ?? 0 ),
				'lifetime_used'   => (float) ( $body['lifetimeUsed'] ?? 0 ),
				'buy_credits_url' => esc_url_raw( (string) ( $body['buyCreditsUrl'] ?? '' ) ),
				'last_synced_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * The redirect URI the connect flow returns to.
	 *
	 * @return string
	 */
	public function get_broker_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=vulopilot_connect_broker_callback' );
	}

	/**
	 * The URL that starts the passwordless connect flow.
	 *
	 * @return string|null Null if this build isn't configured to reach the server at all yet.
	 */
	public function get_broker_authorize_url(): ?string {
		if ( '' === trim( VULOPILOT_VULOCLOUD_URL ) ) {
			return null;
		}

		$state = wp_create_nonce( 'vulopilot_connect_broker' );

		$params = array(
			'domain'             => home_url(),
			'returnUri'          => $this->get_broker_redirect_uri(),
			'state'              => $state,
			'soloOrganizationId' => trim( VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID ),
		);

		if ( '' === $params['soloOrganizationId'] ) {
			unset( $params['soloOrganizationId'] );
		}

		return untrailingslashit( VULOPILOT_VULOCLOUD_URL ) . '/plugin/connect/authorize?' . http_build_query( $params );
	}

	/**
	 * Redeems the broker's single-use exchange code and stores the resulting connection.
	 *
	 * @param string $code Single-use exchange code from the broker's return redirect.
	 * @return array<string, mixed>|\WP_Error Same shape as get_status().
	 */
	public function exchange_broker_code( string $code ) {
		$response = wp_remote_post(
			untrailingslashit( VULOPILOT_VULOCLOUD_URL ) . '/plugin/connect/exchange',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'domain' => home_url(),
						'code'   => $code,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// DNS failure, connection refused, timeout, ... - the request never got a response at all.
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'vulopilot_connect_broker_unparseable_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Connect broker returned a non-JSON response (HTTP %d).', 'vulopilot' ),
					$status
				)
			);
		}

		if ( $status < 200 || $status >= 300 || empty( $body['siteId'] ) || empty( $body['siteSecret'] ) ) {
			return new \WP_Error(
				'vulopilot_connect_broker_exchange_failed',
				$body['message'] ?? $body['error'] ?? __( 'Connect broker could not complete the connection.', 'vulopilot' )
			);
		}

		$site_id     = (string) $body['siteId'];
		$site_secret = (string) $body['siteSecret'];
		$credits     = (int) ( $body['credits'] ?? 0 );

		$this->save_connection(
			array(
				'site_id'         => $site_id,
				'secret_enc'      => CredentialEncryption::encrypt( $site_secret ),
				'credits'         => $credits,
				'lifetime_earned' => $credits,
				'connected_at'    => current_time( 'mysql' ),
				'last_synced_at'  => current_time( 'mysql' ),
			)
		);

		// Immediate first report, so telemetry isn't blank until the daily cron fires.
		( new SiteTelemetryReporter() )->report( $site_id, $site_secret );

		return $this->get_status();
	}

	/**
	 * Caches the balance reported after a successful request.
	 *
	 * @param float $credits_remaining The authoritative post-spend balance, straight from the response.
	 * @return void
	 */
	public function record_known_balance( float $credits_remaining ): void {
		$this->save_connection(
			array(
				'credits'        => $credits_remaining,
				'last_synced_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Disconnects this site and clears the stored connection.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$credential = $this->get_site_credential();

		if ( $credential ) {
			$result = $this->post_credits( VULOPILOT_VULOCLOUD_URL, '/plugin/ai-credits/disconnect', $credential['site_id'], $credential['secret'] );

			if ( is_wp_error( $result ) ) {
				error_log( sprintf( '[VuloPilot] Could not revoke ConnectedSite %s on disconnect: %s', $credential['site_id'], $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate, not debug leftover: the one failure mode worth a site owner's server error log seeing, per this method's own docblock (a remote secret left un-revoked after a local disconnect).
			}
		}

		delete_option( self::OPTION_KEY );
	}

	/**
	 * Sends one plugin-built prompt for credit-metered execution.
	 *
	 * @param string      $feature    This plugin's surface name (e.g. 'geo_analysis') - the reporting category.
	 * @param string      $prompt     This site's own already-built prompt text.
	 * @param string      $site_tone  The `site_tone` setting, or ''.
	 * @param string      $request_id Idempotency key, stable across retries of this one logical request.
	 * @param string|null $label      Human task name for the site owner's credit history (e.g. 'Write Meta Title').
	 * @return array{success: true, request_id: string, response: string, credits_used: float, credits_remaining: float, truncated: bool}|\WP_Error
	 *   WP_Error codes: 'vulopilot_ai_insufficient_credits' (nothing charged yet),
	 *   'vulopilot_vulocloud_ai_not_configured', 'vulopilot_vulocloud_ai_unreachable'/'_busy' (safe
	 *   to retry with the same $request_id), or a non-retryable gateway error.
	 */
	public function execute( string $feature, string $prompt, string $site_tone, string $request_id, ?string $label = null ) {
		$body = array(
			'feature' => substr( $feature, 0, 100 ),
			'prompt'  => $prompt,
		);

		if ( null !== $label && '' !== trim( $label ) ) {
			$body['label'] = mb_substr( trim( $label ), 0, 120 );
		}

		return $this->post_ai( '/plugin/ai/prompt', $body, $site_tone, $request_id );
	}

	/**
	 * Runs one AI-service feature, sending its structured input rather than a prompt.
	 *
	 * @param string               $feature_id e.g. 'rewrite_content'.
	 * @param string               $action     e.g. 'rewrite'.
	 * @param array<string, mixed> $context    The feature's structured input.
	 * @param string               $site_tone  The `site_tone` setting, or ''.
	 * @param string               $request_id Idempotency key, stable across retries of this one logical request.
	 * @return array{success: true, request_id: string, response: string, credits_used: float, credits_remaining: float, truncated: bool}|\WP_Error Same contract as execute().
	 */
	public function execute_feature( string $feature_id, string $action, array $context, string $site_tone, string $request_id ) {
		return $this->post_ai(
			'/plugin/ai/execute',
			array(
				'featureId' => $feature_id,
				'action'    => $action,
				'context'   => (object) $context,
			),
			$site_tone,
			$request_id
		);
	}

	/**
	 * Shared request/response handling for the two credit-metered AI endpoints.
	 *
	 * @param string               $path       '/plugin/ai/prompt' or '/plugin/ai/execute'.
	 * @param array<string, mixed> $body       The endpoint's own fields; credentials, tone and request id are added here.
	 * @param string               $site_tone  The `site_tone` setting, or ''.
	 * @param string               $request_id Idempotency key.
	 * @return array{success: true, request_id: string, response: string, credits_used: float, credits_remaining: float, truncated: bool}|\WP_Error
	 */
	private function post_ai( string $path, array $body, string $site_tone, string $request_id ) {
		if ( '' === trim( VULOPILOT_VULOCLOUD_URL ) ) {
			return new \WP_Error( 'vulopilot_vulocloud_ai_not_configured', __( 'VuloCloud isn’t configured for this build yet.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$credential = $this->get_site_credential();

		if ( ! $credential ) {
			return new \WP_Error( 'vulopilot_vulocloud_ai_not_connected', __( 'This site is not connected to VuloCloud.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$body['siteId']    = $credential['site_id'];
		$body['secret']    = $credential['secret'];
		$body['requestId'] = $request_id;

		if ( '' !== $site_tone ) {
			$body['siteTone'] = $site_tone;
		}

		$response = wp_remote_post(
			untrailingslashit( VULOPILOT_VULOCLOUD_URL ) . $path,
			array(
				'timeout' => self::AI_REQUEST_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'vulopilot_vulocloud_ai_unreachable',
				sprintf(
					/* translators: %s: underlying error message. */
					__( 'Could not reach VuloCloud: %s', 'vulopilot' ),
					$response->get_error_message()
				),
				array( 'status' => 503 )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'vulopilot_vulocloud_ai_unparseable_response', __( 'VuloCloud returned an unexpected response.', 'vulopilot' ), array( 'status' => 502 ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$error = (string) ( $decoded['error'] ?? '' );

			if ( 'AI_PROVIDER_NOT_CONFIGURED' === $error ) {
				return new \WP_Error( 'vulopilot_vulocloud_ai_not_configured', __( 'Your Organization hasn’t configured an AI key yet.', 'vulopilot' ), array( 'status' => $status ) );
			}

			if ( 'CONNECTED_SITE_REVOKED' === $error ) {
				// Local credential is stale; clear it so Settings → Connections offers to reconnect.
				delete_option( self::OPTION_KEY );

				return new \WP_Error( 'vulopilot_vulocloud_site_revoked', __( 'This site\'s VuloCloud connection was revoked. Please reconnect under Settings → Connections.', 'vulopilot' ), array( 'status' => $status ) );
			}

			// The server says whether a retry can help; when it doesn't, fall back to the status.
			$retryable = isset( $decoded['retryable'] )
				? (bool) $decoded['retryable']
				: ( 409 === $status || 429 === $status || $status >= 500 );

			if ( $retryable ) {
				return new \WP_Error( 'vulopilot_vulocloud_ai_busy', __( 'VuloCloud could not process this AI request right now.', 'vulopilot' ), array( 'status' => $status ) );
			}

			return new \WP_Error(
				'vulopilot_vulocloud_ai_gateway_error',
				__( 'VuloCloud could not process this AI request right now.', 'vulopilot' ),
				array( 'status' => $status )
			);
		}

		if ( empty( $decoded['success'] ) ) {
			$remaining = (float) ( $decoded['creditsRemaining'] ?? 0 );
			$buy_url   = esc_url_raw( (string) ( $decoded['buyCreditsUrl'] ?? '' ) );
			$this->save_connection(
				array(
					'credits'         => $remaining,
					'buy_credits_url' => $buy_url,
					'last_synced_at'  => current_time( 'mysql' ),
				)
			);

			return new \WP_Error(
				'vulopilot_ai_insufficient_credits',
				__( 'You don’t have enough credits to complete this request.', 'vulopilot' ),
				array(
					'status'            => 402,
					'credits_remaining' => $remaining,
					'can_buy_credits'   => (bool) ( $decoded['canBuyCredits'] ?? true ),
					'can_upgrade'       => (bool) ( $decoded['canUpgrade'] ?? false ),
					'buy_credits_url'   => $buy_url,
				)
			);
		}

		$remaining = (float) ( $decoded['creditsRemaining'] ?? 0 );
		$this->record_known_balance( $remaining );

		return array(
			'success'           => true,
			'request_id'        => (string) ( $decoded['requestId'] ?? $request_id ),
			'response'          => (string) ( $decoded['response'] ?? '' ),
			'credits_used'      => (float) ( $decoded['creditsUsed'] ?? 0 ),
			'credits_remaining' => $remaining,
			'truncated'         => ! empty( $decoded['truncated'] ),
		);
	}

	/**
	 * Checks the AI connection status without sending a prompt or charging credits.
	 *
	 * @return array{connected: bool, configured: bool}|\WP_Error A \WP_Error only for a real connectivity failure.
	 */
	public function get_vulocloud_ai_status() {
		$credential = $this->get_site_credential();

		if ( ! $credential ) {
			return array(
				'connected'  => false,
				'configured' => false,
			);
		}

		$result = $this->post_credits( VULOPILOT_VULOCLOUD_URL, '/plugin/ai/status', $credential['site_id'], $credential['secret'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result['http_status'] >= 200 && $result['http_status'] < 300 ) {
			$this->cache_status( $result['body'] );
		}

		return array(
			'connected'  => true,
			'configured' => ! empty( $result['body']['configured'] ),
		);
	}

	/**
	 * Shared site-secret-authenticated POST helper for the status/disconnect endpoints.
	 *
	 * @param string $base_url The base URL (VULOPILOT_VULOCLOUD_URL).
	 * @param string $path     e.g. '/plugin/ai/status'.
	 * @param string $site_id  The ConnectedSite id from this class's own stored connection.
	 * @param string $secret   The plaintext site secret from that same stored connection.
	 * @return array{http_status: int, body: array<string, mixed>}|\WP_Error
	 */
	private function post_credits( string $base_url, string $path, string $site_id, string $secret ) {
		$response = wp_remote_post(
			untrailingslashit( $base_url ) . $path,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'siteId' => $site_id,
						'secret' => $secret,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'vulopilot_ai_credits_unparseable_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'VuloCloud returned a non-JSON response (HTTP %d).', 'vulopilot' ),
					$status
				)
			);
		}

		return array(
			'http_status' => $status,
			'body'        => $decoded,
		);
	}
}
