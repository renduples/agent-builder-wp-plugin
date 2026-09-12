# Skills

Skills are reusable instructions that teach agents when and how to use tools — a playbook for a job (draft a newsletter, handle a WooCommerce order, reply to support) rather than the tool itself. Manage tools on [Tools](https://agentic-plugin.com/docs/tools/); teach the workflow here.

> Basic mode is a chat with the bundled **Skills Assistant**. The skill table, Create Skill, Browse Community, and file import/export live in Advanced mode on this same page.

## Overview

Open **Agent Builder → Skills** in wp-admin. At the top you'll see:
- The **Skills** heading
- *Instructions that teach agents when and how to use tools.*
- A **Basic** / **Advanced** toggle (this page only). The **?** next to it: *Only changes this screen. Other screens and your site-wide default (Settings → Interface) are unaffected.*

**Basic** embeds Skills Assistant (no agent picker). **Advanced** is the installed-skills table. **Create Skill**, **Edit**, and **Browse Community** are extra views of this same page.

## Skills sections

### Basic mode (Skills Assistant)

A chat with the bundled **Skills Assistant** agent. It can draft, edit, and manage skills, and find or import skills other people have published — conversationally. Its tools include managing a skill, browsing community skills, and listing available agents.

You get:
- The agent's icon and name (**Skills Assistant**)
- Its welcome message
- **Try asking** — four starter prompts. Clicking one sends it immediately:
  - Teach an agent how to draft a weekly newsletter from recent posts.
  - Find a skill for managing WooCommerce orders.
  - What skills does my Content Writer agent have?
  - Help me write a skill for replying to support comments.
- Composer placeholder **Ask Skills Assistant…** — Enter sends; Shift+Enter inserts a newline; **Send**
- While it works: **Skills Assistant is thinking…**
- The same in-thread **Proposed Change** / **Needs Your Approval** cards as [Agent Chat](https://agentic-plugin.com/docs/chat/) (**Allow Once**, **Allow this Session**, **Always Allow**, **Deny** — or **Approve** / **Reject** for high-risk)

This embed has no agent dropdown, no chat history panel, no New Chat control, and no paperclip / microphone / read-aloud buttons (those live on Agent Chat when the matching Settings → Agents features are on).

If Skills Assistant isn't available: **This assistant is still activating.** with **Check the Agents page**. Open [Agents](https://agentic-plugin.com/docs/agents/) and confirm Skills Assistant is active, then return.

### Advanced mode (installed skills table)

The panel title is **Skills**. Two buttons above the table:
- **Create Skill** (primary) — opens the template gallery
- **Browse Community** — opens the community hub

A search box filters by skill name, description, or assigned agent.

#### Table

| Column | What it shows |
| --- | --- |
| **Skill** | A status light (**Active** / **Disabled**), the name, and the description |
| **Agent** | Assigned agent names, or **All agents** if none are checked |
| **Source** | **Core**, **Agentic**, **ClawHub**, **WordPress.org**, **Anthropic**, or **Local** |
| **Version** | Version string, or — |
| **Actions** | **Edit** · **Export** · **Delete** |

The light is a status indicator, not a switch — enable or disable a skill on its Edit form.

Empty list: **No skills installed yet. Import a recommended skill, create your own, or browse the community above.** (The actions above the table are **Create Skill** and **Browse Community**.)  
No search matches: **No skills match your search. Try a different keyword or clear the search.**

**Delete** confirms **Delete this skill? This cannot be undone.** Failure: **Delete failed.**

**Export** downloads that skill's `SKILL.md`.

**Edit** opens the Edit Skill form.

### Create Skill

Opens from **Create Skill**. Heading **Create Skill**, with **← Back to Skills**.

*Start from a template close to what you need, or from a blank skill.* Five cards:

| Card | Category | Description |
| --- | --- | --- |
| **Blank skill** | General | Start from an empty SKILL.md skeleton. |
| **WordPress content workflow** | WordPress | For a skill that creates, edits, or reviews posts and pages. |
| **WooCommerce workflow** | WooCommerce | For a skill touching products, orders, or store data. |
| **Developer / technical workflow** | Developer | For a skill wrapping technical or maintenance tools. |
| **Communication / support workflow** | Communication | For a skill handling messaging, notifications, or support handoffs. |

#### Or import an existing SKILL.md

Any spec-compliant skill file — from ClawHub, Android Skills, Claude Skills, or elsewhere — can be dropped in directly. Choose a `.md` file and **Import File**. A bad file: **Could not import that file — make sure it is a valid SKILL.md (YAML frontmatter followed by Markdown).**

Picking a template opens the same form as Edit, titled **Create Skill**, with that skeleton in **SKILL.md Content**.

### Edit Skill / the create form

Heading **Edit Skill** or **Create Skill**, with **← Back to Skills**.

If it's a bundled **core** skill:
- Unedited: *This is a bundled skill. Editing it will fork a local copy — your changes will be preserved across plugin updates.*
- Already edited: **This bundled skill has local edits.** *Plugin updates will not overwrite your changes.* plus **Reset to shipped version** (confirms **Discard your edits and restore the shipped version of this skill?**). Success: **Skill reset to the shipped version.**

A **Spec check** warning (not blocking) may list portable-spec problems, including **allowed-tools references unknown tool(s): …**

Fields:
- **Name** (required) — empty save: **Skill name is required.**
- **Description** — brief summary of what this skill teaches the agent to do
- **SKILL.md Content** — markdown injected into the agent's system prompt; teaches when and how to use specific tools
- **Assign to Agents** — one checkbox per *active* agent. Leave every box unchecked to make the skill available to all agents, or check one or more to limit it
- **Author** (optional) and **Version** (default `1.0.0`)
- **Enable** — **Active — inject this skill into agent system prompts** (new skills default on)

**Preview what this skill sends to the agent** (Advanced, disclosure):
- Always in context (progressive-disclosure "metadata" tier — every enabled skill's index line)
- Loaded only when the agent calls `load_skill` for this skill (the full body), with line count and an estimated token count. Over ~500 lines: **Over the spec's ~500-line guidance — consider moving reference material out.**

If the skill was imported from ClawHub: *This skill was imported from ClawHub. Local edits will be preserved unless you re-import.*

**Create Skill** / **Update Skill** saves. Success: **Skill created.** / **Skill updated.** Then you return to the list.

### Browse Community Skills

Opens from **Browse Community**. Heading **Browse Community Skills**, with **← Back to Skills**.

Source tabs:
- **WordPress.org** — official `WordPress/agent-skills` catalog (default)
- **Anthropic** — `anthropics/skills`
- **OpenClaw / ClawHub** — large open-publish registry with a **Search skills...** box and **Search**

The list loads with **Loading...**. Each row: **Name**, **Description**, **Action** → **Import**. Confirm **Import this skill?** then **Importing...**. Success: **Skill "{name}" imported.** Failures: **Failed to import skill.** / **Invalid skill data.** / **No skills found in this repository.** / **No skills found. Try a different search term.**

Imported skills then appear on the Advanced table.

## Common tasks

### Asking Skills Assistant to write a skill (Basic)

1. Open **Skills** in Basic mode
2. Click a **Try asking** prompt, or type in **Ask Skills Assistant…** and **Send**
3. If it proposes a change, review **Proposed Change** and **Allow Once** (or session / always / deny)

To inspect or tweak the file it created, switch this screen to **Advanced** and **Edit** that row.

### Creating a skill from a template (Advanced)

1. Open **Skills** in Advanced
2. Click **Create Skill**
3. Pick a template (or **Blank skill**)
4. Fill **Name**, **Description**, and **SKILL.md Content**
5. Optionally **Assign to Agents**
6. Leave **Enable** checked to inject it
7. Click **Create Skill**

### Importing a community skill

1. Open **Skills** in Advanced
2. Click **Browse Community**
3. Stay on **WordPress.org**, or switch to **Anthropic** / **OpenClaw / ClawHub**
4. Click **Import** on a row and confirm

Or on **Create Skill**, use **Import File** to drop in a local `SKILL.md`.

### Editing or disabling a skill

1. Open **Skills** in Advanced
2. Click **Edit**
3. Change the markdown, assignments, or clear **Enable** to stop injecting it
4. **Update Skill**

The list light is not a toggle — disable from this form.

### Exporting a skill

1. Open **Skills** in Advanced
2. Click **Export** on that row
3. Save the `SKILL.md`

### Deleting a skill

1. Open **Skills** in Advanced
2. Click **Delete**
3. Confirm **Delete this skill? This cannot be undone.**

## FAQ

**Q: Where is the list of skills?**

A: In Advanced mode. Click **Advanced** at the top right of this page (not the site-wide switch in the header). Basic mode is Skills Assistant chat only.

**Q: What's the difference between a skill and a tool?**

A: A **tool** is an action an agent may call (see [Tools](https://agentic-plugin.com/docs/tools/)). A **skill** is markdown that teaches *when* and *how* to use tools for a job. Enabling a skill injects its index line into prompts; the full body loads when the agent calls `load_skill`.

**Q: Skills Assistant isn't showing.**

A: The embed shows **This assistant is still activating.** with **Check the Agents page**. Open [Agents](https://agentic-plugin.com/docs/agents/) and confirm Skills Assistant is active, then return.

**Q: Can I change the model from this chat?**

A: No. This embed has no Playground. Provider and model come from Settings, same as other chats. For the full Chat chrome (history, voice, images), open [Agent Chat](https://agentic-plugin.com/docs/chat/) and pick Skills Assistant there.

**Q: Why is Source "Core"?**

A: Bundled skills that ship with the plugin. Editing one forks a local copy so plugin updates won't overwrite you. **Reset to shipped version** on the Edit screen discards those edits.

**Q: What's the difference between this page's Basic / Advanced and the site-wide switch?**

A: This page's toggle changes only Skills (Assistant chat vs the table) and is remembered per user. The site-wide switch in the admin header is the default for every Agent Builder screen.

**Q: Who can open this page?**

A: Administrators, and anyone granted permission to manage tools. Create, edit, import, and delete use the same permission.
