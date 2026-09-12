# Settings

Settings is where you choose how the admin looks, which AI provider agents use, who may open the plugin, default agent mode and retention, and (in Advanced) APIs, service URLs, and MCP. Changes apply to new conversations.

> Open **Agent Builder → Settings**. There is no single Basic / Advanced pair on the page title. Instead: a left nav, a **Search settings…** box, and **Advanced settings** at the bottom of the nav (which reveals APIs, Endpoints, and MCP). **Interface**, **Users**, and **Security** each have their own Basic / Advanced switch (tooltip: “Only changes this screen. Other screens and your site-wide default (Settings → Interface) are unaffected.”). Adding or editing a provider opens a separate form with its own Basic / Advanced pair.

## Overview

The heading is **Settings**. Default tab: **Interface**.

Left nav, **Basic** group (always listed):

- **Interface**
- **Agents**
- **Providers**
- **Users**
- **Security**

**Advanced** group (hidden until you click **Advanced settings**, search, or open a deep link such as `tab=mcp`):

- **APIs**
- **Endpoints**
- **MCP**

**Advanced settings** turns the Settings page’s own mode on and jumps to **APIs** if you were on a Basic tab. **Hide advanced settings** turns it off and jumps back to **Interface** if you were on APIs / Endpoints / MCP. That hatch is independent of the site-wide switch and of the per-tab toggles on Interface / Users / Security.

**Search settings…** filters the nav by tab name (or slug). Searching is enough to reveal the Advanced group even if the hatch is off.

While it loads: **Loading settings…**. Failure: **Could not load settings.** / **Settings unavailable.** Save failure: **Could not save settings.** Success: **Settings saved.** The save button on tabs that have one is **Save changes** (busy: **Saving…**). If a save returns warnings, an alert: **Settings saved with warnings:** plus the warning lines.

Provider keys and the add/edit form still use the classic PHP page (`?add_provider=1` / `?edit_provider=`). The React list only links into that form.

## Settings sections

### Interface

Panel **Interface**, with this tab’s own Basic / Advanced switch.

- **Default interface mode** — **Basic** or **Advanced**. Help: *Starting point for screens you have not switched individually. Basic is guided and uses plain language; Advanced shows every control.* This is the site-wide default other screens fall back to.
- **Reset all screens to my default** — confirms: *Reset every screen back to your default interface? Any screen you switched individually will lose that override.*

**Chat theme** — *Visual theme for admin chat and frontend shortcodes. Click a preview to select.* Four preview cards (each shows “AI Agent”, “Hello! How can I help?”, “Update my homepage”):

| Theme | Description |
| --- | --- |
| **Light** | Clean white with WordPress blue accents — the default. |
| **Dark** | Deep purple dark theme. |
| **Midnight** | Pure dark with emerald green accents. |
| **Ocean** | Deep blue with teal highlights. |

**Advanced on this tab** also shows:

