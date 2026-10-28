<?php
/**
 * FindingRepository class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for vulopilot_scan_findings. category/severity/status back the admin UI's FindingsTable filters;
 *
 * @class       FindingRepository class
 * @version     1.0.0
 * @author      VuloLabs
 */
class FindingRepository extends RepositoryUtil {

	/**
	 * @var string[]
	 */
	protected array $filterable_columns = array( 'category', 'severity', 'status', 'object_type', 'object_ref', 'scanner_id' );

	/**
	 * @var string[]
	 */
	protected array $searchable_columns = array( 'title', 'description' );

	/**
	 * @inheritDoc
	 */
	protected function get_table_key(): string {
		return 'scan_finding';
	}

	/**
	 * The already-open finding a fresh re-detection should refresh instead of duplicating.
	 *
	 * @param string      $scanner_id  Owning scanner's id.
	 * @param string|null $object_type Finding::get_object_type().
	 * @param string|null $object_ref  Finding::get_object_ref().
	 * @param string      $title       Finding::get_title().
	 * @param string|null $dedupe_key  Finding::get_dedupe_key().
	 * @return array<string, mixed>|null
	 */
	public function find_open_duplicate( string $scanner_id, ?string $object_type, ?string $object_ref, string $title, ?string $dedupe_key = null ): ?array {
		global $wpdb;

		$params = array( $this->get_table(), $scanner_id );

		// Column/table names are bound with %i rather than interpolated.
		$type_is_null = null === $object_type || '' === $object_type;
		$ref_is_null  = null === $object_ref || '' === $object_ref;

		$params[] = 'object_type';

		if ( ! $type_is_null ) {
			$params[] = $object_type;
		}

		$params[] = 'object_ref';

		if ( ! $ref_is_null ) {
			$params[] = $object_ref;
		}

		if ( null !== $dedupe_key ) {
			$params[] = $dedupe_key;
		} else {
			$params[] = $title;
		}

		$where = implode(
			' AND ',
			array(
				"status = 'open'",
				'scanner_id = %s',
				$type_is_null ? '%i IS NULL' : '%i = %s',
				$ref_is_null ? '%i IS NULL' : '%i = %s',
				null !== $dedupe_key ? 'dedupe_key = %s' : 'title = %s',
				null !== $dedupe_key ? '1 = 1' : 'dedupe_key IS NULL',
			)
		);

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT 1", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a fixed set of %i/%s placeholder fragments, all bound through prepare() above.

		return $row ? $row : null;
	}

