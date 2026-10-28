/* global vulopilotAppLocalizer */
import { useEffect, useMemo, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse, COLOR_PALETTE } from '@zyra/core';
import {
	CardComponent,
	ChartComponent,
	ColumnComponent,
	ContainerComponent,
	FormGroupComponent,
	FormGroupWrapperComponent,
	ListComponent,
	ModuleGuardComponent,
	TooltipComponent,
	TypographyComponent,
} from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import { TableCard } from '@zyra/table';
import { formatWpDate } from '../../services/formatWpDate';
import RecommendedFixesCard from './RecommendedFixesCard';
import './Performance.scss';


interface PageSpeedRow {
	id: number;
	url: string;
	title: string;
	page_type: string;
	load_time_ms: number | null;
	score: number | null;
	status: 'slow' | 'needs_improvement' | 'good' | null;
	mobile_score: number | null;
	desktop_score: number | null;
	main_issue: string | null;
	page_size_bytes: number | null;
	requests_count: number | null;
	lcp_ms: number | null;
	lcp_rating: string | null;
	inp_ms: number | null;
	inp_rating: string | null;
	cls_thousandths: number | null;
	cls_rating: string | null;
	scanned_at: string;
}

interface PageSpeedSummary {
	total: number;
	slow: number;
	very_slow: number;
	needs_improvement: number;
	good: number;
	avg_score: number | null;
	avg_mobile_score: number | null;
	avg_load_time_ms: number | null;
	last_scanned_at: string | null;
}

export interface PageSpeedIssue {
	issue: string;
	affected_pages: number;
}

interface PageSpeedResponse {
	summary: PageSpeedSummary;
	status_counts: Record<string, number>;
	top_issues: PageSpeedIssue[];
	data: PageSpeedRow[];
	total: number;
	/** Pages a running scan has still to check; 0 once it has finished. */
	pending?: number;
}

interface ScoreSnapshot {
	snapshot_date: string;
	performance_score: number;
}


const PAGE_TYPE_ICONS: Record<string, string> = {
	homepage: 'home',
	page: 'document',
	post: 'web-page-website',
	shop: 'storefront',
	cart: 'cart',
	checkout: 'credit-card',
	product: 'product',
	category: 'category',
};

const PAGE_TYPE_LABELS: Record<string, string> = {
	homepage: __('Homepage', 'vulopilot'),
	page: __('Page', 'vulopilot'),
	post: __('Post', 'vulopilot'),
	shop: __('Shop Page', 'vulopilot'),
	cart: __('Cart Page', 'vulopilot'),
	checkout: __('Checkout Page', 'vulopilot'),
	product: __('Product Page', 'vulopilot'),
	category: __('Category Page', 'vulopilot'),
};

/** Same 80/50/25 score bands the page-speed summary itself uses. */
const avgScoreSummary = (score: number | null): string => {
	if (null === score) {
		return __('Not enough scanned pages yet to compute an average score.', 'vulopilot');
	}
	if (score >= 90) {
		return __('Your pages are loading excellently.', 'vulopilot');
	}
	if (score >= 80) {
		return __('Your pages are loading well, but there are still a few pages that can be improved.', 'vulopilot');
	}
	if (score >= 50) {
		return __('Several pages could use performance improvements.', 'vulopilot');
	}
	return __('Many of your pages need performance attention.', 'vulopilot');
};

const ratingFor = (score: number | null): { label: string; className: 'good' | 'needs-improvement' | 'poor' | 'unknown' } => {
	if (null === score) {
		return { label: __('Not scored yet', 'vulopilot'), className: 'unknown' };
	}

	if (score >= 80) {
		return { label: __('Good', 'vulopilot'), className: 'good' };
	}

	if (score >= 50) {
		return { label: __('Needs Work', 'vulopilot'), className: 'needs-improvement' };
	}

	return { label: __('At Risk', 'vulopilot'), className: 'poor' };
};

/** Real zyra palette hex (`@zyra/core`'s `COLOR_PALETTE`). */
const RATING_RING_COLOR: Record<'good' | 'needs-improvement' | 'poor' | 'unknown', string> = {
	good: COLOR_PALETTE.green,
	'needs-improvement': COLOR_PALETTE.orange,
	poor: COLOR_PALETTE.red,
	unknown: COLOR_PALETTE.gray,
};

