<?php
/**
 * ThinkRankMigrator class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Migration;

use VuloPilot\SeoVisibility\PostSeoMetaFields;
use VuloPilot\SeoVisibility\RobotsTxtManager;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Imports everything VuloPilot can read from a free ThinkRank AI SEO install, in one migrator
 * (same shape as RankMathMigrator/YoastSeoMigrator/AllInOneSeoMigrator).
 *
 * Verified against the real distributed plugin's own source (installed on wp-env and read off
 * disk at `wp-content/plugins/thinkrank/includes/...`), not guessed - ThinkRank is not a single
 * JSON-blob-option plugin like the other three:
 *
 * - Per-post data lives in real postmeta keys (`includes/admin/class-metabox-manager.php`):
 *   `_thinkrank_seo_title`, `_thinkrank_meta_description`, `_thinkrank_canonical_url`,
 *   `_thinkrank_focus_keywords` (JSON array; `_thinkrank_focus_keyword` is the pre-2.x legacy
 *   singular key), `_thinkrank_robots_meta_enabled` + `_thinkrank_robots_meta` (JSON
 *   `{"noindex":bool,"nofollow":bool,...}`), `_thinkrank_og_title`, `_thinkrank_og_description`,
 *   `_thinkrank_og_image` (a URL, not an attachment id).
 *
 * - Sitewide settings live in a real custom table, `{prefix}thinkrank_seo_settings` - an EAV
 *   store (`context_type`, `context_id`, `setting_category`, `setting_key`, `setting_value`),
 *   not an option. Each `Abstract_SEO_Manager` subclass owns one `setting_category`
 *   (`includes/seo/class-*-manager.php`'s `parent::__construct('site_identity')` etc.); this
 *   migrator reads the `site_identity` and `sitemap` categories directly.
 *
 * - Per-post-type/taxonomy/archive title+description templates live in a single option,
 *   `thinkrank_global_seo_settings` (`includes/seo/class-content-type-settings.php`'s own
 *   doc comment confirms the option name and key shape), keyed by the real post type slug for a
 *   post type and by `taxonomy:{slug}`/`archive:author`/`archive:date`/`special:search` for
 *   everything else (`includes/abilities/settings/class-settings-key-map.php` documents every
 *   key actually read/written there).
 *
 * - robots.txt: ThinkRank writes its own managed block straight into the real physical
 *   `wp-content/robots.txt`/webroot `robots.txt` file when `robots_txt_enabled` is on (same
 *   architecture as Yoast's File Editor) - there's no separate content-only option to copy, so
 *   this migrator reads that physical file via `RobotsTxtManager`'s own convention, the same way
 *   `YoastSeoMigrator::migrate_robots_txt()` does, and carries the same physical-file warning.
 *
 * - Per-post schema type/custom schema JSON, from `_thinkrank_selected_schema_type` and
 *   `_thinkrank_schema_form_data` (`includes/admin/class-metabox-manager.php`) into VuloPilot's
 *   own matching postmeta, `_vulopilot_schema_type`/`_vulopilot_schema_json`
 *   (`PostSeoMetaFields::META_KEYS['schema_type']`/`'_vulopilot_schema_json'`).
 *
 * - Per-post AI content score, from ThinkRank's own `{prefix}thinkrank_seo_scores` table
 *   (`overall_score`, `suggestions`, `calculated_at`) into VuloPilot's own
 *   `_vulopilot_content_score` postmeta - the same JSON shape
 *   `VuloPilot\ContentOptimization\ValueObjects\ContentScore::to_array()` writes. ThinkRank has
 *   no `deterministic_score`/`topic_authority` equivalent (those are VuloPilot Content
 *   Intelligence's own AI-judged dimensions), so they're carried over as `null`/`0` rather than
 *   guessed.
 *
 * - llms.txt content, from the plain option `thinkrank_llms_txt_content`
 *   (`includes/seo/class-llms-txt-manager.php`'s own `CONTENT_OPTION` const).
 *
 * - IndexNow/Instant Indexing settings, from `thinkrank_instant_indexing_settings`
 *   (`{"api_key":"...","auto_submit_post_types":[...]}` - confirmed against the real option's
 *   live value on wp-env) into VuloPilot's own `indexnow_api_key`/`indexnow_post_types`.
 *
 * Not migrated (confirmed from source, not from the database alone):
 * - Webmaster/verification meta tags: ThinkRank has none. Search Console access is OAuth
 *   (`includes/integrations/class-google-search-console-client.php`), not a manual verification
 *   code field, so there is nothing of that shape to read.
 * - PageSpeed/Core Web Vitals history: ThinkRank's `{prefix}thinkrank_seo_performance` table
 *   stores site-level metric rows (`metric_type`/`metric_value`) with no page/URL column at all,
 *   while VuloPilot's own `{prefix}vulopilot_page_speed` is keyed per-URL - the two schemas have
 *   no common key to join on, so copying rows across would misattribute every metric to the
 *   wrong page.
 * - AI content briefs (`{prefix}thinkrank_content_briefs`): unlike the Content Score above, this
 *   has no VuloPilot equivalent to receive it.
 * - Redirects & 404 Monitor: the free plugin only ships the "Redirect this to" post/term field as
 *   a UI shim (`includes/seo/class-object-redirect.php`) that reads/writes through
 *   `thinkrank_object_redirect`/`thinkrank_object_redirect_save` filters Pro answers; with Pro
 *   inactive nothing answers them and nothing is ever stored, confirmed by there being no
 *   redirect/404 table or option in the free plugin's own schema (`includes/database/
 *   class-database-schema.php`) or disk tree.
 * - AI content tools, MCP server config, llms.txt, IndexNow keys, PageSpeed/Core Web Vitals
 *   monitoring, scheduled email reports, Schema Manager entries, multilingual settings, and every
 *   Pro-gated feature (Custom Schema, multi-location Local SEO, Rank Tracker, Broken Link
 *   Checker, News/Video sitemaps) - none has a VuloPilot equivalent to receive the data.
 *
 * @class       ThinkRankMigrator
 * @version     1.0.0
 * @author      VuloLabs
 */
