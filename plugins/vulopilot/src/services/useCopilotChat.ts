/* global vulopilotAppLocalizer */
import { useRef, useState } from 'react';
import axios from 'axios';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { NoticeManager } from '@zyra/components';
import { useAiCredits } from './useAiCredits';

/** A real, clickable edit link for content the AI just created and saved - see CopilotChatResponse's own docblock. */
export interface CopilotChatLink {
	url: string;
	label: string;
}

export interface CopilotChatTurn {
	role: 'user' | 'assistant';
	content: string;
	link?: CopilotChatLink | null;
	/** The real `vulopilot_ai_action_runs.id` this turn's content creation executed as - set only alongside `link`, lets ChatTab.tsx offer a real inline "Undo" right next to what it undoes (`POST /ai-action-runs/{id}/rollback`, the same endpoint HistoryDetailPanel.tsx's own Undo button already calls). */
	runId?: number | null;
	/** Set client-side once this turn's own run has been successfully rolled back, so the inline Undo button can honestly show "Undone" instead of staying clickable for an already-reverted run. */
	undone?: boolean;
	/** The real files this turn was sent with - user turns only, set from the composer's own `attachments` state at send time so ChatTab.tsx can still show what was attached after the composer clears it. */
	attachments?: CopilotAttachment[];
}

/**
 * A user-picked "Add context" item - always re-resolved against real,
 * current data server-side (Copilot.php's build_context_refs_block());
 * the extra fields here (label/category/count/severity, name) are for
 * rendering the chip client-side only, never trusted as-is by the server.
 */
export type CopilotContextRef =
	| {
			type: 'finding_group';
			scannerId: string;
			label: string;
			category: string;
			count: number;
			severity: string;
	  }
	| {
			type: 'automations';
			id: number;
			name: string;
	  };

/** A user-picked "Attach" file - a real WP Media Library attachment (zyra FileInput's wp.media() picker always returns a real id, never a client-only blob). */
export interface CopilotAttachment {
	id: number;
	url: string;
	name: string;
}

interface CopilotChatResponse {
	content: string;
	/** Set when this turn really created a WordPress draft (Copilot.php's own ContentCreationOrchestrator hand-off) - a real, clickable edit link, never present for an ordinary advisory reply. */
	link: CopilotChatLink | null;
	/** The real action run id behind that same draft - see CopilotChatTurn's own `runId` docblock. */
	run_id: number | null;
	/** The real `vulopilot_ai_conversations.id` this turn was just saved under (Copilot.php's own persist_conversation()) - new on the first turn of a session, unchanged on every following turn in the same session. */
	conversation_id: number;
}

/** One turn as `GET /copilot/conversations/{id}` returns it - see CopilotChatTurn's own docblock for the client-side shape this maps onto. */
interface StoredConversationTurn {
	role: 'user' | 'assistant';
	content: string;
	link?: CopilotChatLink | null;
	run_id?: number | null;
	attachments?: CopilotAttachment[];
}

interface StoredConversation {
	id: number;
	title: string;
	turns: StoredConversationTurn[];
}

/**
 * The shape WP_REST_Server::error_to_response() gives a WP_Error - what
 * actually arrives in `error.response.data` when Copilot.php returns one
 * (e.g. "No AI connection is configured…", a safety-validator rejection).
 * Raw axios rather than @zyra/core's sendApiResponse() here on purpose,
 * same reasoning as AiContentAssistantSidebar.tsx: sendApiResponse()
 * swallows the response body on any error.
 */
interface WpRestErrorBody {
	code?: string;
	message?: string;
}

/**
 * A message like "write a blog about X" really creates and saves a
 * WordPress draft (Copilot.php's own ContentCreationOrchestrator hand-off,
 * shared with the separate "Create Content" page) - that reply's `link`
 * carries the real edit URL, which ChatTab.tsx renders as a real clickable
 * link, same as AiContentAssistantSidebar.tsx already does for its own
 * identical `link` field. Every other kind of request is still advice-only,
 * per build_messages()'s own system prompt.
 *
 * `send()`'s own `autoApply` (from ChatTab.tsx's "Auto-applies (with
 * approval)" toggle) is sent as `auto_apply` and is what actually gates
 * the one real content-creation capability above - previously local UI
 * state with no server effect at all. When off, Copilot.php describes what
 * it would create instead of creating it, same "advice-only" shape every
 * other kind of request already gets.
 *
 * @param noticeKey Unique NoticeManager key for this composer's error banner, so two composers on the same page (if that ever happens) don't clobber each other's notice.
 */
