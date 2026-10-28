<?php
/**
 * RankMathMigrator class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Migration;

use VuloPilot\SeoVisibility\PostSeoMetaFields;
use VuloPilot\SeoVisibility\RobotsTxtManager;
use VuloPilot\Content\RedirectRepository;
use VuloPilot\Content\NotFoundLogRepository;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Imports everything VuloPilot can read from a Rank Math SEO install, in one migrator:
 *
 * - Per-post SEO postmeta (`rank_math_*`) into VuloPilot's own `PostSeoMetaFields::META_KEYS`.
 * - Sitewide Settings (`rank-math-options-general/titles/sitemap`) into `vulopilot_settings`
 *   (confirmed against `includes/helpers/class-options.php` in rankmath/seo-by-rank-math).
 * - Real saved redirect rules, from Rank Math's own `{prefix}rank_math_redirections` table
 *   (schema confirmed against `includes/class-installer.php`) into `vulopilot_redirects`,
 *   including `contains`/`start`/`end`/`regex` sources - VuloPilot's own redirect table grew a
 *   `match_type` column (`RedirectRepository::find_matching()`) using the exact same vocabulary
 *   Rank Math's own `sources[].comparison` already uses (confirmed against
 *   `includes/modules/redirections/class-redirection.php`), so this is a direct pass-through now,
 *   not a lossy best-effort mapping.
 * - Real logged 404 hits, from `{prefix}rank_math_404_logs`, into `vulopilot_not_found_logs`.
 * - Webmaster Tools' Facebook App ID/Admin ID, the Knowledge Graph name/logo override, the
 *   sitewide default-robots + 5 noindex conditions, the Sitemap module's Author tab, and
 *   per-custom-post-type/per-custom-taxonomy title/description templates - all newly built in
 *   VuloPilot alongside this migrator (see `Utill::VULOPILOT_SETTINGS_DEFAULTS`'s
 *   `facebook_*`/`entity_business_*`/`robots_noindex_*`/`sitemap_*_author*`/`title_format_post_types`
 *   etc. fields).
 *
 * `migrate()` runs all four and returns their combined counts. Settings fields with no VuloPilot
 * field to write into (Breadcrumbs, social profile URLs beyond Facebook's app-association tags,
 * sitewide default OG image/Twitter card, link settings, RSS content injection, Frontend SEO
 * Score, capitalize titles, pillar/cornerstone flag, primary taxonomy picker) are deliberately
 * left out rather than guessed at.
 *
 * @class       RankMathMigrator
 * @version     2.0.0
 * @author      VuloLabs
 */
class RankMathMigrator implements MigratorInterface {

	/**
	 * Rank Math postmeta key for each VuloPilot per-post field it has an equivalent for.
	 *
	 * @var array<string, string>
	 */
	private const POST_META_MAP = array(
		'focus_keyword'      => 'rank_math_focus_keyword',
		'canonical_url'      => 'rank_math_canonical_url',
		// Both robots fields read the same serialized `rank_math_robots` array - normalize_robots()
		// below picks the right flag out of it either way.
		'robots_noindex'     => 'rank_math_robots',
		'robots_nofollow'    => 'rank_math_robots',
		'social_title'       => 'rank_math_facebook_title',
		'social_description' => 'rank_math_facebook_description',
		'social_image_id'    => 'rank_math_facebook_image_id',
	);

	/**
	 * Rank Math's own `rank-math-options-titles` key for each VuloPilot title/description
	 * template field it has a direct equivalent for.
	 *
	 * @var array<string, string>
	 */
	private const TITLE_FIELD_MAP = array(
		'title_format_home'           => 'homepage_title',
		'description_format_home'     => 'homepage_description',
		'title_format_post'           => 'pt_post_title',
		'description_format_post'     => 'pt_post_description',
		'title_format_page'           => 'pt_page_title',
		'description_format_page'     => 'pt_page_description',
		'title_format_category'       => 'tax_category_title',
		'description_format_category' => 'tax_category_description',
		'title_format_tag'            => 'tax_post_tag_title',
		'description_format_tag'      => 'tax_post_tag_description',
		'title_format_archive'        => 'date_archive_title',
		'description_format_archive'  => 'date_archive_description',
		'title_format_search'         => 'search_title',
		'title_format_author'         => 'author_archive_title',
		'description_format_author'   => 'author_archive_description',
	);

