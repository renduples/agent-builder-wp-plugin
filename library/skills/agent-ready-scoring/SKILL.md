---
name: agent-ready-scoring
description: "Explain and interpret the Site Passport score — an 8-check readiness score covering MCP reachability, WebMCP tool registration, approval-gate safety, llms.txt/robots.txt/schema.org discoverability, and commerce readiness. Use when the user asks how accessible/visible their site is to AI agents, what the score means, what a specific check does, or how to raise it. Call check_agent_readiness first, then explain the result using this skill."
---

# Site Passport

## What it measures

Eight checks, each weighted high/medium/low, combined into an overall score (0-100) and a letter grade (A-F):

| Check | Category | Weight | Fix available? |
|---|---|---|---|
| `mcp_server_reachable` | Capability exposure | high | Yes — one-click |
| `webmcp_tools_registered` | Capability exposure | high | Yes — one-click |
| `approval_gate_configured` | Safety & trust | high | Yes — one-click |
| `llms_txt_present` | Discoverability | medium | Yes — via the AI Radar agent |
| `robots_ai_directives` | Bot access control | medium | Yes — via the AI Radar agent |
| `schema_org_present` | Content | medium | Yes — via the AI Radar agent |
| `well_known_manifest` | Discoverability | low | Yes — one-click |
| `commerce_readiness` | Commerce | medium | No one-click fix yet |

All eight checks run locally, in-process, with zero outbound HTTP requests. `commerce_readiness` only applies to sites with an active commerce platform (currently WooCommerce) — a site with none scores 100/not-applicable, never a failure. On a WooCommerce site it checks for a configured payment gateway and, at the top tier, a live WebMCP-exposed commerce tool (the bundled Storefront Assistant agent) an AI agent can actually transact through via this plugin's own risk gate — WooCommerce's own native Abilities API presence alone is a secondary, informational signal only.

## How to read a result

`check_agent_readiness` returns:
- `overall` — 0-100 weighted score.
- `grade` — a letter, A (≥90) through F (<40).
- `categories` — one entry per check above, each with `{score, status, detail, category, weight, fixable}`. `status` is `pass`/`partial`/`fail`; `detail` is a human-readable explanation of exactly why the check scored what it did.
- `checked_at` — when this was last computed. Pass `force_rescan: true` to recompute (checks run in well under a second — there's no cost to rescanning on request).

## What you can fix directly

The four checks marked "Yes — one-click" above have real one-click fixes available in wp-admin → Passport, and corresponding tools you may be asked to run directly:
- `resign_agent_manifest` — re-signs any active agent whose abilities.json signature has gone stale.
- `enable_webmcp_defaults` — exposes only a small, hand-curated allowlist of read-only tools known to be genuinely safe for an anonymous public visitor (`search_content`, plus `wc_browse_products`/`wc_view_cart` where WooCommerce is active), never a blanket sweep of readonly/low-risk tools. Deliberately never touches the write-capable cart tools (`wc_add_to_cart`, `wc_update_cart_item`) even though they're LOW risk — those ship off like everything else and need the per-tool toggle in wp-admin → Passport (Advanced mode) to turn on, a separate, deliberate admin decision since they mutate state. Risk tiers designed for the trusted wp-admin chat context are not a safe proxy for "safe to expose to anyone on the internet."
- `configure_approval_gate` — turns off WebMCP exposure for anything exposed above a safe risk tier (never lowers a tool's own declared risk).
- `enable_agent_readiness` — the master WebMCP Bridge switch; also backs `webmcp_tools_registered` and `well_known_manifest`.

## What the AI Radar agent fixes

`llms_txt_present`, `robots_ai_directives`, and `schema_org_present` are checked here, but the real fixes for them (`generate_llms_txt`, `update_robots_txt`, and `add_faq_schema`/schema tooling) live in the bundled AI Radar agent, not in this skill's own tool list. If the user asks you to fix one of these three directly, point them to AI Radar (or hand off / suggest opening it) rather than attempting a workaround with unrelated tools — don't try to write to robots.txt or llms.txt yourself using generic file tools; that would bypass the preview-then-apply safety AI Radar's dedicated tools provide.

## Explaining the score conversationally

Lead with the overall grade and the single highest-impact fixable issue (usually the highest-weight check that scored 0 or low), not a recitation of all eight rows. Offer to run a free fix directly when one exists and the user confirms; for the three AI Radar-fixable checks, offer to hand off to AI Radar; otherwise point to wp-admin → Passport for the fuller breakdown.
