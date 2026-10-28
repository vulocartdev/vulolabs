# Settings System, Integrations and Notifications

How settings are stored, served and rendered, and the connection classes for Google and VuloCloud. User view: [../user/SETTINGS.md](../user/SETTINGS.md).

## Storage

- One option: `vulopilot_settings`. Read it as `wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS )`.
- Per-user data (dashboard layout) uses user meta `vulopilot_dashboard_widget_layout`.
- Secrets (API keys, OAuth tokens) are stored encrypted by the connection classes.
- REST: `classes/Settings/Rest/Settings.php` reads and saves the option; the React settings screens auto-save per field.

## How settings screens are built

Each settings tab is a file in `src/components/Settings` (or a subfolder) exporting an object with `id`, `priority`, `headerTitle`, `settingTitle`, `modal` (a list of fields) and optional `settingAction`. `src/services/templateService.ts` imports every file with `require.context`; a `FolderPriority.ts` file in a folder sets that folder's position and turns the folder into a group. Current order: Business Information, SEO, Scanning, Automation, Reports, Notifications, Integrations, Backups, Developer Tools, Modules.

Field types come from the `zyra` UI kit. A field's `key` is the settings key; `moduleEnabled` hides a field unless that module is active; `proSetting` marks a field as belonging to an add-on.

Adding a setting:

1. Add a default to `Utill::VULOPILOT_SETTINGS_DEFAULTS`.
2. Add a field with the same `key` to the right tab file.
3. Read it in PHP with the merge above.

## Integrations

| Class | What it does |
|---|---|
| `Settings\GoogleServicesConnection` | Google OAuth (authorize, token exchange, refresh) and the Search Console / Analytics / AdSense scopes |
| `Settings\GoogleOAuthBrokerClient` | Optional broker flow so a site never needs its own Google client secret |
| `Settings\GoogleSearchConsoleOAuthCallbackHandler` | Handles the OAuth redirect |
| `Settings\GoogleAnalyticsClient` / `GoogleAnalyticsTracker` | GA4 Data API reads; outputs `gtag.js` through the enqueue API |
| `Settings\GoogleAdSenseClient` | Read-only AdSense data |
| `Settings\WebmasterToolsManager` | Outputs verification `meta` tags; custom tags are limited to `meta` |
| `SeoVisibility\TagManagerService` | GTM head script via `wp_add_inline_script()` and the `<noscript>` body fallback |
| `AiAssistant\VuloCloudAccountConnection`, `AiCreditsConnection` | VuloCloud login and credits |
| `AiAssistant\ConnectBrokerCallbackHandler` | VuloCloud connect callback |
| `AiAssistant\AiRequestSender` | Sends AI requests through VuloCloud |

Every outside host is documented in the readme's External services section; keep it in sync when you add a call.

## Notifications

Alert groups (AI crawler, security, visibility, critical issue) are defined in `src/components/Settings/Notifications.ts` and evaluated by `AiAssistant`/monitor classes that read the same option keys. Channels: email and in-dashboard. Delivery frequency values: `immediate`, `daily_digest`, `weekly_digest`.

## Settings keys (SEO area)

| Setting key | Default |
|---|---|
| `flag_orphan_pages` | `array( 'flag_orphan_pages' )` |
| `thin_content_word_threshold` | `300` |
| `flag_missing_featured_image` | `array( 'flag_missing_featured_image' )` |
| `flag_missing_meta_description` | `array( 'flag_missing_meta_description' )` |
| `flag_duplicate_titles` | `array( 'flag_duplicate_titles' )` |
| `flag_missing_alt_text` | `array( 'flag_missing_alt_text' )` |
| `flag_broken_images` | `array( 'flag_broken_images' )` |
| `flag_broken_links` | `array( 'flag_broken_links' )` |
| `content_readability_min_score` | `50` |
| `sitemap_enabled` | `array( 'sitemap_enabled' )` |
| `sitemap_links_per_page` | `200` |
| `sitemap_include_images` | `array()` |
| `sitemap_include_featured_images` | `array()` |
| `sitemap_exclude_posts` | `''` |
| `sitemap_exclude_terms` | `''` |
| `sitemap_xml_post_types` | `array( 'post', 'page', 'attachment', 'product' )` |
| `sitemap_xml_taxonomies` | `array( 'category', 'post_tag', 'product_cat', 'product_tag' )` |
| `html_sitemap_enabled` | `array( 'html_sitemap_enabled' )` |
| `html_sitemap_display_format` | `'list'` |
| `html_sitemap_sort_by` | `'published_date'` |
| `html_sitemap_show_dates` | `array( 'html_sitemap_show_dates' )` |
| `html_sitemap_item_titles` | `'post_title'` |
| `indexnow_post_types` | `array( 'post', 'page', 'product' )` |
| `indexnow_api_key` | `''` |
| `robots_auto_generate` | `array( 'robots_auto_generate' )` |
| `flag_ai_crawler_blocked_pages` | `array( 'flag_ai_crawler_blocked_pages' )` |
| `canonical_url_enabled` | `array()` |
| `social_meta_tags_enabled` | `array()` |
| `title_separator` | `'\|'` |
| `title_format_home` | `'%site_title% %sep% %site_description%'` |
| `title_format_post` | `'%post_title% %sep% %site_title%'` |
| `title_format_page` | `'%page_title% %sep% %site_title%'` |
| `title_format_category` | `'%category_title% %sep% %site_title%'` |
| `title_format_tag` | `'%tag_title% %sep% %site_title%'` |
| `title_format_search` | `'Search results for "%search_term%" %sep% %site_title%'` |
| `title_format_archive` | `'%archive_title% %sep% %site_title%'` |
| `description_format_home` | `'%site_description%'` |
| `description_format_post` | `'%post_title% %sep% %site_description%'` |
| `description_format_page` | `'%page_title% %sep% %site_description%'` |
| `description_format_category` | `'%category_title% %sep% %site_description%'` |
| `description_format_tag` | `'%tag_title% %sep% %site_description%'` |
| `description_format_search` | `'Search results for "%search_term%" %sep% %site_description%'` |
| `description_format_archive` | `'%archive_title% %sep% %site_description%'` |
| `enable_redirect_manager` | `array( 'enable_redirect_manager' )` |
| `auto_redirect_on_slug_change` | `array( 'auto_redirect_on_slug_change' )` |
| `log_404s` | `array( 'log_404s' )` |
| `brand_about_page_min_words` | `80` |

