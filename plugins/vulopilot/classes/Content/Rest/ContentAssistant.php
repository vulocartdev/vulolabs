<?php
namespace VuloPilot\Content\Rest;

use VuloPilot\AiCopilot\BlogIntake;
use VuloPilot\AiCopilot\ContentCreationOrchestrator;
use VuloPilot\Utill\VuloPilotException;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the AI Content Assistant sidebar's chat turn.
 *
 * @class       ContentAssistant controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class ContentAssistant extends \WP_REST_Controller {

	/**
	 * REST base for this controller's routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'content-assistant';

	/**
	 * Creates the content once a blog request is complete.
	 *
	 * @var ContentCreationOrchestrator
	 */
	private ContentCreationOrchestrator $orchestrator;

	/**
	 * ContentAssistant constructor.
	 */
	public function __construct() {
		$this->orchestrator = new ContentCreationOrchestrator();
	}

	/**
	 * Registers POST /content-assistant/chat.
	 *
	 * @inheritDoc
	 */
	public function register_routes() {
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/chat',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Same manage_options gate every other VuloPilot REST route uses.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! VuloPilot()->modules->is_active( 'ai-copilot' ) ) {
			return new \WP_Error(
				'vulopilot_ai_copilot_inactive',
				__( 'Enable the AI Copilot module to use the AI Content Assistant.', 'vulopilot' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handles one chat turn: the next blog question, the created draft, or the AI's answer.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( '' === trim( $message ) ) {
			return new \WP_Error(
				'vulopilot_empty_message',
				__( 'Message cannot be empty.', 'vulopilot' ),
				array( 'status' => 400 )
			);
		}

		// A blog request is collected by fixed questions, with no AI, until every field is known.
		$decision = ( new BlogIntake( 'content_assistant' ) )->handle( $message );

		if ( null === $decision ) {
			$answer = $this->ask( $this->build_chat_context( $message, (array) $request->get_param( 'history' ) ) );

			if ( $answer instanceof \WP_Error ) {
				return $answer;
			}

			$decision = array(
				'status'  => 'respond',
				'message' => $answer,
			);
		}

		if ( 'ready_action' === $decision['status'] ) {
			$result = $this->orchestrator->create_content_and_respond( $decision );

			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		return rest_ensure_response(
			array(
				'content' => $decision['message'],
				'link'    => null,
				'run_id'  => null,
			)
		);
	}

	/**
	 * Sends one chat message with the conversation so far and returns the AI's answer.
	 *
	 * @param array<string, mixed> $context build_chat_context()'s result.
	 * @return string|\WP_Error
	 */
	private function ask( array $context ) {
		try {
			$response = VuloPilot()->ai_request_sender->send_feature( 'content_assistant_chat', 'chat', $context, 'content_assistant_chat', null, false );
		} catch ( VuloPilotException $exception ) {
			if ( VuloPilotException::TYPE_INSUFFICIENT_CREDITS === $exception->get_type() ) {
				return $exception->to_insufficient_credits_error();
			}

			// Returned, not rethrown, so the \Throwable catch below doesn't fatal.
			if ( VuloPilotException::TYPE_UNSAFE_PROMPT !== $exception->get_type() ) {
				return new \WP_Error( 'vulopilot_ai_request_failed', $exception->getMessage(), array( 'status' => 502 ) );
			}

			return new \WP_Error( 'vulopilot_unsafe_prompt', $exception->getMessage(), array( 'status' => 400 ) );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error(
				'vulopilot_ai_not_connected',
				sprintf(
					/* translators: %s is the exception's own real message, e.g. "No AI connection is configured." */
					__( '%s Connect this site to VuloCloud in Settings → Connections.', 'vulopilot' ),
					$exception->getMessage()
				),
				array( 'status' => 400 )
			);
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'vulopilot_ai_request_failed', $exception->getMessage(), array( 'status' => 502 ) );
		}

		$answer = trim( $response->get_content() );

		return '' !== $answer ? $answer : __( "Sorry, I didn't quite catch that - could you rephrase?", 'vulopilot' );
	}

	/**
	 * The content assistant feature's input: the new message and the whole conversation so far.
	 *
	 * @param string            $message     The new user message.
	 * @param array<int, mixed> $raw_history Client-supplied {role, content} turns, oldest first.
	 * @return array<string, mixed>
	 */
	private function build_chat_context( string $message, array $raw_history ): array {
		$history = array();

		foreach ( $raw_history as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['role'] ) || empty( $entry['content'] ) ) {
				continue;
			}

			$history[] = array(
				'role'    => 'user' === $entry['role'] ? 'user' : 'assistant',
				'content' => sanitize_textarea_field( (string) $entry['content'] ),
			);
		}

		return array(
			'site_name' => get_bloginfo( 'name' ),
			'message'   => $message,
			'history'   => $history,
		);
	}
}
