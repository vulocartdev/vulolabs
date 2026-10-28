<?php
namespace VuloPilot\SiteHealth\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches `/plugin-overlap` - backs "Protect My Site" → Files & Plugins' own "VuloPilot already covers this" card. Deliberately NOT tied to any vulnerability finding:
 *
 * @class       PluginOverlap controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class PluginOverlap extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'plugin-overlap';

	/**
	 * @var array<string, array{plugin_name: string, category: string, vulopilot_feature: string, module_id: string|null, link_tab: string}>
	 */
	private const KNOWN_OVERLAPS = array(
		'wordpress-seo/wp-seo.php'                    => array(
			'plugin_name'       => 'Yoast SEO',
			'category'          => 'seo',
			'vulopilot_feature' => 'SEO Copilot',
			'module_id'         => 'advanced-seo',
			'link_tab'          => 'modules',
		),
		'seo-by-rank-math/rank-math.php'              => array(
			'plugin_name'       => 'Rank Math SEO',
			'category'          => 'seo',
			'vulopilot_feature' => 'SEO Copilot',
			'module_id'         => 'advanced-seo',
			'link_tab'          => 'modules',
		),
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => array(
			'plugin_name'       => 'All in One SEO',
			'category'          => 'seo',
			'vulopilot_feature' => 'SEO Copilot',
			'module_id'         => 'advanced-seo',
			'link_tab'          => 'modules',
		),
		'wordfence/wordfence.php'                     => array(
			'plugin_name'       => 'Wordfence Security',
			'category'          => 'security',
			'vulopilot_feature' => 'Security Watchtower',
			'module_id'         => 'security-monitoring',
			'link_tab'          => 'modules',
		),
		'sucuri-scanner/sucuri.php'                   => array(
			'plugin_name'       => 'Sucuri Security',
			'category'          => 'security',
			'vulopilot_feature' => 'Security Watchtower',
			'module_id'         => 'security-monitoring',
			'link_tab'          => 'modules',
		),
		'better-wp-security/better-wp-security.php'   => array(
			'plugin_name'       => 'Solid Security (iThemes Security)',
			'category'          => 'security',
			'vulopilot_feature' => 'Security Watchtower',
			'module_id'         => 'security-monitoring',
			'link_tab'          => 'modules',
		),
		'all-in-one-wp-security-and-firewall/wp-security.php' => array(
			'plugin_name'       => 'All In One WP Security & Firewall',
			'category'          => 'security',
			'vulopilot_feature' => 'Security Watchtower',
			'module_id'         => 'security-monitoring',
			'link_tab'          => 'modules',
		),
		'wp-accessibility/wp-accessibility.php'       => array(
			'plugin_name'       => 'WP Accessibility',
			'category'          => 'accessibility',
			'vulopilot_feature' => 'Accessibility Guard',
			'module_id'         => 'accessibility-audits',
			'link_tab'          => 'modules',
		),
		'uncanny-automator/uncanny-automator.php'     => array(
			'plugin_name'       => 'Uncanny Automator',
			'category'          => 'automation',
			'vulopilot_feature' => 'Workflow Autopilot',
			'module_id'         => 'automations',
			'link_tab'          => 'modules',
		),
		'wp-rocket/wp-rocket.php'                     => array(
			'plugin_name'       => 'WP Rocket',
			'category'          => 'caching',
			'vulopilot_feature' => 'Efficiency checks & speed monitoring',
			'module_id'         => null,
			'link_tab'          => 'performance',
		),
		'w3-total-cache/w3-total-cache.php'           => array(
			'plugin_name'       => 'W3 Total Cache',
			'category'          => 'caching',
			'vulopilot_feature' => 'Efficiency checks & speed monitoring',
			'module_id'         => null,
			'link_tab'          => 'performance',
		),
		'wp-super-cache/wp-cache.php'                 => array(
			'plugin_name'       => 'WP Super Cache',
			'category'          => 'caching',
			'vulopilot_feature' => 'Efficiency checks & speed monitoring',
			'module_id'         => null,
			'link_tab'          => 'performance',
		),
		'litespeed-cache/litespeed-cache.php'         => array(
			'plugin_name'       => 'LiteSpeed Cache',
			'category'          => 'caching',
			'vulopilot_feature' => 'Efficiency checks & speed monitoring',
			'module_id'         => null,
			'link_tab'          => 'performance',
		),
	);

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
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$matches = array();

		foreach ( self::KNOWN_OVERLAPS as $plugin_file => $overlap ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$matches[] = array_merge( array( 'plugin_file' => $plugin_file ), $overlap );
			}
		}

		// `data`/`total`, not a bespoke `matches` key.
		return rest_ensure_response(
			array(
				'data'  => $matches,
				'total' => count( $matches ),
			)
		);
	}
}
