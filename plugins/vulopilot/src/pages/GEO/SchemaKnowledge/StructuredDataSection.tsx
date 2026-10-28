import { useEffect, useMemo, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { scrollToId } from '@zyra/core';
import {
	CardComponent,
	ColumnComponent,
	ModuleGuardComponent,
	BadgeComponent,
	ListComponent,
} from '@zyra/components';
import { TableCard } from '@zyra/table';
import { fetchOpenFindingsFor, buildEditLink } from '../seoIssuesShared';
import { SEO_ISSUE_QUERY_PARAM, getEditorTargetForScanner } from '../../../services/seoIssueEditorTarget';
import type { RawFinding } from '../seoIssuesShared';
import type {
	SchemaCoverageRow,
	SchemaCoveragePage,
	SchemaCoverageSnapshot,
} from './useSchemaCoverage';

interface StructuredDataSectionProps {
	coverage: {
		snapshot: SchemaCoverageSnapshot | null;
		isLoading: boolean;
	};
}

/**
 * Per real schema.org @type icon - purely cosmetic, every value here is a real.
 */
/** Same 5 schema-related scanners IssuesSection.tsx's own "Schema Problems" table reads. */
const SCHEMA_ISSUE_SCANNER_IDS = [
	'schema',
	'structured-data',
	'sitewide-structured-data',
	'organization-schema',
	'author-schema',
];

/** Where a row's click goes: that page's editor (with the scanner's own field highlighted when one is mapped). */
const getIssueLink = (page: SchemaCoveragePage, finding: RawFinding): string => {
	if (!page.id) {
		return page.url;
	}
	const editLink = buildEditLink(page.id);
	return getEditorTargetForScanner(finding.scanner_id)
		? `${editLink}&${SEO_ISSUE_QUERY_PARAM}=${encodeURIComponent(finding.scanner_id)}`
		: editLink;
};

const normalizeUrl = (url: string): string => url.replace(/\/+$/, '');

const TYPE_ICONS: Record<string, string> = {
	Organization: 'shield',
	WebSite: 'link',
	Product: 'product',
	LocalBusiness: 'location',
	BreadcrumbList: 'category',
};
const getTypeIcon = (type: string): string => TYPE_ICONS[type] ?? 'attachment';

/**
 * Three-tier status per row, computed from the `found_on` and `problems` numbers the table shows.
 */
type CoverageStatus = 'good' | 'check' | 'problems';

const getRowStatus = (row: SchemaCoverageRow): CoverageStatus => {
	if (0 === row.problems) {
		return 'good';
	}
	const affectedShare = row.found_on > 0 ? row.problems / row.found_on : 1;
	return affectedShare >= 0.5 ? 'problems' : 'check';
};

const STATUS_CONFIG: Record<
	CoverageStatus,
	{ color: string; icon: string; label: string }
> = {
	good: { color: 'green', icon: 'check', label: __('Good', 'vulopilot') },
	check: { color: 'yellow', icon: 'alarm', label: __('Check', 'vulopilot') },
	problems: { color: 'red', icon: 'error', label: __('Problems', 'vulopilot') },
};

/**
 * Maps this table's own 3-tier status to the real `badge-{severity}` CSS classes zyra's Table.scss
 * actually defines.
 */
const STATUS_SEVERITY_CLASS: Record<CoverageStatus, string> = {
	good: 'resolved',
	check: 'medium',
	problems: 'critical',
};

/**
 * "Structured Data" section of the merged "Schema & Knowledge" tab - table left, detail panel
 * right (grid 8/4), same split IssuesSection.tsx uses above it, instead of a popup lightbox.
 * First real row auto-selects once a snapshot loads, same convention as `selectedGroup` there.
 */
const StructuredDataSection = ({ coverage }: StructuredDataSectionProps) => {
	const { snapshot, isLoading } = coverage;
	// The real row the side detail panel is showing.
	const [selectedRow, setSelectedRow] = useState<SchemaCoverageRow | null>(
		null
	);

	// Auto-selects the first real row once a snapshot loads (or after a re-analyze), so the detail
	// panel always has something real to show.
	useEffect(() => {
		if (!snapshot) {
			return;
		}
		setSelectedRow((current) => {
			if (
				current &&
				snapshot.coverage.some((row) => row.type === current.type)
			) {
				return (
					snapshot.coverage.find((row) => row.type === current.type) ??
					current
				);
			}
			return snapshot.coverage[0] ?? null;
		});
	}, [snapshot]);

	/** Shared by the row click and the action cell's own "More Details"/"Showing" button. */
	const handleSelectRow = (row: SchemaCoverageRow) => {
		setSelectedRow(row);
		scrollToId('structured-data-detail-panel');
	};

	// Real open schema findings, fetched once - matched to each selected
	// type's own pages below (a finding is scoped to a page, not a @type).
	const [schemaFindings, setSchemaFindings] = useState<RawFinding[]>([]);
	useEffect(() => {
		fetchOpenFindingsFor(SCHEMA_ISSUE_SCANNER_IDS)
			.then(setSchemaFindings)
			.catch(() => setSchemaFindings([]));
	}, [snapshot]);

	const selectedIssues = useMemo(() => {
		if (!selectedRow) {
			return [];
		}
		return selectedRow.pages.flatMap((page) =>
			schemaFindings
				.filter(
					(finding) =>
						finding.object_ref === String(page.id) ||
						normalizeUrl(finding.object_ref) === normalizeUrl(page.url)
				)
				.map((finding) => ({ finding, page }))
		);
	}, [selectedRow, schemaFindings]);

	return (
		<>
			<ColumnComponent grid={8}>
				<CardComponent
					title={__('Schema Coverage', 'vulopilot')}
					titleIcon="attachment"
					id="schema-knowledge-structured-data"
					desc={__(
						'VuloPilot checked how your website describes its pages, products, articles and business to search engines - see what structured information is there and where something is missing or incorrect, a real sample from its own live pages.',
						'vulopilot'
					)}
					isLoading={isLoading}
				>
					{!isLoading && !snapshot && (
						<ModuleGuardComponent
							icon="info"
							title={__('Not analyzed yet', 'vulopilot')}
							desc={__(
								'Run a scan (the “Run scan” button at the top of the page) and this table fills in with what structured data your real pages output.',
								'vulopilot'
							)}
						/>
					)}

					{snapshot && (
						<>
							{0 === snapshot.coverage.length ? (
								<div className="desc">
									{__(
										'No structured data (JSON-LD) was found on any sampled page.',
										'vulopilot'
									)}
								</div>
							) : (
								<TableCard
									showMenu={false}
									hideHeader={true}
									variant="transparent"
									headers={{
										type: {
											key: 'type',
											type: 'info',
											label: __('Schema type', 'vulopilot'),
											width: '65%',
											iconKey: 'typeIcon',
											descriptionKey: 'meaning',
											badgesKey: 'statusBadges',
										},
										found_on: {
											label: __('Found on', 'vulopilot'),
											render: (row: SchemaCoverageRow) =>
												sprintf(
													/* translators: %d is how many of the real sampled pages carried this schema type. */
													__('%d pages', 'vulopilot'),
													row.found_on
												),
										},
										action: {
											label: __('Action', 'vulopilot'),
											// `type: 'more-action'` no longer exists in
											// @zyra/table.
											type: 'action',
											actions: [
												{
													type: 'button',
													label: (row: SchemaCoverageRow) =>
														row.type === selectedRow?.type
															? __('Showing', 'vulopilot')
															: __('More Details', 'vulopilot'),
													color: (row: SchemaCoverageRow) =>
														row.type === selectedRow?.type
															? 'text-green'
															: 'text-purple',
													icon: (row: SchemaCoverageRow) =>
														row.type === selectedRow?.type
															? 'eye'
															: 'pagination-next-arrow',
													// The panel is never closed - clicking the row
													// already showing just keeps it open.
													onClick: handleSelectRow,
												},
											],
										},
									}}
									rows={snapshot.coverage.map((row) => ({
										...row,
										typeIcon: getTypeIcon(row.type),
										statusBadges: [
											{
												text: STATUS_CONFIG[getRowStatus(row)].label,
												color: `badge-${STATUS_SEVERITY_CLASS[getRowStatus(row)]}`,
											},
										],
									}))}
									ids={snapshot.coverage.map((row) => row.type)}
									totalRows={snapshot.coverage.length}
									isLoading={isLoading}
									activeRowId={selectedRow?.type}
									// A click anywhere on the row now opens the detail panel too,
									// not just the action cell's own small "More Details" button.
									onRowClick={(row: Record<string, unknown>) =>
										handleSelectRow(row as unknown as SchemaCoverageRow)
									}
									emptyMessage={__(
										'No structured data (JSON-LD) was found on any sampled page.',
										'vulopilot'
									)}
								/>
							)}

						</>
					)}
				</CardComponent>
			</ColumnComponent>

			<ColumnComponent grid={4}>
				<div id="structured-data-detail-panel">
				{selectedRow && (
					<CardComponent
						title={selectedRow.type}
						titleIcon={getTypeIcon(selectedRow.type)}
						desc={selectedRow.meaning}
					>
						<div className="schema-detail-stats">
							<BadgeComponent
								color={STATUS_CONFIG[getRowStatus(selectedRow)].color}
								icon={STATUS_CONFIG[getRowStatus(selectedRow)].icon}
								text={STATUS_CONFIG[getRowStatus(selectedRow)].label}
							/>
							<span className="desc">
								{sprintf(
									/* translators: 1: how many of the real sampled pages carried this schema type, 2: how many of those had a real problem. */
									__('Found on %1$s · %2$s', 'vulopilot'),
									sprintf(
										/* translators: %d: number of pages. */
										_n('%d page', '%d pages', selectedRow.found_on, 'vulopilot'),
										selectedRow.found_on
									),
									sprintf(
										/* translators: %d: number of problems. */
										_n('%d problem', '%d problems', selectedRow.problems, 'vulopilot'),
										selectedRow.problems
									)
								)}
							</span>
						</div>

						<div className="schema-detail-pages-heading">
							{sprintf(
								/* translators: %s is a real schema.org @type, e.g. "Product". */
								__('Pages with %s schema', 'vulopilot'),
								selectedRow.type
							)}
						</div>

						{0 === selectedRow.pages.length ? (
							<div className="desc">
								{__(
									'No individual pages recorded for this type.',
									'vulopilot'
								)}
							</div>
						) : (
							<ListComponent
								className="mini-card report"
								items={selectedRow.pages.map((page: SchemaCoveragePage) => ({
									id: String(page.id),
									title: page.title,
									tags: (
										<div className="schema-view-pages-actions">
											<a href={page.url} target="_blank" rel="noreferrer">
												{__('View', 'vulopilot')}
											</a>
											{page.edit_url && (
												<a
													href={page.edit_url}
													target="_blank"
													rel="noreferrer"
												>
													{__('Edit', 'vulopilot')}
												</a>
											)}
										</div>
									),
								}))}
							/>
						)}

						<div className="schema-detail-pages-heading">
							{__('Schema issues on these pages', 'vulopilot')}
						</div>

						{0 === selectedIssues.length ? (
							<div className="desc">
								{__(
									'No open schema issues on the pages carrying this type.',
									'vulopilot'
								)}
							</div>
						) : (
							<ListComponent
								className="mini-card report"
								items={selectedIssues.map(({ finding, page }) => ({
									id: String(finding.id),
									title: finding.title,
									desc: page.title,
									// Same "click the row → open that page's editor" behaviour as
									// the GEO/AEO issue tables.
									action: () => {
										window.location.href = getIssueLink(page, finding);
									},
									tags: (
										<>
											<BadgeComponent
												color={`badge-${finding.severity}`}
												text={finding.severity}
											/>
											<i className="adminfont-pagination-right-arrow ai-copilot-row-arrow" />
										</>
									),
								}))}
							/>
						)}
					</CardComponent>
				)}
				</div>
			</ColumnComponent>
		</>
	);
};

export default StructuredDataSection;
