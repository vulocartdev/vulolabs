<?php
/**
 * RedirectRepository class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Content;

use VuloPilot\Utill\RepositoryUtil;


defined( 'ABSPATH' ) || exit;

/**
 * Persistence for vulopilot_redirects - the "Redirects & 404s" feature's user-managed
 * 301/302 redirect table.
 *
 * @class       RedirectRepository class
 * @version     1.0.0
 * @author      VuloLabs
 */
class RedirectRepository extends RepositoryUtil {

	/**
	 * Columns find_all() may filter on.
	 *
	 * @var string[]
	 */
	protected array $filterable_columns = array( 'is_active', 'source_path' );

	/**
	 * Columns an incoming `search` arg is matched against.
	 *
	 * @var string[]
	 */
	protected array $searchable_columns = array( 'source_path', 'target_url' );

	/**
	 * CoreUtill::TABLES key this repository owns.
	 *
	 * @inheritDoc
	 */
	protected function get_table_key(): string {
		return 'redirect';
	}

	/**
	 * Looks up a redirect row by its exact source path.
	 *
	 * @param string $source_path Already-normalized path (see normalize_path()).
	 * @return array<string, mixed>|null
	 */
	public function find_by_source_path( string $source_path ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( 'SELECT * FROM %i WHERE source_path = %s', $this->get_table(), $source_path ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Bumps a redirect's own hit counter and records the moment.
	 *
	 * @param int $id Redirect row id.
	 * @return void
	 */
	public function increment_hit_count( int $id ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET hit_count = hit_count + 1, last_accessed_at = %s WHERE id = %d',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				$id
			)
		);
	}

	/**
	 * Finds the active redirect this request path matches - an exact `source_path` row if one exists (the common case, one indexed lookup), otherwise the first active non-exact row (`match_type` of `contains`/`start`/`end`/`regex`) whose own `source_path` pattern matches, checked oldest-created first.
	 *
	 * @param string $requested_path Already-normalized path (see normalize_path()).
	 * @return array<string, mixed>|null
	 */
	public function find_matching( string $requested_path ): ?array {
		$exact = $this->find_by_source_path( $requested_path );

		if ( $exact ) {
			return $exact;
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM %i WHERE is_active = 1 AND match_type != 'exact' ORDER BY id ASC", $this->get_table() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( $this->pattern_matches( (string) $row['match_type'], (string) $row['source_path'], $requested_path ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param string $match_type     'contains'/'start'/'end'/'regex'.
	 * @param string $pattern        The row's own `source_path` value, used as the match pattern.
	 * @param string $requested_path Already-normalized path.
	 * @return bool
	 */
	private function pattern_matches( string $match_type, string $pattern, string $requested_path ): bool {
		switch ( $match_type ) {
			case 'contains':
				return '' !== $pattern && false !== strpos( $requested_path, $pattern );

			case 'start':
				return '' !== $pattern && 0 === strpos( $requested_path, $pattern );

			case 'end':
				return '' !== $pattern && str_ends_with( $requested_path, $pattern );

			case 'regex':
				// A rule-author-supplied regex that fails to compile just never matches, rather
				// than fataling every request on the site.
				return @preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#', $requested_path ) > 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid admin-authored pattern must degrade to "no match", not a fatal on every page load.

			default:
				return false;
		}
	}

	/**
	 * Normalizes a request path (strips the subdirectory prefix, forces a leading slash,
	 * drops trailing slashes) so redirects and 404 log entries compare equal.
	 *
	 * @param string $raw_path A raw REQUEST_URI path component (no query string) or a user-typed path.
	 * @return string
	 */
	public static function normalize_path( string $raw_path ): string {
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( $home_path && '/' !== $home_path && 0 === strpos( $raw_path, $home_path ) ) {
			$raw_path = substr( $raw_path, strlen( $home_path ) );
		}

		$path = '/' . ltrim( $raw_path, '/' );

		return '/' !== $path ? untrailingslashit( $path ) : $path;
	}
}
