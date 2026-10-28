/* global vulopilotAppLocalizer */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { AnalyticsComponent, CardComponent, ChartComponent, ModuleGuardComponent } from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import { formatWpDate } from '../../services/formatWpDate';
import { nonceHeaders } from './seoIssuesShared';
import './WhatShouldIFixFirst.scss';
import type { PeriodDays } from './types';

interface TrendPoint {
	date: string;
	score: number;
}

interface WeekStat {
	this_week: number;
	delta: number;
}

interface SeoProgressResponse {
	days: number;
	trend: TrendPoint[];
	issues_fixed: WeekStat;
	new_issues: WeekStat;
	pages_improved: WeekStat;
}

/** Same 7/30/90-day trio GeoScoreSection.tsx's "Score Snapshot" period toggle already uses. */
const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

/**
 * "SEO progress" - a new, additive card.
 */
const SeoProgressCard = () => {
	const [data, setData] = useState<SeoProgressResponse | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const [hasError, setHasError] = useState(false);
	const [period, setPeriod] = useState<PeriodDays>('30');

	useEffect(() => {
		let cancelled = false;
		setIsLoading(true);

		getApiResponse<SeoProgressResponse>(
			getApiLink(vulopilotAppLocalizer, `seo/progress?days=${period}`),
			nonceHeaders
		)
			.then((response) => {
				if (cancelled) {
					return;
				}
				if (response) {
					setData(response);
				} else {
					setHasError(true);
				}
			})
			.catch(() => {
				if (!cancelled) {
					setHasError(true);
				}
			})
			.finally(() => {
				if (!cancelled) {
					setIsLoading(false);
				}
			});

		return () => {
			cancelled = true;
		};
	}, [period]);

	return (
		<CardComponent
			title={__('SEO progress', 'vulopilot')}
			titleIcon="seo"
			desc={__('Track your SEO health over time.', 'vulopilot')}
			isLoading={isLoading}
			action={
				<ToggleInput
					options={PERIOD_OPTIONS}
					value={period}
					onChange={(value) => setPeriod(value as PeriodDays)}
					modules={[]}
					variant="pill"
				/>
			}
		>
			{hasError && (
				<ModuleGuardComponent
					icon="error"
					title={__('Could not load your SEO progress', 'vulopilot')}
					desc={__(
						'Something went wrong fetching this data. Please try again.',
						'vulopilot'
					)}
				/>
			)}

			{data && (
				<div className="seo-progress-layout">
					<div className="seo-progress-chart">
						<div className="seo-progress-chart-title typography-body-xs">
							{__('SEO Score Over Time', 'vulopilot')}
						</div>
						<ChartComponent
							type="dynamic-line"
							data={data.trend.map((point: TrendPoint) => ({
								...point,
								date: formatWpDate(point.date),
							}))}
							dataKey="score"
							xKey="date"
							height={220}
							yDomain={[0, 100]}
						/>
					</div>

					<AnalyticsComponent
						cols={3}
						data={[
							{
								colorClass: 'green',
								number: String(data.issues_fixed.this_week),
								text: (
									<>
										<div className="typography-body-xs">
											{__('Issues Fixed', 'vulopilot')}
										</div>
									</>
								),
							},
							{
								colorClass: 'yellow',
								number: String(data.new_issues.this_week),
								text: (
									<>
										<div className="typography-body-xs">
											{__('New Issues', 'vulopilot')}
										</div>
									</>
								),
							},
							{
								colorClass: 'blue',
								number: String(data.pages_improved.this_week),
								text: (
									<>
										<div className="typography-body-xs">
											{__('Pages Improved', 'vulopilot')}
										</div>
									</>
								),
							},
						]}
						variant="background-color"
						isLoading={isLoading}
					/>
				</div>
			)}
		</CardComponent>
	);
};

export default SeoProgressCard;
