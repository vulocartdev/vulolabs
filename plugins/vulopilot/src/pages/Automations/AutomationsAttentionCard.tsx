/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __, sprintf, _n } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import { CardComponent, ListComponent } from '@zyra/components';
import type { AutomationRow } from './automationsTypes';

interface AutomationsAttentionCardProps {
	onViewAll: () => void;
	/** Bumped by the host after something changes elsewhere on the page (a new automation created, a status toggled). */
	refetchSignal: number;
}

/** Cron-based trigger types `with_next_run()` (Automations.php) resolves - any other trigger type never gets a `next_run_at`, so it's excluded from the "missed schedule" check below. */
const CRON_TRIGGER_TYPES = ['hourly', 'daily', 'weekly', 'monthly'];

interface AttentionGroup {
	key: string;
	count: number;
	title: string;
	desc: string;
}

/**
 * "Needs your attention" - flags only what actually blocks an automation, grouped by flag type.
 * Only 3 flags are wired up: setup incomplete, run failed, and a cron automation with no
 * scheduled run. Connection/credits/approval flags have no backing signal yet, left out.
 */
const AutomationsAttentionCard = ({ onViewAll, refetchSignal }: AutomationsAttentionCardProps) => {
	const [rows, setRows] = useState<AutomationRow[]>([]);
	const [isLoading, setIsLoading] = useState(true);

	// `isLoading` is only true for the first load - a refetch (after a toggle
	// elsewhere on the page) shouldn't flash this card back into its skeleton.
	useEffect(() => {
		getApiResponse<{ data: AutomationRow[] } | AutomationRow[]>(
			`${getApiLink(vulopilotAppLocalizer, 'automations')}?per_page=100`,
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		)
			.then((response) => {
				const list = Array.isArray(response) ? response : (response?.data ?? []);
				setRows(list);
			})
			.finally(() => setIsLoading(false));
	}, [refetchSignal]);

	const needsSetup = rows.filter((row) => 'draft' === row.status);
	const runFailed = rows.filter((row) => 'enabled' === row.status && 'failed' === row.last_run_status);
	const scheduleMissing = rows.filter(
		(row) =>
			'enabled' === row.status &&
			CRON_TRIGGER_TYPES.includes(row.trigger_type) &&
			! row.next_run_at
	);

	const groups: AttentionGroup[] = [
		needsSetup.length > 0 && {
			key: 'needs-setup',
			count: needsSetup.length,
			title: sprintf(
				/* translators: %d: automation count. */
				_n('%d automation needs setup', '%d automations need setup', needsSetup.length, 'vulopilot'),
				needsSetup.length
			),
			desc: __('Complete the required settings to enable them.', 'vulopilot'),
		},
		runFailed.length > 0 && {
			key: 'run-failed',
			count: runFailed.length,
			title: sprintf(
				/* translators: %d: automation count. */
				_n('%d automation failed its last run', '%d automations failed their last run', runFailed.length, 'vulopilot'),
				runFailed.length
			),
			desc: __('View the error, or retry from the list below.', 'vulopilot'),
		},
		scheduleMissing.length > 0 && {
			key: 'schedule-missing',
			count: scheduleMissing.length,
			title: sprintf(
				/* translators: %d: automation count. */
				_n('%d automation has no scheduled run', '%d automations have no scheduled run', scheduleMissing.length, 'vulopilot'),
				scheduleMissing.length
			),
			desc: __('Its schedule never got registered, so it will not fire on its own.', 'vulopilot'),
		},
	].filter(Boolean) as AttentionGroup[];

	return (
		<CardComponent
			title={__('Needs your attention', 'vulopilot')}
			titleIcon="error"
			desc={__('Resolve these items so your automations can run.', 'vulopilot')}
			isLoading={isLoading}
		>
			{!isLoading && 0 === groups.length && (
				<div className="automation-attention-empty">
					<div className="automation-attention-empty-pill">
						<i className="adminfont-check" />
						<p>{__('No action needed. Your automations are ready to run.', 'vulopilot')}</p>
					</div>
				</div>
			)}
			{groups.length > 0 && (
				<ListComponent
					className="mini-card report"
					items={groups.map((group) => ({
						id: group.key,
						icon: 'error red',
						title: group.title,
						desc: group.desc,
						tags: (
							<button type="button" className="automation-attention-view-all" onClick={onViewAll}>
								{__('Review automations →', 'vulopilot')}
							</button>
						),
					}))}
				/>
			)}
		</CardComponent>
	);
};

export default AutomationsAttentionCard;
