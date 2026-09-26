import { __, sprintf } from '@wordpress/i18n';
import { formatWpDate, formatWpTime } from './formatWpDate';

/**
 * Shared `HistoryRow`/`toHistoryRow()` (a `vulopilot_ai_history` row →
 * this shared day-grouped-timeline row shape) behind every real History
 * surface in this plugin: Reports' own History tab (HistoryTab.tsx/
 * HistoryTimeline.tsx/HistoryDetailPanel.tsx), GEO's OverviewTab.tsx,
 * Security's RecentActivityCard.tsx, Automations' AutomationsActivityCard.tsx,
 * and the Dashboard's RecentActivityWidget.tsx. Moved out of
 * `pages/AIAssistant/` into this plain-utility `services/` folder (same
 * home `formatWpDate.ts`/`getSeverityClass.ts` already have) per direct
 * instruction - it was never actually AI-Copilot-specific, just
 * historically created there.
 */
export type HistoryFilter = 'all' | 'conversation' | 'scan' | 'change' | 'automations';

export interface RelatedAction {
	id: number;
	label: string;
	created_at: string;
}

export interface ConversationDetail {
	id: number;
	/** Real AI credits this turn spent, or null for a row logged before this column existed. `0` is a genuine value (the free direct AI path reports no credits), never fabricated. */
	credits_used: number | null;
	/** The request id for this turn, when the gateway that answered it returns one. */
	request_id: string | null;
	status: 'success' | 'failure';
	excerpt: string | null;
	/** The real, human-typed question this reply answers - null for any row logged before this column existed (AiRequestSender.php's own build_prompt_excerpt()), never fabricated. */
	prompt_excerpt: string | null;
	/** Real `ai_action.*` history rows this exact turn caused, if any - see Controllers/History.php's own build_related_actions() for why this can be real and tightly matched rather than a fuzzy guess. Empty for the overwhelming majority of turns (a plain question causes no action). */
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
	/** Real pages/posts this scan found an issue on, most-findings-first, plus a trailing `id: 0`/"Site-wide" entry when any findings aren't page-scoped - see Controllers/History.php's own build_affected_pages(). */
	affected_pages: AffectedPage[];
	/** Real pages/posts this scan considered and found clean - only ever populated when `total === 0` (a scan that DID find issues already has them in affected_pages above), and only for scans persisted after `vulopilot_scans.scanned_objects` existed - see Controllers/History.php's own build_scanned_pages(). */
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
	/** 'auto_automation' when Automate Work's Auto-fix mode approved this run itself, 'auto_unattended' when Settings → Automation → Approval Settings' own risk-based/"Do not ask" mode did (ActionRunner::approve()'s own $method param - see that method's docblock for why these are two distinct values, not one generic 'auto') - real rows from before this column existed, or from before the second value existed, fall back to 'manual'/'auto_automation' respectively. */
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
	// `HistoryFilter`/`activeFilter`'s own default state, and every real
	// consumer's own fetch (e.g. HistoryTab.tsx's own `fetchPage()`:
	// `'all' !== activeFilter && params.set('type', activeFilter)`),
	// already treat `'all'` as the real no-filter sentinel - this was just
	// the missing pill for it, same gap SlowPagesTab.tsx's own
	// `statusCategoryCounts` had.
	{ id: 'all', label: __('All', 'vulopilot') },
	{ id: 'conversation', label: __('Conversations', 'vulopilot') },
	{ id: 'scan', label: __('Scans', 'vulopilot') },
	{ id: 'change', label: __('Changes', 'vulopilot') },
	{ id: 'automations', label: __('Automations', 'vulopilot') },
];

/**
 * `ContentCreationOrchestrator::CONTENT_CREATION_ACTIONS`'s own noun
 * values (`classes/AIActions/ContentCreationOrchestrator.php`) - kept in
 * sync by hand, the same way that PHP class's own docblock already keeps
 * its `action_id` whitelist in sync with each chat controller's system
 * prompt. Only used to build a "Created a {noun}" label below; never sent
 * to the server.
 */
const CONTENT_CREATION_NOUNS: Record<string, string> = {
	'generate-blog': __('blog post', 'vulopilot'),
	'generate-landing-page': __('landing page', 'vulopilot'),
	'generate-product-description': __('product description', 'vulopilot'),
};

/**
 * A parsed orchestrator decision - the exact 3-shape JSON contract both
 * Copilot.php and ContentAssistant.php's system prompts require
 * (`{"status":"question"|"ready_action"|"respond", ...}`,
 * ContentCreationOrchestrator::parse_response()'s own PHP-side contract).
 */
interface OrchestratorDecision {
	status: 'question' | 'ready_action' | 'respond';
	message?: string;
	action_id?: string;
}

