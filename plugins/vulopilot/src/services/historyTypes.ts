import { __, sprintf } from '@wordpress/i18n';
import { formatWpDate, formatWpTime } from './formatWpDate';

/**
 * Shared `HistoryRow`/`toHistoryRow()` (a `vulopilot_ai_history` row → this shared day-grouped-
 * timeline row shape) behind every real History surface in this plugin.
 */
export type HistoryFilter = 'all' | 'conversation' | 'scan' | 'change' | 'automations';

export interface RelatedAction {
	id: number;
	label: string;
	created_at: string;
}

export interface ConversationDetail {
	id: number;
	/** Real AI credits this turn spent, or null for a row logged before this column existed. */
	credits_used: number | null;
	/** The request id for this turn, when the gateway that answered it returns one. */
	request_id: string | null;
	status: 'success' | 'failure';
	excerpt: string | null;
	/** The real, human-typed question this reply answers - null for any row logged before this column existed (AiRequestSender.php's own build_prompt_excerpt()), never fabricated. */
	prompt_excerpt: string | null;
	/** Real `ai_action.*` history rows this exact turn caused, if any. */
	related_actions: RelatedAction[];
}

export interface AffectedPage {
	id: number;
	title: string;
	link: string | null;
	edit_link: string | null;
	count: number;
}

export interface ScannedPage {
	id: number;
	title: string;
	link: string | null;
	edit_link: string;
}

export interface ScanDetail {
	id: number;
	scanner_id: string;
	label: string;
	status: string;
	trigger_type: string;
	duration_ms: number | null;
	by_severity: Record<string, number>;
	total: number;
	/** Real pages/posts this scan found an issue on, most-findings-first. */
	affected_pages: AffectedPage[];
	/** Real pages/posts this scan considered and found clean. */
	scanned_pages: ScannedPage[];
}

export interface ChangeDetail {
	id: number;
	action_id: string;
	label: string;
	status: 'pending_approval' | 'executed' | 'failed' | 'rejected' | 'rolled_back';
	before: string | null;
	after: string | null;
	format: 'text' | 'html' | 'json';
	error_message: string | null;
	page: string | null;
	/** 'auto_automation' when Automate Work's Auto-fix mode approved this run itself. */
	approval_method: 'manual' | 'auto_automation' | 'auto_unattended';
}

export interface HistoryRow {
	id: number | string;
	event_type: string;
	category: 'scan' | 'change' | 'conversation';
	message: string;
	severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
	created_at: string;
	scan: ScanDetail | null;
	change: ChangeDetail | null;
	conversation?: ConversationDetail | null;
}

export const FILTER_TABS: { id: HistoryFilter; label: string }[] = [
	// `HistoryFilter`/`activeFilter`'s own default state.
	{ id: 'all', label: __('All', 'vulopilot') },
	{ id: 'conversation', label: __('Conversations', 'vulopilot') },
	{ id: 'scan', label: __('Scans', 'vulopilot') },
	{ id: 'change', label: __('Changes', 'vulopilot') },
	{ id: 'automations', label: __('Automations', 'vulopilot') },
];

const CONTENT_CREATION_NOUNS: Record<string, string> = {
	'generate-blog': __('blog post', 'vulopilot'),
	'generate-landing-page': __('landing page', 'vulopilot'),
	'generate-product-description': __('product description', 'vulopilot'),
};

/**
 * A parsed orchestrator decision - the exact 3-shape JSON contract both Copilot.php and
 * ContentAssistant.php's system prompts require (`{"status":"question"|"ready_action"|"respond",
 * ...}`, ContentCreationOrchestrator::parse_response()'s own PHP-side contract).
 */
interface OrchestratorDecision {
	status: 'question' | 'ready_action' | 'respond';
	message?: string;
	action_id?: string;
}

/**
 * Turns a real `vulopilot_ai_history.response_excerpt` into what a human should actually read.
 */
