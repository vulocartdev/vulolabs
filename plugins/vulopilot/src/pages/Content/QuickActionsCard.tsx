/* global vulopilotAppLocalizer */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { CardComponent, ListComponent, PopupComponent } from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import ContentToolPopup from './ContentToolPopup';
import { ContentTool, ProToolsSlot } from './ContentToolsGrid';
import { useFilterSlot, useIsRouteRegistered } from '../../services/useFilterSlot';
import ShowProPopup, { VuloCloudInlineNotice } from '../../components/Popup/Popup';
import { useAiCredits } from '../../services/useAiCredits';
import { useContentToolsEnabled } from '../../services/useContentToolsEnabled';

/**
 * "Quick Actions" card, same propose/preview/approve flow as ContentToolsGrid.tsx. The 2 Pro
 * tiles are display shells only - real fields/runner come from the `vulopilot_content_tools_pro` slot.
 */
const QUICK_ACTION_TOOLS: ContentTool[] = [
	{
		id: 'content-audit',
		icon: 'lock',
		color: 'purple',
		title: __('AI Content Audit', 'vulopilot'),
		desc: __('Scan and audit all your content', 'vulopilot'),
		actionId: 'audit-content',
		fields: [
			{
				key: 'post_id',
				label: __('Post or page', 'vulopilot'),
				type: 'post-picker',
			},
		],
	},
	{
		id: 'keyword-research',
		icon: 'search',
		color: 'blue',
		title: __('Keyword Research', 'vulopilot'),
		desc: __('Discover content opportunities', 'vulopilot'),
		actionId: 'keyword-research',
		pro: true,
		fields: [],
	},
	{
		id: 'content-templates',
		icon: 'document',
		color: 'orange',
		title: __('Content Templates', 'vulopilot'),
		desc: __('Use proven content templates', 'vulopilot'),
		actionId: 'generate-from-template',
		pro: true,
		fields: [],
	},
];

const QuickActionsCard = () => {
	const [activeTool, setActiveTool] = useState<ContentTool | null>(null);
	const proSlot = useFilterSlot<ProToolsSlot>('vulopilot_content_tools_pro');
	const hasContentPlanner = useIsRouteRegistered('content-planner');
	const { status: creditsStatus } = useAiCredits();
	const isContentToolsEnabled = useContentToolsEnabled();
	const [isCloudConnectPromptOpen, setIsCloudConnectPromptOpen] = useState(false);
	const [isProLocked, setIsProLocked] = useState(false);
	const dismissProLocked = () => setIsProLocked(false);

	const scrollTo = (id: string) => {
		document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	const openPlanner = () => {
		if (hasContentPlanner) {
			window.location.hash = '#&tab=content-planner';
			return;
		}

		setIsProLocked(true);
	};

	const handleToolClick = (tool: ContentTool) => {
		if (tool.pro && (!isContentToolsEnabled || !proSlot)) {
			setIsProLocked(true);
			return;
		}

		if (!tool.pro && creditsStatus && !creditsStatus.connected) {
			setIsCloudConnectPromptOpen(true);
			return;
		}

		setActiveTool(tool.pro ? (proSlot?.tools.find((t) => t.id === tool.id) ?? tool) : tool);
	};

	const proBadge = (tool: ContentTool) =>
		tool.pro && (!isContentToolsEnabled || !proSlot) ? (
			<span className="admin-tag pro-tag pro-tag-inline">
				<i className="adminfont-pro-tag" />
				{__('Pro', 'vulopilot')}
			</span>
		) : (
			<i className="adminfont-arrow-right" />
		);

	return (
		<CardComponent
			id="content-quick-actions-card"
			title={__('Quick Actions', 'vulopilot')}
			titleIcon="ai"
			desc={__('Jump straight to your most common content tasks.', 'vulopilot')}
		>
			<ListComponent
				className="mini-card report without-border "
				border
				items={[
					...QUICK_ACTION_TOOLS.map((tool) => ({
						id: tool.id,
						icon: tool.icon,
						className: `icon-${tool.color}`,
						title: tool.title,
						desc: tool.desc,
						tags: proBadge(tool),
						action: () => handleToolClick(tool),
					})),
					{
						id: 'content-planner',
						icon: 'calendar',
						className: 'icon-blue',
						title: __('Content Planner', 'vulopilot'),
						desc: __('Plan and schedule content', 'vulopilot'),
						tags: hasContentPlanner ? (
							<i className="adminfont-arrow-right" />
						) : (
							<span className="admin-tag pro-tag pro-tag-inline">
								<i className="adminfont-pro-tag" />
								{__('Pro', 'vulopilot')}
							</span>
						),
						action: openPlanner,
					},
				]}
			/>
			<ButtonInput
				wrapperClass="quick-actions-view-all"
				buttons={{
					text: __('View all tools', 'vulopilot'),
					rightIcon: 'arrow-right',
					color: 'border-purple',
					onClick: () => scrollTo('content-tools-grid'),
				}}
			/>
			{activeTool?.pro && proSlot ? (
				<proSlot.Popup
					tool={activeTool}
					onClose={() => setActiveTool(null)}
					VuloCloudInlineNotice={VuloCloudInlineNotice}
					creditsStatus={creditsStatus}
				/>
			) : (
				<ContentToolPopup
					tool={activeTool?.pro ? null : activeTool}
					onClose={() => setActiveTool(null)}
				/>
			)}
			<PopupComponent
				open={isCloudConnectPromptOpen}
				onClose={() => setIsCloudConnectPromptOpen(false)}
				width={22}
				height="auto"
				position="lightbox"
			>
				<ShowProPopup vulocloud />
			</PopupComponent>
			<PopupComponent
				open={isProLocked}
				onClose={dismissProLocked}
				width={31.25}
				height="auto"
				position="lightbox"
			>
				{vulopilotAppLocalizer.khali_dabba ? (
					<ShowProPopup moduleName="content-optimization" />
				) : (
					<ShowProPopup />
				)}
			</PopupComponent>
		</CardComponent>
	);
};

export default QuickActionsCard;
