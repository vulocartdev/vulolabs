/* global vulopilotAppLocalizer */
import { useState } from 'react';
import axios from 'axios';
import InsufficientCreditsNotice from '../../components/AiCredits/InsufficientCreditsNotice';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink } from '@zyra/core';
import { NoticeManager, PopupComponent } from '@zyra/components';
import { ChatInput, AiChatCard, CopilotTurnBubble } from '../../components/ChatComposerCard';
import ShowProPopup from '../../components/Popup/Popup';
import { useAiCredits } from '../../services/useAiCredits';

interface ChatLink {
	url: string;
	label: string;
}

interface ChatTurn {
	role: 'user' | 'assistant';
	content: string;
	link?: ChatLink | null;
}

interface ChatResponse {
	content: string;
	link: ChatLink | null;
}

interface WpRestErrorBody {
	code?: string;
	message?: string;
}

interface PromptChip {
	id: string;
	icon: string;
	/** The short label shown on the chip itself. */
	title: string;
	/** The clarifying question asked (as a local, non-AI chat turn) once this chip is picked. */
	ask: string;
	/** Combines the user's next reply into the real instruction actually sent to the AI. */
	// eslint-disable-next-line no-unused-vars -- named param on a type-only call signature; base no-unused-vars doesn't recognize TS call-signature parameters.
	build: (answer: string) => string;
}

const PROMPT_CHIPS: PromptChip[] = [
	{
		id: 'blog',
		icon: 'document',
		title: __('Write a blog', 'vulopilot'),
		ask: __('What should the blog be about?', 'vulopilot'),
		build: (answer) =>
			/* translators: %s: real user-typed topic. */
			sprintf(__('Write a blog about %s', 'vulopilot'), answer),
	},
	{
		id: 'product-description',
		icon: 'cart',
		title: __('Create a product description', 'vulopilot'),
		ask: __(
			'Which product is this for? Include the product name and a few key details.',
			'vulopilot'
		),
		build: (answer) =>
			sprintf(
				/* translators: %s: real user-typed product details. */
				__('Create a product description for %s', 'vulopilot'),
				answer
			),
	},
	{
		id: 'faqs',
		icon: 'question',
		title: __('Generate FAQs', 'vulopilot'),
		ask: __('What topic or policy should these FAQs cover?', 'vulopilot'),
		build: (answer) =>
			sprintf(
				/* translators: %s: topic or policy the FAQs should cover. */
				__('Generate FAQs for %s', 'vulopilot'),
				answer
			),
	},
	{
		id: 'meta-title',
		icon: 'price',
		title: __('Create meta title', 'vulopilot'),
		ask: __('Which page is this meta title for?', 'vulopilot'),
		build: (answer) =>
			sprintf(
				/* translators: %s: page the meta title is for. */
				__('Create meta title for %s', 'vulopilot'),
				answer
			),
	},
	{
		id: 'cta',
		icon: 'edit',
		title: __('Write a call-to-action', 'vulopilot'),
		ask: __(
			'What product or service is this call-to-action for?',
			'vulopilot'
		),
		build: (answer) =>
			sprintf(
				/* translators: %s: product or service the call-to-action is for. */
				__('Write a call-to-action for %s', 'vulopilot'),
				answer
			),
	},
];

