# M5 final WP.org export and Plugin Check gate

Date: 2026-09-11
Branch tip exported: `release/3.4-wporg` (`21b889e`, plus this packaging commit)
Plugin version: **3.4.0** (`agent-builder.php` `Version:` and `readme.txt` `Stable tag:` match)

This is the final pre-submission packaging gate (#108). The zip itself is a build artifact and is **not** in git.

## How to reproduce

```bash
PATH="/tmp:$PATH" bash bin/export-wporg-tree.sh /tmp/agent-builder
# tree: /tmp/agent-builder
# zip:  /tmp/agent-builder.zip
```

`composer` is not on the default PATH on this machine; `/tmp/composer` is the phar used here (same as #96).

Plugin Check was run against a **fresh copy of that exact exported tree**, rsynced into `experiment.test` as an inactive side-by-side folder so the live `agent-builder` 3.3.97 install was not touched:

```bash
rsync -a --delete /tmp/agent-builder/ \
  /path/to/experiment.test/wp-content/plugins/agent-builder-pc108/
cd /path/to/experiment.test
WP_CLI_PHP_ARGS="-d display_errors=0" wp plugin check agent-builder-pc108 --slug=agent-builder
```

Environment: WordPress 7.1, Plugin Check 2.0.0, PHP 8.5.4, `--mode=new` (default). Vendor is excluded by Plugin Check itself (`.git`, `vendor`, `vendor_prefixed`, `vendor-prefixed`, `node_modules`).

The side-by-side folder was removed after the run.

## Export tree

Clean. Confirmed absent from the exported tree:

- `.fleet-task`, `.git`, `.wordpress-org/`, `screenshots/`, `tests/`, `bin/`, `reports/`, `node_modules/`
- bundled locales (`languages/*.po|mo|json`) — only `agent-builder.pot` + `languages/index.php` remain
- `vendor/mpdf`, `vendor/smalot` (stripped in the export script; PDF tools self-report unavailable)
- `composer.lock`

Production `vendor/` contains: `phpoffice/phpspreadsheet`, `phpoffice/phpword`, `maennchen/zipstream-php`, plus their runtime deps (`markbaker/*`, `psr/simple-cache`, `composer/pcre`, `phpoffice/math`).

## Final zip

| | |
| --- | --- |
| Name | `agent-builder.zip` (plugin-slug basename of the export dest; identical bytes also saved as `agent-builder-3.4.0.zip` for this run) |
| Size | **3.7M / 3,783,732 bytes** |
| Files inside zip (non-directory entries) | **1,901** |
| Zip entries including directories | 2,454 |
| Zip root | single top-level folder `agent-builder/` |

`.gitignore` now ignores `*.zip` and `/dist/` so this binary cannot be committed by accident.

## Plugin Check result

Exactly the two pre-existing documented exceptions. No other ERROR or WARNING.

```
FILE: includes/class-agent-ready-score.php

line 369  col 40  WARNING  WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
  Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: "robots_txt".

line 489  col 0   ERROR    wp_function_not_compatible_with_requires_wp
  Function "wp_get_abilities()" requires WordPress 6.9.0, but your plugin
  minimum supported version is WordPress 6.4.0.
```

### Why these two stay

1. **`robots_txt` WARNING** — `Agent_Ready_Score::local_robots_txt_content()` calls core’s `robots_txt` filter (`includes/class-agent-ready-score.php` around line 369). That is the WordPress core hook name; the plugin does not introduce it. Same false positive documented in `reports/SUBMISSION-NOTES.md` §8.8.

2. **`wp_get_abilities` ERROR** — the call at line 489 is inside `if ( function_exists( 'wp_get_abilities' ) )`. Plugin Check’s `wp_function_not_compatible_with_requires_wp` check does not honour that guard. The rest of the plugin already goes through `WP_Optional_API` (dynamic name + `function_exists`) so static analysis stays quiet; this one remaining direct call is informational (list WooCommerce abilities when the Abilities API is actually present) and cannot run on WP < 6.9. Documented exception; not a real hard dependency on 6.9.

No new findings. The three M4 readme regressions cleared in #96 (`stable_tag_mismatch`, `too_many_tags`, `trimmed_short_description`) remain gone.

## Packaging fixes in this pass

- `.distignore`: exclude `.fleet-task` (would trip `hidden_files` if a fleet worktree is exported; called out in #96), `.env.local`, and `reports/` (internal design/audit notes, including this file, must not ship in the reviewer zip).
- `.gitignore`: `*.zip` and `/dist/`.
- `bin/export-wporg-tree.sh`: after the tree is built, write a sibling `*.zip` and print size + file count.
- `reports/SUBMISSION-NOTES.md` §8.7: packaging bullets updated to match the above.
