<?php
/**
 * Copilot controller file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot\Rest;

use VuloPilot\AiCopilot\ContentCreationOrchestrator;
use VuloPilot\Utill\VuloPilotException;
use VuloPilot\Utill\Severity;
use VuloPilot\Utill\FindingRepository;
use VuloPilot\Automations\AutomationsRepository;
use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\AiCopilot\Repositories\AiConversationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reuses VuloPilot()->ai_request_sender (AI\AiRequestSender)
 * exactly like ContentAssistant.php and GeoAnalyzer already do - same
 * safety-validate → send → sanitize sequence, and every
 * call is automatically recorded to `vulopilot_ai_history` by
 * AI\AiRequestSender itself.
 *
 * Grounded with a real, live snapshot of the site's own findings/automation
 * counts (build_site_context()) so answers like "why is my traffic
 * dropping?" reason from this site's actual open issues rather than generic
 * advice. Shares ContentAssistant.php's own ContentCreationOrchestrator - a
 * message like "write a blog about X" really creates a WordPress draft via
 * the same AiCopilot\ActionRunner propose()→approve() lifecycle
 * (auto-approved, since the conversation itself IS the approval), not just
 * advice about writing one. Every *other* kind of change (an SEO fix, a
 * security setting, anything mutating something that already exists) is
 * still advice-only - build_messages()'s own prompt says so plainly rather
 * than claiming to have done it.
 *
 * @class       Copilot controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Copilot extends \WP_REST_Controller {

    /**
     * REST base for this controller's routes.
     *
     * @var string
     */
    protected $rest_base = 'copilot';

    /**
     * Shared with ContentAssistant.php - see ContentCreationOrchestrator's
     * own docblock for why this logic lives outside both controllers.
     *
     * @var ContentCreationOrchestrator
     */
    private ContentCreationOrchestrator $orchestrator;

    /**
     * Copilot constructor.
     */
    public function __construct() {
        $this->orchestrator = new ContentCreationOrchestrator();
    }

    /**
     * How many prior turns of client-supplied history to include - bounds
     * the prompt sent to the AI service on a long-running chat.
     */
    private const MAX_HISTORY_MESSAGES = 20;

    /**
     * Max "Add context" refs (findings/automations) accepted per turn.
     */
    private const MAX_CONTEXT_REFS = 5;

    /**
     * Max "Attach" file refs accepted per turn.
     */
    private const MAX_ATTACHMENTS = 3;

    /**
     * How many bytes of an attached file's own content to read and include
     * - bounds the prompt the same way MAX_HISTORY_MESSAGES bounds history,
     * since this is untrusted-length user content going straight into the
     * AI request.
     */
    private const ATTACHMENT_MAX_BYTES = 200000;

    /**
     * Mime types this plugin will actually read the *content* of for an
     * attached file - deliberately just the two WordPress itself allows
     * uploading by default (includes/functions.php's own
     * get_allowed_mime_types() 'txt'/'csv' entries), so "Attach" works out
     * of the box without asking the site owner to first loosen their
     * upload-type allowlist.
     */
    private const ATTACHMENT_TEXT_MIME_TYPES = array( 'text/plain', 'text/csv' );

    /**
     * Registers POST /copilot/chat.
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

        // RecentConversationsCard.tsx's own list of this admin's recent,
        // real, reloadable conversation threads - see get_conversations().
        register_rest_route(
            VuloPilot()->rest_namespace,
            '/' . $this->rest_base . '/conversations',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_conversations' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                ),
            )
        );

        // useCopilotChat.ts's own loadConversation() - one thread's real,
        // full, untruncated turns, read back into the composer.
        register_rest_route(
            VuloPilot()->rest_namespace,
            '/' . $this->rest_base . '/conversations/(?P<id>\d+)',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_conversation' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                ),
            )
        );
    }

    /**
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
                __( 'Enable the AI Copilot module to use Chat with VuloPilot.', 'vulopilot' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Sends one real chat turn through the AI request sender,
     * grounded with a live site snapshot, and acts on its decision - either
     * a real content-creation draft (ContentCreationOrchestrator) or a
     * plain grounded reply.
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

        $conversation_id_param = $request->get_param( 'conversation_id' );
        $conversation_id       = $conversation_id_param ? absint( $conversation_id_param ) : null;

        $raw_attachments = (array) $request->get_param( 'attachments' );

        $extra_context = $this->build_extra_context(
            (array) $request->get_param( 'context_refs' ),
            $raw_attachments
        );

        $message_with_context = '' !== $extra_context ? $message . "\n\n" . $extra_context : $message;

        $messages = $this->build_messages( $message_with_context, (array) $request->get_param( 'history' ) );

        try {
            $response = VuloPilot()->ai_request_sender->send( $messages, null, 'copilot_chat' );
        } catch ( VuloPilotException $exception ) {
            if ( VuloPilotException::TYPE_INSUFFICIENT_CREDITS === $exception->get_type() ) {
                return $exception->to_insufficient_credits_error();
            }

            // Returned, not rethrown, so the \Throwable catch below doesn't fatal.
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

        // "Auto-applies (with approval)" (ChatTab.tsx) - the one real
        // content-creation capability this orchestrator has (a new blog
        // post/landing page/product description draft) is auto-approved
        // by default, same as before this param existed. Turning the
        // toggle off downgrades a `ready_action` decision to the same
        // honest "respond" shape every other kind of request already
        // gets, rather than silently creating something the user asked
        // not to be auto-applied.
        $auto_apply = rest_sanitize_boolean( $request->get_param( 'auto_apply' ) ?? true );

        if ( 'ready_action' === $decision['status'] && ! $auto_apply ) {
            $decision = array(
                'status'  => 'respond',
                'message' => $this->describe_pending_action( $decision ),
            );
        }

        if ( 'ready_action' === $decision['status'] ) {
            $result = $this->orchestrator->create_content_and_respond( $decision );

            if ( $result instanceof \WP_Error ) {
                return $result;
            }

            $conversation_id = $this->persist_conversation(
                $conversation_id,
                $message,
                (array) $request->get_param( 'history' ),
                array(
                    'role'    => 'assistant',
                    'content' => $result['content'] ?? '',
                    'link'    => $result['link'] ?? null,
                    'run_id'  => $result['run_id'] ?? null,
                )
            );

            return rest_ensure_response(
                array_merge(
                    $result,
                    array(
                        'conversation_id' => $conversation_id,
                    )
                )
            );
        }

        $conversation_id = $this->persist_conversation(
            $conversation_id,
            $message,
            (array) $request->get_param( 'history' ),
            array(
                'role'    => 'assistant',
                'content' => $decision['message'],
                'link'    => null,
                'run_id'  => null,
            )
        );

        return rest_ensure_response(
            array(
                'content'         => $decision['message'],
                'link'            => null,
                'run_id'          => null,
                'conversation_id' => $conversation_id,
            )
        );
    }

    /**
     * Saves this turn to a real, reloadable conversation thread
     * (`vulopilot_ai_conversations`) - separate from, and in addition to,
     * `vulopilot_ai_history`'s own automatic excerpt-only audit-trail write
     * (AI\AiRequestSender, untouched by this). Starts a new conversation
     * when `$conversation_id` is null/0 or no longer owned by this user
     * (e.g. a stale/tampered id), otherwise appends to the existing one.
     *
     * @param int|null             $conversation_id Client-supplied existing conversation id, or null for a new one.
     * @param string               $user_message    This turn's real, full user message - never the context-appended version sent to the AI service.
     * @param array<int, mixed>    $client_history   The client's own prior turns, as sent on `history` - only really populated for a conversation started before this feature existed, or a same-request edge case; a fresh conversation's `client_history` is normally empty.
     * @param array<string, mixed> $assistant_turn   `{role: 'assistant', content, link, run_id}` for this turn's real reply.
     * @return int The conversation id this turn was saved under.
     */
    private function persist_conversation( ?int $conversation_id, string $user_message, array $client_history, array $assistant_turn ): int {
        $repository = new AiConversationRepository();
        $user_id    = get_current_user_id();

        $new_turns = array(
            array(
                'role'    => 'user',
                'content' => $user_message,
            ),
            $assistant_turn,
        );

        if ( $conversation_id ) {
            $existing = $repository->find_full( $conversation_id, $user_id );

            if ( $existing ) {
                $repository->append_turns( $conversation_id, $user_id, array_merge( $existing['turns'], $new_turns ) );

                return $conversation_id;
            }
        }

        return $repository->create( $user_id, $user_message, array_merge( $this->sanitize_client_turns( $client_history ), $new_turns ) );
    }

    /**
     * Same shape/sanitization build_messages() already applies to client-
     * supplied history turns - reused here so a brand-new conversation that
     * somehow already carries client history (see persist_conversation()'s
     * own docblock) never persists an unsanitized/malformed entry.
     *
     * @param array<int, mixed> $raw_history Client-supplied {role, content} turns.
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitize_client_turns( array $raw_history ): array {
        $turns = array();

        foreach ( $raw_history as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['role'] ) || empty( $entry['content'] ) ) {
                continue;
            }

            $turns[] = array(
                'role'    => 'user' === $entry['role'] ? 'user' : 'assistant',
                'content' => sanitize_textarea_field( (string) $entry['content'] ),
            );
        }

        return $turns;
    }

    /**
     * `GET /copilot/conversations` - RecentConversationsCard.tsx's own list
     * of this admin's most recent real conversation threads.
     * `?with_excerpt=1` returns the same rows plus a real one-line excerpt
     * (AiConversationRepository::get_recent_with_excerpt()) - was read by
     * an inline "Recent conversations" section on AI Copilot's Chat tab,
     * removed as dead code (never actually rendered); left here rather
     * than removed too, since it's a real, harmless, independently useful
     * response shape a future caller could still opt into.
     *
     * @param \WP_REST_Request $request Full request object.
     * @return \WP_REST_Response
     */
    public function get_conversations( $request ) {
        $per_page     = absint( $request->get_param( 'per_page' ) );
        $with_excerpt = rest_sanitize_boolean( $request->get_param( 'with_excerpt' ) );
        $repository   = new AiConversationRepository();

        $result = $with_excerpt
            ? $repository->get_recent_with_excerpt( get_current_user_id(), $per_page > 0 ? $per_page : 3 )
            : $repository->get_recent( get_current_user_id(), $per_page > 0 ? $per_page : 5 );

        return rest_ensure_response( $result );
    }

    /**
     * `GET /copilot/conversations/{id}` - one thread's real, full,
     * untruncated turns, read back into the composer by useCopilotChat.ts's
     * own loadConversation().
     *
     * @param \WP_REST_Request $request Full request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_conversation( $request ) {
        $conversation = ( new AiConversationRepository() )->find_full(
            absint( $request->get_param( 'id' ) ),
            get_current_user_id()
        );

        if ( null === $conversation ) {
            return new \WP_Error(
                'vulopilot_conversation_not_found',
                __( 'Conversation not found.', 'vulopilot' ),
                array( 'status' => 404 )
            );
        }

        return rest_ensure_response( $conversation );
    }

    /**
     * "Auto-applies (with approval)" turned off - describes what the
     * orchestrator would have created instead of creating it. Uses
     * ContentCreationOrchestrator::CONTENT_CREATION_ACTIONS's own `noun`
     * for the action, and the AI-supplied `topic`/`title` input field when
     * present, so the message names the actual thing it would make rather
     * than a generic placeholder.
     *
     * @param array{action_id: string, input: array<string, mixed>} $decision parse_response()'s "ready_action" return value.
     * @return string
     */
    private function describe_pending_action( array $decision ): string {
        $noun  = ContentCreationOrchestrator::CONTENT_CREATION_ACTIONS[ $decision['action_id'] ]['noun'] ?? 'content';
        $topic = sanitize_text_field( (string) ( $decision['input']['topic'] ?? $decision['input']['title'] ?? '' ) );

        if ( '' !== $topic ) {
            return sprintf(
                /* translators: 1: e.g. "blog post", 2: the requested topic/title. */
                __( 'I can create a %1$s about "%2$s" for you - turn on "Auto-applies (with approval)" and ask again to have me create it.', 'vulopilot' ),
                $noun,
                $topic
            );
        }

        return sprintf(
            /* translators: %s is e.g. "blog post". */
            __( 'I can create a %s for you - turn on "Auto-applies (with approval)" and ask again to have me create it.', 'vulopilot' ),
            $noun
        );
    }

    /**
     * Builds a real chat-style prompt: a system message describing the
     * copilot's role, its one real execution capability (content creation,
     * via ContentCreationOrchestrator - kept in sync with that class's own
     * CONTENT_CREATION_ACTIONS by hand, the same way ContentAssistant.php's
     * own orchestrator prompt already had to be), and a live site snapshot,
     * then the client's own recent turns, then the new user message.
     * Instructed to respond with strict JSON only - the same 3-shape
     * contract ContentAssistant.php's own orchestrator prompt uses,
     * extended here with a "respond" case that also covers ordinary
     * grounded Q&A (using the site snapshot), not just "content I can't
     * save."
     *
     * @param string            $message     The new user message.
     * @param array<int, mixed> $raw_history Client-supplied {role, content} turns, oldest first.
     * @return array<int, array{role: string, content: string}>
     */
    private function build_messages( string $message, array $raw_history ): array {
        $messages   = array();
        $messages[] = array(
            'role'    => 'system',
            'content' => sprintf(
                /* translators: 1: site name, 2: real live site snapshot text. */
                __(
                    'You are VuloPilot, an AI website copilot embedded in the WordPress plugin VuloPilot, helping the owner of the site "%1$s". You help with SEO, performance, security, accessibility, GEO/AI-search visibility, WooCommerce store health, and automations.

You can directly create 1 specific kind of real WordPress content (a blog post) - everything else you help with is advice only. For each, collect the fields in order - a field listed after the first one does NOT mean it\'s skippable; ask about each one, one at a time, unless the user already stated it somewhere in the conversation:
1. "generate-blog" - a blog post or article. Collect, in order: topic (what it\'s about), word_count (target word count), tone (e.g. Professional/Friendly/Informative/Casual).

Rules for content creation:
- Ask for exactly ONE missing field at a time, as a short natural question, following the collection order above. Never ask about a field already given anywhere earlier in this conversation. Never ask more than 3 questions total for one request.
- If the user changed their mind about something, use their latest answer, not an earlier one.
- A field can be given implicitly inside natural phrasing, not just as an explicit "field: value" statement - e.g. "a casual blog post about X" already gives both topic and tone; "500 words" gives word_count. Recognize these the same as an explicit answer, and don\'t ask about them again.
- If a message already gives multiple fields at once, or a fully-specified first message gives everything needed, only ask about whatever is still actually missing - or proceed straight to ready_action if nothing is missing.

Worked example for "generate-blog" (the same collect-one-at-a-time pattern):
User: "Write a blog" → {"status":"question","message":"Sure! What should the blog be about?"}
User: "AI in eCommerce" → {"status":"question","message":"Great - how many words would you like?"}
User: "1500 words" → {"status":"question","message":"What tone would you prefer? For example: Professional, Friendly, Informative, or Casual."}
User: "Professional" → {"status":"ready_action","action_id":"generate-blog","input":{"topic":"AI in eCommerce","word_count":1500,"tone":"Professional"}}

For anything that is NOT a blog post request - a general question, SEO/performance/security/accessibility/GEO/WooCommerce/automation advice, editing something that already exists, or any other kind of written content - answer using the real site snapshot below when relevant, and point the user to the specific tab (Issues, Performance, Security, GEO, WooCommerce, Automations) where they can review or fix something themselves. You cannot execute any change on the site yourself beyond the 3 content-creation kinds above - say so plainly if asked to perform some other action, rather than claiming to have done it. Reply in plain text or Markdown, never HTML.

Respond with ONLY raw JSON, no markdown fences, no commentary, in exactly one of these shapes:
{"status":"question","message":"<the single next question, phrased naturally>"}
{"status":"ready_action","action_id":"generate-blog","input":{...only the fields listed above for that action_id...}}
{"status":"respond","message":"<a direct answer, or other written content the user still has to copy/paste themselves>"}%2$s',
                    'vulopilot'
                ),
                get_bloginfo( 'name' ),
                $this->build_site_context()
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

    /**
     * Resolves the user's "Add context" picks and "Attach" file picks into
     * one real text block, appended to the outgoing user message (not the
     * always-on system-prompt site snapshot build_site_context() already
     * provides) - this is per-turn, user-chosen grounding, not a permanent
     * site-wide fact. Everything is re-resolved from the database/media
     * library here rather than trusting any label, count, or content the
     * client already had cached, same "never trust client-supplied facts"
     * posture the rest of this codebase's AI-grounding code already takes.
     *
     * @param array<int, mixed> $raw_context_refs           Client-supplied {type, scanner_id|id} refs, from the "Add context" picker.
     * @param array<int, mixed> $raw_attachments            Client-supplied {id} WP attachment refs, from the "Attach" file picker.
     * @return string Empty string if nothing in either list resolved to something real.
     */
    private function build_extra_context( array $raw_context_refs, array $raw_attachments ): string {
        $blocks = array_filter(
            array(
                $this->build_context_refs_block( $raw_context_refs ),
                $this->build_attachments_block( $raw_attachments ),
            ),
            static fn( $block ) => '' !== $block
        );

        if ( ! $blocks ) {
            return '';
        }

        return "[Context the user attached to this message:]\n" . implode( "\n\n", $blocks );
    }

    /**
     * Resolves "Add context" refs - a user-picked open finding group
     * (grouped by scanner_id, same unit NeedsAttentionCard.tsx's own list
     * already shows) or automation - into real, current one-line summaries.
     *
     * @param array<int, mixed> $raw_refs Client-supplied {type: 'finding_group'|'automations', scanner_id?, id?} entries.
     * @return string Empty string if none resolved.
     */
    private function build_context_refs_block( array $raw_refs ): string {
        $findings    = new FindingRepository();
        $automations = new AutomationsRepository();
        $lines       = array();

        foreach ( array_slice( $raw_refs, 0, self::MAX_CONTEXT_REFS ) as $ref ) {
            if ( ! is_array( $ref ) ) {
                continue;
            }

            $type = sanitize_key( (string) ( $ref['type'] ?? '' ) );

            if ( 'finding_group' === $type ) {
                $scanner_id = sanitize_key( (string) ( $ref['scanner_id'] ?? '' ) );
                $group      = '' !== $scanner_id ? $findings->get_group_by_scanner_id( $scanner_id ) : null;

                if ( null === $group ) {
                    continue;
                }

                $scanner = VuloPilot()->scanner_registry->get_scanner( $scanner_id );
                $label   = $scanner ? $scanner->get_label() : $scanner_id;

                $lines[] = sprintf(
                    /* translators: 1: scanner label, 2: category, 3: open finding count, 4: severity */
                    __( '- Finding group "%1$s" (%2$s): %3$d open, severity %4$s.', 'vulopilot' ),
                    $label,
                    $group['category'],
                    $group['count'],
                    $group['severity']
                );
            } elseif ( 'automations' === $type ) {
                $automation_id = absint( $ref['id'] ?? 0 );
                $automation    = 0 !== $automation_id ? $automations->find( $automation_id ) : null;

                if ( null === $automation ) {
                    continue;
                }

                $lines[] = sprintf(
                    /* translators: 1: automation name, 2: status (enabled/disabled), 3: trigger type */
                    __( '- Automation "%1$s": %2$s, trigger %3$s.', 'vulopilot' ),
                    $automation['name'],
                    $automation['status'],
                    $automation['trigger_type']
                );
            }
        }

        return $lines ? implode( "\n", $lines ) : '';
    }

    /**
     * Resolves "Attach" file refs - real WP Media Library attachment ids
     * (zyra FileInput's wp.media() picker in ChatTab.tsx only ever hands
     * back a real {id, url}, never a client-only blob preview; see that
     * component's own onChange contract) - into real content blocks.
     * ATTACHMENT_TEXT_MIME_TYPES are read as text. Anything else (an unsupported
     * type, or an image - the gateway carries text only) gets an
     * honest "can't be read" note instead of silently doing nothing with it.
     *
     * @param array<int, mixed> $raw_attachments              Client-supplied {id} entries.
     * @return string Empty string if none resolved.
     */
    private function build_attachments_block( array $raw_attachments ): string {
        $blocks = array();

        foreach ( array_slice( $raw_attachments, 0, self::MAX_ATTACHMENTS ) as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $attachment_id = absint( $attachment['id'] ?? 0 );

            if ( 0 === $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
                continue;
            }

            $path     = get_attached_file( $attachment_id );
            $filename = $path ? wp_basename( $path ) : sprintf( 'attachment-%d', $attachment_id );
            $mime     = (string) get_post_mime_type( $attachment_id );

            if ( ! in_array( $mime, self::ATTACHMENT_TEXT_MIME_TYPES, true ) ) {
                $blocks[] = sprintf(
                    /* translators: 1: original filename, 2: mime type */
                    __( '- Attached file "%1$s" (%2$s) - its content can\'t be read, only the filename is available.', 'vulopilot' ),
                    $filename,
                    $mime
                );
                continue;
            }

            $contents = ( $path && is_readable( $path ) )
                ? file_get_contents( $path, false, null, 0, self::ATTACHMENT_MAX_BYTES ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local Media Library file this same admin already uploaded (via wp.media()), not a remote URL or arbitrary user-supplied path; WP_Filesystem's FTP-credential fallback isn't warranted for a bounded local read.
                : false;

            if ( false === $contents ) {
                $blocks[] = sprintf(
                    /* translators: 1: original filename, 2: mime type */
                    __( '- Attached file "%1$s" (%2$s) - could not be read.', 'vulopilot' ),
                    $filename,
                    $mime
                );
                continue;
            }

            $contents  = sanitize_textarea_field( $contents );
            $truncated = strlen( $contents ) >= self::ATTACHMENT_MAX_BYTES ? "\n[...truncated]" : '';

            $blocks[] = sprintf(
                /* translators: 1: original filename, 2: mime type, 3: the file's own text content, 4: truncation note or empty string */
                __( "- Attached file \"%1\$s\" (%2\$s):\n%3\$s%4\$s", 'vulopilot' ),
                $filename,
                $mime,
                $contents,
                $truncated
            );
        }

        return $blocks ? implode( "\n\n", $blocks ) : '';
    }

    /**
     * A real, live snapshot of the site's own open findings and automation
     * state - the same repositories/queries Controllers\Dashboard.php's
     * `/dashboard` payload already reads, condensed into a short text block
     * instead of a JSON payload, so the AI reasons about this site's actual
     * issues instead of generic advice.
     *
     * @return string
     */
    private function build_site_context(): string {
        $findings    = new FindingRepository();
        $automations = new AutomationsRepository();
        $action_runs = new ActionRunRepository();

        $categories = array(
            'seo'           => __( 'SEO', 'vulopilot' ),
            'performance'   => __( 'Performance', 'vulopilot' ),
            'security'      => __( 'Security', 'vulopilot' ),
            'accessibility' => __( 'Accessibility', 'vulopilot' ),
            'geo'           => __( 'GEO/AI-search', 'vulopilot' ),
        );

        if ( class_exists( 'WooCommerce' ) ) {
            $categories['woocommerce'] = __( 'WooCommerce', 'vulopilot' );
        }

        $category_lines = array();
        foreach ( $categories as $category => $label ) {
            $category_lines[] = sprintf( '%s: %d', $label, $findings->count_by_category( $category ) );
        }

        $pending_approvals = (int) $action_runs->find_all(
            array(
                'status'   => 'pending_approval',
                'per_page' => 1,
            )
        )['total'];

        return sprintf(
            "\n\nCurrent site snapshot:\n- Open findings by category: %1\$s\n- Critical: %2\$d, High: %3\$d\n- Active automations: %4\$d\n- Action runs pending approval: %5\$d",
            implode( ', ', $category_lines ),
            $findings->count_by_severity( Severity::CRITICAL ),
            $findings->count_by_severity( Severity::HIGH ),
            $automations->count_enabled(),
            $pending_approvals
        );
    }
}
