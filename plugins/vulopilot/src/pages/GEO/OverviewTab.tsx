/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import type { PeriodDays } from './types';
import {
	CardComponent,
	ChartComponent,
	ColumnComponent,
	ContainerComponent,
	IconComponent,
	ListComponent,
	TypographyComponent,
} from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import { formatWpDate } from '../../services/formatWpDate';
import type { FindingGroup } from '../../components/Issues/issuesTypes';
import { useVisibilityScore } from './useVisibilityScore';
import type { VisibilityScoreResponse } from './useVisibilityScore';
import type { CrawlUrlsSectionId } from './CrawlUrlsTab';
import GeoFixTheseFirstCard from './GeoFixTheseFirstCard';
import VisibilityBySourceCard from './VisibilityBySourceCard';
import './SeoVisibility.scss';

interface OverviewTabProps {
	onNavigateTab: (
		// eslint-disable-next-line no-unused-vars
		tab: string,
		// eslint-disable-next-line no-unused-vars
		crawlUrlsSection?: CrawlUrlsSectionId,
		// eslint-disable-next-line no-unused-vars
		scannerId?: string
	) => void;
}

const getRating = (score: number): string => {
	if (score >= 70) {
		return __('Good', 'vulopilot');
	}
	if (score >= 40) {
		return __('Needs Work', 'vulopilot');
	}
	return __('At Risk', 'vulopilot');
};

const ratingClass = (score: number): string => {
	if (score >= 70) {
		return 'green';
	}
	if (score >= 40) {
		return 'blue';
	}
	return 'red';
};

/** Dynamic caption under the ring's own "Overall Score" label. */
const getRatingSummary = (score: number): string => {
	if (score >= 70) {
		return __('Your visibility is in good shape across the board.', 'vulopilot');
	}
	if (score >= 40) {
		return __('Your visibility could use some improvement.', 'vulopilot');
	}
	return __('Your visibility needs attention in several areas.', 'vulopilot');
};

/** Real CSS hex per `ratingClass()` tier - `ChartComponent`'s own ring `data[].color` takes a real CSS color. */
const RATING_RING_COLOR: Record<string, string> = {
	green: '#16a34a',
	blue: '#2563eb',
	red: '#dc2626',
};

/** Real per-area tab id `QUICK_LINKS` below already uses for the same 4 areas. */
const AREA_TABS: Record<keyof VisibilityScoreResponse['areas'], string> = {
	brand: 'brand-visibility',
	seo: 'seo',
	geo: 'geo',
	crawl: 'crawl-urls',
};

const AREA_TILES: Record<
	keyof VisibilityScoreResponse['areas'],
	{ title: string; icon: string }
> = {
	brand: { title: __('Brand Visibility Score', 'vulopilot'), icon: 'person' },
	seo: { title: __('SEO Health Score', 'vulopilot'), icon: 'search' },
	geo: { title: __('GEO Visibility Score', 'vulopilot'), icon: 'search-discovery' },
	crawl: { title: __('Crawl & URLs Score', 'vulopilot'), icon: 'url' },
};

// Same real `key` field `GeoScoreSection.tsx`'s own identical `ToggleInput` usage already
// includes.
const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

interface ProgressResponse {
	days: number;
	trend: { date: string; score: number }[];
}

/** Real `FindingGroup.category` values → the real SEO & Visibility subtab that owns that category's findings. */
const CATEGORY_TO_TAB: Record<string, string> = {
	geo: 'geo',
	brand: 'brand-visibility',
	schema: 'schema-knowledge',
	links: 'crawl-urls',
};
const categoryToTab = (category: string): string => CATEGORY_TO_TAB[category] ?? 'seo';

/**
 * Scanner id → the SEO & Visibility subtab that actually surfaces that scanner's findings.
 */
const SCANNER_TAB_OVERRIDES: Record<string, string> = {
	'geo-trust-signals': 'brand-visibility',
	'geo-eeat-signals': 'brand-visibility',
	'geo-author-info': 'brand-visibility',
	'geo-entity-naming-consistency': 'brand-visibility',
	'about-page-analysis': 'brand-visibility',
	'author-schema': 'brand-visibility',
	'organization-schema': 'brand-visibility',
	'aeo-schema': 'aeo',
	'geo-summary-block': 'aeo',
	'geo-faq-opportunity': 'aeo',
	'geo-chunking': 'geo',
	'geo-semantic-structure': 'geo',
	// Crawl & URLs' own Robots/Sitemap/AI-crawler and Broken Links sections.
	sitemap: 'crawl-urls',
	'sitemap-validation': 'crawl-urls',
	'robots-txt': 'crawl-urls',
	'ai-crawler-blocked-pages': 'crawl-urls',
	'broken-links': 'crawl-urls',
	'not-found': 'crawl-urls',
	'redirect-analysis': 'crawl-urls',
	// Business Identity & Schema's own issues list.
	schema: 'schema-knowledge',
	'structured-data': 'schema-knowledge',
	'sitewide-structured-data': 'schema-knowledge',
};
/** Which Crawl & URLs inner tab owns each of those scanners. */
const CRAWL_SECTION_BY_SCANNER: Record<string, CrawlUrlsSectionId> = {
	sitemap: 'robots-sitemap',
	'sitemap-validation': 'robots-sitemap',
	'robots-txt': 'robots-sitemap',
	'ai-crawler-blocked-pages': 'robots-sitemap',
	'broken-links': 'broken-links',
	'not-found': '404s',
	'redirect-analysis': 'redirects',
};
const groupToTab = (group: FindingGroup): string =>
	SCANNER_TAB_OVERRIDES[group.scanner_id] ?? categoryToTab(group.category);

