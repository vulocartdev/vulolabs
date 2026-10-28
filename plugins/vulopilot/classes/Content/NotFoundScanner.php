<?php
namespace VuloPilot\Content;

use VuloPilot\Utill\TracksScannedObjectsInterface;
use VuloPilot\Utill\Finding;
use VuloPilot\Utill\Severity;
use VuloPilot\Utill\ScannerUtil;
use VuloPilot\Utill\ScannedPostsTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether a bounded batch of recently-modified published posts' OWN permalinks
 * resolve.
 *
 * @class       NotFoundScanner class
 * @version     1.0.0
 * @author      VuloLabs
 */
class NotFoundScanner extends ScannerUtil implements TracksScannedObjectsInterface {

	use ScannedPostsTrait;

	private const BATCH_SIZE              = 30;
	private const REQUEST_TIMEOUT_SECONDS = 5;

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'not-found';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '404 Detection', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'not-found';
	}

	/**
	 * @inheritDoc
	 */
	public function scan(): array {
		$content_search_scans = \VuloPilot\Utill::get_setting( 'content_search_scans', array() );

		if ( empty( $content_search_scans['links']['enable'] ) ) {
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

			$permalink = get_permalink( $post );
			$response  = wp_remote_head(
				$permalink,
				array(
					'timeout'     => self::REQUEST_TIMEOUT_SECONDS,
					'redirection' => 5,
					'sslverify'   => false,
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			if ( 404 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$findings[] = new Finding(
				sprintf(
					/* translators: %s is the post/page title. */
					__( 'Published content returns a 404: %s', 'vulopilot' ),
					get_the_title( $post )
				),
				Severity::HIGH,
				$this->get_category(),
				__( 'This page is published but its own URL does not resolve - often caused by a permalink structure or rewrite rule change.', 'vulopilot' ),
				'post',
				(string) $post->ID,
				array( 'url' => $permalink )
			);
		}

		return $findings;
	}
}
