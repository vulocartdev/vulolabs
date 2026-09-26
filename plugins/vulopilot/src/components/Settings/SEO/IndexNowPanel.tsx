/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import {
	CardComponent,
	FormGroupComponent,
	FormGroupWrapperComponent,
	NoticeComponent,
	NoticeManager
} from '@zyra/components';
import { ButtonInput, MultiCheckboxInput, TextAreaInput } from '@zyra/inputs';
import { useSetting } from '../../../contexts/SettingContext';
import { formatWpDate } from '../../../services/formatWpDate';
import './IndexNowPanel.scss';

interface HistoryRow {
	id: number;
	url: string;
	response_code: number | null;
	response_status: string;
	trigger_type: string;
	created_at: string;
}

interface SubmitResult {
	url: string;
	success: boolean;
	status_code: number | null;
	status: string;
	message: string;
}


/** "Products" only ever a real, selectable option once WooCommerce is actually active (`vulopilotAppLocalizer.has_woocommerce`, `FrontendScripts::localize_scripts()`) - a site with no WooCommerce has no `product` post type at all, so offering it here would just be a checkbox for something that can never exist. */
const POST_TYPE_OPTIONS = [
	{ value: 'post', label: __('Posts', 'vulopilot') },
	{ value: 'page', label: __('Pages', 'vulopilot') },
	{ value: 'attachment', label: __('Media', 'vulopilot') },
	...(vulopilotAppLocalizer.has_woocommerce
		? [{ value: 'product', label: __('Products', 'vulopilot') }]
		: []),
	{ value: 'knowledgebase', label: __('Knowledgebase', 'vulopilot') },
	{ value: 'mega_menu', label: __('Mega Menu', 'vulopilot') },
];

const RESPONSE_CODE_HELP: { code: string; type: string; desc: string }[] = [
	{ code: '200 OK', type: 'good', desc: __('URL received.', 'vulopilot') },
	{
		code: '202 Accepted',
		type: 'good',
		desc: __('URL received; key not yet validated.', 'vulopilot'),
	},
	{ code: '400 Bad Request', type: 'warn', desc: __('Invalid format.', 'vulopilot') },
	{
		code: '403 Forbidden',
		type: 'crit',
		desc: __("Key not found or doesn't match.", 'vulopilot'),
	},
	{
		code: '422 Unprocessable Entity',
		type: 'crit',
		desc: __("URL doesn't belong to this site.", 'vulopilot'),
	},
	{
		code: '429 Too Many Requests',
		type: 'crit',
		desc: __('Rate limited, try again later.', 'vulopilot'),
	},
];

/**
 * Hand-built rather than InputRenderer-driven - same escape hatch
 * other panels already use (Settings.tsx's
 * GetForm() special-cases `currentTab === 'indexnow'`). Unlike those,
 * this tab DOES have two real flat settings fields
 * (`indexnow_api_key`/`indexnow_post_types`) - read via `useSetting()`
 * (LlmsTxtCard.tsx's own precedent for a hand-built component reading/
 * writing the shared SettingContext) - alongside two real actions/logs
 * that don't fit the per-field model at all: manual URL submission
 * (`POST /indexnow/submit`) and submission history (`GET /indexnow/history`),
 * both backed by RestAPI\Controllers\IndexNow.
 */
