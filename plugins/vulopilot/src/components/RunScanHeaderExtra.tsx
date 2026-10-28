import { __, sprintf } from '@wordpress/i18n';
import { ButtonInput } from '@zyra/inputs';
import { useRunScan } from '../services/useRunScan';
import { useLastScanTime } from '../services/useLastScanTime';
import { formatWpDate, formatWpTime } from '../services/formatWpDate';
import './RunScanHeaderExtra.scss';

interface RunScanHeaderExtraProps {
	/** Category ids passed to `useRunScan` - omit for a site-wide "Run scan". */
	categories?: string[];
	/** Settings subtab id this page's scan settings live on. */
	settingsSubtab: string;
	/** Drops the gear/settings button beside "Run scan". */
	hideSettingsButton?: boolean;
	/** Drops the "Run scan" button itself; settings gear, "Last scan" caption, and trailingButtons stay. */
	hideRunScanButton?: boolean;
	/** Renders in "Run scan"'s place, same button-row slot. */
	replaceRunScanButton?: {
		text: string;
		icon: string;
		color?: string;
		onClick: () => void;
	};
	/** Overrides the button's idle-state label. */
	label?: string;
	/** Called after a scan completes, for a page's own refetch. */
	onSuccess?: () => void;
	/** Extra icon-only buttons appended after "Run scan"/the settings gear, in the same row. */
	trailingButtons?: {
		icon: string;
		color?: string;
		text?: string;
		tooltip?: string;
		onClick: () => void;
	}[];
}

/** The "Run scan"/gear-icon/"Last scan" cluster shown in each category page's header. */
const RunScanHeaderExtra = ({
	categories,
	settingsSubtab,
	hideSettingsButton = false,
	hideRunScanButton = false,
	replaceRunScanButton,
	label,
	onSuccess,
	trailingButtons = [],
}: RunScanHeaderExtraProps) => {
	const { isScanning, runScanButton } = useRunScan({ categories, onSuccess });
	const { lastScanAt, isLoading } = useLastScanTime(undefined, categories);

	return (
		<div className="run-scan-header-extra">
				<ButtonInput
					buttons={[
						...(hideRunScanButton
							? replaceRunScanButton
								? [
										{
											text: replaceRunScanButton.text,
											icon: replaceRunScanButton.icon,
											color: replaceRunScanButton.color ?? 'purple-bg',
											onClick: replaceRunScanButton.onClick,
										},
									]
								: []
							: [
									{
										text:
											!isScanning && label
												? label
												: runScanButton.label,
										icon: runScanButton.icon,
										color: 'purple-bg',
										onClick: runScanButton.onClick,
									},
								]),
						...(hideSettingsButton
							? []
							: [
									{
										text: '',
										icon: 'setting',
										color: 'text-purple icon',
										tooltip: __('Scan settings', 'vulopilot'),
										onClick: () => {
											window.location.hash = `#&tab=settings&subtab=${settingsSubtab}`;
										},
									},
								]),
						...trailingButtons.map((button) => ({
							text: button.text ?? '',
							icon: button.icon,
							color: button.color,
							tooltip: button.tooltip,
							onClick: button.onClick,
						})),
					]}
				/>
			{!isLoading && lastScanAt && (
				<div className="run-scan-header-extra-last-scan desc">
					{sprintf(
						/* translators: 1: formatted date, 2: formatted time. */
						__('Last scan: %1$s • %2$s', 'vulopilot'),
						formatWpDate(lastScanAt),
						formatWpTime(lastScanAt)
					)}
				</div>
			)}
		</div>
	);
};

export default RunScanHeaderExtra;
