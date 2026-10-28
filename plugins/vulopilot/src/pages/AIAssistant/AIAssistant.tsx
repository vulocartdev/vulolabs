/* global vulopilotAppLocalizer */
import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import './AICopilot.scss';
import {
	ColumnComponent,
	ContainerComponent,
	ListComponent,
	NavigatorHeaderComponent,
	NoticeManager,
	PopupComponent,
	SectionComponent,
	TooltipComponent,
} from '@zyra/components';
import { ButtonInput, FileInput, TextInput } from '@zyra/inputs';
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
import { useGuidedCopilotFlow } from '../../services/useGuidedCopilotFlow';
import { GUIDED_FLOW_ID_BY_PROMPT } from '../../services/copilotGuidedFlows';
import { ChatInput, AiChatCard, ChatMessage, CopilotTurnBubble } from '../../components/ChatComposerCard';

/** Mirrors Copilot.php's MAX_ATTACHMENTS. */
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
 * "AI Copilot" - a single-view page.
 */
const AIAssistant = () => {
	const [chatMessage, setChatMessage] = useState('');
	const [autoApply, setAutoApply] = useState(true);
	/** Opens the "Recent conversations" popup. */
	const [isHistoryPopupOpen, setIsHistoryPopupOpen] = useState(false);
	const [issuesFilter, setIssuesFilter] = useState<IssuesFilter | null>(
		null
	);
	// Bumped on every "go to the Issues section" navigation.
	const [issuesNavToken, setIssuesNavToken] = useState(0);
	const { status: aiCreditsStatus } = useAiCredits();
	const vulocloudConnected = Boolean(aiCreditsStatus?.connected);

	/**
	 * The Issues table lives inline below the composer rather than as its own nav tab.
	 */
	const goToTab = (tab: string, filter?: IssuesFilter) => {
		if ('chat' === tab) {
			setIssuesFilter(filter ?? null);
			setIssuesNavToken((n) => n + 1);
		}
	};

	const composerRef = useRef<HTMLDivElement>(null);
	const didMountRef = useRef(false);

	// Scrolls the Issues table into view on a new filter or "View all issues" click.
	useEffect(() => {
		if (!didMountRef.current) {
			didMountRef.current = true;
			return;
		}

		scrollToId('ai-copilot-issues-section');
		// Keyed on the token so a same-value filter update still re-triggers the scroll.
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

	/** Starter prompts (and only starter prompts) walk their fixed question tree first - AI is asked only once, right at the end, via this onComplete. */
	const {
		activeFlow,
		activeStep,
		turns: guidedTurns,
		findings: guidedFindings,
		isLoadingFindings,
		startFlow,
		answerStep,
		answerFinding,
		cancelFlow,
	} = useGuidedCopilotFlow((message) => send(message, [], [], autoApply));
	const [otherAnswer, setOtherAnswer] = useState('');

	/**
	 * RecentConversationsCard.tsx's own click-to-load - the card renders below the composer.
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
	 * A starter chip walks its own fixed question tree first (`useGuidedCopilotFlow`) - no AI call
	 * until the last step. A free-typed message (not one of these chips) still goes straight to
	 * the AI as before - this tree only exists for the 9 starter prompts.
	 */
	const handleSelectPrompt = (title: string) => {
		if (isSending || activeFlow) {
			return;
		}

		const prompt = SUGGESTED_PROMPTS.find((candidate) => candidate.title === title);

		if (prompt && GUIDED_FLOW_ID_BY_PROMPT[prompt.id]) {
			startFlow(GUIDED_FLOW_ID_BY_PROMPT[prompt.id]);
			return;
		}

		send(title, [], [], autoApply);
		setChatMessage('');
	};

	const handleSend = () => {
		send(chatMessage, [], attachments, autoApply);
		setChatMessage('');
		setAttachments([]);
		// Close the Attach panel on send.
		setIsAttachPanelOpen(false);
	};

	/**
	 * Undoes a turn's content-creation run via `POST /ai-action-runs/{id}/rollback`.
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

	/** AutomationTemplatesCard's real home is Automate Work (Automations.tsx). */
	const handleSelectAutomationTemplate = (template: AutomationTemplate) => {
		window.location.hash = `#&tab=automations&automation_template=${template.id}`;
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
						{/* Scroll target for handleSelectConversation(). */}
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
										{activeFlow && (
											<div className="guided-flow-panel">
												{guidedTurns.map((turn, index) => (
													<ChatMessage
														key={`guided-turn-${index}`}
														sender={'user' === turn.role ? 'user' : 'ai'}
													>
														{turn.content}
													</ChatMessage>
												))}

												{activeStep && 'findings' === activeStep.kind ? (
													isLoadingFindings ? (
														<p className="desc">
															{__('Checking your site data…', 'vulopilot')}
														</p>
													) : guidedFindings.length > 0 ? (
														<ListComponent
															className="chip-grid"
															items={guidedFindings.map((group) => ({
																id: group.scanner_id,
																icon: 'error',
																title: group.label,
																action: () => answerFinding(group),
															}))}
														/>
													) : (
														<p className="desc">
															{__(
																'No open findings here right now - moving to the next question.',
																'vulopilot'
															)}
														</p>
													)
												) : activeStep ? (
													<>
														{activeStep.prefillHint && (
															<p className="small desc">{activeStep.prefillHint}</p>
														)}
														{(activeStep.options?.length ?? 0) > 0 && (
															<ListComponent
																className="chip-grid"
																items={(activeStep.options ?? []).map((option) => ({
																	id: option.value,
																	title: option.label,
																	action: () => answerStep(option),
																}))}
															/>
														)}
														{(activeStep.allowOther ?? 0 === (activeStep.options?.length ?? 0)) && (
															<div className="guided-flow-other-row">
																<TextInput
																	value={otherAnswer}
																	onChange={(value: string) => setOtherAnswer(value)}
																	placeholder={__('Or type your own answer…', 'vulopilot')}
																/>
																<ButtonInput
																	buttons={{
																		text: __('Answer', 'vulopilot'),
																		color: 'purple',
																		disabled: '' === otherAnswer.trim(),
																		onClick: () => {
																			answerStep({ value: 'other', label: otherAnswer.trim() });
																			setOtherAnswer('');
																		},
																	}}
																/>
															</div>
														)}
													</>
												) : null}

												<ButtonInput
													buttons={{
														text: __('Cancel', 'vulopilot'),
														color: 'gray',
														onClick: cancelFlow,
													}}
												/>
											</div>
										)}

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
									// Enter-to-send guard lives in ChatComposerCard.tsx now.
									<ChatInput
										value={chatMessage}
										onChange={setChatMessage}
										onSend={handleSend}
										disabled={isSending || !!activeFlow}
										placeholder={
											activeFlow
												? __('Answer the question above to continue…', 'vulopilot')
												: __('Ask VuloPilot anything about your website…', 'vulopilot')
										}
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
											window.location.hash =
												'#&tab=reports&subtab=history';
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
