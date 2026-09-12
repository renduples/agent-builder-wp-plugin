# Agents

The Agents screen is where you manage every AI agent installed on your site — bundled agents that ship with Agent Builder, agents you create yourself, and agents you add from the community. It works like the WordPress Plugins screen: each agent can be activated, deactivated, or (if it isn't a bundled library agent) deleted.

> Basic mode is a card grid for chatting. Activate, deactivate, delete, connector status, and filters live in Advanced mode on this same page.

## Overview

Open **Agent Builder → Agents** in wp-admin. At the top you'll see:
- The **Agents** heading
- **Add an agent** — opens the Train an Agent wizard (a separate guided flow)
- **This screen** — a Basic / Advanced toggle that applies to the Agents page only (independent of the site-wide Basic / Advanced switch in the admin header)

## Agents sections

### Basic mode (card grid)

Basic mode lists every installed agent as a card. Each card shows:
- **Name**
- **One-line description** (from the agent's manifest; if a custom agent has none, the fallback is "An AI agent for this site.")
- **Chat** — a primary button that opens a conversation with that agent

There are no activate, deactivate, delete, or bulk controls in Basic mode, and no All / Active / Inactive filters. If no agents are installed, the page says **No agents installed yet.**

**Chat** on most agents opens [Agent Chat](https://agentic-plugin.com/docs/chat/) with that agent selected. **Assistant Trainer** is the exception: its Chat link opens the main Agent Builder page (`agent-builder`) with that agent, not Agent Chat.

Agent Chat only talks to agents that are **active** and that your user can access. If you click Chat on an inactive agent, Chat falls back to WordPress Assistant (if it's active) or to the first accessible agent.

### Advanced mode (table)

Switch **This screen** to **Advanced** (the page reloads) to get the plugins-style table.

#### Status filters

Above the table:
- **All** — every installed agent, with a count
- **Active** — agents currently turned on
- **Inactive** — installed but not running
- **Community Agents** — opens [agentic-plugin.com/community-agents](https://agentic-plugin.com/community-agents/) in a new tab (browse-only; this WordPress.org build does not phone home for in-plugin agent updates)

#### Bulk actions

A **Bulk actions** dropdown and **Apply** button sit above and below the table. Options:
- **Activate**
- **Deactivate**
- **Delete**

Select one or more rows with the checkboxes (the header checkbox selects all), choose an action, then **Apply**. Delete asks you to confirm ("Are you sure you want to delete the selected agents?") before it runs. If you click Apply with no action selected, you'll see "Please select a bulk action."; with no rows checked, "Please select at least one agent."

#### Agent column

Each row's name is followed by actions that change with status:

**Active agent**
- **Chat** — same destination as Basic mode
- **Deactivate** — turns the agent off. Success notice: "Agent deactivated."

**Inactive agent**
- **Activate** — turns the agent on. On success: "{name} activated. Chat with this agent now →"
- **Delete** — removes a non-bundled agent. Confirms with "Are you sure you want to delete this agent?" Bundled library agents cannot be deleted (you'll get "Bundled library agents cannot be deleted."). Active agents are deactivated first, then deleted. Success notice: "Agent deleted."

Activation can fail — for example if [Emergency Stop](https://agentic-plugin.com/docs/safety-center/) is on, the agent isn't installed, it requires a premium license, PHP/WordPress version requirements aren't met, or the agent's `abilities.json` fails validation. The error shows as a red notice at the top of the page.

#### Description column

The description is followed by metadata (each piece appears only when the agent has it):
- **Version {n}**
- **By {author}** (linked if the agent has an author URL)
- **Signature: Pass** or **Signature: Mismatch** — shown when the agent has an `abilities.json` manifest. Pass means the signed manifest matches what's on disk; Mismatch means the file changed after it was signed, and MCP tools for that agent stay blocked until it is re-signed
- **Category** (for example `admin`, `content`, `seo`)
- **Capabilities:** a comma-separated list of WordPress capabilities the agent requires

#### Connector columns (MCP, WebMCP, WhatsApp)

These three columns are **status indicators**, not toggles. You don't turn connectors on from this table.

| Column | Green check means | Red X means |
| --- | --- | --- |
| **MCP** | Reachable via MCP | Not reachable. Hover the icon for the reason — commonly "Agent is not active.", "No abilities.json manifest for this agent.", "Manifest signature mismatch — this agent's tools are blocked until it is re-signed.", or "MCP is off by default for this agent — enable it in Settings > MCP." |
| **WebMCP** | Reachable via WebMCP | Not reachable via WebMCP (the site-wide WebMCP switch is off, or this agent has no tools marked for WebMCP exposure) |
| **WhatsApp** | Reachable via WhatsApp | Not reachable via WhatsApp (the per-agent WhatsApp setting is off) |

Enable MCP per agent on [Settings → MCP](https://agentic-plugin.com/mcp-integration/). WebMCP is a separate site-wide bridge. WhatsApp reachability is a per-agent setting — this table only reports it.

### Add an agent

**Add an agent** (next to the page title, both modes) opens **Train an Agent** — a five-step wizard (Basics → Persona → Capabilities → Knowledge → Review) that creates and can activate a new agent. That wizard is its own screen; when you finish, the new agent shows up back here.

## Common tasks

### Starting a chat with an agent

1. Open **Agents**
2. In Basic mode, click **Chat** on the agent's card
3. Agent Chat opens with that agent selected (Assistant Trainer opens the main Agent Builder page instead)

If the agent isn't active, switch this screen to **Advanced**, click **Activate**, then use the "Chat with this agent now" link in the success notice.

### Activating or deactivating an agent

1. Open **Agents**
2. Set **This screen** to **Advanced**
3. Find the agent (use **Active** / **Inactive** filters if the list is long)
4. Click **Activate** or **Deactivate** in the row

Or select several checkboxes, choose **Activate** or **Deactivate** under **Bulk actions**, and click **Apply**.

### Adding a custom agent

1. Open **Agents**
2. Click **Add an agent**
3. Complete the Train an Agent wizard
4. The new agent appears on this page; activate it in Advanced mode if the wizard didn't already

To browse community agents instead, switch to Advanced and click **Community Agents**.

### Checking whether an agent is reachable over MCP

1. Open **Agents** in Advanced mode
2. Look at the **MCP** column for that row
3. Green check: reachable. Red X: hover for the reason
4. If the reason is that MCP is off, enable it for that agent in [Settings → MCP](https://agentic-plugin.com/mcp-integration/)
5. If the reason is a signature mismatch, see [Safety Center](https://agentic-plugin.com/docs/safety-center/) — that agent's tools stay blocked until the manifest is re-signed

### Deleting a user-created agent

1. Open **Agents** in Advanced mode
2. The agent must be inactive to show **Delete** (deactivate it first if needed)
3. Click **Delete** and confirm

Bundled agents that ship inside the plugin cannot be deleted from this screen.

## FAQ

**Q: Where did Activate / Deactivate go?**

A: They're in Advanced mode. Click **Advanced** next to **This screen** (not the site-wide toggle in the header — that one is a different control). Basic mode is chat-only cards.

**Q: I clicked Chat and landed on a different agent.**

A: Agent Chat only lists agents that are active and that your user can access. Inactive agents still have a Chat button in Basic mode, but Chat will fall back to WordPress Assistant or the first accessible agent. Activate the agent in Advanced mode first.

**Q: What's the difference between "This screen" and the site-wide Basic / Advanced switch?**

A: **This screen** changes only the Agents page (card grid vs table) and is remembered per user. The site-wide switch in the admin header is the default for every Agent Builder screen. Agents can be Advanced while the rest of the admin stays Basic.

**Q: Can I update agents from this page?**

A: Not in this WordPress.org build. Remote update checks are off (no phone-home). Use **Community Agents** to browse the public marketplace. There is no "update now" row on this screen here.

**Q: Why is Signature showing Mismatch?**

A: The agent's `abilities.json` no longer matches its signature file. MCP tools for that agent are blocked until the manifest is re-signed. A mismatch is also a reason the MCP column shows a red X.

**Q: Why are MCP / WebMCP / WhatsApp all red?**

A: Those columns start red until each connector is actually on for that agent. MCP is off by default per agent (turn it on in Settings → MCP, and the agent must be active with a valid signed manifest). WebMCP needs the site-wide WebMCP switch plus at least one tool on that agent marked for exposure. WhatsApp needs the per-agent WhatsApp setting on. The table does not toggle any of these.

**Q: Activation failed with an Emergency Stop message.**

A: Emergency Stop is on (from the Dashboard or Safety Center). Restore the agent system there first; you can't activate agents while it's engaged.

**Q: Does deleting an agent remove its files from a bundled install?**

A: No. Bundled library agents are refused with "Bundled library agents cannot be deleted." so the plugin directory isn't vandalised. Only user-installed / user-created agents can be deleted, and they're deactivated first.

**Q: Who can open this page?**

A: Administrators, and anyone granted the agent-management permission. Everyone else gets "You do not have permission to access this page."
