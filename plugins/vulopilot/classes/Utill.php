<?php
/**
 * Utill class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot;

defined( 'ABSPATH' ) || exit;

/**
 * VuloPilot Utill class.
 *
 * @class       Utill class
 * @version     1.0.0
 * @author      VuloLabs
 */
class Utill {

	/**
	 * Custom $wpdb table names, keyed by short entity id.
	 *
	 * @var array
	 */
	const TABLES = array(
		'scan'               => 'vulopilot_scans',
		'scan_finding'       => 'vulopilot_scan_findings',
		'automations'        => 'vulopilot_automations',
		'automations_run'    => 'vulopilot_automations_runs',
		'ai_history'         => 'vulopilot_ai_history',
		// Full, untruncated AI Copilot chat threads (RecentConversationsCard.tsx's "click to load
		// full history" feature).
		'ai_conversation'    => 'vulopilot_ai_conversations',
		'report'             => 'vulopilot_reports',
		'activity_log'       => 'vulopilot_activity_logs',
		'ai_action_run'      => 'vulopilot_ai_action_runs',
		'crawler_visit'      => 'vulopilot_crawler_visits',
		'redirect'           => 'vulopilot_redirects',
		'not_found_log'      => 'vulopilot_not_found_logs',
		'snapshot'           => 'vulopilot_snapshots',
		'performance_sample' => 'vulopilot_performance_samples',
		'page_speed'         => 'vulopilot_page_speed',
		// Protect My Site's Malware/Firewall/Login Protection/Backups/ Recovery tiles - real,
		// always-on core features, not a Modules-page module.
		'security_event'     => 'vulopilot_security_events',
		'backup'             => 'vulopilot_backups',
	);

	/**
	 * Option keys used by the bootstrap/Install flow.
	 *
	 * @var array
	 */
	const VULOPILOT_OTHER_SETTINGS = array(
		'run_installer'              => 'vulopilot_run_installer',
		'plugin_db_version'          => 'vulopilot_version',
		'crawler_alert_known_bots'   => 'vulopilot_crawler_alert_known_bots',
		// Per alert-type digest bookkeeping (last-sent timestamp + pending items accumulated since
		// then) for the "Daily digest"/"Weekly digest" frequency options.
		'crawler_alert_digest_state' => 'vulopilot_crawler_alert_digest_state',
	);

	/**
	 * Option name for VuloPilot's plain settings - deliberately a single wp_options row
	 * (an array), not a custom table.
	 *
	 * @var string
	 */
	const VULOPILOT_SETTINGS_KEY = 'vulopilot_settings';