export const useCopilotChat = ( noticeKey: string ) => {
	const [ turns, setTurns ] = useState< CopilotChatTurn[] >( [] );
	const [ isSending, setIsSending ] = useState( false );
	/** The real `vulopilot_ai_conversations.id` this session is saving to - null until the first successful reply of a fresh conversation, or until loadConversation() below hydrates it from a past one. */
	const [ conversationId, setConversationId ] = useState< number | null >( null );
	const [ isLoadingConversation, setIsLoadingConversation ] = useState( false );
	/** True right after a real send was blocked (or failed) because no AI connection is configured - see this hook's own docblock. Reset via `dismissCloudConnectPrompt()`. */
	const [ isCloudConnectPromptOpen, setIsCloudConnectPromptOpen ] = useState( false );
	const { status: creditsStatus } = useAiCredits();

	const dismissCloudConnectPrompt = () => setIsCloudConnectPromptOpen( false );
	/** Bumped by `startNewConversation()` - a reply still in flight from before that reset belongs to the abandoned thread and must not land in the fresh one. */
	const chatGeneration = useRef( 0 );

	const send = (
		message: string,
		contextRefs: CopilotContextRef[] = [],
		attachments: CopilotAttachment[] = [],
		autoApply: boolean = true
	) => {
		const trimmed = message.trim();

		if ( '' === trimmed || isSending ) {
			return;
		}

		if ( creditsStatus && ! creditsStatus.connected ) {
			setIsCloudConnectPromptOpen( true );
			return;
		}

		const history = turns;

		setTurns( [
			...history,
			{
				role: 'user',
				content: trimmed,
				attachments: attachments.length > 0 ? attachments : undefined,
			},
		] );
		setIsSending( true );
		const generation = chatGeneration.current;

		axios
			.post< CopilotChatResponse >(
				getApiLink( vulopilotAppLocalizer, 'copilot/chat' ),
				{
					message: trimmed,
					history,
					conversation_id: conversationId,
					auto_apply: autoApply,
					context_refs: contextRefs.map( ( ref ) =>
						'finding_group' === ref.type
							? { type: ref.type, scanner_id: ref.scannerId }
							: { type: ref.type, id: ref.id }
					),
					attachments: attachments.map( ( attachment ) => ( {
						id: attachment.id,
					} ) ),
				},
				{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
			)
			.then( ( response ) => {
				if ( generation !== chatGeneration.current ) {
					return;
				}

				setTurns( ( current ) => [
					...current,
					{
						role: 'assistant',
						content: response.data.content,
						link: response.data.link,
						runId: response.data.run_id,
					},
				] );
				setConversationId( response.data.conversation_id );
			} )
			.catch( ( error ) => {
				if ( generation !== chatGeneration.current ) {
					return;
				}

				const message = ( error?.response?.data as WpRestErrorBody | undefined )
					?.message;

				// Copilot.php's own real "No AI connection is configured."
				// (AiRequestSender) - defense-in-depth for the same
				// condition the up-front `creditsStatus` check above
				// normally already catches (e.g. that status hadn't
				// loaded yet, or the connection dropped since); same
				// real fix, so it gets the same popup instead of just
				// another error toast (AiContentAssistantSidebar.tsx's
				// own sendToAi() applies this identical check).
				if ( message?.includes( 'No AI connection is configured' ) && ! creditsStatus?.connected ) {
					setIsCloudConnectPromptOpen( true );
					return;
				}

				NoticeManager.add( {
					uniqueKey: noticeKey,
					type: 'error',
					position: 'float',
					message:
						message ??
						__(
							'Could not reach VuloPilot. Please try again.',
							'vulopilot'
						),
				} );
			} )
			.finally( () => {
				if ( generation === chatGeneration.current ) {
					setIsSending( false );
				}
			} );
	};

	/**
	 * Marks one turn's own run as rolled back - called by ChatTab.tsx after
	 * a real, successful `POST /ai-action-runs/{id}/rollback` (same pattern
	 * HistoryDetailPanel.tsx's own Undo button already uses). Matched by
	 * `runId` rather than array index, since `turns` can grow between when
	 * a turn renders its Undo button and when that click resolves.
	 */
	const markTurnUndone = ( runId: number ) => {
		setTurns( ( current ) =>
			current.map( ( turn ) =>
				turn.runId === runId ? { ...turn, undone: true } : turn
			)
		);
	};

	/**
	 * Loads a real, past conversation's full turns back into this composer
	 * (`GET /copilot/conversations/{id}`, Copilot.php's own get_conversation())
	 * - RecentConversationsCard.tsx's "click to load full history" feature.
	 * Replaces `turns` entirely and adopts `id` as the active
	 * `conversationId`, so sending a new message afterward appends to this
	 * same thread server-side instead of starting a new one. No gate here -
	 * a past conversation only exists if it was already sent for real, which
	 * already required a connected AI account; reading it back doesn't
	 * make a new AI call of its own.
	 */
	const loadConversation = ( id: number ) => {
		setIsLoadingConversation( true );

		getApiResponse< StoredConversation >(
			getApiLink( vulopilotAppLocalizer, `copilot/conversations/${ id }` ),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then( ( response ) => {
				if ( ! response ) {
					return;
				}

				setTurns(
					response.turns.map( ( turn ) => ( {
						role: turn.role,
						content: turn.content,
						link: turn.link ?? null,
						runId: turn.run_id ?? null,
						attachments: turn.attachments,
					} ) )
				);
				setConversationId( response.id );
			} )
			.finally( () => setIsLoadingConversation( false ) );
	};

	/**
	 * "New Chat" - clears `turns` and drops `conversationId` so the next
	 * `send()` starts a genuinely new `vulopilot_ai_conversations` row
	 * server-side (Copilot.php's own persist_conversation() only appends to
	 * an existing conversation when `conversation_id` is sent) instead of
	 * appending onto whatever thread was active. Purely client-side reset -
	 * the conversation just left doesn't need a corresponding request:
	 * it's already been saved turn-by-turn as it happened.
	 */
	const startNewConversation = () => {
		chatGeneration.current += 1;
		setTurns( [] );
		setConversationId( null );
		setIsSending( false );
	};

	return {
		turns,
		isSending,
		send,
		markTurnUndone,
		loadConversation,
		startNewConversation,
		isLoadingConversation,
		conversationId,
		isCloudConnectPromptOpen,
		dismissCloudConnectPrompt,
	};
};