	/**
	 * Every currently-open row id for one scanner, unpaginated.
	 *
	 * @param string $scanner_id Owning scanner id.
	 * @return int[]
	 */
	public function get_open_finding_ids_for_scanner( string $scanner_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- {$this->get_table()} is this plugin's own table name, not user input.
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status = 'open' AND scanner_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_table(),
				$scanner_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Open/resolved/ignored/snoozed counts, zero-filled.
	 *
	 * @param string|null   $category    Category to scope to, or null for all.
	 * @param string[]|null $scanner_ids Scanner ids to scope to, or null for all.
	 * @return array{open: int, resolved: int, ignored: int, snoozed: int}
	 */
	public function get_status_counts( ?string $category = null, ?array $scanner_ids = null ): array {
		$args = array();

		if ( null !== $category ) {
			$args['category'] = $category;
		}

		if ( null !== $scanner_ids ) {
			$args['scanner_id'] = $scanner_ids;
		}

		return array_merge(
			array(
				'open'     => 0,
				'resolved' => 0,
				'ignored'  => 0,
				'snoozed'  => 0,
			),
			$this->count_by_column( 'status', $args )
		);
	}

	/**
	 * Open findings bucketed into the 3-tier High/Medium/Low priority.
	 *
	 * @param string|null $category Category to scope to, or null for sitewide.
	 * @return array{high: int, medium: int, low: int}
	 */
	public function get_priority_counts( ?string $category = null ): array {
		$raw = array_merge(
			array(
				'critical' => 0,
				'high'     => 0,
				'medium'   => 0,
				'low'      => 0,
				'info'     => 0,
			),
			$this->count_by_column(
				'severity',
				array(
					'status'   => 'open',
					'category' => $category ?? '',
				)
			)
		);

		return array(
			'high'   => $raw['critical'] + $raw['high'],
			'medium' => $raw['medium'],
			'low'    => $raw['low'] + $raw['info'],
		);
	}

	/**
	 * Same as get_priority_counts(), scoped to a scanner_id set.
	 *
	 * @param string[] $scanner_ids Scanner ids to scope to.
	 * @return array{high: int, medium: int, low: int}
	 */
	public function get_priority_counts_for_scanner_ids( array $scanner_ids ): array {
		$raw = $this->get_severity_breakdown_for_scanner_ids( $scanner_ids );

		return array(
			'high'   => $raw['critical'] + $raw['high'],
			'medium' => $raw['medium'],
			'low'    => $raw['low'] + $raw['info'],
		);
	}

	/**
	 * Groups every open finding by scanner_id, top $limit groups, most-severe-first.
	 *
	 * @param int      $limit      Max groups to return.
	 * @param string[] $categories Categories to scope to; empty means sitewide.
	 * @return array<int, array{scanner_id: string, count: int, severity: string, category: string, object_type: ?string}>
	 */
	public function get_top_finding_groups( int $limit = 3, array $categories = array() ): array {
		$scope = array( 'status' => 'open' );

		if ( $categories ) {
			$scope['category'] = $categories;
		}

		$counts_by_scanner = $this->count_by_column( 'scanner_id', $scope );

		if ( empty( $counts_by_scanner ) ) {
			return array();
		}

		$sample = $this->find_all(
			array_merge(
				$scope,
				array(
					'per_page' => 100,
					'orderby'  => 'id',
					'order'    => 'desc',
				)
			)
		);

		$severity_rank = array(
			'critical' => 0,
			'high'     => 1,
			'medium'   => 2,
			'low'      => 3,
			'info'     => 4,
		);

		// Worst (most urgent) severity seen per scanner_id in the sample.
		$representatives = array();

		foreach ( $sample['data'] as $row ) {
			$scanner_id = (string) ( $row['scanner_id'] ?? '' );

			if ( '' === $scanner_id ) {
				continue;
			}

			$existing      = $representatives[ $scanner_id ] ?? null;
			$existing_rank = null !== $existing ? ( $severity_rank[ $existing['severity'] ] ?? 5 ) : 6;
			$row_rank      = $severity_rank[ $row['severity'] ] ?? 5;

			if ( null === $existing || $row_rank < $existing_rank ) {
				$representatives[ $scanner_id ] = $row;
			}
		}

		$groups = array();

		foreach ( $counts_by_scanner as $scanner_id => $count ) {
			$representative = $representatives[ $scanner_id ] ?? null;

			if ( null === $representative ) {
				continue;
			}

			$groups[] = array(
				'scanner_id'  => $scanner_id,
				'count'       => $count,
				'severity'    => $representative['severity'],
				'category'    => $representative['category'],
				'object_type' => $representative['object_type'],
			);
		}

		usort(
			$groups,
			static function ( $a, $b ) use ( $severity_rank ) {
				$rank_a = $severity_rank[ $a['severity'] ] ?? 5;
				$rank_b = $severity_rank[ $b['severity'] ] ?? 5;

				if ( $rank_a !== $rank_b ) {
					return $rank_a <=> $rank_b;
				}

				return $b['count'] <=> $a['count'];
			}
		);

		return array_slice( $groups, 0, $limit );
	}

	/**
	 * The single top open finding-type group within a fixed set of categories.
	 *
	 * @param string[] $categories Category values to scope to.
	 * @return array{scanner_id: string, count: int, severity: string, category: string, object_type: ?string}|null
	 */
	public function get_top_finding_group_for_categories( array $categories ): ?array {
		$groups = $this->get_top_finding_groups( 1, $categories );

		return $groups[0] ?? null;
	}

	/**
	 * Same worst-severity grouping as get_finding_groups(), for one scanner_id.
	 *
	 * @param string $scanner_id Scanner id to look up.
	 * @return array{scanner_id: string, category: string, count: int, severity: string}|null Null if no open findings.
	 */
	public function get_group_by_scanner_id( string $scanner_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT category, COUNT(*) AS count, MIN( CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 WHEN 'info' THEN 4 ELSE 5 END ) AS severity_rank FROM %i WHERE status = 'open' AND scanner_id = %s GROUP BY category", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_table(),
				$scanner_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$severity_by_rank = array(
			0 => 'critical',
			1 => 'high',
			2 => 'medium',
			3 => 'low',
			4 => 'info',
			5 => 'info',
		);

		return array(
			'scanner_id' => $scanner_id,
			'category'   => (string) $row['category'],
			'count'      => (int) $row['count'],
			'severity'   => $severity_by_rank[ (int) $row['severity_rank'] ] ?? 'info',
		);
	}

	/**
	 * Open group counts per category (distinct scanner_id/category/object_type rows).
	 *
	 * @return array<string, int> category => open group count.
	 */
	public function get_category_group_counts(): array {
		global $wpdb;
		$table = $this->get_table();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT category, COUNT(*) AS total FROM ( SELECT scanner_id, category, object_type FROM %i WHERE status = 'open' GROUP BY scanner_id, category, object_type ) grouped GROUP BY category", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is code-controlled, no user input in this query.

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ $row['category'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Paginated version of get_top_finding_groups(), with explicit filters.
	 *
	 * @param array{status?: string, category?: string|string[], scanner_ids?: string[], priority_ranks?: int[], page?: int, per_page?: int} $args `category` accepts several values (IN-matched); `scanner_ids` scopes to an explicit scanner set (ANDed with `category`); `priority_ranks` filters by worst-severity rank (0=critical..4=info).
	 * @return array{data: array<int, array{scanner_id: string, category: string, count: int, severity: string, object_type: ?string}>, total: int}
	 */
	public function get_finding_groups( array $args = array() ): array {
		global $wpdb;
		$table = $this->get_table();

		$status         = ! empty( $args['status'] ) ? (string) $args['status'] : 'open';
		$category       = $args['category'] ?? '';
		$scanner_ids    = ! empty( $args['scanner_ids'] ) ? (array) $args['scanner_ids'] : array();
		$priority_ranks = ! empty( $args['priority_ranks'] ) ? array_map( 'intval', $args['priority_ranks'] ) : array();
		$page           = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page       = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset         = ( $page - 1 ) * $per_page;

		// 'all' is a deliberate escape hatch, not a real status value.
		$category_values = array();

		if ( is_array( $category ) ) {
			$category_values = array_map( 'strval', $category );
		} elseif ( is_string( $category ) && '' !== $category ) {
			$category_values = array( $category );
		}

		$values = array();

		if ( 'all' !== $status ) {
			$values[] = $status;
		}

		array_push( $values, ...$category_values );
		array_push( $values, ...$scanner_ids );

		$category_placeholders = implode( ', ', array_fill( 0, count( $category_values ), '%s' ) );
		$scanner_placeholders  = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );
		$rank_placeholders     = implode( ', ', array_fill( 0, count( $priority_ranks ), '%d' ) );

		// Each optional filter is picked, never assembled.
		$where = implode(
			' AND ',
			array(
				'1 = 1',
				'all' !== $status ? 'status = %s' : '1 = 1',
				$category_values ? "category IN ({$category_placeholders})" : '1 = 1',
				$scanner_ids ? "scanner_id IN ({$scanner_placeholders})" : '1 = 1',
			)
		);

		// Grouped once, then filtered by worst-severity rank in an outer WHERE.
		$having = implode(
			' AND ',
			array(
				'1 = 1',
				$priority_ranks ? "severity_rank IN ({$rank_placeholders})" : '1 = 1',
			)
		);

		$count_values = array_merge( array( $table ), $values, $priority_ranks );

		$total_groups = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT scanner_id, category, object_type, MIN( CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 WHEN 'info' THEN 4 ELSE 5 END ) AS severity_rank FROM %i WHERE {$where} GROUP BY scanner_id, category, object_type ) grouped WHERE {$having}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
				...$count_values
			)
		);

