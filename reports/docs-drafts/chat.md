# Agent Chat

Agent Chat is the conversation screen for your active agents. You pick an agent, type (or speak, or attach an image), and the agent replies — streaming the answer, calling tools when it needs to, and pausing for your OK when a change needs approval.

> The Chat page has no Basic / Advanced toggle of its own. The site-wide Basic / Advanced switch in the admin header controls whether the read-only **Playground** side panel appears. Basic Chat (the thread, composer, and history) is the same in both modes.

## Overview

Open **Agent Builder → Chat**. The page heading is **Agent Chat** with a static **Online** label next to it (that label is not a live health check — use the AI and Credits lights in the chat header for that).

What you see:
- A chat thread with the current agent's welcome message
- A composer to send messages
- Header controls to switch agents, check AI/credits, open history, and start a new chat
- A **Manage Agent Deployments** link under the thread (opens Publish)
- In **Advanced** mode only, a **Playground** panel beside the thread

If no agent is active, you get an empty state: **No Agents Activated**, plus **Activate Agents in Dashboard** for administrators (despite the label, that link opens the [Agents](https://agentic-plugin.com/docs/agents/) screen) or "Contact your site administrator to activate AI agents." for everyone else.

Chat picks a default agent in this order: a locked-in agent (when this widget is embedded on another admin screen), the agent in the page URL, your last-used agent, **WordPress Assistant** if you've never chatted, then the first accessible agent.

## Agent Chat sections

### Header

#### Agent picker

When more than one agent is accessible, a dropdown lists them by name (with each agent's icon), sorted A–Z. Changing the selection saves that agent as your last-used preference and reloads Chat on the new agent.

If you're an administrator, the last option is **+ Load more . . .** — it opens the [Agents](https://agentic-plugin.com/docs/agents/) screen in a new tab and does **not** switch the current conversation.

When only one agent is available (or Chat is locked to one agent), you see that agent's icon instead of a dropdown.

Next to the picker: **Version {n} | By {author}** (author links out when the agent has an author URL).

#### AI and Credits lights

Two status lights, filled from the plugin's status endpoint:
- **AI** — connected, unreachable, no provider configured, or still checking
- **Credits** — OK, running low, exhausted (add credits or use your own API key), or unknown

Hover a light for the exact tooltip. These are the live health check; the **Online** text in the page title does not change with them.

#### History and New Chat

Two icon buttons:
- **Chat History** (clock) — opens the history panel
- **New Chat** (pencil) — starts a fresh session for this agent (clears the on-screen thread back to the welcome message, and clears this agent's saved local history)

### Welcome message and starter prompts

The first bubble is the agent's welcome (from Knowledge → Instructions if you've set one, otherwise the agent's default). Markdown bold, links, and list items are rendered.

**WordPress Assistant** expands a live **Your AI team:** list of your other *active* agents, plus a **Download more Agents** link to the [community marketplace](https://agentic-plugin.com/community-agents/). That roster is the agent's welcome text, not a separate control.

Under the welcome, **Try asking** shows up to four starter prompts. Click one:
- A prompt *without* `[brackets]` is sent immediately
- A prompt *with* a `[placeholder]` is copied into the composer so you can fill it in first

### History panel

**Chat History** slides open a panel titled **Chat History** (close with ×). It loads up to 30 previous sessions for the current agent:
- Loading: "Loading sessions…"
- Empty: "No previous conversations found."
- Error: "Failed to load history."
- Each row: a preview line and a date/time; the current session is highlighted. Click a row to replay that conversation in the thread.

If history is capped by plan, a note at the bottom says "Showing last {n} days" with a link to upgrade.

### Composer

The input placeholder is **Ask {agent name} a question...**. Enter sends; Shift+Enter inserts a newline. While the agent is working, a typing line reads **Agent is thinking...** (after 20 seconds with no reply it changes to **Warming up the AI model, please wait…**; while a tool runs it shows a gear and the tool name).

#### Attach image (paperclip)

Shown when vision is enabled for this agent (on by default). Accepts JPEG, PNG, GIF, or WebP, max 5 MB. A preview appears above the composer with an × to remove it. Messages with no text send as "Describe this image."

#### Voice input (microphone)

Shown when audio is enabled for this agent (on by default). Click to dictate into the composer (placeholder becomes **Listening...**). Click again to stop. Needs HTTPS and Chrome, Edge, or Safari; if the browser can't do it, the button stays visible but disabled with that reason in the tooltip. Denying the microphone disables it until you reload over HTTPS.

#### Read aloud

The speaker button appears only when text-to-speech is enabled *and* an API secret is configured. It's off until you click it (**Read aloud: off — click to enable** / **Read aloud: on — click to disable**). When on, the next reply is spoken and the text is revealed in time with the audio.

#### Send

The paper-plane button (accessible name **Send message**) submits the composer.

### Replies

Agent replies stream in as they're generated. After a reply you get an action bar:
- **Copy response**
- **Good response** / **Bad response** (thumbs; saved as feedback)
- **Regenerate response** — resends your last message and replaces this reply

Code blocks in a reply get a **Copy** button (it briefly reads **Copied!**).

If the reply was served from cache, a **⚡ cached** mark appears.

When the agent used tools, thought out loud, or took more than one iteration, an **Agent reasoning & steps** disclosure appears under the reply with **Thinking:**, **Tools used:**, and **Iterations:** as applicable.

If another agent handed this conversation over, a banner at the top reads **Handed off from {agent}. Context from the previous agent has been shared.** and the first message is sent automatically with that context.

### Proposed changes and approvals

When the agent wants to change something, a card appears in the thread. There are two kinds:

**Proposed Change** (medium-risk, in-chat grant)
- Description of the change
- **▶ Show Diff** / **▼ Hide Diff** when a diff is included
- For administrators: **Allow Once**, **Allow this Session**, **Always Allow**, **Deny**
- For everyone else: "This action requires admin approval."

**Needs Your Approval** (high-risk, same queue as the Approvals screen)
- Description
- For administrators: **Approve** or **Reject** only (no once/session/always)
- For everyone else: "This action requires admin approval."

Approving or rejecting is logged either way. High-risk items are the same records you see on [Approvals](https://agentic-plugin.com/approval-queue/).

### Consent banner

If **Require chat consent** is on in Settings, a banner with your **Consent text** and **I Understand** appears above the composer. Input and Send stay disabled until you accept. Acceptance is stored in a cookie for a year.

### Optional WhatsApp call-to-action

If **Show the WhatsApp Pro promo in admin chat** is on in Settings, a **Continue on WhatsApp** strip (with a **Pro** badge) appears under the thread and links to the WhatsApp connector page. It is off by default.

### Footer

Under the chat: **Manage Agent Deployments**, which opens the Publish screen so you can put this agent on the site (shortcodes, blocks, scheduled tasks, and so on).

Token/cost totals in the chat footer are not shown on this WordPress.org build.

A "Powered by Agent Builder — {provider} — {model}" line appears only if you turn off **Hide “Powered by Agent Builder” branding** in Settings; that hide-branding option is on by default.

## Playground (Advanced mode only)

When the site-wide (or Chat) mode is Advanced, a **Playground** panel sits beside the thread. It is **read-only**. The panel says so up front: per-request model or temperature overrides are not wired in the chat backend — changing them here would not change the run.

The subtitle is the current agent name and slug (for example `wordpress-assistant`).

### Model

- **Provider** — display name of the effective provider (for example Agentic, OpenAI)
- **Model** — model id, or — if none is set
- **Vision model** — only if a vision model is configured and it differs from the chat model
- **Source** — **Site default (Settings → Providers)** or **Per-agent override (Settings → Agents)**

### Instructions

Persona notes from [Knowledge → Instructions](https://agentic-plugin.com/agent-instructions/), appended to the system prompt (they do not replace it). If a response style is set, you'll see **Response style:** Concise, Detailed, Technical, or Friendly. If there are no notes: **No persona notes saved for this agent.**

### System prompt

The agent's base prompt. At send time, knowledge, skills, site context, and the persona notes are assembled around it. Expand **Preview ({n} characters)** to read it, or **This agent has no base system prompt on file.**

### Tool access

Tools this agent may call, from the same inventory as [Safety Center](https://agentic-plugin.com/docs/safety-center/). Each row is the tool's identifier plus a risk badge (`none`, `low`, `medium`, `high`, `extreme`) — the effective tier the executor enforces. Disabled tools are omitted. Empty: **No tools declared for this agent.**

### Last Chat API exchange

Real JSON for `POST agentic/v1/chat` — the plugin REST request and the stream's `end` (or `error`) event, or the JSON body. The raw LLM provider payload is assembled server-side and is **not** returned here.

- **Request** starts as "Send a message to capture the request." After you send, the JSON body is shown (image attachments are replaced with `[omitted image attachment]`)
- **Response** starts as "Waiting for a reply…", then "Waiting for response…", then the captured end event

Switching to Basic hides this panel; the thread is unchanged.

## Common tasks

### Sending a first message

1. Open **Chat**
2. If needed, pick an agent from the dropdown
3. Click a **Try asking** prompt, or type in **Ask {agent} a question...** and press Enter (or the send button)

### Switching agents

1. Open the agent dropdown in the chat header
2. Choose another agent — the page reloads on that agent
3. To activate more agents, choose **+ Load more . . .** (administrators) or go to [Agents](https://agentic-plugin.com/docs/agents/)

### Reviewing a proposed change in the thread

1. When a **Proposed Change** or **Needs Your Approval** card appears, read the description
2. Open **Show Diff** if you want the details
3. For a proposed change: **Allow Once**, **Allow this Session**, **Always Allow**, or **Deny**
4. For a high-risk approval: **Approve** or **Reject** (or handle it on the Approvals page — it's the same queue)

### Replaying an earlier conversation

1. Click the clock (**Chat History**)
2. Pick a session
3. The thread replays that session; the history panel closes

**New Chat** starts a new session without deleting past ones from the server.

### Attaching an image

1. Click the paperclip
2. Choose a JPEG, PNG, GIF, or WebP under 5 MB
3. Check the preview (× to remove)
4. Type a question or send with an empty box to ask the agent to describe the image

If the paperclip is missing, **Vision / image input** is off under Settings → Agents (Chat features).

### Inspecting what the agent actually used (Advanced)

1. Set the site-wide switch to **Advanced** and reopen Chat (or stay there if you're already Advanced)
2. In **Playground**, check **Model**, **Instructions**, **System prompt**, and **Tool access**
3. Send a message
4. Scroll to **Last Chat API exchange** for the captured request and end event

Nothing in Playground is an editor — change provider/model in Settings, persona notes in Knowledge → Instructions, and tools in Tools / Safety Center.

## FAQ

**Q: Why is there no Basic / Advanced toggle on this page?**

A: Chat doesn't have a per-screen toggle. The site-wide Basic / Advanced switch in the admin header is what shows or hides Playground. The thread, composer, history, and approvals are the same in both modes.

**Q: The page says Online but the AI light is red.**

A: **Online** next to the title is static. The **AI** and **Credits** lights are the live check. Red AI usually means no provider is configured or the service is unreachable — test the provider from the [Dashboard](https://agentic-plugin.com/docs/dashboard/) or [Settings → Providers](https://agentic-plugin.com/manage-llm-providers/).

**Q: I don't see Playground.**

A: Switch the site-wide control to Advanced. Playground is Chat-only Advanced chrome; Skills or Publish embeds of the same chat widget never show it.

**Q: Can I change the model from Playground?**

A: No. The panel is read-only. Effective model comes from the site default (Settings → Providers) or a per-agent override (Settings → Agents). Temperature isn't a Chat control.

**Q: Where did my previous chat go?**

A: **New Chat** starts a new session for this agent; earlier sessions are under **Chat History**. History is per agent (switching agents is a different list). Some plans only keep the last N days.

**Q: Voice / images / read-aloud aren't showing.**

A: These are **Chat features** on Settings → Agents:
- **Voice** — **Audio input** (on by default); needs HTTPS and Chrome, Edge, or Safari
- **Images** — **Vision / image input** (on by default); otherwise the paperclip is hidden
- **Read aloud** — **Text-to-speech** on *and* an API secret configured; otherwise the speaker button stays hidden

**Q: What happens if the model is busy?**

A: Chat retries and shows "The AI model is busy. Retrying in {n}s…". Timeouts surface "The request timed out. The AI model may be overloaded — please try again."

**Q: An agent offered to hand me to a teammate.**

A: Some replies include a button that opens Chat with another agent and shares recent turns. You'll see the **Handed off from** banner on the next screen.

**Q: How do I put this chat on my site?**

A: Use **Manage Agent Deployments** under the thread, or open **Publish**. That's where shortcodes, blocks, the admin bar, scheduled tasks, and other surfaces are configured — not on this Chat screen.

**Q: Who can open Agent Chat?**

A: Administrators, and anyone granted permission to chat from the admin bar. The empty state still depends on there being at least one agent you can access.
