# Safety Center

The Safety Center is your command center for agent security. It shows you what tools your agents can access, which actions are waiting for approval, whether your audit log has been tampered with, and gives you an instant kill switch if anything goes wrong.

> Safety Center summarizes existing operator controls. It does not change how tools, approvals, or Emergency Stop work — it's a read-mostly overview with links out to the screens where you actually change things.

## Overview

Safety Center brings together five overview cards:
- **Tool risk inventory** — How many tools are enabled/disabled and the highest risk level currently enabled
- **Approvals status** — Actions waiting for your OK, plus your current Mode and Comfort profile
- **Last verification** — Whether your audit log's hash chain checks out
- **Emergency Stop** — Instantly shut down all agents
- **Active agents** — Which agents are running and their risk/MCP exposure

Below the cards, three always-visible drill-down sections give you the full detail behind each summary: **Risk inventory**, **Audit-log integrity**, and **Per-agent tool scopes**. Advanced mode doesn't reveal these sections — they're there in Basic mode too — it just expands extra technical detail inside them (see below).

This is where you come when you need to understand your security posture at a glance, or when you need to lock everything down quickly.

## Safety Center sections

### Tool risk inventory (overview card)

Tools are the actions your agents can perform. This card shows:
- **Enabled · Disabled count** — e.g. "12 enabled · 4 disabled"
- **Highest enabled risk** — The most dangerous thing your agents are allowed to do right now, shown as a colored badge: gray/**None**, green/**Low**, amber/**Medium**, red/**High**, purple/**Extreme**

The hint explains: high-risk tools require extra care when you enable them. Click **Review tools** to jump to the Tools page.

### Approvals status

This card shows the current approval backlog and safety settings:
- **Pending count** — e.g. "3 waiting for your OK"
- **Mode** — **Disabled**, **Supervised**, or **Autonomous**
- **Comfort** — **Always ask me**, **Auto-approve low risk**, or **Trust more**

The hint explains: when an agent wants to make an important change, it stops here first. Nothing runs until you approve it.

Click **Open approvals** to jump to the Approvals page, where Comfort profile is the control you actually change — picking a profile sets both Comfort and Mode together:

| Comfort profile (label shown here) | Sets Mode to | What it means |
| --- | --- | --- |
| Always ask me | Supervised | Safest default — only the safest reads run automatically; everything else waits for you. |
| Auto-approve low risk | Supervised | Recommended for most sites — simple look-ups run freely; bigger changes still pause. |
| Trust more (higher risk) | Autonomous | Agents work with less interruption; you must explicitly accept the increased risk to turn this on. Extreme-risk tools stay blocked regardless. |

### Last verification

Agent Builder's audit log is tamper-evident: each entry is hash-linked to the one before it, so editing or deleting a past entry breaks the chain. This card shows the integrity check status:
- **Verified** (green pill) — The chain is unbroken
- **Needs attention** (red pill) — The chain is broken; something was altered after the fact

Below the status:
- **Rows checked** — How many audit entries were verified
- **Broken at entry #N** (if broken) — The specific entry where the check failed

Click **View integrity details** to jump down to the full **Audit-log integrity** section on this same page.

### Emergency Stop

Your ultimate safety control is a single button, not two separate controls. It's the same `Disable All Agents` / `Restore agent system` control — its label and color flip depending on whether Emergency Stop is currently on:
- **Off** — a secondary-styled, destructive-tinted button labeled **Disable All Agents**
- **On** — a primary-styled button labeled **Restore agent system**, and the card itself gets a red highlighted border/background

The card's hint explains what happens when you turn it on: every active agent is deactivated, pending and in-progress jobs are cancelled, AI providers are disconnected, and no new agent activity is allowed until an administrator restores service.

Clicking the button asks you to confirm before it takes effect (a browser confirmation dialog, not a separate modal screen). Turning it back off may show a follow-up list of warnings — for example, if a provider's key or an agent couldn't be restored automatically.

The same Emergency Stop control (as a toggle switch rather than a button) also appears on the Dashboard's Quick Actions card, and both read/write the same underlying setting — flipping it in one place is reflected in the other.

### Active agents

This card shows what agents are currently running and their capability summary:
- **Active count** — e.g. "4 active"
- **With a high-risk tool · With MCP on** — e.g. "1 with a high-risk tool · 2 with MCP on"

Click **View agent scopes** to jump down to the **Per-agent tool scopes** section on this same page.

## Drill-down sections (below the overview cards)

These three sections are always present, in both Basic and Advanced mode. What changes in Advanced mode is noted per section.

### Risk inventory

A strip of five tiles, one per risk tier (None, Low, Medium, High, Extreme), each showing its enabled/disabled counts, a plain-language explanation of what that tier means, and 1–3 example tool names.

Below the strip, **Highest-risk tools currently enabled** lists every currently-enabled High or Extreme tool by name, with its risk badge and description (or a reassuring message if none are enabled). Two buttons follow: **Manage tools** and **Open approval settings**.

### Audit-log integrity

If the chain is broken, an incident banner appears automatically at the top of this section (you don't have to click anything to surface it) — red background, "Audit log may have been altered after the fact," which entry it broke at, and three recommended next steps: pause agents via Emergency Stop, export logs, and review hosting/database access.

Below that (or in place of it, if the chain is intact) is the same **Last verification** status shown in the overview card, plus an explanation of how the tamper-evident chain works.

**Advanced mode only:** a raw technical block showing the exact `valid`, `checked`, `chain_start_id`, and `broken_at_id` values returned by the integrity check.

### Per-agent tool scopes

One card per active agent, showing its name, version, author, whether MCP is enabled, its highest risk tier, and a breakdown of tool counts by tier (None/Low/Medium/High/Extreme). Below that, any High/Extreme tools it can access are listed directly; other tools are tucked under a "N more tools" expandable in Basic mode (shown inline instead, in Advanced mode). A note reminds you the tool list is blocked if the agent's manifest signature fails to verify. Each card ends with **Manage this agent** and **Manage tools** buttons.

**Advanced mode only:** each card also shows the agent's slug, and tool names are shown as raw identifiers (with the friendly label alongside) instead of just the friendly label.

## Other page elements

At the bottom of the page, a callout points to **Site Passport** for AI discoverability/access questions ("Looking for AI discoverability and access, not safety controls?"), and a row of links crosslinks to Tools, Approvals, Activity, and Passport.

## Common tasks

### Reviewing a pending approval

1. Open Safety Center
2. In **Approvals status**, click **Open approvals**
3. The Approvals page opens
4. Review the pending action (what agent, what it wants to do, why it needs approval)
5. Approve it to let it run once, or reject it to cancel it
6. The action is logged either way

### Checking tool risk levels

1. Open Safety Center
2. In **Tool risk inventory**, look at the "Highest enabled risk" badge
3. If it's red (High) or purple (Extreme), scroll down to **Risk inventory** → **Highest-risk tools currently enabled** to see which tool(s)
4. Click **Manage tools** to disable a tool or adjust it from the Tools page

### Understanding audit integrity

If **Last verification** shows "Needs attention" (red):

1. Scroll to (or click **View integrity details** to jump to) **Audit-log integrity**
2. Read the incident banner — it names the broken entry directly
3. Follow its recommended steps: pause agents with Emergency Stop, export logs, and review hosting/database access for anything unexpected

A broken entry is a tamper marker, not automatic proof of malice — it could mean a database backup was restored, or something modified the log directly. But always investigate.

### Turning on Emergency Stop

Only do this in a crisis (e.g., agents are running wild, consuming credits, or making unwanted changes):

1. Open Safety Center
2. Scroll to **Emergency Stop**
3. Click **Disable All Agents**
4. Confirm the action in the dialog
5. The card turns red and the button now reads **Restore agent system**

To restore:

1. Click **Restore agent system** on the same card (it's the same button — no separate control)
2. Confirm again
3. If restoring a provider key or agent fails, you'll see a list of warnings after it finishes

All of this is logged.

### Viewing agent capabilities

1. Open Safety Center
2. Scroll to **Active agents**, then click **View agent scopes** (or just scroll further)
3. The **Per-agent tool scopes** section shows a card per agent with its risk tier, tool-count breakdown, and named High/Extreme tools
4. In Advanced mode, each card also shows the agent's slug and every tool's raw identifier

This is where you see the full picture of what each agent can actually do.

## FAQ

**Q: What's the difference between High and Extreme risk?**

A: **High risk** tools require approval before running — they wait in the Approvals queue. **Extreme risk** tools are hidden from agents entirely and can never be enabled; they're reserved for operations too dangerous for normal use (the code treats them as always blocked, not just defaulted off).

**Q: Why does my audit integrity check show "Broken at entry #12345"?**

A: The hash chain breaks at that entry. This usually means:
- Someone restored a database backup
- Something wrote directly to the audit log table, bypassing the logger
- There was a corruption issue during a log write

Check that entry in Activity's Audit tab. If it's legitimate, later entries still chain correctly from that point forward. If it's suspicious, investigate who had access to your database.

**Q: Can I see which agent is using which tool?**

A: Yes — scroll to **Per-agent tool scopes** on the Safety Center page itself (this is visible in Basic mode too, not just Advanced). Advanced mode adds the agent's slug and raw tool identifiers alongside the friendly names.

**Q: What happens to jobs when I turn on Emergency Stop?**

A: Pending and in-progress jobs are cancelled immediately. This is logged in Activity. When you restore service, agents that were active are reactivated, but cancelled jobs are not automatically re-run.

**Q: Should I leave Emergency Stop on for security?**

A: No. Emergency Stop is for emergencies. To disable agents normally, go to Agents and toggle them off individually. Emergency Stop is for "something is wrong, lock it down now."

**Q: Can I adjust which tools agents can see?**

A: Yes, but not from Safety Center. Go to Tools to enable/disable tools and adjust risk-level gating. Extreme-risk tools can't be enabled from anywhere — they're hard-blocked.

**Q: What's MCP?**

A: Model Context Protocol (MCP) lets your agents reach external tools/data sources beyond what's built in. The **With MCP on** count on the Active agents card shows how many agents have it enabled.

**Q: What are "Comfort" profiles, and how are they different from "Mode"?**

A: Comfort is the setting you actually pick, on the Approvals page: **Always ask me**, **Auto-approve low risk**, or **Trust more (higher risk)**. Picking a profile sets Mode for you: the first two set Mode to **Supervised**, and "Trust more" sets it to **Autonomous** (and requires you to explicitly accept the added risk first). Safety Center shows both values side by side so you can see the effective setting at a glance.

**Q: How often does the audit integrity check run?**

A: It runs each time you load Safety Center. The **Last verification** card shows the result of that check and how many rows it covered.

