# Agent Builder

**Version:** 3.3.46

Free [Agent Builder](https://agentic-plugin.com/) WordPress plugin.

- **Plugin slug:** `agent-builder`
- **License:** GPL-2.0-or-later
- **Requires:** WordPress 6.4+, PHP 8.1+
- **Docs / product site:** https://agentic-plugin.com/documentation/
- **Community agents:** https://agentic-plugin.com/community-agents/

Deploy AI agents and teams in WordPress. Create role-based agents with simple job descriptions.

## Eight agents included free

| Agent | Role |
|-------|------|
| **Assistant Trainer** | Train new assistants from plain job descriptions |
| **Content Writer** | Create, edit, and publish posts and pages |
| **Editorial Director** | Plan content work and coordinate specialists |
| **SEO Optimizer** | Audit on-page SEO and propose improvements |
| **Site Health Sentinel** | Site health, performance, and security signals |
| **Support Triage** | Triage comments/forms; draft replies |
| **User Assistant** | Registrations, accounts, and member outreach |
| **WordPress Assistant** | Guide to WordPress and Agent Builder |

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