export const humanizeConversationExcerpt = (
	excerpt: string | null,
	status: 'success' | 'failure'
): string => {
	if ('failure' === status) {
		return __('Something went wrong.', 'vulopilot');
	}

	if (!excerpt) {
		return __('(no reply)', 'vulopilot');
	}

	const trimmed = excerpt.trim();

	if (!trimmed.startsWith('{')) {
		return trimmed;
	}

	try {
		const decision = JSON.parse(trimmed) as OrchestratorDecision;

		if ('ready_action' === decision.status && decision.action_id) {
			const noun =
				CONTENT_CREATION_NOUNS[decision.action_id] ??
				__('piece of content', 'vulopilot');

			return sprintf(
				/* translators: %s: content type, e.g. "blog post" */
				__('Created a %s', 'vulopilot'),
				noun
			);
		}

		if (decision.message) {
			return decision.message;
		}
	} catch {
		// Either not actually the orchestrator's JSON shape, or - very commonly for a real
		// "respond" reply with substantial content.
		const messageMatch = trimmed.match(/"message"\s*:\s*"((?:[^"\\]|\\.)*)/);

		if (messageMatch) {
			const recovered = messageMatch[1]
				.replace(/\\n/g, ' ')
				.replace(/\\"/g, '"')
				.trim();

			// build_excerpt() (AiRequestSender.php) already appends its
			// own '…' when it truncates - don't double it up.
			return recovered.endsWith('…') ? recovered : recovered + '…';
		}
	}

	return trimmed;
};

const SCANNER_ACRONYMS: Record<string, string> = {
	ssl: 'SSL',
	seo: 'SEO',
	geo: 'GEO',
	aeo: 'AEO',
	cdn: 'CDN',
	css: 'CSS',
	php: 'PHP',
	ai: 'AI',
	llms: 'LLMs',
	txt: 'txt',
	wcag: 'WCAG',
	aria: 'ARIA',
};

/**
 * The compact "Recent activity" lists (dashboard widget, Security, …) feed rows with no scan join.
 */
const scannerTitleFromMessage = (row: HistoryRow): string | null => {
	if (!row.event_type.startsWith('scan.')) {
		return null;
	}

	const slug = /^Scan "([^"]+)"/.exec(row.message)?.[1];

	if (!slug) {
		return null;
	}

	return slug
		.split(/[-_]/)
		.map(
			(word) =>
				SCANNER_ACRONYMS[word] ??
				word.charAt(0).toUpperCase() + word.slice(1)
		)
		.join(' ');
};

/**
 * Every real row title comes straight from its real scan/change/ conversation label - never
 * invented copy.
 */
export const rowTitle = (row: HistoryRow): string => {
	if (row.scan) {
		return row.scan.label;
	}

	if (row.change) {
		return row.change.label;
	}

	if (row.conversation) {
		return humanizeConversationExcerpt(
			row.conversation.excerpt,
			row.conversation.status
		);
	}

	return scannerTitleFromMessage(row) ?? row.message;
};

const CHANGE_ICON_BY_EVENT: Record<string, string> = {
	'ai_action.proposed': 'clock yellow',
	'ai_action.executed': 'check purple',
	'ai_action.failed': 'error red',
	'ai_action.rejected': 'close red',
	'ai_action.rolled_back': 'undo pink',
	// Synthesized by `toHistoryRow()` below for a real `GET /automation-runs` row
	// (AutomationsActivityCard.tsx).
	'automation.completed': 'check green',
	'automation.failed': 'error red',
	'automation.running': 'clock gray',
};

export const rowIcon = (row: HistoryRow): string => {
	if ('scan' === row.category) {
		return 'search blue';
	}

	if ('conversation' === row.category) {
		return 'live-chat purple';
	}

	return CHANGE_ICON_BY_EVENT[row.event_type] ?? 'update';
};

/**
 * The mockup's bottom-left pale category tag ("Conversation"/"Scan"/ "Change"/"Automation").
 */
export const rowTag = (row: HistoryRow): { text: string; className: string } => {
	if ('scan' === row.category) {
		return { text: __('Scan', 'vulopilot'), className: 'blue' };
	}

	if ('conversation' === row.category) {
		return { text: __('Conversation', 'vulopilot'), className: 'purple' };
	}

	return { text: __('Change', 'vulopilot'), className: 'green' };
};

