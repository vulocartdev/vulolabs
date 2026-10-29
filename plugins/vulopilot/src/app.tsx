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

// Forces initializeModules() (called in index.tsx, right after this module is imported) to actually
// fetch active modules from the server on every load.
localStorage.setItem('force_vulopilot_context_reload', 'true');

/**
 * Reads the active tab from the URL hash (`?page=vulopilot#&tab=dashboard`) and renders the
 * component registered for it in routes.ts. Uses the hash rather than path routes, since every
 * admin URL is `admin.php?page=vulopilot`.
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
			// Real dropdown category ('modules'/'settings'/'sections').
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
	 * A page-section result's target tab may not be mounted yet at click time (the hash change
	 * above triggers Route's own async re-render).
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

	// Highlight the active tab in the WP admin sidebar submenu. Content Planner has no menu
	// entry of its own (it opens from the Content page), so it highlights Content.
	useEffect(() => {
		const menuTab = currentTab === 'content-planner' ? 'content' : currentTab;

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

				if (menuItemHashParams.get('tab') === menuTab) {
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
