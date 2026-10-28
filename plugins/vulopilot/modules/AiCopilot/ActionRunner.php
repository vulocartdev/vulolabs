<?php
/**
 * ActionRunner class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot;

use VuloPilot\Utill\Severity;
use VuloPilot\Utill\Impact;
use VuloPilot\AiAssistant\AIResponse;
use VuloPilot\AiAssistant\AiRequestSender;
use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\Dashboard\ActivityLogRepository;
use VuloPilot\Utill\CloudAIActionInterface;
use VuloPilot\Utill\VuloPilotException;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates every AIAction through its full lifecycle.
 *
 * @class       ActionRunner class
 * @version     1.0.0
 * @author      VuloLabs
 */
class ActionRunner {

	private ActionRegistry $registry;
	private AiRequestSender $request_sender;
	private ActionRunRepository $runs;
	private ActivityLogRepository $activity_logs;

	/**
	 * @param ActionRegistry             $registry       Action id registry.
	 * @param AiRequestSender            $request_sender Sends every action's AI request.
	 * @param ActionRunRepository|null   $runs           Defaults to a new instance.
	 * @param ActivityLogRepository|null $activity_logs  Defaults to a new instance.
	 */
	public function __construct(
		ActionRegistry $registry,
		AiRequestSender $request_sender,
		?ActionRunRepository $runs = null,
		?ActivityLogRepository $activity_logs = null
	) {
		$this->registry       = $registry;
		$this->request_sender = $request_sender;
		$this->runs           = $runs ?? new ActionRunRepository();
		$this->activity_logs  = $activity_logs ?? new ActivityLogRepository();
	}

	/**
	 * Validates input, sends the prompt, then validates and previews the result.
	 *
	 * @param string               $action_id  e.g. 'generate-alt'.
	 * @param array<string, mixed> $raw_input Raw input (REST params, or built from a Recommendation).
	 * @return array{run_id: int, preview: array<string, mixed>, auto_approved: bool, approval_method: string|null}
	 *
	 * @throws \InvalidArgumentException If $action_id isn't registered.
	 * @throws \RuntimeException         If no AI connection is configured.
	 */
	public function propose( string $action_id, array $raw_input ): array {
		$action = $this->get_action_or_fail( $action_id );

		$input    = $action->validate_input( $raw_input );
		$response = $this->send( $action, $input );

		$output = $action->parse_response( $response );
		$action->validate_output( $output, $input );

		$preview    = $action->build_preview( $output, $input );
		$risk_level = $action->get_risk_level();

		$run_id = $this->runs->insert(
			array(
				'action_id'    => $action_id,
				'status'       => 'pending_approval',
				'risk_level'   => $risk_level,
				'input'        => wp_json_encode( $input ),
				'output'       => wp_json_encode( $output ),
				'preview'      => wp_json_encode( $preview->to_array() ),
				'requested_by' => get_current_user_id(),
			)
		);

		$this->log( $run_id, 'ai_action.proposed', Severity::INFO, sprintf( '%s proposed: %s', $action->get_label(), $preview->get_summary() ) );

		$auto_approved   = false;
		$approval_method = null;

		if ( $this->should_auto_approve( $risk_level ) ) {
			try {
				$this->approve( $run_id, 'auto_unattended' );
				$auto_approved   = true;
				$approval_method = 'auto_unattended';
			} catch ( \Throwable $exception ) {
				// Leave it pending_approval so a human can still approve it manually.
				$auto_approved = false;
			}
		}

		return array(
			'run_id'          => $run_id,
			'preview'         => $preview->to_array(),
			'auto_approved'   => $auto_approved,
			'approval_method' => $approval_method,
		);
	}

	/**
	 * Gets the AI completion for a proposed action: the AI-service feature of a
	 * CloudAIActionInterface action, the action's own prompt otherwise (third-party actions).
	 *
	 * @param \VuloPilot\Utill\AIActionInterface $action Resolved action instance.
	 * @param array                              $input  Normalized input.
	 * @return AIResponse
	 *
	 * @throws VuloPilotException If credits can't cover the request, or the AI request fails.
	 * @throws \RuntimeException  If no AI connection is configured.
	 */
	private function send( $action, array $input ): AIResponse {
		if ( $action instanceof CloudAIActionInterface ) {
			list( $feature_id, $feature_action ) = $action->get_cloud_feature();

			return $this->request_sender->send_feature(
				$feature_id,
				$feature_action,
				$action->build_cloud_context( $input ),
				'ai_action',
				$action->get_label()
			);
		}

		return $this->request_sender->send( $action->build_prompt( $input ), null, 'ai_action', $action->get_label() );
	}

	/**
	 * @param string $risk_level One of Impact::LOW/MEDIUM/HIGH.
	 * @return bool
	 */
	private function should_auto_approve( string $risk_level ): bool {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$mode     = $settings['ai_change_approval_mode'] ?? 'always';

		if ( 'never' === $mode ) {
			return true;
		}

		if ( 'risk_based' === $mode ) {
			return Impact::LOW === $risk_level;
		}

		return false;
	}

