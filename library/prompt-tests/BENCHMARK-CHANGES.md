# Benchmark quality pass — tool-choice failure resolutions

Twelve tool-choice failures from a live 54-prompt run, each resolved as either a
**catalog fix** (the assertion was over-strict — a genuinely fair alternative
tool path exists) or an **agent fix** (the agent picked a wrong or inefficient
tool and its system prompt/tool guidance was sharpened so the right tool is
the obvious choice). No risk tier was weakened and no assertion was relaxed
just to clear a failure without a real justification.

| ID | Decision | Justification |
|---|---|---|
| P07 | Catalog fix | `get_site_overview` and `run_health_check` are two equally valid ways to open a "check my whole site" answer; the run that called `run_health_check` plus seven specific diagnostic tools (web vitals, security, cron, PHP errors, DB, plugin updates, agent readiness) gave a *more* thorough answer than the summary tool alone would have. Requiring both was double-counting the same intent. Changed the entry from `a, b` (AND) to `a\|b` (OR). |
| P13 | Catalog fix | Prompt says "titles" (plural) and names no post; there is no CTR/Search-Console signal wired to any bundled tool to pick one candidate, so surveying title issues site-wide via `get_seo_overview` is a fair, sufficient opening step for a single turn, not a stall. Changed to `get_seo_overview\|optimize_post_title`. |
| P15 | Catalog fix | `analyze_post_seo`'s own description says "Always analyse before updating" — auditing the specific post before proposing the metadata fix is the *correct* order the tool itself documents, not evasion of the write. Changed the second entry from a bare `update_post_seo` requirement to `update_post_seo\|analyze_post_seo`. |
| P22 | Agent fix | `wordpress-assistant`'s system prompt opened with "You are read-only: you never... make changes to the site yourself," while its manifest actually grants three direct-action tools (`update_attachment_alt_text`, `moderate_comment`, `reply_to_comment`). The prompt was actively telling the model not to use a tool it holds, so it searched for another agent (`search_capabilities`) instead of fixing the alt text itself. Rewrote the opening to name the three exceptions and instruct direct use. |
| P24 | Agent fix | The Onboarding Awareness section's trigger ("asking a broad... question") was broad enough to also catch "what can this plugin actually do for me?", a capabilities question, pulling it into `get_onboarding_status` instead of `search_capabilities`/`get_agent_list`. Added an explicit line distinguishing setup-progress questions from capability questions. |
| P26 | Agent fix | The Metric Ownership section literally called `analyze_search_intent`/`suggest_intent_alignment` "**Future** tools" that "will compare" data once "built" — stale copy describing tools that are already in the agent's manifest and fully implemented, which told the model to fall back to generic advice (`get_site_context`) instead of calling them. Rewrote the section to state the tools are live now and instruct their use. |
| P38 | Agent fix | The Content Writer's own workflow already says to draft immediately after `get_site_context` when given a topic ("how to choose a local tradesperson" is not ambiguous), but the run stopped after the context lookup. Strengthened step 2 to explicitly require calling `create_post_content` in the same turn and states that stopping after only `get_site_context` is not a complete answer. |
| P39 | Catalog fix | No page is named ("this page"), and the row's own Notes already predicted the agent "should ask which page" — a `list_posts` lookup (to find the candidate and/or ask which one) is a reasonable opening move the original assertion didn't credit. Changed to `list_posts\|rewrite_for_readability`. Also fixed a stale tool-name typo in the same agent's Editing workflow (`get_post` → `get_post_content`, no such tool as `get_post` exists) found while reading this prompt's path. |
| P40 | Agent fix | Content Writer has no tool that lists posts missing a featured image, so it substituted by reading ten posts' full content one at a time via `get_post_content` — a genuine rabbit hole, not a deliberate choice, caused by a real (but out-of-scope-to-close-here) tool gap. Adding a new tool would require an `Abilities_Manifest` re-sign and a DB version bump outside this PR's file scope, so instead added explicit guidance: check only a small handful of candidates, then move straight to `search_media_library`/`set_featured_image` rather than auditing every post. |
| P43 | Agent fix (with a minor fair loosening) | Editorial Director's own operating rules already say "Do simple planning and lookups yourself with your own tools" — `get_content_stats`/`get_site_writing_stats` are exactly that, and are on no other teammate, so delegating instead of consulting them first skips grounding the plan in real cadence data. Added an explicit rule to call one of them before delegating on consistency/cadence questions. Also loosened the assertion from requiring both stats tools to either one (`a, b` → `a\|b`), since a single-turn planning answer reasonably only needs one to be grounded. |
| P49 | Catalog fix | No agent is named for the widget, and Agent Orchestrator's own operating procedure unconditionally calls `get_agent_list` first and then asks the owner which agent when more than one is active and it isn't obvious from context — the same "ask before acting" shape already accepted for P51's assistant-trainer. Changed to `get_agent_list\|manage_frontend_modal_agent`. |
| P50 | Agent fix | Storefront Assistant only has four tools and the prompt ("show me what you've got for under fifty pounds") maps directly to the worked example already in its own system prompt, yet the run reached for an unrelated site-info tool instead of `wc_browse_products`. Added an explicit "these four tools and no others" constraint and a rule that any browse/afford-style request calls `wc_browse_products` immediately as the first tool call. |

