<?php
/**
 * Copilot controller file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot\Rest;

use VuloPilot\AiCopilot\BlogIntake;
use VuloPilot\AiCopilot\ContentCreationOrchestrator;
use VuloPilot\Utill\VuloPilotException;
use VuloPilot\Utill\Severity;
use VuloPilot\Utill\FindingRepository;
use VuloPilot\Automations\AutomationsRepository;
use VuloPilot\AiAssistant\ActionRunRepository;
use VuloPilot\AiCopilot\Repositories\AiConversationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the AI Copilot chat. Sends the AI service the conversation
 * plus a live snapshot of the site's findings/automation counts (build_site_snapshot())
 * so answers reason from actual open issues. A "write a blog about X"
 * request creates a real draft via ContentCreationOrchestrator
 * (auto-approved); every other kind of change stays advice-only.
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
	 * Max "Add context" refs (findings/automations) accepted per turn.
	 */
	private const MAX_CONTEXT_REFS = 5;

	/**
	 * Max "Attach" file refs accepted per turn.
	 */
	private const MAX_ATTACHMENTS = 3;

	/**
	 * Mime types this plugin will actually read the *content* of for an attached file.
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
	 * Sends one real chat turn through the AI request sender, grounded with a live site
	 * snapshot, and acts on its decision.
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

		// A blog request is collected by fixed questions, with no AI, until every field is known.
		$decision = ( new BlogIntake( 'copilot' ) )->handle( $message );

		if ( null === $decision ) {
			$answer = $this->ask(
				$this->build_chat_context(
					$message,
					(array) $request->get_param( 'history' ),
					(array) $request->get_param( 'context_refs' ),
					(array) $request->get_param( 'attachments' )
				)
			);

			if ( $answer instanceof \WP_Error ) {
				return $answer;
			}

			$decision = array(
				'status'  => 'respond',
				'message' => $answer,
			);
		}

		// "Auto-applies (with approval)" (ChatTab.tsx).
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
	 * (`vulopilot_ai_conversations`).
	 *
	 * @param int|null             $conversation_id Client-supplied existing conversation id, or null for a new one.
	 * @param string               $user_message    This turn's real, full user message - never the context-appended version sent to the AI service.
	 * @param array<int, mixed>    $client_history   The client's own prior turns, as sent on `history`.
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
	 * Sanitizes client-supplied history turns to {role, content}.
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
	 * `GET /copilot/conversations` - RecentConversationsCard.tsx's own list of this
	 * admin's most recent real conversation threads.
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
	 * `GET /copilot/conversations/{id}` - one thread's real, full, untruncated turns, read
	 * back into the composer by useCopilotChat.ts's own loadConversation().
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
	 * "Auto-applies (with approval)" turned off - describes what would
	 * have been created instead of creating it.
	 *
	 * @param array{action_id: string, input: array<string, mixed>} $decision BlogIntake::handle()'s "ready_action" decision.
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
	 * Sends one chat message, with everything the chat may use, and returns the AI's answer.
	 *
	 * @param array<string, mixed> $context build_chat_context()'s result.
	 * @return string|\WP_Error
	 */
	private function ask( array $context ) {
		try {
			$response = VuloPilot()->ai_request_sender->send_feature( 'copilot_chat', 'chat', $context, 'copilot_chat', null, false );
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

		$answer = trim( $response->get_content() );

		return '' !== $answer ? $answer : __( "Sorry, I didn't quite catch that - could you rephrase?", 'vulopilot' );
	}

	/**
	 * The chat feature's input: the new message, the whole conversation so far, and the site
	 * data the chat may use - the live snapshot plus anything the user attached.
	 *
	 * @param string            $message          The new user message.
	 * @param array<int, mixed> $raw_history      Client-supplied {role, content} turns, oldest first.
	 * @param array<int, mixed> $raw_context_refs Client-supplied finding-group/automation references.
	 * @param array<int, mixed> $raw_attachments  Client-supplied Media Library attachment references.
	 * @return array<string, mixed>
	 */
	private function build_chat_context( string $message, array $raw_history, array $raw_context_refs, array $raw_attachments ): array {
		return array(
			'site_name'    => get_bloginfo( 'name' ),
			'message'      => $message,
			'history'      => $this->sanitize_client_turns( $raw_history ),
			'snapshot'     => $this->build_site_snapshot(),
			'context_refs' => $this->build_context_refs( $raw_context_refs ),
			'attachments'  => $this->build_attachments( $raw_attachments ),
		);
	}

	/**
	 * The finding groups and automations the user attached, each resolved from the database.
	 *
	 * @param array<int, mixed> $raw_refs Client-supplied references.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_context_refs( array $raw_refs ): array {
		$findings    = new FindingRepository();
		$automations = new AutomationsRepository();
		$refs        = array();

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

				$refs[] = array(
					'type'       => 'finding_group',
					'label'      => $scanner ? $scanner->get_label() : $scanner_id,
					'category'   => (string) $group['category'],
					'open_count' => (int) $group['count'],
					'severity'   => (string) $group['severity'],
				);
			} elseif ( 'automations' === $type ) {
				$automation_id = absint( $ref['id'] ?? 0 );
				$automation    = 0 !== $automation_id ? $automations->find( $automation_id ) : null;

				if ( null === $automation ) {
					continue;
				}

				$refs[] = array(
					'type'    => 'automation',
					'name'    => (string) $automation['name'],
					'status'  => (string) $automation['status'],
					'trigger' => (string) $automation['trigger_type'],
				);
			}
		}

		return $refs;
	}

	/**
	 * The Media Library files the user attached: a text file's contents, or just its name and type.
	 *
	 * @param array<int, mixed> $raw_attachments Client-supplied attachment references.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_attachments( array $raw_attachments ): array {
		$files = array();

		foreach ( array_slice( $raw_attachments, 0, self::MAX_ATTACHMENTS ) as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$attachment_id = absint( $attachment['id'] ?? 0 );

			if ( 0 === $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			$path = get_attached_file( $attachment_id );
			$file = array(
				'filename' => $path ? wp_basename( $path ) : sprintf( 'attachment-%d', $attachment_id ),
				'mime'     => (string) get_post_mime_type( $attachment_id ),
			);

			if ( ! in_array( $file['mime'], self::ATTACHMENT_TEXT_MIME_TYPES, true ) ) {
				$files[] = $file;
				continue;
			}

			$contents = ( $path && is_readable( $path ) )
				? file_get_contents( $path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local Media Library file this same admin already uploaded (via wp.media()), not a remote URL or arbitrary user-supplied path; WP_Filesystem's FTP-credential fallback isn't warranted for a local read.
				: false;

			if ( false === $contents ) {
				$files[] = $file + array( 'readable' => false );
				continue;
			}

			$files[] = $file + array( 'content' => sanitize_textarea_field( $contents ) );
		}

		return $files;
	}

	/**
	 * Live site health counts the chat can refer to.
	 *
	 * @return array<string, mixed>
	 */
	private function build_site_snapshot(): array {
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

		$by_category = array();
		foreach ( $categories as $category => $label ) {
			$by_category[] = array(
				'label' => $label,
				'count' => (int) $findings->count_by_category( $category ),
			);
		}

		return array(
			'findings_by_category' => $by_category,
			'critical'             => (int) $findings->count_by_severity( Severity::CRITICAL ),
			'high'                 => (int) $findings->count_by_severity( Severity::HIGH ),
			'active_automations'   => (int) $automations->count_enabled(),
			'pending_approvals'    => (int) $action_runs->find_all(
				array(
					'status'   => 'pending_approval',
					'per_page' => 1,
				)
			)['total'],
		);
	}
}
