=== Agent Builder ===
Contributors: agenticplugin
Tags: ai, ai agents, automation, ai assistant, mcp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 4.4.0
Donate link: https://agentic-plugin.com/donate/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give AI agents real WordPress work. They run in the background and only come back when something needs your OK. Built with safety controls.

== Description ==

**Hand a job to an agent, walk away, and get pinged only when it needs you.**

Agent Builder gives your WordPress site a team of AI agents that do real work: drafting posts, fixing SEO, triaging comments, checking site health and helping run your store. Low-risk steps happen on their own. Anything that needs your judgement waits for one-click approval.

Bring your own AI provider (OpenAI, Anthropic, Google Gemini, xAI, DeepSeek, Mistral and more, or a local model through Ollama). Free, with no account required.

**Important:** AI models are run by third-party providers you choose (or by your own Ollama server). Agents can make mistakes or be misled by content they read. Approvals, backups and the activity log reduce risk but are not guarantees: review what agents do, keep independent backups and test on a staging site.

---

### ✅ Assign a task, get on with your day

* **Tasks:** pick an agent, describe the job in plain English, and press Assign. It runs in the background. Watch each step live or come back later.
* **Waiting on you:** when a step needs your OK, the task pauses and tells you. Approve it and the agent picks up where it left off.
* **Notifications:** an inbox, admin-bar badge, dashboard card and an optional daily email digest.
* **Results you can use:** finished work arrives as cards, such as a post with an "Open in editor" link, a file, a table or a diff.

### 🧭 Site Brief

Site Brief scans your install for pending updates, Site Health warnings, comments held for moderation, oversized media and WooCommerce store issues. It turns the results into a ranked list of jobs, each assigned to the right agent. The scan is read-only. Nothing changes until you approve.

### 🔁 Routines

Run any agent on a schedule or when something happens on your site, such as a post being published, a new order or a new user. Each routine has a test run, pause and resume, its next run in your timezone and a 20-run history.

### 🧑‍💼 Agents that feel like teammates

* **Profiles:** a name, job title, avatar and standing instructions for each agent. Pin favourites, hide the rest.
* **Create one in three fields**, duplicate an existing agent, or export and import agents as templates.
* **12 agents included:** Content Writer, SEO Optimizer, Site Health Sentinel, Support Triage, WordPress Assistant, Assistant Trainer, Agent Orchestrator, Editorial Director, User Assistant, Skills Assistant, Storefront Assistant and AI Radar.

### 🎓 Skills

Save a conversation as a skill, teach a task by demonstrating it once, or write one from a description. Type **/skill-name** in chat to use it. Skills are shared across all your agents.

### 🛡️ You stay in control

* **Approval rules in plain English:** for example, "Ask me first before publishing anything." "Ask first" always wins.
* **Allow once, for the session or always.** Every standing permission is listed under Approvals, where you can revoke it.
* **Risk levels on every tool.** High-risk actions always need your OK.
* **Automatic backups** before file and database changes, a **tamper-evident activity log**, and a **kill switch** that stops every agent.

### 🔌 Works with the AI tools you already use

* **MCP server:** connect Grok Bot, Claude Desktop, Cursor or VS Code to your site, behind the same approval gates.
* **WebMCP:** visitors' AI browser agents can use the low-risk tools you choose.
* **WordPress Abilities API (WP 6.9+)** support, and voice dictation in chat on HTTPS sites.

### 🧪 Tested on real problems, built for developers

* **Prompt Tests:** every release is graded against 54 real problems WordPress owners ask about. Run `wp agent prompt-test` on your own site within a spending cap you set.
* **Basic / Advanced modes:** guided screens for site owners, and a full console for developers.
* **Embed anywhere** with blocks, shortcodes or wp-admin launchers. Train agents on your own docs with the local Open Knowledge Format wiki.

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

* **Cloud Providers:** OpenAI (GPT-4o, o3-mini), Anthropic (Claude Sonnet 5.5, Opus 5.5, Haiku 4.5), Google Gemini, xAI (Grok), DeepSeek, Kimi (Moonshot), Mistral, Cohere, and OpenRouter.
* **Local & Private:** Ollama (default: `http://localhost:11434`) or any custom OpenAI-compatible endpoint.
* **Managed Credits:** Optional Agentic AI service with daily free credits.

== Frequently Asked Questions ==

