/* global vulopilotAppLocalizer */
import React, { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import {
	NoticeComponent,
	NoticeManager,
	PopupComponent,
} from '@zyra/components';
import { ButtonInput, ToggleInput, SelectInput } from '@zyra/inputs';
import CardHeader from '../CardHeader';
import ShowProPopup from '../Popup/Popup';
import { useSetting } from '../../contexts/SettingContext';
import { formatWpDate } from '../../services/formatWpDate';
import {
	useGoogleServicesConnection,
	GoogleServicesStatus,
} from '../../services/useGoogleServicesConnection';

interface GscSite {
	site_url: string;
	permission_level: string;
}

interface Ga4Property {
	property_id: string;
	property_name: string;
}

interface Ga4Account {
	account_id: string;
	account_name: string;
	properties: Ga4Property[];
}

interface Ga4DataStream {
	data_stream_id: string;
	display_name: string;
	measurement_id: string;
}

interface AdSenseAccount {
	account_id: string;
	display_name: string;
}

interface TestResults {
	search_console: boolean;
	analytics: boolean;
	adsense: boolean;
}

const nonceHeaders = { headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } };

const BENEFITS = [
	__( 'Verify site ownership on Google Search Console in a single click.', 'vulopilot' ),
	__( 'See real Search Console properties without leaving your WP dashboard.', 'vulopilot' ),
	__( 'Set up Google Analytics (GA4) tracking without another 3rd-party plugin.', 'vulopilot' ),
	__( 'Read your real AdSense account, if this site has one.', 'vulopilot' ),
];

const TEST_RESULT_LABEL: Record<keyof TestResults, string> = {
	search_console: __( 'Search Console', 'vulopilot' ),
	analytics: __( 'Analytics', 'vulopilot' ),
	adsense: __( 'AdSense', 'vulopilot' ),
};

interface GoogleServiceCardProps {
	icon: string;
	title: string;
	desc: string;
	isConnected: boolean;
	statusLines: React.ReactNode;
	manageLabel: string;
	onManage: () => void;
	isMenuOpen: boolean;
	onToggleMenu: () => void;
	onTest: () => void;
	onReconnect: () => void;
	onDisconnect: () => void;
	isDisconnecting: boolean;
	isExpanded: boolean;
	children?: React.ReactNode;
}

/**
 * One Search Console/Analytics/AdSense summary card - the mockup's own
 * per-service layout (status badge, Property/account summary line,
 * "Manage Connection"/"Connect AdSense" button, ⋮ menu). All 3 services
 * share ONE real Google OAuth connection (GoogleServicesConnection's own
 * docblock - a single consent screen covering all three read scopes at
 * once), so "Test Connection"/"Reconnect"/"Disconnect" in this card's own
 * ⋮ menu are real, but honestly scoped: disconnecting from any one card
 * disconnects the whole Google account, same as the confirm dialog says.
 */
const GoogleServiceCard = ( {
	icon,
	title,
	desc,
	isConnected,
	statusLines,
	manageLabel,
	onManage,
	isMenuOpen,
	onToggleMenu,
	onTest,
	onReconnect,
	onDisconnect,
	isDisconnecting,
	isExpanded,
	children,
}: GoogleServiceCardProps ) => (
	<CardHeader
		className="gsc-service-card"
		icon={ icon }
		title={ title }
		desc={ desc }
		action={
			<span className={ `admin-badge ${ isConnected ? 'green' : 'red' }` }>
				{ isConnected ? __( 'Connected', 'vulopilot' ) : __( 'Not Connected', 'vulopilot' ) }
			</span>
		}
	>

		<div className="ai-provider-card-body gsc-service-body">
			<div className="gsc-service-info">{ statusLines }</div>
			<div className="gsc-service-actions">
				<ButtonInput
					buttons={ {
						text: manageLabel,
						icon: isConnected ? 'admin-links' : undefined,
						color: isConnected ? 'border-purple' : undefined,
						onClick: onManage,
					} }
				/>
				<div className="gsc-service-menu">
					<button
						type="button"
						className="ai-provider-inline-action gsc-service-menu-trigger"
						onClick={ onToggleMenu }
						aria-label={ __( 'More actions', 'vulopilot' ) }
					>
						<i className="adminfont-more-vertical" />
					</button>
					{ isMenuOpen && (
						<div className="gsc-service-menu-dropdown">
							<button type="button" onClick={ onTest }>
								{ __( 'Test Connection', 'vulopilot' ) }
							</button>
							<button type="button" onClick={ onReconnect }>
								{ __( 'Reconnect Google Account', 'vulopilot' ) }
							</button>
							<button type="button" className="is-destructive" onClick={ onDisconnect } disabled={ isDisconnecting }>
								{ isDisconnecting
									? __( 'Disconnecting…', 'vulopilot' )
									: __( 'Disconnect Google Account', 'vulopilot' ) }
							</button>
						</div>
					) }
				</div>
			</div>
		</div>

		{ isExpanded && <div className="ai-provider-card-body gsc-service-expand">{ children }</div> }
	</CardHeader>
);

