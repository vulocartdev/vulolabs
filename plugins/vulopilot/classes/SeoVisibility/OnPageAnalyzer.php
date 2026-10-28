<?php
namespace VuloPilot\SeoVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless on-page SEO checklist for the post-editor metabox
 * (RestAPI\Controllers\PostSeo::analyze_item()).
 *
 * @class       OnPageAnalyzer class
 * @version     1.0.0
 * @author      VuloLabs
 */
class OnPageAnalyzer {

	/**
	 * Matches SeoScanner::TITLE_MIN_LENGTH/TITLE_MAX_LENGTH and
	 * WriteMetaTitleAction::MIN_LENGTH/MAX_LENGTH.
	 */
	private const TITLE_MIN_LENGTH = 10;
	private const TITLE_MAX_LENGTH = 60;

	/**
	 * Matches WriteMetaDescriptionAction::MAX_LENGTH.
	 */
	private const DESCRIPTION_MIN_LENGTH = 120;
	private const DESCRIPTION_MAX_LENGTH = 160;

	/**
	 * Words from the start of the content the focus keyword should appear within for the
	 * "keyword in first paragraph" check.
	 */
	private const FIRST_PARAGRAPH_WORDS = 100;

	/**
	 * Runs every check against the editor's current (possibly unsaved)
	 * field values.
	 *
	 * @param array<string, string> $fields Live editor state - 'title', 'content', 'excerpt', 'slug'.
	 * @return array<int, array<string, mixed>> Each entry shaped like result()'s own return value.
	 */
	public function analyze( array $fields ): array {
		$title        = trim( (string) ( $fields['title'] ?? '' ) );
		$content_html = (string) ( $fields['content'] ?? '' );
		$content_text = trim( wp_strip_all_tags( $content_html ) );
		$excerpt      = trim( (string) ( $fields['excerpt'] ?? '' ) );
		$slug         = (string) ( $fields['slug'] ?? '' );
		// `focus_keyword` may carry a comma-separated list; only the primary (first) one is scored.
		$focus_keyword = PostSeoMetaFields::primary_keyword( (string) ( $fields['focus_keyword'] ?? '' ) );

		$results = array();

		$results[] = $this->check_title_length( $title );
		$results[] = $this->check_description_length( $excerpt );
		$results[] = $this->check_content_length( $content_text );
		$results[] = $this->check_subheadings( $content_html );
		$results[] = $this->check_links( $content_html );
		$results[] = $this->check_internal_link( $content_html );
		$results[] = $this->check_external_link( $content_html );
		$results[] = $this->check_image_alt( $content_html );

		$density_result = $this->check_keyword_density( $focus_keyword, $content_text );

		if ( null !== $density_result ) {
			$results[] = $density_result;
		}

		// Always run, even with no focus keyword set - contains() already returns false for an
		// empty keyword, so each one legitimately (not just nominally) fails on its own, the same
		// way Rank Math's own equivalent tests each fail independently when no keyword is set,
		// rather than being skipped/exempted or collapsed into one substitute check. A missing
		// keyword is exactly what each of these 6 checks exists to catch - skipping them used to
		// make an unoptimized, keyword-less post score easier than one that tried and partially
		// failed.
		$results[] = $this->check_keyword_in_title( $focus_keyword, $title );
		$results[] = $this->check_keyword_in_description( $focus_keyword, $excerpt );
		$results[] = $this->check_keyword_in_slug( $focus_keyword, $slug );
		$results[] = $this->check_keyword_in_content( $focus_keyword, $content_text );
		$results[] = $this->check_keyword_in_first_paragraph( $focus_keyword, $content_text );
		$results[] = $this->check_keyword_at_title_start( $focus_keyword, $title );

		$results[] = $this->check_title_has_number( $title );

		return $results;
	}

