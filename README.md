# Agent Builder

**Version:** 3.4.0

Free [Agent Builder](https://agentic-plugin.com/) WordPress plugin.

- **Plugin slug:** `agent-builder`
- **License:** GPL-2.0-or-later
- **Requires:** WordPress 6.4+, PHP 8.1+
- **Docs / product site:** https://agentic-plugin.com/documentation/
- **Community agents:** https://agentic-plugin.com/community-agents/

Create, train, and orchestrate AI agents in WordPress with built-in safety using simple job descriptions — approval gates, risk audits, tamper-proof logs, a Basic/Advanced interface split, MCP and WebMCP.

## 11 agents included free

| Agent | Role |
|-------|------|
| **Content Writer** | Researches, writes, edits, and formats blog posts and pages |
| **SEO Optimizer** | Audits on-page content and proposes keyword and meta improvements |
| **Site Health Sentinel** | Continuously checks performance, database health, and security alerts |
| **Support Triage** | Summarizes customer comments, reviews form submissions, and drafts replies |
| **WordPress Assistant** | Onboards new users and helps troubleshoot core settings |
| **Assistant Trainer** | Builds new specialized AI agents from a plain-English job description |
| **Agent Orchestrator** | Deploys assistants as frontend chat widgets, admin launchers, or background jobs |
| **Editorial Director** | Plans editorial calendars and coordinates publishing workflows |
| **User Assistant** | Manages member outreach, onboarding, and role-based permissions |
| **Skills Assistant** | Discovers and imports community skills to teach agents new capabilities |
| **Storefront Assistant** | Helps visitors browse your WooCommerce catalog and build a cart, including via WebMCP |

More at [Community Agents](https://agentic-plugin.com/community-agents/).

## Install from source

1. Clone into `wp-content/plugins/agent-builder` (or symlink).
2. Optional document tools: `composer install --no-dev`
3. Optional rebuild admin React: `npm ci && npm run build`
   Pre-built assets already ship in `build/`.
4. Activate **Agent Builder** in WordPress.

## Project structure

| Path | What's there |
|------|--------------|
| `includes/` | Core PHP: agent registry, tool executor, risk levels, audit log, REST API, admin menu |
| `library/tools/` | Every tool an agent can call — one self-contained class per directory (`get_description()`, `get_parameters()`, `execute()`) |
| `library/agents/` | The 11 bundled agents — see [`library/README.md`](library/README.md) for the four-file format and how to build your own |
| `admin/` | Classic PHP admin screens (non-React) |
| `src/` | React admin surfaces (Dashboard, Safety Center, Settings, etc.), built via `@wordpress/scripts` into `build/` |
| `templates/` | Frontend chat widget and modal markup |

Adding a new tool means creating a directory under `library/tools/`, declaring its risk level (`includes/class-risk-level.php` documents the tiers and the reasoning behind each floor), and registering it in the relevant agent's `abilities.json`.

## Development

- `npm run start` — watch mode for the React admin (`src/`)
- `npm run build` — production build into `build/`
- `npm run lint:js` / `npm run format:js` — `@wordpress/scripts` lint/format for `src/`

### PHP tests

A real PHPUnit suite runs against a live WordPress core test install (`WP_UnitTestCase`), not a mocked sandbox:

```
composer install
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
composer test
```

`bin/install-wp-tests.sh` needs a local MySQL to provision a test database against, and `svn` to pull the WordPress core test library — it's the standard WP-CLI plugin-scaffold installer, so any WordPress plugin dev environment already has both. It downloads WordPress core + the `wordpress-develop` test library into `$WP_CORE_DIR`/`$WP_TESTS_DIR` (default: `/tmp/wordpress`, `/tmp/wordpress-tests-lib`) and creates the test database — safe to re-run.

To run a single test file or method:

```
./vendor/bin/phpunit tests/unit/test-risk-level.php
./vendor/bin/phpunit --filter test_dangerous_tools_are_never_silently_allowed
```

Coverage priorities live under `tests/unit/`: `Risk_Level` (enforcement matrix + `BASELINE_RISKS` floor), `Audit_Log_Integrity` (hash-chain tamper detection), `Approval_Queue` (create/approve/reject/expire), `Tool_Executor` (the full risk-gate flow — allow/confirm/queue/block, plus that a non-readonly tool triggers a table backup before it runs), and `Abilities_Manifest` (effective-risk resolution). `tests/unit/test-tool-risk-floor-coverage.php` is a standing regression test: it fails the moment a new non-readonly tool ships under `library/tools/` without either a `get_risk_level()` override or a `Risk_Level::BASELINE_RISKS` entry.

No test makes a real outbound HTTP call — `tests/helpers/MockWPFunctions.php` intercepts `wp_remote_*()` via the `pre_http_request` filter for any test that needs one.

## Releases

GitHub tags match plugin versions (`v3.3.0`, etc.). Downloadable ZIPs for production installs are published from the product site and WordPress.org once listed.

## Support

- WordPress.org support forum
- https://agentic-plugin.com/support/
