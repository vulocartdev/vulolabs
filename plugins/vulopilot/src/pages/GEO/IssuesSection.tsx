/* global vulopilotAppLocalizer */
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { TabsComponent, CardComponent } from '@zyra/components';
import { ButtonInput, SelectInput, TextInput } from '@zyra/inputs';
import { Priority } from '../../components/Issues/IssuesSummaryCards';
import {
	FindingSeverity,
	PageRow,
	RawFinding,
	bucketFindingsByPage,
	buildEditLink,
	fetchAllPagesWithScores,
	fetchOpenFindingsFor,
	fetchPagesByIds,
	nonceHeaders,
} from './seoIssuesShared';
import SeoSiteWideIssuesTable from './SeoSiteWideIssuesTable';
import SeoIssuesByPageTable from './SeoIssuesByPageTable';

interface FindingGroupRow {
	scanner_id: string;
	label: string;
	severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
	count: number;
}

export interface IssuesSectionCategory {
	key: string;
	title: string;
	scannerIds: string[];
}

/** `content.toolbarFilters`'s own real severity `select` labels. */
const SEVERITY_LABELS: Record<FindingSeverity, string> = {
	critical: __('Critical', 'vulopilot'),
	high: __('High', 'vulopilot'),
	medium: __('Medium', 'vulopilot'),
	low: __('Low', 'vulopilot'),
	info: __('Info', 'vulopilot'),
};

/** `GET /findings/groups`' own raw per-scanner counts, summed across whichever scanner ids matter for a given tab/tile. */
const sumGroupCounts = (groups: FindingGroupRow[], scannerIds: string[]): number =>
	groups
		.filter((group) => scannerIds.includes(group.scanner_id))
		.reduce((total, group) => total + group.count, 0);

interface CategoryFocus {
	/** A real category `key` from `categories`, or the literal `'all'` to reset back to the unfiltered view. */
	key: string;
	token: number;
}

export interface ContentRowTab {
	key: string;
	label: string;
	/** Real per-row test - `RecentContentCard.tsx`'s own post-type/meta classification (Blog Post/Landing Page/Product/Other). */
	// eslint-disable-next-line no-unused-vars
	matches: (row: PageRow) => boolean;
}

/**
 * `RecentContentCard.tsx`'s own real content mode - every recent post/page/product (not just ones
 * with an open finding).
 */
export interface ContentModeConfig {
	/** The page meta flag that tells a landing page apart from any other `page` post type. */
	landingPageMetaKey: string;
	/** Real display label/icon per raw `categoryKey` (`'blog-post'`/`'landing-page'`/`'product'`/`'other'`). */
	categories: Record<string, { label: string; icon: string }>;
	rowTabs: ContentRowTab[];
	/** Real `DELETE` row action (moves to trash) - `undefined` hides it. */
	// eslint-disable-next-line no-unused-vars
	onDelete?: (row: PageRow) => void;
	/** Which row's real delete request is currently in flight, so that row's own action label can read "Deleting…". */
	deletingId?: number | null;
	/**
	 * `RecentContentCard.tsx`'s own real filter bar - a search box + real severity/resource
	 * `select`s + a real "Show ignored" toggle + Export CSV.
	 */
	toolbarFilters?: boolean;
}

