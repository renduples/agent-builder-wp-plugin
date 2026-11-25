---
name: skill-creator
description: "Help the user create a new skill for their AI agents, improve an existing skill, or optimise a skill's trigger description. Use when the user wants to build a custom skill to teach an agent a new workflow, edit or refine a skill that isn't working as expected, or understand how to structure SKILL.md files for this plugin."
---

# Skill Creator

Skills are SKILL.md files stored in the WordPress database that inject instructions into an agent's context when the skill is triggered. They teach agents when and how to perform a specific category of tasks.

## Skill Structure

Every skill has two parts:

**1. YAML frontmatter** (required)
```yaml
---
name: my-skill
description: "The trigger description — when should this skill activate? Be specific and include example phrases."
---
```

**2. Markdown body** — the instructions Claude follows when the skill is active.

## Creating a New Skill

### Step 1: Define intent

Answer these four questions:
1. **What does this skill help with?** (one sentence)
2. **When should it trigger?** (list 5 example user requests that should activate it)
3. **What should the output look like?** (file, text, a series of tool calls, a structured response)
4. **What tools does it need?** (list the tool names, or "none" if it's instruction-only)

### Step 2: Write the description (most important part)

The `description` field is the trigger. It must be:
- **Specific** — name the exact task types, file formats, or phrases that should activate it.
- **Inclusive** — cover casual phrasings ("the xlsx in my downloads") not just formal ones.
- **Exclusive** — say what should NOT trigger it to prevent false positives.

Good description pattern:
> "Use this skill when [specific trigger conditions]. Also trigger when [edge cases]. Do NOT trigger when [exclusions]."

### Step 3: Write the body

Structure the body with:
- **Available tools** — a table listing each tool and when to use it (if tools are involved)
- **Workflows** — step-by-step numbered sequences for the most common tasks
- **Quality rules** — non-negotiable standards (never do X, always do Y first)
- **Examples** — concrete examples of inputs and expected tool calls or outputs

Keep the body under 400 lines. Long skills are harder to follow — move reference material to the tool descriptions instead.

### Step 4: Save and test

1. Go to **AgentStructor → Skills → Add New Skill**
2. Paste your SKILL.md content into the content field
3. Assign it globally (all agents), or check the box for one or more specific agents
4. Enable it and start a chat — use one of your trigger phrases
5. Check whether the agent behaves as intended

## Improving an Existing Skill

When a skill isn't triggering correctly:
- Make the `description` more specific and add more trigger phrases
- Add "Also trigger when..." clauses for edge cases that were missed

When the agent triggers the skill but does the wrong thing:
- Add a **Workflows** section with explicit numbered steps
- Add a **Quality rules** section with clear constraints
- Give a concrete before/after example of the correct behaviour

When the skill fires when it shouldn't:
- Add "Do NOT trigger when..." clauses to the description
- Narrow the trigger phrases to be more specific

## Skill Writing Rules

- **The description is the trigger, not the title.** A short name is fine; the description does the real work.
- **Lead with the workflow, not the background.** Agents need what to do, not why skills exist.
- **Be explicit about tool call order.** "Always call `read_pdf` before `create_pdf`" is better than "use the tools appropriately."
- **Use tables for tool lists.** Easier to scan than prose.
- **Avoid vague verbs.** "Handle", "process", "manage" mean nothing. "Call `list_posts` first", "pass `limit: 50`", "cap at 50 rows" are actionable.
- **One skill, one domain.** Don't combine spreadsheet and PDF workflows in a single skill — create two.

## Example Minimal Skill

```markdown
---
name: post-seo-review
description: "Review the SEO of a WordPress post and suggest improvements. Trigger when the user asks to 'check SEO', 'review a post for SEO', 'improve my post's ranking', or similar. Use when a specific post ID or URL is mentioned alongside an SEO-related request."
---

# Post SEO Review

## Workflow
1. Call `analyze_post_seo` with the post ID.
2. Identify the top 3 issues from the returned data.
3. Suggest specific fixes with the exact copy or metadata changes needed.
4. Offer to apply the fixes with `update_post_seo`.

## Quality Rules
- Always confirm the post ID with the user before running `update_post_seo`.
- Focus on the three highest-impact changes, not a laundry list.
- Give the user the exact new title or meta description text — don't just say "improve it".
```
