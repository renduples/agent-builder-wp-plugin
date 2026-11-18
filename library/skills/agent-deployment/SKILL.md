---
name: agent-deployment
description: "Use this skill whenever the user wants to put an agent to work — embed it as a chat widget, make it check something on a schedule, or have it react automatically to something happening on the site. Trigger on requests like 'put this agent on my homepage', 'add a chat widget to my site', 'make an agent check my site every morning', 'reply to new comments automatically', or 'add an AI panel to the block editor'. Do NOT trigger for creating or configuring an agent's own behaviour (system prompt, tools, knowledge) — that's agent creation/training, a separate domain."
allowed-tools: manage_agent_shortcode manage_editor_sidebar_agent manage_frontend_modal_agent manage_gutenberg_block_agent manage_admin_bar_launcher manage_scheduled_task manage_event_listener get_agent_list
---

# Agent Deployment

Deploying an agent means choosing where and how it runs: embedded in a page, floating site-wide, inside the editor, inside wp-admin, on a recurring schedule, or in response to a WordPress event. Every channel below writes to the same underlying storage the classic Publish (Deployment) admin screens use — a change made here shows up there, and vice versa.

## Available Tools

| Tool | When to use |
|---|---|
| `manage_agent_shortcode` | A chat widget the user pastes into one specific page, post, or text widget. |
| `manage_frontend_modal_agent` | A floating chat bubble shown to visitors across the whole site (or a subset of pages). |
| `manage_gutenberg_block_agent` | Makes an agent insertable as a block in the WordPress block editor / Site Editor. |
| `manage_editor_sidebar_agent` | An AI panel inside the block editor while writing posts/pages, with draft awareness. |
| `manage_admin_bar_launcher` | Chat access inside wp-admin: a per-agent admin-bar button, or the contextual "Ask AI" launcher on specific screens. |
| `manage_scheduled_task` | A recurring task an agent runs unattended (hourly/twice-daily/daily/weekly). |
| `manage_event_listener` | A trigger that runs an agent automatically when a WordPress action hook fires. |
| `get_agent_list` | See which agents are installed and active before choosing one to deploy. |

## Workflows

### Embed a chat widget on one page

1. Confirm which agent and where it'll be pasted (page, post, or widget).
2. Call `manage_agent_shortcode` with `action: create`, the `agent_slug`, a `label`, and any style preferences (`style`, `height`, `placeholder`).
3. Hand back the exact shortcode string and tell the user to paste it into the page/post/widget content.

### Add a site-wide floating chat widget

1. Confirm which agent, which corner (`position`), and which pages (`pages`) — default to all pages unless the user says otherwise.
2. Call `manage_frontend_modal_agent` with `action: enable`.
3. Confirm it's live — no further action needed from the user, it applies immediately.

### Make an agent available as a block

Call `manage_gutenberg_block_agent` with `action: enable`. Tell the user to search for the agent by name in the block inserter next time they edit a post or page.

### Add an AI panel to the block editor

1. Confirm which agent(s) should appear, which post types (post/page/others), and whether draft content should be shared automatically (`inject_context`).
2. Call `manage_editor_sidebar_agent` with `action: update`, `enabled: true`, and the chosen `agent_slugs`/`post_types`.

### Add wp-admin chat access

- For a per-agent admin-bar button: `manage_admin_bar_launcher` with `target: agent_bar_button`, `agent_slug`, `enabled: true`, and optional `position`/`pages`.
- For the contextual "Ask AI" launcher shown on specific screens (Users, Plugins, etc.): `target: contextual_launcher`, `enabled: true`, `agent_slug`, and `screens`.

### Schedule a recurring task

1. State back the exact prompt the agent will follow and the recurrence (`hourly`/`twicedaily`/`daily`/`weekly`), and get explicit confirmation — this runs unattended, with nobody reviewing the outcome each time.
2. Call `manage_scheduled_task` with `action: create`, `agent_slug`, `prompt`, and `schedule`.
3. Confirm it's set up and mention where the user can review/cancel it later (the classic Scheduled Tasks tab, or by asking you to list/delete it).

### React automatically to a WordPress event

1. Identify the right hook for what the user described (e.g. `comment_post` for new comments, `user_register` for new sign-ups, `publish_post` for newly published posts, a WooCommerce order hook for order events).
2. State back the hook and the exact prompt the agent will follow, and get explicit confirmation — this also runs unattended.
3. Call `manage_event_listener` with `action: create`, `agent_slug`, `hook`, and `prompt`.

## Rules

- Always confirm before creating anything that runs unattended — scheduled tasks and event listeners. Everything else (widgets, blocks, editor sidebar, launchers) is reversible config, safe to just do and report back.
- If a deployment already exists for what's being asked, update it instead of creating a duplicate — list first when unsure.
- After any change, describe the result in plain language ("your Content Writer now appears as a chat bubble on every page"), not the underlying mechanics.
- If no active agent fits the request, check `get_agent_list` for an installed one to activate; if none fits, say so and suggest Assistant Trainer rather than forcing a mismatched agent into the role.
