# Dashboard

The Dashboard is your central control panel for Agent Builder. It displays your system status at a glance and gives you quick access to every major task you need to run and monitor your agents.

## Overview

When you open Agent Builder, the Dashboard is where you land. It shows:
- Your current license and plugin version
- How many agents are running
- Pending approvals waiting for you
- Recent activity and token usage
- Connected AI providers and their status
- Your site's readiness score for AI agent discovery

All of this information updates automatically as your agents work. You can rearrange the cards by dragging their ⋮⋮ handle to match your workflow.

## Dashboard sections

### Status

The Status card shows your plugin's current health:
- **License** — Your current license tier (Free or Pro) and any renewal deadlines
- **Version** — The installed Agent Builder version and database schema version
- **Pending Jobs** — How many agent tasks are waiting to start (or stuck, if any have crashed)
- **Running Jobs** — How many agent tasks are currently in progress
- **Completed Jobs** — Total agent tasks successfully completed

If you see abandoned or stuck jobs, they auto-recover, but you should check your [Approvals](https://agentic-plugin.com/approval-queue/) queue to see if any important actions failed.

### Approvals & Backups

This card tracks your safety controls:
- **Pending Approvals** — Actions waiting for your OK before agents can run them
- **Completed Approvals** — Total actions you've approved
- **Files Backed Up** — How many files have been backed up (if backups are enabled)
- **DB Tables Backed Up** — How many database tables have been backed up

If you have pending approvals, click the number to go directly to the [Approvals](https://agentic-plugin.com/approval-queue/) page and review them. Higher-risk actions always pause here first, even if agents would run them automatically.

### Site Passport

Your Site Passport score tells you how discoverable and accessible your site is to AI agents. The score ranges from 0 to 100, and includes a letter grade (A, B, C, D, F).

- **Score** — Your overall readiness (green ✓ for 75+, yellow ⚠ for 40-74, red ✗ for below 40)
- **Top Fix** — The quickest win to improve your score

[Learn more](https://agentic-plugin.com/docs/site-passport/) or click **View Details** to see all 8 checks and fix them one by one. Pro users get automated fixes for AI radar and robots.txt directives.

### Activity

The Activity card shows usage patterns:
- **Total Actions** — How many agent actions have been performed
- **Tokens Used** — Total tokens consumed by your agents (Pro users also see estimated cost)
- **Active Agents** — How many agents are currently enabled
- **Uploaded Agents** — Agents you've installed from the library
- **User-Created Agents** — Custom agents you or your team built

Click any metric to drill into [Activity](https://agentic-plugin.com/audit-log/) for detailed logs, or click on specific links to jump to the Agents page.

### Connected Providers

Your AI providers are listed here with their connection status. Each provider shows:
- **Provider name** (e.g., OpenAI, Anthropic)
- **Model** (e.g., gpt-4-turbo, claude-opus-5)
- **Default badge** (if this is your primary provider)
- **Test button** — Click to verify the connection is working

If you see a red ✗ after testing, check your API key and rate limits in [Settings > Providers](https://agentic-plugin.com/manage-llm-providers/). If you have no providers, the [Setup Wizard](https://agentic-plugin.com/setup-wizard/) will guide you through adding one.

### Quick Actions

One-click access to common tasks:
- **Setup Wizard** — Initial configuration (always available)
- **Agent Chat** — Talk to your agents
- **Train an Agent** — Create a new agent
- **Agents** — Manage installed agents
- **Knowledge** — Add training data

Below the main buttons, you'll see secondary actions like Activity and Approvals. You can customize which actions appear here by clicking **Manage Actions**.

#### Emergency Stop

At the bottom of Quick Actions is your Emergency Stop switch. This is a nuclear option — when turned on, it immediately:
- Deactivates all running agents
- Cancels all pending and in-progress jobs
- Disconnects your AI providers
- Blocks any new agent activity until you restore service

Turn this on only in a crisis (e.g., a runaway agent causing problems). You'll be asked to confirm before it activates.

### Interface Settings

Control how the entire admin interface looks and behaves:
- **Default interface** — Choose between Basic (simplified, fewer options) or Advanced (full power)
- **Automatic Agent Updates** — Toggle whether installed agents should update automatically (Free tier shows a link to browse community agents instead)

Every admin page respects this setting unless you've individually overridden it for a specific screen. Click **Reset all screens to my default** to wipe out per-screen overrides.

### Getting Started

If this is your first time with Agent Builder, you'll see a checklist of onboarding steps. Each step has a status indicator:
- ✓ (checkmark) — Completed
- ◆ (diamond) — Not done yet

The checklist disappears once you've completed all steps (you can always turn it back on in Interface Settings if you want to see it again).

## Common tasks

### Checking pending approvals

1. Open the Dashboard
2. In the **Approvals & Backups** card, click the number next to "Pending Approvals"
3. Review each action and approve or reject it
4. Rejected actions are logged; approved actions continue

### Testing a provider connection

1. Open the Dashboard
2. In the **Connected Providers** card, find your provider
3. Click the **Test** button
4. Wait for the result (should show ✓ or ✗ in seconds)

If the test fails:
- Check your API key is correct
- Make sure you haven't hit your provider's rate limit
- Verify your account is active and has credits

### Improving your Site Passport score

1. Open the Dashboard
2. In the **Site Passport** card, click **View Details**
3. Go through each check and address the ones marked as failing
4. Pro users can auto-fix some checks from that page

### Switching to Advanced mode

1. Open the Dashboard
2. Look for the **Interface Settings** card
3. Click the **Advanced** button
4. The entire dashboard will reload with more options on every screen

You can switch back to Basic at any time. Each admin screen will remember your preference even if you change the default.

### Turning on Emergency Stop

1. Open the Dashboard
2. Scroll to the **Quick Actions** card
3. At the bottom, find the **Disable All Agents** toggle
4. Click it
5. Confirm in the dialog that appears

When Emergency Stop is on, the entire Dashboard shows a red "Emergency stop is ACTIVE" banner. To restore:
1. Scroll to **Quick Actions**
2. Flip the same **Disable All Agents** toggle back off — there's no separate "restore" control
3. Answer any warnings

## FAQ

**Q: Why does the Dashboard say I have "abandoned" jobs?**

A: Agent Builder auto-detects jobs that crashed or stopped responding. When it finds one, it logs it and frees up the slot so other jobs can run. You don't need to do anything, but you can check the [Activity](https://agentic-plugin.com/audit-log/) log to see what happened. If jobs keep abandoning, check your agent code and logs.

**Q: Can I hide the Getting Started checklist?**

A: Yes. Open **Interface Settings** and toggle off the **Show getting started checklist** option (only visible once you've started onboarding). The checklist also disappears automatically once all steps are done.

**Q: What does "Unique IPs" mean in the Security card?**

A: That card isn't on the Dashboard — it's on the [Activity](https://agentic-plugin.com/audit-log/) > Security tab. It shows how many different IP addresses have triggered security events (blocks, rate limits, PII warnings) in the time period you selected.

**Q: Do I need to test my provider every day?**

A: No. The Dashboard test is just a quick manual check. Agent Builder tests your provider in the background whenever it uses it, so you'll only see failures if something is actually wrong. Use the manual test when you suspect a connection issue.

**Q: What's the difference between "Pending Approvals" and "Pending Jobs"?**

A: A **Pending Job** is an agent task waiting to start (e.g., processing a user request). A **Pending Approval** is a specific action an agent wants to run but is asking you first (e.g., modifying a post). A job can contain zero or more approval requests.

**Q: Can I rearrange the cards?**

A: Yes. Each card has a ⋮⋮ handle on the bottom-right. Drag it to move the card up or down. Your arrangement is saved per-user. Click **Reset layout** (top-right) to go back to the default arrangement.

**Q: Why does my Site Passport score keep changing?**

A: Your score updates based on your site's current configuration. When you enable MCP, add an llms.txt file, or update your robots.txt, those checks pass and your score goes up. If you disable something, it may go down. This is intentional — your score always reflects your current readiness.

**Q: What happens if I turn off Emergency Stop?**

A: Agent Builder asks for confirmation, then:
- Re-enables all agents (in their previous on/off state)
- Reconnects your AI providers
- Resumes processing any jobs in the queue
- Logs the event in Activity

Any jobs that failed during Emergency Stop stay failed, but new ones can run again.
