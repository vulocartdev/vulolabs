<?php
/**
 * ContentAnalyzer class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\ContentOptimization;

use VuloPilot\AiAssistant\AiRequestSender;
use VuloPilot\ContentOptimization\ValueObjects\ContentScore;
use VuloPilot\Utill\FindingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Generates a ContentScore for one post - "Topic Authority" (the one Content Intelligence
 * AI capability actually requested).
 *
 * @class       ContentAnalyzer class
 * @version     1.0.0
 * @author      VuloLabs
 */
class ContentAnalyzer {

	/**
	 * The 5 real Content Intelligence checks a post can have an open finding for: this
	 * module's own readability scanner.
	 */
	private const SCANNER_IDS = array( 'readability', 'thin-content', 'duplicate-content', 'heading-structure', 'internal-linking' );

	public const META_KEY = '_vulopilot_content_score';

	private FindingRepository $findings;
	private AiRequestSender $request_sender;

	/**
	 * @param AiRequestSender        $request_sender Sends a prompt through the safety-validate → send → sanitize sequence.
	 * @param FindingRepository|null $findings       Defaults to a new instance (injectable for tests).
	 */
	public function __construct( AiRequestSender $request_sender, ?FindingRepository $findings = null ) {
		$this->request_sender = $request_sender;
		$this->findings       = $findings ?? new FindingRepository();
	}

	/**
	 * Runs a fresh analysis, persists it to postmeta, and returns it.
	 *
	 * @param int $post_id Post to analyze.
	 * @return ContentScore
	 *
	 * @throws \InvalidArgumentException If post_id doesn't refer to a published post/page.
	 * @throws \RuntimeException         If no AI connection is configured, or the AI response is unusable.
	 */
	public function analyze( int $post_id ): ContentScore {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'publish' !== $post->post_status ) {
			throw new \InvalidArgumentException( esc_html__( 'post_id must refer to a published post or page.', 'vulopilot' ) );
		}

		$deterministic_score = $this->calculate_deterministic_score( $post_id );
		$context             = $this->build_context( $post, $deterministic_score );
		$response            = $this->request_sender->send_feature( 'content_intelligence', 'analyze', $context, 'content_intelligence', null, false );
		$parsed              = $this->parse_response( $response );

		$overall_score = $this->calculate_overall_score( $deterministic_score, $parsed['topic_authority'] );

		$score = new ContentScore(
			$post_id,
			$deterministic_score,
			$parsed['topic_authority'],
			$overall_score,
			$parsed['suggestions'],
			current_time( 'mysql', true )
		);

		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $score->to_array() ) );

		return $score;
	}

	/**
	 * Reads back a previously generated score without spending another AI
	 * call - what the REST controller's GET route returns.
	 *
	 * @param int $post_id Post to read a score for.
	 * @return array<string, mixed>|null
	 */
	public function get_stored_score( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );

		if ( '' === $stored ) {
			return null;
		}

		$decoded = json_decode( (string) $stored, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Percentage of SCANNER_IDS's 5 checks with no open finding for this post.
	 *
	 * @param int $post_id Post to score.
	 * @return int|null 0-100, or null if no finding across SCANNER_IDS has ever been recorded.
	 */
	private function calculate_deterministic_score( int $post_id ): ?int {
		$has_any_history = 0 < (int) $this->findings->find_all(
			array(
				'scanner_id' => self::SCANNER_IDS,
				'per_page'   => 1,
			)
		)['total'];

		if ( ! $has_any_history ) {
			return null;
		}

		$open_failures = (int) $this->findings->find_all(
			array(
				'scanner_id' => self::SCANNER_IDS,
				'status'     => 'open',
				'object_ref' => (string) $post_id,
				'per_page'   => count( self::SCANNER_IDS ),
			)
		)['total'];

		$failures = min( count( self::SCANNER_IDS ), $open_failures );

		return (int) round( ( count( self::SCANNER_IDS ) - $failures ) / count( self::SCANNER_IDS ) * 100 );
	}

	/**
	 * The content analysis feature's input.
	 *
	 * @param \WP_Post $post                Post being analyzed.
	 * @param int|null $deterministic_score Already-known deterministic score, if any - given to the AI as context.
	 * @return array<string, mixed>
	 */
	private function build_context( \WP_Post $post, ?int $deterministic_score ): array {
		$context = array(
			'title'   => $post->post_title,
			'content' => wp_strip_all_tags( $post->post_content ),
		);

		if ( null !== $deterministic_score ) {
			$context['deterministic_score'] = $deterministic_score;
		}

		return $context;
	}

	/**
	 * @param \VuloPilot\AiAssistant\AIResponse $response Raw AI response.
	 * @return array{topic_authority: int, suggestions: string[]}
	 *
	 * @throws \RuntimeException If the response isn't usable JSON in the expected shape.
	 */
	private function parse_response( \VuloPilot\AiAssistant\AIResponse $response ): array {
		$content = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response->get_content() ) );
		$decoded = json_decode( trim( (string) $content ), true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( esc_html__( 'The AI did not return a usable Content Intelligence analysis.', 'vulopilot' ) );
		}

		$value = $decoded['topic_authority'] ?? null;

		if ( ! is_int( $value ) && ! ( is_numeric( $value ) && (string) (int) $value === (string) $value ) ) {
			throw new \RuntimeException( esc_html__( 'The AI response is missing a valid "topic_authority" score.', 'vulopilot' ) );
		}

		$suggestions = array_values(
			array_filter(
				is_array( $decoded['suggestions'] ?? null ) ? $decoded['suggestions'] : array(),
				static fn( $suggestion ) => is_string( $suggestion ) && '' !== trim( $suggestion )
			)
		);

		if ( empty( $suggestions ) ) {
			throw new \RuntimeException( esc_html__( 'The AI did not return any suggestions.', 'vulopilot' ) );
		}

		return array(
			'topic_authority' => max( 0, min( 100, (int) $value ) ),
			'suggestions'     => $suggestions,
		);
	}

	/**
	 * Simple, documented average - same "coarse, not scientific" posture
	 * GeoAnalyzer::calculate_overall_score() already takes.
	 *
	 * @param int|null $deterministic_score 0-100, or null.
	 * @param int      $topic_authority     0-100.
	 * @return int 0-100.
	 */
	private function calculate_overall_score( ?int $deterministic_score, int $topic_authority ): int {
		if ( null === $deterministic_score ) {
			return $topic_authority;
		}

		return (int) round( ( $deterministic_score + $topic_authority ) / 2 );
	}
}