= What is Agent Builder? =
Agent Builder allows you to create, train, and orchestrate autonomous AI agents inside WordPress using simple job descriptions. Agents use modular, risk-rated tools and skills to perform real administrative and editorial tasks — all under your control, with full visibility into what they do.

= How is this different from generic WordPress chatbot plugins? =
Standard chatbot plugins stream text from an API. Agent Builder gives agents permission-controlled tools to interact directly with your site—drafting posts, auditing SEO, checking health—backed by risk classification, approval gates, and a tamper-evident audit log. You stay in control.

= Is Agent Builder safe to use? =
Agent Builder is built with safety controls: (1) every tool is classified by risk level, (2) medium-risk actions need confirmation and high-risk actions queue for your review, (3) a one-click Emergency Stop disables all agents, (4) a tamper-evident audit log records what agents do, (5) per-agent tool scopes show exactly what each agent can do. See “You stay in control” above. These controls reduce risk, but no software can guarantee safety: AI models can make mistakes or be misled by content they read. You remain responsible for reviewing what agents do and for keeping your own backups of your site.

= Does Site Brief change my site by itself? =
No. The scan is read-only. Writes use the same approval queue as chat.

= What if an agent tries to do something dangerous? =
It depends on the risk level. Low-risk actions happen immediately. Medium-risk actions pause and ask for confirmation. High-risk actions (like publishing a post, deleting data, or updating settings) queue in the Approvals screen where you review them one by one before they execute. Extreme-risk tools (like arbitrary shell execution) are blocked by default.

= How do I know the agents actually work on real problems? =
Because they are tested the way you would use them. Agent Builder ships a ranked catalog of 54 real requests from WordPress owners, each assigned to a bundled agent, and a WP-CLI command (`wp agent prompt-test`) that replays them through the real chat path and writes a report of what every agent did: tools called, approval stops, cost. Run it on your own site, add your own requests, and read the report in plain markdown. From chat, ask the Assistant Trainer "which of my agents are missing tools?" and it will analyse the results and propose what to grant — you approve. It runs only when you start it and costs only what your AI provider charges for those calls.

= Can I undo a change an agent made? =
Often, yes. Before an agent changes a tracked file or database table, Agent Builder automatically takes a timestamped backup (on by default, no setup needed), and you can restore it with one click from the Approvals screen. Database backups keep the last 3 snapshots per table, and for large tables only the affected rows are saved. These backups cover only the files and tables that agent tools track. They do not replace a full site backup or a staging site.

= Do I need coding skills to use Agent Builder? =
No. The plugin includes a **Basic interface mode** designed for non-technical site owners, with guided workflows, plain-language approvals, and one-click controls. Experienced users can switch to **Advanced mode** for developer tools and raw configuration.

= What is WebMCP? =
WebMCP (Web-based Model Context Protocol) lets your visitors' own AI browser agents interact with your site safely. For example, if a visitor has Claude in their browser, their Claude can search your content, browse your WooCommerce store, and add items to their cart—all protected by the same risk gates and per-visitor scoping that guard your backend. It's agent-to-agent communication, not user-to-user.

= What is MCP? =
MCP (Model Context Protocol) is an open standard that lets external AI clients like Grok Bot, Claude Desktop, Cursor, and VS Code connect directly to your WordPress site as a tool provider. You create a secure MCP credential in Settings → MCP, and external clients can then access safe tools on your site under your approval gate, with full audit logging.

= Is Agent Builder free? =
Yes. The free core plugin includes all 12 bundled agents, the complete tools/skills hub, the Approvals queue, the local OKF Knowledge wiki, multi-provider BYOK support, and cloud AI image/video generation (via the optional Agentic AI connection, which includes daily free credits — no purchase required). Advanced hosted vector embeddings and semantic search across large document sets are available via optional Agent Builder Pro add-ons.

PDF and Word document generation are not bundled in this WordPress.org package (license and file-size reasons); spreadsheet creation, editing, and analysis are. The PDF/Word tools still appear in the Tools list so agents can use them if you're on a build that includes those libraries — Tools Hub marks them "Unavailable on this install" otherwise.

= What modes are there? =
**Basic Mode:** Simplified interface with guided workflows, fewer options, and one-click safety controls. Best for site owners who want to use agents without configuration. **Advanced Mode:** Full developer console showing tool manifests, REST API docs, risk audits, MCP credentials, and technical audit logs. Switch anytime from Settings → Interface.