	/**
	 * Checks the SEO title's length against SeoScanner/WriteMetaTitleAction's own thresholds.
	 *
	 * @param string $title Current post title.
	 * @return array<string, mixed>
	 */
	private function check_title_length( string $title ): array {
		$length = mb_strlen( $title );

		if ( 0 === $length ) {
			return $this->result( 'title_length', 'basic', 'fail', __( 'SEO title is empty.', 'vulopilot' ), 'write-meta-title' );
		}

		if ( $length < self::TITLE_MIN_LENGTH ) {
			return $this->result( 'title_length', 'basic', 'warning', __( 'SEO title is too short - search engines may show more than this.', 'vulopilot' ), 'write-meta-title' );
		}

		if ( $length > self::TITLE_MAX_LENGTH ) {
			return $this->result( 'title_length', 'basic', 'warning', __( 'SEO title may be truncated in search results.', 'vulopilot' ), 'write-meta-title' );
		}

		return $this->result( 'title_length', 'basic', 'pass', __( 'SEO title length is good.', 'vulopilot' ) );
	}

	/**
	 * Checks the meta description's length against the recommended window.
	 *
	 * @param string $excerpt Current post excerpt (used as the meta description).
	 * @return array<string, mixed>
	 */
	private function check_description_length( string $excerpt ): array {
		$length = mb_strlen( $excerpt );

		if ( 0 === $length ) {
			return $this->result( 'description_length', 'basic', 'fail', __( 'Meta description is empty.', 'vulopilot' ), 'write-meta-description' );
		}

		if ( $length < self::DESCRIPTION_MIN_LENGTH ) {
			return $this->result( 'description_length', 'basic', 'warning', __( 'Meta description is shorter than the recommended length.', 'vulopilot' ), 'write-meta-description' );
		}

		if ( $length > self::DESCRIPTION_MAX_LENGTH ) {
			return $this->result( 'description_length', 'basic', 'warning', __( 'Meta description may be truncated in search results.', 'vulopilot' ), 'write-meta-description' );
		}

		return $this->result( 'description_length', 'basic', 'pass', __( 'Meta description length is good.', 'vulopilot' ) );
	}

	/** Content-depth tiers, word count => fraction of full credit - Rank Math's own published tiers (rankmath.com/kb/seo-score-vs-content-ai-score/: */
	private const CONTENT_DEPTH_TIERS = array(
		2500 => 1.0,
		2000 => 0.7,
		1500 => 0.6,
		1000 => 0.4,
		600  => 0.2,
		0    => 0.0,
	);

	/**
	 * Scores the content's own depth against `CONTENT_DEPTH_TIERS` - not the flat `thin_content_word_threshold` pass/fail the Thin Content scanner uses elsewhere, which only ever gates a single low bar (300 words by default) and gave full score credit to anything past it, even content far short of what actually ranks (#bug:
	 *
	 * @param string $content_text Plain-text content.
	 * @return array<string, mixed>
	 */
	private function check_content_length( string $content_text ): array {
		$count = str_word_count( $content_text );

		$fraction = 0.0;
		$tier_min = 0;

		foreach ( self::CONTENT_DEPTH_TIERS as $min_words => $tier_fraction ) {
			if ( $count >= $min_words ) {
				$fraction = $tier_fraction;
				$tier_min = $min_words;
				break;
			}
		}

		if ( $fraction >= 1.0 ) {
			$status = 'pass';
		} elseif ( $fraction >= 0.4 ) {
			$status = 'warning';
		} else {
			$status = 'fail';
		}

		$message = $fraction >= 1.0
			? sprintf(
				/* translators: %d: current word count. */
				__( 'Content is %d words - good depth for search results.', 'vulopilot' ),
				$count
			)
			: sprintf(
				/* translators: 1: current word count, 2: percentage of full credit this depth earns, 3: word count the next tier up starts at. */
				__( 'Content is %1$d words - only %2$d%% of full content-depth credit. Aim for %3$d+ words.', 'vulopilot' ),
				$count,
				(int) round( $fraction * 100 ),
				$this->next_content_depth_tier( $tier_min )
			);

		$result                   = $this->result( 'content_length', 'basic', $status, $message, $fraction < 1.0 ? 'expand-content' : null );
		$result['score_fraction'] = $fraction;

		return $result;
	}

	/**
	 * The word count the next tier up from `$tier_min` starts at, for the "aim for X+ words"
	 * message - 2500 (the top tier) has no "next" tier, so that case is never reached by a caller
	 * already short of full credit.
	 *
	 * @param int $tier_min Current tier's own minimum word count.
	 * @return int
	 */
	private function next_content_depth_tier( int $tier_min ): int {
		$mins = array_keys( self::CONTENT_DEPTH_TIERS );
		rsort( $mins );
		$mins = array_reverse( $mins );
		$pos  = array_search( $tier_min, $mins, true );

		return $mins[ $pos + 1 ] ?? 2500;
	}

