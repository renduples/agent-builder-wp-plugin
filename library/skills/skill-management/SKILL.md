---
name: skill-management
description: "Use this skill whenever the user wants to teach an agent a new workflow, change how an agent handles something it already does, or find/reuse a skill someone else already published. Trigger on requests like 'teach my agent to draft a newsletter', 'make my Content Writer handle support replies a certain way', 'what skills does my agent have', 'find a skill for managing WooCommerce orders', or 'that skill isn't working right, fix it'. Do NOT trigger for deploying an agent somewhere (chat widget, schedule, event trigger) — that's agent deployment, a separate domain."
allowed-tools: manage_skill browse_community_skills search_capabilities
---

# Skill Management

A skill is a reusable set of instructions an agent loads on demand when it recognizes a task the skill covers. Skills are how a user teaches an agent to handle a specific kind of request reliably, without retraining it from scratch each time. Every workflow below writes to the same underlying storage the classic Skills admin screen uses — a change made here shows up there, and vice versa.

## Available Tools

| Tool | When to use |
|---|---|
| `manage_skill` | List, read, create, update, delete, or reset a skill. |
| `browse_community_skills` | Browse or import skills other people have already published (WordPress.org's official repository, or Siteloom's curated recommended feed). |
| `search_capabilities` | Confirm a tool name actually exists before referencing it in a skill, or check which agents are installed. |

## Workflows

### Draft a new skill

1. Interview the user: what should trigger this skill (in their own words, with a few example requests), what steps should the agent follow, and what tools (if any) does it need.
2. Before naming any tool in the skill, confirm it exists — use `search_capabilities` with `type: tool` or ask the user rather than guessing.
3. Draft the full SKILL.md content yourself: a specific trigger `description`, a tool table if tools are involved, numbered workflow steps, and a short rules section. Never ask the user to write markdown.
4. Call `manage_skill` with `action: create`, `name`, `description`, `content` (the body), and `allowed_tools` if applicable. Set `agent_slug` for one agent or `agent_slugs` for several if the user wants it scoped; leave both empty for all agents.
5. Report back in plain language what the agent can now do — not the SKILL.md mechanics. Mention any `warnings` from the result, but don't treat them as a blocker.

### Edit an existing skill

1. Call `manage_skill` with `action: list` (filtered by `agent_slug` if the user named an agent) to find the right one, or `action: get` if the slug/id is already known.
2. Call `action: get` to read its current content.
3. If it's a **core** (built-in) skill (`is_core: true`), confirm with the user before changing it — they'd be customizing something the plugin ships and updates on its own. A skill the user created themselves (`source: local`) needs no such caution.
4. Revise the content and call `action: update` with the same `id`/`slug` and only the fields that changed — unspecified fields keep their previous values.
5. Report the change in plain language.

### Find and reuse a community skill

1. Call `browse_community_skills` with `action: browse` against `source: wordpress` and/or `source: agentic`, based on what the user is looking for.
2. Summarize the relevant matches in plain language (name + what it does) — don't dump raw data.
3. Once the user picks one, call `action: import` with its `slug` and `source`.
4. Confirm it's available now, and ask whether it should apply to one or more specific agents or all of them (skills import as global by default — use `manage_skill action: update` with `agent_slug`/`agent_slugs` to scope it afterward if needed).

### Explain or audit what a skill does

1. Call `manage_skill action: list` (optionally filtered by `agent_slug`) to see what's assigned.
2. Call `action: get` on the specific skill(s) of interest and explain the trigger and behavior in plain language.

### Reset or remove a skill

- To discard customizations on a **core** skill and restore the shipped version: confirm with the user, then `manage_skill action: reset`.
- To delete a skill the user created or fully replaced: confirm with the user, then `manage_skill action: delete`. Note that un-customized core skills can't be deleted — only edited or reset — since they'd just come back on the next plugin update anyway.

## Rules

- Always confirm before editing, deleting, or resetting a **core** skill — that's customizing something Siteloom maintains. Creating a new skill, or editing a local one the user made, is low-risk and doesn't need the same caution.
- Never fabricate a tool name in a skill's `allowed-tools` or tool table — verify it exists first.
- Validation warnings (missing description, etc.) are advisory — mention them, don't block on them.
- If a skill already exists for what's being described, update it instead of creating a near-duplicate — list first when unsure.
- Keep drafted skill bodies focused on one workflow domain — don't combine unrelated tasks into a single skill.
