import { __, sprintf } from '@wordpress/i18n';
import { COLOR_PALETTE } from '@zyra/core';
import type { Rating } from './types';
import {
	CardComponent,
	ChartComponent,
	TypographyComponent,
} from '@zyra/components';
import { useApiList } from '../../services/useApiList';
import './ProtectMySite.scss';
import SiteHealthStatusCard from './SiteHealthStatusCard';

interface FindingRow {
	id: number;
	severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
}

/**
 * Same canonical weighted-severity formula this codebase's own SEO/Content/ Brand scores already
 * use (`100 - critical*15 - high*8 - medium*3 - low*1`, clamped 0-100).
 */
const calculateScore = (high: number, medium: number, low: number): number =>
	Math.max(0, Math.min(100, 100 - high * 8 - medium * 3 - low * 1));

/** Same real 3-tier band shape `PerformanceScoreCard.tsx`'s own `Rating` interface uses. */
/**
 * Same 3-tier 0-100 thresholds seoRating.ts's own `getRating()` already establishes elsewhere in
 * this codebase.
 */
const getScoreRating = (score: number): Rating => {
	if (score >= 70) {
		return { label: __('Good', 'vulopilot'), className: 'good' };
	}
	if (score >= 40) {
		return { label: __('Needs Work', 'vulopilot'), className: 'needs-improvement' };
	}
	return { label: __('At Risk', 'vulopilot'), className: 'poor' };
};

/**
 * Real zyra palette hex (`@zyra/core`'s `COLOR_PALETTE`) - same real source
 * `PerformanceScoreCard.tsx`'s own `RATING_COLOR` reads.
 */
const RATING_COLOR: Record<Rating['className'], string> = {
	good: COLOR_PALETTE.green,
	'needs-improvement': COLOR_PALETTE.yellow,
	poor: COLOR_PALETTE.red,
};

/** Same real bands as `getScoreRating()` above, mapped to the real palette class name the ring color map is keyed by. */
const ratingClass = (score: number): Rating['className'] => {
	return getScoreRating(score).className;
};

/** Same 3 tiers as `RATING_COLOR` above, mapped to `TypographyComponent`'s own palette color names instead of a literal hex. */
const TEXT_COLOR: Record<Rating['className'], string> = {
	good: 'green',
	'needs-improvement': 'yellow',
	poor: 'red',
};

/** Same real score-tier summary line `OverallScoreWidget.tsx`'s own `getRatingSummary()` establishes for this exact ring pattern. */
const getRatingSummary = (score: number, label: string): string => {
	if (score >= 90) {
		return sprintf(
			/* translators: %s: this hero's own real section label, e.g. "Site Health". */
			__('Your %s is in excellent shape.', 'vulopilot'),
			label
		);
	}
	if (score >= 70) {
		return sprintf(
			/* translators: %s: this hero's own real section label, e.g. "Site Health". */
			__('Your %s is healthy and needs minimal work.', 'vulopilot'),
			label
		);
	}
	if (score >= 50) {
		return sprintf(
			/* translators: %s: this hero's own real section label, e.g. "Site Health". */
			__('Your %s could use some improvement.', 'vulopilot'),
			label
		);
	}
	return sprintf(
		/* translators: %s: this hero's own real section label, e.g. "Site Health". */
		__('Your %s needs attention in several areas.', 'vulopilot'),
		label
	);
};

interface FindingsHeroCardProps {
	/** adminfont- icon name for the hero's own icon circle. */
	icon: string;
	/** Used in the headline, e.g. "Site Health". */
	label: string;
	/** Combined scanner ids across every section this tab shows - same list SiteHealthTab.tsx builds for its own SectionedFindingsTab sections. */
	scannerIds: string[];
	/** Scrolls to the tab's own first section. */
	/** Forwarded straight to SiteHealthStatusCard's own `onSectionClick` - see that file's docblock. */
	// eslint-disable-next-line no-unused-vars
	onSectionClick?: (key: string) => void;
}

/**
 * Shared hero card for a category of findings, with a score ring and section links.
 */
const FindingsHeroCard = ({
	icon,
	label,
	scannerIds,
	onSectionClick,
}: FindingsHeroCardProps) => {
	const { data, total, isLoading } = useApiList<FindingRow>('findings', {
		scanner_id: scannerIds.join(','),
		status: 'open',
		per_page: 100,
	});

	const high = data.filter(
		(row) => row.severity === 'critical' || row.severity === 'high'
	).length;
	const medium = data.filter((row) => row.severity === 'medium').length;
	const low = data.filter(
		(row) => row.severity === 'low' || row.severity === 'info'
	).length;
	const score = calculateScore(high, medium, low);

	return (
		<CardComponent
			isLoading={isLoading}
			titleIcon={icon}
			title={
				total > 0
					? sprintf(
						/* translators: 1: number of open findings, 2: section label, e.g. "Site Health". */
						__('I found %1$d %2$s issue(s).', 'vulopilot'),
						total,
						label
					)
					: sprintf(
						/* translators: %s is the section label, e.g. "Site Health". */
						__(
							"You're all caught up - no open %s issues.",
							'vulopilot'
						),
						label
					)
			}
			desc={
				high > 0 && (
					<>
						{sprintf(
							/* translators: %d is the number of high-priority findings. */
							__('%d should be reviewed first.', 'vulopilot'),
							high
						)}
					</>
				)
			}
			className="findings-hero"
		>
			{!isLoading && (
				<>
					<div className='overall-score-wrapper'>
						{total > 0 && (
							<div className="overall-score-summary">
								<ChartComponent
									type="ring"
									height={200}
									// Top-level `color`: `type="ring"` paints its stroke only from this, never `data[].color`.
									color={RATING_COLOR[ratingClass(score)]}
									centerLabel={
										<>
											<TypographyComponent
												variant={'h1'}
												color={TEXT_COLOR[ratingClass(score)]}
											>
												{score}
											</TypographyComponent>
											<TypographyComponent variant={'h4'}>
												{getScoreRating(score).label}
											</TypographyComponent>
										</>
									}
									data={[
										{
											label: __('Score', 'vulopilot'),
											value: score,
											color: RATING_COLOR[ratingClass(score)],
										},
										{
											label: __('Remaining', 'vulopilot'),
											value: 100 - score,
											color: '#e5e7eb',
										},
									]}
								/>
								<TypographyComponent variant={'h3'} color="text-green">
									{__('Overall Score', 'vulopilot')}
								</TypographyComponent>
								<div className="desc">
									{getRatingSummary(score, label)}
								</div>
							</div>
						)}
						<SiteHealthStatusCard onSectionClick={onSectionClick} />
					</div>
				</>
			)}
		</CardComponent>
	);
};

export default FindingsHeroCard;