# Agent Builder — notes for the WordPress.org Plugin Review team

Plugin: **Agent Builder** 3.4.0 (`agent-builder.php`, text domain `agent-builder`)
Branch / commit this document describes: `release/3.4-wporg` at `d6ccf37` (`#102: recapture .wordpress-org screenshots without the Apple Pay notice`)
Intended zip: English-only tree produced by `bin/export-wporg-tree.sh` using `.distignore`

This is not marketing copy. It is a pre-emptive map of the surfaces a reviewer will actually look at: REST routes, outbound HTTP, tool execution, stored data, WebMCP, i18n, licensing, and the gaps that are still real.

## How this was assembled

Every claim below is taken from current code on that tip, or from an in-repo report, or both. Where an older report disagrees with current code, the code wins and the disagreement is called out.

| Source | What it is | Status vs this tip |
| --- | --- | --- |
| Current PHP under `includes/`, `uninstall.php`, `readme.txt`, `.distignore`, `LICENSE`, `languages/agent-builder.pot` | Source of truth | `d6ccf37` |
| `reports/m2-security-audit-rest-capabilities.md` | REST / capability audit (47 `register_rest_route()` calls at the time) | Several findings have since been patched; remaining items are restated from live code in §8 |
| `reports/m2-safety-center-design.md` | Safety Center design (written as design-only) | The screen itself is now implemented (`agentic-safety-center`); underlying risk/approval/kill-switch machinery it describes is still the live model |
| `reports/m4-guidelines-checklist.md` | Guideline checklist | Partially stale: Site Passport URL mismatch is fixed in `readme.txt`; `.wordpress-org/` now exists; uninstall of `agentic_agent_library` / `agentic_skills` was fixed in `38aa28e` |
| `reports/m0-baseline.md` | Named in the task that produced this file | **Not in this repository** (not on `release/3.4-wporg` or `main`). Nothing was invented from it. |
| `STATE.md` | Cited by the M2 audit for the jobs / anonymous-session incident | **Not in this branch.** The anonymous-session gap is re-checked against current `class-rest-api.php` in §1 and §8. |

Related in-plugin comments that already point at a `SUBMISSION-NOTES.md` (historically expected at the plugin root, which `.distignore` excludes) live in `includes/class-webmcp-bridge.php` and `includes/class-directory-submission.php`. This file is the `reports/` draft of that reviewer document.

---

## 1. REST API routes and capability / nonce gating

All plugin REST routes are registered from `includes/*.php` via `register_rest_route()`. There are no `register_rest_route()` calls under `admin/`, `library/`, or `src/`.

### CSRF / nonce model (applies to every cookie-authenticated route)

WordPress core, not this plugin, enforces CSRF for cookie-authenticated REST:

- Cookie + `X-WP-Nonce` for action `wp_rest` (`rest_cookie_check_errors()`).
- Application Passwords / Basic auth (the MCP client path) authenticate as a real user and do **not** use that cookie nonce.
- Routes whose `permission_callback` is `__return_true`, or that return `true` for a logged-out user without calling `current_user_can()`, do **not** get the cookie-nonce check. That is the guest path for: relay ping; native-form submit (handler then verifies `X-WP-Nonce` itself); WebMCP execute for `ANONYMOUS_SAFE_TOOLS` (same-origin check instead); and `/chat`, `/history`, `/feedback`, `/proposals`, `/tts` when `agentic_allow_anonymous_chat` is on.

The plugin documents this at the approvals route (`includes/class-rest-api.php` around the `/approvals/{id}` registration): cookie + `X-WP-Nonce`, or Application Passwords.

Custom capabilities (`agentic_manage_settings`, `agentic_manage_tools`, `agentic_manage_agents`, `agentic_view_dashboard`, `agentic_view_audit_log`, plus chat privileges) are injected through `Agentic\User_Roles` (`includes/class-user-roles.php`) via `user_has_cap`. Administrators always pass.

### 1.1 `includes/class-rest-api.php` — namespace `agentic/v1`

| Method | Route | `permission_callback` | What it actually requires |
| --- | --- | --- | --- |
| POST | `/chat` | `check_logged_in()` | Logged-in user with `chat_frontend` or `chat_admin_bar`, **or** `agentic_allow_anonymous_chat` is truthy (default **off**: `false` / `'0'`). |
| GET | `/sessions` | `check_real_user()` | Real logged-in user with a chat privilege. Guests are refused even when anonymous chat is on. Added after M2 finding #2 so listing cannot dump every `user_id = 0` session. |
| GET | `/history/{session_id}` | `check_logged_in()` | Same as `/chat`. Handler then filters `wp_{prefix}agentic_conversations` by `session_id` **and** `user_id`, unless `manage_options`. Session IDs are `wp_generate_uuid4()`. See §8 for the remaining anonymous-ownership gap. |
| GET | `/status` | `check_admin()` | `manage_options` |
| GET | `/models` | `check_admin()` | `manage_options` |
| POST | `/test-api` | `check_admin()` | `manage_options` |
| GET | `/approvals` | `check_manage_agents()` | `manage_options` or `agentic_manage_agents` |
| POST | `/approvals/{id}` | `check_manage_agents()` | Same. Body `action` enum: `approve` / `reject`. |
| POST | `/proposals/{id}` | `check_logged_in()` | Anyone who can chat. Handler `handle_proposal()` additionally requires `created_by === get_current_user_id()` or `manage_options`. `always` grants require `manage_options`. |
| DELETE | `/tool-grants/{tool}` | `check_admin()` | `manage_options` |
| POST | `/backups/restore` | `check_admin()` | `manage_options` |
| DELETE | `/backups/{file}` | `check_admin()` | `manage_options` |
| POST | `/backups/restore-table` | `check_admin()` | `manage_options` |
| DELETE | `/backups/table/{file}` | `check_admin()` | `manage_options` |
| POST | `/tts` | `check_logged_in()` | Same as `/chat`. Text capped at 4096 chars. |
| POST | `/feedback` | `check_logged_in()` | Same as `/chat`. Updates the latest assistant row for `session_id` **and** current `user_id`. Same anonymous-ownership caveat as `/history` (§8). |

