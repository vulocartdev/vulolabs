<?php
/**
 * YoastSeoMigrator class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Migration;

use VuloPilot\SeoVisibility\PostSeoMetaFields;
use VuloPilot\SeoVisibility\RobotsTxtManager;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Imports everything VuloPilot can read from a free Yoast SEO install, in one migrator (same
 * shape as RankMathMigrator):
 *
 * - Per-post SEO postmeta (`_yoast_wpseo_*`) into `PostSeoMetaFields::META_KEYS`.
 * - Sitewide settings, from Yoast's own three option rows (schema confirmed against
 *   `inc/options/class-wpseo-option-{wpseo,social,titles}.php` in Yoast/wordpress-seo):
 *   `wpseo` (webmaster verification, IndexNow key), `wpseo_social` (Pinterest verification,
 *   default OG image/Twitter card, social profile URLs), `wpseo_titles` (separator, title/
 *   description templates - including every post type's/taxonomy's own dynamic `title-{slug}`/
 *   `title-tax-{slug}` key, not just post/page/category/tag - and Knowledge Graph identity).
 *
 * - Per-post schema type (`_yoast_wpseo_schema_page_type`/`_yoast_wpseo_schema_article_type`,
 *   confirmed against `Indexable_To_Postmeta_Helper::$yoast_to_postmeta` in the real plugin)
 *   into `PostSeoMetaFields::META_KEYS['schema_type']`.
 * - Per-post AI content score, from Yoast's own legacy postmeta `_yoast_wpseo_linkdex` (SEO
 *   score) and `_yoast_wpseo_content_score` (readability) - confirmed these are still the real
 *   source of truth read by `Indexable_Post_Builder::build()` itself (`$this->meta->get_value(
 *   'linkdex'/'content_score', $post_id )`), not legacy/unused fields - into VuloPilot's own
 *   `_vulopilot_content_score` postmeta, the same JSON shape `ContentScore::to_array()` writes.
 *
 * No redirects/404-log migration here, unlike RankMathMigrator: Yoast's Redirect Manager and 404
 * Monitor are Premium-only features with no free-plugin database tables to read, so there is
 * nothing to migrate for them.
 *
 * Settings fields with no VuloPilot field to write into (Breadcrumbs, RSS content injection, the
 * per-post-type noindex-{type} toggles, disable-author/disable-date/disable-post_format/
 * disable-attachment archive kill switches) are deliberately left out rather than guessed at.
 *
 * @class       YoastSeoMigrator
 * @version     2.0.0
 * @author      VuloLabs
 */
class YoastSeoMigrator implements MigratorInterface {

	/**
	 * `_yoast_wpseo_*` postmeta key for each VuloPilot per-post field it has an equivalent for.
	 *
	 * @var array<string, string>
	 */
	private const POST_META_MAP = array(
		'focus_keyword'      => '_yoast_wpseo_focuskw',
		'canonical_url'      => '_yoast_wpseo_canonical',
		'robots_noindex'     => '_yoast_wpseo_meta-robots-noindex',
		'robots_nofollow'    => '_yoast_wpseo_meta-robots-nofollow',
		'social_title'       => '_yoast_wpseo_opengraph-title',
		'social_description' => '_yoast_wpseo_opengraph-description',
		'social_image_id'    => '_yoast_wpseo_opengraph-image-id',
	);

