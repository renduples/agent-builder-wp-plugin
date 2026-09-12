# Publish

Publish is how you put agents in front of people — a chat bubble on the site, the WordPress toolbar, the block editor, a Gutenberg block, a shortcode, a schedule, or a WordPress event. The same surfaces can be turned on from the [Agent Creation wizard](https://agentic-plugin.com/docs/agent-wizard/) (**Publish Your Agent**); this page is where you manage them afterwards.

> The menu label and page heading are **Publish**. A **Basic** / **Advanced** pair applies to this page only. Basic is a chat with the bundled **Agent Orchestrator**. Advanced is seven technical tabs. The page is always in the Agent Builder menu — Basic/Advanced never hides it.

## Overview

Open **Agent Builder → Publish**. Heading: **Publish**. Description: *Manage how agents are invoked: embed them on your site with shortcodes, schedule recurring tasks, or react to WordPress events.*

**Basic** — Agent Orchestrator chat (no agent picker). **Advanced** — a left nav of deployment methods. [Agent Chat](https://agentic-plugin.com/docs/chat/) also links **Manage Agent Deployments** back here.

You need permission to manage agents. Refusal: **You do not have permission to access this page.**

## Publish sections

### Basic mode (Agent Orchestrator)

Instead of the seven tabs, Basic embeds a chat with **Agent Orchestrator**. That agent has tools for shortcodes, scheduled tasks, event listeners, admin-bar launchers, the editor sidebar, the frontend modal, and Gutenberg blocks — conversationally.

You get the same chat chrome as [Agent Chat](https://agentic-plugin.com/docs/chat/), locked to this agent (no agent dropdown):

- Icon and name **Agent Orchestrator**
- Welcome: *Hi! I'm the Agent Orchestrator. I handle putting your agents to work — chat widgets on your site, scheduled check-ins, automatic replies to things that happen on your site — all without the technical setup screens. What would you like to deploy?*
- **Try asking** — four starter prompts. Clicking one sends it immediately:
  - Put my Content Writer agent on the homepage as a chat widget.
  - Make my Site Health Sentinel check my site every morning and email me if something's wrong.
  - Set up an agent to reply to new comments automatically.
  - Add an AI panel to the block editor so I can chat with an agent while I write.
- Composer, **Send**, and (when those [Settings → Agents](https://agentic-plugin.com/docs/settings/) features are on) the usual audio / image / TTS controls
- In-thread approval cards when a change needs your OK

If Orchestrator is not available:

> The Agent Orchestrator is still activating. **Check the Agents page** or switch to Advanced above in the meantime.

Open [Agents](https://agentic-plugin.com/docs/agents/) and confirm Agent Orchestrator is active, then return — or use Advanced to configure surfaces by hand.

### Advanced mode (deployment tabs)

Switch **Advanced** (the page reloads). Left nav, A–Z by label (**Deployment methods**):

| Tab | What it configures |
| --- | --- |
| **Admin** | Toolbar **AI Agents** menu + slide-in chat, and compact **Ask AI** launchers on selected wp-admin screens |
| **Editor** | Gutenberg editor **sidebar** agent (and optional Magic Wand toolbar) |
| **Event Listeners** | Run an agent when a WordPress hook fires |
| **Frontend Modal** | Floating chat button on the public site |
| **Gutenberg Blocks** | Which agents appear in the Block Inserter |
| **Scheduled Tasks** | WP-Cron schedules with an AI prompt |
| **Shortcodes** | `[agentic_chat]` / `[agentic_ask]` deployments |

Default tab is **Admin**. Bookmarks such as `?tab=shortcodes` still work. Each tab’s panel title is that tab’s label. With seven sections, the left nav shows a **Search…** box; no matches: **No sections match your search.**

You do not need every field on every tab to publish an agent. For a first publish, use **Publish Your Agent** in the [wizard](https://agentic-plugin.com/docs/agent-wizard/), then come back here to fine-tune.

#### Admin

Two sections, one **Save Settings**.

**Admin bar chat** — *Choose which agents appear under “AI Agents” in the WordPress toolbar. Clicking an agent opens the slide-in chat overlay.*

Per active agent: **Enable**, **Agent**, **Position** (**Bottom Right** / **Bottom Left**), **Show On** (**Everywhere** / **Admin Only** / **Frontend Only**). Empty: **No active agents found. Activate at least one agent first.**

**Ask AI launchers** — *Show a compact “Ask AI” control on selected core admin screens. Launchers open the chat overlay with a screen-relevant starter prompt — nothing is sent until you press Send. Administrators only; each user can dismiss a launcher on the screen itself.*

- **Enable launchers** — *Show contextual Ask AI launchers on selected admin screens*
- **Screens** — *Choose where the launcher may appear. Uncheck all to hide launchers without turning the feature off globally.*
- **Default agent** — *Used for Ask AI launchers when that agent is active. Defaults to WordPress Assistant; pick a custom agent you trained if you prefer.* (tagged **recommended default** when it is)

**How admin chat works** under the form: toolbar vs launchers; nothing is sent until you press Send.

#### Editor

**Sidebar Agent** — *Add an AI agent panel inside the block editor… with full awareness of the current draft (title, content, post type, and status).*

- **Enable Sidebar Agent** — *Show an AI agent panel in the Gutenberg block editor*
- **Agents** — **Show in sidebar** and **Pre-selected** (the pre-selected agent opens by default; you can switch in-sidebar when more than one is ticked)
- **Post Types** — leave all unchecked to hide the sidebar everywhere
- **Confirmation Mode** — **Autonomous — execute actions immediately (recommended)** or **Supervised — medium-risk actions create a proposal before executing**
- **Context Injection** — *Automatically share the current draft title and content with the agent on the first message* (sent only when you open the chat)
- **Content Writer Toolbar** — *Enable the inline AI toolbar (Magic Wand)* so a wand on the block toolbar opens or closes the sidebar agent

**Save Settings**.

#### Event Listeners

**Add New Trigger** — *When the chosen WordPress event fires, the selected agent will be invoked asynchronously with the prompt you provide plus the event context.*

Fields: **Agent** (**— choose an agent —**; *No agents installed. Install an agent first.*), **WordPress Event** (**— choose an event —**, or **Custom hook name…**), **Prompt**, **Label**, **Priority** (default 10). **Add Trigger**.

Table: Agent, Trigger / Label, WordPress Hook, Priority, Mode (**AI Async** or **Direct**), Source (**Custom** / **Built-in**), **Remove**. Empty: **No event listeners active. Add a trigger above or activate agents that define built-in listeners.**

Custom triggers queue an async AI task and do not block the page load.

#### Frontend Modal

*Deploy a floating chat button on your public website.* Also shows the current chat **Theme** with a **change** link to [Settings → Interface](https://agentic-plugin.com/docs/settings/).

Per active agent: **Enable**, **Agent**, **Position** (**Bottom Right** / **Bottom Left**), **Show On** (**All Pages** / **Homepage Only** / **Posts & Pages** / **Frontend Only**), **Require Login**. **Save Settings**. Empty: **No active agents found. Activate at least one agent first.**

If several agents are enabled on the same page, visitors get a picker; a single agent opens directly. The modal injects page context (URL, title, post type). Chat history is per agent in the visitor’s `localStorage`.

#### Gutenberg Blocks

*Select which agents should appear as blocks in the Gutenberg Block Inserter. Enabled agents can be placed into any post, page, or template — including the Site Editor.*

Table: **Enable**, **Agent**, **Block Name** (`agentic/{slug}`). **Save Settings**. Empty: **No active agents found. Activate at least one agent first.**

Enabled agents appear under the **Agentic AI** category in the inserter (+). Block sidebar options (height, theme, style, provider, and more) are in the editor, not on this tab.

#### Scheduled Tasks

**Add Scheduled Task** — *Run any active agent on a WP-Cron schedule with an AI prompt. Built-in tasks from agents also appear in the table below.*

Fields: **Agent** (**— choose an agent —**; *No agents active. Activate an agent first.*), **Schedule**, **Prompt**, **Label**, **Description**. **Add & Schedule**.

Table: Agent, Task, Schedule, Mode (**AI** vs **Direct**), Source (**Custom** / **Built-in**), Status (**Active** / **Not Scheduled**, plus **overdue** when the next run is late), Next Run, Last Run, Actions (**Run Now**, **Schedule** / **Unschedule**, **Delete**). Empty: *No scheduled tasks yet. Add one above, or activate agents that define built-in scheduled tasks (for example Site Auditor or AI Radar).*

WP-Cron runs when someone visits the site. Custom tasks you add are scheduled immediately and run through the LLM. Built-in tasks can be scheduled or unscheduled without deleting the definition. Every run is logged in [Activity](https://agentic-plugin.com/docs/activity/).

#### Shortcodes

**Shortcode Deployments** table: Label, Agent, Style, Source (**Admin** / **Auto-detected** / **Hardcoded**), Status (**Enabled** / **Disabled**), Shortcode, Created, **Enable** / **Disable**, and (admin-created rows only) **Edit** / **Delete** (delete confirms *Remove this shortcode deployment?*). Empty: *No shortcode deployments yet. Use the form below to create one, or place [agentic_chat] in a page — it will be auto-detected here on first load.*

**Create New Deployment** (or **Edit Deployment** with **← Back**): **Label**, **Agent** (**— Select Agent —**), **Display Style** (**Inline — embedded in page content**, **Popup — floating button that opens a chat window**, **Sidebar — compact widget for sidebars**), **Chat Height**, **Header** (*Show agent name and icon header*), **Placeholder Text**, plus a **Shortcode preview**. **Create Shortcode** or **Save Changes**. No agents: **No active agents.** / **No active agents. Activate at least one agent before creating a shortcode deployment.**

Two shortcodes: `[agentic_chat]` (full interactive chat) and `[agentic_ask]` (one-shot inline reply). Theme/plugin hard-coded shortcodes can show as Auto-detected or Hardcoded.

## Common tasks

### Publishing an agent without the technical tabs

1. Open **Publish** in **Basic**
2. Ask Agent Orchestrator to put a named, **active** agent on a surface (for example a homepage chat widget)
3. Approve any in-chat proposal if one appears
4. Switch to **Advanced** later if you need the exact position, screens, or shortcode

Or run **Publish Your Agent** from the [wizard](https://agentic-plugin.com/docs/agent-wizard/) after you create an agent.

### Adding a floating chat button (Advanced)

1. Open **Publish**, set **Advanced**
2. Open **Frontend Modal**
3. Tick **Enable** on the agent, pick **Position** and **Show On**
4. Tick **Require Login** if it should not appear to logged-out visitors
5. **Save Settings**

### Showing an agent in the WordPress toolbar

1. Advanced → **Admin**
2. Under **Admin bar chat**, enable the agent and set **Position** / **Show On**
3. **Save Settings**

For compact prompts on Plugins, Media, and similar screens, use **Ask AI launchers** on the same tab.

### Scheduling a recurring check

1. Advanced → **Scheduled Tasks**
2. Choose an **Agent**, **Schedule**, and **Prompt**
3. **Add & Schedule**
4. Use **Run Now** to test, **Unschedule** to pause a built-in task, or **Delete** to remove a custom one

### Embedding chat in a page

1. Advanced → **Shortcodes**
2. Fill **Create New Deployment** and **Create Shortcode**
3. Paste the preview shortcode into a page, post, or text widget

Or enable the agent under **Gutenberg Blocks**, then insert `agentic/{slug}` from the Block Inserter.

## FAQ

**Q: Where did the tabs go?**

A: They are in Advanced mode. Click **Advanced** at the top right of this page (not the site-wide switch in the header). Basic mode is Agent Orchestrator chat only.

**Q: Agent Orchestrator isn’t showing.**

A: The notice is **The Agent Orchestrator is still activating.** with **Check the Agents page**. Activate it on [Agents](https://agentic-plugin.com/docs/agents/), or use Advanced.

**Q: Does Basic change the same settings as Advanced?**

A: Yes. Orchestrator’s tools write the same storage the tabs use. Advanced is the form UI for those same surfaces. The [wizard](https://agentic-plugin.com/docs/agent-wizard/) **Publish Your Agent** page is a third front door to four of them (chat widget, admin bar, Ask AI, Gutenberg block).

**Q: Why must the agent be active first?**

A: Almost every tab refuses empty agent lists (*No active agents found. Activate at least one agent first.*). Activate on [Agents](https://agentic-plugin.com/docs/agents/) in Advanced, then return.

**Q: What’s the difference between Frontend Modal, Shortcodes, and Gutenberg Blocks?**

A: **Frontend Modal** is a site-wide floating button (with position and which pages). **Shortcodes** are pasted into specific content (`[agentic_chat]` / `[agentic_ask]`). **Gutenberg Blocks** put the same chat in the Block Inserter as `agentic/{slug}`.

**Q: What’s the difference between this page’s Basic / Advanced and the site-wide switch?**

A: This page’s toggle changes only Publish (Orchestrator vs the seven tabs) and is remembered per user. The site-wide switch in the admin header is the default for every Agent Builder screen.

**Q: Who can open this page?**

A: Administrators, and anyone granted permission to manage agents. Everyone else gets “You do not have permission to access this page.”
