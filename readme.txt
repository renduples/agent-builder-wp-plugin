=== Agent Builder ===
Contributors: agenticplugin
Tags: ai, ai safety, ai agents, mcp, webmcp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.4.0
Donate link: https://agentic-plugin.com/donate/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create, train, and orchestrate AI agents with built-in safety using simple job descriptions. Approval gates, tamper-proof logs, MCP and WebMCP.

== Description ==

**Agent Builder** turns your WordPress site into an AI workspace where agents work under your control. Unlike generic chatbots that only answer questions, agents perform real actions — drafting articles, auditing SEO, triaging comments, monitoring health — all supervised by safety controls you can see and trust.

Every tool is risk-classified. Sensitive actions pause in an approval queue, logged and tamper-evident. Roll back changes or use the kill switch to stop all agents.

---

## 🛡️ Agent Safety: The Cornerstone

Unlike plugins that add AI without guardrails, **Agent Builder puts safety at the center**:

* **Risk Inventory:** Every tool classified Low/Medium/High/Extreme.
* **Approval Gate & Queue:** Medium-risk actions confirm; High-risk actions queue for your review first.
* **Kill Switch:** One click disables all agents and disconnects providers.
* **Tool Risk Floors:** Sensitive tools like password resets or refunds require confirmation.
* **Automatic Backups:** A timestamped backup is saved before any file or database change — restore with one click.
* **Tamper-Proof Activity Log:** Hash-chained audit trail; tampering becomes detectable.

One of the first WordPress plugins actively built for AI-agent safety.

---

### 🚀 Zero-Code Simplicity

**12 free built-in agents**: Content Writer, SEO Optimizer, Site Health Sentinel, Support Triage, WordPress Assistant, Assistant Trainer, Agent Orchestrator, Editorial Director, User Assistant, Skills Assistant, Storefront Assistant, AI Radar.

* **Basic / Advanced Modes:** Guided flows for owners; a full developer console (manifests, risk audits, REST API, MCP) for power users.
* **Embed Everywhere:** Gutenberg blocks, shortcodes, or wp-admin launchers.
* **100% Free Local Knowledge:** Train agents on your own docs via the local Open Knowledge Format (OKF) wiki — no cloud storage needed.

---

### ⚡ Built for Developers

* **WebMCP Bridge:** Expose low-risk tools to visitors' own AI browser agents, same risk gates as the backend.
* **MCP Ready:** Connect Claude Desktop, Cursor, and VS Code via secure credentials.
* **WordPress Abilities API (WP 6.9+):** Exposes tools as native abilities; imports other plugins' abilities.
* **Multi-LLM BYOK:** OpenAI, Anthropic, Google Gemini, DeepSeek, xAI, Kimi, Mistral, Cohere, or local Ollama.

== Installation ==

= Minimum Requirements =

* WordPress 6.4 or higher (6.9+ recommended for native AI Abilities API support)
* PHP 8.1 or higher
* MySQL 8.0 or MariaDB 10.6+

= Automatic Installation =

1. Log in to your WordPress admin dashboard.
2. Navigate to **Plugins → Add New Plugin**.
3. Search for **Agent Builder**.
4. Click **Install Now**, then click **Activate**.
5. The Quick Start wizard will guide you through connecting your preferred AI provider in under two minutes.

= Manual Installation =

1. Download the plugin ZIP file.
2. Go to **Plugins → Add New Plugin → Upload Plugin**.
3. Select the `.zip` file and click **Install Now**.
4. Activate the plugin through the WordPress Plugins menu.
5. Navigate to **Agent Builder → Settings** to connect your LLM provider.

= Supported LLM Providers =

* **Cloud Providers:** OpenAI (GPT-4o, o3-mini), Anthropic (Claude 3.5 Sonnet), Google Gemini, xAI (Grok), DeepSeek, Kimi (Moonshot), Mistral, Cohere, and OpenRouter.
* **Local & Private:** Ollama (default: `http://localhost:11434`) or any custom OpenAI-compatible endpoint.
* **Managed Credits:** Optional Agentic AI service with daily free credits.

== Frequently Asked Questions ==