	/**
	 * Applies a previously proposed, still-pending action.
	 *
	 * @param int    $run_id A propose()-returned run_id.
	 * @param string $method 'manual', 'auto_automation', or 'auto_unattended'. For the
	 *                       latter two, `approved_by` is left null since no human was
	 *                       involved, and the exact method is persisted for History.
	 * @return array<string, mixed> ActionExecutionResult::to_array().
	 *
	 * @throws \RuntimeException If $run_id doesn't exist or isn't pending approval.
	 */
	public function approve( int $run_id, string $method = 'manual' ): array {
		$run = $this->get_pending_run_or_fail( $run_id );

		$action = $this->get_action_or_fail( $run['action_id'] );
		$input  = json_decode( (string) $run['input'], true ) ?? array();
		$output = json_decode( (string) $run['output'], true ) ?? array();

		$result        = $action->execute( $output, $input );
		$is_unattended = 0 === strpos( $method, 'auto' );

		$this->runs->update(
			$run_id,
			array(
				'status'          => $result->is_success() ? 'executed' : 'failed',
				'object_type'     => $result->get_object_type(),
				'object_ref'      => $result->get_object_ref(),
				'snapshot'        => wp_json_encode( $result->get_snapshot() ),
				'error_message'   => $result->get_message(),
				'approved_by'     => $is_unattended ? null : get_current_user_id(),
				'approval_method' => $method,
				'approved_at'     => current_time( 'mysql', true ),
				'executed_at'     => $result->is_success() ? current_time( 'mysql', true ) : null,
			)
		);

		$this->log(
			$run_id,
			$result->is_success() ? 'ai_action.executed' : 'ai_action.failed',
			$result->is_success() ? Severity::INFO : Severity::HIGH,
			$result->is_success()
				? sprintf(
					'auto_automation' === $method
						? '%s auto-approved and executed by automation.'
						: ( 'auto_unattended' === $method
							? '%s applied automatically - no approval required by Approval Settings.'
							: '%s executed.' ),
					$action->get_label()
				)
				: sprintf( '%s failed: %s', $action->get_label(), $result->get_message() )
		);

		return $result->to_array();
	}

	/**
	 * Declines a pending action without calling execute().
	 *
	 * @param int $run_id A propose()-returned run_id.
	 * @return void
	 *
	 * @throws \RuntimeException If $run_id doesn't exist or isn't pending approval.
	 */
	public function reject( int $run_id ): void {
		$this->get_pending_run_or_fail( $run_id );

		$this->runs->update( $run_id, array( 'status' => 'rejected' ) );

		$this->log( $run_id, 'ai_action.rejected', Severity::INFO, sprintf( 'Action run #%d rejected.', $run_id ) );
	}

	/**
	 * Reverts a previously executed action.
	 *
	 * @param int $run_id A propose()-returned, subsequently approve()'d run_id.
	 * @return void
	 *
	 * @throws \RuntimeException If $run_id doesn't exist or wasn't successfully executed.
	 */
	public function rollback( int $run_id ): void {
		$run = $this->runs->find( $run_id );

		if ( ! $run || 'executed' !== $run['status'] ) {
			throw new \RuntimeException( esc_html__( 'This action run cannot be rolled back.', 'vulopilot' ) );
		}

		$action = $this->get_action_or_fail( $run['action_id'] );

		$action->rollback( json_decode( (string) $run['snapshot'], true ) ?? array() );

		$this->runs->update(
			$run_id,
			array(
				'status'         => 'rolled_back',
				'rolled_back_at' => current_time( 'mysql', true ),
			)
		);

		$this->log( $run_id, 'ai_action.rolled_back', Severity::MEDIUM, sprintf( '%s rolled back.', $action->get_label() ) );
	}

	/**
	 * @param string $action_id Action id to resolve.
	 * @return \VuloPilot\Utill\AIActionInterface
	 *
	 * @throws \InvalidArgumentException If unregistered.
	 */
	private function get_action_or_fail( string $action_id ) {
		$action = $this->registry->get_action( $action_id );

		if ( ! $action ) {
			throw new \InvalidArgumentException( sprintf( 'No AI action registered for "%s".', esc_html( $action_id ) ) );
		}

		return $action;
	}

	/**
	 * @param int $run_id Run id to fetch.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException If missing or not pending approval.
	 */
	private function get_pending_run_or_fail( int $run_id ): array {
		$run = $this->runs->find( $run_id );

		if ( ! $run || 'pending_approval' !== $run['status'] ) {
			throw new \RuntimeException( esc_html__( 'This action run is not awaiting approval.', 'vulopilot' ) );
		}

		return $run;
	}

	/**
	 * @param int    $run_id   Run id this event is about.
	 * @param string $event_type e.g. 'ai_action.executed'.
	 * @param string $severity One of Severity's constants.
	 * @param string $message  Human-readable description.
	 * @return void
	 */
	private function log( int $run_id, string $event_type, string $severity, string $message ): void {
		$this->activity_logs->log( $event_type, $message, $severity, 'user', 'ai_action_run', (string) $run_id );
	}
}
