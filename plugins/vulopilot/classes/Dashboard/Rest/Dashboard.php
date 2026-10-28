<?php
namespace VuloPilot\Dashboard\Rest;

use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\AiAssistant\AiHistoryRepository;
use VuloPilot\Automations\AutomationsRepository;
use VuloPilot\Utill\FindingRepository;
use VuloPilot\Utill\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for `/dashboard`: one aggregate payload for the Dashboard page's widgets (src/dashboard-widgets/registry.ts's DashboardSummary), using cheap COUNT/GROUP BY queries.
 *
 * @class       Dashboard controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Dashboard extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'dashboard';

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
		$findings        = new FindingRepository();
		$automations     = new AutomationsRepository();
		$action_runs     = new ActionRunRepository();
		$ai_usage        = $this->build_ai_usage_this_month();
		$category_scores = $this->build_category_scores( $findings );

		return rest_ensure_response(
			array(
				'overall_score'            => $this->calculate_overall_score( $category_scores ),
				'open_findings'            => $this->count_open_findings( $findings ),
				'critical_findings'        => $findings->count_by_severity( Severity::CRITICAL ),
				'findings_by_severity'     => $this->build_findings_by_severity( $findings ),
				'active_automations'       => $automations->count_enabled(),
				'ai_jobs_used'             => $ai_usage['ai_jobs_used'],
				'ai_jobs_quota'            => $ai_usage['ai_jobs_quota'],
				'category_scores'          => $category_scores,
				'psi_speed_scores'         => $this->build_psi_speed_scores(),
				'category_scores_7d_ago'   => $this->build_category_scores_as_of( $findings, gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ),
				'new_findings_this_week'   => $findings->count_created_since( gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ),
				'fixed_findings_this_week' => $findings->count_resolved_since( gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ),
				'quick_fixes'              => $this->count_quick_fixes( $findings ),
				'pending_approvals'        => (int) $action_runs->find_all(
					array(
						'status'   => 'pending_approval',
						'per_page' => 1,
					)
				)['total'],
				'automation_status'        => $automations->get_status_counts(),
				'site_snapshot'            => $this->build_site_snapshot(),
			)
		);
	}

	/**
	 * "Site snapshot" - counts read directly from WordPress core functions, not from scan
	 * findings.
	 *
	 * @return array{posts: int, pages: int, comments: int, users: int, plugins_active: int, plugins_total: int, wp_version: string, php_version: string}
	 */
	private function build_site_snapshot(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$post_counts    = wp_count_posts( 'post' );
		$page_counts    = wp_count_posts( 'page' );
		$comment_counts = wp_count_comments();
		$user_counts    = count_users();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		return array(
			'posts'          => (int) ( $post_counts->publish ?? 0 ),
			'pages'          => (int) ( $page_counts->publish ?? 0 ),
			'comments'       => (int) ( $comment_counts->approved ?? 0 ),
			'users'          => (int) ( $user_counts['total_users'] ?? 0 ),
			'plugins_active' => count( $active_plugins ),
			'plugins_total'  => count( get_plugins() ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
	}

	/**
	 * Real AI usage for the current calendar month, read from `vulopilot_ai_history`.
	 *
	 * @return array{ai_jobs_used: int, ai_jobs_quota: int}
	 */
	private function build_ai_usage_this_month(): array {
		$stats = ( new AiHistoryRepository() )->get_stats_for_period( gmdate( 'Y-m-01' ), gmdate( 'Y-m-d' ) );

		return array(
			'ai_jobs_used'  => $stats['total_calls'],
			'ai_jobs_quota' => 0,
		);
	}

	/**
	 * Per-domain widget scores (SEO/Performance/Security/Accessibility/ Commerce).
	 *
	 * @param FindingRepository $findings Repository to read category breakdowns from.
	 * @return array<string, int|null> Category id => 0-100 score.
	 */
	private function build_category_scores( FindingRepository $findings ): array {
		$categories = array( 'seo', 'performance', 'security', 'accessibility', 'geo' );
		$scores     = array();

		foreach ( $categories as $category ) {
			$scores[ $category ] = $this->calculate_category_score( $findings, $category );
		}

		$scores['woocommerce'] = class_exists( 'WooCommerce' )
			? $this->calculate_category_score( $findings, 'woocommerce' )
			: null;

		$scores['content'] = $this->calculate_content_score( $findings );
		$scores['brand']   = $this->calculate_brand_score( $findings );

		return $scores;
	}

	/**
	 * Same 8 keys/same weighting as build_category_scores().
	 *
	 * @param FindingRepository $findings Repository to read category breakdowns from.
	 * @param string            $as_of    MySQL datetime (UTC) to reconstruct every category's open set as of.
	 * @return array<string, int|null> Category id => 0-100 score.
	 */
	private function build_category_scores_as_of( FindingRepository $findings, string $as_of ): array {
		$categories = array( 'seo', 'performance', 'security', 'accessibility', 'geo' );
		$scores     = array();

		foreach ( $categories as $category ) {
			$breakdown           = $findings->get_severity_breakdown_for_category_as_of( $category, $as_of );
			$scores[ $category ] = $this->score_from_breakdown( $breakdown );
		}

		$scores['woocommerce'] = class_exists( 'WooCommerce' )
			? $this->score_from_breakdown( $findings->get_severity_breakdown_for_category_as_of( 'woocommerce', $as_of ) )
			: null;

		$scores['content'] = $this->score_from_breakdown(
			$findings->get_severity_breakdown_for_scanner_ids_as_of(
				array( 'readability', 'thin-content', 'duplicate-content', 'heading-structure', 'internal-linking', 'orphan-pages' ),
				$as_of
			)
		);

		$scores['brand'] = $this->score_from_breakdown(
			$findings->get_severity_breakdown_for_scanner_ids_as_of(
				array(
					'geo-trust-signals',
					'about-page-analysis',
					'geo-eeat-signals',
					'geo-author-info',
					'author-schema',
					'geo-entity-naming-consistency',
					'organization-schema',
				),
				$as_of
			)
		);

		return $scores;
	}

	/**
	 * Shared weighting formula for the overall, category, content and brand scores.
	 * Uses log(1 + n) instead of a linear penalty, so one category with many findings
	 * doesn't flatten the whole score to 0.
	 *
	 * @param array{critical: int, high: int, medium: int, low: int} $breakdown Severity counts to score.
	 * @return int 0-100.
	 */
	private function score_from_breakdown( array $breakdown ): int {
		$score = 100
			- ( 15 * log( 1 + $breakdown['critical'] ) )
			- ( 8 * log( 1 + $breakdown['high'] ) )
			- ( 3 * log( 1 + $breakdown['medium'] ) )
			- ( 1 * log( 1 + $breakdown['low'] ) );

		return (int) round( max( 0, min( 100, $score ) ) );
	}

	/**
	 * Same weighting as calculate_category_score()/calculate_content_score(), scoped to
	 * Brand Intelligence's own combined scanner_id list.
	 *
	 * @param FindingRepository $findings Repository to read the breakdown from.
	 * @return int 0-100.
	 */
	private function calculate_brand_score( FindingRepository $findings ): int {
		return $this->score_from_breakdown(
			$findings->get_severity_breakdown_for_scanner_ids(
				array(
					'geo-trust-signals',
					'about-page-analysis',
					'geo-eeat-signals',
					'geo-author-info',
					'author-schema',
					'geo-entity-naming-consistency',
					'organization-schema',
				)
			)
		);
	}

	/**
	 * Same weighting as calculate_category_score()/calculate_overall_score(), scoped to
	 * Content Intelligence's own fixed scanner_id list.
	 *
	 * @param FindingRepository $findings Repository to read the breakdown from.
	 * @return int 0-100.
	 */
	private function calculate_content_score( FindingRepository $findings ): int {
		return $this->score_from_breakdown(
			$findings->get_severity_breakdown_for_scanner_ids(
				array( 'readability', 'thin-content', 'duplicate-content', 'heading-structure', 'internal-linking', 'orphan-pages' )
			)
		);
	}

	/**
	 * Same weighting as calculate_overall_score(), scoped to one category.
	 *
	 * @param FindingRepository $findings Repository to read the breakdown from.
	 * @param string            $category One of the scanner category strings.
	 * @return int 0-100.
	 */
	private function calculate_category_score( FindingRepository $findings, string $category ): int {
		return $this->score_from_breakdown( $findings->get_severity_breakdown_for_category( $category ) );
	}

	/**
	 * Real Google PageSpeed Insights Mobile/Desktop scores.
	 *
	 * @return array{mobile: int|null, desktop: int|null, checked_at: string|null}
	 */
	private function build_psi_speed_scores(): array {
		$mobile     = get_option( 'vulopilot_psi_mobile_score', null );
		$desktop    = get_option( 'vulopilot_psi_desktop_score', null );
		$checked_at = get_option( 'vulopilot_psi_checked_at', null );

		return array(
			'mobile'     => null !== $mobile ? (int) $mobile : null,
			'desktop'    => null !== $desktop ? (int) $desktop : null,
			'checked_at' => '' !== $checked_at ? $checked_at : null,
		);
	}

	/**
	 * "Quick Fixes" = open findings in a category that has a matching one-click AIAction
	 * already registered.
	 *
	 * @param FindingRepository $findings Repository to count from.
	 * @return int
	 */
	private function count_quick_fixes( FindingRepository $findings ): int {
		if ( ! VuloPilot()->ai_action_registry->get_action( 'generate-alt' ) ) {
			return 0;
		}

		return $findings->count_by_category( 'images' );
	}

	/**
	 * @param FindingRepository $findings Repository to sum severities from.
	 * @return int
	 */
	private function count_open_findings( FindingRepository $findings ): int {
		$total = 0;

		foreach ( Severity::all() as $severity ) {
			$total += $findings->count_by_severity( $severity );
		}

		return $total;
	}

	/**
	 * Open finding count per severity - backs the Dashboard's issue distribution chart.
	 *
	 * @param FindingRepository $findings Repository to count from.
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	private function build_findings_by_severity( FindingRepository $findings ): array {
		return array(
			'critical' => $findings->count_by_severity( Severity::CRITICAL ),
			'high'     => $findings->count_by_severity( Severity::HIGH ),
			'medium'   => $findings->count_by_severity( Severity::MEDIUM ),
			'low'      => $findings->count_by_severity( Severity::LOW ),
		);
	}

	/**
	 * "Overall Health" - the average of every applicable category's own already-computed.
	 *
	 * @param array<string, int|null> $category_scores build_category_scores()'s own return value.
	 * @return int 0-100.
	 */
	private function calculate_overall_score( array $category_scores ): int {
		$applicable = array_filter(
			$category_scores,
			static function ( $score ) {
				return null !== $score;
			}
		);

		if ( ! $applicable ) {
			return 100;
		}

		return (int) round( array_sum( $applicable ) / count( $applicable ) );
	}
}
