# Tools

The Tools screen is where you decide which actions agents may perform — look something up, draft a post, change a setting, and so on. Agents only use the tools you allow. Higher-risk actions still follow [Approvals](https://agentic-plugin.com/approval-queue/) and your safety settings.

> Basic mode is three safety profiles. The full tool-by-tool list, category tabs, search, and risk filters live in Advanced mode on this same page.

## Overview

Open **Agent Builder → Tools** in wp-admin. At the top you'll see:
- The **Tools** heading
- A short description (it changes with the mode)
- A **Basic** / **Advanced** toggle (this page only). The **?** next to it: *Only changes this screen. Other screens and your site-wide default (Settings → Interface) are unaffected.*

**Basic** description: *Pick a simple safety profile. We turn tools on or off to match — no need to manage hundreds of tools one by one.*

**Advanced** description: *Enable or disable tools agents can use. Group by category using the tabs.*

[Safety Center](https://agentic-plugin.com/docs/safety-center/) summarizes the same inventory (enabled/disabled counts and highest enabled risk) and links back here.

## Tools sections

### Basic mode (ability profiles)

The panel title is **What may agents do?**

Copy under it: these profiles control which tools **every** agent may use; Approvals still apply for riskier actions. Switch to Advanced (top right) for the full tool-by-tool list.

If tools were toggled one-by-one in Advanced, a warning reads: **Tools were customized outside a profile. Choose a card below to reset to a simple safety level.**

Three cards (clicking a card applies it immediately):

| Card | Summary | What it turns on | Up to |
| --- | --- | --- | --- |
| **Browse & answer** | Safest — read-only help | Agents can look things up and answer questions. They cannot change posts, settings, or your site. | low risk |
| **Help with drafts** | Balanced — create drafts with care | Read plus everyday writing (drafts and light edits). Riskier changes still ask for confirmation. | medium risk |
| **Manage my site** | Full productivity — approvals for big changes | Most tools on, including significant updates. High-risk actions go through the Approvals queue. Extreme tools stay off. | high risk |

The active card shows a **Current** badge.

Success notice: **Profile applied: {n} tools on, {m} tools off.** Failure: **Could not apply profile.**

A line under the cards reports the live inventory: **Right now: {n} tools on, {m} off · highest enabled risk: {level}**.

There is no per-tool table, search, or category tab in Basic mode.

### Advanced mode (full tool list)

The panel title is **All tools**, or the category name when a category tab is selected. A line under the tabs reminds you: **You are in Advanced view (full tool list).**

#### Category tabs

**All ({count})** first, then every non-empty category A–Z, each with a count. Examples of labels you may see: Agents, AI Visibility, Analytics, Caching, CLI, Communication, Content, CRM, Database, DataForSEO, Ecommerce, Email, Files, Forms, Google Business, Git, Search Marketing, Google Workspace, Maintenance, Media, Orchestration, Plugins, Security, SEO, Site Audit, Site Health, Themes, Users, Utility, Web, WordPress. Unknown categories show as title-cased slugs.

Bookmarks that used `?category=` still work; they open the matching tab. The default tab is **All**.

#### Search and risk filters

- **Search tools…** — matches name, description, category, id, and risk
- A count of the filtered list (**1 tool** / **{n} tools**)
- **Filter by risk:** **All risks**, **None**, **Low**, **Medium**, **High**, **Extreme** (each with a count). Empty levels are hidden unless they are the active filter

No matches: **No tools match this search or risk filter.**  
Empty category: **No tools in this category.**

#### Table

| Column | What it shows |
| --- | --- |
| **Enabled** | On/off switch (`Enabled: {tool}` for screen readers) |
| **Tool** | The tool's identifier (for example `db_create_post`) and a truncated description |
| **Category** | On the **All** tab only — links to that category tab |
| **Risk** | `none`, `low`, `medium`, `high`, or `extreme`, plus a **?** with the meaning below |
| **Source** | Where the tool was registered (commonly `core`) |

Risk **?** text:

| Risk | Meaning |
| --- | --- |
| **none** | Safe to run automatically — read-only, no approval needed. |
| **low** | Runs automatically by default; may read data that includes personal information. |
| **medium** | Changes something — agents pause for your in-chat confirmation first. |
| **high** | A significant or bulk change — waits in the Approvals queue for you to allow it. |
| **extreme** | Too risky to allow at all — hidden from agents entirely, cannot be enabled. |

Toggling is optimistic (the switch flips without reloading). A failure rolls it back and shows **Toggle failed.** Turning any tool on or off by hand marks the Basic profile as custom — you'll see the yellow warning when you switch back to Basic.

#### Enabling a high-risk or extreme tool

Turning **on** a **high** or **extreme** tool does not flip immediately. A modal asks first.

**High** — title **Enable high-risk tool?**
- *{name}* can make a significant change to your site.
- **Why this is high-risk:** a specific reason when the plugin has one (for example *installs code on your site*, *can affect account access*, *moves money*, *can permanently remove data*, *changes the deployed codebase*), otherwise the high-risk sentence above
- If an agent uses this tool later, the action will still wait for human review in the Approvals queue before it runs
- Enabling this tool makes it available to eligible agents. It does not run the tool immediately
- **Cancel** or **Enable tool**

**Extreme** — title **This tool cannot be enabled**
- *{name}* is marked Extreme Risk
- Extreme-risk tools are hidden from agents entirely and blocked from running, because they are too risky for normal use
- **Close** only — there is no enable button. The server also refuses the same change

Turning a tool **off** never shows this modal.

## Common tasks

### Picking a simple safety level

1. Open **Tools** in Basic mode
2. Click **Browse & answer**, **Help with drafts**, or **Manage my site**
3. Wait for **Profile applied: {n} tools on, {m} tools off.**
4. Check the **Right now** line if you want the new counts and highest enabled risk

### Turning one tool off or on

1. Set this screen to **Advanced**
2. Find the tool (category tab, **Search tools…**, or a risk filter)
3. Flip **Enabled**
4. For a high-risk tool turning on, read the modal and click **Enable tool** — or **Cancel**
5. Extreme-risk tools cannot be enabled; **Close** the modal

### Checking why a tool is high-risk

1. Open **Tools** in Advanced
2. Filter **High** (or search the identifier)
3. Read the **Risk** badge **?**
4. If you enable it, the modal repeats a more specific reason when one is on file

Or open [Safety Center](https://agentic-plugin.com/docs/safety-center/) → **Highest-risk tools currently enabled** / **Per-agent tool scopes**.

### Resetting a custom mix back to a profile

1. After toggling tools in Advanced, switch back to **Basic**
2. Read the warning that tools were customized outside a profile
3. Click a profile card to replace that mix

## FAQ

**Q: Where did the long tool list go?**

A: It's in Advanced mode. Click **Advanced** at the top right of this page (not the site-wide switch in the header). Basic mode is the three profile cards only.

**Q: Does a profile apply to every agent?**

A: Yes. Profiles turn tools on or off site-wide. They do not hide the Approvals queue — medium-risk changes still pause in chat, high-risk changes still wait on Approvals, and extreme tools stay off even on **Manage my site**.

**Q: I enabled a high-risk tool and nothing happened.**

A: Enabling only makes the tool *available*. The next time an agent wants to use it, the action still waits in [Approvals](https://agentic-plugin.com/approval-queue/) for you to allow it. The modal says this before you confirm.

**Q: Why can't I switch an Extreme tool on?**

A: Extreme-risk tools are hard-blocked — hidden from agents and refused by the server. That is not a default you can override from this screen (or from Safety Center).

**Q: What's the difference between this page's Basic / Advanced and the site-wide switch?**

A: This page's toggle changes only Tools (profiles vs the full list) and is remembered per user. The site-wide switch in the admin header is the default for every Agent Builder screen. The **?** on this toggle says so.

**Q: The tool name looks like a code id.**

A: The Advanced table lists the registry identifier (what the executor and audit log use), not a marketing label. The description under it is the plain-language hint.

**Q: Who can open this page?**

A: Administrators, and anyone granted permission to manage tools.