	/**
	 * Rank Math's own `rank-math-options-general` key for each VuloPilot Webmaster Tools field.
	 *
	 * @var array<string, string>
	 */
	private const WEBMASTER_FIELD_MAP = array(
		'webmaster_google_verification'    => 'google_verify',
		'webmaster_bing_verification'      => 'bing_verify',
		'webmaster_baidu_verification'     => 'baidu_verify',
		'webmaster_yandex_verification'    => 'yandex_verify',
		'webmaster_pinterest_verification' => 'pinterest_verify',
		'webmaster_norton_verification'    => 'norton_verify',
		'webmaster_custom_tags'            => 'custom_webmaster_tags',
	);

	/**
	 * Rank Math's own `rank-math-options-titles` key for each VuloPilot Facebook field - the two
	 * app-association meta tags SocialMetaTagsManager outputs (not the still-unused App Secret).
	 *
	 * @var array<string, string>
	 */
	private const FACEBOOK_FIELD_MAP = array(
		'facebook_app_id'   => 'facebook_app_id',
		'facebook_admin_id' => 'facebook_admin_id',
	);

	private const SITEMAP_POST_TYPES = array( 'post', 'page', 'attachment', 'product' );
	private const SITEMAP_TAXONOMIES = array( 'category', 'post_tag', 'product_cat', 'product_tag' );

	/** Rank Math `header_code` values VuloPilot's own `redirect_type` column accepts. */
	private const SUPPORTED_REDIRECT_TYPES = array( 301, 302, 307 );

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'rank-math';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Rank Math SEO', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function count_available(): int {
		$general        = get_option( 'rank-math-options-general', array() );
		$has_robots_txt = is_array( $general ) && '' !== trim( (string) ( $general['robots_txt_content'] ?? '' ) );

