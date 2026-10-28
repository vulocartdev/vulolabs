/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { getApiLink, getApiResponse } from '@zyra/core';

export interface GeoSignalScore {
	score: number | null;
	open_count: number | null;
	affected_pages: number;
	main_problem: string | null;
	/**
	 * Real `PROGRESS_TREND_DAYS`-point daily score trend, oldest first (`Geo.php`'s own
	 * `get_signal_trend()`).
	 */
	trend: number[] | null;
}

export interface GeoScoreResponse {
	geo_score: number;
	/** Real published post+page count - the same real scope every GEO scanner itself scans. */
	pages_checked: number;
	signals: {
		'ai-summary': GeoSignalScore;
		'question-coverage': GeoSignalScore;
		'evidence-citations': GeoSignalScore;
		'ai-readable-structure': GeoSignalScore;
		'entity-clarity': GeoSignalScore;
		'content-freshness': GeoSignalScore;
		'other-geo-signals': GeoSignalScore;
	};
	/** Exact reconstruction of the same total `lookback_days` ago, no stored snapshot needed. */
	deltas: {
		lookback_days: number;
		total_open: number;
	};
}

export const useGeoScore = (): {
	score: GeoScoreResponse | null;
	isLoading: boolean;
} => {
	const [score, setScore] = useState<GeoScoreResponse | null>(null);
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		getApiResponse<GeoScoreResponse>(getApiLink(vulopilotAppLocalizer, 'geo/score'), {
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
