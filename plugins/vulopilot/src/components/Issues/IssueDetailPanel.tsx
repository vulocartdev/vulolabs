/* global vulopilotAppLocalizer */
import React, { useEffect, useRef, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import {
	CardComponent,
	ListComponent,
	ModuleGuardComponent,
	PopupComponent,
	ClipboardComponent,
	BadgeComponent,
	AnalyticsComponent,
	NoticeComponent
} from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import ShowProPopup from '../../components/Popup/Popup';
import DummyDataNotice from '../../components/DummyDataNotice';
import { formatWpDate } from '../../services/formatWpDate';
import { getSeverityClass } from '../../services/getSeverityClass';
import {
	CATEGORY_ICONS,
	CATEGORY_LABELS,
	formatAffected,
	getObjectTypeNoun,
	FindingGroup,
} from './issuesTypes';
import { FixOutcome } from '../../services/showFixOutcome';
import { useFixNotice } from '../../services/useFixNotice';
import './IssueDetailPanel.scss';

interface BatchFixOutcome extends Omit<FixOutcome, 'noFixAvailable'> {
	succeeded?: number;
	total?: number;
	/** Per-batch no-fix count, distinct from FixOutcome's final verdict. */
	noFixAvailable?: number;
}

const BULK_FIX_BATCH_SIZE = 50;

interface FindingRow {
	id: number;
	title: string;
	object_type: string | null;
	object_ref: string | null;
	created_at: string;
	/** Last reconfirmed by a scan - same as created_at if only ever detected once. */
	last_seen_at?: string;
	page?: string;
}

/** How many individual findings to list under "Affected accounts"/"Affected pages"/etc. */
const MAX_AFFECTED_ITEMS_SHOWN = 20;

/** Prefers the live front-end page, falls back to the post edit screen. */
const getAffectedItemLink = (
	row: Pick<FindingRow, 'object_type' | 'object_ref' | 'page'>
): string | undefined => {
	if (row.page && __('Site-wide', 'vulopilot') !== row.page) {
		return `${vulopilotAppLocalizer.site_url}${row.page}`;
	}

	if (
		!row.object_ref ||
		('attachment' !== row.object_type && 'post' !== row.object_type)
	) {
		return undefined;
	}

	// admin_url is `.../admin.php?page=vulopilot` (built for appending `#&tab=...` hashes
	// elsewhere in this app) - not a base to prefix a *different* admin.php query onto.
	return `${vulopilotAppLocalizer.site_url}/wp-admin/post.php?post=${encodeURIComponent(row.object_ref)}&action=edit`;
};

/** Splits a sample finding's title ("{message}: {affected item name}") into its two halves. */
const splitSampleTitle = (
	title: string
): { message: string; name: string | null } => {
	const separatorIndex = title.lastIndexOf(': ');

	if (-1 === separatorIndex) {
		return { message: title, name: null };
	}

	return {
		message: title.slice(0, separatorIndex),
		name: title.slice(separatorIndex + 2),
	};
};

/** Section label per `object_type`. */
const AFFECTED_ITEMS_LABEL: Record<string, string> = {
	user: __('Affected accounts', 'vulopilot'),
	post: __('Affected pages', 'vulopilot'),
	attachment: __('Affected images', 'vulopilot'),
	product: __('Affected products', 'vulopilot'),
	url: __('Affected endpoints', 'vulopilot'),
	plugin: __('Affected plugins', 'vulopilot'),
	theme: __('Affected themes', 'vulopilot'),
	table: __('Affected tables', 'vulopilot'),
	file: __('Affected files', 'vulopilot'),
	site: __('Affected checks', 'vulopilot'),
};

const getFindingBulkFixHandler = () =>
	applyFilters('vulopilot_finding_bulk_fix_handler', null);

const SEVERITY_LABEL: Record<string, string> = {
	critical: __('Critical Priority', 'vulopilot'),
	high: __('High Priority', 'vulopilot'),
	medium: __('Medium Priority', 'vulopilot'),
	low: __('Low Priority', 'vulopilot'),
	info: __('Low Priority', 'vulopilot'),
};

interface IssueDetailPanelProps {
	group: FindingGroup | null;
	/** `event` is set for a fix or an undo of `group`, so the host can keep it listed instead of dropping it. */
	// eslint-disable-next-line no-unused-vars
	onActionComplete: (event?: { group: FindingGroup; fixed: boolean }) => void;
}

const IssueDetailPanel: React.FC<IssueDetailPanelProps> = ({
	group,
	onActionComplete,
}) => {
	const isProActive = !!vulopilotAppLocalizer.khali_dabba;
	const [isBusy, setIsBusy] = useState(false);
	const [proPopup, setProPopup] = useState<null | 'one-click-fix' | 'generic'>(null);
	const [affectedItems, setAffectedItems] = useState<FindingRow[] | null>(
		null
	);
	const [isLoadingAffected, setIsLoadingAffected] = useState(false);
	// Result of the last action, rendered right above the action buttons.
	const { show: showPanelNotice, fixNotice, isUnfixable } = useFixNotice(() => group && onActionComplete({ group, fixed: false }));
	// Tracks the selected issue so a late-arriving result doesn't apply after selection changes.
	const activeScannerId = useRef<string | undefined>(group?.scanner_id);

	useEffect(() => {
		activeScannerId.current = group?.scanner_id;
		// A fixed issue listed again after a reload gets its Fixed message and Undo back.
		showPanelNotice(
			group?.fixed && group.undo_ids?.length
				? (applyFilters('vulopilot_fixed_group_outcome', null, group) as FixOutcome | null) ?? undefined
				: undefined
		);
		// `showPanelNotice` is re-created every render and only calls a state setter; re-run only when the selected issue changes.
	}, [group?.scanner_id]);

	/** The group response only ever carries a count + one sample. */
	useEffect(() => {
		if (!group || !isProActive) {
			setAffectedItems(null);
			return;
		}

		setIsLoadingAffected(true);
		setAffectedItems(null);

		// Scoped by object_type too, not just scanner_id - one scanner can report several
		// unrelated finding types under the same scanner_id.
		const objectTypeParam = group.object_type
			? `&object_type=${encodeURIComponent(group.object_type)}`
			: '';

		getApiResponse<{ data?: FindingRow[] } | FindingRow[]>(
			getApiLink(
				vulopilotAppLocalizer,
				`findings?scanner_id=${encodeURIComponent(group.scanner_id)}${objectTypeParam}&status=open&per_page=${MAX_AFFECTED_ITEMS_SHOWN}&orderby=id&order=desc`
			),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => {
				const list = Array.isArray(response)
					? response
					: (response?.data ?? []);

				setAffectedItems(list);
			})
			.finally(() => setIsLoadingAffected(false));
	}, [group?.scanner_id, group?.object_type]);

	if (!group) {
		return (
			<CardComponent
				title={__('Issue details', 'vulopilot')}
				titleIcon="issue"
				desc={__('More detail on the issue you select from the table.', 'vulopilot')}
			>
				<ModuleGuardComponent
					icon="issue"
					title={__('Select an issue', 'vulopilot')}
					desc={__(
						'Choose a row from the table to see more detail here.',
						'vulopilot'
					)}
				/>
			</CardComponent>
		);
	}

	/** Parses the raw JSON meta column, which older findings may not have. */
	const sampleMeta: Record<string, unknown> | null = (() => {
		if (!group.sample?.meta) {
			return null;
		}

		try {
			const parsed = JSON.parse(group.sample.meta);

			return parsed && 'object' === typeof parsed ? parsed : null;
		} catch {
			return null;
		}
	})();

	/** Per-finding `meta.recommended_fix` if present, else the per-scanner `no_fix_steps`. */
	const recommendedFixSteps: string[] = Array.isArray(
		sampleMeta?.recommended_fix
	)
		? (sampleMeta?.recommended_fix as unknown[]).filter(
			(step: unknown): step is string => 'string' === typeof step
		)
		: (group.no_fix_steps ?? []);

	const showRecommendedFix =
		!group.fix_action_id && recommendedFixSteps.length > 0;

	const whyItMatters =
		'string' === typeof sampleMeta?.why_it_matters
			? sampleMeta.why_it_matters
			: '';
	const whatHappened =
		'string' === typeof sampleMeta?.what_happened
			? sampleMeta.what_happened
			: '';
	const showWhatHappened = !showRecommendedFix && '' !== whatHappened;

	/** group.sample is already shown in full above ("Recommended fix"/"What happened"/"Example finding"). */
	const otherAffectedItems = (affectedItems ?? []).filter(
		(row) => row.id !== group.sample?.id
	);

	const renderProGatedSection = (
		realContent: ReactNode,
		dummyContent: ReactNode,
		showTag: boolean = true
	): ReactNode => {
		if (isProActive) {
			return realContent;
		}

		return (
			<div className="issue-detail-pro-gate">
				{showTag && (
					<div className="issue-detail-pro-gate-tag">
						<span className="admin-tag pro-tag">
							<i className="adminfont-pro-tag" />
							{__('Pro', 'vulopilot')}
						</span>
					</div>
				)}
				<div className="issue-detail-pro-gate-dummy" aria-hidden="true">
					{dummyContent}
				</div>
				{showTag && <DummyDataNotice />}
				<div
					className="issue-detail-pro-gate-overlay"
					role="button"
					tabIndex={0}
					aria-label={__('Upgrade to Pro', 'vulopilot')}
					onClick={() => setProPopup('generic')}
					onKeyDown={(e) => {
						if ('Enter' === e.key || ' ' === e.key) {
							e.preventDefault();
							setProPopup('generic');
						}
					}}
				/>
			</div>
		);
	};

	/** Fetches every finding id for this group, scoped by object_type like the fetch above. */
	const fetchGroupIds = (
		scannerId: string,
		objectType: string | null
	): Promise<number[]> =>
		getApiResponse<{ data?: { id: number }[] } | { id: number }[]>(
			getApiLink(
				vulopilotAppLocalizer,
				`findings?scanner_id=${encodeURIComponent(scannerId)}${objectType
					? `&object_type=${encodeURIComponent(objectType)}`
					: ''
				}&status=open&per_page=100`
			),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		).then((response) => {
			const list = Array.isArray(response)
				? response
				: (response?.data ?? []);

			return list.map((row) => row.id);
		});

	const handleBulkStatus = (
		status: 'resolved' | 'ignored',
		successMessage: string
	) => {
		setIsBusy(true);
		fetchGroupIds(group.scanner_id, group.object_type)
			.then((ids) => {
				if (!ids.length) {
					return;
				}

				return sendApiResponse(
					vulopilotAppLocalizer,
					getApiLink(vulopilotAppLocalizer, 'findings/bulk'),
					{ ids, status }
				).then((response) => {
					if (activeScannerId.current !== group.scanner_id) {
						return;
					}

					showPanelNotice({
						success: !!response,
						message: response
							? successMessage
							: __(
								'Could not update these findings. Please try again.',
								'vulopilot'
							),
					});

					if (response) {
						onActionComplete();
					}
				});
			})
			.finally(() => setIsBusy(false));
	};

	/** Runs the bulk-fix handler in BULK_FIX_BATCH_SIZE chunks, aggregating the counts into one outcome. */
	const runBulkFixInBatches = (

		// eslint-disable-next-line no-unused-vars
		bulkFixHandler: (batchIds: number[]) => Promise<BatchFixOutcome> | undefined,
		ids: number[]
	): Promise<FixOutcome> => {
		const batches: number[][] = [];

		for (let i = 0; i < ids.length; i += BULK_FIX_BATCH_SIZE) {
			batches.push(ids.slice(i, i + BULK_FIX_BATCH_SIZE));
		}

		return batches
			.reduce(
				(chain, batch) =>
					chain.then((totals) =>
						Promise.resolve(bulkFixHandler(batch)).then((outcome) => ({
							succeeded: totals.succeeded + (outcome?.succeeded ?? 0),
							total: totals.total + (outcome?.total ?? batch.length),
							noFixCount:
								totals.noFixCount + (outcome?.noFixAvailable ?? 0),
							lastMessage: outcome?.message ?? totals.lastMessage,
							link: outcome?.link ?? totals.link,
							undos: outcome?.undo ? [...totals.undos, outcome.undo] : totals.undos,
						}))
					),
				Promise.resolve({
					succeeded: 0,
					total: 0,
					noFixCount: 0,
					lastMessage: '',
					link: undefined as FixOutcome['link'],
					undos: [] as Array<NonNullable<FixOutcome['undo']>>,
				})
			)
			.then(({ succeeded, total, noFixCount, lastMessage, link, undos }) => {
				// One Undo that reverses every batch that could be undone.
				const undo: FixOutcome['undo'] = undos.length
					? () =>
						Promise.all(undos.map((run) => run())).then((results) => ({
							success: results.every((result) => result.success),
							message:
								results.find((result) => !result.success)?.message ??
								results[0].message,
						}))
					: undefined;

				const failed = total - succeeded;

				// Single batch: the handler's own message already says exactly the right thing
				// (including the "no automatic fix exists yet" honest case).
				if (batches.length <= 1) {
					return {
						success: 0 === failed,
						message: lastMessage,
						link,
						undo,
						noFixAvailable: 0 === succeeded && noFixCount === total && total > 0,
					};
				}

				let message: string;

				if (0 === failed) {
					message = sprintf(
						/* translators: %d is how many findings were fixed. */
						__('Fixed %d findings.', 'vulopilot'),
						succeeded
					);
				} else if (noFixCount === failed) {
					message =
						0 === succeeded && lastMessage
							// Nothing was fixable: keep the handler's own message, it says why.
							? lastMessage
							: succeeded > 0
								? sprintf(
									/* translators: 1: number fixed, 2: how many had no automatic fix available at all. */
									__(
										'Fixed %1$d findings - no automatic fix exists yet for the other %2$d.',
										'vulopilot'
									),
									succeeded,
									noFixCount
								)
								: __(
									'No automatic fix exists yet for these findings.',
									'vulopilot'
								);
				} else {
					message = sprintf(
						/* translators: 1: number fixed, 2: total findings attempted. */
						__(
							'Fixed %1$d of %2$d findings - some had no fix available or failed.',
							'vulopilot'
						),
						succeeded,
						total
					);
				}

				return {
					success: 0 === failed,
					message,
					link,
					undo,
					noFixAvailable: 0 === succeeded && noFixCount === total && total > 0,
				};
			});
	};

	const handleFix = () => {
		const bulkFixHandler = getFindingBulkFixHandler();

		if ('function' !== typeof bulkFixHandler) {
			setProPopup(isProActive ? 'one-click-fix' : 'generic');
			return;
		}

		setIsBusy(true);
		fetchGroupIds(group.scanner_id, group.object_type)
			.then((ids) => {
				if (!ids.length) {
					return;
				}

				showPanelNotice(undefined);

				return runBulkFixInBatches(bulkFixHandler, ids).then((outcome) => {
					if (activeScannerId.current === group.scanner_id) {
						showPanelNotice({ ...outcome, label: group.label });
					}

					onActionComplete({ group, fixed: outcome.success });
				});
			})
			.finally(() => setIsBusy(false));
	};

	/** Header uses the sample's affected-item name/message when available, else group.label/group.sample?.title. */
	const sampleSplit = group.sample ? splitSampleTitle(group.sample.title) : null;
	const headerTitle =
		sampleSplit?.name
			? `${sampleSplit.name} (${getObjectTypeNoun(1, group.object_type)})`
			: group.label;
	const headerDesc = sampleSplit ? sampleSplit.message : group.sample?.title;

	return (
		<>
			<CardComponent
				className="issue-detail-panel"
				title={headerTitle}
				titleIcon="error"
				desc={headerDesc}
			>
				<div className="issue-detail-badges-row">
					{group.fixed && <BadgeComponent color="green" text={__('Fixed', 'vulopilot')} />}
					<BadgeComponent
						color={getSeverityClass(group.severity)}
						text={SEVERITY_LABEL[group.severity]}
					/>
					<BadgeComponent
						color="blue"
						text={CATEGORY_LABELS[group.category] ?? group.category}
					/>
				</div>

				<AnalyticsComponent
					variant="small-priority-card"
					cols={3}
					data={[
						{
							icon: 'global-community',
							colorClass: 'blue',
							text: formatAffected(group.count, group.object_type),
							number: __('Affected', 'vulopilot'),
						},
						{
							icon: 'calendar',
							colorClass: 'orange',
							text: group.sample
								? formatWpDate(
									group.sample.last_seen_at ?? group.sample.created_at
								)
								: '-',
							number: __('Detected', 'vulopilot'),
						},
						{
							icon: 'location',
							colorClass: 'purple',
							number: group.sample?.page || __('Site-wide', 'vulopilot'),
							text: __('Scope', 'vulopilot'),
						},
					]}
				/>
				{whyItMatters && (
					<div className="issue-detail-why-it-matters">
						<div className="issue-detail-why-it-matters-icon">
							<i className="adminfont-info" />
						</div>
						<div>
							<div className="issue-detail-why-it-matters-title">
								{__('Why it matters', 'vulopilot')}
							</div>
							<div className="desc">{whyItMatters}</div>
						</div>
					</div>
				)}

				{group.sample && (() => {
					const samplePageLink = getAffectedItemLink(group.sample);

					return (
						<div className="issue-detail-section">
							<div className="issue-detail-section-header">
								{!isProActive && (
									<i className="adminfont-lock issue-detail-section-lock" />
								)}
								<span className="issue-detail-section-title">
									{showRecommendedFix
										? __('Recommended fix', 'vulopilot')
										: showWhatHappened
											? __('What happened?', 'vulopilot')
											: __('Example finding', 'vulopilot')}
								</span>
								{!isProActive && (
									<span className="admin-tag pro-tag">
										<i className="adminfont-pro-tag" />
										{__('Pro', 'vulopilot')}
									</span>
								)}
							</div>
							{showRecommendedFix
								? renderProGatedSection(
									<ol className="issue-detail-fix-steps">
										{recommendedFixSteps.map((step, index) => (
											<li key={index} className="issue-detail-fix-step">
												<span className="issue-detail-fix-step-number">
													{index + 1}
												</span>
												<span className="issue-detail-fix-step-text">
													{step}
												</span>
											</li>
										))}
									</ol>,
									<span className="desc">
										{__(
											'Step-by-step guidance for fixing this specific issue appears here once Pro is active.',
											'vulopilot'
										)}
									</span>,
									false
								)
								: showWhatHappened
									? renderProGatedSection(
										<>
											<div className="desc">{whatHappened}</div>
											<div className="issue-detail-example-where">
												<ClipboardComponent
													text={
														group.sample.page || __('Site-wide', 'vulopilot')
													}
													variant="code"
													copyButtonLabel={__('Copy', 'vulopilot')}
													copiedLabel={__('Copied!', 'vulopilot')}
												/>
												{samplePageLink && (
													<a
														href={samplePageLink}
														target="_blank"
														rel="noreferrer"
														className="issue-detail-example-open-link"
													>
														<i className="adminfont-external" />
														{__('View page', 'vulopilot')}
													</a>
												)}
											</div>
										</>,
										<span className="desc">
											{__(
												'What this specific check actually found appears here once Pro is active.',
												'vulopilot'
											)}
										</span>,
										false
									)
									: renderProGatedSection(
										<>
											<div className="issue-detail-example-title">
												{group.sample.title}
											</div>
											<div className="desc">{group.sample.description}</div>
											<div className="issue-detail-example-where">
												<ClipboardComponent
													text={
														group.sample.page || __('Site-wide', 'vulopilot')
													}
													variant="code"
													copyButtonLabel={__('Copy', 'vulopilot')}
													copiedLabel={__('Copied!', 'vulopilot')}
												/>
												{samplePageLink && (
													<a
														href={samplePageLink}
														target="_blank"
														rel="noreferrer"
														className="issue-detail-example-open-link"
													>
														<i className="adminfont-external" />
														{__('View page', 'vulopilot')}
													</a>
												)}
											</div>
										</>,
										<span className="desc">
											{__(
												'A real, representative finding from this group - its title, description, and where it was found - appears here once Pro is active.',
												'vulopilot'
											)}
										</span>,
										false
									)}
						</div>
					);
				})()}

				{isProActive && !fixNotice && !group.fix_action_id && group.no_fix_reason && (
					<NoticeComponent
						type="info"
						displayPosition="inline-notice"
						title={__('Why this needs manual intervention', 'vulopilot')}
						message={[
							!showRecommendedFix && group.no_fix_reason
								? `<span class="desc">${group.no_fix_reason}</span>`
								: '',
							group.no_fix_link
								? `<span class="small desc"><a href="${group.no_fix_link.url}" target="_blank" rel="noreferrer" style="color: var(--color-primary); text-decoration: underline;">${group.no_fix_link.label}</a></span>`
								: '',
							`<span class="small desc">${__('Check the details above for what to look at.', 'vulopilot')}</span>`,
						]
							.filter(Boolean)
							.join(' ')}
					/>
				)}

				<div className="issue-detail-section">
					<div className="issue-detail-section-header">
						{!isProActive && (
							<i className="adminfont-lock issue-detail-section-lock" />
						)}
						<span className="issue-detail-section-title">
							{AFFECTED_ITEMS_LABEL[group.object_type ?? ''] ??
								__('Affected items', 'vulopilot')}
						</span>
						{!isProActive && (
							<span className="admin-tag pro-tag">
								<i className="adminfont-pro-tag" />
								{__('Pro', 'vulopilot')}
							</span>
						)}
					</div>
					{renderProGatedSection(
						<>
							<ListComponent
								className="mini-card report"
								loading={isLoadingAffected}
								items={otherAffectedItems.map((row) => {
									const pageLink = getAffectedItemLink(row);

									return {
										id: row.id,
										icon: CATEGORY_ICONS[group.category] ?? 'ai',
										title: row.title,
										desc: sprintf(
											/* translators: 1: affected page/location, 2: formatted detection date */
											__('%1$s • Detected %2$s', 'vulopilot'),
											row.page || __('Site-wide', 'vulopilot'),
											formatWpDate(row.last_seen_at ?? row.created_at)
										),
										// `action` (not `link`) - ListComponent's own `<a>` branch
										// for `link` drops the `desc` line entirely.
										action: pageLink
											? () =>
												window.open(
													pageLink,
													'_blank',
													'noopener,noreferrer'
												)
											: undefined,
										// Visible link affordance; stopPropagation avoids double-opening via the row's action.
										tags: pageLink ? (
											<a
												href={pageLink}
												target="_blank"
												rel="noreferrer"
												className="issue-detail-example-open-link"
												onClick={(e) => e.stopPropagation()}
											>
												<i className="adminfont-external" />
												{__('View page', 'vulopilot')}
											</a>
										) : undefined,
									};
								})}
							/>
							{!isLoadingAffected &&
								affectedItems &&
								0 === affectedItems.length && (
									<span className="desc">
										{__(
											'No individual findings could be loaded for this group right now.',
											'vulopilot'
										)}
									</span>
								)}
							{!isLoadingAffected &&
								affectedItems &&
								affectedItems.length > 0 &&
								0 === otherAffectedItems.length && (
									<span className="desc">
										{__(
											'This group\'s only other open finding is already shown above.',
											'vulopilot'
										)}
									</span>
								)}
							{!isLoadingAffected &&
								affectedItems &&
								group.count > affectedItems.length && (
									<span className="small desc">
										{sprintf(
											/* translators: %d: how many further open findings exist beyond the list shown above */
											__(
												'+%d more not shown here - use Resolve all/Ignore all below, or open the Issues table to see every one.',
												'vulopilot'
											),
											group.count - affectedItems.length
										)}
									</span>
								)}
						</>,
						<span className="desc">
							{__(
								'The specific accounts/pages/etc. this group affects appear here once Pro is active.',
								'vulopilot'
							)}
						</span>,
						false
					)}
				</div>

				{!isProActive && (
					<div className="issue-detail-upgrade-banner">
						<i className="adminfont-info" />
						<span>
							{showRecommendedFix
								? __(
									'Detailed recommendations, setup guidance, and AI fixes are included in Pro.',
									'vulopilot'
								)
								: __(
									'Detailed examples, the full affected list, and AI-assisted fixes are included in Pro.',
									'vulopilot'
								)}
						</span>
					</div>
				)}

				{isProActive && fixNotice}

				{isProActive ? (
					<ButtonInput
						position="full-width"
						buttons={[
							{
								text: __('Ignore all', 'vulopilot'),
								color: 'border-red',
								rightIcon: 'rejecte',
								onClick: () =>
									handleBulkStatus(
										'ignored',
										__(
											'All findings in this group ignored.',
											'vulopilot'
										)
									),
								disabled: isBusy,
							},
							// No automatic fix exists for this issue; don't offer a button that can only fail again.
							...(isUnfixable || !group.fix_action_id
								? []
								: [
									{
										text: __('Fix with AI', 'vulopilot'),
										icon: 'ai',
										color: 'orange-bg',
										onClick: handleFix,
										disabled: isBusy,
									},
								]),
							{
								text: __('Resolve all', 'vulopilot'),
								color: 'purple-bg',
								rightIcon: 'resolve',
								onClick: () =>
									handleBulkStatus(
										'resolved',
										__(
											'All findings in this group marked resolved.',
											'vulopilot'
										)
									),
								disabled: isBusy,
							},
							
						]}
					/>
				) : (
					<ButtonInput
						position="full-width"
						buttons={[
							{
								text: __('Unlock to pro see details', 'vulopilot'),
								icon: 'pro-tag',
								onClick: () => setProPopup('generic'),
							},
						]}
					/>
				)}
			</CardComponent>
			<PopupComponent
				open={!!proPopup}
				onClose={() => setProPopup(null)}
				width={31.25}
				height="auto"
				position="lightbox"
			>
				{'one-click-fix' === proPopup ? (
					<ShowProPopup moduleName="one-click-fix" />
				) : (
					<ShowProPopup />
				)}
			</PopupComponent>
		</>
	);
};

export default IssueDetailPanel;