		if ( 0 === $total_groups ) {
			return array(
				'data'  => array(),
				'total' => 0,
			);
		}

		// Grouped by object_type too, since one scanner_id can report several unrelated finding types.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
				"SELECT * FROM ( SELECT scanner_id, category, object_type, COUNT(*) AS count, MIN( CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 WHEN 'info' THEN 4 ELSE 5 END ) AS severity_rank FROM %i WHERE {$where} GROUP BY scanner_id, category, object_type ) grouped WHERE {$having} ORDER BY severity_rank ASC, count DESC, scanner_id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
				...array_merge( array( $table ), $values, $priority_ranks, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$severity_by_rank = array(
			0 => 'critical',
			1 => 'high',
			2 => 'medium',
			3 => 'low',
			4 => 'info',
			5 => 'info',
		);

		return array(
			'data'  => array_map(
				static function ( array $row ) use ( $severity_by_rank ): array {
					return array(
						'scanner_id'  => (string) $row['scanner_id'],
						'category'    => (string) $row['category'],
						'count'       => (int) $row['count'],
						'severity'    => $severity_by_rank[ (int) $row['severity_rank'] ] ?? 'info',
						'object_type' => '' !== (string) $row['object_type'] ? (string) $row['object_type'] : null,
					);
				},
				null !== $rows ? $rows : array()
			),
			'total' => $total_groups,
		);
	}

