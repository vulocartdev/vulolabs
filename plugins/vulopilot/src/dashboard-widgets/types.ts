/**
 * Shared shapes for the Dashboard's widget system.
 */
import React from 'react';

export interface DashboardSummary {
	overall_score: number;
	open_findings: number;
	critical_findings: number;
	findings_by_severity: {
		critical: number;
		high: number;
		medium: number;
		low: number;
	};
	active_automations: number;
	ai_jobs_used: number;
	ai_jobs_quota: number;
	category_scores: {
		seo: number;
		performance: number;
		security: number;
		accessibility: number;
		woocommerce: number | null;
		geo: number;
		content: number;
		brand: number;
	};
	/**
	 * Same 8 keys as `category_scores`, reconstructed as of 7 days ago (Dashboard controller's
	 * build_category_scores_as_of()).
	 */
	category_scores_7d_ago: {
		seo: number;
		performance: number;
		security: number;
		accessibility: number;
		woocommerce: number | null;
		geo: number;
		content: number;
		brand: number;
	};
	/** Real findings first detected in the last 7 days - Dashboard's "N new issues" hero badge. */
	new_findings_this_week: number;
	/** Real findings resolved in the last 7 days - Dashboard's "N fixed" hero badge. */
	fixed_findings_this_week: number;
	quick_fixes: number;
	pending_approvals: number;
	automation_status: {
		enabled: number;
		disabled: number;
	};
	/** Real WP core counts (Dashboard controller's build_site_snapshot()). */
	site_snapshot: {
		posts: number;
		pages: number;
		comments: number;
		users: number;
		plugins_active: number;
		plugins_total: number;
		wp_version: string;
		php_version: string;
	};
}

export interface WidgetProps {
	summary: DashboardSummary;
	isLoading: boolean;
	/** Removes this widget from the visible grid - DashboardGrid.tsx supplies the real handler, which toggles `enabled: false` in the saved layout. */
	onHide: () => void;
	/** Whether Dashboard.tsx's "Customize dashboard" mode is on. */
	isCustomizing: boolean;
	/**
	 * Re-fetches `summary` (Dashboard.tsx's own `loadDashboard`).
	 */
	onRefreshSummary: () => void;
}

/**
 * What a widget registers with the grid.
 */
export interface WidgetDefinition {
	id: string;
	title: string;
	desc?: string;
	icon: string;
	grid: number;
	component: React.ComponentType<WidgetProps>;
}

/** One entry in the persisted layout - GET/POST `/dashboard-layout`. */
export interface WidgetLayoutEntry {
	id: string;
	enabled: boolean;
}

/** Shared "last N days" trend-period selector value, used by this folder's trend-chart widgets. */
export type PeriodDays = '7' | '30' | '90';
