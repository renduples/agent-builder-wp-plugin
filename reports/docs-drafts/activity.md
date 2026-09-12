# Activity

Activity is the site’s log of what agents did — tools they used, approvals you handled, chats, settings changes, and security events. It is a friendly timeline, not a raw database dump. Retention (how long rows are kept) is set on [Settings → Security](https://agentic-plugin.com/docs/settings/).

> The menu label and page heading are **Activity**. The same log’s integrity is summarized on [Safety Center](https://agentic-plugin.com/docs/safety-center/). A **Basic** / **Advanced** pair applies to this page only (tooltip: “Only changes this screen. Other screens and your site-wide default (Settings → Interface) are unaffected.”). Advanced adds the technical action slug on each row; it does not add extra tabs.

## Overview

Open **Agent Builder → Activity**. Heading: **Activity**.

The description under the heading changes with the tab:

| Tab | Description |
| --- | --- |
| **Timeline** | A simple timeline of agent work — tools used, approvals, and important system events. No technical jargon required. |
| **Conversations** | Chats between people and agents on this site. |
| **Security** | Security-related events. Most sites stay quiet here. |

Under that, on every tab: *This is a friendly activity feed. Technical names stay in Advanced detail when useful.*

The panel title also changes: **What your agents have been doing** (Timeline), **Recent conversations**, or **Security events**.

Chat start/finish noise (`chat_start` / `chat_complete`) is hidden on Timeline by default — full message content is not stored here. Conversations is the chat list; [Agent Chat](https://agentic-plugin.com/docs/chat/) is where you talk.

## Activity sections

### Log integrity (Timeline only)

On **Timeline**, a **Log integrity:** line reports the hash chain that links each audit row to the one before it (the same check [Safety Center](https://agentic-plugin.com/docs/safety-center/) shows as Last verification):

| Badge | Hover text |
| --- | --- |
| **Verified** | *{n} entries checked, chain intact.* |
| **Tampering detected** (danger) | *Chain broken at entry #{id} — an entry was edited or deleted after the fact.* |

This is computed when you open Timeline, not on Conversations or Security.

### Summary metrics

- **Events** — how many rows are in the current period (after the built-in chat-start/finish filter on Timeline)
- On **Timeline** only: **Tools used**, **Approvals**, and **Tokens** (Tokens appears only when the count is greater than zero)

These counts are for the selected period, not all time.

### Tabs

Three tabs (the Timeline tab’s URL slug is still `audit`):

- **Timeline** — the main activity feed
- **Conversations** — one row per stored chat session
- **Security** — security-related events (blocks, errors, and similar)

### Time period

**Time period:** **Today**, **Last 7 days**, **Last 30 days**. Default is **Last 7 days**. Changing the period reloads the feed (it is stored on the page URL as `period`).

Row limits behind the scenes: 200 (Today), 500 (Last 7 days), 1,500 (Last 30 days). Older rows still exist until retention deletes them; they just are not on this screen.

### Activity type (Timeline only)

**Activity type** filters (each with a count). Empty types are hidden unless they are the one you have selected:

| Filter | What it includes |
| --- | --- |
| **All** | Every remaining row |
| **Tools used** | Tool calls and related tool events |
| **Approvals** | Queued, approved, and rejected actions |
| **Chats** | Chat-kind rows that were not filtered out as start/finish noise |
| **Settings** | Settings, mode, profile, deployment, and tool on/off events |
| **Other** | Anything that did not match the kinds above |

### Search, count, and export

- **Search activity…** — matches title, subtitle, detail, the technical action slug, and kind
- A count: **1 event** or **{n} events**
- **Export CSV** — downloads the *current* tab and period (not only the search/kind filter). Tooltip: *Download this log as a CSV file — handy to attach when emailing support about an issue.* Filename like `agent-builder-{tab}-log-{date}.csv`. Columns: Date (UTC), Kind, Actor, Action, Summary, Detail, Tokens, Cost (USD)

Export permission is the same as opening Activity. A bad or expired nonce: **Security check failed. Please reload the Activity page and try exporting again.** No permission: **You do not have permission to export logs.**

### Timeline rows

Each row:

- An icon for the kind (tool, approval, chat, settings, and so on)
- A plain-language **title** (for example **Used tool: {name}**, **You approved an action**, **Added knowledge**, **Agent created**)
- A kind pill (`tool`, `approval`, `chat`, `settings`, `other`, `security`)
- Subtitle (usually the agent’s name, or **You / system**) and a relative time
- Optional detail (reasoning, token count, error text, and similar)
- In **Advanced** only: the raw action slug as code (for example `tool_call`)

Titles the plugin knows include: Started a chat, Finished a chat, Used a tool, Chose tools for a reply, Ran an approved action, Tool turned on / off, Tools safety profile applied, Action waiting for approval, You approved / rejected an action, Approval preferences saved, Interface mode changed, Default agent mode changed, Deployment created / updated / enabled / disabled / deleted, Settings changed, Changed a service endpoint URL, Added knowledge, Agent activated / deactivated, Agent created. Anything else is the action slug turned into words.

### Conversations tab

Each row is **Chat with {agent} (#{id})**, with the WordPress display name (or **Guest**) and when it was last updated. There is no transcript viewer and no Delete control on this React screen — open [Agent Chat](https://agentic-plugin.com/docs/chat/) to read a live thread.

### Security tab

Same timeline layout. Titles are a friendly form of the event name (for example **Approved action failed while running**, **Chat hit an error**, **Chat error recorded**). Most sites stay quiet here.

This tab is **not** the Unique IPs / Blocked Messages cards — those are not on the current Activity screen. Tune scanning, consent, and retention on [Settings → Security](https://agentic-plugin.com/docs/settings/).

### Empty states

- **Nothing here yet**
- Unfiltered: *When agents chat or use tools, their activity will show up here.*
- Search or type filter active: *No events match this filter. Try another period or clear search.*

If the page fails to load: **Unavailable.** (or the API error). While it loads: **Loading…**

## Common tasks

### Seeing what an agent did today

1. Open **Activity**
2. Stay on **Timeline**
3. Click **Today**
4. Optionally click **Tools used** or **Approvals**
5. Use **Search activity…** if you know a tool or agent name

Switch this screen to **Advanced** if you need the raw action slug to match a log from support.

### Exporting a period for support

1. Open the tab you care about (**Timeline**, **Conversations**, or **Security**)
2. Pick **Today**, **Last 7 days**, or **Last 30 days**
3. Click **Export CSV**
4. Attach the file to your support email

Search and type filters do not change the CSV — only the tab and period do.

### Checking whether the log was tampered with

1. Open **Activity → Timeline**
2. Read **Log integrity**
3. **Verified** means the chain is intact. **Tampering detected** means a row was edited or deleted after it was written

The same check is on [Safety Center](https://agentic-plugin.com/docs/safety-center/) as Last verification.

### Finding a chat session

1. Open **Conversations**
2. Pick a period
3. Search for the agent name or user

To read the messages themselves, open [Agent Chat](https://agentic-plugin.com/docs/chat/) (or the conversation that is still in that user’s browser). Activity Conversations is an index, not a full transcript.

### Changing how long logs are kept

1. Open [Settings → Security](https://agentic-plugin.com/docs/settings/)
2. Set **Conversation retention (days)** (Basic and Advanced)
3. In Advanced on that tab, set **Audit log retention (days)**
4. **Save changes**

**0** means keep forever. Activity does not have its own retention control.

## FAQ

**Q: Where is the old Audit table (Time, Tokens, Cost, View details)?**

A: The current screen is the friendly timeline (this is the M3 Activity page). Advanced shows the technical action slug on each row. Export CSV if you need columns such as tokens and cost.

**Q: Why don’t I see every chat start?**

A: Timeline hides `chat_start` and `chat_complete` on purpose so the feed is readable. Use **Conversations** for sessions, or [Agent Chat](https://agentic-plugin.com/docs/chat/) for the live thread.

**Q: What’s the difference between Timeline, Conversations, and Security?**

A: **Timeline** is agent work (tools, approvals, settings). **Conversations** is stored chat sessions. **Security** is security-related events. Integrity and the type filters exist only on Timeline.

**Q: Does Export CSV respect Search or Tools used?**

A: No. It exports the current **tab** and **period**. Narrow the period first if the file would otherwise be huge.

**Q: Unique IPs isn’t on this page.**

A: Correct — that card is not on the current Activity Security tab. This tab is a timeline of security events. Scanning and retention are on [Settings → Security](https://agentic-plugin.com/docs/settings/).

**Q: What’s the difference between this page’s Basic / Advanced and the site-wide switch?**

A: This page’s toggle only changes Activity (plain titles vs titles plus the raw action slug) and is remembered per user. The site-wide switch in the admin header is the default for every Agent Builder screen. The **?** on the toggle says so.

**Q: Who can open this page?**

A: Administrators, and anyone granted permission to view the audit log. Everyone else is refused. Export uses the same permission.
