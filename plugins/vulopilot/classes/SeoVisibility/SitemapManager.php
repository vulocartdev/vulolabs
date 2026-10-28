<?php
namespace VuloPilot\SeoVisibility;

use VuloPilot\Content\RedirectRepository;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Settings-driven filters over WordPress core's native sitemap at /wp-sitemap.xml (enable,
 * links per page, post types, taxonomies, exclusions).
 *
 * @class       SitemapManager class
 * @version     1.0.0
 * @author      VuloLabs
 */
class SitemapManager {

	/**
	 * SitemapManager constructor.
	 */
	public function __construct() {
		add_filter( 'wp_sitemaps_enabled', array( $this, 'filter_sitemaps_enabled' ) );

		add_filter( 'wp_sitemaps_max_urls', array( $this, 'filter_max_urls' ) );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'filter_taxonomies' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_posts_query_args' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_taxonomies_query_args' ) );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_posts_entry' ), 10, 2 );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_users_query_args', array( $this, 'filter_users_query_args' ) );

		add_action( 'save_post', array( $this, 'clear_conflict_cache' ) );
		add_action( 'deleted_post', array( $this, 'clear_conflict_cache' ) );
	}

	/**
	 * Maximum URLs per sitemap allowed by the sitemaps protocol.
	 */
	private const PROTOCOL_MAX_URLS = 50000;

	/**
	 * Transient holding the ids of posts that must not be listed.
	 */
	private const CONFLICT_TRANSIENT = 'vulopilot_sitemap_conflicting_ids';

	/**
	 * @return array<string, mixed> Effective settings, defaults filled in.
	 */
	private function get_settings(): array {
		return wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
	}

	/**
	 * `sitemap_links_per_page` - 0 or unset falls back to core's own
	 * default (2000) rather than passing through a nonsensical override.
	 *
	 * @param int $max_urls Core's own current max-URLs-per-page value.
	 * @return int
	 */
	public function filter_max_urls( $max_urls ) {
		$links_per_page = (int) ( $this->get_settings()['sitemap_links_per_page'] ?? 0 );

		$max_urls = $links_per_page > 0 ? $links_per_page : $max_urls;

		return min( (int) $max_urls, self::PROTOCOL_MAX_URLS );
	}

	/**
	 * Narrows core's own registered sitemap post types down to `sitemap_xml_post_types`.
	 *
	 * @param \WP_Post_Type[] $post_types Core's own currently-registered sitemap post types, keyed by slug.
	 * @return \WP_Post_Type[]
	 */
	public function filter_post_types( $post_types ) {
		$included = (array) ( $this->get_settings()['sitemap_xml_post_types'] ?? array() );

		foreach ( array_keys( $post_types ) as $slug ) {
			if ( ! in_array( $slug, $included, true ) ) {
				unset( $post_types[ $slug ] );
			}
		}

		return $post_types;
	}

	/**
	 * Same narrowing as filter_post_types(), for taxonomies.
	 *
	 * @param \WP_Taxonomy[] $taxonomies Core's own currently-registered sitemap taxonomies, keyed by slug.
	 * @return \WP_Taxonomy[]
	 */
	public function filter_taxonomies( $taxonomies ) {
		$included = (array) ( $this->get_settings()['sitemap_xml_taxonomies'] ?? array() );

		foreach ( array_keys( $taxonomies ) as $slug ) {
			if ( ! in_array( $slug, $included, true ) ) {
				unset( $taxonomies[ $slug ] );
			}
		}

		return $taxonomies;
	}

	/**
	 * `sitemap_exclude_posts` - comma-separated post IDs, applied via
	 * core's own `wp_sitemaps_posts_query_args` filter.
	 *
	 * @param array $args Core's own current WP_Query args for one sitemap page.
	 * @return array
	 */
	public function filter_posts_query_args( $args ) {
		$excluded = array_merge(
			$this->parse_id_list( (string) ( $this->get_settings()['sitemap_exclude_posts'] ?? '' ) ),
			$this->get_conflicting_post_ids()
		);

		if ( $excluded ) {
			$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $excluded ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- admin-configured `sitemap_exclude_posts` list, a small bounded set of explicit ids, not an unbounded/user-controlled exclusion.
		}

		return $args;
	}

	/**
	 * `sitemap_exclude_terms` - comma-separated term IDs, applied via
	 * core's own `wp_sitemaps_taxonomies_query_args` filter.
	 *
	 * @param array $args Core's own current get_terms() args for one sitemap page.
	 * @return array
	 */
	public function filter_taxonomies_query_args( $args ) {
		$excluded = $this->parse_id_list( (string) ( $this->get_settings()['sitemap_exclude_terms'] ?? '' ) );

		// Empty archives are thin pages, so never list them.
		$args['hide_empty'] = true;

		if ( $excluded ) {
			$args['exclude'] = array_merge( $args['exclude'] ?? array(), $excluded ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- admin-configured `sitemap_exclude_terms` list, a small bounded set of explicit ids, not an unbounded/user-controlled exclusion.
		}

		return $args;
	}

	/**
	 * Drops the author (users) sitemap on single-author sites, where the author archive
	 * only repeats the blog page.
	 *
	 * @param \WP_Sitemaps_Provider|false $provider Sitemap provider.
	 * @param string                      $name     Provider name.
	 * @return \WP_Sitemaps_Provider|false
	 */
	public function filter_provider( $provider, $name ) {
		if ( 'users' !== $name ) {
			return $provider;
		}

		// Scanning → Sitemap → Authors' own master switch - 'on' by default (Rank Math's own
		// default too), so most sites see no behavior change from this field existing.
		if ( ! $this->is_enabled( 'sitemap_include_authors' ) ) {
			return false;
		}

		if ( ! $this->is_enabled( 'sitemap_skip_single_author' ) ) {
			return $provider;
		}

		$authors = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => 'ID',
				'number'              => 2,
			)
		);

		return count( $authors ) > 1 ? $provider : false;
	}

	/**
	 * `sitemap_exclude_author_roles`/`sitemap_exclude_author_ids` - applied via core's own
	 * `wp_sitemaps_users_query_args` filter (the 'users' provider's own get_users() args).
	 *
	 * @param array $args Core's own current get_users() args for one users-sitemap page.
	 * @return array
	 */
	public function filter_users_query_args( $args ) {
		$settings       = $this->get_settings();
		$excluded_ids   = $this->parse_id_list( (string) ( $settings['sitemap_exclude_author_ids'] ?? '' ) );
		$excluded_roles = array_values(
			array_filter( array_map( 'trim', explode( ',', (string) ( $settings['sitemap_exclude_author_roles'] ?? '' ) ) ) )
		);

		if ( $excluded_ids ) {
			$args['exclude'] = array_merge( $args['exclude'] ?? array(), $excluded_ids );
		}

		if ( $excluded_roles ) {
			$role_user_ids = get_users(
				array(
					'role__in' => $excluded_roles,
					'fields'   => 'ID',
				)
			);

			if ( $role_user_ids ) {
				$args['exclude'] = array_merge( $args['exclude'] ?? array(), $role_user_ids );
			}
		}

		return $args;
	}

	/**
	 * Adds a `lastmod` timestamp taken from the post's modified date.
	 *
	 * @param array<string, string> $entry Sitemap entry.
	 * @param \WP_Post              $post  Post the entry is for.
	 * @return array<string, string>
	 */
	public function filter_posts_entry( $entry, $post ) {
		if ( empty( $entry['lastmod'] ) && ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			$entry['lastmod'] = gmdate( 'c', (int) strtotime( $post->post_modified_gmt . ' UTC' ) );
		}

		return $entry;
	}

	/**
	 * Ids of posts that should not be in the sitemap: noindex posts, posts whose canonical
	 * points elsewhere, redirected posts and unedited placeholder posts. Cached briefly.
	 *
	 * @return int[]
	 */
	private function get_conflicting_post_ids(): array {
		$cached = get_transient( self::CONFLICT_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = array_merge(
			$this->get_noindex_post_ids(),
			$this->get_canonicalized_elsewhere_post_ids(),
			$this->get_redirected_post_ids(),
			$this->get_placeholder_post_ids()
		);
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		set_transient( self::CONFLICT_TRANSIENT, $ids, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * @param string $key Toggle setting key.
	 * @return bool Whether the toggle is on.
	 */
	private function is_enabled( string $key ): bool {
		return ! empty( $this->get_settings()[ $key ] );
	}

	/**
	 * Clears the cached list of conflicting post ids.
	 *
	 * @return void
	 */
	public function clear_conflict_cache(): void {
		delete_transient( self::CONFLICT_TRANSIENT );
	}

	/**
	 * Runs a post id query page by page, up to 50 pages of 100.
	 *
	 * @param array<string, mixed> $args get_posts() args with `posts_per_page` set.
	 * @return int[]
	 */
	private function get_all_post_ids( array $args ): array {
		$ids = array();

		for ( $page = 1; $page <= 50; $page++ ) {
			$batch = get_posts( array_merge( $args, array( 'paged' => $page ) ) );
			$ids   = array_merge( $ids, $batch );

			if ( count( $batch ) < (int) $args['posts_per_page'] ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * @return int[] WordPress's default "Hello world!" and "Sample Page" while still unedited.
	 */
	private function get_placeholder_post_ids(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'post_name__in'  => array( 'hello-world', 'sample-page' ),
				'posts_per_page' => 10,
				'no_found_rows'  => true,
			)
		);

		$ids = array();

		foreach ( $posts as $post ) {
			if ( $post->post_date_gmt === $post->post_modified_gmt ) {
				$ids[] = $post->ID;
			}
		}

		return $ids;
	}

	/**
	 * @return int[] Posts flagged noindex in the post editor.
	 */
	private function get_noindex_post_ids(): array {
		return $this->get_all_post_ids(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => PostSeoMetaFields::META_KEYS['robots_noindex'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one bounded, cached lookup of posts flagged noindex.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see meta_key above.
			)
		);
	}

	/**
	 * @return int[] Posts with a custom canonical URL that differs from their own permalink.
	 */
	private function get_canonicalized_elsewhere_post_ids(): array {
		$post_ids = $this->get_all_post_ids(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => PostSeoMetaFields::META_KEYS['canonical_url'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one bounded, cached lookup of posts with a custom canonical.
				'meta_compare'   => '!=',
				'meta_value'     => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see meta_key above.
			)
		);

		$elsewhere = array();

		foreach ( $post_ids as $post_id ) {
			$canonical = trailingslashit( (string) get_post_meta( $post_id, PostSeoMetaFields::META_KEYS['canonical_url'], true ) );

			if ( '/' !== $canonical && trailingslashit( (string) get_permalink( $post_id ) ) !== $canonical ) {
				$elsewhere[] = $post_id;
			}
		}

		return $elsewhere;
	}

	/**
	 * @return int[] Posts whose permalink is the source of an active redirect.
	 */
	private function get_redirected_post_ids(): array {
		$rows = ( new RedirectRepository() )->find_all(
			array(
				'is_active' => 1,
				'page'      => 1,
				'per_page'  => 1000,
			)
		)['data'];

		$post_ids = array();

		foreach ( $rows as $row ) {
			$post_id = url_to_postid( home_url( (string) $row['source_path'] ) );

			if ( $post_id ) {
				$post_ids[] = $post_id;
			}
		}

		return $post_ids;
	}

	/**
	 * @param string $raw Comma-separated IDs, e.g. "12, 48, 103".
	 * @return int[] Positive integer IDs only.
	 */
	private function parse_id_list( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', explode( ',', $raw ) )
			)
		);
	}

	/**
	 * @param bool $enabled Core's own current wp_sitemaps_enabled value.
	 * @return bool
	 */
	public function filter_sitemaps_enabled( $enabled ) {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['sitemap_enabled'] ) ) {
			return false;
		}

		return $enabled;
	}
}
