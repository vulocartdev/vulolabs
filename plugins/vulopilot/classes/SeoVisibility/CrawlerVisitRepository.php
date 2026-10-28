<?php
/**
 * CrawlerVisitRepository class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\SeoVisibility;

use VuloPilot\Utill\RepositoryUtil;


defined( 'ABSPATH' ) || exit;

/**
 * @class       CrawlerVisitRepository class
 * @version     1.0.0
 * @author      VuloLabs
 */
class CrawlerVisitRepository extends RepositoryUtil {

	/**
	 * @var string[]
	 */
	protected array $filterable_columns = array( 'bot_name' );

	/**
	 * @var string[]
	 */
	protected array $searchable_columns = array( 'requested_url' );

	/**
	 * @inheritDoc
	 */
	protected function get_table_key(): string {
		return 'crawler_visit';
	}

	/**
	 * Records one detected bot visit - no IP address, user id.
	 *
	 * @param string $bot_name      Display name of the matched bot.
	 * @param string $user_agent    The raw User-Agent header that matched.
	 * @param string $requested_url The requested path.
	 * @param bool   $is_404        Whether the request resolved to a 404.
	 * @return int Inserted row id.
	 */
	public function log( string $bot_name, string $user_agent, string $requested_url, bool $is_404 = false ): int {
		return $this->insert(
			array(
				'bot_name'      => $bot_name,
				'user_agent'    => $user_agent,
				'requested_url' => $requested_url,
				'is_404'        => $is_404 ? 1 : 0,
			)
		);
	}

	/**
	 * Visit counts per bot - backs the Crawler Traffic page's filter-pill bar.
	 *
	 * @return array<string, int>
	 */
	public function get_bot_counts(): array {
		return $this->count_by_column( 'bot_name' );
	}

	/**
	 * Most recent visit timestamp per bot.
	 *
	 * @return array<int, array{bot_name: string, last_seen_at: string}>
	 */
	public function get_bot_last_seen(): array {
		global $wpdb;

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( 'SELECT bot_name, MAX(created_at) AS last_seen_at FROM %i GROUP BY bot_name ORDER BY last_seen_at DESC', $this->get_table() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Most-requested URLs across every bot.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int, array{requested_url: string, total: int}>
	 */
	public function get_most_crawled_pages( int $limit = 10 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT requested_url, COUNT(*) AS total FROM %i GROUP BY requested_url ORDER BY total DESC LIMIT %d',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, $limit )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Visit counts per calendar day over a trailing window, zero-filled for days with no
	 * visits.
	 *
	 * @param int $days Trailing window size.
	 * @return array<int, array{date: string, total: int}> Oldest first.
	 */
	public function get_daily_volume( int $days = 30 ): array {
		global $wpdb;

		$days = max( 1, $days );

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT DATE(created_at) AS visit_date, COUNT(*) AS total FROM %i WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY visit_date',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			),
			ARRAY_A
		);

		$counts_by_date = array();
		foreach ( (array) $rows as $row ) {
			$counts_by_date[ $row['visit_date'] ] = (int) $row['total'];
		}

		$volume = array();
		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$date     = gmdate( 'Y-m-d', strtotime( "-{$offset} days" ) );
			$volume[] = array(
				'date'  => $date,
				'total' => $counts_by_date[ $date ] ?? 0,
			);
		}

		return $volume;
	}

	/**
	 * @param int $days Trailing window size.
	 * @return array<string, array<int, array{date: string, total: int}>> Bot name => daily volume, oldest first.
	 */
	public function get_daily_volume_by_bot( int $days = 30 ): array {
		global $wpdb;

		$days = max( 1, $days );

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT bot_name, DATE(created_at) AS visit_date, COUNT(*) AS total FROM %i WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY bot_name, visit_date',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			),
			ARRAY_A
		);

		$counts_by_bot = array();
		foreach ( (array) $rows as $row ) {
			$counts_by_bot[ $row['bot_name'] ][ $row['visit_date'] ] = (int) $row['total'];
		}

		$volume_by_bot = array();
		foreach ( $counts_by_bot as $bot_name => $counts_by_date ) {
			$volume = array();
			for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
				$date     = gmdate( 'Y-m-d', strtotime( "-{$offset} days" ) );
				$volume[] = array(
					'date'  => $date,
					'total' => $counts_by_date[ $date ] ?? 0,
				);
			}
			$volume_by_bot[ $bot_name ] = $volume;
		}

