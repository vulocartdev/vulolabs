<?php
/**
 * VuloPilot class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot;

defined( 'ABSPATH' ) || exit;

/**
 * VuloPilot Class.
 *
 * @class       VuloPilot class
 * @version     1.0.0
 * @author      VuloLabs
 */
final class VuloPilot {

	/**
	 * Holds the single instance of the class (singleton pattern).
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The main plugin file path.
	 *
	 * @var string
	 */
	private $file = '';

	/**
	 * Container for shared class instances and config values.
	 *
	 * @var array
	 */
	private $container = array();

	/**
	 * Class constructor.
	 *
	 * @param string $file Main plugin file path.
	 */
	public function __construct( $file ) {
		require_once trailingslashit( dirname( $file ) ) . '/config.php';

		$this->file                        = $file;
		$this->container['plugin_url']     = trailingslashit( plugins_url( '', $file ) );
		$this->container['plugin_path']    = trailingslashit( dirname( $file ) );
		$this->container['plugin_base']    = plugin_basename( $file );
		$this->container['version']        = VULOPILOT_PLUGIN_VERSION;
		$this->container['rest_namespace'] = 'vulopilot/v1';
		$this->container['plugin_slug']    = VULOPILOT_PLUGIN_SLUG;

		register_activation_hook( $file, array( $this, 'activate' ) );
		register_deactivation_hook( $file, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public function activate() {
		add_option( Utill::VULOPILOT_OTHER_SETTINGS['run_installer'], true );
		// A no-op if this site already has an active-module list (e.g. a deactivate/reactivate
		// cycle).
		add_option( Utill::ACTIVE_MODULES_DB_KEY, array( 'geo-analysis', 'technical-seo', 'content-optimization', 'brand-visibility', 'knowledge-graph', 'ai-copilot' ) );
		// The sitemap rules are only registered once WordPress has initialised its sitemaps, after
		// this hook.
		delete_option( 'vulopilot_sitemap_rewrite_version' );
		flush_rewrite_rules();

		// One-shot flag - admin_init's own maybe_redirect_to_setup_wizard() consumes and deletes
		// this on the very next admin page load, same pattern WooCommerce/Jetpack-style onboarding
		// redirects use. Not set on a bulk activation (several plugins at once) - that redirect
		// would only ever reach whichever plugin's own admin_init ran last, dropping every other
		// bulk-activated plugin's own redirect.
		if ( ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, core's own bulk-activate query var.
			add_option( 'vulopilot_activation_redirect', true );
		}
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Boots the plugin once every other active plugin has loaded.
	 *
	 * @return void
	 */
	public function init_plugin() {
		add_action( 'init', array( $this, 'init_classes' ), 0 );

		$needs_install = get_option( Utill::VULOPILOT_OTHER_SETTINGS['run_installer'] )
			|| get_option( Utill::VULOPILOT_OTHER_SETTINGS['plugin_db_version'] ) !== VULOPILOT_PLUGIN_VERSION;

		if ( $needs_install ) {
			new Install();
			delete_option( Utill::VULOPILOT_OTHER_SETTINGS['run_installer'] );
		}
	}

	/**
	 * @return void
	 */
	public function init_classes() {
		$this->container['util']            = new Utill();
		$this->container['admin']           = new Admin();
		$this->container['frontendScripts'] = new FrontendScripts();

		// Module loader - loaded before every registry below.
		$this->container['modules'] = new Modules();
		$this->container['modules']->load_active_modules();

		$this->container['scanner_registry'] = new \VuloPilot\Utill\ScannerRegistry();
		$this->container['scan_runner']      = new \VuloPilot\Utill\ScanRunner( $this->container['scanner_registry'] );

		$this->container['rule_registry'] = new \VuloPilot\Utill\RuleRegistry();
		$this->container['rule_engine']   = new \VuloPilot\Utill\RuleEngine( $this->container['rule_registry'] );

		$this->container['scan_persistence'] = new \VuloPilot\Utill\ScanPersistenceListener();

		$this->container['manual_action_registry'] = new Automations\ActionRegistry();
		$this->container['manual_action_runner']   = new Automations\ManualActionRunner( $this->container['manual_action_registry'] );

		$this->container['report_type_registry']     = null;
		$this->container['report_exporter_registry'] = null;
		$this->container['report_generator']         = null;

		$this->container['builtin_automation_seeder'] = new Automations\BuiltinAutomationSeeder();
		$this->container['automation_scheduler']      = new \VuloPilot\Automations\AutomationScheduler(
			$this->container['scan_runner']
		);

		$this->container['rest'] = new Rest();

		$this->container['ai_request_sender'] = new \VuloPilot\AiAssistant\AiRequestSender();

		$this->container['ai_action_registry'] = new AiCopilot\ActionRegistry();
		$this->container['ai_action_runner']   = new AiCopilot\ActionRunner(
			$this->container['ai_action_registry'],
			$this->container['ai_request_sender']
		);

		// GEO module - reuses the same ai_request_sender
		// every AIAction goes through, not a second AI-calling path.
		$this->container['geo_analyzer'] = new GeoAnalysis\GeoAnalyzer( $this->container['ai_request_sender'] );

		$this->container['content_analyzer'] = new ContentOptimization\ContentAnalyzer( $this->container['ai_request_sender'] );

		// llms.txt Generation & Management (readme.txt) - self-registers its own rewrite-
		// rule/template_redirect hooks.
		$this->container['llms_txt_generator'] = new GeoAnalysis\LlmsTxtGenerator();

		// AI Crawler Traffic Monitoring (readme.txt) - self-registers its own
		// template_redirect/cron hooks.
		$this->container['crawler_traffic_logger'] = new \VuloPilot\SeoVisibility\CrawlerTrafficLogger();

		// Scanning → Sitemap/Robots.txt cards - all wrap WordPress core's own native
		// sitemap/robots.txt rather than building either from scratch.
		$this->container['sitemap_manager']       = new \VuloPilot\SeoVisibility\SitemapManager();
		$this->container['sitemap_stylesheet']    = new \VuloPilot\SeoVisibility\SitemapStylesheet();
		$this->container['sitemap_url_rewriter']  = new \VuloPilot\SeoVisibility\SitemapUrlRewriter();
		$this->container['robots_txt_manager']    = new \VuloPilot\SeoVisibility\RobotsTxtManager();
		$this->container['html_sitemap_renderer'] = new \VuloPilot\SeoVisibility\HtmlSitemapRenderer();

		// Scanning → SEO & Content → Tag Manager.
		$this->container['tag_manager_service'] = new \VuloPilot\SeoVisibility\TagManagerService();

		// Scanning → Webmaster Tools - real `wp_head` verification `meta` tag output.
		$this->container['webmaster_tools_manager'] = new \VuloPilot\Settings\WebmasterToolsManager();

		// Connections → Google Services (Search Console/Analytics/AdSense).
		$this->container['gsc_oauth_callback_handler'] = new \VuloPilot\Settings\GoogleSearchConsoleOAuthCallbackHandler();
		$this->container['google_analytics_tracker']   = new \VuloPilot\Settings\GoogleAnalyticsTracker();

		$this->container['connect_broker_callback_handler'] = new \VuloPilot\AiAssistant\ConnectBrokerCallbackHandler();

		// Scanning → Instant Indexing (IndexNow) - real key-file serving (self-registers its own
		// rewrite-rule/template_redirect hooks, same shape as llms_txt_generator above) and
		// automatic submission on publish/update/trash, gated by their own settings.
		$this->container['indexnow_key_file_server'] = new \VuloPilot\SeoVisibility\IndexNowKeyFileServer();
		$this->container['indexnow_auto_submitter']  = new \VuloPilot\SeoVisibility\IndexNowAutoSubmitter();

		$this->container['site_tone_learner'] = new \VuloPilot\AiAssistant\SiteToneLearner( $this->container['ai_request_sender'] );

		$this->container['canonical_url_manager']    = new \VuloPilot\SeoVisibility\CanonicalUrlManager();
		$this->container['social_meta_tags_manager'] = new \VuloPilot\SeoVisibility\SocialMetaTagsManager();
		// Settings → Site Identity → Title Formats' real backing - filters
		// `pre_get_document_title`.
		$this->container['title_formatter']          = new \VuloPilot\SeoVisibility\TitleFormatter();
		$this->container['schema_json_ld_renderer']  = new \VuloPilot\SeoVisibility\SchemaJsonLdRenderer();
		$this->container['homepage_schema_renderer'] = new \VuloPilot\SeoVisibility\HomepageSchemaRenderer();

		// Post-editor SEO metabox: General tab's noindex/nofollow output (PostRobotsMetaManager)
		// and the Block Editor sidebar's asset loader (PostEditorAssets).
		$this->container['post_seo_meta_fields']     = new \VuloPilot\SeoVisibility\PostSeoMetaFields();
		$this->container['post_robots_meta_manager'] = new \VuloPilot\SeoVisibility\PostRobotsMetaManager();
		$this->container['default_robots_manager']   = new \VuloPilot\SeoVisibility\DefaultRobotsManager();
		$this->container['post_editor_assets']       = new \VuloPilot\SeoVisibility\PostEditorAssets();

		// Gutenberg blocks - `vulopilot/table-of-contents` and `vulopilot/faq` (src/blocks/).
		$this->container['block_registrar']         = new \VuloPilot\Content\BlockRegistrar();
		$this->container['heading_anchor_injector'] = new \VuloPilot\Content\HeadingAnchorInjector();

		// Redirects & 404s (readme.txt) - real functionality behind the
		// enable_redirect_manager/auto_redirect_on_slug_change/log_404s settings.
		$this->container['redirect_manager']        = new \VuloPilot\Content\RedirectManager();
		$this->container['not_found_logger']        = new \VuloPilot\Content\NotFoundLogger();
		$this->container['trashed_post_redirector'] = new \VuloPilot\Content\TrashedPostRedirector();

		// "Performance" Overview - Speed History's daily score snapshots (scan-completed + daily-
		// cron triggered).
		$this->container['performance_score_snapshot_recorder'] = new \VuloPilot\Performance\PerformanceScoreSnapshotRecorder();
		$this->container['security_score_snapshot_recorder']    = new \VuloPilot\Security\SecurityScoreSnapshotRecorder();
		// Schema Coverage table (Schema & Knowledge tab) refreshes itself
		// whenever a scan that includes the schema scanner completes.
		$this->container['schema_coverage_analyzer'] = new \VuloPilot\SeoVisibility\SchemaCoverageAnalyzer();
		add_action( 'vulopilot_scan_completed', array( $this->container['schema_coverage_analyzer'], 'refresh_after_scan' ), 30 );
		$this->container['performance_request_logger'] = new \VuloPilot\Performance\PerformanceRequestLogger();
		$this->container['performance_optimizations']  = new \VuloPilot\Performance\PerformanceOptimizations();

		// "Performance" Overview's PerformanceScoreCard.tsx redesign.
		$this->container['psi_fetcher']            = new \VuloPilot\Performance\PageSpeedInsightsFetcher();
		$this->container['core_web_vitals_beacon'] = new \VuloPilot\Performance\CoreWebVitalsBeacon();

		// "Performance" › Slow Pages - real per-page load-time checks (plus real per-page PSI
		// mobile/desktop scores when a psi_api_key is configured).
		$this->container['page_speed_scanner'] = new \VuloPilot\Performance\PageSpeedScanner();

		// Protect My Site's Malware/Firewall/Login Protection/Backups/ Recovery tiles.
		$this->container['login_protection_guard'] = new \VuloPilot\Security\LoginProtectionGuard();
		$this->container['firewall_guard']         = new \VuloPilot\Security\FirewallGuard();
		$this->container['backup_manager']         = new \VuloPilot\SiteHealth\BackupManager();
		$this->container['backup_scheduler']       = new \VuloPilot\SiteHealth\BackupScheduler();

		$this->container['backup_storage_manager'] = new \VuloPilot\SiteHealth\BackupStorageManager();

		$this->container['extension_manager'] = new \VuloPilot\Sdk\ExtensionManager();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( VuloPilotCli::class, 'register' ) );
		}

		do_action( 'vulopilot_loaded' );
	}

	/**
	 * Magic getter for the container.
	 *
	 * @param string $class_name Container key to retrieve.
	 * @return mixed
	 * @throws \Exception If the requested key does not exist in the container.
	 */
	public function __get( $class_name ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
		if ( array_key_exists( $class_name, $this->container ) ) {
			return $this->container[ $class_name ];
		}

		throw new \Exception( sprintf( 'Call to unknown class %s.', esc_html( $class_name ) ) );
	}

	/**
	 * Magic setter for the container.
	 *
	 * @param string $class_name Container key to store under.
	 * @param mixed  $value      Value to store.
	 * @return void
	 */
	public function __set( $class_name, $value ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
		$this->container[ $class_name ] = $value;
	}

	/**
	 * Returns the single instance of this class, creating it if necessary.
	 *
	 * @param string $file Main plugin file path.
	 * @return self
	 */
	public static function init( $file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
		}

		return self::$instance;
	}
}
