/* global vulopilotAppLocalizer */
import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { COLOR_PALETTE } from '@zyra/core';
import {
	AnalyticsComponent,
	ChartComponent,
	TypographyComponent,
	ListComponent,
	IconComponent,
} from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import DashboardWidget from './DashboardWidget';
import { WidgetProps } from './types';
import { useApiList } from '../services/useApiList';
import { SEO_SECTIONS } from '../pages/GEO/seoSections';
import { ALL_AEO_SCANNER_IDS } from '../pages/GEO/AeoTab';
import VuloPilotActivityWidget from './VuloPilotActivityWidget';

type GlanceRow = {
	key: 'seo' | 'geo' | 'aeo';
	label: string;
	subtab: string;
	/** `scanner_id`/`category` REST params to count real open findings with. */
	params: Record<string, string>;
};
const SEO_SCANNER_IDS = SEO_SECTIONS.flatMap((section) => section.scannerIds);

/** Same 3 real "Issues at a glance" rows KeyPagesWidget.tsx used. */
const GLANCE_ROWS: GlanceRow[] = [
	{
		key: 'seo',
		label: __('SEO', 'vulopilot'),
		subtab: 'seo',
		params: { scanner_id: SEO_SCANNER_IDS.join(',') },
	},
	{
		key: 'geo',
		label: __('GEO', 'vulopilot'),
		subtab: 'geo',
		params: { category: 'geo' },
	},
	{
		key: 'aeo',
		label: __('AEO', 'vulopilot'),
		subtab: 'aeo',
		params: { scanner_id: ALL_AEO_SCANNER_IDS.join(',') },
	},
];

/**
 * "Vital Pulse" - the Dashboard's hero status ring: one real 0-100 `overall_score`, colored by its
 * own real rating band via `ratingColorFor()`.
 */
export const getRating = (score: number): string => {
	if (score >= 90) {
		return __('Excellent', 'vulopilot');
	}
	if (score >= 70) {
		return __('Good', 'vulopilot');
	}
	if (score >= 50) {
		return __('Fair', 'vulopilot');
	}
	return __('Needs work', 'vulopilot');
};

/** Same real 4-tier `getRating()` bands above, mapped to real palette color names. */
export const ratingColorFor = (score: number): string => {
	if (score >= 90) {
		return 'green';
	}
	if (score >= 70) {
		return 'blue';
	}
	if (score >= 50) {
		return 'yellow';
	}
	return 'red';
};

const getRatingSummary = (score: number): string => {
	if (score >= 90) {
		return __('Your site is in excellent shape.', 'vulopilot');
	}
	if (score >= 70) {
		return __(
			'Your site is healthy and needs minimal work.',
			'vulopilot'
		);
	}
	if (score >= 50) {
		return __('Your site could use some improvement.', 'vulopilot');
	}
	return __('Your site needs attention in several areas.', 'vulopilot');
};

/** Average helper for grouping category scores into buckets. */
const average = (nums: number[]): number =>
	Math.round(nums.reduce((sum, n) => sum + n, 0) / nums.length);