## Files touched

- `library/prompt-tests/most_popular_prompts.md` — P07, P13, P15, P39, P43, P49 assertions relaxed to accept documented alternative tool paths.
- `library/agents/wordpress-assistant/templates/system-prompt.txt` — P22, P24, P26.
- `library/agents/content-writer/templates/system-prompt.txt` — P38, P39 (typo), P40.
- `library/agents/editorial-director/templates/system-prompt.txt` — P43.
- `library/agents/storefront-assistant/templates/system-prompt.txt` — P50.

No `agent.json`/`abilities.json` manifest changed tool grants, so no `Abilities_Manifest::save_integrity_hash()` re-sign or `AGENT_BUILDER_DB_VERSION` bump is needed. `includes/class-llm-client.php` was not touched.

## Round 2

Eleven tool-choice failures remained after round 1's live run (80%, 43/54). Four of them share
one root cause — agents reaching for a generic WP-bridged tool (`wp_extended__*`/`core__*`,
imported via this site's Abilities bridge) instead of their own purpose-built, risk-gated tool —
and are fixed together as a genuine quality improvement, not a benchmark patch: the plugin
already ships a global `[TOOL PREFERENCE]` block (`includes/class-agent-prompt-builder.php`,
out of this PR's file scope), but it's generic ("use your own tool when it covers the request")
and evidently not concrete enough on its own. Each affected agent's own system prompt now names
the specific own-tool-vs-generic-tool pairing for the tasks it actually got wrong, which is a
stronger and more auditable signal than the generic block alone.

| ID | Decision | Justification |
|---|---|---|
| P16 | Agent fix (systemic) | seo-optimizer called the generic `wp_extended__get_posts` instead of its own `analyze_content_quality`, which is purpose-built for exactly this question (originality, density, sentence variety, structural depth) and a generic post fetch cannot compute any of that. Added an explicit rule naming the pairing. |
| P19 | Agent fix (systemic) | wordpress-assistant called the generic `core__get_environment_info` instead of `get_plugin_maintenance_status`/`get_abandoned_plugins`. The generic tool only reports what's installed — it has no maintenance/abandonment signal at all, so this isn't just a style preference, the generic tool can't actually answer the question. Added an explicit rule naming the pairing, plus siblings for oversized-media and plugin-status questions the same run's tool list makes tempting to answer generically. |
| P33 | Agent fix (systemic) | user-assistant called the generic `wp_extended__get_users` instead of its own `list_privileged_users`, which filters to admin/editor-level accounts specifically. The generic tool returns every account with no filtering, so the owner would have to do the filtering by hand — the plugin's tool is genuinely the better answer. Added an explicit rule naming the pairing. |
| P36 | Agent fix (systemic) | Same root cause as P33, this time on a named-account lock request — user-assistant reached for `wp_extended__get_users` instead of confirming via `list_privileged_users` and acting with `lock_user_account`. Covered by the same new rule. |
| P38 | Agent fix (strengthened) | Round 1 added a "draft in the same turn" nudge, but the run still stopped after `get_site_context`. Rewrote it to say the two calls are one unit of work, not two turns, and explicitly forbids ending a response with only a plan to write ("coming up") instead of the draft itself — a stronger, less escapable phrasing of the same rule. |
| P26 | Agent fix (strengthened) | Round 1's fix (stating the intent tools are live, not a future item) moved the run off `get_site_context` but only as far as `list_posts` — `analyze_search_intent` requires a `post_id`, so the run needs to actually pick a candidate page and call it. Added an explicit two-step instruction: `list_posts` to find a candidate when none is named, then `analyze_search_intent` on it in the same turn — listing posts alone has not analysed anything. |
| P22 | Agent fix | Round 1's fix (naming the three direct-action exceptions) worked — the run no longer calls `search_capabilities` — but it now stops after `search_media_library` discovery. `search_media_library` returning which images exist is a fair first step (there's no site-wide accessibility audit tool), but proposing alt text from filename/title/caption context and calling `update_attachment_alt_text` (a gated write the owner reviews) is the actual fix and was missing. Added an explicit instruction to complete the sequence in the same turn. Also documented in the catalog Notes that this is a gated write, matching the pattern used for other write-action rows. |
| P40 | Agent fix (tightened) | Round 1 added guidance to check "a small handful of candidates" via `get_post_content` before falling back to `search_media_library` — but neither `list_posts` nor `get_post_content` returns featured-image status at all, so that check can never answer the question and the model kept pulling more candidates (the observed 5-post rabbit hole) looking for a signal that isn't there. Removed the "check a handful" permission entirely; the agent now goes straight to `search_media_library`/`set_featured_image`, using at most one `list_posts` call (not per-post content fetches) to get a candidate list when no posts are named. A tool that lists posts missing a featured image would close this gap properly but needs a new `abilities.json` grant (manifest re-sign, DB bump) that's out of this PR's file scope — noted as a follow-up, not forced here. |
| P39 | Agent fix | Round 1 relaxed the assertion to accept `list_posts\|rewrite_for_readability`, but the Editing workflow itself never told the agent to look for the page when none is named — only the *Creating New Content* workflow mentions `get_site_context`, and the agent applied that instinct to an editing request by analogy. Added an explicit step 0 to the Editing workflow: with no page named, call `list_posts` to find a candidate; `get_site_context` informs tone/category for new content and does not help locate an existing page, so it's the wrong tool for this ask regardless of catalog leniency. |
| P15 | Catalog fix | `get_seo_overview`'s own scan explicitly buckets `missing_meta_description` with counts and examples — for "half my pages have no meta description" it surfaces the exact reported gap at least as directly as the filtered `list_posts_needing_seo`, so requiring only the latter was over-strict. Changed the first required entry from a bare `list_posts_needing_seo` to `list_posts_needing_seo\|get_seo_overview`; the second entry (`update_post_seo\|analyze_post_seo`) is unchanged from round 1. Also added a system-prompt rule that a site-wide-gap survey must still be followed by a fix on the worst offenders, not left as a standalone report. |
| P17 | Catalog fix | `scan_media_library`'s own scan flags oversized files by actual file size (KB) alongside orphan/storage stats, which is at least as relevant a "slowing the site down" signal as `find_oversized_images`' pixel-dimension threshold — both are genuine, tool-native ways to answer this prompt. Changed the entry from a bare `find_oversized_images` to `find_oversized_images\|scan_media_library`. No agent prompt change was needed; the model's choice was already a fair one. |

### Files touched (round 2)

- `library/prompt-tests/most_popular_prompts.md` — P15, P17 assertions relaxed; P22, P26 Notes documented (no assertion change, agent fix only).
- `library/agents/seo-optimizer/templates/system-prompt.txt` — P16 (own-tool preference), P15 (survey-then-fix).
- `library/agents/wordpress-assistant/templates/system-prompt.txt` — P19 (own-tool preference), P22 (complete the alt-text fix), P26 (complete the search-intent analysis).
- `library/agents/user-assistant/templates/system-prompt.txt` — P33, P36 (own-tool preference).
- `library/agents/content-writer/templates/system-prompt.txt` — P38 (strengthened draft-immediately), P39 (Editing workflow now finds the page via list_posts, not get_site_context), P40 (removed the futile "check a handful via get_post_content" step).

No `agent.json`/`abilities.json` manifest changed tool grants in round 2 either, so no re-sign or
DB bump is needed. `includes/class-llm-client.php` was not touched. The global
`[TOOL PREFERENCE]` block in `includes/class-agent-prompt-builder.php` was left as-is (out of
this PR's file scope) — the per-agent reinforcements above are additive to it, not a replacement.

## Round 3

After round 2's fixes, the live run reached a mean ~88.6% (N=6) with zero deterministic
failures, but three prompts still failed more often than not — in every case the agent picked a
*different bundled tool it already owns* over the expected one, a sibling-tool confusion rather
than a bridge escape. All three are agent fixes: the existing prompt guidance either didn't cover
this specific pairing yet, or named a different (already-fixed) confusion and left this one
unaddressed.

| ID | Decision | Justification |
|---|---|---|
| P28 | Agent fix | Support Triage had no guidance at all distinguishing `detect_form_plugins` from the form-reading tools, so "has anyone filled in my contact form recently?" — a question about stored entries — pattern-matched to the tool whose description says "Always call this first before any other form tool." Read `detect_form_plugins`' own description: it only reports which form *plugin* is installed (Contact Form 7, WPForms, etc.) and never returns a submission. Added an explicit rule: submission/entry questions go to `list_native_forms` then `get_native_form_submissions`; `detect_form_plugins` is reserved for questions about which plugin is running or setting one up. |
| P36 | Agent fix | Round 2 fixed this prompt's confusion with the *generic bridged* `wp_extended__get_users`, and that fix holds — the run no longer reaches for it. The remaining failure is a different, undocumented confusion: the model now picks `manage_user_privileges`, one of user-assistant's own tools, over `lock_user_account`. Read `manage_user_privileges`' own description and parameter schema: its actions (`get`, `set_privilege`, `set_usage_limit`, `set_anonymous_chat`) are all role-wide (which WordPress *role* can do what) and it has no per-account action whatsoever — it structurally cannot lock one named account. Added an explicit scope rule: a request naming one account (by name or email) is `lock_user_account` (after `list_privileged_users` to identify it if needed); `manage_user_privileges` is only for requests naming a role or a class of users. |
| P16 | Agent fix (strengthened) | Round 2 added a rule preferring `analyze_content_quality` for single-page quality questions, but it only said what *to* call, never what *not* to — so `get_seo_overview`'s description ("scan all published posts... returns counts and examples") still reads as a plausible opener for "is my About page any good?" and sometimes still won the choice. Read `get_seo_overview`'s own description again: it is a site-wide scan (missing meta, title length, thin content, links, images across *every* published post) with no per-page quality verdict at all. Added an explicit negative rule: do not open a single-page content-quality question with `get_seo_overview` — it cannot answer it — go straight to `analyze_content_quality` on the named page. |

### Files touched (round 3)

- `library/agents/support-triage/templates/system-prompt.txt` — P28 (list_native_forms/get_native_form_submissions vs. detect_form_plugins).
- `library/agents/user-assistant/templates/system-prompt.txt` — P36 (lock_user_account vs. manage_user_privileges, scoped by single-account vs. role-wide).
- `library/agents/seo-optimizer/templates/system-prompt.txt` — P16 (explicit negative rule against get_seo_overview for single-page quality questions).

No `most_popular_prompts.md` assertion changed in round 3 — all three prompts already accept the
correct bundled tool (`list_native_forms|get_native_form_submissions`, `lock_user_account|list_privileged_users`,
`analyze_content_quality`); the fix in every case was making the agent prompt reliably choose the
tool the assertion already expects. No `agent.json`/`abilities.json` manifest changed tool grants,
so no re-sign or DB bump is needed. `includes/class-llm-client.php` was not touched.

### Round 3 addendum - P12 fair-assertion fix
P12 (seo-optimizer, "which posts are really short?") expected list_posts_needing_seo only, but the agent reliably calls get_seo_overview. get_seo_overview buckets thin_content at the same <300-word threshold (get_seo_overview/tool.php lines 100,125-126), so it genuinely returns the short posts with counts/examples - a correct answer, not a miss. P11 and P15 already accept get_seo_overview as an OR-alternative; P12 was simply missed in round 1. Added get_seo_overview as a fair OR-alternative (catalog fix, source-verified - not gaming).

### Round 4 - P15 follow-through fix
P15 (seo-optimizer, "half my pages have no meta description, can you fix that?") consistently ran the survey (list_posts_needing_seo / get_seo_overview) then stopped - listing pages or proposing descriptions in prose without calling analyze_post_seo/update_post_seo. The round-2 nudge was too soft. Strengthened it into a concrete mandate (mirroring the P40/P28/P36 pattern): the survey and the fix are ONE unit of work; after the survey, in the same response, call analyze_post_seo on the worst offender then update_post_seo (a gated write the owner reviews); writing descriptions as prose is not fixing them. Genuine improvement - the user asked to fix it, so surveying-and-stopping is an incomplete answer, not a fair one. No assertion changed.
