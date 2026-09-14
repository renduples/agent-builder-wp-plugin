# Agentic Library – 11 Pre-Built Agents

> Ready-to-use AI agents that ship with the free Agent Builder plugin, and a guide for building your own.

---

## What's Inside

**11 production-ready agents** ship with Agent Builder (WordPress.org free edition). Each is fully functional out of the box and can be customised.

| Slug | Name |
|------|------|
| `agent-orchestrator` | Agent Orchestrator |
| `assistant-trainer` | Assistant Trainer |
| `content-writer` | Content Writer |
| `editorial-director` | Editorial Director |
| `seo-optimizer` | SEO Optimizer |
| `site-health-sentinel` | Site Health Sentinel |
| `skills-assistant` | Skills Assistant |
| `storefront-assistant` | Storefront Assistant |
| `support-triage` | Support Triage |
| `user-assistant` | User Assistant |
| `wordpress-assistant` | WordPress Assistant |

More agents: [Community Agents](https://agentic-plugin.com/community-agents/).

---

## Agents

### Agent Orchestrator (`agent-orchestrator/`)
Deploys other agents to chat widgets, scheduled tasks, and triggers via natural language instead of the technical Publish screens.
- Creates shortcodes, frontend modals, and Gutenberg block deployments
- Sets up scheduled tasks and event listeners
- Manages admin bar launchers and editor sidebar assignments

**Category:** Developer

---

### Assistant Trainer (`assistant-trainer/`)
Meta-agent that trains new AI assistants from natural language descriptions.
- Analyses requirements and generates agent scaffolding
- Creates tool schemas and system prompts
- Validates agent code

**Category:** Developer

---

### Content Writer (`content-writer/`)
Creates, edits, and publishes posts and pages.
- Drafts posts from outlines or descriptions
- Rewrites for readability and tone
- Manages categories, tags, and structure

**Category:** Content

---

### Editorial Director (`editorial-director/`)
Team lead for content operations — plans work and coordinates specialist agents.
- Plans editorial work
- Delegates writing and SEO subtasks
- Pulls results into one coherent plan

**Category:** Content

---

### SEO Optimizer (`seo-optimizer/`)
Audits posts and pages for on-page SEO and proposes concrete improvements.
- On-page SEO audits
- Titles, metadata, and internal links
- Finds content that needs attention

**Category:** SEO

---

### Site Health Sentinel (`site-health-sentinel/`)
Read-only watchdog for site health, performance, and security signals.
- Performance and database checks
- PHP errors, plugin updates, cron
- Explains what to fix and why

**Category:** Maintenance

---

### Skills Assistant (`skills-assistant/`)
Creates and edits Skills — reusable instruction bundles that teach any agent a new workflow — via chat instead of the raw SKILL.md editor.
- Writes new Skills from a plain-language description
- Edits and versions existing Skills
- Browses and imports community Skills

**Category:** Developer

---

### Storefront Assistant (`storefront-assistant/`)
Helps a visitor browse your WooCommerce catalog and build a cart — in wp-admin chat, or directly in the browser via the WebMCP Bridge so an AI browser agent visiting your store can shop on a visitor's behalf.
- Browses products and checks the visitor's own cart
- Adds items to cart and updates quantities
- Never places an order or takes payment — checkout stays on the store's own checkout page

**Category:** Ecommerce

---

### Support Triage (`support-triage/`)
Triages comments and form submissions.
- Summarises requests
- Suggests priority and category
- Drafts replies for your review

**Category:** Support

---

### User Assistant (`user-assistant/`)
Helps manage site people and accounts.
- Reviews registrations and inactive accounts
- Flags risky privileged users
- Drafts member emails (with approval)

**Category:** Users

---

### WordPress Assistant (`wordpress-assistant/`)
Your guide to WordPress and Agent Builder for new users.
- Answers questions about the plugin
- Helps with settings and getting started
- Recommends the right specialist agent

**Category:** Starter

---

## Quick Start

1. **Activate in WordPress:**
   - Go to **Agent Builder → Agents**
   - All 11 bundled agents appear automatically
   - Click **Activate** on any agent

2. **Start using:**
   - Open the chat interface
   - Select an agent and start typing

### Example Usage

**Content Writer:**
> "Draft a blog post about WordPress security best practices"

**SEO Optimizer:**
> "Audit my latest 5 posts for SEO issues"

**Site Health Sentinel:**
> "Run a full health check on my site"

---

## Customising Agents

Each agent here is plain data (JSON + a text prompt, no PHP) — edit any bundled agent's files directly and Agent Builder picks up the change (a bundled agent's `abilities.json` needs re-signing after an edit; see below). See the [Developer Documentation](https://agentic-plugin.com/documentation/#doc-developers) for the full reference.

---

## Building Your Own Agent

An agent is a folder under `library/agents/{slug}/` with four pieces:

```
library/agents/my-agent/
├── agent.json               # Identity, tools list, suggested prompts, welcome message
├── abilities.json           # The tool allowlist + risk level per tool (security boundary)
├── templates/
│   └── system-prompt.txt    # The agent's instructions ({wp_version} etc. are expanded at runtime)
└── README.md                # Same format as this file's agent entries — required for marketplace listing
```

### `agent.json`

```json
{
  "$schema": "https://agentic-plugin.com/schemas/agent/v1.json",
  "slug": "my-agent",
  "name": "My Agent",
  "description": "One or two sentences — shown in the Agents list and marketplace card.",
  "category": "content",
  "icon": "🤖",
  "version": "1.0.0",
  "author": "Your Name",
  "author_uri": "https://example.com",
  "capabilities": [ "edit_posts" ],
  "tools": [ "list_posts", "get_post_content" ],
  "suggested_prompts": [ "Do X", "Do Y" ],
  "welcome_message": "Shown when a user first opens the chat with this agent."
}
```

`category` must be one of the values `Agent_Manifest_Validator::ALLOWED_CATEGORIES` declares: `content`, `admin`, `ecommerce`, `frontend`, `developer`, `seo`, `security`, `media`, `support`. `tools` must name tools that actually exist under `library/tools/` (or WordPress core abilities) — an agent can only ever call what it lists here.

### `abilities.json` — the security boundary

Every tool the agent can call needs a `risk` level, which controls whether a call runs immediately or pauses in the Approvals Queue for the site owner to review:

| Risk | Behaviour |
|------|-----------|
| `none` | Runs immediately — reads and safe queries only |
| `low` | Runs immediately, logged |
| `medium` | Runs immediately in autonomous mode, requires approval in supervised mode |
| `high` | Always requires approval — real trust decisions (privilege grants, deletions) |
| `extreme` | Hard-blocked, no approval bypass (e.g. arbitrary command execution) |

```json
{
  "$schema": "https://agentic-plugin.com/schemas/abilities/v1.json",
  "version": "2.0",
  "agent": "my-agent",
  "description": "Tools required by My Agent",
  "abilities": {
    "list_posts": { "risk": "none" },
    "create_post_content": { "risk": "medium", "reason": "Creates a new post or page with content" }
  }
}
```

Set risk as low as the tool's own intrinsic default allows — a manifest can only ever raise a tool's risk above its registered default, never lower it below it. Always include a short `reason` for anything above `none`; it's shown to the reviewer/approver.

### `templates/system-prompt.txt`

Plain text instructions for the LLM — see any bundled agent's file for the expected shape (role, `== Deployment ==` context handling, `== Workflow ==` steps, tone/constraints). Keep it focused: list what the agent should do, how it should sequence tool calls, and any hard "don't do X" rules.

### Fastest path: let Assistant Trainer or Agent Orchestrator do it

Rather than hand-writing these files, you can describe the agent you want in chat to the bundled **Assistant Trainer** agent (or use its `create_agent_files`/`generate_agent` tools directly) — it scaffolds all four files from a natural-language description, then you refine from there.

## Listing on the Marketplace

The [Community Agents marketplace](https://agentic-plugin.com/community-agents/) is where developers publish agents beyond the 11 bundled here. In the free/WordPress.org plugin, Community Agents is **browse-only** — visitors can preview and download an agent's files, but not one-click remote-install (that requires Agent Builder Pro or a connector).

To list an agent:
1. Package the four files above exactly as they appear in this directory (a real `agent.json` + `abilities.json` + `templates/system-prompt.txt` + `README.md`, no PHP).
2. Make sure `abilities.json` only requests tools that already ship with Agent Builder (free or Pro) — a listed agent can't bundle its own custom tool code.
3. Submit via the marketplace's own submission flow at [agentic-plugin.com/community-agents/](https://agentic-plugin.com/community-agents/).

---

## Need Help?

- [Documentation](https://agentic-plugin.com/documentation/)
- [Support](https://agentic-plugin.com/support/)
- [Changelog](https://agentic-plugin.com/changelog/)
