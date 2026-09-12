# M2: Safety Center — Design Doc

Status: **design only**, no implementation. This proposes a new admin screen that
makes Agent Builder's existing safety controls visible to a non-technical site
owner without changing how those controls work underneath.

Base for file/line references: `release/3.4-wporg` at commit `be24d7b`.

---

## 1. Current-state inventory: what already exists

Agent Builder already has real safety machinery; the gap is that it is spread
across Tools, Approvals, Activity, Passport, Settings, and REST endpoints.

### 1.1 Tool risk model already exists and is mature

The core primitive is `Agentic\Risk_Level`, which defines five tiers:
`none`, `low`, `medium`, `high`, `extreme`
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-risk-level.php:37-61`).
It also defines:

- human-readable labels (`:85-94`)
- the enforcement ladder (`allow` / `confirm` / `queue` / `block`) by risk and
  mode (`:148-187`)
- a `BASELINE_RISKS` floor map for especially sensitive tools (`:212-321`)

That baseline map already contains product-ready plain-language rationale worth
reusing almost verbatim. Examples:

- `install_plugin_from_url` is HIGH because it executes or installs code
  (`:235-250`)
- `run_wp_cli` is EXTREME because it is arbitrary shell execution and should be
  hidden from the LLM entirely (`:240-249`)
- `force_password_reset` is HIGH because it is account-takeover surface
  (`:264-266`)
- `wc_create_refund` is HIGH because it moves money (`:267-269`)

### 1.2 Tool registry already exists

`Agentic\Tools_Registry` is the database-backed source of truth for tool
metadata, enabled state, category, and `risk_level`
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-tools-registry.php:3-8`,
`:79-115`). It already supports:

- reading all tools via `get_all()` (`:83-115`)
- enabling/disabling tools (`:157-182`)
- bulk applying a maximum risk profile (`:185-236`)
- computing the highest risk among enabled tools (`:238-253`)

The existing React Tools payload already returns `enabled_count`,
`disabled_count`, and `enabled_max_risk`
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-pages-rest.php:761-810`).
So Safety Center does **not** need a new storage model for risk inventory;
only a new page payload or shared helper that groups the same registry data by
risk tier.

### 1.3 Risk badges already exist in both tool-management UIs

This was pre-researched and checks out.

- Classic PHP Tools UI: `admin/tools.php` renders a colored badge with icon and
  label next to each tool toggle using `$agentic_risk_styles`,
  `$agentic_risk_labels`, and `$agentic_risk_icons`
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/admin/tools.php:415-444`).
- React Tools UI: `src/admin-pages/index.js` defines a shared
  `RISK_EXPLANATIONS` map (`:19-27`) and renders the badge plus `InfoTip` per
  row (`:660-680`).

Implication: Safety Center should **not** duplicate per-tool risk education from
scratch. It should roll that data up into counts and summaries, then deep-link
back to Tools for edits.

### 1.4 High-risk enable confirmation does not exist yet

This was also pre-researched and checks out.

In the classic PHP Tools screen, the toggle's `change` handler reads
`this.checked` and POSTs the new enabled state immediately
(`/home/runner/work/agent-builder-wp/agent-builder-wp/admin/tools.php:536-555`),
with revert only on AJAX failure (`:556-568`). There is no confirmation gate in
that flow.

This is the clearest concrete UX gap for M2.

### 1.5 Approvals queue already exists

There is already a dedicated Approvals screen at
`admin.php?page=agentic-approvals`:

- menu registration:
  `/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-menu-handler.php:190-207`
- classic page copy: "Review and approve actions requested by AI agents before
  they are executed"
  `/home/runner/work/agent-builder-wp/agent-builder-wp/admin/approvals.php:121-130`
- React payload: pending rows, pending count, agent mode, and approval comfort
  preferences
  `/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-pages-rest.php:1009-1040`

The product model is consistent across code:

- `medium` risk pauses for confirmation (`class-risk-level.php:181-186`)
- `high` risk queues for approval (`:181-184`)
- user-facing approval copy already says "Nothing happens until you decide"
  in `APPROVAL_ACTION_HINT`
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/src/admin-pages/index.js:29-32`)

### 1.6 Audit log already exists, and tamper-evidence already exists

The Activity/Audit screen already exists:

- menu registration:
  `/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-menu-handler.php:226-235`
- classic audit-page description:
  `/home/runner/work/agent-builder-wp/agent-builder-wp/admin/audit.php:59-66`

More importantly, `Audit_Log_Integrity` already hash-chains the audit log so
that edited or deleted historical rows become detectable
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-audit-log-integrity.php:3-19`).
`verify_chain()` walks the chain and returns `valid`, `checked`,
`broken_at_id`, and `chain_start_id` (`:150-223`).

