<?php
/**
 * ImproveReadabilityAction class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot\Actions;

use VuloPilot\Utill\VuloPilotException;
use VuloPilot\AiAssistant\ActionExecutionResult;
use VuloPilot\AiAssistant\ActionPreview;
use VuloPilot\AiAssistant\AIResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites a post's entire `post_content` for readability.
 *
 * @class       ImproveReadabilityAction class
 * @version     1.0.0
 * @author      VuloLabs
 */
class ImproveReadabilityAction extends AbstractCloudAction {

	/**
	 * A rewrite shorter than this fraction of the original is treated as a likely
	 * truncation/summarization rather than a genuine readability pass, and rejected.
	 */
	private const MIN_LENGTH_RATIO = 0.5;

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'improve-readability';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Improve readability', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function validate_input( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			VuloPilotException::raise( esc_html__( 'post_id must refer to an existing post or page.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
			VuloPilotException::raise( esc_html__( 'This post has no content to rewrite.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		return array(
			'post_id'          => $post_id,
			'original_content' => $post->post_content,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_cloud_feature(): array {
		return array( 'content_improvement', 'rewrite' );
	}

	/**
	 * @inheritDoc
	 */
	public function build_cloud_context( array $input ): array {
		return array(
			'content' => $input['original_content'],
			'goal'    => 'improve readability',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function parse_response( AIResponse $response ): array {
		return array( 'rewritten_content' => trim( $response->get_content() ) );
	}

	/**
	 * @inheritDoc
	 */
	public function validate_output( array $output, array $input ): void {
		$rewritten = $output['rewritten_content'] ?? '';

		if ( '' === trim( wp_strip_all_tags( $rewritten ) ) ) {
			VuloPilotException::raise( esc_html__( 'The AI returned empty content.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_OUTPUT );
		}

		$original_length  = mb_strlen( wp_strip_all_tags( $input['original_content'] ) );
		$rewritten_length = mb_strlen( wp_strip_all_tags( $rewritten ) );

		if ( $original_length > 0 && ( $rewritten_length / $original_length ) < self::MIN_LENGTH_RATIO ) {
			VuloPilotException::raise(
				esc_html__( 'The AI returned content that looks truncated rather than rewritten - rejected for safety.', 'vulopilot' ),
				VuloPilotException::TYPE_INVALID_ACTION_OUTPUT
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function build_preview( array $output, array $input ): ActionPreview {
		return new ActionPreview(
			__( 'Rewrite content for readability', 'vulopilot' ),
			wp_trim_words( wp_strip_all_tags( $input['original_content'] ), 30 ),
			wp_trim_words( wp_strip_all_tags( $output['rewritten_content'] ), 30 ),
			'html'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute( array $output, array $input ): ActionExecutionResult {
		$result = wp_update_post(
			array(
				'ID'           => $input['post_id'],
				'post_content' => wp_kses_post( $output['rewritten_content'] ),
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
				'previous_content' => $input['original_content'],
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function rollback( array $snapshot ): void {
		wp_update_post(
			array(
				'ID'           => $snapshot['post_id'],
				'post_content' => $snapshot['previous_content'],
			)
		);
	}
}
