/* global vulopilotAppLocalizer */
import React from 'react';
import { ButtonInput } from '@zyra/inputs';
import { NoticeComponent } from '@zyra/components';
import { __, sprintf } from '@wordpress/i18n';
import MODULES_CATALOG, { isModuleCatalogEntry } from '../Modules';
import { useConnectVuloCloud } from '../../services/useConnectVuloCloud';
import '../Popup/Popup.scss';

interface PopupProps {
	moduleName?: string;
	plugin?: string;
	vulocloud?: boolean;

	confirmMode?: boolean;
	title?: string;
	confirmMessage?: React.ReactNode;
	confirmYesText?: string;
	confirmNoText?: string;
	onConfirm?: () => void;
	onCancel?: () => void;
}

const formatModuleName = (name: string): string => {
	return name
		.split('-')
		.map((word) => word.charAt(0).toUpperCase() + word.slice(1))
		.join(' ');
};

/**
 * Every real module id from ../Modules/index.ts's own catalog, keyed for a
 * cheap lookup below. GEO Radar ('geo-insights') and AEO Autopilot
 * ('aeo-insights') used to share one id here, which silently mislabeled
 * every 'geo-insights' popup as "Activate AEO Autopilot" (a `Map` keyed by
 * id keeps only the last of two entries sharing a key, and the AEO card is
 * listed second in that file) - fixed by giving AEO Autopilot its own
 * real, separate backend module id (see that catalog entry's own
 * docblock), so this lookup no longer has two entries to choose between.
 */
const MODULE_CATALOG_BY_ID = new Map(
	MODULES_CATALOG.modules
		.filter(isModuleCatalogEntry)
		.map((module) => [module.id, module])
);

/**
 * Resolves a real module id to the SAME name Settings → Modules shows for
 * it - used to render `Activate {name}` below. Falls back to a plain
 * Title-Cased-from-kebab guess only for the handful of real, backend
 * modules that have no card on that page at all ('advanced-reports',
 * 'one-click-fix' - see Modules/index.ts's own docblock for why), since
 * there's no human-authored name anywhere in this catalog to look up for
 * those. Fixes a real mismatch this popup used to always have: every
 * moduleName call site with a real card (`automation`, `accessibility-
 * audits`, `geo-insights`, …) previously showed this same guessed name
 * ("Activate Automation") instead of that module's own real, branded name
 * ("Activate Workflow Autopilot - Automation Engine") - two different
 * names for the same module, depending on which part of the app you saw
 * it locked from.
 *
 * Exported so a caller rendering its own module-lock tag (e.g.
 * AutomationsTemplatesCard.tsx's per-row "module" badge) can show the exact
 * same name this popup's own "Activate {name}" heading uses, rather than a
 * second, separately-typed label that could drift from it.
 */
export const resolveModuleDisplayName = (moduleId: string): string =>
	MODULE_CATALOG_BY_ID.get(moduleId)?.name ?? formatModuleName(moduleId);

/**
 * Real icons for the real backend modules with no catalog entry at all
 * (see Modules/index.ts's own ModuleCatalogEntry.icon docblock) - kept
 * local here rather than added to that catalog, which would misleadingly
 * imply these have a real card to point `moduleName`'s "Enable Now"
 * link at.
 */
const CARDLESS_MODULE_ICONS: Record<string, string> = {
	'advanced-reports': 'report',
	'one-click-fix': 'tools',
	'copilot-chat': 'ai',
};

/**
 * Resolves a real module id to a real `adminfont-*` glyph - every id in
 * Modules/index.ts's own catalog uses that entry's `icon` field; the 2
 * cardless ids above use CARDLESS_MODULE_ICONS; anything else (there
 * shouldn't be one) falls back to the id itself, same as before this
 * lookup existed. Fixes a real regression this popup would otherwise have:
 * `module.id` values (`geo-insights`, `accessibility-audits`, …) aren't
 * real icon glyph names in zyra's fonts.scss - confirmed only 1 of the 15
 * real module ids used across this file (`automation`) happens to also be
 * a defined `adminfont-automation` glyph, so deriving icons straight from
 * id would silently render a blank icon for the other 14.
 */
const resolveModuleIcon = (moduleId: string): string =>
	MODULE_CATALOG_BY_ID.get(moduleId)?.icon ??
	CARDLESS_MODULE_ICONS[moduleId] ??
	moduleId;

const proPopupContent = {
	messages: [
		...MODULES_CATALOG.modules
			.filter(isModuleCatalogEntry)
			.filter((module) => module.popupTitle)
			.map((module) => ({
				icon: resolveModuleIcon(module.id),
				text: `${module.popupTitle} · ${module.name}`,
				des: module.popupDesc ?? '',
			})),
		{
			icon: resolveModuleIcon('advanced-reports'),
			text: `${__('See What’s Actually Improving', 'vulopilot')} · ${__('Advanced Reports', 'vulopilot')}`,
			des: __('Bring your results together to track progress, spot changes, and share clear reports with your team or clients.', 'vulopilot'),
		},
	],
};

