=== Agent Builder ===
Contributors: agenticplugin
Tags: ai, chatbot, agents, safety, webmcp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.4.0
Donate link: https://agentic-plugin.com/donate/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create, train, and orchestrate AI agents with built-in safety. 11 free agents, approval gates, risk audits, tamper-proof logs, and WebMCP.

== Description ==

**Agent Builder** turns your WordPress site into an AI workspace where agents work under your control. Unlike generic chatbots that only answer questions, Agent Builder lets specialized AI agents take actions on your site — drafting articles, auditing SEO, triaging comments, monitoring health — all supervised by safety controls you can see and trust.

This plugin is built from the ground up for **AI agent safety**. Every tool an agent can use is flagged with a risk level. Sensitive actions pause in an approval queue for your review. Every agent action is logged and tamper-evident. You can audit what happened, roll back harmful changes, or hit a kill switch to disable all agents instantly.

Equipped with a **Basic / Advanced interface switch**, Agent Builder is effortless for site owners while providing complete control, custom tooling, and Model Context Protocol (MCP) and WebMCP integration for developers.

---

## 🛡️ Agent Safety: The Cornerstone

Unlike plugins that add AI without safety guardrails, **Agent Builder puts safety at the center**. You get:

* **Risk Inventory:** Every tool an agent can use is classified by risk level (Low, Medium, High, Extreme). See exactly what each agent can do at a glance.
* **Approval Gate:** Medium-risk actions need confirmation; High-risk actions queue for your review. Nothing happens until you decide.
* **Approval Queue:** Review and approve (or reject) every sensitive action before it touches your site — publishing posts, updating settings, creating accounts.
* **Kill Switch / Emergency Stop:** One click disables all agents, cancels pending jobs, and disconnects providers.
* **Tool Risk Floors:** Require confirmation for sensitive tools like password resets, plugin updates, or payment refunds — no exceptions.
* **Tamper-Proof Activity Log:** Full audit trail of what agents did and when. Hash-chained integrity prevents tampering — edited or deleted entries become detectable.
* **Per-Agent Tool Scopes:** See exactly which tools each agent has access to, and their current risk tier.

This is one of the first WordPress plugins actively built for AI-agent safety.

---

### 🚀 Zero-Code Simplicity for Site Owners

* **11 Free Built-In Agents:**
  * ✍️ **Content Writer:** Researches, writes, edits, and formats blog posts and pages.
  * 🔍 **SEO Optimizer:** Audits on-page content and proposes keyword and meta improvements.
  * 🛡️ **Site Health Sentinel:** Continuously checks performance, database health, and security alerts.
  * 💬 **Support Triage:** Summarizes customer comments, reviews form submissions, and drafts replies.
  * 🧭 **WordPress Assistant:** Onboards new users and helps troubleshoot core settings.
  * 🎓 **Assistant Trainer:** Build new specialized AI agents simply by describing their job in plain English.
  * ⚙️ **Agent Orchestrator:** Deploys assistants as frontend chat widgets, admin launchers, or background jobs.
  * 📰 **Editorial Director:** Plans editorial calendars and coordinates publishing workflows.
  * 👤 **User Assistant:** Manages member outreach, onboarding, and role-based permissions.
  * 🧩 **Skills Assistant:** Discovers and imports community skills to teach agents new capabilities.
  * 🛍️ **Storefront Assistant:** Helps visitors browse your WooCommerce catalog and build a cart — including directly in the browser via WebMCP.
* **Basic and Advanced Modes:** Switch modes anytime from Settings → Interface:
  * **Basic Mode:** Simplified, guided flows for non-technical site owners. Drag-and-drop agent setup, plain-language approvals, and one-click safety controls.
  * **Advanced Mode:** Full developer console. Raw tool manifests, detailed risk audits, REST API endpoints, MCP credentials, and technical audit logs.
* **Embed Everywhere:** Drop responsive chat widgets on any page using native **Gutenberg blocks**, shortcodes, or wp-admin launchers.
* **100% Free Local Knowledge:** Train agents on your company guidelines, docs, or site content using the local Open Knowledge Framework (OKF) wiki — no cloud storage needed.