// Same real "icon name" + trailing color modifier convention `SeoTab.tsx`'s own `CATEGORY_CARDS`
// already establishes (e.g. `'search blue'`).
const QUICK_LINKS: { tab: string; icon: string; title: string; desc: string }[] = [
	{
		tab: 'brand-visibility',
		icon: 'person purple',
		title: __('Brand Visibility', 'vulopilot'),
		desc: __('Check how AI understands your brand.', 'vulopilot'),
	},
	{
		tab: 'seo',
		icon: 'search blue',
		title: __('SEO', 'vulopilot'),
		desc: __('Optimize for search engines.', 'vulopilot'),
	},
	{
		tab: 'geo',
		icon: 'search-discovery green',
		title: __('GEO (AI Visibility)', 'vulopilot'),
		desc: __('Improve visibility in AI answers.', 'vulopilot'),
	},
	{
		tab: 'aeo',
		icon: 'ai orange',
		title: __('AEO', 'vulopilot'),
		desc: __('Answer-engine readiness checks.', 'vulopilot'),
	},
	{
		tab: 'keywords',
		icon: 'vpn-key yellow',
		title: __('Keywords', 'vulopilot'),
		desc: __('Track your keyword rankings.', 'vulopilot'),
	},
	{
		tab: 'crawl-urls',
		icon: 'url teal',
		title: __('Crawl & URLs', 'vulopilot'),
		desc: __('robots.txt, sitemaps, redirects & more.', 'vulopilot'),
	},
	{
		tab: 'schema-knowledge',
		icon: 'identity-verification red',
		title: __('Business Identity & Schema', 'vulopilot'),
		desc: __('Manage structured data & entities.', 'vulopilot'),
	},
];

/**
 * "SEO & Visibility" Overview tab: score cards, trend, breakdown, opportunities, activity and
 * quick links.
 */