const ScorePill = ({ score }: { score: number | null }) => {
	const rating = ratingFor(score);

	return (
		<ChartComponent
			type="ring"
			height={40}
			color={RATING_RING_COLOR[rating.className]}
			data={[{ value: score ?? 0 }]}
			centerLabel={
				<span className={`page-speed-score-ring-value ${rating.className}`}>
					{null === score ? '-' : score}
				</span>
			}
		/>
	);
};

/** Real byte count → a human string, same MB/KB thresholds browser devtools use. */
const formatBytes = (bytes: number | null): string => {
	if (null === bytes) {
		return '-';
	}

	if (bytes >= 1024 * 1024) {
		return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
	}

	if (bytes >= 1024) {
		return `${(bytes / 1024).toFixed(0)} KB`;
	}

	return `${bytes} B`;
};

/**
 * Google's own real CrUX field-data category ('FAST'/'AVERAGE'/'SLOW') for one Core Web Vital,
 * passed through verbatim from PageSpeedScanner.
 */
const CWV_DOT_CLASS: Record<string, string> = {
	FAST: 'good',
	AVERAGE: 'needs-improvement',
	SLOW: 'poor',
};

/** `ratingFor()`'s own className → the closest `admin-badge` color name. */
const RATING_BADGE_COLOR: Record<string, string> = {
	good: 'green',
	'needs-improvement': 'orange',
	poor: 'red',
	'very-poor': 'red',
	unknown: '',
};

const CWV_METRICS = [
	{ key: 'lcp', label: 'LCP', ratingKey: 'lcp_rating' as const },
	{ key: 'inp', label: 'INP', ratingKey: 'inp_rating' as const },
	{ key: 'cls', label: 'CLS', ratingKey: 'cls_rating' as const },
];

/**
 * Real Google CrUX field-data dots - one per metric, gray/"unknown" when that metric has no real
 * field data for this page (common for low-traffic pages; CrUX only reports once enough real
 * visits exist).
 */
const CoreWebVitalsDots = ({ row }: { row: PageSpeedRow }) => (
	<div className="page-speed-cwv-dots">
		{CWV_METRICS.map((metric) => {
			const rating = row[metric.ratingKey];
			const dotClass = rating ? (CWV_DOT_CLASS[rating] ?? 'unknown') : 'unknown';

			return (
				<span
					key={metric.key}
					className="page-speed-cwv-item"
					title={
						rating
							? `${metric.label}: ${rating}`
							: `${metric.label}: ${__('No field data yet', 'vulopilot')}`
					}
				>
					<span className={`page-speed-cwv-dot ${dotClass}`} />
					<span className="page-speed-cwv-label">{metric.label}</span>
				</span>
			);
		})}
	</div>
);

const csvEscape = (value: string): string => `"${value.replace(/"/g, '""')}"`;

/**
 * "Slow Pages" tab of "Performance" - real per-page speed data from `GET /page-speed`
 * (PageSpeedRepository, populated in the background by Services\PageSpeedScanner).
 */
