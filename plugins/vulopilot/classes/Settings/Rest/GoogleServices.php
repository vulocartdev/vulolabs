<?php
namespace VuloPilot\Settings\Rest;

use VuloPilot\Settings\GoogleAdSenseClient;
use VuloPilot\Settings\GoogleAnalyticsClient;
use VuloPilot\Settings\GoogleServicesConnection;

defined( 'ABSPATH' ) || exit;

/**
 * Backs Settings → Connections → Google Services' real "Connect Google Services" flow
 * (GoogleServicesPanel.tsx) and the Keywords tab's own real connection-status read
 * (KeywordsTab.tsx).
 *
 * @class       GoogleServices controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class GoogleServices extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'google-services';

	/**
	 * @inheritDoc
	 */
	public function register_routes() {
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/authorize-url',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_authorize_url' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/disconnect',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/test-connections',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'test_connections' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Search Console.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/search-console-sites',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_search_console_sites' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/select-search-console-site',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'select_search_console_site' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Analytics (GA4).
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/analytics-accounts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics_accounts' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/analytics-data-streams',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics_data_streams' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/select-analytics-property',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'select_analytics_property' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// AdSense.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/adsense-accounts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_adsense_accounts' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/select-adsense-account',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'select_adsense_account' ),
					'permission_callback' => array( $this, 'permissions_check' ),
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
	public function permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response( ( new GoogleServicesConnection() )->get_status() );
	}

	/**
	 * `return_to` is only sanitized here; the allow-list check is in
	 * GoogleServicesConnection::get_authorization_url().
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_authorize_url( $request ) {
		$return_to = sanitize_text_field( (string) $request->get_param( 'return_to' ) );
		$url       = ( new GoogleServicesConnection() )->get_authorization_url( $return_to ? $return_to : 'settings' );

		if ( ! $url ) {
			return new \WP_Error( 'vulopilot_gsc_no_credentials', __( 'Google Connect isn’t configured for this build yet.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$connection = new GoogleServicesConnection();

		if ( $connection->has_broker() ) {
			// Ask the broker first, so a broker that isn't fully set up answers with its own
			// message here instead of sending the browser to a raw JSON error page.
			$probe = wp_remote_get(
                $url,
                array(
					'timeout'     => 15,
					'redirection' => 0,
                )
            );

			if ( ! is_wp_error( $probe ) ) {
				$status = (int) wp_remote_retrieve_response_code( $probe );

				if ( $status >= 300 && $status < 400 && wp_remote_retrieve_header( $probe, 'location' ) ) {
					$url = (string) wp_remote_retrieve_header( $probe, 'location' );
				} elseif ( $status >= 400 ) {
					$body    = json_decode( (string) wp_remote_retrieve_body( $probe ), true );
					$message = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : '';

					return new \WP_Error(
						'vulopilot_gsc_broker_unavailable',
						'' !== $message
							? sprintf(
								/* translators: %s: the Google connect service's own explanation. */
								__( 'Google connect service: %s', 'vulopilot' ),
								$message
							)
							: __( 'The Google connect service could not start the connection. Please try again later.', 'vulopilot' ),
						array( 'status' => 502 )
					);
				}
			}
		}

		return rest_ensure_response( array( 'url' => $url ) );
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function disconnect() {
		$connection = new GoogleServicesConnection();
		$connection->disconnect();

		return rest_ensure_response( $connection->get_status() );
	}

	/**
	 * Pings each Google service once, since a revoked refresh token would still read as
	 * connected from `get_status()`.
	 *
	 * @return \WP_REST_Response
	 */
	public function test_connections() {
		$connection = new GoogleServicesConnection();

		$search_console = $connection->list_search_console_sites();
		$analytics      = ( new GoogleAnalyticsClient( $connection ) )->list_account_summaries();
		$adsense        = ( new GoogleAdSenseClient( $connection ) )->list_accounts();

		return rest_ensure_response(
			array(
				'search_console' => ! is_wp_error( $search_console ),
				'analytics'      => ! is_wp_error( $analytics ),
				'adsense'        => ! is_wp_error( $adsense ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_search_console_sites() {
		$sites = ( new GoogleServicesConnection() )->list_search_console_sites();

		if ( is_wp_error( $sites ) ) {
			return $sites;
		}

		return rest_ensure_response( $sites );
	}

	/**
	 * Uses `sanitize_text_field()`, not `esc_url_raw()`, which would strip valid `sc-
	 * domain:` property values.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function select_search_console_site( $request ) {
		$site_url = sanitize_text_field( (string) $request->get_param( 'site_url' ) );

		if ( '' === $site_url ) {
			return new \WP_Error( 'vulopilot_gsc_missing_site', __( 'No site selected.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$connection = new GoogleServicesConnection();
		$sites      = $connection->list_search_console_sites();

		if ( is_wp_error( $sites ) ) {
			return $sites;
		}

		if ( ! in_array( $site_url, array_column( $sites, 'site_url' ), true ) ) {
			return new \WP_Error( 'vulopilot_gsc_unknown_site', __( 'That property isn’t in your Search Console account.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$connection->select_search_console_site( $site_url );

		return rest_ensure_response( $connection->get_status() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_analytics_accounts() {
		$accounts = ( new GoogleAnalyticsClient() )->list_account_summaries();

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		return rest_ensure_response( $accounts );
	}

	/**
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_analytics_data_streams( $request ) {
		$property_id = sanitize_text_field( (string) $request->get_param( 'property_id' ) );

		if ( '' === $property_id ) {
			return new \WP_Error( 'vulopilot_ga4_missing_property', __( 'No property given.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$streams = ( new GoogleAnalyticsClient() )->list_data_streams( $property_id );

		if ( is_wp_error( $streams ) ) {
			return $streams;
		}

		return rest_ensure_response( $streams );
	}

	/**
	 * Validated against a fresh real `list_account_summaries()`/ `list_data_streams()`
	 * pair rather than trusting the posted account/property names as-is.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function select_analytics_property( $request ) {
		$account_id     = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$property_id    = sanitize_text_field( (string) $request->get_param( 'property_id' ) );
		$data_stream_id = sanitize_text_field( (string) $request->get_param( 'data_stream_id' ) );

		if ( '' === $account_id || '' === $property_id || '' === $data_stream_id ) {
			return new \WP_Error( 'vulopilot_ga4_missing_selection', __( 'Account, property, and data stream are all required.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$analytics_client = new GoogleAnalyticsClient();
		$accounts         = $analytics_client->list_account_summaries();

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$account = current( array_filter( $accounts, static fn( $a ) => $a['account_id'] === $account_id ) );

		if ( ! $account ) {
			return new \WP_Error( 'vulopilot_ga4_unknown_account', __( 'That Analytics account isn’t available.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$property = current( array_filter( $account['properties'], static fn( $p ) => $p['property_id'] === $property_id ) );

		if ( ! $property ) {
			return new \WP_Error( 'vulopilot_ga4_unknown_property', __( 'That Analytics property isn’t available.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$streams = $analytics_client->list_data_streams( $property_id );

		if ( is_wp_error( $streams ) ) {
			return $streams;
		}

		$stream = current( array_filter( $streams, static fn( $s ) => $s['data_stream_id'] === $data_stream_id ) );

		if ( ! $stream ) {
			return new \WP_Error( 'vulopilot_ga4_unknown_stream', __( 'That data stream isn’t available.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$connection = new GoogleServicesConnection();
		$connection->select_ga4_property(
			array(
				'account_id'     => $account['account_id'],
				'account_name'   => $account['account_name'],
				'property_id'    => $property['property_id'],
				'property_name'  => $property['property_name'],
				'measurement_id' => $stream['measurement_id'],
			)
		);

		return rest_ensure_response( $connection->get_status() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_adsense_accounts() {
		$accounts = ( new GoogleAdSenseClient() )->list_accounts();

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		return rest_ensure_response( $accounts );
	}

	/**
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function select_adsense_account( $request ) {
		$account_id = sanitize_text_field( (string) $request->get_param( 'account_id' ) );

		if ( '' === $account_id ) {
			return new \WP_Error( 'vulopilot_adsense_missing_account', __( 'No AdSense account selected.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$accounts = ( new GoogleAdSenseClient() )->list_accounts();

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		$account = current( array_filter( $accounts, static fn( $a ) => $a['account_id'] === $account_id ) );

		if ( ! $account ) {
			return new \WP_Error( 'vulopilot_adsense_unknown_account', __( 'That AdSense account isn’t available.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$connection = new GoogleServicesConnection();
		$connection->select_adsense_account( $account['account_id'], $account['display_name'] );

		return rest_ensure_response( $connection->get_status() );
	}
}
