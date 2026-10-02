# Agent Builder

> ### ⬇️ Install in WordPress (no coding needed)
> 1. **[Download agent-builder.zip](https://agentic-plugin.com/downloads/agent-builder.zip)**. It is always the latest version.
> 2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the file, and click **Install Now**.
> 3. Click **Activate**. Agent Builder opens a short setup wizard to connect your AI provider.
>
> Updates then arrive in your normal WordPress update screen.
>
> ⚠️ Don't use GitHub's green **Code → Download ZIP** button. That is the source code, not an installable plugin: it is missing required libraries and unzips under the wrong folder name.

**Version:** 4.4.0

Free [Agent Builder](https://agentic-plugin.com/) WordPress plugin.

- **Plugin slug:** `agent-builder`
- **License:** GPL-2.0-or-later
- **Requires:** WordPress 6.4+, PHP 8.1+
- **Docs / product site:** https://agentic-plugin.com/documentation/
- **Community agents:** https://agentic-plugin.com/community-agents/

Give AI agents real WordPress work. Hand a job to an agent, walk away, and get pinged only when it needs you. Low-risk steps run on their own; anything that needs your judgement waits for one-click approval.

## What it does

- **Tasks:** assign a job in plain English. It runs in the background, pauses under *Waiting on you* when a step needs approval, then resumes.
- **Site Brief:** a read-only scan of your site turned into a ranked list of approve-to-act jobs.
- **Routines:** run an agent on a schedule or when something happens on the site, with test run, pause and a 20-run history.
- **Agent profiles:** a name, title, avatar and standing instructions for each agent. Pin, hide, duplicate, or export and import as a template.
- **Skills:** save a conversation as a skill, teach a task by demonstration, or invoke a skill with `/skill-name`.
- **Approval rules:** plain-English rules ("Ask me first before publishing anything"), plus allow once, for this task or always.
- **Safety:** risk levels on every tool, automatic backups, a hash-chained audit log and a kill switch.
- **MCP and WebMCP:** connect Grok Bot, Claude Desktop, Cursor or VS Code. Visitors' AI browser agents can use low-risk tools.
- **Bring your own model:** OpenAI, Anthropic, Google Gemini, xAI, DeepSeek, Mistral, Kimi, Cohere or local Ollama.

## 12 agents included free

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
| **AI Radar** | Checks how visible your site is to AI crawlers and assistants (robots.txt, llms.txt, schema.org) and fixes gaps with your approval |

More at [Community Agents](https://agentic-plugin.com/community-agents/).

## Install from source (developers)

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
| `tests/` | PHPUnit suite (`tests/unit/`) — committed to git, excluded from the WordPress.org zip |
| `library/prompt-tests/` | The prompt-test catalog and its developer docs (`most_popular_prompts.md`, `PROMPT-TESTING.md`) — ships in the zip, so `wp agent prompt-test` works on every install |

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

### Prompt tests (real LLM calls)

Alongside the hermetic PHPUnit suite there's a prompt-testing harness — PHPUnit for the *agents*
rather than for the code. It replays a ranked catalog of 54 real-world prompts
([`library/prompt-tests/most_popular_prompts.md`](library/prompt-tests/most_popular_prompts.md))
against the bundled agents on a live install and writes a markdown report.

```
wp agent prompt-test --dry-run                             # resolve and validate, no LLM calls
wp agent prompt-test --user=admin --agent=seo-optimizer
wp agent prompt-test --user=admin --rank=1-10 --max-cost=0.50
```

Unlike `composer test` this makes **real LLM calls against your configured provider and costs
real money**, and prompts that write really write — so it's deliberately not part of
`composer test` or CI, and a bare invocation runs nothing until you select a subset. The catalog
and the parser are covered by the PHPUnit suite, so a bad tool name or agent slug fails for free
in milliseconds rather than partway through a paid run.

The catalog ships inside the plugin and is treated as read-only; the first edit copies it to
`wp-content/agentic-knowledge/prompt-tests/`, so your additions survive plugin updates and
nothing writes inside the plugin directory.

Assistant Trainer can also drive this from chat — running prompts, then proposing the tools or
skills an agent turns out to be missing. It proposes; you approve. Full instructions, every
flag, and how to add prompts:
[`library/prompt-tests/PROMPT-TESTING.md`](library/prompt-tests/PROMPT-TESTING.md).

## Releases

GitHub tags match plugin versions (`v3.3.0`, etc.). Downloadable ZIPs for production installs are published from the product site and WordPress.org once listed.

## Support

- WordPress.org support forum
- https://agentic-plugin.com/support/