`check_logged_in()` / `check_real_user()` / `check_admin()` / `check_manage_agents()` are in `includes/class-rest-api.php` (the four methods around the end of `register_routes()`).

Chat also sanitizes history roles to `user`/`assistant` only (blocks injected `system` messages), caps history at 50 items, and rate-limits via `Agentic\Chat_Security`.

### 1.2 `includes/class-admin-pages-rest.php` — `agentic/v1/admin-page`

One route, two methods:

| Method | Route | Gate |
| --- | --- | --- |
| GET | `/admin-page` | `can_manage()` |
| POST | `/admin-page` | `can_manage()` |

`can_manage()` is **not** a blanket `manage_options` check. It maps `page` / `action_name` onto the same custom caps the wp-admin menus use (`includes/class-admin-pages-rest.php`):

- `manage_options` short-circuits to allow.
- `tools` / `skills` / `toggle_tool` / `apply_tools_profile` / `delete_skill` → `agentic_manage_tools`
- `approvals` / `deployment` / approval decide actions → `agentic_manage_agents`
- `logs` → `agentic_view_audit_log`
- `agent-ready` (except `submit_to_directory`) → `agentic_manage_settings`
- `submit_to_directory` → `manage_options` (the one deliberate Site Passport phone-home)
- `set_ui_mode` → `agentic_manage_settings`
- `set_screen_mode` / `reset_screen_modes` → the cap of the screen being switched
- `train-data`, `upgrade-pro`, `safety-center`, anything unmapped → `agentic_manage_settings`

Safety Center is this GET payload: `?page=safety-center` → `safety_center_payload()`.

### 1.3 `includes/class-admin-settings-rest.php` — `agentic/v1/admin-settings*`

| Method | Route | Gate |
| --- | --- | --- |
| GET | `/admin-settings` | `can_manage()` = `agentic_manage_settings` or `manage_options` |
| POST | `/admin-settings/test-service` | same |
| GET | `/admin-settings/classic-tab` | same |
| POST | `/admin-settings/mcp-test` | same |
| POST | `/admin-settings/mcp-toggle-agent` | same |
| POST | `/admin-settings/mcp-create-credential` | `can_manage_mcp_credentials()` = **`manage_options` only** |
| POST | `/admin-settings/mcp-revoke-credential` | **`manage_options` only** |

Minting/revoking Application Passwords for MCP is deliberately tighter than reading settings.

### 1.4 Other admin React surfaces (`agentic/v1`)

| File | Method | Route | Gate |
| --- | --- | --- | --- |
| `class-agent-wizard-rest.php` | GET | `/agent-wizard/options` | `manage_options` |
| `class-agent-wizard-rest.php` | POST | `/agent-wizard/create` | `manage_options` |
| `class-dashboard-rest.php` | GET | `/dashboard-stats` | `manage_options` or `agentic_view_dashboard` |
| `class-dashboard-rest.php` | GET | `/dashboard` | same (`can_view()`) |
| `class-dashboard-rest.php` | POST | `/dashboard` | `manage_options` or `agentic_manage_settings` (`can_manage()`). Mutations are site settings or the **current user's own** usermeta. |
| `class-deploy-wizard-rest.php` | GET | `/deploy-wizard/options` | `manage_options` |
| `class-deploy-wizard-rest.php` | POST | `/deploy-wizard/save` | `manage_options` |
| `class-inventory-rest.php` | GET | `/inventory` | `agentic_manage_settings` or `manage_options` |
| `class-inventory-rest.php` | GET | `/inventory/integrity` | same (hash-chain check) |
| `class-knowledge-wizard-rest.php` | GET | `/knowledge-wizard/search-pages` | `agentic_manage_settings` or `manage_options` |
| `class-knowledge-wizard-rest.php` | POST | `/knowledge-wizard/save` | same |
| `class-score-rest.php` | GET | `/score` | `agentic_view_dashboard` or `manage_options` |
| `class-score-rest.php` | POST | `/score/rescan` | `agentic_manage_settings` or `manage_options` |
| `class-system-checker.php` | GET | `/system-check` | `manage_options` |
| `class-system-checker.php` | GET | `/timeout-test` | `manage_options` |
| `class-ui-settings-rest.php` | GET | `/ui-settings` | `agentic_manage_settings` or `manage_options` |
| `class-ui-settings-rest.php` | POST | `/ui-settings` | same |

### 1.5 Jobs API — `includes/class-jobs-api.php`

M2 finding #1 (Critical) was: any logged-in user could `POST /jobs` and pick a processor that ran tools outside `Tool_Executor`. **HTTP access is now `manage_options`.**

| Method | Route | Gate |
| --- | --- | --- |
| POST | `/jobs` | `check_permission()` = `manage_options` |
| GET | `/jobs/{id}` | `manage_options` |
| DELETE | `/jobs/{id}` | `manage_options` |
| GET | `/jobs/user/{user_id}` | `manage_options` |

Additional server-side constraints (still true on this tip):

