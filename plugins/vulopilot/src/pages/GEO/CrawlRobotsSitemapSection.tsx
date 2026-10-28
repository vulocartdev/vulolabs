/* global vulopilotAppLocalizer */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { getApiLink, getApiResponse, sendApiResponse } from '@zyra/core';
import {
	BadgeComponent,
	CardComponent,
	ColumnComponent,
	ContainerComponent,
	IconComponent,
	ListComponent,
	ModuleGuardComponent,
	NoticeManager,
	PopupComponent,
	SectionComponent
} from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import { TableCard, TableRow } from '@zyra/table';
import TypographyComponent from '../../components/TypographyComponent';
import { useFindingsTable } from '../../services/useFindingsTable';
import ShowProPopup from '../../components/Popup/Popup';
import './SeoVisibility.scss';

const nonceHeaders = { headers: { 'X-WP-Nonce': vulopilotAppLocalizer.nonce } };

interface RobotsResponse {
	reachable: boolean;
	url: string;
	content: string;
	is_custom: boolean;
	custom_content: string;
	rules: { total: number; allowed: number; disallowed: number; sitemaps: number };
	directives: {
		user_agents: string[];
		allow: string[];
		disallow: string[];
		sitemaps: string[];
		crawl_delay: string | null;
	};
}

interface SitemapChild {
	loc: string;
	type: string;
	lastmod: string | null;
	url_count: number | null;
	status: 'ok' | 'error';
}

interface SitemapResponse {
	reachable: boolean;
	index_url: string;
	valid: boolean;
	total_sitemaps: number;
	total_urls: number;
	sitemaps: SitemapChild[];
}

interface SitemapRow extends TableRow, SitemapChild {
	id: string;
}

/**
 * "Blocked Pages", "Robots.txt Issues", and "XML Sitemap Issues" are all registered by
 * modules/Seo/Module.php.
 */
const isSeoModuleActive = () =>
	vulopilotAppLocalizer.active_modules?.includes('technical-seo') ?? false;

/**
 * Real sitemap `loc` URL, stripped down to just its own path/name for display.
 */
const getSitemapDisplayName = (loc: string): string => {
	try {
		const { pathname } = new URL(loc);

		const name = pathname
			.replace(/-\d+(?=\.xml$)/i, '')
			.replace(/\.xml$/i, '')
			.replace(/^\/+|\/+$/g, '')
			.replace(/-/g, ' ');

		if (!name) {
			return 'Sitemap';
		}

		return name.charAt(0).toUpperCase() + name.slice(1);
	} catch {
		return loc;
	}
};

const escapeHtml = (text: string): string =>
	text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * Real, per-line syntax highlight for the robots.txt editor below.
 */
const highlightRobotsLine = (line: string): string => {
	if (/^\s*#/.test(line)) {
		return `<span class="rt-comment">${escapeHtml(line)}</span>`;
	}

	const match = line.match(/^(\s*)([A-Za-z][\w-]*)(\s*:\s*)(.*)$/);

	if (!match) {
		return escapeHtml(line);
	}

	const [, leadingSpace, directive, colon, value] = match;

	return (
		escapeHtml(leadingSpace) +
		`<span class="rt-directive">${escapeHtml(directive)}</span>` +
		escapeHtml(colon) +
		(value ? `<span class="rt-value">${escapeHtml(value)}</span>` : '')
	);
};

/**
 * Line-numbered, syntax-highlighted robots.txt editor.
 */
interface RobotsTxtEditorProps {
	value: string;
	// eslint-disable-next-line no-unused-vars
	onChange?: (next: string) => void;
	placeholder?: string;
	readOnly?: boolean;
}

export const RobotsTxtEditor = ({ value, onChange, placeholder, readOnly = false }: RobotsTxtEditorProps) => {
	// Gutter/height track the real placeholder's own line count while empty.
	const lineCount = Math.max((value || placeholder || '').split('\n').length, readOnly ? 1 : 6);
	const highlighted = value ? value.split('\n').map(highlightRobotsLine).join('\n') : '';

	return (
		<div className={`rt-editor${readOnly ? ' rt-editor-readonly' : ''}`}>
			<div className="rt-gutter" aria-hidden="true">
				{Array.from({ length: lineCount }).map((_, i) => (
					<span key={i}>{i + 1}</span>
				))}
			</div>
			<div className="rt-code-wrap">
				<pre
					className="rt-highlight"
					aria-hidden="true"
					// Real highlight markup built entirely from `escapeHtml`'d
					// content above - never raw user input.
					dangerouslySetInnerHTML={{ __html: highlighted || '&nbsp;' }}
				/>
				{!readOnly && (
					<textarea
						className="rt-textarea"
						value={value}
						rows={lineCount}
						spellCheck={false}
						placeholder={placeholder}
						onChange={(e) => onChange?.(e.target.value)}
					/>
				)}
			</div>
		</div>
	);
};