		return count( $this->find_post_ids() )
			+ ( empty( $this->build_settings_values() ) ? 0 : 1 )
			+ count( $this->get_redirect_rows() )
			+ count( $this->get_404_log_rows() )
			+ ( $has_robots_txt ? 1 : 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function migrate( bool $overwrite = false ): array {
		$migrated = 0;
		$skipped  = 0;
		$errors   = array();

		$this->migrate_posts( $overwrite, $migrated, $skipped, $errors );
		$this->migrate_settings( $overwrite, $migrated, $skipped );
		$this->migrate_redirects( $overwrite, $migrated, $skipped );
		$this->migrate_404_logs( $overwrite, $migrated, $skipped );
		$this->migrate_robots_txt( $overwrite, $migrated, $skipped, $errors );

		return array(
			'migrated' => $migrated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Rank Math's "Edit robots.txt" content (`general.robots_txt_content`, confirmed against
	 * `includes/modules/robots-txt/class-robots-txt.php`) into VuloPilot's own custom robots.txt
	 * override (`RobotsTxtManager`'s `vulopilot_custom_robots_txt` option - deliberately outside
	 * `vulopilot_settings`, so it's never touched by migrate_settings() above).
	 *
	 * @param bool  $overwrite Passed through from migrate().
	 * @param int   $migrated  Running total, by reference.
	 * @param int   $skipped   Running total, by reference.
	 * @param array $errors    Running list, by reference.
	 * @return void
	 */
	private function migrate_robots_txt( bool $overwrite, int &$migrated, int &$skipped, array &$errors ): void {
		$general = get_option( 'rank-math-options-general', array() );
		$content = is_array( $general ) ? trim( (string) ( $general['robots_txt_content'] ?? '' ) ) : '';

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

		// Same WordPress architecture fact YoastSeoMigrator's own robots.txt step warns about: a
		// real physical robots.txt file at the site root bypasses WordPress's whole virtual
		// robots.txt system (every plugin's `robots_txt` filter, VuloPilot's own included), so
		// this copy alone doesn't make it take effect while one exists.
		if ( file_exists( trailingslashit( ABSPATH ) . 'robots.txt' ) ) {
			$errors[] = __(
				'robots.txt content was copied into VuloPilot, but a real robots.txt file still exists on your server - WordPress serves that file directly and ignores every plugin\'s own robots.txt output (VuloPilot\'s included) while it\'s there. Remove or rename the physical robots.txt file at your site\'s root for VuloPilot\'s version to take effect.',
				'vulopilot'
			);
		}
	}

	// ---------------------------------------------------------------------
	// Per-post SEO data.
	// ---------------------------------------------------------------------

	/**
	 * @param bool  $overwrite Passed through from migrate().
	 * @param int   $migrated  Running total, by reference.
	 * @param int   $skipped   Running total, by reference.
	 * @param array $errors    Running list, by reference.
	 * @return void
	 */
	private function migrate_posts( bool $overwrite, int &$migrated, int &$skipped, array &$errors ): void {
		foreach ( $this->find_post_ids() as $post_id ) {
			try {
				$existing = get_post_meta( $post_id, PostSeoMetaFields::META_KEYS['focus_keyword'], true );

				if ( ! $overwrite && '' !== (string) $existing ) {
					++$skipped;
					continue;
				}

				foreach ( self::POST_META_MAP as $field => $source_key ) {
					$raw = get_post_meta( $post_id, $source_key, true );

					if ( '' === $raw || null === $raw ) {
						continue;
					}

					$value = $this->normalize_post_value( $field, $raw );

					if ( '' === $value || null === $value ) {
						continue;
					}

					update_post_meta( $post_id, PostSeoMetaFields::META_KEYS[ $field ], $value );
				}

				++$migrated;
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( 'Post #%d: %s', $post_id, $e->getMessage() );
			}
		}
	}

	/**
	 * @return int[]
	 */
	private function find_post_ids(): array {
		$keys = array_unique( array_values( self::POST_META_MAP ) );

		$meta_query = array( 'relation' => 'OR' );

		foreach ( $keys as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => '',
				'compare' => '!=',
			);
		}

		return get_posts(
			array(
				'post_type'      => PostSeoMetaFields::POST_TYPES,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}

	/**
	 * @param string $field     The `PostSeoMetaFields::META_KEYS` field name.
	 * @param mixed  $raw_value The raw postmeta value.
	 * @return mixed
	 */
	private function normalize_post_value( string $field, $raw_value ) {
		if ( 'robots_noindex' === $field || 'robots_nofollow' === $field ) {
			$robots = maybe_unserialize( $raw_value );
			$flag   = 'robots_noindex' === $field ? 'noindex' : 'nofollow';

			return is_array( $robots ) && in_array( $flag, $robots, true );
		}

		// Rank Math stores the focus keyword(s) comma-separated already, same contract
		// `PostSeoMetaFields::split_keywords()` expects.
		return $raw_value;
	}

	// ---------------------------------------------------------------------
	// Sitewide settings.
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
	 * Every VuloPilot settings field Rank Math has real, non-empty data for.
	 *
	 * @return array<string, mixed>
	 */
	private function build_settings_values(): array {
		$general = get_option( 'rank-math-options-general', array() );
		$titles  = get_option( 'rank-math-options-titles', array() );
		$sitemap = get_option( 'rank-math-options-sitemap', array() );

		if ( ! is_array( $general ) || ! is_array( $titles ) || ! is_array( $sitemap ) ) {
			return array();
		}

		$values = array();

		foreach ( self::WEBMASTER_FIELD_MAP as $vulopilot_field => $rank_math_field ) {
			$this->maybe_set_string( $values, $vulopilot_field, $general[ $rank_math_field ] ?? '' );
		}

		foreach ( self::TITLE_FIELD_MAP as $vulopilot_field => $rank_math_field ) {
			$this->maybe_set_string( $values, $vulopilot_field, $titles[ $rank_math_field ] ?? '' );
		}

		// Rank Math stores the literal separator character already (`sanitize_separator`), same
		// contract `title_separator` expects - direct pass-through.
		$this->maybe_set_string( $values, 'title_separator', $titles['title_separator'] ?? '' );

		if ( isset( $sitemap['items_per_page'] ) && '' !== (string) $sitemap['items_per_page'] ) {
			$values['sitemap_links_per_page'] = (int) $sitemap['items_per_page'];
		}

		if ( isset( $sitemap['include_images'] ) ) {
			$values['sitemap_include_images'] = $this->toggle_array( 'sitemap_include_images', $sitemap['include_images'] );
		}

		if ( isset( $sitemap['include_featured_image'] ) ) {
			$values['sitemap_include_featured_images'] = $this->toggle_array( 'sitemap_include_featured_images', $sitemap['include_featured_image'] );
		}

		$this->maybe_set_string( $values, 'sitemap_exclude_posts', $sitemap['exclude_posts'] ?? '' );
		$this->maybe_set_string( $values, 'sitemap_exclude_terms', $sitemap['exclude_terms'] ?? '' );

		$included_post_types = array_values(
			array_filter(
				self::SITEMAP_POST_TYPES,
				static function ( $post_type ) use ( $sitemap ) {
					return 'on' === ( $sitemap[ "pt_{$post_type}_sitemap" ] ?? '' );
				}
			)
		);

		if ( ! empty( $included_post_types ) ) {
			$values['sitemap_xml_post_types'] = $included_post_types;
		}

		$included_taxonomies = array_values(
			array_filter(
				self::SITEMAP_TAXONOMIES,
				static function ( $taxonomy ) use ( $sitemap ) {
					return 'on' === ( $sitemap[ "tax_{$taxonomy}_sitemap" ] ?? '' );
				}
			)
		);

		if ( ! empty( $included_taxonomies ) ) {
			$values['sitemap_xml_taxonomies'] = $included_taxonomies;
		}

		if ( isset( $sitemap['html_sitemap'] ) ) {
			$values['html_sitemap_enabled'] = $this->toggle_array( 'html_sitemap_enabled', $sitemap['html_sitemap'] );
		}

		if ( isset( $sitemap['html_sitemap_show_dates'] ) ) {
			$values['html_sitemap_show_dates'] = $this->toggle_array( 'html_sitemap_show_dates', $sitemap['html_sitemap_show_dates'] );
		}

		if ( isset( $sitemap['html_sitemap_sort'] ) ) {
			$sort_map = array(
				'published'    => 'published_date',
				'modified'     => 'modified_date',
				'alphabetical' => 'title',
			);

			if ( isset( $sort_map[ $sitemap['html_sitemap_sort'] ] ) ) {
				$values['html_sitemap_sort_by'] = $sort_map[ $sitemap['html_sitemap_sort'] ];
			}
		}

		if ( isset( $sitemap['html_sitemap_seo_titles'] ) ) {
			$values['html_sitemap_item_titles'] = 'seo_titles' === $sitemap['html_sitemap_seo_titles'] ? 'seo_title' : 'post_title';
		}

		if ( isset( $titles['knowledgegraph_type'] ) ) {
			$business_type_map = array(
				'person'  => 'Person',
				'company' => 'Organization',
			);

			if ( isset( $business_type_map[ $titles['knowledgegraph_type'] ] ) ) {
				$values['entity_business_type'] = $business_type_map[ $titles['knowledgegraph_type'] ];
			}
		}

		if ( isset( $titles['robots_global'] ) ) {
			$global_robots = is_array( $titles['robots_global'] ) ? $titles['robots_global'] : array( $titles['robots_global'] );

			$values['robots_noindex_sitewide'] = in_array( 'noindex', $global_robots, true )
				? array( 'robots_noindex_sitewide' )
				: array();
		}

		if ( isset( $titles['author_robots'] ) ) {
			$author_robots = is_array( $titles['author_robots'] ) ? $titles['author_robots'] : array( $titles['author_robots'] );

			$values['robots_noindex_author'] = in_array( 'noindex', $author_robots, true )
				? array( 'robots_noindex_author' )
				: array();
		}

		$noindex_toggle_map = array(
			'robots_noindex_search'             => 'noindex_search',
			'robots_noindex_empty_taxonomies'   => 'noindex_empty_taxonomies',
			'robots_noindex_archive_subpages'   => 'noindex_archive_subpages',
			'robots_noindex_paginated_pages'    => 'noindex_paginated_pages',
			'robots_noindex_password_protected' => 'noindex_password_protected',
		);

		foreach ( $noindex_toggle_map as $vulopilot_field => $rank_math_field ) {
			if ( isset( $titles[ $rank_math_field ] ) ) {
				$values[ $vulopilot_field ] = $this->toggle_array( $vulopilot_field, $titles[ $rank_math_field ] );
			}
		}

		// Rank Math's 'Person/Organization Name' field - VuloPilot's own name OVERRIDE, distinct
		// from its business_type above.
		$this->maybe_set_string( $values, 'entity_business_name', $titles['knowledgegraph_name'] ?? '' );

		// CMB2's 'file' field type (knowledgegraph_logo's own field type) auto-saves a companion
		// `{id}_id` key holding the real attachment id alongside the URL - the attachment lives on
		// this same site either way, so the id is directly reusable.
		if ( ! empty( $titles['knowledgegraph_logo_id'] ) ) {
			$values['entity_business_logo_id'] = absint( $titles['knowledgegraph_logo_id'] );
		}

		foreach ( self::FACEBOOK_FIELD_MAP as $vulopilot_field => $rank_math_field ) {
			$this->maybe_set_string( $values, $vulopilot_field, $titles[ $rank_math_field ] ?? '' );
		}

		if ( isset( $sitemap['authors_sitemap'] ) ) {
			$values['sitemap_include_authors'] = $this->toggle_array( 'sitemap_include_authors', $sitemap['authors_sitemap'] );
		}

		if ( ! empty( $sitemap['exclude_roles'] ) ) {
			$roles = is_array( $sitemap['exclude_roles'] ) ? $sitemap['exclude_roles'] : array( $sitemap['exclude_roles'] );
			$this->maybe_set_string( $values, 'sitemap_exclude_author_roles', implode( ',', array_map( 'strval', $roles ) ) );
		}

		$this->maybe_set_string( $values, 'sitemap_exclude_author_ids', $sitemap['exclude_users'] ?? '' );

		// Per-custom-post-type/taxonomy title & description templates - every `pt_{type}_title`/
		// `tax_{taxonomy}_title` key beyond the ones TITLE_FIELD_MAP already covers (post/page's
		// own flat fields handle 'post'/'page'; built-in 'category'/'post_tag' too). Rank Math
		// names its own post-type/taxonomy keys the exact same way WordPress's own
		// get_post_types()/get_taxonomies() slugs are spelled, so the Rank Math key suffix doubles
		// as the real post type/taxonomy slug directly - no separate lookup table needed.
		$custom_post_type_titles       = array();
		$custom_post_type_descriptions = array();

		foreach ( $titles as $key => $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			if ( preg_match( '/^pt_(.+)_title$/', $key, $matches ) && ! in_array( $matches[1], array( 'post', 'page' ), true ) ) {
				$custom_post_type_titles[ $matches[1] ] = $value;
			} elseif ( preg_match( '/^pt_(.+)_description$/', $key, $matches ) && ! in_array( $matches[1], array( 'post', 'page' ), true ) ) {
				$custom_post_type_descriptions[ $matches[1] ] = $value;
			}
		}

		if ( ! empty( $custom_post_type_titles ) ) {
			$values['title_format_post_types'] = $custom_post_type_titles;
		}

		if ( ! empty( $custom_post_type_descriptions ) ) {
			$values['description_format_post_types'] = $custom_post_type_descriptions;
		}

		$custom_taxonomy_titles       = array();
		$custom_taxonomy_descriptions = array();

		foreach ( $titles as $key => $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			if ( preg_match( '/^tax_(.+)_title$/', $key, $matches ) && ! in_array( $matches[1], array( 'category', 'post_tag' ), true ) ) {
				$custom_taxonomy_titles[ $matches[1] ] = $value;
			} elseif ( preg_match( '/^tax_(.+)_description$/', $key, $matches ) && ! in_array( $matches[1], array( 'category', 'post_tag' ), true ) ) {
				$custom_taxonomy_descriptions[ $matches[1] ] = $value;
			}
		}

		if ( ! empty( $custom_taxonomy_titles ) ) {
			$values['title_format_taxonomies'] = $custom_taxonomy_titles;
		}

		if ( ! empty( $custom_taxonomy_descriptions ) ) {
			$values['description_format_taxonomies'] = $custom_taxonomy_descriptions;
		}

		return $values;
	}

	/**
	 * @param array  $values Field values being built, by reference.
	 * @param string $field  VuloPilot settings field name.
	 * @param mixed  $raw    Raw Rank Math value.
	 * @return void
	 */
	private function maybe_set_string( array &$values, string $field, $raw ): void {
		$value = trim( (string) $raw );

		if ( '' !== $value ) {
			$values[ $field ] = $value;
		}
	}

	/**
	 * VuloPilot's own "checkbox list used as a single on/off toggle" convention.
	 *
	 * @param string $field VuloPilot settings field name.
	 * @param mixed  $raw   Raw Rank Math `'on'`/`'off'` toggle value.
	 * @return string[]
	 */
	private function toggle_array( string $field, $raw ): array {
		return 'on' === $raw ? array( $field ) : array();
	}

	// ---------------------------------------------------------------------
	// Redirects.
	// ---------------------------------------------------------------------

	/**
	 * @param bool $overwrite Passed through from migrate().
	 * @param int  $migrated  Running total, by reference.
	 * @param int  $skipped   Running total, by reference.
	 * @return void
	 */
	private function migrate_redirects( bool $overwrite, int &$migrated, int &$skipped ): void {
		$repository = new RedirectRepository();

		foreach ( $this->get_redirect_rows() as $row ) {
			$sources = maybe_unserialize( $row['sources'] );

			if ( ! is_array( $sources ) ) {
				continue;
			}

			$redirect_type = in_array( (int) $row['header_code'], self::SUPPORTED_REDIRECT_TYPES, true )
				? (int) $row['header_code']
				: 301;

			foreach ( $sources as $source ) {
				$pattern    = is_array( $source ) ? (string) ( $source['pattern'] ?? '' ) : '';
				$comparison = is_array( $source ) ? (string) ( $source['comparison'] ?? '' ) : '';

				if ( '' === $pattern ) {
					continue;
				}

				// 'exact' is the only comparison type that needs path normalization - the other
				// four are raw match patterns (a substring, a regex, ...), not real URL paths, so
				// normalizing them (stripping a subdirectory prefix, forcing a leading slash)
				// would corrupt the pattern itself.
				$source_value = 'exact' === $comparison ? RedirectRepository::normalize_path( $pattern ) : $pattern;
				$match_type   = in_array( $comparison, array( 'exact', 'contains', 'start', 'end', 'regex' ), true ) ? $comparison : 'exact';
				$existing     = $repository->find_by_source_path( $source_value );

				if ( $existing && ! $overwrite ) {
					++$skipped;
					continue;
				}

				$data = array(
					'source_path'      => $source_value,
					'target_url'       => esc_url_raw( (string) $row['url_to'] ),
					'redirect_type'    => $redirect_type,
					'match_type'       => $match_type,
					'hit_count'        => (int) $row['hits'],
					'is_active'        => 'active' === $row['status'] ? 1 : 0,
					'last_accessed_at' => '0000-00-00 00:00:00' !== $row['last_accessed'] ? $row['last_accessed'] : null,
				);

				if ( $existing ) {
					$repository->update( (int) $existing['id'], $data );
				} else {
					$repository->insert( $data );
				}

				++$migrated;
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_redirect_rows(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		return (array) $wpdb->get_results( "SELECT id, sources, url_to, header_code, hits, status, last_accessed FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// ---------------------------------------------------------------------
	// 404 logs.
	// ---------------------------------------------------------------------

	/**
	 * @param bool $overwrite Passed through from migrate().
	 * @param int  $migrated  Running total, by reference.
	 * @param int  $skipped   Running total, by reference.
	 * @return void
	 */
	private function migrate_404_logs( bool $overwrite, int &$migrated, int &$skipped ): void {
		$repository = new NotFoundLogRepository();

		foreach ( $this->get_404_log_rows() as $row ) {
			$uri = (string) $row['uri'];

			if ( '' === $uri ) {
				continue;
			}

			$requested_path = RedirectRepository::normalize_path( $uri );
			$existing       = $repository->find_by_requested_path( $requested_path );

			if ( $existing && ! $overwrite ) {
				++$skipped;
				continue;
			}

			$data = array(
				'requested_path' => $requested_path,
				'referrer'       => '' !== (string) $row['referer'] ? esc_url_raw( (string) $row['referer'] ) : null,
				'hit_count'      => max( 1, (int) $row['times_accessed'] ),
				'last_seen_at'   => '0000-00-00 00:00:00' !== $row['accessed'] ? $row['accessed'] : current_time( 'mysql' ),
			);

			if ( $existing ) {
				$repository->update( (int) $existing['id'], $data );
			} else {
				$repository->insert( $data );
			}

			++$migrated;
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_404_log_rows(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_404_logs';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		return (array) $wpdb->get_results( "SELECT uri, accessed, times_accessed, referer FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