	/**
	 * @var array
	 */
	const VULOPILOT_SETTINGS_DEFAULTS = array(
		'automatic_site_scan'                   => 'enabled',
		'scan_frequency'                        => 'daily',
		'keep_data_uninstall'                   => 'keep_data',
		'anonymous_usage_data'                  => 'disabled',
		// A short, freeform phrase describing this site's writing voice, sent as a `site_tone`
		// hint on every AI request (AI\AiRequestSender).
		'site_tone'                             => '',
		'site_tone_source'                      => 'auto',
		// Notifications.
		'notification_email'                    => '',
		// "Last test email sent successfully on ..." - set by Settings::send_test_email().
		'email_last_test_sent'                  => '',
		'notify_on_critical_findings'           => array(),
		// Settings → Notifications → Website Alerts' own "Notify me about" checklist.
		'critical_alert_types'                  => array( 'security', 'availability', 'performance', 'seo', 'other' ),
		'email_on_crawler_alerts'               => array(),
		// Settings → Notifications → Visibility Alerts' own master switch.
		'email_on_visibility_alerts'            => array( 'email_on_visibility_alerts' ),
		'visibility_alerts'                     => array(
			'geo'   => array(
				'enable'    => false,
				'threshold' => 5,
			),
			'brand' => array(
				'enable'    => false,
				'threshold' => 5,
			),
			'kg'    => array(
				'enable'    => false,
				'threshold' => 5,
			),
		),
		'email_from_name'                       => '',
		'email_from_address'                    => '',
		// Automation - replaces AutomationEngine's previously-hardcoded
		// COOLDOWN_MINUTES constant.
		'automation_cooldown_minutes'           => 60,
		'automation_max_retries'                => 0,
		'automation_retry_delay_minutes'        => 5,
		'automation_mode'                       => 'suggest',
		'auto_fix_max_impact'                   => 'low',
		'ai_change_approval_mode'               => 'always',
		'security_scan_frequency'               => 'daily',
		'security_alerts_enabled'               => array(),
		'security_alert_email'                  => '',
		'security_alert_min_severity'           => 'high',
		'security_alert_types'                  => array( 'vulnerabilities', 'malware', 'failed_login', 'new_user', 'file_changes', 'ssl_certificate' ),
		'enable_integrity_monitoring'           => array( 'enable_integrity_monitoring' ),
		'integrity_monitoring_max_files'        => 2000,
		// "WCAG Scanner" - same granular per-scanner toggle shape as the security scanners above.
		'enable_wcag_scanner'                   => array( 'enable_wcag_scanner' ),
		'accessibility_audit_frequency'         => 'daily',
		// Settings → Scanning → Accessibility's own "WCAG level" row.
		'target_wcag_level'                     => '2.1_aa',
		'inventory_stockout_threshold_days'     => 7,
		'enable_mcp_server'                     => array(),
		// Reports.
		'default_report_format'                 => 'pdf',
		'default_report_period_days'            => 30,
		// "Last test report sent on ..." - set by Settings::send_test_report(), read back by
		// ReportTestPanel.tsx on load so that line survives a page refresh.
		'report_last_test_sent'                 => '',
		// Security.
		'enable_rest_api_scanner'               => array( 'enable_rest_api_scanner' ),
		'enable_xmlrpc_scanner'                 => array( 'enable_xmlrpc_scanner' ),
		'enable_security_headers_scanner'       => array( 'enable_security_headers_scanner' ),
		'enable_exposed_files_scanner'          => array( 'enable_exposed_files_scanner' ),
		// Free scanners - same granular per-scanner toggle shape as the four above.
		'enable_weak_password_scanner'          => array( 'enable_weak_password_scanner' ),
		'enable_basic_vulnerabilities_scanner'  => array( 'enable_basic_vulnerabilities_scanner' ),
		'enable_core_file_integrity_scanner'    => array( 'enable_core_file_integrity_scanner' ),
		// Protect My Site's Malware/Firewall/Login Protection/Backups tiles.
		'enable_malware_scanner'                => array( 'enable_malware_scanner' ),
		// Read by LoginProtectionGuard - real brute-force lockout
		// enforced via the `authenticate` filter, not just detection.
		'enable_login_protection'               => array( 'enable_login_protection' ),
		'login_max_attempts'                    => 5,
		'login_lockout_minutes'                 => 15,
		// Read by FirewallGuard - `enable_firewall` always logs matched requests (safe, never
		// blocks anyone).
		'enable_firewall'                       => array( 'enable_firewall' ),
		'enable_firewall_blocking'              => array(),
		// Read by BackupManager/BackupScheduler - real DB+file archives.
		'enable_automatic_backups'              => array(),
		'backup_frequency'                      => 'disabled',
		'backup_retention_count'                => 5,
		// Which real storage destination a just-completed backup uploads to.
		'backup_storage_destination'            => 'local',
		// Scanner-category kill switches - each gates every scanner registered under that category
		// string, not just one check.
		'enable_accessibility_scanning'         => array( 'enable_accessibility_scanning' ),
		'enable_woocommerce_scanning'           => array( 'enable_woocommerce_scanning' ),
		// Scanning > SEO - granular, per-check toggles (readme's SEO Optimization pillar).
		'flag_orphan_pages'                     => array( 'flag_orphan_pages' ),
		// Read by ThinContentScanner as its minimum word
		// count instead of a hardcoded constant.
		'thin_content_word_threshold'           => 300,
		'flag_missing_featured_image'           => array( 'flag_missing_featured_image' ),
		// Moved back OUT of the nested `content_search_scans.seo` row (see that setting's own
		// docblock below) and into flat, standalone keys.
		'flag_missing_meta_description'         => array( 'flag_missing_meta_description' ),
		'flag_duplicate_titles'                 => array( 'flag_duplicate_titles' ),
		// Same "moved back out to a flat key" story as the two directly above, for the 'images'
		// row's own two granular flags.
		'flag_missing_alt_text'                 => array( 'flag_missing_alt_text' ),
		'flag_broken_images'                    => array( 'flag_broken_images' ),
		// Same "moved back out to a flat key" story again, for the 'links' row's own one granular
		// flag.
		'flag_broken_links'                     => array( 'flag_broken_links' ),
		// Scanning > Content & Search - Settings → Scanning → "Content & Search" tab's own 5
		// toggle-card rows (seo/images/ links/schema/readability).
		'content_search_scans'                  => array(
			'seo'         => array(
				'enable' => true,
			),
			'images'      => array(
				'enable' => true,
			),
			'links'       => array(
				'enable' => true,
			),
			// Covers both SchemaScanner (presence) and StructuredDataValidationScanner (validity).
			'schema'      => array(
				'enable' => true,
			),
			'readability' => array(
				'enable' => true,
			),
		),
		// Same "moved back out to a flat key" story again, for the 'readability' row's own one
		// granular field.
		'content_readability_min_score'         => 50,
		// Read by BrokenLinksScanner to self-rate-limit - 'daily'/'weekly'.
		'broken_link_check_frequency'           => 'daily',
		// Read by BrokenImagesScanner - same real gate/frequency shape as
		// broken_link_check_frequency directly above, for `img` instead of `a`.
		'broken_image_check_frequency'          => 'daily',
		// Scanning > Sitemap - moved out of the SEO tab into its own dedicated Settings sub-tab
		// (Scanning/Sitemap.ts).
		'sitemap_enabled'                       => array( 'sitemap_enabled' ),
		// Max URLs per sitemap page - core's own default is 2000; this
		// overrides `wp_sitemaps_max_urls` when set.
		'sitemap_links_per_page'                => 200,
		// Neither of these has a real backing implementation.
		'sitemap_include_images'                => array(),
		'sitemap_include_featured_images'       => array(),
		// Comma-separated post/term IDs - read by SitemapManager and applied via
		// `wp_sitemaps_posts_query_args`/ `wp_sitemaps_taxonomies_query_args`
		// (`post__not_in`/`exclude`).
		'sitemap_exclude_posts'                 => '',
		'sitemap_exclude_terms'                 => '',
		// Which real post types/taxonomies are included.
		'sitemap_xml_post_types'                => array( 'post', 'page', 'attachment', 'product' ),
		'sitemap_xml_taxonomies'                => array( 'category', 'post_tag', 'product_cat', 'product_tag' ),
		// Read by SitemapManager - keep the sitemap to URLs meant for search results.
		'sitemap_skip_single_author'            => array( 'sitemap_skip_single_author' ),
		// Read by the sitemap health check (SitemapValidationScanner).
		'sitemap_health_enabled'                => array( 'sitemap_health_enabled' ),
		'sitemap_health_last_run'               => '',
		'sitemap_health_last_problems'          => 0,
		// Read by HtmlSitemapRenderer - a real, human-readable `[vulopilot_html_sitemap]` shortcode
		// (mockup's own "Shortcode" settings row).
		'html_sitemap_enabled'                  => array( 'html_sitemap_enabled' ),
		'html_sitemap_display_format'           => 'list',
		'html_sitemap_sort_by'                  => 'published_date',
		'html_sitemap_show_dates'               => array( 'html_sitemap_show_dates' ),
		// 'post_title' or 'seo_title'; the latter reads PostSeoMetaFields::META_KEYS['social_title'] per
		// post/term (the closest "SEO title" field; there's no separate one), falling back to the title.
		'html_sitemap_item_titles'              => 'post_title',
		// Scanning > SEO & Content > Tag Manager.
		'tag_manager_enabled'                   => array(),
		'tag_manager_container_id'              => '',
		// Scanning > Webmaster Tools.
		'webmaster_google_verification'         => '',
		// Connections > Google Services (GoogleServicesPanel.tsx).
		'ga_install_tracking_code'              => array(),
		'ga_anonymize_ip'                       => array(),
		'ga_self_hosted_js'                     => array(),
		'ga_exclude_logged_in_users'            => array(),
		'webmaster_bing_verification'           => '',
		'webmaster_baidu_verification'          => '',
		'webmaster_yandex_verification'         => '',
		'webmaster_pinterest_verification'      => '',
		'webmaster_norton_verification'         => '',
		// Free-form custom `meta` tags - WebmasterToolsManager sanitizes this with an allowlist
		// limited to `meta` tags only (mockup's own "Only meta tags are allowed" copy) before
		// echoing it.
		'webmaster_custom_tags'                 => '',
		// Connections > Site Verification (SiteVerificationPanel.tsx).
		'webmaster_google_verified_at'          => '',
		'webmaster_bing_verified_at'            => '',
		'webmaster_pinterest_verified_at'       => '',
		// Scanning > Instant Indexing (IndexNow).
		'indexnow_post_types'                   => array( 'post', 'page', 'product' ),
		// Generated lazily (Settings::get_stored_settings(), same "can't call a method inside a
		// class const array" reasoning llms_txt_content's own comment gives) rather than defaulted
		// here.
		'indexnow_api_key'                      => '',
		// "Performance" Overview's PerformanceScoreCard.tsx - a real, user-supplied Google
		// PageSpeed Insights API key (free tier available).
		'psi_api_key'                           => '',
		// Settings → Connections → PageSpeed Insights' own "Daily API Limit".
		'psi_daily_limit'                       => 1000,
		// Read by RobotsTxtManager - appends a `Sitemap:` line to WordPress core's own virtual
		// robots.txt via the `robots_txt` filter.
		'robots_auto_generate'                  => array( 'robots_auto_generate' ),
		// Read by AiCrawlerBlockedPagesScanner - flags real published pages robots.txt disallows
		// for one specific known AI bot.
		'flag_ai_crawler_blocked_pages'         => array( 'flag_ai_crawler_blocked_pages' ),
		'canonical_url_enabled'                 => array(),
		'social_meta_tags_enabled'              => array(),
		// Scanning > SEO > Open Graph - fallback used by SocialMetaTagsManager only when a page
		// has no post thumbnail/site icon of its own.
		'social_default_image_id'               => 0,
		'social_twitter_card_type'              => 'summary_large_image',
		// Newline-separated profile URLs - fed into Pro's Organization schema `sameAs` array
		// alongside entity_business_name/entity_business_logo_id above.
		'social_profile_urls'                   => '',
		// Settings → Site Identity → Title Formats, read by TitleFormatter
		// (`pre_get_document_title`).
		'site_identity_enabled'                 => 'enabled',
		'title_separator'                       => '|',
		'title_format_home'                     => '%site_title% %sep% %site_description%',
		'title_format_post'                     => '%post_title% %sep% %site_title%',
		'title_format_page'                     => '%page_title% %sep% %site_title%',
		'title_format_category'                 => '%category_title% %sep% %site_title%',
		'title_format_tag'                      => '%tag_title% %sep% %site_title%',
		'title_format_search'                   => 'Search results for "%search_term%" %sep% %site_title%',
		'title_format_archive'                  => '%archive_title% %sep% %site_title%',
		// Author archives aren't covered by the generic 'archive' bucket above - a real,
		// dedicated context (TitleFormatter's own 'author' context key), same as Rank
		// Math's/Yoast's own separate author-archive title/description fields.
		'title_format_author'                   => '%author_name% %sep% %site_title%',
		// Same feature, same `TitleFormatter::resolve()`/`%variable%` engine as `title_format_*`
		// above.
		'description_format_home'               => '%site_description%',
		'description_format_post'               => '%post_title% %sep% %site_description%',
		'description_format_page'               => '%page_title% %sep% %site_description%',
		'description_format_category'           => '%category_title% %sep% %site_description%',
		'description_format_tag'                => '%tag_title% %sep% %site_description%',
		'description_format_search'             => 'Search results for "%search_term%" %sep% %site_description%',
		'description_format_archive'            => '%archive_title% %sep% %site_description%',
		'description_format_author'             => '',
		// Read by RedirectManager (the first two) and NotFoundLogger (the third).
		'enable_redirect_manager'               => array( 'enable_redirect_manager' ),
		'auto_redirect_on_slug_change'          => array( 'auto_redirect_on_slug_change' ),
		'log_404s'                              => array( 'log_404s' ),
		// AI Visibility / GEO.
		'enable_llms_txt'                       => array( 'enable_llms_txt' ),
		// Empty means "not customized yet" - Settings::get_stored_settings() fills this with
		// GeoAnalysis\LlmsTxtGenerator::generate()'s live output for display until an admin edits
		// and saves their own.
		'llms_txt_content'                      => '',
		// Read by modules/GeoAnalysis/Module.php's save_post hook.
		'llms_auto_regen'                       => array( 'llms_auto_regen' ),
		// Read by GeoAnalysis\LlmsTxtGenerator::generate() to decide which
		// sections to build at all.
		'llms_include_types'                    => array( 'pages', 'posts' ),
		// Settings → Scanning → AI Visibility's own `expandable-panel` field (AiVisibility.ts).
		'ai_visibility_scans'                   => array(
			'structure'    => array(
				'enable' => true,
			),
			// `enable` gates whether GeoAnalyzer's AI-judged "entity_coverage" dimension is scored for a post.
			// It needs per-post AI judgment, so it can't be a deterministic scanner's kill switch like 'structure'.
			'entity'       => array(
				'enable'       => true,
				'min_mentions' => 2,
			),
			'freshness'    => array(
				'enable'       => true,
				'stale_months' => 12,
			),
			// Read by Geo\Scanners\GeoSummaryBlockScanner - GEO scanning has no whole-category
			// kill switch (unlike SEO/Accessibility/ e-commerce above).
			'answer_first' => array(
				'enable'    => true,
				'min_words' => 200,
			),
			// `enable` gates Geo\Scanners\GeoCitationOpportunityScanner (its own findings-list
			// check).
			'evidence'     => array(
				'enable'          => true,
				'min_data_points' => 3,
			),
		),
		'geo_competitor_urls'                   => array(),
		// Scanning > Brand Intelligence.
		'brand_about_page_min_words'            => 80,
		// AI Crawler Traffic Monitoring.
		'enable_crawler_tracking'               => array( 'enable_crawler_tracking' ),
		'log_retention'                         => '30',
		'crawler_volume_drop_threshold_percent' => 50,
		// Notifications > AI Crawler Alerts' "Notify me about".
		'alert_channels'                        => array( 'email', 'dashboard' ),
		'crawler_alerts'                        => array(
			'blocked'        => array(
				'enable'    => true,
				'frequency' => 'immediate',
			),
			'access_limited' => array(
				'enable'    => true,
				'frequency' => 'immediate',
			),
			'traffic_drop'   => array(
				'enable' => true,
			),
			'inactive'       => array(
				'enable'         => true,
				'days_threshold' => '7',
			),
			'new_bot'        => array(
				'enable'    => true,
				'frequency' => 'weekly_digest',
			),
		),
		// "Last test alert sent successfully on ..." - set by Settings::send_test_crawler_alert().
		'crawler_alert_last_test_sent'          => '',
		// Scanning > Entity Extraction.
		'entity_business_type'                  => '',
		// Newline-separated page URLs/ids - this codebase has no existing Service concept to derive
		// these from automatically.
		'entity_service_pages'                  => '',
		// Newline-separated `Name | Address` lines - same reasoning as 'entity_service_pages'
		// above.
		'entity_business_locations'             => '',
		// Owner-provided override - EntityExtractor falls back to the first extracted
		// organization's name, then the site title, when this is empty.
		'entity_business_name'                  => '',
		// Attachment id - same convention as PostSeoMetaFields::META_KEYS['social_image_id'].
		'entity_business_logo_id'               => 0,
		// Scanning > SEO > Open Graph - read by SocialMetaTagsManager, output as
		// fb:app_id/fb:admin_id meta tags (Facebook's own app-association tags, not an API
		// credential - the still-unused App Secret purposely has no field here).
		'facebook_app_id'                       => '',
		'facebook_admin_id'                     => '',
		// Scanning > SEO > Robots - read by DefaultRobotsManager. Each is a real, narrow condition
		// (not Rank Math's single freeform "default robots meta" value, which is almost always
		// 'index,follow' and rarely actionable) - 'robots_noindex_sitewide' is the one blunt
		// "noindex everything" switch, useful for staging sites.
		'robots_noindex_sitewide'               => array(),
		'robots_noindex_search'                 => array(),
		'robots_noindex_empty_taxonomies'       => array(),
		'robots_noindex_archive_subpages'       => array(),
		'robots_noindex_paginated_pages'        => array(),
		'robots_noindex_password_protected'     => array(),
		'robots_noindex_author'                 => array(),
		'robots_noindex_author_without_posts'   => array( 'robots_noindex_author_without_posts' ),
		// Scanning > Sitemap > Authors.
		'sitemap_include_authors'               => array( 'sitemap_include_authors' ),
		// Comma-separated role slugs/user IDs.
		'sitemap_exclude_author_roles'          => '',
		'sitemap_exclude_author_ids'            => '',
		// Settings → Site Identity → Title Formats - per-post-type/per-taxonomy overrides for any
		// post type/taxonomy other than the built-in post/page/category/tag (which keep their own
		// flat title_format_*/description_format_* fields above). Keyed by post type/taxonomy slug.
		'title_format_post_types'               => array(),
		'description_format_post_types'         => array(),
		'title_format_taxonomies'               => array(),
		'description_format_taxonomies'         => array(),
		// Advanced / Debug.
		'enable_debug_logging'                  => array(),
	);