= Can I run agents on a schedule? =
Yes. Use the Routines screen (Agent Builder → Routines, next to Tasks) to run any agent automatically. A routine can run on a repeating schedule — hourly, twice daily, daily, or weekly — or trigger from a WordPress event such as a post being published, a comment submitted, a new user registered, a file uploaded, a new WooCommerce order, or a plugin or theme change. Each routine can be paused and resumed, test-run before it goes live, and keeps a history of its last 20 runs, each linked back to the Tasks screen.

Routines run on WordPress's WP-Cron, which only fires when your site receives a visit. On a low-traffic site, set `DISABLE_WP_CRON` and add a real system cron entry that hits `wp-cron.php` so scheduled routines run reliably.

If you prefer to script things, you can still orchestrate agents programmatically through the Agent Orchestrator agent or the REST API.

= What is a task? =
A task is a job you hand to an agent to run in the background. From the Tasks screen you pick an agent, describe what you want, and start it. The agent works on its own — low-risk steps run automatically, while anything that needs your judgement waits for you in Tasks under "Waiting on you". You get a notification when a task is waiting on your OK or has finished, so you only check back when there is something to act on.

= Does Agent Builder send me email? =
Yes — a daily activity digest that summarizes what your agents did. It is sent only to site administrators, uses WordPress's own mail function (`wp_mail`, no external email service), and is on by default. You can switch it to instant run notifications or turn email off entirely from Settings → Security, and each administrator can opt out of the digest individually.

= Where is my data sent? =
When using cloud LLM providers, conversation context and tool parameters are sent directly to your chosen provider via their official API (see External Services below). If you use Ollama or another local endpoint, AI requests go only to that endpoint (by default on your own server), not to a cloud provider. Optional services listed under External Services are used only if you turn them on.

= What is the WordPress Abilities API integration? =
On WordPress 6.9+, Agent Builder provides bidirectional integration: (1) **Outbound:** Registers agent tools as abilities under `agent-builder/` and `wp-extended/` namespaces for external MCP discovery. (2) **Inbound:** Automatically imports abilities exposed by other WordPress plugins so your agents can use them as tools, all protected by the same risk gate.

= What is the difference between Tools and Skills? =
**Tools:** Single, permission-controlled actions an agent can execute (e.g., `create_draft_post`, `get_site_health`). **Skills:** Pre-packaged instruction sets and tool workflows following the open `agentskills.io` standard that teach agents multi-step capabilities without writing code.

= What happens to my data if I delete the plugin? =
Uninstall keeps your data unless you check “Delete all plugin data” on the deactivation dialog. If you do choose to delete, conversation history, options, custom tables, and the agents and skills you created or imported are all removed.

