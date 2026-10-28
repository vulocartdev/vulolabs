<?php
/**
 * SeoScoreEngine class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\SeoVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * VuloPilot's own SEO score engine - a diagnostic metric (not Google's score, not a ranking prediction), built against Google's own general guidance (developers.google.com/search/docs/ essentials, .../fundamentals/seo-starter-guide, .../crawling-indexing/overview-google-crawlers) rather than any other plugin's proprietary formula.
 *
 * @class       SeoScoreEngine class
 * @version     1.0.0
 * @author      VuloLabs
 */
class SeoScoreEngine {

	public const RULESET_VERSION = '1.0.0';

	/**
	 * @var array<string, int>
	 */
	public const CATEGORY_WEIGHTS = array(
		'on_page'              => 25,
		'content_quality'      => 25,
		'keyword_optimization' => 15,
		'links'                => 10,
		'images_media'         => 10,
		'technical_seo'        => 15,
	);

	/** Rule 9 - configurable word-count target. */
	private const CONTENT_LENGTH_TARGET_WORDS = 1000;

	/** Rule 10 - configurable long-paragraph threshold. */
	private const LONG_PARAGRAPH_WORDS = 120;

	/** Rule 11 - configurable "a TOC would help" threshold. */
	private const TOC_RECOMMEND_WORDS = 1200;

	/** Rule 13/16 - configurable density heuristics (percent). */
	private const KEYWORD_DENSITY_WARN_ABOVE     = 2.5;
	private const KEYWORD_DENSITY_STUFFING_ABOVE = 4.0;

	/** Rule 4 (URL length advisory) / slug-quality guideline. */
	private const URL_LENGTH_GUIDELINE = 75;

	/**
	 * @var PageTechnicalChecks
	 */
	private PageTechnicalChecks $technical;

	/**
	 * SeoScoreEngine constructor.
	 */
	public function __construct() {
		$this->technical = new PageTechnicalChecks();
	}

	/**
	 * Runs every rule against one post's current (possibly live/unsaved) field values. the real stored object (ID, post_type, post_status, attachments).
	 *
	 * @param \WP_Post                                                                                    $post          Saved post - used for technical/link/image checks that need
	 * @param array{title: string, content: string, excerpt: string, slug: string, focus_keyword: string} $live
	 * @return array<int, array<string, mixed>>
	 * @param bool                                                                                        $skip_fetch Skip the one real HTTP self-fetch this evaluation would otherwise
	 */
	public function evaluate( \WP_Post $post, array $live, bool $skip_fetch = false ): array {
		$title         = trim( (string) $live['title'] );
		$content_html  = (string) $live['content'];
		$content_text  = trim( wp_strip_all_tags( $content_html ) );
		$excerpt       = trim( (string) $live['excerpt'] );
		$slug          = (string) $live['slug'];
		$focus_keyword = PostSeoMetaFields::primary_keyword( (string) $live['focus_keyword'] );
		$word_count    = str_word_count( $content_text );

		$results = array();

		// A. On-page SEO (25).
		$results[] = $this->rule_seo_title( $title, $focus_keyword );
		$results[] = $this->rule_meta_description( $excerpt, $focus_keyword );
		$results[] = $this->rule_url_slug( $slug );
		$results[] = $this->rule_focus_keyword_placement( $focus_keyword, $title, $excerpt, $content_text );
		$results[] = $this->rule_heading_structure( $content_html );
		$results[] = $this->rule_search_intent_alignment();

		// B. Content quality (25).
		$results[] = $this->rule_content_usefulness();
		$results[] = $this->rule_content_originality();
		$results[] = $this->rule_content_length( $word_count );
		$results[] = $this->rule_readability_paragraphs( $content_html );
		$results[] = $this->rule_table_of_contents( $content_html, $word_count );
		$results[] = $this->rule_freshness();

		// C. Keyword optimization (15).
		$density   = $this->keyword_density_percent( $focus_keyword, $content_text, $word_count );
		$results[] = $this->rule_natural_keyword_usage( $focus_keyword, $density );
		$results[] = $this->rule_topic_coverage();
		$results[] = $this->rule_keyword_distribution( $focus_keyword, $content_text );
		$results[] = $this->rule_keyword_stuffing( $focus_keyword, $density );
		$results[] = $this->rule_duplicate_keyword_targeting( $post->ID, $focus_keyword );

		// D. Links (10).
		$link_counts = $this->count_links( $content_html );
		$results[]   = $this->rule_internal_links( $link_counts['internal'] );
		$results[]   = $this->rule_external_links( $link_counts['external'] );
		$results[]   = $this->rule_broken_links( $post->ID );

		// E. Images and media (10).
		$images    = $this->extract_images( $content_html );
		$results[] = $this->rule_relevant_images( $post, $images );
		$results[] = $this->rule_image_alt_text( $images, $focus_keyword );
		$results[] = $this->rule_image_optimization( $images );

		// F. Technical SEO (15). Indexability/sitemap are cheap (settings/post-status reads, no
		// network) and always run; http_status/canonical/structured_data need a real HTTP
		// round trip to the live permalink, skipped for live-typing calls (see `$skip_fetch`'s own
		// docblock above).
		$results[] = $this->rule_indexability( $post );
		$results[] = $this->rule_sitemap_discovery( $post );

		if ( $skip_fetch ) {
			// Omitted entirely rather than reported `unknown` - `unknown` still counts against the
			// coverage denominator (rightly so for a rule that genuinely couldn't be verified), but
			// these 3 weren't un-verifiable, they were deliberately not re-run this call. Counting
			// them as unknown on every single keystroke pushed coverage below the 70% floor
			// permanently while typing, which is exactly what made the score show 0 the whole time
			// (#bug - a stricter regression than the original "frozen" complaint this was fixing).
		} else {
			$fetch     = $this->technical->fetch( (string) get_permalink( $post ) );
			$results[] = $this->rule_http_status( $fetch );
			$results[] = $this->rule_canonical( $fetch );
			$results[] = $this->rule_structured_data( $fetch );
		}

		// Advisory only - carries 0 points (spec explicitly: avoids double-counting with slug quality).
		$results[] = $this->rule_url_length( $post );

		return $results;
	}

