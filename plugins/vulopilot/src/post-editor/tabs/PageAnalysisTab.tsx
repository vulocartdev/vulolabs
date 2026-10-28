import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Dropdown, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import { usePostData } from '../usePostData';
import { analyzePage, analyzePost, AnalysisResult, FixResponse, fetchOpenFindings, fixFinding, fixWithAi, PageAnalysisCheck, PageAnalysisResponse, RawFinding } from '../api';
import { SEO_ISSUE_EDITOR_TARGETS, SeoIssueEditorTab, SeoIssueEditorTarget } from '../../services/seoIssueEditorTarget';

interface PageAnalysisTabProps {
	/** Either of 2 real deep-link vocabularies this tab now understands. */
	highlightTarget?: string;
	/** `PostSeoPanel.tsx`'s own in-sidebar tab switch - lets a row here jump straight to the real General/Social/Schema field that fixes it. */
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: SeoIssueEditorTab, target?: string ) => void;
}

/** This tab's check keys → the scanner id SEO_ISSUE_EDITOR_TARGETS understands. */
const CHECK_KEY_TO_SCANNER_ID: Record< string, string > = {
	h1_heading: 'heading-structure',
	canonical: 'canonical-url',
	structured_data: 'structured-data',
	social_metadata: 'open-graph',
	// No literal single-field fix for "nothing links here" - points at the real Internal
	// Linking check instead of running the nav-menu proxy fix below.
	orphan_page: 'internal-linking',
};

/** Saved-page checks the live checklist already covers, left out of the "SEO Issues" list. */
const CHECKS_COVERED_BY_LIVE_CHECKLIST = [ 'title_tag', 'meta_description', 'content', 'headings', 'images' ];

/** Saved-page checks with a real fix. Social Metadata (sitewide only) and Orphan Page (navigates to Internal Linking instead) are left out on purpose. */
const CHECK_KEY_TO_FIX_ACTION: Record< string, string > = {
	structured_data: 'generate-schema',
	featured_image: 'set-featured-image-from-content',
	h1_heading: 'insert-h1-from-title',
};

/** Fix ids that run a deterministic action, not an AI generation - shown as "Fix" rather than "Fix with AI". */
const MECHANICAL_FIX_ACTIONS = [ 'set-featured-image-from-content', 'insert-h1-from-title' ];

/** Mechanical fix ids whose result rewrites post_content, same as CONTENT_MUTATING_ACTIONS below for the AI ones. */
const CONTENT_MUTATING_MECHANICAL_ACTIONS = [ 'insert-h1-from-title' ];

/** GEO/AEO scanners with a mapped fix - `POST /findings/{id}/fix` resolves it from the finding's own scanner. */
const FIXABLE_FINDING_SCANNER_IDS = [
	'geo-faq-opportunity',
	'geo-summary-block',
	'geo-author-info',
	'geo-eeat-signals',
	'geo-trust-signals',
	'geo-citation-opportunities',
	'geo-chunking',
	'geo-semantic-structure',
	'geo-entity-naming-consistency',
];

const editorTargetForCheck = ( checkKey: string ): SeoIssueEditorTarget | null => {
	const scannerId = CHECK_KEY_TO_SCANNER_ID[ checkKey ];

	return scannerId ? SEO_ISSUE_EDITOR_TARGETS[ scannerId ] ?? null : null;
};

/** Local copy of GeoTab.tsx's/AeoTab.tsx's own scanner-id lists. */
const GEO_SCANNER_IDS = [
	'geo-citation-opportunities',
	'geo-chunking',
	'geo-semantic-structure',
	'geo-author-info',
	'geo-eeat-signals',
	'geo-entity-naming-consistency',
	'geo-trust-signals',
	'llms-txt-missing',
	'stale-content',
];

const AEO_SCANNER_IDS = [
	'geo-faq-opportunity',
	'geo-summary-block',
	'aeo-schema',
];

/** A finding's own `scanner_id` already IS the id `SEO_ISSUE_EDITOR_TARGETS` is keyed by. */
const editorTargetForFinding = ( finding: RawFinding ): SeoIssueEditorTarget | null =>
	SEO_ISSUE_EDITOR_TARGETS[ finding.scanner_id ] ?? null;