= Is this plugin translated into other languages? =
The interface text is written in English, and the `.pot` translation template is included so you (or [translate.wordpress.org](https://translate.wordpress.org/)) can generate your own `.mo`/`.json` translation files for your site's locale. Pre-built translations for 11 languages ship with Agent Builder Pro.

== Screenshots ==

1. Tasks — Assign a job in plain English and the agent works in the background. Run details show the result and every step it took.
2. Waiting on you — When a step needs your OK the task pauses. Allow it once, for the session or always, or deny it.
3. Site Brief — A read-only scan of your site, turned into a ranked list of jobs, each assigned to the agent best suited to it. Nothing changes until you approve.
4. Routines — Run agents on a schedule or when something happens on your site, with test runs, pause and resume, and a run history.
5. Agents — Each agent has a profile with a name, title and avatar. Pin your favourites, assign a task, or duplicate or export an agent as a template.
6. Approval rules — Plain-English rules for what agents must ask you about, may do automatically, or must never do.
7. Skills — One shared skill library. Create a skill from a description, import a SKILL.md file, teach a task, or browse the community library.
8. Chat — Talk to any agent and see exactly what it looked at and which tools it used.
9. Safety Center — Risk inventory, approval status, audit-log verification and the emergency stop, all on one screen.
10. Activity — A tamper-evident timeline of everything your agents did, with search and CSV export.
11. Tools — Every tool an agent can use, with its risk level and an on/off switch.
12. Providers — Bring your own AI provider, such as OpenAI, Anthropic, Google Gemini, xAI, DeepSeek or Mistral, or run a local model with Ollama.

== External Services ==

This plugin connects to external AI APIs to process prompts and tool executions — no request to any AI provider is made until you configure or explicitly activate it. Optional catalog refresh from Agentic is off by default and only runs after you enable it in Settings → Security. See "Agentic Account & Platform Services" below for every Agentic endpoint, when it is used, and what is sent.

If you create approval rules, each tool call an agent proposes is also checked against them with your configured AI provider: a short description of the proposed action (the tool name, its arguments with passwords, keys and tokens removed, and your rule text) is sent to that provider to decide whether a rule applies. Nothing is sent for this when you have no approval rules.

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
  * Video Delivery (retrieving generated video files): `https://videos.agentic-plugin.com`
  * Music Search (Jamendo): the Video Generation endpoint above proxies royalty-free background-music searches to [Jamendo](https://www.jamendo.com/) — your search terms are relayed to Jamendo's catalog, not sent to Jamendo directly from your site.
* **When used:** Only when using Agentic managed AI credits or Pro cloud features.
* **Data sent:** Site URL, license key, prompt text, and task-specific media/document payloads.
* **Terms of Service:** [https://agentic-plugin.com/terms-of-service/](https://agentic-plugin.com/terms-of-service/) | [Jamendo Terms](https://www.jamendo.com/legal/terms-of-use) | [Jamendo Privacy](https://www.jamendo.com/legal/privacy)
* **Privacy Policy:** [https://agentic-plugin.com/privacy-policy/](https://agentic-plugin.com/privacy-policy/)

= Agentic Platform Services (Opt in) =
* **Endpoints:**
  * `https://agentic-plugin.com/wp-json/agentic/v1/model-pricing` — refreshes the LLM model/pricing catalog. Runs only after an administrator enables “Refresh model catalog from Agentic” in Settings → Security (off by default). Can also be triggered manually from the Costs page's "Get Latest Pricing" button. A plain GET request; no personal data is sent, and the response is cached locally.
  * `https://agentic-plugin.com/wp-json/agentic-marketplace/v1/agents` — fetches the marketplace agent catalog Site Brief uses to recommend the best-fit agent for a finding. Runs only during a Site Brief scan, and only after an administrator enables the same “Refresh model catalog from Agentic” setting in Settings → Security (off by default); when disabled, Site Brief only recommends already-installed/bundled agents. A plain GET request; nothing about your site or its findings is sent, matching happens entirely locally, and the response is cached locally.
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

= 4.4.0 - 2026-10-01 =
* New Tasks screen: hand a job to an agent and it runs in the background — low-risk steps happen automatically, anything that needs your judgement waits under "Waiting on you".
* Autonomous tasks: agents now run approved low-risk tools on their own, pausing only when a step needs your OK.
* New notifications: an inbox and admin-bar badge keep you informed when a task finishes, is waiting, or fails.
* Optional daily activity digest email for administrators summarizing what your agents did — on by default, configurable from Settings → Security.
* Anthropic: added the Claude 5.x family (Sonnet 5.5 as the fresh-install default, Opus 5.5, Fable 5.1, plus Sonnet 5 and Opus 5) and made forced tool choice resilient to the newer models rejecting it.
* Approval rules: write plain-English rules such as "Ask me first before publishing anything" or "Allow automatically: adding tags". "Ask first" always beats "Allow automatically", and high-risk tools still need your OK.
* Approval rules are now checked by your configured AI provider, so a rule only applies to the actions it describes (a "Never delete users" rule no longer stops an agent from listing them). "Never" rules now block the action outright and tell you which rule stopped it; if the check is unsure, the action waits for your approval instead. An optional reviewer model can be set with the agent_builder_reviewer_model option.
* Allow once, for this task, or always — and see and revoke every standing permission under Approvals → Grants.
* Agent profiles: give each agent a display name, title, avatar and standing instructions; pin, hide, duplicate, and export or import an agent as a template. Create a new agent from three fields.
* Routines: run an agent or skill on a schedule or when something happens on your site, with a test run, pause/resume, next run in your timezone and the last 20 runs.
* Skills: save a conversation as a skill, teach a task by demonstration, create one from a description, and invoke any skill with /skill-name in chat. Skills are shared across agents by default.
* Results arrive as cards (posts, files, tables, diffs), a live "Working…" pane shows each step as it happens, and voice dictation is available on HTTPS sites.
* Accuracy: the plugin no longer calls itself "safe by design". The readme now describes the safety controls, says plainly that no software can guarantee safety, and adds a short note that AI models are run by third-party providers you choose, that agents can make mistakes, and that you should keep your own backups and use a staging site.
* Accuracy: the Activity log is described as tamper-evident (edits and deletions are detectable), not tamper-proof.
* Accuracy: the Safety Center approvals hint now says actions that need approval wait there until you approve them, instead of "Nothing runs until you approve it".
* Accuracy: the default chat consent notice no longer says data is not shared with third parties. It now says messages are sent to the AI provider the site uses. Sites that saved their own consent text keep it.
* Accuracy: the readme now explains what backups cover (the last 3 snapshots per database table, only the affected rows for large tables) and that they do not replace a full site backup or a staging site. The Ollama answer now says AI requests stay on your endpoint instead of "100% of your data".

= 4.0.2 - 2026-09-27 =
* Maintenance: version alignment across the WordPress.org, self-hosted, and Pro editions. Ensures clean compatibility with Agent Builder Pro 4.0.2. No functional changes to this edition.

= 4.0.1 - 2026-09-26 =
Hardened activation: heavy setup now runs deferred and in the background, and a database/hosting hiccup during setup can no longer take a site offline. New SAFE MODE constant.
* Activation now only creates the (idempotent) database tables and default options — never fatal, and every step is guarded so a DB error degrades the plugin instead of the site.
* Heavy setup (bundled agents, ~276 tools, ~33 skills, demo knowledge) is deferred out of the activation request and runs chunked (one step per request) and lock-guarded on admin_init, so a fresh install stays usable while it fills in.
* New pre-flight check before any heavy work: a trivial database write/read round-trip plus PHP/MySQL version minimums. On failure the plugin activates in a reduced/safe state and shows a dismissible "finished setup in a reduced state — click to retry" admin notice instead of bursting into a struggling host.
* New `AGENT_BUILDER_SAFE_MODE` wp-config.php constant: define it as `true` to disable all background work (deferred seeding and cron) without deactivating the plugin — a one-line throttle for a struggling host or an admin locked out of wp-admin.
* Cron events are now lock-guarded against overlapping runs, and are skipped entirely under safe mode or a failed pre-flight check.

= 4.0.0 - 2026-09-20 =
Major release. The safety-first way to run AI agents on WordPress — agents that take real actions only after you approve them.
* Site Brief — a read-only scan that turns your site into a ranked list of approve-to-act jobs, each attributed to the agent that raised it. Nothing changes until you approve.
* Prompt Tests — replay a catalog of real owner requests against your agents; see what passed, what stopped for approval and what it cost, and get proposed improvements for your approval.
* Safety Center — one screen for risk inventory, approval gates, per-agent tool scopes, tamper-detection status, and an emergency stop.
* Agent-Ready Score and the opt-in WebMCP Bridge for AI-agent discoverability.
* Hardened throughout: hash-chained tamper-evident Activity log, a stronger approval write-path, and a consistent agent_builder_ prefix across constants, options, tables, hooks, and capabilities.

= 3.4.2 - 2026-09-20 =
* Added Site Brief: a read-only dashboard scan that turns this site into a ranked list of approve-to-act jobs. No new external services.

= 3.4.1 - 2026-09-19 =
* Fixed: the hosted Agentic free tier could not connect on a new site. The billing identity sent to the service was read from a setting nothing ever saved, so completing signup left the plugin still asking you to sign up. It now reads the key your account was issued.
* New: Prompt Tests. A ranked catalog of 54 real-world owner requests, a `wp agent prompt-test` command that replays them against the bundled agents and writes a markdown report, and three Assistant Trainer tools (run_prompt_tests, analyze_prompt_results, manage_prompt_catalog) so it can check the agents' coverage and propose improvements for your approval. Never runs unattended; real provider calls only when you start it, within a cost cap.

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

= 4.4.0 =
Adds Tasks, approval rules, agent profiles, Routines and skill drafting, and creates new database tables. Low-risk autonomous steps now run on their own; anything else waits for you under Tasks. Admins get a daily digest (Settings → Security).

= 3.3.0 =
React Settings, Tools, Approvals, and Knowledge hubs. No breaking changes for existing agents or API keys.

= 3.0.0 =
Contextual AI launchers across wp-admin, manage_cache tool, and bidirectional WordPress Abilities integration. No breaking changes.

= 2.9.272 =
WordPress.org Plugin Check compliance, guarded Abilities API adapter, multi-agent orchestration, and opt-in local memory.

== Privacy ==

See **External Services** for what is sent when you enable a cloud LLM or optional Agentic API. For Agentic product services, also see the [Agentic Privacy Policy](https://agentic-plugin.com/privacy-policy/) and [Terms of Service](https://agentic-plugin.com/terms-of-service/).