This is already surfaced in two places:

- admin-facing Activity payload computes integrity for the Audit tab
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-pages-rest.php:1463-1469`,
  `:1646-1647`)
- admin-gated REST endpoint `agentic/v1/inventory/integrity`
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-inventory-rest.php:53-79`,
  `:191-199`)

### 1.7 Per-agent inventory already exists

`Inventory_REST` already provides an admin-gated site inventory of active
agents, declared tools, and each tool's **effective** risk tier:

- route registration:
  `/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-inventory-rest.php:60-79`
- capability gate:
  `:82-93`
- active-agent listing:
  `:100-118`
- per-agent tool inventory using
  `Abilities_Manifest::get_effective_risk()`:
  `:141-189`

This is exactly the backend data source Safety Center needs for its
"per-agent tool scopes" section. No new database state is required.

### 1.8 Emergency Stop already exists and is already exposed in the UI

`Emergency_Stop` is the site-wide kill switch. When enabled it:

- snapshots active agent and provider state (`enable()`,
  `/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-emergency-stop.php:62-145`)
- deactivates all agents (`:102-121`)
- cancels pending/processing jobs (`:123-129`)
- disconnects providers and clears the default provider (`:131-137`)
- blocks chat, new jobs, and activation while active (`:5-11`)

It is already visible in current UI surfaces:

- Dashboard quick-actions card shows "Disable All Agents"
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/src/dashboard-app/index.js:795-806`)
- Settings UI exposes a ToggleControl with confirmation copy
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/src/settings-app/index.js:1739-1767`)

### 1.9 Site Passport / Agent-Ready Score already exists, but should stay distinct

The current "Passport" screen is registered as `agentic-agent-ready`
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-menu-handler.php:211-224`).
Its payload describes it as "Your site's passport for AI agents — what they can
discover, and what they can access"
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-pages-rest.php:2198-2205`).

`Agent_Ready_Score::compute()` scores eight categories, only one of which is
explicitly "Safety & trust" via `approval_gate_configured`
(`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-agent-ready-score.php:105-131`,
`:248-279`).

Conclusion: Safety Center should **cross-link** to Passport, not absorb it.
Passport is about readiness/discoverability/exposure; Safety Center is about
operator controls, approvals, integrity, and incident response.

### 1.10 Additional safety machinery worth surfacing

One more safety-related mechanism belongs in Safety Center even though it was
not listed in the brief: manifest integrity blocking.

