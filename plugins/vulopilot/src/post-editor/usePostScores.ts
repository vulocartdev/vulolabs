import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { fetchAllFindings, getPostSeoScore, SeoScoreRule } from './api';

/** Local copy of `PageAnalysisTab.tsx`'s `AEO_SCANNER_IDS` - kept in sync by hand. */
const AEO_SCANNER_IDS = [ 'geo-faq-opportunity', 'geo-summary-block', 'aeo-schema' ];

/** Same severity-weighted formula `AeoScoreSummaryCard.tsx`'s `calculateScore()` uses, narrowed to this post's own open AEO findings. */
const calculateAeoScore = ( breakdown: { critical: number; high: number; medium: number; low: number } ): number => {
	const score =
		100 -
		breakdown.critical * 15 -
		breakdown.high * 8 -
		breakdown.medium * 3 -
		breakdown.low * 1;

	return Math.max( 0, Math.min( 100, score ) );
};

/** Matches PageAnalysisTab.tsx's own live-recheck debounce. */
const LIVE_SCORE_DEBOUNCE_MS = 600;

/**
 * Real SEO score (live against current editor fields, same `OnPageAnalyzer` engine as the Page
 * Analysis tab) and real AEO score (this post's open AEO findings) - shared by the in-panel
 * badges and the toolbar score pill, so both read one fetch pair. Reacts to typing (debounced)
 * and to every successful save.
 */
export function usePostScores( postId: number ) {
	const [ seoScore, setSeoScore ] = useState< number | null >( null );
	const [ aeoScore, setAeoScore ] = useState< number | null >( null );
	/** Last full evaluation's rules - ScoreImprovementCard's "reach 100 by fixing these" list. */
	const [ seoScoreRules, setSeoScoreRules ] = useState< SeoScoreRule[] >( [] );

	// The editor's own live, possibly-unsaved field values - same shape PageAnalysisTab's analyzePost() sends.
	const live = useSelect( ( select ) => {
		const editor = select( 'core/editor' ) as any;
		const meta = editor.getEditedPostAttribute( 'meta' ) || {};
		const seoTitle = ( meta[ window.vulopilotPostSeo.metaKeys.seo_title ] as string ) || '';
		const title = editor.getEditedPostAttribute( 'title' ) || '';

		return {
			title: seoTitle || title,
			content: editor.getEditedPostContent() || '',
			excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
			slug: editor.getEditedPostAttribute( 'slug' ) || '',
			focus_keyword: ( meta[ window.vulopilotPostSeo.metaKeys.focus_keyword ] as string ) || '',
		};
	}, [] );

	// Bumped on every call so a slower, stale request can never overwrite a newer resolved one.
	const requestIdRef = useRef( 0 );

	const fetchScores = useCallback( ( currentPostId: number, liveFields: typeof live, liveOnly: boolean ) => {
		if ( ! currentPostId ) {
			return;
		}

		const requestId = ++requestIdRef.current;

		getPostSeoScore( currentPostId, liveFields, liveOnly )
			.then( ( response ) => {
				if ( requestId === requestIdRef.current ) {
					setSeoScore( response.score );

					// Live-only responses omit fetch rules entirely - keep the last full response's rules.
					if ( ! liveOnly ) {
						setSeoScoreRules( response.rules ?? [] );
					}
				}
			} )
			.catch( () => {
				// Left as null - the caller shows its own "…" placeholder.
			} );

		// Any-status fetch - zero rows means never scanned, must show "unknown", not a fabricated 100.
		fetchAllFindings( AEO_SCANNER_IDS )
			.then( ( findings ) => {
				const postFindings = findings.filter( ( finding ) => finding.object_ref === String( currentPostId ) );

				if ( postFindings.length === 0 ) {
					setAeoScore( null );
					return;
				}

				const breakdown = { critical: 0, high: 0, medium: 0, low: 0 };

				postFindings
					.filter( ( finding ) => finding.status === 'open' )
					.forEach( ( finding ) => {
						if ( finding.severity in breakdown ) {
							breakdown[ finding.severity as keyof typeof breakdown ] += 1;
						}
					} );

				setAeoScore( calculateAeoScore( breakdown ) );
			} )
			.catch( () => {} );
	}, [] );

	// Live recheck, debounced like the Page Analysis checklist. Fires full on mount, live-only after.
	const hasLoadedOnceRef = useRef( false );

	useEffect( () => {
		const isFirstLoad = ! hasLoadedOnceRef.current;
		const timeout = setTimeout( () => {
			hasLoadedOnceRef.current = true;
			fetchScores( postId, live, ! isFirstLoad );
		}, isFirstLoad ? 0 : LIVE_SCORE_DEBOUNCE_MS );
		return () => clearTimeout( timeout );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postId, live.title, live.content, live.excerpt, live.slug, live.focus_keyword, fetchScores ] );

	// Refetch (full) right after every successful save, so the score doesn't wait out the debounce.
	const isSavingPost = useSelect( ( select ) => ( select( 'core/editor' ) as any ).isSavingPost(), [] );
	const didSaveSucceed = useSelect( ( select ) => ( select( 'core/editor' ) as any ).didPostSaveRequestSucceed(), [] );
	const wasSavingRef = useRef( false );

	useEffect( () => {
		if ( wasSavingRef.current && ! isSavingPost && didSaveSucceed ) {
			fetchScores( postId, live, false );
		}

		wasSavingRef.current = isSavingPost;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isSavingPost, didSaveSucceed, postId, fetchScores ] );

	// Full refetch on demand - ScoreImprovementCard's auto-fix button calls this after a fix runs.
	const refetch = useCallback( () => fetchScores( postId, live, false ), [ postId, live, fetchScores ] );

	return { seoScore, aeoScore, seoScoreRules, refetch };
}