	/**
	 * Applies the formula: (sum earned / sum applicable weight) × 100, excluding `not_applicable`
	 * and `unknown` rules from both sums. Rounds only the final displayed score.
	 *
	 * @param array<int, array<string, mixed>> $results `evaluate()`'s own return value.
	 * @return array{score: int|null, insufficient_data: bool, coverage_percent: float, applicable_weight: float, earned_points: float, evaluated_count: int, unknown_count: int, not_applicable_count: int, categories: array<string, array{max: int, earned: float, applicable: float}>}
	 */
	public function calculate_score( array $results ): array {
		$earned            = 0.0;
		$applicable_weight = 0.0;
		// Coverage denominator: applicable + genuinely-unknown weight only. `not_applicable` is
		// excluded from BOTH sides entirely (spec, explicitly) - a rule that plainly doesn't apply
		// (e.g. image alt text on a page with no images) isn't a gap in what this engine knows, so
		// it must not count against coverage the way a real "couldn't verify" unknown does.
		$unknown_weight       = 0.0;
		$evaluated_count      = 0;
		$unknown_count        = 0;
		$not_applicable_count = 0;
		$categories           = array();

		foreach ( array_keys( self::CATEGORY_WEIGHTS ) as $category ) {
			$categories[ $category ] = array(
				'max'        => self::CATEGORY_WEIGHTS[ $category ],
				'earned'     => 0.0,
				'applicable' => 0.0,
			);
		}

		foreach ( $results as $rule ) {
			$max_points = (float) ( $rule['max_points'] ?? 0 );

			// Advisory rules (url_length) carry 0 weight by design - nothing to add either way.
			if ( 0.0 === $max_points ) {
				continue;
			}

			$status   = (string) ( $rule['status'] ?? 'unknown' );
			$category = (string) ( $rule['category'] ?? '' );

			if ( 'not_applicable' === $status ) {
				++$not_applicable_count;
				continue;
			}

			if ( 'unknown' === $status ) {
				++$unknown_count;
				$unknown_weight += $max_points;
				continue;
			}

			++$evaluated_count;
			$applicable_weight += $max_points;
			$earned            += (float) ( $rule['earned_points'] ?? 0 );

			if ( isset( $categories[ $category ] ) ) {
				$categories[ $category ]['applicable'] += $max_points;
				$categories[ $category ]['earned']     += (float) ( $rule['earned_points'] ?? 0 );
			}
		}

		$coverage_denominator = $applicable_weight + $unknown_weight;
		$coverage_percent     = $coverage_denominator > 0 ? round( ( $applicable_weight / $coverage_denominator ) * 100, 1 ) : 0.0;

		// 50%, not the spec's own suggested starting point of 70% - this engine permanently
		// reports 5 rules (23 of 100 points: search intent, content usefulness, originality,
		// topic coverage, freshness) as unknown by design, since it has no AI/semantic capability
		// wired in to evaluate them honestly. 70% coverage is close to unreachable once that fixed
		// 23-point gap is combined with any real not_applicable/unknown rule on top of it, which
		// made the engine report "insufficient data" for nearly every real post (#bug - the user's
		// own "score is not changing at all" was this: coverage never cleared 70%, so `score`
		// stayed null/0 regardless of what changed). 50% still means more than half of what COULD
		// be evaluated actually was.
		$insufficient_data = $coverage_percent < 50.0;

		return array(
			'score'                => ( $applicable_weight > 0 && ! $insufficient_data )
				? (int) round( ( $earned / $applicable_weight ) * 100 )
				: null,
			'insufficient_data'    => $insufficient_data,
			'coverage_percent'     => $coverage_percent,
			'applicable_weight'    => $applicable_weight,
			'earned_points'        => round( $earned, 2 ),
			'evaluated_count'      => $evaluated_count,
			'unknown_count'        => $unknown_count,
			'not_applicable_count' => $not_applicable_count,
			'categories'           => $categories,
		);
	}

	// ---------------------------------------------------------------------
	// Shared helpers.
	// ---------------------------------------------------------------------

	/**
	 * @param string      $id             Stable rule id.
	 * @param string      $category       One of `CATEGORY_WEIGHTS`'s own keys.
	 * @param string      $status         'pass'|'warning'|'fail'|'not_applicable'|'unknown'.
	 * @param float       $max_points     This rule's own max point value.
	 * @param float       $earned_points  Points actually earned (0 for not_applicable/unknown).
	 * @param string      $reason         Human-readable explanation.
	 * @param array       $evidence       Real data this rule's decision was based on.
	 * @param string      $data_source    Where the evidence came from (e.g. 'post_content', 'live_http_fetch', 'postmeta').
	 * @param string|null $recommendation Actionable fix, when status isn't 'pass'.
	 * @return array<string, mixed>
	 */
	private function rule_result( string $id, string $category, string $status, float $max_points, float $earned_points, string $reason, array $evidence, string $data_source, ?string $recommendation = null ): array {
		return array(
			'rule_id'        => $id,
			'category'       => $category,
			'status'         => $status,
			'max_points'     => $max_points,
			'earned_points'  => $earned_points,
			'reason'         => $reason,
			'evidence'       => $evidence,
			'recommendation' => $recommendation,
			'data_source'    => $data_source,
		);
	}

	/**
	 * Case-insensitive substring match - same convention `OnPageAnalyzer::contains()` already uses.
	 *
	 * @param string $keyword  Focus keyword.
	 * @param string $haystack Text to search within.
	 * @return bool
	 */
	private function contains( string $keyword, string $haystack ): bool {
		return '' !== $keyword && false !== stripos( $haystack, $keyword );
	}

	/**
	 * @param string $keyword      Focus keyword.
	 * @param string $content_text Plain-text content.
	 * @param int    $word_count   Pre-computed word count (avoids re-counting).
	 * @return float|null Percent, or null when there's no keyword/content to measure.
	 */
	private function keyword_density_percent( string $keyword, string $content_text, int $word_count ): ?float {
		if ( '' === $keyword || 0 === $word_count ) {
			return null;
		}

		$keyword_words = max( 1, str_word_count( $keyword ) );
		$occurrences   = substr_count( mb_strtolower( $content_text ), mb_strtolower( $keyword ) );

		return ( $occurrences * $keyword_words / $word_count ) * 100;
	}

