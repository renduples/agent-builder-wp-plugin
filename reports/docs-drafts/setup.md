# Setup Wizard

The Setup Wizard is how you connect Agent Builder to an AI provider — pick a provider, paste (or register) a key, choose a model and how much authority agents have, then test the connection. It is not listed in the Agent Builder sidebar.

> The wizard is hidden from the Agent Builder menu. Open it from the Dashboard’s **Setup Wizard** Quick Action (always listed), from Settings → Agents (**Use the setup Wizard**), or from Quick Start’s **Skip — I will pay for tokens with my own LLM Provider.** link. It has no Basic / Advanced switch of its own.

## Overview

Browser title: **Connect an AI Provider — Agent Builder**. The page heading is **Welcome to Agent Builder**, with: “Connect to your preferred AI Provider to get started. This takes only a few minutes.”

A four-step progress strip stays at the top:

1. **Choose provider**
2. **Get API key**
3. **Connect**
4. **Test**

You move with **← Back** and **Next →** in the card footer (they appear once you leave the first question). There is no Skip button on this screen.

Until at least one LLM provider is connected, opening any other Agent Builder admin page sends you to Quick Start (`agentic-signup`) instead. Saving a key in this wizard marks onboarding complete and turns on **WordPress Assistant** so you can try a chat at the end.

## Setup Wizard sections

### Do you already have an account?

The first card asks **Do you already have an account with an AI Provider?** and explains that at least one provider is needed for agents to respond.

**Popular AI Providers:** lists every built-in LLM (A–Z by name) with its badge:

| Provider | Badge |
| --- | --- |
| Agentic AI | Free |
| Anthropic (Claude) | Pay-as-you-go |
| Cohere | Free trial available |
| DeepSeek | Pay-as-you-go |
| Google (Gemini) | Free tier available |
| Kimi (Moonshot AI) | Pay-as-you-go |
| Meta Llama | Pay-as-you-go |
| Mistral AI | Pay-as-you-go |
| Ollama (Local) | Free · runs locally |
| OpenAI | Pay-as-you-go |
| xAI (Grok) | $25 free credits |

Two large buttons:

- **Yes, I have an account** — later, the first “create an account” step is hidden for that provider
- **No, I'll create one now** — the sign-up step stays visible

### Choose a provider

The heading and subtitle change with your previous answer:

- If you already have an account: **Which provider do you use?** / “Select the provider whose API key you already have.”
- If you don’t: **Choose a provider to sign up with** / “We recommend Agentic AI (Experimental) for getting started — for faster replies switch to a paid Provider”

Click a provider card to select it. A details panel opens with:

- Name and tagline
- **Pricing**, **Rate Limits**, **Best For**
- **Available Models** (the provider’s model list; the default model is marked **Recommended**)

**Next →** appears after you select a card. **← Back** returns to the account question.

Custom providers you added in Settings also appear in this grid (same A–Z list). They only get the extra how-to steps below if they match a built-in slug.

### Get the key and connect

Each provider has its own connect card: icon + name, a key (or URL / email) field, numbered how-to steps, and optional screenshots (click to enlarge; Escape or click the lightbox to close).

Providers that have a key URL (OpenAI, Anthropic, xAI, Google, Mistral, Meta Llama, Cohere, Kimi, DeepSeek) also show **Get Key**. That button opens the provider’s key (or sign-up) page in a new window and focuses the field here. Note under the button: “Opens new window so you can perform the steps above. Remember to return here and save the key — only shown once.” Agentic AI and Ollama have no **Get Key** button.

If you chose **Yes, I have an account**, the first step (sign-up) is hidden and the remaining steps are renumbered.

#### Most providers (OpenAI, Anthropic, xAI, Google, Mistral, Meta Llama, Cohere, Kimi, DeepSeek)

- Label: **Paste your API key here once you have followed the steps below.**
- Password field (placeholders such as `sk-…`, `xai-…`, `AIza…`, `sk-ant-…` where the wizard has one)
- After a few characters, **Save** appears
- After a successful save: **✓ Saved!** and **Continue to Testing →** (or **Next →** in the footer)

Some providers show a credit note under the steps, for example:

- OpenAI: “Note: you may need to add a payment method before the API will work.”
- Anthropic: “Note: you will need to add credits (minimum $5) before the API will work.”
- Kimi: “Note: Kimi K3 requires a minimum $1 top-up. China endpoint: api.moonshot.cn.”

Save errors show under the field (for example “Failed to save. Please try again.” or a network error).

#### Agentic AI

- **Email address** (`you@example.com`) instead of an API key
- Typing an email shows two required checkboxes: **I agree to the Terms of Service** and **I agree to the Privacy Policy** (both links open agentic-plugin.com)
- **Register & Connect** — creates the free Agentic key for this site
- If you skip the checkboxes: “Please agree to the Terms of Service and Privacy Policy.”

#### Ollama (Local)

- **Ollama Server URL** (default `http://localhost:11434`)
- Hint: “Leave as default unless you changed the port or are using a remote server.”
- **Connect to Ollama** — no API key; steps cover installing Ollama and `ollama pull llama3.2`
- No **Get Key** button

Saving a key (any provider) stores it, sets that provider as the site default, and activates WordPress Assistant.

### Choose a model, choose an agent mode, test

Progress step 4. Three blocks on one card:

#### Choose a model

“Each model has different strengths. You can change this any time in Settings.”

One card per model from that provider. The default is pre-selected and tagged **Recommended**. Click another card to change it. If the provider has no model list: “No models listed for this provider — you can select one in Settings after setup.”