- `create_job()` overwrites `user_id` with `get_current_user_id()`; a caller cannot attribute a job to someone else (`includes/class-jobs-api.php`).
- `Job_Manager::process_job()` instantiates `_processor` only if the class exists **and** implements `Agentic\Job_Processor_Interface` (`includes/class-job-manager.php`). A random core class cannot be used as a processor.
- `Job_Manager::create_job()` no-ops while Emergency Stop is active.

What is **not** fixed: the only `Job_Processor_Interface` implementation, `Agent_Builder_Job_Processor`, still calls `Tool_Loader::execute()` rather than `Tool_Executor::execute()`, so a `manage_options` caller of `POST /jobs` can still run Assistant Trainer tools (including `create_agent_files`) without the approval queue. See §8.

### 1.6 Native forms — `includes/class-native-forms.php`

| Method | Route | Gate |
| --- | --- | --- |
| POST | `/native-forms` | `current_user_can( 'manage_options' )` |
| GET | `/native-forms/{id}` | `current_user_can( 'manage_options' )` |
| POST | `/native-forms/{id}/submit` | `__return_true` (intentionally public) |

The submit handler is the one public mutating route. On this tip it **does** verify the `wp_rest` nonce from `X-WP-Nonce` (`rest_submit_form()`). The M2 audit recorded that nonce as rendered-but-not-verified; that is no longer accurate. Honeypot and Cloudflare Turnstile remain **per-form opt-in**, not mandatory. There is no dedicated rate limiter on this route. Form definitions and entries are non-public CPTs (`agentic_form`, `agentic_form_entry`) with `show_in_rest => false` and `create_posts => manage_options`.

### 1.7 MCP relay — `includes/class-relay-connect.php`

| Method | Namespace + route | Gate |
| --- | --- | --- |
| GET | `agentic/relay/ping` | `__return_true` |
| POST, GET, DELETE | `agentic/{slug}/mcp` | `check_mcp_permission()` = `is_user_logged_in()` **and** `current_user_can( 'edit_posts' )` |

Ping is a detection probe. The body is `{ "relay_ready": true }` only — no version, no agent list (`handle_ping()`). M2 finding #4 still applies as a fingerprinting disclosure; it is accepted as product behaviour for the relay connect flow.

The per-agent MCP JSON-RPC route is **not** a new listen port. It is a REST route on the site's existing REST API, authenticated as a WordPress user (typically via Application Password minted at Settings → MCP). M2 finding #5 (any logged-in user, including a subscriber, could call `initialize` / `tools/list`) is **addressed**: the floor is now `edit_posts`. Per-tool execution still goes through `is_tool_mcp_safe()` and `required_capability_for_tool()`:

- Always blocked by name: `run_wp_cli`, `install_plugin_from_url`, `create_agent_files`, `add_custom_js`, `validate_agent_code`, plus any `git_*` or `cloudflare_*` prefix.
- Blocked if effective risk is HIGH or EXTREME (`Abilities_Manifest::get_effective_risk()`).
- Read-only tools require `edit_posts`; everything else requires `manage_options`.

`run_wp_cli` / `manage_cli_settings` **do not ship in this build**. `Activator` unregisters those rows on upgrade (`includes/class-activator.php`). They remain in the risk floor map and MCP blocklist as inert names so a leftover DB row cannot become MCP-callable.

### 1.8 WebMCP Bridge — `includes/class-webmcp-bridge.php`

Covered in detail in §5. Routes:

| Method | Route | Gate |
| --- | --- | --- |
| POST | `/webmcp/execute` | `permission_execute()` |
| POST | `/webmcp/confirm` | `permission_confirm()` |

Master switch `agentic_webmcp_enabled` defaults to empty/off (`Activator` `add_option( ..., '' )`). Same-origin check is an extra CSRF layer, not the only one.

### 1.9 Non-REST mutating admin entry points (for completeness)

Not REST, but they mutate state and a reviewer will see them:

| Hook | File | Gate |
| --- | --- | --- |
| `admin_post_agentic_export_logs` | `class-admin-pages-rest.php` | nonce `agentic_export_logs` + `agentic_view_audit_log` or `manage_options` |
| `admin_post_agentic_export_skill` | same | nonce `agentic_export_skill` + `agentic_manage_tools` |
| `admin_post_agentic_import_skill` | same | nonce `agentic_import_skill` + `agentic_manage_tools` |
| `admin_post_agentic_set_emergency_stop` | `class-admin-menu-handler.php` | nonce `agentic_set_emergency_stop` + `agentic_manage_settings` or `manage_options` |
| `admin_post_agentic_set_agent_updates` | same | nonce + cap; **no-op on this WP.org build** (`Agent_Updates::is_remote_check_available()` is false here) |
| `admin_post_agentic_save_quick_actions` | same | nonce + cap |

Mutating `wp_ajax_*` handlers wired by `class-ajax-dispatcher.php` / `class-admin-ajax.php` / `class-okf-admin.php` were reviewed in the M2 audit as nonce + capability gated. This document does not re-list every AJAX action.

---

## 2. Third-party services

The plugin does not embed an LLM. Outbound calls happen only after an administrator configures a provider or clicks an explicit action. The canonical per-endpoint disclosure is `readme.txt` `== External Services ==`. This section is the code-backed counterpart.

### 2.1 LLM providers (bring-your-own-key)

Keys live in `wp_{prefix}agentic_providers.api_key` (and the legacy option `agentic_llm_api_keys`). They are entered by the site owner in Settings / the setup wizard. **No provider key is bundled in this zip.** A search of the tree for live `sk-` / `AIza…` / `sk-ant-` / `xai-` material finds placeholders only (`admin/setup.php`, `admin/apis.php`).

Configured providers call their official APIs with chat prompts, system instructions, tool definitions, and tool results. Endpoints (from `readme.txt`, matching `Provider_Registry`):

