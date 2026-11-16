---
name: site-maintenance
description: "Use when user wants to clean up auto-drafts, delete spam comments, purge expired transients, toggle XML-RPC, or check/disable file editing in wp-admin."
---

# Site Maintenance Skill

## Available Tools

| Tool | When to use |
|------|-------------|
| `cleanup_auto_drafts` | Delete old auto-draft posts that accumulate when editing is started but not saved. |
| `cleanup_spam_comments` | Permanently delete all spam comments and their meta records. |
| `purge_expired_transients` | Remove expired transient cache entries from the wp_options table. |
| `manage_cache` | Active cache control (HIGH risk, disabled by default): flush object cache, flush/delete transients, reset OPcache, flush rewrite rules, purge page-cache plugins. Supports `dry_run`. |
| `check_caching_status` | Read-only: detect which object/page/opcode caches are active. |
| `toggle_xml_rpc` | Enable or disable the WordPress XML-RPC endpoint. |
| `toggle_file_editing` | Check whether theme/plugin file editing in wp-admin is disabled and get the wp-config.php line to disable it (changes nothing itself). |

## Workflows

### Routine site cleanup

1. Call `cleanup_auto_drafts` with `dry_run: true` to count eligible drafts.
2. Call `cleanup_spam_comments` with `dry_run: true` to count spam.
3. Call `purge_expired_transients` with `dry_run: true` to estimate space savings.
4. Present findings to the user.
5. With user confirmation, run all three again with `dry_run: false`.

### Security hardening

1. Call `toggle_xml_rpc` with `enabled: false` to disable XML-RPC (recommended for most sites).
2. Call `toggle_file_editing` with `allow_editing: false`. If the file editor is still enabled, give the user the returned `wp_config_line` and explain that the site administrator adds it to wp-config.php (Agent Builder does not change this setting itself).
3. Confirm the XML-RPC setting is applied and explain what each recommendation does.

## Rules

- All three cleanup tools (`cleanup_auto_drafts`, `cleanup_spam_comments`, `purge_expired_transients`) are marked destructive — always run `dry_run: true` first unless the user explicitly says to proceed.
- `toggle_xml_rpc` stores its setting in wp_options and is enforced by Agent Builder via a WordPress filter — it requires Agent Builder to remain active to take effect.
- `toggle_file_editing` only reports and recommends: disabling the file editor is done by adding `define( 'DISALLOW_FILE_EDIT', true );` to wp-config.php, which the site administrator does themselves.
- Purging transients is safe for performance but may temporarily slow down pages that rely on cached data as those caches are rebuilt.
- `manage_cache` is HIGH risk and disabled by default (an admin must enable it on Tools). Its `flush_object_cache`, `reset_opcode_cache` and `purge_page_cache` actions can affect other sites sharing the same Redis/Memcached/PHP-FPM/cache instance and cause a temporary load spike — always preview with `dry_run: true` and confirm with the user before running for real.
- `cleanup_spam_comments` deletes records permanently with no undo — confirm with the user before proceeding.