const STATUS_ICON: Record< PageAnalysisCheck[ 'status' ], string > = {
	pass: 'yes-alt',
	warn: 'warning',
	fail: 'dismiss',
};

/** Maps a check status to the matching vulopilot-seo-checklist__item--{modifier} CSS class. */
const STATUS_MODIFIER: Record< PageAnalysisCheck[ 'status' ], string > = {
	pass: 'pass',
	warn: 'warning',
	fail: 'fail',
};

/** GEO/AEO findings have no "pass" state - a finding only ever exists for a real open problem. */
const SEVERITY_TO_STATUS: Record< RawFinding[ 'severity' ], PageAnalysisCheck[ 'status' ] > = {
	critical: 'fail',
	high: 'fail',
	medium: 'warn',
	low: 'warn',
	info: 'warn',
};

/** What "Fix with AI" runs for a row: an AI action on this post, or the fix for one finding. */
type RowFix = { kind: 'post'; actionId: string } | { kind: 'finding'; findingId: number };

/** One shared row shape the live checks, the saved SEO checks and GEO's/AEO's open findings all resolve into. */
interface IssueRow {
	id: string;
	/** DOM id, when it differs from `${idPrefix}-${id}` (live checks keep the id the deep links target). */
	domId?: string;
	status: PageAnalysisCheck[ 'status' ];
	label: string;
	message: string;
	target: SeoIssueEditorTarget | null;
	fix?: RowFix;
	/** A manual option next to `fix` - jumps to the real WordPress field instead of running the automatic action. */
	manualFix?: { label: string; onClick: () => void };
	/** Set right after `onFix` resolves so the row shows fixed immediately, before the re-analysis round trip returns. */
	justFixed?: boolean;
}

/** Opens the Document sidebar and expands WordPress core's own Featured Image panel. */
const jumpToFeaturedImagePanel = () => {
	( dispatch( 'core/edit-post' ) as any ).openGeneralSidebar( 'edit-post/document' );

	if ( ! ( select( 'core/editor' ) as any ).isEditorPanelOpened( 'featured-image' ) ) {
		( dispatch( 'core/editor' ) as any ).toggleEditorPanelOpened( 'featured-image' );
	}

	setTimeout( () => {
		document
			.querySelector( '.editor-post-featured-image' )
			?.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}, 100 );
};

const rowFromCheck = ( check: PageAnalysisCheck, fixedIds: Set< string > ): IssueRow => {
	const actionId = CHECK_KEY_TO_FIX_ACTION[ check.key ];

	return {
		id: check.key,
		status: check.status,
		label: check.label,
		message: check.message,
		target: editorTargetForCheck( check.key ),
		fix: actionId && 'pass' !== check.status ? { kind: 'post', actionId } : undefined,
		manualFix:
			'featured_image' === check.key && 'pass' !== check.status
				? { label: __( 'Set it myself', 'vulopilot' ), onClick: jumpToFeaturedImagePanel }
				: undefined,
		justFixed: fixedIds.has( check.key ),
	};
};

const LIVE_STATUS: Record< AnalysisResult[ 'status' ], PageAnalysisCheck[ 'status' ] > = {
	pass: 'pass',
	warning: 'warn',
	fail: 'fail',
};

const rowFromLive = ( result: AnalysisResult, fixedIds: Set< string > ): IssueRow => ( {
	id: result.id,
	domId: `vulopilot-seo-check-${ result.id }`,
	status: LIVE_STATUS[ result.status ],
	label: '',
	message: result.message,
	target: null,
	fix: result.fixable && result.action_id && 'pass' !== result.status ? { kind: 'post', actionId: result.action_id } : undefined,
	justFixed: fixedIds.has( result.id ),
} );

const rowFromFinding = ( finding: RawFinding, fixedIds: Set< string > ): IssueRow => ( {
	id: String( finding.id ),
	status: SEVERITY_TO_STATUS[ finding.severity ],
	label: finding.title,
	message: '',
	target: editorTargetForFinding( finding ),
	fix: FIXABLE_FINDING_SCANNER_IDS.includes( finding.scanner_id ) ? { kind: 'finding', findingId: finding.id } : undefined,
	justFixed: fixedIds.has( String( finding.id ) ),
} );