- OpenAI `https://api.openai.com/v1/chat/completions`
- Anthropic `https://api.anthropic.com/v1/messages`
- xAI `https://api.x.ai/v1/chat/completions`
- Google Gemini `https://generativelanguage.googleapis.com/v1beta/models/`
- Mistral `https://api.mistral.ai/v1/chat/completions`
- Meta Llama `https://api.llama.com/v1/chat/completions`
- Cohere `https://api.cohere.com/v2/chat`
- Kimi / Moonshot `https://api.moonshot.ai/v1/chat/completions`
- DeepSeek `https://api.deepseek.com/chat/completions`
- OpenRouter `https://openrouter.ai/api/v1/chat/completions`
- Ollama: user-configured URL, default `http://localhost:11434` — data stays on the host

Optional Agentic-managed endpoints (`readme.txt` “Agentic AI Services”), used only when the site uses Agentic credits / the Connect-to-Agentic flow: `chat.agentic-plugin.com:11435`, `rag.agentic-plugin.com`, `imagegen.agentic-plugin.com`, `tts.agentic-plugin.com`, `videogen.agentic-plugin.com`.

### 2.2 Google PageSpeed Insights — no bundled key, no rotation

`library/tools/check_core_web_vitals/tool.php` reads `get_option( 'agentic_psi_api_key', '' )`. If empty, it returns setup instructions and **does not call Google**. If set, it GETs `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` with the URL under test, strategy, and that site-owned key.

Changelog 3.3.85 records that a **shared/bundled** PageSpeed key was removed so this WP.org build matches the BYOK model of every other provider. Current code contains **no** fallback key and **no** per-release-rotation mechanism. If a fleet audit still describes rotation, that describes an older design that is not in this tree.

### 2.3 WhatsApp — not an integration in this plugin

There is **no** WhatsApp Business / Graph API client in this codebase. What exists:

- Option `agentic_show_whatsapp_cta`, default `'0'` (`includes/class-activator.php`). When an admin turns it on, `templates/chat-interface.php` renders a “Continue on WhatsApp” link to `https://agentic-plugin.com/whatsapp/…` with a Pro badge.
- Agents list column “WhatsApp” reads per-agent setting `whatsapp_enabled` (`admin/agents.php`) — a display flag, not a network call.

### 2.4 Agentic account / platform (all optional, mostly admin-initiated)

From `readme.txt` and the cited classes:

| Endpoint | Trigger | Payload (as disclosed) |
| --- | --- | --- |
| `https://agentic-plugin.com/wp-json/agentic/v1/model-pricing` | Settings → Security checkbox `agentic_allow_platform_sync` (default **off**), or a manual “Get Latest Pricing” / “Refresh Models” click | GET; no personal data |
| `https://agentic-plugin.com/wp-json/agentic/v1/register` | Admin submits the sign-up form (`includes/class-admin-ajax.php`) | Admin email, site URL, site name, plugin version, plan tier |
| `https://agentic-plugin.com/wp-json/agentic-license/v1/cancellation-feedback` | Deactivation survey, only if previously consented | License key (if any), site URL, reason, optional comment, version |
| `https://agentic-plugin.com/wp-json/agentic/v1/agents/activate-token` | Installing an uploaded community/purchased agent that includes a license file | Token, slug, site URL |
| `https://agentic-plugin.com/wp-json/agentic/v1/report-issue` | In-chat “report an issue” tool, preview + second confirmation | Site URL, license key if any, log excerpts, provider, versions, admin description |
| `https://agentic-plugin.com/wp-json/agentic/v1/deregister` | Uninstall, only if `agentic_allow_deregister_on_uninstall` is `'1'` **and** an API key is stored (`uninstall.php`) | API key, site URL. Non-blocking POST. |
| Agent marketplace update-check URLs | **Pro-only**; this WP.org build never contacts them (`readme.txt`; `admin_post_agentic_set_agent_updates` is a no-op here) | — |
| `https://mcp.agentic-plugin.com/api/verify-state` and `…/oauth2/relay-callback` | Only the browser “Connect an MCP client” approval screen (`includes/class-relay-connect.php`) | Site URL, approving admin username/email, a newly generated Application Password, active agent slugs, provider name. Direct Application Password MCP never hits this. |

### 2.5 Other outbound HTTP

| Service | When | Notes |
| --- | --- | --- |
| Cloudflare Turnstile `https://challenges.cloudflare.com/turnstile/v0/api.js` and `…/siteverify` | Only if the admin pastes Turnstile site/secret keys (`includes/class-turnstile.php`) | Script cannot be bundled; verification is the point. Keys are site-owned. |
| GitHub `api.github.com` / `raw.githubusercontent.com` (WordPress/agent-skills, anthropics/skills) | Skills screen browse/import, user-initiated | Unauthenticated GET of public files |
| `https://agentic-plugin.com/wp-json/agentic/v1/skills` | Same | Recommended-skills catalog |
| ClawHub `https://wry-manatee-359.convex.site/api/v1/` | Same, search | Search queries |
| `https://api.wordpress.org/` | Site-health tools (core checksums, plugin abandonment/changelog) | Same official API core uses |
| User-configured form webhook URL | Only if the admin enabled a webhook on a native form | Payload is that form’s fields, to that URL only |
| Site Passport `https://sitepassport.org/api/submit.php` | Only “Submit to Directory” on Agent-Ready, `manage_options` | Current code posts `{ "url": home_url("/") }` (`includes/class-directory-submission.php` `SUBMIT_URL`). `readme.txt` now matches this path. `reports/m4-guidelines-checklist.md` still records an older mismatch (`/api/v1/submissions` vs `submit.php`); treat m4 as stale on this point. Computing the Agent-Ready score itself makes **zero** outbound requests (`readme.txt` “Site Passport (Local Only)”). |

