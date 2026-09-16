---
name: plugin-manager
description: "Use when user wants to check if a plugin is abandoned or outdated, read a plugin's changelog, check WordPress.org plugin status, or manage plugin auto-updates."
---

# Plugin Manager Skill

## Available Tools

| Tool | When to use |
|------|-------------|
| `get_abandoned_plugins` | Scan all installed plugins and flag those not updated in N months. |
| `get_plugin_changelog` | Fetch the changelog for a plugin from WordPress.org. |
| `get_plugin_maintenance_status` | Get full maintenance stats for a single plugin: last updated, tested-up-to, active installs, support threads. |
| `toggle_plugin_auto_update` | Enable or disable WordPress auto-updates for a specific plugin. |

## Workflows

### Plugin health audit

1. Call `get_abandoned_plugins` with the default `months_threshold: 12`.
2. For each flagged plugin, optionally call `get_plugin_maintenance_status` for full detail.
3. Present the findings: plugin name, last updated date, months since update.
4. Recommend the user consider replacing or removing plugins not updated in over 2 years.

### Read a plugin's recent changes

1. Identify the WordPress.org slug (e.g. "woocommerce", not the plugin filename).
2. Call `get_plugin_changelog` with the slug.
3. Present the changelog in a readable format.

### Install a plugin

Agent Builder does not install plugins on the user's behalf — WordPress.org
guidelines treat that as remote code installation, even with human approval
in the loop. If asked, point the user to Plugins → Add New in wp-admin, or
to the plugin's WordPress.org page if you found it via `get_plugin_changelog`
or `get_plugin_maintenance_status`.

### Manage auto-updates

1. Identify the plugin's file path (e.g. "woocommerce/woocommerce.php").
2. Call `toggle_plugin_auto_update` with `enabled: true` or `false`.
3. Confirm the new state.

## Rules

- Plugin slugs for WordPress.org tools are the folder name only (e.g. "woocommerce"), not the full file path.
- `get_abandoned_plugins` makes one HTTP request per installed plugin (cached 12h) — it may take a moment on sites with many plugins.
