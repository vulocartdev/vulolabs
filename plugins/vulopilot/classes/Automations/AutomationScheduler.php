<?php
namespace VuloPilot\Automations;

use VuloPilot\Automations\BuiltinAutomationSeeder;
use VuloPilot\Automations\AutomationsRepository;
use VuloPilot\Automations\AutomationsRunRepository;
use VuloPilot\Utill\ScanRunner;

defined( 'ABSPATH' ) || exit;

/**
 * @class       AutomationScheduler class
 * @version     1.0.0
 * @author      VuloLabs
 */
class AutomationScheduler {

	private const SCAN_HOOK   = 'vulopilot_automation_full_site_scan_run';
	private const REPORT_HOOK = 'vulopilot_automation_visibility_report_run';

	/**
	 * @var ScanRunner
	 */
	private ScanRunner $scan_runner;

	/**
	 * @var AutomationsRepository
	 */
	private AutomationsRepository $automations;

	/**
	 * @var AutomationsRunRepository
	 */
	private AutomationsRunRepository $runs;

	/**
	 * @param ScanRunner                    $scan_runner Runs row 1's real action.
	 * @param AutomationsRepository|null    $automations Defaults to a new instance - injectable for tests.
	 * @param AutomationsRunRepository|null $runs        Defaults to a new instance - injectable for tests.
	 */
	public function __construct( ScanRunner $scan_runner, ?AutomationsRepository $automations = null, ?AutomationsRunRepository $runs = null ) {
		$this->scan_runner = $scan_runner;
		$this->automations = $automations ?? new AutomationsRepository();
		$this->runs        = $runs ?? new AutomationsRunRepository();

		add_filter( 'cron_schedules', array( $this, 'register_custom_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- registering real, standard 7/30-day intervals, not shorter-than-recommended ones.
		add_action( 'init', array( $this, 'ensure_scheduled' ), 30 );
		add_action( self::SCAN_HOOK, array( $this, 'run_scheduled_scan' ) );
		add_action( self::REPORT_HOOK, array( $this, 'run_scheduled_report' ) );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules Real, currently-registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function register_custom_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'vulopilot' ),
			);
		}

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'vulopilot' ),
			);
		}

		return $schedules;
	}

	/**
	 * Reconciles both real scheduled cron events against their own
	 * automation row's current `status`/`trigger_config`.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$scan_row = $this->automations->find_by_system_default_marker( BuiltinAutomationSeeder::MARKER_FULL_SITE_SCAN );

		if ( $scan_row ) {
			$this->reconcile_row( $scan_row, self::SCAN_HOOK, array( 'daily', 'weekly', 'monthly' ) );
		}

		$report_row = $this->automations->find_by_system_default_marker( BuiltinAutomationSeeder::MARKER_VISIBILITY_REPORT );

		if ( $report_row ) {
			$this->reconcile_row( $report_row, self::REPORT_HOOK, array( 'weekly', 'monthly' ) );
		}
	}

	/**
	 * @param array<string, mixed> $row               Real automation row.
	 * @param string               $hook              This row's own cron hook.
	 * @param string[]             $valid_frequencies Frequencies this row's own UI actually offers (excludes 'manual').
	 * @return void
	 */
	private function reconcile_row( array $row, string $hook, array $valid_frequencies ): void {
		$trigger_config = json_decode( (string) ( $row['trigger_config'] ?? '' ), true );
		$trigger_config = is_array( $trigger_config ) ? $trigger_config : array();

		$frequency   = (string) ( $trigger_config['frequency'] ?? 'disabled' );
		$day_of_week = max( 1, min( 7, absint( $trigger_config['day_of_week'] ?? 1 ) ) );
		$enabled     = 'enabled' === ( $row['status'] ?? '' );

		$scheduled = wp_get_scheduled_event( $hook );

		if ( ! $enabled || ! in_array( $frequency, $valid_frequencies, true ) ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( $hook );
			}

			return;
		}

		if ( $scheduled && $frequency === $scheduled->schedule ) {
			if ( 'weekly' !== $frequency || (int) gmdate( 'N', $scheduled->timestamp ) === $day_of_week ) {
				return;
			}
		}

		wp_clear_scheduled_hook( $hook );

		$start = 'weekly' === $frequency ? $this->next_weekday_timestamp( $day_of_week ) : time();

		wp_schedule_event( $start, $frequency, $hook );
	}

	/**
	 * @param int $day_of_week ISO-8601 weekday, 1 (Monday) through 7 (Sunday).
	 * @return int Unix timestamp for 03:00 UTC on the next occurrence of that weekday (today included).
	 */
	private function next_weekday_timestamp( int $day_of_week ): int {
		$now         = time();
		$current_iso = (int) gmdate( 'N', $now );
		$days_until  = ( $day_of_week - $current_iso + 7 ) % 7;
		$target_date = gmdate( 'Y-m-d', $now + ( $days_until * DAY_IN_SECONDS ) );

		return (int) strtotime( $target_date . ' 03:00:00 UTC' );
	}

	/**
	 * Row 1's real cron tick - runs every scanner and records a run row.
	 *
	 * @return void
	 */
	public function run_scheduled_scan(): void {
		$row = $this->automations->find_by_system_default_marker( BuiltinAutomationSeeder::MARKER_FULL_SITE_SCAN );

		if ( ! $row ) {
			return;
		}

		$started_at = current_time( 'mysql', true );

		try {
			$this->scan_runner->run_all();
			$this->record_run( (int) $row['id'], 'completed', $started_at );
		} catch ( \Throwable $exception ) {
			$this->record_run( (int) $row['id'], 'failed', $started_at, $exception->getMessage() );
		}
	}

	/**
	 * @return void
	 */
	public function run_scheduled_report(): void {
		$row = $this->automations->find_by_system_default_marker( BuiltinAutomationSeeder::MARKER_VISIBILITY_REPORT );

		if ( ! $row ) {
			return;
		}

		$trigger_config = json_decode( (string) ( $row['trigger_config'] ?? '' ), true );
		$frequency      = is_array( $trigger_config ) ? (string) ( $trigger_config['frequency'] ?? 'weekly' ) : 'weekly';
		$started_at     = current_time( 'mysql', true );

		try {
			/**
			 * Sends the scheduled visibility report email. Returns null when no
			 * extension provides report delivery.
			 *
			 * @param bool|null $sent      Whether the email was sent.
			 * @param string    $frequency Configured frequency.
			 */
			$sent = apply_filters( 'vulopilot_send_scheduled_visibility_report', null, $frequency );

			if ( null === $sent ) {
				$this->record_run( (int) $row['id'], 'failed', $started_at, __( 'No report delivery extension is active.', 'vulopilot' ) );
				return;
			}

			$this->record_run( (int) $row['id'], $sent ? 'completed' : 'failed', $started_at, $sent ? '' : __( 'No recipient configured, or the report failed to generate.', 'vulopilot' ) );
		} catch ( \Throwable $exception ) {
			$this->record_run( (int) $row['id'], 'failed', $started_at, $exception->getMessage() );
		}
	}

	/**
	 * @param int    $automation_id Real automation row id.
	 * @param string $status        'completed'|'failed'.
	 * @param string $started_at    Real MySQL datetime this run started at.
	 * @param string $error         Real error message, empty when $status is 'completed'.
	 * @return void
	 */
	private function record_run( int $automation_id, string $status, string $started_at, string $error = '' ): void {
		$finished_at = current_time( 'mysql', true );

		$this->runs->insert(
			array(
				'automation_id'    => $automation_id,
				'triggered_by'     => 'scheduled',
				'trigger_ref_id'   => null,
				'status'           => $status,
				'actions_executed' => 'completed' === $status ? 1 : 0,
				'actions_failed'   => 'failed' === $status ? 1 : 0,
				'changes_made'     => 0,
				'result_log'       => $error ? wp_json_encode( array( 'error' => $error ) ) : null,
				'started_at'       => $started_at,
				'finished_at'      => $finished_at,
			)
		);

		$this->automations->update( $automation_id, array( 'last_triggered_at' => $finished_at ) );
	}
}