		return $volume_by_bot;
	}

	/**
	 * @param string $period_start Y-m-d, inclusive.
	 * @param string $period_end   Y-m-d, inclusive.
	 * @return array{total: int, by_bot: array<string, int>, top_pages: array<int, array{requested_url: string, total: int}>}
	 */
	public function get_stats_for_period( string $period_start, string $period_end ): array {
		global $wpdb;

		$total = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 DAY)',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$period_start,
				$period_end
			)
		);

		$bot_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT bot_name, COUNT(*) AS total FROM %i WHERE created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 DAY) GROUP BY bot_name',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$period_start,
				$period_end
			),
			ARRAY_A
		);

		$by_bot = array();
		foreach ( (array) $bot_rows as $row ) {
			$by_bot[ $row['bot_name'] ] = (int) $row['total'];
		}

		$top_pages = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT requested_url, COUNT(*) AS total FROM %i WHERE created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 DAY) GROUP BY requested_url ORDER BY total DESC LIMIT 10',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$period_start,
				$period_end
			),
			ARRAY_A
		);

		return array(
			'total'     => $total,
			'by_bot'    => $by_bot,
			'top_pages' => $top_pages ? $top_pages : array(),
		);
	}

	/**
	 * Current-versus-previous-period comparison for the Crawler Traffic tab, with percent change against the preceding period of equal length. current_total:
	 *
	 * @param int $days Trailing window size (also the length of the "previous" comparison window).
	 * @return array{
	 */
	public function get_period_comparison( int $days = 30 ): array {
		global $wpdb;

		$days = max( 1, $days );

		$current_start  = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$previous_start = gmdate( 'Y-m-d', strtotime( '-' . ( 2 * $days - 1 ) . ' days' ) );
		$previous_end   = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

		$current  = $this->get_stats_for_period( $current_start, gmdate( 'Y-m-d' ) );
		$previous = $this->get_stats_for_period( $previous_start, $previous_end );

		$last_seen_by_bot = array();
		foreach ( $this->get_bot_last_seen() as $row ) {
			$last_seen_by_bot[ $row['bot_name'] ] = $row['last_seen_at'];
		}

		$bot_names    = array_unique( array_merge( array_keys( $current['by_bot'] ), array_keys( $previous['by_bot'] ) ) );
		$top_crawlers = array();
		foreach ( $bot_names as $bot_name ) {
			$top_crawlers[] = array(
				'bot_name'       => $bot_name,
				'total'          => $current['by_bot'][ $bot_name ] ?? 0,
				'previous_total' => $previous['by_bot'][ $bot_name ] ?? 0,
				'last_seen_at'   => $last_seen_by_bot[ $bot_name ] ?? null,
			);
		}
		usort( $top_crawlers, static fn( $a, $b ) => $b['total'] <=> $a['total'] );

		$page_urls            = array_column( $current['top_pages'], 'requested_url' );
		$previous_page_counts = array();
		if ( $page_urls ) {
			$placeholders       = implode( ',', array_fill( 0, count( $page_urls ), '%s' ) );
			$previous_page_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
				$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
					"SELECT requested_url, COUNT(*) AS total FROM %i WHERE created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 DAY) AND requested_url IN ({$placeholders}) GROUP BY requested_url", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					array_merge( array( $this->get_table(), $previous_start, $previous_end ), $page_urls )
				),
				ARRAY_A
			);
			foreach ( (array) $previous_page_rows as $row ) {
				$previous_page_counts[ $row['requested_url'] ] = (int) $row['total'];
			}
		}

		$most_crawled_pages = array_map(
			static fn( $page ) => array(
				'requested_url'  => $page['requested_url'],
				'total'          => (int) $page['total'],
				'previous_total' => $previous_page_counts[ $page['requested_url'] ] ?? 0,
			),
			$current['top_pages']
		);

		return array(
			'current_total'        => $current['total'],
			'previous_total'       => $previous['total'],
			'current_unique_bots'  => count( $current['by_bot'] ),
			'previous_unique_bots' => count( $previous['by_bot'] ),
			'top_crawlers'         => $top_crawlers,
			'most_crawled_pages'   => $most_crawled_pages,
		);
	}

	/**
	 * 404 rate for one bot's most recent visits.
	 *
	 * @param string $bot_name  Display name.
	 * @param int    $recent_n  How many of the bot's most recent visits to look at.
	 * @param int    $min_sample Minimum visits required before a rate is considered meaningful.
	 * @return array{rate_percent: int, sample_size: int}|null
	 */
	public function get_404_rate_for_bot( string $bot_name, int $recent_n = 20, int $min_sample = 5 ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT is_404 FROM %i WHERE bot_name = %s ORDER BY created_at DESC LIMIT %d',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bot_name,
				max( 1, $recent_n )
			),
			ARRAY_A
		);

		$sample_size = count( (array) $rows );

		if ( $sample_size < max( 1, $min_sample ) ) {
			return null;
		}

		$not_found = array_sum( array_column( (array) $rows, 'is_404' ) );

		return array(
			'rate_percent' => (int) round( ( $not_found / $sample_size ) * 100 ),
			'sample_size'  => $sample_size,
		);
	}

	/**
	 * Per-bot visit counts and last-seen time for one specific URL - backs a single post's own
	 * "AI Visibility" (crawler activity) tab, as opposed to the sitewide Crawler Traffic page.
	 *
	 * @param string $requested_url Exact path this plugin logs visits under (e.g. post permalink path).
	 * @param int    $days          Trailing window size.
	 * @return array{total: int, by_bot: array<int, array{bot_name: string, total: int, last_seen_at: string}>, is_404_rate_percent: int|null}
	 */
	public function get_stats_for_url( string $requested_url, int $days = 90 ): array {
		global $wpdb;

		$days = max( 1, $days );

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}-style variables here are always this plugin's own hardcoded table name, never user input.
			$wpdb->prepare(
				'SELECT bot_name, COUNT(*) AS total, MAX(created_at) AS last_seen_at, SUM(is_404) AS not_found FROM %i WHERE requested_url = %s AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY bot_name ORDER BY total DESC',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$requested_url,
				$days
			),
			ARRAY_A
		);

		$rows = $rows ? $rows : array();

		$total     = 0;
		$not_found = 0;
		$by_bot    = array();
		foreach ( $rows as $row ) {
			$total     += (int) $row['total'];
			$not_found += (int) $row['not_found'];
			$by_bot[]   = array(
				'bot_name'     => $row['bot_name'],
				'total'        => (int) $row['total'],
				'last_seen_at' => $row['last_seen_at'],
			);
		}

		return array(
			'total'               => $total,
			'by_bot'              => $by_bot,
			'is_404_rate_percent' => $total > 0 ? (int) round( ( $not_found / $total ) * 100 ) : null,
		);
	}

	/**
	 * Every bot name that has ever logged at least one visit.
	 *
	 * @return string[]
	 */
	public function get_all_bot_names_ever_seen(): array {
		global $wpdb;

		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT bot_name FROM %i', $this->get_table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $names ? $names : array();
	}

	/**
	 * @param int $days Rows with `created_at` older than this many days are deleted.
	 * @return int Number of rows deleted.
	 */
	public function delete_older_than( int $days ): int {
		global $wpdb;

		$deleted = $wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, $days )
			)
		);

		return false !== $deleted ? (int) $deleted : 0;
	}
}
