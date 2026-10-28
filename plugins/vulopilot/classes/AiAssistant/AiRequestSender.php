<?php
/**
 * AiRequestSender class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiAssistant;

use VuloPilot\Utill\VuloPilotException;
use VuloPilot\Utill as UtillHelper;

defined( 'ABSPATH' ) || exit;

/**
 * The one path every AI call in this plugin goes through.
 *
 * @class       AiRequestSender class
 * @version     1.0.0
 * @author      VuloLabs
 */
class AiRequestSender {

	/**
	 * Requests this site may send per minute (a local, pre-emptive guard
	 * against burning through AI credits too fast).
	 */
	private const MAX_REQUESTS_PER_MINUTE = 20;

	/**
	 * Total attempts including the first; the delay doubles after each failure.
	 * Kept low since a chat reply already waits on one non-streamed AI generation.
	 */
	private const MAX_ATTEMPTS = 2;

	private const BASE_RETRY_DELAY_MS = 500;

	/**
	 * `response_excerpt`/`prompt_excerpt` are an audit trail, not a cache -
	 * bounds how much of a real prompt or reply gets persisted per call.
	 */
	private const EXCERPT_MAX_LENGTH = 300;

	/**
	 * Regex patterns matching common API-key shapes. Deliberately conservative, not exhaustive.
	 *
	 * @var string[]
	 */
	private const SECRET_PATTERNS = array(
		'/sk-[a-zA-Z0-9]{20,}/',            // OpenAI-style secret key.
		'/AIza[0-9A-Za-z\-_]{35}/',          // Google API key.
		'/-----BEGIN (RSA |EC )?PRIVATE KEY-----/',
	);

	/**
	 * Most characters one request may carry. Content is never shortened to fit: a larger
	 * request is refused with a message instead.
	 */
	private const MAX_PROMPT_LENGTH = 800000;

	private AiHistoryRepository $history;
	private AiCreditsConnection $credits_connection;

	/**
	 * @param AiHistoryRepository|null $history            Defaults to a new instance (injectable for tests).
	 * @param AiCreditsConnection|null $credits_connection Defaults to a new instance (injectable for tests).
	 */
	public function __construct(
		?AiHistoryRepository $history = null,
		?AiCreditsConnection $credits_connection = null
	) {
		$this->credits_connection = $credits_connection ?? new AiCreditsConnection();
		$this->history            = $history ?? new AiHistoryRepository();
	}

	/**
	 * @param array<int, array{role: string, content: string}> $messages Chat-style prompt messages.
	 * @param array{mime_type: string, data: string}|null      $image    Optional inline image for the current turn. The gateway's wire contract doesn't carry one today, so it is recorded on the request but never sent.
	 * @param string|null                                      $surface  Optional real feature label recorded to `vulopilot_ai_history.surface`.
	 * @param string|null                                      $label    Optional human task name for the site owner's credit history (e.g. an AI action's own label).
	 * @return AIResponse
	 *
	 * @throws VuloPilotException If the prompt fails safety validation, this minute's request budget is already spent, the site owner's AI credits can't cover the request (TYPE_INSUFFICIENT_CREDITS), no AI key is configured for this site's Organization, or the gateway rejects or fails the request.
	 * @throws \RuntimeException  If no AI connection is configured.
	 */
	public function send( array $messages, ?array $image = null, ?string $surface = null, ?string $label = null ): AIResponse {
		$this->validate_prompt( implode( "\n", array_column( $messages, 'content' ) ) );
		$this->assert_connected();

		$request_id     = $this->new_request_id();
		$prompt_excerpt = $this->build_prompt_excerpt( $messages );

		try {
			$response = $this->send_with_retries(
				fn() => $this->call_gateway( $messages, $surface, $label, $request_id )
			);
			$this->record_success( $prompt_excerpt, $surface, $response );
		} catch ( \Throwable $exception ) {
			$this->record_failure( $prompt_excerpt, $surface, $request_id );
			throw $exception;
		}

		return $response->with_content( wp_kses( $response->get_content(), array() ) );
	}

