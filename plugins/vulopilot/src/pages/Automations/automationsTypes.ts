import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { TableRow } from '@zyra/table';

export interface AutomationRow extends TableRow {
	id: number;
	name: string;
	category: string;
	trigger_type: string;
	actions: string;
	conditions?: string | null;
	status: 'enabled' | 'disabled' | 'draft';
	last_triggered_at: string | null;
	last_run_status: 'running' | 'completed' | 'failed' | null;
	last_run_actions_executed: number | null;
	last_run_actions_failed: number | null;
	last_run_changes_made: number | null;
	/** Set for the 4 cron-based trigger types only. */
	next_run_at: string | null;
	last_run_finished_at: string | null;
}

export interface AutomationTemplate {
	id: string;
	icon: string;
	label: string;
	description: string;
	/** null = "Create from scratch" - no prefill, opens today's blank form. */
	category: 'monitoring' | 'security' | 'content' | 'commerce' | 'reporting' | 'custom' | null;
	triggerType: string | null;
	actionTypes: ('create-notification' | 'run-ai-action')[] | null;
	pro?: boolean;
	linkOnly?: boolean;
}

/**
 * The 2 real free built-in automations shown first
 * - see `AutomationTemplate.linkOnly`'s own docblock.
 */
const FREE_TEMPLATES: AutomationTemplate[] = [
	{
		id: 'run-full-site-scan',
		icon: 'search blue',
		label: __('Run Full Site Scan', 'vulopilot'),
		description: __('Scan your whole site on a schedule', 'vulopilot'),
		category: null,
		triggerType: null,
		actionTypes: null,
		linkOnly: true,
	},
	{
		id: 'send-visibility-report',
		icon: 'bar-chart green',
		label: __('Send Visibility Report', 'vulopilot'),
		description: __('Email your SEO visibility summary on a schedule', 'vulopilot'),
		category: null,
		triggerType: null,
		actionTypes: null,
		linkOnly: true,
	},
];

const PRO_TEMPLATE_SHELLS: AutomationTemplate[] = [
	{
		id: 'security-monitoring',
		icon: 'security yellow',
		label: __('Security monitoring', 'vulopilot'),
		description: __('Keep your site safe', 'vulopilot'),
		category: null,
		triggerType: null,
		actionTypes: null,
		pro: true,
	},
	{
		id: 'woocommerce-monitor',
		icon: 'woocommerce orange',
		label: __('WooCommerce monitor', 'vulopilot'),
		description: __('Keep your store running smoothly', 'vulopilot'),
		category: null,
		triggerType: null,
		actionTypes: null,
		pro: true,
	},
	{
		id: 'from-scratch',
		icon: 'plus rose',
		label: __('Create from scratch', 'vulopilot'),
		description: __('Build a custom automation', 'vulopilot'),
		category: null,
		triggerType: null,
		actionTypes: null,
		pro: true,
	},
];

export const getAutomationTemplates = (): AutomationTemplate[] =>
	applyFilters(
		'vulopilot_automation_templates',
		[...FREE_TEMPLATES, ...PRO_TEMPLATE_SHELLS]
	) as AutomationTemplate[];

export const getAutomationTemplateById = (id: string): AutomationTemplate | null =>
	getAutomationTemplates().find((template) => template.id === id) ?? null;
