/* global vulopilotAppLocalizer */
import React, { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import {
	ListComponent,
	ModuleGuardComponent,
	TypographyComponent,
} from '@zyra/components';

interface DashboardSummary {
	category_scores: {
		security: number | null;
		woocommerce: number | null;
	};
}

export interface CoreWebVitalsSummary {
	lcp_ms: number | null;
	cls: number | null;
	inp_ms: number | null;
	sample_count: number;
}

interface CrawlerSummary {
	daily_volume: { date: string; total: number }[];
	bot_last_seen: { bot_name: string; last_seen_at: string }[];
}

interface RealtimeStats {
	avg_response_time_ms: number | null;
	page_views_last_5_min: number;
	samples_last_hour: number;
}

/**
 * "Live Site Insights" card - moved here from AI Copilot Chat's ChatTab.tsx (pages/AIAssistant/).
 */
const LiveSiteInsightsCard: React.FC = () => {
	const [dashboard, setDashboard] = useState<DashboardSummary | null>(null);
	const [vitals, setVitals] = useState<CoreWebVitalsSummary | null>(null);
	const [crawler, setCrawler] = useState<CrawlerSummary | null>(null);
	const [realtime, setRealtime] = useState<RealtimeStats | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const [hasError, setHasError] = useState(false);

	useEffect(() => {
		setIsLoading(true);
		setHasError(false);

		const headers = { 'X-WP-Nonce': vulopilotAppLocalizer.nonce };

		Promise.all([
			getApiResponse<DashboardSummary>(
				getApiLink(vulopilotAppLocalizer, 'dashboard'),
				{ headers }
			),
			getApiResponse<CoreWebVitalsSummary>(
				getApiLink(vulopilotAppLocalizer, 'core-web-vitals'),
				{ headers }
			),
			getApiResponse<CrawlerSummary>(
				getApiLink(vulopilotAppLocalizer, 'crawler-traffic/summary?days=30'),
				{ headers }
			),
			getApiResponse<RealtimeStats>(
				getApiLink(vulopilotAppLocalizer, 'performance-realtime'),
				{ headers }
			),
		])
			.then(
				([
					dashboardResponse,
					vitalsResponse,
					crawlerResponse,
					realtimeResponse,
				]) => {
					if (
						!dashboardResponse ||
						!vitalsResponse ||
						!crawlerResponse ||
						!realtimeResponse
					) {
						setHasError(true);
						return;
					}

					setDashboard(dashboardResponse);
					setVitals(vitalsResponse);
					setCrawler(crawlerResponse);
					setRealtime(realtimeResponse);
				}
			)
			.catch(() => setHasError(true))
			.finally(() => setIsLoading(false));
	}, []);

	if (hasError) {
		return (
			<>
				<ModuleGuardComponent
					icon="error"
					title={__('Could not load live insights', 'vulopilot')}
					desc={__(
						'Please refresh the page to try again.',
						'vulopilot'
					)}
				/>
			</>
		);
	}

	const crawlerVisits30d = (crawler?.daily_volume ?? []).reduce(
		(sum, day) => sum + day.total,
		0
	);
	const distinctBots = crawler?.bot_last_seen?.length ?? 0;
	const showStoreMetrics =
		null !== (dashboard?.category_scores?.woocommerce ?? null);

	return (
		<>
			<ListComponent
				loading={isLoading}
				skeletonCount={showStoreMetrics ? 5 : 4}
				className="mini-card report list"
				items={[
					{
						id: 'security-score',
						icon: 'security green',
						title: __('Security score', 'vulopilot'),
						tags: (
							<TypographyComponent variant="h5">
								{sprintf(
									/* translators: %d: real 0-100 score. */
									__('%d/100', 'vulopilot'),
									dashboard?.category_scores?.security ?? 0
								)}
							</TypographyComponent>
						),
						desc: __('From your open security findings', 'vulopilot'),
					},
					{
						id: 'ai-crawler-traffic',
						icon: 'search-discovery yellow',
						title: __('AI crawler traffic (30 days)', 'vulopilot'),
						tags: (
							<TypographyComponent variant="h5">
								{crawlerVisits30d}
							</TypographyComponent>
						),
						desc:
							crawlerVisits30d > 0
								? sprintf(
									/* translators: %d: number of distinct AI crawler bots that have visited */
									__('Across %d bot(s)', 'vulopilot'),
									distinctBots
								)
								: __('No AI crawlers have visited yet', 'vulopilot'),
					},
					{
						id: 'core-web-vitals',
						icon: 'bar-chart pink',
						title: __('Core Web Vitals', 'vulopilot'),
						tags: (
							<TypographyComponent variant="h5">
								{vitals && vitals.sample_count > 0 && null !== vitals.lcp_ms
									? sprintf(
										/* translators: %d: real p75 Largest Contentful Paint, in milliseconds, from actual visitors */
										__('%dms LCP (p75)', 'vulopilot'),
										vitals.lcp_ms
									)
									: __('Collecting data', 'vulopilot')}
							</TypographyComponent>
						),
						desc:
							vitals && vitals.sample_count > 0
								? sprintf(
									/* translators: %d: real number of visitor samples this p75 was computed from */
									__('From %d real visits', 'vulopilot'),
									vitals.sample_count
								)
								: __(
									'Visit your live site to start collecting real data',
									'vulopilot'
								),
					},
					{
						id: 'page-views',
						icon: 'global-community yellow',
						title: __('Page Views', 'vulopilot'),
						tags: (
							<TypographyComponent variant="h5">
								{realtime?.page_views_last_5_min ?? 0}
							</TypographyComponent>
						),
						desc: __(
							"Real page views in the last 5 minutes",
							'vulopilot'
						),
					},
					...(showStoreMetrics
						? [
							{
								id: 'store-metrics',
								icon: 'woocommerce sky',
								title: __('Store metrics', 'vulopilot'),
								tags: (
									<TypographyComponent variant="desc" color="red">
										{__('Not available yet', 'vulopilot')}
									</TypographyComponent>
								),
								desc: __(
									"Orders, revenue, and conversion data aren't connected in this version.",
									'vulopilot'
								),
							},
						]
						: []),
				]}
			/>
		</>
	);
};

export default LiveSiteInsightsCard;