	/**
	 * Checks whether the focus keyword appears in the SEO title.
	 *
	 * @param string $keyword Focus keyword.
	 * @param string $title   Current post title.
	 * @return array<string, mixed>
	 */
	private function check_keyword_in_title( string $keyword, string $title ): array {
		// Deliberately not wired to 'write-meta-title'.
		return $this->contains( $keyword, $title )
			? $this->result( 'keyword_in_title', 'basic', 'pass', __( 'Focus keyword found in the SEO title.', 'vulopilot' ) )
			: $this->result( 'keyword_in_title', 'basic', 'fail', __( 'Focus keyword is missing from the SEO title.', 'vulopilot' ) );
	}

	/**
	 * Checks whether the focus keyword appears in the meta description.
	 *
	 * @param string $keyword Focus keyword.
	 * @param string $excerpt Current meta description.
	 * @return array<string, mixed>
	 */
	private function check_keyword_in_description( string $keyword, string $excerpt ): array {
		return $this->contains( $keyword, $excerpt )
			? $this->result( 'keyword_in_description', 'basic', 'pass', __( 'Focus keyword found in the meta description.', 'vulopilot' ) )
			: $this->result( 'keyword_in_description', 'basic', 'fail', __( 'Focus keyword is missing from the meta description.', 'vulopilot' ) );
	}

	/**
	 * Checks whether the focus keyword appears in the URL slug.
	 *
	 * @param string $keyword Focus keyword.
	 * @param string $slug    Post slug.
	 * @return array<string, mixed>
	 */
	private function check_keyword_in_slug( string $keyword, string $slug ): array {
		return $this->contains( $keyword, str_replace( '-', ' ', $slug ) )
			? $this->result( 'keyword_in_slug', 'basic', 'pass', __( 'Focus keyword found in the URL.', 'vulopilot' ) )
			: $this->result( 'keyword_in_slug', 'basic', 'warning', __( 'Focus keyword is missing from the URL.', 'vulopilot' ) );
	}

	/**
	 * Checks whether the focus keyword appears anywhere in the content.
	 *
	 * @param string $keyword      Focus keyword.
	 * @param string $content_text Plain-text content.
	 * @return array<string, mixed>
	 */
	private function check_keyword_in_content( string $keyword, string $content_text ): array {
		return $this->contains( $keyword, $content_text )
			? $this->result( 'keyword_in_content', 'basic', 'pass', __( 'Focus keyword found in the content.', 'vulopilot' ) )
			: $this->result( 'keyword_in_content', 'basic', 'fail', __( 'Focus keyword is missing from the content.', 'vulopilot' ) );
	}

	/**
	 * Checks whether the focus keyword appears within the opening words of the content.
	 *
	 * @param string $keyword      Focus keyword.
	 * @param string $content_text Plain-text content.
	 * @return array<string, mixed>
	 */
	private function check_keyword_in_first_paragraph( string $keyword, string $content_text ): array {
		$words = preg_split( '/\s+/', $content_text );
		$lead  = implode( ' ', array_slice( is_array( $words ) ? $words : array(), 0, self::FIRST_PARAGRAPH_WORDS ) );

		return $this->contains( $keyword, $lead )
			? $this->result( 'keyword_in_first_paragraph', 'additional', 'pass', __( 'Focus keyword appears early in the content.', 'vulopilot' ) )
			: $this->result( 'keyword_in_first_paragraph', 'additional', 'warning', __( 'Focus keyword does not appear in the opening paragraph.', 'vulopilot' ) );
	}

	/**
	 * Checks whether the content has any h2-h6 subheadings.
	 *
	 * @param string $content_html Raw post content (blocks/HTML).
	 * @return array<string, mixed>
	 */
	private function check_subheadings( string $content_html ): array {
		return (bool) preg_match( '/<h[2-6][\s>]/i', $content_html )
			? $this->result( 'has_subheadings', 'additional', 'pass', __( 'Content has subheadings.', 'vulopilot' ) )
			: $this->result( 'has_subheadings', 'additional', 'warning', __( 'Content has no subheadings - breaking it up improves readability and scannability.', 'vulopilot' ), 'add-subheadings' );
	}

