import { registerVuloPilotRoute } from './routeRegistry';

import Dashboard from './pages/Dashboard/Dashboard';
import Performance from './pages/Performance/Performance';
import SeoVisibility from './pages/GEO/SeoVisibility';
import Commerce from './pages/Commerce/Commerce';
import Automations from './pages/Automations/Automations';
import Reports from './pages/Reports/Reports';
import AIAssistant from './pages/AIAssistant/AIAssistant';
import Settings from './pages/Settings/Settings';
import Security from './pages/Security/Security';
import SiteHealth from './pages/SiteHealth/SiteHealth';
import Accessibility from './pages/Accessibility/Accessibility';
import Content from './pages/Content/Content';
import SetupWizard from './pages/SetupWizard/SetupWizard';

registerVuloPilotRoute({ tab: 'dashboard', component: Dashboard });
// Not listed in Admin.php's $submenus - reached only via the activation redirect
// (Admin::maybe_redirect_to_setup_wizard()) or a direct link, never shown in the sidebar.
registerVuloPilotRoute({ tab: 'setup-wizard', component: SetupWizard });
registerVuloPilotRoute({ tab: 'performance', component: Performance });
registerVuloPilotRoute({ tab: 'seo-visibility', component: SeoVisibility });
registerVuloPilotRoute({ tab: 'commerce', component: Commerce });
registerVuloPilotRoute({ tab: 'automations', component: Automations });
registerVuloPilotRoute({ tab: 'reports', component: Reports });
registerVuloPilotRoute({ tab: 'ai-assistant', component: AIAssistant });
// No standalone `tab: 'modules'` route any more.
registerVuloPilotRoute({ tab: 'settings', component: Settings });
registerVuloPilotRoute({ tab: 'security', component: Security });
registerVuloPilotRoute({ tab: 'site-health', component: SiteHealth });
registerVuloPilotRoute({ tab: 'accessibility', component: Accessibility });
registerVuloPilotRoute({ tab: 'content', component: Content });
// 'content-planner' is registered by vulopilot-pro's ContentOptimization module
// (modules/ContentOptimization/src/index.tsx) - Pro-only, no Free fallback page.
