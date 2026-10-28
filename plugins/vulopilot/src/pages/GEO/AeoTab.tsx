/* global vulopilotAppLocalizer */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useModules } from '@zyra/core';
import { ColumnComponent, ContainerComponent } from '@zyra/components';
import AeoScoreSummaryCard from './AeoScoreSummaryCard';
import { computeTrendChange } from './geoTrendChange';
import AeoCitationCoverageCard from './AeoCitationCoverageCard';
import AeoEngineTestingCard from './AeoEngineTestingCard';
import IssuesSection from './IssuesSection';
import GeoAeoPageAnalysisPanel from './GeoAeoPageAnalysisPanel';
import {
	useAllFindingGroups,
	sumGroupCounts,
	useGeoVisibilitySnapshot,
	type GeoVisibilityHistoryRow,
} from './useGeoTabData';
import { useAeoPageAnalysis } from './useAeoPageAnalysis';

/**
 * Section → scanner_id grouping for AEO's 6 topics - every scanner_id is a real, already-running
 * scanner; no topic invents a signal.
 */
const AEO_SECTIONS: {
	key: string;
	title: string;
	titleIcon: string;
	description: string;
	emptyMessage: string;
	scannerIds: string[];
}[] = [
	{
		key: 'coverage',
		title: __('Questions & Answers', 'vulopilot'),
		titleIcon: 'question blue',
		description: __(
			'Whether pages clearly answer the questions people are likely to ask, with a dedicated FAQ or Q&A block making that answer easy for AI to extract.',
			'vulopilot'
		),
		emptyMessage: __(
			'No question-coverage findings yet - run a scan to check for FAQ opportunities.',
			'vulopilot'
		),
		scannerIds: ['geo-faq-opportunity'],
	},
	{
		key: 'answers',
		title: __('Direct Answers', 'vulopilot'),
		titleIcon: 'analytics pink',
		description: __(
			'Whether pages have an extractable AI summary - a short, up-front answer an AI system can quote directly, rather than one buried in the middle of the content.',
			'vulopilot'
		),
		emptyMessage: __(
			'No direct-answer findings yet - run a scan to check for AI summary blocks.',
			'vulopilot'
		),
		scannerIds: ['geo-summary-block'],
	},
	{
		key: 'schema',
		title: __('Schema Markup', 'vulopilot'),
		titleIcon: 'shortcode teal',
		description: __(
			'Content already shaped like an FAQ or a how-to guide, but missing the schema.org markup that lets AI answer engines recognize it as one.',
			'vulopilot'
		),
		emptyMessage: __(
			'No schema findings yet - run a scan to check FAQ/HowTo-shaped content for missing markup.',
			'vulopilot'
		),
		scannerIds: ['aeo-schema'],
	},
];

/** Exported so KeyPagesWidget.tsx's own Dashboard-tab "Issues at a glance" row can count real open AEO findings the same way this tab itself does. */
export const ALL_AEO_SCANNER_IDS = AEO_SECTIONS.flatMap((section) => section.scannerIds);

const average = (values: number[]): number =>
	values.length
		? Math.round(values.reduce((sum, n) => sum + n, 0) / values.length)
		: 0;

/**
 * The same 3-dimension average the "AEO Score" ring computes for "today"
 * (`answer_first_structure`/`question_coverage`/`citation_readiness`).
 */
const getAeoTrendScore = (row: GeoVisibilityHistoryRow): number | null =>
	row.ai_scores && row.sub_scores
		? average([
				row.ai_scores.answer_first_structure,
				row.ai_scores.question_coverage,
				row.sub_scores.citation_readiness,
			])
		: null;

/** Real backend module id (Settings → Modules) - Pro-only, no matching Free module folder. */
const AEO_MODULE_ID = 'answer-engine-optimization';

/**
 * Whether GeoInsights' Rest.php class is registered - either 'geo-insights' or 'aeo-insights'
 * being active is enough.
 */
const isCitationCheckActive = (modules: string[]): boolean =>
	Boolean(vulopilotAppLocalizer.khali_dabba) &&
	(modules.includes('geo-analysis') || modules.includes(AEO_MODULE_ID));

