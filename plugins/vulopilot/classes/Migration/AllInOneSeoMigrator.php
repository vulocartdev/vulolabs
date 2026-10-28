<?php
/**
 * AllInOneSeoMigrator class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Migration;

use VuloPilot\SeoVisibility\PostSeoMetaFields;
use VuloPilot\SeoVisibility\RobotsTxtManager;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Imports everything VuloPilot can read from a free All in One SEO (AIOSEO v4+, now listed as
 * "AI SEO" on wordpress.org) install, in one migrator (same shape as RankMathMigrator/
 * YoastSeoMigrator):
 *
 * - Per-post data from its own `{prefix}aioseo_posts` table (not postmeta).
 * - Sitewide settings, from its own two option rows - `aioseo_options` (a single JSON blob,
 *   confirmed by activating the real plugin and reading `get_option('aioseo_options')` directly;
 *   AIOSEO's actual plugin logic lives in a private package not published on GitHub, so this was
 *   verified empirically rather than against source) and `aioseo_options_dynamic` (same JSON
 *   shape, holding every real post type's/taxonomy's own title/description template, keyed by
 *   the real WordPress slug).
 *
 * - Robots.txt rules (`tools.robots.rules`), into VuloPilot's own custom robots.txt override.
 *   Each entry is a JSON-encoded string shaped `{"userAgent":"*","directive":"disallow",
 *   "fieldValue":"/wp-admin/"}` - confirmed against the real distributed plugin's own
 *   `app/Common/Tools/RobotsTxt.php` (`isRuleShaped()`/`encodeRule()`), not guessed. Rules are
 *   grouped by `userAgent` and written out as plain `User-agent:`/`Directive: value` lines - a raw
 *   copy of the data AIOSEO itself would produce (`stringifyRuleset()`), not a reimplementation of
 *   its own dedup/override merge logic.
 *
 * Not migrated: Redirects/404 Monitor. Confirmed by reading the real plugin's own files on disk
 * (not just its database) - free AIOSEO ships no Redirects or 404 Monitor code at all, not even a
 * disabled stub; it's a Pro-only add-on with nothing present to migrate from.
 *
 * @class       AllInOneSeoMigrator
 * @version     2.0.0
 * @author      VuloLabs
 */
class AllInOneSeoMigrator implements MigratorInterface {

