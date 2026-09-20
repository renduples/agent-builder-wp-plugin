# Prompt Test Findings — 2026-09-19

First full sweep of the 54-prompt catalog against the 12 bundled agents, on lffci.org.

| | |
|---|---|
| Provider / model | xAI `grok-3` |
| Agent mode | supervised |
| Result | **54 run · 33 passed · 21 failed · 6 gated** |
| Cost | 1,668,629 tokens · 13m 55s |
| Report | `wp-content/agentic-knowledge/prompt-tests/prompt_test_results.md` |

> **Status: all findings below were fixed on 2026-09-20.** The fixes are described inline under
> each item. The numbers in this document are from the original sweep and are kept as the
> baseline to re-measure against — they are not current.

**Read the headline number carefully.** 21 "failures" is not 21 bugs. Sorting them by cause
gives **5 real product gaps**, one behavioural pattern worth a product decision, and the rest
split between flaws in this catalog and a test site with almost no content. The five real gaps
are the ones that matter before a WordPress.org reviewer opens the plugin.

## Per-agent

| Agent | Passed | Notes |
|---|---|---|
| site-health-sentinel | **9/9** | Flawless. The strongest agent in the suite. |
| editorial-director | **3/3** | Delegation worked; correctly gated. |
| skills-assistant | **2/2** | Both correctly gated for approval. |
| storefront-assistant | **1/1** | |
| user-assistant | **5/6** | The one failure is this catalog's fault, not the agent's. |
| ai-radar | 2/3 | Partial-coverage expectation. |
| assistant-trainer | 1/2 | Alternative-route expectation. |
| seo-optimizer | 3/7 | Mostly the "reads, then offers" pattern. |
| support-triage | 2/5 | **Three failures are unmeasurable — the site has 0 comments.** |
| **wordpress-assistant** | **4/10** | Worst absolute count; mostly over-strict expectations, one real bug. |
| **content-writer** | **1/5** | **Worst rate, and the most commercially important agent.** |
| **agent-orchestrator** | **0/1** | Single prompt, and it exposed a real bug. |

## A. Real product gaps — fix these

### A1. Agent Orchestrator cannot see the site's own agents · P49 · **highest priority**

Asked *"I want a chat box on my website so visitors can ask questions"*, it replied:

> No agents are currently available on your site for chat widgets. You'll need to create one
> first (e.g., **via Agent Builder Pro or similar tools**)…

There are **12 active agents** on that site. Two problems, both serious:

1. Its manifest declares `agents_available` — *"Browse AI agents available for **download**…
   pricing, rating, download count"* — but **not** `get_agent_list` (*"all installed agents and
   their status"*). It queried the download catalogue, got nothing, and concluded the site has
   no agents.
2. It then recommended **Agent Builder Pro**. Pro upsell surfaces were deliberately removed
   from the free build (`c19d845`). An agent volunteering a paid upgrade unprompted is a
   guideline risk, and it did so while giving factually wrong information.

This is the single most likely thing for a reviewer to hit: "deploy a chat widget" is the
plugin's headline use case, and the answer is both wrong and an upsell.

**Fixed.** `get_agent_list` added to the manifest and abilities (risk `none`), the prompt now
calls it first and explains that `agents_available` is the download catalogue and says nothing
about what is installed. The Pro line came from the prompt itself — see A6.

### A2. Content Writer refuses work it has the tools for · P40

Asked about posts with no featured image:

> This request … falls outside my scope as the Content Writer (I focus only on creating/editing
> posts and pages). [→ WordPress Assistant]

It declares **`search_media_library` and `set_featured_image`**. It has exactly the tools for
the job and delegated it away. The system prompt's self-description is narrower than its real
toolset.

**Fixed.** The Scope section now states that media already in the library is its job, naming
`search_media_library` and `set_featured_image`, and tells it to check its own tool list before
deciding something is out of scope. A test asserts the prompt names the tool it declares.

### A3. Content Writer writes into the chat instead of creating the draft · P38 · rank 4

The flagship prompt — *"I don't have time to write. Can you draft me a blog post about…"* — and
the agent produced a **genuinely good, site-tailored 600-word post** (it had called
`get_site_context` and correctly pitched to a Georgia farm co-op). Then pasted it into the chat
and never called `create_post_content`.

For a non-technical owner, prose in a chat box is materially worse than "draft created, here's
the link" — they now have to copy, paste and format it themselves, which is the work they were
trying to avoid. This is the use case that sells the plugin.

**Fixed.** Step 4 changed from *"present the draft"* to *"save the draft, then share it"*: call
`create_post_content` with status `draft` and link it. The prompt now says explicitly that
leaving the text in the chat box has not done the job, and that saving a draft is always safe
because publishing is the step that needs approval.

### A4. The approval message is a tautology · P22

Every gated action renders:

> This action requires your confirmation. Reason: This action requires your confirmation before
> proceeding.. Please approve or reject in the chat.

The "Reason:" restates the sentence before it, and there is a doubled full stop. A reviewer
clicking through the safety features — the plugin's main selling point — sees this. It should
name the actual tool and risk.

Also in P22: an alt-text request routed to `report_issue`, which is simply the wrong tool.

**Fixed.** `Tool_Executor` now names the tool and drops the tautology:
*"'Report issue' can change your site, so it needs your approval first. Approve or reject it
below."* A declared manifest reason is used when there is one, normalised to a single full stop.

### A5. Third-party MCP tools outcompete our own

lffci.org has Rank Math installed and MCP-exposed. Our agents repeatedly preferred its tools:

