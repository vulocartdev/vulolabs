<?php
/**
 * WritePostContentAction class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot\Actions;

use VuloPilot\Utill\VuloPilotException;
use VuloPilot\AiAssistant\ActionExecutionResult;
use VuloPilot\AiAssistant\ActionPreview;
use VuloPilot\AiAssistant\AIResponse;
use VuloPilot\Utill\Impact;

defined( 'ABSPATH' ) || exit;

/**
 * Create Content's "AI Writer" tool (ContentToolsGrid.tsx) - given a short brief of what
 * to write, creates a new draft post with AI-written body copy.
 *
 * @class       WritePostContentAction class
 * @version     1.0.0
 * @author      VuloLabs
 */
class WritePostContentAction extends AbstractCloudAction {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'write-post-content';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Write content', 'vulopilot' );
	}

	/**
	 * Impact::HIGH - replaces the post's entire `post_content` body wholesale.
	 *
	 * @inheritDoc
	 */
	public function get_risk_level(): string {
		return Impact::HIGH;
	}

	/**
	 * @inheritDoc
	 */
	public function validate_input( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		// No post_id = the AI Writer tool's own "write something new" case (a draft is created in
		// execute()).
		if ( $post_id && ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) ) {
			VuloPilotException::raise( esc_html__( 'post_id must refer to an existing post or page.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		$brief = sanitize_textarea_field( (string) ( $input['brief'] ?? '' ) );

		if ( mb_strlen( $brief ) < 5 ) {
			VuloPilotException::raise( esc_html__( 'Please describe what to write about (at least 5 characters).', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		return array(
			'post_id'          => $post_id,
			'post_title'       => $post ? $post->post_title : wp_trim_words( $brief, 8, '' ),
			'previous_content' => $post ? $post->post_content : '',
			'brief'            => $brief,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_cloud_feature(): array {
		return array( 'post_content', 'write' );
	}

	/**
	 * @inheritDoc
	 */
	public function build_cloud_context( array $input ): array {
		return array(
			'title'   => $input['post_title'],
			'brief'   => $input['brief'],
			'content' => $input['previous_content'],
		);
	}

	/**
	 * @inheritDoc
	 */
	public function parse_response( AIResponse $response ): array {
		return array( 'content' => trim( $response->get_content() ) );
	}

	/**
	 * @inheritDoc
	 */
	public function validate_output( array $output, array $input ): void {
		$content = $output['content'] ?? '';

		if ( mb_strlen( wp_strip_all_tags( $content ) ) < 100 ) {
			VuloPilotException::raise( esc_html__( 'The AI returned content that is too short to be useful.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_OUTPUT );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function build_preview( array $output, array $input ): ActionPreview {
		return new ActionPreview(
			sprintf(
				/* translators: %s is the post/page title being written for. */
				__( 'Write content for: %s', 'vulopilot' ),
				$input['post_title']
			),
			'' !== $input['previous_content']
				? wp_trim_words( wp_strip_all_tags( $input['previous_content'] ), 40 )
				: null,
			wp_trim_words( wp_strip_all_tags( $output['content'] ), 40 ),
			'html'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute( array $output, array $input ): ActionExecutionResult {
		if ( ! $input['post_id'] ) {
			$new_id = wp_insert_post(
				array(
					'post_title'   => $input['post_title'],
					'post_content' => wp_kses_post( $output['content'] ),
					'post_status'  => 'draft',
					'post_type'    => 'post',
				),
				true
			);

			if ( is_wp_error( $new_id ) ) {
				return new ActionExecutionResult( false, null, null, array(), $new_id->get_error_message() );
			}

			return new ActionExecutionResult( true, 'post', (string) $new_id, array( 'created_post_id' => $new_id ) );
		}

		$result = wp_update_post(
			array(
				'ID'           => $input['post_id'],
				'post_content' => wp_kses_post( $output['content'] ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new ActionExecutionResult( false, 'post', (string) $input['post_id'], array(), $result->get_error_message() );
		}

		return new ActionExecutionResult(
			true,
			'post',
			(string) $input['post_id'],
			array(
				'post_id'          => $input['post_id'],
				'previous_content' => $input['previous_content'],
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function rollback( array $snapshot ): void {
		if ( ! empty( $snapshot['created_post_id'] ) ) {
			wp_trash_post( $snapshot['created_post_id'] );
			return;
		}

		wp_update_post(
			array(
				'ID'           => $snapshot['post_id'],
				'post_content' => $snapshot['previous_content'],
			)
		);
	}
}
