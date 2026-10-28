<?php
namespace VuloPilot\Reports\Rest;

use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\Dashboard\ActivityLogRepository;
use VuloPilot\AiAssistant\AiHistoryRepository;
use VuloPilot\Utill\FindingRepository;
use VuloPilot\Utill\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the AI Copilot page's History tab: a day-groupable activity timeline scoped to `scan.completed`/`ai_action.*`/conversation events, each enriched from its source table.
 *
 * @class       History controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class History extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'history';

	/**
	 * @var array<string, string[]>
	 */
	private const EVENT_TYPES_BY_CATEGORY = array(
		'scan'   => array( 'scan.completed', 'scan.completed.security' ),
		'change' => array(
			'ai_action.proposed',
			'ai_action.executed',
			'ai_action.failed',
			'ai_action.rejected',
			'ai_action.rolled_back',
		),
	);

	/**
	 * Window for matching an AI action to the conversation turn that triggered it.
	 */
	private const RELATED_ACTION_WINDOW_SECONDS = 30;

	/**
	 * @inheritDoc
	 */
	public function register_routes() {
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		// Zyra's sendApiResponse() always issues a plain POST regardless of semantic intent.
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @inheritDoc
	 */
	public function delete_item_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_items( $request ) {
		$repository   = new ActivityLogRepository();
		$history_repo = new AiHistoryRepository();
		$category     = sanitize_key( (string) $request->get_param( 'type' ) );
		$page         = absint( $request->get_param( 'page' ) );
		$per_page     = absint( $request->get_param( 'per_page' ) );

		// 'automations' is a filter pill the client always sends, but has no backing data.
		if ( 'automations' === $category ) {
			return rest_ensure_response(
				array(
					'data'        => array(),
					'total'       => 0,
					'type_counts' => $this->get_type_counts( $repository, $history_repo ),
				)
			);
		}

		// 'conversation' reads from a distinct source table (vulopilot_ai_history).
		if ( 'conversation' === $category ) {
			$result = $history_repo->get_conversations(
				array(
					'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'date_from' => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
					'date_to'   => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
					'page'      => $page > 0 ? $page : 1,
					'per_page'  => $per_page > 0 ? $per_page : 20,
				)
			);

			$result['data']        = array_map( array( $this, 'enrich_conversation_row' ), $result['data'] );
			$result['type_counts'] = $this->get_type_counts( $repository, $history_repo );

			return rest_ensure_response( $result );
		}

		// A single category filters activity_logs alone; 'all' also needs conversations, a separate table.
		if ( isset( self::EVENT_TYPES_BY_CATEGORY[ $category ] ) ) {
			$result = $repository->get_timeline(
				array(
					'event_types' => self::EVENT_TYPES_BY_CATEGORY[ $category ],
					'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'date_from'   => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
					'date_to'     => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
					'page'        => $page > 0 ? $page : 1,
					'per_page'    => $per_page > 0 ? $per_page : 20,
					// A deep link's row id (`?vulopilot_history_id=`).
					'around_id'   => absint( $request->get_param( 'around_id' ) ),
				)
			);

			$result['data']        = array_map( array( $this, 'enrich_row' ), $result['data'] );
			$result['type_counts'] = $this->get_type_counts( $repository, $history_repo );

			return rest_ensure_response( $result );
		}

		$result = $this->get_combined_timeline(
			array(
				'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'date_from' => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
				'date_to'   => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
				'page'      => $page > 0 ? $page : 1,
				'per_page'  => $per_page > 0 ? $per_page : 20,
				'around_id' => absint( $request->get_param( 'around_id' ) ),
			)
		);

		$result['type_counts'] = $this->get_type_counts( $repository, $history_repo );

		return rest_ensure_response( $result );
	}

	/**
	 * The 'all' tab's timeline: activity_logs and ai_history merged via UNION ALL and
	 * ordered by created_at, so pagination stays correct across both sources.
	 *
	 * @param array{search?: string, date_from?: string, date_to?: string, page?: int, per_page?: int, around_id?: int} $args
	 * @return array{data: array<int, array<string, mixed>>, total: int, pages_loaded: int}
	 */
	private function get_combined_timeline( array $args ): array {
		global $wpdb;

		$log_table     = $wpdb->prefix . \VuloPilot\Utill::TABLES['activity_log'];
		$ai_table      = $wpdb->prefix . \VuloPilot\Utill::TABLES['ai_history'];
		$event_types   = array_merge( ...array_values( self::EVENT_TYPES_BY_CATEGORY ) );
		$chat_surfaces = array( 'copilot_chat', 'content_assistant_chat' );

		$log_where  = implode(
			' AND ',
			array(
				'event_type IN (' . implode( ', ', array_fill( 0, count( $event_types ), '%s' ) ) . ')',
				! empty( $args['search'] ) ? 'message LIKE %s' : '1 = 1',
				! empty( $args['date_from'] ) ? 'DATE(created_at) >= %s' : '1 = 1',
				! empty( $args['date_to'] ) ? 'DATE(created_at) <= %s' : '1 = 1',
			)
		);
		$log_values = $event_types;
		if ( ! empty( $args['search'] ) ) {
			$log_values[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['date_from'] ) ) {
			$log_values[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$log_values[] = (string) $args['date_to'];
		}

		$ai_where  = implode(
			' AND ',
			array(
				'surface IN (' . implode( ', ', array_fill( 0, count( $chat_surfaces ), '%s' ) ) . ')',
				! empty( $args['search'] ) ? '(prompt_excerpt LIKE %s OR response_excerpt LIKE %s)' : '1 = 1',
				! empty( $args['date_from'] ) ? 'DATE(created_at) >= %s' : '1 = 1',
				! empty( $args['date_to'] ) ? 'DATE(created_at) <= %s' : '1 = 1',
			)
		);
		$ai_values = $chat_surfaces;
		if ( ! empty( $args['search'] ) ) {
			$like        = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$ai_values[] = $like;
			$ai_values[] = $like;
		}
		if ( ! empty( $args['date_from'] ) ) {
			$ai_values[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$ai_values[] = (string) $args['date_to'];
		}

		$total_log = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$log_where}", $log_table, ...$log_values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
		);
		$total_ai  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$ai_where}", $ai_table, ...$ai_values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the table and optional filters are picked from fixed literals and every value is a bound placeholder; only the placeholder count varies at runtime.
		);
		$total     = $total_log + $total_ai;

		if ( 0 === $total ) {
			return array(
				'data'         => array(),
				'total'        => 0,
				'pages_loaded' => 1,
			);
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );

		$pages_loaded = $page;
		$around_id    = absint( $args['around_id'] ?? 0 );

		// A deep link always points at an activity_logs row. Count how many rows across
		// both sources are newer and jump straight to that page.
		if ( $around_id > 0 ) {
			$target_created_at = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
				$wpdb->prepare( "SELECT created_at FROM %i WHERE {$log_where} AND id = %d", $log_table, ...array_merge( $log_values, array( $around_id ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
			);

			$limit  = $per_page;
			$offset = ( $page - 1 ) * $per_page;

			if ( $target_created_at ) {
				$newer_log       = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
					$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$log_where} AND ( created_at > %s OR ( created_at = %s AND id > %d ) )", $log_table, ...array_merge( $log_values, array( $target_created_at, $target_created_at, $around_id ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
				);
				$newer_ai        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
					$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$ai_where} AND created_at > %s", $ai_table, ...array_merge( $ai_values, array( $target_created_at ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
				);
				$newer_rows      = $newer_log + $newer_ai;
				$pages_to_target = (int) floor( $newer_rows / $per_page ) + 1;

				if ( $pages_to_target > 0 && $pages_to_target * $per_page <= 1000 ) {
					$limit        = $pages_to_target * $per_page;
					$offset       = 0;
					$pages_loaded = $pages_to_target;
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled entirely from the two
			// prepared fragments above; no raw request input reaches this string.
			$union_sql = $wpdb->prepare( "(SELECT id, created_at, 'log' AS src FROM %i WHERE {$log_where})", $log_table, ...$log_values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
				. ' UNION ALL '
				. $wpdb->prepare( "(SELECT id, created_at, 'ai' AS src FROM %i WHERE {$ai_where})", $ai_table, ...$ai_values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.

			$ids = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the two halves above are each individually prepared; this only orders/limits their already-safe union.
				$wpdb->prepare( "SELECT * FROM ({$union_sql}) combined ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $union_sql is built entirely from the two prepared fragments above.
				ARRAY_A
			);

			$data = $this->hydrate_combined_rows( (array) $ids, $log_table, $ai_table );

			return array(
				'data'         => $data,
				'total'        => $total,
				'pages_loaded' => $pages_loaded,
			);
		}

		// Reserve a small per-page quota for conversations, since a single scan can log
		// many rows and would otherwise bury conversations for pages at a time.
		$conversation_quota = max( 1, (int) round( $per_page * 0.2 ) );

		$ai_offset     = 0;
		$log_offset    = 0;
		$ai_remaining  = $total_ai;
		$log_remaining = $total_log;

		for ( $seek_page = 1; $seek_page < $page; $seek_page++ ) {
			[ $seek_ai_take, $seek_log_take ] = $this->split_page_quota( $per_page, $conversation_quota, $ai_remaining, $log_remaining );
			$ai_offset                       += $seek_ai_take;
			$log_offset                      += $seek_log_take;
			$ai_remaining                    -= $seek_ai_take;
			$log_remaining                   -= $seek_log_take;
		}

		[ $ai_take, $log_take ] = $this->split_page_quota( $per_page, $conversation_quota, $ai_remaining, $log_remaining );

		$page_rows = array();

		if ( $ai_take > 0 ) {
			$page_rows = array_merge(
				$page_rows,
				(array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
					$wpdb->prepare( "SELECT *, 'ai' AS src FROM %i WHERE {$ai_where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $ai_table, ...array_merge( $ai_values, array( $ai_take, $ai_offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
					ARRAY_A
				)
			);
		}

		if ( $log_take > 0 ) {
			$page_rows = array_merge(
				$page_rows,
				(array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
					$wpdb->prepare( "SELECT *, 'log' AS src FROM %i WHERE {$log_where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $log_table, ...array_merge( $log_values, array( $log_take, $log_offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
					ARRAY_A
				)
			);
		}

		// Both slices are independently newest-first; interleave them back into one
		// newest-first page so day-grouping and read order stay sensible.
		usort(
			$page_rows,
			static function ( $a, $b ) {
				return $b['created_at'] <=> $a['created_at'] ?: $b['id'] <=> $a['id'];
			}
		);

		$data = array();

		foreach ( $page_rows as $row ) {
			$data[] = 'ai' === $row['src'] ? $this->enrich_conversation_row( $row ) : $this->enrich_row( $row );
		}

		return array(
			'data'         => $data,
			'total'        => $total,
			'pages_loaded' => $pages_loaded,
		);
	}

	/**
	 * One page's worth of (ai_take, log_take) under get_combined_timeline()'s quota. Gives
	 * leftover slots to whichever source isn't exhausted.
	 *
	 * @param int $per_page           Rows per page.
	 * @param int $conversation_quota This page's conversation reservation, before checking what's left.
	 * @param int $ai_remaining       Conversation rows not yet handed out to an earlier page.
	 * @param int $log_remaining      Log rows not yet handed out to an earlier page.
	 * @return array{0: int, 1: int} [ai_take, log_take].
	 */
	private function split_page_quota( int $per_page, int $conversation_quota, int $ai_remaining, int $log_remaining ): array {
		$ai_take  = min( $conversation_quota, $ai_remaining );
		$log_take = min( $per_page - $ai_take, $log_remaining );

		// The log ran dry before filling the page - hand its unused slots to conversations.
		if ( $ai_take + $log_take < $per_page && $ai_remaining > $ai_take ) {
			$ai_take = min( $ai_take + ( $per_page - $ai_take - $log_take ), $ai_remaining );
		}

		return array( $ai_take, $log_take );
	}

	/**
	 * Turns get_combined_timeline()'s around_id branch's (id, src) pairs back into full,
	 * enriched rows, in the same order.
	 *
	 * @param array<int, array{id: string, src: string}> $ordered
	 * @param string                                     $log_table
	 * @param string                                     $ai_table
	 * @return array<int, array<string, mixed>>
	 */
	private function hydrate_combined_rows( array $ordered, string $log_table, string $ai_table ): array {
		global $wpdb;

		if ( ! $ordered ) {
			return array();
		}

		$log_ids = array();
		$ai_ids  = array();

		foreach ( $ordered as $row ) {
			if ( 'log' === $row['src'] ) {
				$log_ids[] = (int) $row['id'];
			} else {
				$ai_ids[] = (int) $row['id'];
			}
		}

		$log_rows_by_id = array();
		if ( $log_ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $log_ids ), '%d' ) );
			$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
				$wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders})", $log_table, ...$log_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$log_rows_by_id[ (int) $row['id'] ] = $this->enrich_row( $row );
			}
		}

		$ai_rows_by_id = array();
		if ( $ai_ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ai_ids ), '%d' ) );
			$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the query is prepared.
				$wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders})", $ai_table, ...$ai_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$ai_rows_by_id[ (int) $row['id'] ] = $this->enrich_conversation_row( $row );
			}
		}

		$data = array();

		foreach ( $ordered as $row ) {
			$id       = (int) $row['id'];
			$enriched = 'log' === $row['src'] ? ( $log_rows_by_id[ $id ] ?? null ) : ( $ai_rows_by_id[ $id ] ?? null );

			if ( $enriched ) {
				$data[] = $enriched;
			}
		}

		return $data;
	}

	/**
	 * @inheritDoc
	 */
	public function delete_item( $request ) {
		$repository = new ActivityLogRepository();
		$id         = absint( $request->get_param( 'id' ) );

		if ( ! $repository->delete( $id ) ) {
			return new \WP_Error( 'vulopilot_history_delete_failed', __( 'Could not delete this history entry.', 'vulopilot' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Bucket-sums count_by_column('event_type')'s raw per-event-type counts into the 2
	 * activity_logs-backed category counts.
	 *
	 * @param ActivityLogRepository $repository   Repository to count scan/change from.
	 * @param AiHistoryRepository   $history_repo Repository to count conversations from.
	 * @return array{all: int, scan: int, change: int, conversation: int, automations: int}
	 */
	private function get_type_counts( ActivityLogRepository $repository, AiHistoryRepository $history_repo ): array {
		$raw = $repository->count_by_column( 'event_type' );

		$counts = array(
			'scan'         => 0,
			'change'       => 0,
			'conversation' => $history_repo->get_conversation_count(),
			'automations'  => 0,
		);

		foreach ( self::EVENT_TYPES_BY_CATEGORY as $category => $event_types ) {
			foreach ( $event_types as $event_type ) {
				$counts[ $category ] += $raw[ $event_type ] ?? 0;
			}
		}

		$counts = array( 'all' => array_sum( $counts ) ) + $counts;

		return $counts;
	}

	/**
	 * Adds a real `conversation` sub-object to one `vulopilot_ai_history`
	 * row.
	 *
	 * @param array<string, mixed> $row One vulopilot_ai_history row.
	 * @return array<string, mixed>
	 */
	private function enrich_conversation_row( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'event_type'   => 'success' === $row['status'] ? 'ai_history.success' : 'ai_history.failure',
			'category'     => 'conversation',
			'message'      => (string) ( $row['response_excerpt'] ?? '' ),
			'severity'     => 'success' === $row['status'] ? 'info' : 'high',
			'created_at'   => $row['created_at'],
			'scan'         => null,
			'change'       => null,
			'conversation' => array(
				'id'              => (int) $row['id'],
				'credits_used'    => isset( $row['credits_used'] ) ? round( (float) $row['credits_used'], 3 ) : null,
				'request_id'      => $row['request_id'] ?? null,
				'status'          => $row['status'],
				'excerpt'         => $row['response_excerpt'],
				'prompt_excerpt'  => $row['prompt_excerpt'] ?? null,
				'related_actions' => $this->build_related_actions( $row ),
			),
		);
	}

	/**
	 * Real `ai_action.*` rows this conversation turn caused, if any.
	 *
	 * @param array<string, mixed> $row One vulopilot_ai_history row.
	 * @return array<int, array{id: int, label: string, created_at: string}>
	 */
	private function build_related_actions( array $row ): array {
		$requested_by = isset( $row['requested_by'] ) ? (int) $row['requested_by'] : 0;

		if ( $requested_by <= 0 ) {
			return array();
		}

		$candidates = ( new ActivityLogRepository() )->find_actions_in_window(
			self::EVENT_TYPES_BY_CATEGORY['change'],
			(string) $row['created_at'],
			self::RELATED_ACTION_WINDOW_SECONDS
		);

		$related = array();

		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['object_id'] ) ) {
				continue;
			}

			$run = ( new ActionRunRepository() )->find( (int) $candidate['object_id'] );

			if ( ! $run || (int) ( $run['requested_by'] ?? 0 ) !== $requested_by ) {
				continue;
			}

			$action = VuloPilot()->ai_action_registry->get_action( $run['action_id'] );

			$related[] = array(
				'id'         => (int) $candidate['id'],
				'label'      => $action ? $action->get_label() : $run['action_id'],
				'created_at' => $candidate['created_at'],
			);
		}

		return $related;
	}

	/**
	 * Adds a real `scan` or `change` sub-object to one activity_logs row,
	 * joined back to its source table by `object_id`.
	 *
	 * @param array<string, mixed> $row One vulopilot_activity_logs row.
	 * @return array<string, mixed>
	 */
	private function enrich_row( array $row ): array {
		$row['category'] = 0 === strpos( (string) $row['event_type'], 'scan.' ) ? 'scan' : 'change';
		$row['scan']     = null;
		$row['change']   = null;

		if ( 'scan' === $row['category'] && ! empty( $row['object_id'] ) ) {
			$row['scan'] = $this->build_scan_detail( (int) $row['object_id'] );
		} elseif ( 'change' === $row['category'] && ! empty( $row['object_id'] ) ) {
			$row['change'] = $this->build_change_detail( (int) $row['object_id'] );
		}

		return $row;
	}

	/**
	 * Real detail for one scan.completed timeline row, joined back to its
	 * vulopilot_scans source row.
	 *
	 * @param int $scan_id vulopilot_scans.id.
	 * @return array<string, mixed>|null Null if the source scan row is gone.
	 */
	private function build_scan_detail( int $scan_id ): ?array {
		$scan = ( new ScanRepository() )->find( $scan_id );

		if ( ! $scan ) {
			return null;
		}

		$scanner = VuloPilot()->scanner_registry->get_scanner( $scan['scanner_id'] );
		$summary = json_decode( (string) $scan['summary'], true );
		$summary = is_array( $summary ) ? $summary : array();
		$total   = $summary['total'] ?? 0;

		return array(
			'id'             => (int) $scan['id'],
			'scanner_id'     => $scan['scanner_id'],
			'label'          => $scanner ? $scanner->get_label() : $scan['scanner_id'],
			'status'         => $scan['status'],
			'trigger_type'   => $scan['trigger_type'],
			'duration_ms'    => null !== $scan['duration_ms'] ? (int) $scan['duration_ms'] : null,
			'by_severity'    => $summary['by_severity'] ?? array(),
			'total'          => $total,
			'affected_pages' => $this->build_affected_pages( $scan_id ),
			'scanned_pages'  => 0 === $total ? $this->build_scanned_pages( $scan ) : array(),
		);
	}

	/**
	 * Real pages/posts a scan considered but found nothing wrong with.
	 *
	 * @param array<string, mixed> $scan One vulopilot_scans row.
	 * @return array<int, array{id: int, title: string, link: string|null, edit_link: string}>
	 */
	private function build_scanned_pages( array $scan ): array {
		$post_ids = json_decode( (string) ( $scan['scanned_objects'] ?? '' ), true );

		if ( ! is_array( $post_ids ) || ! $post_ids ) {
			return array();
		}

		$pages = array();

		foreach ( array_unique( array_map( 'absint', $post_ids ) ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$permalink = get_permalink( $post );

			$pages[] = array(
				'id'        => $post_id,
				'title'     => get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'vulopilot' ),
				'link'      => $permalink ? wp_make_link_relative( $permalink ) : null,
				'edit_link' => admin_url( "post.php?post={$post_id}&action=edit" ),
			);
		}

		usort( $pages, static fn( array $a, array $b ): int => strcasecmp( $a['title'], $b['title'] ) );

		return $pages;
	}

	/**
	 * Real pages/posts this scan run found an issue on.
	 *
	 * @param int $scan_id vulopilot_scans.id.
	 * @return array<int, array{id: int, title: string, link: string|null, edit_link: string|null, count: int}>
	 */
	private function build_affected_pages( int $scan_id ): array {
		$refs = ( new FindingRepository() )->get_object_refs_for_scan( $scan_id );

		$counts_by_post_id = array();
		$site_wide_count   = 0;

		foreach ( $refs as $ref ) {
			$post_ids = $this->extract_post_ids( $ref['object_type'] ?? null, $ref['object_ref'] ?? null );

			if ( ! $post_ids ) {
				++$site_wide_count;
				continue;
			}

			foreach ( $post_ids as $post_id ) {
				$counts_by_post_id[ $post_id ] = ( $counts_by_post_id[ $post_id ] ?? 0 ) + 1;
			}
		}

		$pages = array();

		foreach ( $counts_by_post_id as $post_id => $count ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$permalink = get_permalink( $post );

			$pages[] = array(
				'id'        => $post_id,
				'title'     => get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'vulopilot' ),
				'link'      => $permalink ? wp_make_link_relative( $permalink ) : null,
				'edit_link' => admin_url( "post.php?post={$post_id}&action=edit" ),
				'count'     => $count,
			);
		}

		usort( $pages, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		if ( $site_wide_count > 0 ) {
			$pages[] = array(
				'id'        => 0,
				'title'     => __( 'Site-wide', 'vulopilot' ),
				'link'      => null,
				'edit_link' => null,
				'count'     => $site_wide_count,
			);
		}

		return $pages;
	}

	/**
	 * Real numeric post ids one finding's `object_type`/`object_ref` points at - empty for
	 * anything not page/post-scoped (a URL/site-wide finding).
	 *
	 * @param string|null $object_type e.g. 'post'.
	 * @param string|null $object_ref  A post id, or a comma-joined list of post ids.
	 * @return array<int, int>
	 */
	private function extract_post_ids( ?string $object_type, ?string $object_ref ): array {
		if ( 'post' !== $object_type || null === $object_ref || '' === $object_ref ) {
			return array();
		}

		$ids = array_filter(
			array_map( 'absint', explode( ',', $object_ref ) )
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Real detail for one ai_action.* timeline row, joined back to its
	 * vulopilot_ai_action_runs source row.
	 *
	 * @param int $run_id vulopilot_ai_action_runs.id.
	 * @return array<string, mixed>|null Null if the source run row is gone.
	 */
	private function build_change_detail( int $run_id ): ?array {
		$run = ( new ActionRunRepository() )->find( $run_id );

		if ( ! $run ) {
			return null;
		}

		$action  = VuloPilot()->ai_action_registry->get_action( $run['action_id'] );
		$preview = json_decode( (string) $run['preview'], true );
		$preview = is_array( $preview ) ? $preview : array();

		return array(
			'id'              => (int) $run['id'],
			'action_id'       => $run['action_id'],
			'label'           => $action ? $action->get_label() : $run['action_id'],
			'status'          => $run['status'],
			'before'          => $preview['before'] ?? null,
			'after'           => $preview['after'] ?? null,
			'format'          => $preview['format'] ?? 'text',
			'error_message'   => $run['error_message'],
			'page'            => $this->resolve_page_link( $run['object_type'] ?? null, $run['object_ref'] ?? null ),
			'approval_method' => $run['approval_method'] ?? 'manual',
		);
	}

	/**
	 * Same "post permalink, else site-wide" resolution Findings.php's own
	 * add_page_field() uses.
	 *
	 * @param string|null $object_type e.g. 'post'.
	 * @param string|null $object_ref  Post id, as a string.
	 * @return string|null
	 */
	private function resolve_page_link( ?string $object_type, ?string $object_ref ): ?string {
		if ( null === $object_type || null === $object_ref ) {
			return null;
		}

		if ( 'post' === $object_type && is_numeric( $object_ref ) ) {
			$permalink = get_permalink( (int) $object_ref );

			return $permalink ? wp_make_link_relative( $permalink ) : __( 'Site-wide', 'vulopilot' );
		}

		return __( 'Site-wide', 'vulopilot' );
	}
}