## Settings keys (other areas)

| Setting key | Default |
|---|---|
| `automation_cooldown_minutes` | `60` |
| `automation_max_retries` | `0` |
| `automation_retry_delay_minutes` | `5` |
| `automation_mode` | `'suggest'` |
| `ai_change_approval_mode` | `'always'` |
| `enable_wcag_scanner` | `array( 'enable_wcag_scanner' )` |
| `accessibility_audit_frequency` | `'daily'` |
| `target_wcag_level` | `'2.1_aa'` |
| `default_report_format` | `'pdf'` |
| `default_report_period_days` | `30` |
| `enable_malware_scanner` | `array( 'enable_malware_scanner' )` |
| `enable_accessibility_scanning` | `array( 'enable_accessibility_scanning' )` |
| `broken_link_check_frequency` | `'daily'` |
| `enable_crawler_tracking` | `array( 'enable_crawler_tracking' )` |
| `log_retention` | `'30'` |
| `crawler_volume_drop_threshold_percent` | `50` |
| `crawler_alerts` | `array( ... )` |
| `crawler_alert_last_test_sent` | `''` |
| `enable_debug_logging` | `array()` |

## Class reference

| Class | File | What it does |
|---|---|---|
| `GoogleAdSenseClient` | `classes/Settings/GoogleAdSenseClient.php` | Real Google AdSense Management API (v2) client - backs the "Analytics" settings panel's own AdSense account dropdown (GoogleServicesPanel.tsx), reusing GoogleServicesConnection's shared OAuth token the same way GoogleAnalyticsClie |
| `GoogleAnalyticsClient` | `classes/Settings/GoogleAnalyticsClient.php` | Real Google Analytics Admin API (GA4) client - backs the "Analytics" settings panel's own Account/Property/Data Stream dropdowns (GoogleServicesPanel.tsx), reusing GoogleServicesConnection's shared OAuth token rather than its own  |
| `GoogleAnalyticsTracker` | `classes/Settings/GoogleAnalyticsTracker.php` | Real `gtag.js` output on the public-facing site - the "Analytics" settings panel's own "Install analytics code"/"Anonymize IP addresses"/"Self-Hosted Analytics JS File"/"Exclude Logged-in users" toggles (GoogleServicesPanel.tsx) a |
| `GoogleOAuthBrokerClient` | `classes/Settings/GoogleOAuthBrokerClient.php` | HTTP client for VuloCloud's `/plugin/google/*` broker endpoints - the real fix for the "Redirect URI scaling" trade-off documented in config.php: VuloCloud holds the ONE Google Cloud OAuth Client actually registered with Google (i |
| `GoogleSearchConsoleOAuthCallbackHandler` | `classes/Settings/GoogleSearchConsoleOAuthCallbackHandler.php` | Handles Google's real OAuth redirect back to this site (`admin-post.php?action=vulopilot_gsc_oauth_callback` - GoogleServicesConnection::get_redirect_uri()'s own exact URL). |
| `GoogleServicesConnection` | `classes/Settings/GoogleServicesConnection.php` | Real Google OAuth 2.0 connection shared by Search Console, Analytics (GA4), and AdSense - one "Connect Google Services" button/consent screen covering all three read scopes at once, matching the reference flow (a single connect st |
| `WebmasterToolsManager` | `classes/Settings/WebmasterToolsManager.php` | - |
| `GoogleServices` | `classes/Settings/Rest/GoogleServices.php` | - |
| `Settings` | `classes/Settings/Rest/Settings.php` | GET/POST /settings backs src/pages/Settings/Settings.tsx, now built on zyra's real settings framework (`InputRenderer`/`NavigatorComponent`, `getAvailableSettings`/`getSettingById` from zyra's core module - see the free vulolabs p |
| `AIResponse` | `classes/AiAssistant/AIResponse.php` | The response returned by the VuloCloud AI API, including the credits consumed by the request. |
| `ActionExecutionResult` | `classes/AiAssistant/ActionExecutionResult.php` | The outcome of an AIActionInterface::execute() call. |
| `ActionPreview` | `classes/AiAssistant/ActionPreview.php` | The human-facing preview an AIActionInterface::build_preview() returns, shown to the user before they approve an ActionRunner::propose() call. |
| `ActionRunRepository` | `classes/AiAssistant/ActionRunRepository.php` | - |
| `AiCreditsConnection` | `classes/AiAssistant/AiCreditsConnection.php` | The real "AI Credits" site connection - a genuine `ConnectedSite` credential (siteId + secret) minted by VuloCloud's own `contexts/vulopilot/ai-credits` bounded context. |
| `AiHistoryRepository` | `classes/AiAssistant/AiHistoryRepository.php` | - |
| `AiRequestSender` | `classes/AiAssistant/AiRequestSender.php` | The one path every real AI call in this plugin goes through: safety-validate the prompt, make sure this site is connected to VuloCloud, spend one request from the per-minute budget, send `{feature, prompt, site_tone}` to the VuloC |
| `ConnectBrokerCallbackHandler` | `classes/AiAssistant/ConnectBrokerCallbackHandler.php` | Handles the Connect broker's real redirect back to this site (`admin-post.php?action=vulopilot_connect_broker_callback` - AiCreditsConnection::get_broker_redirect_uri()'s own exact URL). |
| `CredentialEncryption` | `classes/AiAssistant/CredentialEncryption.php` | Encrypts/decrypts third-party secrets (Backups' S3/Drive credentials, the VuloCloud site secret, Google tokens) before they're stored. |
| `SiteTelemetryReporter` | `classes/AiAssistant/SiteTelemetryReporter.php` | Reports this site's own real WordPress/PHP/theme/plugin details to VuloCloud's generic `POST /connected-sites/ingest` endpoint - the one HTTP surface that fills in the Connected Sites detail page's "Site & Server"/"Plugin & Theme" |
| `SiteToneLearner` | `classes/AiAssistant/SiteToneLearner.php` | Keeps `vulopilot_site_tone` (the placeholder field added earlier this session - sent as a `site_tone` hint on every direct VuloCloud AI request, see AiAssistant\AiRequestSender) learned automatically from the site's own recent con |
| `VuloCloudAccountConnection` | `classes/AiAssistant/VuloCloudAccountConnection.php` | Read-only from this class's own side: `FrontendScripts::localize_scripts()` surfaces `get_status()` as `vulopilotAppLocalizer.vulocloud_connected`/ `vulocloud_account_email` (a display-only badge), and AiCreditsConnection::get_sta |
| `AiCredits` | `classes/AiAssistant/Rest/AiCredits.php` | - |
| `AiHistory` | `classes/AiAssistant/Rest/AiHistory.php` | GET /ai-history backs src/pages/AIAssistant/AIAssistant.tsx's table. |
| `VuloCloudAiConnection` | `classes/AiAssistant/Rest/VuloCloudAiConnection.php` | - |

Hooks and routes registered by these classes:

- `GoogleAnalyticsTracker` - hooks: `wp_enqueue_scripts`
- `GoogleSearchConsoleOAuthCallbackHandler` - hooks: `admin_post_vulopilot_gsc_oauth_callback`
- `WebmasterToolsManager` - hooks: `wp_head`
- `GoogleServices` - routes: `/google-services/adsense-accounts`, `/google-services/analytics-accounts`, `/google-services/analytics-data-streams`, `/google-services/authorize-url`, `/google-services/disconnect`, `/google-services/search-console-sites`, `/google-services/select-adsense-account`, `/google-services/select-analytics-property`, `/google-services/select-search-console-site`, `/google-services/status`, `/google-services/test-connections`
- `Settings` - routes: `/`, `/settings`, `/settings/clear-cache`, `/settings/reset`, `/settings/reset-ai-visibility-scans`, `/settings/test-crawler-alert`, `/settings/test-email`, `/settings/test-pagespeed`, `/settings/test-report`, `/settings/verify-webmaster`
- `ConnectBrokerCallbackHandler` - hooks: `admin_post_vulopilot_connect_broker_callback`
- `SiteTelemetryReporter` - hooks: `init`
- `SiteToneLearner` - hooks: `save_post`
- `AiCredits` - routes: `/ai-credits/disconnect`, `/ai-credits/refresh-balance`, `/ai-credits/status`
- `AiHistory` - routes: `/ai-history`
- `VuloCloudAiConnection` - routes: `/vulocloud-ai-connection`, `/vulocloud-ai-connection/broker-authorize-url`
