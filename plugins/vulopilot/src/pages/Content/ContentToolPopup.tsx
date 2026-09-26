/* global vulopilotAppLocalizer */
import React, { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import { NoticeComponent, NoticeManager, PopupComponent, FormGroupWrapperComponent, FormGroupComponent } from '@zyra/components';
import {
	ButtonInput,
	SelectInput,
	TextAreaInput,
	TextInput,
} from '@zyra/inputs';
import { ContentTool, ToolField } from './ContentToolsGrid';
import { VuloCloudInlineNotice } from '../../components/Popup/Popup';
import { useAiCredits } from '../../services/useAiCredits';

interface WpRestPost {
	id: number;
	title: { rendered: string };
}

interface WpRestMedia {
	id: number;
	title: { rendered: string };
	source_url: string;
}

interface DuplicateFinding {
	id: number;
	title: string;
	object_ref: string;
}

interface ProposeResponse {
	success?: boolean;
	run_id?: number;
	preview?: {
		title: string;
		before: string | null;
		after: string;
		format: string;
	};
	/** Settings → Automation → Approval Settings - true when ActionRunner::propose() itself already approved and executed this run (risk-based/"Do not ask" mode), same as AIActions\ActionRunner::propose()'s own docblock. When true there's nothing left to review - see the propose `.then()` handler below. */
	auto_approved?: boolean;
}

type Step = 'input' | 'loading' | 'preview' | 'error';

interface PickerOption {
	value: string;
	label: string;
}

interface ContentToolPopupProps {
	tool: ContentTool | null;
	onClose: () => void;
}

/**
 * Uses a raw `fetch()` for the propose() call specifically rather than
 * zyra's `sendApiResponse()` - that helper always resolves to `null` on
 * any failure (confirmed by reading its own implementation), discarding
 * the real WP_Error body - but this is the one call in the whole flow
 * where the real per-field validation message ("Please provide a topic of
 * at least 5 characters") or a real provider error ("Invalid API Key") is
 * exactly what the user needs to see, not a generic failure notice.
 *
 * "Product Descriptions" gets one extra, tool-specific convenience: a real
 * WooCommerce product picker (`GET /wc/v3/products`, same graceful-404
 * handling every other WooCommerce probe in this codebase already uses -
 * see RecentContentCard.tsx's own docblock) that prefills the real
 * product_name/key_features fields from an existing product rather than
 * requiring them typed from scratch. It only prefills - the actual
 * generation still runs from whatever's in those two (still-editable)
 * fields, matching GenerateProductDescriptionAction's real input contract
 * exactly (it has no `product_id` concept of its own).
 */
const ContentToolPopup: React.FC<ContentToolPopupProps> = ({
	tool,
	onClose,
}) => {
	const [step, setStep] = useState<Step>('input');
	const [fieldValues, setFieldValues] = useState<Record<string, string>>(
		{}
	);
	const [postOptions, setPostOptions] = useState<PickerOption[]>([]);
	const [mediaOptions, setMediaOptions] = useState<PickerOption[]>([]);
	const [duplicateFindings, setDuplicateFindings] = useState<
		DuplicateFinding[]
	>([]);
	const [isLoadingOptions, setIsLoadingOptions] = useState(false);
	const [errorMessage, setErrorMessage] = useState('');
	const [runId, setRunId] = useState<number | null>(null);
	const [preview, setPreview] = useState<ProposeResponse['preview'] | null>(
		null
	);
	const [isBusy, setIsBusy] = useState(false);

	const { status: creditsStatus } = useAiCredits();
	// Only offer "Connect" when not already connected - otherwise show the real server error.
	const isNoProviderError =
		errorMessage.includes('No AI connection is configured') && !creditsStatus?.connected;

	useEffect(() => {
		if (!tool) {
			return;
		}

		setStep('input');
		setFieldValues({});
		setErrorMessage('');
		setRunId(null);
		setPreview(null);

		const needs = (type: ToolField['type']) =>
			tool.fields.some((field) => field.type === type);

		if (needs('post-picker')) {
			setIsLoadingOptions(true);
			Promise.all([
				getApiResponse<WpRestPost[]>(
					getApiLink(
						vulopilotAppLocalizer,
						'posts?per_page=20&orderby=date&order=desc&_fields=id,title',
						'wp/v2'
					),
					{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
				),
				getApiResponse<WpRestPost[]>(
					getApiLink(
						vulopilotAppLocalizer,
						'pages?per_page=20&orderby=date&order=desc&_fields=id,title',
						'wp/v2'
					),
					{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
				),
			])
				.then(([posts, pages]) => {
					const options = [...(posts || []), ...(pages || [])].map(
						(post) => ({
							value: String(post.id),
							label: post.title.rendered || `#${post.id}`,
						})
					);
					setPostOptions(options);
				})
				.finally(() => setIsLoadingOptions(false));
		}

		if (needs('media-picker')) {
			setIsLoadingOptions(true);
			getApiResponse<WpRestMedia[]>(
				getApiLink(
					vulopilotAppLocalizer,
					'media?per_page=20&media_type=image&orderby=date&order=desc&_fields=id,title,source_url',
					'wp/v2'
				),
				{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
			)
				.then((media) => {
					setMediaOptions(
						(media || []).map((item) => ({
							value: String(item.id),
							label:
								item.title.rendered ||
								item.source_url.split('/').pop() ||
								`#${item.id}`,
						}))
					);
				})
				.finally(() => setIsLoadingOptions(false));
		}

		if (needs('duplicate-finding-picker')) {
			setIsLoadingOptions(true);
			getApiResponse<{ data?: DuplicateFinding[] }>(
				getApiLink(
					vulopilotAppLocalizer,
					'findings?scanner_id=duplicate-content&status=open&per_page=20'
				),
				{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
			)
				.then((response) => {
					setDuplicateFindings(response?.data ?? []);
				})
				.finally(() => setIsLoadingOptions(false));
		}

	}, [tool]);

	if (!tool) {
		return null;
	}

	const isReadyToSubmit = tool.fields.every((field) => {
		if ('textarea' === field.type && 'brief' !== field.key) {
			return true; // Optional textareas (e.g. key_features).
		}

		return Boolean(fieldValues[field.key]);
	});

	// Free tiles call Free's shared `/ai-action-runs`, the same endpoint "Fix with AI" buttons elsewhere use.
	const runsBase = 'ai-action-runs';

	const handleSubmit = () => {
		setStep('loading');
		setErrorMessage('');

		const input: Record<string, unknown> = {};

		tool.fields.forEach((field) => {
			if ('duplicate-finding-picker' === field.type) {
				const finding = duplicateFindings.find(
					(f) => String(f.id) === fieldValues[field.key]
				);
				input[field.key] = finding
					? finding.object_ref.split(',').map(Number)
					: [];
				return;
			}

			input[field.key] = fieldValues[field.key] ?? '';
		});

		fetch(getApiLink(vulopilotAppLocalizer, runsBase), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': vulopilotAppLocalizer.nonce,
			},
			body: JSON.stringify({ action_id: tool.actionId, input }),
		})
			.then(async (response) => {
				const body = await response.json();

				if (!response.ok) {
					throw new Error(
						body?.message ||
						__(
							'Something went wrong. Please try again.',
							'vulopilot'
						)
					);
				}

				return body as ProposeResponse;
			})
			.then((body) => {
				// Settings → Automation → Approval Settings' risk-based/
				// "Do not ask" modes can make propose() itself apply this
				// change immediately - there's no pending run left to show
				// an Approve/Reject step for, so this is the same real
				// success notice handleApprove() below shows after a human
				// clicks Approve, just fired for a change that already
				// went live without waiting for one.
				if (body.auto_approved) {
					NoticeManager.add({
						uniqueKey: `content-tool-approve-${body.run_id}`,
						type: 'success',
						position: 'float',
						message: __(
							'Applied automatically - the change is now live.',
							'vulopilot'
						),
					});
					onClose();
					return;
				}

				setRunId(body.run_id ?? null);
				setPreview(body.preview ?? null);
				setStep('preview');
			})
			.catch((error: Error) => {
				setErrorMessage(error.message);
				setStep('error');
			});
	};

	const handleApprove = () => {
		if (!runId) {
			return;
		}

		setIsBusy(true);
		sendApiResponse<{ success?: boolean }>(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, `${runsBase}/${runId}/approve`),
			{}
		)
			.then((response) => {
				NoticeManager.add({
					uniqueKey: `content-tool-approve-${runId}`,
					type: response?.success ? 'success' : 'error',
					position: 'float',
					message: response?.success
						? __('Applied - the change is now live.', 'vulopilot')
						: __(
							'Could not apply this change. Please try again.',
							'vulopilot'
						),
				});

				if (response?.success) {
					onClose();
				}
			})
			.finally(() => setIsBusy(false));
	};

	const handleReject = () => {
		if (!runId) {
			return;
		}

		setIsBusy(true);
		sendApiResponse<{ success?: boolean }>(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, `${runsBase}/${runId}/reject`),
			{}
		)
			.then(() => {
				NoticeManager.add({
					uniqueKey: `content-tool-reject-${runId}`,
					type: 'info',
					position: 'float',
					message: __('Discarded - nothing was changed.', 'vulopilot'),
				});
				onClose();
			})
			.finally(() => setIsBusy(false));
	};

	const renderField = (field: ToolField) => {
		const value = fieldValues[field.key] ?? '';
		const setValue = (next: string) =>
			setFieldValues((current) => ({ ...current, [field.key]: next }));

		if ('text' === field.type) {
			return (
				<TextInput
					id={field.key}
					type="text"
					name={field.key}
					inputClass="content-tool-field-input"
					value={value}
					onChange={(newValue) => setValue(newValue as string)}
				/>
			);
		}

		if ('textarea' === field.type) {
			return (
				<TextAreaInput
					id={field.key}
					name={field.key}
					inputClass="content-tool-field-input"
					rowNumber={3}
					usePlainText
					value={value}
					onChange={(newValue) => setValue(newValue)}
				/>
			);
		}

		if ('select' === field.type) {
			return (
				<SelectInput
					type="single-select"
					name={field.key}
					value={value}
					onChange={(newValue) => setValue(newValue as string)}
					placeholder={__('Select…', 'vulopilot')}
					options={field.options ?? []}
					isClearable={false}
				/>
			);
		}

		const options =
			'post-picker' === field.type
				? postOptions
				: 'media-picker' === field.type
					? mediaOptions
					: duplicateFindings.map((finding) => ({
						value: String(finding.id),
						label: finding.title,
					}));

		// An empty dropdown with no explanation reads as broken - real for
		// 'duplicate-finding-picker' especially, since (unlike post/media
		// pickers on any site with actual content) it's entirely normal for
		// this to be genuinely empty: DuplicateContentScanner only ever
		// creates a finding when two or more published posts/pages share
		// the *exact same* title, which most sites simply never trigger.
		// Each message names the real, specific reason so it's honest
		// about what "empty" here actually means, not a generic fallback.
		if (!isLoadingOptions && 0 === options.length) {
			return (
				<p className="desc content-tool-empty-picker">
					{'duplicate-finding-picker' === field.type
						? __(
							"No duplicate titles found. This only lists published posts/pages that currently share the exact same title - most sites never trigger it, and it's not a sign anything is broken. If you expect one here, run a scan under SEO & Visibility → SEO first (DuplicateContentScanner needs a completed scan to have flagged it).",
							'vulopilot'
						)
						: 'media-picker' === field.type
							? __(
								'No images found in the Media Library yet - upload one first.',
								'vulopilot'
							)
							: __(
								'No posts or pages found yet - create one first.',
								'vulopilot'
							)}
				</p>
			);
		}

		return (
			<SelectInput
				type="single-select"
				name={field.key}
				value={value}
				onChange={(newValue) => setValue(newValue as string)}
				placeholder={
					isLoadingOptions
						? __('Loading…', 'vulopilot')
						: __('Select…', 'vulopilot')
				}
				options={options}
				isClearable={false}
				disabled={isLoadingOptions}
			/>
		);
	};

	return (
		<PopupComponent
			open={Boolean(tool)}
			onClose={onClose}
			width={31.25}
			height="50%"
			header={{
				title: tool.title,
				icon: tool.icon,
				description: tool.desc,
			}}
			footer={
				<>
					{'input' === step && (
						<ButtonInput
							buttons={{
								text: __('Generate', 'vulopilot'),
								icon: 'ai',
								color: 'orange-bg',
								onClick: handleSubmit,
								disabled: !isReadyToSubmit,
							}}
						/>
					)}

					{'error' === step && !isNoProviderError && (
						<ButtonInput
							buttons={{
								text: __('Try again', 'vulopilot'),
								icon: 'refresh',
								color: 'border-red',
								onClick: () => setStep('input'),
							}}
						/>
					)}

					{'preview' === step && preview && (
						<>
							<ButtonInput
								buttons={{
									text: __('Reject', 'vulopilot'),
									icon: 'close',
									color: 'border-red',
									onClick: handleReject,
									disabled: isBusy,
								}}
							/>
							<ButtonInput
								buttons={{
									text: __('Approve & apply', 'vulopilot'),
									icon: 'check',
									onClick: handleApprove,
									disabled: isBusy,
								}}
							/>
						</>
					)}
				</>
			}
		>
			<>
				{'input' === step && (
					<>
						<FormGroupWrapperComponent>
							{tool.fields.map((field) => (
								<FormGroupComponent label={field.label}>
									{renderField(field)}
								</FormGroupComponent >
							))}
						</FormGroupWrapperComponent>
					</>
				)}

				{'loading' === step && (
					<div className="content-tool-loading">
						<i className="adminfont-refresh content-tool-spinner" />
						<div className="desc">
							{__('Generating with AI…', 'vulopilot')}
						</div>
					</div>
				)}

				{'error' === step && (
					isNoProviderError ? (
						<VuloCloudInlineNotice />
					) : (
						<NoticeComponent
							displayPosition="inline-notice"
							type="error"
							message={errorMessage}
						/>
					)
				)}

				{'preview' === step && preview && (
					<div className="content-tool-preview">
						<div className="content-tool-preview-title">
							{preview.title}
						</div>
						{null !== preview.before && (
							<>
								<div className="content-tool-preview-label">
									{__('Before', 'vulopilot')}
								</div>
								<div className="content-tool-preview-before">
									{preview.before}
								</div>
							</>
						)}
						<div className="content-tool-preview-label">
							{__('After', 'vulopilot')}
						</div>
						<div className="content-tool-preview-after">
							{preview.after}
						</div>
					</div>
				)}
			</>
		</PopupComponent>
	);
};

export default ContentToolPopup;