---

### ⚡ Built for Developers & Power Users

* **WebMCP Bridge:** Enable low-risk tools for AI browser agents visiting your site. Visitors' own AI agents can search your content, browse WooCommerce catalogs, and build carts — with the same risk gates and per-visitor scoping that protect your backend. All the safety controls that guard agents inside wp-admin also protect your storefront.
* **Model Context Protocol (MCP) Ready:** Connect external clients like **Claude Desktop**, **Cursor**, and **VS Code** directly to your WordPress site using secure MCP credentials. Each client connection registers separately so you know exactly who has what access.
* **Bidirectional WordPress Abilities API (WP 6.9+):**
  * *Outbound:* Exposes all agent tools as native WordPress abilities (`agent-builder/` and `wp-extended/` namespaces) with risk tiers intact.
  * *Inbound:* Automatically transforms abilities declared by other plugins into callable agent tools with the same approval gate.
* **Multi-LLM & BYOK (Bring Your Own Key):** Connect OpenAI, Anthropic (Claude), Google Gemini, DeepSeek, xAI (Grok), Kimi (Moonshot), Mistral, Cohere, or run 100% private local models via **Ollama**.
* **Open Skill Architecture:** Full support for the `agentskills.io` open standard, WordPress.org Community Skills, and Anthropic Skills repositories.
* **Developer Controls:** Programmatic orchestration via REST API, automated cron triggers, and detailed JSON activity audit logs. React admin sources live in `src/`; production bundles in `build/` (`npm run build` with `@wordpress/scripts`).