= What is Agent Builder? =
Agent Builder allows you to create, train, and orchestrate autonomous AI agents inside WordPress using simple job descriptions. Agents use modular, risk-rated tools and skills to perform real administrative and editorial tasks — all under your control, with full visibility into what they do.

= How is this different from generic WordPress chatbot plugins? =
Standard chatbot plugins stream text from an API. Agent Builder gives agents permission-controlled tools to interact directly with your site—drafting posts, auditing SEO, checking health—backed by risk classification, approval gates, and a tamper-proof audit log. You stay in control.

= Is Agent Builder safe? =
Yes. Agent Builder is built around safety controls: (1) every tool is classified by risk level, (2) medium-risk actions need confirmation, high-risk actions queue for your review, (3) you have a one-click Emergency Stop to disable all agents, (4) a tamper-proof audit log records everything, (5) per-agent tool scopes show exactly what each agent can do. Read the “Agent Safety” section above for the full picture.

= What if an agent tries to do something dangerous? =
It depends on the risk level. Low-risk actions happen immediately. Medium-risk actions pause and ask for confirmation. High-risk actions (like publishing a post, deleting data, or updating settings) queue in the Approvals screen where you review them one by one before they execute. Extreme-risk tools (like arbitrary shell execution) are blocked by default.

= Can I undo a change an agent made? =
Yes. Before an agent modifies a tracked file or database table, Agent Builder automatically creates a timestamped backup — this is on by default and needs no setup. Every backup shows up in the Approvals screen, where you can restore it with one click.

= Do I need coding skills to use Agent Builder? =
No. The plugin includes a **Basic interface mode** designed for non-technical site owners, with guided workflows, plain-language approvals, and one-click controls. Experienced users can switch to **Advanced mode** for developer tools and raw configuration.

= What is WebMCP? =
WebMCP (Web-based Model Context Protocol) lets your visitors' own AI browser agents interact with your site safely. For example, if a visitor has Claude in their browser, their Claude can search your content, browse your WooCommerce store, and add items to their cart—all protected by the same risk gates and per-visitor scoping that guard your backend. It's agent-to-agent communication, not user-to-user.

= What is MCP? =
MCP (Model Context Protocol) is an open standard that lets external AI clients like Claude Desktop, Cursor, and VS Code connect directly to your WordPress site as a tool provider. You create a secure MCP credential in Settings → MCP, and external clients can then access safe tools on your site under your approval gate, with full audit logging.

= Is Agent Builder free? =
Yes. The free core plugin includes all 12 bundled agents, the complete tools/skills hub, the Approvals queue, the local OKF Knowledge wiki, multi-provider BYOK support, and cloud AI image/video generation (via the optional Agentic AI connection, which includes daily free credits — no purchase required). Advanced hosted vector embeddings and semantic search across large document sets are available via optional Agent Builder Pro add-ons.

PDF and Word document generation are not bundled in this WordPress.org package (license and file-size reasons); spreadsheet creation, editing, and analysis are. The PDF/Word tools still appear in the Tools list so agents can use them if you're on a build that includes those libraries — Tools Hub marks them "Unavailable on this install" otherwise.

= What modes are there? =
**Basic Mode:** Simplified interface with guided workflows, fewer options, and one-click safety controls. Best for site owners who want to use agents without configuration. **Advanced Mode:** Full developer console showing tool manifests, REST API docs, risk audits, MCP credentials, and technical audit logs. Switch anytime from Settings → Interface.

= Can I run agents on a schedule? =
Yes. The Agent Orchestrator agent can deploy other agents as background cron jobs or triggered by site events. You can also use the REST API to orchestrate agents programmatically.

= Where is my data sent? =
When using cloud LLM providers, conversation context and tool parameters are sent directly to your chosen provider via their official API (see External Services below). If you use Ollama or a local endpoint, 100% of your data stays on your local server.

= What is the WordPress Abilities API integration? =
On WordPress 6.9+, Agent Builder provides bidirectional integration: (1) **Outbound:** Registers agent tools as abilities under `agent-builder/` and `wp-extended/` namespaces for external MCP discovery. (2) **Inbound:** Automatically imports abilities exposed by other WordPress plugins so your agents can use them as tools, all protected by the same risk gate.

