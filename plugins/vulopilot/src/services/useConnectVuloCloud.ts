/* global vulopilotAppLocalizer */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { NoticeManager } from '@zyra/components';

/**
 * The real "Connect to VuloCloud" redirect (`GET /vulocloud-ai-connection/broker-
 * authorize-url`, AiCreditsConnection's own docblock) - same passwordless
 * broker flow AiCreditsIndicator.tsx's own dropdown and Settings → AI
 * Providers already use, extracted here so any other "no AI service
 * configured" recovery UI (`ShowProPopup vulocloud`/`VuloCloudInlineNotice` in Popup.tsx, ContentToolPopup.tsx's
 * own inline error step) can offer the exact same real connect action
 * without duplicating the fetch/notice/loading-state wiring.
 */
export const useConnectVuloCloud = () => {
	const [isConnecting, setIsConnecting] = useState(false);

	const handleConnect = () => {
		setIsConnecting(true);

		// Opened now (sync, inside the click) so popup blockers allow it; navigated once the URL arrives.
		const connectWindow = window.open('', '_blank');
		if (connectWindow) {
			connectWindow.opener = null;
		}

		getApiResponse<{ url: string }>(
			getApiLink(vulopilotAppLocalizer, 'vulocloud-ai-connection/broker-authorize-url'),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => {
				setIsConnecting(false);

				if (response?.url) {
					if (connectWindow) {
						connectWindow.location.href = response.url;
					} else {
						window.open(response.url, '_blank', 'noopener,noreferrer');
					}
					return;
				}

				connectWindow?.close();
				NoticeManager.add({
					uniqueKey: 'vulopilot-connect-broker-unavailable',
					type: 'error',
					position: 'float',
					message: __('VuloCloud isn’t configured for this build yet.', 'vulopilot'),
				});
			})
			.catch(() => {
				connectWindow?.close();
				setIsConnecting(false);
			});
	};

	return { isConnecting, handleConnect };
};
