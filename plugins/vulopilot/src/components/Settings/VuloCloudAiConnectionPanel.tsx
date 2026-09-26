/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import {
	FormGroupWrapperComponent,
	NoticeManager,
	PopupComponent,
} from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import ShowProPopup from '../Popup/Popup';
import CardHeader from '../CardHeader';

interface VuloCloudStatus {
	connected: boolean;
	configured: boolean;
}

interface VuloCloudAiConnectionResponse {
	vulocloud_status: VuloCloudStatus;
}

const nonceHeaders = { headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } };

const VuloCloudAiConnectionPanel = () => {
	const [vulocloudStatus, setVulocloudStatus] = useState<VuloCloudStatus>({
		connected: false,
		configured: false,
	});
	const [isConnectingToVulocloud, setIsConnectingToVulocloud] = useState(false);
	const [isDisconnectingFromVulocloud, setIsDisconnectingFromVulocloud] = useState(false);
	const [showVulocloudDisconnectConfirm, setShowVulocloudDisconnectConfirm] = useState(false);
	const [isLoading, setIsLoading] = useState(true);

	const load = () => {
		setIsLoading(true);

		getApiResponse<VuloCloudAiConnectionResponse>(
			getApiLink(vulopilotAppLocalizer, 'vulocloud-ai-connection'),
			nonceHeaders
		)
			.then((response) => {
				if (!response) {
					return;
				}

				setVulocloudStatus(response.vulocloud_status);
			})
			.finally(() => setIsLoading(false));
	};

	useEffect(load, []);

	// ConnectBrokerCallbackHandler.php's own redirect lands back on this
	// exact URL carrying `connect_status=connected|error` as a real signal
	// - same `?_status=` redirect-flag handling
	// useGoogleServicesConnection.ts's own hook already establishes for
	// the Google Connect broker.
	useEffect(() => {
		const params = new URLSearchParams(
			window.location.hash.split('?')[1] || window.location.hash.substring(1)
		);
		const connectStatus = params.get('connect_status');

		if ('connected' === connectStatus) {
			NoticeManager.add({
				uniqueKey: 'vulopilot-connect-broker-connected',
				type: 'success',
				position: 'float',
				message: __('Connected to VuloCloud.', 'vulopilot'),
			});
		} else if ('error' === connectStatus) {
			NoticeManager.add({
				uniqueKey: 'vulopilot-connect-broker-failed',
				type: 'error',
				position: 'float',
				message: __('Could not connect to VuloCloud. Please try again.', 'vulopilot'),
			});
		}
	}, []);

	const handleConnectToVulocloud = () => {
		setIsConnectingToVulocloud(true);

		getApiResponse<{ url: string }>(
			getApiLink(vulopilotAppLocalizer, 'vulocloud-ai-connection/broker-authorize-url'),
			nonceHeaders
		)
			.then((response) => {
				if (response?.url) {
					window.location.href = response.url;
					return;
				}

				setIsConnectingToVulocloud(false);
				NoticeManager.add({
					uniqueKey: 'vulopilot-connect-broker-unavailable',
					type: 'error',
					position: 'float',
					message: __('VuloCloud isn’t configured for this build yet.', 'vulopilot'),
				});
			})
			.catch(() => setIsConnectingToVulocloud(false));
	};

	const handleDisconnectFromVulocloud = () => {
		setShowVulocloudDisconnectConfirm(true);
	};

	const handleConfirmDisconnectVulocloud = () => {
		setShowVulocloudDisconnectConfirm(false);
		setIsDisconnectingFromVulocloud(true);

		sendApiResponse(vulopilotAppLocalizer, getApiLink(vulopilotAppLocalizer, 'ai-credits/disconnect'), {})
			.then((response) => {
				NoticeManager.add({
					uniqueKey: 'vulopilot-vulocloud-disconnected',
					type: response ? 'success' : 'error',
					position: 'float',
					message: response
						? __('Disconnected from VuloCloud.', 'vulopilot')
						: __('Could not disconnect from VuloCloud.', 'vulopilot'),
				});

				if (response) {
					load();
				}
			})
			.finally(() => setIsDisconnectingFromVulocloud(false));
	};

	return (
		<>
			<FormGroupWrapperComponent>
				{isLoading ? (
					<div className="desc">{__('Loading…', 'vulopilot')}</div>
				) : (
					<CardHeader
						icon="ai orange"
						title={__('VuloCloud AI', 'vulopilot')}
						desc={
							!vulocloudStatus.connected
								? __(
										'Connect this site to VuloCloud to use the AI key managed by your Organization.',
										'vulopilot'
									)
								: vulocloudStatus.configured
									? __(
											'Connected - AI requests run on your Organization’s AI key and use your AI credits.',
											'vulopilot'
										)
									: __(
											'Connected to VuloCloud, but your Organization hasn’t configured an AI key yet. Ask your Organization to add one.',
											'vulopilot'
										)
						}
						badge={
							<span
								className={`admin-badge ${
									!vulocloudStatus.connected ? 'red' : vulocloudStatus.configured ? 'green' : 'orange'
								}`}
							>
								{!vulocloudStatus.connected
									? __('Not Connected', 'vulopilot')
									: vulocloudStatus.configured
										? __('Connected', 'vulopilot')
										: __('Awaiting AI key', 'vulopilot')}
							</span>
						}
						action={
							!vulocloudStatus.connected ? (
								<ButtonInput
									buttons={{
										text: isConnectingToVulocloud
											? __('Connecting…', 'vulopilot')
											: __('Connect to VuloCloud', 'vulopilot'),
											icon: 'link',
											color: 'orange-bg',
										disabled: isConnectingToVulocloud,
										onClick: handleConnectToVulocloud,
									}}
								/>
							) : (
								<ButtonInput
									buttons={{
										text: isDisconnectingFromVulocloud
											? __('Disconnecting…', 'vulopilot')
											: __('Disconnect', 'vulopilot'),
											icon: 'close',
											color: 'text-red',
										disabled: isDisconnectingFromVulocloud,
										onClick: handleDisconnectFromVulocloud,
									}}
								/>
							)
						}
					/>
				)}
			</FormGroupWrapperComponent>
			<PopupComponent
				position="lightbox"
				open={showVulocloudDisconnectConfirm}
				onClose={() => setShowVulocloudDisconnectConfirm(false)}
				width={31.25}
				height="auto"
			>
				<ShowProPopup
					confirmMode
					title={__('Disconnect VuloCloud', 'vulopilot')}
					confirmMessage={__(
						'Disconnect this site from VuloCloud? AI features that rely on your Organization’s key will stop working until you connect again.',
						'vulopilot'
					)}
					confirmYesText={__('Disconnect', 'vulopilot')}
					confirmNoText={__('Cancel', 'vulopilot')}
					onConfirm={handleConfirmDisconnectVulocloud}
					onCancel={() => setShowVulocloudDisconnectConfirm(false)}
				/>
			</PopupComponent>
		</>
	);
};

export default VuloCloudAiConnectionPanel;
