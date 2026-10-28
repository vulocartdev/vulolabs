/* global vulopilotAppLocalizer */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import type { PeriodDays } from './types';
import {
	AnalyticsComponent,
	CardComponent,
	ChartComponent,
	ModuleGuardComponent,
} from '@zyra/components';
import { ToggleInput } from '@zyra/inputs';
import { useApiList } from '../../services/useApiList';
import { formatWpDate } from '../../services/formatWpDate';
import { useReportsOverview } from '../Reports/reportsOverview';

interface SecurityScoreSnapshot {
	snapshot_date: string;
	security_score: number;
}

const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

/**
 * `days` is a real 7/30/90 toggle now (same `PERIOD_OPTIONS`/`ToggleInput` shape
 * GeoScoreSection.tsx's own card action already uses).
 */
const SecurityTrendCard = () => {
	const [period, setPeriod] = useState<PeriodDays>('30');
	const { data: snapshots, isLoading } = useApiList<SecurityScoreSnapshot>(
		'security-score-snapshots',
		{ days: Number(period) }
	);

	// Fixed / new / still-open counts for the same selected period.
	const hasOverview = Boolean(vulopilotAppLocalizer.khali_dabba);
	const { data: overview, isLoading: isLoadingSummary } = useReportsOverview(
		Number(period),
		hasOverview
	);
	const summary = overview?.security_summary;

	return (
		<CardComponent
			id="security-trend-card"
			title={__('Security Trend', 'vulopilot')}
			titleIcon="security"
			desc={sprintf(
				/* translators: %d: number of days the trend below covers. */
				__('Your daily security score over the last %d days.', 'vulopilot'),
				Number(period)
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
			{!isLoading && snapshots.length === 0 ? (
				<ModuleGuardComponent
					icon="analytics"
					title={__('No trend data yet', 'vulopilot')}
					desc={__(
						'Security trend builds up after your first scan - run a scan, or check back after today.',
						'vulopilot'
					)}
				/>
			) : (
				<ChartComponent
					type="dynamic-line"
					isLoading={isLoading}
					data={snapshots.map((snapshot) => ({
						...snapshot,
						snapshot_date: formatWpDate(snapshot.snapshot_date),
					}))}
					dataKey="security_score"
					xKey="snapshot_date"
					height={250}
					yDomain={[0, 100]}
				/>
			)}
			{hasOverview && (
			<AnalyticsComponent
				variant="background-color"
				cols={3}
				isLoading={isLoadingSummary}
				data={[
					{
						colorClass: 'green',
						number: String(summary?.fixed ?? 0),
						text: __('Issues Fixed', 'vulopilot'),
					},
					{
						colorClass: 'yellow',
						number: String(summary?.new ?? 0),
						text: __('New Issues', 'vulopilot'),
					},
					{
						colorClass: 'blue',
						number: String(summary?.still_open ?? 0),
						text: __('Still Open', 'vulopilot'),
					},
				]}
			/>
			)}
		</CardComponent>
	);
};

export default SecurityTrendCard;
