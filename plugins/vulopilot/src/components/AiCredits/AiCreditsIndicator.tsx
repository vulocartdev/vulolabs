/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { NoticeComponent, PopupComponent } from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import { buyCreditsUrl, formatCredits, useAiCredits } from '../../services/useAiCredits';
import { INSUFFICIENT_CREDITS_EVENT } from './insufficientCredits';
import { VuloCloudInlineNotice } from '../Popup/Popup';
import './AiCreditsIndicator.scss';

/**
 * The persistent "⚡ N AI Credits" indicator (architecture plan §21) -
 * mounted once, via zyra's own `HeaderComponent`'s `beforeSearch` prop in
 * app.tsx, which renders it inline in the header's right-section row,
 * immediately before the "Modules & Settings" search box (not through
 * that component's own `utilityList` prop: that prop's `toggleIcon` only
 * ever renders a plain icon-font glyph - see PopupComponent's own real
 * toggle-icon behavior - so it has nowhere to put the live number itself;
 * this component drives its own `PopupComponent` in fully-controlled mode
 * instead, with the credit count as its own custom, always-visible
 * trigger).
 */
const AiCreditsIndicator = () => {
	const { status, isLoading, refresh } = useAiCredits();
	const [isOpen, setIsOpen] = useState(false);

	useEffect(() => {
		const onInsufficient = () => refresh();
		window.addEventListener(INSUFFICIENT_CREDITS_EVENT, onInsufficient);
		return () => window.removeEventListener(INSUFFICIENT_CREDITS_EVENT, onInsufficient);
	}, [refresh]);

	if (isLoading || !status) {
		return null;
	}

	return (
		<div className="ai-credits-indicator">
			<ButtonInput
				buttons={{
					text: `⚡ ${status.connected
							? sprintf(
								/* translators: %s: real remaining AI Credit balance, e.g. "76.550". */
								__('%s AI Credits', 'vulopilot'),
								formatCredits(status.credits)
							)
							: __('Claim free AI Credits', 'vulopilot')
						}`,
					color: 'orange-bg',
					onClick: () => setIsOpen(!isOpen),
				}}
			/>

			<PopupComponent
				width={35}
				height="fit-content"
				open={isOpen}
				onClose={() => setIsOpen(false)}
			>
				{status.connected ? (
					<AiCreditsBalancePanel
						status={status}
						onRefresh={refresh}
					/>
				) : (
					<VuloCloudInlineNotice />
				)}
			</PopupComponent>
		</div>
	);
};

const AiCreditsBalancePanel = ({
	status,
	onRefresh,
}: {
	status: import('../../services/useAiCredits').AiCreditsStatus;
	onRefresh: () => void;
}) => {
	const exhausted = status.credits <= 0;
	// Depletion meter - how much of what's ever been earned is still
	// available, not how much has been used (an all-time-earned account
	// with nothing spent yet reads as "full", same intuition as "remaining"
	// being this panel's own headline number). Earned starts at 0 for a
	// brand-new connection, so this guards the same divide-by-zero every
	// other real percentage in this codebase already does.
	const remainingPercent =
		status.lifetime_earned > 0
			? Math.min(100, Math.round((status.credits / status.lifetime_earned) * 100))
			: 0;

	return (
		<div className="ai-credits-balance-panel">
			<div className="ai-credits-balance-panel-icon">
				<i className="adminfont-wallet" />
			</div>
			<div className="ai-credits-balance-panel-count">
				{formatCredits(status.credits)}
			</div>
			<div className="ai-credits-balance-panel-label">
				{__('AI Credits remaining', 'vulopilot')}
			</div>

			<div className="ai-credits-balance-panel-progress">
				<div
					className="ai-credits-balance-panel-progress-fill"
					style={{ width: `${remainingPercent}%` }}
				/>
			</div>
			<div className="ai-credits-balance-panel-stats">
				<span>
					{sprintf(
						/* translators: %s: real lifetime-earned credit count. */
						__('%s earned', 'vulopilot'),
						formatCredits(status.lifetime_earned)
					)}
				</span>
				<span>
					{sprintf(
						/* translators: %s: real lifetime-used credit count. */
						__('%s used', 'vulopilot'),
						formatCredits(status.lifetime_used)
					)}
				</span>
			</div>

			{exhausted ? (
				<NoticeComponent
					displayPosition="inline-notice"
					type="warning"
					title={__(
						"You've used all your available AI credits.",
						'vulopilot'
					)}
					message={__(
						'AI requests are paused until you add more credits. Buy Credits to continue.',
						'vulopilot'
					)}
				/>
			) : (
				<div className="ai-credits-balance-panel-tip">
					<div className="ai-credits-balance-panel-tip-icon">
						<i className="adminfont-ai" />
					</div>
					<div className="ai-credits-balance-panel-tip-text">
						<div className="ai-credits-balance-panel-tip-title">
							{__(
								'Use AI credits to generate content, improve text, create titles, and more.',
								'vulopilot'
							)}
						</div>
						<div className="ai-credits-balance-panel-tip-desc">
							{__(
								'Get the most out of VuloPilot with AI.',
								'vulopilot'
							)}
						</div>
					</div>
				</div>
			)}


			<ButtonInput
				wrapperClass="credits-button"
				position="left"
				buttons={[
					{
						text: exhausted
							? __('Buy Credits', 'vulopilot')
							: __('Buy More Credits', 'vulopilot'),
						leftIcon: 'cart',
						rightIcon: 'arrow-right',
						color: 'purple-bg',
						onClick: () => {
							window.open(appLocalizer.shop_url, '_blank', 'noopener,noreferrer');
						},
					},
					{
						text: __('Explore VuloPilot Pro', 'vulopilot'),
						leftIcon: 'pro-tag',
						rightIcon: 'arrow-right',
						color: 'border-purple',
						onClick: () => {
							window.open(vulopilotAppLocalizer.shop_url, '_blank', 'noopener,noreferrer');
						},
					},
				]}
			/>

			<button
				type="button"
				className="ai-credits-balance-panel-refresh"
				onClick={onRefresh}
			>
				<i className="adminfont-refresh" />
				{__('Refresh balance', 'vulopilot')}
			</button>
		</div>
	);
};

export default AiCreditsIndicator;