	/**
	 * Runs an AI-service feature from its structured input instead of a plugin-built prompt.
	 * Same safety checks, retries and history as send().
	 *
	 * @param string               $feature_id Feature id, e.g. 'rewrite_content'.
	 * @param string               $action     Feature action, e.g. 'rewrite'.
	 * @param array<string, mixed> $context    The feature's structured input.
	 * @param string|null          $surface    Optional feature label recorded to `vulopilot_ai_history.surface`.
	 * @param string|null          $label      Optional human task name, recorded as the history row's prompt excerpt.
	 * @param bool                 $allow_html True keeps post-safe HTML (wp_kses_post()), for replies written into
	 *                                         content; false strips all tags, as send() does, for plain text and JSON.
	 * @return AIResponse
	 *
	 * @throws VuloPilotException Same as send(), plus TYPE_INVALID_ACTION_OUTPUT if the reply is still incomplete.
	 * @throws \RuntimeException  If no AI connection is configured.
	 */
	public function send_feature( string $feature_id, string $action, array $context, ?string $surface = null, ?string $label = null, bool $allow_html = true ): AIResponse {
		$this->validate_prompt( implode( "\n", $this->context_strings( $context ) ) );
		$this->assert_connected();

		$request_id     = $this->new_request_id();
		$prompt_excerpt = $label ?? $feature_id;

		try {
			$response = $this->send_with_retries(
				fn() => $this->to_response(
					$this->credits_connection->execute_feature( $feature_id, $action, $context, $this->site_tone(), $request_id ),
					$request_id
				)
			);
			$this->record_success( $prompt_excerpt, $surface, $response );
		} catch ( \Throwable $exception ) {
			$this->record_failure( $prompt_excerpt, $surface, $request_id );
			throw $exception;
		}

		if ( $response->is_truncated() ) {
			VuloPilotException::raise(
				esc_html__( 'The AI could not finish this reply, so nothing was saved from it. Please try again.', 'vulopilot' ),
				VuloPilotException::TYPE_INVALID_ACTION_OUTPUT
			);
		}

		$content = $response->get_content();

		return $response->with_content( $allow_html ? wp_kses_post( $content ) : wp_kses( $content, array() ) );
	}

	/**
	 * @return void
	 *
	 * @throws \RuntimeException If no AI connection is configured.
	 */
	private function assert_connected(): void {
		if ( ! $this->credits_connection->is_connected() ) {
			throw new \RuntimeException( esc_html__( 'No AI connection is configured.', 'vulopilot' ) );
		}
	}

