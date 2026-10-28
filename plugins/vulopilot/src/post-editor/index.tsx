import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { dispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { createPortal } from 'react-dom';
import { __ } from '@wordpress/i18n';
import PostSeoPanel from './PostSeoPanel';
import ToolbarScorePills from './ToolbarScorePills';

// The text-selection AI toolbar is Pro-only - vulopilot-pro's own post-editor bundle registers
// it directly (TextSelectionActionsPanel.tsx), only while licensed.

/**
 * `PluginSidebar`'s `icon` prop can't fit our two text pills, so this portals them straight into
 * the header's settings row (`.editor-header__settings`) instead. That row is a hardcoded
 * `Fragment` with no public Slot/Fill, and React wipes a plain `insertBefore()` on its next
 * re-render - so a MutationObserver re-inserts the container every time it gets evicted.
 */
const useToolbarScorePillsPortal = () => {
	const [ container, setContainer ] = useState< HTMLElement | null >( null );

	useEffect( () => {
		let cancelled = false;
		let observer: MutationObserver | null = null;

		const ensureMounted = ( settingsRow: Element ) => {
			if ( settingsRow.querySelector( '.vulopilot-toolbar-score-pills-root' ) ) {
				return;
			}

			const root = document.createElement( 'div' );
			root.className = 'vulopilot-toolbar-score-pills-root';
			settingsRow.insertBefore( root, settingsRow.firstChild );
			setContainer( root );
		};

		const findAndWatch = () => {
			if ( cancelled ) {
				return;
			}

			const settingsRow = document.querySelector( '.editor-header__settings' );

			if ( ! settingsRow ) {
				// The header mounts asynchronously - keep trying until it exists.
				requestAnimationFrame( findAndWatch );
				return;
			}

			ensureMounted( settingsRow );

			observer = new MutationObserver( () => ensureMounted( settingsRow ) );
			observer.observe( settingsRow, { childList: true } );
		};

		findAndWatch();

		return () => {
			cancelled = true;
			observer?.disconnect();
		};
	}, [] );

	return container;
};

import {
	getEditorTargetForScanner,
	SEO_ISSUE_QUERY_PARAM,
	PAGE_ANALYSIS_CHECK_QUERY_PARAM,
	FINDING_ID_QUERY_PARAM,
	SeoIssueEditorTab,
} from '../services/seoIssueEditorTarget';
import './style.scss';

const SIDEBAR_NAME = 'vulopilot-seo-sidebar';

interface DeepLinkTarget {
	wasPresent: boolean;
	tab?: SeoIssueEditorTab;
	target?: string;
}

/**
 * Two independent deep-link sources land here, both stripped from the URL immediately so a page
 * refresh doesn't keep re-triggering the highlight.
 */
const readDeepLinkTarget = (): DeepLinkTarget => {
	const params = new URLSearchParams( window.location.search );
	const scannerId = params.get( SEO_ISSUE_QUERY_PARAM );
	const pageAnalysisCheckKey = params.get( PAGE_ANALYSIS_CHECK_QUERY_PARAM );
	const findingId = params.get( FINDING_ID_QUERY_PARAM );

	if ( ! scannerId && ! pageAnalysisCheckKey && ! findingId ) {
		return { wasPresent: false };
	}

	params.delete( SEO_ISSUE_QUERY_PARAM );
	params.delete( PAGE_ANALYSIS_CHECK_QUERY_PARAM );
	params.delete( FINDING_ID_QUERY_PARAM );
	const query = params.toString();
	window.history.replaceState(
		{},
		'',
		window.location.pathname + ( query ? `?${ query }` : '' ) + window.location.hash
	);

	if ( pageAnalysisCheckKey ) {
		return { wasPresent: true, tab: 'page-analysis', target: pageAnalysisCheckKey };
	}

	if ( findingId ) {
		return { wasPresent: true, tab: 'page-analysis', target: findingId };
	}

	const resolved = getEditorTargetForScanner( scannerId as string );
	return { wasPresent: true, tab: resolved?.tab, target: resolved?.target };
};

// Read once at module scope, before first render - deep-link state is static for the lifetime of
// this editor page load.
const { wasPresent: shouldOpenSidebar, tab: deepLinkTab, target: deepLinkHighlight } = readDeepLinkTarget();

/**
 * Opens the SEO sidebar the first time the user clicks into the title field. The editor canvas
 * (title included) renders in its own iframe since WP 5.9, so this polls for `contentDocument`
 * the same way `useToolbarScorePillsPortal` does, and attaches the listener there instead of on
 * the top-level `document`. Fires once per page load, never re-opens after the user closes it.
 */
const useOpenSidebarOnTitleClick = () => {
	useEffect( () => {
		let cancelled = false;
		let attachedDoc: Document | null = null;
		const firedRef = { current: false };

		const onFocusIn = ( event: FocusEvent ) => {
			if ( firedRef.current ) {
				return;
			}

			const target = event.target as HTMLElement | null;
			const isTitleField = Boolean( target?.closest( '.editor-post-title__input' ) );

			if ( ! isTitleField ) {
				return;
			}

			firedRef.current = true;
			dispatch( 'core/edit-post' ).openGeneralSidebar( `vulopilot-seo/${ SIDEBAR_NAME }` );
		};

		const tryAttach = () => {
			if ( cancelled || firedRef.current ) {
				return;
			}

			const iframe = document.querySelector< HTMLIFrameElement >( 'iframe[name="editor-canvas"]' );
			const doc = iframe?.contentDocument;

			if ( doc && doc !== attachedDoc ) {
				attachedDoc?.removeEventListener( 'focusin', onFocusIn );
				doc.addEventListener( 'focusin', onFocusIn );
				attachedDoc = doc;
			}

			requestAnimationFrame( tryAttach );
		};

		tryAttach();

		return () => {
			cancelled = true;
			attachedDoc?.removeEventListener( 'focusin', onFocusIn );
		};
	}, [] );
};

/**
 * The post-editor SEO metabox, mounted separately from the dashboard app.
 */
const VuloPilotIcon = () => (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 400 300"
		width="24"
		height="24"
		preserveAspectRatio="xMidYMid meet"
		aria-hidden="true"
		focusable="false"
	>
		<g fill="currentColor">
			<path d="M204.25 311.45 c-1.17 -0.41 -5.40 -2.35 -9.39 -4.29 -37.28 -18.14 -68.75 -43.80 -86.77 -70.57 -4.34 -6.52 -14.15 -24.95 -14.15 -26.71 0 -0.59 0.88 -0.88 3.05 -1.06 2.94 -0.18 2.88 -0.18 -0.82 -0.29 -2.23 -0.06 -3.99 -0.35 -3.99 -0.70 0 -0.70 11.21 -0.76 26.89 -0.18 9.86 0.35 10.69 0.47 10.69 1.47 0 0.88 -0.47 1.06 -3.17 1.06 -2.05 0 -3.41 0.29 -3.87 0.88 -0.94 1.17 -0.47 1.70 5.99 7.10 10.33 8.63 18.08 11.92 36.52 15.56 6.52 1.23 13.15 2.70 14.74 3.23 5.23 1.70 10.98 4.93 19.43 10.86 11.33 7.93 14.15 9.57 21.55 12.39 6.99 2.70 8.63 4.05 8.63 7.34 0 2.52 -1.41 4.64 -3.87 5.81 -3.99 1.88 -14.56 0 -34.23 -6.16 -6.22 -2 -11.86 -3.58 -12.45 -3.58 -2.88 0 -0.88 2.41 5.75 6.93 11.68 7.93 27.06 12.51 38.92 11.57 8.40 -0.65 15.32 -2.41 35.64 -9.28 10.74 -3.58 19.73 -6.34 19.96 -6.05 0.47 0.41 -2.35 3.23 -8.92 8.87 -7.40 6.34 -24.31 17.91 -29.77 20.37 -1.23 0.53 -3.11 1.64 -4.17 2.41 -1 0.82 -3.52 2.29 -5.52 3.29 -2 1 -3.76 2.05 -3.99 2.41 -0.18 0.29 -0.76 0.59 -1.29 0.59 -0.53 0 -1.41 0.35 -1.88 0.76 -0.53 0.47 -1.88 1.12 -2.99 1.53 -1.12 0.41 -2.58 1 -3.23 1.29 -3.05 1.29 -10.33 4.05 -10.74 3.99 -0.23 0 -1.35 -0.35 -2.52 -0.82z" />

			<path d="M321.02 285.44 c-4.87 -2.82 -30.47 -23.60 -31.06 -25.24 -0.35 -0.82 -0.41 -2.52 -0.23 -3.70 0.29 -1.82 0.18 -2.29 -0.82 -2.82 -2.17 -1.23 -4.11 -0.70 -7.34 1.88 -3.35 2.76 -9.51 5.58 -15.62 7.16 -5.23 1.35 -15.68 0.94 -21.43 -0.88 -14.27 -4.52 -24.42 -14.91 -27.95 -28.65 -1.53 -6.05 -1.53 -14.85 0.06 -20.96 2 -7.81 7.22 -15.91 13.68 -21.31 17.61 -14.50 45.38 -11.39 58.89 6.52 6.11 8.16 8.63 15.73 8.57 25.66 -0.06 6.87 -1 11.27 -3.64 16.97 -1 2.05 -1.76 3.82 -1.76 3.87 0 0.12 0.88 0.70 2 1.35 1.47 0.88 2.64 1.12 4.40 0.88 2.94 -0.41 1.94 -1.06 20.02 13.09 8.28 6.46 15.09 12.27 15.79 13.39 1.47 2.35 1.64 7.22 0.35 9.75 -2.52 4.81 -8.57 6.16 -13.91 3.05z m-56.77 -34.93 c11.21 -3.82 19.14 -12.74 21.14 -23.89 2.76 -15.56 -12.04 -32.82 -28.30 -32.88 -18.67 -0.06 -34.11 19.55 -28.71 36.46 4.99 15.56 21.84 25.07 35.87 20.31z" />

			<path d="M200.49 223.86 c-9.51 -27.24 -23.07 -54.78 -36.52 -74.03 -12.51 -17.96 -28.24 -33.58 -45.91 -45.62 -7.81 -5.34 -5.46 -5.75 8.16 -1.35 16.44 5.34 32.06 14.68 46.09 27.59 12.33 11.33 21.02 22.43 30.53 38.87 l1.29 2.29 3.99 -6.11 c15.79 -24.36 36.69 -43.74 59.30 -55.01 11.57 -5.75 25.42 -10.39 29.71 -9.98 1.82 0.18 1.76 0.29 -3.23 3.58 -2.76 1.88 -6.22 4.34 -7.57 5.46 -1.35 1.12 -4.99 4.05 -8.04 6.52 -17.32 14.03 -30.12 28.42 -43.74 49.20 -14.79 22.49 -22.19 37.05 -27.59 53.89 -1.94 6.05 -3.70 10.98 -3.87 10.98 -0.23 0 -1.41 -2.82 -2.58 -6.28z" />

			<path d="M306.46 222.39 c0 -7.28 -1.35 -13.27 -4.46 -19.90 l-2.11 -4.46 1.59 -3.17 c2.52 -4.99 6.52 -17.73 8.16 -26.07 2.47 -12.80 2.76 -19.73 2.58 -56.07 l-0.18 -33.76 -9.69 -2.52 c-33.52 -8.75 -66.05 -23.84 -89.59 -41.57 -5.52 -4.17 -6.69 -4.17 -12.62 -0.06 -2.47 1.64 -6.87 4.70 -9.80 6.69 -2.94 2.05 -5.46 3.93 -5.69 4.29 -0.18 0.29 -0.82 0.59 -1.41 0.59 -0.59 0 -1.41 0.41 -1.82 0.88 -0.41 0.47 -1.41 1.23 -2.29 1.64 -2.94 1.59 -10.92 5.87 -13.27 7.22 -4.11 2.29 -18.79 9.04 -19.78 9.04 -0.29 0 -1.59 0.47 -2.99 1.12 -1.35 0.59 -3.41 1.41 -4.52 1.82 -1.12 0.41 -2.58 0.94 -3.23 1.23 -0.65 0.29 -4.76 1.59 -9.10 2.88 -4.34 1.29 -9.63 2.88 -11.74 3.52 -2.11 0.59 -6.40 1.70 -9.51 2.47 -5.34 1.29 -5.69 1.41 -5.99 3.05 -0.47 2.29 -0.47 40.33 0.06 54.54 0.29 9.10 0.53 11.57 1.17 11.57 0.41 0 0.76 0.35 0.76 0.82 0 0.53 -1.35 0.88 -4.81 1.12 -7.51 0.59 -17.50 0.47 -17.50 -0.18 0 -0.29 0.70 -0.59 1.64 -0.65 0.88 0 1.23 -0.18 0.88 -0.35 -1.06 -0.41 -1.06 -81.02 0 -82.43 1.17 -1.59 3.05 -2.23 9.33 -3.52 14.32 -2.82 30.76 -7.63 45.38 -13.27 2.94 -1.12 10.92 -4.81 17.85 -8.22 13.39 -6.63 26.60 -14.97 40.86 -25.71 11.74 -8.87 11.74 -8.87 15.91 -5.46 8.28 6.93 24.66 18.26 34.58 23.95 23.07 13.33 48.79 22.96 76.26 28.53 8.04 1.64 9.57 2.41 10.04 5.11 0.23 1.06 0.29 22.13 0.12 46.79 -0.18 42.21 -0.29 45.38 -1.47 52.54 -3.58 22.31 -9.86 40.69 -19.20 56.18 -4.23 6.99 -4.40 6.99 -4.40 -0.23z" />

			<path d="M89 204.37 c-0.29 -0.47 0.59 -0.65 2.99 -0.70 3.11 -0.12 3.17 -0.12 1.06 -0.47 l-2.29 -0.35 -2.17 -7.05 c-1.17 -3.82 -2.41 -7.10 -2.82 -7.22 -0.35 -0.12 -0.65 -0.47 -0.65 -0.76 0 -0.65 6.40 -0.65 21.02 -0.12 9.57 0.29 10.69 0.47 10.69 1.35 0 0.82 -0.65 1.12 -2.99 1.29 -4.46 0.47 -4.58 0.88 -1.76 6.05 1.35 2.41 2.29 4.70 2.11 4.99 -0.18 0.35 0.18 0.59 0.88 0.59 2.11 0 1 1.41 -1.53 1.88 -4.34 0.88 -24.07 1.29 -24.54 0.53z" />

			<path d="M83.43 184.70 c-0.70 -0.82 -0.59 -0.94 0.76 -0.94 1.29 0 1.47 -0.18 0.94 -1 -0.65 -1.17 -2.35 -9.63 -2.35 -11.57 0 -0.88 -0.59 -1.59 -1.59 -2.11 -1.59 -0.70 -1.59 -0.76 -0.41 -1.29 1.12 -0.53 1.17 -0.88 0.82 -2.99 -0.29 -1.35 -0.59 -4.05 -0.70 -5.99 l-0.18 -3.52 3.82 -0.35 3.82 -0.35 -4.87 -0.06 c-3.11 -0.06 -4.76 -0.35 -4.52 -0.70 0.23 -0.35 4.70 -0.59 11.51 -0.59 10.63 0 11.10 0.06 11.10 1.17 0 0.76 -0.29 1.06 -0.88 0.82 -1.12 -0.47 -1.12 0.94 -0.06 6.52 0.47 2.29 0.70 4.29 0.53 4.58 -0.12 0.23 0.35 0.41 1 0.41 0.94 0 1.17 0.29 0.94 0.88 -0.23 0.70 0.23 0.88 2.29 0.88 2.23 0 2.64 0.18 2.64 1.12 0 0.94 -0.47 1.17 -2.47 1.35 -2.35 0.18 -2.52 0.29 -2.41 1.82 0.18 2.94 2.64 9.80 3.46 9.80 0.88 0 1.12 1.53 0.41 1.94 -0.29 0.18 -5.52 0.47 -11.68 0.70 -9.80 0.35 -11.27 0.29 -11.92 -0.53z" />

			<path d="M202.25 118.01 c-7.81 -1.29 -14.50 -5.81 -18.38 -12.33 -3.76 -6.40 -4.11 -16.38 -0.82 -23.07 2.64 -5.40 6.34 -9.22 11.39 -11.92 l4.29 -2.29 7.05 0 c6.46 0 7.34 0.12 10.45 1.59 4.34 2.05 9.75 7.22 11.74 11.21 2.23 4.64 2.94 8.10 2.64 13.50 -0.29 6.28 -2.58 11.33 -7.22 16.20 -5.93 6.22 -12.74 8.51 -21.14 7.10z" />

			<path d="M140.31 210.35 c-21.43 -0.82 -49.14 -2 -54.72 -2.29 l-6.93 -0.41 0 -1.53 0 -1.59 11.92 -0.76 c6.52 -0.41 14.15 -1 16.97 -1.29 4.70 -0.47 23.78 -1.70 41.04 -2.58 9.98 -0.53 10.16 -0.41 9.80 5.40 -0.18 2.88 -0.59 4.58 -1.12 4.93 -0.76 0.47 -6.75 0.53 -16.97 0.12z" />

			<path d="M56.07 206.71 c-4.17 -0.65 -1.23 -1.23 7.57 -1.64 8.51 -0.35 8.57 -0.35 8.57 0.88 0 1.23 -0.18 1.29 -6.87 1.23 -3.82 -0.06 -7.98 -0.29 -9.28 -0.47z" />

			<path d="M129.75 190.39 c-1.76 -0.06 -18.55 -0.70 -37.28 -1.35 -18.73 -0.70 -34.93 -1.35 -35.93 -1.53 -1.35 -0.18 -1.94 -0.59 -1.94 -1.35 0 -0.94 1.29 -1.12 16.03 -1.76 8.75 -0.35 21.37 -0.94 28 -1.29 27.95 -1.35 40.16 -1.59 41.74 -0.76 2.82 1.41 3.05 4.23 0.59 6.75 -1.82 1.82 -1.76 1.82 -11.21 1.29z" />

			<path d="M128.87 171.61 c-9.98 -0.41 -20.14 -0.94 -22.43 -1.12 -4.23 -0.35 -4.29 -0.35 -4.29 -2.05 0 -1.29 0.29 -1.76 1.35 -1.94 0.70 -0.18 13.03 -0.82 27.36 -1.47 18.61 -0.82 26.42 -0.94 27.42 -0.53 2.99 1.41 3.05 5.11 0.12 7.16 -1.59 1.12 -3.70 1.12 -29.53 -0.06z" />

			<path d="M120.65 156.17 c-5.81 -0.29 -26.65 -1.17 -46.38 -2.05 -19.67 -0.88 -36.16 -1.64 -36.58 -1.82 -0.35 -0.12 -0.70 -0.76 -0.70 -1.41 0 -1.12 0.35 -1.17 8.10 -1.17 4.40 0 12.80 -0.23 18.61 -0.59 39.34 -2.17 73.50 -3.41 76.20 -2.70 1.64 0.41 3.35 2.94 3.35 4.99 0 0.70 -0.76 2.05 -1.70 3.11 -1.59 1.82 -1.94 1.94 -5.99 2 -2.41 0.06 -9.10 -0.12 -14.91 -0.35z" />

			<path d="M144.25 210.35 c-0.23 -0.18 -3.64 -0.59 -7.63 -0.76 l-7.16 -0.41 9.10 -0.06 c4.99 0 9.57 -0.23 10.16 -0.41 0.59 -0.23 1 -0.18 1 0.23 0 0.41 1.23 0.65 3.23 0.65 2.47 0 3.23 -0.18 3.23 -0.88 0 -0.47 -0.41 -0.88 -0.88 -0.88 -1.23 0 -1.12 -1 0.18 -1.29 0.82 -0.23 1.06 -0.82 0.94 -2.76 -0.12 -3.11 -0.35 -3.29 -2.52 -2.23 -1.17 0.59 -1.82 0.65 -2 0.23 -0.12 -0.41 -0.94 -0.41 -2.70 0.06 -1.41 0.35 -3.11 0.41 -3.82 0.18 -0.65 -0.29 -2.70 -0.41 -4.46 -0.35 -1.76 0.12 -6.63 0.35 -10.86 0.53 -4.17 0.18 -9.45 0.47 -11.74 0.70 -4.81 0.41 -4.17 0.41 -6.69 0.29 -1.29 -0.06 -1.82 -0.29 -1.47 -0.59 0.47 -0.47 14.56 -1.47 38.98 -2.76 l7.40 -0.35 1.88 1.59 c1.59 1.29 1.88 2 1.88 4.11 0 2.11 -0.29 2.82 -1.82 4.05 -1.64 1.41 -2.17 1.53 -7.81 1.53 -3.35 0 -6.22 -0.18 -6.40 -0.41z" />

			<path d="M71.63 207.24 c-0.82 -0.23 -4.11 -0.53 -7.34 -0.53 -6.63 -0.06 -9.04 -0.82 -4.58 -1.47 1.53 -0.18 8.63 -0.47 15.73 -0.65 7.10 -0.12 11.27 -0.06 9.28 0.18 -4.17 0.47 -5.23 1.12 -2.64 1.64 1.06 0.23 1.70 0.59 1.53 0.88 -0.35 0.65 -9.98 0.59 -11.98 -0.06z" />

			<path d="M49.37 187.28 c-6.28 -0.23 -7.16 -0.41 -6.34 -1.12 0.70 -0.59 3.70 -0.82 11.10 -1 10.27 -0.23 11.80 -0.06 5.52 0.65 -2.05 0.23 -3.17 0.59 -2.76 0.88 1.29 0.76 -0.06 0.88 -7.51 0.59z" />

			<path d="M118.89 171.02 c-1.29 -0.06 -9.45 -0.41 -18.20 -0.76 -19.14 -0.76 -23.48 -1.12 -22.72 -1.88 0.65 -0.65 5.75 -1 43.21 -2.82 24.66 -1.17 32.47 -1.41 32 -0.88 -0.29 0.29 -12.45 1.23 -22.25 1.70 -3.41 0.18 -8.40 0.47 -11.15 0.65 -2.76 0.18 -7.16 0.41 -9.86 0.53 -3.11 0.12 -5.17 0.47 -5.58 1 -0.59 0.65 -0.53 0.65 0.29 0.06 0.53 -0.41 1.12 -0.53 1.29 -0.23 0.18 0.29 1.41 0.47 2.70 0.41 1.35 -0.06 2.52 0.12 2.70 0.41 0.18 0.23 2.64 0.47 5.58 0.47 2.88 0 5.23 0.23 5.23 0.53 0 0.82 -0.59 0.94 -3.23 0.82z" />

			<path d="M140.55 154.35 c1.23 -1.82 1.17 -4.17 -0.12 -5.81 l-1.06 -1.29 -19.61 0.65 c-24.25 0.82 -28 0.82 -27.24 0.12 0.65 -0.70 45.09 -2.17 47.38 -1.64 1.94 0.53 3.70 3.70 3.11 5.87 -0.18 0.76 -1.06 1.88 -1.88 2.47 l-1.59 1 1 -1.35z" />

			<path d="M62.76 153.58 c-0.12 -0.18 -4.87 -0.41 -10.51 -0.59 -24.83 -0.76 -29.47 -1.06 -28.65 -1.88 0.53 -0.47 4.11 -0.76 11.92 -0.94 6.16 -0.18 9.51 -0.18 7.51 -0.06 -6.28 0.41 -4.11 1.17 4.40 1.59 15.62 0.76 20.02 1.12 19.73 1.59 -0.29 0.53 -3.93 0.76 -4.40 0.29z" />

			<path d="M254.50 245.87 c-0.12 -0.41 -0.29 -3.46 -0.41 -6.75 -0.29 -8.04 -1 -9.10 -6.69 -9.75 -5.58 -0.59 -7.34 -1.29 -9.92 -3.82 -2.41 -2.35 -5.28 -7.93 -5.87 -11.39 -0.41 -2.11 -0.35 -2.17 1.76 -2.58 3.41 -0.65 9.10 0.06 12.51 1.53 3.76 1.64 7.28 5.40 8.87 9.63 0.65 1.76 1.23 2.64 1.41 2.11 0.18 -0.47 1 -2.99 1.82 -5.58 1.82 -5.52 3.82 -8.28 7.75 -10.39 3.23 -1.70 8.87 -2.99 11.98 -2.70 l2.05 0.18 -0.06 2.94 c-0.06 4.23 -1.12 7.40 -3.46 10.51 -2.52 3.23 -5.34 4.81 -10.33 5.58 -2.52 0.41 -4.11 1.06 -5.05 1.94 -1.64 1.64 -2.52 6.05 -2.52 12.56 0 2.64 -0.18 5.23 -0.35 5.75 -0.41 1.12 -3.11 1.23 -3.46 0.23z" />
		</g>
	</svg>
);

/**
 * VuloPilot SEO sidebar.
 */
const VuloPilotSeoPlugin = () => {
	const toolbarPortalContainer = useToolbarScorePillsPortal();
	// Toolbar score pills live in a separate portaled tree from PostSeoPanel below, so a click
	// on one needs to reach the panel's tab state from here, the shared parent - `requestedTab`
	// bridges the two. Re-keys on every click (`Date.now()`) so clicking the same pill twice in a
	// row still re-navigates even though the tab name itself didn't change.
	const [ requestedTab, setRequestedTab ] = useState< { tab: 'general' | 'ai-visibility'; target?: string; nonce: number } | null >( null );

	const openSidebarToTab = ( tab: 'general' | 'ai-visibility', target?: string ) => {
		setRequestedTab( { tab, target, nonce: Date.now() } );
		dispatch( 'core/edit-post' ).openGeneralSidebar( `vulopilot-seo/${ SIDEBAR_NAME }` );
	};

	useOpenSidebarOnTitleClick();

	useEffect( () => {
		if ( ! shouldOpenSidebar ) {
			return;
		}

		/**
		 * Open VuloPilot's sidebar when arriving through
		 * one of the SEO/GEO/AEO deep links.
		 */
		const open = () => {
			dispatch( 'core/edit-post' ).openGeneralSidebar(
				`vulopilot-seo/${ SIDEBAR_NAME }`
			);
		};

		open();

		/**
		 * WordPress may restore the previously selected sidebar during editor initialization.
		 */
		const timeout = setTimeout( open, 500 );

		return () => clearTimeout( timeout );

	}, [] );

	return (
		<>
			{ toolbarPortalContainer && createPortal(
				<ToolbarScorePills
					onClickSeo={ () => openSidebarToTab( 'general', 'snippet' ) }
					onClickAeo={ () => openSidebarToTab( 'ai-visibility' ) }
				/>,
				toolbarPortalContainer
			) }

			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME } icon={ <VuloPilotIcon /> }>
				{ __( 'VuloPilot SEO', 'vulopilot' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'VuloPilot SEO', 'vulopilot' ) }
				icon={ <VuloPilotIcon /> }
			>
				<PostSeoPanel
					initialTabName={ requestedTab?.tab ?? deepLinkTab }
					highlightTarget={ requestedTab ? undefined : deepLinkHighlight }
					navigationNonce={ requestedTab?.nonce }
					navigationTarget={ requestedTab?.target }
				/>
			</PluginSidebar>
		</>
	);
};


registerPlugin( 'vulopilot-seo', { render: VuloPilotSeoPlugin } );
