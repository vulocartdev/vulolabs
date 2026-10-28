<?php
/**
 * ScanRunner class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * VuloPilot ScanRunner class.
 *
 * @class       ScanRunner class
 * @version     1.0.0
 * @author      VuloLabs
 */
class ScanRunner {

	private const QUEUE_OPTION = 'vulopilot_scan_queue';

	private const BATCH_HOOK = 'vulopilot_scan_process_batch';

	/** Elapsed-time budget per batch tick, in seconds - same shape and reasoning as BackupManager::BATCH_SECONDS_BUDGET: */
	private const BATCH_SECONDS_BUDGET = 20;

	/** Transient-based mutex name - WP-Cron has no built-in protection against the same due event firing from more than one overlapping loopback request (observed live: */
	private const LOCK_TRANSIENT = 'vulopilot_scan_batch_lock';

	/**
	 * @var ScannerRegistry
	 */
	private ScannerRegistry $registry;

	/**
	 * @param ScannerRegistry $registry Registry to pull scanners from.
	 */
	public function __construct( ScannerRegistry $registry ) {
		$this->registry = $registry;

		add_action( self::BATCH_HOOK, array( $this, 'process_batch' ) );
	}

	/**
	 * Queues the given scanners to run across one or more background batch ticks -
	 * never blocks the calling request. Mirrors BackupManager::start_backup()'s own
	 * queue-then-schedule shape.
	 *
	 * @param string[] $scanner_ids Scanner ids to run, in order.
	 * @param bool     $force       See run()'s own docblock.
	 * @return array{total: int} Real total scanner count now queued.
	 */
	public function run_queued( array $scanner_ids, bool $force = false ): array {
		$scanner_ids = array_values( array_unique( $scanner_ids ) );

		update_option(
			self::QUEUE_OPTION,
			array(
				'scanner_ids' => $scanner_ids,
				'total'       => count( $scanner_ids ),
				'force'       => $force,
			),
			false
		);

		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time(), self::BATCH_HOOK );
		}

		return array( 'total' => count( $scanner_ids ) );
	}

	/**
	 * Real status of the current (if any) queued run - polled by the frontend instead of
	 * the REST request blocking until every scanner finishes.
	 *
	 * @return array{running: bool, total: int, remaining: int}
	 */
	public function get_queue_status(): array {
		$queue = get_option( self::QUEUE_OPTION, null );

		if ( ! is_array( $queue ) || empty( $queue['scanner_ids'] ) ) {
			return array(
				'running'   => false,
				'total'     => 0,
				'remaining' => 0,
			);
		}

		return array(
			'running'   => true,
			'total'     => (int) ( $queue['total'] ?? count( $queue['scanner_ids'] ) ),
			'remaining' => count( $queue['scanner_ids'] ),
		);
	}

	/**
	 * Runs as many queued scanners as fit in `BATCH_SECONDS_BUDGET`, then either
	 * reschedules itself or clears the queue once it's drained.
	 *
	 * @return void
	 */
	public function process_batch(): void {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::BATCH_SECONDS_BUDGET + 30 );

		$queue = get_option( self::QUEUE_OPTION, null );

		if ( ! is_array( $queue ) || empty( $queue['scanner_ids'] ) ) {
			delete_transient( self::LOCK_TRANSIENT );
			return;
		}

		$scanner_ids = $queue['scanner_ids'];
		$force       = ! empty( $queue['force'] );
		$started_at  = microtime( true );

		// Progress is persisted after every scanner, not only once the loop ends - so a
		// tick that dies partway through (fatal error, process kill) still leaves the
		// queue advanced past whatever it already ran, instead of re-running it next tick.
		while ( $scanner_ids && ( microtime( true ) - $started_at ) < self::BATCH_SECONDS_BUDGET ) {
			$this->run( array_shift( $scanner_ids ), $force );

			$queue['scanner_ids'] = $scanner_ids;
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		delete_transient( self::LOCK_TRANSIENT );

		if ( $scanner_ids ) {
			wp_schedule_single_event( time() + 1, self::BATCH_HOOK );
			return;
		}

		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Runs a single scanner by id.
	 *
	 * @param string $scanner_id A scanner's get_id().
	 * @param bool   $force      True for a user-initiated "Run scan" - forces a scanner
	 *                           implementing SupportsForceRunInterface to bypass its own rate limit.
	 * @return ScanResult|null Null if no scanner is registered under that id.
	 */
	public function run( string $scanner_id, bool $force = false ): ?ScanResult {
		$scanner = $this->registry->get_scanner( $scanner_id );

		if ( ! $scanner ) {
			return null;
		}

		if ( $force && $scanner instanceof SupportsForceRunInterface ) {
			$scanner->set_force_run( true );
		}

		$started_at = microtime( true );

		try {
			$findings = $scanner->scan();
			$result   = new ScanResult(
				$scanner_id,
				ScanResult::STATUS_COMPLETED,
				$findings,
				( microtime( true ) - $started_at ) * 1000,
				null,
				$scanner instanceof TracksScannedObjectsInterface ? $scanner->get_scanned_post_ids() : array()
			);
		} catch ( \Throwable $exception ) {
			$result = new ScanResult(
				$scanner_id,
				ScanResult::STATUS_FAILED,
				array(),
				( microtime( true ) - $started_at ) * 1000,
				$exception->getMessage()
			);
		}

		do_action( 'vulopilot_scan_completed', $result );

		return $result;
	}

	/**
	 * Runs every registered scanner.
	 *
	 * @param bool $force See run()'s own docblock.
	 * @return array<string, ScanResult> Keyed by scanner id.
	 */
	public function run_all( bool $force = false ): array {
		$results = array();

		foreach ( array_keys( $this->registry->get_all_scanners() ) as $scanner_id ) {
			$results[ $scanner_id ] = $this->run( $scanner_id, $force );
		}

		return $results;
	}

	/**
	 * Runs every registered scanner except those in the given categories.
	 *
	 * @param string[] $excluded_categories Category strings to leave out.
	 * @param bool     $force                See run()'s own docblock.
	 * @return array<string, ScanResult> Keyed by scanner id.
	 */
	public function run_all_except( array $excluded_categories, bool $force = false ): array {
		$results = array();

		foreach ( array_keys( $this->registry->get_all_scanners_except( $excluded_categories ) ) as $scanner_id ) {
			$results[ $scanner_id ] = $this->run( $scanner_id, $force );
		}

		return $results;
	}

	/**
	 * Runs every scanner registered under a given category.
	 *
	 * @param string $category e.g. 'seo', 'security'.
	 * @param bool   $force    See run()'s own docblock.
	 * @return array<string, ScanResult> Keyed by scanner id.
	 */
	public function run_category( string $category, bool $force = false ): array {
		$results = array();

		foreach ( array_keys( $this->registry->get_scanners_by_category( $category ) ) as $scanner_id ) {
			$results[ $scanner_id ] = $this->run( $scanner_id, $force );
		}

		return $results;
	}
}