	/**
	 * Counts open findings by severity across every scan.
	 *
	 * @param string $severity One of Severity's constants.
	 * @return int
	 */
	public function count_by_severity( string $severity ): int {
		global $wpdb;

		return (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE severity = %s AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_table(),
				$severity
			)
		);
	}

	/**
	 * Counts open findings in one category.
	 *
	 * @param string $category One of the scanner category strings.
	 * @return int
	 */
	public function count_by_category( string $category ): int {
		global $wpdb;

		return (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE category = %s AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_table(),
				$category
			)
		);
	}

	/**
	 * Findings first detected on or after $since.
	 *
	 * @param string $since MySQL datetime (UTC), inclusive.
	 * @return int
	 */
	public function count_created_since( string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);
	}

	/**
	 * Findings resolved on or after $since.
	 *
	 * @param string $since MySQL datetime (UTC), inclusive.
	 * @return int
	 */
	public function count_resolved_since( string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE resolved_at >= %s',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			)
		);
	}

	/**
	 * Findings resolved within a bounded window, optionally scoped.
	 *
	 * @param string        $period_start MySQL datetime (UTC), inclusive.
	 * @param string        $period_end   MySQL datetime (UTC), inclusive.
	 * @param string|null   $category     One of the scanner category strings, or null for all.
	 * @param string[]|null $scanner_ids  Scanner ids to additionally scope to, or null for every scanner in $category.
	 * @return int
	 */
	public function count_resolved_between( string $period_start, string $period_end, ?string $category = null, ?array $scanner_ids = null ): int {
		global $wpdb;

		$scanner_ids = $scanner_ids ? $scanner_ids : array();
		$values      = array( $this->get_table(), $period_start, $period_end );

		if ( null !== $category ) {
			$values[] = $category;
		}

		array_push( $values, ...$scanner_ids );

		$scanner_placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

		$where = implode(
			' AND ',
			array(
				'resolved_at BETWEEN %s AND %s',
				null !== $category ? 'category = %s' : '1 = 1',
				$scanner_ids ? "scanner_id IN ({$scanner_placeholders})" : '1 = 1',
			)
		);

		return (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", ...$values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
		);
	}

	/**
	 * Open-finding counts by severity within a single category.
	 *
	 * @param string $category One of the scanner category strings.
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	public function get_severity_breakdown_for_category( string $category ): array {
		global $wpdb;

		$counts = array_fill_keys( array( 'critical', 'high', 'medium', 'low' ), 0 );

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT severity, COUNT(*) AS total FROM %i WHERE category = %s AND status = 'open' GROUP BY severity", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_table(),
				$category
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( $row['severity'], $counts ) ) {
				$counts[ $row['severity'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Same shape as get_severity_breakdown_for_category(), scoped to scanner ids.
	 *
	 * @param string[] $scanner_ids Scanner ids to scope to.
	 * @return array{critical: int, high: int, medium: int, low: int, info: int}
	 */
	public function get_severity_breakdown_for_scanner_ids( array $scanner_ids ): array {
		global $wpdb;

		$counts = array_fill_keys( array( 'critical', 'high', 'medium', 'low', 'info' ), 0 );

		if ( ! $scanner_ids ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
				"SELECT severity, COUNT(*) AS total FROM %i WHERE scanner_id IN ({$placeholders}) AND status = 'open' GROUP BY severity", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders' %s count matches $scanner_ids' size at runtime.
				$this->get_table(),
				...$scanner_ids
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( $row['severity'], $counts ) ) {
				$counts[ $row['severity'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Same shape as get_severity_breakdown_for_scanner_ids(), scoped to one post.
	 *
	 * @param string[] $scanner_ids Scanner ids to scope to.
	 * @param int      $post_id    Post id to scope to.
	 * @return array{critical: int, high: int, medium: int, low: int, info: int}
	 */
	public function get_severity_breakdown_for_scanner_ids_by_post_id( array $scanner_ids, int $post_id ): array {
		global $wpdb;

		$counts = array_fill_keys( array( 'critical', 'high', 'medium', 'low', 'info' ), 0 );

		if ( ! $scanner_ids ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- {$this->get_table()} is this plugin's own table name, not user input.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the placeholder count varies at runtime with $scanner_ids' size, every value is still a bound placeholder.
				"SELECT severity, COUNT(*) AS total FROM %i WHERE scanner_id IN ({$placeholders}) AND status = 'open' AND object_ref = %s GROUP BY severity", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders' %s count matches $scanner_ids' size at runtime.
				...array_merge( array( $this->get_table() ), $scanner_ids, array( (string) $post_id ) )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( $row['severity'], $counts ) ) {
				$counts[ $row['severity'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * @param string $category One of the scanner category strings.
	 * @param string $as_of    MySQL datetime (UTC) to reconstruct the open set as of.
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	public function get_severity_breakdown_for_category_as_of( string $category, string $as_of ): array {
		global $wpdb;

		$counts = array_fill_keys( array( 'critical', 'high', 'medium', 'low' ), 0 );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT severity, COUNT(*) AS total FROM %i
                WHERE category = %s AND status != 'ignored' AND status != 'snoozed'
                AND created_at <= %s AND ( status = 'open' OR ( resolved_at IS NOT NULL AND resolved_at > %s ) )
                GROUP BY severity",
				$this->get_table(),
				$category,
				$as_of,
				$as_of
			),
			ARRAY_A
		);
        // phpcs:enable

		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( $row['severity'], $counts ) ) {
				$counts[ $row['severity'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Same "as of a past moment" reconstruction as get_severity_breakdown_for_category_as_of().
	 *
	 * @param string[] $scanner_ids Scanner ids to scope to.
	 * @param string   $as_of       MySQL datetime (UTC) to reconstruct the open set as of.
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	public function get_severity_breakdown_for_scanner_ids_as_of( array $scanner_ids, string $as_of ): array {
		global $wpdb;

		$counts = array_fill_keys( array( 'critical', 'high', 'medium', 'low' ), 0 );

		if ( ! $scanner_ids ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT severity, COUNT(*) AS total FROM %i
                WHERE scanner_id IN ({$placeholders}) AND status != 'ignored' AND status != 'snoozed'
                AND created_at <= %s AND ( status = 'open' OR ( resolved_at IS NOT NULL AND resolved_at > %s ) )
                GROUP BY severity",
				$this->get_table(),
				...array_merge( $scanner_ids, array( $as_of, $as_of ) )
			),
			ARRAY_A
		);
        // phpcs:enable

		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( $row['severity'], $counts ) ) {
				$counts[ $row['severity'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Open findings among $scanner_ids tied to a page or post, bucketed by post id.
	 *
	 * @param string[]    $scanner_ids Scanner ids to scope to.
	 * @param string|null $as_of       MySQL datetime (UTC) to reconstruct as of; null for the current open set.
	 * @return array<int, array<int, array{id: int, title: string, severity: string}>> post_id => its open findings.
	 */
	public function get_open_findings_for_scanner_ids_by_post( array $scanner_ids, ?string $as_of = null ): array {
		global $wpdb;

		$buckets = array();

		if ( ! $scanner_ids ) {
			return $buckets;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( null === $as_of ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, severity, object_ref FROM %i
                    WHERE scanner_id IN ({$placeholders}) AND status = 'open' AND object_type = 'post'",
					$this->get_table(),
					...$scanner_ids
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, severity, object_ref FROM %i
                    WHERE scanner_id IN ({$placeholders}) AND object_type = 'post'
                    AND status != 'ignored' AND status != 'snoozed'
                    AND created_at <= %s AND ( status = 'open' OR ( resolved_at IS NOT NULL AND resolved_at > %s ) )",
					$this->get_table(),
					...array_merge( $scanner_ids, array( $as_of, $as_of ) )
				),
				ARRAY_A
			);
		}
        // phpcs:enable

		foreach ( (array) $rows as $row ) {
			$post_ids = array_filter(
				array_map( 'intval', explode( ',', (string) $row['object_ref'] ) ),
				static fn( $id ) => $id > 0
			);

			foreach ( $post_ids as $post_id ) {
				$buckets[ $post_id ][] = array(
					'id'       => (int) $row['id'],
					'title'    => $row['title'],
					'severity' => $row['severity'],
				);
			}
		}

		return $buckets;
	}

	/**
	 * Distinct objects with at least one currently-open finding among $scanner_ids.
	 *
	 * @param string[] $scanner_ids Scanner ids to scope to.
	 * @return int
	 */
	public function get_affected_object_count_for_scanner_ids( array $scanner_ids ): int {
		global $wpdb;

		if ( ! $scanner_ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

		return (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
				"SELECT COUNT(DISTINCT object_ref) FROM %i WHERE scanner_id IN ({$placeholders}) AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders' %s count matches $scanner_ids' size at runtime.
				$this->get_table(),
				...$scanner_ids
			)
		);
	}

	/**
	 * Aggregate finding counts for one date range, optionally narrowed.
	 *
	 * @param string        $period_start Y-m-d, inclusive.
	 * @param string        $period_end   Y-m-d, inclusive.
	 * @param string|null   $category     One of the scanner category strings, or null for all.
	 * @param string[]|null $scanner_ids  Scanner ids to additionally scope to, or null for every scanner in $category.
	 * @return array{total: int, by_severity: array<string, int>, by_category: array<string, int>, by_status: array<string, int>}
	 */
	public function get_stats_for_period( string $period_start, string $period_end, ?string $category = null, ?array $scanner_ids = null ): array {
		global $wpdb;

		$scanner_ids = $scanner_ids ? $scanner_ids : array();
		$values      = array( $period_start, $period_end );

		if ( null !== $category ) {
			$values[] = $category;
		}

		array_push( $values, ...$scanner_ids );

		$scanner_placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

		$where = implode(
			' AND ',
			array(
				'DATE(created_at) BETWEEN %s AND %s',
				null !== $category ? 'category = %s' : '1 = 1',
				$scanner_ids ? "scanner_id IN ({$scanner_placeholders})" : '1 = 1',
			)
		);

		$by_severity = array_fill_keys( array( 'critical', 'high', 'medium', 'low', 'info' ), 0 );

		$severity_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( "SELECT severity, COUNT(*) AS total FROM %i WHERE {$where} GROUP BY severity", $this->get_table(), ...$values ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where's %s count matches $values' size at runtime.
			ARRAY_A
		);

		foreach ( (array) $severity_rows as $row ) {
			if ( array_key_exists( $row['severity'], $by_severity ) ) {
				$by_severity[ $row['severity'] ] = (int) $row['total'];
			}
		}

		$by_category = array();

		if ( null === $category ) {
			$category_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
				$wpdb->prepare( "SELECT category, COUNT(*) AS total FROM %i WHERE {$where} GROUP BY category", $this->get_table(), ...$values ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
				ARRAY_A
			);

			foreach ( (array) $category_rows as $row ) {
				$by_category[ $row['category'] ] = (int) $row['total'];
			}
		}

		$status_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM %i WHERE {$where} GROUP BY status", $this->get_table(), ...$values ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
			ARRAY_A
		);

		$by_status = array();

		foreach ( (array) $status_rows as $row ) {
			$by_status[ $row['status'] ] = (int) $row['total'];
		}

		return array(
			'total'       => array_sum( $by_severity ),
			'by_severity' => $by_severity,
			'by_category' => $by_category,
			'by_status'   => $by_status,
		);
	}

	/**
	 * Every object_type/object_ref pair from one scan run.
	 *
	 * @param int $scan_id Scan id.
	 * @return array<int, array{object_type: string|null, object_ref: string|null}>
	 */
	public function get_object_refs_for_scan( int $scan_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				'SELECT object_type, object_ref FROM %i WHERE scan_id = %d LIMIT 2000',
				$this->get_table(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scan_id
			),
			ARRAY_A
		);

		return null !== $rows ? $rows : array();
	}

	/**
	 * The highest-severity currently-open findings, worst-first.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_open_findings( int $limit = 10 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT id, title, description, severity, category, created_at FROM %i WHERE status = 'open' ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info') ASC, created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->get_table(),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * The highest-severity findings opened in one date range, worst-first.
	 *
	 * @param string        $period_start Y-m-d, inclusive.
	 * @param string        $period_end   Y-m-d, inclusive.
	 * @param string|null   $category     One of the scanner category strings, or null for all.
	 * @param int           $limit        Max rows to return.
	 * @param string[]|null $scanner_ids  Scanner ids to additionally scope to.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_top_findings_for_period( string $period_start, string $period_end, ?string $category = null, int $limit = 10, ?array $scanner_ids = null ): array {
		global $wpdb;

		$scanner_ids = $scanner_ids ? $scanner_ids : array();
		$values      = array( $period_start, $period_end );

		if ( null !== $category ) {
			$values[] = $category;
		}

		array_push( $values, ...$scanner_ids );

		$scanner_placeholders = implode( ', ', array_fill( 0, count( $scanner_ids ), '%s' ) );

		$where = implode(
			' AND ',
			array(
				'DATE(created_at) BETWEEN %s AND %s',
				null !== $category ? 'category = %s' : '1 = 1',
				$scanner_ids ? "scanner_id IN ({$scanner_placeholders})" : '1 = 1',
			)
		);

		$values[] = max( 1, $limit );

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
			$wpdb->prepare(
				"SELECT id, title, severity, category, status, created_at FROM %i WHERE {$where} ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info') ASC, created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where's %s count matches $values' size at runtime.
				$this->get_table(),
				...$values
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