Documentation & Guides: [agentic-plugin.com/documentation](https://agentic-plugin.com/documentation/)

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
* **Managed Credits:** Optional Agentic AI routing service with daily free credits.

== Frequently Asked Questions ==

= What is Agent Builder? =
Agent Builder allows you to create, train, and orchestrate autonomous AI agents inside WordPress. Agents use modular, risk-rated tools and skills to perform real administrative and editorial tasks — all under your control, with full visibility into what they do.

= How is this different from generic WordPress chatbot plugins? =
Standard chatbot plugins stream text from an API. Agent Builder gives agents permission-controlled tools to interact directly with your site—drafting posts, auditing SEO, checking health—backed by risk classification, approval gates, and a tamper-proof audit log. You stay in control.

= Is Agent Builder safe? =
Yes. Agent Builder is built around safety controls: (1) every tool is classified by risk level, (2) medium-risk actions need confirmation, high-risk actions queue for your review, (3) you have a one-click Emergency Stop to disable all agents, (4) a tamper-proof audit log records everything, (5) per-agent tool scopes show exactly what each agent can do. Read the “Agent Safety” section above for the full picture.

= What if an agent tries to do something dangerous? =
It depends on the risk level. Low-risk actions happen immediately. Medium-risk actions pause and ask for confirmation. High-risk actions (like publishing a post, deleting data, or updating settings) queue in the Approvals screen where you review them one by one before they execute. Extreme-risk tools (like arbitrary shell execution) are blocked by default.

= Do I need coding skills to use Agent Builder? =
No. The plugin includes a **Basic interface mode** designed for non-technical site owners, with guided workflows, plain-language approvals, and one-click controls. Experienced users can switch to **Advanced mode** for developer tools and raw configuration.

= What is WebMCP? =
WebMCP (Web-based Model Context Protocol) lets your visitors' own AI browser agents interact with your site safely. For example, if a visitor has Claude in their browser, their Claude can search your content, browse your WooCommerce store, and add items to their cart—all protected by the same risk gates and per-visitor scoping that guard your backend. It's agent-to-agent communication, not user-to-user.

= What is MCP? =
MCP (Model Context Protocol) is an open standard that lets external AI clients like Claude Desktop, Cursor, and VS Code connect directly to your WordPress site as a tool provider. You create a secure MCP credential in Settings → MCP, and external clients can then access safe tools on your site under your approval gate, with full audit logging.

= Is Agent Builder free? =
Yes. The free core plugin includes all 11 bundled agents, the complete tools/skills hub, the Approvals queue, the local OKF Knowledge wiki, and multi-provider BYOK support. Advanced hosted vector embeddings and cloud media generation are available via optional Agent Builder Pro add-ons.

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

= Where is the React admin source? =
React admin sources live in `src/`; production bundles are in `build/`. Rebuild with `npm run build` (`@wordpress/scripts`).

== Screenshots ==

1. Dashboard — Overview of active agents, connected providers, safety status, and quick actions.
2. Interactive Chat — Chat with specialized agents; see every tool the agent calls and its result.
3. Agents Hub — Activate/deactivate bundled agents, see their tools, and assign MCP exposure.
4. Approvals Queue — Review, approve, or reject sensitive actions before agents execute them.
5. Tools Hub — See every tool an agent can use, its risk level, and enable/disable by category.
6. Approvals Preferences — Configure which risk levels need approval, confirmation, or immediate blocking.
7. Site Passport / Agent-Ready Score — Verify your site is discoverable by AI agents and your commerce stack is ready.
8. Activity Log — Full audit trail showing what agents did, when, and whether they succeeded.
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

= Agentic AI Services (Optional) =
* **Endpoints:** 
  * Chat: `https://chat.agentic-plugin.com:11435`
  * Vector Store / RAG: `https://rag.agentic-plugin.com`
  * Image Generation: `https://imagegen.agentic-plugin.com`
  * Text-to-Speech: `https://tts.agentic-plugin.com`
  * Video Generation: `https://videogen.agentic-plugin.com`
* **When used:** Only when using Agentic managed AI credits or Pro cloud features.
* **Data sent:** Site URL, license key, prompt text, and task-specific media/document payloads.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Agentic Account & Platform Services (Optional) =
* **Endpoints:**
  * `https://agentic-plugin.com/wp-json/agentic/v1/model-pricing` — refreshes the LLM model/pricing catalog. Runs only after an administrator enables “Refresh model catalog from Agentic” in Settings → Security (off by default). Can also be triggered manually from the Costs page's "Get Latest Pricing" button. A plain GET request; no personal data is sent, and the response is cached locally.
  * `https://agentic-plugin.com/wp-json/agentic/v1/register` — only when an administrator submits the plugin's sign-up form to obtain a free Agentic API key. Sends the administrator's email address, site URL, site name, plugin version, and plan tier.
  * `https://agentic-plugin.com/wp-json/agentic-license/v1/cancellation-feedback` — only when a licensed, previously-consenting administrator submits a reason on the plugin-deactivation survey. Sends the license key, site URL, the selected reason, an optional free-text comment, and plugin version.
  * `https://agentic-plugin.com/wp-json/agentic/v1/agents/activate-token` — only when installing an uploaded community or purchased agent package that includes a license file. Sends the license token, agent slug, and site URL.
  * `https://agentic-plugin.com/wp-json/agentic/v1/report-issue` — only when an administrator explicitly confirms sending a diagnostic report via the in-chat "report an issue" tool (a preview is always shown first, and a second explicit confirmation is required before anything is sent). Sends site URL, license key (if any), recent error-log excerpts, the active AI provider, plugin/WordPress/PHP version information, and the administrator's own description of the problem.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Agentic Agent Marketplace (Agent Builder Pro Only, Optional) =
* **Endpoints:** `https://agentic-plugin.com/wp-json/agentic/v1/agents/check-updates`, plus a per-agent marketplace manifest URL and package download URL under the same domain.
* **When used:** Only on sites with an active Agent Builder Pro license, and only after an administrator separately opts in to agent update checks. Free and WordPress.org-only installs never contact this endpoint.
* **Data sent:** For update checks, the slug and version of every installed non-bundled agent. For installs/updates, the agent's slug and its manifest or package download URL.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Agentic MCP Connector Relay (Optional) =
* **Endpoints:**
  * `https://mcp.agentic-plugin.com/api/verify-state`
  * `https://mcp.agentic-plugin.com/oauth2/relay-callback`
* **When used:** Only when an administrator uses the browser-driven "Connect an MCP client" approval screen to link an external client such as Claude.ai or Cursor. Direct MCP access via a manually created Application Password (Settings → MCP) never contacts this service.
* **Data sent:** Site URL, the approving administrator's WordPress username and email address, a newly generated WordPress Application Password (base64-encoded) scoped to that connection, the list of active agent slugs, and the connecting provider's name.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Community Agent Skills Repositories (Optional) =
* **Endpoints:**
  * WordPress.org Skills: `https://api.github.com/repos/WordPress/agent-skills/` and `https://raw.githubusercontent.com/WordPress/agent-skills/`
  * Anthropic Skills: `https://api.github.com/repos/anthropics/skills/` and `https://raw.githubusercontent.com/anthropics/skills/`
  * Recommended Skills: `https://agentic-plugin.com/wp-json/agentic/v1/skills`
  * ClawHub: `https://wry-manatee-359.convex.site/api/v1/`
* **When used:** When browsing or importing community skills from the Skills screen.
* **Data sent:** Unauthenticated GET requests for public skills; search queries when using ClawHub.
* **Terms of Service:** [GitHub Terms](https://docs.github.com/site-policy/github-terms/github-terms-of-service) | [GitHub Privacy](https://docs.github.com/site-policy/privacy-policies/github-privacy-statement) | [Convex Terms](https://www.convex.dev/legal/tos) | [Convex Privacy](https://www.convex.dev/legal/privacy) | [OpenClaw Docs](https://docs.openclaw.ai/)

= Google PageSpeed Insights (Optional) =
* **Endpoint:** `https://www.googleapis.com/pagespeedonline/v5/runPagespeed`
* **When used:** Only when the Core Web Vitals check tool is used, and only if you've added your own free Google PageSpeed Insights API key in Settings → APIs. This plugin does not ship or use a shared API key — without your own key, this tool returns setup instructions instead of making a request. (Agent Builder Pro provides managed PageSpeed access through a separate mechanism, without requiring your own key.)
* **Data sent:** The URL being tested (defaults to your homepage), device strategy (mobile/desktop), and your Google API key.
* **Terms of Service:** [https://developers.google.com/terms](https://developers.google.com/terms)
* **Privacy Policy:** [https://policies.google.com/privacy](https://policies.google.com/privacy)

= WordPress.org Plugin & Core Directory (Optional) =
* **Endpoint:** `https://api.wordpress.org/`
* **When used:** Only when specific site-health tools are used — checking core file integrity against official checksums, or checking a plugin's abandonment/maintenance status or changelog. The same official API WordPress core itself uses for plugin/theme browsing and update checks.
* **Data sent:** WordPress version and locale, and the relevant plugin slug(s). No personal data.

= User-Configured Webhooks (Optional) =
* **Endpoint:** A URL you choose yourself when setting up a form.
* **When used:** Only if you enable a webhook on a form you create, so that form's submissions are also sent to a destination you specify.
* **Data sent:** Whatever data that form collects, sent only to the URL you configured — never to Agentic or any other third party.

= Site Passport Directory (Optional) =
* **Endpoint:** `https://sitepassport.org/api/submit.php`
* **When used:** Only when an administrator explicitly clicks "Submit to Directory" on the Agent-Ready page. Never automatic, never triggered by a cron job, and never sent as part of computing your score.
* **Data sent:** Your site's URL, the URL of this plugin's own `/.well-known/webmcp.json` manifest, and a minimal score summary (overall score, letter grade, and the date it was last checked — not the full per-check breakdown).
* **Terms of Service:** [https://sitepassport.org/terms](https://sitepassport.org/terms)
* **Privacy Policy:** [https://sitepassport.org/privacy](https://sitepassport.org/privacy)

= Site Passport (Local Only) =
The Site Passport score itself (Agent Builder → Passport) makes **zero external requests**. All eight checks — MCP reachability, WebMCP tool registration, approval-gate configuration, the `/.well-known/webmcp.json` manifest, the presence of `llms.txt`, AI-crawler directives in `robots.txt`, Organization/WebSite schema markup, and commerce readiness (whether an active WooCommerce store has a payment gateway configured and a commerce ability registered for agents) — are computed entirely from this site's own local files, database, and active-plugin state. Nothing is sent anywhere unless you separately choose "Submit to Directory" above.

== Changelog ==

= 3.4.0 - 2026-09-10 =
* WordPress.org plugin directory release. Major update: (1) Rewritten plugin description emphasizing AI agent safety as the core differentiator — Agent Builder is built with tamper-proof audit logs, approval gates, tool risk tiers, and a kill switch by default; (2) New "Safety Center" admin screen (agentic-safety-center) providing a unified risk dashboard — risk inventory, approval-gate configuration, per-agent tool scopes, tamper-detection status, and emergency stop; (3) Updated readme.txt with comprehensive FAQ covering safety, WebMCP, modes, and Features sections; (4) WebMCP Bridge documentation in Features section with plain-language explanation of how visitor AI agents interact with storefronts safely; (5) Stable tag and Tested-up-to now reflect WordPress 7.1.

= 3.3.97 - 2026-09-09 =
* Follow-up to 3.3.94/3.3.96: Storefront Assistant's four tools no longer ship with WebMCP exposure pre-enabled — the two read-only tools are still one click away via the existing "Turn on WebMCP defaults" fix, and the two cart-mutating tools now require the same deliberate per-tool toggle every other write-capable tool already needs, instead of riding along the moment the WebMCP Bridge master switch is on for an unrelated reason. Also added a visible "Log integrity: Verified / Tampering detected" indicator to Activity → Timeline for 3.3.96's hash-chained audit log, which previously had no UI at all — only a REST endpoint.

= 3.3.96 - 2026-09-08 =
* Hash-chained the Activity/audit log (Agent Builder → Activity) so an edited or deleted entry becomes detectable after the fact, the same tamper-evidence principle already used for signed agent manifests — nothing prevents direct database access, but tampering no longer goes unnoticed. Every log entry now also snapshots which developer/vendor built the acting agent and at what version, at the moment the action happened, so that record can't silently repoint if the agent is later updated or reassigned. Added a new admin-authenticated `agentic/v1/inventory` endpoint listing every active agent, its declared tools, and each tool's currently-effective risk tier in one machine-readable document — previously that picture only existed scattered across N separate per-agent MCP endpoints. Fixed a real bug found while building this: the daily audit-log retention cleanup was silently ignoring the retention period configured in Settings → Security, always falling back to its own 30-day default instead — a site owner who set a longer retention window was not actually getting one.

= 3.3.95 - 2026-09-08 =
* Fix: the Agent-Ready Score's Commerce readiness check now actually looks for a live, WebMCP-exposed, write-capable commerce tool (Storefront Assistant, active, with the WebMCP Bridge turned on) before scoring 100 — previously it only checked WooCommerce's own native Abilities API, which is a separate, ungated framework signal that has nothing to do with whether this plugin itself gates a transaction. Activating or deactivating Storefront Assistant now actually moves the score.

= 3.3.94 - 2026-09-08 =
* New bundled agent, Storefront Assistant, for sites running WooCommerce: browse the published catalog and build a cart, both in wp-admin chat and — the actual point — directly in the browser via the WebMCP Bridge, so a visitor's own AI agent can shop on their behalf. Four new tools (wc_browse_products, wc_view_cart, wc_add_to_cart, wc_update_cart_item), all scoped to the calling visitor's own session — no other visitor's data is ever touched. Deliberately does not place orders or take payment: checkout stays on the store's own checkout page. Fixed a real bug in passing — a logged-in customer with no elevated WordPress capability could not use these tools via WebMCP even though an anonymous guest could, because the anonymous-safe allowlist wasn't consulted for logged-in callers; now anyone gets the same shopping capability regardless of login state.

= 3.3.93 - 2026-09-08 =
* Agent-Ready Score adds an eighth check, Commerce readiness: on a site with WooCommerce active, scores whether a payment gateway is actually configured and whether a commerce-scoped WordPress Ability is registered for agents to call through Agent Builder's own approval gate. Sites with no commerce platform score as not applicable, never as failing. No one-click fix yet — that's a separate, larger change (wrapping WooCommerce's abilities as risk-tiered tools) still to come.

= 3.3.90 - 2026-09-04 =
* Added the Agent-Ready Score (Agent Builder → Agent-Ready): seven local, read-only checks covering MCP reachability, WebMCP tool registration, approval-gate safety, and llms.txt/robots.txt/schema.org discoverability, plus a new WebMCP Bridge (opt-in, off by default) that lets your own site register safe, low-risk tools for AI browser agents to use — search is supported out of the box, with the underlying tool contributed by the existing Support Triage agent. Three checks stay informational-only in this free tier and point to Agent Builder Pro's AI Radar for the matching one-click fix, so this feature never duplicates that existing Pro tooling.

= 3.3.89 - 2026-08-28 =
* This is now a permanently standalone WordPress.org codebase — removed all remaining dead code for detecting a self-hosted/Pro install (it can never happen here): the License and Distribution-channel systems, the agent-package upload/purchase flow, the no-code Site Tools builder, and the WP-CLI execution tools. Fixed real bugs found along the way: image/video generation and text-to-speech now correctly connect through the same free "Connect to Agentic AI" account as chat instead of a broken license check; a real Cloudflare Turnstile bot-protection implementation replaces a check that always silently no-opped; and the deactivation feedback survey and uninstall deregistration notice, both previously gated on a license that could never exist in this build, now work as designed.

= 3.3.88 - 2026-08-21 =
* Fix: MCP/abilities skip disabled tools; nested meta.mcp.public; MCP credential create returns user_id; HIGH floors for manage_skill / manage_user_privileges.

= 3.3.87 - 2026-08-21 =
* Fixed four regressions found in code review before 3.3.86 shipped anywhere: the Settings Advanced group (APIs, Endpoints, MCP) no longer disappears from the nav in Basic mode (it's still just a direct link away, but now discoverable); the manual "Refresh Models" button no longer fails by default now that catalog sync is opt-in; a dangling reference to a deleted agent template file no longer silently returns an empty result; and the WP.org export script now rebuilds a production-only `vendor/` instead of stripping it outright (was breaking every spreadsheet/PDF/DOCX tool in a real build).

= 3.3.86 - 2026-08-20 =
* WordPress.org submission: catalog sync is now opt-in (off by default), branding and WhatsApp promo default off, plugin install no longer auto-activates, unused Chart.js removed, WP-CLI agent tools omitted from the directory zip, and External Services entries added for OpenRouter and GitHub/Convex privacy.

= 3.3.85 - 2026-08-19 =
* WordPress.org submission readiness: corrected plugin name/trademark and Stable Tag mismatches, disclosed every External Service the plugin contacts (including several automatic, low-data background syncs), and removed the bundled/shared Google PageSpeed Insights key — Core Web Vitals checks now require your own free key, matching the plugin's existing bring-your-own-key model for every other provider.

= 3.3.78 - 2026-08-18 =
* Housekeeping: Trimmed the changelog to major releases only per WordPress.org guidelines. Full version history available on git.

= 3.3.76 - 2026-08-18 =
* Settings > Users: Added Basic/Advanced switch; User Assistant can now manage role-based plugin access and anonymous frontend chat conversationally.

= 3.3.75 - 2026-08-17 =
* Skills: Added Basic/Advanced split via Skills Assistant — create, edit, and import community skills conversationally.

= 3.3.67 - 2026-08-11 =
* Publish: Added Basic/Advanced split via Agent Orchestrator — deploy agents as chat widgets, scheduled tasks, or event triggers conversationally.

= 3.3.63 - 2026-08-10 =
* Standardized Basic/Advanced switches across Tools, Skills, Approvals, and Activity; added bulk approve/reject actions.

= 3.3.57 - 2026-08-07 =
* Skills now follow the open agentskills.io standard with runtime execution context, spec validation, and template gallery.

= 3.3.46 - 2026-08-03 =
* Full JavaScript translation coverage for React admin UI across all 11 bundled locales.

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