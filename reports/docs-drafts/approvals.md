# Approvals

Approvals is the queue where high-risk agent actions wait until you say yes or no. It is also where you pick how closely to watch agents (Comfort profiles) and whether to get an email when something is waiting.

> Approving an item **runs that action once**. Rejecting cancels it. Either way it is logged. Medium-risk “proposed changes” are confirmed in [Agent Chat](https://agentic-plugin.com/docs/chat/), not here — this page is the high-risk queue.

## Overview

Open **Agent Builder → Approvals**. The heading is **Approvals**, with: “When an agent wants to change something important, it waits here until you approve or reject it.”

The card is titled **Things waiting for your OK**.

At the top right, a **Basic** / **Advanced** pair applies to this page only (tooltip: “Only changes this screen. Other screens and your site-wide default (Settings → Interface) are unaffected.”). That is separate from the **Site-wide** switch in the admin header.

- Basic: “Simple view for non-technical admins.”
- Advanced: “Advanced view shows technical detail.” — the only extra on each request is the raw tool id next to the title

A pending count can appear on the Approvals menu item. The same queue is summarized on [Safety Center](https://agentic-plugin.com/docs/safety-center/) and the Dashboard.

## Approvals sections

### Preferences

“Choose how closely you want to watch agents, and whether we should email you when something is waiting.”

Three Comfort cards. Clicking **Always ask me** or **Auto-approve low risk** saves immediately. **Trust more** does not — you must tick the risk box and click **Save “Trust more” preference**.

The active card shows a **Current** badge.

| Card | Summary | What it sets |
| --- | --- | --- |
| **Always ask me** 🛡️ | Safest default | Supervised mode. “Important or writing actions wait for you. Best when you want full control.” Risk note: “No automatic approvals beyond the safest reads.” |
| **Auto-approve low risk** ⚖️ | Recommended for most sites | Supervised mode. “Simple look-ups run freely. Drafts and bigger changes still pause for confirmation or this queue.” |
| **Trust more (higher risk)** ⚡ | Faster — use with care | Autonomous mode. “Agents work with less interruption (autonomous mode). You can still review history. Extreme tools stay blocked.” |

Picking a profile is what actually sets **Mode** (Supervised / Autonomous). [Safety Center](https://agentic-plugin.com/docs/safety-center/) shows both values; this page is where you change Comfort.

#### Trust more acknowledgement

When **Trust more** is selected, a checkbox appears:

> I understand agents may change my site with less waiting, and I accept that increased risk.

**Save “Trust more” preference** stays disabled until the box is checked. Saving without it: “Check the box to accept the increased risk before choosing “Trust more”.”

Extreme-risk tools stay blocked on every profile.

### Email alerts

- Checkbox: **Email me when an action is waiting for approval**
- When checked, **Send alerts to** (email field, placeholder `you@example.com`). Default is your WordPress admin email if you have not set one
- **Save email preferences**

Emails are rate-limited (at most one every five minutes; the next mail can mention how many extra requests arrived while paused). Subject: `[{site name}] Agent action waiting for your approval`. The body lists Action, Agent, Risk, and Why, with a **Review approvals in WordPress admin** link. Alerts never include passwords.

Invalid address: “Enter a valid email address.” Success: “Preferences saved.”

### Waiting for you

Heading **Waiting for you** with a count when the queue is not empty, for example **Waiting for you (1)**.

#### Empty state

**You’re all caught up**

“Approvals pause any action an agent rates as risky until you say yes — nothing waiting here means nothing is on hold right now.”

“When an agent needs permission for a bigger change, it will show up here.” If email alerts are on, that sentence ends “ — and we’ll email you.”

#### A pending item

Each card shows:

- **Title** — the tool name with underscores turned into spaces (for example `manage skill`)
- In **Advanced** only: the raw tool id in a code style (for example `manage_skill`)
- A **?** tip: “An agent tried to run this specific action and paused here first. Nothing happens until you decide — approve to let it run once, or reject to cancel it.”
- A **risk** badge (`high` on typical queue items) and a second **?** explaining that tier (high: “A significant or bulk change — waits in the Approvals queue for you to allow it.”)
- Agent id and the time it was created
- A short **summary** (the agent’s reasoning, or a file path / title, truncated at 200 characters)

Buttons: **Approve** and **Reject**. While a request is in flight they read **Working…** and other buttons on the page disable.

There is no extra confirm dialog on a single Approve / Reject.

#### Progress

Approving opens a status block:

1. Recording your approval…
2. Running the approved action…
3. Confirming result…

Headings: **Working…**, then **Complete** or **Needs attention**, plus the action title. **Dismiss** hides it when finished.

Typical outcomes:

- “Approved and completed.” / “Action completed successfully.” / “Done — agent task finished”
- “Approved, but the action could not be started.”
- “Action ran but reported a problem.” / “Finished with errors — check detail below”
- Reject: “Rejected — the action will not run.”

Errors: “Could not update that item.” (or the server message).

#### Groups (Approve all / Reject all)

Items from the **same agent** created within **two minutes** of each other are grouped. When a group has more than one action, a header appears:

`{agent} · {n} actions · {time} ago`

- **Approve all** — confirm: “Approve all {n} actions in this group? They will run in order.” Scaffold-style steps (creating plugin/agent files) are run before later edits. If one step fails, the rest of the group is **not** approved (“Stopped after X of Y — …”).
- **Reject all** — confirm: “Reject all {n} actions in this group?” Every item is attempted even if one fails.

Success toasts: “Approved {n} actions.” / “Rejected {n} actions.” Mixed: “{n} succeeded, {n} failed.”

### View backups and restore

Under the queue:

“Agents back up files and database tables automatically before changing them.” **View backups and restore** opens the Backups view of this same admin page (`tab=backups`).

That view is a separate classic screen (not the React card above). It has **Approvals** / **Backups** section nav.

- Intro: “Automatic backups created before AI agents modify files or database tables. Restore with one click.” plus **Learn more →**
- Empty: **No backups yet** — “When agents modify files or database tables, backups are created automatically and will appear here.”

When backups exist:

**Database Tables** — “Full table snapshots taken before write operations. Up to 3 copies kept per table.” Columns: Table, Type (**Full** or **Partial**), Backed Up, Rows, Size, Actions (**Restore**, trash). Restore confirms: “Restore this table? The current table will be backed up first, then replaced with this snapshot.”

**Files** — “File snapshots taken before write operations.” Columns: Original File, Backed Up, Size, Status (**File exists** or **Deleted**), Actions (**Restore**, trash). Restore confirms: “Restore this file? The current version will be backed up first.”

Trash confirms: “Delete this backup permanently?” / “Delete this table backup permanently?”

Footer note: stored in `wp-content/agentic-backups/`. Database tables keep up to 3 snapshots each; file backups stay until you delete them.

The Backups view requires the administrator capability (`manage_options`). If you can open Approvals but not Backups, you will see “You do not have permission to access this page.”

## Common tasks

### Approving a waiting action

1. Open **Approvals**
2. Read the title, risk badge, agent, and summary
3. Click **Approve**
4. Watch the progress list until it says **Complete** (or **Needs attention** if the action failed after you approved)

The item leaves the queue. Check [Activity](https://agentic-plugin.com/audit-log/) if you need the log line.

### Rejecting an action

1. Open **Approvals**
2. Click **Reject** on that card
3. Status: “Rejected — the action will not run.”

Nothing on the site is changed.

### Approving a whole burst from one agent

1. If you see a group header with **Approve all**, click it
2. Confirm that they will run in order
3. If a step fails, fix that problem before approving the rest — later steps may depend on earlier ones

### Getting an email when something is waiting

1. Tick **Email me when an action is waiting for approval**
2. Check **Send alerts to**
3. Click **Save email preferences**

Turn the checkbox off and save again to stop the mail.

### Switching from “always ask” to auto-approve low risk

1. Click the **Auto-approve low risk** card
2. It saves on click (no extra button)
3. Low-risk look-ups can run without this queue; high-risk items still land here; medium-risk still pauses in Chat

### Restoring a file an agent changed

1. Click **View backups and restore**
2. Find the file or table
3. Click **Restore** and confirm

## FAQ

**Q: What’s the difference between Approve here and Allow Once in Chat?**

A: **Approve** / **Reject** on this page are for **high-risk** tools — the same “Needs Your Approval” cards in Chat. Medium-risk **Proposed Change** cards in Chat use **Allow Once** / **Allow this Session** / **Always Allow** / **Deny** and never appear in this queue.

**Q: Why is the queue empty if agents are working?**

A: Nothing high-risk is on hold. Reads and other tools at or under your Comfort ceiling run without this page. Medium-risk waits in the chat thread. Extreme-risk never runs.

**Q: What does Advanced add?**

A: The raw tool identifier next to the friendly title, and the “Advanced view shows technical detail.” line. Preferences, email, Approve / Reject, and groups are the same in both modes.

**Q: I picked Trust more and still see items here.**

A: Trust more auto-approves up to medium-risk and switches Mode to Autonomous. **High-risk** actions still wait in this queue. Extreme stays blocked.

**Q: Does Approve all run steps in a safe order?**

A: Yes. The server puts scaffold/create-file steps first, then the rest by id, and **stops at the first failed approve** so a later edit is not applied on a missing file. Reject all does not stop early.

**Q: Where did the Approvals / Backups tabs go?**

A: The main Approvals screen does not show those tabs at the top. Use **View backups and restore** at the bottom. Once you are on Backups, **Approvals** | **Backups** nav is there to jump back.

**Q: Who can open this page?**

A: Anyone granted the agent-management permission (and administrators). The **Backups** view is administrators only.

**Q: Can I undo an approval?**

A: Not as a second button on the card — the action has already run. Use **View backups and restore** if a file or table snapshot exists, or fix the content in WordPress.