class ThinkRankMigrator implements MigratorInterface {

	/**
	 * ThinkRank's own `title_separator` setting is a named key ('pipe', 'dash', ...), not a
	 * literal character - `Site_Identity_Manager::$title_separators`.
	 *
	 * @var array<string, string>
	 */
	private const SEPARATOR_SYMBOLS = array(
		'pipe'    => '|',
		'dash'    => '-',
		'bullet'  => '•',
		'colon'   => ':',
		'greater' => '>',
		'tilde'   => '~',
	);

	/**
	 * ThinkRank's Site Identity `%token%` template vocabulary
	 * (`class-settings-key-map.php`'s `template()` helper) into VuloPilot's own.
	 *
	 * @var array<string, string>
	 */
	private const SITE_IDENTITY_TOKEN_MAP = array(
		'%site_name%' => '%site_title%',
		'%tagline%'   => '%site_description%',
		'%separator%' => '%sep%',
		'%category%'  => '%category_title%',
	);

	/**
	 * ThinkRank's Global SEO (per-post-type/taxonomy/archive) `%token%` vocabulary
	 * (`class-global-seo-endpoint.php`) into VuloPilot's own. `%title%` is context-dependent -
	 * resolved separately per call in `translate_global_seo_template()`.
	 *
	 * @var array<string, string>
	 */
	private const GLOBAL_SEO_TOKEN_MAP = array(
		'%sitename%' => '%site_title%',
		'%sep%'      => '%sep%',
	);

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'thinkrank';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'ThinkRank AI SEO', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function count_available(): int {
		return $this->count_posts()
			+ $this->count_content_scores()
			+ ( empty( $this->build_settings_values() ) ? 0 : 1 )
			+ ( '' !== $this->read_physical_robots_txt() ? 1 : 0 );
	}

