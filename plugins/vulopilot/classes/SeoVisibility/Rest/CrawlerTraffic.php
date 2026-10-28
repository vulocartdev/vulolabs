<?php
namespace VuloPilot\SeoVisibility\Rest;

use VuloPilot\SeoVisibility\CrawlerVisitRepository;
use VuloPilot\Utill\FindingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Backs src/pages/CrawlerTraffic/CrawlerTraffic.tsx (AI Crawler Traffic Monitoring,
 * readme.txt).
 *
 * @class       CrawlerTraffic controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class CrawlerTraffic extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'crawler-traffic';

	/**
	 * @inheritDoc
	 */
	public function register_routes() {
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/summary',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// GET-only, real current-vs-previous-period comparison.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/analytics',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Scoped to one post - backs the post editor's own AI Visibility (crawler activity) tab.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/post/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_stats' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
	}

	/**
	 * `GET /crawler-traffic/post/{post_id}` - real per-post bot-visit counts, scoped by the
	 * post's own permalink path (not a sitewide aggregate).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_stats( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'vulopilot_invalid_post', __( 'Post not found.', 'vulopilot' ), array( 'status' => 404 ) );
		}

		$path = wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
		$days = absint( $request->get_param( 'days' ) ) ? absint( $request->get_param( 'days' ) ) : 90;

		return rest_ensure_response( ( new CrawlerVisitRepository() )->get_stats_for_url( (string) $path, $days ) );
	}

	/**
	 * @inheritDoc
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_items( $request ) {
		$repository = new CrawlerVisitRepository();

		$result                    = $repository->find_all(
			array(
				'page'     => absint( $request->get_param( 'page' ) ) ? absint( $request->get_param( 'page' ) ) : 1,
				'per_page' => absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 20,
				'bot_name' => sanitize_text_field( (string) $request->get_param( 'bot_name' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'    => sanitize_key( (string) $request->get_param( 'order' ) ),
			)
		);
		$result['bot_name_counts'] = $repository->get_bot_counts();

		return rest_ensure_response( $result );
	}

	/**
	 * `GET /crawler-traffic/analytics` - real current-vs-previous-period comparison
	 * (CrawlerVisitRepository::get_period_comparison()) plus a real "by AI lab" breakdown
	 * and the real open blocked-pages count.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_analytics( $request ) {
		$days       = absint( $request->get_param( 'days' ) ) ? absint( $request->get_param( 'days' ) ) : 30;
		$repository = new CrawlerVisitRepository();
		$comparison = $repository->get_period_comparison( $days );

		$by_vendor = array();
		foreach ( $comparison['top_crawlers'] as $crawler ) {
			$vendor = $crawler['bot_name'];
			if ( preg_match( '/\(([^)]+)\)\s*$/', $crawler['bot_name'], $matches ) ) {
				$vendor = $matches[1];
			}
			$by_vendor[ $vendor ] = ( $by_vendor[ $vendor ] ?? 0 ) + $crawler['total'];
		}
		arsort( $by_vendor );

		$findings            = new FindingRepository();
		$blocked_pages_total = $findings->find_all(
			array(
				'scanner_id' => 'ai-crawler-blocked-pages',
				'status'     => 'open',
				'per_page'   => 1,
			)
		)['total'];

		// Same real weighted-severity formula Seo::calculate_score()/ Geo::calculate_score()
		// already use.
		$crawl_scanner_ids  = array( 'robots-txt', 'sitemap', 'sitemap-validation', 'ai-crawler-blocked-pages' );
		$crawl_health_score = $this->calculate_score( $findings->get_severity_breakdown_for_scanner_ids( $crawl_scanner_ids ) );

		return rest_ensure_response(
			array_merge(
				$comparison,
				array(
					'by_vendor'           => $by_vendor,
					'blocked_pages_total' => (int) $blocked_pages_total,
					'daily_volume'        => $repository->get_daily_volume( $days ),
					'crawl_health_score'  => $crawl_health_score,
				)
			)
		);
	}

	/**
	 * Same weighting `Seo::calculate_score()`/ `Geo::calculate_score()` already use.
	 *
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
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_summary( $request ) {
		$repository = new CrawlerVisitRepository();
		$days       = absint( $request->get_param( 'days' ) ) ? absint( $request->get_param( 'days' ) ) : 30;

		return rest_ensure_response(
			array(
				'bot_last_seen'      => $repository->get_bot_last_seen(),
				'most_crawled_pages' => $repository->get_most_crawled_pages(),
				'daily_volume'       => $repository->get_daily_volume( $days ),
			)
		);
	}
}
