<?php
/**
 * AiHistoryRepository class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiAssistant;
use VuloPilot\Utill\RepositoryUtil;


defined( 'ABSPATH' ) || exit;

/**
 * Persistence for vulopilot_ai_history (DATABASE.md).
 *
 * @class       AiHistoryRepository class
 * @version     1.0.0
 * @author      VuloLabs
 */
class AiHistoryRepository extends RepositoryUtil {

    /**
     * The only 2 `surface` values that represent a real chat turn a human
     * had with VuloPilot (Controllers\Copilot.php/ContentAssistant.php) -
     * every other real caller of `ai_request_sender`/`request_sender`
     * (AiCopilot\ActionRunner, GeoAnalysis\GeoAnalyzer,
     * ContentIntelligence\ContentAnalyzer) tags its own rows with its own
     * real feature label instead, so this is the whitelist
     * get_conversations() scopes to - kept in sync with AiRequestSender::send()'s own
     * `$surface` values by hand, the same way ContentCreationOrchestrator's
     * CONTENT_CREATION_ACTIONS is kept in sync with each chat controller's
     * own system prompt.
     */
    private const CHAT_SURFACES = array( 'copilot_chat', 'content_assistant_chat' );

    /**
     * @var string[]
     */
    protected array $filterable_columns = array( 'status', 'surface' );

    /**
     * @var string[]
     */
    protected array $searchable_columns = array( 'prompt_excerpt', 'response_excerpt' );

    /**
     * @inheritDoc
     */
    protected function get_table_key(): string {
        return 'ai_history';
    }

    /**
     * Success/failure counts, zero-filled - backs the AI Assistant table's
     * status-count pill bar (same reasoning as
     * AutomationsRepository::get_status_counts()).
     *
     * @return array{success: int, failure: int}
     */
    public function get_status_counts(): array {
        return array_merge(
            array(
                'success' => 0,
                'failure' => 0,
            ),
            $this->count_by_column( 'status' )
        );
    }

    /**
     * Call counts and real credits spent for one date range - what
     * Reports\Types\AiUsageReport's headline summary reads.
     *
     * @param string $period_start Y-m-d, inclusive.
     * @param string $period_end   Y-m-d, inclusive.
     * @return array{total_calls: int, successful_calls: int, failed_calls: int, credits_used: float}
     */
    public function get_stats_for_period( string $period_start, string $period_end ): array {
        global $wpdb;

        $row = $wpdb->get_row(  // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
            $wpdb->prepare(
                "SELECT COUNT(*) AS total_calls,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful_calls,
                        SUM(CASE WHEN status = 'failure' THEN 1 ELSE 0 END) AS failed_calls,
                        COALESCE(SUM(credits_used), 0) AS credits_used
                 FROM {$this->get_table()} WHERE DATE(created_at) BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $period_start,
                $period_end
            ),
            ARRAY_A
        );

        return array(
            'total_calls'      => (int) ( $row['total_calls'] ?? 0 ),
            'successful_calls' => (int) ( $row['successful_calls'] ?? 0 ),
            'failed_calls'     => (int) ( $row['failed_calls'] ?? 0 ),
            'credits_used'     => round( (float) ( $row['credits_used'] ?? 0 ), 3 ),
        );
    }

    /**
     * Paginated, searchable, date-ranged real chat turns - scoped to
     * CHAT_SURFACES so AI Copilot History's "Conversations" filter
     * (Controllers\History.php) shows real chat, not every other feature
     * that shares this same table. Same shape/pagination contract as
     * ActivityLogRepository::get_timeline() (its own docblock explains why
     * a dedicated method beats routing through find_all(): an explicit
     * allow-list alongside a separate free-text `search` needs both at
     * once, which find_all()'s generic filterable-column path can't do).
     *
     * @param array{search?: string, date_from?: string, date_to?: string, page?: int, per_page?: int} $args
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function get_conversations( array $args ): array {
        global $wpdb;
        $table = $this->get_table();

        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $placeholders = implode( ', ', array_fill( 0, count( self::CHAT_SURFACES ), '%s' ) );
        $where        = "WHERE surface IN ({$placeholders})";
        $values       = self::CHAT_SURFACES;

        if ( ! empty( $args['search'] ) ) {
            $where   .= ' AND (prompt_excerpt LIKE %s OR response_excerpt LIKE %s)';
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where   .= ' AND DATE(created_at) >= %s';
            $values[] = (string) $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where   .= ' AND DATE(created_at) <= %s';
            $values[] = (string) $args['date_to'];
        }

        $total = (int) $wpdb->get_var(  // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where's %s count matches $values' size at runtime.
        );

        if ( 0 === $total ) {
            return array(
                'data'  => array(),
                'total' => 0,
            );
        }

        $rows = $wpdb->get_results(  // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
            $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", ...array_merge( $values, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- same runtime-sized-array case as above.
            ARRAY_A
        );

        return array(
            'data'  => null !== $rows ? $rows : array(),
            'total' => $total,
        );
    }

    /**
     * Real conversation count for History's "Conversations" filter pill -
     * same CHAT_SURFACES scope as get_conversations() above.
     *
     * @return int
     */
    public function get_conversation_count(): int {
        global $wpdb;

        $placeholders = implode( ', ', array_fill( 0, count( self::CHAT_SURFACES ), '%s' ) );

        return (int) $wpdb->get_var(  // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching  -- {$this->get_table()}/$table-style variables here are always this plugin's own hardcoded table name(s), never user input; dynamic placeholder counts (IN (...) lists, optional WHERE fragments) are sized correctly at runtime, just not statically visible to this sniff.
            $wpdb->prepare( "SELECT COUNT(*) FROM {$this->get_table()} WHERE surface IN ({$placeholders})", ...self::CHAT_SURFACES ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder count matches CHAT_SURFACES' size, a fixed private const.
        );
    }

}
