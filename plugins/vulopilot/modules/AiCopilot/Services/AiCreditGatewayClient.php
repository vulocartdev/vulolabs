<?php
/**
 * AiCreditGatewayClient class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot\Services;

use VuloPilot\AiAssistant\AiCreditsConnection;

defined( 'ABSPATH' ) || exit;

/**
 * The structured credits-metered AI call -
 * `POST /plugin/ai/execute` against VuloCloud's own
 * `contexts/vulopilot/ai-gateway` (architecture plan §8/§9/§10): sends a
 * STRUCTURED `{ featureId, action, context }` payload, never a built
 * prompt - VuloCloud's own feature catalog owns the actual system
 * prompt/model/provider choice, this site never sees or influences it.
 *
 * Deliberately its own class, separate from AiCreditsConnection (which
 * only talks to the `ai-credits` context's connect/balance endpoints) -
 * this is a different bounded context on the vulocloud side and a
 * different real caller here (AiCopilot\ActionRunner, its only real
 * caller - hence living here rather than classes/AiAssistant/ alongside
 * AiCreditsConnection, which stays there as genuinely shared core, read
 * by AiAssistant\Rest\AiCredits.php/FrontendScripts.php too, not just this
 * module).
 *
 * @class       AiCreditGatewayClient class
 * @version     1.0.0
 * @author      VuloLabs
 */
class AiCreditGatewayClient {

    private AiCreditsConnection $credits;

    public function __construct( ?AiCreditsConnection $credits = null ) {
        $this->credits = $credits ?? new AiCreditsConnection();
    }

    /**
     * @param string               $feature_id e.g. 'seo_title'.
     * @param string               $action     e.g. 'generate'.
     * @param array<string, mixed> $context    Structured feature input - see
     *                                          each AiCopilot\Actions\* class's
     *                                          own credit-context mapping in
     *                                          AiCopilot\ActionRunner.
     * @param string|null          $request_id Idempotency key (a fresh one when null) - resending the same id is never charged twice.
     * @return array{success: true, request_id: string, credits_used: float, credits_remaining: float, response: string}|array{success: false, error: string, credits_remaining: float, can_buy_credits: bool, can_upgrade: bool, buy_credits_url: string}|\WP_Error {
     *   A \WP_Error only for a genuine connectivity/configuration failure
     *   (not connected, network unreachable, malformed response) - every
     *   OTHER outcome (including "insufficient credits" and any
     *   VuloCloud-side DomainError, e.g. an unknown feature) comes back as
     *   a plain array so AiCopilot\ActionRunner's own credits branch can
     *   handle "insufficient_credits" as a real, structured, user-facing
     *   outcome (VuloPilot brief §15) rather than an exception.
     * }
     */
    public function execute( string $feature_id, string $action, array $context, ?string $request_id = null ) {
        if ( '' === trim( VULOPILOT_VULOCLOUD_URL ) ) {
            return new \WP_Error( 'vulopilot_ai_credits_not_configured', __( 'VuloCloud isn’t configured for this build yet.', 'vulopilot' ), array( 'status' => 400 ) );
        }

        $credential = $this->credits->get_site_credential();

        if ( ! $credential ) {
            return new \WP_Error( 'vulopilot_ai_credits_not_connected', __( 'This site is not connected to VuloCloud AI Credits.', 'vulopilot' ), array( 'status' => 400 ) );
        }

        $response = wp_remote_post(
            untrailingslashit( VULOPILOT_VULOCLOUD_URL ) . '/plugin/ai/execute',
            array(
                // Real provider latency lives on VuloCloud's side of this
                // call - long enough that a slow completion doesn't time
                // out here before VuloCloud's own response comes back.
                'timeout' => 60,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode(
                    array(
                        'siteId'    => $credential['site_id'],
                        'secret'    => $credential['secret'],
                        'featureId' => $feature_id,
                        'action'    => $action,
                        'context'   => $context,
                        'requestId' => $request_id ?? 'wp_' . str_replace( '-', '', wp_generate_uuid4() ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            // VuloPilot brief §27 - never destroy local state or deduct
            // credits locally on a request that couldn't be confirmed;
            // this simply surfaces the honest "VuloCloud unreachable"
            // outcome to the caller.
            return new \WP_Error(
                'vulopilot_ai_credits_unreachable',
                sprintf(
                    /* translators: %s: underlying error message. */
                    __( 'Could not reach VuloCloud: %s', 'vulopilot' ),
                    $response->get_error_message()
                ),
                array( 'status' => 503 )
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'vulopilot_ai_credits_unparseable_response', __( 'VuloCloud returned an unexpected response.', 'vulopilot' ), array( 'status' => 502 ) );
        }

        // A structured {success:false, error:'insufficient_credits', ...}
        // body (VuloPilot brief §15) always comes back HTTP 200 - see the
        // vulocloud-side controller's own docblock on why that specific
        // failure isn't a generic API error. Any other non-2xx status
        // (site not found, feature not found, provider failure, rate
        // limited) is a real DomainError envelope from VuloCloud's shared
        // exception filter - never expose its internal `error`/`message`
        // verbatim to the end user (VuloPilot brief §19/§26); translate to
        // one honest, generic message here instead.
        if ( $status < 200 || $status >= 300 ) {
            return new \WP_Error(
                'vulopilot_ai_credits_gateway_error',
                __( 'VuloCloud could not process this AI request right now.', 'vulopilot' ),
                array( 'status' => $status )
            );
        }

        if ( empty( $body['success'] ) ) {
            // Refused before the provider was called - nothing charged.
            $this->credits->record_known_balance( (float) ( $body['creditsRemaining'] ?? 0 ) );

            return array(
                'success'            => false,
                'error'              => (string) ( $body['error'] ?? 'unknown_error' ),
                'credits_remaining'  => (float) ( $body['creditsRemaining'] ?? 0 ),
                'can_buy_credits'    => (bool) ( $body['canBuyCredits'] ?? false ),
                'can_upgrade'        => (bool) ( $body['canUpgrade'] ?? false ),
                'buy_credits_url'   => esc_url_raw( (string) ( $body['buyCreditsUrl'] ?? '' ) ),
            );
        }

        // Real spend just happened - update the local cache immediately
        // with VuloCloud's own authoritative post-spend balance (see
        // AiCreditsConnection::record_known_balance()'s own docblock on
        // why this is still a cache write, not an independent deduction).
        $this->credits->record_known_balance( (float) ( $body['creditsRemaining'] ?? 0 ) );

        return array(
            'success'            => true,
            'request_id'         => (string) ( $body['requestId'] ?? '' ),
            'credits_used'       => (float) ( $body['creditsUsed'] ?? 0 ),
            'credits_remaining'  => (float) ( $body['creditsRemaining'] ?? 0 ),
            'response'           => (string) ( $body['response'] ?? '' ),
        );
    }
}
