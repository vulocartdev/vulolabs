/* global vulopilotAppLocalizer */
import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, sendApiResponse } from '@zyra/core';
import {
	ListComponent,
	ModuleGuardComponent,
	NoticeManager,
	TabsComponent,
	BadgeComponent,
} from '@zyra/components';
import DashboardWidget from './DashboardWidget';
import { useApiList } from '../services/useApiList';
import { formatWpDate } from '../services/formatWpDate';
import { getCategoryTabHash } from '../services/getCategoryTabLink';
import { formatAffected } from '../components/Issues/issuesTypes';
import type { FindingGroup } from '../components/Issues/issuesTypes';
import { WidgetProps } from './types';

/** `write-meta-title` → "Write meta title" - the action ids the approval queue carries are internal slugs. */
const humanizeActionId = (actionId: string): string => {
	const words = actionId.replace(/[-_]+/g, ' ').trim();

	return words.charAt(0).toUpperCase() + words.slice(1);
};

/** First sentence of a finding's own description, capped - the "what's wrong" line under each issue type. */
const summarize = (text?: string | null): string => {
	const first = (text ?? '').split(/(?<=[.!?])\s/)[0].trim();

	return first.length > 110 ? `${first.slice(0, 107)}…` : first;
};

interface ActionRunRow {
	id: number;
	action_id: string;
	created_at: string;
}

/**
 * "Needs your attention" - the three real, honest data sources that used to be three separate
 * cards (Quick fixes, Recent open issues, Pending approval), combined into one tabbed widget
 * instead.
 */
const NeedsAttentionWidget: React.FC<WidgetProps> = ({
	onHide,
	isCustomizing,
}) => {
	// Issue *types*, worst severity first (`GET /findings/groups`).
	const quickFixes = useApiList<FindingGroup>('findings/groups', {
		category: 'images',
		status: 'open',
		per_page: 5,
	});
	const openIssues = useApiList<FindingGroup>('findings/groups', {
		status: 'open',
		per_page: 5,
	});
	const toIssueItem = (group: FindingGroup, hash: string) => ({
		id: `${group.scanner_id}-${group.category}`,
		title: group.label,
		desc: summarize(group.sample?.description),
		action: () => {
			window.location.hash = hash;
		},
		tags: (
			<>
				<BadgeComponent
					color="blue"
					text={formatAffected(group.count, group.object_type)}
				/>
				<BadgeComponent
					color={`badge-${group.severity}`}
					text={group.severity}
				/>
			</>
		),
	});
	const pendingApproval = useApiList<ActionRunRow>('ai-action-runs', {
		status: 'pending_approval',
		per_page: 5,
	});
	const [busyId, setBusyId] = useState<number | null>(null);

	const handleDecision = (
		row: ActionRunRow,
		decision: 'approve' | 'reject'
	) => {
		if (busyId === row.id) {
			return;
		}

		setBusyId(row.id);

		sendApiResponse(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, `ai-action-runs/${row.id}/${decision}`),
			{}
		)
			.then((response) => {
				NoticeManager.add({
					uniqueKey: `ai-action-${decision}-${row.id}`,
					type: response ? 'success' : 'error',
					position: 'float',
					message: response
						? decision === 'approve'
							? __('Action approved and executed.', 'vulopilot')
							: __('Action rejected.', 'vulopilot')
						: __(
								'Could not complete this action. Please try again.',
								'vulopilot'
							),
				});

				if (response) {
					pendingApproval.refetch();
				}
			})
			.finally(() => setBusyId(null));
	};

	const isLoading =
		quickFixes.isLoading || openIssues.isLoading || pendingApproval.isLoading;

	return (
		<DashboardWidget
			title={__('Needs your attention', 'vulopilot')}
			desc={__('Open issues, quick fixes, and changes waiting on your approval.', 'vulopilot')}
			icon="error"
			isLoading={isLoading}
			onHide={onHide}
			isCustomizing={isCustomizing}
			id="needs-attention"
		>
			<TabsComponent
				className="dashboard-attention-tabs"
				tabs={[
					{
						label: __('Open issues', 'vulopilot'),
						content:
							openIssues.data.length === 0 ? (
								<ModuleGuardComponent
									icon="check"
									title={__('No open issues', 'vulopilot')}
									desc={__(
										'Every scanned finding is resolved right now.',
										'vulopilot'
									)}
								/>
							) : (
								<ListComponent
									className= "mini-card report"
									items={openIssues.data.map((group) =>
										toIssueItem(group, getCategoryTabHash(group.category))
									)}
								/>
							),
					},
					{
						label: __('Quick fixes', 'vulopilot'),
						content:
							quickFixes.data.length === 0 ? (
								<ModuleGuardComponent
									icon="check"
									title={__(
										'Nothing to fix right now',
										'vulopilot'
									)}
									desc={__(
										'Quick fixes appear here once a scan finds an image issue AI can resolve.',
										'vulopilot'
									)}
								/>
							) : (
								<ListComponent
									className="mini-card report"
									items={quickFixes.data.map((group) =>
										toIssueItem(
											group,
											'#&tab=seo-visibility&subtab=seo'
										)
									)}
								/>
							),
					},
					{
						label: __('Pending approval', 'vulopilot'),
						content:
							pendingApproval.data.length === 0 ? (
								<ModuleGuardComponent
									icon="check"
									title={__(
										'Nothing waiting on you',
										'vulopilot'
									)}
									desc={__(
										'AI actions that need your approval before they run will appear here.',
										'vulopilot'
									)}
								/>
							) : (
								<ListComponent
									className="notification"
									items={pendingApproval.data.map((row) => ({
										id: String(row.id),
										title: humanizeActionId(row.action_id),
										value: formatWpDate(row.created_at),
										onApprove: () =>
											handleDecision(row, 'approve'),
										onReject: () =>
											handleDecision(row, 'reject'),
									}))}
								/>
							),
					},
				]}
			/>
		</DashboardWidget>
	);
};

export default NeedsAttentionWidget;
