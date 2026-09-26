/* global vulopilotAppLocalizer */
import { useCallback, useEffect, useState } from 'react';
import { getApiLink, getApiResponse } from '@zyra/core';

/**
 * `GET /ai-credits/status` (AiCredits::get_status(), this plugin's own
 * `classes/RestAPI/Controllers/`) - the real, live AI Credits balance
 * (architecture plan: "WordPress may cache/display the balance, but it
 * must never be considered the source of truth" - this hook always
 * reflects what THIS site's own local cache last synced from VuloCloud,
 * itself synced from VuloCloud's own authoritative wallet).
 */
export interface AiCreditsStatus {
	connected: boolean;
	/** Fractional - exactly what VuloCloud last reported. */
	credits: number;
	lifetime_earned: number;
	lifetime_used: number;
	/** VuloCloud's own AI Credits page (Buy Credits) - '' until first synced. */
	buy_credits_url: string;
	connected_at: string;
	last_synced_at: string;
	vulocloud_account_connected: boolean;
	vulocloud_account_email: string;
}

export const useAiCredits = () => {
	const [status, setStatus] = useState<AiCreditsStatus | null>(null);
	const [isLoading, setIsLoading] = useState(true);

	const refresh = useCallback(() => {
		setIsLoading(true);
		return getApiResponse<AiCreditsStatus>(
			getApiLink(vulopilotAppLocalizer, 'ai-credits/status'),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => response && setStatus(response))
			.finally(() => setIsLoading(false));
	}, []);

	useEffect(() => {
		refresh();
	}, [refresh]);

	return { status, isLoading, refresh };
};

/** Credits are fractional: always shown to 3 decimals ("76.550"). */
export const formatCredits = (value: number | null | undefined): string =>
	(value ?? 0).toLocaleString(undefined, {
		minimumFractionDigits: 3,
		maximumFractionDigits: 3,
	});

/** Where "Buy Credits" goes: VuloCloud's own AI Credits page when known. */
export const buyCreditsUrl = (status: Pick<AiCreditsStatus, 'buy_credits_url'> | null): string =>
	status?.buy_credits_url || appLocalizer.shop_url;
