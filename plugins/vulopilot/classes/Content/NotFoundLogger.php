<?php
namespace VuloPilot\Content;

use VuloPilot\Content\NotFoundLogRepository;
use VuloPilot\Content\RedirectRepository;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Readme.txt's "Redirects & 404s" - the `log_404s` setting's own implementation.
 *
 * @class       NotFoundLogger class
 * @version     1.0.0
 * @author      VuloLabs
 */
class NotFoundLogger {

	/**
	 * Path prefixes that mark a 404 as "system" rather than a missing CONTENT page.
	 *
	 * @var string[]
	 */
	private const SYSTEM_PATH_PREFIXES = array( '/wp-content/', '/wp-includes/', '/wp-admin/', '/.well-known/' );

	/**
	 * File extensions treated the same way as SYSTEM_PATH_PREFIXES above.
	 *
	 * @var string[]
	 */
	private const SYSTEM_EXTENSIONS = array(
		'css',
		'js',
		'mjs',
		'map',
		'json',
		'xml',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'svg',
		'webp',
		'avif',
		'ico',
		'woff',
		'woff2',
		'ttf',
		'eot',
		'otf',
		'zip',
		'txt',
		'pdf',
		'mp4',
		'webm',
		'mp3',
		'csv',
	);

	/**
	 * NotFoundLogger constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_log' ), 20 );
	}

	/**
	 * Logs the current request as a 404 visit, if it is one - classified
	 * as `is_system` (see is_system_path()) or a real content-page miss.
	 *
	 * @return void
	 */
	public function maybe_log(): void {
		if ( empty( Utill::get_setting( 'log_404s' ) ) || ! is_404() ) {
			return;
		}

		global $wp;

		$path           = wp_parse_url( home_url( $wp->request ), PHP_URL_PATH ) ?? '/';
		$requested_path = RedirectRepository::normalize_path( $path );
		$referrer       = wp_get_referer();

		( new NotFoundLogRepository() )->log_or_increment(
			$requested_path,
			$referrer ? esc_url_raw( $referrer ) : null,
			self::is_system_path( $requested_path )
		);
	}

	/**
	 * Checks a path against SYSTEM_PATH_PREFIXES/SYSTEM_EXTENSIONS.
	 *
	 * @param string $path Already-normalized request path (RedirectRepository::normalize_path()).
	 * @return bool True if this path is a static asset/tooling-probe request, not a real missing content page.
	 */
	private static function is_system_path( string $path ): bool {
		foreach ( self::SYSTEM_PATH_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}

		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return '' !== $extension && in_array( $extension, self::SYSTEM_EXTENSIONS, true );
	}
}