const OverviewTab = ({ onNavigateTab }: OverviewTabProps) => {
	const { score, isLoading } = useVisibilityScore();
	const [period, setPeriod] = useState<PeriodDays>('30');
	const [progress, setProgress] = useState<ProgressResponse | null>(null);
	const [isLoadingProgress, setIsLoadingProgress] = useState(true);
	const [opportunityGroups, setOpportunityGroups] = useState<FindingGroup[]>([]);
	const [opportunityTotal, setOpportunityTotal] = useState(0);
	const [isLoadingOpportunities, setIsLoadingOpportunities] = useState(true);

	useEffect(() => {
		setIsLoadingProgress(true);
		getApiResponse<ProgressResponse>(
			getApiLink(vulopilotAppLocalizer, `visibility/progress?days=${period}`),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => response && setProgress(response))
			.finally(() => setIsLoadingProgress(false));
	}, [period]);

	useEffect(() => {
		getApiResponse<{ data: FindingGroup[]; total: number }>(
			getApiLink(
				vulopilotAppLocalizer,
				// Same real category grouping issuesTypes.ts's own CATEGORY_TABS already
				// establishes for "SEO & Visibility" ('seo','images','schema','links') + "AI
				// Visibility" ('geo','brand').
				'findings/groups?per_page=5&status=open&category=seo,images,schema,links,geo,brand'
			),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => {
				if (response) {
					setOpportunityGroups(response.data);
					setOpportunityTotal(response.total);
				}
			})
			.finally(() => setIsLoadingOpportunities(false));
	}, []);

	const areas = score?.areas;

	const trendUplift =
		progress && progress.trend.length > 1
			? progress.trend[progress.trend.length - 1].score - progress.trend[0].score
			: null;

	return (
		<ContainerComponent>
			<ColumnComponent grid={6} fullHeight>
				{/* * Same real ring + `ListComponent` "mini-card report" row * shape `SeoTab.tsx`'s own "SEO Health" card already uses. */}
				<CardComponent
					title={__('Visibility Score', 'vulopilot')}
					titleIcon="bar-chart"
					desc={__('Your real, combined score across Brand, SEO, GEO, and Crawl & URLs.', 'vulopilot')}
					isLoading={isLoading}
				>
					{score && (
						<div className="overall-score-wrapper">
							<div className="overall-score-summary">
									<ChartComponent
										type="ring"
										height={200}
										// Top-level `color`, as OverallScoreWidget.tsx's ring reads: `type="ring"` never reads per-row
										// `data[].color` (only `type="pie"` does; see BusinessProfileCard.tsx).
										color={RATING_RING_COLOR[ratingClass(score.visibility_score)]}
										centerLabel={
											<>
												<TypographyComponent
													variant={'h1'}
													color={ratingClass(score.visibility_score)}
												>
													{score.visibility_score}
												</TypographyComponent>
												<TypographyComponent variant={'h4'}>
													{getRating(score.visibility_score)}
												</TypographyComponent>
											</>
										}
										data={[
											{
												label: __('Score', 'vulopilot'),
												value: score.visibility_score,
												color: RATING_RING_COLOR[ratingClass(score.visibility_score)],
											},
											{
												label: __('Remaining', 'vulopilot'),
												value: 100 - score.visibility_score,
												color: '#e5e7eb',
											},
										]}
									/>
									{/* * "Overall Score" - was a verbatim repeat of this card's * own header title ("Visibility Score") right above it. */}
									<TypographyComponent variant={'h3'} color="text-green">
										{__('Overall Score', 'vulopilot')}
									</TypographyComponent>
									<div className="desc">
										{getRatingSummary(score.visibility_score)}
									</div>
							</div>
							<div className="overall-score-summary">
							<ListComponent
								className="mini-card report hover seo-health-score-category-list"
								loading={isLoading}
								items={(
									Object.keys(AREA_TILES) as (keyof VisibilityScoreResponse['areas'])[]
								).map((key) => {
									const area = areas?.[key];
									const tile = AREA_TILES[key];

									return {
										id: key,
										icon: tile.icon,
										title: tile.title,
										tags: area ? (
											<>
												<TypographyComponent
													variant="h5"
													weight="bold"
													color={ratingClass(area.score)}
													className="seo-health-score-row-value"
												>
													{area.score}
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
													color={area.change >= 0 ? 'green' : 'red'}
													className="seo-health-score-row-delta"
												>
													<IconComponent
														name={area.change >= 0 ? 'arrow-up' : 'arrow-down'}
													/>
													{Math.abs(area.change)}
												</TypographyComponent>
											</>
										) : null,
										action: () => onNavigateTab(AREA_TABS[key]),
									};
								})}
							/>
							</div>
						</div>
					)}
				</CardComponent>
			</ColumnComponent>
			<ColumnComponent grid={6} fullHeight>
				<CardComponent
					title={__('Visibility Trend', 'vulopilot')}
					titleIcon='security'
					desc={
						null !== trendUplift
							? sprintf(
								/* translators: 1: signed real point change, 2: real number of days the chart covers. */
								__('%1$s points vs %2$d days ago', 'vulopilot'),
								trendUplift >= 0 ? `+${trendUplift}` : `${trendUplift}`,
								progress?.days ?? 30
							)
							: __('Combined score across Brand, SEO, GEO, and Crawl & URLs.', 'vulopilot')
					}
					isLoading={isLoadingProgress}
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
					{progress && (
						<ChartComponent
							type="dynamic-line"
							data={progress.trend.map((point: { date: string; score: number }) => ({
								...point,
								date: formatWpDate(point.date),
							}))}
							dataKey="score"
							xKey="date"
							height={220}
							yDomain={[0, 100]}
						/>
					)}
				</CardComponent>
			</ColumnComponent>

			<ColumnComponent grid={8} fullHeight>
				<VisibilityBySourceCard />
				<GeoFixTheseFirstCard
					title={__('Top Opportunities', 'vulopilot')}
					emptyMessage={__('No open findings right now - nothing to fix.', 'vulopilot')}
					groups={opportunityGroups}
					total={opportunityTotal}
					isLoading={isLoadingOpportunities}
					onViewAll={() =>
						opportunityGroups.length
							? onNavigateTab(
									groupToTab(opportunityGroups[0]),
									CRAWL_SECTION_BY_SCANNER[opportunityGroups[0].scanner_id],
									opportunityGroups[0].scanner_id
								)
							: onNavigateTab('seo')
					}
					onSelectScanner={(scannerId) => {
						const group = opportunityGroups.find((g) => g.scanner_id === scannerId);
						onNavigateTab(group ? groupToTab(group) : 'seo', CRAWL_SECTION_BY_SCANNER[scannerId], scannerId);
					}}
				/>
			</ColumnComponent>
			<ColumnComponent grid={4}>
				<CardComponent
					title={__('Quick Links', 'vulopilot')}
					titleIcon="link"
					desc={__('Jump straight to any SEO & Visibility section.', 'vulopilot')}
				>
					{/* Same real `mini-card report hover` row shape the "SEO Health"/"Visibility Score" cards above already use. */}
					<ListComponent
						className="mini-card report hover"
						items={QUICK_LINKS.map((link) => ({
							id: link.tab,
							icon: link.icon,
							title: link.title,
							desc: link.desc,
							tags: (
								<i className="adminfont-pagination-right-arrow ai-copilot-row-arrow" />
							),
							action: () => onNavigateTab(link.tab),
						}))}
					/>
				</CardComponent>
			</ColumnComponent>
		</ContainerComponent>
	);
};

export default OverviewTab;