/**
 * Settings → Connections → Google Services.
 *
 * Moved from the old Settings → Scanning → Google Services tab per direct
 * instruction, alongside AI Providers/Webhooks/External Services - same
 * "folder of sub-tab files" shape Settings/GetStarted/'s own AiProviders.ts
 * establishes. Redesigned to match a mockup: one summary card per service
 * (Search Console/Analytics/AdSense) instead of the previous always-open
 * stacked cards - "Manage Connection" expands the exact same real
 * pickers/toggles that used to always be visible, just collapsed by
 * default now. All real state/handlers below are unchanged from the
 * original panel (same `useGoogleServicesConnection('settings')` hook,
 * same REST calls) - only the layout wrapping them changed.
 *
 * One click, nothing to configure: VuloPilot ships with its own shared
 * Google Cloud OAuth Client (VULOPILOT_GOOGLE_CLIENT_ID/SECRET, see
 * config.php's own docblock) - a site owner never sees or enters a
 * Client ID/Secret. `GoogleServicesConnection::get_authorization_url()`
 * (PHP) actually has 2 real ways to complete this: the embedded shared
 * Client above, OR routing through VuloLabs' own OAuth broker
 * (`status.has_broker` - needs no embedded Client ID/Secret at all, tried
 * FIRST server-side). The button below is only replaced with the honest
 * "not available yet" state when NEITHER is configured for this build
 * (`!status.has_client_credentials && !status.has_broker`) - checking
 * `has_client_credentials` alone was a real bug (fixed per direct report):
 * it showed "not available yet" even on a working broker-only build,
 * since `has_broker` was never read here at all.
 */
