# Prompt Testing

PHPUnit tells you the code works. This tells you the *agents* work.

`wp agent prompt-test` replays a ranked catalog of real-world prompts —
[`most_popular_prompts.md`](most_popular_prompts.md), fifty-four things that genuinely frustrate
WordPress site owners — against the bundled agents on a live install, and writes a report
saying which ones answered, which tools they actually reached for, and what it cost.

**Results are not deterministic.** The same prompt and the same model produce different prose
every time. So nothing here asserts on wording. What it asserts on is everything that *is*
stable: the call did not error, it produced something, it did not spin out in the tool loop,
and the tools the catalog says should have been reached were reached. If you are expecting
PHPUnit-style byte-identical reruns, recalibrate now.

## ⚠️ Before you run anything

**It costs real money.** Every prompt is a real call to your configured provider. A full
fifty-prompt sweep is roughly $0.50–$3.00 and 10–30 minutes depending on the model.

**Writes really write.** "Draft me a blog post" creates a real post. "Update the meta
description" changes a real one. There is no undo. In supervised mode most of these stop and
ask first — but do not rely on that.

**Use a staging site, not production.** And type `--dry-run` before you type anything else.

## Prerequisites

- **A WordPress install with the plugin active**, and WP-CLI.
- **A configured LLM provider and API key** (Settings → APIs). Without one the run refuses to
  start rather than failing fifty times.
- **An administrator account.** The bundled agents require between them `manage_options`,
  `edit_posts`, `moderate_comments`, `list_users`, `edit_users` and `read`. Pass it with
  WP-CLI's own global flag: `--user=admin`. Running as nobody is an error, not a silent skip.
That is all. The catalog ships **inside the plugin**, at
`library/prompt-tests/most_popular_prompts.md`, so this works on a WordPress.org install as
well as a source checkout.

## Quick start

```bash
# Resolve, validate and print the plan. No LLM calls, no cost.
wp agent prompt-test --dry-run

# One agent, for real.
wp agent prompt-test --user=admin --agent=seo-optimizer

# The ten most common complaints, capped at 50 cents.
wp agent prompt-test --user=admin --rank=1-10 --max-cost=0.50

# Everything, graded.
wp agent prompt-test --user=admin --all --judge --yes
```

A bare `wp agent prompt-test` runs **nothing**. It prints the selectors and exits. That is
deliberate: there is no default that quietly spends money.

## Every flag

| Flag | Default | What it does |
|---|---|---|
| `--all` | — | Run the whole catalog. |
| `--agent=<slugs>` | — | Only these agents' prompts. Comma-separated. |
| `--rank=<spec>` | — | Rank range: `1-10`, `1,3,7`, `5-`, `-10`. |
| `--id=<spec>` | — | Prompt IDs: `P01`, `P01,P05`, `P01-P10`. |
| `--limit=<n>` | — | Cap after the other filters. |
| `--shuffle` | off | Randomise order, so `--limit` samples across the catalog instead of always the top N. |
| `--dry-run` | off | Resolve, validate, print the plan. **Zero LLM calls.** |
| `--judge` | off | Also grade each answer 1–5 with the configured provider. Roughly doubles cost. |
| `--verdict=<mode>` | `assert` | `assert` applies the checks below; `none` records everything and judges nothing. |
| `--mode=<mode>` | `chat` | `chat` is what a real user gets. `autonomous` is the scheduled-task path and skips capability checks. |
| `--max-cost=<usd>` | `2.00` | Stop once this much has been spent. `0` disables the cap. |
| `--max-iterations=<n>` | `10` | Tool-loop ceiling per prompt. |
| `--timeout=<seconds>` | provider default | Per-request LLM timeout. |
| `--delay=<seconds>` | `0` | Sleep between prompts, to stay under a rate limit. |
| `--prompts=<path>` | site copy, else shipped | Alternate catalog. Also accepted positionally. |
| `--output=<path>` | `wp-content/agentic-knowledge/prompt-tests/prompt_test_results.md` | Where the report goes. `-` writes to stdout. |
| `--format=<fmt>` | `md` | `md` writes the report; `table`/`json`/`csv`/`yaml` print to stdout instead. |
| `--max-response-chars=<n>` | `2000` | Truncate responses in the report. `0` keeps them whole. |
| `--allow-skips` | off | Exit 0 even when prompts were skipped for a missing capability. |
| `--yes` | off | Skip the "this spends real money" confirmation. CI must pass this. |
| `--user=<id\|login>` | — | WP-CLI's own global flag. Effectively required. |