	/**
	 * @var string[]
	 */
	const DASHBOARD_WIDGET_IDS = array(
		// registry.ts's own current MOCKUP_WIDGETS array order, exactly.
		'overall-score',
		'site-snapshot',
		'needs-attention',
		'crawler-traffic',
		'recent-activity',
		'ai-monitoring',
		'knowledge-graph-health',
		'mcp-server-status',
		// Known gap, not fixed here (out of scope for the ordering bug above).
		'vulopilot-activity',
		'automation-status',
		'score-breakdown',
		'key-pages',
		'run-audit',
		'recent-changes',
		'knowledge-graph',
		'health-timeline',
		'latest-reports',
		'brand-breakdown',
	);

	/**
	 * User meta key the Dashboard's widget layout (order + enabled flags) is stored under.
	 *
	 * @var string
	 */
	const DASHBOARD_LAYOUT_META_KEY = 'vulopilot_dashboard_widget_layout';

	/**
	 * Option name the active-modules list is stored under.
	 *
	 * @var string
	 */
	const ACTIVE_MODULES_DB_KEY = 'vulopilot_all_active_module_list';

	/**
	 * @param \Throwable $exception The exception to record.
	 * @return void
	 */
	public function log( \Throwable $exception ): void {
		$settings = wp_parse_args( get_option( self::VULOPILOT_SETTINGS_KEY, array() ), self::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['enable_debug_logging'] ) ) {
			return;
		}

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated behind an explicit, opt-in admin setting (Advanced tab), matching Reports\ReportGenerator::maybe_log_debug()'s existing pattern.
		error_log( sprintf( '[VuloPilot] %s', $exception->getMessage() ) );
	}

	/**
	 * Reads one key from the settings option, merged over VULOPILOT_SETTINGS_DEFAULTS.
	 *
	 * @param string $key     Settings array key to read.
	 * @param mixed  $default Fallback when the key isn't in the defaults either.
	 * @return mixed
	 */
	public static function get_setting( string $key, $default = null ) {
		$settings = wp_parse_args( get_option( self::VULOPILOT_SETTINGS_KEY, array() ), self::VULOPILOT_SETTINGS_DEFAULTS );

		return $settings[ $key ] ?? $default;
	}

	public function is_khali_dabba(): bool {
		return (bool) apply_filters( 'kothay_dabba_vulopilot', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberate, shared cross-plugin filter name (same kothay_dabba/kothay_dabba_{slug} pair vulocart/Utill.php and both *-pro plugins' check_pro_active() registrations use), not an accidental unprefixed hook.
	}

	/**
	 * Accepts both the current shape (`array<{name, url}>`) and the pre-`dynamic-row`
	 * shape (a single newline-separated string) so an older stored option value still
	 * works until it's next saved through the settings UI.
	 *
	 * @param array<int, array<string, mixed>>|string $stored The raw
	 *     `geo_competitor_urls` setting value.
	 * @return string[] Trimmed, non-empty URLs, in stored order.
	 */
	public static function competitor_urls( $stored ): array {
		if ( is_string( $stored ) ) {
			return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $stored ) ) ) );
		}

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$urls = array_map(
			static function ( $row ) {
				return is_array( $row ) ? trim( (string) ( $row['url'] ?? '' ) ) : '';
			},
			$stored
		);

		return array_values( array_filter( $urls ) );
	}
}