	/**
	 * @return string Idempotency key shared by every attempt of one request.
	 */
	private function new_request_id(): string {
		return 'wp_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * The General tab's "Site tone" setting, autosaved to the vulopilot_settings option.
	 *
	 * @return string
	 */
	private function site_tone(): string {
		$settings = wp_parse_args( (array) get_option( UtillHelper::VULOPILOT_SETTINGS_KEY, array() ), UtillHelper::VULOPILOT_SETTINGS_DEFAULTS );

		return (string) $settings['site_tone'];
	}

	/**
	 * Every string inside a feature context, so validate_prompt() sees exactly what is sent.
	 *
	 * @param array<mixed> $context Feature context.
	 * @return string[]
	 */
	private function context_strings( array $context ): array {
		$strings = array();

		array_walk_recursive(
			$context,
			static function ( $value ) use ( &$strings ) {
				if ( is_string( $value ) ) {
					$strings[] = $value;
				}
			}
		);

		return $strings;
	}

	/**
	 * The prompt-length/secret-pattern gate every request goes through before it's ever
	 * sent.
	 *
	 * @param string $combined All the text about to be sent.
	 * @return void
	 *
	 * @throws VuloPilotException If the prompt is too long or appears to contain a secret.
	 */
	private function validate_prompt( string $combined ): void {
		if ( mb_strlen( $combined ) > self::MAX_PROMPT_LENGTH ) {
			VuloPilotException::raise(
				sprintf(
					/* translators: %d is the maximum allowed prompt length in characters. */
					esc_html__( 'This request is too long to send to the AI service (limit: %d characters).', 'vulopilot' ),
					absint( self::MAX_PROMPT_LENGTH )
				),
				VuloPilotException::TYPE_UNSAFE_PROMPT
			);
		}

		foreach ( self::SECRET_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $combined ) ) {
				VuloPilotException::raise(
					esc_html__( 'This request appears to contain a credential and was blocked before sending.', 'vulopilot' ),
					VuloPilotException::TYPE_UNSAFE_PROMPT
				);
			}
		}
	}

	/**
	 * Only a TYPE_TRANSIENT_GATEWAY failure is retried; other exception types are rethrown immediately.
	 *
	 * @param callable(): AIResponse $attempt One attempt, reusing the same request id each time.
	 * @return AIResponse
	 *
	 * @throws VuloPilotException If every attempt is exhausted.
	 */
	private function send_with_retries( callable $attempt ): AIResponse {
		$attempts_made = 0;

		while ( true ) {
			try {
				$this->enforce_rate_limit();

				return $attempt();
			} catch ( VuloPilotException $exception ) {
				if ( VuloPilotException::TYPE_TRANSIENT_GATEWAY !== $exception->get_type() ) {
					throw $exception;
				}

				++$attempts_made;

				if ( $attempts_made >= self::MAX_ATTEMPTS ) {
					throw $exception;
				}

				usleep( self::BASE_RETRY_DELAY_MS * 1000 * ( 2 ** ( $attempts_made - 1 ) ) );
			}
		}
	}

	/**
	 * Uses a WP transient as a lightweight per-minute request counter.
	 *
	 * @return void
	 *
	 * @throws VuloPilotException If this minute's budget is already spent.
	 */
	private function enforce_rate_limit(): void {
		$transient_key = 'vulopilot_ai_rate_vulocloud_' . floor( time() / MINUTE_IN_SECONDS );
		$count         = (int) get_transient( $transient_key );

		if ( $count >= self::MAX_REQUESTS_PER_MINUTE ) {
			VuloPilotException::raise(
				sprintf(
					/* translators: %d: requests-per-minute limit. */
					esc_html__( 'AI rate limit reached (%d requests/minute).', 'vulopilot' ),
					absint( self::MAX_REQUESTS_PER_MINUTE )
				),
				VuloPilotException::TYPE_RATE_LIMIT_EXCEEDED
			);
		}

		set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Sends one attempt to the AI gateway and returns the finished text.
	 *
	 * @param array<int, array{role: string, content: string}> $messages   Chat-style prompt messages.
	 * @param string|null                                      $surface    Optional real feature label.
	 * @param string|null                                      $label      Optional human task name.
	 * @param string                                           $request_id Idempotency key shared by every attempt.
	 * @return AIResponse
	 *
	 * @throws VuloPilotException TYPE_INSUFFICIENT_CREDITS, TYPE_VULOCLOUD_AI_NOT_CONFIGURED, TYPE_TRANSIENT_GATEWAY (retried) or TYPE_GATEWAY_REQUEST.
	 */
	private function call_gateway( array $messages, ?string $surface, ?string $label, string $request_id ): AIResponse {
		$result = $this->credits_connection->execute(
			$surface ?? 'ai_request',
			$this->flatten_messages( $messages ),
			$this->site_tone(),
			$request_id,
			$label
		);

		return $this->to_response( $result, $request_id );
	}

	/**
	 * Turns an AiCreditsConnection result into a response or the matching exception.
	 *
	 * @param array<string, mixed>|\WP_Error $result     AiCreditsConnection::execute()/execute_feature() result.
	 * @param string                         $request_id The request's idempotency key.
	 * @return AIResponse
	 *
	 * @throws VuloPilotException TYPE_INSUFFICIENT_CREDITS, TYPE_VULOCLOUD_AI_NOT_CONFIGURED, TYPE_TRANSIENT_GATEWAY (retried) or TYPE_GATEWAY_REQUEST.
	 */
	private function to_response( $result, string $request_id ): AIResponse {
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$data = (array) $result->get_error_data();

			if ( 'vulopilot_ai_insufficient_credits' === $code ) {
				$credit_context = array(
					'credits_remaining' => (float) ( $data['credits_remaining'] ?? 0 ),
					'can_buy_credits'   => (bool) ( $data['can_buy_credits'] ?? true ),
					'can_upgrade'       => (bool) ( $data['can_upgrade'] ?? false ),
					'buy_credits_url'   => esc_url_raw( (string) ( $data['buy_credits_url'] ?? '' ) ),
				);

				VuloPilotException::raise( esc_html( $result->get_error_message() ), VuloPilotException::TYPE_INSUFFICIENT_CREDITS, $credit_context );
			}

			if ( 'vulopilot_vulocloud_ai_not_configured' === $code ) {
				VuloPilotException::raise( esc_html( $result->get_error_message() ), VuloPilotException::TYPE_VULOCLOUD_AI_NOT_CONFIGURED );
			}

			if ( in_array( $code, array( 'vulopilot_vulocloud_ai_unreachable', 'vulopilot_vulocloud_ai_busy' ), true ) ) {
				VuloPilotException::raise( esc_html( $result->get_error_message() ), VuloPilotException::TYPE_TRANSIENT_GATEWAY );
			}

			VuloPilotException::raise( esc_html( $result->get_error_message() ), VuloPilotException::TYPE_GATEWAY_REQUEST );
		}

		return new AIResponse(
			$result['response'],
			(float) $result['credits_used'],
			$result['request_id'] ?? $request_id,
			! empty( $result['truncated'] )
		);
	}

	/**
	 * @param array<int, array{role: string, content: string}> $messages Chat-style prompt messages.
	 * @return string
	 */
	private function flatten_messages( array $messages ): string {
		return implode(
			"\n\n",
			array_filter( array_map( static fn( $message ) => (string) ( $message['content'] ?? '' ), $messages ) )
		);
	}

	/**
	 * @param string|null $prompt_excerpt What was asked, already excerpted.
	 * @param string|null $surface        Originating request's own feature label.
	 * @param AIResponse  $response       Completed response.
	 * @return void
	 */
	private function record_success( ?string $prompt_excerpt, ?string $surface, AIResponse $response ): void {
		$this->history->insert(
			array(
				'request_id'       => $response->get_request_id(),
				'surface'          => $surface,
				'credits_used'     => $response->get_credits_used(),
				'status'           => 'success',
				'prompt_excerpt'   => $prompt_excerpt,
				'response_excerpt' => $this->build_excerpt( $response->get_content() ),
				'requested_by'     => get_current_user_id(),
			)
		);
	}

	/**
	 * @param string|null $prompt_excerpt What was asked, already excerpted.
	 * @param string|null $surface        Originating request's own feature label.
	 * @param string      $request_id     The request's idempotency key (nothing was charged on failure).
	 * @return void
	 */
	private function record_failure( ?string $prompt_excerpt, ?string $surface, string $request_id ): void {
		$this->history->insert(
			array(
				'request_id'     => $request_id,
				'surface'        => $surface,
				'credits_used'   => 0,
				'status'         => 'failure',
				'prompt_excerpt' => $prompt_excerpt,
				'requested_by'   => get_current_user_id(),
			)
		);
	}

	/**
	 * Truncates real text down to an audit-trail-sized excerpt. `mb_substr`
	 * since real AI replies routinely contain multi-byte characters.
	 *
	 * @param string $content Full text.
	 * @return string
	 */
	private function build_excerpt( string $content ): string {
		$trimmed = trim( $content );

		if ( mb_strlen( $trimmed ) <= self::EXCERPT_MAX_LENGTH ) {
			return $trimmed;
		}

		return mb_substr( $trimmed, 0, self::EXCERPT_MAX_LENGTH ) . '…';
	}

	/**
	 * The real, human-typed question this call is answering.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Originating request messages.
	 * @return string|null Null if the request genuinely has no user message.
	 */
	private function build_prompt_excerpt( array $messages ): ?string {
		$last_user_message = null;

		foreach ( $messages as $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$last_user_message = (string) ( $message['content'] ?? '' );
			}
		}

		return null === $last_user_message ? null : $this->build_excerpt( $last_user_message );
	}
}
