<?php
/**
 * ContentCreationOrchestrator class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot;

use VuloPilot\Utill\VuloPilotException;
use VuloPilot\AiAssistant\AIResponse;

defined( 'ABSPATH' ) || exit;

/**
 * The shared "parse an orchestrator's JSON decision, then really create the
 * content" half of what used to be Controllers\ContentAssistant.php alone.
 * Extracted so a second chat surface (Controllers\Copilot.php's own AI
 * Copilot Chat tab) can offer the exact same real "write a blog"/"create a
 * landing page"/"create a product description" capability without a second,
 * drifting copy of this logic - both controllers still build their own
 * system prompt/AI request (their personas and grounding differ), they only
 * share what happens *after* the AI replies with a decision.
 *
 * @class       ContentCreationOrchestrator class
 * @version     1.0.0
 * @author      VuloLabs
 */
class ContentCreationOrchestrator {

    /**
     * The only 3 AIActions a free-text chat message alone can legitimately
     * trigger - the ones that create a brand-new post from scratch
     * (`GenerateBlogAction`/`GenerateLandingPageAction`/
     * `GenerateProductDescriptionAction`), never one that mutates an
     * *existing* post/attachment neither chat surface has a picker for.
     * Also described (fields and all) in each caller's own system prompt -
     * keep the two in sync by hand, the same way GeoAnalysis\GeoAnalyzer's
     * prompt and its own parse_response() stay in sync. `action_id` here is
     * the whitelist itself: an `action_id` the AI returns that isn't a key
     * of this array is never trusted, regardless of what the model claims.
     */
    public const CONTENT_CREATION_ACTIONS = array(
        'generate-blog'                => array(
            'noun'       => 'blog post',
            'link_label' => 'View/Edit Blog',
        ),
    );

    /**
     * Parses an orchestrator's JSON reply into a decision a caller can
     * safely act on. `action_id` is checked against
     * CONTENT_CREATION_ACTIONS's own whitelist rather than trusted
     * verbatim - the AI is never allowed to pick an action outside the 3
     * safe, no-existing-post-required ones this orchestrator is scoped to.
     * Anything unparseable (not JSON, missing status, an unrecognized
     * action_id) degrades to a plain "respond" using whatever text the
     * model actually returned, rather than failing the whole turn - the
     * same "don't lose a usable reply over a formatting slip" posture
     * GeoAnalyzer's own parse_response() takes.
     *
     * @param AIResponse $response Raw orchestrator response.
     * @return array{status: string, message: string}|array{status: 'ready_action', action_id: string, input: array<string, mixed>}
     */
    public function parse_response( AIResponse $response ): array {
        $raw     = trim( $response->get_content() );
        $content = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $raw );
        $decoded = json_decode( trim( (string) $content ), true );

        if ( ! is_array( $decoded ) || empty( $decoded['status'] ) ) {
            return array(
                'status'  => 'respond',
                'message' => '' !== $raw ? $raw : __( "Sorry, I didn't quite catch that - could you rephrase?", 'vulopilot' ),
            );
        }

        if ( 'ready_action' === $decoded['status'] ) {
            $action_id = sanitize_key( (string) ( $decoded['action_id'] ?? '' ) );

            if ( isset( self::CONTENT_CREATION_ACTIONS[ $action_id ] ) ) {
                return array(
                    'status'    => 'ready_action',
                    'action_id' => $action_id,
                    'input'     => is_array( $decoded['input'] ?? null ) ? $decoded['input'] : array(),
                );
            }

            // An action_id outside the whitelist (hallucinated, or one of
            // the existing-post-only actions) is never executed - ask for
            // more detail instead of guessing what was meant.
            return array(
                'status'  => 'respond',
                'message' => __( "I couldn't quite tell what to create - could you tell me a bit more about what you'd like?", 'vulopilot' ),
            );
        }

        $message = trim( (string) ( $decoded['message'] ?? '' ) );