## Verdicts

| Verdict | Meaning |
|---|---|
| ✅ `PASS` | No error, non-empty answer, under the iteration ceiling, and every expected tool was called. |
| ⏸ `PASS ⏸` | All of the above, and a tool stopped at the risk gate. **A pass.** |
| ❌ `FAIL` | Empty or errored answer, hit the iteration ceiling, bailed out of the tool loop, or an expected tool was never called. |
| ⏭ `SKIP` | The acting user lacked a capability, or the agent is not active. |
| 💥 `ERROR` | Threw or timed out. Recorded with the class and `file:line`; the run continues. |
| ⏹ `NOT RUN` | The cost cap stopped the run before this prompt. |

**A gated prompt is a pass, and this matters.** In supervised mode — the default — every
medium-risk tool returns a proposal and every high-risk tool queues for approval. An agent
correctly refusing to change your site without asking is the safety model working. Scoring
that as a failure would create quiet pressure to weaken the risk tiers to get a green run,
which is exactly the wrong incentive. If a whole run comes back gated, check
Settings → Security for the agent mode before concluding anything.

Note that the expected-tools check counts a tool as called even if the gate then stopped it.
The agent *reaching for the right tool* is what is under test.

### `--judge`

Sends the prompt and the answer back through your configured provider with a fixed rubric —
did it answer the question, is it consistent with the tool output, could a non-technical owner
act on it — and records a 1–5 score with one sentence of justification.

The score sits **beside** the verdict and never changes it. A nondeterministic grader must not
be able to flip a deterministic result. Judge tokens and cost are reported on their own line so
they never contaminate the agent cost you are actually measuring.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Everything selected passed. |
| `1` | At least one `FAIL`, `ERROR`, or `SKIP` (unless `--allow-skips`). |
| `2` | The run was aborted: cost cap hit, catalog unreadable, or a pre-flight check failed. |

## The catalog

[`most_popular_prompts.md`](most_popular_prompts.md) is the source of truth — the runner parses
those markdown tables directly. There is no generated sidecar to keep in sync; edit the file.

There are two copies, and the difference matters:

| | Path | Role |
|---|---|---|
| **Shipped** | `library/prompt-tests/most_popular_prompts.md` | Ships in the plugin. Treated as read-only — a plugin update replaces it. |
| **Yours** | `wp-content/agentic-knowledge/prompt-tests/most_popular_prompts.md` | Created the first time anything edits the catalog, by copying the shipped file. |

The runner reads your copy when it exists and the shipped one otherwise, and
`manage_prompt_catalog` always writes to your copy. So your additions survive plugin updates,
and nothing ever needs to write inside the plugin directory.

Its columns and the rules for writing a good prompt are documented at the top of the file
itself. The short version:

- Write it **the way a non-technical owner types it**. If an agent only works when addressed in
  tool names, it does not work.
- Make it **self-contained** — the harness sends no conversation history.
- Only list `expect_tools` the assigned agent genuinely declares, or validation fails and the
  whole run refuses to start.
- Be honest in `Coverage`. The `partial` and `none` rows are the most valuable in the file:
  they are the ones that say what the product cannot do. The catalog's own **Coverage gaps**
  section collects them.

`--dry-run` re-validates everything — every agent slug, every tool name, every duplicate ID —
in about a second, for free. Run it after any edit.

## Reading the report

The report is written beside your catalog, at
`wp-content/agentic-knowledge/prompt-tests/prompt_test_results.md`, and overwritten on every
run. It contains real LLM output from your site and real cost figures, so it is deliberately
kept out of the plugin directory and out of git.

It is rewritten after *every prompt*, not once at the end. A fatal, an OOM kill or a ctrl-C
thirty prompts into a paid run still leaves a readable report of the thirty that finished.

- **Header** — plugin, WordPress and PHP versions, site URL, provider and model, agent mode,
  who it ran as, and the exact command. Agent mode is there because "the agent refused to write
  the post" reads very differently once you know the site is in supervised mode.
- **Totals** — run / passed / failed / skipped / errored / gated, tokens, cost, wall clock.
- **Needs attention** — everything that did not pass, with the reason, so you do not have to
  scroll.
- **Details** — one section per prompt. Responses are collapsed behind `<details>` so a
  fifty-entry file stays skimmable.

## What the harness changes while it runs

