<?php
/**
 * ContentIntelligence controller file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\ContentOptimization\Rest;

use VuloPilot\Utill\FindingRepository;
use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\ContentOptimization\Scanners\ReadabilityScanner;
use VuloPilot\SeoVisibility\OnPageAnalyzer;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the composite, deterministic "Content Score",
 * scoped to a fixed scanner_id list spanning content and seo categories,
 * using the same weighting as Controllers\Dashboard::calculate_category_score().
 *
 * @class       ContentIntelligence controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class ContentIntelligence extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'content-intelligence';

	/**
	 * Same scanner list ContentAnalyzer::SCANNER_IDS reads.
	 *
	 * @var string[]
	 */
	private const SCANNER_IDS = array( 'readability', 'thin-content', 'duplicate-content', 'heading-structure', 'internal-linking', 'orphan-pages' );

	/**
	 * Content-creation actions only. `generate-faq` is excluded since it
	 * appends to an existing post rather than creating new content.
	 *
	 * @var string[]
	 */
	private const CONTENT_CREATION_ACTION_IDS = array( 'generate-blog' );

	/**
	 * @inheritDoc
	 */
	public function register_routes() {
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/score',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_score' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
				),
			)
		);

		// "Content Quality" card (ContentQualityCard.tsx) - real, per-post
		// readability/completeness/structure for one selected piece of content.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/quality',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_quality' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
				),
			)
		);

		// "Content Stats" card (ContentStatsCard.tsx) - real Content-Created/Words-Generated
		// counts for one period.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Same manage_options gate every other VuloPilot REST route uses.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool
	 */
	public function get_score_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function get_score() {
		$breakdown = ( new FindingRepository() )->get_severity_breakdown_for_scanner_ids( self::SCANNER_IDS );

		$score = 100
			- ( 15 * log( 1 + $breakdown['critical'] ) )
			- ( 8 * log( 1 + $breakdown['high'] ) )
			- ( 3 * log( 1 + $breakdown['medium'] ) )
			- ( 1 * log( 1 + $breakdown['low'] ) );

		return rest_ensure_response(
			array(
				'score'              => (int) round( max( 0, min( 100, $score ) ) ),
				'severity_breakdown' => $breakdown,
			)
        );
    }

    /**
     * Real Flesch Reading Ease bands - the same scale
     * ReadabilityScanner's own docblock already documents (the formula's
     * original published scale, not an invented cutoff).
     *
     * @var array<int, string>
     */
    private const READABILITY_BANDS = array(
        90 => 'Very Easy',
        80 => 'Easy',
        70 => 'Fairly Easy',
        60 => 'Standard',
        50 => 'Fairly Difficult',
        30 => 'Difficult',
        0  => 'Very Confusing',
    );

    /**
     * Per-post quality signals: readability (Flesch reading ease),
     * completeness (OnPageAnalyzer's basic checks), and structure
     * (has_subheadings).
     *
     * @param \WP_REST_Request $request Full request object.
     * @return \WP_REST_Response
     */
    public function get_quality( $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $post    = $post_id ? get_post( $post_id ) : null;

        if ( ! $post ) {
            return new \WP_Error( 'vulopilot_post_not_found', __( 'Post not found.', 'vulopilot' ), array( 'status' => 404 ) );
        }

        $plain_text        = wp_strip_all_tags( $post->post_content );
        $readability_score = ( new ReadabilityScanner() )->calculate_flesch_reading_ease( $plain_text );

        $checks = ( new OnPageAnalyzer() )->analyze(
            array(
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'slug'    => $post->post_name,
            )
        );

        $basic_checks  = array_values(
            array_filter(
                $checks,
                static fn( array $check ): bool => 'basic' === $check['group']
            )
        );
        $passed_checks = array_values(
            array_filter(
                $basic_checks,
                static fn( array $check ): bool => 'pass' === $check['status']
            )
        );

        $structure_check = current(
            array_filter(
                $checks,
                static fn( array $check ): bool => 'has_subheadings' === $check['id']
            )
        );

        return rest_ensure_response(
            array(
                'post_id'      => $post_id,
                'readability'  => array(
                    'score' => $readability_score,
                    'label' => $this->readability_label( $readability_score ),
                ),
                'completeness' => array(
                    'passed' => count( $passed_checks ),
                    'total'  => count( $basic_checks ),
                    'checks' => $basic_checks,
                ),
                'structure'    => $structure_check ? $structure_check : null,
            )
        );
    }

    /**
     * Maps a real Flesch Reading Ease score to its published band label.
     *
     * @param int $score Real 0-100 Flesch Reading Ease score.
     * @return string
     */
    private function readability_label( int $score ): string {
        foreach ( self::READABILITY_BANDS as $floor => $label ) {
            if ( $score >= $floor ) {
                return $label;
            }
        }

        return self::READABILITY_BANDS[0];
    }

    /**
     * Fetches `/content-intelligence/stats` - Content Created/Words
     * Generated counts for one period (`date_from`/`date_to`, both Y-m-d;
     * defaults to the current calendar month), plus each metric's percent
     * change against the immediately preceding period of equal length.
     *
     * @param \WP_REST_Request $request Full request object.
     * @return \WP_REST_Response
     */
    public function get_stats( $request ) {
        $date_from    = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
        $date_to      = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
        $period_start = '' !== $date_from ? $date_from : gmdate( 'Y-m-01' );
        $period_end   = '' !== $date_to ? $date_to : gmdate( 'Y-m-d' );

        $runs = new ActionRunRepository();

        [ $previous_start, $previous_end ] = $this->get_previous_period( $period_start, $period_end );

        $current  = $runs->get_content_creation_stats_for_period( $period_start, $period_end, self::CONTENT_CREATION_ACTION_IDS );
        $previous = $runs->get_content_creation_stats_for_period( $previous_start, $previous_end, self::CONTENT_CREATION_ACTION_IDS );

        return rest_ensure_response(
            array(
                'content_created' => array(
                    'current'        => $current['content_created'],
                    'previous'       => $previous['content_created'],
                    'change_percent' => $this->calculate_change_percent( $current['content_created'], $previous['content_created'] ),
                ),
                'words_generated' => array(
                    'current'        => $current['words_generated'],
                    'previous'       => $previous['words_generated'],
                    'change_percent' => $this->calculate_change_percent( $current['words_generated'], $previous['words_generated'] ),
                ),
            )
        );
    }

    /**
     * The date range immediately preceding [$period_start, $period_end], of the same inclusive day length - same real trend math Reports\AbstractReportType::get_previous_period() already uses for the Reports page (duplicated as a small local method rather than shared, see this class's own register_routes() comment on why).
     *
     * @param string $period_start Y-m-d, inclusive.
     * @param string $period_end   Y-m-d, inclusive.
     * @return array{0: string, 1: string} [previous_start, previous_end], both Y-m-d.
     */
    private function get_previous_period( string $period_start, string $period_end ): array {
        $start = new \DateTimeImmutable( $period_start );
        $end   = new \DateTimeImmutable( $period_end );
        $days  = (int) $start->diff( $end )->days + 1;

        $previous_end   = $start->modify( '-1 day' );
        $previous_start = $previous_end->modify( '-' . ( $days - 1 ) . ' days' );

        return array( $previous_start->format( 'Y-m-d' ), $previous_end->format( 'Y-m-d' ) );
    }

    /**
     * Same real trend math Reports\AbstractReportType::calculate_change_percent() already uses.
     *
     * @param int|float $current  This period's value.
     * @param int|float $previous Previous period's value.
     * @return float|null Percentage change, or null when $previous is 0 (no meaningful percentage to show).
     */
    private function calculate_change_percent( $current, $previous ): ?float {
        if ( 0 == $previous ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- deliberately loose: catches both int 0 and float 0.0 from either metric type.
            return null;
        }

        return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
    }
}
