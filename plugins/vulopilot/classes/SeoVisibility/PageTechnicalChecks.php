<?php
/**
 * PageTechnicalChecks class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\SeoVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Shared, stateless technical-page checks (indexability, canonical, structured data, HTTP
 * status, broken links) that both `Seo::get_page_analysis()` and `SeoScoreEngine` need - pulled
 * out so the score engine doesn't duplicate logic `Seo.php` already has (one real HTTP fetch per
 * check call, one definition of "is this indexable", shared by both).
 *
 * @class       PageTechnicalChecks class
 * @version     1.0.0
 * @author      VuloLabs
 */
class PageTechnicalChecks {

	/**
	 * @var int
	 */
	private const PAGE_FETCH_TIMEOUT_SECONDS = 5;

	/**
	 * One real GET against the page's own permalink - canonical/structured-data/HTTP-status all
	 * read from this single fetch rather than each re-requesting the page.
	 *
	 * @param string $url Real permalink to fetch.
	 * @return array{body: string|null, status: int|null, error: string|null}
	 */
	public function fetch( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => self::PAGE_FETCH_TIMEOUT_SECONDS,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'body'   => null,
				'status' => null,
				'error'  => $response->get_error_message(),
			);
		}

		return array(
			'body'   => wp_remote_retrieve_body( $response ),
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'error'  => null,
		);
	}

	/**
	 * Rule 24 - indexability and crawl access. Checks publish status, sitewide
	 * `blog_public`/"discourage search engines", and VuloPilot's own per-post robots-noindex
	 * override (`PostSeoMetaFields::META_KEYS['robots_noindex']`) - an intentional noindex must
	 * never be scored the same as an accidental one, so the message distinguishes them.
	 *
	 * @param \WP_Post $post Post being checked.
	 * @return array{status: string, reason: string, evidence: array<string, mixed>, critical: bool}
	 */
	public function check_indexability( \WP_Post $post ): array {
		if ( 'publish' !== $post->post_status ) {
			return array(
				'status'   => 'fail',
				'reason'   => __( 'This page is not published.', 'vulopilot' ),
				'evidence' => array( 'post_status' => $post->post_status ),
				'critical' => false,
			);
		}

		if ( '1' !== (string) get_option( 'blog_public' ) ) {
			return array(
				'status'   => 'fail',
				'reason'   => __( 'The site is set to discourage search engines (Settings → Reading).', 'vulopilot' ),
				'evidence' => array( 'blog_public' => get_option( 'blog_public' ) ),
				'critical' => true,
			);
		}

		$noindex = (bool) get_post_meta( $post->ID, PostSeoMetaFields::META_KEYS['robots_noindex'], true );

		if ( $noindex ) {
			return array(
				// `warning`, not `pass` - the spec is explicit that an intentionally noindexed
				// page "should not be treated as a successful public indexable page". Still not
				// `critical` (the distinction that matters is accidental vs. deliberate, not
				// visible vs. hidden), and the reason text says outright that it's a deliberate
				// per-post choice, not a site-wide/accidental one, so the auto-fix button's
				// corresponding `clear-post-noindex` fix reads as an offered option, not a flagged
				// bug (#bug this also fixed: `pass` here made that fix permanently unreachable -
				// it could never appear as an actionable rule to run it against).
				'status'   => 'warning',
				'reason'   => __( 'This page is set to noindex (General tab → Robots) - deliberate, but still excluded from search results.', 'vulopilot' ),
				'evidence' => array( 'robots_noindex' => true ),
				'critical' => false,
			);
		}

		return array(
			'status'   => 'pass',
			'reason'   => __( 'This page is published and publicly indexable.', 'vulopilot' ),
			'evidence' => array(
				'post_status'    => 'publish',
				'blog_public'    => '1',
				'robots_noindex' => false,
			),
			'critical' => false,
		);
	}

	/**
	 * Rule 25 - HTTP status of the live, public permalink.
	 *
	 * @param array{body: string|null, status: int|null, error: string|null} $fetch This page's own `fetch()` result.
	 * @return array{status: string, reason: string, evidence: array<string, mixed>}
	 */
	public function check_http_status( array $fetch ): array {
		if ( null !== $fetch['error'] ) {
			return array(
				'status'   => 'unknown',
				'reason'   => __( 'Could not verify the live page - the request failed or timed out.', 'vulopilot' ),
				'evidence' => array( 'error' => $fetch['error'] ),
			);
		}

		$code = $fetch['status'];

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'status'   => 'pass',
				'reason'   => sprintf( /* translators: %d: real HTTP status code. */ __( 'The page responded with HTTP %d.', 'vulopilot' ), $code ),
				'evidence' => array( 'http_status' => $code ),
			);
		}

		if ( $code >= 300 && $code < 400 ) {
			return array(
				'status'   => 'warning',
				'reason'   => sprintf( /* translators: %d: real HTTP status code. */ __( 'The page redirects (HTTP %d) - verify the final destination is the intended one.', 'vulopilot' ), $code ),
				'evidence' => array( 'http_status' => $code ),
			);
		}

		return array(
			'status'   => 'fail',
			'reason'   => sprintf( /* translators: %d: real HTTP status code. */ __( 'The page responded with HTTP %d.', 'vulopilot' ), $code ),
			'evidence' => array( 'http_status' => $code ),
		);
	}

	/**
	 * Rule 26 - canonical URL presence (not full conflict/redirect-chain analysis, which would
	 * need crawling every candidate duplicate - out of scope here and reported as such).
	 *
	 * @param array{body: string|null, status: int|null, error: string|null} $fetch This page's own `fetch()` result.
	 * @return array{status: string, reason: string, evidence: array<string, mixed>}
	 */
	public function check_canonical( array $fetch ): array {
		if ( null === $fetch['body'] ) {
			return array(
				'status'   => 'unknown',
				'reason'   => __( 'Could not fetch the live page to check for a canonical tag.', 'vulopilot' ),
				'evidence' => array(),
			);
		}

		if ( preg_match( '/rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $fetch['body'], $m )
			|| preg_match( '/href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']/i', $fetch['body'], $m ) ) {
			return array(
				'status'   => 'pass',
				'reason'   => __( 'A canonical URL tag is present.', 'vulopilot' ),
				'evidence' => array( 'canonical_url' => $m[1] ),
			);
		}

		return array(
			'status'   => 'fail',
			'reason'   => __( 'No canonical URL tag was found on the live page.', 'vulopilot' ),
			'evidence' => array(),
		);
	}

	/**
	 * Rule 27 - structured data presence (JSON-LD block detected and is valid JSON; doesn't
	 * validate individual schema.org properties, which would need a full schema validator).
	 *
	 * @param array{body: string|null, status: int|null, error: string|null} $fetch This page's own `fetch()` result.
	 * @return array{status: string, reason: string, evidence: array<string, mixed>}
	 */
	public function check_structured_data( array $fetch ): array {
		if ( null === $fetch['body'] ) {
			return array(
				'status'   => 'unknown',
				'reason'   => __( 'Could not fetch the live page to check for structured data.', 'vulopilot' ),
				'evidence' => array(),
			);
		}

		if ( ! preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $fetch['body'], $matches ) ) {
			return array(
				'status'   => 'warning',
				'reason'   => __( 'No JSON-LD structured data was found on the live page.', 'vulopilot' ),
				'evidence' => array(),
			);
		}

		$valid_types = array();
		$has_invalid = false;

		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( trim( $json ), true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				$has_invalid = true;
				continue;
			}

			if ( isset( $decoded['@type'] ) ) {
				$valid_types[] = (string) $decoded['@type'];
			}
		}

		if ( $has_invalid && empty( $valid_types ) ) {
			return array(
				'status'   => 'fail',
				'reason'   => __( 'Structured data was found but is not valid JSON.', 'vulopilot' ),
				'evidence' => array(),
			);
		}

		return array(
			'status'   => 'pass',
			'reason'   => sprintf(
				/* translators: %s: comma-separated real schema.org @type values found on the page. */
				__( 'Valid structured data found: %s.', 'vulopilot' ),
				implode( ', ', array_unique( $valid_types ) )
			),
			'evidence' => array( 'schema_types' => array_unique( $valid_types ) ),
		);
	}

	/**
	 * Rule 20 - broken links, read from the Broken Links scanner's own stored findings for this post rather than live-checking every link here (that scanner already owns link-destination verification, with its own timeout/retry handling - re-implementing it here would be the exact duplicate logic the spec says to avoid).
	 *
	 * @param int $post_id Post being checked.
	 * @return array{status: string, reason: string, evidence: array<string, mixed>}
	 */
	public function check_broken_links( int $post_id ): array {
		$findings = new \VuloPilot\Utill\FindingRepository();

		// No row at all for this post+scanner (open or resolved) means the Broken Links scanner
		// has never actually considered this page, not that it checked and found nothing -
		// reporting "pass" for an unscanned page would be a false clean bill of health.
		$ever_scanned = $findings->find_all(
			array(
				'scanner_id'  => 'broken-links',
				'object_type' => 'post',
				'object_ref'  => (string) $post_id,
				'per_page'    => 1,
			)
		);

		if ( 0 === count( $ever_scanned['data'] ?? array() ) ) {
			return array(
				'status'   => 'unknown',
				'reason'   => __( 'This page has not been checked for broken links yet - run a scan to verify.', 'vulopilot' ),
				'evidence' => array(),
			);
		}

		$broken = $findings->find_all(
			array(
				'scanner_id'  => 'broken-links',
				'status'      => 'open',
				'object_type' => 'post',
				'object_ref'  => (string) $post_id,
				'per_page'    => 100,
			)
		);

		$count = count( $broken['data'] ?? array() );

		if ( $count > 0 ) {
			return array(
				'status'   => 'fail',
				'reason'   => sprintf(
					/* translators: %d: real number of broken links found on this page. */
					_n( '%d broken link found on this page.', '%d broken links found on this page.', $count, 'vulopilot' ),
					$count
				),
				'evidence' => array( 'broken_link_count' => $count ),
			);
		}

		return array(
			'status'   => 'pass',
			'reason'   => __( 'No broken links found on this page.', 'vulopilot' ),
			'evidence' => array( 'broken_link_count' => 0 ),
		);
	}

	/**
	 * Rule 28 - sitemap discovery, derived from VuloPilot's own sitemap settings (post type
	 * enabled, post not in the exclude list) rather than fetching and parsing the live sitemap
	 * XML - equivalent in practice (those settings are exactly what the sitemap generator itself
	 * reads to decide inclusion), without a second live HTTP round trip per score calculation.
	 *
	 * @param \WP_Post $post Post being checked.
	 * @return array{status: string, reason: string, evidence: array<string, mixed>}
	 */
	public function check_sitemap_discovery( \WP_Post $post ): array {
		$settings = wp_parse_args( get_option( \VuloPilot\Utill::VULOPILOT_SETTINGS_KEY, array() ), \VuloPilot\Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['sitemap_enabled'] ) ) {
			// `warning`, not `unknown` - this is a known, fixable state (VuloPilot's own sitemap
			// setting is off), not something that genuinely couldn't be verified. Reporting it as
			// `unknown` made the matching `enable-sitemap` auto-fix permanently unreachable, the
			// same bug `check_indexability()`'s own noindex branch had (see its docblock).
			return array(
				'status'   => 'warning',
				'reason'   => __( 'XML sitemaps are disabled - the page may still be discoverable through crawlable links, but a sitemap makes discovery more reliable.', 'vulopilot' ),
				'evidence' => array( 'sitemap_enabled' => false ),
			);
		}

		$included_types = (array) ( $settings['sitemap_xml_post_types'] ?? array() );

		if ( ! in_array( $post->post_type, $included_types, true ) ) {
			return array(
				'status'   => 'fail',
				'reason'   => sprintf(
					/* translators: %s: real post type name. */
					__( 'The "%s" post type is not included in the XML sitemap settings.', 'vulopilot' ),
					$post->post_type
				),
				'evidence' => array(
					'post_type'           => $post->post_type,
					'included_post_types' => $included_types,
				),
			);
		}

		$excluded_posts = array_filter( array_map( 'absint', explode( ',', (string) ( $settings['sitemap_exclude_posts'] ?? '' ) ) ) );

		if ( in_array( $post->ID, $excluded_posts, true ) ) {
			return array(
				'status'   => 'fail',
				'reason'   => __( 'This page is on the sitemap exclusion list.', 'vulopilot' ),
				'evidence' => array( 'post_id' => $post->ID ),
			);
		}

		return array(
			'status'   => 'pass',
			'reason'   => __( 'This page\'s post type is included in the XML sitemap and it is not excluded.', 'vulopilot' ),
			'evidence' => array( 'post_type' => $post->post_type ),
		);
	}
}
