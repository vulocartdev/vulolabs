<?php
/**
 * GenerateBlogAction class file.
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
 * Generates a new blog post draft from a topic the site owner types.
 *
 * @class       GenerateBlogAction class
 * @version     1.0.0
 * @author      VuloLabs
 */
class GenerateBlogAction extends AbstractCloudAction {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'generate-blog';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Generate blog post', 'vulopilot' );
	}

	/**
	 * Impact::HIGH - creates a brand-new post outright.
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
		$topic = sanitize_text_field( (string) ( $input['topic'] ?? '' ) );

		if ( mb_strlen( $topic ) < 5 ) {
			VuloPilotException::raise( esc_html__( 'Please provide a topic of at least 5 characters.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_INPUT );
		}

		// All optional - a bare topic is still a complete, valid input.
		$word_count = absint( $input['word_count'] ?? 0 );
		$word_count = $word_count > 0 ? max( 100, min( 5000, $word_count ) ) : 0;
		$tone       = mb_substr( sanitize_text_field( (string) ( $input['tone'] ?? '' ) ), 0, 60 );
		// Content Planner's own writing-style fields (audience/prompt) - optional for every
		// other caller of this action, which simply won't pass them.
		$audience  = mb_substr( sanitize_text_field( (string) ( $input['audience'] ?? '' ) ), 0, 120 );
		$prompt    = mb_substr( sanitize_textarea_field( (string) ( $input['prompt'] ?? '' ) ), 0, 2000 );
		$post_type = in_array( $input['post_type'] ?? 'post', array( 'post', 'page' ), true ) ? $input['post_type'] : 'post';

		return array(
			'topic'      => $topic,
			'word_count' => $word_count,
			'tone'       => $tone,
			'audience'   => $audience,
			'prompt'     => $prompt,
			'post_type'  => $post_type,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_cloud_feature(): array {
		return array( 'blog_post', 'generate' );
	}

	/**
	 * @inheritDoc
	 */
	public function build_cloud_context( array $input ): array {
		return array(
			'topic'      => $input['topic'],
			'word_count' => $input['word_count'],
			'tone'       => $input['tone'],
		);
	}

	/**
	 * @inheritDoc
	 */
	public function parse_response( AIResponse $response ): array {
		$content = $response->get_content();

		preg_match( '/TITLE:\s*(.+?)\n/i', $content, $title_match );
		preg_match( '/BODY:\s*(.+)/is', $content, $body_match );

		return array(
			'title' => trim( $title_match[1] ?? '' ),
			'body'  => trim( $body_match[1] ?? '' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function validate_output( array $output, array $input ): void {
		if ( '' === ( $output['title'] ?? '' ) || '' === ( $output['body'] ?? '' ) ) {
			VuloPilotException::raise(
				esc_html__( 'The AI response did not match the expected TITLE/BODY format.', 'vulopilot' ),
				VuloPilotException::TYPE_INVALID_ACTION_OUTPUT
			);
		}

		if ( mb_strlen( wp_strip_all_tags( $output['body'] ) ) < 100 ) {
			VuloPilotException::raise( esc_html__( 'The AI returned a post body that is too short to be useful.', 'vulopilot' ), VuloPilotException::TYPE_INVALID_ACTION_OUTPUT );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function build_preview( array $output, array $input ): ActionPreview {
		return new ActionPreview(
			sprintf(
				/* translators: %s is the generated post title. */
				__( 'Create a new draft post: %s', 'vulopilot' ),
				$output['title']
			),
			null,
			wp_trim_words( wp_strip_all_tags( $output['body'] ), 40 ),
			'html'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute( array $output, array $input ): ActionExecutionResult {
		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $output['title'] ),
				'post_content' => wp_kses_post( $output['body'] ),
				'post_status'  => 'draft',
				'post_type'    => $input['post_type'] ?? 'post',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new ActionExecutionResult( false, null, null, array(), $post_id->get_error_message() );
		}

		return new ActionExecutionResult(
			true,
			'post',
			(string) $post_id,
			array( 'created_post_id' => $post_id )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function rollback( array $snapshot ): void {
		wp_trash_post( $snapshot['created_post_id'] );
	}
}
