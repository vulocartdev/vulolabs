/* global vulopilotAppLocalizer */
import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import './AICopilot.scss';
import {
	ColumnComponent,
	ContainerComponent,
	NavigatorHeaderComponent,
	NoticeManager,
	PopupComponent,
	SectionComponent,
	TooltipComponent,
} from '@zyra/components';
import { ButtonInput, FileInput } from '@zyra/inputs';
import { getApiLink, scrollToId, sendApiResponse } from '@zyra/core';
import ShowProPopup from '../../components/Popup/Popup';
import { useAiCredits } from '../../services/useAiCredits';
import NeedsAttentionCard, { IssuesFilter } from './NeedsAttentionCard';
import RecentConversationsCard from './RecentConversationsCard';
import RecommendedActionsCard from './RecommendedActionsCard';
import IssuesList from './IssuesList';
import AutomationTemplatesCard from '../Automations/AutomationsTemplatesCard';
import { AutomationTemplate } from '../Automations/automationsTypes';
import { useFilterSlot } from '../../services/useFilterSlot';
import {
	useCopilotChat,
	CopilotAttachment,
} from '../../services/useCopilotChat';
import { ChatInput, AiChatCard, CopilotTurnBubble } from '../../components/ChatComposerCard';

/** Mirrors Controllers\Copilot.php's own MAX_ATTACHMENTS - capped client-side too so the composer never offers to add more than the server would actually resolve. */
const MAX_ATTACHMENTS = 3;

const ATTACHMENT_ACCEPT =
	'.txt,.csv,text/plain,text/csv,.jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp';

/** "Try asking me…" starter prompts - static UI copy, not fetched data. */
const SUGGESTED_PROMPTS = [
	{ id: 'homepage', icon: 'home', title: __('Improve my homepage', 'vulopilot') },
	{ id: 'traffic', icon: 'bar-chart', title: __('Why is traffic dropping?', 'vulopilot') },
	{ id: 'vitals', icon: 'bar-chart', title: __('Fix my Core Web Vitals', 'vulopilot') },
	{ id: 'schema', icon: 'coding', title: __('Generate schema', 'vulopilot') },
	{ id: 'checkout', icon: 'cart', title: __('Improve checkout', 'vulopilot') },
	{ id: 'woocommerce', icon: 'woocommerce', title: __('Optimize WooCommerce', 'vulopilot') },
	{ id: 'blog', icon: 'edit', title: __('Write a blog', 'vulopilot') },
	{ id: 'security', icon: 'security', title: __('Find security issues', 'vulopilot') },
	{ id: 'geo', icon: 'geo-location', title: __('Make my site GEO ready', 'vulopilot') },
];

/**
 * "AI Copilot" - a single-view page. NeedsAttentionCard.tsx/
 * RecentConversationsCard.tsx/RecommendedActionsCard.tsx/IssuesList.tsx
 * stay as their own files: each is a self-contained concern with its own
 * state and API calls, not a thin pass-through.
 *
 * "Attach" opens zyra's FileInput, which - now that Admin.php calls
 * wp_enqueue_media() - hands back a WP Media Library attachment {id, url}
 * via wp.media(), never a client-only blob. Sent as `attachments` on the
 * next `POST /copilot/chat` and re-resolved server-side
 * (Copilot.php's build_extra_context()); this component only carries an id.
 *
 * The header's "Online"/"Offline" badge reads `AiCreditsConnection::
 * is_connected()` via `useAiCredits()` (`GET /ai-credits/status`), the
 * same gate `AI\AiRequestSender::send()` actually checks - not the account-login
 * flag in `vulopilotAppLocalizer`, which is a separate, unrelated credential: a
 * site can have AI credits
 * connected with no personal login connected, so that flag would show a
 * misleading "Offline" while chat still works. Loading state fails
 * closed (`status?.connected` false-y default), same convention
 * useContentGate.tsx uses.
 */