const OverallScoreWidget: React.FC<WidgetProps> = ({
	summary,
	isLoading,
	onHide,
	isCustomizing,
	onRefreshSummary,
}) => {
	// Fixed cardinality (always exactly 3 rows), so one real `useApiList`
	// call each rather than a loop - `per_page: 1` since only `total` is used.
	const seoFindings = useApiList<{ id: number }>('findings', {
		...GLANCE_ROWS[0].params,
		status: 'open',
		per_page: 1,
	});
	const geoFindings = useApiList<{ id: number }>('findings', {
		...GLANCE_ROWS[1].params,
		status: 'open',
		per_page: 1,
	});
	const aeoFindings = useApiList<{ id: number }>('findings', {
		...GLANCE_ROWS[2].params,
		status: 'open',
		per_page: 1,
	});
	const totals: Record<GlanceRow['key'], number> = {
		seo: seoFindings.total,
		geo: geoFindings.total,
		aeo: aeoFindings.total,
	};

	// --- Category score breakdown data (from ScoreBreakdownWidget) ---
	const cs = summary.category_scores;
	const visibility = average([cs.seo, cs.geo, cs.content, cs.brand]);
	const health = average([cs.security, cs.accessibility]);
	const commerce = cs.woocommerce ?? 0;
	const performance = cs.performance;

	// Real week-over-week deltas per bucket, diffed against
	// category_scores_7d_ago.
	const cs7 = summary.category_scores_7d_ago;
	const visibility7d = average([cs7.seo, cs7.geo, cs7.content, cs7.brand]);
	const health7d = average([cs7.security, cs7.accessibility]);
	const commerce7d = cs7.woocommerce ?? 0;
	const performance7d = cs7.performance;

	const scoreRows = [
		{
			key: 'visibility',
			label: __('Visibility Score', 'vulopilot'),
			score: visibility,
			delta: visibility - visibility7d,
			icon: 'tax-compliance',
		},
		{
			key: 'health',
			label: __('Health Score', 'vulopilot'),
			score: health,
			delta: health - health7d,
			icon: 'order',
		},
		...(vulopilotAppLocalizer.has_woocommerce
			? [
					{
						key: 'commerce',
						label: __('Commerce Score', 'vulopilot'),
						score: commerce,
						delta: commerce - commerce7d,
						icon: 'shipping',
					},
				]
			: []),
		{
			key: 'performance',
			label: __('Performance Score', 'vulopilot'),
			score: performance,
			delta: performance - performance7d,
			icon: 'shipping',
		},
		{
			key: 'content',
			label: __('Content Score', 'vulopilot'),
			score: cs.content,
			delta: cs.content - cs7.content,
			icon: 'text-fields',
		},
		{
			key: 'brand',
			label: __('Brand Score', 'vulopilot'),
			score: cs.brand,
			delta: cs.brand - cs7.brand,
			icon: 'person',
		},
	];

	return (
		<>
		<DashboardWidget
			title={__('Website Health Scores', 'vulopilot')}
			desc={
				vulopilotAppLocalizer.has_woocommerce
					? __('Your overall score across visibility, health, commerce, performance, content, and brand.', 'vulopilot')
					: __('Your overall score across visibility, health, performance, content, and brand.', 'vulopilot')
			}
			icon="analytics"
			isLoading={isLoading}
			onHide={onHide}
			isCustomizing={isCustomizing}
			headerAction={
				<ButtonInput
					buttons={{
						text: __('View full report', 'vulopilot'),
						rightIcon: 'pagination-right-arrow',
						color: 'text-purple',
						onClick: () => {
							window.location.hash = '#&tab=reports';
						},
					}}
				/>
			}
		>
			<div className="overall-score-wrapper">
				<div className="overall-score-summary chart">
					<ChartComponent
						type="ring"
						isLoading={isLoading}
						height={240}
						color={
							COLOR_PALETTE[
							ratingColorFor(
								summary.overall_score
							) as keyof typeof COLOR_PALETTE
							]
						}
						centerLabel={
							<>
								<TypographyComponent
									variant={'h1'}
									color={ratingColorFor(summary.overall_score)}
								>
									{summary.overall_score}
								</TypographyComponent>
								<TypographyComponent variant={'h4'}>
									{getRating(summary.overall_score)}
								</TypographyComponent>
							</>
						}
						data={[
							{
								label: __('Score', 'vulopilot'),
								value: summary.overall_score,
							},
						]}
					/>

					<TypographyComponent variant={'h3'} color="text-green">
						{__('Overall Score', 'vulopilot')}
					</TypographyComponent>
					<div className="desc">
						{getRatingSummary(summary.overall_score)}
					</div>
				</div>
				{/* Category score breakdown list, moved here from ScoreBreakdownWidget.tsx */}
				<div className='overall-score-summary'>
					<ListComponent
						className="mini-card report seo-health-score-category-list"
						loading={isLoading}
						items={scoreRows.map((row) => ({
							id: row.key,
							icon: row.icon,
							title: row.label,
							tags: (
								<>
									<TypographyComponent
										variant="h5"
										weight="bold"
										color={ratingColorFor(row.score)}
										className="seo-health-score-row-value"
									>
										{row.score}
										<TypographyComponent
											as="span"
											variant="body-md"
											className="seo-health-score-row-suffix"
										>
											/100
										</TypographyComponent>
									</TypographyComponent>
									<TypographyComponent
										as="span"
										variant="body-md"
										weight="bold"
										color={row.delta >= 0 ? 'green' : 'red'}
										className="seo-health-score-row-delta"
									>
										<IconComponent
											name={
												row.delta >= 0
													? 'arrow-up'
													: 'arrow-down'
											}
										/>
										{Math.abs(row.delta)}
									</TypographyComponent>
								</>
							),
						}))}
					/>
				</div>
			</div>
			<AnalyticsComponent
				variant="background-color"
				cols={3}
				data={GLANCE_ROWS.map((row, index) => ({
					colorClass: `admin-bg-color${index + 2}`,
					number: totals[row.key],
					text: sprintf(
						/* translators: %s: sub-tab name, e.g. "SEO". */
						__('%s issues', 'vulopilot'),
						row.label
					),
					link: `?page=vulopilot#&tab=seo-visibility&subtab=${row.subtab}`,
				}))}
			/>
		</DashboardWidget>
		<VuloPilotActivityWidget
			summary={summary}
			isLoading={isLoading}
			onHide={onHide}
			isCustomizing={isCustomizing}
			onRefreshSummary={onRefreshSummary}
		/>
		</>
	);
};

export default OverallScoreWidget;