= What is the difference between Tools and Skills? =
**Tools:** Single, permission-controlled actions an agent can execute (e.g., `create_draft_post`, `get_site_health`). **Skills:** Pre-packaged instruction sets and tool workflows following the open `agentskills.io` standard that teach agents multi-step capabilities without writing code.

= What happens to my data if I delete the plugin? =
Uninstall keeps your data unless you check “Delete all plugin data” on the deactivation dialog. If you do choose to delete, conversation history, options, custom tables, and the agents and skills you created or imported are all removed.

= Is this plugin translated into other languages? =
The interface text is written in English, and the `.pot` translation template is included so you (or [translate.wordpress.org](https://translate.wordpress.org/)) can generate your own `.mo`/`.json` translation files for your site's locale. Pre-built translations for 11 languages ship with Agent Builder Pro.

== Screenshots ==

1. Dashboard — Overview of active agents, connected providers, safety status, and quick actions.
2. Interactive Chat — Issue instructions to specialized agents; see every tool the agent calls and its result.
3. Agents Hub — Activate/deactivate bundled agents, see their tools, and assign MCP exposure.
4. Agent Ready Score — Verify your site is discoverable by AI agents and your agentic commerce stack is ready.
5. Approvals Queue — Review, approve, or reject sensitive actions before agents execute them.
6. Tools Hub — See every tool an agent can use, its risk level, and enable/disable by category.
7. Knowledge — Train agents on your own docs and guidelines with the local Open Knowledge Framework wiki, no cloud storage needed.
8. Activity Log — Full tamper proof audit trail showing what agents did, when, and whether they succeeded.
9. Safety Center — Risk inventory, kill switch, per-agent tool scopes, and audit-log integrity check.
10. Quick Start Wizard — Connect your LLM provider and choose Basic or Advanced mode in under two minutes.
11. Settings & Providers — Connect and manage LLM providers. Interface (UI modes) and Security are in the same Settings nav.

== External Services ==

This plugin connects to external AI APIs to process prompts and tool executions — no request to any AI provider is made until you configure or explicitly activate it. Optional catalog refresh from Agentic is off by default and only runs after you enable it in Settings → Security. See "Agentic Account & Platform Services" below for every Agentic endpoint, when it is used, and what is sent.

= OpenAI =
* **Endpoint:** `https://api.openai.com/v1/chat/completions`
* **When used:** When OpenAI is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://openai.com/terms](https://openai.com/terms)
* **Privacy Policy:** [https://openai.com/privacy](https://openai.com/privacy)

= Anthropic =
* **Endpoint:** `https://api.anthropic.com/v1/messages`
* **When used:** When Anthropic Claude is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://www.anthropic.com/terms](https://www.anthropic.com/terms)
* **Privacy Policy:** [https://www.anthropic.com/privacy](https://www.anthropic.com/privacy)

= xAI =
* **Endpoint:** `https://api.x.ai/v1/chat/completions`
* **When used:** When xAI (Grok) is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://x.ai/legal/terms-of-service](https://x.ai/legal/terms-of-service)
* **Privacy Policy:** [https://x.ai/legal/privacy-policy](https://x.ai/legal/privacy-policy)

= Google Gemini =
* **Endpoint:** `https://generativelanguage.googleapis.com/v1beta/models/`
* **When used:** When Google Gemini is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://ai.google.dev/terms](https://ai.google.dev/terms)
* **Privacy Policy:** [https://policies.google.com/privacy](https://policies.google.com/privacy)

= Mistral AI =
* **Endpoint:** `https://api.mistral.ai/v1/chat/completions`
* **When used:** When Mistral is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://mistral.ai/terms/](https://mistral.ai/terms/)
* **Privacy Policy:** [https://mistral.ai/terms/#privacy-policy](https://mistral.ai/terms/#privacy-policy)

= Meta Llama =
* **Endpoint:** `https://api.llama.com/v1/chat/completions`
* **When used:** When Meta Llama API endpoints are configured.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://llama.meta.com/llama3/license/](https://llama.meta.com/llama3/license/)
* **Privacy Policy:** [https://www.meta.com/privacy/](https://www.meta.com/privacy/)

= Cohere =
* **Endpoint:** `https://api.cohere.com/v2/chat`
* **When used:** When Cohere is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://cohere.com/terms-of-use](https://cohere.com/terms-of-use)
* **Privacy Policy:** [https://cohere.com/privacy](https://cohere.com/privacy)

= Kimi (Moonshot AI) =
* **Endpoint:** `https://api.moonshot.ai/v1/chat/completions`
* **When used:** When Kimi is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://platform.moonshot.ai/docs/agreement/modeluse](https://platform.moonshot.ai/docs/agreement/modeluse)
* **Privacy Policy:** [https://platform.moonshot.ai/docs/agreement/privacy](https://platform.moonshot.ai/docs/agreement/privacy)

= DeepSeek =
* **Endpoint:** `https://api.deepseek.com/chat/completions`
* **When used:** When DeepSeek is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html](https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html)
* **Privacy Policy:** [https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)

= OpenRouter =
* **Endpoint:** `https://openrouter.ai/api/v1/chat/completions`
* **When used:** When OpenRouter is selected as your AI provider.
* **Data sent:** Chat prompts, system instructions, tool definitions, and execution payloads.
* **Terms of Service:** [https://openrouter.ai/terms](https://openrouter.ai/terms)
* **Privacy Policy:** [https://openrouter.ai/privacy](https://openrouter.ai/privacy)

= Ollama (Local) =
* **Endpoint:** User-configured local URL (default: `http://localhost:11434`)
* **When used:** When Ollama is selected as your AI provider.
* **Data sent:** All data remains strictly on your local infrastructure.

= WordPress.org Plugin & Core Directory (Opt in) =
* **Endpoint:** `https://api.wordpress.org/`
* **When used:** Only when specific site-health tools are used — checking core file integrity against official checksums, or checking a plugin's abandonment/maintenance status or changelog. The same official API WordPress core itself uses for plugin/theme update checks.
* **Data sent:** WordPress version and locale, and the relevant plugin slug(s). No personal data.

= Agentic AI Services (Opt in) =
* **Endpoints:**
  * Chat Service: `https://chat.agentic-plugin.com`
  * Image Generation: `https://imagegen.agentic-plugin.com`
  * Text-to-Speech: `https://tts.agentic-plugin.com`
  * Video Generation: `https://videogen.agentic-plugin.com`
  * Music Search (Jamendo): the Video Generation endpoint above proxies royalty-free background-music searches to [Jamendo](https://www.jamendo.com/) — your search terms are relayed to Jamendo's catalog, not sent to Jamendo directly from your site.
* **When used:** Only when using Agentic managed AI credits or Pro cloud features.
* **Data sent:** Site URL, license key, prompt text, and task-specific media/document payloads.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/) | [Jamendo Terms](https://www.jamendo.com/legal/terms-of-use) | [Jamendo Privacy](https://www.jamendo.com/legal/privacy)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Agentic Platform Services (Opt in) =
* **Endpoints:**
  * `https://agentic-plugin.com/wp-json/agentic/v1/model-pricing` — refreshes the LLM model/pricing catalog. Runs only after an administrator enables “Refresh model catalog from Agentic” in Settings → Security (off by default). Can also be triggered manually from the Costs page's "Get Latest Pricing" button. A plain GET request; no personal data is sent, and the response is cached locally.
  * `https://agentic-plugin.com/wp-json/agentic/v1/register` — only when an administrator submits the plugin's sign-up form to obtain a free Agentic API key. Sends the administrator's email address, site URL, site name, plugin version, and plan tier.
  * `https://agentic-plugin.com/wp-json/agentic-license/v1/cancellation-feedback` — only when a licensed, previously-consenting administrator submits a reason on the plugin-deactivation survey. Sends the license key, site URL, the selected reason, an optional free-text comment, and plugin version.
  * `https://agentic-plugin.com/wp-json/agentic/v1/agents/activate-token` — only when installing an uploaded community or purchased agent package that includes a license file. Sends the license token, agent slug, and site URL.
  * `https://agentic-plugin.com/wp-json/agentic/v1/report-issue` — only when an administrator explicitly confirms sending a diagnostic report via the in-chat "report an issue" tool (a preview is always shown first, and a second explicit confirmation is required before anything is sent). While composing the preview, the tool also makes a lightweight `GET /health` connectivity check against the Chat Service endpoint above to include in the report; this check itself sends no personal data. Sends site URL, recent error-log excerpts, the active AI provider, connectivity status, plugin/WordPress/PHP version information, and the administrator's own description of the problem.
  * `https://agentic-plugin.com/wp-json/agentic/v1/deregister` — only when the plugin is uninstalled, and only if an Agentic API key is configured and an administrator has explicitly enabled "Deregister on uninstall". Sends the API key and site URL.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Cloudflare Turnstile (Opt in) =
* **Endpoint:** `https://challenges.cloudflare.com/turnstile/v0/siteverify`
* **When used:** Only when an administrator has configured a Cloudflare Turnstile site key and secret key in Settings → Security — for anonymous chat-widget bot protection, and optionally for spam protection on native forms.
* **Data sent:** Your configured Turnstile secret key, the visitor's challenge-response token, and the visitor's IP address.
* **Terms of Service:** [https://www.cloudflare.com/website-terms/](https://www.cloudflare.com/website-terms/)
* **Privacy Policy:** [https://www.cloudflare.com/privacypolicy/](https://www.cloudflare.com/privacypolicy/)

= Community Agent Skills Repositories (Opt in) =
* **Endpoints:**
  * WordPress.org Skills: `https://api.github.com/repos/WordPress/agent-skills/` and `https://raw.githubusercontent.com/WordPress/agent-skills/`
  * Anthropic Skills: `https://api.github.com/repos/anthropics/skills/` and `https://raw.githubusercontent.com/anthropics/skills/`
  * Recommended Skills: `https://agentic-plugin.com/wp-json/agentic/v1/skills`
  * ClawHub: `https://wry-manatee-359.convex.site/api/v1/`
* **When used:** When browsing or importing community skills from the Skills screen.
* **Data sent:** Unauthenticated GET requests for public skills; search queries when using ClawHub.
* **Imported content:** A skill is plain text (YAML frontmatter + Markdown) stored in this plugin's own database — never a program file, and never written to disk, executed, or included as code. Manually uploading a skill file only accepts `.md`, `.markdown`, or `.txt`; anything else, including `.php`, is rejected before the file is even read.
* **Terms of Service:** [GitHub Terms](https://docs.github.com/site-policy/github-terms/github-terms-of-service) | [GitHub Privacy](https://docs.github.com/site-policy/privacy-policies/github-privacy-statement) | [Convex Terms](https://www.convex.dev/legal/tos) | [Convex Privacy](https://www.convex.dev/legal/privacy) | [OpenClaw Docs](https://docs.openclaw.ai/)

= GitHub API (Opt in) =
* **Endpoint:** `https://api.github.com` — any endpoint under it (repos, issues, pull requests, commits, releases, actions, and more), any of GET/POST/PATCH/PUT/DELETE.
* **When used:** Only if an agent is given the `github_api` tool and you've set your own GitHub personal access token via WP-CLI (`wp option update agentic_github_token "<token>"`) — there is no settings-screen field for this yet. No bundled agent has this tool by default; it's available for a custom agent you build yourself. Always requires your approval before running (High risk).
* **Data sent:** Whatever the request needs — your personal access token for authentication, plus any endpoint path, query parameters, or request body the agent sends. Scope of access is whatever your token allows.
* **Terms of Service:** [GitHub Terms](https://docs.github.com/site-policy/github-terms/github-terms-of-service)
* **Privacy Policy:** [GitHub Privacy](https://docs.github.com/site-policy/privacy-policies/github-privacy-statement)

= Google PageSpeed Insights (Opt in) =
* **Endpoint:** `https://www.googleapis.com/pagespeedonline/v5/runPagespeed`
* **When used:** Only when the Core Web Vitals check tool is used, and only if you've added your own free Google PageSpeed Insights API key in Settings → APIs. This plugin does not ship or use a shared API key — without your own key, this tool returns setup instructions instead of making a request.
* **Data sent:** The URL being tested (defaults to your homepage), device strategy (mobile/desktop), and your Google API key.
* **Terms of Service:** [https://developers.google.com/terms](https://developers.google.com/terms)
* **Privacy Policy:** [https://policies.google.com/privacy](https://policies.google.com/privacy)

= Site Passport Directory (Opt in) =
* **Endpoint:** `https://sitepassport.org/api/submit.php`
* **When used:** Only when an administrator explicitly clicks "Submit to Directory" on the Agent-Ready page. Never automatic, never triggered by a cron job, and never sent as part of computing your score.
* **Data sent:** Your site's URL, the URL of this plugin's own `/.well-known/webmcp.json` manifest, and a minimal score summary (overall score, letter grade, and the date it was last checked — not the full per-check breakdown).
* **Terms of Service:** [https://sitepassport.org/terms](https://sitepassport.org/terms)
* **Privacy Policy:** [https://sitepassport.org/privacy](https://sitepassport.org/privacy)

= Your Own Configured Form Webhook (Opt in) =
* **Endpoint:** A URL you configure yourself, per form — not a fixed third-party service. Behaves like the "Ollama (Local)" entry above: this plugin does not send data anywhere except where you've explicitly pointed it.
* **When used:** Only for a native form that has a webhook URL set in its own form settings, and only when a visitor submits that specific form.
* **Data sent:** The submitted form's field values, plus the form ID, title, and submission timestamp — sent only to the URL you configured, with an optional HMAC-SHA256 signature header if you also set a shared secret.


== Changelog ==

= 3.4.0 - 2026-09-10 =
* New "Safety Center" admin screen providing a unified risk dashboard — risk inventory, approval-gate configuration, per-agent tool scopes, tamper-detection status, and emergency stop; 

= 3.3.96 - 2026-09-08 =
* Hash-chained the Activity/audit log so an edited or deleted entry becomes detectable after the fact — tampering no longer goes unnoticed. 

= 3.3.95 - 2026-09-08 =
* Agent-Ready Score's Commerce readiness check now actually looks for a live, WebMCP-exposed, write-capable commerce tool.

= 3.3.94 - 2026-09-08 =
* New bundled agent, Storefront Assistant, for sites running WooCommerce, so a visitor's own AI agent can shop on their behalf. Deliberately does not place orders or take payment: checkout stays on the store's own checkout page.

= 3.3.93 - 2026-09-08 =
* Agent-Ready Score adds an eighth check, Commerce readiness: on a site with WooCommerce active and whether a commerce-scoped WordPress Ability is registered for agents to call.

= 3.3.90 - 2026-09-04 =
* Added the Agent-Ready Score read-only checks covering MCP reachability, with a new WebMCP Bridge (opt-in, off by default) that lets your own site register safe, low-risk tools for AI browser agents to use.

= 3.3.85 - 2026-08-19 =
* Google PageSpeed Insights key — Core Web Vitals checks now require your own free key, matching the plugin's existing bring-your-own-key model for every other provider.

= 3.3.57 - 2026-08-07 =
* Skills now follow the open agentskills.io standard with runtime execution context, spec validation, and template gallery.

= 3.3.24 - 2026-08-01 =
* Guided deployment wizards for chat widgets, admin launchers, Gutenberg blocks, and Knowledge training.

= 3.3.0 - 2026-07-29 =
* React-based admin hubs for Settings, Tools, Approvals, and Knowledge; added Kimi and DeepSeek provider support.

For the full version history, visit [agentic-plugin.com/changelog](https://agentic-plugin.com/changelog/).

== Upgrade Notice ==

= 3.3.0 =
React Settings, Tools, Approvals, and Knowledge hubs. No breaking changes for existing agents or API keys.

= 3.0.0 =
Contextual AI launchers across wp-admin, manage_cache tool, and bidirectional WordPress Abilities integration. No breaking changes.

= 2.9.272 =
WordPress.org Plugin Check compliance, guarded Abilities API adapter, multi-agent orchestration, and opt-in local memory.

== Privacy ==

See **External Services** for what is sent when you enable a cloud LLM or optional Agentic API. For Agentic product services, also see the [Agentic Privacy Policy](https://agentic-plugin.com/privacy-policy/) and [Terms of Service](https://agentic-plugin.com/terms-of-service/).