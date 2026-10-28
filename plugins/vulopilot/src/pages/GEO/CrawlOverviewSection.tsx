/* global vulopilotAppLocalizer */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import type { ComponentType } from 'react';
import { BadgeComponent, CardComponent, ColumnComponent, ModuleGuardComponent } from '@zyra/components';
import { TableCard, TableRow } from '@zyra/table';
import { useApiList } from '../../services/useApiList';
import CrawlerAnalyticsSection from './CrawlerAnalyticsSection';
import { useCrawlerAnalytics } from './useCrawlerAnalytics';
import type { PeriodDays } from './types';

const HistoricalCrawlTrendsCard = applyFilters(
	'vulopilot_crawler_historical_trends_card',
	null
) as ComponentType | null;

const CrawlerVisibilityCorrelationCard = applyFilters(
	'vulopilot_crawler_visibility_correlation_card',
	null
) as ComponentType | null;

const CrawlerAlertsCard = applyFilters(
	'vulopilot_crawler_alerts_card',
	null
) as ComponentType | null;

interface CrawlerVisitRow extends TableRow {
	id: number;
	bot_name: string;
	requested_url: string;
	created_at: string;
	/** Real `is_404` column (`wp_vulopilot_crawler_visits`). */
	is_404: string | number | boolean;
}

/**
 * Used to also render a separate "Last seen" tiles card (CrawlerSummaryCard.tsx) right here.
 */
/** Same real 7/30/90-day trio `SeoProgressCard.tsx`'s/`GeoScoreSection.tsx`'s own identical period toggles already use. */
const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

const CrawlOverviewSection = () => {
	const botNameOptions = [
		{ label: __('GPTBot (OpenAI)', 'vulopilot'), value: 'GPTBot (OpenAI)' },
		{
			label: __('ChatGPT-User (OpenAI)', 'vulopilot'),
			value: 'ChatGPT-User (OpenAI)',
		},
		{
			label: __('ClaudeBot (Anthropic)', 'vulopilot'),
			value: 'ClaudeBot (Anthropic)',
		},
		{
			label: __('anthropic-ai (Anthropic)', 'vulopilot'),
			value: 'anthropic-ai (Anthropic)',
		},
		{
			label: __('PerplexityBot (Perplexity)', 'vulopilot'),
			value: 'PerplexityBot (Perplexity)',
		},
		{
			label: __('Bytespider (ByteDance)', 'vulopilot'),
			value: 'Bytespider (ByteDance)',
		},
		{
			label: __('CCBot (Common Crawl)', 'vulopilot'),
			value: 'CCBot (Common Crawl)',
		},
		{
			label: __(
				'Google-CloudVertexBot (Google AI training)',
				'vulopilot'
			),
			value: 'Google-CloudVertexBot (Google AI training)',
		},
		{ label: __('Amazonbot (Amazon)', 'vulopilot'), value: 'Amazonbot (Amazon)' },
	];

	const {
		data,
		total,
		categoryCounts,
		isLoading,
		error,
		onQueryUpdate,
	} = useApiList<CrawlerVisitRow>(
		'crawler-traffic',
		{},
		{ key: 'bot_name', options: botNameOptions }
	);
	const [period, setPeriod] = useState<PeriodDays>('30');
	const { analytics, isLoading: isLoadingAnalytics } = useCrawlerAnalytics(
		Number(period)
	);

	return (
		<ColumnComponent>
			{error ? (
				<CardComponent
					title={__('Crawler Traffic', 'vulopilot')}
					titleIcon="error"
					desc={__('Real crawler visits to your site, over time.', 'vulopilot')}
				>
					<ModuleGuardComponent
						icon="error"
						title={__(
							'Could not load crawler traffic',
							'vulopilot'
						)}
						desc={error}
					/>
				</CardComponent>
			) : (
				<>
					<CrawlerAnalyticsSection
						analytics={analytics}
						isLoading={isLoadingAnalytics}
						period={period}
						periodOptions={PERIOD_OPTIONS}
						onPeriodChange={(value) => setPeriod(value as PeriodDays)}
					/>

					{CrawlerAlertsCard && <CrawlerAlertsCard />}
					{HistoricalCrawlTrendsCard && (
						<HistoricalCrawlTrendsCard />
					)}
					{CrawlerVisibilityCorrelationCard && (
						<CrawlerVisibilityCorrelationCard />
					)}

					<div id="recent-crawl-requests">
						<CardComponent
							title={__('Recent Crawl Requests', 'vulopilot')}
							titleIcon="request-send"
							desc={__('Every real crawler visit to your site, searchable and filterable.', 'vulopilot')}
						>
							<TableCard
								search={{
									placeholder: __(
										'Search requested URLs…',
										'vulopilot'
									),
								}}
								showMenu={false}
								hideHeader={true}
								format={vulopilotAppLocalizer.date_format_js}
								headers={{
									bot_name: {
										label: __('Bot', 'vulopilot'),
									},
									requested_url: {
										label: __('Requested URL', 'vulopilot'),
									},
									is_404: {
										label: __('Status', 'vulopilot'),
										render: (row: CrawlerVisitRow) =>
											Number(row.is_404) ? (
												<BadgeComponent color="red" text={__('Not Found', 'vulopilot')} />
											) : (
												<BadgeComponent color="green" text={__('Success', 'vulopilot')} />
											),
									},
									created_at: {
										label: __('When', 'vulopilot'),
										type: 'date',
										isSortable: true,
										defaultSort: true,
										defaultOrder: 'desc',
									},
								}}
								rows={data}
								ids={data.map((row) => row.id)}
								totalRows={total}
								categoryCounts={categoryCounts}
								isLoading={isLoading}
								onQueryUpdate={onQueryUpdate}
								emptyMessage={__(
									'No AI crawler visits detected yet.',
									'vulopilot'
								)}
							/>
						</CardComponent>
					</div>
				</>
			)}
		</ColumnComponent>
	);
};

export default CrawlOverviewSection;