/** Live checks about the post body itself - shown in their own "Content" section. */
const CONTENT_CHECK_IDS = [ 'content_length', 'has_subheadings', 'has_links', 'image_alt', 'keyword_in_content', 'keyword_in_first_paragraph' ];

/** AI actions that rewrite post_content. */
const CONTENT_MUTATING_ACTIONS = [ 'improve-readability', 'add-subheadings', 'expand-content', 'suggest-internal-links' ];

/**
 * Loads content the server just saved into the open block editor.
 */
const applyContentToEditor = ( content: string ) => {
	( dispatch( 'core/editor' ) as any ).resetEditorBlocks( parse( content ) );
};

const notify = ( message: string, status: 'success' | 'error' = 'success' ) => {
	( dispatch( 'core/notices' ) as any ).createNotice( status, message, { type: 'snackbar', isDismissible: true } );
};

const STATUS_ORDER: Record< PageAnalysisCheck[ 'status' ], number > = { fail: 0, warn: 1, pass: 2 };

interface FixControls {
	isPro: boolean;
	shopUrl: string;
	fixingId: string | null;
	// eslint-disable-next-line no-unused-vars
	onFix: ( row: IssueRow ) => void;
}

interface IssueListProps {
	idPrefix: string;
	rows: IssueRow[];
	pulsingId: string | null;
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: SeoIssueEditorTab, target?: string ) => void;
	fixControls: FixControls;
}

