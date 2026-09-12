# M2 security audit: REST capabilities, nonces, escaping

Audited all `register_rest_route()` calls under `includes/`, plus mutating `admin_post_*` and `wp_ajax_*` handlers under `includes/`.

## Status (supervisor, 2026-09-10, same day as the audit)

Both the Critical and High findings were confirmed live-exploitable on **lffci.org** (this plugin's live established test deployment) and fixed the same day, deployed there immediately, and verified live:

- **Finding #1 (Critical, jobs API)** — **fixed** (`c3cf860`). `check_permission()` now requires `manage_options`; `create_job()` ignores caller-supplied `user_id`; `Job_Manager::process_job()` now requires the processor class to implement `Job_Processor_Interface` before instantiating it. Verified live: a real subscriber-role account on lffci.org now gets `check_permission() === false` (previously `true`); a non-`Job_Processor_Interface` class (`WP_Query`) now fails with "Invalid or missing job processor" instead of being instantiated.
- **Finding #2 (High, anonymous session enumeration)** — **partially fixed** (`aa6e77d`). `GET /sessions` now requires a real logged-in user (`check_real_user()`), closing the practical enumeration path — no client code anywhere in this repo (`src/`, `templates/`, `build/`) calls this endpoint, so this has no UX impact. `session_id` is a real UUIDv4 (`wp_generate_uuid4()`), not enumerable, so with `/sessions` closed the realistic attack path is gone. **Not yet fixed**: `/history/{session_id}` and the feedback endpoint still accept any `session_id` from an anonymous caller with no per-visitor ownership binding — this needs a real per-visitor token design (e.g. a signed cookie), which is legitimate follow-up work, not a same-day hotfix. Tracked as a separate fleet task.
- **Finding #3 (Medium, native forms)**, **#4 and #5 (Low)** — not yet addressed; not confirmed live-exploited, dispatched as regular (non-emergency) fleet follow-up work.

See `STATE.md` and `fleet:alert` issue on the private `fleet` repo for the incident record.

## Executive summary

- Audited 47 REST route registrations across 13 files.
- Reviewed mutating `admin_post_*` handlers in `includes/class-admin-pages-rest.php` and mutating `wp_ajax_*` handlers wired by `includes/class-ajax-dispatcher.php`, `includes/class-admin-surfaces.php`, and `includes/class-okf-admin.php`.
- Found **5 issues**:
  - 1 Critical
  - 1 High
  - 1 Medium
  - 2 Low
- Did **not** find any request-derived SQL interpolated into a query without `$wpdb->prepare()` in the audited REST/admin-post/admin-ajax handlers.

## Findings

### Critical

1. **Any authenticated user can create arbitrary background jobs and bypass the tool approval/risk gate**
   - **Files:** `includes/class-jobs-api.php:42-49, 85-97`; `includes/class-job-manager.php:129-176, 257-330`; `includes/class-agent-job-processor.php:87-132`; `library/agents/assistant-trainer/abilities.json:47-50`
   - **Risk:** Any logged-in user can `POST /wp-json/agentic/v1/jobs` because `permission_callback` only checks `is_user_logged_in()`, and the handler forwards raw request parameters into `Job_Manager::create_job()`, letting the caller choose `processor=Agentic\\Agent_Builder_Job_Processor`; that processor executes assistant-trainer tool calls through `Tool_Loader::execute()` instead of `Tool_Executor`, so high-risk tools such as `create_agent_files` can run without the normal approval queue.
   - **Suggested fix:** Restrict job creation to an explicit capability (`manage_options` unless there is a narrower real need), ignore caller-supplied `processor`/`user_id`, hardcode an allowlist of server-approved processors, and route any tool execution through `Tool_Executor` so risk floors and approvals still apply.

### High

2. **Anonymous chat mode lets one visitor enumerate and read other anonymous visitors' chat history**
   - **Files:** `includes/class-rest-api.php:149-179, 397-420, 690-783, 792-943, 1516-1522, 2453-2494`
   - **Risk:** When `agentic_allow_anonymous_chat` is enabled, `check_logged_in()` returns true for all guests; `/sessions` then queries by `user_id = 0`, so any anonymous visitor can list every anonymous session, fetch another visitor's `/history/{session_id}`, and even change feedback on another anonymous session because all guests are treated as the same owner.
   - **Suggested fix:** Do not reuse the shared anonymous `user_id = 0` bucket for session ownership checks; require authentication for `/sessions`, `/history`, and `/feedback`, or bind guest sessions to an unguessable per-browser token/cookie and enforce that token on reads and writes.

### Medium

3. **Public native form submission route mutates state with no capability check and no mandatory abuse control**
   - **Files:** `includes/class-native-forms.php:218-229, 304-424, 817-828`
   - **Risk:** Any internet user or bot can `POST /wp-json/agentic/v1/native-forms/{id}/submit` because the route uses `permission_callback => '__return_true'`; successful requests create entry posts and can trigger notification emails and webhooks, while Turnstile and honeypot checks are optional and the REST nonce emitted into the rendered form is not verified server-side.
   - **Suggested fix:** If public submissions are required, keep the route public but add mandatory anti-abuse controls such as a server-verified signed form token, rate limiting, and/or mandatory Turnstile; if public submissions are not required, replace `__return_true` with a real capability/ownership check.

### Low

4. **Public relay ping endpoint exposes plugin presence**
   - **Files:** `includes/class-relay-connect.php:69-77, 86-93`
   - **Risk:** Anyone on the internet can call `/wp-json/agentic/relay/ping` and learn that Agent Builder is installed and relay-ready, which is useful for fingerprinting even though the endpoint appears intentionally public.
   - **Suggested fix:** Confirm that this disclosure is acceptable for the WordPress.org threat model; otherwise require a shared secret/token or make the response less product-specific.

5. **MCP route only checks “is logged in”, so low-privilege users can enumerate exposed MCP tools**
   - **Files:** `includes/class-relay-connect.php:102-132, 229-320, 620-729`
   - **Risk:** Any authenticated user who knows an enabled agent slug can reach `/wp-json/agentic/{slug}/mcp`, call `initialize`/`tools/list`, and learn that agent's MCP-safe tool catalog and schemas even if they do not have the capability required to execute those tools.
   - **Suggested fix:** Move capability checks into `permission_callback` based on the JSON-RPC method, or require a minimum capability such as `edit_posts` before allowing any MCP access, while keeping the per-tool execution checks in place.

## Negative results / correctly guarded surfaces

These routes/handlers were reviewed and did **not** show a concrete capability/nonce/escaping/SQL issue in this audit:

- **`includes/class-admin-pages-rest.php`**
  - `agentic/v1/admin-page` GET/POST is guarded by `can_manage()` (`includes/class-admin-pages-rest.php:89-146`), which maps page/action access to the matching custom capability instead of a blanket `manage_options` check.
  - `admin_post_agentic_export_logs`, `admin_post_agentic_export_skill`, and `admin_post_agentic_import_skill` each verify a nonce and capability before exporting/importing (`includes/class-admin-pages-rest.php:1694-1868`).

- **`includes/class-admin-settings-rest.php`**
  - `agentic/v1/admin-settings`, `/test-service`, `/classic-tab`, `/mcp-test`, `/mcp-toggle-agent`, `/mcp-create-credential`, and `/mcp-revoke-credential` are all capability-gated (`includes/class-admin-settings-rest.php:36-163, 169-183`).
  - The credential mint/revoke routes correctly require `manage_options` instead of the broader settings capability.

- **`includes/class-agent-wizard-rest.php`**
  - `agentic/v1/agent-wizard/options` and `/agent-wizard/create` are admin-only (`includes/class-agent-wizard-rest.php:77-105`) and sanitize user input before invoking the create flow.

- **`includes/class-dashboard-rest.php`**
  - `agentic/v1/dashboard-stats` uses a view capability and `agentic/v1/dashboard` POST uses a manage capability (`includes/class-dashboard-rest.php:93-140`).
  - Mutations in `post_dashboard()` are scoped to site settings or the current user's own metadata and sanitize incoming values (`includes/class-dashboard-rest.php:369-450`).

- **`includes/class-deploy-wizard-rest.php`**
  - `agentic/v1/deploy-wizard/options` and `/deploy-wizard/save` are restricted to `manage_options` (`includes/class-deploy-wizard-rest.php:68-98`), and the save handler sanitizes agent/surface/config input before mutating options.

- **`includes/class-inventory-rest.php`**
  - `agentic/v1/inventory` and `/inventory/integrity` are read-only and protected by the settings capability (`includes/class-inventory-rest.php:61-93`).

- **`includes/class-knowledge-wizard-rest.php`**
  - `agentic/v1/knowledge-wizard/search-pages` and `/knowledge-wizard/save` use the same settings capability as the classic wiki UI (`includes/class-knowledge-wizard-rest.php:59-88`) and did not show unsafe SQL or missing sanitization in the reviewed handler code.

- **`includes/class-rest-api.php`**
  - Admin routes `/status`, `/models`, `/test-api`, `/approvals`, `/approvals/{id}`, `/tool-grants/{tool}`, `/backups/*` are capability-gated (`includes/class-rest-api.php:183-367, 1533-1544`).
  - `/proposals/{id}` does perform an ownership check for authenticated users before resolving a proposal (`includes/class-rest-api.php:1088-1155`), which is the right pattern even though the anonymous session model above still needs tightening.
  - Dynamic queries used by `/sessions`, `/history`, `/approvals`, and `/feedback` were prepared with `$wpdb->prepare()` where request-derived values were involved.

- **`includes/class-score-rest.php`**
  - `agentic/v1/score` and `/score/rescan` are separated into view/manage capabilities (`includes/class-score-rest.php:48-85`).

- **`includes/class-system-checker.php`**
  - `agentic/v1/system-check` and `/timeout-test` are both admin-only (`includes/class-system-checker.php:35-57`).

- **`includes/class-ui-settings-rest.php`**
  - `agentic/v1/ui-settings` GET/POST requires the settings capability (`includes/class-ui-settings-rest.php:45-70`), and the update handler sanitizes the values it persists.

- **`includes/class-webmcp-bridge.php`**
  - `agentic/v1/webmcp/execute` and `/webmcp/confirm` are intentionally broader than wp-admin routes, but they do apply explicit same-origin checks, a reviewed anonymous allowlist, and capability checks for non-anonymous-safe tools (`includes/class-webmcp-bridge.php:102-223, 273-394`).
  - I did **not** find a direct bypass from these routes into high-risk tools; Medium-risk tools are diverted into proposals and High/Extreme-risk tools are blocked before execution.

- **AJAX handlers**
  - The mutating `wp_ajax_*` handlers in `includes/class-admin-ajax.php` all reviewed as nonce-protected with a capability check before mutation (`includes/class-admin-ajax.php:77-260` and the remaining dispatcher-mapped handlers in the same file).
  - `includes/class-okf-admin.php` centralizes nonce/capability enforcement in `guard()` and calls it before each mutating AJAX handler (`includes/class-okf-admin.php:47-54, 102-185, 205-260`).
  - `includes/class-admin-surfaces.php:861-870` protects launcher dismissal with both `current_user_can( 'manage_options' )` and `check_ajax_referer()`.

## Notes for the next reviewer

- The biggest risk is the **jobs API**, because it provides an HTTP path around the plugin's documented risk-tier/approval design.
- The **anonymous chat** issue depends on `agentic_allow_anonymous_chat` being enabled, but when enabled it becomes a real cross-visitor confidentiality bug.
- The **public** routes in `class-native-forms.php` and `class-relay-connect.php` may be intentional product choices; they are called out here so they are explicitly accepted or narrowed, not silently assumed safe.