const SlowPagesTab = () => {
	const [response, setResponse] = useState<PageSpeedResponse | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [statusFilter, setStatusFilter] = useState('all');
	const [pageTypeFilter, setPageTypeFilter] = useState('');
	const [searchTerm, setSearchTerm] = useState('');
	const [detailRow, setDetailRow] = useState<PageSpeedRow | null>(null);
	const [trendDays] = useState('30');
	// Real "which real PSI device's own scores to show" toggle for the "Slow Pages Score" summary
	// card's header.
	const [scoreDevice, setScoreDevice] = useState<'mobile' | 'desktop'>('mobile');
	const [trend, setTrend] = useState<ScoreSnapshot[]>([]);
	const [isTrendLoading, setIsTrendLoading] = useState(true);
	// This table's own rows are all fetched once (per_page=200) and filtered/searched entirely
	// client-side (see this file's own docblock).
	const [paged, setPaged] = useState(1);
	const [perPage, setPerPage] = useState(10);

	const load = () => {
		setIsLoading(true);
		setError(null);

		getApiResponse<PageSpeedResponse>(
			getApiLink(vulopilotAppLocalizer, 'page-speed') + '?per_page=200',
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((data) => {
				if (data) {
					setResponse(data);
				} else {
					setError(
						__(
							'Could not load Slow Pages.',
							'vulopilot'
						)
					);
				}
			})
			.finally(() => setIsLoading(false));
	};

	useEffect(() => {
		load();
	}, []);

	// "Scan Again" lives in the page header (Performance.tsx) and only starts a background scan.
	useEffect(() => {
		window.addEventListener('vulopilot_page_speed_scan_started', load);

		return () => window.removeEventListener('vulopilot_page_speed_scan_started', load);
	}, []);

	const pendingPages = response?.pending ?? 0;

	useEffect(() => {
		if (pendingPages <= 0) {
			return;
		}

		const timer = window.setTimeout(load, 5000);

		return () => window.clearTimeout(timer);
	}, [pendingPages, response]);

	useEffect(() => {
		let cancelled = false;
		setIsTrendLoading(true);

		getApiResponse<ScoreSnapshot[]>(
			`${getApiLink(vulopilotAppLocalizer, 'performance-score-snapshots')}?days=${trendDays}`,
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((data) => {
				if (!cancelled) {
					setTrend(
						(data ?? []).map((row) => ({
							snapshot_date: row.snapshot_date,
							performance_score: Number(row.performance_score),
						}))
					);
				}
			})
			.finally(() => {
				if (!cancelled) {
					setIsTrendLoading(false);
				}
			});

		return () => {
			cancelled = true;
		};
	}, [trendDays]);

	// The "Scan Again" trigger for this real per-page speed scan (`POST /page-speed`) lives in
	// Performance.tsx's own page header now.

	const rows = response?.data ?? [];
	const hasDeviceScores = rows.some((row) => null !== row.mobile_score);
	const hasPsiDetail = rows.some((row) => null !== row.page_size_bytes);
	// Real average - `avg_mobile_score` only when a PSI key is configured (per-device split
	// available).
	const avgScore = response?.summary
		? (hasDeviceScores
			? response.summary.avg_mobile_score
			: response.summary.avg_score)
		: null;
	const avgLoadTimeMs = response?.summary?.avg_load_time_ms ?? null;

	/**
	 * Real average mobile/desktop PSI scores, computed client-side from this same `rows` fetch.
	 */
	const avgDeviceScore = (key: 'mobile_score' | 'desktop_score'): number | null => {
		const scores = rows
			.map((row) => row[key])
			.filter((score): score is number => null !== score);

		return scores.length
			? Math.round(scores.reduce((sum, score) => sum + score, 0) / scores.length)
			: null;
	};

	const avgMobileScore = hasDeviceScores ? avgDeviceScore('mobile_score') : null;
	const avgDesktopScore = hasDeviceScores ? avgDeviceScore('desktop_score') : null;

	const trendDelta =
		trend.length >= 2
			? trend[trend.length - 1].performance_score - trend[0].performance_score
			: null;
	const isTrendImproving = null !== trendDelta && trendDelta >= 0;

	const pageTypeOptions = useMemo(() => {
		const present = Array.from(new Set(rows.map((row) => row.page_type)));
		return [
			{ label: __('All Page Types', 'vulopilot'), value: '' },
			...present.map((type) => ({
				label: PAGE_TYPE_LABELS[type] ?? type,
				value: type,
			})),
		];
	}, [rows]);

	const filteredRows = useMemo(() => {
		return rows.filter((row) => {
			if ('all' !== statusFilter && row.status !== statusFilter) {
				return false;
			}

			if (pageTypeFilter && row.page_type !== pageTypeFilter) {
				return false;
			}

			if (
				searchTerm &&
				!row.title.toLowerCase().includes(searchTerm.toLowerCase()) &&
				!row.url.toLowerCase().includes(searchTerm.toLowerCase())
			) {
				return false;
			}

			return true;
		});
	}, [rows, statusFilter, pageTypeFilter, searchTerm]);

	// A stale page 3 left over from a previous filter could otherwise show
	// an empty page once a new filter/search narrows the real result set.
	useEffect(() => {
		setPaged(1);
	}, [statusFilter, pageTypeFilter, searchTerm]);

	// Auto-open the first row's details whenever the filtered set changes and nothing valid is
	// currently selected.
	useEffect(() => {
		if (0 === filteredRows.length) {
			setDetailRow(null);
			return;
		}

		const stillVisible = filteredRows.some((row) => row.id === detailRow?.id);

		if (!stillVisible) {
			setDetailRow(filteredRows[0]);
		}
	}, [filteredRows]);

	const pageRows = filteredRows.slice((paged - 1) * perPage, paged * perPage);

	const statusCounts = response?.status_counts ?? {};
	const summary = response?.summary ?? null;

	/**
	 * The whole "Slow Pages Score" summary card's own real data for whichever device the header
	 * toggle has selected.
	 */
	const deviceScoreKey = 'mobile' === scoreDevice ? 'mobile_score' : 'desktop_score';
	const displayScore = hasDeviceScores
		? ('mobile' === scoreDevice ? avgMobileScore : avgDesktopScore)
		: avgScore;
	const displayScoreRating = ratingFor(displayScore);

	const deviceScoredRows = hasDeviceScores
		? rows.filter((row) => null !== row[deviceScoreKey])
		: [];
	const deviceSlowCount = hasDeviceScores
		? deviceScoredRows.filter((row) => (row[deviceScoreKey] as number) < 50).length
		: (summary?.slow ?? 0);
	const deviceGoodCount = hasDeviceScores
		? deviceScoredRows.filter((row) => (row[deviceScoreKey] as number) >= 80).length
		: (summary?.good ?? 0);
	const deviceTotalScored = hasDeviceScores
		? deviceScoredRows.length
		: (summary?.total ?? 0);
	const topIssues = response?.top_issues ?? [];


	// Fed to TableCard's own `categoryCounts`/`activeCategory`.
	const statusCategoryCounts = [
		// Same "All" pill shape IssuesList.tsx's own `tableCategoryCounts` already establishes for
		// this same `TableCard`/`categoryCounts` prop.
		{ value: 'all', label: __('All', 'vulopilot'), count: rows.length },
		{ value: 'slow', label: __('Slow', 'vulopilot'), count: statusCounts.slow ?? 0 },
		{
			value: 'needs_improvement',
			label: __('Needs Work', 'vulopilot'),
			count: statusCounts.needs_improvement ?? 0,
		},
		{ value: 'good', label: __('Good', 'vulopilot'), count: statusCounts.good ?? 0 },
	];

	const handleExport = () => {
		const headerRow = [
			'Page',
			'URL',
			'Type',
			...(hasDeviceScores ? ['Mobile Score', 'Desktop Score'] : ['Score']),
			'Load Time (ms)',
			...(hasPsiDetail ? ['Page Size (bytes)', 'Requests', 'LCP (ms)', 'LCP Rating', 'INP (ms)', 'INP Rating', 'CLS (thousandths)', 'CLS Rating'] : []),
			'Main Issue',
		];

		const lines = [headerRow.map(csvEscape).join(',')];

		filteredRows.forEach((row) => {
			const cells = [
				row.title,
				row.url,
				PAGE_TYPE_LABELS[row.page_type] ?? row.page_type,
				...(hasDeviceScores ? [row.mobile_score ?? '', row.desktop_score ?? ''] : [row.score ?? '']),
				row.load_time_ms ?? '',
				...(hasPsiDetail
					? [
						row.page_size_bytes ?? '',
						row.requests_count ?? '',
						row.lcp_ms ?? '',
						row.lcp_rating ?? '',
						row.inp_ms ?? '',
						row.inp_rating ?? '',
						row.cls_thousandths ?? '',
						row.cls_rating ?? '',
					]
					: []),
				row.main_issue ?? '',
			];

			lines.push(cells.map((cell) => csvEscape(String(cell))).join(','));
		});

		const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
		const url = URL.createObjectURL(blob);
		const anchor = document.createElement('a');
		anchor.href = url;
		anchor.download = 'slow-pages.csv';
		anchor.click();
		URL.revokeObjectURL(url);
	};

	if (error) {
		return (
			<ContainerComponent>
				<ColumnComponent>
					<CardComponent
						title={__('Slow Pages', 'vulopilot')}
						titleIcon="error"
						desc={__('Pages with the slowest real load times.', 'vulopilot')}
					>
						<ModuleGuardComponent
							icon="error"
							title={__('Could not load Slow Pages', 'vulopilot')}
							desc={error}
						/>
					</CardComponent>
				</ColumnComponent>
			</ContainerComponent>
		);
	}

	return (
		<ContainerComponent>
			<ColumnComponent>
				<CardComponent
					isLoading={isLoading || isTrendLoading}
					action={
						hasDeviceScores && (
							<ToggleInput
								value={scoreDevice}
								modules={[]}
								variant="pill"
								options={[
									{ key: 'desktop', value: 'desktop', label: __('Desktop', 'vulopilot') },
									{ key: 'mobile', value: 'mobile', label: __('Mobile', 'vulopilot') },
								]}
								onChange={(value) => setScoreDevice(value as 'mobile' | 'desktop')}
							/>
						)
					}
				>
					<div className="overall-score-wrapper">
						<div className="overall-score-summary">
							<ChartComponent
								type="ring"
								height={200}
								color={RATING_RING_COLOR[displayScoreRating.className]}
								centerLabel={
									<>
										<TypographyComponent
											variant={'h1'}
											color={RATING_BADGE_COLOR[displayScoreRating.className] || 'gray'}
										>
											{null === displayScore ? '-' : displayScore}
										</TypographyComponent>
										<TypographyComponent variant={'h4'}>
											{displayScoreRating.label}
										</TypographyComponent>
									</>
								}
								data={[
									{
										label: __('Score', 'vulopilot'),
										value: displayScore ?? 0,
										color: RATING_RING_COLOR[displayScoreRating.className],
									},
									{
										label: __('Remaining', 'vulopilot'),
										value: 100 - (displayScore ?? 0),
										color: '#e5e7eb',
									},
								]}
							/>
							<TypographyComponent variant={'h3'} color="text-green">
								{__('Slow Pages Score', 'vulopilot')}
							</TypographyComponent>
							<div className="desc">{avgScoreSummary(displayScore)}</div>
						</div>

						<ListComponent
							className="mini-card report list"
							items={[
								{
									id: 'slow-pages',
									icon: 'error red',
									title: __('Slow Pages', 'vulopilot'),
									desc: sprintf(
										/* translators: %d: real PageSpeed score threshold below which a page counts as "slow" (PageSpeedRepository::SCORE_NEEDS_IMPROVEMENT). */
										__('Pages with a performance score below %d', 'vulopilot'),
										50
									),
									tags: (
										<div className="page-speed-summary-row-value">
											<TypographyComponent variant="h5">
												{deviceSlowCount}
											</TypographyComponent>
											<div className="page-speed-summary-row-sub">
												{sprintf(
													/* translators: %d: real total scanned page count. */
													__('of %d pages', 'vulopilot'),
													deviceTotalScored
												)}
											</div>
										</div>
									),
								},
								{
									id: 'average-load-time',
									icon: 'clock orange',
									title: __('Average Load Time', 'vulopilot'),
									desc: __('Across all scanned pages', 'vulopilot'),
									tags: (
										<div className="page-speed-summary-row-value">
											<TypographyComponent variant="h5">
												{avgLoadTimeMs !== null
													? sprintf(/* translators: %s: real load time in seconds. */ __('%s s', 'vulopilot'), (avgLoadTimeMs / 1000).toFixed(1))
													: '-'}
											</TypographyComponent>
											<div className="page-speed-summary-row-sub">
												{null !== displayScore
													? displayScoreRating.label
													: __('Not scored yet', 'vulopilot')}
											</div>
										</div>
									),
								},
								{
									id: 'good-pages',
									icon: 'check green',
									title: __('Good Pages', 'vulopilot'),
									desc: sprintf(
										/* translators: %d: real PageSpeed score threshold at/above which a page counts as "good" (PageSpeedRepository::SCORE_GOOD). */
										__('Pages with a performance score of %d or higher', 'vulopilot'),
										80
									),
									tags: (
										<div className="page-speed-summary-row-value">
											<TypographyComponent variant="h5">
												{deviceGoodCount}
											</TypographyComponent>
											<div className="page-speed-summary-row-sub">
												{sprintf(
													/* translators: %d: real total scanned page count. */
													__('of %d pages', 'vulopilot'),
													deviceTotalScored
												)}
											</div>
										</div>
									),
								},
								{
									id: 'performance-trend',
									icon: 'bar-chart violet',
									title: __('Performance Trend', 'vulopilot'),
									desc: __('Compared to the start of the selected period', 'vulopilot'),
									tags: (
										<div className="page-speed-summary-row-value">
											<TypographyComponent variant="h5">
												{null !== trendDelta
													? `${trendDelta >= 0 ? '+' : ''}${trendDelta} ${__('pts', 'vulopilot')}`
													: '-'}
											</TypographyComponent>
											<div className="page-speed-summary-row-sub">
												{null !== trendDelta ? (
													<span className={isTrendImproving ? 'up' : 'down'}>
														<i
															className={`adminfont-arrow-${isTrendImproving ? 'up' : 'down'}`}
														/>
														{isTrendImproving
															? __('Improving', 'vulopilot')
															: __('Declining', 'vulopilot')}
													</span>
												) : (
													__('Not enough trend data yet', 'vulopilot')
												)}
											</div>
										</div>
									),
								},
							]}
						/>
					</div>
				</CardComponent>
			</ColumnComponent>
			<ColumnComponent grid={8}>

				{0 === filteredRows.length ? (
					<ModuleGuardComponent
						icon="document"
						title={__('No pages found', 'vulopilot')}
						desc={
							0 === rows.length
								? __(
									'No pages scanned yet - click "Scan Again" to check your real pages\' load times.',
									'vulopilot'
								)
								: __(
									'No pages match this filter.',
									'vulopilot'
								)
						}
					/>
				) : (
					<TableCard
						showMenu={false}
						hideHeader={true}
						categoryCounts={statusCategoryCounts}
						activeCategory={statusFilter}
						search={{ placeholder: __('Search pages…', 'vulopilot') }}
						filters={[
							{
								key: 'page_type',
								label: __('Page Type', 'vulopilot'),
								type: 'select',
								size: 12,
								options: pageTypeOptions,
							},
						]}
						buttonActions={[
							{
								label: __('Export', 'vulopilot'),
								icon: 'export',
								color: 'border-purple',
								onClick: handleExport,
							},
						]}
						headers={{
							title: {
								key: 'title',
								type: 'info',
								label: __('Page', 'vulopilot'),
								width: '60%',
								iconKey: 'pageTypeIcon',
								titleLinkKey: 'url',
								descriptionKey: 'descriptionText',
								badgesKey: 'pageTypeBadges',
							},
							...(hasDeviceScores
								? {
									mobile_score: {
										label: __('Mobile Score', 'vulopilot'),
										render: (row: PageSpeedRow) => <ScorePill score={row.mobile_score} />,
									},
									desktop_score: {
										label: __('Desktop Score', 'vulopilot'),
										render: (row: PageSpeedRow) => <ScorePill score={row.desktop_score} />,
									},
								}
								: {
									score: {
										label: __('Score', 'vulopilot'),
										render: (row: PageSpeedRow) => <ScorePill score={row.score} />,
									},
								}),
							load_time_ms: {
								label: __('Load Time', 'vulopilot'),
								render: (row: PageSpeedRow) =>
									null !== row.load_time_ms
										? sprintf(/* translators: %s: real load time in seconds. */ __('Load Time: %s s', 'vulopilot'), (row.load_time_ms / 1000).toFixed(1))
										: '-',
							},
							...(hasPsiDetail
								? {
									page_size_bytes: {
										label: (
											<TooltipComponent
												text={__('Total real page weight (Lighthouse total-byte-weight audit)', 'vulopilot')}
											>
												{__('Page Size', 'vulopilot')}
											</TooltipComponent>
										),
										render: (row: PageSpeedRow) => formatBytes(row.page_size_bytes),
									},
									requests_count: {
										label: (
											<TooltipComponent
												text={__('Real network requests observed (Lighthouse network-requests audit)', 'vulopilot')}
											>
												{__('Requests', 'vulopilot')}
											</TooltipComponent>
										),
										render: (row: PageSpeedRow) =>
											null !== row.requests_count ? row.requests_count : '-',
									},
									core_web_vitals: {
										label: (
											<TooltipComponent
												text={__('Real Chrome UX Report field data: Largest Contentful Paint / Interaction to Next Paint / Cumulative Layout Shift', 'vulopilot')}
											>
												{__('Core Web Vitals', 'vulopilot')}
											</TooltipComponent>
										),
										render: (row: PageSpeedRow) => <CoreWebVitalsDots row={row} />,
									},
								}
								: {}),
							action: {
								label: __('Action', 'vulopilot'),
								// `type: 'more-action'` no longer exists in @zyra/table.
								type: 'action',
								actions: [
									{
										type: 'button',
										label: (row) =>
											(row as unknown as PageSpeedRow).id === detailRow?.id
												? __('Showing', 'vulopilot')
												: __('More Details', 'vulopilot'),
										color: (row) =>
											(row as unknown as PageSpeedRow).id === detailRow?.id
												? 'text-green'
												: 'text-purple',
										icon: (row) =>
											(row as unknown as PageSpeedRow).id === detailRow?.id
												? 'eye'
												: 'pagination-next-arrow',
										onClick: (row) => {
											const pageRow = row as unknown as PageSpeedRow;
											setDetailRow(
												pageRow.id === detailRow?.id ? null : pageRow
											);
										},
									},
								],
							},
						}}
						rows={pageRows.map((row) => ({
							...row,
							pageTypeIcon: PAGE_TYPE_ICONS[row.page_type] ?? 'document',
							descriptionText:
								row.main_issue ?? __('No issues detected', 'vulopilot'),
							pageTypeBadges: [
								{
									text: PAGE_TYPE_LABELS[row.page_type] ?? row.page_type,
									color: 'green',
								},
							],
						}))}
						ids={pageRows.map((row) => row.id)}
						totalRows={filteredRows.length}
						activeRowId={detailRow?.id}
						// Same toggle the action cell's own "More Details"/"Showing" button
						// already does.
						onRowClick={(row: Record<string, unknown>) => {
							const pageRow = row as unknown as PageSpeedRow;
							setDetailRow(
								pageRow.id === detailRow?.id ? null : pageRow
							);
						}}
						onQueryUpdate={(query: {
							paged?: number | string;
							per_page?: number | string;
							categoryFilter?: string;
							searchValue?: string;
							filter?: { page_type?: string };
						}) => {
							setPaged(Number(query.paged) || 1);
							setPerPage(Number(query.per_page) || 10);
							if (
								query.categoryFilter &&
								query.categoryFilter !== statusFilter
							) {
								setStatusFilter(query.categoryFilter);
							}
							setSearchTerm(query.searchValue ?? '');
							setPageTypeFilter(query.filter?.page_type ?? '');
						}}
					/>
				)}
			</ColumnComponent>

			<ColumnComponent grid={4}>
				{/* "Page details" card only renders when there are real rows * to select from. */}
				{0 < filteredRows.length && (
					<>
						<CardComponent
							title={detailRow?.title ?? __('Page details', 'vulopilot')}
							titleIcon="info"
							desc={detailRow?.url}
							action={
								detailRow && (
									<i
										className="adminfont-close"
										role="button"
										tabIndex={0}
										aria-label={__('Close', 'vulopilot')}
										onClick={() => setDetailRow(null)}
										onKeyDown={(e) => {
											if ('Enter' === e.key || ' ' === e.key) {
												e.preventDefault();
												setDetailRow(null);
											}
										}}
									/>
								)
							}
						>
							{!detailRow ? (
								<ModuleGuardComponent
									icon="document"
									title={__('Select a page', 'vulopilot')}
									desc={__(
										'Click "More Details" on a row to see it here.',
										'vulopilot'
									)}
								/>
							) : (
								<FormGroupWrapperComponent>
									<FormGroupComponent row label={__('URL', 'vulopilot')}>
										<a href={detailRow.url} target="_blank" rel="noopener noreferrer">
											{detailRow.url}
										</a>
									</FormGroupComponent>
									<FormGroupComponent row label={__('Type', 'vulopilot')}>
										{PAGE_TYPE_LABELS[detailRow.page_type] ?? detailRow.page_type}
									</FormGroupComponent>
									<FormGroupComponent row label={__('Load Time', 'vulopilot')}>
										{null !== detailRow.load_time_ms
											? sprintf(/* translators: %s: real load time in seconds. */ __('%s s', 'vulopilot'), (detailRow.load_time_ms / 1000).toFixed(2))
											: '-'}
									</FormGroupComponent>
									{hasDeviceScores ? (
										<>
											<FormGroupComponent row label={__('Mobile Score', 'vulopilot')}>
												<ScorePill score={detailRow.mobile_score} />
											</FormGroupComponent>
											<FormGroupComponent row label={__('Desktop Score', 'vulopilot')}>
												<ScorePill score={detailRow.desktop_score} />
											</FormGroupComponent>
										</>
									) : (
										<FormGroupComponent row label={__('Score', 'vulopilot')}>
											<ScorePill score={detailRow.score} />
										</FormGroupComponent>
									)}
									{hasPsiDetail && (
										<>
											<FormGroupComponent row label={__('Page Size', 'vulopilot')}>
												{formatBytes(detailRow.page_size_bytes)}
											</FormGroupComponent>
											<FormGroupComponent row label={__('Requests', 'vulopilot')}>
												{null !== detailRow.requests_count ? detailRow.requests_count : '-'}
											</FormGroupComponent>
											<FormGroupComponent row label={__('Core Web Vitals', 'vulopilot')}>
												<CoreWebVitalsDots row={detailRow} />
											</FormGroupComponent>
										</>
									)}
									<FormGroupComponent row label={__('Main Issue', 'vulopilot')}>
										{detailRow.main_issue ?? __('None detected', 'vulopilot')}
									</FormGroupComponent>
									<FormGroupComponent row label={__('Last Scanned', 'vulopilot')}>
										{formatWpDate(detailRow.scanned_at)}
									</FormGroupComponent>
								</FormGroupWrapperComponent>
							)}
						</CardComponent>
						<CardComponent
							id="slow-pages-why-card"
							title={__('Why these pages are slow?', 'vulopilot')}
							titleIcon="info"
							desc={__('The most common issues dragging your pages down.', 'vulopilot')}
						>
							{0 === topIssues.length ? (
								<ModuleGuardComponent
									icon="check"
									title={__('No issues detected', 'vulopilot')}
									desc={__(
										'Run a scan to check your real pages for common slowdown causes.',
										'vulopilot'
									)}
								/>
							) : (
								<ListComponent
									className="mini-card report"
									items={topIssues.slice(0, 5).map((item) => ({
										id: item.issue,
										title: item.issue,
										desc: sprintf(
											/* translators: %d: real number of pages this affects. */
											_n(
												'%d page affected',
												'%d pages affected',
												item.affected_pages,
												'vulopilot'
											),
											item.affected_pages
										),
									}))}
								/>
							)}
						</CardComponent>
					</>
				)}

				<RecommendedFixesCard topIssues={topIssues} />

				<CardComponent
					title={__("What's considered slow?", 'vulopilot')}
					titleIcon="ai"
					desc={__('How page speed scores map to real performance ratings.', 'vulopilot')}
				>
					<ListComponent
						className="mini-card report without-border"
						items={[
							{
								id: 'very-poor',
								title: __('Very Slow', 'vulopilot'),
								tags: <span className="page-speed-legend-range">0 – 24</span>,
							},
							{
								id: 'poor',
								title: __('Slow', 'vulopilot'),
								tags: <span className="page-speed-legend-range">25 – 49</span>,
							},
							{
								id: 'needs-improvement',
								title: __('Needs Work', 'vulopilot'),
								tags: <span className="page-speed-legend-range">50 – 79</span>,
							},
							{
								id: 'good',
								title: __('Good', 'vulopilot'),
								tags: <span className="page-speed-legend-range">80 – 100</span>,
							},
						]}
					/>
				</CardComponent>
			</ColumnComponent>
		</ContainerComponent>
	);
};

export default SlowPagesTab;