interface IssuesSectionProps {
	/** Every real scanner id this section covers - `SeoTab.tsx` passes SEO_SECTIONS' own ids. */
	scannerIds: string[];
	/** Only needed if `categoryFocus` is ever set to a real category key (not just `'all'`). */
	categories?: IssuesSectionCategory[];
	categoryFocus?: CategoryFocus | null;
	/** "SEO Issues" by default - AeoTab.tsx/GeoTab.tsx pass "AEO Issues"/"GEO Issues" so the Pages & Posts table's own issues-count column reads correctly for whichever real check set it's showing. */
	issuesColumnLabel?: string;
	/**
	 * When set, this section also becomes GeoTab.tsx's/AeoTab.tsx's own standalone "Page-by-page
	 * analysis"/"Page-by-Page Answer Readiness" table.
	 */
	pageAnalysis?: {
		/** Defaults to "AI Visibility". */
		scoreColumnLabel?: string;
		/** Defaults to 'page-analysis.csv'. */
		exportFilename?: string;
	};
	/**
	 * Real `scrollToId()` target for GeoTab.tsx's/AeoTab.tsx's own "View all"/"View page-by-page
	 * breakdown" shortcuts.
	 */
	id?: string;
	/** Only passed by `SeoTab.tsx`'s own SEO usage - see `SeoIssuesByPageTable.tsx`'s own `onAnalyze` prop docblock. */
	// eslint-disable-next-line no-unused-vars
	onAnalyze?: (postId: number) => void;
	/** `SeoTab.tsx`'s own `analyzingPostId` - which row's `PageAnalysisPanel` (if any) is currently open. */
	activePostId?: number | null;
	/** Called when the active tab's "Pages & Posts" list has nothing to show, so the host can close an open page-analysis panel that no longer applies. */
	onAnalyzeClose?: () => void;
	/**
	 * Set by SeoTab.tsx to also fetch `GET /seo/pages-needing-attention` and show a per-page score
	 * and change.
	 */
	pageScore?: boolean;
	/** Defaults to "All SEO Findings" (this section's own original real hardcoded title, kept as the default so SEO's/AEO's/GEO's own existing usage is unaffected). */
	title?: string;
	titleIcon?: string;
	desc?: string;
	/** `CardComponent`'s own header `action` slot - `undefined` for SEO/AEO/GEO (which have none today). */
	headerAction?: ReactNode;
	content?: ContentModeConfig;
	/** Bumped by the host after it changes something outside this section's own control (e.g. `RecentContentCard.tsx`'s own real Delete, once the request succeeds). */
	reloadSignal?: number;
}

/**
 * Generalized from what used to be `SeoIssuesSection.tsx` (now inlined as a thin SEO-defaults
 * usage directly in SeoTab.tsx, its only consumer).
 */