/** Renders one section's own real row list - the exact same clickable-row markup/behavior this tab's SEO section already had. */
function IssueList( { idPrefix, rows, pulsingId, onNavigate, fixControls }: IssueListProps ) {
	return (
		<ul className="vulopilot-seo-checklist__list">
			{ rows.map( ( row ) => {
				// Real "go fix this" destination - resolves to null (row stays inert) whenever this
				// row's own scanner/check has no real editor-sidebar field anywhere in this
				// codebase.
				const isClickable = Boolean( row.target && onNavigate );

				return (
					<li
						key={ row.id }
						id={ row.domId ?? `${ idPrefix }-${ row.id }` }
						className={ `vulopilot-seo-checklist__item vulopilot-seo-checklist__item--${ row.justFixed ? 'pass' : STATUS_MODIFIER[ row.status ] }${ pulsingId === row.id ? ' vulopilot-seo-highlight-pulse' : '' }${ isClickable ? ' vulopilot-seo-checklist__item--clickable' : '' }` }
						role={ isClickable ? 'button' : undefined }
						tabIndex={ isClickable ? 0 : undefined }
						onClick={
							isClickable
								? () => onNavigate?.( ( row.target as SeoIssueEditorTarget ).tab, ( row.target as SeoIssueEditorTarget ).target )
								: undefined
						}
						onKeyDown={
							isClickable
								? ( event ) => {
										if ( 'Enter' === event.key || ' ' === event.key ) {
											event.preventDefault();
											onNavigate?.( ( row.target as SeoIssueEditorTarget ).tab, ( row.target as SeoIssueEditorTarget ).target );
										}
									}
								: undefined
						}
					>
						<i className={ `dashicons dashicons-${ row.justFixed ? 'yes-alt' : STATUS_ICON[ row.status ] } vulopilot-seo-checklist__icon` } />
						<span className="vulopilot-seo-checklist__message">
							{ row.label && <strong>{ row.label }</strong> }
							{ row.label && row.message && ' - ' }
							{ row.message }
						</span>
						{ row.fix && (
							row.justFixed ? (
								<Button variant="secondary" size="small" disabled>
									{ __( 'Fixed', 'vulopilot' ) }
								</Button>
							) : fixControls.isPro ? (
								row.manualFix ? (
									<Dropdown
										className="vulopilot-seo-checklist__fix-dropdown"
										popoverProps={ { placement: 'bottom-end' } }
										renderToggle={ ( { isOpen, onToggle } ) => (
											<Button
												variant="secondary"
												size="small"
												isBusy={ fixControls.fixingId === row.id }
												disabled={ null !== fixControls.fixingId }
												aria-expanded={ isOpen }
												onClick={ ( event: { stopPropagation: () => void } ) => {
													event.stopPropagation();
													onToggle();
												} }
											>
												{ fixControls.fixingId === row.id ? (
													<Spinner />
												) : (
													<>
														{ __( 'Fix', 'vulopilot' ) }
														<i className="dashicons dashicons-arrow-down-alt2" />
													</>
												) }
											</Button>
										) }
										renderContent={ ( { onClose } ) => (
											<div className="vulopilot-seo-checklist__fix-menu">
												<Button
													variant="tertiary"
													size="small"
													onClick={ ( event: { stopPropagation: () => void } ) => {
														event.stopPropagation();
														onClose();
														fixControls.onFix( row );
													} }
												>
													{ __( 'Fix automatically', 'vulopilot' ) }
												</Button>
												<Button
													variant="tertiary"
													size="small"
													onClick={ ( event: { stopPropagation: () => void } ) => {
														event.stopPropagation();
														onClose();
														row.manualFix!.onClick();
													} }
												>
													{ row.manualFix!.label }
												</Button>
											</div>
										) }
									/>
								) : (
									<Button
										variant="secondary"
										size="small"
										isBusy={ fixControls.fixingId === row.id }
										disabled={ null !== fixControls.fixingId }
										onClick={ ( event: { stopPropagation: () => void } ) => {
											event.stopPropagation();
											fixControls.onFix( row );
										} }
									>
										{ fixControls.fixingId === row.id ? (
											<Spinner />
										) : 'post' === row.fix.kind && MECHANICAL_FIX_ACTIONS.includes( row.fix.actionId ) ? (
											__( 'Fix', 'vulopilot' )
										) : (
											__( 'Fix with AI', 'vulopilot' )
										) }
									</Button>
								)
							) : (
								<Button variant="tertiary" size="small" href={ fixControls.shopUrl } target="_blank" rel="noreferrer">
									{ __( 'Upgrade to fix', 'vulopilot' ) }
								</Button>
							)
						) }
						{ isClickable && ! row.fix && (
							<i className="dashicons dashicons-arrow-right-alt2 vulopilot-seo-checklist__arrow" />
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

interface IssueSectionProps {
	heading: string;
	idPrefix: string;
	rows: IssueRow[];
	isLoading: boolean;
	error: string | null;
	emptyMessage: string;
	pulsingId: string | null;
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: SeoIssueEditorTab, target?: string ) => void;
	fixControls: FixControls;
	/** Worst status across `rows` - drives the header's summary pill (same look the old General-tab groups had). */
	showSummary?: boolean;
}

/** Collapsible group: header always visible, rows expand/collapse on click. Starts open only when it contains the deep-link target. */
function IssueSection( { heading, idPrefix, rows, isLoading, error, emptyMessage, pulsingId, onNavigate, fixControls, showSummary }: IssueSectionProps ) {
	const failCount = rows.filter( ( row ) => 'fail' === row.status ).length;
	const warnCount = rows.filter( ( row ) => 'warn' === row.status ).length;
	const summary = failCount > 0 ? 'bad' : warnCount > 0 ? 'ok' : 'good';
	const summaryLabel =
		failCount > 0
			? sprintf( /* translators: %d: number of failing checks. */ _n( '%d Error', '%d Errors', failCount, 'vulopilot' ), failCount )
			: warnCount > 0
				? sprintf( /* translators: %d: number of checks that could be improved. */ _n( '%d Warning', '%d Warnings', warnCount, 'vulopilot' ), warnCount )
				: __( 'All Good', 'vulopilot' );

	const containsPulsingRow = Boolean( pulsingId ) && rows.some( ( row ) => row.id === pulsingId );
	const [ isOpenState, setIsOpenState ] = useState( false );
	const isOpen = isOpenState || containsPulsingRow;

	return (
		<div className={ `vulopilot-seo-checklist vulopilot-seo-checklist--collapsible${ isOpen ? ' vulopilot-seo-checklist--open' : '' }` }>
			<button
				type="button"
				className="vulopilot-seo-checklist__header"
				aria-expanded={ isOpen }
				onClick={ () => setIsOpenState( ( open ) => ! open ) }
			>
				<span className="title vulopilot-seo-checklist__title">{ heading }</span>
				{ showSummary && rows.length > 0 && (
					<span className={ `vulopilot-seo-checklist__summary vulopilot-seo-checklist__summary--${ summary }` }>{ summaryLabel }</span>
				) }
				<i className={ `dashicons dashicons-arrow-${ isOpen ? 'up' : 'down' }-alt2 vulopilot-seo-checklist__chevron` } />
			</button>
			{ isOpen && (
				isLoading ? (
					<div className="desc vulopilot-seo-checklist__error">{ __( 'Loading…', 'vulopilot' ) }</div>
				) : error ? (
					<div className="desc vulopilot-seo-checklist__error">{ error }</div>
				) : 0 === rows.length ? (
					<div className="desc vulopilot-seo-checklist__error">{ emptyMessage }</div>
				) : (
					<IssueList idPrefix={ idPrefix } rows={ rows } pulsingId={ pulsingId } onNavigate={ onNavigate } fixControls={ fixControls } />
				)
			) }
		</div>
	);
}

/**
 * The metabox's "Page Analysis" tab - 3 headed sections (SEO / GEO Issues / AEO Issues).
 */
export default function PageAnalysisTab( { highlightTarget, onNavigate }: PageAnalysisTabProps ) {
	const { postId, title, excerpt, slug, content, meta, setExcerpt, setMeta } = usePostData();
	// Same per-post override GeneralTab.tsx's own SEO Title field writes - independent of the real
	// post title, which "Fix with AI" must never silently rename (#bug: this used to call
	// setTitle(), renaming the page itself).
	const seoTitle = ( meta[ window.vulopilotPostSeo.metaKeys.seo_title ] as string ) || '';
	const focusKeyword = ( meta[ window.vulopilotPostSeo.metaKeys.focus_keyword ] as string ) || '';

	const [ liveResults, setLiveResults ] = useState< AnalysisResult[] >( [] );
	const [ isAnalyzingLive, setIsAnalyzingLive ] = useState( true );
	const [ fixingId, setFixingId ] = useState< string | null >( null );
	const [ fixError, setFixError ] = useState< string | null >( null );
	/** Bumped after a fix succeeds so the saved SEO checks and the GEO/AEO findings refetch and the fixed row drops out. */
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	/** Row ids shown as fixed (tick + "Fixed") the instant `onFix` resolves - cleared once the refetch above lands, by which point the real check/finding data agrees. */
	const [ fixedIds, setFixedIds ] = useState< Set< string > >( new Set() );

	const [ data, setData ] = useState< PageAnalysisResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ geoFindings, setGeoFindings ] = useState< RawFinding[] >( [] );
	const [ isLoadingGeo, setIsLoadingGeo ] = useState( true );
	const [ geoError, setGeoError ] = useState< string | null >( null );

	const [ aeoFindings, setAeoFindings ] = useState< RawFinding[] >( [] );
	const [ isLoadingAeo, setIsLoadingAeo ] = useState( true );
	const [ aeoError, setAeoError ] = useState< string | null >( null );

	const [ pulsingKey, setPulsingKey ] = useState< string | null >( null );
	const hasScrolledRef = useRef( false );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		setError( null );

		analyzePage( postId )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setData( response );
					// The real re-checked data has landed - drop the optimistic "just fixed" override
					// so a row that's genuinely still failing (an unrelated edit undid the fix) shows
					// that, instead of staying stuck on "Fixed".
					setFixedIds( new Set() );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( err instanceof Error ? err.message : String( err ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ postId, refreshKey ] );

	// 2 more real, independent requests rather than gating the whole tab (including the unchanged
	// SEO section above) behind them.
	useEffect( () => {
		let cancelled = false;
		setIsLoadingGeo( true );
		setGeoError( null );

		fetchOpenFindings( GEO_SCANNER_IDS )
			.then( ( findings ) => {
				if ( ! cancelled ) {
					setGeoFindings( findings.filter( ( finding ) => finding.object_ref === String( postId ) ) );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setGeoError( err instanceof Error ? err.message : String( err ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoadingGeo( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ postId, refreshKey ] );

	useEffect( () => {
		let cancelled = false;
		setIsLoadingAeo( true );
		setAeoError( null );

		fetchOpenFindings( AEO_SCANNER_IDS )
			.then( ( findings ) => {
				if ( ! cancelled ) {
					setAeoFindings( findings.filter( ( finding ) => finding.object_ref === String( postId ) ) );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setAeoError( err instanceof Error ? err.message : String( err ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoadingAeo( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ postId, refreshKey ] );

	// The live checklist re-analyzes LIVE, possibly-unsaved editor state on a short debounce (see
	// PostSeo.php for why that's a POST-with-body, not a stored-post read).
	useEffect( () => {
		let cancelled = false;
		setIsAnalyzingLive( true );

		const timeout = setTimeout( () => {
			analyzePost( postId, { title: seoTitle || title, content, excerpt, slug, focus_keyword: focusKeyword } )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setLiveResults( response.results );
					}
				} )
				.catch( () => {
					// A failed call just leaves the previous checklist
					// showing - it re-runs on the next edit.
				} )
				.finally( () => {
					if ( ! cancelled ) {
						setIsAnalyzingLive( false );
					}
				} );
		}, 600 );

		return () => {
			cancelled = true;
			clearTimeout( timeout );
		};
	}, [ postId, title, seoTitle, excerpt, slug, content, focusKeyword ] );

	const applyPostFix = ( actionId: string, response: FixResponse ) => {
		if ( ! response.post ) {
			return;
		}

		if ( 'write-meta-title' === actionId ) {
			setMeta( { [ window.vulopilotPostSeo.metaKeys.seo_title ]: response.post.title } );
		}

		if ( 'write-meta-description' === actionId ) {
			setExcerpt( response.post.excerpt );
		}

		if ( response.post.content_changed && response.post.content ) {
			applyContentToEditor( response.post.content );
		}

		// Updates the Document sidebar's own Featured Image panel live, without a page reload.
		if ( 'set-featured-image-from-content' === actionId && response.post.featured_media_id ) {
			( dispatch( 'core/editor' ) as any ).editPost( { featured_media: response.post.featured_media_id } );
		}
	};

	const handleFix = async ( row: IssueRow ) => {
		if ( ! row.fix ) {
			return;
		}

		setFixingId( row.id );
		setFixError( null );

		try {
			if ( 'post' === row.fix.kind ) {
				const rewritesContent =
					CONTENT_MUTATING_ACTIONS.includes( row.fix.actionId ) ||
					CONTENT_MUTATING_MECHANICAL_ACTIONS.includes( row.fix.actionId );

				// A content fix works on the SAVED post, then its result is loaded into the editor.
				if ( rewritesContent && ( select( 'core/editor' ) as any ).isEditedPostDirty() ) {
					await ( dispatch( 'core/editor' ) as any ).savePost();
				}

				applyPostFix( row.fix.actionId, await fixWithAi( postId, row.fix.actionId ) );
				notify(
					rewritesContent
						? __( 'Fixed - the updated content is now in the editor.', 'vulopilot' )
						: __( 'Fixed.', 'vulopilot' )
				);
			} else {
				await fixFinding( row.fix.findingId );
			}

			setFixedIds( ( current ) => new Set( current ).add( row.id ) );
			setRefreshKey( ( key ) => key + 1 );
		} catch ( err ) {
			setFixError( err instanceof Error ? err.message : String( err ) );
			notify( err instanceof Error ? err.message : String( err ), 'error' );
		} finally {
			setFixingId( null );
		}
	};

	const fixControls: FixControls = {
		isPro: window.vulopilotPostSeo.isPro,
		shopUrl: window.vulopilotPostSeo.shopUrl,
		fixingId,
		onFix: handleFix,
	};

	// "SEO" and "Content" lists, worst first, so nothing is shown twice: the live checks (this
	// editor's current, possibly unsaved state) are split by what they grade.
	const bySeverity = ( a: IssueRow, b: IssueRow ) => STATUS_ORDER[ a.status ] - STATUS_ORDER[ b.status ];
	const contentRows = liveResults
		.filter( ( result ) => CONTENT_CHECK_IDS.includes( result.id ) )
		.map( ( result ) => rowFromLive( result, fixedIds ) )
		.sort( bySeverity );
	const seoRows = [
		...liveResults.filter( ( result ) => ! CONTENT_CHECK_IDS.includes( result.id ) ).map( ( result ) => rowFromLive( result, fixedIds ) ),
		...( data?.checks ?? [] )
			.filter( ( check ) => ! CHECKS_COVERED_BY_LIVE_CHECKLIST.includes( check.key ) )
			.map( ( check ) => rowFromCheck( check, fixedIds ) ),
	].sort( bySeverity );

	// Deep-link highlighting: live checks (by check id, e.g. 'description_length',
	// `SEO_ISSUE_EDITOR_TARGETS`) and SEO's saved `data.checks` (by `key`,
	// `PAGE_ANALYSIS_CHECK_QUERY_PARAM`) are tried first; GEO/AEO findings (by numeric id,
	// `FINDING_ID_QUERY_PARAM`) next, once each section's fetch has resolved.
	useEffect( () => {
		if ( ! highlightTarget || hasScrolledRef.current ) {
			return;
		}

		let elementId: string | null = null;

		if ( liveResults.some( ( result ) => result.id === highlightTarget ) ) {
			elementId = `vulopilot-seo-check-${ highlightTarget }`;
		} else if ( data?.checks.some( ( check ) => check.key === highlightTarget ) ) {
			elementId = `vulopilot-page-analysis-check-${ highlightTarget }`;
		} else if (
			! isLoadingGeo &&
			geoFindings.some( ( finding ) => String( finding.id ) === highlightTarget )
		) {
			elementId = `vulopilot-page-analysis-geo-${ highlightTarget }`;
		} else if (
			! isLoadingAeo &&
			aeoFindings.some( ( finding ) => String( finding.id ) === highlightTarget )
		) {
			elementId = `vulopilot-page-analysis-aeo-${ highlightTarget }`;
		}

		if ( ! elementId ) {
			return;
		}

		hasScrolledRef.current = true;
		const element = document.getElementById( elementId );
		element?.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		setPulsingKey( highlightTarget );

		const timeout = setTimeout( () => setPulsingKey( null ), 4000 );
		return () => clearTimeout( timeout );
	}, [ highlightTarget, liveResults, data, geoFindings, aeoFindings, isLoadingGeo, isLoadingAeo ] );

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--page-analysis">
			<p className="small desc vulopilot-seo-focus-keyword-help">
				{ __( 'This page\'s real SEO/GEO/AEO signals, last checked live.', 'vulopilot' ) }
			</p>

			{ fixError && <div className="desc vulopilot-seo-checklist__error">{ fixError }</div> }

			<IssueSection
				heading={ __( 'SEO', 'vulopilot' ) }
				idPrefix="vulopilot-page-analysis-check"
				rows={ seoRows }
				isLoading={ ( isAnalyzingLive && 0 === liveResults.length ) || ( isLoading && ! data ) }
				error={ error && 0 === seoRows.length ? error : null }
				emptyMessage={ __( 'No SEO checks to show.', 'vulopilot' ) }
				pulsingId={ pulsingKey }
				onNavigate={ onNavigate }
				fixControls={ fixControls }
				showSummary
			/>

			<IssueSection
				heading={ __( 'Content', 'vulopilot' ) }
				idPrefix="vulopilot-page-analysis-content"
				rows={ contentRows }
				isLoading={ isAnalyzingLive && 0 === liveResults.length }
				error={ null }
				emptyMessage={ __( 'No content checks to show.', 'vulopilot' ) }
				pulsingId={ pulsingKey }
				onNavigate={ onNavigate }
				fixControls={ fixControls }
				showSummary
			/>

			<IssueSection
				heading={ __( 'GEO Issues', 'vulopilot' ) }
				idPrefix="vulopilot-page-analysis-geo"
				rows={ geoFindings.map( ( finding ) => rowFromFinding( finding, fixedIds ) ) }
				isLoading={ isLoadingGeo }
				error={ geoError }
				emptyMessage={ __( 'No open GEO findings for this page.', 'vulopilot' ) }
				pulsingId={ pulsingKey }
				onNavigate={ onNavigate }
				fixControls={ fixControls }
				showSummary
			/>

			<IssueSection
				heading={ __( 'AEO Issues', 'vulopilot' ) }
				idPrefix="vulopilot-page-analysis-aeo"
				rows={ aeoFindings.map( ( finding ) => rowFromFinding( finding, fixedIds ) ) }
				isLoading={ isLoadingAeo }
				error={ aeoError }
				emptyMessage={ __( 'No open AEO findings for this page.', 'vulopilot' ) }
				pulsingId={ pulsingKey }
				onNavigate={ onNavigate }
				fixControls={ fixControls }
				showSummary
			/>
		</div>
	);
}
