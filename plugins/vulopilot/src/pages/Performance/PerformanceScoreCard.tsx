/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse, COLOR_PALETTE } from '@zyra/core';
import type { PeriodDays, Rating } from './types';
import {
	CardComponent,
	ChartComponent,
	ColumnComponent,
	ModuleGuardComponent,
	TypographyComponent
} from '@zyra/components';
import { ButtonInput, ToggleInput } from '@zyra/inputs';
import './Performance.scss';
import RealTimeMonitoringCard from './RealTimeMonitoringCard';
import SpeedHistoryCard from './SpeedHistoryCard';
import LiveSiteInsightsCard from '../Security/LiveSiteInsightsCard';
import type { CoreWebVitalsSummary } from '../Security/LiveSiteInsightsCard';


interface DashboardSummary {
	category_scores: { performance: number };
	psi_speed_scores: {
		mobile: number | null;
		desktop: number | null;
		checked_at: string | null;
	};
}

const PERIOD_OPTIONS = [
	{ key: '7', value: '7', label: __('7D', 'vulopilot') },
	{ key: '30', value: '30', label: __('30D', 'vulopilot') },
	{ key: '90', value: '90', label: __('90D', 'vulopilot') },
];

interface PerformanceScoreCardProps {
	/** Scrolls to the "Top Issues" FindingsTable further down this Overview tab. */
	onViewDetails: () => void;
}


/** Lighthouse's own real, documented 0-100 performance-score bands. */
const getScoreRating = (score: number): Rating => {
	if (score >= 90) {
		return { label: __('Good', 'vulopilot'), className: 'good' };
	}
	if (score >= 50) {
		return { label: __('Needs Work', 'vulopilot'), className: 'needs-improvement' };
	}
	return { label: __('At Risk', 'vulopilot'), className: 'poor' };
};

/**
 * Real zyra palette hex (`@zyra/core`'s `COLOR_PALETTE`).
 */
const RATING_COLOR: Record<Rating['className'], string> = {
	good: COLOR_PALETTE.green,
	'needs-improvement': COLOR_PALETTE.orange,
	poor: COLOR_PALETTE.red,
};

/** Same real bands as `getScoreRating()` above, mapped to the real palette class name this file's own SCSS and the reference snippet both use. */
const ratingClass = (score: number): Rating['className'] => {
	return getScoreRating(score).className;
};

/** Same 3 tiers as `RATING_COLOR` above, mapped to `TypographyComponent`'s own palette color names instead of a literal hex. */
const TEXT_COLOR: Record<Rating['className'], string> = {
	good: 'green',
	'needs-improvement': 'orange',
	poor: 'red',
};







/**
 * "Performance Score": an Overall Speed Score card (from `GET /dashboard`) plus Core Web Vitals.
 */
