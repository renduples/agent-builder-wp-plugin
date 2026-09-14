# Agent Builder

**Version:** 3.4.0

Free [Agent Builder](https://agentic-plugin.com/) WordPress plugin.

- **Plugin slug:** `agent-builder`
- **License:** GPL-2.0-or-later
- **Requires:** WordPress 6.4+, PHP 8.1+
- **Docs / product site:** https://agentic-plugin.com/documentation/
- **Community agents:** https://agentic-plugin.com/community-agents/

Create, train, and orchestrate AI agents in WordPress with built-in safety — approval gates, risk audits, tamper-proof logs, a Basic/Advanced interface split, and WebMCP.

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

More on [Community Agents](https://agentic-plugin.com/community-agents/).

## Install from source

1. Clone into `wp-content/plugins/agent-builder` (or symlink).
2. Optional document tools: `composer install --no-dev`
3. Optional rebuild admin React: `npm ci && npm run build`  
   Pre-built assets already ship in `build/`.
4. Activate **Agent Builder** in WordPress.

## Baseline admin screenshots

Full-page (1440px) captures of every Agent Builder wp-admin screen, written to `screenshots/baseline/`:

```
WP_ADMIN_USER=... WP_ADMIN_PASS=... npm run screenshot:baseline
```

Optional `SCREEN=slug,slug` captures a subset. Safety Center is stored as `safety-center.png` (Basic) and `safety-center-advanced.png`. The Approvals risk-gate preferences (readme caption 6) are `approvals-risk-gate.png`.

Uses system Google Chrome when Playwright’s bundled Chromium is unavailable (override with `PLAYWRIGHT_CHROME_PATH`). Safe to re-run; existing PNGs are overwritten.

WordPress.org listing shots (`.wordpress-org/screenshot-N.png`, readme.txt order) are recaptured the same way:

```
WP_ADMIN_USER=... WP_ADMIN_PASS=... npm run screenshot:wporg
```

Safety Center and the Advanced Tools list are viewport-height (1440×900) so they stay reviewable; other screens are full-page. Optional `SCREEN=2,9` recaptures a subset.

## Releases

GitHub tags match plugin versions (`v3.3.0`, etc.). Downloadable ZIPs for production installs are published from the product site and WordPress.org once listed.

## Support

- WordPress.org support forum
- https://agentic-plugin.com/support/
