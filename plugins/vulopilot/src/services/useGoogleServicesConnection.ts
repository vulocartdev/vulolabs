/* global vulopilotAppLocalizer */
import { useCallback, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import { NoticeManager } from '@zyra/components';

export interface GoogleServicesStatus {
	connected: boolean;
	has_client_credentials: boolean;
	/** Whether "Connect Google Services" will route through the OAuth broker instead - GoogleServicesConnection::get_authorization_url() (PHP) already tries this FIRST, so a build can be broker-only (`has_client_credentials` false) and still have a real, working connect flow. */
	has_broker: boolean;
	search_console_site: string;
	ga4_account_id: string;
	ga4_account_name: string;
	ga4_property_id: string;
	ga4_property_name: string;
	ga4_measurement_id: string;
	adsense_account_id: string;
	adsense_account_name: string;
	connected_at: string;
}

/**
 * Same real, allow-listed set GoogleServicesConnection.php's own
 * `RETURN_TARGETS` enforces server-side - kept in sync by hand since
 * there's no shared PHP/TS constant here, same as every other route-id
 * string literal this codebase already duplicates across the two sides.
 */
export type GoogleConnectReturnTo = 'settings' | 'keywords';

const nonceHeaders = { headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } };

/**
 * Shared real Google OAuth 2.0 status/connect/disconnect logic -
 * GoogleServicesConnection.php's own frontend counterpart, extracted out
 * of GoogleServicesPanel.tsx (Settings → Connections → Google Services, the
 * full Search Console + Analytics + AdSense picker) so KeywordsTab.tsx's
 * own inline "Connect Google Services" flow (SEO & Visibility → Keywords)
 * can reuse the exact same real handshake - same status shape, same REST
 * routes, same `gsc_status` redirect-flag handling - instead of a second,
 * hand-duplicated copy of this state machine. Neither caller fabricates
 * anything: every call here hits the same real routes
 * Controllers\GoogleServices registers.
 *
 * `returnTo` picks which allow-listed SPA tab Google's redirect
 * (GoogleSearchConsoleOAuthCallbackHandler.php, via
 * GoogleServicesConnection::get_return_to_from_state()) lands back on
 * once the handshake completes - so a connect started from Keywords
 * finishes on Keywords, not Settings.
 */
export const useGoogleServicesConnection = (
	returnTo: GoogleConnectReturnTo = 'settings'
) => {
	const [status, setStatus] = useState<GoogleServicesStatus | null>(null);
	const [isLoading, setIsLoading] = useState(true);
	const [isConnecting, setIsConnecting] = useState(false);
	const [isDisconnecting, setIsDisconnecting] = useState(false);

	const refreshStatus = useCallback(
		() =>
			getApiResponse<GoogleServicesStatus>(
				getApiLink(vulopilotAppLocalizer, 'google-services/status'),
				nonceHeaders
			).then((response) => {
				if (response) {
					setStatus(response);
				}
				return response;
			}),
		[]
	);

	useEffect(() => {
		setIsLoading(true);
		refreshStatus().finally(() => setIsLoading(false));

		// Google's own OAuth redirect lands back on this exact URL
		// (GoogleSearchConsoleOAuthCallbackHandler.php builds it) carrying
		// `gsc_status=connected|error` as a real signal, not a fabricated
		// success message.
		const params = new URLSearchParams(
			window.location.hash.split('?')[1] || window.location.hash.substring(1)
		);
		const gscStatus = params.get('gsc_status');

		if (gscStatus === 'connected') {
			NoticeManager.add({
				uniqueKey: 'vulopilot-gsc-connected',
				type: 'success',
				position: 'float',
				message: __('Connected to Google.', 'vulopilot'),
			});
		} else if (gscStatus === 'error') {
			NoticeManager.add({
				uniqueKey: 'vulopilot-gsc-connect-failed',
				type: 'error',
				position: 'float',
				message: __(
					'Could not connect to Google. Please check your Client ID/Secret and try again.',
					'vulopilot'
				),
			});
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const connect = useCallback(() => {
		setIsConnecting(true);

		getApiResponse<{ url: string }>(
			getApiLink(
				vulopilotAppLocalizer,
				`google-services/authorize-url?return_to=${returnTo}`
			),
			nonceHeaders
		)
			.then((response) => {
				if (response?.url) {
					// Real top-level handoff to Google's own consent
					// screen - not an XHR, so there's nothing to await
					// past this point; the redirect back through
					// admin-post.php replaces this page entirely.
					window.location.href = response.url;
					return;
				}

				NoticeManager.add({
					uniqueKey: 'vulopilot-gsc-authorize-url-failed',
					type: 'error',
					position: 'float',
					message: __(
						'Could not start the Google connection. Please try again.',
						'vulopilot'
					),
				});
				setIsConnecting(false);
			})
			.catch(() => setIsConnecting(false));
	}, [returnTo]);

	const disconnect = useCallback(
		() =>
			new Promise<void>((resolve) => {
				setIsDisconnecting(true);

				sendApiResponse<GoogleServicesStatus>(
					vulopilotAppLocalizer,
					getApiLink(vulopilotAppLocalizer, 'google-services/disconnect'),
					{}
				)
					.then((response) => {
						if (response) {
							setStatus(response);
						}
					})
					.finally(() => {
						setIsDisconnecting(false);
						resolve();
					});
			}),
		[]
	);

	return {
		status,
		setStatus,
		isLoading,
		isConnecting,
		isDisconnecting,
		connect,
		disconnect,
		refreshStatus,
	};
};