The M4 checklist’s “FAIL” on External Services completeness was that URL mismatch. It is corrected in current `readme.txt`.

---

## 3. Tool execution and the agent-safety model

This plugin’s tools are PHP classes that already ship in `library/tools/*/tool.php`. They are loaded from the local filesystem (`Tool_Loader::load()` iterates `agentic_tool_dirs`, default `library/tools/`, `include_once` each `tool.php`, keeps instances of `Tool_Base`). There is no remote `include`, no `eval` of provider output, and no download-and-execute of PHP. `eval(` / `shell_exec(` appear in `Chat_Security` as **blocked prompt phrases**, and in a scanner tool as things to flag in *other* code — not as an execution path.

### 3.1 Risk tiers

`Agentic\Risk_Level` (`includes/class-risk-level.php`):

| Constant | Meaning | Default enforcement |
| --- | --- | --- |
| `none` | Read, non-personal | `allow` |
| `low` | Read that may include personal data | `allow` in autonomous; otherwise confirm/queue depending on ceiling |
| `medium` | Writes | In-chat **proposal** (`confirm`) unless auto-approve ceiling covers it |
| `high` | Significant / bulk writes | **Approval queue** (`queue`) at `admin.php?page=agentic-approvals` |
| `extreme` | Destructive / arbitrary execution | **`block`** — hidden from the LLM, no approval path |

`enforcement($risk, $mode)`:

- EXTREME always `block`.
- Mode `disabled` always `block`.
- Mode ceiling: `autonomous` → LOW, `supervised` (default option `agentic_agent_mode`) → NONE.
- Site preference `agentic_approval_auto_max_risk` can raise the auto-allow ceiling but **cannot** include EXTREME.
- Above the ceiling: HIGH → `queue`, otherwise `confirm`.

Risk can only be **escalated**, never lowered: `max(tool default, abilities.json, admin override)`. `BASELINE_RISKS` is a floor for tools that must not silently sit at `none` (install-from-URL, password reset, refunds, `create_agent_files`, etc.). An admin can raise those floors, not drop them.

### 3.2 The gated execution path (chat, cron, WebMCP confirm)

`Agent_Controller::execute_tool()` delegates to `Tool_Executor::execute()` (`includes/class-agent-controller.php`, `includes/class-tool-executor.php`):

1. Disabled tools (`Tools_Registry::is_enabled`) are refused and audit-logged.
2. Effective risk is computed; EXTREME returns an error without running the tool.
3. HIGH is queued (`Approval_Queue::add`) unless an admin has an “always allow” grant for that tool (`user_meta agentic_tool_grants_always`) or a matching approved queue row exists.
4. MEDIUM becomes an `Agent_Proposals` card for the same user.
5. Only `allow` (or a consumed approval) reaches `Tool_Loader::execute()`, which runs the already-loaded `Tool_Base` instance.

The LLM only sees tools that:

- exist on disk and `is_available()` (e.g. PDF tools report unavailable when mPDF was stripped from the WP.org zip — `bin/export-wporg-tree.sh`),
- are declared in that agent’s signed `abilities.json`,
- pass integrity (`Abilities_Manifest::verify_integrity()` — failure hides **all** tools for that agent),
- are not EXTREME.

`create_agent_files` writes **declarative JSON/text** (`agent.json`, `abilities.json`, `templates/system-prompt.txt`, optional README) under `wp-content/agentic-agents/{slug}/`. It deletes any legacy `agent.php`. The comment in the tool is explicit: the agent is “plain-data `agent.json` (never executable PHP)” interpreted by the shipped `Manifest_Agent` class. Bundled slugs cannot be overwritten.

### 3.3 Safety Center

Admin screen `agentic-safety-center`, capability `agentic_manage_settings` (`includes/class-admin-menu-handler.php`). It is a **read-mostly dashboard of existing controls**, not a second enforcement engine (`reports/m2-safety-center-design.md`; `reports/docs-drafts/safety-center.md`; payload `Admin_Pages_REST::safety_center_payload()`). It shows:

- enabled/disabled tool counts and highest enabled risk,
- pending approval count, mode, comfort profile,
- audit-log hash-chain status (`Audit_Log_Integrity::verify_chain()`),
- Emergency Stop control,
- per-agent tool scopes (from `Inventory_REST`).

High-risk enable confirmation on the Tools screen is a later M2 phase; the design doc originally recorded that the classic Tools toggle posted immediately. Current React Tools UI includes a high-risk enable modal (`src/admin-pages` / built `build/admin-pages.js`).

### 3.4 Kill switch

`Agentic\Emergency_Stop` (`includes/class-emergency-stop.php`), option `agentic_disable_all_agents`. `enable()`:

- snapshots active agents and encrypted provider keys,
- deactivates every active agent,
- `Job_Manager::emergency_cancel_all()`,
- disconnects providers and sets `agentic_llm_provider` to `none`,
- writes `Security_Log` events.

While active:

- `Agent_Controller::chat()` returns the blocked message,
- `LLM_Client::is_configured()` is false,
- `Job_Manager::create_job()` returns `''`,
- `Agent_Registry::activate_agent()` returns `WP_Error( 'emergency_stop' )`.

Classic form path: `admin_post_agentic_set_emergency_stop` (nonce + cap). React path: Dashboard POST / Settings / Safety Center, same class.

### 3.5 Audit logging

