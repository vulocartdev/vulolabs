<?php
/**
 * EntityExtractor class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\KnowledgeGraph;

use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * "Entity Extraction" - the Free half of Phase 05's Knowledge Graph.
 *
 * @class       EntityExtractor class
 * @version     1.0.0
 * @author      VuloLabs
 */
class EntityExtractor {

	private const CACHE_KEY          = 'vulopilot_entity_extraction';
	private const CACHE_TTL_SECONDS  = HOUR_IN_SECONDS;
	private const AUTHOR_BATCH_SIZE  = 200;
	private const PRODUCT_BATCH_SIZE = 200;

	/**
	 * Same real slug list Geo\Scanners\GeoTrustSignalsScanner already checks for its own
	 * "site is missing a Contact page" finding.
	 */
	private const CONTACT_SLUGS = array( 'contact', 'contact-us' );

	/**
	 * Real slug list checked for `get_business_name_sources()`'s own "About page" source.
	 */
	private const ABOUT_SLUGS = array( 'about', 'about-us' );

	/**
	 * Real relationship sentences the "Knowledge Graph" section's own "Suggested
	 * relationships" panel shows.
	 */
	private const MAX_SUGGESTED_RELATIONSHIPS = 6;

	/**
	 * @return array{people: array, organizations: array, products: array|null, services: array, locations: array, categories: array, business_type: string, business_name: string, business_logo_url: string|null, has_contact_page: bool, contact_page_url: string|null, suggested_relationships: array<int, string>}
	 */
	public function extract_all(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! $this->is_module_active() ) {
			$empty = array(
				'people'                  => array(),
				'organizations'           => array(),
				'products'                => class_exists( 'WooCommerce' ) ? array() : null,
				'services'                => array(),
				'locations'               => array(),
				'categories'              => array(),
				'business_type'           => '',
				'business_name'           => '',
				'business_logo_url'       => null,
				'has_contact_page'        => false,
				'contact_page_url'        => null,
				'suggested_relationships' => array(),
			);

			return $empty;
		}

		$people        = $this->extract_people();
		$organizations = $this->extract_organizations();
		$products      = $this->extract_products();
		$services      = $this->extract_services();
		$locations     = $this->extract_locations();
		$categories    = $this->extract_categories();
		$contact       = $this->find_contact_page();
		$override_name = $this->get_business_name_setting();
		$business_name = '' !== $override_name ? $override_name : ( $organizations[0]['name'] ?? trim( (string) get_bloginfo( 'name' ) ) );

		$result = array(
			'people'                  => $people,
			'organizations'           => $organizations,
			'products'                => $products,
			'services'                => $services,
			'locations'               => $locations,
			'categories'              => $categories,
			'business_type'           => $this->get_business_type_setting(),
			'business_name'           => $business_name,
			'business_logo_url'       => $this->get_business_logo_url(),
			'has_contact_page'        => null !== $contact,
			'contact_page_url'        => $contact,
			'suggested_relationships' => $this->build_suggested_relationships( $business_name, $services, $products, $categories ),
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS );

