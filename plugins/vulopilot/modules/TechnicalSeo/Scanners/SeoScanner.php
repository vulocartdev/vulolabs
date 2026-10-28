<?php
/**
 * SeoScanner class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\TechnicalSeo\Scanners;

use VuloPilot\Utill\TracksScannedObjectsInterface;
use VuloPilot\Utill\Finding;
use VuloPilot\Utill\Severity;
use VuloPilot\Utill\ScannerUtil;
use VuloPilot\Utill\ScannedPostsTrait;
use VuloPilot\SeoVisibility\PostSeoMetaFields;

defined( 'ABSPATH' ) || exit;

/**
 * Flags published post/page titles outside the length search engines reliably display in
 * full (roughly 10-60 characters).
 *
 * @class       SeoScanner class
 * @version     1.0.0
 * @author      VuloLabs
 */
class SeoScanner extends ScannerUtil implements TracksScannedObjectsInterface {

	use ScannedPostsTrait;

	private const TITLE_MIN_LENGTH = 10;
	private const TITLE_MAX_LENGTH = 60;
	private const BATCH_SIZE       = 50;

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'seo';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'SEO', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'seo';
	}

	/**
	 * @inheritDoc
	 */
	public function scan(): array {
		$settings = wp_parse_args( get_option( \VuloPilot\Utill::VULOPILOT_SETTINGS_KEY, array() ), \VuloPilot\Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( empty( $settings['content_search_scans']['seo']['enable'] ) ) {
			return array();
		}

		$findings = array();
		$posts    = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::BATCH_SIZE,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		foreach ( $posts as $post ) {
			$this->mark_post_scanned( $post->ID );

			// The per-post SEO title override (General tab) always wins over the real post_title -
			// same source `TitleFormatter::maybe_filter_title()` prefers for the actual `<title>`
			// tag, so this check scores what search engines will really see.
			$seo_title    = trim( (string) get_post_meta( $post->ID, PostSeoMetaFields::META_KEYS['seo_title'], true ) );
			$title        = '' !== $seo_title ? $seo_title : $post->post_title;
			$title_length = mb_strlen( trim( $title ) );

			if ( $title_length >= self::TITLE_MIN_LENGTH && $title_length <= self::TITLE_MAX_LENGTH ) {
				continue;
			}

			$findings[] = new Finding(
				$title_length < self::TITLE_MIN_LENGTH
					? sprintf(
						/* translators: %s is the post/page title. */
						__( 'Title is too short for search results: %s', 'vulopilot' ),
						get_the_title( $post )
					)
					: sprintf(
						/* translators: %s is the post/page title. */
						__( 'Title may be truncated in search results: %s', 'vulopilot' ),
						get_the_title( $post )
					),
				Severity::LOW,
				$this->get_category(),
				sprintf(
					/* translators: 1: minimum recommended title length, 2: maximum recommended title length. */
					__( 'Search engines display roughly %1$d–%2$d characters of a title before truncating.', 'vulopilot' ),
					self::TITLE_MIN_LENGTH,
					self::TITLE_MAX_LENGTH
				),
				'post',
				(string) $post->ID,
				array( 'title_length' => $title_length )
			);
		}

		return $findings;
	}
}