- **How agents address people** — points at [Knowledge](https://agentic-plugin.com/docs/knowledge/) for site knowledge, and Knowledge → Instructions for per-agent personality. Fields: **What should agents call administrators?** (blank = WordPress display name), **What should agents call frontend visitors?**
- **Chat font** — Theme default, System UI, Arial / Helvetica, Georgia, Times New Roman, Courier New (monospace), Verdana
- **Use theme accent colour** — off reveals **Accent colour**
- **Show the Getting Started checklist on the dashboard**

**Save changes** writes the tab (including the site-wide default mode).

### Agents

Not the [Agents](https://agentic-plugin.com/docs/agents/) list. This tab is chat capabilities and per-agent provider/model.

Lead: *Chat capabilities and per-agent provider/model. Set the site default provider on the Providers tab. Theme is under Interface.*

**Chat features** (*Features available in agent chat.*):

- **Audio input**
- **Text-to-speech**
- **Vision / image input**
- **Hide “Powered by Agent Builder” branding** — on by default; uncheck only if you want a small credit line in the chat footer
- **Show the WhatsApp Pro promo in admin chat** — off by default

**Active agent overrides** — each row starts on the site default from Providers. Change only agents that need a different **Provider** (connected providers; the default is tagged **(default)**) and **Model**. Empty: **No active agents.**

**Disable All Agents** — labeled **Emergency stop**: deactivate and log agent states, cancels all jobs and disconnects providers. Confirm on: *EMERGENCY STOP: deactivate and log agent states, cancel all jobs, and disconnect providers. Continue?* Confirm off: *Turn off emergency stop?* The same switch lives on the [Dashboard](https://agentic-plugin.com/docs/dashboard/) and [Safety Center](https://agentic-plugin.com/docs/safety-center/).

**Save changes**.

### Providers

Lead: *Configure the AI providers available on this site. Only a connected provider can be set as the site default.*

**Add Provider** opens the classic add form (not an in-list modal).

Table: **Status** (**Connected** / **Not connected**), **Name** (plus **built-in** when it ships with the plugin), **Slug**, **Model**, **Actions** (**Edit**; **Default** badge; or **Set Default** when connected and not already default).

Note under the table: *API keys: use Edit on a provider. Setting a default updates the global provider and model used by agents.* There is no Test button on this list (the [Dashboard](https://agentic-plugin.com/docs/dashboard/) **Connected Providers** card has **Test**).

#### Add / Edit Provider form

Opens as a full page. Heading **Add Provider** or **Edit Provider**. Its own **Basic** / **Advanced** pair (this form only).

Add copy: *Use an endpoint that speaks the OpenAI Chat Completions format (many hosts expose this as /v1/chat/completions).*

**Always visible**

- **Name**
- **API Key** (password field; omitted only when Auth Type is None). Stored key: *Key is stored. Leave blank to keep the current key, or enter a new value to replace it.* Empty: *Your API key for this provider. Stored securely in the database.* Optional **Get a key ↗** when the provider has a key URL

**When adding, or when this form is Advanced**

- **Slug** — lowercase letters, numbers, hyphens, underscores; cannot change after creation; built-in slugs are read-only
- **Endpoint URL** — `%MODEL%`, `%KEY%`, `%OLLAMA_URL%` placeholders as described on the field

**Advanced on this form**

- **Icon URL**
- **Default Model** (dropdown or text; **↺ Refresh** fetches the live list)
- **Available Models** (one per line)
- **Vision Model**
- **Auth Type** — Bearer token (Authorization: Bearer {key}); Anthropic (x-api-key header); Key in URL (%KEY% placeholder); None — no auth header (e.g. Ollama)
- **Request Format** — OpenAI-compatible (default); Anthropic (messages + system); Google Gemini (contents/parts); Agentic / Ollama native (/api/chat)
- **Response Format** — OpenAI (no normalisation needed); Cohere v2 (auto-normalised); Ollama /api/chat (auto-normalised)
- **Get Key URL**

Buttons: **Add Provider** or **Update Provider**, and **Cancel** (back to the list). Success: **Provider saved successfully.**

To connect a provider through a guided flow instead, use the [Setup Wizard](https://agentic-plugin.com/docs/setup/).

### Users

This tab’s own Basic / Advanced switch.

**Basic** — a chat with the bundled **User Assistant** (no role matrix). Same embed pattern as Skills / this tab’s Advanced swap:

- Agent icon and name, its welcome message
- **Try asking** — four starters from that agent (overview of users, privileged accounts to review, re-engagement email, which roles can access settings and chat)
- Composer placeholder **Ask User Assistant…** — Enter sends; Shift+Enter newline; **Send**
- While it works: **User Assistant is thinking…**
- In-thread **Proposed Change** / **Needs Your Approval** cards like [Agent Chat](https://agentic-plugin.com/docs/chat/)

If the assistant is not available: **User Assistant is still activating.** with **Check the Agents page**.

Description in Basic: *Control who can administer the plugin and use AI agents.*

**Advanced** — two matrices. Lead: *Control which WordPress roles can administer the plugin and interact with AI agents. Administrators always retain full access.* A user needs at least one ticked plugin box to see the corresponding feature; clearing every plugin privilege for a role hides the Agent Builder menu from that role.

**Plugin Administration** — who can open admin pages:

| Permission | What it is for |
| --- | --- |
| **View Dashboard** | Dashboard overview and stats |
| **Manage Agents** | Install, activate, deactivate, edit, and delete agents |
| **View Audit Log** | Activity / audit log |
| **Configure Tools** | Enable or disable tools |
| **Run Tasks Manually** | Trigger scheduled tasks by hand |
| **Manage Plugin Settings** | Providers, keys, caching, security — all Settings tabs |

Administrator columns are always ticked and disabled (*Administrators always have full access*).

**AI Agents** — who can chat, plus daily limits. Extra **Visitor** column (*Not logged in*) only on **Chat on the Frontend** (that checkbox is **Allow non-logged-in visitors to use frontend chat**).

| Permission | What it is for |
| --- | --- |
| **Chat on the Frontend** | Page-embedded chat / `[agentic_chat]` |
| **Chat in the Admin Bar** | Toolbar overlay |
| **View Installed Agents** | Browse installed agents in admin |
| **Use Premium / Uploaded Agents** | Interact with marketplace / uploaded agents |

**Daily Limits** — *0 = unlimited, resets at midnight UTC. Administrators are never limited.*

- **Queries / day** — max AI chat messages per user per day
- **Tokens / day** — max AI tokens per user per day

Note at the bottom: these rules are enforced on the admin menu, admin-bar chat, REST chat, and AJAX task triggers — unchecked roles are blocked on the server. Request-per-minute throttles are on **Security**.

**Save changes**.

### Security

This tab’s own Basic / Advanced switch.

- **Default agent mode**
  - **Supervised** — *Agents propose actions for you to approve before anything changes. Recommended for most sites.*
  - **Autonomous** — *Agents act immediately without waiting for your OK. Use only if you trust them on this site.*
  - **Read-only** — *Agents can chat but cannot change anything on the site.*
- **Message scanning** — *Blocks common injection patterns and flags personal data in chat messages.*
- **Require chat consent** — *Ask visitors to agree before chatting. Required in some regions.* When on: **Consent text**
- **Conversation retention (days)** — *How long to keep chat history. 0 means keep forever.* (default 30)

**Advanced on this tab** also shows:

- **Audit log retention (days)** (default 30)
- **Refresh model catalog from Agentic** — off by default; a daily cron of names and list prices from agentic-plugin.com. BYOK providers work without this
- **Request Rate Limits** — *IP-based throttle applied before role-based daily quotas.* **Authenticated users (requests per minute)** (default 30), **Anonymous visitors (requests per minute)** (default 10)

Comfort profiles on [Approvals](https://agentic-plugin.com/docs/approvals/) also set mode. This tab is the explicit **Default agent mode** control.

**Save changes**.

### APIs (Advanced settings)

Lead: *Third-party API keys used by agent tools.*

Table: **Service**, **Status** (**Configured** / **Not configured**), **Key** (masked). On this WordPress.org build the row is **Google PageSpeed Insights**, with **Get a key** (Google’s docs, new tab). This tab is a status list — it has no save field.

### Endpoints (Advanced settings)

Lead: *REST and webhook endpoints for integrations. Full editor remains available via Advanced tools.*

- **Namespace:** `agentic/v1`
- **Base URL:** this site’s REST URL for that namespace

**Agentic Services** — *Base URLs the plugin calls for AI chat, knowledge search, and media generation. Leave these on their defaults unless you’re self-hosting a proxy or support asked you to change one.* Each service: URL field, **Test** (busy: **Testing…**; result ✓ / ✗ plus the message; failure fallback **Test failed.**). If the URL is custom: **Default:** the shipped URL and **Reset to default**.

**Save changes**.

### MCP (Advanced settings)

MCP (Model Context Protocol) lets external apps — Claude Desktop, Cursor, and others — use your agents, including tool calls. Copy on the first card: it is free on every WordPress version this plugin supports — no Pro or connector required. Status: **Available**.

**Agent endpoints** — *Each agent has its own MCP URL, scoped to only the tools that agent is allowed to use.*

| Column | What it shows |
| --- | --- |
| **Agent** | Name |
| **MCP URL** | `…/wp-json/agentic/{slug}/mcp` plus **Copy** (**Copied!** for two seconds) |
| **Enabled** | Per-agent switch (`Enable MCP: {name}` for screen readers). Off by default |
| **Status** | **Ready**, or a reason (commonly **Agent is not active.**, **No abilities.json manifest for this agent.**, **Manifest signature mismatch — this agent's tools are blocked until it is re-signed.**, **MCP is off by default for this agent — enable it in Settings > MCP.**) |
| **Connected** | Last connection, or **Never connected** |
| (last) | **Test** (disabled until Ready; busy **Testing…**; ✓ / ✗ plus message) |

The [Agents](https://agentic-plugin.com/docs/agents/) Advanced table’s MCP column is a status light for the same switch — it does not turn MCP on.

**Connected clients** — badges for clients that completed the approval flow, or *No clients have connected via the approval flow yet.*

**Application Passwords** — *Credentials for manually configuring an MCP client (e.g. Cursor) that needs a username and password rather than the browser-driven approval flow.*

- Table: **User**, **Created**, **Last used** (**Never** if unused), **Revoke** (confirms: *Revoke this Application Password? Any client using it will stop working immediately.*)
- Empty: **No Application Passwords yet.**
- **Create Application Password** — success: *Password created — copy it now, it will not be shown again:* plus the password and **Copy**. Failure: **Could not create credential.**
- If any medium-risk write tools would run without a per-action prompt: a warning that unlike chat, MCP has no per-action confirmation step, listing those tools

## Common tasks

### Switching the whole admin to Advanced

1. Open **Settings → Interface**
2. Set **Default interface mode** to **Advanced**
3. **Save changes**

Screens you already flipped individually keep their override. Use **Reset all screens to my default** to clear those.

### Connecting or changing a provider key

1. Open **Settings → Providers**
2. Click **Edit** on the provider (or **Add Provider** for a custom one)
3. Paste the **API Key**
4. In Advanced on that form, set **Default Model** if you need to
5. **Update Provider** / **Add Provider**
6. Back on the list, **Set Default** if this should be the site default

Or run the [Setup Wizard](https://agentic-plugin.com/docs/setup/) from the Dashboard.

### Turning MCP on for one agent

1. Open **Settings** and click **Advanced settings** (or search for MCP)
2. Open **MCP**
3. Flip **Enabled** for that agent
4. Wait until **Status** is **Ready**
5. **Copy** the MCP URL into the client, or **Create Application Password** if the client needs a username and password
6. Optionally **Test**

The agent must be active with a valid signed `abilities.json`. [Safety Center](https://agentic-plugin.com/docs/safety-center/) and Agents → MCP explain signature mismatch.

### Stopping every agent immediately

1. Open **Settings → Agents** (or Dashboard / Safety Center)
2. Turn on **Disable All Agents**
3. Confirm the Emergency stop dialog

Turn the same switch off (and confirm *Turn off emergency stop?*) to restore.

### Letting a role open Activity but not Settings

1. Open **Settings → Users**
2. Switch that tab to **Advanced**
3. Under **Plugin Administration**, tick **View Audit Log** for the role and leave **Manage Plugin Settings** clear
4. **Save changes**

### Keeping chats for 90 days

1. Open **Settings → Security**
2. Set **Conversation retention (days)** to `90`
3. **Save changes**

Audit-log retention is the separate Advanced field on the same tab.

## FAQ

**Q: Where did APIs / Endpoints / MCP go?**

A: Click **Advanced settings** at the bottom of the left nav (or type `mcp` in **Search settings…**). **Hide advanced settings** tucks that group away again. That hatch is only this Settings page — it is not the site-wide Basic / Advanced switch.

**Q: Why are there two Basic / Advanced controls?**

A: The hatch shows or hides developer tabs (APIs, Endpoints, MCP). The switch on **Interface**, **Users**, or **Security** only changes *that tab’s* body (for example Users: User Assistant chat vs the role matrix). Provider add/edit has a third switch that only applies to that form.

**Q: I clicked Save on Providers and nothing happened.**

A: The Providers *list* has no Save bar. Keys and models are saved on **Add Provider** / **Update Provider**. **Set Default** is a separate action on the list.

**Q: Is MCP a Pro feature?**

A: No. The MCP tab says it is free on every WordPress version this plugin supports. It is listed under Advanced settings because it is a developer integration, not because it is paywalled.

**Q: Why is Status not Ready after I enable MCP?**

A: Hover/read the reason. The agent must be active, have an `abilities.json`, and that manifest’s signature must match. Enable is off by default even for active agents.

**Q: What’s the difference between Default agent mode here and Comfort on Approvals?**

A: **Security → Default agent mode** is Supervised / Autonomous / Read-only. [Approvals](https://agentic-plugin.com/docs/approvals/) Comfort cards also set Supervised or Autonomous (and email alerts). Use either; they write the same kind of mode setting. Extreme-risk tools stay blocked either way.

**Q: Where did Instructions and Memory go?**

A: They are on [Knowledge](https://agentic-plugin.com/docs/knowledge/) (Instructions and Memory sections in Advanced). Old `tab=instructions` / `tab=memory` URLs open **Interface**.

**Q: Who can open this page?**

A: Administrators, and anyone granted **Manage Plugin Settings**. Everyone else gets “You do not have permission to access this page.” Creating MCP Application Passwords is administrators only.