const CHANGE_STATUS_BADGE_BY_EVENT: Record<string, string> = {
	'ai_action.proposed': __('Proposed', 'vulopilot'),
	'ai_action.executed': __('Applied', 'vulopilot'),
	'ai_action.failed': __('Failed', 'vulopilot'),
	'ai_action.rejected': __('Rejected', 'vulopilot'),
	'ai_action.rolled_back': __('Rolled back', 'vulopilot'),
	'automation.completed': __('Completed', 'vulopilot'),
	'automation.failed': __('Failed', 'vulopilot'),
	'automation.running': __('Running', 'vulopilot'),
};

/**
 * The mockup's top-right status pill ("Applied") - change rows only.
 */
export const rowStatusBadge = (
	row: HistoryRow
): { text: string; className: string } | null => {
	if ('change' !== row.category) {
		return null;
	}

	const text = CHANGE_STATUS_BADGE_BY_EVENT[row.event_type] ?? row.event_type;
	const classByEvent: Record<string, string> = {
		'ai_action.proposed': 'yellow',
		'ai_action.executed': 'green',
		'ai_action.failed': 'red',
		'ai_action.rejected': 'grey',
		'ai_action.rolled_back': 'grey',
		'automation.completed': 'green',
		'automation.failed': 'red',
		'automation.running': 'grey',
	};

	return { text, className: classByEvent[row.event_type] ?? 'grey' };
};

/**
 * Real time-of-day, in this site's own real Settings → General → Time Format (`formatWpTime()`,
 * previously a hardcoded `toLocaleTimeString()` that ignored that setting) - the day heading
 * already carries the date, so the row itself only needs the time.
 */
export const rowTime = (createdAt: string): string => formatWpTime(createdAt);

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * "Today"/"Yesterday"/this site's own real Settings → General → Date Format (`formatWpDate()`,
 * previously a hardcoded `toLocaleDateString()` that ignored that setting - same real fix
 * `rowTime()` above already applies for the time half).
 */
export const dayLabel = (createdAt: string): string => {
	const date = new Date(createdAt);
	const today = new Date();
	const startOfToday = new Date(
		today.getFullYear(),
		today.getMonth(),
		today.getDate()
	);
	const startOfRow = new Date(
		date.getFullYear(),
		date.getMonth(),
		date.getDate()
	);
	const diffDays = Math.round(
		(startOfToday.getTime() - startOfRow.getTime()) / DAY_MS
	);

	if (0 === diffDays) {
		return __('Today', 'vulopilot');
	}

	if (1 === diffDays) {
		return __('Yesterday', 'vulopilot');
	}

	return formatWpDate(createdAt);
};

/**
 * Groups already-desc-sorted rows into consecutive day buckets.
 */
export const groupByDay = (
	rows: HistoryRow[]
): { label: string; rows: HistoryRow[] }[] => {
	const groups: { label: string; rows: HistoryRow[] }[] = [];

	rows.forEach((row) => {
		const label = dayLabel(row.created_at);
		const lastGroup = groups[groups.length - 1];

		if (lastGroup && lastGroup.label === label) {
			lastGroup.rows.push(row);
		} else {
			groups.push({ label, rows: [row] });
		}
	});

	return groups;
};

/**
 * Adapts an activity-style row (from `GET /activity-logs` or `GET /automation-runs`) into a
 * `HistoryRow` for HistoryTimeline.tsx.
 */
export const toHistoryRow = (row: {
	id: number | string;
	message: string;
	created_at: string;
	/** Real event type this row's own source table already carries (`activity-logs`). */
	event_type?: string;
}): HistoryRow => ({
	id: row.id,
	event_type: row.event_type ?? '',
	category: row.event_type?.startsWith('scan.') ? 'scan' : 'change',
	message: row.message,
	severity: 'info',
	created_at: row.created_at,
	scan: null,
	change: null,
});