const AIAssistant = () => {
	const [chatMessage, setChatMessage] = useState('');
	const [autoApply, setAutoApply] = useState(true);
	/** Opens the "Recent conversations" popup - the header button lives in this page's own header, the popup and the real conversation data/selection it needs render further down. */
	const [isHistoryPopupOpen, setIsHistoryPopupOpen] = useState(false);
	const [issuesFilter, setIssuesFilter] = useState<IssuesFilter | null>(
		null
	);
	// Bumped on every "go to the Issues section" navigation, even when
	// `issuesFilter` resolves to the same value as before (e.g. clicking
	// "View all issues" when it was already null) - the scroll-into-view
	// effect below keys off this instead of `issuesFilter` so a same-value
	// React state bailout doesn't silently swallow the scroll.
	const [issuesNavToken, setIssuesNavToken] = useState(0);
	const { status: aiCreditsStatus } = useAiCredits();
	const vulocloudConnected = Boolean(aiCreditsStatus?.connected);

	/**
	 * The Issues table lives inline below the composer rather than as its
	 * own nav tab - NeedsAttentionCard's "View all issues"/group-row clicks
	 * still pass through here as `onNavigateTab('chat', filter)`, so this
	 * still needs to update the filter that table reads. `tab` itself is
	 * otherwise unused now that Chat is the only surface this page renders.
	 */
	const goToTab = (tab: string, filter?: IssuesFilter) => {
		if ('chat' === tab) {
			setIssuesFilter(filter ?? null);
			setIssuesNavToken((n) => n + 1);
		}
	};

	const composerRef = useRef<HTMLDivElement>(null);
	const didMountRef = useRef(false);

	// Scrolls the appended Issues table into view whenever NeedsAttentionCard
	// sends a new filter (or a bare "View all issues" click) - without this
	// the click would silently do nothing visible if the table is off-screen.
	// Skipped on first mount so loading this page itself never auto-scrolls.
	useEffect(() => {
		if (!didMountRef.current) {
			didMountRef.current = true;
			return;
		}

		scrollToId('ai-copilot-issues-section');
		// eslint-disable-next-line react-hooks/exhaustive-deps -- keyed on the token specifically so a same-value issuesFilter update (e.g. "View all issues" when it was already null) still re-triggers the scroll.
	}, [issuesNavToken]);

	const {
		turns,
		isSending,
		send,
		markTurnUndone,
		loadConversation,
		startNewConversation,
		isCloudConnectPromptOpen,
		dismissCloudConnectPrompt,
	} = useCopilotChat('vulopilot-copilot-chat-error');
	const [undoingRunId, setUndoingRunId] = useState<number | null>(null);

	/**
	 * RecentConversationsCard.tsx's own click-to-load - the card renders
	 * below the composer, so loading a past thread also scrolls the
	 * composer back into view rather than leaving the user looking at the
	 * still-visible "Recent conversations" list while the turns above it
	 * silently change.
	 */
	const handleSelectConversation = (id: number) => {
		loadConversation(id);
		composerRef.current?.scrollIntoView({
			behavior: 'smooth',
			block: 'start',
		});
	};

	const [attachments, setAttachments] = useState<CopilotAttachment[]>([]);
	const [isAttachPanelOpen, setIsAttachPanelOpen] = useState(false);

	/**
	 * A starter chip starts the chat straight away - its title is sent as
	 * the first message, no separate Send click needed.
	 */
	const handleSelectPrompt = (title: string) => {
		if (isSending) {
			return;
		}

		send(title, [], [], autoApply);
		setChatMessage('');
	};

	const handleSend = () => {
		send(chatMessage, [], attachments, autoApply);
		setChatMessage('');
		setAttachments([]);
		// Close the Attach panel on send - otherwise it stays open and
		// reverts to its own empty "Drag and drop" state, which reads as if
		// nothing was actually sent.
		setIsAttachPanelOpen(false);
	};

	/**
	 * Undoes a turn's own content-creation run - the same real
	 * `POST /ai-action-runs/{id}/rollback` (AIActions\ActionRunner::rollback())
	 * HistoryDetailPanel.tsx's own Undo button already calls, just reachable
	 * right here next to what it undoes instead of only from History.
	 */
	const handleUndo = (runId: number) => {
		setUndoingRunId(runId);

		sendApiResponse<{ success?: boolean }>(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, `ai-action-runs/${runId}/rollback`),
			{}
		)
			.then((response) => {
				NoticeManager.add({
					uniqueKey: `copilot-chat-rollback-${runId}`,
					type: response ? 'success' : 'error',
					position: 'float',
					message: response
						? __('Change undone.', 'vulopilot')
						: __(
								'Could not undo this change. Please try again.',
								'vulopilot'
							),
				});

				if (response) {
					markTurnUndone(runId);
				}
			})
			.finally(() => setUndoingRunId(null));
	};

	const toggleAttachPanel = () => {
		setIsAttachPanelOpen((open) => !open);
	};

	const handleFileInputChange = (
		value:
			| { id?: number; url: string }
			| { id?: number; url: string }[]
			| ''
	) => {
		const raw = Array.isArray(value) ? value : value ? [value] : [];
		const valid = raw.filter(
			(file): file is { id: number; url: string } =>
				'number' === typeof file.id
		);

		if (valid.length < raw.length) {
			NoticeManager.add({
				uniqueKey: 'vulopilot-chat-attach-local-only',
				type: 'error',
				position: 'float',
				message: __(
					"That file wasn't uploaded - use the Upload File button so it's saved to the Media Library and readable by the AI.",
					'vulopilot'
				),
			});
		}

		setAttachments(
			valid.slice(0, MAX_ATTACHMENTS).map((file) => ({
				id: file.id,
				url: file.url,
				name: file.url.split('#').pop()?.split('/').pop() || file.url,
			}))
		);
	};

	const removeAttachment = (id: number) =>
		setAttachments((current) => current.filter((file) => file.id !== id));

	const automationsPanelSlot = useFilterSlot<{ Wizard?: unknown }>(
		'vulopilot_automations_panel'
	);

	/**
	 * AutomationTemplatesCard's real home is Automate Work
	 * (`ManageAutomationsSection.tsx`, via `Automations.tsx`) - this
	 * preview navigates there, carrying the picked template through the
	 * `automation_template` URL param so the wizard opens already seeded.
	 * Only reached for an unlocked row - a locked one is replaced by
	 * AutomationTemplatesCard.tsx's own content-gate popup instead.
	 */
	const handleSelectAutomationTemplate = (template: AutomationTemplate) => {
		window.location.href = `${vulopilotAppLocalizer.admin_url}#&tab=automations&automation_template=${template.id}`;
	};

	return (
		<>
			<NavigatorHeaderComponent
				headerIcon="ai"
				headerTitle={
					(
						<>
							{__('AI Copilot', 'vulopilot')}
							<TooltipComponent
								text={__(
									'Ask a question or pick a suggested prompt. VuloPilot checks your live site data - scans, traffic, security, and store health - and answers with real recommendations. Ask for a blog post and it writes one and saves it as a real draft, logged to History with a real Undo. Everything else - SEO, performance, security, and other fixes - is advice only for now.',
									'vulopilot'
								)}
								position="bottom"
							>
								<i className="adminfont-info ai-copilot-title-info" />
							</TooltipComponent>
						</>
					) as unknown as string
				}
				headerDescription={__(
					'Your always-on AI assistant for WordPress. Ask anything, get intelligent answers and take action.',
					'vulopilot'
				)}
				showPremiumLink={false}
				badges={[
					vulocloudConnected
						? {
								text: `● ${__('Online', 'vulopilot')}`,
								color: 'green',
							}
						: {
								text: `● ${__('Offline', 'vulopilot')}`,
								color: 'red',
							},
				]}
			/>
			<ContainerComponent general>
				<ContainerComponent>
					<ColumnComponent grid={8}>
						{/* Scroll target for handleSelectConversation() - loading a past thread from the "Recent conversations" sidebar brings this composer back into view. */}
						<div ref={composerRef}>
							<AiChatCard
								cardClassName="ai-copilot-main-chat"
								cardTitle={__('Chat with VuloPilot', 'vulopilot')}
								cardDesc={__(
									'Your AI assistant for site health, SEO, security and content. Review every change before it is applied.',
									'vulopilot'
								)}
								onNewChat={
									turns.length > 0 || isSending
										? startNewConversation
										: undefined
								}
								onOpenHistoryPopup={() => setIsHistoryPopupOpen(true)}
								emptyDesc={__(
									'Ask me anything about your website, performance, security, content and more.',
									'vulopilot'
								)}
								prompts={SUGGESTED_PROMPTS}
								onSelectPrompt={handleSelectPrompt}
								turns={turns}
								renderTurn={(turn, index) => (
									<CopilotTurnBubble
										key={index}
										turn={turn}
										undoingRunId={undoingRunId}
										onUndo={handleUndo}
									/>
								)}
								isSending={isSending}
								beforeComposer={
									<>
										{attachments.length > 0 && (
											<div className="chat-composer-chips">
												{attachments.map((attachment) => (
													<span
														className="chat-composer-chip"
														key={`attachment-${attachment.id}`}
													>
														<i className="adminfont-attachment" />
														{attachment.name}
														<i
															className="adminfont-close"
															onClick={() =>
																removeAttachment(attachment.id)
															}
														/>
													</span>
												))}
											</div>
										)}

										{isAttachPanelOpen && (
											<div className="chat-composer-panel">
												<p className="chat-composer-panel-label">
													{__(
														'Attach a .txt/.csv file to read, or a .jpg/.png/.gif/.webp image for the AI to actually see - uploaded to your Media Library first.',
														'vulopilot'
													)}
												</p>
												<FileInput
													multiple
													accept={ATTACHMENT_ACCEPT}
													openUploader={__('Upload File', 'vulopilot')}
													wrapperClass="chat-composer-fileinput"
													imageSrc={attachments.map((attachment) => ({
														id: attachment.id,
														url: attachment.url,
													}))}
													onChange={handleFileInputChange}
												/>
											</div>
										)}
									</>
								}
								composer={
									// The Enter-to-send bubble-propagation guard every
									// real composer needs now lives once in
									// ChatComposerCard.tsx itself (wraps `composer`
									// there) rather than duplicated per consumer.
									<ChatInput
										value={chatMessage}
										onChange={setChatMessage}
										onSend={handleSend}
										disabled={isSending}
										placeholder={__(
											'Ask VuloPilot anything about your website…',
											'vulopilot'
										)}
										onAttach={toggleAttachPanel}
										attachLabel={__('Attach', 'vulopilot')}
										autoApply={{
											checked: autoApply,
											onChange: setAutoApply,
											label: (
												<>
													{__('Auto-applies', 'vulopilot')}
													<TooltipComponent
														text={__(
															'When on, VuloPilot applies a fix itself and still asks you to approve it before it goes live - nothing changes on your site without your say.',
															'vulopilot'
														)}
													>
														<i className="adminfont-info chat-input-autoapply-info" />
													</TooltipComponent>
												</>
											),
										}}
									/>
								}
							/>
						</div>
						<PopupComponent
							open={isHistoryPopupOpen}
							onClose={() => setIsHistoryPopupOpen(false)}
							width={25}
							height="70%"
							header={{
								icon: 'live-chat',
								title: __('Recent conversations', 'vulopilot'),
								description: __(
									'Your past conversations with AI Copilot.',
									'vulopilot'
								),
							}}
							footer={
								<ButtonInput
									buttons={{
										text: __('View all history', 'vulopilot'),
										rightIcon: 'arrow-right',
										color: 'text-purple',
										onClick: (e) => {
											e.preventDefault();
											window.location.href =
												'?page=vulopilot#&tab=reports&subtab=history';
										},
									}}
								/>
							}
						>
							<RecentConversationsCard
								onSelectConversation={(id: number) => {
									handleSelectConversation(id);
									setIsHistoryPopupOpen(false);
								}}
							/>
						</PopupComponent>
						<PopupComponent
							open={isCloudConnectPromptOpen}
							onClose={dismissCloudConnectPrompt}
							width={22}
							height="auto"
							position="lightbox"
						>
							<ShowProPopup vulocloud />
						</PopupComponent>
						<RecommendedActionsCard onNavigateTab={goToTab} />
					</ColumnComponent>

					<ColumnComponent grid={4}>
						<NeedsAttentionCard onNavigateTab={goToTab} />
						<AutomationTemplatesCard
							onSelectTemplate={handleSelectAutomationTemplate}
							isAutomationsActive={!!automationsPanelSlot?.Wizard}
						/>
					</ColumnComponent>

					<ColumnComponent>
						<SectionComponent
							title={__('Issues', 'vulopilot')}
							desc={__(
								'Findings from your most recent scans, grouped by check.',
								'vulopilot'
							)}
						/>
					</ColumnComponent>
					<IssuesList
						key={issuesFilter?.scannerId ?? 'all'}
						initialScannerId={issuesFilter?.scannerId}
						initialCategory={issuesFilter?.category}
					/>
				</ContainerComponent>
			</ContainerComponent>
		</>
	);
};

export default AIAssistant;