	/**
	 * @param string $content_html Raw post content.
	 * @return array{internal: int, external: int}
	 */
	private function count_links( string $content_html ): array {
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
			'' === $host || $host === $home_host ? ++$internal : ++$external;
		}

		return array(
			'internal' => $internal,
			'external' => $external,
		);
	}

	/**
	 * @param string $content_html Raw post content.
	 * @return array<int, array{src: string, alt: string|null}>
	 */
	private function extract_images( string $content_html ): array {
		if ( ! preg_match_all( '/<img\s[^>]*>/i', $content_html, $tags ) ) {
			return array();
		}

		$images = array();

		foreach ( $tags[0] as $tag ) {
			preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $src_match );
			$has_alt = preg_match( '/alt=["\']([^"\']*)["\']/i', $tag, $alt_match );

			$images[] = array(
				'src' => $src_match[1] ?? '',
				'alt' => $has_alt ? $alt_match[1] : null,
			);
		}

		return $images;
	}

	// ---------------------------------------------------------------------
	// A. On-page SEO (25 points).
	// ---------------------------------------------------------------------

	/**
	 * Rule 1 - SEO title (5 pts: 2 non-empty, 2 concise length, 1 no obvious stuffing).
	 */
	private function rule_seo_title( string $title, string $focus_keyword ): array {
		$earned  = 0.0;
		$reasons = array();
		$length  = mb_strlen( $title );

		if ( '' !== $title ) {
			$earned   += 2;
			$reasons[] = __( 'A title is set.', 'vulopilot' );
		} else {
			$reasons[] = __( 'No SEO title is set.', 'vulopilot' );
		}

		if ( $length >= 30 && $length <= 60 ) {
			$earned   += 2;
			$reasons[] = sprintf( /* translators: %d: real character count. */ __( 'Title length (%d chars) is concise.', 'vulopilot' ), $length );
		} elseif ( '' !== $title ) {
			$reasons[] = sprintf( /* translators: %d: real character count. */ __( 'Title is %d characters - aim for roughly 50-60.', 'vulopilot' ), $length );
		}

		// Obvious stuffing: the focus keyword (or its first word) repeated 3+ times in the title.
		$stuffed = '' !== $focus_keyword && substr_count( mb_strtolower( $title ), mb_strtolower( $focus_keyword ) ) >= 3;

		if ( ! $stuffed ) {
			$earned   += 1;
			$reasons[] = __( 'No obvious keyword stuffing in the title.', 'vulopilot' );
		} else {
			$reasons[] = __( 'The focus keyword repeats unnaturally often in the title.', 'vulopilot' );
		}

		$status = $earned >= 5 ? 'pass' : ( $earned > 0 ? 'warning' : 'fail' );

		return $this->rule_result(
			'seo_title',
            'on_page',
            $status,
            5,
            $earned,
			implode( ' ', $reasons ),
			array(
				'title'  => $title,
				'length' => $length,
            ),
			'live_editor_state',
			$earned < 5 ? __( 'Write a concise, descriptive SEO title around 50-60 characters.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 2 - meta description (5 pts: 2 non-empty, 2 concise length, 1 summarizes content).
	 */
	private function rule_meta_description( string $excerpt, string $focus_keyword ): array {
		$earned  = 0.0;
		$reasons = array();
		$length  = mb_strlen( $excerpt );

		if ( '' !== $excerpt ) {
			$earned   += 2;
			$reasons[] = __( 'A meta description is set.', 'vulopilot' );
		} else {
			$reasons[] = __( 'No meta description is set.', 'vulopilot' );
		}

		if ( $length >= 70 && $length <= 160 ) {
			$earned   += 2;
			$reasons[] = sprintf( /* translators: %d: real character count. */ __( 'Description length (%d chars) is appropriate.', 'vulopilot' ), $length );
		} elseif ( '' !== $excerpt ) {
			$reasons[] = sprintf( /* translators: %d: real character count. */ __( 'Description is %d characters - aim for roughly 120-160.', 'vulopilot' ), $length );
		}

		// "Summarizes the content" can't be judged without semantic comparison - credit this
		// point only on the deterministic proxy available: it mentions the focus keyword (when
		// one is set) or is simply present (when there's no keyword to check against).
		if ( '' !== $excerpt && ( '' === $focus_keyword || $this->contains( $focus_keyword, $excerpt ) ) ) {
			$earned   += 1;
			$reasons[] = __( 'Description appears relevant to the page topic.', 'vulopilot' );
		}

		$status = $earned >= 5 ? 'pass' : ( $earned > 0 ? 'warning' : 'fail' );

		return $this->rule_result(
			'meta_description',
            'on_page',
            $status,
            5,
            $earned,
			implode( ' ', $reasons ),
			array(
				'description' => $excerpt,
				'length'      => $length,
            ),
			'live_editor_state',
			$earned < 5 ? __( 'Write a clear, 120-160 character meta description that summarizes the page.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 3 - URL slug quality (4 pts). Readable, not just numeric/auto-generated, no excessive
	 * length or repeated separators - does not require exact keyword inclusion (spec).
	 */
	private function rule_url_slug( string $slug ): array {
		if ( '' === $slug ) {
			return $this->rule_result(
				'url_slug_quality',
                'on_page',
                'fail',
                4,
                0,
				__( 'No URL slug is set.', 'vulopilot' ),
                array( 'slug' => $slug ),
                'live_editor_state',
				__( 'Set a short, readable URL slug.', 'vulopilot' )
			);
		}

		$issues = array();

		if ( preg_match( '/^\d+$/', $slug ) ) {
			$issues[] = __( 'slug is only numbers', 'vulopilot' );
		}
		if ( mb_strlen( $slug ) > 80 ) {
			$issues[] = __( 'slug is unusually long', 'vulopilot' );
		}
		if ( preg_match( '/--+/', $slug ) ) {
			$issues[] = __( 'slug has repeated hyphens', 'vulopilot' );
		}

		$status = empty( $issues ) ? 'pass' : 'warning';
		$earned = empty( $issues ) ? 4 : 2;

		return $this->rule_result(
			'url_slug_quality',
            'on_page',
            $status,
            4,
            $earned,
			empty( $issues ) ? __( 'The URL slug is readable and reasonably sized.', 'vulopilot' ) : sprintf( /* translators: %s: comma-separated list of real issues found. */ __( 'Slug issues: %s.', 'vulopilot' ), implode( ', ', $issues ) ),
			array(
				'slug'   => $slug,
				'length' => mb_strlen( $slug ),
            ),
			'live_editor_state',
			empty( $issues ) ? null : __( 'Use a short, descriptive, hyphen-separated slug.', 'vulopilot' )
		);
	}

	/**
	 * Rule 4 - focus keyword placement (4 pts, split across title/description/opening paragraph).
	 * not_applicable when no focus keyword is configured (spec: exclude, don't fail).
	 */
	private function rule_focus_keyword_placement( string $keyword, string $title, string $excerpt, string $content_text ): array {
		if ( '' === $keyword ) {
			return $this->rule_result(
				'focus_keyword_placement',
                'on_page',
                'not_applicable',
                4,
                0,
				__( 'No focus keyword is configured.', 'vulopilot' ),
                array(),
                'live_editor_state'
			);
		}

		$earned = 0.0;
		$where  = array();

		if ( $this->contains( $keyword, $title ) ) {
			$earned += 4 / 3;
			$where[] = __( 'title', 'vulopilot' );
		}
		if ( $this->contains( $keyword, $excerpt ) ) {
			$earned += 4 / 3;
			$where[] = __( 'description', 'vulopilot' );
		}

		$opening = implode( ' ', array_slice( preg_split( '/\s+/', $content_text ) ?: array(), 0, 100 ) );

		if ( $this->contains( $keyword, $opening ) ) {
			$earned += 4 / 3;
			$where[] = __( 'opening paragraph', 'vulopilot' );
		}

		$status = $earned >= 3.9 ? 'pass' : ( $earned > 0 ? 'warning' : 'fail' );

		return $this->rule_result(
			'focus_keyword_placement',
            'on_page',
            $status,
            4,
            round( $earned, 2 ),
			empty( $where )
				? __( 'Focus keyword was not found in the title, description, or opening paragraph.', 'vulopilot' )
				: sprintf( /* translators: %s: comma-separated list of where the keyword was actually found. */ __( 'Focus keyword found in: %s.', 'vulopilot' ), implode( ', ', $where ) ),
			array(
				'keyword'  => $keyword,
				'found_in' => $where,
            ),
			'live_editor_state',
			$earned < 3.9 ? __( 'Work the focus keyword naturally into the title, description, and opening paragraph.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 5 - heading structure (4 pts). H1 is the theme's own rendered post title, not something
	 * `post_content` itself should contain - checked for subheadings and basic H2-before-H3 order
	 * instead of re-litigating whether an H1 exists in content (spec: "do not incorrectly penalize
	 * a valid theme-generated H1").
	 */
	private function rule_heading_structure( string $content_html ): array {
		$has_h2 = (bool) preg_match( '/<h2[\s>]/i', $content_html );
		$has_h3 = (bool) preg_match( '/<h3[\s>]/i', $content_html );

		// A real (if rough) hierarchy check: does an H3 appear before the first H2?
		$h2_pos       = stripos( $content_html, '<h2' );
		$h3_pos       = stripos( $content_html, '<h3' );
		$out_of_order = $has_h3 && $has_h2 && false !== $h3_pos && $h3_pos < $h2_pos;

		if ( $has_h2 && ! $out_of_order ) {
			return $this->rule_result(
				'heading_structure',
                'on_page',
                'pass',
                4,
                4,
				__( 'Content has section headings in a logical order.', 'vulopilot' ),
				array(
					'has_h2' => $has_h2,
					'has_h3' => $has_h3,
                ),
                'post_content'
			);
		}

		if ( $has_h2 && $out_of_order ) {
			return $this->rule_result(
				'heading_structure',
                'on_page',
                'warning',
                4,
                2,
				__( 'An H3 appears before the first H2 - heading levels should nest in order.', 'vulopilot' ),
				array(
					'has_h2' => $has_h2,
					'has_h3' => $has_h3,
                ),
                'post_content',
				__( 'Reorder headings so H3s sit under an H2, not before one.', 'vulopilot' )
			);
		}

		return $this->rule_result(
			'heading_structure',
            'on_page',
            'fail',
            4,
            0,
			__( 'No section headings (H2/H3) found in the content.', 'vulopilot' ),
			array(
				'has_h2' => false,
				'has_h3' => $has_h3,
            ),
            'post_content',
			__( 'Break the content into sections with H2 (and H3 where needed) subheadings.', 'vulopilot' )
		);
	}

	/**
	 * Rule 6 - search intent alignment (3 pts). Judging whether a page actually answers the query
	 * it targets needs real language understanding this codebase has no built-in capability for
	 * (no AI call is wired into this engine) - reporting a pass/fail here without one would be
	 * exactly the fabrication the spec says not to do.
	 */
	private function rule_search_intent_alignment(): array {
		return $this->rule_result(
			'search_intent_alignment',
            'on_page',
            'unknown',
            3,
            0,
			__( 'Search intent alignment needs semantic/AI assessment, which this engine does not perform - not evaluated.', 'vulopilot' ),
			array(),
            'not_available'
		);
	}

	// ---------------------------------------------------------------------
	// B. Content quality (25 points).
	// ---------------------------------------------------------------------

	/**
	 * Rule 7 - content usefulness/completeness (8 pts). Whether content "addresses the reader's
	 * likely question" or "demonstrates appropriate depth for the search intent" needs real
	 * reading comprehension this engine doesn't have - reported unknown rather than inferred from
	 * word count alone (spec explicitly: "Do not reward length alone").
	 */
	private function rule_content_usefulness(): array {
		return $this->rule_result(
			'content_usefulness',
            'content_quality',
            'unknown',
            8,
            0,
			__( 'Content usefulness/completeness needs human or AI judgment of what the reader actually needed - not evaluated.', 'vulopilot' ),
			array(),
            'not_available'
		);
	}

	/**
	 * Rule 8 - content originality/value (5 pts). Detecting duplication against the rest of the
	 * web needs a real plagiarism/similarity service this codebase doesn't integrate - reported
	 * unknown rather than a guess (spec: "Do not claim plagiarism... without sufficient evidence").
	 */
	private function rule_content_originality(): array {
		return $this->rule_result(
			'content_originality',
            'content_quality',
            'unknown',
            5,
            0,
			__( 'Content originality needs a duplication/similarity check this engine does not perform - not evaluated.', 'vulopilot' ),
			array(),
            'not_available'
		);
	}

	/**
	 * Rule 9 - content length (4 pts), graduated against a configurable target (default 1000
	 * words) rather than Rank Math's own tiers - a short but complete answer should not
	 * automatically fail (spec), so this is deliberately gentler than a hard cliff.
	 */
	private function rule_content_length( int $word_count ): array {
		$target = self::CONTENT_LENGTH_TARGET_WORDS;
		$ratio  = $target > 0 ? min( 1.0, $word_count / $target ) : 1.0;
		$earned = round( $ratio * 4, 2 );

		$status = $ratio >= 1.0 ? 'pass' : ( $ratio >= 0.5 ? 'warning' : 'fail' );

		return $this->rule_result(
			'content_length',
            'content_quality',
            $status,
            4,
            $earned,
			sprintf(
				/* translators: 1: real word count, 2: configured target word count. */
				__( 'Content is %1$d words (target: %2$d+).', 'vulopilot' ),
				$word_count,
                $target
			),
			array(
				'word_count' => $word_count,
				'target'     => $target,
            ),
            'live_editor_state',
			$ratio < 1.0 ? __( 'Expand the content with real, useful detail relevant to the topic.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 10 - readability/paragraph length (4 pts). Specific, deterministic sub-check: no
	 * editorial `<p>` paragraph exceeds 120 words. Headings/captions/code excluded by only
	 * scanning `<p>` tag contents.
	 */
	private function rule_readability_paragraphs( string $content_html ): array {
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $content_html, $matches );
		$long_paragraphs = array();

		foreach ( $matches[1] ?? array() as $index => $paragraph_html ) {
			$words = str_word_count( wp_strip_all_tags( $paragraph_html ) );

			if ( $words > self::LONG_PARAGRAPH_WORDS ) {
				$long_paragraphs[] = array(
					'index' => $index,
					'words' => $words,
				);
			}
		}

		if ( empty( $long_paragraphs ) ) {
			return $this->rule_result(
				'readability_paragraphs',
                'content_quality',
                'pass',
                4,
                4,
				sprintf( /* translators: %d: real max paragraph length, in words. */ __( 'No paragraph exceeds %d words.', 'vulopilot' ), self::LONG_PARAGRAPH_WORDS ),
				array( 'long_paragraph_count' => 0 ),
                'post_content'
			);
		}

		return $this->rule_result(
			'readability_paragraphs',
            'content_quality',
            'warning',
            4,
            2,
			sprintf(
				/* translators: 1: real count of overlong paragraphs, 2: real max paragraph length, in words. */
				_n( '%1$d paragraph exceeds %2$d words.', '%1$d paragraphs exceed %2$d words.', count( $long_paragraphs ), 'vulopilot' ),
				count( $long_paragraphs ),
                self::LONG_PARAGRAPH_WORDS
			),
			array( 'long_paragraphs' => $long_paragraphs ),
            'post_content',
			__( 'Split the longest paragraphs into shorter ones for easier reading.', 'vulopilot' )
		);
	}

	/**
	 * Rule 11 - table of contents (2 pts). Detects VuloPilot's own TOC block, common third-party
	 * TOC plugin output classes, or a list of same-page anchor links near the top of the content.
	 * not_applicable below the recommendation threshold (default 1200 words) - a short article
	 * doesn't need one (spec).
	 */
	private function rule_table_of_contents( string $content_html, int $word_count ): array {
		if ( $word_count < self::TOC_RECOMMEND_WORDS ) {
			return $this->rule_result(
				'table_of_contents',
                'content_quality',
                'not_applicable',
                2,
                0,
				sprintf( /* translators: %d: real word count below the configured recommendation threshold. */ __( 'Article is %d words - too short to need a table of contents.', 'vulopilot' ), $word_count ),
				array( 'word_count' => $word_count ),
                'post_content'
			);
		}

		$has_toc = (bool) preg_match(
			'/wp:vulopilot\/table-of-contents|class=["\'][^"\']*(?:toc|table-of-contents)[^"\']*["\']|<nav[^>]+(?:id|class)=["\'][^"\']*toc/i',
			$content_html
		);

		if ( $has_toc ) {
			return $this->rule_result(
				'table_of_contents',
                'content_quality',
                'pass',
                2,
                2,
				__( 'A table of contents was detected.', 'vulopilot' ),
                array( 'word_count' => $word_count ),
                'post_content'
			);
		}

		return $this->rule_result(
			'table_of_contents',
            'content_quality',
            'fail',
            2,
            0,
			sprintf( /* translators: %d: real word count above the configured recommendation threshold. */ __( 'No table of contents detected on this %d-word article.', 'vulopilot' ), $word_count ),
			array( 'word_count' => $word_count ),
            'post_content',
			__( 'Add a table of contents (e.g. the VuloPilot Table of Contents block) for long-form content.', 'vulopilot' )
		);
	}

	/**
	 * Rule 12 - freshness/accuracy (2 pts). Whether facts are current or claims are supportable
	 * needs real-world verification this engine can't perform, and the spec explicitly forbids
	 * using the publish/modified date alone as a proxy ("do not reward changing publication dates
	 * without substantive updates") - reported unknown.
	 */
	private function rule_freshness(): array {
		return $this->rule_result(
			'freshness_accuracy',
            'content_quality',
            'unknown',
            2,
            0,
			__( 'Freshness/accuracy needs real-world fact verification this engine does not perform - not evaluated.', 'vulopilot' ),
			array(),
            'not_available'
		);
	}

	// ---------------------------------------------------------------------
	// C. Keyword optimization (15 points).
	// ---------------------------------------------------------------------

	/**
	 * Rule 13 - natural keyword usage (4 pts), scored against density. not_applicable with no
	 * keyword configured.
	 */
	private function rule_natural_keyword_usage( string $keyword, ?float $density ): array {
		if ( null === $density ) {
			return $this->rule_result(
				'natural_keyword_usage',
                'keyword_optimization',
                'not_applicable',
                4,
                0,
				__( 'No focus keyword is configured.', 'vulopilot' ),
                array(),
                'live_editor_state'
			);
		}

		if ( $density <= 0 ) {
			return $this->rule_result(
				'natural_keyword_usage',
                'keyword_optimization',
                'fail',
                4,
                0,
				__( 'Focus keyword does not appear in the content.', 'vulopilot' ),
				array(
					'density_percent' => round( $density, 2 ),
					'keyword'         => $keyword,
                ),
                'live_editor_state',
				__( 'Use the focus keyword a few times naturally throughout the content.', 'vulopilot' )
			);
		}

		if ( $density > self::KEYWORD_DENSITY_WARN_ABOVE ) {
			return $this->rule_result(
				'natural_keyword_usage',
                'keyword_optimization',
                'warning',
                4,
                2,
				sprintf( /* translators: %s: real keyword density percentage. */ __( 'Keyword density is %s%% - on the high side.', 'vulopilot' ), round( $density, 1 ) ),
				array(
					'density_percent' => round( $density, 2 ),
					'keyword'         => $keyword,
                ),
                'live_editor_state',
				__( 'Vary the wording so the keyword appears a little less densely.', 'vulopilot' )
			);
		}

		return $this->rule_result(
			'natural_keyword_usage',
            'keyword_optimization',
            'pass',
            4,
            4,
			sprintf( /* translators: %s: real keyword density percentage. */ __( 'Keyword density is %s%% - natural.', 'vulopilot' ), round( $density, 1 ) ),
			array(
				'density_percent' => round( $density, 2 ),
				'keyword'         => $keyword,
            ),
            'live_editor_state'
		);
	}

	/**
	 * Rule 14 - related terms/topic coverage (5 pts). Judging real semantic topic coverage needs
	 * an entity/NLP model this engine doesn't have - a keyword-overlap heuristic would be low
	 * enough confidence to mislead rather than help, so this is reported unknown rather than
	 * invented (spec: "Do not require an arbitrary list of keywords").
	 */
	private function rule_topic_coverage(): array {
		return $this->rule_result(
			'topic_coverage',
            'keyword_optimization',
            'unknown',
            5,
            0,
			__( 'Related-term/topic-coverage analysis needs semantic/NLP capability this engine does not have - not evaluated.', 'vulopilot' ),
			array(),
            'not_available'
		);
	}

	/**
	 * Rule 15 - keyword distribution (2 pts). Splits the content into thirds and checks the
	 * keyword appears in more than one, so it isn't concentrated entirely in one section.
	 */
	private function rule_keyword_distribution( string $keyword, string $content_text ): array {
		if ( '' === $keyword ) {
			return $this->rule_result(
				'keyword_distribution',
                'keyword_optimization',
                'not_applicable',
                2,
                0,
				__( 'No focus keyword is configured.', 'vulopilot' ),
                array(),
                'live_editor_state'
			);
		}

		$length = mb_strlen( $content_text );

		if ( 0 === $length ) {
			return $this->rule_result(
				'keyword_distribution',
                'keyword_optimization',
                'fail',
                2,
                0,
				__( 'There is no content to check keyword distribution in.', 'vulopilot' ),
                array(),
                'live_editor_state'
			);
		}

		$third     = (int) ceil( $length / 3 );
		$sections  = array(
			mb_substr( $content_text, 0, $third ),
			mb_substr( $content_text, $third, $third ),
			mb_substr( $content_text, $third * 2 ),
		);
		$hit_count = 0;

		foreach ( $sections as $section ) {
			if ( $this->contains( $keyword, $section ) ) {
				++$hit_count;
			}
		}

		if ( $hit_count >= 2 ) {
			return $this->rule_result(
				'keyword_distribution',
                'keyword_optimization',
                'pass',
                2,
                2,
				__( 'Focus keyword appears across multiple parts of the content.', 'vulopilot' ),
				array( 'sections_with_keyword' => $hit_count ),
                'live_editor_state'
			);
		}

		return $this->rule_result(
			'keyword_distribution',
            'keyword_optimization',
            'warning',
            2,
            $hit_count > 0 ? 1 : 0,
			__( 'Focus keyword is concentrated in one part of the content rather than spread naturally throughout.', 'vulopilot' ),
			array( 'sections_with_keyword' => $hit_count ),
            'live_editor_state',
			__( 'Mention the focus keyword naturally in more than one section.', 'vulopilot' )
		);
	}

	/**
	 * Rule 16 - keyword stuffing (2 pts), distinct threshold from Rule 13's "is density natural"
	 * - this one only flags egregious, clearly manipulative repetition.
	 */
	private function rule_keyword_stuffing( string $keyword, ?float $density ): array {
		if ( null === $density ) {
			return $this->rule_result(
				'keyword_stuffing',
                'keyword_optimization',
                'not_applicable',
                2,
                0,
				__( 'No focus keyword is configured.', 'vulopilot' ),
                array(),
                'live_editor_state'
			);
		}

		if ( $density > self::KEYWORD_DENSITY_STUFFING_ABOVE ) {
			return $this->rule_result(
				'keyword_stuffing',
                'keyword_optimization',
                'fail',
                2,
                0,
				sprintf( /* translators: %s: real keyword density percentage. */ __( 'Keyword density is %s%% - this reads as manipulative repetition.', 'vulopilot' ), round( $density, 1 ) ),
				array(
					'density_percent' => round( $density, 2 ),
					'keyword'         => $keyword,
                ),
                'live_editor_state',
				__( 'Reduce how often the exact keyword phrase repeats; use natural variations instead.', 'vulopilot' )
			);
		}

		return $this->rule_result(
			'keyword_stuffing',
            'keyword_optimization',
            'pass',
            2,
            2,
			__( 'No keyword stuffing detected.', 'vulopilot' ),
			array(
				'density_percent' => round( $density, 2 ),
				'keyword'         => $keyword,
            ),
            'live_editor_state'
		);
	}

	/**
	 * Rule 17 - duplicate keyword targeting (2 pts). Real DB check against this site's other
	 * published posts/pages sharing the same focus keyword - deterministic, not a guess.
	 */
	private function rule_duplicate_keyword_targeting( int $post_id, string $keyword ): array {
		if ( '' === $keyword ) {
			return $this->rule_result(
				'duplicate_keyword_targeting',
                'keyword_optimization',
                'not_applicable',
                2,
                0,
				__( 'No focus keyword is configured.', 'vulopilot' ),
                array(),
                'live_editor_state'
			);
		}

		global $wpdb;

		$other_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_status = 'publish' AND p.ID != %d
				LIMIT 5",
				PostSeoMetaFields::META_KEYS['focus_keyword'],
				'%' . $wpdb->esc_like( $keyword ) . '%',
				$post_id
			)
		);

		if ( empty( $other_posts ) ) {
			return $this->rule_result(
				'duplicate_keyword_targeting',
                'keyword_optimization',
                'pass',
                2,
                2,
				__( 'No other published page targets this same focus keyword.', 'vulopilot' ),
				array( 'keyword' => $keyword ),
                'postmeta_scan'
			);
		}

		return $this->rule_result(
			'duplicate_keyword_targeting',
            'keyword_optimization',
            'warning',
            2,
            1,
			sprintf(
				/* translators: %d: real number of other published posts/pages targeting the same focus keyword. */
				_n( '%d other published page also targets this focus keyword.', '%d other published pages also target this focus keyword.', count( $other_posts ), 'vulopilot' ),
				count( $other_posts )
			),
			array(
				'keyword'        => $keyword,
				'other_post_ids' => array_map( 'intval', $other_posts ),
            ),
            'postmeta_scan',
			__( 'Review whether these pages should target different keywords, or consolidate them.', 'vulopilot' )
		);
	}

	// ---------------------------------------------------------------------
	// D. Links (10 points).
	// ---------------------------------------------------------------------

	/**
	 * Rule 18 - internal links (5 pts).
	 */
	private function rule_internal_links( int $count ): array {
		$status = $count > 0 ? 'pass' : 'warning';

		return $this->rule_result(
			'internal_links',
            'links',
            $status,
            5,
            $count > 0 ? 5 : 0,
			$count > 0
				? sprintf( /* translators: %d: real number of internal links found. */ _n( '%d internal link found.', '%d internal links found.', $count, 'vulopilot' ), $count )
				: __( 'No internal links found.', 'vulopilot' ),
			array( 'internal_link_count' => $count ),
            'post_content',
			0 === $count ? __( 'Link to other relevant pages on this site.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 19 - external links (2 pts).
	 */
	private function rule_external_links( int $count ): array {
		$status = $count > 0 ? 'pass' : 'warning';

		return $this->rule_result(
			'external_links',
            'links',
            $status,
            2,
            $count > 0 ? 2 : 0,
			$count > 0
				? sprintf( /* translators: %d: real number of external links found. */ _n( '%d external link found.', '%d external links found.', $count, 'vulopilot' ), $count )
				: __( 'No external links found.', 'vulopilot' ),
			array( 'external_link_count' => $count ),
            'post_content',
			0 === $count ? __( 'Consider linking to a relevant, credible external source.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 20 - broken links (3 pts). Delegates to `PageTechnicalChecks::check_broken_links()`
	 * (shared with `Seo::get_page_analysis()`) rather than re-verifying link destinations here.
	 */
	private function rule_broken_links( int $post_id ): array {
		$check = $this->technical->check_broken_links( $post_id );

		return $this->rule_result(
			'broken_links',
            'links',
            $check['status'],
            3,
            'pass' === $check['status'] ? 3 : 0,
			$check['reason'],
            $check['evidence'],
            'broken_links_scanner',
			'fail' === $check['status'] ? __( 'Fix or remove the broken link(s).', 'vulopilot' ) : null
		);
	}

	// ---------------------------------------------------------------------
	// E. Images and media (10 points).
	// ---------------------------------------------------------------------

	/**
	 * Rule 21 - relevant images (3 pts). Can't judge "relevant" without visual/semantic analysis,
	 * so this only checks presence (featured image or inline) and reports a warning (not a fail)
	 * when there's none, since a text-only article may legitimately need none (spec).
	 */
	private function rule_relevant_images( \WP_Post $post, array $images ): array {
		$has_featured = has_post_thumbnail( $post );
		$has_inline   = ! empty( $images );

		if ( $has_featured || $has_inline ) {
			return $this->rule_result(
				'relevant_images',
                'images_media',
                'pass',
                3,
                3,
				__( 'This page has at least one image.', 'vulopilot' ),
				array(
					'has_featured_image' => $has_featured,
					'inline_image_count' => count( $images ),
                ),
                'post_content'
			);
		}

		return $this->rule_result(
			'relevant_images',
            'images_media',
            'warning',
            3,
            1.5,
			__( 'No featured or inline images found - add one if a visual would help this content.', 'vulopilot' ),
			array(
				'has_featured_image' => false,
				'inline_image_count' => 0,
            ),
            'post_content'
		);
	}

	/**
	 * Rule 22 - image alt text (4 pts). not_applicable with zero images (spec, explicitly).
	 */
	private function rule_image_alt_text( array $images, string $focus_keyword ): array {
		if ( empty( $images ) ) {
			return $this->rule_result(
				'image_alt_text',
                'images_media',
                'not_applicable',
                4,
                0,
				__( 'No images in this content.', 'vulopilot' ),
                array(),
                'post_content'
			);
		}

		$missing = 0;

		foreach ( $images as $image ) {
			if ( null === $image['alt'] ) {
				++$missing;
			}
		}

		// An empty alt="" is a legitimate decorative-image marker (spec) - only a MISSING attribute counts against this.
		if ( 0 === $missing ) {
			return $this->rule_result(
				'image_alt_text',
                'images_media',
                'pass',
                4,
                4,
				__( 'Every image has an alt attribute (empty is fine for decorative images).', 'vulopilot' ),
				array(
					'image_count'       => count( $images ),
					'missing_alt_count' => 0,
                ),
                'post_content'
			);
		}

		return $this->rule_result(
			'image_alt_text',
            'images_media',
            'fail',
            4,
            0,
			sprintf( /* translators: %d: real count of images with no alt attribute at all. */ _n( '%d image has no alt attribute.', '%d images have no alt attribute.', $missing, 'vulopilot' ), $missing ),
			array(
				'image_count'       => count( $images ),
				'missing_alt_count' => $missing,
            ),
            'post_content',
			__( 'Add an alt attribute (can be empty for purely decorative images) to every image.', 'vulopilot' )
		);
	}

	/**
	 * Rule 23 - image optimization (3 pts). Checks local attachments' file size where the content
	 * references a real Media Library attachment; remote/external images can't be inspected this
	 * way and are excluded from the denominator-affecting evidence (not silently passed or failed).
	 */
	private function rule_image_optimization( array $images ): array {
		if ( empty( $images ) ) {
			return $this->rule_result(
				'image_optimization',
                'images_media',
                'not_applicable',
                3,
                0,
				__( 'No images in this content.', 'vulopilot' ),
                array(),
                'post_content'
			);
		}

		$checked     = 0;
		$oversized   = 0;
		$oversize_kb = 500;

		foreach ( $images as $image ) {
			$attachment_id = attachment_url_to_postid( $image['src'] );

			if ( ! $attachment_id ) {
				continue;
			}

			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			++$checked;

			if ( ( filesize( $file ) / 1024 ) > $oversize_kb ) {
				++$oversized;
			}
		}

		if ( 0 === $checked ) {
			return $this->rule_result(
				'image_optimization',
                'images_media',
                'unknown',
                3,
                0,
				__( 'None of this content\'s images are local Media Library attachments that can be inspected.', 'vulopilot' ),
				array(),
                'attachment_metadata'
			);
		}

		if ( 0 === $oversized ) {
			return $this->rule_result(
				'image_optimization',
                'images_media',
                'pass',
                3,
                3,
				sprintf( /* translators: %d: real number of local images checked. */ __( 'Checked %d local image(s); none are unusually large.', 'vulopilot' ), $checked ),
				array(
					'checked'      => $checked,
					'oversized'    => 0,
					'threshold_kb' => $oversize_kb,
                ),
                'attachment_metadata'
			);
		}

		return $this->rule_result(
			'image_optimization',
            'images_media',
            'warning',
            3,
            1.5,
			sprintf(
				/* translators: 1: real count of oversized images, 2: the size threshold in KB. */
				_n( '%1$d image is over %2$dKB.', '%1$d images are over %2$dKB.', $oversized, 'vulopilot' ),
				$oversized,
                $oversize_kb
			),
			array(
				'checked'      => $checked,
				'oversized'    => $oversized,
				'threshold_kb' => $oversize_kb,
            ),
            'attachment_metadata',
			__( 'Compress or resize the oversized image(s).', 'vulopilot' )
		);
	}

	// ---------------------------------------------------------------------
	// F. Technical SEO (15 points).
	// ---------------------------------------------------------------------

	/**
	 * Rule 24 - indexability and crawl access (5 pts).
	 */
	private function rule_indexability( \WP_Post $post ): array {
		$check  = $this->technical->check_indexability( $post );
		$earned = 'pass' === $check['status'] ? 5 : 0;

		return $this->rule_result(
			'indexability',
            'technical_seo',
            $check['status'],
            5,
            $earned,
			$check['reason'],
            $check['evidence'],
            'post_status_and_settings',
			'fail' === $check['status']
				? __( 'Review publish status and Settings → Reading.', 'vulopilot' )
				: ( 'warning' === $check['status'] ? __( 'Remove the noindex override if this page should appear in search results.', 'vulopilot' ) : null )
		);
	}

	/**
	 * Rule 25 - HTTP status (3 pts).
	 */
	private function rule_http_status( array $fetch ): array {
		$check  = $this->technical->check_http_status( $fetch );
		$earned = 'pass' === $check['status'] ? 3 : ( 'warning' === $check['status'] ? 1.5 : 0 );

		return $this->rule_result(
			'http_status',
            'technical_seo',
            $check['status'],
            3,
            $earned,
			$check['reason'],
            $check['evidence'],
            'live_http_fetch'
		);
	}

	/**
	 * Rule 26 - canonical URL (3 pts).
	 */
	private function rule_canonical( array $fetch ): array {
		$check  = $this->technical->check_canonical( $fetch );
		$earned = 'pass' === $check['status'] ? 3 : 0;

		return $this->rule_result(
			'canonical_url',
            'technical_seo',
            $check['status'],
            3,
            $earned,
			$check['reason'],
            $check['evidence'],
            'live_http_fetch',
			'fail' === $check['status'] ? __( 'Ensure a canonical URL tag is output for this page.', 'vulopilot' ) : null
		);
	}

	/**
	 * Rule 27 - structured data (2 pts).
	 */
	private function rule_structured_data( array $fetch ): array {
		$check  = $this->technical->check_structured_data( $fetch );
		$earned = 'pass' === $check['status'] ? 2 : 0;

		return $this->rule_result(
			'structured_data',
            'technical_seo',
            $check['status'],
            2,
            $earned,
			$check['reason'],
            $check['evidence'],
            'live_http_fetch'
		);
	}

	/**
	 * Rule 28 - sitemap discovery (2 pts).
	 */
	private function rule_sitemap_discovery( \WP_Post $post ): array {
		$check  = $this->technical->check_sitemap_discovery( $post );
		$earned = 'pass' === $check['status'] ? 2 : 0;

		return $this->rule_result(
			'sitemap_discovery',
            'technical_seo',
            $check['status'],
            2,
            $earned,
			$check['reason'],
            $check['evidence'],
            'sitemap_settings',
			'pass' !== $check['status'] ? __( 'Enable XML sitemaps in Settings → Sitemap.', 'vulopilot' ) : null
		);
	}

	/**
	 * URL length advisory (section 4 of the spec) - 0 points by design, to avoid double-counting
	 * with `rule_url_slug()`'s own length consideration.
	 */
	private function rule_url_length( \WP_Post $post ): array {
		$url    = (string) get_permalink( $post );
		$length = mb_strlen( $url );
		$status = $length <= self::URL_LENGTH_GUIDELINE ? 'pass' : 'warning';

		return $this->rule_result(
			'url_length',
            'technical_seo',
            $status,
            0,
            0,
			sprintf(
				/* translators: 1: real canonical URL character count, 2: the configured guideline length. */
				__( 'Canonical URL is %1$d characters (guideline: %2$d or fewer).', 'vulopilot' ),
				$length,
                self::URL_LENGTH_GUIDELINE
			),
			array(
				'url'    => $url,
				'length' => $length,
            ),
            'live_permalink',
			'warning' === $status ? __( 'Consider shortening unnecessary URL components.', 'vulopilot' ) : null
		);
	}
}