/**
 * "AI Content Assistant" - a real chat, `POST /content-assistant/chat`
 * (classes/RestAPI/Controllers/ContentAssistant.php), which sends the
 * conversation through the same real AI request sender
 * (AI\AiRequestSender) AI Actions/GEO scoring already use. It answers for real
 * once this site is connected (`AiCreditsConnection::is_connected()`,
 * Settings → Connections); when it isn't, `sendToAi()` below recognizes that exact
 * real "No AI connection is configured." condition and opens the connect popup -
 * the same real free "Claim free AI Credits" flow AiCreditsIndicator.tsx's own
 * dropdown already
 * offers - instead of a dead-end NoticeManager error toast. Every other
 * real error (a safety-validator rejection, a provider's own failure)
 * still shows as that toast. The running conversation (`turns`) is kept
 * client-side and
 * sent back as `history` on every call - there's no conversation entity
 * in this codebase to persist it against; every real call is still
 * recorded to `vulopilot_ai_history` server-side regardless (Reports'
 * own AI Usage report already reads that table). Prompt chips prefill
 * the composer only, same harmless pattern as AI Copilot's ChatTab.tsx.
 *
 * A "write a blog"/"create a landing page"/"create a product description"
 * style message doesn't come back as raw generated text: the controller
 * runs the real AIAction (generate-blog/generate-landing-page/
 * generate-product-description - the same ones ContentToolsGrid.tsx's own
 * tiles run), actually creates and saves the WordPress draft, and this
 * response's `link` carries the real edit URL, rendered below as a real
 * clickable `<a>` - never markdown-in-text, since ChatMessage
 * renders `content` as plain text.
 */