- `wp_{prefix}agentic_audit_log` — actions, actor, details, tokens, cost. Hash-chained by `includes/class-audit-log-integrity.php`. Integrity is shown on Activity and Safety Center and at `GET /inventory/integrity`.
- `wp_{prefix}agentic_security_log` — system/security events (Emergency Stop, tool exceptions, log export).
- `wp_{prefix}agentic_approval_queue` — HIGH-risk items waiting on a human.
- GDPR exporters/erasers register chat, security log, audit log, and jobs (`includes/class-gdpr.php`). IP anonymization option `agentic_ip_anonymize` defaults on.

This is tamper-**evidence**, not tamper-**proofing**. Direct database access can still edit rows; the chain is designed so that edit/delete becomes detectable.

### 3.6 Why this is not arbitrary remote code execution

A reviewer looking at “AI agents that take actions on the site” should expect this question. The concrete bounds:

1. **No remote code load.** Tools are local `Tool_Base` subclasses. `Job_Manager` will not instantiate a class that does not implement `Job_Processor_Interface`.
2. **No `eval` of model output as PHP.** Model output selects a **named** tool and JSON arguments; the named class’s `execute()` runs.
3. **New agents are data, not PHP.** `create_agent_files` writes JSON/text; HIGH; MCP/WebMCP blocked by name; chat path queues for admin approval unless an admin always-grant exists.
4. **EXTREME tools are not callable.** `run_wp_cli` is classified EXTREME and is **not present** in this zip; leftover DB rows are unregistered on upgrade.
5. **MCP/WebMCP additionally strip HIGH/EXTREME** even if a manifest tried to expose them.
6. **WebMCP does not honor** the chat auto-approve preference (`agentic_approval_auto_max_risk`); MEDIUM always pauses for that visitor.

The remaining hole that is still RCE-adjacent is §8: `Agent_Builder_Job_Processor` → `Tool_Loader::execute()` under `manage_options`. That is an administrator HTTP path, not an unauthenticated one.

---

## 4. Data storage and privacy

### 4.1 What is stored

Custom tables created in `includes/class-activator.php` (all `{$wpdb->prefix}agentic_*`):

| Table | Contents |
| --- | --- |
| `agentic_audit_log` | Agent actions, optional PII in details/reasoning |
| `agentic_approval_queue` | Pending HIGH-risk tool calls and params |
| `agentic_memory` | Agent memory entries |
| `agentic_tools` | Tool registry (enabled, risk_level, category) |
| `agentic_conversations` | Chat turns: `session_id`, `user_id`, `agent_id`, `role`, `content`, `tools_used`, `feedback` |
| `agentic_agent_settings` | Per-agent key/value (includes flags such as `whatsapp_enabled`) |
| `agentic_providers` | Provider endpoints **and API keys** (`api_key` column) |
| `agentic_skills` | Bundled + user-created/imported skills |
| `agentic_runs` | Agent run records |
| `agentic_agent_library` | Bundled + user-created/purchased agents (`source` = `user` / `purchased` / bundled) |
| `agentic_jobs` | Background jobs |
| `agentic_security_log` | Security/system events; IPs hashed when `agentic_ip_anonymize` is on |
| `agentic_deployments` | Deployment/shortcode/launcher config |

Also:

- Options `agentic_*` (modes, retention, Turnstile keys, PageSpeed key, UI prefs, Emergency Stop snapshot, …).
- Usermeta `agentic_*` (always-allow grants, dismissed notices, per-user UI mode, …).
- Transients `_transient_agentic_*`.
- Non-public CPTs `agentic_form` and `agentic_form_entry` (form definitions and submissions — the latter is visitor PII if the form collected it).
- Files under `wp-content/agentic-agents/`, `wp-content/agentic-knowledge/`, `wp-content/agentic-backups/` (constants in `agent-builder.php`).
- Frontend cookies `agentic_consent_given` and `agentic_last_agent` (documented in `GDPR::add_privacy_policy_content()`).

Chat messages for a logged-in user are tied to `user_id`. Anonymous chat (off by default) stores `user_id = 0` and a UUID `session_id`.

WordPress Privacy Tools: exporters and erasers for chat, security log, audit log, jobs (`includes/class-gdpr.php`). Suggested privacy-policy text is registered with `wp_add_privacy_policy_content()`. Retention cron `agentic_gdpr_cleanup` honors `agentic_retention_conversations` and `agentic_retention_audit_log` (0 = keep).

### 4.2 Uninstall

`uninstall.php`:

1. Optional deregister POST to Agentic (opt-in option + stored key only).
2. If `agentic_deactivate_delete_data !== '1'`, **return**. Default is keep data.
3. If the admin did choose delete-data:
   - `DROP TABLE` every table in the current schema matching `{prefix}agentic_%` (information_schema lookup — no preserve list).
   - `DELETE` options `agentic_%`, transients, usermeta `agentic_%`.
   - Clear cron hooks whose names start with `agentic_`.

Commit `38aa28e` (`#97`) is the fix that **includes** `agentic_agent_library` and `agentic_skills` in that drop. Bundled rows are re-seeded on next activation (`Activator::seed_skills()`, `seed_bundled_agents()`). `reports/m4-guidelines-checklist.md` §8 documents that preserving those two tables was incorrect.

`uninstall.php` does **not** currently delete:

- `wp-content/agentic-agents/`, `agentic-knowledge/`, `agentic-backups/`,
- CPT posts `agentic_form` / `agentic_form_entry`.

The file header still mentions “the chat page”; there is no chat-page deletion in the body.

**Stale FAQ:** `readme.txt` still says that on delete, “Custom agents you created and skills you imported are kept.” That is the **pre-`38aa28e`** behaviour. Current `uninstall.php` drops those tables when delete-data is on. The FAQ should be corrected in a follow-up; do not treat it as accurate.