	/**
	 * Yoast's own `wpseo_titles` separator codes (`class-wpseo-option-titles.php`'s own
	 * `get_separator_option_list()`) to the literal character `title_separator` expects.
	 *
	 * @var array<string, string>
	 */
	private const SEPARATOR_MAP = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⁕',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '>',
		'sc-gt'     => '<',
	);

	/**
	 * The five built-in contexts with their own fixed `wpseo_titles` key, keyed by VuloPilot field.
	 *
	 * @var array<string, string>
	 */
	private const FIXED_TITLE_FIELD_MAP = array(
		'title_format_home'          => 'title-home-wpseo',
		'description_format_home'    => 'metadesc-home-wpseo',
		'title_format_archive'       => 'title-archive-wpseo',
		'description_format_archive' => 'metadesc-archive-wpseo',
		'title_format_search'        => 'title-search-wpseo',
		'title_format_author'        => 'title-author-wpseo',
		'description_format_author'  => 'metadesc-author-wpseo',
	);

	/** `wpseo_titles` keys that are NOT a dynamic post-type/taxonomy template - excluded when scanning for those. */
	private const FIXED_TITLE_KEYS = array(
		'title-home-wpseo',
		'title-author-wpseo',
		'title-archive-wpseo',
		'title-search-wpseo',
		'title-404-wpseo',
		'metadesc-home-wpseo',
		'metadesc-author-wpseo',
		'metadesc-archive-wpseo',
	);

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'yoast-seo';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Yoast SEO', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function count_available(): int {
		return count( $this->find_post_ids() )
			+ ( empty( $this->build_settings_values() ) ? 0 : 1 )
			+ ( '' !== $this->read_physical_robots_txt() ? 1 : 0 );
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
		$this->migrate_robots_txt( $overwrite, $migrated, $skipped, $errors );

		return array(
			'migrated' => $migrated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Yoast's File Editor tool (`admin/views/tool-file-editor.php`) writes straight to the real
	 * `robots.txt` file at the site root (`fopen()`/`fwrite()`) - there is no `wpseo_*` option
	 * holding this content, unlike Rank Math's `robots_txt_content`. So this reads that real file
	 * (if one exists) and copies its content into VuloPilot's own override
	 * (`RobotsTxtManager::save_custom_content()`).
	 *
	 * Important: while that physical file still exists, WordPress's entire virtual robots.txt
	 * system never runs for this request at all - the webserver serves the static file directly,
	 * bypassing every plugin's `robots_txt` filter, VuloPilot's own included. So copying the
	 * content alone isn't enough to make VuloPilot's version take effect; this is reported back
	 * as an error/warning entry telling the admin the physical file still needs removing, rather
	 * than deleting it here automatically (a filesystem delete is too destructive to do silently
	 * inside a background migration step).
	 *
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

		$errors[] = __(
			'robots.txt content was copied into VuloPilot, but a real robots.txt file still exists on your server - WordPress serves that file directly and ignores every plugin\'s own robots.txt output (VuloPilot\'s included) while it\'s there. Remove or rename the physical robots.txt file at your site\'s root for VuloPilot\'s version to take effect.',
			'vulopilot'
		);
	}

	/**
	 * @return string The real `robots.txt` file's content at the site root, or '' if none exists
	 *                 or it can't be read.
	 */
	private function read_physical_robots_txt(): string {
		$path = trailingslashit( ABSPATH ) . 'robots.txt';

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		return trim( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a real local file at a hardcoded, non-user-controllable path (the site's own robots.txt), not a remote URL.
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

				$schema_type = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true );

				if ( 'Article' === $schema_type ) {
					$article_type = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_article_type', true );
					$schema_type  = '' !== $article_type ? $article_type : $schema_type;
				}

				if ( '' !== $schema_type ) {
					update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['schema_type'], $schema_type );
				}

				$this->migrate_content_score( $post_id, $overwrite, $migrated, $skipped );

				++$migrated;
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( 'Post #%d: %s', $post_id, $e->getMessage() );
			}
		}
	}

	/**
	 * Yoast's own legacy `linkdex`/`content_score` postmeta into VuloPilot's `ContentScore`
	 * JSON shape, written directly as postmeta - same convention every other per-post field in
	 * this migrator follows.
	 *
	 * @param int  $post_id   Post id.
	 * @param bool $overwrite Passed through from migrate().
	 * @param int  $migrated  Running total, by reference.
	 * @param int  $skipped   Running total, by reference.
	 * @return void
	 */
	private function migrate_content_score( int $post_id, bool $overwrite, int &$migrated, int &$skipped ): void {
		$seo_score = get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );

		if ( '' === $seo_score || null === $seo_score ) {
			return;
		}

		$existing = get_post_meta( $post_id, '_vulopilot_content_score', true );

		if ( ! $overwrite && '' !== (string) $existing ) {
			++$skipped;
			return;
		}

		$readability = get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
		$suggestions = array();

		if ( '' !== $readability && null !== $readability ) {
			$suggestions[] = sprintf(
				/* translators: %d: Yoast's own readability score, 0-100. */
				__( 'Readability score from Yoast: %d/100.', 'vulopilot' ),
				(int) $readability
			);
		}

		update_post_meta(
			$post_id,
			'_vulopilot_content_score',
			wp_json_encode(
				array(
					'post_id'             => $post_id,
					'deterministic_score' => null,
					'topic_authority'     => 0,
					'overall_score'       => (int) $seo_score,
					'suggestions'         => $suggestions,
					'generated_at'        => current_time( 'mysql', true ),
				)
			)
		);
		++$migrated;
	}

	/**
	 * @return int[]
	 */
	private function find_post_ids(): array {
		$keys       = array_unique( array_values( self::POST_META_MAP ) );
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
		// Yoast stores these as the string '1' for "on", '0'/absent for "off".
		if ( 'robots_noindex' === $field || 'robots_nofollow' === $field ) {
			return '1' === (string) $raw_value;
		}

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
	 * Every VuloPilot settings field Yoast has real, non-empty data for.
	 *
	 * @return array<string, mixed>
	 */
	private function build_settings_values(): array {
		$wpseo  = get_option( 'wpseo', array() );
		$social = get_option( 'wpseo_social', array() );
		$titles = get_option( 'wpseo_titles', array() );

		if ( ! is_array( $wpseo ) || ! is_array( $social ) || ! is_array( $titles ) ) {
			return array();
		}

		$values = array();

		$this->maybe_set_string( $values, 'webmaster_google_verification', $wpseo['googleverify'] ?? '' );
		$this->maybe_set_string( $values, 'webmaster_bing_verification', $wpseo['msverify'] ?? '' );
		$this->maybe_set_string( $values, 'webmaster_baidu_verification', $wpseo['baiduverify'] ?? '' );
		$this->maybe_set_string( $values, 'webmaster_yandex_verification', $wpseo['yandexverify'] ?? '' );
		$this->maybe_set_string( $values, 'indexnow_api_key', $wpseo['index_now_key'] ?? '' );

		$this->maybe_set_string( $values, 'webmaster_pinterest_verification', $social['pinterestverify'] ?? '' );
		$this->maybe_set_string( $values, 'social_twitter_card_type', $social['twitter_card_type'] ?? '' );

		if ( ! empty( $social['og_default_image_id'] ) ) {
			$values['social_default_image_id'] = absint( $social['og_default_image_id'] );
		}

		$profile_urls = array_values(
			array_filter(
				array_merge(
					array(
						(string) ( $social['facebook_site'] ?? '' ),
						(string) ( $social['instagram_url'] ?? '' ),
						(string) ( $social['linkedin_url'] ?? '' ),
						(string) ( $social['pinterest_url'] ?? '' ),
						(string) ( $social['youtube_url'] ?? '' ),
						(string) ( $social['mastodon_url'] ?? '' ),
						(string) ( $social['wikipedia_url'] ?? '' ),
					),
					is_array( $social['other_social_urls'] ?? null ) ? array_map( 'strval', $social['other_social_urls'] ) : array()
				)
			)
		);

		if ( ! empty( $profile_urls ) ) {
			$values['social_profile_urls'] = implode( "\n", $profile_urls );
		}

		if ( isset( $titles['separator'] ) && isset( self::SEPARATOR_MAP[ $titles['separator'] ] ) ) {
			$values['title_separator'] = self::SEPARATOR_MAP[ $titles['separator'] ];
		}

		foreach ( self::FIXED_TITLE_FIELD_MAP as $vulopilot_field => $yoast_key ) {
			$raw = $titles[ $yoast_key ] ?? '';

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$context    = false !== strpos( $vulopilot_field, '_author' ) ? 'author' : 'default';
				$translated = $this->translate_yoast_template( $raw, $context );

				if ( '' !== $translated ) {
					$values[ $vulopilot_field ] = $translated;
				}
			}
		}

		if ( isset( $titles['noindex-author-wpseo'] ) ) {
			$values['robots_noindex_author'] = ! empty( $titles['noindex-author-wpseo'] )
				? array( 'robots_noindex_author' )
				: array();
		}

		if ( isset( $titles['noindex-author-noposts-wpseo'] ) ) {
			$values['robots_noindex_author_without_posts'] = ! empty( $titles['noindex-author-noposts-wpseo'] )
				? array( 'robots_noindex_author_without_posts' )
				: array();
		}

		$this->migrate_dynamic_templates( $values, $titles );
		$this->migrate_entity_identity( $values, $titles );

		return $values;
	}

	/**
	 * Every post type's/taxonomy's own dynamic `title-{slug}`/`metadesc-{slug}`/
	 * `title-tax-{slug}`/`metadesc-tax-{slug}` key - unlike Rank Math, Yoast gives post/page and
	 * category/post_tag no separate fixed field, so even those built-in contexts go through this
	 * same dynamic path; 'post'/'page'/'category'/'post_tag' route to VuloPilot's own flat
	 * fields, everything else into the `title_format_post_types`/`title_format_taxonomies` maps.
	 *
	 * @param array $values Field values being built, by reference.
	 * @param array $titles The raw `wpseo_titles` option.
	 * @return void
	 */
	private function migrate_dynamic_templates( array &$values, array $titles ): void {
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

		foreach ( $titles as $key => $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) || in_array( $key, self::FIXED_TITLE_KEYS, true ) ) {
				continue;
			}

			$translated = $this->translate_yoast_template( $value );

			if ( preg_match( '/^title-tax-(.+)$/', $key, $matches ) ) {
				$slug = $matches[1];

				if ( isset( $flat_taxonomies[ $slug ] ) ) {
					$this->maybe_set_string( $values, "title_format_{$flat_taxonomies[ $slug ]}", $translated );
				} else {
					$taxonomy_titles[ $slug ] = $translated;
				}
			} elseif ( preg_match( '/^metadesc-tax-(.+)$/', $key, $matches ) ) {
				$slug = $matches[1];

				if ( isset( $flat_taxonomies[ $slug ] ) ) {
					$this->maybe_set_string( $values, "description_format_{$flat_taxonomies[ $slug ]}", $translated );
				} else {
					$taxonomy_descriptions[ $slug ] = $translated;
				}
			} elseif ( preg_match( '/^title-(?!tax-)([a-z0-9_-]+)$/i', $key, $matches ) ) {
				$slug = $matches[1];

				if ( isset( $flat_post_types[ $slug ] ) ) {
					$this->maybe_set_string( $values, "title_format_{$flat_post_types[ $slug ]}", $translated );
				} else {
					$post_type_titles[ $slug ] = $translated;
				}
			} elseif ( preg_match( '/^metadesc-(?!tax-)([a-z0-9_-]+)$/i', $key, $matches ) ) {
				$slug = $matches[1];

				if ( isset( $flat_post_types[ $slug ] ) ) {
					$this->maybe_set_string( $values, "description_format_{$flat_post_types[ $slug ]}", $translated );
				} else {
					$post_type_descriptions[ $slug ] = $translated;
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
	 * Yoast's Knowledge Graph identity (`company_or_person`/`company_name`/`person_name`/
	 * `company_logo_id`/`website_name`) into the same `entity_business_*` fields
	 * RankMathSettingsMigrator's own `knowledgegraph_type` mapping writes into.
	 *
	 * @param array $values Field values being built, by reference.
	 * @param array $titles The raw `wpseo_titles` option.
	 * @return void
	 */
	private function migrate_entity_identity( array &$values, array $titles ): void {
		if ( isset( $titles['company_or_person'] ) ) {
			$type_map = array(
				'person'  => 'Person',
				'company' => 'Organization',
			);

			if ( isset( $type_map[ $titles['company_or_person'] ] ) ) {
				$values['entity_business_type'] = $type_map[ $titles['company_or_person'] ];
			}
		}

		$is_person = 'person' === ( $titles['company_or_person'] ?? '' );
		$name      = $is_person ? ( $titles['person_name'] ?? '' ) : ( $titles['company_name'] ?? '' );
		$name      = '' !== trim( (string) $name ) ? $name : ( $titles['website_name'] ?? '' );

		$this->maybe_set_string( $values, 'entity_business_name', $name );

		if ( ! empty( $titles['company_logo_id'] ) ) {
			$values['entity_business_logo_id'] = absint( $titles['company_logo_id'] );
		}
	}

	/**
	 * Yoast's `%%variable%%` template syntax into VuloPilot's `%variable%` one - only the tokens
	 * TitleFormatter actually resolves; anything else (`%%focuskw%%`, `%%excerpt%%`, `%%page%%`,
	 * ...) is dropped rather than left as literal, unresolved `%%...%%` text in a migrated title.
	 *
	 * @param string $template Raw Yoast template.
	 * @param string $context  `'author'` for `title-author-wpseo`/`metadesc-author-wpseo`
	 *                         (its own `%%name%%` token means the author's display name, not
	 *                         `%post_title%` like every other context); `'default'` otherwise.
	 * @return string
	 */
	private function translate_yoast_template( string $template, string $context = 'default' ): string {
		$map = array(
			'%%sitename%%'     => '%site_title%',
			'%%sitedesc%%'     => '%site_description%',
			'%%sep%%'          => '%sep%',
			'%%searchphrase%%' => '%search_term%',
			// `%%title%%` means "this item's own title" everywhere - `%post_title%` resolves the
			// same way regardless of which context it's used in (TitleFormatter's own
			// var_current_title() reads real query conditionals, not the token name).
			'%%title%%'        => '%post_title%',
			'%%name%%'         => 'author' === $context ? '%author_name%' : '%post_title%',
		);

		$translated = strtr( $template, $map );
		$translated = preg_replace( '/%%[a-z0-9_-]+%%/i', '', $translated );
		$translated = preg_replace( '/\s{2,}/', ' ', (string) $translated );

		return trim( (string) $translated );
	}

	/**
	 * Sets `$values[$field]` only when `$raw` is a real, non-empty string.
	 *
	 * @param array  $values Field values being built, by reference.
	 * @param string $field  VuloPilot settings field name.
	 * @param mixed  $raw    Raw Yoast value.
	 * @return void
	 */
	private function maybe_set_string( array &$values, string $field, $raw ): void {
		$value = trim( (string) $raw );

		if ( '' !== $value ) {
			$values[ $field ] = $value;
		}
	}
}
