/* global vulopilotAppLocalizer */
import React, { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse, AnalyticsComponent } from '@zyra/core';
import { ChartComponent, ModuleGuardComponent, PopupComponent } from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import DashboardWidget from './DashboardWidget';
import DummyDataNotice from '../components/DummyDataNotice';
import { BlurredProContent } from '../components/UpgradeToProOverlay';
import ShowProPopup from '../components/Popup/Popup';
import { useApiList } from '../services/useApiList';
import { formatWpDate } from '../services/formatWpDate';
import { WidgetProps, PeriodDays } from './types';

interface CrawlerAnalyticsResponse {
	current_total: number;
	previous_total: number;
	daily_volume: { date: string; total: number }[];
}


interface HealthSnapshot {
	snapshot_date: string;
	overall_score: number;
}


/** Same real `key` field convention `OverviewTab.tsx`'s own identical `ToggleInput` usage already establishes. */
const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];


/** Fabricated 7-day score trend - same "obviously fake, never mistaken for a real scan result" reasoning Accessibility.tsx's own `DUMMY_ACCESSIBILITY_HISTORY` documents. */
const DUMMY_HEALTH_TIMELINE = [
	{ day: __('Day 1', 'vulopilot'), score: 58 },
	{ day: __('Day 2', 'vulopilot'), score: 63 },
	{ day: __('Day 3', 'vulopilot'), score: 61 },
	{ day: __('Day 4', 'vulopilot'), score: 68 },
	{ day: __('Day 5', 'vulopilot'), score: 72 },
	{ day: __('Day 6', 'vulopilot'), score: 75 },
	{ day: __('Day 7', 'vulopilot'), score: 79 },
];

/**
 * "VuloPilot activity" - a real 5-tile activity strip.
 */
const VuloPilotActivityWidget: React.FC<WidgetProps> = ({
	summary,
	isLoading,
	onHide,
	isCustomizing,
}) => {
	const [healthTimelineDays, setHealthTimelineDays] = useState<PeriodDays>('30');
	const [crawlerAnalytics, setCrawlerAnalytics] =
		useState<CrawlerAnalyticsResponse | null>(null);

	useEffect(() => {
		getApiResponse<CrawlerAnalyticsResponse>(
			getApiLink(
				vulopilotAppLocalizer,
				`crawler-traffic/analytics?days=${healthTimelineDays}`
			),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		).then((response) => {
			if (response) {
				setCrawlerAnalytics(response);
			}
		});
	}, [healthTimelineDays]);


	const { data: healthSnapshots } = useApiList<HealthSnapshot>(
		'site-health-snapshots',
		{ days: Number(healthTimelineDays) },
		undefined,
		Boolean(vulopilotAppLocalizer.khali_dabba)
	);
	const isHealthTimelineModuleActive = Boolean(vulopilotAppLocalizer.khali_dabba);
	const [isHealthTimelineProPopupOpen, setIsHealthTimelineProPopupOpen] = useState(false);

	const crawlerCurrent = crawlerAnalytics?.current_total ?? 0;


	return (
		<>
			<DashboardWidget
				title={__('Health timeline', 'vulopilot')}
				desc={__('How your health scores have trended over time.', 'vulopilot')}
				icon="analytics"
				isLoading={isLoading}
				onHide={onHide}
				isCustomizing={isCustomizing}
				headerAction={
					<ToggleInput
						options={PERIOD_OPTIONS}
						value={healthTimelineDays}
						onChange={(value) => setHealthTimelineDays(value as PeriodDays)}
						modules={[]}
						variant="pill"
					/>
				}
			>

				{!isHealthTimelineModuleActive ? (
					<>
						<BlurredProContent
							contentClassName="health-timeline-dummy"
							onClick={() => setIsHealthTimelineProPopupOpen(true)}
						>
							<ChartComponent
								type="dynamic-line"
								data={DUMMY_HEALTH_TIMELINE}
								dataKey="score"
								xKey="day"
								height={300}
								yDomain={[0, 100]}
							/>
						</BlurredProContent>
						<DummyDataNotice />
					</>
				) : 0 === healthSnapshots.length ? (
					<ModuleGuardComponent
						icon="analytics"
						title={__('No trend data yet', 'vulopilot')}
						desc={__(
							'Health timeline builds up once daily snapshots start recording - check back after today.',
							'vulopilot'
						)}
					/>
				) : (
					<ChartComponent
						type="dynamic-line"
						data={healthSnapshots.map((snapshot) => ({
							...snapshot,
							snapshot_date: formatWpDate(snapshot.snapshot_date),
						}))}
						dataKey="overall_score"
						xKey="snapshot_date"
						height={300}
						yDomain={[0, 100]}
					/>
				)}
				<AnalyticsComponent
					variant="small"
					cols={3}
					data={[
						{
							// icon: 'global-community green',
							number: crawlerCurrent,
							text: __('AI crawler visits', 'vulopilot'),
						},
						{
							// icon: 'automation blue',
							number: summary.automation_status.enabled,
							text: __('Automations', 'vulopilot'),
						},
						{
							// icon: 'ai purple',
							number: summary.pending_approvals,
							text: __('Pending approvals', 'vulopilot'),
						}
					]}
				/>
			</DashboardWidget>
			<PopupComponent
				open={isHealthTimelineProPopupOpen}
				onClose={() => setIsHealthTimelineProPopupOpen(false)}
				width={31.25}
				height="auto"
				position="lightbox"
			>
				<ShowProPopup />
			</PopupComponent>
		</>
	);
};

export default VuloPilotActivityWidget;