const PerformanceScoreCard = ({ onViewDetails }: PerformanceScoreCardProps) => {
	const [dashboard, setDashboard] = useState<DashboardSummary | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const [hasError, setHasError] = useState(false);
	/** Drives SpeedHistoryCard's own real `days` param below. */
	const [period, setPeriod] = useState<PeriodDays>('30');

	/**
	 * Real objects only - `getApiResponse` (zyra) hands back whatever axios parsed `response.data`
	 * into.
	 */
	const isPlainObject = (value: unknown): value is Record<string, unknown> =>
		null !== value && 'object' === typeof value && !Array.isArray(value);

	useEffect(() => {
		setIsLoading(true);
		setHasError(false);

		Promise.all([
			getApiResponse<DashboardSummary>(getApiLink(vulopilotAppLocalizer, 'dashboard'), {
				headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce },
			}),
			getApiResponse<CoreWebVitalsSummary>(getApiLink(vulopilotAppLocalizer, 'core-web-vitals'), {
				headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce },
			}),
		])
			.then(([dashboardResponse, vitalsResponse]) => {
				const dashboardValid =
					isPlainObject(dashboardResponse) &&
					isPlainObject(dashboardResponse.category_scores);
				const vitalsValid = isPlainObject(vitalsResponse);

				if (!dashboardValid || !vitalsValid) {
					setHasError(true);
					return;
				}

				setDashboard(dashboardResponse);
			})
			.catch(() => setHasError(true))
			.finally(() => setIsLoading(false));
	}, []);

	const psi = dashboard?.psi_speed_scores ?? null;
	const hasPsi =
		null !== psi &&
		'number' === typeof psi.mobile &&
		'number' === typeof psi.desktop;

	/**
	 * Real score the hero ring plots - same number the old `ScoreTile` row showed.
	 */
	const overallScore = hasPsi && psi
		? Math.round(((psi.mobile as number) + (psi.desktop as number)) / 2)
		: dashboard?.category_scores.performance ?? 0;

	const comparisonMessage = (): string | null => {
		if (
			!hasPsi ||
			'number' !== typeof psi?.mobile ||
			'number' !== typeof psi?.desktop
		) {
			return null;
		}

		const gap = psi.desktop - psi.mobile;

		if (gap >= 10) {
			return sprintf(
				/* translators: %d is how many points lower the mobile score is than desktop. */
				__(
					'Your mobile site is %d points slower than desktop. Focus on improving mobile performance for a better experience.',
					'vulopilot'
				),
				gap
			);
		}

		if (gap <= -10) {
			return sprintf(
				/* translators: %d is how many points lower the desktop score is than mobile. */
				__('Your desktop site is %d points slower than mobile.', 'vulopilot'),
				Math.abs(gap)
			);
		}

		return __('Mobile and desktop performance are similar.', 'vulopilot');
	};

	return (
		<>
			<ColumnComponent grid={6} row fullHeight>
				<CardComponent
					id="performance-overall-speed-score-card"
					title={__('Overall Speed Score', 'vulopilot')}
					titleIcon="analytics"
					desc={__('Your real performance score from Google PageSpeed Insights.', 'vulopilot')}
					isLoading={isLoading}
					headerAction={
						<ButtonInput
							buttons={{
								text: __('View Slow Pages', 'vulopilot'),
								rightIcon: 'eye',
								color: 'text-purple',
								onClick: onViewDetails,
							}}
						/>
					}
				>
					{!isLoading && hasError && (
						<ModuleGuardComponent
							icon="error"
							title={__('Could not load your speed score', 'vulopilot')}
							desc={__('Please refresh the page to try again.', 'vulopilot')}
						/>
					)}
					{!isLoading && !hasError && dashboard && (
						<>
							<div className='overall-score-wrapper'>
								<div className="overall-score-summary">
									<ChartComponent
										type="ring"
										height={200}
										// Top-level `color`: `type="ring"` paints its stroke only from this, never `data[].color`.
										color={RATING_COLOR[ratingClass(overallScore)]}
										centerLabel={
											<>
												<TypographyComponent
													variant={'h1'}
													color={TEXT_COLOR[ratingClass(overallScore)]}
												>
													{overallScore}
												</TypographyComponent>
												<TypographyComponent variant={'h4'}>
													{getScoreRating(overallScore).label}
												</TypographyComponent>
											</>
										}
										data={[
											{
												label: __('Score', 'vulopilot'),
												value: overallScore,
												color: RATING_COLOR[ratingClass(overallScore)],
											},
											{
												label: __('Remaining', 'vulopilot'),
												value: 100 - overallScore,
												color: '#e5e7eb',
											},
										]}
									/>
									<div className="desc">
										{hasPsi
											? comparisonMessage()
											: __(
												'Connect Google PageSpeed Insights for a real Mobile/Desktop breakdown.',
												'vulopilot'
											)}
									</div>
								</div>
								<div className="overall-score-summary">
									<LiveSiteInsightsCard />
								</div>
							</div>
						</>
					)}
				</CardComponent>
			</ColumnComponent>
			<ColumnComponent grid={6} row fullHeight>
				<CardComponent
					id="performance-core-web-vitals-card"
					title={__('Core Web Vitals', 'vulopilot')}
					titleIcon="analytics"
					desc={__('Real Google Core Web Vitals for this site.', 'vulopilot')}
					isLoading={isLoading}
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
					<SpeedHistoryCard days={Number(period)} />
					<RealTimeMonitoringCard />
				</CardComponent>
			</ColumnComponent>
		</>
	);
};

export default PerformanceScoreCard;