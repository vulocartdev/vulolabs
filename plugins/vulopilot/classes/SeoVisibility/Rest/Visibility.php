<?php
namespace VuloPilot\SeoVisibility\Rest;

use VuloPilot\BrandVisibility\Rest\BrandIntelligence;
use VuloPilot\GeoAnalysis\Rest\Geo;
use VuloPilot\Utill\FindingRepository;
use VuloPilot\TechnicalSeo\Rest\Seo;
use VuloPilot\Settings\GoogleAnalyticsClient;
use VuloPilot\Settings\GoogleServicesConnection;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for "SEO & Visibility → Overview": a combined score across Brand/SEO/GEO/Crawl & URLs, read directly from each area's own controller method so the numbers can never disagree.
 *
 * @class       Visibility controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Visibility extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'visibility';

	/**
	 * Scanner ids behind each area's score, kept in sync manually with each source controller's own list (`content-freshness` excluded, same reasoning as GEO's own progress trend).
	 *
	 * @var array<string, string[]>
	 */
	private const AREA_SCANNER_IDS = array(
		'brand' => array( 'geo-trust-signals', 'about-page-analysis', 'geo-eeat-signals', 'geo-author-info', 'author-schema' ),
		'seo'   => array(
			'seo',
			'meta-description',
			'meta-description-duplication',
			'focus-keyword-audit',
			'heading-structure',
			'multiple-h1',
			'thin-content',
			'seo-images',
			'images',
			'internal-linking',
			'canonical-url',
			'duplicate-content',
			'orphan-pages',
			'open-graph',
			'twitter-card',
		),
		'geo'   => array(
			'geo-summary-block',
			'geo-faq-opportunity',
			'geo-citation-opportunities',
			'geo-chunking',
			'geo-semantic-structure',
			'geo-entity-naming-consistency',
			'geo-author-info',
			'geo-eeat-signals',
			'geo-trust-signals',
			'llms-txt-missing',
		),
		'crawl' => array( 'robots-txt', 'sitemap', 'sitemap-validation', 'ai-crawler-blocked-pages' ),
	);

	/**
	 * Real, human-facing label per area - same 4 areas the "Visibility
	 * Breakdown" table's own rows show.
	 *
	 * @var array<string, string>
	 */
	private const AREA_LABELS = array(
		'brand' => 'Brand Visibility',
		'seo'   => 'SEO',
		'geo'   => 'GEO (AI Visibility)',
		'crawl' => 'Crawl & URLs',
	);

	/**
	 * Same 7-day lookback every other real score delta in this codebase
	 * uses (`Seo::DELTA_LOOKBACK_DAYS`, `Geo::DELTA_LOOKBACK_DAYS`).
	 *
	 * @var int
	 */
	private const DELTA_LOOKBACK_DAYS = 7;

	/**
	 * Real day-range options "Visibility Trend"'s own period dropdown offers, same trio
	 * `Geo::ALLOWED_PROGRESS_DAYS` already uses.
	 *
	 * @var int[]
	 */
	private const ALLOWED_PROGRESS_DAYS = array( 7, 30, 90 );

	/**
	 * Real GA4 traffic-source lookback window for "Visibility by Source" -
	 * fixed rather than user-selectable (unlike "Visibility Trend"'s own
	 * 7/30/90 dropdown above) since this card has no period control of its
	 * own in the reference layout it matches.
	 *
	 * @var int
	 */
	private const TRAFFIC_SOURCE_WINDOW_DAYS = 30;

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
			'/' . $this->rest_base . '/traffic-sources',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_traffic_sources' ),
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
		$seo_data   = ( new Seo() )->get_score()->get_data();
		$geo_data   = ( new Geo() )->get_score()->get_data();
		$brand_data = ( new BrandIntelligence() )->get_score()->get_data();
		$crawl_data = ( new CrawlerTraffic() )->get_analytics( new \WP_REST_Request() )->get_data();

		$areas = array(
			'brand' => array(
				'label' => self::AREA_LABELS['brand'],
				'score' => (int) $brand_data['brand_score'],
			),
			'seo'   => array(
				'label' => self::AREA_LABELS['seo'],
				'score' => (int) $seo_data['seo_score'],
			),
			'geo'   => array(
				'label' => self::AREA_LABELS['geo'],
				'score' => (int) $geo_data['geo_score'],
			),
			'crawl' => array(
				'label' => self::AREA_LABELS['crawl'],
				'score' => (int) $crawl_data['crawl_health_score'],
			),
		);

		$findings = new FindingRepository();
		$as_of    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::DELTA_LOOKBACK_DAYS . ' days' ) );

		foreach ( self::AREA_SCANNER_IDS as $key => $scanner_ids ) {
			$previous_breakdown              = $findings->get_severity_breakdown_for_scanner_ids_as_of( $scanner_ids, $as_of );
			$areas[ $key ]['previous_score'] = $this->calculate_score( $previous_breakdown );
			$areas[ $key ]['change']         = $areas[ $key ]['score'] - $areas[ $key ]['previous_score'];
		}

		$visibility_score          = (int) round( array_sum( array_column( $areas, 'score' ) ) / count( $areas ) );
		$previous_visibility_score = (int) round( array_sum( array_column( $areas, 'previous_score' ) ) / count( $areas ) );

		return rest_ensure_response(
			array(
				'visibility_score'          => $visibility_score,
				'previous_visibility_score' => $previous_visibility_score,
				'change'                    => $visibility_score - $previous_visibility_score,
				'lookback_days'             => self::DELTA_LOOKBACK_DAYS,
				'areas'                     => $areas,
			)
		);
	}

	/**
	 * Same weighting every other real score in this codebase uses - logarithmic, not linear,
	 * per-tier penalty (see Dashboard::score_from_breakdown()'s own docblock for why: a linear
	 * count*weight penalty saturates to 0 after roughly a dozen high-severity findings).
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
	 * "Visibility Trend" - a real daily combined-score trend over `days` (7/30/90).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_progress( \WP_REST_Request $request ) {
		$days = (int) $request->get_param( 'days' );
		if ( ! in_array( $days, self::ALLOWED_PROGRESS_DAYS, true ) ) {
			$days = 30;
		}

		$findings = new FindingRepository();
		$all_ids  = array_values( array_unique( array_merge( ...array_values( self::AREA_SCANNER_IDS ) ) ) );

		$trend = array();
		for ( $days_ago = $days - 1; $days_ago >= 0; $days_ago-- ) {
			$breakdown = $findings->get_severity_breakdown_for_scanner_ids_as_of(
				$all_ids,
				gmdate( 'Y-m-d 23:59:59', strtotime( "-{$days_ago} days" ) )
			);

			$trend[] = array(
				'date'  => gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) ),
				'score' => $this->calculate_score( $breakdown ),
			);
		}

		return rest_ensure_response(
			array(
				'days'  => $days,
				'trend' => $trend,
			)
		);
	}

	/**
	 * "Visibility by Source": GA4 sessions grouped by `sessionDefaultChannelGroup` over the last `TRAFFIC_SOURCE_WINDOW_DAYS` days. `connected:
	 *
	 * @return \WP_REST_Response
	 */
	public function get_traffic_sources() {
		$connection = new GoogleServicesConnection();
		$status     = $connection->get_status();

		$response = array(
			'connected'      => false,
			'window_days'    => self::TRAFFIC_SOURCE_WINDOW_DAYS,
			'total_sessions' => 0,
			'sources'        => array(),
		);

		if ( ! $status['connected'] || '' === $status['ga4_property_id'] ) {
			return rest_ensure_response( $response );
		}

		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . ( self::TRAFFIC_SOURCE_WINDOW_DAYS - 1 ) . ' days' ) );

		$sessions_by_channel = ( new GoogleAnalyticsClient( $connection ) )->run_channel_group_report( $status['ga4_property_id'], $start_date, $end_date );

		if ( is_wp_error( $sessions_by_channel ) || empty( $sessions_by_channel ) ) {
			return rest_ensure_response( $response );
		}

		arsort( $sessions_by_channel );

		$total = array_sum( $sessions_by_channel );

		$response['connected']      = true;
		$response['total_sessions'] = $total;

		foreach ( $sessions_by_channel as $channel => $sessions ) {
			$response['sources'][] = array(
				'label'    => $channel,
				'sessions' => $sessions,
				'percent'  => $total > 0 ? (int) round( $sessions / $total * 100 ) : 0,
			);
		}

		return rest_ensure_response( $response );
	}
}
