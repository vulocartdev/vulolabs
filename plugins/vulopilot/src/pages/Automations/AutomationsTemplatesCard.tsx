/* global vulopilotAppLocalizer */
import React, { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { CardComponent, ListComponent, PopupComponent } from '@zyra/components';
import ShowProPopup from '../../components/Popup/Popup';
import { getAutomationTemplates, AutomationTemplate } from './automationsTypes';

const AUTOMATIONS_MODULE_ID = 'workflow-automation';

interface AutomationsTemplatesCardProps {
	 
	// eslint-disable-next-line no-unused-vars
	onSelectTemplate: (template: AutomationTemplate) => void;
	isAutomationsActive: boolean;
}

/**
 * "Create new automation" - a real entry point into Automate Work's
 * existing create-automation flow, not a decorative mockup.
 */
const AutomationsTemplatesCard: React.FC<AutomationsTemplatesCardProps> = ({
	onSelectTemplate,
	isAutomationsActive,
}) => {
	const [templates, setTemplates] = useState<AutomationTemplate[]>(() =>
		getAutomationTemplates()
	);

	useEffect(() => {
		const recheck = () => setTemplates(getAutomationTemplates());

		recheck();

		window.addEventListener('vulopilot_pro_modules_loaded', recheck);
		return () =>
			window.removeEventListener('vulopilot_pro_modules_loaded', recheck);
	}, []);

	const isProInstalled = Boolean(vulopilotAppLocalizer.khali_dabba);

	const [lockReason, setLockReason] = useState<'pro' | 'module' | null>(null);
	const dismissLock = () => setLockReason(null);

	const handleItemClick = (template: AutomationTemplate) => {
		if (template.linkOnly) {
			window.location.hash = `#&tab=automations&automation_template=${template.id}`;
			return;
		}

		if (!template.pro) {
			onSelectTemplate(template);
			return;
		}

		if (!isProInstalled) {
			setLockReason('pro');
			return;
		}

		if (!isAutomationsActive) {
			setLockReason('module');
			return;
		}

		onSelectTemplate(template);
	};

	return (
		<div id="create-new-automation-card">
			<CardComponent
				title={ __( 'Create new automation', 'vulopilot' ) } titleIcon="analytics"
				desc={ __( 'Start from a ready-made template instead of building one from scratch.', 'vulopilot' ) }
			>
				<ListComponent
					className="mini-card report"
					items={templates.map((template) => ({
						id: template.id,
						icon: template.icon,
						title: template.label,
						desc: template.description,
						tags: (
							<>
								{template.pro && !isProInstalled && (
									<span className="admin-tag pro-tag pro-tag-inline">
										<i className="adminfont-pro-tag" />
										{__('Pro', 'vulopilot')}
									</span>
								)}
								<i className="adminfont-plus ai-copilot-row-arrow" />
							</>
						),
						action: () => handleItemClick(template),
					}))}
				/>
			</CardComponent>
			<PopupComponent
				open={null !== lockReason}
				onClose={dismissLock}
				width={31.25}
				height="auto"
				position="lightbox"
			>
				{'module' === lockReason ? (
					<ShowProPopup moduleName={AUTOMATIONS_MODULE_ID} />
				) : (
					<ShowProPopup />
				)}
			</PopupComponent>
		</div>
	);
};

export default AutomationsTemplatesCard;
