/* global vulopilotAppLocalizer */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { COLOR_PALETTE, getApiLink, getApiResponse } from '@zyra/core';
import { AnalyticsComponent, CardComponent, ChartComponent, ColumnComponent, IconComponent, ListComponent, TypographyComponent } from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import { formatWpDate } from '../../services/formatWpDate';
import { useGeoScore } from './useGeoScore';
import type { GeoSignalScore } from './useGeoScore';
import './SeoVisibility.scss';
import type { PeriodDays } from './types';

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
		return 'yellow';
	}
	return 'red';
};

/**
 * This card's own 7 `SIGNAL_META` keys → GeoTab.tsx's own 5 real `GEO_TOPICS` keys.
 */
const SIGNAL_TO_TOPIC_KEY: Record<string, string> = {
	'ai-summary': 'ai-summary',
	'question-coverage': 'faq-questions',
	'evidence-citations': 'evidence-citations',
	'ai-readable-structure': 'ai-readable-structure',
	'entity-clarity': 'other-signals',
	'content-freshness': 'other-signals',
	'other-geo-signals': 'other-signals',
};

/**
 * The 7 real signals `Geo.php`'s own `SIGNAL_SCANNER_IDS` (+ the separately computed `content-
 * freshness`) return.
 */
const SIGNAL_META: { key: keyof import('./useGeoScore').GeoScoreResponse['signals']; label: string; icon: string; description: string }[] = [
	{
		key: 'ai-summary',
		label: __('AI Summary', 'vulopilot'),
		icon: 'ai orange',
		description: __(
			'Whether pages have an extractable AI summary block an AI system can lift directly.',
			'vulopilot'
		),
	},
	{
		key: 'question-coverage',
		label: __('Question Coverage', 'vulopilot'),
		icon: 'question blue',
		description: __(
			'Commonly-asked questions a page plausibly answers, but with no FAQ or Q&A block making that answer easy to extract.',
			'vulopilot'
		),
	},
	{
		key: 'evidence-citations',
		label: __('Evidence & Citations', 'vulopilot'),
		icon: 'report sky',
		description: __(
			'Statistic-shaped claims with no citation or outbound link backing them up.',
			'vulopilot'
		),
	},
	{
		key: 'ai-readable-structure',
		label: __('AI-Readable Structure', 'vulopilot'),
		icon: 'blocks lime',
		description: __(
			'Paragraph length and heading hierarchy - how easily an AI system can extract a clean chunk of this content.',
			'vulopilot'
		),
	},
	{
		key: 'entity-clarity',
		label: __('Entity Clarity', 'vulopilot'),
		icon: 'person cyan',
		description: __(
			'Whether your brand, people, and product names are used consistently enough for AI to recognize them as the same entity.',
			'vulopilot'
		),
	},
	{
		key: 'content-freshness',
		label: __('Content Freshness', 'vulopilot'),
		icon: 'calendar green',
		description: __(
			'How recently your published pages have been updated, relative to your own "stale after" setting.',
			'vulopilot'
		),
	},
	{
		key: 'other-geo-signals',
		label: __('Other GEO Signals', 'vulopilot'),
		icon: 'module violet',
		description: __('Author credentials, trust signals, and llms.txt.', 'vulopilot'),
	},
];

const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

interface ProgressResponse {
	days: number;
	trend: { date: string; score: number }[];
}