const AiContentAssistantSidebar = () => {
	const [message, setMessage] = useState('');
	const [turns, setTurns] = useState<ChatTurn[]>([]);
	const [isSending, setIsSending] = useState(false);
	// Set the moment a chip is picked; cleared once the user's next message
	// has been folded into that chip's own build() and sent for real.
	const [pendingChip, setPendingChip] = useState<PromptChip | null>(null);
	const [isCloudConnectPromptOpen, setIsCloudConnectPromptOpen] = useState(false);
	const { status: creditsStatus } = useAiCredits();

	const sendToAi = (realMessage: string, displayedTurns: ChatTurn[]) => {
		setIsSending(true);

		axios
			.post<ChatResponse>(
				getApiLink(vulopilotAppLocalizer, 'content-assistant/chat'),
				{ message: realMessage, history: displayedTurns },
				{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
			)
			.then((response) => {
				setTurns((current) => [
					...current,
					{
						role: 'assistant',
						content: response.data.content,
						link: response.data.link,
					},
				]);
			})
			.catch((error) => {
				const message = (error?.response?.data as WpRestErrorBody | undefined)?.message;

				if (message?.includes('No AI connection is configured') && !creditsStatus?.connected) {
					setIsCloudConnectPromptOpen(true);
					return;
				}

				NoticeManager.add({
					uniqueKey: 'vulopilot-content-assistant-error',
					type: 'error',
					position: 'float',
					message:
						message ??
						__(
							'Could not reach the AI Content Assistant. Please try again.',
							'vulopilot'
						),
				});
			})
			.finally(() => setIsSending(false));
	};

	/**
	 * Picking a chip doesn't send anything to the AI yet - it asks the real
	 * follow-up question first (a local, scripted chat turn, not an AI
	 * response) and waits for the user's next message to answer it. That
	 * reply gets folded into the chip's own build() into one real, useful
	 * instruction (e.g. "Write a blog about eco-friendly packaging") -
	 * what's actually shown as the user's turn and sent to the AI, not the
	 * bare reply on its own.
	 *
	 * Guarded on `pendingChip` the same way `handleSend()` already guards
	 * on `isSending` - the chip grid stays clickable the whole time (it's
	 * not disabled/hidden once a question is asked), so without this a
	 * user clicking the same chip again while its question is still
	 * unanswered re-ran this and appended a 2nd, identical "assistant"
	 * turn - confirmed live: 4 clicks on "Write a blog" stacked 4 copies
	 * of "What should the blog be about?" in the chat. One open question
	 * at a time is the real, correct behavior; the user must answer (or
	 * the request must finish) before another chip can ask a new one.
	 *
	 * Checked up front, before even asking the clarifying question - per
	 * direct instruction ("when click work on description then the
	 * connect popup show, not functionality work until the account is
	 * connected"): picking a chip with no AI service connected opens the connect
	 * popup immediately, rather than walking through a
	 * question the eventual real send would just fail on anyway.
	 */
	const handleChipClick = (chip: PromptChip) => {
		if (isSending || pendingChip) {
			return;
		}

		if (creditsStatus && !creditsStatus.connected) {
			setIsCloudConnectPromptOpen(true);
			return;
		}

		setPendingChip(chip);
		setMessage('');
		setTurns((current) => [
			...current,
			{ role: 'assistant', content: chip.ask },
		]);
	};

	const handleSend = () => {
		const trimmed = message.trim();

		if ('' === trimmed || isSending) {
			return;
		}

		// Same up-front check `handleChipClick()` already makes - this is
		// the one still needed for a message typed directly into "Ask
		// Anything…" without going through a chip first.
		if (creditsStatus && !creditsStatus.connected) {
			setIsCloudConnectPromptOpen(true);
			return;
		}

		const history = turns;
		const realMessage = pendingChip ? pendingChip.build(trimmed) : trimmed;

		setTurns([...history, { role: 'user', content: realMessage }]);
		setMessage('');
		setPendingChip(null);
		sendToAi(realMessage, history);
	};

	// AiChatCard's own onSelectPrompt only hands back a prompt's title (the
	// shape every real composer's prompt grid shares) - looked back up
	// against PROMPT_CHIPS here since handleChipClick needs the chip's own
	// `ask`/`build`, not just its title.
	const handleSelectPrompt = (title: string) => {
		const chip = PROMPT_CHIPS.find((c) => c.title === title);

		if (chip) {
			handleChipClick(chip);
		}
	};

	/**
	 * "New Chat" - this composer has no server-side conversation entity to
	 * reset (see this file's own docblock: `turns` is client-side-only,
	 * sent back as plain `history` on every call), so starting fresh is
	 * just clearing everything local: the running turns, whatever's typed,
	 * and a still-unanswered chip question.
	 */
	const handleNewChat = () => {
		setTurns([]);
		setMessage('');
		setPendingChip(null);
	};

	/**
	 * "Chat History" - unlike AI Copilot's own per-conversation popup, this
	 * composer has no `vulopilot_ai_conversations` row to reopen a past
	 * thread from (this file's own docblock). What IS real: every message
	 * that actually creates content runs through the same
	 * `ContentCreationOrchestrator` AI Copilot's own content-creation turns
	 * do (ContentAssistant.php), which logs a real `vulopilot_ai_action_runs`
	 * row/activity-log "change" event - exactly what Reports → History's
	 * own "Change" filter (HistoryTab.tsx, moved there from AI Copilot)
	 * already lists. So "Chat History" here is a real navigation to that
	 * existing report rather than a reopen-this-thread popup - there's
	 * nothing to reopen, but there's real history to see.
	 */
	const handleOpenHistory = () => {
		window.location.href = '?page=vulopilot#&tab=reports&subtab=history';
	};

	return (
		<>
			<InsufficientCreditsNotice />
			<AiChatCard
				emptyDesc={sprintf(
					/* translators: %s: the real logged-in WP user's own display name */
					__(
						'Hi %s! I can help you create amazing content. Try one of these prompt ideas or ask your own.',
						'vulopilot'
					),
					vulopilotAppLocalizer.current_user_display_name
				)}
				prompts={PROMPT_CHIPS}
				onSelectPrompt={handleSelectPrompt}
				onNewChat={turns.length > 0 || isSending ? handleNewChat : undefined}
				onOpenHistoryPopup={handleOpenHistory}
				turns={turns}
				renderTurn={(turn, index) => (
					<CopilotTurnBubble key={index} turn={turn} />
				)}
				isSending={isSending}
				sendingSpinnerClassName="content-assistant-spinner"
				composer={
					<ChatInput
						value={message}
						onChange={setMessage}
						onSend={handleSend}
						disabled={isSending}
						placeholder={
							pendingChip
								? __('Type your answer…', 'vulopilot')
								: __('Ask Anything…', 'vulopilot')
						}
					/>
				}
			/>
			<PopupComponent
				open={isCloudConnectPromptOpen}
				onClose={() => setIsCloudConnectPromptOpen(false)}
				width={22}
				height="auto"
				position="lightbox"
			>
				<ShowProPopup vulocloud />
			</PopupComponent>
		</>
	);
};

export default AiContentAssistantSidebar;
