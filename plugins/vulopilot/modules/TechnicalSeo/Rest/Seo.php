<?php
/**
 * Seo controller file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\TechnicalSeo\Rest;

use VuloPilot\Utill\FindingRepository;
use VuloPilot\Utill\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the SEO tab: deterministic SEO score, progress trend,
 * pages needing attention, and per-page analysis. Score uses the same
 * weighted-severity formula as BrandIntelligence/ContentIntelligence.
 *
 * @class       Seo controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Seo extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'seo';

	/**
	 * Scanner ids grouped by the SEO tab's category sections.
	 *
	 * @var array<string, string[]>
	 */
	private const CATEGORY_SCANNER_IDS = array(
		'titles-meta'             => array(
			'seo',
			'meta-description',
			'meta-description-duplication',
			'focus-keyword-audit',
		),
		'content-structure'       => array(
			'heading-structure',
			'multiple-h1',
			'thin-content',
		),
		'images'                  => array( 'seo-images', 'images' ),
		'internal-linking'        => array( 'internal-linking' ),
		'indexability-canonicals' => array(
			'canonical-url',
			'duplicate-content',
			'orphan-pages',
		),
		'structured-data'         => array( 'open-graph', 'twitter-card' ),
	);

	/**
	 * Days back for the "since last week" delta comparison.
	 *
	 * @var int
	 */
	private const DELTA_LOOKBACK_DAYS = 7;

	/**
	 * Number of points plotted in the score-over-time chart.
	 *
	 * @var int
	 */
	private const PROGRESS_TREND_DAYS = 7;

	/**
	 * Day-range options the progress period toggle offers.
	 *
	 * @var int[]
	 */
	private const ALLOWED_PROGRESS_DAYS = array( 7, 30, 90 );

	/**
	 * Below this length a meta description is flagged "Too short".
	 *
	 * @var int
	 */
	private const MIN_META_DESCRIPTION_LENGTH = 50;

	/**
	 * Timeout for fetch_rendered_body()'s wp_remote_get() call.
	 *
	 * @var int
	 */
	private const PAGE_FETCH_TIMEOUT_SECONDS = 8;

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

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/progress',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_progress' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/pages-needing-attention',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pages_needing_attention' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/analyze-page',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page_analysis' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ),
						),
					),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/post-score',
			array(
				array(
					// CREATABLE (POST) too, same as post-seo/{id}/analyze - the frontend posts the
					// editor's own live (possibly-unsaved) title/content/excerpt/slug/focus_keyword
					// as the body so this score reacts to typing, not just to the saved post.
					'methods'             => array( \WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE ),
					'callback'            => array( $this, 'get_post_score' ),
					'permission_callback' => array( $this, 'get_score_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'validate_callback' => static fn( $value ): bool => is_numeric( $value ),
						),
					),
				),
			)
		);
	}

	/**
	 * Block-editor sidebar's SEO score badge, using the same calculate_score() formula
	 * scoped to one post.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	/**
	 * VuloPilot's own SEO Score Engine (`SeoScoreEngine`) - 6 categories, 28 rules, weights summing to exactly 100, run against this post's current (possibly live/unsaved) field values.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_score( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error(
				'vulopilot_page_not_found',
				__( 'That page could not be found.', 'vulopilot' ),
				array( 'status' => 404 )
			);
		}

		// Live, possibly-unsaved editor state - same override pattern analyze_post() already
		// supports, so the toolbar score pill reacts to typing, not just to the last save.
		$seo_title     = trim( (string) get_post_meta( $post_id, \VuloPilot\SeoVisibility\PostSeoMetaFields::META_KEYS['seo_title'], true ) );
		$focus_keyword = (string) get_post_meta( $post_id, \VuloPilot\SeoVisibility\PostSeoMetaFields::META_KEYS['focus_keyword'], true );

		$live = array(
			'title'         => (string) ( $request->get_param( 'title' ) ?? ( '' !== $seo_title ? $seo_title : $post->post_title ) ),
			'content'       => (string) ( $request->get_param( 'content' ) ?? $post->post_content ),
			'excerpt'       => (string) ( $request->get_param( 'excerpt' ) ?? $post->post_excerpt ),
			'slug'          => (string) ( $request->get_param( 'slug' ) ?? $post->post_name ),
			'focus_keyword' => (string) ( $request->get_param( 'focus_keyword' ) ?? $focus_keyword ),
		);

		$engine  = new \VuloPilot\SeoVisibility\SeoScoreEngine();
		$rules   = $engine->evaluate( $post, $live, (bool) $request->get_param( 'live_only' ) );
		$summary = $engine->calculate_score( $rules );

		return rest_ensure_response(
			array(
				// Backward-compatible top-level field the toolbar/sidebar badge already reads -
				// null (insufficient data) reported as 0 rather than breaking that number contract.
				'score'                => $summary['score'] ?? 0,
				'ruleset_version'      => \VuloPilot\SeoVisibility\SeoScoreEngine::RULESET_VERSION,
				'insufficient_data'    => $summary['insufficient_data'],
				'coverage_percent'     => $summary['coverage_percent'],
				'evaluated_count'      => $summary['evaluated_count'],
				'unknown_count'        => $summary['unknown_count'],
				'not_applicable_count' => $summary['not_applicable_count'],
				'categories'           => $summary['categories'],
				'rules'                => $rules,
			)
		);
	}

	/**
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
		$findings          = new FindingRepository();
		$all_scanner_ids   = array_merge( ...array_values( self::CATEGORY_SCANNER_IDS ) );
		$overall_breakdown = $findings->get_severity_breakdown_for_scanner_ids( $all_scanner_ids );

		$category_scores = array();
		foreach ( self::CATEGORY_SCANNER_IDS as $key => $scanner_ids ) {
			$category_breakdown = $findings->get_severity_breakdown_for_scanner_ids( $scanner_ids );

			$category_scores[ $key ] = array(
				'score'          => $this->calculate_score( $category_breakdown ),
				'open_count'     => array_sum( $category_breakdown ),
				'affected_pages' => $findings->get_affected_object_count_for_scanner_ids( $scanner_ids ),
				'trend'          => $this->get_category_trend( $findings, $scanner_ids ),
			);
		}

		$as_of              = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::DELTA_LOOKBACK_DAYS . ' days' ) );
		$previous_breakdown = $findings->get_severity_breakdown_for_scanner_ids_as_of( $all_scanner_ids, $as_of );

		return rest_ensure_response(
			array(
				'seo_score'          => $this->calculate_score( $overall_breakdown ),
				'pages_checked'      => $this->get_pages_checked(),
				'category_scores'    => $category_scores,
				'severity_breakdown' => $overall_breakdown,
				'total_open'         => array_sum( $overall_breakdown ),
				'deltas'             => array(
					'lookback_days' => self::DELTA_LOOKBACK_DAYS,
					'total_open'    => array_sum( $overall_breakdown ) - array_sum( $previous_breakdown ),
					'critical'      => $overall_breakdown['critical'] - ( $previous_breakdown['critical'] ?? 0 ),
					'high'          => $overall_breakdown['high'] - ( $previous_breakdown['high'] ?? 0 ),
				),
			)
		);
	}

	/**
	 * Published post/page count, matching the scope SeoScanner::run() scans.
	 *
	 * @return int
	 */
	private function get_pages_checked(): int {
		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );

		return (int) ( $posts->publish ?? 0 ) + (int) ( $pages->publish ?? 0 );
	}

	/**
	 * Daily score trend for one SEO category, over PROGRESS_TREND_DAYS days.
	 *
	 * @param FindingRepository $findings    Shared repository instance.
	 * @param string[]          $scanner_ids Scanner ids for this category.
	 * @return int[] Scores, oldest first.
	 */
	private function get_category_trend( FindingRepository $findings, array $scanner_ids ): array {
		$trend = array();

		for ( $days_ago = self::PROGRESS_TREND_DAYS - 1; $days_ago >= 0; $days_ago-- ) {
			$breakdown = $findings->get_severity_breakdown_for_scanner_ids_as_of(
				$scanner_ids,
				gmdate( 'Y-m-d 23:59:59', strtotime( "-{$days_ago} days" ) )
			);

			$trend[] = $this->calculate_score( $breakdown );
		}

		return $trend;
	}

	/**
	 * @param array{critical: int, high: int, medium: int, low: int} $breakdown Severity breakdown to score.
	 * @return int 0-100.
	 */
	private function calculate_score( array $breakdown ): int {
		$score = 100
			- ( 15 * log( 1 + $breakdown['critical'] ) )
			- ( 8 * log( 1 + $breakdown['high'] ) )
			- ( 3 * log( 1 + $breakdown['medium'] ) )
			- ( 1 * log( 1 + $breakdown['low'] ) );

		return (int) round( max( 0, min( 100, $score ) ) );
	}

	/**
	 * Pages that need attention, for the "What should I fix first?" section.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_pages_needing_attention() {
		$findings        = new FindingRepository();
		$all_scanner_ids = array_merge( ...array_values( self::CATEGORY_SCANNER_IDS ) );

		$current_buckets = $findings->get_open_findings_for_scanner_ids_by_post( $all_scanner_ids );

		$as_of         = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::DELTA_LOOKBACK_DAYS . ' days' ) );
		$as_of_buckets = $findings->get_open_findings_for_scanner_ids_by_post( $all_scanner_ids, $as_of );

		$severity_rank = array_flip( Severity::all() );
		$rows          = array();

		foreach ( $current_buckets as $post_id => $post_findings ) {
			$post = get_post( $post_id );

			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$breakdown = array_fill_keys( array( 'critical', 'high', 'medium', 'low' ), 0 );
			foreach ( $post_findings as $finding ) {
				if ( array_key_exists( $finding['severity'], $breakdown ) ) {
					++$breakdown[ $finding['severity'] ];
				}
			}

			usort(
				$post_findings,
				static fn( $a, $b ) => ( $severity_rank[ $a['severity'] ] ?? 99 ) <=> ( $severity_rank[ $b['severity'] ] ?? 99 )
			);

			$as_of_breakdown = array_fill_keys( array( 'critical', 'high', 'medium', 'low' ), 0 );
			foreach ( ( $as_of_buckets[ $post_id ] ?? array() ) as $finding ) {
				if ( array_key_exists( $finding['severity'], $as_of_breakdown ) ) {
					++$as_of_breakdown[ $finding['severity'] ];
				}
			}

			$score = $this->calculate_score( $breakdown );

			$rows[] = array(
				'post_id'      => $post_id,
				'title'        => get_the_title( $post ),
				'edit_link'    => (string) get_edit_post_link( $post_id, 'raw' ),
				'permalink'    => (string) get_permalink( $post_id ),
				'score'        => $score,
				'issues'       => count( $post_findings ),
				'main_problem' => $post_findings[0]['title'],
				'change'       => $score - $this->calculate_score( $as_of_breakdown ),
			);
		}

		usort( $rows, static fn( $a, $b ) => $a['score'] <=> $b['score'] );

		return rest_ensure_response(
			array(
				'data'  => $rows,
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * Daily score trend plus week-over-week counters for the progress card.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_progress( \WP_REST_Request $request ) {
		$days = (int) $request->get_param( 'days' );
		if ( ! in_array( $days, self::ALLOWED_PROGRESS_DAYS, true ) ) {
			$days = self::PROGRESS_TREND_DAYS;
		}

		$findings        = new FindingRepository();
		$all_scanner_ids = array_merge( ...array_values( self::CATEGORY_SCANNER_IDS ) );

		$trend = array();
		for ( $days_ago = $days - 1; $days_ago >= 0; $days_ago-- ) {
			$breakdown = $findings->get_severity_breakdown_for_scanner_ids_as_of(
				$all_scanner_ids,
				gmdate( 'Y-m-d 23:59:59', strtotime( "-{$days_ago} days" ) )
			);

			$trend[] = array(
				'date'  => gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) ),
				'score' => $this->calculate_score( $breakdown ),
			);
		}

		// Counters scale with $days (7/30/90).
		$now             = gmdate( 'Y-m-d H:i:s' );
		$period_ago      = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$two_periods_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $days * 2 ) . ' days' ) );

		$issues_fixed_this_week = $findings->count_resolved_between( $period_ago, $now, null, $all_scanner_ids );
		$issues_fixed_last_week = $findings->count_resolved_between( $two_periods_ago, $period_ago, null, $all_scanner_ids );

		// Two equal-length, back-to-back windows.
		$new_issues_this_week = $findings->get_stats_for_period(
			gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) ),
			gmdate( 'Y-m-d' ),
			null,
			$all_scanner_ids
		)['total'];
		$new_issues_last_week = $findings->get_stats_for_period(
			gmdate( 'Y-m-d', strtotime( '-' . ( ( $days * 2 ) - 1 ) . ' days' ) ),
			gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
			null,
			$all_scanner_ids
		)['total'];

		$pages_improved_this_week = $this->count_pages_with_improved_score(
			$findings->get_open_findings_for_scanner_ids_by_post( $all_scanner_ids ),
			$findings->get_open_findings_for_scanner_ids_by_post( $all_scanner_ids, $period_ago )
		);
		$pages_improved_last_week = $this->count_pages_with_improved_score(
			$findings->get_open_findings_for_scanner_ids_by_post( $all_scanner_ids, $period_ago ),
			$findings->get_open_findings_for_scanner_ids_by_post( $all_scanner_ids, $two_periods_ago )
		);

		return rest_ensure_response(
			array(
				'days'           => $days,
				'trend'          => $trend,
				'issues_fixed'   => array(
					'this_week' => $issues_fixed_this_week,
					'delta'     => $issues_fixed_this_week - $issues_fixed_last_week,
				),
				'new_issues'     => array(
					'this_week' => $new_issues_this_week,
					'delta'     => $new_issues_this_week - $new_issues_last_week,
				),
				'pages_improved' => array(
					'this_week' => $pages_improved_this_week,
					'delta'     => $pages_improved_this_week - $pages_improved_last_week,
				),
			)
		);
	}

	/**
	 * How many published pages/posts scored better in $current than in $previous.
	 *
	 * @param array<int, array<int, array{id: int, title: string.
	 * @param array<int, array<int, array{id: int, title: string, severity: string}>> $previous Same shape.
	 * @return int
	 */
	private function count_pages_with_improved_score( array $current, array $previous ): int {
		$score_by_post_id = function ( array $buckets ): array {
			$scores = array();

			foreach ( $buckets as $post_id => $post_findings ) {
				$breakdown = array_fill_keys( array( 'critical', 'high', 'medium', 'low' ), 0 );

				foreach ( $post_findings as $finding ) {
					if ( array_key_exists( $finding['severity'], $breakdown ) ) {
						++$breakdown[ $finding['severity'] ];
					}
				}

				$scores[ $post_id ] = $this->calculate_score( $breakdown );
			}

			return $scores;
		};

		$current_scores  = $score_by_post_id( $current );
		$previous_scores = $score_by_post_id( $previous );

		$post_ids = array_unique( array_merge( array_keys( $current_scores ), array_keys( $previous_scores ) ) );
		$improved = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$current_score  = $current_scores[ $post_id ] ?? 100;
			$previous_score = $previous_scores[ $post_id ] ?? 100;

			if ( $current_score > $previous_score ) {
				++$improved;
			}
		}

		return $improved;
	}

	/**
	 * On-demand per-page check runner for the "Analyze" row action.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_page_analysis( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error(
				'vulopilot_page_not_found',
				__( 'That page could not be found.', 'vulopilot' ),
				array( 'status' => 404 )
			);
		}

		$permalink = (string) get_permalink( $post );
		$body      = $this->fetch_rendered_body( $permalink );

		return rest_ensure_response(
			array(
				'post_id'          => $post_id,
				'title'            => get_the_title( $post ),
				'permalink'        => $permalink,
				'meta_description' => $post->post_excerpt,
				'analyzed_at'      => current_time( 'mysql', true ),
				// check_featured_image()/check_orphan_page() return null when their setting is off.
				'checks'           => array_values(
					array_filter(
						array(
							$this->check_title_tag( $post ),
							$this->check_meta_description( $post ),
							$this->check_h1_heading( $post ),
							$this->check_headings( $post ),
							$this->check_content_length( $post ),
							$this->check_images_alt_text( $post ),
							$this->check_featured_image( $post ),
							$this->check_broken_links( $post_id ),
							$this->check_orphan_page( $post_id ),
							$this->check_canonical( $body ),
							$this->check_indexability( $post ),
							$this->check_structured_data( $body ),
							$this->check_social_metadata( $body ),
						)
					)
				),
			)
		);
	}

	/**
	 * Checks the title is unique in the database, same query DuplicateContentScanner uses.
	 *
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_title_tag( \WP_Post $post ): array {
		$label = __( 'Title Tag', 'vulopilot' );
		$title = trim( get_the_title( $post ) );

		if ( '' === $title ) {
			return $this->build_check( 'title_tag', $label, 'fail', __( 'Missing title tag', 'vulopilot' ) );
		}

		global $wpdb;
		$duplicate_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s AND post_status = 'publish' AND post_type IN ('post', 'page') AND ID != %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$title,
				$post->ID
			)
		);

		if ( $duplicate_count > 0 ) {
			return $this->build_check( 'title_tag', $label, 'fail', __( 'Duplicate title - shared with another published page', 'vulopilot' ) );
		}

		return $this->build_check( 'title_tag', $label, 'pass', __( 'Title tag is unique and set', 'vulopilot' ) );
	}

	/**
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_meta_description( \WP_Post $post ): array {
		$label   = __( 'Meta Description', 'vulopilot' );
		$excerpt = trim( $post->post_excerpt );
		$length  = strlen( $excerpt );

		if ( '' === $excerpt ) {
			return $this->build_check( 'meta_description', $label, 'fail', __( 'Missing meta description', 'vulopilot' ) );
		}

		if ( $length < self::MIN_META_DESCRIPTION_LENGTH ) {
			return $this->build_check(
				'meta_description',
				$label,
				'warn',
				sprintf(
					/* translators: %d: real current character count. */
					__( 'Too short (%d characters)', 'vulopilot' ),
					$length
				)
			);
		}

		return $this->build_check( 'meta_description', $label, 'pass', __( 'Meta description is set', 'vulopilot' ) );
	}

	/**
	 * Checks for an h1 tag anywhere in post_content.
	 *
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_h1_heading( \WP_Post $post ): array {
		$label = __( 'H1 Heading', 'vulopilot' );

		if ( preg_match( '/<h1[\s>]/i', $post->post_content ) ) {
			return $this->build_check( 'h1_heading', $label, 'pass', __( 'H1 tag found', 'vulopilot' ) );
		}

		return $this->build_check( 'h1_heading', $label, 'fail', __( 'H1 tag not found', 'vulopilot' ) );
	}

	/**
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_headings( \WP_Post $post ): array {
		$label = __( 'Subheadings', 'vulopilot' );

		if ( preg_match( '/<h[2-6][\s>]/i', $post->post_content ) ) {
			return $this->build_check( 'headings', $label, 'pass', __( 'H2, H3+ tags found', 'vulopilot' ) );
		}

		return $this->build_check( 'headings', $label, 'warn', __( 'No subheadings found', 'vulopilot' ) );
	}

	/**
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_content_length( \WP_Post $post ): array {
		$label             = __( 'Content', 'vulopilot' );
		$settings          = wp_parse_args( get_option( \VuloPilot\Utill::VULOPILOT_SETTINGS_KEY, array() ), \VuloPilot\Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$threshold_setting = absint( $settings['thin_content_word_threshold'] ?? 300 );
		$min_words         = $threshold_setting > 0 ? $threshold_setting : 300;
		$word_count        = str_word_count( wp_strip_all_tags( $post->post_content ) );

		if ( $word_count < $min_words ) {
			return $this->build_check(
				'content',
				$label,
				'fail',
				sprintf(
					/* translators: 1: real word count, 2: real minimum recommended word count. */
					__( 'Thin content (%1$d words, recommended %2$d+)', 'vulopilot' ),
					$word_count,
					$min_words
				)
			);
		}

		return $this->build_check( 'content', $label, 'pass', __( 'Word count is good', 'vulopilot' ) );
	}

	/**
	 * Checks each img tag in post_content for alt text.
	 *
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_images_alt_text( \WP_Post $post ): array {
		$label = __( 'Images', 'vulopilot' );

		if ( ! preg_match_all( '/<img\b[^>]*>/i', $post->post_content, $matches ) ) {
			return $this->build_check( 'images', $label, 'pass', __( 'No images in this content', 'vulopilot' ) );
		}

		$missing = 0;

		foreach ( $matches[0] as $img_tag ) {
			$has_alt = preg_match( '/\balt\s*=\s*"([^"]*)"/i', $img_tag, $alt_match )
				|| preg_match( "/\balt\s*=\s*'([^']*)'/i", $img_tag, $alt_match );

			if ( ! $has_alt || '' === trim( $alt_match[1] ) ) {
				++$missing;
			}
		}

		if ( $missing > 0 ) {
			return $this->build_check(
				'images',
				$label,
				'fail',
				sprintf(
					/* translators: %d: real number of images missing alt text on this page. */
					_n( '%d image missing alt text', '%d images missing alt text', $missing, 'vulopilot' ),
					$missing
				)
			);
		}

		return $this->build_check( 'images', $label, 'pass', __( 'All images have alt text', 'vulopilot' ) );
	}

	/**
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}|null
	 */
	private function check_featured_image( \WP_Post $post ): ?array {
		$settings = wp_parse_args( get_option( \VuloPilot\Utill::VULOPILOT_SETTINGS_KEY, array() ), \VuloPilot\Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['flag_missing_featured_image'] ) ) {
			return null;
		}

		$label = __( 'Featured Image', 'vulopilot' );

		if ( has_post_thumbnail( $post ) ) {
			return $this->build_check( 'featured_image', $label, 'pass', __( 'Featured image is set', 'vulopilot' ) );
		}

		return $this->build_check( 'featured_image', $label, 'fail', __( 'No featured image set', 'vulopilot' ) );
	}

	/**
	 * Reads stored broken-links findings scoped to this page.
	 *
	 * @param int $post_id Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_broken_links( int $post_id ): array {
		$label    = __( 'Broken Links', 'vulopilot' );
		$findings = new FindingRepository();
		$broken   = $findings->find_all(
			array(
				'scanner_id'  => 'broken-links',
				'status'      => 'open',
				'object_type' => 'post',
				'object_ref'  => (string) $post_id,
				'per_page'    => 100,
			)
		);

		$count = count( $broken['data'] ?? array() );

		if ( $count > 0 ) {
			return $this->build_check(
				'broken_links',
				$label,
				'fail',
				sprintf(
					/* translators: %d: real number of broken links found on this page by the Broken Links scanner. */
					_n( '%d broken internal link found', '%d broken internal links found', $count, 'vulopilot' ),
					$count
				)
			);
		}

		return $this->build_check( 'broken_links', $label, 'pass', __( 'No broken links found', 'vulopilot' ) );
	}

	/**
	 * Reads the stored orphan-pages finding instead of re-running the scan.
	 *
	 * @param int $post_id Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}|null
	 */
	private function check_orphan_page( int $post_id ): ?array {
		$settings = wp_parse_args( get_option( \VuloPilot\Utill::VULOPILOT_SETTINGS_KEY, array() ), \VuloPilot\Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['flag_orphan_pages'] ) ) {
			return null;
		}

		$label    = __( 'Orphan Page', 'vulopilot' );
		$findings = new FindingRepository();
		$orphan   = $findings->find_all(
			array(
				'scanner_id'  => 'orphan-pages',
				'status'      => 'open',
				'object_type' => 'post',
				'object_ref'  => (string) $post_id,
				'per_page'    => 1,
			)
		);

		if ( count( $orphan['data'] ?? array() ) > 0 ) {
			return $this->build_check(
				'orphan_page',
				$label,
				'fail',
				__( '0 internal links point to this page - nothing else links to it', 'vulopilot' )
			);
		}

		return $this->build_check( 'orphan_page', $label, 'pass', __( 'At least one other page links to this page', 'vulopilot' ) );
	}

	/**
	 * @param string|null $body Fetched HTML, or null if the fetch failed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_canonical( ?string $body ): array {
		$label = __( 'Canonical', 'vulopilot' );

		if ( null === $body ) {
			return $this->build_check( 'canonical', $label, 'warn', __( 'Could not fetch this page to check', 'vulopilot' ) );
		}

		if ( false !== stripos( $body, 'rel="canonical"' ) || false !== stripos( $body, "rel='canonical'" ) ) {
			return $this->build_check( 'canonical', $label, 'pass', __( 'Canonical is set', 'vulopilot' ) );
		}

		return $this->build_check( 'canonical', $label, 'fail', __( 'No canonical URL tag found', 'vulopilot' ) );
	}

	/**
	 * @param \WP_Post $post Page being analyzed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_indexability( \WP_Post $post ): array {
		$label = __( 'Indexability', 'vulopilot' );

		if ( 'publish' !== $post->post_status ) {
			return $this->build_check( 'indexability', $label, 'fail', __( 'Not published', 'vulopilot' ) );
		}

		if ( '1' !== (string) get_option( 'blog_public' ) ) {
			return $this->build_check( 'indexability', $label, 'fail', __( 'Site is set to discourage search engines (Settings → Reading)', 'vulopilot' ) );
		}

		return $this->build_check( 'indexability', $label, 'pass', __( 'Content is indexed', 'vulopilot' ) );
	}

	/**
	 * @param string|null $body Fetched HTML, or null if the fetch failed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_structured_data( ?string $body ): array {
		$label = __( 'Structured Data', 'vulopilot' );

		if ( null === $body ) {
			return $this->build_check( 'structured_data', $label, 'warn', __( 'Could not fetch this page to check', 'vulopilot' ) );
		}

		if ( false !== stripos( $body, 'application/ld+json' ) ) {
			return $this->build_check( 'structured_data', $label, 'pass', __( 'Structured data (JSON-LD) found', 'vulopilot' ) );
		}

		return $this->build_check( 'structured_data', $label, 'warn', __( 'No schema detected', 'vulopilot' ) );
	}

	/**
	 * @param string|null $body Fetched HTML, or null if the fetch failed.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function check_social_metadata( ?string $body ): array {
		$label = __( 'Social Metadata', 'vulopilot' );

		if ( null === $body ) {
			return $this->build_check( 'social_metadata', $label, 'warn', __( 'Could not fetch this page to check', 'vulopilot' ) );
		}

		$required = array( 'og:title', 'og:description', 'og:image' );
		$missing  = array();

		foreach ( $required as $property ) {
			if ( false === stripos( $body, 'property="' . $property . '"' ) && false === stripos( $body, "property='" . $property . "'" ) ) {
				$missing[] = $property;
			}
		}

		if ( empty( $missing ) ) {
			return $this->build_check( 'social_metadata', $label, 'pass', __( 'Open Graph tags found', 'vulopilot' ) );
		}

		return $this->build_check(
			'social_metadata',
			$label,
			'warn',
			sprintf(
				/* translators: %s: real comma-separated list of missing Open Graph properties. */
				__( 'Open Graph tags missing: %s', 'vulopilot' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Builds one row of the `checks` array get_page_analysis() returns.
	 *
	 * @param string $key   Stable machine key for this check.
	 * @param string $label Human-readable check name.
	 * @param string $status One of 'pass'/'warn'/'fail'.
	 * @param string $message Specific finding message.
	 * @return array{key: string, label: string, status: string, message: string}
	 */
	private function build_check( string $key, string $label, string $status, string $message ): array {
		return array(
			'key'     => $key,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Fetches the page once, shared by the canonical, structured data and social metadata
	 * checks.
	 *
	 * @param string $url Real permalink to fetch.
	 * @return string|null Response body, or null if the request failed.
	 */
	private function fetch_rendered_body( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => self::PAGE_FETCH_TIMEOUT_SECONDS,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}
}
