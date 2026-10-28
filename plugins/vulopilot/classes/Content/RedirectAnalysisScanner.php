<?php
namespace VuloPilot\Content;

use VuloPilot\Utill\Finding;
use VuloPilot\Utill\Severity;
use VuloPilot\Utill\ScannerUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the homepage's own redirect chain (if any), manually following `Location` headers.
 *
 * @class       RedirectAnalysisScanner class
 * @version     1.0.0
 * @author      VuloLabs
 */
class RedirectAnalysisScanner extends ScannerUtil {

	/**
	 * A chain this long or shorter is normal (e.g. http -> https, or a
	 * single canonical www/non-www redirect) and isn't worth flagging.
	 */
	private const MAX_HEALTHY_HOPS = 2;

	/**
	 * Hard cap on hops followed before giving up and treating the chain
	 * as a likely loop - bounds the request count this scanner can make.
	 */
	private const MAX_HOPS_CHECKED = 5;

	private const REQUEST_TIMEOUT_SECONDS = 5;

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'redirect-analysis';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( 'Redirect Analysis', 'vulopilot' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'redirects';
	}

	/**
	 * @inheritDoc
	 */
	public function scan(): array {
		$content_search_scans = \VuloPilot\Utill::get_setting( 'content_search_scans', array() );

		if ( empty( $content_search_scans['links']['enable'] ) ) {
			return array();
		}

		$url  = home_url( '/' );
		$hops = array();

		for ( $i = 0; $i < self::MAX_HOPS_CHECKED; $i++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => self::REQUEST_TIMEOUT_SECONDS,
					'redirection' => 0,
					'sslverify'   => false,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code < 300 || $status_code >= 400 ) {
				break;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( empty( $location ) ) {
				break;
			}

			$hops[] = $url;
			$url    = $location;
		}

		if ( count( $hops ) > self::MAX_HEALTHY_HOPS ) {
			$looped = count( $hops ) === self::MAX_HOPS_CHECKED;

			return array(
				new Finding(
					$looped
						? __( 'Homepage redirect did not resolve within the checked hop limit', 'vulopilot' )
						: sprintf(
							/* translators: %d is the number of redirect hops. */
							__( 'Homepage redirects through %d hops before resolving', 'vulopilot' ),
							count( $hops )
						),
					$looped ? Severity::HIGH : Severity::MEDIUM,
					$this->get_category(),
					__( 'Long or looping redirect chains slow down every visit and hurt search engine crawling.', 'vulopilot' ),
					'url',
					home_url( '/' ),
					array( 'chain' => $hops ),
					'homepage-redirect-chain'
				),
			);
		}

		return array();
	}
}
