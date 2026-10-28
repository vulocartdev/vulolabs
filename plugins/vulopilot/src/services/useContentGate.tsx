/* global vulopilotAppLocalizer */
import { useState, type KeyboardEvent, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PopupComponent } from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import ShowProPopup from '../components/Popup/Popup';
import DummyDataNotice from '../components/DummyDataNotice';
import { BlurredProContent } from '../components/UpgradeToProOverlay';
import MODULES_CATALOG, { isModuleCatalogEntry } from '../components/Modules';
import { useAiCredits } from './useAiCredits';
import './useContentGate.scss';

const MODULE_CATALOG_BY_ID = new Map(
	MODULES_CATALOG.modules.filter(isModuleCatalogEntry).map((module) => [module.id, module])
);

/**
 * Fallback preview used when a caller doesn't supply `dummyContent` to `wrap()`.
 */
const DEFAULT_DUMMY_CONTENT = (
	<div className="content-gate-dummy-content" aria-hidden="true">
		<div className="desc">
			{__('Unlock this to see real, live data for your site here.', 'vulopilot')}
		</div>
		<ButtonInput
			position="full-width"
			buttons={{ text: __('Take Action', 'vulopilot'), icon: 'ai', color: 'orange-bg', disabled: true, onClick: () => {} }}
		/>
	</div>
);

/**
 * Shared logic behind content-gated cards (originally AiSpeedAssistantCard.tsx).
 */
export const useContentGate = (
	moduleId: string | null,
	isModuleActive?: boolean
) => {
	const [isPopupOpen, setIsPopupOpen] = useState(false);
	const { status: creditsStatus } = useAiCredits();

	// `creditsStatus` starts null while the first `ai-credits/status` fetch is in flight.
	const isVuloCloudLocked = !creditsStatus?.connected;
	const isProLocked = !isVuloCloudLocked && !vulopilotAppLocalizer.khali_dabba;
	const isModuleLocked =
		!isVuloCloudLocked &&
		!isProLocked &&
		!!moduleId &&
		!(isModuleActive ?? vulopilotAppLocalizer.active_modules.includes(moduleId));

	const gateReason: 'vulocloud' | 'pro' | 'module' | null = isVuloCloudLocked
		? 'vulocloud'
		: isProLocked
			? 'pro'
			: isModuleLocked
				? 'module'
				: null;

	const handleActivate = () => {
		if ('module' === gateReason && moduleId) {
			window.location.hash = `#&tab=settings&subtab=modules&module=${moduleId}`;
			return;
		}
		setIsPopupOpen(true);
	};

	const handleSectionKeyDown = (event: KeyboardEvent) => {
		if ('Enter' === event.key || ' ' === event.key) {
			event.preventDefault();
			handleActivate();
		}
	};

	const renderTag = (): ReactNode => {
		const moduleName = (moduleId && MODULE_CATALOG_BY_ID.get(moduleId)?.name) ?? moduleId ?? '';

		return (
			<span className="admin-tag module-tag">
				<i className="adminfont-lock" />
				{moduleName}
			</span>
		);
	};

	const wrap = (realContent: ReactNode, dummyContent: ReactNode = DEFAULT_DUMMY_CONTENT): ReactNode => {
		if (null === gateReason) {
			return realContent;
		}

		const isPro = 'pro' === gateReason;
		const isVuloCloud = 'vulocloud' === gateReason;

		if (isPro || isVuloCloud) {
			return (
				<div className="content-gate">
					<BlurredProContent
						contentClassName="content-gate-dummy-content"
						onClick={handleActivate}
						icon={isVuloCloud ? 'cloud-upload orange' : undefined}
						title={isVuloCloud ? __('Connect to VuloCloud', 'vulopilot') : undefined}
						desc={
							isVuloCloud
								? __('Connect your account to see real, live data here.', 'vulopilot')
								: undefined
						}
						buttonColor="orange-bg"
						buttonText={isVuloCloud ? __('Connect to VuloCloud', 'vulopilot') : undefined}
					>
						{isVuloCloud ? realContent : dummyContent}
					</BlurredProContent>
					{!isVuloCloud && <DummyDataNotice />}
					<PopupComponent
						open={isPopupOpen}
						onClose={() => setIsPopupOpen(false)}
						width={31.25}
						height="auto"
						position="lightbox"
					>
						{isVuloCloud ? <ShowProPopup vulocloud /> : <ShowProPopup />}
					</PopupComponent>
				</div>
			);
		}

		return (
			<div className="content-gate">
				<div className="content-gate-tag">{renderTag()}</div>
				{/* Module: the caller's own dummy preview, plus the shared * "This is dummy data" notice (DummyDataNotice). */}
				{dummyContent}
				<DummyDataNotice />
				{/* Covers the whole section (tag + dummy content) so a click * anywhere within it activates. */}
				<div
					className="content-gate-click-overlay"
					role="button"
					tabIndex={0}
					aria-label={sprintf(
						/* translators: %s: module display name. */
						__('Activate %s', 'vulopilot'),
						MODULE_CATALOG_BY_ID.get(moduleId ?? '')?.name ?? moduleId ?? ''
					)}
					onClick={handleActivate}
					onKeyDown={handleSectionKeyDown}
				/>
			</div>
		);
	};

	return { isLocked: null !== gateReason, gateReason, wrap };
};
