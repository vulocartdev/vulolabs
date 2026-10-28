# AI Actions

An **AI action** is a change to the site drafted by AI and applied only after a person approves it. Every action follows the same lifecycle, run by `ActionRunner`.

```
propose()   input -> validate -> feature context (or prompt) -> AI call -> parse -> validate output -> preview   [row: pending_approval]
approve()   execute()                                                                            [row: executed | failed]
reject()    record the decision                                                                  [row: rejected]
rollback()  rollback( snapshot )                                                                 [row: rolled_back]
```

`propose()` and `approve()` are always separate HTTP requests, so the run is persisted in `vulopilot_ai_action_runs` between them (input, output, preview, snapshot, who requested, who approved and how).

## Contract

`VuloPilot\Utill\AIActionInterface`:

| Method | Job |
|---|---|
| `get_id()`, `get_label()`, `get_tier()` | Identity |
| `get_risk_level()` | Risk of the change (`Impact` constants) |
| `validate_input( array $input ): array` | Check and normalize the request; throw `VuloPilotException` on bad input |
| `build_prompt( array $input ): array` | Messages for the AI |
| `parse_response( AIResponse ): array` | Turn the raw text into structured output |
| `validate_output( array $output, array $input ): void` | Reject unusable output |
| `build_preview( array $output, array $input ): ActionPreview` | Summary plus before/after shown to the person |
| `execute( array $output, array $input ): ActionExecutionResult` | Apply the change; the result carries the snapshot needed to undo it |
| `rollback( array $snapshot ): void` | Undo |

Extend `AiCopilot\Actions\AbstractBasicAction` for the defaults.

### Built-in actions: AI-service features

Every built-in action (free and Pro) implements `VuloPilot\Utill\CloudAIActionInterface` instead of writing its own prompt. The AI service owns the wording, so it can be improved without a plugin release:

| Method | Job |
|---|---|
| `get_cloud_feature(): array` | `[ featureId, action ]` of the AI-service feature this action runs |
| `build_cloud_context( array $input ): array` | The feature's structured input, built from `validate_input()`'s result (WordPress lookups such as permalinks and attachment filenames happen here) |

`ActionRunner` sends these through `AiRequestSender::send_feature()`. Extend `AiCopilot\Actions\AbstractCloudAction` (free) or `VuloPilotPro\Utill\ActionUtil` (Pro); their `build_prompt()` is never called. A new built-in action needs its feature added to the AI service first.

Actions added by other plugins keep implementing `build_prompt()` and go through `AiRequestSender::send()`.

## Rules every action must follow

1. Sanitize before saving: `wp_kses_post()` for HTML body content, `sanitize_text_field()` for titles and plain fields.
2. Put everything needed to undo the change in the snapshot returned by `execute()`.
3. Validate the AI output; never trust its structure.
4. Do not publish content. Create drafts unless the action's purpose requires otherwise.

## Approval modes

`ActionRunner::propose()` persists the run first. It then self-approves (`approval_method` `auto_unattended`) only when the site setting `ai_change_approval_mode` allows it for the action's risk level: `always` (default) asks a person, `risk_based` auto-approves low risk, `never` auto-approves everything. Add-ons that run actions may approve with their own method after `propose()`.

## Registry and REST

`AiCopilot\ActionRegistry` starts from its default class list and applies `vulopilot_ai_action_sources`, so other plugins can add actions. REST controllers: `AiActionRuns` (propose, approve, reject, rollback, list) and `Copilot` (chat). See [REST-API](REST-API.md).

## Add an action

```php
add_filter( 'vulopilot_ai_action_sources', fn( array $c ) => array_merge( $c, array( \MyPlugin\MyAction::class ) ) );
```

## Actions in the core

| Class | File | What it does |
|---|---|---|
| `AbstractBasicAction` | `modules/AiCopilot/Actions/AbstractBasicAction.php` | Base class for actions that build their own prompt (third-party actions). |
| `AbstractCloudAction` | `modules/AiCopilot/Actions/AbstractCloudAction.php` | Base class for every free-tier action under AiCopilot/Actions/; runs an AI-service feature. |
| `AddSubheadingsAction` | `modules/AiCopilot/Actions/AddSubheadingsAction.php` | Fixes Seo\Scanners\HeadingStructureScanner's finding: 300+ word content with no `h2`-`h6` tag anywhere in it. |
| `AuditContentAction` | `modules/AiCopilot/Actions/AuditContentAction.php` | Create Content's "AI Content Audit" quick action (QuickActionsCard.tsx) - a real, standalone AI action rather than the in-page scroll shortcut this row used to be (it used to jump to RecentContentCard.tsx's own rule-based scanner  |
| `DifferentiateDuplicateTitleAction` | `modules/AiCopilot/Actions/DifferentiateDuplicateTitleAction.php` | Fixes Seo\Scanners\DuplicateContentScanner's finding: two or more published posts sharing the exact same title. |
| `GenerateBlogAction` | `modules/AiCopilot/Actions/GenerateBlogAction.php` | The new-content-creation pattern - the odd one out among the four built-in actions: its input is a topic the site owner types, not a Recommendation's object_type/object_ref (there's no existing post or attachment this operates on; |
| `ImproveReadabilityAction` | `modules/AiCopilot/Actions/ImproveReadabilityAction.php` | The existing-content-rewrite pattern: unlike GenerateAltAction's single postmeta value, this replaces a post's entire `post_content` - a much larger snapshot, and a real risk (an AI rewrite could gut the content) that validate_out |
| `WriteMetaDescriptionAction` | `modules/AiCopilot/Actions/WriteMetaDescriptionAction.php` | - |
| `WritePostContentAction` | `modules/AiCopilot/Actions/WritePostContentAction.php` | Create Content's "AI Writer" tool (ContentToolsGrid.tsx) - given a short brief of what to write, creates a new draft post with AI-written body copy. |

## Runner, registry and REST

| Class | File | What it does |
|---|---|---|
| `ActionRunner` | `modules/AiCopilot/ActionRunner.php` | Orchestrates every AIAction through its full lifecycle - the same orchestrator role Scanners\ScanRunner and RuleEngine\RuleEngine play for their own engines, but split across four public methods instead of one `run()`, because "Ap |
| `ActionRegistry` | `modules/AiCopilot/ActionRegistry.php` | - |
| `ContentCreationOrchestrator` | `modules/AiCopilot/ContentCreationOrchestrator.php` | The shared "parse an orchestrator's JSON decision, then really create the content" half of what used to be Controllers\ContentAssistant.php alone. |
| `AiActionRuns` | `modules/AiCopilot/Rest/AiActionRuns.php` | GET /ai-action-runs backs the Dashboard's "Pending Approval" widget. |
| `Copilot` | `modules/AiCopilot/Rest/Copilot.php` | Reuses VuloPilot()->ai_request_sender (AI\AiRequestSender) exactly like ContentAssistant.php and GeoAnalyzer already do - same safety-validate → send → sanitize sequence, and every call is automatically recorded to `vulopilot_ai_h |

Hooks and routes registered by these classes:

- `ActionRegistry` - hooks: `init`
- `AiActionRuns` - ; routes: `/ai-action-runs`, `/ai-action-runs/(?P<id>\d+)/approve`, `/ai-action-runs/(?P<id>\d+)/reject`, `/ai-action-runs/(?P<id>\d+)/rollback`
- `Copilot` - ; routes: `/copilot/chat`, `/copilot/conversations`, `/copilot/conversations/(?P<id>\d+)`