        return array(
            'status'  => 'question' === $decoded['status'] ? 'question' : 'respond',
            'message' => '' !== $message ? $message : __( 'Could you tell me a bit more about what you need?', 'vulopilot' ),
        );
    }

    /**
     * Runs a whitelisted CONTENT_CREATION_ACTIONS entry through the real
     * AIAction lifecycle end to end - propose() (AI call, exactly like
     * ContentToolsGrid.tsx's own tiles trigger) immediately followed by
     * approve() (auto-approving since the conversation itself IS the
     * user's approval, the same way clicking a tool tile and submitting
     * its form is) - and turns the result into a real, generic chat-reply
     * shape: a short success line plus a real, clickable edit link, never
     * the raw generated body text. $decision['input'] is AI-supplied, so it
     * is not trusted directly - the target action's own validate_input()
     * (already sanitizing/validating every field) is what actually decides
     * whether it's usable, the same safety net a human-submitted
     * ContentToolsGrid.tsx form goes through.
     *
     * @param array{action_id: string, input: array<string, mixed>} $decision parse_response()'s "ready_action" return value.
     * @return array{content: string, link: array{url: string, label: string}|null, run_id: int}|\WP_Error
     */
    public function create_content_and_respond( array $decision ) {
        try {
            $proposal = VuloPilot()->ai_action_runner->propose( $decision['action_id'], $decision['input'] );
        } catch ( \InvalidArgumentException $exception ) {
            return new \WP_Error( 'vulopilot_ai_action_invalid', $exception->getMessage(), array( 'status' => 400 ) );
        } catch ( VuloPilotException $exception ) {
            if ( VuloPilotException::TYPE_INVALID_ACTION_INPUT === $exception->get_type() ) {
                return new \WP_Error( 'vulopilot_ai_action_invalid_input', $exception->getMessage(), array( 'status' => 400 ) );
            } elseif ( VuloPilotException::TYPE_INVALID_ACTION_OUTPUT === $exception->get_type() ) {
                return new \WP_Error( 'vulopilot_ai_action_invalid_output', $exception->getMessage(), array( 'status' => 502 ) );
            } elseif ( VuloPilotException::TYPE_UNSAFE_PROMPT === $exception->get_type() ) {
                return new \WP_Error( 'vulopilot_unsafe_prompt', $exception->getMessage(), array( 'status' => 400 ) );
            } elseif ( VuloPilotException::TYPE_INSUFFICIENT_CREDITS === $exception->get_type() ) {
                return $exception->to_insufficient_credits_error();
            } elseif ( $exception->is_ai_request_failure() ) {
                return new \WP_Error( 'vulopilot_ai_request_error', $exception->getMessage(), array( 'status' => 502 ) );
            }

            return new \WP_Error( 'vulopilot_ai_request_error', $exception->getMessage(), array( 'status' => 502 ) );
        } catch ( \RuntimeException $exception ) {
            return new \WP_Error(
                'vulopilot_ai_not_connected',
                sprintf(
                    /* translators: %s is the exception's own real message, e.g. "No AI connection is configured." */
                    __( '%s Connect this site to VuloCloud in Settings → Connections.', 'vulopilot' ),
                    $exception->getMessage()
                ),
                array( 'status' => 400 )
            );
        }

        try {
            $result = VuloPilot()->ai_action_runner->approve( $proposal['run_id'] );
        } catch ( \RuntimeException $exception ) {
            return new \WP_Error( 'vulopilot_ai_action_not_pending', $exception->getMessage(), array( 'status' => 409 ) );
        } catch ( \InvalidArgumentException $exception ) {
            return new \WP_Error( 'vulopilot_ai_action_invalid', $exception->getMessage(), array( 'status' => 400 ) );
        }

        if ( empty( $result['success'] ) ) {
            return new \WP_Error(
                'vulopilot_ai_action_execution_failed',
                $result['message'] ?? __( 'The content was generated but could not be saved.', 'vulopilot' ),
                array( 'status' => 502 )
            );
        }

        $meta      = self::CONTENT_CREATION_ACTIONS[ $decision['action_id'] ];
        $edit_link = get_edit_post_link( (int) $result['object_ref'], 'raw' );

        return array(
            'content' => sprintf(
                /* translators: %s is a content type, e.g. "blog post". */
                __( 'Your %s has been created successfully:', 'vulopilot' ),
                $meta['noun']
            ),
            'link'    => $edit_link ? array(
                'url'   => $edit_link,
                'label' => $meta['link_label'],
            ) : null,
            'run_id'  => (int) $proposal['run_id'],
        );
    }
}
