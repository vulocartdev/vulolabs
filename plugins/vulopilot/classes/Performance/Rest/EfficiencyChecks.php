<?php
namespace VuloPilot\Performance\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for `/efficiency-checks`, backing "Protect My Site" → Performance tab. Unlike other tabs, this isn't read from `vulopilot_scan_findings` (which only stores problems):
 *
 * @class       EfficiencyChecks controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class EfficiencyChecks extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'efficiency-checks';

	/**
	 * Timeout for a probe request, in seconds.
	 */
	private const REQUEST_TIMEOUT_SECONDS = 8;

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
		$checks = array(
			$this->check_page_caching(),
			$this->check_browser_caching(),
			$this->check_persistent_object_cache(),
			$this->check_opcache(),
		);

		$need_attention = 0;
		$working        = 0;
		$not_applicable = 0;

		foreach ( $checks as $check ) {
			switch ( $check['status'] ) {
				case 'attention':
					++$need_attention;
					break;
				case 'not_applicable':
					++$not_applicable;
					break;
				default:
					++$working;
			}
		}

		return rest_ensure_response(
			array(
				'summary'      => array(
					'total'          => count( $checks ),
					'need_attention' => $need_attention,
					'working'        => $working,
					'not_applicable' => $not_applicable,
				),
				'sections'     => array(
					array(
						'key'      => 'page-delivery',
						'label'    => __( 'Page Delivery', 'vulopilot' ),
						'question' => __( 'Is WordPress avoiding unnecessary work?', 'vulopilot' ),
						'checks'   => array( $checks[0], $checks[1] ),
					),
					array(
						'key'      => 'data-efficiency',
						'label'    => __( 'WordPress Data Efficiency', 'vulopilot' ),
						'question' => __( 'Can WordPress retrieve frequently used information efficiently?', 'vulopilot' ),
						'checks'   => array( $checks[2] ),
					),
					array(
						'key'      => 'server-processing',
						'label'    => __( 'Server Processing', 'vulopilot' ),
						'question' => __( 'Can your server prepare WordPress efficiently?', 'vulopilot' ),
						'checks'   => array( $checks[3] ),
					),
				),
				// Only the checks that need attention.
				'review_items' => array_values(
					array_filter(
						$checks,
						static function ( $check ) {
							return 'attention' === $check['status'];
						}
					)
				),
			)
		);
	}

	/**
	 * "Page caching" - is anything full-page-caching the homepage at all?
	 *
	 * @return array
	 */
	private function check_page_caching(): array {
		$known_plugin_active = $this->has_known_caching_plugin();
		// WP core sets this true once a page-cache plugin's advanced-cache.php drop-in is loaded.
		$advanced_cache_present = defined( 'WP_CACHE' ) && WP_CACHE;
		$page_cache_detected    = $known_plugin_active || $advanced_cache_present;

		$cache_headers_detected = $this->has_caching_header( home_url( '/' ) );

		$status = ( $page_cache_detected || $cache_headers_detected ) ? 'good' : 'attention';

		return array(
			'id'                 => 'page-caching',
			'title'              => __( 'Page caching', 'vulopilot' ),
			'description'        => __( 'WordPress may be rebuilding pages that could otherwise be served from a saved copy.', 'vulopilot' ),
			'icon'               => 'refresh-bold',
			'status'             => $status,
			'badge'              => 'good' === $status ? __( 'Working', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
			'review_title'       => __( 'Page caching isn\'t detected', 'vulopilot' ),
			'review_description' => __( 'WordPress may be rebuilding pages for repeat visits.', 'vulopilot' ),
			'technical_details'  => array(
				array(
					'label'  => __( 'Page cache', 'vulopilot' ),
					'value'  => $page_cache_detected ? __( 'Detected', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
					'status' => $page_cache_detected ? 'good' : 'attention',
				),
				array(
					'label'  => __( 'Cache headers', 'vulopilot' ),
					'value'  => $cache_headers_detected ? __( 'Detected', 'vulopilot' ) : __( 'None detected', 'vulopilot' ),
					'status' => $cache_headers_detected ? 'good' : 'attention',
				),
			),
		);
	}

	/**
	 * "Browser caching" check: whether static assets are served with cache headers.
	 *
	 * @return array
	 */
	private function check_browser_caching(): array {
		$response = $this->probe( includes_url( 'js/wp-embed.min.js' ) );

		$cache_control    = $response ? wp_remote_retrieve_header( $response, 'cache-control' ) : '';
		$cache_control_ok = (bool) preg_match( '/max-age=[1-9]/', (string) $cache_control );
		$expires_header   = $response ? wp_remote_retrieve_header( $response, 'expires' ) : '';
		$expires_ok       = '' !== $expires_header && strtotime( (string) $expires_header ) > time();

		$status = $cache_control_ok ? 'good' : 'attention';

		return array(
			'id'                 => 'browser-caching',
			'title'              => __( 'Browser caching', 'vulopilot' ),
			'description'        => __( 'Visitors can reuse suitable website files.', 'vulopilot' ),
			'icon'               => 'global-community',
			'status'             => $status,
			'badge'              => 'good' === $status ? __( 'Working', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
			'review_title'       => __( 'Browser caching headers are missing', 'vulopilot' ),
			'review_description' => __( 'Visitor browsers may re-download files that could be reused.', 'vulopilot' ),
			'technical_details'  => array(
				array(
					'label'  => 'Cache-Control',
					'value'  => $cache_control_ok ? __( 'Detected', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
					'status' => $cache_control_ok ? 'good' : 'attention',
				),
				array(
					'label'  => __( 'Expires header', 'vulopilot' ),
					'value'  => $expires_ok ? __( 'Detected', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
					'status' => $expires_ok ? 'good' : 'attention',
				),
			),
		);
	}

	/**
	 * "Persistent object cache" check, via `wp_using_ext_object_cache()`.
	 *
	 * @return array
	 */
	private function check_persistent_object_cache(): array {
		$active  = wp_using_ext_object_cache();
		$drop_in = file_exists( trailingslashit( dirname( get_theme_root() ) ) . 'object-cache.php' );

		$status = 'good';
		$badge  = __( 'Working', 'vulopilot' );

		if ( ! $active ) {
			$status = $this->should_suggest_persistent_object_cache() ? 'attention' : 'not_applicable';
			$badge  = 'attention' === $status ? __( 'Recommended', 'vulopilot' ) : __( 'Not required', 'vulopilot' );
		}

		return array(
			'id'                 => 'persistent-object-cache',
			'title'              => __( 'Persistent object cache', 'vulopilot' ),
			'description'        => __( 'Your website may benefit from keeping frequently used WordPress data ready between visits.', 'vulopilot' ),
			'icon'               => 'database',
			'status'             => $status,
			'badge'              => $badge,
			'review_title'       => __( 'Persistent object cache recommended', 'vulopilot' ),
			'review_description' => __( 'Your site may benefit from a persistent object cache.', 'vulopilot' ),
			'technical_details'  => array(
				array(
					'label'  => __( 'Persistent object cache', 'vulopilot' ),
					'value'  => $active ? __( 'Detected', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
					'status' => $active ? 'good' : 'attention',
				),
				array(
					'label'  => __( 'Drop-in', 'vulopilot' ),
					'value'  => $drop_in ? __( 'Active', 'vulopilot' ) : __( 'Not active', 'vulopilot' ),
					'status' => $drop_in ? 'good' : 'attention',
				),
			),
		);
	}

	/**
	 * "PHP acceleration" check (Zend OPcache).
	 *
	 * @return array
	 */
	private function check_opcache(): array {
		$enabled = false;

		if ( function_exists( 'opcache_get_status' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache_get_status() itself warns when opcache.enable=0; that's the exact case being checked for.
			$opcache_status = @opcache_get_status( false );
			$enabled        = is_array( $opcache_status ) && ! empty( $opcache_status['opcache_enabled'] );
		}

		$ini_active = filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );

		$status = $enabled ? 'good' : 'attention';

		return array(
			'id'                 => 'opcache',
			'title'              => __( 'PHP acceleration', 'vulopilot' ),
			'description'        => __( 'Your server can reuse compiled PHP code.', 'vulopilot' ),
			'icon'               => 'coding',
			'status'             => $status,
			'badge'              => 'good' === $status ? __( 'Working', 'vulopilot' ) : __( 'Not detected', 'vulopilot' ),
			'review_title'       => __( 'OPcache is not enabled', 'vulopilot' ),
			'review_description' => __( 'Enabling OPcache can significantly improve PHP performance.', 'vulopilot' ),
			'technical_details'  => array(
				array(
					'label'  => 'OPcache',
					'value'  => $enabled ? __( 'Enabled', 'vulopilot' ) : __( 'Disabled', 'vulopilot' ),
					'status' => $enabled ? 'good' : 'attention',
				),
				array(
					'label'  => __( 'Status', 'vulopilot' ),
					'value'  => $ini_active ? __( 'Active', 'vulopilot' ) : __( 'Inactive', 'vulopilot' ),
					'status' => $ini_active ? 'good' : 'attention',
				),
			),
		);
	}

	/**
	 * @return bool
	 */
	private function has_known_caching_plugin(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Mirrors the list CacheDetectionScanner maintains.
		$known_caching_plugins = array(
			'wp-rocket/wp-rocket.php',
			'w3-total-cache/w3-total-cache.php',
			'wp-super-cache/wp-cache.php',
			'litespeed-cache/litespeed-cache.php',
			'cache-enabler/cache-enabler.php',
			'wp-fastest-cache/wpFastestCache.php',
		);

		foreach ( $known_caching_plugins as $plugin_file ) {
			if ( is_plugin_active( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $url URL to request.
	 * @return array|null The raw `wp_remote_get()` response, or null on failure. Read
	 *                     headers with `wp_remote_retrieve_header()`, not an `(array)` cast.
	 */
	private function probe( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => self::REQUEST_TIMEOUT_SECONDS,
				'sslverify' => false,
			)
		);

		return is_wp_error( $response ) ? null : $response;
	}

	/**
	 * @param string $url URL to request.
	 * @return bool
	 */
	private function has_caching_header( string $url ): bool {
		$response = $this->probe( $url );

		if ( ! $response ) {
			// Can't tell either way - don't flag on an inconclusive request.
			return true;
		}

		$cache_control = (string) wp_remote_retrieve_header( $response, 'cache-control' );

		if ( preg_match( '/max-age=[1-9]/', $cache_control ) ) {
			return true;
		}

		return '' !== (string) wp_remote_retrieve_header( $response, 'etag' )
			|| '' !== (string) wp_remote_retrieve_header( $response, 'age' );
	}

	/**
	 * Wraps `WP_Site_Health::should_suggest_persistent_object_cache()`'s
	 * own thresholds rather than re-deriving them.
	 *
	 * @return bool
	 */
	private function should_suggest_persistent_object_cache(): bool {
		if ( ! class_exists( '\WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		return \WP_Site_Health::get_instance()->should_suggest_persistent_object_cache();
	}
}