/**
 * Turns a real `vulopilot_ai_history.response_excerpt` into what a human
 * should actually read - never the raw text verbatim, since a real chat
 * turn's own "reply" is the orchestrator's strict JSON decision, not
 * natural language (e.g. `{"status":"ready_action","action_id":
 * "generate-blog",...}` for a turn that created a blog post). Falls back
 * to the excerpt as-is for anything that isn't that JSON shape (a plain
 * AI reply already IS natural language) - never invents wording for real
 * content, only unwraps a structure that was never meant to be read
 * verbatim.
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
		// Either not actually the orchestrator's JSON shape, or - very
		// commonly for a real "respond" reply with substantial content -
		// genuinely valid JSON that AiRequestSender::build_excerpt()
		// truncated to its own 300-char audit-trail cap, cutting off the
		// closing `"}` and leaving unparseable JSON. That truncation only
		// ever lands inside the "message" field's own text (the only long
		// string value this shape ever has), so recover that field with a
		// regex instead of showing broken JSON syntax - real text, just
		// pulled out without a full parse.
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
 * The compact "Recent activity" lists (dashboard widget, Security, …) feed
 * rows with no scan join, so a scan row would otherwise be titled with its
 * raw log message (`Scan "about-page-analysis" completed with 0
 * finding(s).`). Recovers a readable scanner name from that message so
 * these rows read like the full History tab's: "About Page Analysis" as
 * the title, the message as the description. Returns null when the message
 * isn't in that shape.
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
 * Every real row title comes straight from its real scan/change/
 * conversation label - never invented copy. `scan` rows always carry a
 * real ScanDetail (their REST enrichment only ever fails silently to
 * `null` if the source scan row was deleted after the fact - genuinely
 * rare, and there's no honest title to show for a vanished source row
 * either way).
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
	// Synthesized by `toHistoryRow()` below for a real `GET /automation-runs`
	// row (AutomationsActivityCard.tsx) - that endpoint's own real `status`
	// column, not a fabricated event type.
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
 * The mockup's bottom-left pale category tag ("Conversation"/"Scan"/
 * "Change"/"Automation") - always shown, one per row, real category only
 * (never "Scan Result"/"Automation" for a row that's actually a plain
 * scan/change, since those extra mockup categories aren't real event
 * types this table has - see Controllers/History.php's own docblock).
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
 * The mockup's top-right status pill ("Applied") - change rows only, no
 * scan-row equivalent (a scan's own real outcome is its issue count,
 * shown via rowMeta() instead, matching the mockup's own "24 issues
 * found" placement there rather than a redundant pill). Reflects what
 * happened AT this specific timeline event (its own `event_type`), not
 * the joined run's current status - a "proposed" entry for a run that
 * was later executed still honestly reads "Proposed" here, since that's
 * what this row is a record of.
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
 * Real time-of-day, in this site's own real Settings → General → Time
 * Format (`formatWpTime()`, previously a hardcoded `toLocaleTimeString()`
 * that ignored that setting) - the day heading already carries the date,
 * so the row itself only needs the time.
 */
export const rowTime = (createdAt: string): string => formatWpTime(createdAt);

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * "Today"/"Yesterday"/this site's own real Settings → General → Date
 * Format (`formatWpDate()`, previously a hardcoded `toLocaleDateString()`
 * that ignored that setting - same real fix `rowTime()` above already
 * applies for the time half). Same local-time-from-a-MySQL-datetime-string
 * handling formatWpDate.ts already relies on (`new Date('2026-08-10
 * 07:40:56')` parses as local time, no 'T'/'Z').
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
 * Groups already-desc-sorted rows into consecutive day buckets - the
 * grouping itself is done client-side (same precedent as
 * HealthTimelineWidget.tsx's own client-side day-grouping of snapshot
 * rows), since the REST endpoint stays a flat, ordinary paginated list.
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
 * Adapts a plain, real activity-style row (`GET /activity-logs`'s own
 * `ActivityLogRow`, `GET /automation-runs`'s own `AutomationRunRow`, …)
 * into a real `HistoryRow` HistoryTimeline.tsx can render - used by the
 * several real "recent activity" widgets that share that component now
 * (RecentActivityCard.tsx, RecentActivityWidget.tsx, AutomationsActivityCard.tsx,
 * GEO's OverviewTab.tsx) rather than each re-deriving this mapping.
 *
 * `category` is inferred from the row's own real `event_type` prefix -
 * `scan.*` → 'scan', everything else → 'change' (the same fallback
 * `rowTag`/`rowIcon` above already apply to any change-category row whose
 * specific `event_type` isn't one of the few they special-case, e.g.
 * `critical_alert`). None of these source endpoints joins back to a real
 * scan/change detail row the way `GET /history` does, so `scan`/`change`
 * are honestly `null` here - `rowTitle()` already falls back to
 * `row.message` for that case, and the "N issues found"/before-after meta
 * lines already only render when `row.scan`/`row.change` are actually
 * present, so nothing here is fabricated to fill a gap that doesn't exist.
 * `severity` is a required `HistoryRow` field with nothing real to read
 * from these rows either - set to 'info' but never actually rendered
 * (confirmed: no function in this file reads `HistoryRow.severity`).
 */
export const toHistoryRow = (row: {
	id: number | string;
	message: string;
	created_at: string;
	/** Real event type this row's own source table already carries (`activity-logs`), or a caller-synthesized one built from a real status field it does carry instead (`automation-runs` has no `event_type` of its own - see AutomationsActivityCard.tsx's own mapping). */
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
