# VuloLabs (free-tier base) - Claude Code Guide

This is a **separate git repository**, mounted as a submodule of the parent `vulolabs-pro` monorepo. It holds the free/base-tier plugins `vulopilot` and `vulocart`.

**Git boundary**: edits to files here are fine and expected - the Pro plugins are built against this code. But `git add`/`git commit`/`git push` **inside this directory only when explicitly asked**. Committing here is a separate commit in this repo's own history; the parent repo then needs its submodule pointer updated in a second, separate commit - see `DEVELOPER-DOC.md` for the exact recipe. Never run `git submodule update`/`git checkout` here without confirming first.

## Structure

```
vulolabs/
├── plugins/
│   ├── vulopilot/    free-tier "AI Operating System" plugin - SEO/scan/automation, not WooCommerce-bound
│   └── vulocart/      free-tier commerce engine - Cart/Order modules, WooCommerce-adjacent
├── tools/             shared webpack config + release/build scripts
├── DEVELOPER-DOC.md   one-time env setup + new-plugin bootstrap recipe
└── CLAUDE.md          this file
```

## These two plugins are independent of each other

`vulopilot` and `vulocart` are two unrelated products that happen to share this monorepo, tooling, and the `zyra` UI kit. Each has:

- **Its own REST namespace** (`vulopilot/v1`, `vulocart/v1` - `$this->container['rest_namespace']` in each plugin's own main class), not a shared platform namespace.
- **Its own module loader** (`classes/Modules.php` in each plugin, same folder-scan/reflection shape, but a fully separate instance keyed to that plugin's own `{slug}_module_sources` filter and `{slug}_activated_module_*` hooks).
- **Its own `*_loaded` gate** (`vulopilot_loaded`, `vulocart_loaded`) that its Pro counterpart (in the sibling `vulolabs-pro` repo) boots on.
- **No `use VuloPilot\...` / `use VuloCart\...` cross-references between the two** - confirmed via grep, they don't know about each other.

Don't assume a change to one plugin's `Utill.php`/`Modules.php`/REST controllers has any bearing on the other.

## Module counts

- `vulopilot`: 6 modules (`AiCopilot`, `BrandVisibility`, `ContentOptimization`, `GeoAnalysis`, `KnowledgeGraph`, `TechnicalSeo`), all seeded active on a fresh install by `VuloPilot::activate()`. Module id = kebab-cased folder name, and `Modules::load_active_modules()` drops any stored id that has no matching folder - so every toggle card `id` in `src/components/Modules/index.ts` must exactly match a real module folder (in this plugin or vulopilot-pro), or the toggle won't stick. vulopilot-pro ships same-named folders for 5 of these (all but `AiCopilot`); activating the id boots both the free and Pro `Module` classes.
- `vulocart`: 9 modules. `Cart`/`Order` auto-activate on fresh install and are treated more like built-in core than optional add-ons (seeded once via a `vulocart_cart_order_modules_seeded` option flag). `Customer`/`Address`/`Shipping`/`Taxes`/`Payment`/`Review`/`Confirmation` back the storefront checkout wizard's own steps, auto-activated the same way via a separate `vulocart_checkout_modules_seeded` flag.

## Where `vulopilot` code lives

- **Module code goes in `modules/<Module>/`**, namespaced `VuloPilot\<Module>\...` (composer PSR-4 maps `VuloPilot\` to both `classes/` and `modules/`). Scanners in `<Module>/Scanners/`, REST controllers in `<Module>/Rest/`, analyzers/services directly in the module folder. Only a folder with a `Module.php` at the top level of `modules/` is discovered as a module - `Scanners/`/`Rest/` subfolders are just autoloaded.
- **`classes/` is shared core and always-on features only**: bootstrap, `Utill`/`Install`, contracts, value objects, repositories, the scanner/rule/report/AI registries and runners, and features that aren't a Modules-page module (performance, security, backups, sitemaps, redirects, Google/VuloCloud connections).
- **Pro-only code belongs in `vulolabs-pro`, not here.** If a class is referenced only by the Pro plugin, it moves there (e.g. `ConditionInterface` now lives in `vulopilot-pro/modules/WorkflowAutomation/Contracts/`). The reverse isn't true: vulopilot-pro `use`s many classes from here directly (`VuloPilot\Utill\*`, `VuloPilot\AiAssistant\*`, ...), so renaming or changing the signature of a public class here can break Pro - grep `vulolabs-pro/plugins/vulopilot-pro` before doing that.

## `zyra` is an external npm dependency here, not a local package

Both plugins depend on the published package `@multivendorx/zyra` (aliased in `tools/webpack/create-config.js` so existing `import ... from 'zyra'` call sites resolve correctly). There is no `packages/js/zyra` in this repo - don't look for one, and don't fork zyra into this repo; it's a shared design-system package maintained elsewhere.

## React mounting

- `vulopilot/src/index.tsx`: single mount point, `#admin-main-wrapper`, wrapped in `BrowserRouter`.
- `vulocart/src/index.tsx`: **three independent mount points** (`#vulocart-admin-root` for the main SPA wrapped in `QueryClientProvider` from `@tanstack/react-query`, plus two standalone `#vulocart-orders-admin-root`/`#vulocart-offerings-admin-root` pages) - not a single SPA the way `vulopilot` is. Don't assume every VuloLabs plugin follows the single-SPA-mount pattern.

## Everything else

PHP/WordPress conventions (bootstrap singleton pattern, hook self-registration in constructors, `defined('ABSPATH') || exit;`), coding standards, i18n, and security practices are the same throughout this repo - this file only covers what's specific to VuloLabs. See the sibling `vulolabs-pro` repo's `CLAUDE.md` for the Pro-side architecture these two plugins' Pro counterparts follow.