	/**
	 * @return int
	 */
	private function count_content_scores(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'thinkrank_seo_scores';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @inheritDoc
	 */
	public function migrate( bool $overwrite = false ): array {
		$migrated = 0;
		$skipped  = 0;
		$errors   = array();

		$this->migrate_posts( $overwrite, $migrated, $skipped, $errors );
		$this->migrate_content_scores( $overwrite, $migrated, $skipped, $errors );
		$this->migrate_settings( $overwrite, $migrated, $skipped );
		$this->migrate_robots_txt( $overwrite, $migrated, $skipped, $errors );

		return array(
			'migrated' => $migrated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	// ---------------------------------------------------------------------
	// Per-post SEO data (real postmeta keys).
	// ---------------------------------------------------------------------

	/**
	 * @return int
	 */
	private function count_posts(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			WHERE meta_key IN ('_thinkrank_seo_title', '_thinkrank_meta_description') AND meta_value != ''"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param bool  $overwrite Passed through from migrate().
	 * @param int   $migrated  Running total, by reference.
	 * @param int   $skipped   Running total, by reference.
	 * @param array $errors    Running list, by reference.
	 * @return void
	 */
	private function migrate_posts( bool $overwrite, int &$migrated, int &$skipped, array &$errors ): void {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key IN ('_thinkrank_seo_title', '_thinkrank_meta_description') AND meta_value != ''"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			try {
				$existing = get_post_meta( $post_id, PostSeoMetaFields::META_KEYS['focus_keyword'], true );

				if ( ! $overwrite && '' !== (string) $existing ) {
					++$skipped;
					continue;
				}

				$this->migrate_post_row( $post_id );
				++$migrated;
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( 'Post #%d: %s', $post_id, $e->getMessage() );
			}
		}
	}

	/**
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function migrate_post_row( int $post_id ): void {
		$canonical = (string) get_post_meta( $post_id, '_thinkrank_canonical_url', true );

		if ( '' !== $canonical ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['canonical_url'], $canonical );
		}

		$robots_enabled = (bool) get_post_meta( $post_id, '_thinkrank_robots_meta_enabled', true );
		$robots         = $robots_enabled ? get_post_meta( $post_id, '_thinkrank_robots_meta', true ) : array();

		// `register_post_meta()`'s own sanitize callback for this key JSON-encodes the value, so a
		// real site's `get_post_meta()` returns a JSON string here, not a PHP array - confirmed
		// against actual stored data on wp-env, not assumed from the registration schema alone.
		if ( is_string( $robots ) ) {
			$robots = json_decode( $robots, true );
		}

		$robots = is_array( $robots ) ? $robots : array();

		update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['robots_noindex'], ! empty( $robots['noindex'] ) );
		update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['robots_nofollow'], ! empty( $robots['nofollow'] ) );

		$og_title = (string) get_post_meta( $post_id, '_thinkrank_og_title', true );

		if ( '' !== $og_title ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['social_title'], $og_title );
		}

		$og_description = (string) get_post_meta( $post_id, '_thinkrank_og_description', true );

		if ( '' !== $og_description ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['social_description'], $og_description );
		}

		$og_image = (string) get_post_meta( $post_id, '_thinkrank_og_image', true );

		if ( '' !== $og_image ) {
			$attachment_id = attachment_url_to_postid( $og_image );

			if ( $attachment_id ) {
				update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['social_image_id'], $attachment_id );
			}
		}

		$keyword = $this->extract_focus_keyword( $post_id );

		if ( '' !== $keyword ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['focus_keyword'], $keyword );
		}

		$schema_type = (string) get_post_meta( $post_id, '_thinkrank_selected_schema_type', true );

		if ( '' !== $schema_type ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['schema_type'], $schema_type );
		}

		$schema_form_data = get_post_meta( $post_id, '_thinkrank_schema_form_data', true );
		$schema_json      = is_string( $schema_form_data ) ? $schema_form_data : wp_json_encode( $schema_form_data );
		$decoded_schema   = is_string( $schema_json ) ? json_decode( $schema_json, true ) : null;

		if ( is_array( $decoded_schema ) && ! empty( $decoded_schema ) ) {
			update_post_meta( $post_id, '_vulopilot_schema_json', wp_json_encode( $decoded_schema ) );
		}
	}

	/**
	 * Per-post AI content score, from ThinkRank's own `{prefix}thinkrank_seo_scores` table into
	 * the same JSON shape `ContentScore::to_array()` writes to `_vulopilot_content_score` -
	 * written directly as postmeta rather than by instantiating that class, same convention every
	 * other per-post field in this migrator follows.
	 *
	 * @param bool  $overwrite Passed through from migrate().
	 * @param int   $migrated  Running total, by reference.
	 * @param int   $skipped   Running total, by reference.
	 * @param array $errors    Running list, by reference.
	 * @return void
	 */
	private function migrate_content_scores( bool $overwrite, int &$migrated, int &$skipped, array &$errors ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'thinkrank_seo_scores';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// One row per post - its most recently calculated score.
		$rows = $wpdb->get_results(
			"SELECT t1.post_id, t1.overall_score, t1.suggestions, t1.calculated_at FROM {$table} t1
			INNER JOIN (SELECT post_id, MAX(calculated_at) AS max_calculated_at FROM {$table} GROUP BY post_id) t2
			ON t1.post_id = t2.post_id AND t1.calculated_at = t2.max_calculated_at" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;

			try {
				$existing = get_post_meta( $post_id, '_vulopilot_content_score', true );

				if ( ! $overwrite && '' !== (string) $existing ) {
					++$skipped;
					continue;
				}

				$suggestions = json_decode( (string) $row->suggestions, true );
				$suggestions = is_array( $suggestions ) ? array_values( array_map( 'strval', $suggestions ) ) : array();

				update_post_meta(
					$post_id,
					'_vulopilot_content_score',
					wp_json_encode(
						array(
							'post_id'             => $post_id,
							'deterministic_score' => null,
							'topic_authority'     => 0,
							'overall_score'       => (int) $row->overall_score,
							'suggestions'         => $suggestions,
							'generated_at'        => (string) $row->calculated_at,
						)
					)
				);
				++$migrated;
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( 'Content score for post #%d: %s', $post_id, $e->getMessage() );
			}
		}
	}

	/**
	 * `_thinkrank_focus_keywords` is a JSON array of keyword strings (2.x+); `_thinkrank_focus_keyword`
	 * (singular) is the pre-2.x single-string key, kept by ThinkRank itself as a legacy fallback.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function extract_focus_keyword( int $post_id ): string {
		$keywords = get_post_meta( $post_id, '_thinkrank_focus_keywords', true );

		if ( is_array( $keywords ) && ! empty( $keywords ) ) {
			return (string) reset( $keywords );
		}

		if ( is_string( $keywords ) ) {
			$decoded = json_decode( $keywords, true );

			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return (string) reset( $decoded );
			}
		}

		return (string) get_post_meta( $post_id, '_thinkrank_focus_keyword', true );
	}

	// ---------------------------------------------------------------------
	// robots.txt (physical file - same architecture as Yoast's File Editor).
	// ---------------------------------------------------------------------

	/**
	 * @param bool  $overwrite Passed through from migrate().
	 * @param int   $migrated  Running total, by reference.
	 * @param int   $skipped   Running total, by reference.
	 * @param array $errors    Running list, by reference.
	 * @return void
	 */
	private function migrate_robots_txt( bool $overwrite, int &$migrated, int &$skipped, array &$errors ): void {
		$content = $this->read_physical_robots_txt();

		if ( '' === $content ) {
			return;
		}

		$manager = new RobotsTxtManager();

		if ( '' !== $manager->get_custom_content() && ! $overwrite ) {
			++$skipped;
			return;
		}

		$manager->save_custom_content( $content );
		++$migrated;

		$errors[] = __( 'ThinkRank writes its managed rules into the real physical robots.txt file on disk, which (if still present) is served directly by the webserver and bypasses every plugin\'s virtual robots.txt output, including VuloPilot\'s own - remove that physical file for the migrated content to actually take effect.', 'vulopilot' );
	}

	/**
	 * @return string
	 */
	private function read_physical_robots_txt(): string {
		$path = trailingslashit( ABSPATH ) . 'robots.txt';

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		return trim( (string) file_get_contents( $path ) );
	}

	// ---------------------------------------------------------------------
	// Sitewide settings (EAV table + `thinkrank_global_seo_settings` option).
	// ---------------------------------------------------------------------

	/**
	 * @param bool $overwrite Passed through from migrate().
	 * @param int  $migrated  Running total, by reference.
	 * @param int  $skipped   Running total, by reference.
	 * @return void
	 */
	private function migrate_settings( bool $overwrite, int &$migrated, int &$skipped ): void {
		$values = $this->build_settings_values();

		if ( empty( $values ) ) {
			return;
		}

		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$changed  = false;

		foreach ( $values as $field => $value ) {
			$is_customized = isset( $settings[ $field ] )
				&& $settings[ $field ] !== ( Utill::VULOPILOT_SETTINGS_DEFAULTS[ $field ] ?? null );

			if ( $is_customized && ! $overwrite ) {
				++$skipped;
				continue;
			}

			$settings[ $field ] = $value;
			$changed            = true;
			++$migrated;
		}

		if ( $changed ) {
			update_option( Utill::VULOPILOT_SETTINGS_KEY, $settings );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_settings_values(): array {
		$site_identity = $this->read_eav_category( 'site_identity' );
		$sitemap       = $this->read_eav_category( 'sitemap' );
		$global_seo    = get_option( 'thinkrank_global_seo_settings', array() );
		$global_seo    = is_array( $global_seo ) ? $global_seo : array();

		$values = array();

		$this->migrate_site_identity( $values, $site_identity );
		$this->migrate_sitemap( $values, $sitemap );
		$this->migrate_global_seo_templates( $values, $global_seo );
		$this->migrate_llms_txt( $values );
		$this->migrate_indexnow( $values );

		return $values;
	}

	/**
	 * @param array $values Field values being built, by reference.
	 * @return void
	 */
	private function migrate_llms_txt( array &$values ): void {
		$content = (string) get_option( 'thinkrank_llms_txt_content', '' );

		if ( '' === trim( $content ) ) {
			return;
		}

		$values['llms_txt_content'] = $content;
		$values['enable_llms_txt']  = array( 'enable_llms_txt' );
	}

	/**
	 * @param array $values Field values being built, by reference.
	 * @return void
	 */
	private function migrate_indexnow( array &$values ): void {
		$settings = get_option( 'thinkrank_instant_indexing_settings', array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$this->maybe_set_string( $values, 'indexnow_api_key', $settings['api_key'] ?? '' );

		$post_types = $settings['auto_submit_post_types'] ?? array();

		if ( is_array( $post_types ) && ! empty( $post_types ) ) {
			$values['indexnow_post_types'] = array_values( array_map( 'strval', $post_types ) );
		}
	}

	/**
	 * Reads every active `site`-context row for one `setting_category` straight out of
	 * `{prefix}thinkrank_seo_settings` - the same EAV table every `Abstract_SEO_Manager`
	 * subclass reads/writes through, read directly rather than instantiating ThinkRank's own
	 * (not loaded) manager classes.
	 *
	 * @param string $category `setting_category` value (e.g. 'site_identity', 'sitemap').
	 * @return array<string, mixed>
	 */
	private function read_eav_category( string $category ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'thinkrank_seo_settings';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT setting_key, setting_value FROM {$table} WHERE setting_category = %s AND context_type = 'site' AND is_active = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$category
			)
		);

		$settings = array();

		foreach ( $rows as $row ) {
			$settings[ $row->setting_key ] = maybe_unserialize( $row->setting_value );
		}

		return $settings;
	}

	/**
	 * @param array $values   Field values being built, by reference.
	 * @param array $identity `site_identity` category rows.
	 * @return void
	 */
	private function migrate_site_identity( array &$values, array $identity ): void {
		if ( empty( $identity ) ) {
			return;
		}

		if ( isset( self::SEPARATOR_SYMBOLS[ $identity['title_separator'] ?? '' ] ) ) {
			$values['title_separator'] = self::SEPARATOR_SYMBOLS[ $identity['title_separator'] ];
		}

		$this->maybe_set_template( $values, 'title_format_home', $identity['homepage_title'] ?? '' );
		$this->maybe_set_string( $values, 'description_format_home', (string) ( $identity['homepage_description'] ?? '' ) );
		$this->maybe_set_template( $values, 'title_format_category', $identity['category_title'] ?? '' );
		$this->maybe_set_template( $values, 'title_format_tag', $identity['tag_title'] ?? '' );
		$this->maybe_set_template( $values, 'title_format_author', $identity['author_title'] ?? '' );
		$this->maybe_set_template( $values, 'title_format_search', $identity['search_title'] ?? '' );
		$this->maybe_set_template( $values, 'title_format_archive', $identity['archive_title'] ?? '' );

		$represents = $identity['represents'] ?? '';

		if ( 'person' === $represents ) {
			$values['entity_business_type'] = 'Person';
		} elseif ( 'organization' === $represents ) {
			$values['entity_business_type'] = 'Organization';
		}

		$business_name = ! empty( $identity['local_seo_enabled'] ) ? (string) ( $identity['business_name'] ?? '' ) : '';
		$this->maybe_set_string( $values, 'entity_business_name', '' !== $business_name ? $business_name : (string) ( $identity['site_name'] ?? '' ) );

		$logo_url = (string) ( $identity['logo_url'] ?? '' );

		if ( '' !== $logo_url ) {
			$attachment_id = attachment_url_to_postid( $logo_url );

			if ( $attachment_id ) {
				$values['entity_business_logo_id'] = $attachment_id;
			}
		}

		$accounts = $identity['social_media_accounts'] ?? array();

		if ( is_string( $accounts ) && '' !== $accounts ) {
			$accounts = array( $accounts );
		}

		if ( is_array( $accounts ) ) {
			$profile_urls = array_values( array_filter( array_map( 'strval', $accounts ) ) );

			if ( ! empty( $profile_urls ) ) {
				$values['social_profile_urls'] = implode( "\n", $profile_urls );
			}
		}
	}

	/**
	 * @param array $values  Field values being built, by reference.
	 * @param array $sitemap `sitemap` category rows.
	 * @return void
	 */
	private function migrate_sitemap( array &$values, array $sitemap ): void {
		if ( empty( $sitemap ) ) {
			return;
		}

		if ( isset( $sitemap['enabled'] ) ) {
			$values['sitemap_enabled'] = ! empty( $sitemap['enabled'] ) ? array( 'sitemap_enabled' ) : array();
		}

		if ( isset( $sitemap['links_per_sitemap'] ) && (int) $sitemap['links_per_sitemap'] > 0 ) {
			$values['sitemap_links_per_page'] = (int) $sitemap['links_per_sitemap'];
		}

		if ( isset( $sitemap['include_images'] ) ) {
			$values['sitemap_include_images'] = ! empty( $sitemap['include_images'] ) ? array( 'sitemap_include_images' ) : array();
		}

		if ( isset( $sitemap['include_featured_images'] ) ) {
			$values['sitemap_include_featured_images'] = ! empty( $sitemap['include_featured_images'] ) ? array( 'sitemap_include_featured_images' ) : array();
		}

		if ( ! empty( $sitemap['exclude_posts'] ) ) {
			$values['sitemap_exclude_posts'] = (string) $sitemap['exclude_posts'];
		}

		if ( ! empty( $sitemap['exclude_terms'] ) ) {
			$values['sitemap_exclude_terms'] = (string) $sitemap['exclude_terms'];
		}

		$post_types = array();

		if ( ! empty( $sitemap['include_posts'] ) ) {
			$post_types[] = 'post';
		}

		if ( ! empty( $sitemap['include_pages'] ) ) {
			$post_types[] = 'page';
		}

		if ( ! empty( $post_types ) ) {
			$values['sitemap_xml_post_types'] = $post_types;
		}

		$taxonomies = array();

		if ( ! empty( $sitemap['include_categories'] ) ) {
			$taxonomies[] = 'category';
		}

		if ( ! empty( $sitemap['include_tags'] ) ) {
			$taxonomies[] = 'post_tag';
		}

		if ( ! empty( $taxonomies ) ) {
			$values['sitemap_xml_taxonomies'] = $taxonomies;
		}
	}

	/**
	 * Every real post type's/taxonomy's own title/description template, plus the `archive:author`/
	 * `special:search` special entities, from `thinkrank_global_seo_settings`
	 * (`class-settings-key-map.php` documents the exact key shape: `title`, `description`,
	 * `robots.noindex`). 'post'/'page' and 'category'/'post_tag' route to VuloPilot's own flat
	 * fields; `archive:author`/`special:search` route to the matching flat author/search fields;
	 * everything else goes into the `title_format_post_types`/`title_format_taxonomies` maps.
	 *
	 * @param array $values     Field values being built, by reference.
	 * @param array $global_seo The decoded `thinkrank_global_seo_settings` option.
	 * @return void
	 */
	private function migrate_global_seo_templates( array &$values, array $global_seo ): void {
		if ( empty( $global_seo ) ) {
			return;
		}

		$flat_post_types = array(
			'post' => 'post',
			'page' => 'page',
		);
		$flat_taxonomies = array(
			'category' => 'category',
			'post_tag' => 'tag',
		);

		$post_type_titles       = array();
		$post_type_descriptions = array();
		$taxonomy_titles        = array();
		$taxonomy_descriptions  = array();

		foreach ( $global_seo as $entity_key => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$title   = $this->translate_global_seo_template( (string) ( $config['title'] ?? '' ) );
			$desc    = $this->translate_global_seo_template( (string) ( $config['description'] ?? '' ) );
			$noindex = ! empty( $config['robots']['noindex'] );

			if ( 'archive:author' === $entity_key ) {
				$this->maybe_set_string( $values, 'title_format_author', $title );
				$this->maybe_set_string( $values, 'description_format_author', $desc );
				$values['robots_noindex_author'] = $noindex ? array( 'robots_noindex_author' ) : array();
				continue;
			}

			if ( 'archive:date' === $entity_key ) {
				$this->maybe_set_string( $values, 'title_format_archive', $title );
				$this->maybe_set_string( $values, 'description_format_archive', $desc );
				continue;
			}

			if ( 'special:search' === $entity_key ) {
				$this->maybe_set_string( $values, 'title_format_search', $title );
				$this->maybe_set_string( $values, 'description_format_search', $desc );
				$values['robots_noindex_search'] = $noindex ? array( 'robots_noindex_search' ) : array();
				continue;
			}

			if ( 0 === strpos( $entity_key, 'taxonomy:' ) ) {
				$slug = substr( $entity_key, strlen( 'taxonomy:' ) );

				if ( isset( $flat_taxonomies[ $slug ] ) ) {
					$this->maybe_set_string( $values, "title_format_{$flat_taxonomies[ $slug ]}", $title );
					$this->maybe_set_string( $values, "description_format_{$flat_taxonomies[ $slug ]}", $desc );
				} else {
					if ( '' !== $title ) {
						$taxonomy_titles[ $slug ] = $title;
					}
					if ( '' !== $desc ) {
						$taxonomy_descriptions[ $slug ] = $desc;
					}
				}

				continue;
			}

			if ( 0 === strpos( $entity_key, 'archive:' ) || 0 === strpos( $entity_key, 'special:' ) ) {
				continue; // archive:blog, special:404 - no matching VuloPilot template slot.
			}

			// A real post type slug.
			if ( isset( $flat_post_types[ $entity_key ] ) ) {
				$this->maybe_set_string( $values, "title_format_{$flat_post_types[ $entity_key ]}", $title );
				$this->maybe_set_string( $values, "description_format_{$flat_post_types[ $entity_key ]}", $desc );
			} else {
				if ( '' !== $title ) {
					$post_type_titles[ $entity_key ] = $title;
				}
				if ( '' !== $desc ) {
					$post_type_descriptions[ $entity_key ] = $desc;
				}
			}
		}

		if ( ! empty( $post_type_titles ) ) {
			$values['title_format_post_types'] = $post_type_titles;
		}

		if ( ! empty( $post_type_descriptions ) ) {
			$values['description_format_post_types'] = $post_type_descriptions;
		}

		if ( ! empty( $taxonomy_titles ) ) {
			$values['title_format_taxonomies'] = $taxonomy_titles;
		}

		if ( ! empty( $taxonomy_descriptions ) ) {
			$values['description_format_taxonomies'] = $taxonomy_descriptions;
		}
	}

	/**
	 * @param array  $values Field values being built, by reference.
	 * @param string $field  VuloPilot settings field name.
	 * @param mixed  $raw    Raw ThinkRank Site Identity template string.
	 * @return void
	 */
	private function maybe_set_template( array &$values, string $field, $raw ): void {
		$translated = $this->translate_site_identity_template( (string) $raw );

		if ( '' !== $translated ) {
			$values[ $field ] = $translated;
		}
	}

	/**
	 * ThinkRank Site Identity's `%token%` syntax into VuloPilot's own (same vocabulary, different
	 * names for a few tokens) - unsupported tokens (`%date%`) are dropped rather than left as
	 * literal, unresolved text, same policy as the other three migrators' own translators.
	 *
	 * @param string $template Raw ThinkRank Site Identity template.
	 * @return string
	 */
	private function translate_site_identity_template( string $template ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$translated = strtr( $template, self::SITE_IDENTITY_TOKEN_MAP );
		$translated = preg_replace( '/%date%/i', '', $translated );
		$translated = preg_replace( '/\s{2,}/', ' ', (string) $translated );

		return trim( (string) $translated );
	}

	/**
	 * ThinkRank Global SEO's `%token%` syntax into VuloPilot's own. `%title%` has no single fixed
	 * mapping - it means "this entity's own current title", which is the same role every one of
	 * TitleFormatter's `%post_title%`/`%category_title%`/`%tag_title%`/`%archive_title%` variables
	 * already plays for its own context, so VuloPilot resolves whichever one its own context key
	 * picks; `%post_title%` is used here only as the literal substitution text, matching how
	 * `AllInOneSeoMigrator::translate_aioseo_template()` makes the same choice for AIOSEO's `#post_title%`.
	 *
	 * @param string $template Raw ThinkRank Global SEO template.
	 * @return string
	 */
	private function translate_global_seo_template( string $template ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$translated = strtr( $template, self::GLOBAL_SEO_TOKEN_MAP );
		$translated = preg_replace( '/%title%/i', '%post_title%', $translated );
		$translated = preg_replace( '/%(excerpt|caption)%/i', '', (string) $translated );
		$translated = preg_replace( '/\s{2,}/', ' ', (string) $translated );

		return trim( (string) $translated );
	}

	/**
	 * Sets `$values[$field]` only when `$raw` is a real, non-empty string.
	 *
	 * @param array  $values Field values being built, by reference.
	 * @param string $field  VuloPilot settings field name.
	 * @param mixed  $raw    Raw ThinkRank value.
	 * @return void
	 */
	private function maybe_set_string( array &$values, string $field, $raw ): void {
		$value = trim( (string) $raw );

		if ( '' !== $value ) {
			$values[ $field ] = $value;
		}
	}
}