	/**
	 * Checks whether the content contains at least one link - kept for the existing
	 * `has_links`/`suggest-internal-links` UI deep link; `check_internal_link()`/
	 * `check_external_link()` below score the real distinction Rank Math's own tests make
	 * (linking anywhere at all isn't equivalent to having both an internal and an external one).
	 *
	 * @param string $content_html Raw post content.
	 * @return array<string, mixed>
	 */
	private function check_links( string $content_html ): array {
		return (bool) preg_match( '/<a\s[^>]*href=/i', $content_html )
			? $this->result( 'has_links', 'additional', 'pass', __( 'Content contains at least one link.', 'vulopilot' ) )
			: $this->result( 'has_links', 'additional', 'warning', __( 'Content has no links - linking to related content or sources helps both readers and search engines.', 'vulopilot' ), 'suggest-internal-links' );
	}

	/**
	 * Every `href` found in the content, normalized to "internal" (same host as this site, or a
	 * root-relative path) or "external".
	 *
	 * @param string $content_html Raw post content.
	 * @return array{internal: int, external: int}
	 */
	private function count_links_by_scope( string $content_html ): array {
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content_html, $matches ) ) {
			return array(
				'internal' => 0,
				'external' => 0,
			);
		}

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$internal  = 0;
		$external  = 0;

		foreach ( $matches[1] as $href ) {
			if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}

			$host = (string) wp_parse_url( $href, PHP_URL_HOST );

			if ( '' === $host || $host === $home_host ) {
				++$internal;
			} else {
				++$external;
			}
		}

		return array(
			'internal' => $internal,
			'external' => $external,
		);
	}

	/**
	 * Rank Math's own "linking to internal resources" test - at least one link to this site
	 * itself, distinct from `has_links`, which a page with only external links already satisfies.
	 *
	 * @param string $content_html Raw post content.
	 * @return array<string, mixed>
	 */
	private function check_internal_link( string $content_html ): array {
		$counts = $this->count_links_by_scope( $content_html );

		return $counts['internal'] > 0
			? $this->result( 'has_internal_link', 'additional', 'pass', __( 'Content links to at least one other page on this site.', 'vulopilot' ) )
			: $this->result( 'has_internal_link', 'additional', 'warning', __( 'No internal links found - link to other relevant pages on this site.', 'vulopilot' ), 'suggest-internal-links' );
	}

	/**
	 * Rank Math's own "linking to external sources" test - at least one link to another site.
	 *
	 * @param string $content_html Raw post content.
	 * @return array<string, mixed>
	 */
	private function check_external_link( string $content_html ): array {
		$counts = $this->count_links_by_scope( $content_html );

		return $counts['external'] > 0
			? $this->result( 'has_external_link', 'additional', 'pass', __( 'Content links to at least one external source.', 'vulopilot' ) )
			: $this->result( 'has_external_link', 'additional', 'warning', __( 'No external links found - linking to an authoritative outside source builds trust.', 'vulopilot' ) );
	}

	/**
	 * Checks whether every image in the content has alt text.
	 *
	 * @param string $content_html Raw post content.
	 * @return array<string, mixed>
	 */
	private function check_image_alt( string $content_html ): array {
		if ( ! preg_match_all( '/<img\s[^>]*>/i', $content_html, $images ) ) {
			return $this->result( 'image_alt', 'additional', 'warning', __( 'No images in this content - add at least one relevant image with descriptive alt text.', 'vulopilot' ) );
		}

		foreach ( $images[0] as $image_tag ) {
			if ( ! preg_match( '/alt=["\'][^"\']+["\']/i', $image_tag ) ) {
				return $this->result( 'image_alt', 'additional', 'fail', __( 'One or more images are missing alt text.', 'vulopilot' ) );
			}
		}

		return $this->result( 'image_alt', 'additional', 'pass', __( 'All images have alt text.', 'vulopilot' ) );
	}

	/**
	 * Rank Math's own keyword-density test ("about 1-1.5% is sufficient... Warning above 2.5%" -
	 * rankmath.com/kb/seo-score-vs-content-ai-score/). Counted as whole-phrase occurrences of the
	 * (possibly multi-word) focus keyword against the total word count, same convention
	 * `contains()` already uses for every other keyword check in this class.
	 *
	 * @param string $keyword      Focus keyword.
	 * @param string $content_text Plain-text content.
	 * @return array<string, mixed>|null Null when there's no keyword to measure density for.
	 */
	private function check_keyword_density( string $keyword, string $content_text ): ?array {
		if ( '' === $keyword ) {
			return null;
		}

		$word_count = str_word_count( $content_text );

		if ( 0 === $word_count ) {
			return null;
		}

		$keyword_words   = max( 1, str_word_count( $keyword ) );
		$occurrences     = substr_count( mb_strtolower( $content_text ), mb_strtolower( $keyword ) );
		$density_percent = ( $occurrences * $keyword_words / $word_count ) * 100;

		if ( $density_percent > 2.5 ) {
			return $this->result(
				'keyword_density',
				'additional',
				'warning',
				sprintf(
					/* translators: %s: current keyword density, as a percentage. */
					__( 'Focus keyword density is %s%% - this reads as keyword stuffing. Aim for roughly 1-1.5%%.', 'vulopilot' ),
					round( $density_percent, 1 )
				)
			);
		}

		if ( $density_percent < 0.5 ) {
			return $this->result(
				'keyword_density',
				'additional',
				'warning',
				__( 'Focus keyword density is very low - use it a little more naturally throughout the content.', 'vulopilot' )
			);
		}

		return $this->result( 'keyword_density', 'additional', 'pass', __( 'Focus keyword density is good.', 'vulopilot' ) );
	}

	/**
	 * Checks whether the focus keyword appears near the start of the title.
	 *
	 * @param string $keyword Focus keyword.
	 * @param string $title   Current post title.
	 * @return array<string, mixed>
	 */
	private function check_keyword_at_title_start( string $keyword, string $title ): array {
		$half = mb_substr( $title, 0, (int) ceil( mb_strlen( $title ) / 2 ) );

		return $this->contains( $keyword, $half )
			? $this->result( 'keyword_at_title_start', 'title_readability', 'pass', __( 'Focus keyword appears near the beginning of the title.', 'vulopilot' ) )
			: $this->result( 'keyword_at_title_start', 'title_readability', 'warning', __( 'Focus keyword appears late in the title - titles rank slightly better with it near the start.', 'vulopilot' ) );
	}

	/**
	 * Titles with a number (a year, a count, "7 ways to…") measurably
	 * get more clicks.
	 *
	 * @param string $title Current post title.
	 * @return array<string, mixed>
	 */
	private function check_title_has_number( string $title ): array {
		return (bool) preg_match( '/\d/', $title )
			? $this->result( 'title_has_number', 'title_readability', 'pass', __( 'Title contains a number.', 'vulopilot' ) )
			: $this->result( 'title_has_number', 'title_readability', 'warning', __( 'Consider adding a number to the title (e.g. a year or a count) - these tend to attract more clicks.', 'vulopilot' ) );
	}

	/**
	 * Case-insensitive substring match, not word-boundary, since a focus
	 * keyword is often a multi-word phrase.
	 *
	 * @param string $keyword  Focus keyword.
	 * @param string $haystack Text to search within.
	 * @return bool
	 */
	private function contains( string $keyword, string $haystack ): bool {
		return '' !== $keyword && false !== stripos( $haystack, $keyword );
	}

	/**
	 * Builds one checklist entry in the shape every check above returns.
	 *
	 * @param string      $id        Stable check id the frontend keys UI state on.
	 * @param string      $group     'basic'|'additional'|'title_readability'.
	 * @param string      $status    'pass'|'warning'|'fail'.
	 * @param string      $message   Human-readable explanation.
	 * @param string|null $action_id AIActions id that can fix this, if any.
	 * @return array<string, mixed>
	 */
	private function result( string $id, string $group, string $status, string $message, ?string $action_id = null ): array {
		return array(
			'id'        => $id,
			'group'     => $group,
			'status'    => $status,
			'message'   => $message,
			'fixable'   => null !== $action_id,
			'action_id' => $action_id,
		);
	}
}
