/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse, COLOR_PALETTE } from '@zyra/core';
import type { Rating } from './types';
import {
	CardComponent,
	ChartComponent,
	TypographyComponent,
} from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import { useApiList } from '../../services/useApiList';
import { ACCESSIBILITY_SCANNER_IDS } from './accessibilityChecks';
import AccessibilityChecksGrid from './AccessibilityChecksGrid';

interface AccessibilityFinding {
	id: number;
	severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
	page?: string;
}

interface DashboardSummary {
	category_scores: { accessibility: number };
	category_scores_7d_ago: { accessibility: number };
}

interface AccessibilityHeroCardProps {
	onReviewIssues: () => void;
}

/** Same 3-tier band shape as PerformanceScoreCard.tsx's Rating. */
/** Lighthouse-style 0-100 bands, matching PerformanceScoreCard.tsx's getScoreRating(). */
const getScoreRating = (score: number): Rating => {
	if (score >= 90) {
		return { label: __('Good', 'vulopilot'), className: 'good' };
	}
	if (score >= 50) {
		return { label: __('Needs Work', 'vulopilot'), className: 'needs-improvement' };
	}
	return { label: __('At Risk', 'vulopilot'), className: 'poor' };
};

/** Zyra palette hex (@zyra/core's COLOR_PALETTE). */
const RATING_COLOR: Record<Rating['className'], string> = {
	good: COLOR_PALETTE.green,
	'needs-improvement': COLOR_PALETTE.orange,
	poor: COLOR_PALETTE.red,
};

/** Same 3 tiers as RATING_COLOR, mapped to TypographyComponent's palette color names. */
const TEXT_COLOR: Record<Rating['className'], string> = {
	good: 'green',
	'needs-improvement': 'orange',
	poor: 'red',
};

/** Maps a score to the rating class key used by the color maps above. */
const ratingClass = (score: number): Rating['className'] => {
	return getScoreRating(score).className;
};

/** Uses category_scores.accessibility from GET /dashboard. */
const getRating = (score: number): string => {
	if (score >= 90) {
		return __(
			'Great job',
			'vulopilot'
		);
	}
	if (score >= 70) {
		return __('Accessibility needs some attention.', 'vulopilot');
	}
	if (score >= 50) {
		return __('Accessibility needs attention.', 'vulopilot');
	}
	return __('Accessibility needs urgent attention.', 'vulopilot');
};

/** Hero card showing the accessibility score gauge. */
const AccessibilityHeroCard = ({
	onReviewIssues,
}: AccessibilityHeroCardProps) => {
	const [score, setScore] = useState<number | null>(null);

	useEffect(() => {
		getApiResponse<DashboardSummary>(
			getApiLink(vulopilotAppLocalizer, 'dashboard'),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		).then((response) => {
			if (response) {
				setScore(response.category_scores.accessibility);
			}
		});
	}, []);

	const { total, isLoading } = useApiList<AccessibilityFinding>(
		'findings',
		{
			scanner_id: ACCESSIBILITY_SCANNER_IDS.join(','),
			status: 'open',
			// Limits to the 100 most recent open findings.
			per_page: 100,
		}
	);


	const isReady = !isLoading && score !== null;
	const overallScore = (score as number) ?? 0;

	return (
		<CardComponent isLoading={!isReady} className="accessibility-hero">
			<div className='overall-score-wrapper'>
				<div className='overall-score-summary'>
					{isReady && (
						<>
							<ChartComponent
								type="ring"
								height={200}
								// type="ring" only paints its stroke from this top-level color prop.
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
							<TypographyComponent variant={'h3'} color="text-green">
								{getRating(score as number)}
								
							</TypographyComponent>
							<div className="desc">
								{total > 0
									? sprintf(__(
											'Most visitors can use your site, but some areas could be improved.',
											'vulopilot'
										),
									)
									: __(
										"You're all caught up - no open accessibility issues right now.",
										'vulopilot'
									)}
							</div>
							<ButtonInput
								position="left"
								buttons={[
									{
										text: __('Review Important Issues', 'vulopilot'),
										rightIcon: 'pagination-right-arrow',
										color: 'border-purple',
										onClick: onReviewIssues,
									},
								]}
							/>
						</>
					)}
				</div>
				<div className='overall-score-summary'>
					<AccessibilityChecksGrid />
				</div>
			</div>
		</CardComponent>
	);
};

export default AccessibilityHeroCard;