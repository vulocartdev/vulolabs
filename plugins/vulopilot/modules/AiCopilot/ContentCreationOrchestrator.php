<?php
/**
 * ContentCreationOrchestrator class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot;

use VuloPilot\Utill\VuloPilotException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the content a completed chat request describes.
 *
 * @class       ContentCreationOrchestrator class
 * @version     1.0.0
 * @author      VuloLabs
 */
class ContentCreationOrchestrator {

	/**
	 * The only 3 AIActions a free-text chat message alone can legitimately trigger.
	 */
	public const CONTENT_CREATION_ACTIONS = array(
		'generate-blog' => array(
			'noun'       => 'blog post',
			'link_label' => 'View/Edit Blog',
		),
	);

	/**
	 * Runs a whitelisted CONTENT_CREATION_ACTIONS entry through the real AIAction
	 * lifecycle end to end.
	 *
	 * @param array{action_id: string, input: array<string, mixed>} $decision BlogIntake::handle()'s "ready_action" decision.
	 * @return array{content: string, link: array{url: string, label: string}|null, run_id: int}|\WP_Error
	 */
	public function create_content_and_respond( array $decision ) {
		try {
			$proposal = VuloPilot()->ai_action_runner->propose( $decision['action_id'], $decision['input'] );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'vulopilot_ai_action_invalid', $exception->getMessage(), array( 'status' => 400 ) );
		} catch ( VuloPilotException $exception ) {
			if ( VuloPilotException::TYPE_INVALID_ACTION_INPUT === $exception->get_type() ) {
				return new \WP_Error( 'vulopilot_ai_action_invalid_input', $exception->getMessage(), array( 'status' => 400 ) );
			} elseif ( VuloPilotException::TYPE_INVALID_ACTION_OUTPUT === $exception->get_type() ) {
				return new \WP_Error( 'vulopilot_ai_action_invalid_output', $exception->getMessage(), array( 'status' => 502 ) );
			} elseif ( VuloPilotException::TYPE_UNSAFE_PROMPT === $exception->get_type() ) {
				return new \WP_Error( 'vulopilot_unsafe_prompt', $exception->getMessage(), array( 'status' => 400 ) );
			} elseif ( VuloPilotException::TYPE_INSUFFICIENT_CREDITS === $exception->get_type() ) {
				return $exception->to_insufficient_credits_error();
			} elseif ( $exception->is_ai_request_failure() ) {
				return new \WP_Error( 'vulopilot_ai_request_error', $exception->getMessage(), array( 'status' => 502 ) );
			}

			return new \WP_Error( 'vulopilot_ai_request_error', $exception->getMessage(), array( 'status' => 502 ) );
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
		}

		try {
			$result = VuloPilot()->ai_action_runner->approve( $proposal['run_id'] );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'vulopilot_ai_action_not_pending', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'vulopilot_ai_action_invalid', $exception->getMessage(), array( 'status' => 400 ) );
		}

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'vulopilot_ai_action_execution_failed',
				$result['message'] ?? __( 'The content was generated but could not be saved.', 'vulopilot' ),
				array( 'status' => 502 )
			);
		}

		$meta      = self::CONTENT_CREATION_ACTIONS[ $decision['action_id'] ];
		$edit_link = get_edit_post_link( (int) $result['object_ref'], 'raw' );

		return array(
			'content' => sprintf(
				/* translators: %s is a content type, e.g. "blog post". */
				__( 'Your %s has been created successfully:', 'vulopilot' ),
				$meta['noun']
			),
			'link'    => $edit_link ? array(
				'url'   => $edit_link,
				'label' => $meta['link_label'],
			) : null,
			'run_id'  => (int) $proposal['run_id'],
		);
	}
}
