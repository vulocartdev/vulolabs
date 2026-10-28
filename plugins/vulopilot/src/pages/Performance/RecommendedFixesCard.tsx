/* global vulopilotAppLocalizer */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, sendApiResponse } from '@zyra/core';
import { CardComponent, ListComponent, NoticeManager } from '@zyra/components';
import type { PageSpeedIssue } from './SlowPagesTab';

interface RecommendedFix {
	actionId: string;
	icon: string;
	label: string;
}

interface ActionResult {
	success: boolean;
	message: string;
}

/**
 * One-click fixes via `POST /performance-actions/{id}`, the same actions as the Overview Quick
 * Actions card.
 */
const FIX_BY_KEYWORD: { keyword: string; actionId: string; icon: string; label: string }[] = [
	{ keyword: 'image', actionId: 'optimize-images', icon: 'image', label: __('Optimize Images', 'vulopilot') },
	{ keyword: 'cache', actionId: 'clear-caches', icon: 'refresh-bold', label: __('Clear All Caches', 'vulopilot') },
	{ keyword: 'css', actionId: 'minify-css-js', icon: 'coding', label: __('Minify CSS & JS', 'vulopilot') },
	{ keyword: 'javascript', actionId: 'minify-css-js', icon: 'coding', label: __('Minify CSS & JS', 'vulopilot') },
	{ keyword: 'render-blocking', actionId: 'preload-resources', icon: 'cloud-upload', label: __('Preload Critical Resources', 'vulopilot') },
	{ keyword: 'lazy', actionId: 'lazy-loading', icon: 'eye', label: __('Enable Lazy Loading', 'vulopilot') },
	{ keyword: 'offscreen', actionId: 'lazy-loading', icon: 'eye', label: __('Enable Lazy Loading', 'vulopilot') },
];

/**
 * "Recommended Fixes" - real, one-click actions tied to what this site's own Slow Pages scan
 * actually found.
 */
const RecommendedFixesCard = ({ topIssues }: { topIssues: PageSpeedIssue[] }) => {
	const [runningActionId, setRunningActionId] = useState<string | null>(null);

	const fixes = Array.from(
		new Map(
			topIssues
				.map((issue): RecommendedFix | null => {
					const match = FIX_BY_KEYWORD.find((entry) =>
						issue.issue.toLowerCase().includes(entry.keyword)
					);

					return match
						? { actionId: match.actionId, icon: match.icon, label: match.label }
						: null;
				})
				.filter((fix): fix is RecommendedFix => Boolean(fix))
				.map((fix) => [fix.actionId, fix])
		).values()
	);

	if (0 === fixes.length) {
		return null;
	}

	const runFix = (fix: RecommendedFix) => {
		if (runningActionId) {
			return;
		}

		setRunningActionId(fix.actionId);

		sendApiResponse<ActionResult>(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, `performance-actions/${fix.actionId}`),
			{}
		)
			.then((response) => {
				NoticeManager.add({
					uniqueKey: `slow-pages-recommended-fix-${fix.actionId}`,
					type: response && response.success ? 'success' : 'info',
					position: 'float',
					message: response
						? response.message
						: __('Could not run this fix - please try again.', 'vulopilot'),
				});
			})
			.finally(() => setRunningActionId(null));
	};

	return (
		<CardComponent
			title={__('Recommended Fixes', 'vulopilot')}
			titleIcon="light"
			desc={__('Real, one-click fixes for your slowest pages\' top issues.', 'vulopilot')}
		>
			<ListComponent
				className="mini-card"
				border
				items={fixes.map((fix) => ({
					id: fix.actionId,
					icon: fix.icon,
					title: fix.label,
					tags:
						runningActionId === fix.actionId ? (
							<i className="adminfont-refresh performance-quick-action-spinner" />
						) : (
							<i className="adminfont-arrow-right" />
						),
					action: () => runFix(fix),
				}))}
			/>
		</CardComponent>
	);
};

export default RecommendedFixesCard;
