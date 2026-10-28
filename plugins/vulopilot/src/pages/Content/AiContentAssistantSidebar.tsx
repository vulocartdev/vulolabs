/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import axios from 'axios';
import InsufficientCreditsNotice from '../../components/AiCredits/InsufficientCreditsNotice';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { NoticeManager, PopupComponent } from '@zyra/components';
import { SelectInput } from '@zyra/inputs';
import { ChatInput, AiChatCard, CopilotTurnBubble } from '../../components/ChatComposerCard';
import ShowProPopup from '../../components/Popup/Popup';
import { useAiCredits } from '../../services/useAiCredits';

interface WpRestPost {
	id: number;
	title: { rendered: string };
}

interface PageOption {
	value: string;
	label: string;
}

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
	 
	// eslint-disable-next-line no-unused-vars
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

const AiContentAssistantSidebar = () => {
	const [message, setMessage] = useState('');
	const [turns, setTurns] = useState<ChatTurn[]>([]);
	const [isSending, setIsSending] = useState(false);
	// Set the moment a chip is picked; cleared once the user's next message
	// has been folded into that chip's own build() and sent for real.
	const [pendingChip, setPendingChip] = useState<PromptChip | null>(null);
	const [isCloudConnectPromptOpen, setIsCloudConnectPromptOpen] = useState(false);
	const [pageOptions, setPageOptions] = useState<PageOption[]>([]);
	const [isLoadingPageOptions, setIsLoadingPageOptions] = useState(false);
	const { status: creditsStatus } = useAiCredits();

	/**
	 * "Create meta title" is the one chip whose answer is a real page/post, not free text -
	 * load a searchable list the moment it's picked (SelectInput filters the typed text against
	 * `label` itself, so this alone gives real search-as-you-type, same as ContentToolPopup.tsx's
	 * own `post-picker` field).
	 */
	useEffect(() => {
		if ('meta-title' !== pendingChip?.id) {
			return;
		}

		setIsLoadingPageOptions(true);

		Promise.all([
			getApiResponse<WpRestPost[]>(
				getApiLink(
					vulopilotAppLocalizer,
					'posts?per_page=100&orderby=title&order=asc&_fields=id,title',
					'wp/v2'
				),
				{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
			),
			getApiResponse<WpRestPost[]>(
				getApiLink(
					vulopilotAppLocalizer,
					'pages?per_page=100&orderby=title&order=asc&_fields=id,title',
					'wp/v2'
				),
				{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
			),
		])
			.then(([posts, pages]) => {
				setPageOptions(
					[...(posts || []), ...(pages || [])].map((post) => ({
						value: String(post.id),
						label: post.title.rendered || `#${post.id}`,
					}))
				);
			})
			.finally(() => setIsLoadingPageOptions(false));
	}, [pendingChip]);

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
	 * Picking a chip doesn't send anything to the AI yet.
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

	/**
	 * Shared by both answer paths: typing free text (`handleSend`) and picking a page from the
	 * search dropdown (`handlePagePicked`) - either way, `answer` is folded into the pending
	 * chip's own build() the same way.
	 */
	const submitAnswer = (answer: string) => {
		if ('' === answer || isSending) {
			return;
		}

		// Same up-front check `handleChipClick()` already makes.
		if (creditsStatus && !creditsStatus.connected) {
			setIsCloudConnectPromptOpen(true);
			return;
		}

		const history = turns;
		const realMessage = pendingChip ? pendingChip.build(answer) : answer;

		setTurns([...history, { role: 'user', content: realMessage }]);
		setMessage('');
		setPendingChip(null);
		sendToAi(realMessage, history);
	};

	const handleSend = () => submitAnswer(message.trim());

	/**
	 * Selecting a page submits immediately - there's nothing left to type once a real page is
	 * picked.
	 */
	const handlePagePicked = (pageId: string) => {
		const picked = pageOptions.find((option) => option.value === pageId);

		if (picked) {
			submitAnswer(picked.label);
		}
	};

	// AiChatCard's own onSelectPrompt only hands back a prompt's title (the shape every real
	// composer's prompt grid shares).
	const handleSelectPrompt = (title: string) => {
		const chip = PROMPT_CHIPS.find((c) => c.title === title);

		if (chip) {
			handleChipClick(chip);
		}
	};

	/**
	 * "New Chat": clears the local turns, the typed text and any unanswered chip question.
	 */
	const handleNewChat = () => {
		setTurns([]);
		setMessage('');
		setPendingChip(null);
	};

	/**
	 * "Chat History" - unlike AI Copilot's own per-conversation popup.
	 */
	const handleOpenHistory = () => {
		window.location.hash = '#&tab=reports&subtab=history';
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
					'meta-title' === pendingChip?.id ? (
						<SelectInput
							type="single-select"
							options={pageOptions}
							value={null}
							onChange={(value) =>
								handlePagePicked(String(value ?? ''))
							}
							placeholder={
								isLoadingPageOptions
									? __('Loading pages…', 'vulopilot')
									: __('Search for a page…', 'vulopilot')
							}
							disabled={isSending || isLoadingPageOptions}
						/>
					) : (
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
					)
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