		return $result;
	}

	/**
	 * @return string Real, owner-provided `entity_business_type` setting - empty until set, never guessed.
	 */
	private function get_business_type_setting(): string {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		return trim( (string) ( $settings['entity_business_type'] ?? '' ) );
	}

	/**
	 * @return string Real, owner-provided `entity_business_name` override - empty when unset, so
	 *                callers fall back to the first extracted organization's name, then the site
	 *                title.
	 */
	private function get_business_name_setting(): string {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		return trim( (string) ( $settings['entity_business_name'] ?? '' ) );
	}

	/**
	 * @return string|null Full URL of the owner-uploaded `entity_business_logo_id` attachment, or
	 *                      null when none is set.
	 */
	private function get_business_logo_url(): ?string {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$logo_id  = absint( $settings['entity_business_logo_id'] ?? 0 );

		if ( ! $logo_id ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $logo_id, 'full' );

		return $url ?: null;
	}

	/**
	 * @return string|null Real permalink of the first published About/Contact-slugged page found, or null if none exists.
	 */
	private function find_contact_page(): ?string {
		foreach ( self::CONTACT_SLUGS as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );

			if ( $page && 'publish' === $page->post_status ) {
				return get_permalink( $page->ID ) ? get_permalink( $page->ID ) : null;
			}
		}

		return null;
	}

	/**
	 * @param bool $refresh Bust EntityExtractor's own 1-hour cache first - real "Scan Again" semantics (re-reads every real source now).
	 * @return array{business_name: string, confidence: string, sources: array<int, array{key: string, label: string, value: string|null, found: bool, url: string|null}>, sources_checked: int, is_consistent: bool, consistent_count: int}
	 */
	public function get_business_name_sources( bool $refresh = false ): array {
		if ( $refresh ) {
			delete_transient( self::CACHE_KEY );
		}

		$data          = $this->extract_all();
		$business_name = $data['organizations'][0]['name'] ?? trim( (string) get_bloginfo( 'name' ) );

		$site_name     = trim( (string) get_bloginfo( 'name' ) );
		$homepage_name = $this->get_homepage_display_name();
		$schema_name   = $this->get_homepage_publisher()['name'] ?? null;
		$about         = $this->find_about_page_name_match( $business_name );
		// Real, deterministic homepage URL - where the homepage title, the site title `title` tag.
		$homepage_url = home_url( '/' );

		$sources = array(
			array(
				'key'   => 'homepage',
				'label' => __( 'Homepage', 'vulopilot' ),
				'value' => $homepage_name,
				'found' => null !== $homepage_name,
				'url'   => $homepage_url,
			),
			array(
				'key'   => 'website_title',
				'label' => __( 'Website title', 'vulopilot' ),
				'value' => '' !== $site_name ? $site_name : null,
				'found' => '' !== $site_name,
				'url'   => $homepage_url,
			),
			array(
				'key'   => 'organization_schema',
				'label' => __( 'Organization schema', 'vulopilot' ),
				'value' => $schema_name,
				'found' => null !== $schema_name,
				'url'   => $homepage_url,
			),
			array(
				'key'   => 'about_page',
				'label' => __( 'About page', 'vulopilot' ),
				'value' => $about['value'],
				'found' => null !== $about['value'],
				'url'   => $about['url'],
			),
		);

		$found_values  = array_filter(
			array_map(
				static fn( $source ) => $source['found'] ? strtolower( trim( (string) $source['value'] ) ) : null,
				$sources
			)
		);
		$unique_values = array_unique( $found_values );

		return array(
			'business_name'    => $business_name,
			'confidence'       => '' !== $business_name ? 'high' : 'n/a',
			'sources'          => $sources,
			'sources_checked'  => count( $sources ),
			// A single found source (or none) is trivially "consistent" -
			// there's nothing real to disagree with it yet.
			'is_consistent'    => count( $unique_values ) <= 1,
			'consistent_count' => count( $found_values ),
		);
	}

	/**
	 * The homepage's display name: the static front page's title, or the site title for a
	 * latest-posts homepage.
	 *
	 * @return string|null
	 */
	private function get_homepage_display_name(): ?string {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$front_page_id = (int) get_option( 'page_on_front' );

			if ( $front_page_id > 0 ) {
				$title = trim( (string) get_the_title( $front_page_id ) );

				if ( '' !== $title ) {
					return $title;
				}
			}
		}

		$site_name = trim( (string) get_bloginfo( 'name' ) );

		return '' !== $site_name ? $site_name : null;
	}

	/**
	 * Whether the real business name shows up in a real, published About page's own
	 * content.
	 *
	 * @param string $business_name The real, already-resolved business name to check for.
	 * @return array{value: string|null, url: string|null} `value` is the real business name when a published About page's content actually contains it, null otherwise (no About page, or one that doesn't mention it). `url` is that page's own real permalink whenever a published About page exists at all - even when its content doesn't mention the name, so "View" can still send someone to the real page to check for themselves.
	 */
	private function find_about_page_name_match( string $business_name ): array {
		foreach ( self::ABOUT_SLUGS as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );

			if ( ! $page || 'publish' !== $page->post_status ) {
				continue;
			}

			$url     = get_permalink( $page->ID ) ? get_permalink( $page->ID ) : null;
			$content = wp_strip_all_tags( (string) $page->post_content );
			$matched = '' !== trim( $business_name ) && false !== stripos( $content, $business_name );

			return array(
				'value' => $matched ? $business_name : null,
				'url'   => $url,
			);
		}

		return array(
			'value' => null,
			'url'   => null,
		);
	}

	/**
	 * Real, deterministic, template-built candidate relationships - never AI-generated (no
	 * AI call, no cost).
	 *
	 * @param string     $business_name Real organization name (extract_organizations()'s own first entry).
	 * @param array      $services      extract_services()'s own real rows.
	 * @param array|null $products   extract_products()'s own real rows (null when the store platform isn't active).
	 * @param array      $categories    extract_categories()'s own real rows.
	 * @return array<int, string>
	 */
	private function build_suggested_relationships( string $business_name, array $services, ?array $products, array $categories ): array {
		$relationships = array();

		foreach ( $services as $service ) {
			$relationships[] = sprintf(
				/* translators: 1: real business name, 2: real service or product name. */
				__( '%1$s offers %2$s', 'vulopilot' ),
				$business_name,
				$service['name']
			);
		}

		foreach ( (array) $products as $product ) {
			$relationships[] = sprintf(
				/* translators: 1: real business name, 2: real service or product name. */
				__( '%1$s offers %2$s', 'vulopilot' ),
				$business_name,
				$product['name']
			);
		}

		// Only real categories with real published content in them (`extract_categories()` already
		// filters to `hide_empty`) and only the taxonomy WordPress core itself calls "categories".
		foreach ( $categories as $category ) {
			if ( 'category' !== ( $category['meta']['taxonomy'] ?? '' ) ) {
				continue;
			}

			$relationships[] = sprintf(
				/* translators: 1: real business name, 2: real published category name. */
				__( '%1$s publishes content about %2$s', 'vulopilot' ),
				$business_name,
				$category['name']
			);
		}

		return array_slice( $relationships, 0, self::MAX_SUGGESTED_RELATIONSHIPS );
	}

	/**
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * @return bool
	 */
	private function is_module_active(): bool {
		return in_array( 'knowledge-graph', VuloPilot()->modules->get_active_modules(), true );
	}

	/**
	 * Real site "People" - every published post/page's author (as before) plus every real
	 * WordPress Administrator, even one who's never authored anything.
	 *
	 * @return array<int, array{id: string, type: string, name: string, url: string|null, source_object_type: string.
	 */
	private function extract_people(): array {
		global $wpdb;

		$author_ids = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ('post','page') LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'publish',
				self::AUTHOR_BATCH_SIZE
			)
		);

		$admin_ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);

		$user_ids = array_unique( array_map( 'intval', array_merge( (array) $author_ids, (array) $admin_ids ) ) );

		$people = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				continue;
			}

			$people[] = array(
				'id'                 => 'person:' . $user->ID,
				'type'               => 'person',
				'name'               => $user->display_name,
				'url'                => get_author_posts_url( $user->ID ),
				'source_object_type' => 'user',
				'source_object_ref'  => (string) $user->ID,
				'meta'               => array(
					'bio'        => get_the_author_meta( 'description', $user->ID ),
					'role'       => $user->roles[0] ?? '',
					'role_label' => $this->get_role_label( $user ),
				),
			);
		}

		return $people;
	}

	/**
	 * @param \WP_User $user Real WordPress user - may hold more than one role.
	 * @return string Real, translated role display name (e.g. "Administrator").
	 */
	private function get_role_label( \WP_User $user ): string {
		$role = $user->roles[0] ?? '';

		if ( '' === $role ) {
			return '';
		}

		$role_names = wp_roles()->role_names;

		return isset( $role_names[ $role ] ) ? translate_user_role( $role_names[ $role ] ) : $role;
	}

	/**
	 * @return array<int, array{id: string, type: string, name: string, url: string|null, source_object_type: string.
	 */
	private function extract_organizations(): array {
		$organizations = array();
		$site_name     = trim( (string) get_bloginfo( 'name' ) );
		$site_url      = home_url( '/' );
		$publisher     = $this->get_homepage_publisher();

		$organizations[] = array(
			'id'                 => 'organization:site',
			'type'               => 'organization',
			'name'               => $publisher['name'] ?? $site_name,
			'url'                => $publisher['url'] ?? $site_url,
			'source_object_type' => 'site',
			'source_object_ref'  => '0',
			'meta'               => array(
				'logo' => $publisher['logo'] ?? null,
			),
		);

		return $organizations;
	}

	/**
	 * @return array{name?: string, url?: string, logo?: string}|null
	 */
	private function get_homepage_publisher(): ?array {
		$raw = get_option( 'vulopilot_homepage_schema_json', '' );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['publisher'] ) || ! is_array( $decoded['publisher'] ) ) {
			return null;
		}

		return $decoded['publisher'];
	}

	/**
	 * @return array<int, array{id: string, type: string, name: string, url: string|null, source_object_type: string.
	 */
	private function extract_products(): ?array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return null;
		}

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => self::PRODUCT_BATCH_SIZE,
				'return' => 'objects',
			)
		);

		$entities = array();

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$entities[] = array(
				'id'                 => 'product:' . $product->get_id(),
				'type'               => 'product',
				'name'               => $product->get_name(),
				'url'                => get_permalink( $product->get_id() ),
				'source_object_type' => 'product',
				'source_object_ref'  => (string) $product->get_id(),
				'meta'               => array(
					'sku'          => $product->get_sku(),
					'price'        => $product->get_price(),
					'category_ids' => $product->get_category_ids(),
				),
			);
		}

		return $entities;
	}

	/**
	 * "Product Details" panel data - real published store-platform products, each with a
	 * real.
	 *
	 * @return array<int, array{id: int, name: string, url: string, edit_url: string|null, issues: array<int, string>.
	 */
	public function get_product_schema_details(): array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => self::PRODUCT_BATCH_SIZE,
				'return' => 'objects',
			)
		);

		$rows = array();

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$issues = array();

			if ( ! $product->get_image_id() ) {
				$issues[] = __( 'Missing product image', 'vulopilot' );
			}

			if ( '' === trim( (string) $product->get_sku() ) ) {
				$issues[] = __( 'Missing SKU', 'vulopilot' );
			}

			$has_description = '' !== trim( wp_strip_all_tags( (string) $product->get_short_description() ) )
				|| '' !== trim( wp_strip_all_tags( (string) $product->get_description() ) );

			if ( ! $has_description ) {
				$issues[] = __( 'Missing product description', 'vulopilot' );
			}

			if ( '' === (string) $product->get_price() ) {
				$issues[] = __( 'Missing price', 'vulopilot' );
			}

			$rows[] = array(
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'url'         => (string) get_permalink( $product->get_id() ),
				'edit_url'    => get_edit_post_link( $product->get_id(), 'raw' ) ? get_edit_post_link( $product->get_id(), 'raw' ) : null,
				'issues'      => $issues,
				'issue_count' => count( $issues ),
			);
		}

		return $rows;
	}

	/**
	 * Owner-curated, newline-separated page URLs/ids (Site Identity → Business
	 * Information's `entity_service_pages` setting).
	 *
	 * @return array<int, array{id: string, type: string, name: string, url: string|null, source_object_type: string.
	 */
	private function extract_services(): array {
		return $this->extract_pages_from_setting( 'entity_service_pages', 'service' );
	}

	/**
	 * @param string $setting_key Utill::VULOPILOT_SETTINGS_DEFAULTS key.
	 * @param string $entity_type 'service' or reused for other page-list entity types.
	 * @return array<int, array{id: string, type: string, name: string, url: string|null, source_object_type: string.
	 */
	private function extract_pages_from_setting( string $setting_key, string $entity_type ): array {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$lines    = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) ( $settings[ $setting_key ] ?? '' ) ) ) );

		$entities = array();

		foreach ( $lines as $line ) {
			$post_id = $this->resolve_post_id_from_setting_line( $line );

			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}

			$entities[] = array(
				'id'                 => $entity_type . ':' . $post_id,
				'type'               => $entity_type,
				'name'               => get_the_title( $post_id ),
				'url'                => get_permalink( $post_id ),
				'source_object_type' => 'post',
				'source_object_ref'  => (string) $post_id,
				'meta'               => array(),
			);
		}

		return $entities;
	}

	/**
	 * `url_to_postid()` alone (this method's only resolution path until this fix) silently
	 * fails for a real, live.
	 *
	 * @param string $line One raw line from the setting - either a numeric post ID or a URL.
	 * @return int 0 if nothing resolves.
	 */
	private function resolve_post_id_from_setting_line( string $line ): int {
		if ( is_numeric( $line ) ) {
			return absint( $line );
		}

		$post_id = url_to_postid( $line );

		if ( $post_id ) {
			return $post_id;
		}

		$path = trim( (string) wp_parse_url( $line, PHP_URL_PATH ), '/' );

		if ( '' === $path ) {
			return 0;
		}

		$page = get_page_by_path( $path, OBJECT, get_post_types( array( 'public' => true ) ) );

		return $page ? (int) $page->ID : 0;
	}

	/**
	 * Owner-curated, newline-separated `Name | Address` lines (Site Identity → Business
	 * Information's `entity_business_locations` setting).
	 *
	 * @return array<int, array{id: string, type: string, name: string, url: null, source_object_type: string.
	 */
	private function extract_locations(): array {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$lines    = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) ( $settings['entity_business_locations'] ?? '' ) ) ) );

		$entities = array();

		foreach ( $lines as $index => $line ) {
			$parts   = array_map( 'trim', explode( '|', $line, 2 ) );
			$name    = $parts[0] ?? '';
			$address = $parts[1] ?? '';

			if ( '' === $name ) {
				continue;
			}

			$entities[] = array(
				'id'                 => 'location:' . $index,
				'type'               => 'location',
				'name'               => $name,
				'url'                => null,
				'source_object_type' => 'setting',
				'source_object_ref'  => (string) $index,
				'meta'               => array(
					'address' => $address,
				),
			);
		}

		return $entities;
	}

	/**
	 * @return array<int, array{id: string, type: string, name: string, url: string|null, source_object_type: string.
	 */
	private function extract_categories(): array {
		$taxonomies = array( 'category' );

		if ( class_exists( 'WooCommerce' ) ) {
			$taxonomies[] = 'product_cat';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$entities = array();

		foreach ( $terms as $term ) {
			$term_link = get_term_link( $term );

			$entities[] = array(
				'id'                 => 'category:' . $term->term_id,
				'type'               => 'category',
				'name'               => $term->name,
				'url'                => is_wp_error( $term_link ) ? null : $term_link,
				'source_object_type' => 'term',
				'source_object_ref'  => (string) $term->term_id,
				'meta'               => array(
					'taxonomy' => $term->taxonomy,
					'count'    => $term->count,
				),
			);
		}

		return $entities;
	}
}