/** Whether Pro is active+licensed AND the AEO module itself is toggled on. */
const isAeoModuleActive = (modules: string[]): boolean =>
	Boolean(vulopilotAppLocalizer.khali_dabba) && modules.includes(AEO_MODULE_ID);

/**
 * AEO = Answer Engine Optimization - whether AI systems can extract, structure.
 */
const AeoTab = () => {
	const [categoryFocus, setCategoryFocus] = useState<{
		key: string;
		token: number;
	} | null>(null);
	/** Set by a real "Analyze" click in the "Pages & Posts" table below. */
	const [analyzingPostId, setAnalyzingPostId] = useState<number | null>(null);
	const { modules } = useModules();
	const aeoActive = isAeoModuleActive(modules);
	const { groups, isLoading: isLoadingGroups } = useAllFindingGroups();
	const { history, isLoading: isLoadingSnapshot } = useGeoVisibilitySnapshot();
	const { pages: aeoPages, total: totalPages, isLoading: isLoadingPages } =
		useAeoPageAnalysis(ALL_AEO_SCANNER_IDS);

	// "Questions Answered" - real published pages minus the real count of pages with an open geo-
	// faq-opportunity finding (i.e. pages that already have adequate question coverage).
	const openFaqFindings = sumGroupCounts(groups, ['geo-faq-opportunity']);
	const questionsAnswered = totalPages
		? Math.max(0, totalPages - openFaqFindings)
		: 0;

	// "Pages Ready" - real published pages with zero open AEO findings (across all 6 topics
	// above), out of the real total.
	const pagesReady = aeoPages.filter((page) => 0 === page.open_findings).length;

	/**
	 * Sets a fresh `categoryFocus` (a new `token` even for the same `key` twice in a row).
	 */
	const goToIssuesTable = (key: string = 'all') => {
		setCategoryFocus({ key, token: Date.now() });
	};

	const aeoTrend = computeTrendChange(history, getAeoTrendScore);

	return (
		<ContainerComponent>
			<ColumnComponent grid={6}>
				<AeoScoreSummaryCard
					isActive={aeoActive}
					isLoading={isLoadingSnapshot || isLoadingGroups || isLoadingPages}
					questionsAnswered={questionsAnswered}
					totalPages={totalPages}
					pagesReady={pagesReady}
					trend={aeoTrend}
					topics={AEO_SECTIONS}
					groups={groups}
					onSelectTopic={goToIssuesTable}
				/>
			</ColumnComponent>
			<AeoCitationCoverageCard isActive={isCitationCheckActive(modules)} />
			<AeoEngineTestingCard isActive={isCitationCheckActive(modules)} pages={aeoPages} />
	

			{/* Same real "filter pills + Site-wide Issues + Pages & Posts" structure SeoTab.tsx's own issues table already has (IssuesSection.tsx, generalized from what used to be SEO-only) - replaces the differently-shaped SectionedFindingsTab this used before. */}
			<ColumnComponent grid={8}>
				<IssuesSection
					id="aeo-all-issues-table"
					title={__('All AEO Findings', 'vulopilot')}
					scannerIds={ALL_AEO_SCANNER_IDS}
					categories={AEO_SECTIONS}
					categoryFocus={categoryFocus}
					issuesColumnLabel={__('AEO Issues', 'vulopilot')}
					pageAnalysis={{
						scoreColumnLabel: __('Answer Readiness', 'vulopilot'),
						exportFilename: 'aeo-page-analysis.csv',
					}}
					onAnalyze={setAnalyzingPostId}
					activePostId={analyzingPostId}
					onAnalyzeClose={() => setAnalyzingPostId(null)}
				/>
			</ColumnComponent>
			{analyzingPostId && (
				<ColumnComponent grid={4}>
					<GeoAeoPageAnalysisPanel
						postId={analyzingPostId}
						scannerIds={ALL_AEO_SCANNER_IDS}
						title={__('Page Analysis', 'vulopilot')}
						desc={__(
							'A single page’s real AEO signals from your most recent scan.',
							'vulopilot'
						)}
						onClose={() => setAnalyzingPostId(null)}
					/>
				</ColumnComponent>
			)}
		</ContainerComponent>
	);
};

export default AeoTab;
