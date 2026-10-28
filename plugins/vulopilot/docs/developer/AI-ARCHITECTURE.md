# AI Architecture

How the plugin talks to AI. There is one path: every request goes through **VuloCloud**, VuloLabs' AI service. The plugin never holds a provider key and never calls an AI vendor directly.

```
Feature (Copilot chat, an AI action, an analyzer)
   -> AiRequestSender::send( $messages, $image = null, $surface = null ): AIResponse              (only actions added by other plugins)
   -> AiRequestSender::send_feature( $feature_id, $action, $context, $surface, $label ): AIResponse  (every built-in feature: actions, analyzers, both chats)
        -> AiCreditsConnection::execute() / execute_feature() -> VuloCloud
   -> AiHistoryRepository writes one excerpt-only row per call (vulopilot_ai_history)
```

## Connection

| Class | Role |
|---|---|
| `AiAssistant\VuloCloudAccountConnection` | Login state of the person connecting the site; status is localized to the React app |
| `AiAssistant\ConnectBrokerCallbackHandler` | Handles the redirect back from VuloCloud when connecting |
| `AiAssistant\AiCreditsConnection` | Site credentials and the credit balance |
| `AiAssistant\CredentialEncryption` | Encrypts stored secrets |
| `AiAssistant\SiteToneLearner`, `SiteTelemetryReporter` | Site tone and connection telemetry |

Data sent to VuloCloud and the links to its terms are documented in the readme's **External services** section. Update it whenever the payload changes.

## Request and response

- `AiRequestSender::send()` takes chat-style messages, an optional image and a `surface` label (which screen or feature asked). It returns an `AIResponse` (content, credits used, request id).
- A `VuloPilotException` of type "not configured" makes the caller fall back to credits where the action supports it; otherwise the error is shown to the user.

## History and privacy

`vulopilot_ai_history` stores excerpts only. Full chat threads are in `vulopilot_ai_conversations` (Copilot only). See [DATABASE](DATABASE.md).

## Safety rules for AI output

- AI text that becomes post content is passed through `wp_kses_post()`; titles through `sanitize_text_field()`, before saving.
- Nothing changes the site until a person approves (see [AI-ACTIONS](AI-ACTIONS.md)); every applied change can be rolled back.

## Class reference

| Class | File | What it does |
|---|---|---|
| `AIResponse` | `classes/AiAssistant/AIResponse.php` | The response returned by the VuloCloud AI API, including the credits consumed by the request. |
| `ActionExecutionResult` | `classes/AiAssistant/ActionExecutionResult.php` | The outcome of an AIActionInterface::execute() call. |
| `ActionPreview` | `classes/AiAssistant/ActionPreview.php` | The human-facing preview an AIActionInterface::build_preview() returns, shown to the user before they approve an ActionRunner::propose() call. |
| `ActionRunRepository` | `classes/AiAssistant/ActionRunRepository.php` | - |
| `AiCreditsConnection` | `classes/AiAssistant/AiCreditsConnection.php` | The real "AI Credits" site connection - a genuine `ConnectedSite` credential (siteId + secret) minted by VuloCloud's own `contexts/vulopilot/ai-credits` bounded context. |
| `AiHistoryRepository` | `classes/AiAssistant/AiHistoryRepository.php` | - |
| `AiRequestSender` | `classes/AiAssistant/AiRequestSender.php` | The one path every real AI call in this plugin goes through: safety-validate the prompt, make sure this site is connected to VuloCloud, spend one request from the per-minute budget, send `{feature, prompt, site_tone}` to the VuloC |
| `ConnectBrokerCallbackHandler` | `classes/AiAssistant/ConnectBrokerCallbackHandler.php` | Handles the Connect broker's real redirect back to this site (`admin-post.php?action=vulopilot_connect_broker_callback` - AiCreditsConnection::get_broker_redirect_uri()'s own exact URL). |
| `CredentialEncryption` | `classes/AiAssistant/CredentialEncryption.php` | Encrypts/decrypts third-party secrets (Backups' S3/Drive credentials, the VuloCloud site secret, Google tokens) before they're stored. |
| `SiteTelemetryReporter` | `classes/AiAssistant/SiteTelemetryReporter.php` | Reports this site's own real WordPress/PHP/theme/plugin details to VuloCloud's generic `POST /connected-sites/ingest` endpoint - the one HTTP surface that fills in the Connected Sites detail page's "Site & Server"/"Plugin & Theme" |
| `SiteToneLearner` | `classes/AiAssistant/SiteToneLearner.php` | Keeps `vulopilot_site_tone` (the placeholder field added earlier this session - sent as a `site_tone` hint on every direct VuloCloud AI request, see AiAssistant\AiRequestSender) learned automatically from the site's own recent con |
| `VuloCloudAccountConnection` | `classes/AiAssistant/VuloCloudAccountConnection.php` | Read-only from this class's own side: `FrontendScripts::localize_scripts()` surfaces `get_status()` as `vulopilotAppLocalizer.vulocloud_connected`/ `vulocloud_account_email` (a display-only badge), and AiCreditsConnection::get_sta |
| `AiCredits` | `classes/AiAssistant/Rest/AiCredits.php` | - |
| `AiHistory` | `classes/AiAssistant/Rest/AiHistory.php` | GET /ai-history backs src/pages/AIAssistant/AIAssistant.tsx's table. |
| `VuloCloudAiConnection` | `classes/AiAssistant/Rest/VuloCloudAiConnection.php` | - |

Hooks and routes registered by these classes:

- `ConnectBrokerCallbackHandler` - hooks: `admin_post_vulopilot_connect_broker_callback`
- `SiteTelemetryReporter` - hooks: `init`
- `SiteToneLearner` - hooks: `save_post`
- `AiCredits` - ; routes: `/ai-credits/disconnect`, `/ai-credits/refresh-balance`, `/ai-credits/status`
- `AiHistory` - ; routes: `/ai-history`
- `VuloCloudAiConnection` - ; routes: `/vulocloud-ai-connection`, `/vulocloud-ai-connection/broker-authorize-url`
