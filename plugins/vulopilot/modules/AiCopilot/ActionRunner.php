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
use VuloPilot\AiCopilot\Services\AiCreditGatewayClient;
use VuloPilot\AiAssistant\AiCreditsConnection;
use VuloPilot\Utill\VuloPilotException;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates every AIAction through its full lifecycle - the same
 * orchestrator role Scanners\ScanRunner and RuleEngine\RuleEngine play
 * for their own engines, but split across four public methods instead of
 * one `run()`, because "Approval" (AI-ACTIONS.md's 5th lifecycle stage)
 * is a genuine pause: propose() and approve()/reject() are always two
 * separate HTTP requests, potentially made by two different people, with
 * a persisted `vulopilot_ai_action_runs` row bridging them.
 *
 * ```
 * propose()  Input → Prompt Builder → (AI call) → Validator → Preview   [persists: pending_approval]
 * approve()  Execution                                                  [persists: executed | failed]
 * reject()   (no-op besides recording the decision)                     [persists: rejected]
 * rollback() Rollback                                                   [persists: rolled_back]
 * ```
 *
 * Logging (stage 8) isn't a method on AIActionInterface - every
 * transition above writes to the existing ActivityLogRepository here,
 * once, rather than each action re-implementing its own audit trail.
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
    private AiCreditGatewayClient $credit_gateway;
    private AiCreditsConnection $credits_connection;

    /**
     * Real, structured `{action_id: [feature_id, action]}` mapping onto
     * VuloCloud's own ai-gateway feature catalog (architecture plan §C) -
     * every other AI action id sends its own built prompt instead (see
     * send_prompt_or_credits()). Both are charged in AI credits. Adding a feature to a future migration pass means adding one
     * entry here plus a matching build_credit_context() case, not
     * restructuring propose() itself.
     */
    private const CREDIT_FEATURE_MAP = array(
        'write-meta-title'              => array( 'seo_title', 'generate' ),
        'write-meta-description'        => array( 'meta_description', 'generate' ),
        'improve-readability'           => array( 'content_improvement', 'rewrite' ),
        'write-post-content'            => array( 'post_content', 'write' ),
        'generate-blog'                 => array( 'blog_post', 'generate' ),
        'differentiate-duplicate-title' => array( 'duplicate_title', 'differentiate' ),
    );


    /**
     * @param ActionRegistry             $registry           Registry to resolve action ids from.
     * @param AiRequestSender          $request_sender      Sends a prompt through the safety-validate → send → sanitize sequence.
     * @param ActionRunRepository|null   $runs           Defaults to a new instance (injectable for tests).
     * @param ActivityLogRepository|null $activity_logs Defaults to a new instance (injectable for tests).
     * @param AiCreditGatewayClient|null $credit_gateway Defaults to a new instance (injectable for tests).
     * @param AiCreditsConnection|null   $credits_connection Defaults to a new instance (injectable for tests).
     */
    public function __construct(
        ActionRegistry $registry,
        AiRequestSender $request_sender,
        ?ActionRunRepository $runs = null,
        ?ActivityLogRepository $activity_logs = null,
        ?AiCreditGatewayClient $credit_gateway = null,
        ?AiCreditsConnection $credits_connection = null
    ) {
        $this->registry           = $registry;
        $this->request_sender     = $request_sender;
        $this->runs               = $runs ?? new ActionRunRepository();
        $this->activity_logs      = $activity_logs ?? new ActivityLogRepository();
        $this->credits_connection = $credits_connection ?? new AiCreditsConnection();
        $this->credit_gateway     = $credit_gateway ?? new AiCreditGatewayClient( $this->credits_connection );
    }

    /**
     * Stages 1-4: validates input, builds and safety-checks the prompt,
     * sends it through the AI request sender, validates and
     * previews the result. Persists the outcome as a `pending_approval`
     * row - nothing about the site's actual content changes yet.
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
        $response = $this->send_prompt_or_credits( $action_id, $action, $input );

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
                // Leave it pending_approval - a human can still review and
                // approve it manually; an auto-approve failure shouldn't
                // lose the proposal itself.
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
     * Gets the AI completion for a proposed action. Both routes run on the
     * site's Organization's AI key on VuloCloud and are charged in the site
     * owner's AI credits for their real usage:
     *
     *  - an action in CREDIT_FEATURE_MAP goes through VuloCloud's structured
     *    feature catalog (`/plugin/ai/execute`, AiCreditGatewayClient) -
     *    VuloCloud owns that feature's prompt and model;
     *  - every other action sends its own built prompt
     *    (`/plugin/ai/prompt`, AiAssistant\AiRequestSender), labelled with
     *    the action's own name for the site owner's credit history.
     *
     * Either way, when the balance can't cover the request VuloCloud
     * refuses before calling the provider and this throws
     * TYPE_INSUFFICIENT_CREDITS - nothing is charged.
     *
     * @param string                             $action_id Real, registered action id.
     * @param \VuloPilot\Utill\AIActionInterface $action    Same instance get_action_or_fail() already resolved.
     * @param array                              $input     validate_input()'s own normalized output.
     * @return \VuloPilot\AiAssistant\AIResponse
     *
     * @throws VuloPilotException If the site owner's credits can't cover the request, or the AI request fails.
     * @throws \RuntimeException  If this site isn't connected to VuloCloud.
     */
    private function send_prompt_or_credits( string $action_id, $action, array $input ): AIResponse {
        if ( ! isset( self::CREDIT_FEATURE_MAP[ $action_id ] ) ) {
            return $this->request_sender->send( $action->build_prompt( $input ), null, 'ai_action', $action->get_label() );
        }

        if ( ! $this->credits_connection->is_connected() ) {
            throw new \RuntimeException( esc_html__( 'No AI connection is configured.', 'vulopilot' ) );
        }

        list( $feature_id, $credit_action ) = self::CREDIT_FEATURE_MAP[ $action_id ];
        $context = $this->build_credit_context( $action_id, $input );

        $result = $this->credit_gateway->execute( $feature_id, $credit_action, $context );

        if ( $result instanceof \WP_Error ) {
            throw new VuloPilotException( esc_html( $result->get_error_message() ), VuloPilotException::TYPE_GATEWAY_REQUEST );  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- false positive: the message argument is already esc_html()-wrapped; the other arguments are not output.
        }

        if ( empty( $result['success'] ) ) {
            $credit_context = array(
                'credits_remaining' => (float) ( $result['credits_remaining'] ?? 0 ),
                'can_buy_credits'   => (bool) ( $result['can_buy_credits'] ?? false ),
                'can_upgrade'       => (bool) ( $result['can_upgrade'] ?? false ),
                'buy_credits_url'   => esc_url_raw( (string) ( $result['buy_credits_url'] ?? '' ) ),
            );

            throw new VuloPilotException( esc_html__( 'You don’t have enough credits to complete this request.', 'vulopilot' ), VuloPilotException::TYPE_INSUFFICIENT_CREDITS, $credit_context );  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- false positive: the message argument is already esc_html()-wrapped; the other arguments are not output.
        }

        return new AIResponse( $result['response'], (float) ( $result['credits_used'] ?? 0 ), $result['request_id'] ?? null );
    }

    /**
     * The structured `context` payload for one of CREDIT_FEATURE_MAP's
     * three action ids - field names translated from each Action class's
     * own validate_input() output into the exact names VuloCloud's
     * matching feature-catalog entry expects (ai-feature-catalog.ts on the
     * vulocloud side) since they don't always match 1:1 (e.g.
     * WriteMetaTitleAction's own `previous_title` vs. the catalog's
     * `title`).
     *
     * @param string $action_id Real, registered action id - always one of CREDIT_FEATURE_MAP's own keys.
     * @param array  $input     validate_input()'s own normalized output for that same action.
     * @return array<string, mixed>
     */
    private function build_credit_context( string $action_id, array $input ): array {
        switch ( $action_id ) {
            case 'write-meta-title':
                return array(
                    'title'   => $input['previous_title'],
                    'content' => $input['content'],
                );
            case 'write-meta-description':
                return array(
                    'title'   => $input['title'],
                    'content' => $input['content'],
                );
            case 'improve-readability':
                return array(
                    'content' => $input['original_content'],
                    'goal'    => 'improve readability',
                );
            case 'write-post-content':
                return array(
                    'title'   => $input['post_title'],
                    'brief'   => $input['brief'],
                    'content' => $input['previous_content'],
                );
            case 'generate-blog':
                return array(
                    'topic'      => $input['topic'],
                    'word_count' => $input['word_count'],
                    'tone'       => $input['tone'],
                );
            case 'differentiate-duplicate-title':
                return array(
                    'title'         => $input['previous_title'],
                    'content'       => $input['content'],
                    'sibling_count' => count( $input['sibling_titles'] ),
                );
            default:
                return array();
        }
    }

    /**
     * @param string $risk_level One of Impact::LOW/MEDIUM/HIGH - the
     *                           proposal's own AIActionInterface::get_risk_level().
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
     * Stage 6: applies a previously proposed, still-pending action.
     *
     * @param int    $run_id A propose()-returned run_id.
     * @param string $method 'manual' (a human clicked Approve - the only way
     *                       this was ever called before Automate Work's
     *                       Auto-fix mode and Approval Settings' risk-based/
     *                       "Do not ask" modes), 'auto_automation'
     *                       (an automation calling this
     *                       immediately after propose(), gated on Automate
     *                       Work's own automation_mode setting), or
     *                       'auto_unattended' (propose() calling this on
     *                       itself via should_auto_approve(), gated on
     *                       Approval Settings' ai_change_approval_mode
     *                       setting) - the latter two both mean no human
     *                       was involved at all: `approved_by` is left null
     *                       rather than attributing it to whichever user id
     *                       happens to own the request context, and
     *                       `approval_method` is persisted with its exact
     *                       value so History can honestly say *which* of
     *                       the two unattended paths actually applied the
     *                       change, instead of a single generic "auto"
     *                       that would imply automation even when nothing
     *                       automated was involved.
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
     * Stage 5's negative outcome - declines a pending action without
     * ever calling execute().
     *
     * @param int $run_id A propose()-returned run_id.
     * @return void
     *
     * @throws \RuntimeException If $run_id doesn't exist or isn't pending approval.
     */
    public function reject( int $run_id ): void {
        $run = $this->get_pending_run_or_fail( $run_id );

        $this->runs->update( $run_id, array( 'status' => 'rejected' ) );

        $this->log( $run_id, 'ai_action.rejected', Severity::INFO, sprintf( 'Action run #%d rejected.', $run_id ) );
    }

    /**
     * Stage 7: reverts a previously executed action.
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
