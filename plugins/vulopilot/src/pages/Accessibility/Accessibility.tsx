/* global vulopilotAppLocalizer */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import type { PeriodDays } from './types';
import {
	CardComponent,
	ChartComponent,
	ColumnComponent,
	ContainerComponent,
	NavigatorHeaderComponent,
	PopupComponent,
} from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import RunScanHeaderExtra from '../../components/RunScanHeaderExtra';
import ShowProPopup from '../../components/Popup/Popup';
import { BlurredProContent } from '../../components/UpgradeToProOverlay';
import DummyDataNotice from '../../components/DummyDataNotice';
import { useFilterSlot } from '../../services/useFilterSlot';
import { formatWpDate } from '../../services/formatWpDate';
import './Accessibility.scss';
import SectionedIssuesTable, {
	SectionedIssuesTab,
} from '../Security/SectionedIssuesTable';
import PluginOverlapCard from '../Security/PluginOverlapCard';
import AccessibilityHeroCard from './AccessibilityHeroCard';
import AccessibilityManualTestingPanel from './AccessibilityManualTestingPanel';
import WhyAccessibilityMattersCard from './WhyAccessibilityMattersCard';
import { ACCESSIBILITY_CHECKS } from './accessibilityChecks';

/** DOM anchor id the merged issues table below carries. */
const ISSUES_TABLE_ID = 'accessibility-a11y-issues-table';
const ACCESSIBILITY_MODULE_ID = 'accessibility-checks';

/** Fabricated 7-day score trend for the dummy chart. */
const DUMMY_ACCESSIBILITY_SCORES = [62, 66, 65, 71, 74, 78, 82];

/** Last 7 days ending today, labeled with the site's own date format ("August 26, 2026"). */
const DUMMY_ACCESSIBILITY_HISTORY = DUMMY_ACCESSIBILITY_SCORES.map(
	(score, index) => {
		const date = new Date();
		date.setDate(
			date.getDate() - (DUMMY_ACCESSIBILITY_SCORES.length - 1 - index)
		);

		return {
			date: formatWpDate(`${date.toISOString().slice(0, 10)} 12:00:00`),
			score,
		};
	}
);

/** Decorative only - no real per-period fetch behind it. */
const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

/**
 * `ACCESSIBILITY_CHECKS` minus its synthetic `'all'` tile.
 */
const ISSUES_TABLE_SECTIONS = ACCESSIBILITY_CHECKS.filter(
	(check) => 'all' !== check.key
);

const AccessibilityHistoryDummy = () => {
	const [isProPopupOpen, setIsProPopupOpen] = useState(false);
	const [period, setPeriod] = useState<PeriodDays>('30');
	const isProInstalled = Boolean(vulopilotAppLocalizer.khali_dabba);

	return (
		<>
			<CardComponent
				title={__('Accessibility Score History', 'vulopilot')}
				titleIcon="analytics"
				desc={__(
					'Real historical accessibility score trend over time, so you can see whether things are actually improving.',
					'vulopilot'
				)}
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
				<BlurredProContent
					contentClassName="accessibility-history-dummy"
					onClick={() => setIsProPopupOpen(true)}
				>
					<ChartComponent
						type="dynamic-line"
						data={DUMMY_ACCESSIBILITY_HISTORY}
						dataKey="score"
						xKey="date"
						height={220}
						yDomain={[0, 100]}
					/>
				</BlurredProContent>
				<DummyDataNotice />
			</CardComponent>
			<PopupComponent
				open={isProPopupOpen}
				onClose={() => setIsProPopupOpen(false)}
				width={31.25}
				height="auto"
				position="lightbox"
			>
				{isProInstalled ? (
					<ShowProPopup moduleName={ACCESSIBILITY_MODULE_ID} />
				) : (
					<ShowProPopup />
				)}
			</PopupComponent>
		</>
	);
};

/**
 * "Accessibility" top-level page (WP menu slug `accessibility`, `classes/Admin.php`'s
 * `$submenus`).
 */
const Accessibility = () => {
	const [activeTab, setActiveTab] = useState<SectionedIssuesTab>('all');
	const AccessibilityHistoryPanel = useFilterSlot(
		'vulopilot_accessibility_history_panel'
	);
	const goToIssuesTable = (tab: SectionedIssuesTab) => {
		setActiveTab(tab);
		setTimeout(
			() =>
				document
					.getElementById(ISSUES_TABLE_ID)
					?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
			50
		);
	};

	return (
		<>
			<NavigatorHeaderComponent
				headerIcon="support"
				headerTitle={__('Accessibility', 'vulopilot')}
				headerDescription={__(
					'Make sure everyone can use your website.',
					'vulopilot'
				)}
				headerCustomContent={
					<RunScanHeaderExtra
						categories={['accessibility']}
						label={__('Run Accessibility Audit', 'vulopilot')}
						settingsSubtab="modules"
					/>
				}
			/>
			<ContainerComponent general>
				<ColumnComponent fullHeight grid={7}>
					<AccessibilityHeroCard
						onReviewIssues={() => goToIssuesTable('all')}
					/>
				</ColumnComponent>

				<ColumnComponent fullHeight grid={5}>

					<PluginOverlapCard category="accessibility" />
					{AccessibilityHistoryPanel ? (
						<AccessibilityHistoryPanel />
					) : (
						<AccessibilityHistoryDummy />
					)}
				</ColumnComponent>

				<ColumnComponent fullHeight grid={6}>
					<WhyAccessibilityMattersCard />

				</ColumnComponent>
				<ColumnComponent fullHeight grid={6}>
					<AccessibilityManualTestingPanel />
				</ColumnComponent>
				<ColumnComponent>
					<SectionedIssuesTable
						id={ISSUES_TABLE_ID}
						title={__('All Accessibility Findings', 'vulopilot')}
						sections={ISSUES_TABLE_SECTIONS}
						activeTab={activeTab}
						onTabChange={setActiveTab}
					/>
				</ColumnComponent>
			</ContainerComponent>
		</>
	);
};

export default Accessibility;
