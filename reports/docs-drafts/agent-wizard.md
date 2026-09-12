# Agent Creation wizard

The Agent Creation wizard is three hidden guided pages that take you from a new custom agent, through teaching it, to putting it in front of people: **Train an Agent**, **Add Knowledge**, and **Publish Your Agent**. None of them appear in the Agent Builder sidebar. They share the same stepper-and-card layout; they are not three tabs on one screen.

> There is no Basic / Advanced switch on these pages — the wizard *is* the guided path. Fine-tuning afterwards lives on [Agents](https://agentic-plugin.com/docs/agents/), [Knowledge](https://agentic-plugin.com/docs/knowledge/), [Publish](https://agentic-plugin.com/docs/publish/), and [Settings](https://agentic-plugin.com/docs/settings/). **Add Knowledge** is the same wizard you can open from the Knowledge page (**Add knowledge** / **Guided setup**).

## Overview

How the three pages connect:

1. **Train an Agent** (`agentic-agent-wizard`) — create and (usually) activate a custom agent. Open it from [Agents](https://agentic-plugin.com/docs/agents/) → **Add an agent**, or from the Dashboard Quick Action **Train an Agent**.
2. **Add Knowledge** (`agentic-knowledge-wizard`) — save one wiki concept (paste, upload `.txt`/`.md`, or pick existing pages). After Train an Agent, **Add Knowledge** on the success card opens the [Knowledge](https://agentic-plugin.com/docs/knowledge/) page; start this wizard from there (**Add knowledge** in Basic, **Guided setup** in Advanced), or from the Dashboard Getting Started item **Add knowledge**.
3. **Publish Your Agent** (`agentic-deploy-wizard`) — turn on one surface (chat widget, admin bar, Ask AI launcher, or Gutenberg block). After Train an Agent, **Publish This Agent** opens this page with that agent pre-selected (`?agent=`).

Browser titles: **Train an Agent**, **Add Knowledge**, **Publish Your Agent**. Loading copy: **Loading the agent wizard…** / **Loading the knowledge wizard…** / **Loading the publish wizard…**. Without JavaScript, each page warns that the wizard needs JS and points back to Agents, Knowledge, or Publish.

Train an Agent and Publish Your Agent require an administrator (`manage_options`). Add Knowledge allows anyone who can manage plugin settings.

They do not remove or replace bundled agents. Creating an agent writes files and a signed `abilities.json` the same way other admin tools do; the event shows up in [Activity](https://agentic-plugin.com/docs/activity/) as **Agent created**.

## Agent Creation wizard sections

### 1. Train an Agent

Heading: **Train an Agent**. Intro: *Create a new AI agent in a few quick steps. You can refine everything later from the Agents and Settings pages.*

Five steps in the strip (**Wizard steps**): **Basics** → **Persona** → **Capabilities** → **Knowledge** → **Review**. Footer: **Back** / **Continue**, then **Create agent** on Review. Load failure: **Could not load the wizard.** Create failure: **Something went wrong creating the agent.**

#### Basics

- **Agent name** — *A friendly name, e.g. "Support Helper".* Required
- **Identifier (slug)** — *Used in URLs and files. Auto-filled from the name.* Required; editing it marks it as custom so further name changes do not overwrite it
- **What does this agent do?** — *A short description shown in the agents list.* Required
- **Category** — content, admin, ecommerce, frontend, developer, seo, security, media, support (first letter capitalized in the list, so **Seo** not SEO). Default **admin**
- **Icon** — default 🤖

**Continue** stays disabled until name, description, and slug are filled.

#### Persona

- **Instructions (system prompt)** — *Describe how the agent should behave, its tone, and what it should focus on.*
- **Welcome message (optional)** — *The greeting shown when a chat starts.* The create request does not persist this field; a generated greeting (`Hi! I'm {name}…`) is stored instead. Set the live welcome afterwards on [Knowledge → Instructions](https://agentic-plugin.com/docs/knowledge/).
- **Suggested prompts (optional)** — three fields to start (**Suggested prompt 1** …), placeholder *e.g. Summarize my latest posts*. **+ Add another prompt** up to six. Empty ones are dropped; if you leave all blank, the create tool fills three defaults (*What can you help me with?*, *{description} — where should I start?*, *Show me what you can do on this site.*)

You can leave persona fields blank and edit them later on Knowledge → Instructions.

#### Capabilities

- **AI provider** — your configured providers (unconfigured ones are tagged **(needs API key)**). Help: *Leave as-is to use your site default.*
- **Model** — that provider’s list, when it has one
- **Autonomy**
  - **Supervised — asks before taking actions (recommended)** (default)
  - **Autonomous — acts on its own**
  - **Disabled — replies only, never acts**
- Hint: *This only controls how often the agent pauses to ask you. Higher-risk actions still queue for your approval, and the riskiest actions are always blocked, no matter which autonomy level you pick.*
- **Tools this agent can use (optional)** — checkboxes (name + description). Hint: *If you don’t pick any, a safe set of read-only tools is added so your agent works right away.* That default set is `search_content`, `list_posts`, `get_post_content`, `get_site_context`.

Provider and model here become per-agent overrides (same idea as [Settings → Agents](https://agentic-plugin.com/docs/settings/)). Site default still comes from Providers.

#### Knowledge (inside Train an Agent)

On this WordPress.org build the step is an info notice, not an upload form:

> Knowledge uses the Agentic AI service. Connect your license to train agents on your content. **Add knowledge later from the Knowledge page.**

The license flag for in-wizard vector training is off in this build. Use **Add Knowledge** (the next page in this doc) or [Knowledge](https://agentic-plugin.com/docs/knowledge/) after the agent exists. You can **Continue** past this step with nothing selected.

#### Review

Read-only rows: **Name** (icon + name), **Identifier**, **Description**, **Category**, **Instructions** (or **(none)**), **Provider / Model**, **Autonomy**, **Tools** (or **(none)**), **Knowledge** (or **(none)**). **Create agent** (busy while it runs).

#### After a successful create

Heading: **{icon} {name} is ready!**

- If activated: *Your agent has been created and activated. Add knowledge to make it smarter, or start chatting right away.*
- If not: *Your agent was created. Activate it from the Agents page, then add knowledge or start chatting.*

Optional notices:

- Default tools: *A safe set of read-only tools was added so your agent works right away. You can change its tools later from the Agents page.*
- A **warning** from the create tool, when there is one

Buttons:

| Button | Where it goes |
| --- | --- |
| **Add Knowledge** (primary) | The [Knowledge](https://agentic-plugin.com/docs/knowledge/) page (not this wizard’s Add Knowledge URL — start the wizard from that page) |
| **Open Chat** | [Agent Chat](https://agentic-plugin.com/docs/chat/) (no `agent=` in the URL — pick the new agent in the chat header if it is not already selected) |
| **Publish This Agent** | **Publish Your Agent** with this agent selected |
| **Fine-tune Persona** | Settings (legacy Instructions URL; per-agent welcome and persona notes are on Knowledge → Instructions) |
| **View all Agents** | [Agents](https://agentic-plugin.com/docs/agents/) |

### 2. Add Knowledge

Heading: **Add Knowledge**. Intro: *Teach your agents a fact, policy, or FAQ in a few quick steps. You can add more or fine-tune it later from the Knowledge page.*

This is the free wiki path (the same OKF concept format as the Knowledge editor). It is **not** the licensed vector-training step inside Train an Agent.

Two steps: **Source** → **Title & tags**. **Back** / **Continue**, then **Save knowledge**. Save failure: **Something went wrong saving this knowledge.**

#### Source — *Where is this knowledge coming from?*

Three source buttons:

**Paste text** — **Paste the text**. Help: *A fact, policy, FAQ answer — anything you want your agents to know is true.*

**Upload a file** — **Upload a text or Markdown file** (`.txt`, `.md`, `.markdown`). Other types: **Please choose a plain text or Markdown file (.txt or .md).** Read error: **Could not read that file.** After a good file: *Loaded "{name}" — you can review and edit the text below.* plus **File contents**. The title is prefilled from the filename if you have not set one.

**Pick existing pages** — **Search your pages and posts** (placeholder *Leave blank to see recent pages*). Checkboxes show `title (type)` and excerpt. Count: *{n} page(s)/post(s) selected.* Empty: **No matching pages or posts.** Search error: **Could not load pages.**

**Continue** stays disabled until paste/upload has text, or at least one page is selected.

#### Title & tags

- **Title** (required) — *A short, clear name, e.g. "Return policy" or "Support hours".*
- **Tags (optional)** — *Comma-separated, e.g. billing, returns, policy.*

**Save knowledge** stays disabled without a title.

#### After a successful save

**📚 "{title}" is saved!** *Your agents can now use this. Add another, or review it in the full Knowledge Wiki.*

- **Add another** (primary) — resets the wizard
- **View Knowledge Wiki** — [Knowledge](https://agentic-plugin.com/docs/knowledge/)
- **Back to Dashboard**

Wizard concepts are not marked Example. Long pasted or page content is truncated so one concept stays a usable size.

### 3. Publish Your Agent

Heading: **Publish Your Agent**. Intro: *Get an agent in front of people in a few quick steps. You can fine-tune it later from Publish.*

This writes the same storage as the [Publish](https://agentic-plugin.com/docs/publish/) tabs. It never turns a surface off for some other agent already using it.

Two steps: **Agent & surface** → **Configure**. **Back** / **Continue**, then **Publish**. Load failure: **Could not load the wizard.** Save failure: **Something went wrong publishing this agent.**

No active agents:

> No active agents yet. Activate or train an agent first, then come back to publish it. **Go to Agents**

#### Agent & surface

- **Which agent?** — active agents (icon + name). Pre-selected when you arrived from **Publish This Agent**
- **Where should people reach it?** — four surfaces (click to select; hint shows under the active one):

| Surface | Hint |
| --- | --- |
| **Chat widget** | A floating chat bubble visitors can open on your site. |
| **Admin bar** | A quick-chat menu in the WordPress toolbar for logged-in admins. |
| **Ask AI launcher** | A contextual "Ask AI" prompt on admin screens like Plugins or Media. |
| **Gutenberg block** | Makes this agent insertable as a block in any post or page. |

**Continue** stays disabled until an agent is selected.

#### Configure

Depends on the surface:

**Chat widget** / **Admin bar**

- **Position** — **Bottom right** / **Bottom left**
- **Show on**
  - Admin bar: **Everywhere**, **Admin only**, **Front end only**
  - Chat widget: **Everywhere**, **Front end only**, **Single posts/pages only**, **Homepage only**
- Chat widget only: **Only show to logged-in visitors**

**Ask AI launcher**

- If launchers are not configured yet: **Which admin screens?** plus a checkbox per screen (label + description)
- If they already are: *Ask AI launchers are already turned on for some admin screens. Saving makes {agent} the agent that answers them, replacing the current choice — the screens themselves stay as configured.*

**Gutenberg block**

- *Nothing else to configure — saving makes this agent available to insert as a block from any post or page editor.*

#### After a successful publish

**🚀 {agent} is published on {surface}!** *Fine-tune this further, or publish on another surface.*

- **Publish on another surface** (primary) — back to step 1
- **Manage in Publish** — [Publish](https://agentic-plugin.com/docs/publish/) Advanced tabs
- **Back to Dashboard**

## Common tasks

### Creating a custom agent

1. Open **Agents** → **Add an agent** (or Dashboard → **Train an Agent**)
2. Fill **Agent name**, **What does this agent do?**, and **Continue**
3. Optionally set instructions, welcome, and suggested prompts
4. Leave provider/model on the site default unless this agent needs its own; keep **Supervised** unless you have a reason not to
5. Skip picking tools unless you want a specific set (read-only defaults are added otherwise)
6. **Continue** through Knowledge (the wiki wizard is the next page, after create)
7. **Create agent**
8. Use **Open Chat** to try it, **Add Knowledge** / Knowledge’s wizard to teach it, and **Publish This Agent** to put it in front of people

### Teaching the new agent a policy

1. Finish Train an Agent (or skip straight from [Knowledge](https://agentic-plugin.com/docs/knowledge/))
2. On Knowledge, click **Add knowledge** (Basic) or **Guided setup** (Advanced)
3. Choose **Paste text**, **Upload a file**, or **Pick existing pages**
4. **Continue**, give it a **Title**, **Save knowledge**
5. **Add another** or **View Knowledge Wiki** to edit the concept

### Putting the agent on the site

1. From the Train an Agent success card, click **Publish This Agent** (or open `Publish Your Agent` later)
2. Confirm **Which agent?**
3. Pick **Chat widget**, **Admin bar**, **Ask AI launcher**, or **Gutenberg block**
4. **Continue**, set position / pages / screens if asked
5. **Publish**
6. **Manage in Publish** if you need Scheduled Tasks, Event Listeners, or Shortcodes (those are not in this wizard)

### Publishing a second surface

1. After success, click **Publish on another surface**
2. Pick a different surface for the same (or another) agent
3. **Publish** again

## FAQ

**Q: Why isn’t this in the left menu?**

A: All three pages are registered as hidden admin screens so they do not clutter the menu after you have agents. Open Train an Agent from **Add an agent** or the Dashboard Quick Action; Add Knowledge from the Knowledge page; Publish Your Agent from the create-success **Publish This Agent** button (or a direct `agentic-deploy-wizard` URL).

**Q: Is this the five-step wizard or three pages?**

A: Both. **Train an Agent** is five inner steps (Basics → Persona → Capabilities → Knowledge → Review). **Add Knowledge** and **Publish Your Agent** are separate two-step pages. Together they are the creation flow.

**Q: Add Knowledge after create did not open the wizard.**

A: That success button opens the [Knowledge](https://agentic-plugin.com/docs/knowledge/) page. The wizard is **Add knowledge** (Basic) or **Guided setup** (Advanced) on that page — the same `agentic-knowledge-wizard` screen this doc describes.

**Q: The Knowledge step inside Train an Agent asked me to connect a license.**

A: On this WordPress.org build, in-wizard vector training is not enabled (`Connect your license to train agents on your content.`). Use the **Add Knowledge** wizard (paste / `.txt`/`.md` / existing pages) or the Knowledge wiki instead. That is the free path.

**Q: Create agent failed with “An agent named … already exists.”**

A: The slug (identifier) is already taken. Change **Identifier (slug)** on Basics, or pick a different **Agent name** so the auto-filled slug changes. Other create errors include **An agent name is required.**, **A short description is required.**, and **Could not derive a valid slug from the agent name.**

**Q: Create agent said it added default tools.**

A: You left the tool list empty. The wizard assigns a small read-only set so the agent can activate (a signed `abilities.json` needs at least one valid tool). Change tools later from [Tools](https://agentic-plugin.com/docs/tools/) and the agent’s own configuration.

**Q: Can I publish a shortcode or a scheduled task from this wizard?**

A: Not from **Publish Your Agent** — that page only covers chat widget, admin bar, Ask AI, and Gutenberg block. Use [Publish](https://agentic-plugin.com/docs/publish/) Advanced (or Agent Orchestrator in Publish Basic) for shortcodes, schedules, event listeners, and the editor sidebar.

**Q: Who can open these pages?**

A: **Train an Agent** and **Publish Your Agent**: administrators. **Add Knowledge**: administrators, and anyone granted permission to manage plugin settings. Everyone else gets “You do not have permission to access this page.”
