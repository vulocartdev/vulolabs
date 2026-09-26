<?php
namespace VuloPilot\Reports\Rest;

use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\Dashboard\ActivityLogRepository;
use VuloPilot\AiAssistant\AiHistoryRepository;
use VuloPilot\Utill\FindingRepository;
use VuloPilot\Performance\PageSpeedRepository;
use VuloPilot\Utill\ScanRepository;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * GET /history backs the AI Copilot page's History tab (HistoryTab.tsx) -
 * a real, day-groupable activity timeline, distinct from `GET /activity-logs`
 * (ActivityLogs.php, Free, backs Reports > Activity's own flat, unfiltered
 * table): this endpoint scopes to only the event types AI Copilot's own
 * History is about (`scan.completed`/`ai_action.*` from
 * `vulopilot_activity_logs`), and enriches each row with real detail joined
 * back to its source table (`vulopilot_scans`/`vulopilot_ai_action_runs`).
 *
 * "Conversations" is a real category too, backed by a THIRD source table
 * (`vulopilot_ai_history`, via AiHistoryRepository::get_conversations()).
 *
 * "Automations" stays a real category filter the client always sends but
 * has no backing: `vulopilot_automations_runs` has no writer in this
 * codebase at all - it honestly returns zero rows rather than fabricating
 * any.
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
     * See ActivityLogRepository::find_actions_in_window()'s own docblock
     * for why this can be tight rather than a same-day heuristic.
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

        // Zyra's sendApiResponse() (src/services/useApiList.ts and every
        // other AI Copilot delete/apply action this session) always issues
        // a plain POST regardless of semantic intent - same reasoning
        // Findings.php's own class docblock gives for its own sub-routes -
        // so this accepts WP_REST_Server::EDITABLE (POST/PUT/PATCH/DELETE)
        // rather than a stricter DELETABLE-only registration that the real
        // client could never actually reach.
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

        // 'automations' is a real filter pill the client always sends, but
        // has no backing (see class docblock) - short-circuit to an
        // honest empty page rather than querying for an event_type
        // allow-list that can never match.
        if ( 'automations' === $category ) {
            return rest_ensure_response(
                array(
                    'data'        => array(),
                    'total'       => 0,
                    'type_counts' => $this->get_type_counts( $repository, $history_repo ),
                )
            );
        }

        // 'conversation' is a real filter pill too now, but a distinct
        // source table (`vulopilot_ai_history`, not `vulopilot_activity_logs`)
        // - deliberately NOT merged into the 'all'/scan/change timeline
        // below (that would need a real cross-source merge-sort across two
        // differently-shaped paginated queries); it only ever appears when
        // this exact category is requested, same as the client's own
        // filter pills already scope each request to one category.
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

        $event_types = isset( self::EVENT_TYPES_BY_CATEGORY[ $category ] )
            ? self::EVENT_TYPES_BY_CATEGORY[ $category ]
            : array_merge( ...array_values( self::EVENT_TYPES_BY_CATEGORY ) );

        $result = $repository->get_timeline(
            array(
                'event_types' => $event_types,
                'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
                'date_from'   => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
                'date_to'     => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
                'page'        => $page > 0 ? $page : 1,
                'per_page'    => $per_page > 0 ? $per_page : 20,
                // A deep link's row id (`?vulopilot_history_id=`) - the
                // timeline returns every row down through the page holding
                // it so the client can always find and highlight it, see
                // ActivityLogRepository::get_timeline().
                'around_id'   => absint( $request->get_param( 'around_id' ) ),
            )
        );

        $result['data']        = array_map( array( $this, 'enrich_row' ), $result['data'] );
        $result['type_counts'] = $this->get_type_counts( $repository, $history_repo );

        return rest_ensure_response( $result );
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
     * Bucket-sums count_by_column('event_type')'s raw per-event-type counts
     * into the 2 activity_logs-backed category counts, adds a real
     * conversation count from the separate ai_history source
     * (AiHistoryRepository::get_conversation_count()), and zero-fills
     * 'automations' - a real pill the client always renders, just always at
     * 0 today (see class docblock). 'all' is the real sum of the four.
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

            if ( ! $run || $requested_by !== (int) ( $run['requested_by'] ?? 0 ) ) {
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
                'title'     => get_the_title( $post ) ?: __( '(no title)', 'vulopilot' ),
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
                'title'     => get_the_title( $post ) ?: __( '(no title)', 'vulopilot' ),
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
     * Real numeric post ids one finding's `object_type`/`object_ref` points
     * at - empty for anything not page/post-scoped (a URL/site-wide
     * finding). `object_ref` is usually a single post id, but
     * DuplicateContentScanner writes a comma-joined list.
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
