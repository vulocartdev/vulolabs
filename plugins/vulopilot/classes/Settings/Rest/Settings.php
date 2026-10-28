<?php
namespace VuloPilot\Settings\Rest;

use VuloPilot\KnowledgeGraph\EntityExtractor;
use VuloPilot\Reports\ReportRepository;
use VuloPilot\SeoVisibility\RobotsTxtBotAccess;
use VuloPilot\SeoVisibility\SchemaCoverageAnalyzer;
use VuloPilot\Settings\WebmasterToolsManager;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller backing src/pages/Settings/Settings.tsx (zyra's InputRenderer/NavigatorComponent framework). Reads/writes a single wp_options row (Utill::VULOPILOT_SETTINGS_KEY);
 *
 * @class       Settings controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Settings extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * @var string
	 */
	protected $modules_base = 'modules';

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
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/reset',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_settings' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Settings → Developer Tools' "Clear cache" - same `type: 'button'` + `apilink` shape as
		// /reset above.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/clear-cache',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clear_cache' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// "Send test email" button (Settings → Notifications).
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/test-email',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test_email' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/test-crawler-alert',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test_crawler_alert' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/test-report',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test_report' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Settings → Connections → PageSpeed Insights: GET returns the real on-load "Connected"
		// pill/usage-bar state without calling Google's API
		// (PageSpeedInsightsFetcher::get_status()).
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/test-pagespeed',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pagespeed_status' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test_pagespeed' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// "Verify" (Settings → Connections → Site Verification).
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/verify-webmaster',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify_webmaster_tool' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// "Restore Defaults" (Settings → Scanning → AI Visibility).
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/reset-ai-visibility-scans',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_ai_visibility_scans' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		// Enable/disable a module - mirrors the free vulolabs plugin's own Settings controller.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->modules_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_modules' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_modules' ),
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
	public function update_item_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_items( $request ) {
		return rest_ensure_response( $this->get_stored_settings() );
	}

	/**
	 * Merges one tab's fields into the stored settings - the request shape zyra's
	 * `InputRenderer` actually sends (`{ setting, settingName }`, see this class's own
	 * docblock), not a flat replace-everything body.
	 *
	 * @inheritDoc
	 */
	public function update_item( $request ) {
		$tab_fields = $request->get_param( 'setting' );

		// Fall back to treating the whole body as the field set when no `setting` wrapper is
		// present (e.g. a direct API call rather than InputRenderer's own auto-save).
		if ( ! is_array( $tab_fields ) ) {
			$tab_fields = $request->get_json_params();
		}

		if ( ! is_array( $tab_fields ) ) {
			$tab_fields = array();
		}

		$stored  = $this->get_stored_settings();
		$updated = array_merge( $stored, $tab_fields );
		$changed = $updated !== $stored;

		// General tab's own "Site tone" field autosaving a real, human- typed value means it's no
		// longer SiteToneLearner's own auto-detected phrase.
		if ( array_key_exists( 'site_tone', $tab_fields ) ) {
			$updated['site_tone_source'] = 'manual';
		}

		$sitemap_was_enabled = ! empty( $stored['sitemap_enabled'] );

		update_option( Utill::VULOPILOT_SETTINGS_KEY, $updated );
		delete_transient( 'vulopilot_sitemap_conflicting_ids' );

		// Turning the XML sitemap on is when its pretty addresses first need to resolve.
		if ( ! $sitemap_was_enabled && ! empty( $updated['sitemap_enabled'] ) ) {
			VuloPilot()->sitemap_url_rewriter->request_flush();
		}

		// InputRenderer autosaves a tab whenever it opens, even with nothing edited, so only
		// report a save when something actually changed.
		$response = array(
			'success' => true,
			'message' => $changed ? __( 'Settings saved.', 'vulopilot' ) : '',
		);

		// Editing llms.txt's content is meant to take effect immediately, not just on the next
		// dynamic /llms.txt request.
		if ( array_key_exists( 'llms_txt_content', $tab_fields ) ) {
			$response['file_saved'] = VuloPilot()->llms_txt_generator->write_file( $updated['llms_txt_content'] );
		}

		// Instant Indexing tab's "Change key" button - same "an edited field also needs an
		// immediate side-effect on save" precedent as llms_txt_content above.
		if ( array_key_exists( 'indexnow_api_key', $tab_fields ) && ! empty( $updated['indexnow_api_key'] ) ) {
			VuloPilot()->indexnow_key_file_server->write_key_file( $updated['indexnow_api_key'] );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Deletes the stored option entirely, reverting every setting to
	 * Utill::VULOPILOT_SETTINGS_DEFAULTS - the "Reset to defaults" action.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function reset_settings( $request ) {
		delete_option( Utill::VULOPILOT_SETTINGS_KEY );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Settings reset to defaults.', 'vulopilot' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function clear_cache( $request ) {
		( new EntityExtractor() )->clear_cache();
		( new SchemaCoverageAnalyzer() )->clear_cache();
		( new RobotsTxtBotAccess() )->clear_cache();

		do_action( 'vulopilot_clear_all_caches' );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Cache cleared.', 'vulopilot' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function send_test_email( $request ) {
		$settings  = $this->get_stored_settings();
		$recipient = $settings['notification_email'] ? $settings['notification_email'] : get_option( 'admin_email' );

		if ( ! is_email( $recipient ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'The notification email address isn\'t valid.', 'vulopilot' ),
				)
			);
		}

		$headers = array();

		if ( ! empty( $settings['email_from_address'] ) && is_email( $settings['email_from_address'] ) ) {
			$from_name = $settings['email_from_name'] ? $settings['email_from_name'] : get_bloginfo( 'name' );
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $settings['email_from_address'] );
		}

		$sent = wp_mail(
			$recipient,
			sprintf(
				/* translators: %s is the site name. */
				__( '[%s] VuloPilot test email', 'vulopilot' ),
				get_bloginfo( 'name' )
			),
			__( "This is a test email from VuloPilot's Notifications settings. If you received this, your notification email is configured correctly.", 'vulopilot' ),
			$headers
		);

		if ( $sent ) {
			// Same real "Last test … sent on …" persistence send_test_report() already keeps (own
			// key, unrelated to that one).
			$updated = array_merge( $this->get_stored_settings(), array( 'email_last_test_sent' => current_time( 'mysql', true ) ) );
			update_option( Utill::VULOPILOT_SETTINGS_KEY, $updated );
		}

		return rest_ensure_response(
			array(
				'success' => $sent,
				'message' => $sent
					? sprintf(
						/* translators: %s is the recipient email address. */
						__( 'Test email sent to %s.', 'vulopilot' ),
						$recipient
					)
					: __( 'wp_mail() returned false - check your site\'s mail configuration.', 'vulopilot' ),
			)
		);
	}

	/**
	 * On success, also persists a real `report_last_test_sent` timestamp.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function send_test_report( $request ) {
		if ( ! VuloPilot()->report_generator ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Sending a test report requires VuloPilot Pro.', 'vulopilot' ),
				)
			);
		}

		$settings  = $this->get_stored_settings();
		$recipient = $settings['notification_email'] ? $settings['notification_email'] : get_option( 'admin_email' );

		if ( ! is_email( $recipient ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'The notification email address isn\'t valid.', 'vulopilot' ),
				)
			);
		}

		$format = (string) $settings['default_report_format'];

		if ( ! VuloPilot()->report_exporter_registry->get_exporter( $format ) ) {
			$format = 'csv';
		}

		$period_days  = absint( $settings['default_report_period_days'] );
		$period_days  = max( 1, $period_days ? $period_days : 30 );
		$period_end   = current_time( 'Y-m-d' );
		$period_start = gmdate( 'Y-m-d', strtotime( '-' . ( $period_days - 1 ) . ' days', strtotime( $period_end ) ) );

		$report_id = VuloPilot()->report_generator->generate(
			'scan_summary',
			$format,
			$period_start,
			$period_end,
			array(),
			get_current_user_id()
		);

		$report = ( new ReportRepository() )->find( $report_id );

		if ( ! $report || 'ready' !== $report['status'] ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Could not generate a test report. Please try again.', 'vulopilot' ),
				)
			);
		}

		$headers = array();

		if ( ! empty( $settings['email_from_address'] ) && is_email( $settings['email_from_address'] ) ) {
			$from_name = $settings['email_from_name'] ? $settings['email_from_name'] : get_bloginfo( 'name' );
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $settings['email_from_address'] );
		}

		$sent = wp_mail(
			$recipient,
			sprintf(
				/* translators: %s is the site name. */
				__( '[%s] Your test report is ready', 'vulopilot' ),
				get_bloginfo( 'name' )
			),
			__( 'This is a test report from VuloPilot\'s Reports settings. Sign in to your dashboard\'s Reports page to view and download it.', 'vulopilot' ),
			$headers
		);

		if ( $sent ) {
			$updated = array_merge( $this->get_stored_settings(), array( 'report_last_test_sent' => current_time( 'mysql', true ) ) );
			update_option( Utill::VULOPILOT_SETTINGS_KEY, $updated );
		}

		return rest_ensure_response(
			array(
				'success' => $sent,
				'message' => $sent
					? sprintf(
						/* translators: %s is the recipient email address. */
						__( 'Test report generated and emailed to %s.', 'vulopilot' ),
						$recipient
					)
					: __( 'wp_mail() returned false - check your site\'s mail configuration.', 'vulopilot' ),
			)
		);
	}

	/**
	 * "Test Connection" (Settings → Connections → PageSpeed Insights).
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function send_test_pagespeed( $request ) {
		return rest_ensure_response( VuloPilot()->psi_fetcher->test_connection() );
	}

	/**
	 * The real on-load "Connected" pill/usage-bar state, without calling Google's API -
	 * see PageSpeedInsightsFetcher::get_status()'s own docblock.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function get_pagespeed_status( $request ) {
		return rest_ensure_response( VuloPilot()->psi_fetcher->get_status() );
	}

	/**
	 * "Verify"/"Verify with Bing"/"Verify with Pinterest" (Settings → Connections → Site
	 * Verification).
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function verify_webmaster_tool( $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );

		if ( ! in_array( $provider, array( 'google', 'bing', 'pinterest' ), true ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Unknown verification provider.', 'vulopilot' ),
				)
			);
		}

		$setting_key = 'webmaster_' . $provider . '_verification';
		$code        = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$settings    = $this->get_stored_settings();

		$settings[ $setting_key ] = $code;
		// A new attempt clears any earlier "verified" state until this exact code passes.
		$settings[ 'webmaster_' . $provider . '_verified_at' ] = '';
		update_option( Utill::VULOPILOT_SETTINGS_KEY, $settings );

		if ( '' === $code ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Enter a verification code first.', 'vulopilot' ),
				)
			);
		}

		$meta_name = WebmasterToolsManager::VERIFICATION_META_NAMES[ $setting_key ] ?? '';
		$attribute = 'webmaster_pinterest_verification' === $setting_key ? 'property' : 'name';

		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Could not load your homepage to check - please try again.', 'vulopilot' ),
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$pattern = '/<meta\s+[^>]*' . preg_quote( $attribute, '/' ) . '\s*=\s*["\']' . preg_quote( $meta_name, '/' ) . '["\'][^>]*content\s*=\s*["\']' . preg_quote( $code, '/' ) . '["\'][^>]*\/?>/i';

		if ( ! preg_match( $pattern, $body ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Verification tag not found on your homepage yet - if you just saved it, clear any caching and try again.', 'vulopilot' ),
				)
			);
		}

		$settings[ 'webmaster_' . $provider . '_verified_at' ] = current_time( 'mysql', true );
		update_option( Utill::VULOPILOT_SETTINGS_KEY, $settings );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Verified - the tag is live on your homepage.', 'vulopilot' ),
			)
		);
	}

	/**
	 * "Restore Defaults" (Settings → Scanning → AI Visibility).
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function reset_ai_visibility_scans( $request ) {
		$settings                        = $this->get_stored_settings();
		$settings['ai_visibility_scans'] = Utill::VULOPILOT_SETTINGS_DEFAULTS['ai_visibility_scans'];
		update_option( Utill::VULOPILOT_SETTINGS_KEY, $settings );

		return rest_ensure_response(
			array(
				'success'             => true,
				'ai_visibility_scans' => $settings['ai_visibility_scans'],
			)
		);
	}

	/**
	 * On success, also persists a real `crawler_alert_last_test_sent` timestamp into the
	 * flat settings option.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response
	 */
	public function send_test_crawler_alert( $request ) {
		if ( ! has_filter( 'vulopilot_send_test_crawler_alert' ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'AI Crawler Alerts requires VuloPilot Pro, with the AI Crawler Analytics module active.', 'vulopilot' ),
				)
			);
		}

		$result = apply_filters( 'vulopilot_send_test_crawler_alert', null );

		if ( ! is_array( $result ) || ! isset( $result['success'] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Could not send a test alert.', 'vulopilot' ),
				)
			);
		}

		if ( $result['success'] ) {
			$updated = array_merge( $this->get_stored_settings(), array( 'crawler_alert_last_test_sent' => current_time( 'mysql' ) ) );
			update_option( Utill::VULOPILOT_SETTINGS_KEY, $updated );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @return array<string, mixed> Stored settings, defaults filled in for anything never saved.
	 */
	private function get_stored_settings(): array {
		$saved = get_option( Utill::VULOPILOT_SETTINGS_KEY, array() );

		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		// Sites that saved a checkbox setting before this option's defaults moved to zyra's own
		// wire shape still have a raw PHP boolean sitting in the stored option for that key.
		foreach ( $settings as $key => $value ) {
			if ( is_bool( $value ) ) {
				$settings[ $key ] = $value ? array( $key ) : array();
			}
		}

		// 'llms_txt_content' can't live in VULOPILOT_SETTINGS_DEFAULTS as anything but '' (a class
		// const array can't call a method).
		if ( empty( $settings['llms_txt_content'] ) ) {
			$settings['llms_txt_content'] = VuloPilot()->llms_txt_generator->generate();
		}

		// Unlike llms_txt_content above (display-only until an admin explicitly edits/saves),
		// `indexnow_api_key` needs a real.
		if ( empty( $settings['indexnow_api_key'] ) ) {
			$settings['indexnow_api_key'] = \VuloPilot\SeoVisibility\IndexNowKeyFileServer::generate_new_key();
			update_option( Utill::VULOPILOT_SETTINGS_KEY, $settings );
			VuloPilot()->indexnow_key_file_server->write_key_file( $settings['indexnow_api_key'] );
		}

		return $settings;
	}

	/**
	 * Activates or deactivates one module - the same request shape zyra's
	 * `ModuleGridComponent` sends regardless of which plugin it's talking to (`{ id,
	 * action, modules? }`), mirrored from the free vulolabs plugin's own `set_modules()`.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_modules( $request ) {
		$module_id = sanitize_key( (string) $request->get_param( 'id' ) );
		$action    = sanitize_key( (string) $request->get_param( 'action' ) );
		$modules   = array_map( 'sanitize_key', (array) $request->get_param( 'modules' ) );

		if ( '' === $module_id && empty( $modules ) ) {
			return new \WP_Error( 'vulopilot_missing_module_id', __( 'No module id given.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $modules ) ) {
			$result = VuloPilot()->modules->activate_modules( $modules );

			return rest_ensure_response( $result );
		}

		$result = 'activate' === $action
			? VuloPilot()->modules->activate_modules( array( $module_id ) )
			: VuloPilot()->modules->deactivate_modules( array( $module_id ) );

		return rest_ensure_response( $result );
	}

	/**
	 * @return array Every currently active module's id - zyra's
	 *               `initializeModules()`/`useModules()`
	 *               expect this exact flat-array shape.
	 */
	public function get_modules() {
		return VuloPilot()->modules->get_active_modules();
	}
}