/**
 * "Robots & Sitemap" section of the Crawl & URLs tab: robots.txt analysis and editing, sitemap
 * overview, blocked pages and llms.txt content.
 */
const CrawlRobotsSitemapSection = () => {
	const [robots, setRobots] = useState<RobotsResponse | null>(null);
	const [isLoadingRobots, setIsLoadingRobots] = useState(true);
	const [sitemap, setSitemap] = useState<SitemapResponse | null>(null);
	const [isLoadingSitemap, setIsLoadingSitemap] = useState(true);


	/**
	 * `useFindingsTable`'s own `tableCardProps.totalRows` counts every status
	 * (open/resolved/ignored/snoozed).
	 */
	const [blockedPagesOpenCount, setBlockedPagesOpenCount] = useState(0);

	const loadOpenCounts = () => {
		getApiResponse<{ total: number }>(
			getApiLink(vulopilotAppLocalizer, 'findings?scanner_id=ai-crawler-blocked-pages&status=open&per_page=1'),
			nonceHeaders
		).then((response) => setBlockedPagesOpenCount(response?.total ?? 0));
	};

	/**
	 * `refreshEditorContent` gates whether this fetch is allowed to overwrite the editor's local
	 * `value` state.
	 */
	const loadRobots = (refreshEditorContent = true, showLoadingState = true) => {
		if (showLoadingState) {
			setIsLoadingRobots(true);
		}
		getApiResponse<RobotsResponse>(getApiLink(vulopilotAppLocalizer, 'robots-sitemap/robots'), nonceHeaders)
			.then((response) => {
				if (response) {
					setRobots(response);
					if (refreshEditorContent) {
						setRobotsEditContent(response.custom_content || response.content || '');
					}
				}
			})
			.finally(() => {
				if (showLoadingState) {
					setIsLoadingRobots(false);
				}
			});
	};

	const loadSitemap = () => {
		setIsLoadingSitemap(true);
		getApiResponse<SitemapResponse>(getApiLink(vulopilotAppLocalizer, 'robots-sitemap/sitemap'), nonceHeaders)
			.then((response) => response && setSitemap(response))
			.finally(() => setIsLoadingSitemap(false));
	};

	/**
	 * Inline, auto-saving robots.txt editor - same real shape "llms.txt content" below already
	 * established (`llmsTxtContent`/ `llmsTxtSaveState`/`llmsTxtSaveTimer`).
	 */
	const [robotsEditContent, setRobotsEditContent] = useState('');
	const robotsSaveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

	const persistRobotsContent = (content: string, notify = false) => {
		sendApiResponse(vulopilotAppLocalizer, getApiLink(vulopilotAppLocalizer, 'robots-sitemap/robots'), { content })
			.then((response) => {
				if (notify) {
					NoticeManager.add({
						uniqueKey: 'robots-sitemap-save',
						type: response ? 'success' : 'error',
						position: 'float',
						message: response
							? '' === content
								? __('robots.txt reset to the WordPress default.', 'vulopilot')
								: __('robots.txt saved and live.', 'vulopilot')
							: __('Could not save robots.txt. Please try again.', 'vulopilot'),
					});
				}

				if (response) {
					// Refresh the card's own read-only data (rules/directives counts, the "Custom"
					// badge).
					loadRobots(false, false);
				}
			});
	};

	const handleRobotsContentChange = (value: string) => {
		setRobotsEditContent(value);

		if (robotsSaveTimer.current) {
			clearTimeout(robotsSaveTimer.current);
		}
		robotsSaveTimer.current = setTimeout(
			() => persistRobotsContent(value),
			800
		);
	};

	const handleResetRobotsToDefault = () => {
		if (robotsSaveTimer.current) {
			clearTimeout(robotsSaveTimer.current);
		}
		setRobotsEditContent('');
		persistRobotsContent('', true);
	};

	/**
	 * "llms.txt content" - moved here from Settings → AI Visibility (the mockup places it on this
	 * tab instead).
	 */
	const [llmsTxtContent, setLlmsTxtContent] = useState('');
	const [isLlmsTxtEnabled, setIsLlmsTxtEnabled] = useState(false);
	const [isLoadingLlmsTxt, setIsLoadingLlmsTxt] = useState(true);
	const [isRegeneratingLlmsTxt, setIsRegeneratingLlmsTxt] = useState(false);
	const llmsTxtSaveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

	const loadLlmsTxt = () => {
		setIsLoadingLlmsTxt(true);
		getApiResponse<{ enable_llms_txt?: string[] | boolean; llms_txt_content?: string }>(
			getApiLink(vulopilotAppLocalizer, 'settings'),
			nonceHeaders
		)
			.then((response) => {
				if (!response) {
					return;
				}
				setIsLlmsTxtEnabled(
					Array.isArray(response.enable_llms_txt)
						? response.enable_llms_txt.includes('enable_llms_txt')
						: !!response.enable_llms_txt
				);
				setLlmsTxtContent(response.llms_txt_content ?? '');
			})
			.finally(() => setIsLoadingLlmsTxt(false));
	};

	const persistLlmsTxtContent = (content: string, notify = false) => {
		sendApiResponse(vulopilotAppLocalizer, getApiLink(vulopilotAppLocalizer, 'settings'), {
			setting: { llms_txt_content: content },
		}).then((response) => {
			if (notify) {
				NoticeManager.add({
					uniqueKey: 'llms-txt-regenerated',
					type: response ? 'success' : 'error',
					position: 'float',
					message: response
						? __('llms.txt regenerated and saved.', 'vulopilot')
						: __('Regenerated, but saving failed. Please try again.', 'vulopilot'),
				});
			}
		});
	};

	const handleLlmsTxtChange = (value: string) => {
		setLlmsTxtContent(value);
		if (llmsTxtSaveTimer.current) {
			clearTimeout(llmsTxtSaveTimer.current);
		}
		llmsTxtSaveTimer.current = setTimeout(() => persistLlmsTxtContent(value), 800);
	};

	const handleRegenerateLlmsTxt = () => {
		setIsRegeneratingLlmsTxt(true);
		getApiResponse<{ content: string }>(getApiLink(vulopilotAppLocalizer, 'llms-txt/regenerate'), nonceHeaders)
			.then((response) => {
				if (!response) {
					NoticeManager.add({
						uniqueKey: 'llms-txt-regenerate-failed',
						type: 'error',
						position: 'float',
						message: __('Could not regenerate llms.txt. Please try again.', 'vulopilot'),
					});
					return;
				}
				if (llmsTxtSaveTimer.current) {
					clearTimeout(llmsTxtSaveTimer.current);
				}
				setLlmsTxtContent(response.content);
				persistLlmsTxtContent(response.content, true);
			})
			.finally(() => setIsRegeneratingLlmsTxt(false));
	};

	useEffect(() => {
		loadRobots();
		loadSitemap();
		loadOpenCounts();
		loadLlmsTxt();
	}, []);

	const {
		tableCardProps: blockedPagesProps,
		fixNotice: blockedFixNotice,
		error: blockedPagesError,
		isProPopupOpen,
		closeProPopup,
		viewPopup: blockedPagesViewPopup,
	} = useFindingsTable({
		description: __(
			'No AI-bot-specific blocks found - run a scan to check robots.txt against your published pages.',
			'vulopilot'
		),
		scannerIds: ['ai-crawler-blocked-pages'],
	});

	const {
		tableCardProps: robotsTxtProps,
		fixNotice: robotsFixNotice,
		error: robotsTxtError,
		isProPopupOpen: isRobotsTxtProPopupOpen,
		closeProPopup: closeRobotsTxtProPopup,
		viewPopup: robotsTxtViewPopup,
	} = useFindingsTable({
		description: __(
			'No robots.txt issues yet - run a scan to check crawler access.',
			'vulopilot'
		),
		scannerIds: ['robots-txt'],
	});

	/**
	 * Only its own "Fix" pro-upsell popup is still used (below) - the sitemap findings table
	 * itself isn't rendered anywhere in this section (see `llmsTxtFindingsProps`, which used to be
	 * mistakenly fed by this hook's `tableCardProps`).
	 */
	const {
		isProPopupOpen: isSitemapProPopupOpen,
		closeProPopup: closeSitemapProPopup,
	} = useFindingsTable({
		scannerIds: ['sitemap', 'sitemap-validation'],
	});

	const {
		tableCardProps: llmsTxtFindingsProps,
		fixNotice: llmsTxtFixNotice,
		error: llmsTxtFindingsError,
		viewPopup: llmsTxtViewPopup,
	} = useFindingsTable({
		description: __(
			'No llms.txt findings yet - run a scan to check your llms.txt.',
			'vulopilot'
		),
		scannerIds: ['llms-txt-missing'],
	});



	const sitemapRows: SitemapRow[] = (sitemap?.sitemaps ?? []).map((child, index) => ({
		id: `${index}-${child.loc}`,
		...child,
	}));



	if (!isSeoModuleActive()) {
		return (
			<ColumnComponent>
				<CardComponent
					title={__('Robots & Sitemap', 'vulopilot')}
					titleIcon="link"
					desc={__('Robots.txt and XML sitemap checks for this site.', 'vulopilot')}
				>
					<ModuleGuardComponent
						icon="error"
						title={__('SEO module is turned off', 'vulopilot')}
						desc={__(
							'Turn the SEO module back on from Settings → Modules to resume robots.txt/sitemap checks.',
							'vulopilot'
						)}
					/>
				</CardComponent>
			</ColumnComponent>
		);
	}

	return (
		<>
			<ContainerComponent>
				<ColumnComponent>
					<CardComponent
						title={__('Robots.txt Analysis', 'vulopilot')}
						titleIcon="txt"
						desc={__(
							'Your live robots.txt, fetched right now (not a cached copy). Edit it below - saving takes effect immediately, and /robots.txt serves your version from the next request. Other active plugins (e.g. WooCommerce) may still add their own rules on top. If a physical robots.txt file exists in your site’s root folder, the web server serves that file instead and these edits won’t apply.',
							'vulopilot'
						)}
						isLoading={isLoadingRobots}
					>
						<div className="robots-overview-wrapper">
							<div className="left-section">
								{robots?.reachable ? (
									<>
										<ButtonInput
											buttons={{
												text: __('Test robots.txt', 'vulopilot'),
												icon: 'refresh',
												// Explicit click → refresh the editor content too.
												onClick: () => loadRobots(true),
											}}
										/>
										<div className='broken-link-section'>
											<div className="rt-editor-wrap">
												<RobotsTxtEditor
													value={robotsEditContent}
													onChange={handleRobotsContentChange}
													placeholder={__(
														'User-agent: *\nDisallow: /wp-admin/',
														'vulopilot'
													)}
												/>
												{robots.is_custom && (
													<ButtonInput
														buttons={{
															text: __(
																'Reset to WordPress default',
																'vulopilot'
															),
															icon: 'refresh',
															color: 'border-purple',
															onClick: handleResetRobotsToDefault,
														}}
													/>
												)}
											</div>
											<div className='list-wrapper'>
												<ListComponent
													className="mini-card report"
													items={[
														{
															id: 'total',
															desc: __('Total Rules', 'vulopilot'),
															tags: (
																<TypographyComponent
																	variant="h5"
																	weight="bold"
																	className="seo-health-score-row-value"
																>
																	{robots.rules.total}
																</TypographyComponent>
															),
														},
														{
															id: 'allowed',
															desc: __('Allowed', 'vulopilot'),
															tags: (
																<TypographyComponent
																	variant="h5"
																	weight="bold"
																	className="seo-health-score-row-value"
																>
																	{robots.rules.allowed}
																</TypographyComponent>
															),
														},
														{
															id: 'disallowed',
															desc: __('Disallowed', 'vulopilot'),
															tags: (
																<TypographyComponent
																	variant="h5"
																	weight="bold"
																	className="seo-health-score-row-value"
																>
																	{robots.rules.disallowed}
																</TypographyComponent>
															),
														},
													]}
												/>
												<ListComponent
													className="mini-card report"
													cols={2}
													items={[

														{
															id: 'sitemaps',
															desc: __('Sitemaps', 'vulopilot'),
															tags: (
																<TypographyComponent
																	variant="h5"
																	weight="bold"
																	className="seo-health-score-row-value"
																>
																	{robots.rules.sitemaps}
																</TypographyComponent>
															),
														},
														{
															id: 'user-agent',
															desc: __('User-agent', 'vulopilot'),
															tags: (
																<>
																	<TypographyComponent
																		variant="h5"
																		weight="bold"
																		className="seo-health-score-row-value"
																	>
																		{String(robots.directives.user_agents.length)}
																	</TypographyComponent>
																</>
															),
														},
														{
															id: 'crawl-delay',
															desc: __('Crawl-delay', 'vulopilot'),
															tags: (
																<>
																	<div className='small'>{robots.directives.crawl_delay ??
																		__('Not set', 'vulopilot')}</div>
																</>
															),
														},
													]}
												/>
											</div>
										</div>
									</>
								) : (
									<ModuleGuardComponent
										icon="error"
										title={__('robots.txt is not reachable', 'vulopilot')}
										desc={__('This site did not return a working /robots.txt just now.', 'vulopilot')}
									/>
								)}
							</div>
							<div className="right-section">
								{robotsTxtError ? (
									<ModuleGuardComponent
										icon="error"
										title={__('Could not load issues', 'vulopilot')}
										desc={robotsTxtError}
									/>
								) : (
									<>
										<SectionComponent
											title={__('Robots.txt Issues', 'vulopilot')}
											titleIcon="security"
											desc={__('Whether robots.txt is reachable and not accidentally blocking every crawler.', 'vulopilot')}
										/>
										<>
											{robotsFixNotice}
											<TableCard {...robotsTxtProps} bulkActions={[]} />
										</>
									</>
								)}
							</div>
						</div>
					</CardComponent>
				</ColumnComponent>

				<ColumnComponent>
					<CardComponent
						title={__('llms.txt content', 'vulopilot')}
						titleIcon="menu"
						desc={__(
							'Pre-filled with an auto-generated index of your published pages and posts - edit and it saves automatically, just like every other setting here, and is written straight to the live /llms.txt file.',
							'vulopilot'
						)}
						isLoading={isLoadingLlmsTxt}
					>
						<div className="robots-overview-wrapper">
							<div className="left-section">
								{isLlmsTxtEnabled ? (
									<div className="llms-txt-card-field">
										<div className="rt-editor-wrap">
											<RobotsTxtEditor
												value={llmsTxtContent}
												onChange={handleLlmsTxtChange}
												placeholder={__(
													'# Site Name\n\n> A short summary of the site.',
													'vulopilot'
												)}
											/>
											<ButtonInput
												buttons={{
													text: isRegeneratingLlmsTxt
														? __('Regenerating…', 'vulopilot')
														: __('Regenerate', 'vulopilot'),
													icon: 'refresh',
													color: 'border-purple',
													onClick: handleRegenerateLlmsTxt,
													disabled: isRegeneratingLlmsTxt,
												}}
											/>
										</div>
									</div>
								) : (
									<ModuleGuardComponent
										icon="info"
										title={__('llms.txt generation is turned off', 'vulopilot')}
										desc={__(
											'Turn on "Generate llms.txt" under Settings → AI Visibility to edit its content here.',
											'vulopilot'
										)}
										buttonText={__('Open Settings', 'vulopilot')}
										onButtonClick={() => {
											window.location.hash = '#&tab=settings&subtab=ai-visibility';
										}}
									/>
								)}
							</div>
							<div className="right-section">
								<SectionComponent
									title={__('llms.txt Issues', 'vulopilot')}
									titleIcon="security"
									desc={__(
										'Whether robots.txt is reachable and not accidentally blocking every crawler.',
										'vulopilot'
									)} />
								{llmsTxtFindingsError ? (
									<ModuleGuardComponent
										icon="error"
										title={__('Could not load findings', 'vulopilot')}
										desc={llmsTxtFindingsError}
									/>
								) : (
									<>
										{llmsTxtFixNotice}
										<TableCard {...llmsTxtFindingsProps} bulkActions={[]} />
									</>
								)}
							</div>
						</div>
					</CardComponent>
				</ColumnComponent>
				<ColumnComponent grid={6} fullHeight>
					<CardComponent
						title={__('XML Sitemap Overview', 'vulopilot')}
						titleIcon="link"
						desc={__('Check your live sitemap (fetched right now, not a cached copy).', 'vulopilot')}
						isLoading={isLoadingSitemap}
						action={
							<ButtonInput
								buttons={[
									...(sitemap?.reachable
										? [
												{
													text: __('View sitemap index', 'vulopilot'),
													color: 'text-purple',
													onClick: () => window.open(sitemap.index_url, '_blank'),
												},
											]
										: []),
									{
										text: 'Settings',
										icon: 'setting',
										color: 'purple',
										onClick: () => {
											window.location.hash = '#&tab=settings&subtab=sitemap';
										},
									},
								]}
							/>
						}
					>
						{sitemap?.reachable && sitemap.valid ? (
							<div className='broken-link-section left-side'>
								{sitemapRows.length > 0 ? (
									<ListComponent
										className="mini-card report sitemap-overview-list"
										loading={isLoadingSitemap}
										items={sitemapRows.map((row) => ({
											id: row.id,
											icon: 'link blue',
											title: getSitemapDisplayName(row.loc),
											desc: row.loc,
											titleTag: (
												<BadgeComponent
													color="indigo"
													text={
														null === row.url_count
															? __('- URLs', 'vulopilot')
															: sprintf(
																	/* translators: %d: real number of URLs this sitemap lists. */
																	_n('%d URL', '%d URLs', row.url_count, 'vulopilot'),
																	row.url_count
															)
													}
												/>
											),
											tags: (
												<a href={row.loc} target="_blank" rel="noreferrer">
													{__('View sitemap', 'vulopilot')}
													<IconComponent name="pagination-right-arrow" />
												</a>
											),
										}))}
									/>
								) : (
									<ModuleGuardComponent
										icon="info"
										title={__('No child sitemaps found', 'vulopilot')}
										desc={__('No child sitemaps found in the index.', 'vulopilot')}
									/>
								)}
							</div>
						) : (
							<ModuleGuardComponent
								icon="error"
								title={__('No usable sitemap found', 'vulopilot')}
								desc={__(
									'Neither /wp-sitemap.xml nor /sitemap.xml returned valid, parseable XML just now.',
									'vulopilot'
								)}
							/>
						)}

					</CardComponent>
				</ColumnComponent>


				<ColumnComponent grid={6} fullHeight>
					<CardComponent
						title={__('Blocked pages', 'vulopilot')}
						titleIcon="eye-blocked"
						desc={__('Real pages an AI bot is blocked from crawling right now.', 'vulopilot')}
					>
						{blockedPagesError ? (
							<ModuleGuardComponent
								icon="error"
								title={__('Could not load findings', 'vulopilot')}
								desc={blockedPagesError}
							/>
						) : (
							<>
								<div className="robots-blocked-count">
									<TypographyComponent as="span" variant="h3" className="redirect-stat-value is-attention">
										{blockedPagesOpenCount}
									</TypographyComponent>
									<TypographyComponent as="span" variant="desc">
										{sprintf(
											/* translators: %d: number of blocked URLs. */
											__('%d URL(s) currently blocked', 'vulopilot'),
											blockedPagesOpenCount
										)}
									</TypographyComponent>
								</div>
								<>
								{blockedFixNotice}
								<TableCard {...blockedPagesProps} />
							</>
							</>
						)}
					</CardComponent>
				</ColumnComponent>
			</ContainerComponent>

			<PopupComponent open={isProPopupOpen} onClose={closeProPopup} width={31.25} height="auto" position="lightbox">
				{vulopilotAppLocalizer.khali_dabba ? <ShowProPopup moduleName="one-click-fix" /> : <ShowProPopup />}
			</PopupComponent>
			<PopupComponent
				open={isRobotsTxtProPopupOpen}
				onClose={closeRobotsTxtProPopup}
				width={31.25}
				height="auto"

			>
				{vulopilotAppLocalizer.khali_dabba ? <ShowProPopup moduleName="one-click-fix" /> : <ShowProPopup />}
			</PopupComponent>
			<PopupComponent
				open={isSitemapProPopupOpen}
				onClose={closeSitemapProPopup}
				width={31.25}
				height="auto"

			>
				{vulopilotAppLocalizer.khali_dabba ? <ShowProPopup moduleName="one-click-fix" /> : <ShowProPopup />}
			</PopupComponent>

			{blockedPagesViewPopup}
			{robotsTxtViewPopup}
			{llmsTxtViewPopup}
		</>
	);
};

export default CrawlRobotsSitemapSection;