- **P26** — `rank_math__get_top_keywords` chosen instead of our `analyze_search_intent` (failed).
- P10, P16, P44 — `rank_math__audit_site_seo`, `rank_math__get_robots_txt`,
  `rank_math__get_seo_scores`, `wp_extended__get_posts` all called alongside ours (passed anyway).

Not wrong in itself — using a better-informed tool is reasonable — but it means **agent
behaviour changes based on what else is installed**, and our own tools can be sidelined on a
real site.

**Fixed as a preference, not a block.** Every agent prompt now carries a `[TOOL PREFERENCE]`
block: prefer a first-party tool when both would answer, because its results are what the rest
of the instructions describe and its safety checks are the ones the owner configured; reach for
a third-party tool when it genuinely does something ours cannot, and say which and why.
Forbidding them outright would make agents worse on exactly the sites most invested in their setup.

### A6. Five bundled prompts instructed agents to advertise Pro · found while fixing A1

The Pro recommendation in P49 was not the model improvising. It was **written into the system
prompt**: *"if nothing free fits, say so and mention that additional specialist agents are
available separately through Agent Builder Pro."*

Grepping the rest found the same instruction in four more places the admin UI never renders:

| File | What it told the agent |
|---|---|
| `agent-orchestrator/templates/system-prompt.txt` | mention Agent Builder Pro if nothing free fits |
| `assistant-trainer/templates/system-prompt.txt` | specialist needs "available separately through Agent Builder Pro" |
| `support-triage/templates/system-prompt.txt` | mention Pro's "deeper support-automation options" |
| `seo-optimizer/templates/system-prompt.txt` | route to "Agent Builder Pro if nothing free fits" |
| `library/knowledge/platform-knowledge.txt` | a full Pro feature list, loaded into two agents' context |

`c19d845` removed Pro upsell surfaces and pricing URLs from the free build, but only from code
and templates the UI renders. Nobody grepped the *model's* instructions, so the plugin still
shipped telling its agents to sell a paid tier — invisible until an agent said it out loud.

**Fixed.** All five rewritten to state capability limits without naming a product, plus the two
`SKILL.md` availability notes. `tests/unit/test-no-upsell-in-agent-content.php` now scans every
system prompt, manifest, knowledge file and SKILL.md for upsell phrasing, so this cannot return
quietly.

## B. Behavioural pattern — a product decision, not a bug

In roughly six cases the agent **read the data, then asked conversationally instead of
proposing the change through the tool**. P41 is typical:

> Would you like me to: 1. Replace "Uncategorized" with 4–6 relevant categories… Just confirm
> and I'll handle the cleanup using the category tools.

That is polite and safe, but it means the **approval card never appears** and the user needs an
extra round-trip. Only 6 of 54 prompts gated at all. Either the conversational confirm is the
intended UX — in which case the approval mechanism is much less exercised than the safety
messaging implies — or agents should propose and let the gate do the asking. It should be a
decision, not an accident.

Note this is *not* the risk gate failing. When write tools were actually called they gated
correctly every time (P35 `manage_user_privileges`, P53 `manage_skill`, P43/P44
`delegate_to_agent`). The agents simply did not call them.

## C. Flaws in this catalog — my fault, not the agents'

- **P36 was not self-contained.** *"This account looks suspicious, can you lock it"* named no
  account, and the site has one admin. The agent correctly refused to guess — the right
  behaviour. The catalog's own rules require self-contained prompts. **Fixed:** rewritten to
  name the account it means.
- **`expect_tools` was AND-only.** There was no way to say "either of these is acceptable", so a
  prompt answerable two ways always failed. This accounts for most of the wordpress-assistant
  and seo-optimizer failures — P11, P18, P19, P20, P24, P51 all took a defensible alternative
  route and answered well. **Fixed:** an entry may now read `toolA|toolB`, satisfied by either,
  and ten rows were relaxed accordingly. Commas still mean "and".
- **P13 was arguably right to refuse.** It declined to optimise the title of a placeholder
  "Hello world!" post and advised writing real content first. Good judgement, scored as failure.

## D. Environmental — unmeasurable on this site

lffci.org has **1 post, 38 pages, 36 media items, 0 comments, 2 users**.

- **P28, P30, P31 (support-triage)** — "draft a reply to this comment" cannot pass with zero
  comments. Support Triage's 2/5 is not a real score.
- **P13, P15 (seo-optimizer)** — one post, and it is the WordPress default.

These need a seeded fixture site before the numbers mean anything. Until then, treat
support-triage and part of seo-optimizer as **untested**, not failing.

## What was done

All of A1–A6 and C are fixed, with a regression test behind the two that could return quietly
(the upsell scan, and Agent Orchestrator's ability to list installed agents).

**B is deliberately unchanged.** Agents asking conversationally instead of proposing through the
tool is a product decision, not a defect — A3 changes it for the one case where it clearly hurt
(drafting), and the rest should be decided rather than patched prompt by prompt.

**D is still open.** Seeding a fixture site would mutate the dev's own content, so it needs a
`--seed` flag and a disposable target rather than a quick fix. Until then, treat support-triage
as untested rather than failing: the mitigation applied here is narrower — P30's expectation now
accepts `list_comments` alone, because on a site with no comments, looking and reporting none
*is* the correct answer.

Re-run `wp agent prompt-test --all` to measure against the baseline above.

## Caveats

- One model, one run. `grok-3` only; no repeat runs, and results are nondeterministic.
- Supervised mode throughout, so this measures **whether agents reach for the right tools**, not
  whether their writes complete. Exercising write paths needs a separate run on a disposable site.
- `$0.0000` in the report is a cost-estimation gap: no pricing rows exist for `grok-3`, so spend
  is not being tracked. Tokens are accurate.