---

## 5. WebMCP

WebMCP (Web-based Model Context Protocol) is **not** the MCP relay in §1.7.

| | MCP relay (`class-relay-connect.php`) | WebMCP Bridge (`class-webmcp-bridge.php`) |
| --- | --- | --- |
| Who is calling | A remote client (Claude Desktop, Cursor, …) holding an Application Password, acting continuously as that WP user | The **current browser session** — often an anonymous visitor’s own in-page agent |
| Auth | Logged-in + `edit_posts`, then per-tool caps | Master switch + per-tool `webmcp_expose` + same-origin + allowlist/caps |
| Default | Credential must be minted by `manage_options` | `agentic_webmcp_enabled` off |

Frontend script: `assets/js/webmcp-bridge.js` (registers tools via `document.modelContext.registerTool()`, with a deprecated `navigator.modelContext` fallback). Discovery: `/.well-known/webmcp.json` served only when the master switch is on (`serve_well_known_manifest()`).

`permission_execute()` fail-closed, first failure wins:

1. Master switch off → 403.
2. Unknown or disabled tool → 404.
3. Not MCP/WebMCP-safe (HIGH/EXTREME / always-blocked names) → 403.
4. Agent has not set `webmcp_expose` for this tool → 403.
5. `webmcp_context` (frontend / admin / both) must match the Referer.
6. Origin/Referer host must match `home_url()` host. Missing Origin/Referer is treated as same-origin so non-browser verification clients are not blocked; this is documented in-code as CSRF-hardening, **not** the sole boundary.
7. Tools in `ANONYMOUS_SAFE_TOOLS` may run for anyone (logged in or not). Current list: `search_content`, `wc_browse_products`, `wc_view_cart`, `wc_add_to_cart`, `wc_update_cart_item`. Cart writes are session-scoped to that visitor’s WooCommerce cart; they do not place orders or take payment.
8. Any other tool: anonymous → 401; logged-in must pass `required_capability_for_tool()`.

**“Readonly” is not the anonymous-safety boundary.** The class docblock states why: several NONE/LOW readonly tools return admin emails, failed-login counts, or draft/private posts. The hand-reviewed allowlist is the backstop even if an admin sets `webmcp_expose` on those tools.

Execution:

- NONE/LOW run through `Tool_Executor` with invocation context `webmcp`.
- MEDIUM returns `confirmation_required` + a proposal; `POST /webmcp/confirm` re-checks exposure, context, same-origin, login, and capability from the stored proposal (not the first request).
- HIGH/EXTREME never reach execute (filtered in permission).
- Chat auto-approve is **not** applied on this surface.

Contact Form 7 / WPForms helpers in `assets/js/webmcp-bridge.js` fill the visitor’s own DOM form (`form.wpcf7-form` / `form.wpforms-form`); they do not POST to `/webmcp/execute`.

This is still an HTTP POST that can run a small, named set of already-shipped PHP tool classes. It is not a generic eval endpoint, not a new listen port, and not on by default.

---

## 6. i18n

This WordPress.org **zip** is English-only by construction.

`.distignore`:

```
# WP.org edition is English-only. Keep the POT (translate.wordpress.org
# source template) and language-neutral files (e.g. index.php); drop
# bundled locales. Source .po/.mo/.json stay in git for Pro.
languages/*.po
languages/*.mo
languages/*.json
```

What remains in `languages/` after export: `agent-builder.pot` (header: `GPL-2.0-or-later`, `X-Domain: agent-builder`) and `languages/index.php`.

Git still contains 11 bundled locales (de_DE, es_ES, fr_FR, it_IT, ja, ko_KR, nl_NL, pl_PL, pt_BR, ru_RU, zh_CN) plus their `*-agentic-*.json` counterparts. They are for the Pro/self-hosted tree, not this directory zip.

Plugin header: `Text Domain: agent-builder`, `Domain Path: /languages`. There is **no** `load_plugin_textdomain()` call in this tree. For a plugin hosted on WordPress.org that is the post-4.6 default: core loads translations from `wp-content/languages/plugins/` generated by translate.wordpress.org. The first listing ships English; translators work from the POT.

---

## 7. Licensing

| Location | Declaration |
| --- | --- |
| `agent-builder.php` plugin header | `License: GPL-2.0-or-later`, `License URI: https://www.gnu.org/licenses/gpl-2.0.html` |
| `readme.txt` | same |
| `LICENSE` | GPL-2.0-or-later short notice plus the full GPLv2 text |
| `languages/agent-builder.pot` | `This file is distributed under the GPL-2.0-or-later.` |
| Many `includes/*.php` file docblocks | `@license GPL-2.0-or-later` — **not every PHP file repeats this**. The plugin-level header and `LICENSE` are the SPDX source. |

Runtime Composer packages (`composer.json` / m4 lock review): PhpSpreadsheet (MIT), PhpWord (LGPL-3.0-only), zipstream-php (MIT). `mpdf/mpdf` (GPL-2.0-only) and `smalot/pdfparser` (LGPL-3.0) are in the **dev checkout** `composer.json` but **stripped before the WP.org zip** (`bin/export-wporg-tree.sh`) because mPDF’s font tree dominates package size. The four PDF tools then `is_available() === false`.

Obfuscation:

- PHP under `includes/`, `admin/`, `library/` is unminified source. No ionCube, no `goto`-packed blobs, no `eval` of remote PHP (m4 §2; rechecked: the only `eval(` hits are security scanners and prompt filters).
- React admin source is in `src/`; webpack/`@wordpress/scripts` output is in `build/`, committed next to source (`package.json` `wp-scripts build`). That is the standard WP.org pattern for a Gutenberg-style admin app: reviewers can read `src/`, runtime loads `build/`.

