<?php
/**
 * WriteMetaDescriptionAction class file.
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
 * one-click fix for RuleEngine\Rules\MissingMetaDescriptionRule's recommendations.
 *
 * @class       WriteMetaDescriptionAction class
 * @version     1.0.0
 * @author      VuloLabs
 */
class WriteMetaDescriptionAction extends AbstractCloudAction {

	/**
	 * Search snippets are truncated well before this; a description
	 * longer than this reads as the AI padding rather than summarizing.
	 */
	private const MAX_LENGTH = 160;

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'write-meta-description';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Write meta description', 'vulopilot' );
	}

	/**
	 * Impact::LOW - rewrites `post_excerpt` only, never `post_content`.
	 *
	 * @inheritDoc
	 */
	public function get_risk_level(): string {
		return Impact::LOW;
	}

	/**
	 * @inheritDoc
	 */
	public function validate_input( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		// Matches PostSeoMetaFields::POST_TYPES.
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page', 'product' ), true ) ) {
			VuloPilotException::raise( esc_html__( 'post_id must refer to an existing post, page, or product.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
			VuloPilotException::raise( esc_html__( 'This post has no content to summarize.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		return array(
			'post_id'          => $post_id,
			'title'            => $post->post_title,
			'content'          => $post->post_content,
			'previous_excerpt' => $post->post_excerpt,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_cloud_feature(): array {
		return array( 'meta_description', 'generate' );
	}

	/**
	 * @inheritDoc
	 */
	public function build_cloud_context( array $input ): array {
		return array(
			'title'   => $input['title'],
			'content' => $input['content'],
		);
	}

	/**
	 * @inheritDoc
	 */
	public function parse_response( AIResponse $response ): array {
		return array( 'description' => trim( $response->get_content(), " \t\n\r\0\x0B\"'" ) );
	}

	/**
	 * @inheritDoc
	 */
	public function validate_output( array $output, array $input ): void {
		$description = $output['description'] ?? '';

		if ( '' === $description ) {
			VuloPilotException::raise( esc_html__( 'The AI returned an empty description.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_OUTPUT );
		}

		if ( mb_strlen( $description ) > self::MAX_LENGTH * 2 ) {
			VuloPilotException::raise( esc_html__( 'The AI returned a description that is too long.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_OUTPUT );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function build_preview( array $output, array $input ): ActionPreview {
		return new ActionPreview(
			sprintf(
				/* translators: %s is the post/page title. */
				__( 'Set meta description for %s', 'vulopilot' ),
				$input['title']
			),
			'' !== $input['previous_excerpt'] ? $input['previous_excerpt'] : null,
			$output['description'],
			'text'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute( array $output, array $input ): ActionExecutionResult {
		$result = wp_update_post(
			array(
				'ID'           => $input['post_id'],
				'post_excerpt' => wp_kses_post( $output['description'] ),
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
				'previous_excerpt' => $input['previous_excerpt'],
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
				'post_excerpt' => $snapshot['previous_excerpt'],
			)
		);
	}
}