/** Same real `document.getElementById(...).scrollIntoView()` convention `QuickActionsCard.tsx`'s own `scrollTo()` already establishes. */
const scrollToBreakdown = () => {
	document
		.getElementById('geo-score-breakdown-table')
		?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

/**
 * Real per-signal score change - same real "current score minus the oldest point in this signal's
 * own real `trend`" shape `SeoTab.tsx`'s own `categoryScoreDelta()` already established, now
 * backed by `Geo.php`'s own real `signal.trend` (added alongside this).
 */
const signalScoreDelta = (signal: GeoSignalScore): number | null =>
	null !== signal.score && signal.trend && signal.trend.length > 0
		? signal.score - signal.trend[0]
		: null;

const mainProblemText = (key: string, signal: GeoSignalScore): string => {
	if (signal.main_problem) {
		return signal.main_problem;
	}
	if (null === signal.score) {
		return __('Not enough content to check yet', 'vulopilot');
	}
	return 'content-freshness' === key
		? __('All checked pages are reasonably up to date', 'vulopilot')
		: __('No open issues found', 'vulopilot');
};

/**
 * Layout: GEO Score ring + "How this score is calculated" signal list (top left) / Score Snapshot
 * real day trend (top right) / GEO Score Breakdown table - Signal, Score, Status, Main Problem
 * (bottom left).
 */
interface GeoScoreSectionProps {
	/**
	 * GeoTab.tsx's own real `goToIssuesTable()` (its `setCategoryFocus` wrapper).
	 */
	// eslint-disable-next-line no-unused-vars
	onSelectSignal?: (topicKey: string) => void;
}

const GeoScoreSection = ({ onSelectSignal }: GeoScoreSectionProps) => {
	const { score, isLoading } = useGeoScore();
	const [period, setPeriod] = useState<PeriodDays>('30');
	const [progress, setProgress] = useState<ProgressResponse | null>(null);
	const [isLoadingProgress, setIsLoadingProgress] = useState(true);

	useEffect(() => {
		setIsLoadingProgress(true);
		getApiResponse<ProgressResponse>(
			getApiLink(vulopilotAppLocalizer, `geo/progress?days=${period}`),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => response && setProgress(response))
			.finally(() => setIsLoadingProgress(false));
	}, [period]);

	const overall = score?.geo_score ?? 0;

	return (
		<>
			<ColumnComponent grid={6}>
				<CardComponent
					title={__('GEO Score', 'vulopilot')}
					titleIcon='tools'
					desc={sprintf(
						/* translators: %d: real number of published pages/posts every GEO scanner scans. */
						__('Based on %d published pages.', 'vulopilot'),
						score?.pages_checked ?? 0
					)}
					isLoading={isLoading}
				>
					<div className="overall-score-wrapper">
						<div className="overall-score-summary">
								<ChartComponent
									type="ring"
									height={200}
									// Top-level `color` - `type="ring"` only ever paints its stroke
									// from this prop.
									color={
										COLOR_PALETTE[
											ratingClass(overall) as keyof typeof COLOR_PALETTE
										]
									}
									centerLabel={
										<>
											<TypographyComponent variant={'h1'} color={ratingClass(overall)}>
												{overall}
											</TypographyComponent>
											<TypographyComponent variant={'h4'}>
												{getRating(overall)}
											</TypographyComponent>
										</>
									}
									data={[
										{
											label: __('Score', 'vulopilot'),
											value: overall,
											// Same real rating color the ring's own Good/Needs
											// Work/Poor label above already uses
											// (`ratingClass()`/`getRating()`).
											color: COLOR_PALETTE[
												ratingClass(overall) as keyof typeof COLOR_PALETTE
											],
										},
										{ label: __('Remaining', 'vulopilot'), value: 100 - overall, color: '#e5e7eb' },
									]}
								/>
								<TypographyComponent variant={'h3'} color="text-green">
									{__('GEO Score', 'vulopilot')}
								</TypographyComponent>
								<div className="desc">
									{sprintf(
										/* translators: %d: real number of published pages/posts every GEO scanner scans. */
										__('Based on %d published pages.', 'vulopilot'),
										score?.pages_checked ?? 0
									)}
								</div>
						</div>
						{/* * Same real `ListComponent` "mini-card report" row * shape SeoTab.tsx's own "SEO Health" card uses for * its 6 category rows. */}
						 <div className="overall-score-summary">
						<ListComponent
							className="mini-card report hover without-border seo-health-score-category-list"
							loading={isLoading}
							items={SIGNAL_META.map((meta) => {
								const signal = score?.signals[meta.key];
								const signalScore = signal?.score ?? null;
								const delta = signal ? signalScoreDelta(signal) : null;

								return {
									id: meta.key,
									icon: meta.icon,
									title: meta.label,
									desc:
										null === signalScore
											? mainProblemText(meta.key, signal ?? {
													score: null,
													open_count: null,
													affected_pages: 0,
													main_problem: null,
													trend: null,
												})
											: sprintf(
													/* translators: %d: real number of open findings. */
													__('%d issues', 'vulopilot'),
													signal?.open_count ?? 0
												),
									tags: (
										<>
											
											{null !== signalScore && (
												<TypographyComponent
													variant="h5"
													weight="bold"
													color={ratingClass(signalScore)}
													className="seo-health-score-row-value"
												>
													{signalScore}
													<TypographyComponent
														as="span"
														variant="body-md"
														className="seo-health-score-row-suffix"
													>
														/100
													</TypographyComponent>
												</TypographyComponent>
											)}
											{null !== delta && (
												<TypographyComponent
													as="span"
													variant="body-md"
													weight="bold"
													color={delta >= 0 ? 'green' : 'red'}
													className="seo-health-score-row-delta"
												>
													<IconComponent
														name={delta >= 0 ? 'arrow-up' : 'arrow-down'}
													/>
													{Math.abs(delta)}
												</TypographyComponent>
											)}
										</>
									),
									action: () =>
										onSelectSignal
											? onSelectSignal(SIGNAL_TO_TOPIC_KEY[meta.key])
											: scrollToBreakdown(),
								};
							})}
						/>
						</div>
					</div>
					{score && (
						<AnalyticsComponent
							variant="with-out-boxshadow"
							cols={2}
							isLoading={isLoading}
							data={[
								{
									number: score.pages_checked,
									text: __('Pages checked', 'vulopilot'),
									iconClass: 'admin-bg-color2',
								},
								{
									// Real sum of every signal's own real `open_count` -
									// `GeoScoreResponse` has no single "total open" field the way
									// `SeoScoreResponse.total_open` does.
									number: Object.values(score.signals).reduce(
										(sum, signal) => sum + (signal.open_count ?? 0),
										0
									),
									text: __('Issues found', 'vulopilot'),
									iconClass: 'admin-bg-color3',
								},
							]}
						/>
					)}
				</CardComponent>
			</ColumnComponent>
			<ColumnComponent grid={6} fullHeight>
				<CardComponent
					title={__('Score Snapshot', 'vulopilot')}
					desc={__('How your GEO score has trended over the selected period.', 'vulopilot')}
					titleIcon='tools'
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
							data={progress.trend.map((point) => ({
								...point,
								date: formatWpDate(point.date),
							}))}
							dataKey="score"
							xKey="date"
							height={300}
							yDomain={[0, 100]}
						/>
					)}
				</CardComponent>
			</ColumnComponent>
		</>
	);
};

export default GeoScoreSection;