const IndexNowPanel = () => {
	const { setting, updateSetting } = useSetting();

	const apiKey = (setting.indexnow_api_key as string) || '';
	const postTypes = (setting.indexnow_post_types as string[]) || [];

	const [history, setHistory] = useState<HistoryRow[]>([]);
	const [isLoadingHistory, setIsLoadingHistory] = useState(true);
	const [urlsText, setUrlsText] = useState('');
	const [isSubmitting, setIsSubmitting] = useState(false);
	const [submitResults, setSubmitResults] = useState<SubmitResult[]>([]);
	const [showResponseHelp, setShowResponseHelp] = useState(false);
	const [isChangingKey, setIsChangingKey] = useState(false);

	const loadHistory = () => {
		setIsLoadingHistory(true);

		getApiResponse<HistoryRow[]>(
			getApiLink(vulopilotAppLocalizer, 'indexnow/history'),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => {
				if (response) {
					setHistory(response);
				}
			})
			.finally(() => setIsLoadingHistory(false));
	};

	useEffect(loadHistory, []);

	const handlePostTypesChange = (newValue: string | string[]) => {
		const values = Array.isArray(newValue) ? newValue : [newValue];
		updateSetting('indexnow_post_types', values);
		sendApiResponse(vulopilotAppLocalizer, getApiLink(vulopilotAppLocalizer, 'settings'), {
			setting: { indexnow_post_types: values },
		}).then((response) => {
			NoticeManager.add({
				uniqueKey: 'vulopilot-indexnow-post-types-saved',
				type: response ? 'success' : 'error',
				position: 'float',
				message: response
					? __('Settings saved.', 'vulopilot')
					: __('Could not save settings. Please try again.', 'vulopilot'),
			});
		});
	};

	const handleChangeKey = () => {
		setIsChangingKey(true);

		const bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		const newKey = Array.from(bytes)
			.map((byte) => byte.toString(16).padStart(2, '0'))
			.join('');

		updateSetting('indexnow_api_key', newKey);

		sendApiResponse(vulopilotAppLocalizer, getApiLink(vulopilotAppLocalizer, 'settings'), {
			setting: { indexnow_api_key: newKey },
		})
			.then((response) => {
				NoticeManager.add({
					uniqueKey: 'vulopilot-indexnow-key-changed',
					type: response ? 'success' : 'error',
					position: 'float',
					message: response
						? __('IndexNow API key changed.', 'vulopilot')
						: __('Could not change the key. Please try again.', 'vulopilot'),
				});
			})
			.finally(() => setIsChangingKey(false));
	};

	const handleSubmitUrls = () => {
		const urls = urlsText
			.split('\n')
			.map((url) => url.trim())
			.filter(Boolean);

		if (urls.length === 0) {
			return;
		}

		setIsSubmitting(true);

		sendApiResponse(vulopilotAppLocalizer, getApiLink(vulopilotAppLocalizer, 'indexnow/submit'), {
			urls,
		})
			.then((response) => {
				const results = (response as { results?: SubmitResult[] } | null)
					?.results;

				if (results) {
					setSubmitResults(results);
					setUrlsText('');
					loadHistory();
				} else {
					NoticeManager.add({
						uniqueKey: 'vulopilot-indexnow-submit-failed',
						type: 'error',
						position: 'float',
						message: __('Could not submit these URLs. Please try again.', 'vulopilot'),
					});
				}
			})
			.finally(() => setIsSubmitting(false));
	};

	return (
		<>
			<FormGroupWrapperComponent>
				<FormGroupComponent cols={6}
					label={__('What should notify search engines automatically', 'vulopilot')}
					desc={__('When one of these is published, edited, or deleted, VuloPilot tells search engines right away. Everything else you will need to submit yourself, below.',
						'vulopilot'
					)}
				>
					<MultiCheckboxInput
						options={POST_TYPE_OPTIONS}
						value={postTypes}
						modules={[]}
						onChange={handlePostTypesChange}
						selectDeselect={true}
					/>
				</FormGroupComponent>
				<FormGroupComponent cols={6}
					className='api-key-form'
				>
					<>
						<CardComponent
							title={
								apiKey
									? __('IndexNow is connected', 'vulopilot')
									: __('IndexNow needs a key', 'vulopilot')
							}
							titleIcon="check"
							desc={
								apiKey
									? __(
											'Search engines can confirm this site owns the content it submits.',
											'vulopilot'
										)
									: __(
											'Generate a key below to activate IndexNow for this site.',
											'vulopilot'
										)
							}
							// Real zyra palette green, same literal hex this
							// codebase's own other `.is-good`/success-state
							// rules already mirror it with (no `.scss` source
							// to `@use` a real token from - see
							// BrandVisibilityProDummies.tsx's own identical
							// docblock on this).
							borderColor={apiKey ? '#16a34a' : undefined}
							action={
								<span className={`admin-badge ${apiKey ? 'green' : 'gray'}`}>
									{apiKey ? __('Active', 'vulopilot') : __('Inactive', 'vulopilot')}
								</span>
							}
						>
							<div className="indexnow-verification-row">
								<div>
									<strong>{__('Site verification', 'vulopilot')}</strong>
									<p className="desc">
										{__(
											'Proves to search engines that submissions are really coming from your site. Generated and renewed automatically - nothing for you to manage.',
											'vulopilot'
										)}
									</p>
								</div>
								<span className={`admin-badge ${apiKey ? 'green' : 'gray'}`}>
									{apiKey
										? __('✓ Verified', 'vulopilot')
										: __('Not set up', 'vulopilot')}
								</span>
							</div>
						</CardComponent>
						<NoticeComponent
							displayPosition="inline-notice"
							type="info"
							title={__('Why this matters', 'vulopilot')}
							message={__(
								'Without IndexNow, search engines find changes by revisiting your site on their own schedule - which can take days. This tells them immediately, so new and updated pages can appear in results sooner.',
								'vulopilot'
							)}
							actionLabel={`${__('Learn more', 'vulopilot')} ↗`}
							onAction={() =>
								window.open(
									'https://www.indexnow.org/',
									'_blank',
									'noopener,noreferrer'
								)
							}
						/>
					</>
				</FormGroupComponent>
				<FormGroupComponent
					label={__('Submit specific URLs', 'vulopilot')}
					desc={__('Notify search engines about specific pages. One per line, up to 10,000.', 'vulopilot')}
					cols={6}
				>
					<TextAreaInput
						name="indexnow_urls"
						value={urlsText}
						rowNumber={4}
						placeholder="https://yoursite.com/hello-world"
						usePlainText
						onChange={(newValue) => setUrlsText(newValue as string)}
					/>
					<ButtonInput
						buttons={{
							text: isSubmitting
								? __('Submitting…', 'vulopilot')
								: __('Submit URLs', 'vulopilot'),
							icon: 'send',
							onClick: handleSubmitUrls,
							disabled: isSubmitting,
						}}
					/>
				</FormGroupComponent>

				{/* {submitResults.length > 0 && (
					<FormGroupComponent label={__('Just submitted', 'vulopilot')}>
						<div>
							{submitResults.map((result, index) => (
								<div key={index}>
									<code>{result.url}</code>
									{' - '}
									{result.status_code ?? __('error', 'vulopilot')}{' '}
									{result.message}
								</div>
							))}
						</div>
					</FormGroupComponent>
				)} */}
			</FormGroupWrapperComponent>

			<CardComponent
				title={__('Recent submissions', 'vulopilot')}
				titleIcon="clock"
				desc={__('Your last 100 requests to search engines, and whether each was received.', 'vulopilot')}
				isLoading={isLoadingHistory}
				action={
					<ButtonInput
						buttons={{
							text: __('Response code help', 'vulopilot'),
							rightIcon: 'question',
							color: 'text-purple',
							onClick: () => setShowResponseHelp(!showResponseHelp),
						}}
					/>
				}
			>
				{/* {showResponseHelp && (
					<div className="vulopilot-indexnow-help">
						{RESPONSE_CODE_HELP.map((row) => (
							<div key={row.code} className={`vulopilot-indexnow-help__${row.type}`}>
								<strong>{row.code}</strong> - {row.desc}
							</div>
						))}
					</div>
				)} */}

				{history.length === 0 ? (
					<div className="desc">{__('No submissions yet.', 'vulopilot')}</div>
				) : (
					<table className="vulopilot-indexnow-history">
						<thead>
							<tr>
								<th>{__('Time', 'vulopilot')}</th>
								<th>{__('URL', 'vulopilot')}</th>
								<th>{__('Response', 'vulopilot')}</th>
							</tr>
						</thead>
						<tbody>
							{history.map((row) => (
								<tr key={row.id}>
									<td>{formatWpDate(row.created_at)}</td>
									<td>{row.url}</td>
									<td>
										{row.response_code ?? __('error', 'vulopilot')}{' '}
										{row.response_status}
									</td>
								</tr>
							))}
						</tbody>
					</table>
				)}
			</CardComponent>
		</>
	);
};

export default IndexNowPanel;
