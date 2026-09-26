/* global vulopilotAppLocalizer */
import { Suspense, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';
import { HeaderComponent } from '@zyra/components';
import { scrollToId } from '@zyra/core';
import Brand from './assets/images/brand-logo.png';
import { searchIndex, SearchItem } from './searchIndex';
import AiCreditsIndicator from './components/AiCredits/AiCreditsIndicator';
import InsufficientCreditsNotice from './components/AiCredits/InsufficientCreditsNotice';
import './routeRegistry';
import './routes';

// Forces initializeModules() (called in index.tsx, right after this module
// is imported) to actually fetch active modules from the server on every
// load - without this, useModules()'s zustand store stays at its initial
// `modules: []` forever and every moduleEnabled-gated field/module card
// looks permanently locked, matching the vulolabs/catalogx app.tsx
// pattern.
localStorage.setItem('force_vulopilot_context_reload', 'true');

/**
 * Reads the active tab from the URL hash (`?page=vulopilot#&tab=dashboard`)
 * and renders whichever component registered itself for that tab in
 * routes.ts - the same hash-driven tab system the free vulolabs
 * plugin's admin screen uses (see react-frontend.md), rather than
 * react-router path routes, since every VuloPilot admin URL is really
 * `admin.php?page=vulopilot` with WordPress itself only ever serving that
 * one PHP-rendered page.
 */
const Route = () => {
	const location = useLocation();
	const [routes, setRoutes] = useState([...window.VULOPILOT_ROUTES]);

	useEffect(() => {
		const updateRoutes = () => setRoutes([...window.VULOPILOT_ROUTES]);

		window.addEventListener('vulopilot-routes', updateRoutes);

		return () =>
			window.removeEventListener('vulopilot-routes', updateRoutes);
	}, []);

	const tab = new URLSearchParams(location.hash).get('tab') || 'dashboard';
	const route = routes.find((r) => r.tab === tab);
	const Component = route?.component;

	if (!Component) {
		return null;
	}

	return (
		<Suspense fallback={<Spinner />}>
			<Component />
		</Suspense>
	);
};

const App = () => {
	const currentTabParams = new URLSearchParams(useLocation().hash);
	const currentTab = currentTabParams.get('tab') || 'dashboard';
	const [results, setResults] = useState<SearchItem[]>([]);

	const handleQueryUpdate = ({
		searchValue,
		searchAction,
	}: {
		searchValue: string;
		searchAction?: string;
	}) => {
		if (!searchValue.trim()) {
			setResults([]);
			return;
		}

		const lower = searchValue.toLowerCase();

		const filtered = searchIndex.filter((item) => {
			// Real dropdown category ('modules'/'settings'/'sections') -
			// not `item.tab`, each result's own real (and varied)
			// destination tab, which searchIndex.ts's own `SearchItem.category`
			// docblock explains was the actual bug here: picking "Settings"/
			// "Modules" filtered on a field that was never literally
			// 'settings'/'modules', so it silently matched nothing.
			if (
				searchAction &&
				searchAction !== 'all' &&
				item.category !== searchAction
			) {
				return false;
			}

			return (
				item.name?.toLowerCase().includes(lower) ||
				item.desc?.toLowerCase().includes(lower)
			);
		});

		setResults(filtered);
	};

	/**
	 * A page-section result's target tab may not be mounted yet at click
	 * time (the hash change above triggers Route's own async re-render) -
	 * `scrollToId()` itself is a one-shot `getElementById` + `scrollIntoView`
	 * with no retry (confirmed reading zyra's source), so it'd silently no-op
	 * if called synchronously right after switching tabs. Poll briefly for
	 * the real DOM id that section's own card renders instead of a fixed
	 * delay, since mount time varies by tab (a heavier tab's first fetch
	 * takes longer to paint its cards than a lighter one).
	 */
	const scrollToSectionWhenReady = (sectionId: string, attempt = 0) => {
		if (document.getElementById(sectionId)) {
			scrollToId(sectionId);
			return;
		}

		if (attempt < 20) {
			setTimeout(
				() => scrollToSectionWhenReady(sectionId, attempt + 1),
				100
			);
		}
	};

	const handleResultClick = (item: SearchItem) => {
		window.location.hash = item.link;

		if (item.sectionId) {
			scrollToSectionWhenReady(item.sectionId);
		}
	};

	// Highlight the active tab in the WP admin sidebar submenu.
	useEffect(() => {
		document
			.querySelectorAll('#toplevel_page_vulopilot > ul > li > a')
			.forEach((menuItem) => {
				const menuItemUrl = new URL(
					(menuItem as HTMLAnchorElement).href
				);
				const menuItemHashParams = new URLSearchParams(
					menuItemUrl.hash.substring(1)
				);

				if (menuItem.parentNode) {
					(menuItem.parentNode as HTMLElement).classList.remove(
						'current'
					);
				}

				if (menuItemHashParams.get('tab') === currentTab) {
					(menuItem.parentNode as HTMLElement).classList.add(
						'current'
					);
				}
			});
	}, [currentTab]);

	return (
		<>
			<InsufficientCreditsNotice />
			<HeaderComponent
				brandImg={Brand}
				results={results}
				beforeSearch={<AiCreditsIndicator />}
				search={{
					placeholder: __('Search…', 'vulopilot'),
					options: [
						{
							value: 'all',
							label: __('Every Where', 'vulopilot'),
						},
						{
							value: 'modules',
							label: __('Modules', 'vulopilot'),
						},
						{
							value: 'settings',
							label: __('Settings', 'vulopilot'),
						},
						{
							value: 'sections',
							label: __('Sections', 'vulopilot'),
						},
					],
				}}
				onQueryUpdate={handleQueryUpdate}
				onResultClick={handleResultClick}
				free={vulopilotAppLocalizer.version}
				pro={vulopilotAppLocalizer.pro_data.version}
				searchSize={7}
			/>
			<Route />
		</>
	);
};

export default App;
