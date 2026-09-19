---
name: prompt-test-improvement
description: "Use this skill when the user wants to check whether the bundled agents actually work, or wants the plugin to improve itself. Trigger on requests like 'run the prompt tests', 'is the SEO Optimizer still working', 'which agents are missing tools', 'why did that prompt fail', 'add a prompt for X to the test catalog', 'what should we fix next', or 'make the plugin better at handling hacked sites'. Also trigger when a prompt test has just failed and the user asks what to do about it. Do NOT trigger for writing a skill for an unrelated workflow (that is skill management), for building a brand-new agent from scratch (that is agent creation), or for deploying an agent to a chat widget or schedule (that is agent deployment)."
allowed-tools: analyze_prompt_results run_prompt_tests manage_prompt_catalog manage_skill read_agent_source search_capabilities
---

# Prompt-Test-Driven Improvement

The plugin ships a catalog of real-world prompts — the things that actually frustrate
WordPress site owners — each assigned to the bundled agent that should handle it. Replaying
them shows whether the agents work in practice rather than in principle, and the failures
point at exactly what to fix.

This skill is the loop: **measure → diagnose → propose → verify.** Follow it in order. The
expensive step is in the middle, and most of the time you will not need it.

## Available tools

| Tool | When to use | Cost |
|---|---|---|
| `analyze_prompt_results` | Always start here. Correlates the catalog against every agent's real manifest and the tool registry. | Free, read-only |
| `manage_prompt_catalog` | Read the catalog, or add/re-rank/correct a prompt. | Free to read; confirms before writing |
| `run_prompt_tests` | Replay up to five prompts for real and see which tools the agent actually reached for. | **Real money.** Confirms first |
| `manage_skill` | Write or fix a skill, when the agent has the tools and just sequences them badly. | Confirms first |
| `read_agent_source` | Read an agent's manifest and prompt when you need to see why it behaved as it did. | Free |
| `search_capabilities` | Confirm a tool exists, and what it does, before proposing it. | Free |

## The loop

### 1. Measure — start free

Call `analyze_prompt_results` before anything else. It returns three things worth acting on:

- **`proposals` with `kind: grant_tool`** — the catalog expects a tool the assigned agent does
  not declare. These are certain, not guesses: the prompt cannot pass as written. Fix these first.
- **`proposals` with `kind: partial_coverage` or `capability_gap`** — the catalog itself admits
  the agent cannot fully answer. Read the `why`, which carries the catalog's own note.
- **`unreachable`** — implemented, working tools that no bundled agent declares. This is usually
  the largest number in the response and the biggest single source of missed capability.

If there are `grant_tool` proposals, you already know what is wrong. Do not spend money
confirming it.

### 2. Diagnose — only when the answer is not obvious

Run `run_prompt_tests` when you need to see behaviour rather than configuration: a prompt that
should pass and does not, an answer the user says is unhelpful, or a fix you want to verify.

Always tell the user what it will cost before calling it — it makes real LLM calls — and scope
it as tightly as you can (`ids` for specific prompts, or `agent` plus a small `limit`).

Reading the result:

- **`tools_called` is empty but tools were expected** → the agent did not recognise the request
  as something it had a tool for. That is usually a *description* problem, in the tool or in the
  agent's system prompt, not a missing tool.
- **`tools_called` has the right tools but the answer is poor** → a skill problem. The agent has
  the pieces and sequences them badly.
- **`stopped_for_approval: true`** → **this is not a failure.** The agent correctly paused for
  permission before a risky change. Never propose weakening a risk level to make a prompt pass.
- **`verdict: FAIL` with "expected tool ... never called"** → check the manifest first with
  `read_agent_source`; the tool may simply not be granted.

### 3. Propose — smallest change that fixes it

In order of preference, because each is cheaper and safer than the next:

1. **Grant an existing tool.** The commonest fix by a wide margin. The tool exists and works; it
   is just not in that agent's `agent.json` and `abilities.json`.
2. **Write or fix a skill** with `manage_skill`. Right when the agent has the tools and needs to
   be taught the sequence.
3. **Correct the catalog** with `manage_prompt_catalog`. Right when the *expectation* was wrong —
   a tool renamed, a prompt assigned to the wrong agent, a rank that no longer reflects reality.
4. **Propose a new tool.** Last resort, and a real development task. Say so plainly rather than
   implying you can do it in this conversation.

Never do (3) to make a failure disappear. Changing an expectation because the agent could not
meet it turns the suite into decoration. If the expectation was genuinely wrong, say why.

### 4. Verify

After a change, re-run just the affected prompts with `run_prompt_tests` and check the tool
actually gets reached now. An unverified fix is a guess.

## Granting an agent a tool

This has three steps and **skipping any of them leaves the agent broken or the change inert**:

1. Add the tool name to `library/agents/<slug>/agent.json` (`tools`) and to
   `abilities.json` (`abilities`, with a `risk`).
2. Re-sign: `\Agentic\Abilities_Manifest::save_integrity_hash( '<slug>' )`. The manifest is
   HMAC-signed against a server-local signature file. Edit it without re-signing and the agent
   is **blocked outright** with "Manifest signature mismatch" — not degraded, blocked.
3. Bump `AGENT_BUILDER_DB_VERSION`. The tool-path list is cached in a transient, so on existing
   sites a newly added tool file stays invisible to the model until that cache is busted.

You cannot do steps 2 and 3 from chat. Give the user the exact edit and the exact commands, and
be explicit that the agent will be blocked between step 1 and step 2.

## Adding a prompt

Add one when the user describes a real frustration the catalog does not cover. Good prompts:

- are written **the way a non-technical owner types them** — "my contact form stopped emailing
  me", not "diagnose SMTP configuration";
- are **self-contained** — the harness sends no conversation history, so "the post we discussed"
  will never work;
- name an **honest `coverage`** — `partial` and `none` rows are the most valuable in the file,
  because they are the ones that say what the product cannot do;
- only list `expect_tools` the assigned agent genuinely declares, or the catalog will not
  validate and the whole suite refuses to start.

Set `rank` by how much the problem actually hurts a real site owner, not by how well the plugin
handles it.

## What not to do

- Do not lower a risk level, weaken an approval gate or reclassify a tool to make a prompt pass.
  A gated prompt already passes.
- Do not run the full catalog from chat. `run_prompt_tests` is capped at five prompts for good
  reason; a full sweep is `wp agent prompt-test --all` on the command line.
- Do not delete a failing prompt. A failure that is still in the file is a record of something
  worth fixing; a deleted one is a gap nobody remembers.
- Do not claim a fix works because it looks right. Verify it with step 4.