- `Abilities_Manifest::verify_integrity()` exists to detect tampered
  `abilities.json`
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-abilities-manifest.php:620-643`)
- agent execution blocks all tools if manifest integrity fails
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-agent-controller.php:333-345`)
- MCP exposure also blocks the agent when manifest integrity fails
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-relay-connect.php:381-390`)

Safety Center does not need a whole section for this, but it should mention it
in the per-agent scopes area as part of "why you can trust this inventory."

---

## 2. Proposed Safety Center screen layout

Create a new submenu page, conceptually `admin.php?page=agentic-safety-center`,
that consolidates existing signals into one owner-facing screen.

### 2.1 Section A — Safety overview (top summary cards)

Purpose: give a non-technical owner a one-screen answer to "Is my site set up
safely for AI agents right now?"

Show 5 cards:

1. **Tool risk inventory**
   - total enabled tools
   - total disabled tools
   - highest enabled risk tier
   - per-tier counts: NONE / LOW / MEDIUM / HIGH / EXTREME
   - CTA: **Review tools**

   Data source: existing `Tools_Registry::get_all()` and/or the existing Tools
   payload (`class-admin-pages-rest.php:761-810`).

   **Backend support needed?**
   - No new storage.
   - Likely a small new helper/page payload field for per-tier counts.

2. **Approvals status**
   - pending approvals count
   - current operating mode (`disabled` / `supervised` / `autonomous`)
   - current comfort profile (`Always ask me` / `Auto-approve low risk` /
     `Trust more`)
   - CTA: **Open approvals**

   Data source: existing approvals payload
   (`class-admin-pages-rest.php:1009-1040`, `:1048-1083`, `:1118-1161`).

   **Backend support needed?** No.

3. **Audit log integrity**
   - status pill: **Verified** / **Needs attention**
   - rows checked
   - if invalid, first broken row ID
   - explanatory line that this is tamper-evidence, not tamper-prevention
   - CTA: **Open Activity**

   Data source: `Audit_Log_Integrity::verify_chain()` via existing helper or
   existing REST endpoint (`class-audit-log-integrity.php:164-223`,
   `class-inventory-rest.php:191-199`).

   **Backend support needed?** No new storage. Prefer an internal helper call,
   not an HTTP loopback.

4. **Emergency Stop**
   - current state: On / Off
   - explanatory text about what the switch does
   - primary action: **Disable All Agents** when off, **Restore agent system**
     when on

   Data source: `Emergency_Stop::is_active()` and existing toggle actions
   (`class-emergency-stop.php:45-49`, `:62-145`, `:147-227`).

   **Backend support needed?** Only page wiring to reuse the existing toggle
   flow.

5. **Active agents**
   - count of active agents
   - count of active agents with at least one HIGH-risk tool
   - count of active agents with MCP enabled
   - CTA: **View agent scopes**

   Data source: `Inventory_REST::get_inventory()` (`class-inventory-rest.php:100-118`).

   **Backend support needed?** No new storage; only aggregation.

### 2.2 Section B — Risk inventory

This is the first required floor section from the brief.

Layout:

- horizontal risk-tier strip: None / Low / Medium / High / Extreme
- each tile shows:
  - enabled count
  - disabled count
  - 1-sentence explanation
  - 1-3 representative examples pulled from `BASELINE_RISKS`

Below the strip:

- "Highest-risk tools currently enabled" list
- "Recently enabled high-risk tools" is **not** currently available from real
  plugin data, so do **not** design it unless new audit-query work is added
  later
- CTA buttons:
  - **Manage tools**
  - **Open approval settings**

Why this section matters: the current Tools screen is excellent for editing, but
it still assumes the owner starts from a tool list. Safety Center should start
from consequences and exposure.

### 2.3 Section C — Approvals and human control

This is the owner-facing explanation layer for the existing queue.

Show:

- current mode card (Disabled / Supervised / Autonomous)
- current comfort profile card
- pending approvals list preview (top 3-5 items)
- plain-language note describing which risk tiers auto-run, confirm, queue, or
  block
- CTA: **Open full Approvals queue**

Data sources already exist. No new backend support needed.

### 2.4 Section D — Audit-log integrity

This is the second required floor section from the brief.

Show:

- "Last verification" status block
- result of `verify_chain()`
- short explanation of what hash-chaining means
- note that pre-feature rows may predate the chain and therefore define the
  chain start (`chain_start_id`)
- CTA: **View raw Activity / Audit log**

If invalid, show a red incident card with:

- "Audit log may have been altered after the fact"
- `broken_at_id`
- recommended next steps: pause agents, export logs, review hosting/database
  access

### 2.5 Section E — Per-agent tool scopes

This is the third required floor section from the brief.

Show one card/table row per active agent:

- agent name + version + author
- MCP enabled yes/no
- tool counts by risk tier
- highest effective risk tier
- list of declared HIGH/EXTREME tools first, then a collapsible list of the
  rest
- integrity state note: "tool list blocked if manifest signature fails"
- CTA: **Manage this agent / manage tools**

Data source: current inventory endpoint already returns the agent list and the
per-tool effective risk tier actually enforced at runtime
(`class-inventory-rest.php:141-189`).

No new backend support is required unless the team wants pre-computed per-agent
risk buckets for convenience.

### 2.6 Section F — Emergency Stop

This is the fourth required floor section from the brief.

Show:

- current state
- exact effect summary
- prominent destructive action button/toggle
- if currently active, restoration summary using the stored snapshot
- note that chat, new jobs, and agent activation are blocked while active

Use the current emergency-stop confirmation language as the baseline, because it
already accurately reflects behavior (`src/settings-app/index.js:1753-1763`).

### 2.7 Section G — Relationship to Site Passport

Small cross-link panel only, not merged content.

Suggested block:

> **Looking for AI discoverability and access, not safety controls?**
> Visit Site Passport to see what outside AI systems can discover and reach on
> this site.

Rationale: the Passport screen already owns readiness/discoverability, and its
payload/data model is broader than safety alone
(`class-admin-pages-rest.php:2198-2210`,
`class-agent-ready-score.php:105-131`).

---

## 3. Plain-language copy proposals

These should be real strings, not placeholders.

### 3.1 Risk tier explanations

**No Risk**
> Read-only. The agent can look things up, but it cannot change your site.

**Low Risk**
> Usually safe to run automatically. These tools may read information that can
> include personal data, but they do not change your site.

**Medium Risk**
> Changes something. The agent should pause and ask before using these tools.

**High Risk**
> Significant, bulk, account-sensitive, or money-moving actions. These do not
> run immediately — they wait for a human decision in the Approvals queue.

**Extreme Risk**
> Too risky to allow. These tools are hidden from agents entirely and should not
> be enabled for normal use.

### 3.2 Approvals queue explanation

> When an agent wants to make an important change, it stops here first.
> Nothing runs until you approve it. Approve lets that one action continue;
> reject cancels it.

Optional helper line under mode/profile:

> Your current safety settings decide which actions run automatically, which ask
> in the moment, and which wait here for review.

### 3.3 Audit-log tamper-evidence explanation

> This activity log is tamper-evident. Each entry is linked to the one before
> it, so if someone edits or deletes a later entry after the fact, the
> verification check fails.

Second line:

> This does not stop database access by itself. It gives you evidence if the
> history can no longer be trusted.

### 3.4 Kill-switch explanation

> Emergency Stop turns off every active agent, cancels pending and in-progress
> jobs, disconnects AI providers, and blocks new agent activity until an
> administrator restores service.

### 3.5 Why HIGH tools are high-risk: reusable examples

These examples should be used as inline helper copy or tooltip text in Safety
Center and in the future confirmation dialog:

- **Install plugin from URL** — "installs code on your site"
- **Reset password** — "can affect account access"
- **Create refund** — "moves money"
- **Delete form** — "can permanently remove data"
- **Git push / git pull / git commit** — "changes the deployed codebase"

Those are directly grounded in `BASELINE_RISKS`
(`class-risk-level.php:235-320`).

---

## 4. Where it lives in the nav, and Basic vs Advanced

M1's direction was that Basic mode should hide complexity without hiding core
capabilities, and that safety copy must stay plain-language and owner-facing
(`/home/runner/work/agent-builder-wp/agent-builder-wp/reports/m1-modes-design.md:197-247`).
That argues against making Safety Center Advanced-only.

### Recommendation

Add **Safety Center** as a normal submenu page under Agent Builder, placed:

- after **Approvals**
- before **Passport**

That ordering matches the owner's mental model:

1. what can agents do?
2. what is waiting for my approval?
3. how safe is the system overall?
4. what can outside AI systems discover/access?
5. what happened in Activity?

### Basic mode

Basic mode should be the default audience for this screen.

Show:

- summary cards
- plain-language explanations
- simple status pills
- short previews, not raw tables
- clear CTA buttons to Tools / Approvals / Activity / Passport

### Advanced mode

Advanced mode should add:

- full per-agent risk buckets
- full HIGH/EXTREME tool lists per agent
- raw integrity details (`checked`, `broken_at_id`, `chain_start_id`)
- direct links to inventory/integrity technical endpoints where appropriate

### Screen-mode behavior

The implementation model should match other admin-pages screens:

- always visible in nav
- Basic/Advanced changes the content of the page, not whether the page exists
- use the existing per-screen mode pattern from
  `Admin_Menu_Handler::is_advanced_mode()`
  (`/home/runner/work/agent-builder-wp/agent-builder-wp/includes/class-admin-menu-handler.php:297-327`)

---

## 5. High-risk tool enable confirmation — exact proposed UI

This is the concrete gap M2 should specify clearly.

### Trigger

When a site owner changes a tool from **off → on** and that tool's
`risk_level` is:

- `high`: intercept before saving and show a confirmation modal
- `extreme`: intercept before saving and show a blocking modal with no enable
  path

Do **not** prompt for `low` or `medium`; that would over-prompt and train users
to click through warnings.

### Why not use native `confirm()`?

A native `confirm()` is acceptable for the Emergency Stop because it is one
site-wide action with short copy already in use
(`src/settings-app/index.js:1753-1763`).
It is **not** ideal here because high-risk tool enablement needs more context:
what the tool does, why it is high-risk, what guardrail still applies, and what
screen to review next.

So this should be a **real modal**, not `window.confirm()`.

### HIGH-risk modal copy

Title:
> Enable high-risk tool?

Body:
> **{Tool name}** can make a significant change to your site.
>
> Why this is high-risk: {plain-language reason from `BASELINE_RISKS` or
> `RISK_EXPLANATIONS`}.
>
> If an agent uses this tool later, the action will still wait for human review
> in the Approvals queue before it runs.

Secondary note:
> Enabling this tool makes it available to eligible agents. It does not run the
> tool immediately.

Buttons:

- **Cancel**
- **Enable tool**

Optional checkbox if the team wants stronger friction:

- `I understand this tool can make significant changes to my site.`

I would keep the checkbox **out** of the first version unless user testing says
owners are still enabling HIGH tools too casually.

### EXTREME-risk modal copy

Title:
> This tool cannot be enabled

Body:
> **{Tool name}** is marked Extreme Risk.
>
> Extreme-risk tools are hidden from agents entirely and blocked from running,
> because they are too risky for normal use.

Buttons:

- **Close**
- optional tertiary link: **Learn why**

There should be **no** primary enable button for EXTREME.

### Where the modal appears

Primary location: the **Tools** screen, because that is where the change is
made today.

Safety Center should reinforce this with a CTA such as:

> High-risk tools require extra care when you enable them.
> Review them in Tools.

That keeps Safety Center as the education/summary layer and Tools as the edit
surface.

### Implementation note for later phases

The current classic toggle posts immediately on `change`
(`/home/runner/work/agent-builder-wp/agent-builder-wp/admin/tools.php:536-555`).
So implementation will need to intercept before the AJAX POST, not after.

---

## 6. Phased implementation plan

Follow the same independently reviewable style as M1.

### Phase 1 — New screen shell and overview cards (**S**)

- register a new submenu page and page payload
- render Safety Center with Basic-mode overview cards only
- reuse existing data sources for:
  - enabled/disabled tools
  - approvals pending count
  - emergency-stop state
  - active-agent count
- add cross-links to Tools, Approvals, Activity, and Passport

Reviewable outcome: a non-technical owner can finally find a single safety
screen.

### Phase 2 — Risk inventory and per-agent scopes (**M**)

- add per-tier tool counts
- add highest-risk-enabled summary
- add per-agent scope cards/table driven by inventory data
- surface HIGH/EXTREME tools prominently per agent

Reviewable outcome: owner can answer "which agents can do risky things?"
without reading raw manifests or browsing each agent individually.

### Phase 3 — Audit integrity and incident messaging (**S**)

- surface `verify_chain()` results in Safety Center
- add invalid-chain incident state and next-step copy
- link through to Activity for raw follow-up

Reviewable outcome: owner can answer "can I trust the recorded history?"
from the safety screen.

### Phase 4 — High-risk enable confirmation on Tools (**M**)

- add real modal for HIGH risk off→on toggles
- add blocking modal for EXTREME risk
- reuse the same explanation copy in classic and React tool-management UIs
- log the enable event as normal through existing tool-state change paths

Reviewable outcome: the main UX gap identified in M2 is closed.

### Phase 5 — Advanced-mode drill-downs and polish (**S**)

- add richer Advanced-mode tables/details
- expose raw integrity metadata in Advanced only
- add "Safety Center" dashboard card or quick-link if desired
- align helper copy across onboarding, bundled-agent descriptions, Safety
  Center, and readme

Reviewable outcome: safety becomes a visible product pillar, not just hidden
machinery.

---

## Final recommendation

Build Safety Center as a **new, always-visible owner-facing screen with both
Basic and Advanced views**.

Do **not** merge it into Site Passport.
Do **not** duplicate the Tools editor.
Do **use** it to unify risk inventory, approvals, audit integrity, kill switch,
and per-agent scopes into one understandable narrative:

1. what agents are allowed to do
2. what requires human review
3. whether the history is trustworthy
4. how to stop everything immediately if needed

That positioning best supports the WP.org programme goal that Agent Builder be
seen as "actively built for AI-agent safety," not merely safety-capable in
source code.
