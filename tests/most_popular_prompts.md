# Most Popular Prompts

The prompt catalog for `wp agent prompt-test`. Fifty things that genuinely frustrate
WordPress site owners, ranked by how much they hurt, each assigned to the bundled agent
that should handle it.

**This file is the source of truth.** The runner parses the tables below directly — there is
no sidecar data file to keep in sync. Edit it by hand; `wp agent prompt-test --dry-run`
re-validates every row in about a second, for free. See
[`PROMPT-TESTING.md`](PROMPT-TESTING.md) for how to run it.

## Columns

| Column | Meaning |
|---|---|
| **ID** | Stable identifier. Never renumber it when reordering rows — `--id=` targets it. |
| **Rank** | 1 = most frustrating. `--rank=` targets it. |
| **Agent** | Exactly one bundled agent slug. Validated before any LLM call. |
| **Prompt** | Sent verbatim, and must be self-contained — the harness sends no conversation history. |
| **Expect Tools** | Tools the run should reach for. Blank means only the baseline checks apply. |
| **Coverage** | `full`, `partial` or `none` — how well the assigned agent can actually answer. |
| **Notes** | Read by humans, echoed into the report, never asserted on. |
| **Source** | Where the frustration ranking came from. |

Write prompts the way a non-technical owner actually types them ("my contact form stopped
emailing me"), not as tidy commands. That is the point: if an agent only works when spoken to
in tool names, it does not work.

A prompt that trips the risk gate and stops to ask permission is recorded as a **gated pass**,
not a failure. Say so in Notes when you expect it.

## How the ranking was decided

Ranked by frequency × pain × how stuck a non-technical owner gets, from:

- **Generic "most common WordPress problems" roundups** (Bluehost, WPX, ThemeWinter, RSHosting,
  Kinsta) — the 500 error, the white screen, login redirect loops, mail not sending, slow
  sites, upload limits, stuck maintenance mode. Tagged `common`.
- **Patchstack's *State of WordPress Security in 2026*** and related security roundups —
  outdated plugins and themes behind 90%+ of compromises, brute force using leaked
  credentials, SEO-spam cloaking, XSS at 53.3% of new disclosures. Tagged `security`.
- **Beginner FAQ compilations** (WPBeginner, ThemeIsle, WPExplorer, WinningWP) — what people
  ask before they know the vocabulary. Tagged `faq`.
- **WooCommerce owner reports** — checkout and gateway failures, shipping configuration,
  stock, scaling; and the recurring note that error logs "don't exactly scream easy fix to
  someone whose technical expertise peaks at changing themes". Tagged `woo`.
- **WordPress SEO problem roundups** (Pressable, Redshaw, The X Concept) — default permalinks,
  "discourage search engines" left switched on, every service crammed onto one page, chasing
  the plugin's green dot instead of rankings. Tagged `seo`.
- **Maintenance-neglect roundups** (BlogVault, WPRemote, ThePlusAddons) — backups, broken
  links, database bloat, the update that never happens. Tagged `maint`.
- **Small-business content surveys** — 5–8 hours a week of writing is not feasible alongside
  running the business. Tagged `content`.
- **Gutenberg reception** — a 2-star WordPress.org rating, accessibility complaints, no
  responsive controls, hidden UI. Tagged `editor`.
- **AI-visibility**, the 2026 addition — owners now asking whether assistants can see their
  site at all. Tagged `ai`.

Ranking follows the research, not the product. Where a top complaint has no good home, the row
still appears with `Coverage: none` or `partial` and is collected under
[Coverage gaps](#coverage-gaps). Those rows are the most useful ones in the file.

---

## Site Health Sentinel (site-health-sentinel)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P01 | 1 | site-health-sentinel | My site feels really slow, especially on my phone. What's making it slow? | check_core_web_vitals | partial | Can measure LCP/CWV and explain; cannot change the theme or enable caching. | common |
| P02 | 2 | site-health-sentinel | Is my site secure? I'm worried it might have been hacked. | get_security_overview | partial | Reports security signals; no malware scan, no file-integrity check wired to this agent. | security |
| P03 | 3 | site-health-sentinel | Which of my plugins need updating, and is it safe to update them? | check_plugin_updates | full | The single highest-value maintenance question — outdated plugins are behind 90%+ of compromises. | security |
| P04 | 6 | site-health-sentinel | My site showed a blank white page this morning. What happened? | get_php_errors | partial | Reads the error log; cannot restore a site that is currently down. | common |
| P05 | 9 | site-health-sentinel | My scheduled posts aren't publishing on time. Why not? | get_cron_jobs | full | Classic WP-Cron-on-a-low-traffic-site problem; the agent can actually diagnose this one. | common |
| P06 | 12 | site-health-sentinel | My database has got really big. Is that a problem and what's in there? | get_database_health | partial | Diagnoses bloat; cannot clean revisions or transients — those tools exist but are not on this agent. | maint |
| P07 | 16 | site-health-sentinel | Give me a plain-English health check of my whole site. | run_health_check, get_site_overview | full | The "just tell me if anything's wrong" prompt. | faq |
| P08 | 31 | site-health-sentinel | I got an email saying my site had a critical error. What does that mean? | get_php_errors | partial | Tests whether the agent translates a fatal error into something a non-developer can act on. | common |
| P09 | 44 | site-health-sentinel | Is my site ready for AI agents to use safely? | check_agent_readiness | full | Site Passport readiness score. | ai |

## SEO Optimizer (seo-optimizer)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P10 | 5 | seo-optimizer | My site doesn't show up on Google at all. What's wrong? | get_seo_overview | partial | Cannot check index status or the "discourage search engines" setting — the two most common actual causes. | seo |
| P11 | 10 | seo-optimizer | Which of my pages need SEO work the most? | list_posts_needing_seo | full | | seo |
| P12 | 13 | seo-optimizer | A lot of my posts are really short. Does that hurt me, and which ones are they? | list_posts_needing_seo | full | Thin-content detection; the <300-word threshold. | seo |
| P13 | 20 | seo-optimizer | My titles get impressions but nobody clicks them. Can you improve them? | optimize_post_title | full | Expected to stop for approval before rewriting a live title. | seo |
| P14 | 24 | seo-optimizer | Nobody can find my older posts. How should I link things together better? | analyze_internal_links, get_link_suggestions | full | | seo |
| P15 | 27 | seo-optimizer | Half my pages have no meta description. Can you fix that? | list_posts_needing_seo, update_post_seo | full | Write action — expect a gated pass in supervised mode. | seo |
| P16 | 36 | seo-optimizer | Is the content on my About page actually any good? | analyze_content_quality | partial | Needs an About page to exist on the test site. | seo |

## WordPress Assistant (wordpress-assistant)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P17 | 8 | wordpress-assistant | My photos are enormous and I think they're slowing the site down. | find_oversized_images | partial | Finds them; cannot compress or convert to WebP — those tools exist but are not on any bundled agent. | common |
| P18 | 14 | wordpress-assistant | I've got loads of plugins and no idea what half of them do. Which ones can I get rid of? | check_plugin_status, get_abandoned_plugins | full | | maint |
| P19 | 17 | wordpress-assistant | Is this plugin still maintained, or has it been abandoned? | get_plugin_maintenance_status | full | Self-contained on purpose — the agent should ask which plugin rather than guess. | security |
| P20 | 19 | wordpress-assistant | My media library is huge. What's taking up all the space? | get_media_storage_report, find_unused_media | full | | maint |
| P21 | 22 | wordpress-assistant | I'm brand new to this. Where do I even start? | get_onboarding_status | full | The onboarding prompt; tests tone as much as tooling. | faq |
| P22 | 26 | wordpress-assistant | Screen readers can't describe my images. Can you sort out the alt text? | update_attachment_alt_text | partial | Can set alt text per image; no site-wide accessibility audit on this agent. | faq |
| P23 | 30 | wordpress-assistant | What is actually on my website? Give me the overview. | get_site_overview | full | | faq |
| P24 | 35 | wordpress-assistant | What can this plugin actually do for me? | get_agent_list, search_capabilities | full | | faq |
| P25 | 41 | wordpress-assistant | Are there any images in my library I'm not using anywhere? | find_unused_media | full | | maint |
| P26 | 48 | wordpress-assistant | What are people actually searching for when they land on my site? | analyze_search_intent | partial | Intent analysis, not real search-console data. | seo |

## Support Triage (support-triage)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P27 | 7 | support-triage | I'm drowning in spam comments. Can you deal with them? | list_comments | partial | Can review and moderate one at a time; `cleanup_spam_comments` exists but is not on this agent. | security |
| P28 | 15 | support-triage | Has anyone filled in my contact form recently? I think I'm missing enquiries. | list_native_forms, get_native_form_submissions | partial | Reads stored submissions — which is exactly how an owner discovers mail is broken. | common |
| P29 | 23 | support-triage | There are comments waiting for me. Which ones actually need a reply? | list_comments | full | Triage and prioritisation, the agent's core job. | faq |
| P30 | 29 | support-triage | Someone left an angry comment. Can you draft a polite reply for me to check? | reply_to_comment | full | Expect a gated pass — replying publicly is a real action. | faq |
| P31 | 42 | support-triage | Summarise what my visitors have been asking about this month. | list_comments, search_content | partial | | faq |

## User Assistant (user-assistant)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P32 | 11 | user-assistant | Who has admin access to my site? I want to check nobody shouldn't be there. | list_privileged_users | full | Directly addresses the top security hygiene question. | security |
| P33 | 18 | user-assistant | Someone keeps trying to log into my site as admin. What can I do? | list_privileged_users | partial | Can review and lock accounts; `get_failed_logins` exists but is not on this agent, so it cannot see the attempts. | security |
| P34 | 25 | user-assistant | I've got accounts that haven't logged in for years. Can we clean them up? | find_inactive_users | full | Expect a gated pass if it proposes locking anyone. | maint |
| P35 | 32 | user-assistant | I want my editor to be able to use the AI agents but not change settings. | manage_user_privileges | full | High-risk write — expect it to queue for approval. | faq |
| P36 | 39 | user-assistant | This account looks suspicious. Can you lock it until I've checked? | lock_user_account | full | High risk by design; a gated pass is the correct outcome. | security |
| P37 | 46 | user-assistant | Has anyone new signed up recently? | get_recent_registrations | full | | faq |

## Content Writer (content-writer)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P38 | 4 | content-writer | I don't have time to write. Can you draft me a blog post about how to choose a local tradesperson? | create_post_content | full | The #1 reason owners buy AI tooling: 5–8 hrs/week of writing is not feasible. Expect a gated pass. | content |
| P39 | 21 | content-writer | This page is a wall of text and nobody reads it. Can you make it easier to read? | rewrite_for_readability | full | Self-contained enough that the agent should ask which page. Expect a gated pass. | content |
| P40 | 28 | content-writer | Some of my posts have no featured image and look broken when shared. | search_media_library | partial | Can find and set an image; cannot generate one — `generate_image` is not on this agent. | content |
| P41 | 37 | content-writer | My categories are a complete mess. Can you help me tidy them up? | manage_categories | partial | | content |
| P42 | 45 | content-writer | Which of my posts are actually doing well? | get_post_performance | partial | | content |

## Editorial Director (editorial-director)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P43 | 33 | editorial-director | I never know what to write next and I post about once a year. Help me get consistent. | get_content_stats, get_site_writing_stats | partial | Consistency is the single most cited content failure. Planning only — nothing schedules it. | content |
| P44 | 38 | editorial-director | Find my weakest posts and organise getting them rewritten and optimised. | list_posts_needing_seo, delegate_to_agent | full | The only `"team": true` agent — this is the delegation path under test. | content |
| P45 | 49 | editorial-director | How much have I actually published this year? | get_site_writing_stats | full | | content |

## AI Radar (ai-radar)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P46 | 34 | ai-radar | Can ChatGPT and other AI assistants actually see my website? | run_ai_radar_scan | full | The 2026 version of "am I on Google". | ai |
| P47 | 40 | ai-radar | Is my robots.txt accidentally blocking anything important? | check_robots_txt | full | | ai |
| P48 | 43 | ai-radar | My pages don't get those rich Google results. What's missing? | check_schema_markup, check_schema_coverage | full | | seo |

## Agent Orchestrator (agent-orchestrator)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P49 | 47 | agent-orchestrator | I want a chat box on my website so visitors can ask questions. | manage_frontend_modal_agent | full | Expect a gated pass — this changes what the public site shows. | faq |

## Storefront Assistant (storefront-assistant)

| ID | Rank | Agent | Prompt | Expect Tools | Coverage | Notes | Source |
|---|---|---|---|---|---|---|---|
| P50 | 50 | storefront-assistant | Show me what you've got for under fifty pounds. | wc_browse_products | full | Shopper-facing, not owner-facing. Needs WooCommerce with products; will skip or fail on a store-less site. | woo |

---

## Coverage gaps

What ranking by real frustration rather than by product capability surfaces. These are
findings, not defects in the catalog — the rows stay in so each run keeps measuring them.

**The big one: 164 of 269 tools (61%) are not reachable by any bundled agent.** The tools
below all exist under `library/tools/` and are fully implemented; no bundled agent's
`agent.json` lists them, so no prompt can reach them.

| Gap | Prompts | What's missing |
|---|---|---|
| **Backups** | — | No bundled agent can take, list or restore a backup, and no prompt in this catalog can test one. Backups are the most-cited neglected maintenance task, and `Tool_Helpers::backup_file()` already exists internally. There is no agent-facing tool at all. |
| **Mail deliverability** | P28 | "My contact form stopped emailing me" is a top-3 complaint. Support Triage can show stored submissions — which is how owners notice — but nothing diagnoses SMTP, the sender address or the `wp_mail` path. |
| **Image compression** | P17 | `compress_image`, `convert_image`, `convert_image_to_webp` and `resize_image` all exist. WordPress Assistant can only *find* oversized images. The obvious follow-up — "go on then, fix them" — has nowhere to go. |
| **Caching** | P01 | `check_caching_status` and `manage_cache` exist but are not on Site Health Sentinel, so the top complaint on the list ends at a diagnosis. |
| **Brute-force visibility** | P33 | `get_failed_logins` exists but is on no agent, so User Assistant cannot see the login attempts the owner is asking about. |
| **Spam cleanup at scale** | P27 | `cleanup_spam_comments` exists but is not on Support Triage, so "deal with them" means one comment at a time. |
| **Broken links** | — | `check_broken_internal_links`, `audit_internal_links` and `fix_all_internal_links` exist but are not on SEO Optimizer. Broken links are a standard maintenance complaint with no prompt able to reach the tooling. |
| **Permalinks & indexability** | P10 | `check_permalink_structure` exists but is not on SEO Optimizer. Default permalinks and "discourage search engines" left on are the two most common reasons a site is invisible, and neither is checkable. |
| **WooCommerce for the owner** | P50 | Storefront Assistant has four shopper tools (browse, cart). The ~25 `wc_*` owner tools — orders, refunds, stock, coupons, store stats — are on no agent. Every WooCommerce owner frustration in the research (checkout, gateways, shipping, stock) is unreachable. |
| **Accessibility audit** | P22 | `get_accessibility_stats` exists but is on no agent; only per-image alt text is reachable. |
| **Privacy / GDPR** | — | `get_privacy_compliance_status` exists but is on no agent. |
| **Database cleanup** | P06 | `purge_expired_transients` and `cleanup_post_revisions` exist but are not on Site Health Sentinel, so it can diagnose bloat and not act. |
| **File integrity** | P02 | `verify_core_integrity`, `check_file_modifications` and `check_file_permissions` exist but are on no agent — so "have I been hacked?" cannot be answered properly. |
| **The block editor** | — | Gutenberg frustration is real and well documented, but it is a UI complaint with no tool surface. Conversational help only. Not a gap worth closing with a tool. |

The first eleven rows are all the same shape: **the tool exists and works, it is simply not
declared in any agent's `agent.json`.** Closing them is a manifest edit and an
`Abilities_Manifest::save_integrity_hash()` re-sign, not new code — which is exactly what the
self-improvement loop in `PROMPT-TESTING.md` is designed to propose.
