# Site Passport

Site Passport is this site’s score for how discoverable and safely reachable it is to AI agents — what they can find, and what they can access through the WebMCP Bridge.

> The menu label is **Passport**. The page heading is **Site Passport**. This is the Agent-Ready Score / WebMCP screen. It does not replace [Safety Center](https://agentic-plugin.com/docs/safety-center/) (operator safety) or [Approvals](https://agentic-plugin.com/docs/approvals/) (the high-risk queue).

## Overview

Open **Agent Builder → Passport**. Description: “Your site's passport for AI agents — what they can discover, and what they can access.”

The card is titled **Score & fixes**.

At the top right, a **Basic** / **Advanced** pair applies to this page only (tooltip: “Only changes this screen. Other screens and your site-wide default (Settings → Interface) are unaffected.”). Separate from the **Site-wide** switch in the admin header.

Both modes show the score, the top fixes, the WebMCP master switch, and **Submit to Directory**. Advanced adds the full **All checks** table, the **WebMCP tool exposure** matrix, and directory submission status after you submit.

The Dashboard **Site Passport** card is a summary of this same score (with **View Details →** back here).

## Site Passport sections

### Score and grade

A large number (0–100) and a letter grade:

| Overall | Grade |
| --- | --- |
| 90–100 | A |
| 75–89 | B |
| 60–74 | C |
| 40–59 | D |
| 0–39 | F |

The number is a **weighted** average of eight checks (high-weight checks count more than low-weight ones). It is stored until something refreshes it: **Fix now**, flipping the WebMCP switch, **Submit to Directory**, or the plugin’s weekly re-scan. There is no Rescan button on this page.

### Top fixes

Up to **three** checks whose score is under 90, worst first. Each row is the check’s friendly name, a one-line detail, and one of:

- **Fix now** — a free, one-click fix that runs, then the score is recomputed
- **Fix this with AI Radar (Pro) →** — opens [pricing](https://agentic-plugin.com/pricing/) in a new tab (no in-plugin editor for that check on the free WordPress.org build)
- **No one-click fix yet.** — you change something else (for example activate an agent and expose a commerce tool)

If every check is at 90 or above: **Nothing urgent — every check looks good.**

#### What “Fix now” does (by check)

These are the four checks that can show **Fix now**:

| Check (label on the page) | Fix now does |
| --- | --- |
| **MCP server reachable** | Re-signs `abilities.json` for active agents whose manifest signature is mismatched (does not activate agents or invent a missing manifest) |
| **WebMCP tools registered** | Exposes a small, curated set of public-safe readonly tools (search, and WooCommerce browse/cart-read tools when WooCommerce is active) to the WebMCP frontend. It does **not** expose every low-risk admin tool. It will not overwrite a tool you already turned off. |
| **Approval gate configured** | Turns **off** WebMCP exposure for any currently exposed tool whose risk is above medium. It never lowers a risk value to “make it safe.” |
| **WebMCP discovery manifest** | Turns the WebMCP Bridge **on** (same master switch as the toggle below), which serves `/.well-known/webmcp.json` |

If a fix fails, an error notice: “Could not apply that fix.” (or the server message).

#### Pro-only and manual checks

These never get **Fix now** on this build:

| Check | Instead |
| --- | --- |
| **llms.txt present** | **Fix this with AI Radar (Pro) →** |
| **AI crawler directives in robots.txt** | **Fix this with AI Radar (Pro) →** |
| **Organization/WebSite schema** | **Fix this with AI Radar (Pro) →** |
| **Commerce readiness** | **No one-click fix yet.** |

### Let AI agents access my site

Toggle: **Let AI agents access my site (turn on the WebMCP Bridge)**.

This is the site-wide WebMCP master switch. On: the discovery manifest is served and exposed tools can be called (still gated by risk, same-origin, and login rules). Off: WebMCP is disabled (“The WebMCP Bridge is turned off.”).

The same switch is what **Fix now** uses for the discovery-manifest check. Errors: “Could not change that setting.”

WebMCP is **not** the same as per-agent MCP (Claude Desktop / Cursor). MCP stay on [Settings → MCP](https://agentic-plugin.com/mcp-integration/) and the MCP column on [Agents](https://agentic-plugin.com/docs/agents/).

### Submit to Directory

**Submit to Directory** sends **only this site’s URL** to the public Site Passport directory (`sitepassport.org`) so that service can scan your public `llms.txt` / `robots.txt` / schema / `/.well-known/webmcp.json` itself. It does **not** upload your local score, and it does **not** run on a schedule — only when an administrator clicks the button.

Errors: “Could not submit to the directory.” (or the directory’s error). After a submit, Advanced mode can show **Directory submission: {status} ({date})**.

### All checks (Advanced mode only)

A table of every check:

| Column | What it shows |
| --- | --- |
| **Check** | Friendly name (see the eight checks below) |
| **Category** | Capability exposure, Safety & trust, Discoverability, Bot access control, Content, or Commerce |
| **Score** | 0–100 for that check |
| **Detail** | The current explanation (for example “No llms.txt file found.”) |

#### The eight checks

**MCP server reachable** (Capability exposure, high weight). Only agents that have MCP **turned on** are scored. If MCP is off for every agent, this check is 100 — “nothing to fix” — rather than punishing a locked-down default. Failures are real readiness problems (missing manifest, signature mismatch). **Fix now** re-signs mismatched manifests.

**WebMCP tools registered** (Capability exposure, high weight). 100 if the WebMCP Bridge is on **and** at least one tool is exposed to the frontend; 0 if the bridge is off or nothing is exposed. This is not “more tools = higher score.”

**Approval gate configured** (Safety & trust, high weight). 100 if the approval-queue table exists and every WebMCP-exposed tool is none/low/medium risk. Any exposed high/extreme tool, or a missing table, scores 0.

**llms.txt present** (Discoverability). Looks for `llms.txt` in the site root. Missing = 0; present but not well-formed = 50; well-formed = 100. No HTTP fetch.

**AI crawler directives in robots.txt** (Bot access control). Scores how many known AI crawlers have an **explicit** rule. Silence scores lower than an explicit allow or deny. A blanket “block all crawlers” rule scores 0.

**Organization/WebSite schema** (Content). A proxy: an active SEO plugin (Yoast, Rank Math, AIOSEO) scores 100; WooCommerce without those scores 50; neither scores 0. It does **not** parse the rendered homepage.

**WebMCP discovery manifest** (Discoverability, low weight). 100 when the WebMCP Bridge is on (`/.well-known/webmcp.json` is served); 0 when the bridge is off.

**Commerce readiness** (Commerce). No WooCommerce → 100 (not applicable, not a failure). WooCommerce with no payment gateway → 30. Gateways configured but no write-capable commerce tool exposed via WebMCP → partial. A live WebMCP-exposed ecommerce write tool (for example from Storefront Assistant) → 100. This check has no **Fix now**.

None of these eight checks make an outbound HTTP request. **Submit to Directory** is the only optional external call, and only on click.

### WebMCP tool exposure (Advanced mode only)

A table of tools that are **currently** exposed:

| Column | Meaning |
| --- | --- |
| **Agent** | Agent slug |
| **Tool** | Tool id |
| **Context** | `frontend`, `both`, or similar — where that tool is offered |
| **Risk** | Declared risk on that exposure |
| **Exposed** | Switch (on, because this table only lists exposed tools) |

Empty: **No tools are currently exposed to agents via WebMCP.**

Flip **Exposed** off to un-expose that tool (the row disappears after reload). Turning a high/extreme tool back on is refused: “This tool's risk is too high to expose to WebMCP.” Other errors: “Could not change exposure.”

This matrix is not how you invent a new exposure from scratch — use **Fix now** on **WebMCP tools registered** for the curated defaults, or edit exposure here only for tools already listed.

## Common tasks

### Reading the score

1. Open **Passport**
2. Read the large number and letter
3. Skim the three (or fewer) rows under it — those are the cheapest wins
4. Switch this screen to **Advanced** if you want every check’s category, score, and detail

### Turning on the WebMCP Bridge

1. Flip **Let AI agents access my site (turn on the WebMCP Bridge)** on
2. The discovery-manifest check should go to 100 on the next score refresh
3. If no tools are exposed yet, use **Fix now** on **WebMCP tools registered** (or wait until it appears in the top-three list)

Turning the switch off stops WebMCP until you turn it back on.

### Fixing a free check

1. Find the row with **Fix now**
2. Click it
3. Wait for the score to refresh (the applying state is on that button)

If the detail still fails, read it — for example MCP “not ready” because the agent is inactive has to be fixed on [Agents](https://agentic-plugin.com/docs/agents/), not by re-signing.

### Submitting the site to the public directory

1. Improve public files you care about (`llms.txt`, robots.txt, schema, WebMCP manifest) first — the directory rescans those itself
2. Click **Submit to Directory** (administrators)
3. In Advanced, confirm **Directory submission: submitted (…)** (or read the error notice)

### Un-exposing a WebMCP tool

1. Set this screen to **Advanced**
2. Under **WebMCP tool exposure**, flip **Exposed** off for that row

## FAQ

**Q: Why is my score not 100 if WebMCP is on?**

A: The overall number mixes eight weighted checks. Discoverability items (`llms.txt`, robots.txt, schema) are independent of the bridge. Commerce only affects WooCommerce sites. MCP-server readiness only looks at agents that have MCP enabled.

**Q: Is this the same as MCP in Settings?**

A: No. **MCP** is per-agent, for desktop apps. **WebMCP** is the site-wide browser bridge plus `/.well-known/webmcp.json`. Passport scores both. The toggle on this page is WebMCP only.

**Q: Why does “MCP server reachable” say everything is fine when MCP is off?**

A: MCP is off by default on purpose. Agents you have not opted in are excluded from that check so a fresh, locked-down site is not nagged to enable MCP just to raise a number.

**Q: Can I rescan without changing anything?**

A: Not from a button on this page. The score refreshes after **Fix now**, the WebMCP toggle, directory submit, or the weekly cron. The Dashboard card reads the same stored score.

**Q: What does Submit to Directory send?**

A: The site URL, when an administrator clicks the button. Not API keys, not chat, not your local score. The directory computes its own score from public URLs.

**Q: Why is there no Fix now on llms.txt / robots.txt / schema?**

A: Those three are Pro (AI Radar) on this WordPress.org build. The links go to pricing. You can still add `llms.txt` or robots rules by hand; the next score refresh will see the files.

**Q: Commerce readiness is 100 and I don’t run a shop.**

A: Sites without WooCommerce are scored as not applicable (100), so a brochure site is not penalized for skipping checkout tools.

**Q: Who can open this page?**

A: Anyone granted the settings-management permission (and administrators). **Submit to Directory** is administrators only; other people who can open the page will get an error if they click it.