const GoogleServicesPanel = () => {
	const { setting, updateSetting } = useSetting();

	const {
		status,
		setStatus,
		isLoading,
		isConnecting,
		isDisconnecting,
		connect: handleConnect,
		disconnect: handleDisconnect,
	} = useGoogleServicesConnection( 'settings' );

	const [ testResults, setTestResults ] = useState<TestResults | null>( null );

	const [ gscSites, setGscSites ] = useState<GscSite[] | null>( null );
	const [ ga4Accounts, setGa4Accounts ] = useState<Ga4Account[] | null>( null );
	const [ ga4Streams, setGa4Streams ] = useState<Ga4DataStream[] | null>( null );
	const [ adsenseAccounts, setAdsenseAccounts ] = useState<AdSenseAccount[] | null>( null );

	const [ selectedAccountId, setSelectedAccountId ] = useState( '' );
	const [ selectedPropertyId, setSelectedPropertyId ] = useState( '' );

	const [ expandedCard, setExpandedCard ] = useState<string | null>( null );
	const [ openMenu, setOpenMenu ] = useState<string | null>( null );
	/** Shown via the `confirmMode` popup below instead of `window.confirm()`. */
	const [ confirmDisconnectOpen, setConfirmDisconnectOpen ] = useState( false );

	const installTrackingCode = ( ( setting.ga_install_tracking_code as string[] ) || [] ).length > 0;
	const anonymizeIp = ( ( setting.ga_anonymize_ip as string[] ) || [] ).length > 0;
	const selfHostedJs = ( ( setting.ga_self_hosted_js as string[] ) || [] ).length > 0;
	const excludeLoggedInUsers = ( ( setting.ga_exclude_logged_in_users as string[] ) || [] ).length > 0;

	const toggleSetting = ( key: string, isOn: boolean ) => {
		const newValue = isOn ? [] : [ key ];
		updateSetting( key, newValue );
		sendApiResponse( vulopilotAppLocalizer, getApiLink( vulopilotAppLocalizer, 'settings' ), {
			setting: { [ key ]: newValue },
		} );
	};

	// Once connected, real per-service pickers load their own real data.
	useEffect( () => {
		if ( ! status?.connected ) {
			return;
		}

		if ( null === gscSites && ! status.search_console_site ) {
			getApiResponse<GscSite[]>(
				getApiLink( vulopilotAppLocalizer, 'google-services/search-console-sites' ),
				nonceHeaders
			).then( ( response ) => setGscSites( response ?? [] ) );
		}

		if ( null === ga4Accounts ) {
			getApiResponse<Ga4Account[]>(
				getApiLink( vulopilotAppLocalizer, 'google-services/analytics-accounts' ),
				nonceHeaders
			).then( ( response ) => {
				setGa4Accounts( response ?? [] );
				if ( status.ga4_account_id ) {
					setSelectedAccountId( status.ga4_account_id );
					setSelectedPropertyId( status.ga4_property_id );
				}
			} );
		}

		if ( null === adsenseAccounts ) {
			getApiResponse<AdSenseAccount[]>(
				getApiLink( vulopilotAppLocalizer, 'google-services/adsense-accounts' ),
				nonceHeaders
			).then( ( response ) => setAdsenseAccounts( response ?? [] ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status?.connected ] );

	// Real data streams load whenever the selected property changes.
	useEffect( () => {
		if ( ! selectedPropertyId ) {
			setGa4Streams( null );
			return;
		}

		getApiResponse<Ga4DataStream[]>(
			getApiLink(
				vulopilotAppLocalizer,
				`google-services/analytics-data-streams?property_id=${ encodeURIComponent( selectedPropertyId ) }`
			),
			nonceHeaders
		).then( ( response ) => setGa4Streams( response ?? [] ) );
	}, [ selectedPropertyId ] );

	/** Opens the `confirmMode` popup - the actual disconnect runs from `confirmDisconnect` once the user confirms there. */
	const disconnectAndResetPickers = () => {
		setConfirmDisconnectOpen( true );
	};

	const confirmDisconnect = () => {
		setConfirmDisconnectOpen( false );

		handleDisconnect().then( () => {
			setGscSites( null );
			setGa4Accounts( null );
			setGa4Streams( null );
			setAdsenseAccounts( null );
			setSelectedAccountId( '' );
			setSelectedPropertyId( '' );
			setTestResults( null );
		} );
		setOpenMenu( null );
	};

	const handleTestConnections = ( onlyService?: keyof TestResults ) => {
		getApiResponse<TestResults>(
			getApiLink( vulopilotAppLocalizer, 'google-services/test-connections' ),
			nonceHeaders
		).then( ( response ) => {
			if ( ! response ) {
				return;
			}

			setTestResults( response );

			if ( onlyService ) {
				NoticeManager.add( {
					uniqueKey: `gsc-test-${ onlyService }`,
					type: response[ onlyService ] ? 'success' : 'error',
					position: 'float',
					message: response[ onlyService ]
						? __( 'Connection OK.', 'vulopilot' )
						: __( 'This service isn’t responding - try reconnecting.', 'vulopilot' ),
				} );
			}
		} );
		setOpenMenu( null );
	};

	const handleSelectSite = ( siteUrl: string ) => {
		sendApiResponse<GoogleServicesStatus>(
			vulopilotAppLocalizer,
			getApiLink( vulopilotAppLocalizer, 'google-services/select-search-console-site' ),
			{ site_url: siteUrl }
		).then( ( response ) => {
			if ( response ) {
				setStatus( response );
			}
		} );
	};

	const handleSelectAdsense = ( accountId: string ) => {
		sendApiResponse<GoogleServicesStatus>(
			vulopilotAppLocalizer,
			getApiLink( vulopilotAppLocalizer, 'google-services/select-adsense-account' ),
			{ account_id: accountId }
		).then( ( response ) => {
			if ( response ) {
				setStatus( response );
			}
		} );
	};

	const handleSelectDataStream = ( dataStreamId: string ) => {
		if ( ! selectedAccountId || ! selectedPropertyId ) {
			return;
		}

		sendApiResponse<GoogleServicesStatus>(
			vulopilotAppLocalizer,
			getApiLink( vulopilotAppLocalizer, 'google-services/select-analytics-property' ),
			{
				account_id: selectedAccountId,
				property_id: selectedPropertyId,
				data_stream_id: dataStreamId,
			}
		).then( ( response ) => {
			if ( response ) {
				setStatus( response );
			}
		} );
	};

	const selectedAccount = ga4Accounts?.find( ( account ) => account.account_id === selectedAccountId );

	if ( isLoading || ! status ) {
		return <div className="desc">{ __( 'Loading…', 'vulopilot' ) }</div>;
	}

	if ( ! status.connected ) {
		return (
			<>
				{ ! status.has_client_credentials && ! status.has_broker ? (
					<CardHeader
						icon="error red"
						title={ __( 'Google Connect isn’t available yet', 'vulopilot' ) }
						desc={ __(
							'This build doesn’t have a Google Cloud OAuth Client configured yet - that’s a one-time setup VuloLabs does, not something you configure. Flag if you’re seeing this on a real release.',
							'vulopilot'
						) }
						action={
							<ButtonInput
								buttons={ {
									text: __( 'Connect', 'vulopilot' ),
									icon: 'link',
									color: 'purple-bg',
									onClick: () => {},
								} }
							/>
						}
					/>
				) : (
					<CardHeader
						icon="check green"
						title={ __( 'Benefits of connecting your Google account', 'vulopilot' ) }
						desc={ __(
							'We don’t store any of your Google account’s data on our servers - everything is processed and stored on your own site. Tokens are encrypted at rest the same way every other API key in VuloPilot is.',
							'vulopilot'
						) }
						action={
							<ButtonInput
								buttons={ {
									text: isConnecting ? __( 'Redirecting…', 'vulopilot' ) : __( 'Connect Google Services', 'vulopilot' ),
									icon: 'link',
									onClick: handleConnect,
									disabled: isConnecting,
								} }
							/>
						}
					>
						<ul className="gsc-benefits-list">
							{ BENEFITS.map( ( benefit ) => (
								<li key={ benefit }>
									<i className="adminfont-check" /> { benefit }
								</li>
							) ) }
						</ul>
					</CardHeader>
				) }
			</>
		);
	}

	const connectedSince = status.connected_at ? formatWpDate( status.connected_at ) : null;

	return (
		<>
			<GoogleServiceCard
				icon="search-discovery"
				title={ __( 'Search Console', 'vulopilot' ) }
				desc={ __( "Monitor your site's search performance and indexing coverage.", 'vulopilot' ) }
				isConnected={ !! status.search_console_site }
				statusLines={
					status.search_console_site ? (
						<>
							<div>
								{ __( 'Property:', 'vulopilot' ) } <strong>{ status.search_console_site }</strong>
							</div>
							{ connectedSince && (
								<div className="desc">{ __( 'Connected:', 'vulopilot' ) } { connectedSince }</div>
							) }
						</>
					) : (
						<div className="desc">
							{ __( 'Choose a verified property to finish connecting Search Console.', 'vulopilot' ) }
						</div>
					)
				}
				manageLabel={ __( 'Manage Connection', 'vulopilot' ) }
				onManage={ () => setExpandedCard( expandedCard === 'gsc' ? null : 'gsc' ) }
				isMenuOpen={ openMenu === 'gsc' }
				onToggleMenu={ () => setOpenMenu( openMenu === 'gsc' ? null : 'gsc' ) }
				onTest={ () => handleTestConnections( 'search_console' ) }
				onReconnect={ handleConnect }
				onDisconnect={ disconnectAndResetPickers }
				isDisconnecting={ isDisconnecting }
				isExpanded={ expandedCard === 'gsc' }
			>
				{ status.search_console_site ? (
					<div className="desc">
						{ __( 'Already connected. Disconnect and reconnect to choose a different property.', 'vulopilot' ) }
					</div>
				) : (
					<div className="gsc-site-picker">
						<div className="desc">{ __( 'Choose which verified property to use:', 'vulopilot' ) }</div>
						{ null === gscSites && <div className="desc">{ __( 'Loading…', 'vulopilot' ) }</div> }
						{ gscSites && 0 === gscSites.length && (
							<div className="desc">
								{ __( 'No verified Search Console properties found on this Google account.', 'vulopilot' ) }
							</div>
						) }
						{ gscSites?.map( ( site ) => (
							<button
								key={ site.site_url }
								type="button"
								className="gsc-site-option"
								onClick={ () => handleSelectSite( site.site_url ) }
							>
								{ site.site_url }
							</button>
						) ) }
					</div>
				) }
			</GoogleServiceCard>

			<GoogleServiceCard
				icon="bar-chart"
				title={ __( 'Analytics', 'vulopilot' ) }
				desc={ __( 'Track visitor behavior and understand how users engage with your site.', 'vulopilot' ) }
				isConnected={ !! status.ga4_measurement_id }
				statusLines={
					status.ga4_measurement_id ? (
						<>
							<div>
								{ __( 'Property:', 'vulopilot' ) } <strong>{ status.ga4_property_name }</strong>
							</div>
							<div className="desc">
								{ __( 'View ID:', 'vulopilot' ) } <code>{ status.ga4_measurement_id }</code>
							</div>
						</>
					) : (
						<div className="desc">
							{ __( 'Choose an account, property, and data stream to finish connecting Analytics.', 'vulopilot' ) }
						</div>
					)
				}
				manageLabel={ __( 'Manage Connection', 'vulopilot' ) }
				onManage={ () => setExpandedCard( expandedCard === 'analytics' ? null : 'analytics' ) }
				isMenuOpen={ openMenu === 'analytics' }
				onToggleMenu={ () => setOpenMenu( openMenu === 'analytics' ? null : 'analytics' ) }
				onTest={ () => handleTestConnections( 'analytics' ) }
				onReconnect={ handleConnect }
				onDisconnect={ disconnectAndResetPickers }
				isDisconnecting={ isDisconnecting }
				isExpanded={ 'analytics' === expandedCard }
			>
				<div className="gsc-select-row">
					<SelectInput
						name="ga4_account"
						placeholder={ __( 'Account', 'vulopilot' ) }
						value={ selectedAccountId }
						options={ ( ga4Accounts ?? [] ).map( ( account ) => ( {
							label: account.account_name,
							value: account.account_id,
						} ) ) }
						onChange={ ( value ) => {
							setSelectedAccountId( value as string );
							setSelectedPropertyId( '' );
						} }
						size="14rem"
					/>
					<SelectInput
						name="ga4_property"
						placeholder={ __( 'Property', 'vulopilot' ) }
						value={ selectedPropertyId }
						options={ ( selectedAccount?.properties ?? [] ).map( ( property ) => ( {
							label: property.property_name,
							value: property.property_id,
						} ) ) }
						onChange={ ( value ) => setSelectedPropertyId( value as string ) }
						size="14rem"
					/>
					<SelectInput
						name="ga4_data_stream"
						placeholder={ __( 'Data Stream', 'vulopilot' ) }
						value={ status.ga4_measurement_id }
						options={ ( ga4Streams ?? [] ).map( ( stream ) => ( {
							label: `${ stream.display_name } (${ stream.measurement_id })`,
							value: stream.data_stream_id,
						} ) ) }
						onChange={ ( value ) => handleSelectDataStream( value as string ) }
						size="16rem"
					/>
				</div>

				<div className="gsc-toggle-row">
					<ToggleInput
						options={ [
							{
								key: 'ga_install_tracking_code',
								value: 'ga_install_tracking_code',
								label: __( 'Install analytics code', 'vulopilot' ),
							},
						] }
						value={ installTrackingCode ? [ 'ga_install_tracking_code' ] : [] }
						multiSelect
						modules={ [] }
						onChange={ () => toggleSetting( 'ga_install_tracking_code', installTrackingCode ) }
					/>
					<div className="desc">
						{ __(
							'Outputs a real gtag.js snippet on the frontend for the selected property. Only takes effect once a Data Stream is selected above.',
							'vulopilot'
						) }
					</div>
				</div>

				{ installTrackingCode && (
					<div className="gsc-sub-toggles">
						{ (
							[
								[ 'ga_anonymize_ip', __( 'Anonymize IP addresses', 'vulopilot' ), anonymizeIp ],
								[ 'ga_self_hosted_js', __( 'Self-Hosted Analytics JS File', 'vulopilot' ), selfHostedJs ],
								[ 'ga_exclude_logged_in_users', __( 'Exclude logged-in users', 'vulopilot' ), excludeLoggedInUsers ],
							] as const
						).map( ( [ key, label, isOn ] ) => (
							<ToggleInput
								key={ key }
								options={ [ { key, value: key, label } ] }
								value={ isOn ? [ key ] : [] }
								multiSelect
								modules={ [] }
								onChange={ () => toggleSetting( key, isOn ) }
							/>
						) ) }
					</div>
				) }
			</GoogleServiceCard>

			<GoogleServiceCard
				icon="dollar"
				title={ __( 'AdSense', 'vulopilot' ) }
				desc={ __( 'Connect your AdSense account inside VuloPilot.', 'vulopilot' ) }
				isConnected={ !! status.adsense_account_name }
				statusLines={
					status.adsense_account_name ? (
						<div>
							{ __( 'Account:', 'vulopilot' ) } <strong>{ status.adsense_account_name }</strong>
						</div>
					) : (
						<div className="desc">
							{ __(
								// Deliberately doesn't promise "ad performance and earnings" -
								// GoogleAdSenseClient only ever lists real account names,
								// no real earnings/ad-unit data (that class's own docblock).
								'Connect AdSense to link your account. Ad performance and earnings reporting aren’t built yet.',
								'vulopilot'
							) }
						</div>
					)
				}
				manageLabel={
					status.adsense_account_name ? __( 'Manage Connection', 'vulopilot' ) : __( 'Connect AdSense', 'vulopilot' )
				}
				onManage={ () => setExpandedCard( expandedCard === 'adsense' ? null : 'adsense' ) }
				isMenuOpen={ openMenu === 'adsense' }
				onToggleMenu={ () => setOpenMenu( openMenu === 'adsense' ? null : 'adsense' ) }
				onTest={ () => handleTestConnections( 'adsense' ) }
				onReconnect={ handleConnect }
				onDisconnect={ disconnectAndResetPickers }
				isDisconnecting={ isDisconnecting }
				isExpanded={ 'adsense' === expandedCard }
			>
				{ status.adsense_account_name ? (
					<div className="desc">
						{ __( 'Already connected. Disconnect and reconnect to choose a different account.', 'vulopilot' ) }
					</div>
				) : (
					<>
						{ null === adsenseAccounts && <div className="desc">{ __( 'Loading…', 'vulopilot' ) }</div> }
						{ adsenseAccounts && 0 === adsenseAccounts.length && (
							<div className="desc">
								{ __(
									'No AdSense account found on this Google account - that’s fine, AdSense is optional.',
									'vulopilot'
								) }
							</div>
						) }
						{ adsenseAccounts?.map( ( account ) => (
							<button
								key={ account.account_id }
								type="button"
								className="gsc-site-option"
								onClick={ () => handleSelectAdsense( account.account_id ) }
							>
								{ account.display_name }
							</button>
						) ) }
					</>
				) }
			</GoogleServiceCard>

			{ testResults && (
				<div className="gsc-test-results">
					{ (
						[ 'search_console', 'analytics', 'adsense' ] as const
					).map( ( key ) => (
						<span
							key={ key }
							className={ `ai-provider-connection-status ${ testResults[ key ] ? 'is-success' : 'is-error' }` }
						>
							{ TEST_RESULT_LABEL[ key ] }: { testResults[ key ] ? __( 'OK', 'vulopilot' ) : __( 'Failed', 'vulopilot' ) }
						</span>
					) ) }
				</div>
			) }

			<NoticeComponent
				displayPosition="inline"
				message={ __(
					'VuloPilot only reads data from your Google services. We never make changes to your account. We don’t store any of your Google account’s data on our servers - everything is processed and stored on your own site.',
					'vulopilot'
				) }
			/>

			<div className="ui-notice type-info display-notice">
				<i className="admin-font adminfont-info" />
				<div className="notice-details">
					<div className="notice-desc">
						{ __(
							'Connecting and selecting a property only proves this site can read your real Google data. Storing/reporting on that data over time - the Analytics Database, Frontend Stats Bar, Email Reports, and pulling real ranking keywords onto the Keywords tab - is the next step, not built yet. Flag if you want any of it scoped next.',
							'vulopilot'
						) }
					</div>
				</div>
			</div>

			<PopupComponent
				position="lightbox"
				open={ confirmDisconnectOpen }
				onClose={ () => setConfirmDisconnectOpen( false ) }
				width={ 31.25 }
				height="auto"
			>
				<ShowProPopup
					confirmMode
					title={ __( 'Disconnect Google Account', 'vulopilot' ) }
					confirmMessage={ __(
						'Disconnect your Google account? This affects Search Console, Analytics, and AdSense all at once - they share one connection.',
						'vulopilot'
					) }
					confirmYesText={ __( 'Disconnect', 'vulopilot' ) }
					confirmNoText={ __( 'Cancel', 'vulopilot' ) }
					onConfirm={ confirmDisconnect }
					onCancel={ () => setConfirmDisconnectOpen( false ) }
				/>
			</PopupComponent>
		</>
	);
};

export default GoogleServicesPanel;
