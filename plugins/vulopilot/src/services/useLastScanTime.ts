/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { getApiLink, getApiResponse } from '@zyra/core';

interface ScanRow {
	finished_at: string | null;
}

/**
 * The most recent completed scan run's `finished_at` for one scanner group.
 *
 * @param scannerIds Scanner ids to scope to, or omit for site-wide.
 * @param categories Category ids to scope to (e.g. `['security']`), resolved to their
 *                    own scanner ids server-side. Merges with `scannerIds` when both are given.
 */
export const useLastScanTime = (
	scannerIds?: string[],
	categories?: string[]
): { lastScanAt: string | null; isLoading: boolean } => {
	const [lastScanAt, setLastScanAt] = useState<string | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const scannerIdKey = scannerIds?.join(',') ?? '';
	const categoryKey = categories?.join(',') ?? '';

	useEffect(() => {
		setIsLoading(true);

		let url =
			getApiLink(vulopilotAppLocalizer, 'scans') +
			'?status=completed&per_page=1&orderby=finished_at&order=desc';

		if (scannerIdKey) {
			url += `&scanner_id=${encodeURIComponent(scannerIdKey)}`;
		}

		if (categoryKey) {
			url += `&category=${encodeURIComponent(categoryKey)}`;
		}

		getApiResponse<{ data: ScanRow[] }>(url, {
			headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce },
		})
			.then((response) => {
				setLastScanAt(response?.data?.[0]?.finished_at ?? null);
			})
			.finally(() => setIsLoading(false));
	}, [scannerIdKey, categoryKey]);

	return { lastScanAt, isLoading };
};