Prefixing (m4 §6): namespace `Agentic\`, options/tables `agentic_*`, constants `AGENT_BUILDER_*` / `AGENTIC_*`.

---

## 8. Known limitations and intentionally deferred items

These are listed because a reviewer will find them. They are not claimed as fixed.

### 8.1 Anonymous chat session ownership — still open

**Default: off** (`agentic_allow_anonymous_chat` defaults false / `'0'`).

When an administrator enables it, `check_logged_in()` returns true for every guest. Conversations are stored with `user_id = 0`.

Fixed from M2 finding #2: `GET /sessions` now uses `check_real_user()`, so a guest cannot list every anonymous session. `session_id` is UUIDv4, not a sequential integer.

**Not fixed:** `GET /history/{session_id}` and `POST /feedback` still use `check_logged_in()`. For a guest, the handler’s `user_id = 0` filter matches **any** anonymous row with that UUID. Knowing (or being given) another visitor’s `session_id` is enough to read that transcript or write feedback on it. `POST /proposals/{id}` ownership uses `created_by === get_current_user_id()`; for two guests that is `0 === 0`, so the same class of issue exists for anonymous proposals.

This is the gap the M2 audit referred to via `STATE.md` (file not in this branch). Closing it needs a per-browser unguessable token (signed cookie or similar) bound on write and checked on read — not done on this tip.

### 8.2 Jobs processor still bypasses `Tool_Executor`

HTTP `POST /jobs` is `manage_options` (M2 Critical, HTTP half, **fixed**). `Agent_Builder_Job_Processor` still runs tool calls with `Tool_Loader::execute()` (`includes/class-agent-job-processor.php`), which does not queue HIGH-risk tools. An administrator using that REST surface can therefore create agent files without the Approvals UI. Chat/WebMCP/MCP do not use this processor.

### 8.3 Public native-form submit — nonce yes, mandatory anti-abuse no

`POST /native-forms/{id}/submit` is public by design (front-end forms). The `wp_rest` nonce is now verified. Honeypot and Turnstile are optional per form. There is no plugin-level rate limit. M2 Medium finding #3 is therefore only partly addressed.

### 8.4 Public relay ping

`GET /wp-json/agentic/relay/ping` remains `__return_true` and returns `relay_ready: true`. Deliberate for the MCP connector relay. Fingerprints that Agent Builder is installed.

### 8.5 Uninstall does not remove files or form CPTs

Delete-data drops `agentic_*` tables/options/usermeta/cron, including user-created agents and skills. It does not remove `wp-content/agentic-{agents,knowledge,backups}/` or `agentic_form` / `agentic_form_entry` posts. `readme.txt` FAQ still describes the old “keep custom agents/skills” behaviour and should be updated.

### 8.6 Inert Pro / companion-product names

Risk floors, MCP blocklists, and some UI copy still mention `run_wp_cli`, `manage_cli_settings`, `git_*`, `cloudflare_*`. Those tools are **not** in `library/tools/` on this branch; `Activator` unregisters the CLI pair. They are placeholders so a leftover row cannot become callable, not hidden features.

### 8.7 Packaging notes that affect what the reviewer actually unzips

- `bin/export-wporg-tree.sh` rebuilds production `vendor/` without mPDF/pdfparser, then writes a sibling `*.zip` (gitignored build artifact).
- `.distignore` drops `languages/*.po|mo|json`, `tests/`, `screenshots/`, `.wordpress-org/`, `node_modules/`, the checkout `vendor/`, `reports/`, `.fleet-task`, `.env.local`, and a **root** `SUBMISSION-NOTES.md`.
- `reports/` is distignored so this file and the other internal reports do not ship in the plugin zip (they stay in git on `release/3.4-wporg`).
- m4 noted `.wordpress-org/` as missing; it now exists (commits `#100` / `#102`) and is distignored because WP.org reads those assets from SVN `assets/`, not from the plugin zip.

### 8.8 Guideline-check items already called in older notes (status)

| Item | Current status |
| --- | --- |
| Site Passport URL mismatch (m4 FAIL) | **Fixed** in `readme.txt` (`api/submit.php` matches `Directory_Submission::SUBMIT_URL`) |
| Bundled PageSpeed key | **Removed** in 3.3.85; BYOK only |
| Catalog sync phone-home | Opt-in, default off |
| WhatsApp / branding promo | Default off |
| Plugin Check “offloading” on Turnstile script URL and GitHub raw URLs | Service exception (Turnstile cannot be self-hosted; skill browse is user-initiated). Cloudflare dashboard `<a href>` in Settings is a false positive. |
| `wp_get_abilities()` vs `Requires at least: 6.4` | Guarded with `function_exists()` in `class-wp-optional-api.php` / Agent-Ready score |
| `robots_txt` prefix sniff | Core filter name, hooked not introduced |

---

## Quick verification map

If you only spot-check five things:

1. `includes/class-jobs-api.php` `check_permission()` → `manage_options`.
2. `includes/class-webmcp-bridge.php` `ANONYMOUS_SAFE_TOOLS` + `permission_execute()`.
3. `uninstall.php` information_schema `DROP` of `{prefix}agentic_%` when `agentic_deactivate_delete_data === '1'`.
4. `.distignore` English-only `languages/*.{po,mo,json}` keep POT.
5. `library/tools/check_core_web_vitals/tool.php` — empty `agentic_psi_api_key` makes **no** Google request.

Then read `readme.txt` `== External Services ==` against the actual URLs in `class-directory-submission.php`, `class-turnstile.php`, and `class-relay-connect.php`.