#### Choose an agent mode

“Controls how much authority your AI agents have. You can change this any time in Settings.” Three cards (Supervised is selected by default):

| Label | What the card says |
| --- | --- |
| **Supervised** (badge: **Recommended**) | The AI proposes changes — you review and approve before anything is saved or published. Nothing happens without your sign-off. |
| **Autonomous** | The AI executes tasks immediately without asking for approval. Best for experienced users who trust the agent's judgment. |
| **Chat only** | Agents can answer questions and give advice, but cannot read data from or make any changes to your site. |

You can change this later on [Approvals](https://agentic-plugin.com/docs/approvals/) (Comfort profiles) or in Settings.

#### Test your connection

“Click below to verify your AI provider is responding correctly.”

- **Test Connection** — status: “Connecting to {provider}…”
- Success: “Connected! Saving your preferences…” then “Connected successfully!” (model + mode are saved at this point)
- Failure: the provider’s error, or “Connection failed. Check your API key.” The button becomes **Try Again**
- Network error: “Network error. Please try again.”

### After a successful test

A congratulations banner:

- **Your WordPress site just got much smarter.**
- **Go ahead, talk to WordPress!!**

Below it, a **WordPress Assistant** mini-chat (welcome message from that agent). Suggested prompts under the thread — click one to send it. Composer placeholder: **Ask me anything about your WordPress site…**. **Send** (Enter also sends). A microphone button dictates into the composer when the browser supports it (Chrome, Edge, or Safari; denying the mic may require HTTPS).

This chat talks to WordPress Assistant only. For the full [Agent Chat](https://agentic-plugin.com/docs/chat/) screen, leave the wizard.

**Exit Wizard** (secondary button under the chat) opens the [Dashboard](https://agentic-plugin.com/docs/dashboard/). Back / Next are hidden after a successful test.

## Common tasks

### Connecting a provider you already have a key for

1. Open the Setup Wizard (Dashboard → **Setup Wizard**, or Quick Start → **Skip — I will pay for tokens with my own LLM Provider.**)
2. Click **Yes, I have an account**
3. Select the provider
4. Click **Next →**
5. Paste the key (or set the Ollama URL) and click **Save** / **Connect to Ollama**
6. Click **Continue to Testing →**
7. Pick a model and an agent mode if you want something other than the defaults
8. Click **Test Connection**
9. Try the WordPress Assistant box, then **Exit Wizard**

### Creating a new provider account

1. Click **No, I'll create one now**
2. Select a provider (Agentic AI is the free built-in option)
3. Follow the numbered steps; use **Get Key** if you want the provider’s site in a new tab
4. Return here, paste the key, **Save**, then test as above

For Agentic AI, enter an email, tick both agreement boxes, and click **Register & Connect** instead of pasting a key.

### Re-running the wizard later

You can open it again any time from the Dashboard Quick Action or Settings → Agents. It does not remove providers you already connected; saving a key here makes that provider the site default.

To add extra providers without walking through the wizard, use [Settings → Providers](https://agentic-plugin.com/manage-llm-providers/).

### Running Ollama locally

1. Choose **Ollama (Local)**
2. Install Ollama and pull a model (`ollama pull llama3.2` as the step text shows)
3. Leave the URL as `http://localhost:11434` unless you changed the port
4. **Connect to Ollama**, then test

Ollama’s model list in the wizard is often empty (models live on your server). If you see “No models listed for this provider — you can select one in Settings after setup.”, pick the model later under Settings → Providers.

## FAQ

**Q: Why isn’t Setup in the left menu?**

A: It’s registered as a hidden admin page (`admin.php?page=agentic-setup`) so it doesn’t clutter the menu after onboarding. Use the Dashboard **Setup Wizard** button, the Settings → Agents link, or the Quick Start skip link.

**Q: What’s the difference between this and Quick Start?**

A: Quick Start (**Connect to Agentic AI**) registers the free Agentic provider in one form (email, site, security mode, interface mode, models). This wizard is the multi-provider path — your own OpenAI / Anthropic / xAI / … key, or Ollama, or Agentic via email. Quick Start’s skip link is how most people land here.

**Q: Is there a Skip control on the wizard?**

A: No. The footer is only **Back** / **Next**. Closing the tab without saving a key leaves onboarding unfinished; the next Agent Builder admin page will send you to Quick Start until a provider is connected.

**Q: I saved a key but the test failed.**

A: The key is already stored. Use **Try Again**, or check credits / billing on the provider (several wizard steps warn about this). You can also test from the [Dashboard](https://agentic-plugin.com/docs/dashboard/) **Connected Providers** card later.

**Q: Does choosing Chat only hide the Approvals page?**

A: No. **Chat only** sets agent mode to disabled: agents must not read or change the site. [Approvals](https://agentic-plugin.com/docs/approvals/) is still in the menu. You can switch to Supervised or a Comfort profile there (or in Settings) without re-running the wizard.

**Q: Can I change the model after setup?**

A: Yes — Settings → Providers (site default) or Settings → Agents (per-agent override). The wizard’s model picker is the initial default only.

**Q: Who can open the Setup Wizard?**

A: Administrators (`manage_options`). Everyone else is refused.

**Q: Does the mini-chat at the end replace Agent Chat?**

A: No. It’s a one-agent preview (WordPress Assistant) so you can see a reply before leaving. **Exit Wizard** goes to the Dashboard; open **Chat** from the menu for the full conversation screen.
