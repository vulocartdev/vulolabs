<?php
namespace VuloPilot\SeoVisibility;

use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Site Identity → Title Formats' real backing.
 *
 * @class       TitleFormatter class
 * @version     1.0.0
 * @author      VuloLabs
 */
class TitleFormatter {

	/**
	 * `%variable%` token => resolver.
	 *
	 * @var array<string, callable>
	 */
	private const VARIABLES = array(
		'site_title'       => array( __CLASS__, 'var_site_title' ),
		'site_description' => array( __CLASS__, 'var_site_description' ),
		'post_title'       => array( __CLASS__, 'var_current_title' ),
		'page_title'       => array( __CLASS__, 'var_current_title' ),
		'category_title'   => array( __CLASS__, 'var_current_title' ),
		'tag_title'        => array( __CLASS__, 'var_current_title' ),
		'search_term'      => array( __CLASS__, 'var_search_term' ),
		'archive_title'    => array( __CLASS__, 'var_current_title' ),
		'author_name'      => array( __CLASS__, 'var_author_name' ),
	);

	/**
	 * TitleFormatter constructor.
	 */
	public function __construct() {
		add_filter( 'pre_get_document_title', array( $this, 'maybe_filter_title' ), 5 );
		add_action( 'wp_head', array( $this, 'maybe_output_description' ), 5 );
	}