	/**
	 * AIOSEO's own `#token` template syntax for each VuloPilot `%token%` TitleFormatter resolves.
	 * Anything else (`#post_excerpt`, `#author_bio`, `#taxonomy_description`, ...) is dropped
	 * rather than left as literal, unresolved `#...` text in a migrated title/description.
	 *
	 * @var array<string, string>
	 */
	private const TOKEN_MAP = array(
		'#site_title'     => '%site_title%',
		'#tagline'        => '%site_description%',
		'#separator_sa'   => '%sep%',
		'#post_title'     => '%post_title%',
		'#taxonomy_title' => '%category_title%',
		'#archive_date'   => '%archive_title%',
		'#author_name'    => '%author_name%',
		'#search_term'    => '%search_term%',
	);

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'all-in-one-seo';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'All in One SEO', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function count_available(): int {
		return $this->count_posts()
			+ ( empty( $this->build_settings_values() ) ? 0 : 1 )
			+ ( '' !== $this->build_robots_txt_content() ? 1 : 0 );
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
		$this->migrate_robots_txt( $overwrite, $migrated, $skipped );

		return array(
			'migrated' => $migrated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	// ---------------------------------------------------------------------
	// Robots.txt rules.
	// ---------------------------------------------------------------------

	/**
	 * @param bool $overwrite Passed through from migrate().
	 * @param int  $migrated  Running total, by reference.
	 * @param int  $skipped   Running total, by reference.
	 * @return void
	 */
	private function migrate_robots_txt( bool $overwrite, int &$migrated, int &$skipped ): void {
		$content = $this->build_robots_txt_content();

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
	}

	/**
	 * Raw-copies AIOSEO's own robots.txt rules into plain `User-agent:`/directive lines - the same
	 * grouped-by-user-agent shape `RobotsTxt::stringifyRuleset()` itself would output, built from
	 * the rules array directly rather than by re-running AIOSEO's own merge/override logic.
	 *
	 * @return string
	 */
	private function build_robots_txt_content(): string {
		$options = json_decode( (string) get_option( 'aioseo_options', '' ), true );
		$tools   = is_array( $options ) ? ( $options['tools']['robots'] ?? array() ) : array();
		$rules   = is_array( $tools ) ? ( $tools['rules'] ?? array() ) : array();

		if ( empty( $tools['enable'] ) || ! is_array( $rules ) || empty( $rules ) ) {
			return '';
		}

		$grouped = array();

		foreach ( $rules as $raw_rule ) {
			$rule = is_array( $raw_rule ) ? $raw_rule : json_decode( (string) $raw_rule, true );

			if ( ! is_array( $rule ) ) {
				continue;
			}

			$user_agent = (string) ( $rule['userAgent'] ?? '' );
			$directive  = (string) ( $rule['directive'] ?? '' );
			$value      = (string) ( $rule['fieldValue'] ?? '' );

			if ( '' === $user_agent || '' === $directive || '' === $value ) {
				continue;
			}

			$grouped[ $user_agent ][] = ucfirst( $directive ) . ': ' . $value;
		}

		if ( empty( $grouped ) ) {
			return '';
		}

		$lines = array();

		foreach ( $grouped as $user_agent => $directive_lines ) {
			$lines[] = 'User-agent: ' . $user_agent;

			foreach ( $directive_lines as $directive_line ) {
				$lines[] = $directive_line;
			}

			$lines[] = '';
		}

		return trim( implode( "\n", $lines ) );
	}

	// ---------------------------------------------------------------------
	// Per-post SEO data.
	// ---------------------------------------------------------------------

	/**
	 * @return int
	 */
	private function count_posts(): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aioseo_posts WHERE title != '' OR description != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param bool  $overwrite Passed through from migrate().
	 * @param int   $migrated  Running total, by reference.
	 * @param int   $skipped   Running total, by reference.
	 * @param array $errors    Running list, by reference.
	 * @return void
	 */
	private function migrate_posts( bool $overwrite, int &$migrated, int &$skipped, array &$errors ): void {
		if ( ! $this->table_exists() ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT post_id, title, description, keyphrases, canonical_url, robots_noindex, robots_nofollow, og_title, og_description
			FROM {$wpdb->prefix}aioseo_posts
			WHERE title != '' OR description != ''"
		);

		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;

			try {
				$existing = get_post_meta( $post_id, PostSeoMetaFields::META_KEYS['focus_keyword'], true );

				if ( ! $overwrite && '' !== (string) $existing ) {
					++$skipped;
					continue;
				}

				$this->migrate_post_row( $post_id, $row );
				++$migrated;
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( 'Post #%d: %s', $post_id, $e->getMessage() );
			}
		}
	}

	/**
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * @param int       $post_id Post id.
	 * @param \stdClass $row    One `wp_aioseo_posts` row.
	 * @return void
	 */
	private function migrate_post_row( int $post_id, \stdClass $row ): void {
		if ( '' !== (string) $row->canonical_url ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['canonical_url'], $row->canonical_url );
		}

		update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['robots_noindex'], (bool) $row->robots_noindex );
		update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['robots_nofollow'], (bool) $row->robots_nofollow );

		if ( '' !== (string) $row->og_title ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['social_title'], $row->og_title );
		}

		if ( '' !== (string) $row->og_description ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['social_description'], $row->og_description );
		}

		$keyword = $this->extract_focus_keyword( (string) $row->keyphrases );

		if ( '' !== $keyword ) {
			update_post_meta( $post_id, PostSeoMetaFields::META_KEYS['focus_keyword'], $keyword );
		}
	}

	/**
	 * AIOSEO stores `keyphrases` as a JSON blob: `{"focus":{"keyphrase":"..."},"additional":[...]}`.
	 *
	 * @param string $json_keyphrases Raw `keyphrases` column value.
	 * @return string
	 */
	private function extract_focus_keyword( string $json_keyphrases ): string {
		$decoded = json_decode( $json_keyphrases, true );

		return is_array( $decoded ) ? (string) ( $decoded['focus']['keyphrase'] ?? '' ) : '';
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
	 * @return array<string, mixed>
	 */
	private function build_settings_values(): array {
		$options = json_decode( (string) get_option( 'aioseo_options', '' ), true );
		$dynamic = json_decode( (string) get_option( 'aioseo_options_dynamic', '' ), true );

		if ( ! is_array( $options ) ) {
			return array();
		}

		$values = array();

		$this->migrate_webmaster_tools( $values, $options );
		$this->migrate_titles( $values, $options );
		$this->migrate_social( $values, $options );
		$this->migrate_sitemap( $values, $options );
		$this->migrate_entity_identity( $values, $options );

		if ( is_array( $dynamic ) ) {
			$this->migrate_dynamic_templates( $values, $dynamic );
		}

		return $values;
	}

	/**
	 * @param array $values  Field values being built, by reference.
	 * @param array $options The decoded `aioseo_options` blob.
	 * @return void
	 */
	private function migrate_webmaster_tools( array &$values, array $options ): void {
		$webmaster = $options['webmasterTools'] ?? array();

		if ( ! is_array( $webmaster ) ) {
			return;
		}

		$map = array(
			'webmaster_google_verification'    => 'google',
			'webmaster_bing_verification'      => 'bing',
			'webmaster_baidu_verification'     => 'baidu',
			'webmaster_yandex_verification'    => 'yandex',
			'webmaster_pinterest_verification' => 'pinterest',
			'webmaster_norton_verification'    => 'norton',
		);

		foreach ( $map as $vulopilot_field => $aioseo_field ) {
			$this->maybe_set_string( $values, $vulopilot_field, $webmaster[ $aioseo_field ] ?? '' );
		}
	}

	/**
	 * @param array $values  Field values being built, by reference.
	 * @param array $options The decoded `aioseo_options` blob.
	 * @return void
	 */
	private function migrate_titles( array &$values, array $options ): void {
		$global   = $options['searchAppearance']['global'] ?? array();
		$archives = $options['searchAppearance']['archives'] ?? array();

		if ( ! is_array( $global ) ) {
			return;
		}

		$this->maybe_set_string( $values, 'title_separator', $global['separator'] ?? '' );
		$this->maybe_set_template( $values, 'title_format_home', $global['siteTitle'] ?? '' );
		$this->maybe_set_template( $values, 'description_format_home', $global['metaDescription'] ?? '' );

		if ( is_array( $archives ) ) {
			if ( is_array( $archives['author'] ?? null ) ) {
				$this->maybe_set_template( $values, 'title_format_author', $archives['author']['title'] ?? '' );
				$this->maybe_set_template( $values, 'description_format_author', $archives['author']['metaDescription'] ?? '' );

				if ( isset( $archives['author']['advanced']['robotsMeta']['noindex'] ) ) {
					$values['robots_noindex_author'] = ! empty( $archives['author']['advanced']['robotsMeta']['noindex'] )
						? array( 'robots_noindex_author' )
						: array();
				}
			}

			if ( is_array( $archives['date'] ?? null ) ) {
				$this->maybe_set_template( $values, 'title_format_archive', $archives['date']['title'] ?? '' );
				$this->maybe_set_template( $values, 'description_format_archive', $archives['date']['metaDescription'] ?? '' );
			}

			if ( is_array( $archives['search'] ?? null ) ) {
				$this->maybe_set_template( $values, 'title_format_search', $archives['search']['title'] ?? '' );

				if ( isset( $archives['search']['advanced']['robotsMeta']['noindex'] ) ) {
					$values['robots_noindex_search'] = ! empty( $archives['search']['advanced']['robotsMeta']['noindex'] )
						? array( 'robots_noindex_search' )
						: array();
				}
			}
		}
	}

	/**
	 * @param array $values  Field values being built, by reference.
	 * @param array $options The decoded `aioseo_options` blob.
	 * @return void
	 */
	private function migrate_social( array &$values, array $options ): void {
		$social = $options['social'] ?? array();

		if ( ! is_array( $social ) ) {
			return;
		}

		$this->maybe_set_string( $values, 'facebook_app_id', $social['facebook']['advanced']['appId'] ?? '' );
		$this->maybe_set_string( $values, 'facebook_admin_id', $social['facebook']['advanced']['adminId'] ?? '' );
		$this->maybe_set_string( $values, 'social_twitter_card_type', $social['twitter']['general']['defaultCardType'] ?? '' );

		if ( ! empty( $social['facebook']['general']['defaultImagePosts'] ) ) {
			$this->maybe_set_attachment_id_from_url( $values, 'social_default_image_id', (string) $social['facebook']['general']['defaultImagePosts'] );
		}

		$urls = $social['profiles']['urls'] ?? array();

		if ( is_array( $urls ) ) {
			$profile_urls = array_values( array_filter( array_map( 'strval', $urls ) ) );

			if ( ! empty( $profile_urls ) ) {
				$values['social_profile_urls'] = implode( "\n", $profile_urls );
			}
		}
	}

	/**
	 * @param array $values  Field values being built, by reference.
	 * @param array $options The decoded `aioseo_options` blob.
	 * @return void
	 */
	private function migrate_sitemap( array &$values, array $options ): void {
		$sitemap = $options['sitemap']['general'] ?? array();

		if ( ! is_array( $sitemap ) ) {
			return;
		}

		if ( isset( $sitemap['enable'] ) ) {
			$values['sitemap_enabled'] = ! empty( $sitemap['enable'] ) ? array( 'sitemap_enabled' ) : array();
		}

		if ( ! empty( $sitemap['linksPerIndex'] ) ) {
			$values['sitemap_links_per_page'] = (int) $sitemap['linksPerIndex'];
		}

		if ( isset( $sitemap['author'] ) ) {
			$values['sitemap_include_authors'] = ! empty( $sitemap['author'] ) ? array( 'sitemap_include_authors' ) : array();
		}

		if ( ! empty( $sitemap['postTypes']['included'] ) && is_array( $sitemap['postTypes']['included'] ) ) {
			$values['sitemap_xml_post_types'] = array_values( array_map( 'strval', $sitemap['postTypes']['included'] ) );
		}

		if ( ! empty( $sitemap['taxonomies']['included'] ) && is_array( $sitemap['taxonomies']['included'] ) ) {
			$values['sitemap_xml_taxonomies'] = array_values( array_map( 'strval', $sitemap['taxonomies']['included'] ) );
		}

		$advanced = $sitemap['advancedSettings'] ?? array();

		if ( is_array( $advanced ) && ! empty( $advanced['enable'] ) ) {
			$values['sitemap_include_images'] = empty( $advanced['excludeImages'] ) ? array( 'sitemap_include_images' ) : array();

			if ( ! empty( $advanced['excludePosts'] ) && is_array( $advanced['excludePosts'] ) ) {
				$values['sitemap_exclude_posts'] = implode( ',', array_map( 'absint', $advanced['excludePosts'] ) );
			}

			if ( ! empty( $advanced['excludeTerms'] ) && is_array( $advanced['excludeTerms'] ) ) {
				$values['sitemap_exclude_terms'] = implode( ',', array_map( 'absint', $advanced['excludeTerms'] ) );
			}
		}

		$html = $options['sitemap']['html'] ?? array();

		if ( is_array( $html ) && isset( $html['enable'] ) ) {
			$values['html_sitemap_enabled'] = ! empty( $html['enable'] ) ? array( 'html_sitemap_enabled' ) : array();

			$sort_map = array(
				'publish_date' => 'published_date',
				'title'        => 'title',
			);

			if ( isset( $sort_map[ $html['sortOrder'] ?? '' ] ) ) {
				$values['html_sitemap_sort_by'] = $sort_map[ $html['sortOrder'] ];
			}
		}
	}

	/**
	 * AIOSEO's Schema settings (`searchAppearance.global.schema`) - the same business-identity
	 * concept RankMathMigrator's `knowledgegraph_type` mapping and YoastSeoMigrator's
	 * `company_or_person` mapping both write into.
	 *
	 * @param array $values  Field values being built, by reference.
	 * @param array $options The decoded `aioseo_options` blob.
	 * @return void
	 */
	private function migrate_entity_identity( array &$values, array $options ): void {
		$schema = $options['searchAppearance']['global']['schema'] ?? array();

		if ( ! is_array( $schema ) ) {
			return;
		}

		$type_map = array(
			'person'       => 'Person',
			'organization' => 'Organization',
		);

		if ( isset( $type_map[ $schema['siteRepresents'] ?? '' ] ) ) {
			$values['entity_business_type'] = $type_map[ $schema['siteRepresents'] ];
		}

		$is_person = 'person' === ( $schema['siteRepresents'] ?? '' );
		$name      = $is_person ? ( $schema['personName'] ?? '' ) : ( $schema['organizationName'] ?? '' );

		$this->maybe_set_string( $values, 'entity_business_name', $name );

		$logo_url = $is_person ? ( $schema['personLogo'] ?? '' ) : ( $schema['organizationLogo'] ?? '' );

		if ( ! empty( $logo_url ) ) {
			$this->maybe_set_attachment_id_from_url( $values, 'entity_business_logo_id', (string) $logo_url );
		}
	}

	/**
	 * Every real post type's/taxonomy's own title/description template, from
	 * `aioseo_options_dynamic`'s `searchAppearance.postTypes`/`taxonomies` (keyed by the real
	 * WordPress slug already - no separate lookup needed). 'post'/'page' and 'category'/'post_tag'
	 * route to VuloPilot's own flat fields, everything else into the
	 * `title_format_post_types`/`title_format_taxonomies` maps.
	 *
	 * @param array $values  Field values being built, by reference.
	 * @param array $dynamic The decoded `aioseo_options_dynamic` blob.
	 * @return void
	 */
	private function migrate_dynamic_templates( array &$values, array $dynamic ): void {
		$flat_post_types = array(
			'post' => 'post',
			'page' => 'page',
		);
		$flat_taxonomies = array(
			'category' => 'category',
			'post_tag' => 'tag',
		);

		$post_types = $dynamic['searchAppearance']['postTypes'] ?? array();
		$taxonomies = $dynamic['searchAppearance']['taxonomies'] ?? array();

		$post_type_titles       = array();
		$post_type_descriptions = array();

		if ( is_array( $post_types ) ) {
			foreach ( $post_types as $slug => $config ) {
				if ( ! is_array( $config ) ) {
					continue;
				}

				$title = $this->translate_aioseo_template( (string) ( $config['title'] ?? '' ) );
				$desc  = $this->translate_aioseo_template( (string) ( $config['metaDescription'] ?? '' ) );

				if ( isset( $flat_post_types[ $slug ] ) ) {
					$this->maybe_set_string( $values, "title_format_{$flat_post_types[ $slug ]}", $title );
					$this->maybe_set_string( $values, "description_format_{$flat_post_types[ $slug ]}", $desc );
				} else {
					if ( '' !== $title ) {
						$post_type_titles[ $slug ] = $title;
					}
					if ( '' !== $desc ) {
						$post_type_descriptions[ $slug ] = $desc;
					}
				}
			}
		}

		if ( ! empty( $post_type_titles ) ) {
			$values['title_format_post_types'] = $post_type_titles;
		}

		if ( ! empty( $post_type_descriptions ) ) {
			$values['description_format_post_types'] = $post_type_descriptions;
		}

		$taxonomy_titles       = array();
		$taxonomy_descriptions = array();

		if ( is_array( $taxonomies ) ) {
			foreach ( $taxonomies as $slug => $config ) {
				if ( ! is_array( $config ) ) {
					continue;
				}

				$title = $this->translate_aioseo_template( (string) ( $config['title'] ?? '' ) );
				$desc  = $this->translate_aioseo_template( (string) ( $config['metaDescription'] ?? '' ) );

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
			}
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
	 * @param mixed  $raw    Raw AIOSEO template string (its own `#token` syntax).
	 * @return void
	 */
	private function maybe_set_template( array &$values, string $field, $raw ): void {
		$translated = $this->translate_aioseo_template( (string) $raw );

		if ( '' !== $translated ) {
			$values[ $field ] = $translated;
		}
	}

	/**
	 * AIOSEO's `#token` template syntax into VuloPilot's `%token%` one - unsupported tokens are
	 * dropped rather than left as literal `#...` text (same policy as YoastSeoMigrator's own
	 * `%%token%%` translator).
	 *
	 * @param string $template Raw AIOSEO template.
	 * @return string
	 */
	private function translate_aioseo_template( string $template ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$translated = strtr( $template, self::TOKEN_MAP );
		$translated = preg_replace( '/#[a-z_]+/i', '', $translated );
		$translated = preg_replace( '/\s{2,}/', ' ', (string) $translated );

		return trim( (string) $translated );
	}

	/**
	 * AIOSEO stores its default social image and Schema logo as a real attachment URL, not an id
	 * (unlike Rank Math's/Yoast's own `*_id` companion fields) - resolved back to a local
	 * attachment id via core's own `attachment_url_to_postid()` when the URL is one of this site's
	 * own uploads; a remote or otherwise unresolvable URL is left unmigrated rather than guessed.
	 *
	 * @param array  $values Field values being built, by reference.
	 * @param string $field  VuloPilot settings field name (an `*_id` field).
	 * @param string $url    Raw AIOSEO image URL.
	 * @return void
	 */
	private function maybe_set_attachment_id_from_url( array &$values, string $field, string $url ): void {
		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			$values[ $field ] = $attachment_id;
		}
	}

	/**
	 * Sets `$values[$field]` only when `$raw` is a real, non-empty string.
	 *
	 * @param array  $values Field values being built, by reference.
	 * @param string $field  VuloPilot settings field name.
	 * @param mixed  $raw    Raw AIOSEO value.
	 * @return void
	 */
	private function maybe_set_string( array &$values, string $field, $raw ): void {
		$value = trim( (string) $raw );

		if ( '' !== $value ) {
			$values[ $field ] = $value;
		}
	}
}