const ShowProPopup: React.FC<PopupProps> = (props) => {
	const { isConnecting, handleConnect } = useConnectVuloCloud();

	if (props.confirmMode) {
		return (
			<div className="popup-confirm">
				<i className="popup-icon adminfont-suspended admin-badge red"></i>
				<div className="title">{props.title || __('Confirmation', 'vulopilot')}</div>
				<div className="desc">{props.confirmMessage}</div>
				<ButtonInput
					position="center"
					buttons={[
						{
							icon: 'close',
							text: props.confirmNoText || __('Cancel', 'vulopilot'),
							color: 'red',
							onClick: props.onCancel,
						},
						{
							icon: 'delete',
							text: props.confirmYesText || __('Confirm', 'vulopilot'),
							onClick: props.onConfirm,
						},
					]}
				/>
			</div>
		);
	}

	if (props.plugin) {
		return (
			<div className="popup-wrapper">
				<div className="popup-header">
					<i className={`adminfont-${props.plugin}`} />
				</div>
				<div className="popup-body">
					<div className="module-name">
						{sprintf(
							/* translators: %s: Plugin name. */
							__('Plugin Required: %s', 'vulopilot'),
							props.plugin
						)}
					</div>
					<div className="module-desc">
						{sprintf(
							/* translators: %s: name of the required plugin. */
							__(
								'This feature requires the "%s" plugin to be active.',
								'vulopilot'
							),
							props.plugin
						)}
					</div>
					<ButtonInput
						position="center"
						buttons={[
							{
								icon: 'eye',
								text: __('Activate Plugin', 'vulopilot'),
								onClick: () => {
									window.open(
										`${vulopilotAppLocalizer.admin_url.replace(/admin\.php.*/, '')}plugins.php`,
										'_blank'
									);
								},
							},
						]}
					/>
				</div>
			</div>
		);
	}

	if (props.vulocloud) {
		return (
			<div className="popup-wrapper">
				<div className="popup-header orange-bg">
					<i className="adminfont-lock orange-bg" />
				</div>
				<div className="popup-body">
					<div className="module-name">
						{__('Connect to VuloCloud', 'vulopilot')}
					</div>
					<div className="module-desc">
						{__(
							'Claim 100 Free AI Credits - no credit card required - to use this feature.',
							'vulopilot'
						)}
					</div>
					<ButtonInput
						position="center"
						buttons={[
							{
								icon: 'link',
								text: isConnecting
									? __('Connecting…', 'vulopilot')
									: __('Connect to VuloCloud', 'vulopilot'),
								color: 'orange-bg',
								disabled: isConnecting,
								onClick: handleConnect,
							},
						]}
					/>
				</div>
			</div>
		);
	}

	if (props.moduleName) {
		const displayName = resolveModuleDisplayName(props.moduleName);
		const displayIcon = resolveModuleIcon(props.moduleName);

		return (
			<div className="popup-wrapper">
				<div className="popup-header">
					<i className={`adminfont-${displayIcon}`} />
				</div>
				<div className="popup-body">
					<div className="module-name">
						{sprintf(
							/* translators: %s: module display name. */
							__('Activate %s', 'vulopilot'),
							displayName
						)}
					</div>
					<div className="module-desc">
						{sprintf(
							/* translators: %s: Module name. */
							__(
								'This feature is currently unavailable. To activate it, please enable the %s module.',
								'vulopilot'
							),
							displayName
						)}
					</div>
					<ButtonInput
						position="center"
						buttons={[
							{
								icon: 'eye',
								text: __('Enable Now', 'vulopilot'),
								onClick: () => {
									// Same admin page, just a different
									// hash tab - '_self' matches this
									// plugin's own same-page navigation
									// convention. Omitting the
									// target opens a new background tab
									// instead (real browser behavior,
									// despite MDN's prose default of
									// '_self'), which silently left the
									// user looking at the still-locked
									// widget with no visible feedback.
									//
									// `tab=settings&subtab=modules`, not the
									// old standalone `tab=modules` route -
									// the real Modules UI moved there per
									// direct instruction (Modules.ts's own
									// docblock); that old route is still
									// registered and renders the same real
									// page, but it's no longer in the WP
									// sidebar, so a deep-link landing there
									// left the admin with no breadcrumb/
									// highlighted-menu-item back out.
									window.open(
										`${vulopilotAppLocalizer.admin_url}#&tab=settings&subtab=modules&module=${props.moduleName}`,
										'_self'
									);
								},
							},
						]}
					/>
				</div>
			</div>
		);
	}

	return (
		<div className="popup-wrapper">
			<div className="top-section">
				<div className="heading">
					{__(
						'Unlock the full VuloPilot toolkit',
						'vulopilot'
					)}
				</div>
				<div className="description">
					{__(
						'Automate recurring checks, fix issues at scale, track progress over time, and uncover opportunities across SEO, AI search, performance, security, and WooCommerce.',
						'vulopilot'
					)}
				</div>
				<a
					className="admin-btn"
					href={vulopilotAppLocalizer.shop_url}
					target="_blank"
					rel="noreferrer"
				>
					{__('Upgrade to Pro', 'vulopilot')}
					<i className="adminfont-arrow-right arrow-icon"></i>
				</a>
			</div>
			<div className="popup-details">
				<div className="heading-text">
					{__('What you unlock with Pro', 'vulopilot')}
				</div>
				<ul>
					{proPopupContent.messages.map((message, index) => (
						<li key={index}>
							<div className="title">
								<i className={`adminfont-${message.icon}`} />
								{message.text}
							</div>
							<div className="desc">{message.des}</div>
						</li>
					))}
				</ul>
			</div>
		</div>
	);
};

export default ShowProPopup;

export const VuloCloudInlineNotice = () => {
	const { isConnecting, handleConnect } = useConnectVuloCloud();

	return (
		<NoticeComponent
			displayPosition="inline-notice"
			type="info"
			title={__('Connect to VuloCloud', 'vulopilot')}
			message={__(
				'Claim 100 Free AI Credits - no credit card required - to use this feature.',
				'vulopilot'
			)}
			actionLabel={
				isConnecting
					? __('Connecting…', 'vulopilot')
					: __('Connect to VuloCloud', 'vulopilot')
			}
			onAction={handleConnect}
		/>
	);
};
