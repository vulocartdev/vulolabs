/* global vulopilotAppLocalizer */
import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { getApiLink, sendApiResponse } from '@zyra/core';
import { ListComponent, ModuleGuardComponent, BadgeComponent } from '@zyra/components';
import { ButtonInput, MultiCheckboxInput } from '@zyra/inputs';
import DashboardWidget from './DashboardWidget';
import { useApiList } from '../services/useApiList';
import { WidgetProps } from './types';

interface AutomationRow {
	id: number;
	name: string;
	status: 'enabled' | 'disabled';
	trigger_type: string;
}

/** Free ships exactly these 2 built-in automations (Automations\BuiltinAutomationSeeder). */
const BUILTIN_ROWS = [
	{
		trigger: 'free_visibility_report',
		icon: 'mail',
		desc: __("Receive a summary of your website's visibility, issues, and opportunities.", 'vulopilot'),
	},
	{
		trigger: 'free_full_site_scan',
		icon: 'search blue',
		desc: __('Automatically scan your website and refresh your VuloPilot insights.', 'vulopilot'),
	},
];

const AutomationStatusWidget: React.FC<WidgetProps> = ({
	isLoading,
	onHide,
	isCustomizing,
	onRefreshSummary,
}) => {
	const {
		data,
		isLoading: isListLoading,
		error,
		refetch,
	} = useApiList<AutomationRow>('automations', { per_page: 100 });

	const rows = BUILTIN_ROWS.map((builtin) => ({
		...builtin,
		row: data.find((item) => item.trigger_type === builtin.trigger),
	})).filter((item) => item.row);
	const enabledCount = rows.filter((item) => 'enabled' === item.row?.status).length;

	// Same real `PATCH /automations/{id}` toggle BuiltinAutomationCards.tsx's
	// own `handleToggle` already uses - reused here rather than a second path.
	const handleToggle = (row: AutomationRow) => {
		sendApiResponse(
			vulopilotAppLocalizer,
			getApiLink(vulopilotAppLocalizer, `automations/${row.id}`),
			{ status: 'enabled' === row.status ? 'disabled' : 'enabled' }
		).then(() => {
			refetch();
			// Keeps sibling payloads (`summary.automation_status`) in step - see
			// `onRefreshSummary`'s own docblock (types.ts).
			onRefreshSummary();
		});
	};

	return (
		<DashboardWidget
			title={
				<>
					{__('Automation status', 'vulopilot')}
					<BadgeComponent
						color="green"
						text={sprintf(
							/* translators: %d: number of enabled built-in automations. */
							__('%d Enabled', 'vulopilot'),
							enabledCount
						)}
					/>
				</>
			}
			desc={__('Which of your automations are enabled and running.', 'vulopilot')}
			icon="toggle"
			isLoading={isLoading}
			onHide={onHide}
			isCustomizing={isCustomizing}
			headerAction={
				<ButtonInput
					buttons={{
						text: __('Manage', 'vulopilot'),
						rightIcon: 'pagination-right-arrow',
						color: 'text-purple',
						onClick: () => {
							window.location.hash = '#&tab=automations';
						},
					}}
				/>
			}
		>
			{error ? (
				<ModuleGuardComponent
					icon="error"
					title={__('Could not load automations', 'vulopilot')}
					desc={error}
				/>
			) : !isListLoading && 0 === rows.length ? (
				<ModuleGuardComponent
					icon="automation"
					title={__('No automations yet', 'vulopilot')}
					desc={__(
						'Open the Automations page to set up your built-in automations.',
						'vulopilot'
					)}
				/>
			) : (
				<ListComponent
					className="mini-card report"
					items={rows.map(({ trigger, icon, desc, row }) => {
						const automation = row as AutomationRow;
						const isEnabled = 'enabled' === automation.status;

						return {
							id: trigger,
							icon,
							title: automation.name,
							desc,
							tags: (
								<>
									<BadgeComponent
										color={isEnabled ? 'green' : 'blue'}
										text={isEnabled ? __('Enabled', 'vulopilot') : __('Not active', 'vulopilot')}
									/>
									<MultiCheckboxInput
										look="toggle"
										options={[
											{
												key: `automation-${automation.id}-enabled`,
												value: 'enabled',
												label: '',
											},
										]}
										value={isEnabled ? ['enabled'] : []}
										onChange={() => handleToggle(automation)}
									/>
								</>
							),
						};
					})}
				/>
			)}
			{!vulopilotAppLocalizer.khali_dabba && (
				<div className="automation-upgrade-banner">
					<i className="adminfont-pro-tab automation-upgrade-banner-icon" />
					<div className="automation-upgrade-banner-text">
						<div className="automation-upgrade-banner-title">
							{__('Want more automations?', 'vulopilot')}
						</div>
						<div className="desc">
							{__('VuloPilot Pro adds custom and AI-powered automations on top of the two included here.', 'vulopilot')}
						</div>
					</div>
					<ButtonInput
						buttons={{
							text: __('Upgrade to Pro', 'vulopilot'),
							rightIcon: 'arrow-right',
							onClick: () =>
								window.open(vulopilotAppLocalizer.shop_url, '_blank', 'noopener,noreferrer'),
						}}
					/>
				</div>
			)}
		</DashboardWidget>
	);
};

export default AutomationStatusWidget;
