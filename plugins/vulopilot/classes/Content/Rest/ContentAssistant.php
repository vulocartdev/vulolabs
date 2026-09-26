<?php
namespace VuloPilot\Content\Rest;

use VuloPilot\AiCopilot\ContentCreationOrchestrator;
use VuloPilot\Utill\VuloPilotException;
use VuloPilot\GeoAnalysis\Rest\Geo;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /content-assistant/chat` - the conversational turn for "Create
 * Content"'s AI Content Assistant sidebar
 * (src/pages/Content/AiContentAssistantSidebar.tsx). Every message goes
 * through one "orchestrator" AI call (build_orchestrator_messages()) that
 * decides, per turn, whether to ask one more clarifying question, answer
 * directly, or hand off to a real AIAction - never a second, separate
 * "chit-chat" code path. Reuses VuloPilot()->ai_request_sender
 * (AI\AiRequestSender, already wired in
 * VuloPilot::init_classes() for AiCopilot\ActionRunner and
 * Geo\GeoAnalyzer) for that call - the same safety-validate → send →
 * sanitize sequence, and every call is automatically recorded to
 * `vulopilot_ai_history` by AI\AiRequestSender itself, so this controller
 * doesn't do any logging of its own.
 *
 * Once the orchestrator decides it has enough information, it hands off
 * to the exact same real AIAction ContentToolsGrid.tsx's own tiles run -
 * `generate-blog`
 * (VuloPilot()->ai_action_runner, AI-ACTIONS.md's propose→approve
 * lifecycle) - auto-approving immediately, since the conversation itself
 * IS the user's approval, the same way clicking a tool tile and
 * submitting its form is. Only this action qualifies: every other
 * AIAction (FAQ, meta title, schema, alt text, …) mutates an *existing*
 * post/attachment this chat has no picker for, so a request that doesn't
 * match it is written directly in the reply instead (the
 * orchestrator's "respond" status) - real generated content, just never
 * claimed to be saved anywhere, since nothing was.
 *
 * There is deliberately no separate slot-filling state machine: the only
 * conversation state is the same plain `history` array the client already
 * round-trips (AiContentAssistantSidebar.tsx's own `turns`) - the
 * orchestrator re-derives "what's already been answered" from that
 * transcript on every call, the same way a human reading the thread back
 * would, rather than this controller tracking parallel structured state.
 *
 * @class       ContentAssistant controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class ContentAssistant extends \WP_REST_Controller {

    /**
     * REST base for this controller's routes.
     *
     * @var string
     */
    protected $rest_base = 'content-assistant';

    /**
     * How many prior turns of client-supplied history to include - bounds
     * the prompt sent to the AI service on a long-running chat.
     */
    private const MAX_HISTORY_MESSAGES = 20;

    /**
     * The shared "parse the orchestrator's decision, then really create the
     * content" logic - see ContentCreationOrchestrator's own docblock for
     * why this is no longer implemented in this controller directly
     * (Controllers\Copilot.php's own AI Copilot Chat tab now reuses it too).
     *
     * @var ContentCreationOrchestrator
     */
    private ContentCreationOrchestrator $orchestrator;

    /**
     * ContentAssistant constructor.
     */
    public function __construct() {
        $this->orchestrator = new ContentCreationOrchestrator();
    }

    /**
     * Registers POST /content-assistant/chat.
     *
     * @inheritDoc
     */
    public function register_routes() {
        register_rest_route(
            VuloPilot()->rest_namespace,
            '/' . $this->rest_base . '/chat',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Same manage_options gate every other VuloPilot REST route uses, plus
     * the real AI Copilot module check every AI surface now shares (see
     * modules/AiCopilot/Module.php's own docblock) - this is the
     * server-side half; the client-side half is useAiCopilotEnabled().
     *
     * @param \WP_REST_Request $request Full request object.
     * @return bool|\WP_Error
     */
    public function create_item_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        if ( ! VuloPilot()->modules->is_active( 'ai-copilot' ) ) {
            return new \WP_Error(
                'vulopilot_ai_copilot_inactive',
                __( 'Enable the AI Copilot module to use the AI Content Assistant.', 'vulopilot' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Runs one orchestrator turn and acts on its decision.
     *
     * @param \WP_REST_Request $request Full request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_item( $request ) {
        $message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

        if ( '' === trim( $message ) ) {
            return new \WP_Error(
                'vulopilot_empty_message',
                __( 'Message cannot be empty.', 'vulopilot' ),
                array( 'status' => 400 )
            );
        }

        $messages = $this->build_orchestrator_messages( $message, (array) $request->get_param( 'history' ) );

        try {
            $response = VuloPilot()->ai_request_sender->send( $messages, null, 'content_assistant_chat' );
        } catch ( VuloPilotException $exception ) {
            if ( VuloPilotException::TYPE_INSUFFICIENT_CREDITS === $exception->get_type() ) {
                return $exception->to_insufficient_credits_error();
            }

            // Returned, not rethrown - a throw from inside this catch skips
            // the \Throwable catch below and fatals the request.
            if ( VuloPilotException::TYPE_UNSAFE_PROMPT !== $exception->get_type() ) {
                return new \WP_Error( 'vulopilot_ai_request_failed', $exception->getMessage(), array( 'status' => 502 ) );
            }

            return new \WP_Error( 'vulopilot_unsafe_prompt', $exception->getMessage(), array( 'status' => 400 ) );
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
        } catch ( \Throwable $exception ) {
            return new \WP_Error( 'vulopilot_ai_request_failed', $exception->getMessage(), array( 'status' => 502 ) );
        }

        $decision = $this->orchestrator->parse_response( $response );

        if ( 'ready_action' === $decision['status'] ) {
            $result = $this->orchestrator->create_content_and_respond( $decision );

            if ( $result instanceof \WP_Error ) {
                return $result;
            }

            return rest_ensure_response( $result );
        }

        return rest_ensure_response(
            array(
                'content' => $decision['message'],
                'link'    => null,
                'run_id'  => null,
            )
        );
    }

    /**
     * Builds the one orchestrator prompt every turn goes through: a
     * system message describing the 3 real content types it can create
     * (kept in sync with CONTENT_CREATION_ACTIONS and each action's own
     * validate_input() by hand), the client's own recent turns, then the
     * new user message. Instructed to respond with strict JSON only -
     * the same "respond with ONLY raw JSON" structured-output technique
     * GeoAnalysis\GeoAnalyzer and AiCopilot\Actions\GenerateBlogAction
     * already use for their own AI calls.
     *
     * @param string            $message     The new user message.
     * @param array<int, mixed> $raw_history Client-supplied {role, content} turns, oldest first.
     * @return array<int, array{role: string, content: string}>
     */
    private function build_orchestrator_messages( string $message, array $raw_history ): array {
        $messages   = array();
        $messages[] = array(
            'role'    => 'system',
            'content' => sprintf(
                /* translators: %s is the site's own real name (get_bloginfo('name')). */
                __(
                    'You are the intake assistant for the "Content" chat inside the WordPress plugin VuloPilot, on the site "%s". Your job this turn is to move the conversation toward either (a) creating one of 3 specific kinds of real WordPress content, or (b) simply answering the user when that\'s what they actually want.

The 3 kinds of WordPress content you can create. For each, collect the fields in the order listed - a field being listed after the first one does NOT mean it\'s skippable; ask about each one, one at a time, unless the user already stated it somewhere in the conversation:
1. "generate-blog" - a blog post or article. Collect, in order: topic (what it\'s about), word_count (target word count), tone (e.g. Professional/Friendly/Informative/Casual).

Rules:
- Ask for exactly ONE missing field at a time, as a short natural question, following the collection order above. Never ask about a field already given anywhere earlier in this conversation - check the whole conversation, not just the latest message, before asking. Never ask more than 3 questions total for one request.
- If the user changed their mind about something, use their latest answer, not an earlier one.
- Only skip a field if the user\'s messages already gave it, or if they explicitly say they don\'t have a preference for it. Do not stop early just because the first, most-obvious field (e.g. the topic) is known - still ask about the remaining ones in order.
- A field can be given implicitly inside natural phrasing, not just as an explicit "field: value" statement - e.g. "a casual blog post about X" already gives both topic and tone (casual); "a 500-word post" or "500 words" gives word_count. Recognize these the same as an explicit answer, and don\'t ask about them again.
- If the request doesn\'t match any of the 3 kinds (e.g. an email, a social caption, general advice, or editing something that already exists, which you have no way to identify from chat), have a short exchange to understand what\'s actually needed (purpose, audience, tone - whatever is relevant), then write the content yourself as a normal reply. Never claim it was created or saved - there is no WordPress content type for it.
- If the user is just asking a question rather than requesting new content, answer it directly and helpfully. Use plain text or Markdown, never HTML.

Worked example for "generate-blog" (the same collect-one-at-a-time pattern and their own field lists above):
User: "Write a blog" → {"status":"question","message":"Sure! What should the blog be about?"}
User: "AI in eCommerce" → {"status":"question","message":"Great - how many words would you like?"}
User: "1500 words" → {"status":"question","message":"What tone would you prefer? For example: Professional, Friendly, Informative, or Casual."}
User: "Professional" → {"status":"ready_action","action_id":"generate-blog","input":{"topic":"AI in eCommerce","word_count":1500,"tone":"Professional"}}

But if a message already gives multiple fields at once (e.g. "Write a 1000-word blog about SEO" gives topic and word_count together, or a fully-specified first message gives everything), only ask about whatever is still actually missing from that list - or proceed straight to ready_action if nothing is missing.

Respond with ONLY raw JSON, no markdown fences, no commentary, in exactly one of these shapes:
{"status":"question","message":"<the single next question, phrased naturally>"}
{"status":"ready_action","action_id":"generate-blog","input":{...only the fields listed above for that action_id...}}
{"status":"respond","message":"<a direct answer, or fully-written content for a kind with no matching action above>"}',
                    'vulopilot'
                ),
                get_bloginfo( 'name' )
            ),
        );

        foreach ( array_slice( $raw_history, -self::MAX_HISTORY_MESSAGES ) as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['role'] ) || empty( $entry['content'] ) ) {
                continue;
            }

            $messages[] = array(
                'role'    => 'user' === $entry['role'] ? 'user' : 'assistant',
                'content' => sanitize_textarea_field( (string) $entry['content'] ),
            );
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => $message,
        );

        return $messages;
    }
}