	/**
	 * @param string $title Core's own already-resolved title (unused - this
	 *                       either replaces it wholesale or returns it untouched).
	 * @return string
	 */
	public function maybe_filter_title( string $title ): string {
		// A per-post "SEO Title" override (General tab) always wins, same as every other per-post
		// SEO field (canonical URL, robots, social title) - independent of whether the sitewide
		// Title Format templates below are even enabled.
		if ( is_singular() ) {
			$override = trim( (string) get_post_meta( get_queried_object_id(), PostSeoMetaFields::META_KEYS['seo_title'], true ) );

			if ( '' !== $override ) {
				return $override;
			}
		}

		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['site_identity_enabled'] ) || 'enabled' !== $settings['site_identity_enabled'] ) {
			return $title;
		}

		$context_key = $this->current_context_key();

		if ( ! $context_key ) {
			return $title; // 404s and any other context this feature doesn't cover - leave core's own title alone.
		}

		$template = $this->get_template( $settings, 'title_format_', $context_key );

		if ( '' === $template ) {
			return $title;
		}

		$resolved = $this->resolve( $template, $context_key, (string) ( $settings['title_separator'] ?? '|' ) );

		return '' !== $resolved ? $resolved : $title;
	}

	/**
	 * Outputs `meta` on `wp_head`, same setting-gated/context-scoped
	 * posture as `maybe_filter_title()` above.
	 *
	 * @return void
	 */
	public function maybe_output_description(): void {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['site_identity_enabled'] ) || 'enabled' !== $settings['site_identity_enabled'] ) {
			return;
		}

		$context_key = $this->current_context_key();

		if ( ! $context_key ) {
			return;
		}

		$content = '';

		if ( in_array( $context_key, array( 'post', 'page' ), true ) ) {
			$excerpt = trim( wp_strip_all_tags( get_the_excerpt() ) );

			if ( '' !== $excerpt ) {
				$content = $excerpt;
			}
		}

		if ( '' === $content ) {
			$template = $this->get_template( $settings, 'description_format_', $context_key );

			if ( '' !== $template ) {
				$content = $this->resolve( $template, $context_key, (string) ( $settings['title_separator'] ?? '|' ) );
			}
		}

		if ( '' === $content ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $content ) . '" />' . "\n";
	}

	/**
	 * Looks up a raw template for one context key - a flat `{$prefix}{$context_key}` settings field for the built-in contexts (home/post/page/category/tag/search/archive), or the matching `{$prefix}post_types`/`{$prefix}taxonomies` map entry for a dynamic `post_type:{slug}`/`taxonomy:{slug}` context key current_context_key() returns for anything else.
	 *
	 * @param array<string, mixed> $settings    Effective plugin settings.
	 * @param string               $prefix      `'title_format_'` or `'description_format_'`.
	 * @param string               $context_key current_context_key()'s own return value.
	 * @return string
	 */
	private function get_template( array $settings, string $prefix, string $context_key ): string {
		if ( 0 === strpos( $context_key, 'post_type:' ) ) {
			$post_type = substr( $context_key, strlen( 'post_type:' ) );
			$map       = (array) ( $settings[ "{$prefix}post_types" ] ?? array() );

			return trim( (string) ( $map[ $post_type ] ?? '' ) );
		}

		if ( 0 === strpos( $context_key, 'taxonomy:' ) ) {
			$taxonomy = substr( $context_key, strlen( 'taxonomy:' ) );
			$map      = (array) ( $settings[ "{$prefix}taxonomies" ] ?? array() );

			return trim( (string) ( $map[ $taxonomy ] ?? '' ) );
		}

		return trim( (string) ( $settings[ "{$prefix}{$context_key}" ] ?? '' ) );
	}

	/**
	 * Resolves a template string against one context's own variable set.
	 *
	 * @param string $template   Raw template, e.g. `%post_title% %sep% %site_title%`.
	 * @param string $context    One of `home`/`post`/`page`/`category`/`tag`/`search`/`archive`.
	 * @param string $separator  What `%sep%` itself resolves to.
	 * @return string
	 */
	public function resolve( string $template, string $context, string $separator ): string {
		$replacements = array( '%sep%' => $separator );

		foreach ( self::VARIABLES as $token => $resolver ) {
			$replacements[ "%{$token}%" ] = call_user_func( $resolver, $token, $context );
		}

		$resolved = strtr( $template, $replacements );

		// Collapse the empty-separator runs a template produces when one of its own tokens resolves
		// empty in this context (e.g. `%site_description%` when the site tagline is blank).
		$resolved = preg_replace( '/\s*' . preg_quote( $separator, '/' ) . '\s*$/', '', $resolved );
		$resolved = preg_replace( '/^\s*' . preg_quote( $separator, '/' ) . '\s*/', '', $resolved );

		return trim( (string) $resolved );
	}

	/**
	 * @return string|null One of home/post/page/category/tag/search/archive, or null for anything else (404, embeds, ...).
	 */
	private function current_context_key(): ?string {
		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_author() ) {
			return 'author';
		}

		if ( is_category() ) {
			return 'category';
		}

		if ( is_tag() ) {
			return 'tag';
		}

		// Any taxonomy term page that isn't category/tag - a dynamic 'taxonomy:{slug}' key, looked
		// up in the `title_format_taxonomies`/`description_format_taxonomies` maps (get_template()
		// below) rather than a flat settings field, since the taxonomy slug is only known at runtime.
		if ( is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				return 'taxonomy:' . $term->taxonomy;
			}
		}

		if ( is_page() ) {
			return 'page';
		}

		if ( is_singular() ) {
			$post_type = get_post_type();

			// Same dynamic-key story as the taxonomy branch above, for any post type other than
			// the two with their own flat settings fields.
			if ( $post_type && ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
				return 'post_type:' . $post_type;
			}

			return 'post';
		}

		if ( is_archive() ) {
			return 'archive';
		}

		return null;
	}

	/**
	 * @return string
	 */
	private static function var_site_title(): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * @return string
	 */
	private static function var_site_description(): string {
		return get_bloginfo( 'description' );
	}

	/**
	 * `%post_title%`/`%page_title%`/`%category_title%`/`%tag_title%`/ `%archive_title%`
	 * all resolve the same way.
	 *
	 * @return string
	 */
	private static function var_current_title(): string {
		if ( is_category() || is_tag() || is_tax() ) {
			return single_term_title( '', false ) ? single_term_title( '', false ) : '';
		}

		if ( is_archive() ) {
			return wp_strip_all_tags( get_the_archive_title() );
		}

		return get_the_title();
	}

	/**
	 * @return string
	 */
	private static function var_search_term(): string {
		return get_search_query();
	}

	/**
	 * @return string
	 */
	private static function var_author_name(): string {
		if ( ! is_author() ) {
			return '';
		}

		$author = get_queried_object();

		return $author instanceof \WP_User ? $author->display_name : '';
	}
}
