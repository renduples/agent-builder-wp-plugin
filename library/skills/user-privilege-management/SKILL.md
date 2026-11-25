---
name: user-privilege-management
description: "Use this skill whenever the user asks about or wants to change which WordPress roles can administer AgentStructor or use its AI agents, set daily usage limits per role, or allow/block anonymous frontend chat. Trigger on requests like 'which roles can access plugin settings', 'let editors use the audit log', 'stop subscribers from chatting with agents', 'how many messages can each role send per day', or 'can visitors who aren't logged in use the chat widget'. Do NOT trigger for investigating or securing an individual account (last login, inactivity, locking one user) — that's the user-admin skill's domain."
allowed-tools: manage_user_privileges
---

# User Privilege Management

AgentStructor gates two kinds of access by WordPress role: **plugin administration** (dashboard, agents, audit log, tools, settings) and **AI agent interaction** (chat surfaces, viewing the agent list), plus a per-role **daily usage limit** on chat messages/tokens, and a site-wide toggle for **anonymous frontend chat**. Administrators always retain full access no matter what these settings say. Every change here writes to the same storage the classic Settings > Users tab uses — a change made here shows up there, and vice versa.

## Available Tools

| Tool | When to use |
|---|---|
| `manage_user_privileges` | Read the current roles/privileges/limits, grant or revoke a privilege for a role, adjust a role's daily usage limit, or allow/block anonymous chat. |

## Workflows

### Answer "who can do X?"

1. Call `manage_user_privileges` with `action: get`.
2. Find the relevant privilege in `plugin_privileges` or `agent_privileges` by its `label`/`description`, and report which roles currently have it (administrators always do, even if not listed).

### Grant or revoke a privilege for a role

1. Call `action: get` first if you're not already sure of the exact privilege `key` and current state.
2. Confirm with the user exactly what will change — which role, which privilege, and whether it's being granted or revoked — before proceeding, since this is a real trust decision.
3. Call `action: set_privilege` with `category` (`plugin` or `agent`), `key`, `role`, and `allowed`.
4. Report the result in plain language ("Editors can now view the audit log") rather than describing the underlying option.

### Change a role's daily usage limit

1. Call `action: set_usage_limit` with `role` (a role slug, or `anonymous` for not-logged-in visitors) and `queries` and/or `tokens`. `0` means unlimited.
2. Confirm the new limit with the user before or after applying it, since a too-low limit can lock people out of chat mid-day.

### Allow or block anonymous frontend chat

1. Confirm with the user that they understand this lets not-logged-in visitors use `[agentic_chat]` shortcodes and embedded widgets.
2. Call `action: set_anonymous_chat` with `enabled`.

## Rules

- Administrators can't be restricted — don't attempt to toggle a privilege off for the `administrator` role, and say so if asked.
- Always confirm before granting or revoking a plugin/agent privilege — it's a meaningful trust decision, not a cosmetic setting.
- Usage-limit and anonymous-chat changes are lower-stakes but still real — mention exactly what you're changing before or as part of applying it.
- If unsure which exact privilege the user means, call `action: get` and match against the returned `label`/`description` text rather than guessing a key.
