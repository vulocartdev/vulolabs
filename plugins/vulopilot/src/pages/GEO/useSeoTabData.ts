/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { getApiLink, getApiResponse } from '@zyra/core';

/**
 * SeoTab.tsx's own 2 data-fetching hooks (`useSeoScore`/`useSeoProgress`).
 */

export interface SeoCategoryScore {
	score: number;
	open_count: number;
	affected_pages: number;
	/** Real per-category N-day score trend, oldest first (Seo.php's own `get_category_trend()`). */
	trend: number[];
}

export interface SeoScoreResponse {
	seo_score: number;
	/** Real published post+page count - the same real scope SeoScanner itself scans. */
	pages_checked: number;
	category_scores: {
		'titles-meta': SeoCategoryScore;
		'content-structure': SeoCategoryScore;
		images: SeoCategoryScore;
		'internal-linking': SeoCategoryScore;
		'indexability-canonicals': SeoCategoryScore;
		'structured-data': SeoCategoryScore;
	};
	severity_breakdown: {
		critical: number;
		high: number;
		medium: number;
		low: number;
	};
	total_open: number;
	/** Exact reconstruction of the same totals `lookback_days` ago, no stored snapshot needed. */
	deltas: {
		lookback_days: number;
		total_open: number;
		critical: number;
		high: number;
	};
}

/**
 * `GET /seo/score` - Seo.php's own real, deterministic weighted-severity score (same formula
 * BrandIntelligence's own Brand Score uses).
 */
export const useSeoScore = (): {
	score: SeoScoreResponse | null;
	isLoading: boolean;
} => {
	const [score, setScore] = useState<SeoScoreResponse | null>(null);
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		getApiResponse<SeoScoreResponse>(getApiLink(vulopilotAppLocalizer, 'seo/score'), {
			headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce },
		})
			.then((response) => {
				if (response) {
					setScore(response);
				}
			})
			.finally(() => setIsLoading(false));
	}, []);

	return { score, isLoading };
};