const IssuesSection = ({
	scannerIds,
	categories = [],
	categoryFocus,
	issuesColumnLabel,
	pageAnalysis,
	id,
	onAnalyze,
	activePostId,
	onAnalyzeClose,
	pageScore,
	title = __('All SEO Findings', 'vulopilot'),
	titleIcon = 'search',
	desc = __('Every open SEO finding, filterable by priority.', 'vulopilot'),
	headerAction,
	content,
	reloadSignal,
}: IssuesSectionProps) => {
	const [rows, setRows] = useState<PageRow[]>([]);
	const [siteWideFindings, setSiteWideFindings] = useState<RawFinding[]>([]);
	const [groups, setGroups] = useState<FindingGroupRow[]>([]);
	// `content` mode's `rowTabs` always has an 'all' entry, but the default `tabs` array doesn't -
	// starting in sync avoids TabsComponent visually highlighting "Important" while filtering by nothing.
	const [activeTab, setActiveTab] = useState(content ? 'all' : 'important');
	const [activePriority, setActivePriority] = useState<Priority>('all');
	const [isLoading, setIsLoading] = useState(true);
	const [hasError, setHasError] = useState(false);
	const [reloadToken] = useState(0);
	const sectionRef = useRef<HTMLDivElement>(null);
	/** Guards the auto-open effect below so it only ever fires once per mount. */
	const hasAutoOpenedRef = useRef(false);

	// `content.toolbarFilters`'s own real state - `RecentContentCard.tsx`'s original bespoke
	// toolbar.
	const [toolbarSearch, setToolbarSearch] = useState('');
	const [toolbarSeverity, setToolbarSeverity] = useState<'all' | FindingSeverity>('all');

	useEffect(() => {
		let cancelled = false;
		setIsLoading(true);
		setHasError(false);

		(async () => {
			try {
				const findings = await fetchOpenFindingsFor(scannerIds);
				const { byPostId, siteWide } = bucketFindingsByPage(findings);

				let builtRows: PageRow[];

				if (content) {
					// `RecentContentCard.tsx`'s own real content mode.
					const countWords = (html: string): number => {
						const text = html
							.replace(/<[^>]+>/g, ' ')
							.replace(/&[a-z0-9#]+;/gi, ' ')
							.trim();

						return text ? text.split(/\s+/).length : 0;
					};

					interface RawContentPost {
						id: number;
						title: { rendered: string };
						content: { rendered: string };
						status: string;
						date: string;
						link: string;
						meta?: Record<string, unknown>;
					}

					interface RawContentProduct {
						id: number;
						name: string;
						description: string;
						status: string;
						date_created: string;
						permalink: string;
					}

					const fetchContentPosts = (endpoint: 'posts' | 'pages') =>
						getApiResponse<RawContentPost[]>(
							getApiLink(
								vulopilotAppLocalizer,
								`${endpoint}?per_page=20&orderby=date&order=desc&_fields=id,title,content,status,date,link,meta`,
								'wp/v2'
							),
							nonceHeaders
						)
							.then((response) => response || [])
							.catch(() => [] as RawContentPost[]);

					const fetchContentProducts = () =>
						!vulopilotAppLocalizer.has_woocommerce
							? Promise.resolve([] as RawContentProduct[])
							: getApiResponse<RawContentProduct[]>(
							getApiLink(
								vulopilotAppLocalizer,
								'products?per_page=20&orderby=date&order=desc&_fields=id,name,description,status,date_created,permalink',
								'wc/v3'
							),
							nonceHeaders
						)
							.then((response) => response || [])
							.catch(() => [] as RawContentProduct[]);

					// `toolbarFilters`'s own real "Show ignored" toggle needs real ignored
					// findings to show, not just open ones.
					const ignoredFindings = await getApiResponse<{ data: RawFinding[] }>(
						getApiLink(
							vulopilotAppLocalizer,
							`findings?scanner_id=${scannerIds.join(',')}&status=ignored&per_page=100&orderby=id&order=desc`
						),
						nonceHeaders
					)
						.then((response: { data: RawFinding[] } | undefined) => response?.data ?? [])
						.catch(() => [] as RawFinding[]);

					const contentByPostId = new Map(byPostId);

					ignoredFindings.forEach((finding: RawFinding) => {
						if ('post' !== finding.object_type) {
							return;
						}

						const postId = Number(finding.object_ref);
						contentByPostId.set(postId, [
							...(contentByPostId.get(postId) || []),
							finding,
						]);
					});

					const [rawPosts, rawPages, rawProducts] = await Promise.all([
						fetchContentPosts('posts'),
						fetchContentPosts('pages'),
						fetchContentProducts(),
					]);

					const postRows: PageRow[] = rawPosts
						.map((post: RawContentPost) => ({ ...post, categoryKey: 'blog-post' }))
						.concat(
							rawPages.map((post: RawContentPost) => ({
								...post,
								categoryKey:
									true === post.meta?.[content.landingPageMetaKey]
										? 'landing-page'
										: 'other',
							}))
						)
						.map((post: RawContentPost & { categoryKey: string }) => ({
							id: post.id,
							title: post.title.rendered,
							status: post.status,
							date: post.date,
							editLink: buildEditLink(post.id),
							viewLink: 'publish' === post.status ? post.link : null,
							findings: contentByPostId.get(post.id) || [],
							categoryKey: post.categoryKey,
							wordCount: countWords(post.content.rendered),
						}));

					const productRows: PageRow[] = rawProducts.map((product) => ({
						id: product.id,
						title: product.name,
						status: product.status,
						date: product.date_created,
						editLink: buildEditLink(product.id),
						viewLink:
							'publish' === product.status ? product.permalink : null,
						findings: contentByPostId.get(product.id) || [],
						categoryKey: 'product',
						wordCount: countWords(product.description),
					}));

					builtRows = [...postRows, ...productRows].map((row) => {
						const category = content.categories?.[row.categoryKey ?? ''];

						return {
							...row,
							categoryLabel: category?.label,
							categoryIcon: category?.icon,
						};
					});
				} else if (pageAnalysis) {
					// Merged mode (GeoTab.tsx/AeoTab.tsx): every published page/post, not just ones
					// with an open finding right now.
					const pages = await fetchAllPagesWithScores(scannerIds);

					builtRows = pages.map((page) => ({
						id: page.post_id,
						title: page.title,
						status: page.status,
						date: page.date,
						editLink: page.edit_link,
						viewLink: 'publish' === page.status ? page.permalink : null,
						findings: byPostId.get(page.post_id) || [],
						visibilityScore: page.visibility_score,
					}));
				} else {
					const pageIds = Array.from(byPostId.keys());

					const [posts, pages] = await Promise.all([
						fetchPagesByIds('posts', pageIds),
						fetchPagesByIds('pages', pageIds),
					]);

					builtRows = [...posts, ...pages].map((post) => ({
						id: post.id,
						title: post.title.rendered,
						status: post.status,
						date: post.date,
						editLink: buildEditLink(post.id),
						viewLink: 'publish' === post.status ? post.link : null,
						findings: byPostId.get(post.id) || [],
					}));
				}

				if (pageScore) {
					// Real per-page SEO score/week-over-week change -
					// `PagesNeedingAttentionTable.tsx`'s own former data source.
					const scoreResponse = await getApiResponse<{
						data: { post_id: number; score: number; change: number }[];
					}>(getApiLink(vulopilotAppLocalizer, 'seo/pages-needing-attention'), nonceHeaders);

					const scoreByPostId = new Map<number, { score: number; change: number }>(
						(scoreResponse?.data ?? []).map(
							(row: { post_id: number; score: number; change: number }) => [
								row.post_id,
								{ score: row.score, change: row.change },
							]
						)
					);

					builtRows = builtRows.map((row) => {
						const match = scoreByPostId.get(row.id);

						return match
							? { ...row, seoScore: match.score, seoScoreChange: match.change }
							: row;
					});
				}

				if (!cancelled) {
					setRows(
						builtRows.sort(
							(a, b) =>
								new Date(b.date).getTime() - new Date(a.date).getTime()
						)
					);
					setSiteWideFindings(siteWide);
				}
			} catch {
				if (!cancelled) {
					setHasError(true);
				}
			} finally {
				if (!cancelled) {
					setIsLoading(false);
				}
			}
		})();

		return () => {
			cancelled = true;
		};
		// `scannerIds` is a fresh array every render from every real call site (inline `.flatMap()`/literal); re-running on its own reference would refetch every render. Callers never change which scanner ids a given tab covers at runtime, so `reloadToken` (Retry) / `reloadSignal` (a parent-triggered reload, e.g. `RecentContentCard.tsx`'s own real Delete) are the only real triggers this needs.
	}, [reloadToken, reloadSignal]);

	// Default-opens the first row's "More Details" panel (SeoTab.tsx's own PageAnalysisPanel
	// sidebar) once real rows load.
	useEffect(() => {
		if (!onAnalyze || hasAutoOpenedRef.current || 0 === rows.length) {
			return;
		}

		hasAutoOpenedRef.current = true;
		onAnalyze(rows[0].id);
	}, [rows, onAnalyze]);

	useEffect(() => {
		getApiResponse<{ data: FindingGroupRow[] }>(
			getApiLink(vulopilotAppLocalizer, 'findings/groups?per_page=100'),
			nonceHeaders
		)
			.then((response) =>
				setGroups(
					(response?.data ?? []).filter((group) =>
						scannerIds.includes(group.scanner_id)
					)
				)
			)
			.catch(() => setGroups([]));
		// see the fetch effect above.
	}, [reloadToken]);

	/**
	 * `content` mode's own real per-row readability score.
	 */
	useEffect(() => {
		if (!content || 0 === rows.length) {
			return;
		}

		let cancelled = false;

		Promise.all(
			rows.map((row) =>
				getApiResponse<{ readability: { score: number } }>(
					getApiLink(vulopilotAppLocalizer, `content-intelligence/quality?post_id=${row.id}`),
					nonceHeaders
				)
					.then(
						(response: { readability: { score: number } } | undefined) =>
							[row.id, response?.readability.score] as [number, number | undefined]
					)
					.catch(() => [row.id, undefined] as [number, number | undefined])
			)
		).then((results) => {
			if (cancelled) {
				return;
			}

			const scoreByPostId = new Map(results);

			setRows((current) =>
				current.map((row) => ({
					...row,
					contentQualityScore: scoreByPostId.get(row.id) ?? row.contentQualityScore,
				}))
			);
		});

		return () => {
			cancelled = true;
		};
		// keyed on `rows.length` (a fresh real fetch/page of rows), not `rows` itself - `rows` gets a new array reference every time this same effect's own `setRows` call above runs, which would otherwise re-trigger it forever.
	}, [content, rows.length, reloadToken, reloadSignal]);

	useEffect(() => {
		if (!categoryFocus) {
			return;
		}

		setActiveTab(categoryFocus.key);
		sectionRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
		// Only a fresh external trigger (a new `token`) should re-trigger this, not a new `categoryFocus`
		// object reference on every re-render.
	}, [categoryFocus?.token]);

	// Resets the priority filter whenever the active tab changes.
	useEffect(() => {
		setActivePriority('all');
	}, [activeTab]);

	/** Same CSV shape the old standalone `GeoPageAnalysisTable.tsx` exported. */
	const exportCsv =
		pageAnalysis || content
			? () => {
					const csvEscape = (value: string): string => `"${value.replace(/"/g, '""')}"`;
					const headerRow = content
						? [
								__('Title', 'vulopilot'),
								__('Category', 'vulopilot'),
								__('Status', 'vulopilot'),
								__('Words', 'vulopilot'),
								__('Open Issues', 'vulopilot'),
							]
						: [
								'Page',
								'Status',
								'Open Issues',
								`${pageAnalysis?.scoreColumnLabel || __('AI Visibility', 'vulopilot')} (%)`,
							];
					const lines = [headerRow.map(csvEscape).join(',')];

					(content?.toolbarFilters ? toolbarRows : rows).forEach((row) => {
						const cells = content
							? [
									row.title,
									row.categoryLabel ?? '',
									row.status,
									row.wordCount ?? '',
									row.findings.filter((finding) => 'open' === finding.status)
										.length,
								]
							: [
									row.title,
									row.status,
									row.findings.length,
									row.visibilityScore ?? '',
								];
						lines.push(cells.map((cell) => csvEscape(String(cell))).join(','));
					});

					const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
					const url = URL.createObjectURL(blob);
					const anchor = document.createElement('a');
					anchor.href = url;
					anchor.download = content
						? 'vulopilot-recent-content.csv'
						: pageAnalysis?.exportFilename || 'page-analysis.csv';
					anchor.click();
					URL.revokeObjectURL(url);
				}
			: undefined;

	/** The expanded finding sub-rows' own scanner label (SeoIssuesByPageTable.tsx). */
	const scannerLabelMap = new Map(groups.map((group) => [group.scanner_id, group.label]));

	/** Every real scanner id within this section's own scope whose real severity is critical/high. */
	const importantScannerIds = groups
		.filter(
			(group) =>
				scannerIds.includes(group.scanner_id) &&
				('critical' === group.severity || 'high' === group.severity)
		)
		.map((group) => group.scanner_id);

	/**
	 * Filter tabs: Important plus one per category, using the same `GET /findings/groups` counts
	 * as SectionedIssuesTable.tsx.
	 */
	const tabs: { id: string; label: string; count: number }[] = content
		? content.rowTabs.map((tab) => ({
				id: tab.key,
				label: tab.label,
				count: rows.filter((row) => tab.matches(row)).length,
			}))
		: [
				{
					id: 'important',
					label: __('Important', 'vulopilot'),
					count: sumGroupCounts(groups, importantScannerIds),
				},
				...categories.map((category) => ({
					id: category.key,
					label: category.title,
					count: sumGroupCounts(groups, category.scannerIds),
				})),
			];

	const scannerIdsForTab: Record<string, string[]> = {
		all: scannerIds,
		important: importantScannerIds,
	};
	categories.forEach((category) => {
		scannerIdsForTab[category.key] = category.scannerIds;
	});

	/** Resolves the active tab (`'all'`/`'important'`/a real `categories[].key`) down to the concrete scanner ids both tables. */
	const activeScannerIds: 'all' | string[] = content
		? 'all'
		: 'all' === activeTab
			? 'all'
			: (scannerIdsForTab[activeTab] ?? []);

	/** `content` mode's own real row-level filter - which post-category tab is active. */
	const activeRowTab = content?.rowTabs.find((tab) => tab.key === activeTab);
	const contentRows =
		content && activeRowTab ? rows.filter((row) => activeRowTab.matches(row)) : rows;

	/** Darkest (most severe) to lightest - same real order `RecentContentCard.tsx`'s own original `SEVERITY_RANK` used for its severity `select`'s own option order. */
	const SEVERITY_RANK: Record<FindingSeverity, number> = {
		critical: 0,
		high: 1,
		medium: 2,
		low: 3,
		info: 4,
	};

	/** `toolbarFilters`'s own real severity `select` options. */
	const toolbarSeverityOptions: { id: 'all' | FindingSeverity; label: string }[] = content
		?.toolbarFilters
		? [
				{ id: 'all', label: __('All issues', 'vulopilot') },
				...Array.from(
					new Set(rows.flatMap((row) => row.findings.map((finding) => finding.severity)))
				)
					.sort((a, b) => SEVERITY_RANK[a] - SEVERITY_RANK[b])
					.map((severity) => ({
						id: severity,
						label: SEVERITY_LABELS[severity],
					})),
			]
		: [];

	/** A row's findings that are actually relevant to show right now - open always, ignored only while `toolbarShowIgnored` is on. */
	const toolbarVisibleFindingsFor = (row: PageRow) =>
		row.findings.filter(
			(finding) =>
				('open' === finding.status ||
					('ignored' === finding.status)) &&
				('all' === toolbarSeverity || finding.severity === toolbarSeverity)
		);

	/**
	 * `toolbarFilters`'s own final real row set - `contentRows` (already narrowed to the active
	 * resource tab) further narrowed by real search/severity.
	 */
	const toolbarRows = content?.toolbarFilters
		? contentRows
				.map((row) => ({ ...row, findings: toolbarVisibleFindingsFor(row) }))
				.filter((row) => {
					if (
						toolbarSearch &&
						!row.title.toLowerCase().includes(toolbarSearch.toLowerCase())
					) {
						return false;
					}

					return 'all' === toolbarSeverity || row.findings.length > 0;
				})
		: contentRows;

	return (
		<div ref={sectionRef} id={id}>
		<CardComponent
			title={title}
			titleIcon={titleIcon}
			desc={desc}
			action={headerAction}
		>
			{content?.toolbarFilters ? (
				<div className="recent-content-toolbar">
					<TextInput
						type="search"
						name="recent-content-search"
						value={toolbarSearch}
						onChange={(value: string) => setToolbarSearch(value)}
						placeholder={__('Search by title or source page…', 'vulopilot')}
						size={20}
						wrapperClass="recent-content-search"
					/>
					<SelectInput
						name="recent-content-severity-filter"
						type="single-select"
						value={toolbarSeverity}
						onChange={(value: string) =>
							setToolbarSeverity(value as 'all' | FindingSeverity)
						}
						options={toolbarSeverityOptions.map((option) => ({
							label: option.label,
							value: option.id,
						}))}
						isClearable={false}
					/>
					<SelectInput
						name="recent-content-resource-filter"
						type="single-select"
						value={activeTab}
						onChange={(value: string) => setActiveTab(value)}
						options={content.rowTabs.map((tab) => ({
							label: tab.label,
							value: tab.key,
						}))}
						isClearable={false}
					/>
					{exportCsv && (
						<ButtonInput
							buttons={{
								text: __('Export CSV', 'vulopilot'),
								icon: 'download',
								onClick: exportCsv,
								disabled: 0 === toolbarRows.length,
							}}
						/>
					)}
				</div>
			) : (
				<>
					<TabsComponent
						className="seo-issues-filter-tabs"
						activeIndex={Math.max(
							tabs.findIndex((tab) => tab.id === activeTab),
							0
						)}
						onTabChange={(index: number) => setActiveTab(tabs[index].id)}
						tabs={tabs.map((tab) => ({
							label: sprintf('%1$s (%2$d)', tab.label, tab.count),
						}))}
					/>
				</>
			)}
			<SeoIssuesByPageTable
				rows={content?.toolbarFilters ? toolbarRows : content ? contentRows : rows}
				activeScannerIds={activeScannerIds}
				activePriority={activePriority}
				scannerLabelMap={scannerLabelMap}
				isLoading={isLoading}
				hasError={hasError}
				issuesColumnLabel={issuesColumnLabel}
				visibilityColumnLabel={pageAnalysis?.scoreColumnLabel || (pageAnalysis ? __('AI Visibility', 'vulopilot') : undefined)}
				onExportCsv={content?.toolbarFilters ? undefined : exportCsv}
				hideSearch={Boolean(content?.toolbarFilters)}
				onAnalyze={onAnalyze}
				activePostId={activePostId}
				onEmptyChange={
					activePostId
						? (isEmpty: boolean) => {
								if (isEmpty) {
									onAnalyzeClose?.();
								}
							}
						: undefined
				}
				showScoreChange={pageScore}
				showContentScore={Boolean(content)}
				onDelete={content?.onDelete}
				deletingId={content?.deletingId}
			/>
			{!content && (
				<SeoSiteWideIssuesTable
					findings={siteWideFindings}
					activeScannerIds={activeScannerIds}
					activePriority={activePriority}
					isLoading={isLoading}
					hasError={hasError}
				/>
			)}
		</CardComponent>
		</div>
	);
};

export default IssuesSection;
