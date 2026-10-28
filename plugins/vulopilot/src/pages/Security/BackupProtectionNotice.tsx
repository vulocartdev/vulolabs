/* global vulopilotAppLocalizer */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { getApiLink, getApiResponse } from '@zyra/core';
import InfoBanner from '../../components/InfoBanner';

const BACKUPS_TAB_HASH = '#&tab=site-health&subtab=backups';

/**
 * "Backup protection: Enabled/Not enabled" - a single real status line with a link.
 */
interface BackupProtectionNoticeProps {
	/**
	 * Switches this same page's own real "Backups" tab in place instead of navigating.
	 */
	onNavigateToBackups?: () => void;
}

const BackupProtectionNotice = ({
	onNavigateToBackups,
}: BackupProtectionNoticeProps = {}) => {
	const [isEnabled, setIsEnabled] = useState<boolean | null>(null);

	useEffect(() => {
		getApiResponse<{ enable_automatic_backups?: string[] }>(
			getApiLink(vulopilotAppLocalizer, 'settings'),
			{ headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } }
		).then((response) => {
			if (response) {
				setIsEnabled(
					Array.isArray(response.enable_automatic_backups) &&
						response.enable_automatic_backups.length > 0
				);
			}
		});
	}, []);

	if (null === isEnabled) {
		return null;
	}

	return (
		<InfoBanner
			icon={isEnabled ? 'check' : 'info'}
			title={
				isEnabled
					? __('Backup protection: Enabled', 'vulopilot')
					: __('Backup protection: Not enabled', 'vulopilot')
			}
			desc={
				isEnabled
					? __('Your site is being backed up automatically.', 'vulopilot')
					: __('Your site is not currently backed up. Enable backup protection to keep your data safe.', 'vulopilot')
			}
			actionLabel={__('View Backups', 'vulopilot')}
			onAction={
				onNavigateToBackups ??
				(() => {
					window.location.hash = BACKUPS_TAB_HASH;
				})
			}
		/>
	);
};

export default BackupProtectionNotice;
