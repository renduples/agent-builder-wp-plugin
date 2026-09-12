# M4 WordPress.org Guidelines Checklist (release/3.4-wporg)

## 1) License
**Verdict: PASS**

**Evidence**
- `LICENSE:4-7` states GPL v2 or later.
- `agent-builder.php:13-14` and `readme.txt:9-10` both declare `GPL-2.0-or-later` + GPL URI.
- Runtime dependencies in `composer.lock` are GPL-compatible licenses (MIT/BSD/GPL/LGPL families), e.g. `mpdf/mpdf` (GPL-2.0-only), `phpoffice/phpword` (LGPL-3.0-only), `smalot/pdfparser` (LGPL-3.0).

## 2) No obfuscation
**Verdict: PASS**

**Evidence**
- Readable source exists under `src/` (e.g. `src/settings-app/index.js:1-80`, full React source).
- Build artifacts exist separately under `build/` (bundle files only), and build process is declared in `package.json:8-11` (`wp-scripts build`, lint/format scripts).
- No minified/obfuscated PHP source patterns were found in plugin PHP files.

## 3) No tracking without opt-in / External Services completeness
**Verdict: FAIL**

**Evidence**
- Code includes explicit user/consent-gated outbound calls, e.g. signup registration (`includes/class-admin-ajax.php:1116-1159`), deactivation feedback with consent check (`includes/class-admin-ajax.php:125-148`), relay connect approval flow (`includes/class-relay-connect.php:900-963`), and directory submission triggered by explicit admin action (`includes/class-directory-submission.php:3-10,57-67`).
- **Disclosure mismatch found:** readme lists Site Passport endpoint as `https://sitepassport.org/api/v1/submissions` (`readme.txt:270-273`), but code posts to `https://sitepassport.org/api/submit.php` (`includes/class-directory-submission.php:37,60-61`).

**Suggested next step:** Update External Services disclosure so endpoint details exactly match implemented requests.

## 4) No remote code execution
**Verdict: PASS**

**Evidence**
- No remote include/require/eval execution patterns found.
- Tool execution path loads local filesystem tool files only (`includes/class-tool-loader.php:81-99,124-127`) and executes loaded tool instances (`includes/class-tool-loader.php:215-239`).
- Job processor instantiation is constrained to classes implementing `Job_Processor_Interface` (`includes/class-job-manager.php:285-295`).

## 5) No excessive/aggressive upsell nags
**Verdict: PASS**

**Evidence**
- Pro upsell appears as a footer link (`includes/class-admin-menu-handler.php:852-854,919-921`) rather than modal/popover spam.
- Admin notice manager restricts notices to plugin screens and honors dismissal/state checks (`includes/class-admin-notice-manager.php:31-33,64-80,73-75`).
- No evidence of fake urgency or forced recurring upgrade prompt loops.

## 6) Prefixing
**Verdict: PASS**

**Evidence**
- Namespaced classes use `Agentic\` (`agent-builder.php:23`, classes under `includes/`).
- Global helper functions are prefixed (`includes/functions.php:28,70,127,169`).
- Options consistently use `agentic_*` (examples: `includes/class-activator.php:202-214`).
- DB tables are consistently `{$wpdb->prefix}agentic_*` (`includes/class-activator.php:788-1047`, plus `agentic_jobs/security_log/deployments` at `1054-1078`).
- Plugin constants are prefixed `AGENT_BUILDER_*` / `AGENTIC_*` (`agent-builder.php:60-68`).

## 7) Sanitize / escape / nonce (spot-check sample not duplicating M2 REST audit)
**Verdict: PASS**

**Evidence (sampled admin form handlers/pages outside M2 REST-route focus)**
- `admin/settings-memory.php:16-18,28-39,64-67` — capability check, nonce checks, and sanitization on mutating actions.
- `admin/deployment/deployment-admin-bar.php:24-26,37-50,79-90` — nonce check + sanitization/allowlist validation before updates.
- `admin/deployment/deployment-modal.php:25-27,36-49,71-84` — nonce + sanitize/allowlist handling.
- `admin/deployment/deployment-shortcodes.php:47,51,81-83,98-101,127-137` — nonce + sanitization for create/update/delete flows.
- `admin/skills.php:39-47,78-83,98-101` — nonce verification and sanitized input for save/delete/reset actions.

## 8) Uninstall cleanup completeness
**Verdict: PASS**

**Evidence**
- Uninstall honors data-retention choice and exits early unless delete-data is enabled (`uninstall.php:66-69`).
- `agentic_agent_library` and `agentic_skills` hold mixed bundled + user content (custom/imported skills via `Skills_Registry::create()` / `import_from_hub()`, customized core skills via `source_hash`, user-created and purchased library agents with `source` = `user`/`purchased`). When delete-data is enabled they are dropped with every other `agentic_*` table; there is no preserve list. Bundled rows are re-seeded on next activation (`Activator::seed_skills()`, `seed_bundled_agents()`).
- It also cleans options/transients/usermeta/cron (`uninstall.php` remaining sections).

Resolved: preserving these tables on opted-in full deletion was incorrect — they are not bundled-only seed stores.

## 9) `Tested up to` currency
**Verdict: PASS**

**Evidence**
- Declared values are coherent: `Requires at least: 6.4`, `Tested up to: 7.1` (`readme.txt:4-5`; also mirrored in `agent-builder.php:9`).
- Optional newer APIs are guarded by runtime wrappers (`includes/class-wp-optional-api.php:3-8,35-39`), reducing hard dependency risk above minimum version.

## 10) Asset sizes / WP.org asset structure readiness
**Verdict: NEEDS-REVIEW**

**Evidence**
- `.wordpress-org/` directory is currently absent in this branch (repository scan found no matches).
- Existing image assets are in `assets/` and `screenshots/baseline/` (e.g. `assets/icon.svg`; screenshots include very large files such as `screenshots/baseline/tools.png` and `screenshots/baseline/logs.png`).
- For WP.org preparation, standard plugin-directory asset naming/dimensions should be used when creating `.wordpress-org/` (commonly: `banner-772x250.png` / `banner-1544x500.png`, `icon-128x128.png` / `icon-256x256.png`).

**Suggested next step:** Add a dedicated `.wordpress-org/` asset set with WP.org naming/dimensions and optimized file sizes before submission packaging.

## 11) Trademark/name/slug concerns
**Verdict: PASS**

**Evidence**
- Plugin name is `Agent Builder` (`agent-builder.php:5`, `readme.txt:1`) and slug/text-domain is `agent-builder` (`agent-builder.php:15`).
- No prohibited use of "WordPress" in plugin name/slug was found.

---

## Summary
- **PASS:** 9
- **FAIL:** 1
- **NEEDS-REVIEW:** 1
