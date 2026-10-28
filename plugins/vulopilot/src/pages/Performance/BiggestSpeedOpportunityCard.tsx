/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __, sprintf, _n } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { AnalyticsComponent, CardComponent, ModuleGuardComponent } from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import type { PageSpeedIssue } from './SlowPagesTab';

interface PageSpeedResponse {
	top_issues: PageSpeedIssue[];
}

/** One row of `GET /findings/groups` - only the fields this card actually reads (real scanner label + real open-finding count). */
interface FindingGroupRow {
	label: string;
	count: number;
}

interface FindingGroupsResponse {
	data: FindingGroupRow[];
}

type SpeedOpportunity =
	| { source: 'page_speed'; label: string; count: number }
	| { source: 'finding'; label: string; count: number };

interface BiggestSpeedOpportunityCardProps {
	/** Jumps to the real "Slow Pages" tab - only meaningful for a page_speed-sourced opportunity, where every affected page is a real row there. */
	onViewSlowPages: () => void;
	/** Jumps to the real "Top Issues" findings table on this same tab (`#performance-section-findings`). */
	onViewFindings: () => void;
}

const nonceHeaders = { headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } };

/**
 * "Biggest Speed Opportunity" - one real, prioritized recommendation instead of a static tip list:
 * replaces PerformanceTipsCard.tsx (now deleted).
 */
const BiggestSpeedOpportunityCard = ({
	onViewSlowPages,
	onViewFindings,
}: BiggestSpeedOpportunityCardProps) => {
	const [opportunity, setOpportunity] = useState<SpeedOpportunity | null>(
		null
	);
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		getApiResponse<PageSpeedResponse>(
			getApiLink(vulopilotAppLocalizer, 'page-speed') + '?per_page=1',
			nonceHeaders
		)
			.then((response) => {
				const topIssue = response?.top_issues?.[0];

				if (topIssue) {
					setOpportunity({
						source: 'page_speed',
						label: topIssue.issue,
						count: topIssue.affected_pages,
					});
					return null;
				}

				return getApiResponse<FindingGroupsResponse>(
					getApiLink(
						vulopilotAppLocalizer,
						'findings/groups?category=performance&per_page=1'
					),
					nonceHeaders
				);
			})
			.then((groupsResponse) => {
				const group = groupsResponse?.data?.[0];

				if (group) {
					setOpportunity({
						source: 'finding',
						label: group.label,
						count: group.count,
					});
				}
			})
			.finally(() => setIsLoading(false));
	}, []);

	return (
		<CardComponent
			id="biggest-speed-opportunity-card"
			title={__('Biggest Speed Opportunity', 'vulopilot')}
			titleIcon="light"
			desc={__('The single fix with the biggest real impact on your speed score.', 'vulopilot')}
			isLoading={isLoading}
		>
			{!isLoading && !opportunity && (
				<ModuleGuardComponent
					icon="check"
					title={__('Nothing to optimize yet', 'vulopilot')}
					desc={__(
						'Run a speed test to find your biggest opportunity.',
						'vulopilot'
					)}
				/>
			)}
			{!isLoading && opportunity && (
				<>
					<AnalyticsComponent
						variant="with-out-boxshadow"
						data={[
							{
								icon: 'single-product',
								iconClass: 'admin-bg-color2',
								number: opportunity.label,
								text:
									'page_speed' === opportunity.source
										? sprintf(
											/* translators: %d: real number of pages this affects. */
											_n(
												'Affects %d page.',
												'Affects %d pages.',
												opportunity.count,
												'vulopilot'
											),
											opportunity.count
										)
										: sprintf(
											/* translators: %d: real number of endpoints this affects. */
											_n(
												'Affects %d endpoint.',
												'Affects %d endpoints.',
												opportunity.count,
												'vulopilot'
											),
											opportunity.count
										),
							},
						]}
					/>
					<ButtonInput
						position="full-width"
						buttons={{
							text:
								'page_speed' === opportunity.source
									? __('View Affected Pages', 'vulopilot')
									: __('View Details', 'vulopilot'),
							rightIcon: 'pagination-right-arrow',
							color: 'border-purple',
							onClick:
								'page_speed' === opportunity.source
									? onViewSlowPages
									: onViewFindings,
						}}
					/>
				</>
			)}
		</CardComponent>
	);
};

export default BiggestSpeedOpportunityCard;