Two filters, installed for the duration and removed afterwards:

- **Response caching is disabled.** This is not optional. `Agent_Controller::chat()` consults
  `Response_Cache` on both the read and the write side, so without it a second run of the same
  prompt replays the first run's answer — same tokens, same tools, no LLM call — and looks like
  a pass. (Varying the session ID does *not* help: the cache key is
  `md5( message | agent_id | role_bucket )` and the session ID is not part of it.)
- **Local memory recall is disabled**, so run N+1 is comparable to run N.

It also resets the agent permission override and audit mode context after every prompt.
`run_autonomous_task()` does not clear its own override, which across fifty prompts in one
process would silently relax the risk gate for everything after the first autonomous prompt.

It changes nothing else — not your agent mode, not your provider, not your content.

## Troubleshooting

**"No user context."** Add `--user=admin`. The harness will not silently fall back to user 1;
that would make it lie about what a real user can do.

**"current user lacks: edit_posts"** — the prompt is assigned to an agent that account cannot
use. Run as an administrator, or scope the run with `--agent=` to agents that account can reach.
`--allow-skips` stops this failing the build.

**"Emergency Stop is active"** — every agent call would be blocked. Turn it off in
Settings → Security.

**"No LLM provider is configured"** — Settings → APIs.

**Everything comes back gated.** The site is in supervised mode. That is correct behaviour,
not a failure. The header records the mode.

**Rate limits / timeouts.** Use `--delay=2` to space the calls out and `--timeout=` to give
each one longer.

**Results differ wildly between runs on different sites.** Expected. "Audit my most recent
post" behaves completely differently on an empty install. Results are only comparable across
runs on the *same* site; the report header records the site URL for that reason.

## Self-improvement: the loop this feeds

The harness is also wired into the product. **Assistant Trainer** — the meta-agent — can run it
and act on the results, through three tools and the `prompt-test-improvement` skill:

| Tool | What it does | Cost |
|---|---|---|
| `analyze_prompt_results` | Correlates the catalog against every agent's real manifest and the tool registry. Names which agent is missing which tool, which prompts have no honest answer, and which implemented tools no agent can reach. | Free, read-only |
| `run_prompt_tests` | Replays up to five catalog prompts and reports verdicts, tools called and cost. | Real money; confirms first |
| `manage_prompt_catalog` | Reads and edits the catalog — re-rank a prompt, correct a stale expectation, add one for a complaint nobody had thought of. | Free to read; confirms before writing |

So you can ask it, in chat: *"Which of our agents are missing tools they're expected to have?"*
or *"Run the SEO Optimizer's prompts and tell me what's failing."*

The loop is **measure → diagnose → propose → verify**, and the skill enforces two rules worth
repeating here:

- **Never weaken a risk level to make a prompt pass.** A gated prompt already passes.
- **Never edit an expectation just to clear a failure.** Changing the test because the agent
  could not meet it turns the suite into decoration. Fix the agent, or say plainly that the
  capability does not exist.

It proposes; it does not silently rewrite your agents. Granting an agent a tool needs a manifest
edit, an `Abilities_Manifest::save_integrity_hash()` re-sign and a `AGENT_BUILDER_DB_VERSION`
bump — none of which can happen from a chat turn, and the first without the second leaves the
agent **blocked**, not degraded.

## How this relates to the PHPUnit suite

They are different tools and should stay that way.

| | `composer test` | `wp agent prompt-test` |
|---|---|---|
| Hermetic | Yes — no HTTP call, ever | No — real provider calls |
| Cost | Free | Real money |
| Speed | Under a second | Minutes |
| Deterministic | Yes | No |
| In CI on every push | Yes | **No** |
| What it proves | The plumbing is correct | The agents actually work |

Do not wire the prompt harness into `composer test` or run-on-every-push CI. A paid,
nondeterministic, minutes-long suite in a pre-merge gate gets disabled within a fortnight. If
you want it in CI, run it on a schedule against staging, with `--yes --max-cost=` set, and treat
a failure as a report to read rather than a build to block.

The parser and the catalog *are* covered by PHPUnit — `tests/unit/test-prompt-catalog.php` and
`test-prompt-catalog-writer.php` validate the shipped catalog on every run, for free. A typo in
a tool name or an agent slug fails there, in milliseconds, long before it can waste a paid run.
They also assert that every bundled agent has at least one prompt, and that the shipped catalog
lives somewhere the WordPress.org build keeps.
