/* global vulopilotAppLocalizer */
import { useCallback, useEffect, useState } from 'react';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';

/**
 * `GET /ai-credits/status` only reads the DB-cached balance, never talks to VuloCloud - only
 * `POST /ai-credits/refresh-balance` does. `refresh()` calls the live endpoint; `loadCached()` is
 * kept only for the instant-paint read on mount.
 */
export interface AiCreditsStatus {
	connected: boolean;
	/** Fractional - exactly what the server last reported. */
	credits: number;
	lifetime_earned: number;
	lifetime_used: number;
	/** The AI Credits page (Buy Credits) - '' until first synced. */
	buy_credits_url: string;
	connected_at: string;
	last_synced_at: string;
	vulocloud_account_connected: boolean;
	vulocloud_account_email: string;
}

export const useAiCredits = () => {
	const [status, setStatus] = useState<AiCreditsStatus | null>(null);
	const [isLoading, setIsLoading] = useState(true);

	const loadCached = useCallback(() => {
		return getApiResponse<AiCreditsStatus>(
			getApiLink(vulopilotAppLocalizer, 'ai-credits/status'),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		).then((response) => {
			if (response) {
				setStatus(response);
			}
			return response;
		});
	}, []);

	// The live sync - the only call that asks VuloCloud for the real balance. A failed sync leaves `status` as whatever was last shown.
	const refresh = useCallback(() => {
		setIsLoading(true);
		return sendApiResponse<AiCreditsStatus>(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, 'ai-credits/refresh-balance'),
			{}
		)
			.then((response) => response && setStatus(response))
			.finally(() => setIsLoading(false));
	}, []);

	useEffect(() => {
		// Paint instantly from cache, then resync for real. Skipped when never connected - refresh-balance always 400s then.
		loadCached().then((cached) => {
			if (cached?.connected) {
				refresh();
			} else {
				setIsLoading(false);
			}
		});
	}, [loadCached, refresh]);

	return { status, isLoading, refresh };
};

/** Credits are fractional: always shown to 3 decimals ("76.550"). */
export const formatCredits = (value: number | null | undefined): string =>
	(value ?? 0).toLocaleString(undefined, {
		minimumFractionDigits: 3,
		maximumFractionDigits: 3,
	});
