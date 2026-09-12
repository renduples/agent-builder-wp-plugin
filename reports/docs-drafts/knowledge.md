# Knowledge

The Knowledge screen is where you teach your agents what is true on this site — facts, policies, FAQs, and other shared source material. The free Knowledge Wiki stores that as local markdown (Google's Open Knowledge Format) on your server. Per-agent tone and greetings live here too, on Instructions; optional notes that persist between chats live on Memory.

> Basic mode is a short landing that opens the guided Add Knowledge flow. The wiki editor, Instructions, Memory, and Vector Store tabs live in Advanced mode on this same page.

## Overview

Open **Agent Builder → Knowledge** in wp-admin.

**Basic mode** shows:
- The **Knowledge** heading
- A **Basic** / **Advanced** toggle (this page only; it is independent of the site-wide switch in the admin header)
- A card: **What do you want your agents to know?** with **Add knowledge**

**Advanced mode** shows a hero (**Teach your agents what is true**), an **OKF v0.2** pill, a **Docs →** link, the same Basic / Advanced toggle, and four sections:
- **Wiki** — the site's shared knowledge library
- **Instructions** — how one agent talks
- **Memory** — short notes an agent keeps between chats
- **Vector Store · Pro** — hosted semantic search (upgrade surface on this WordPress.org build)

The wiki is on your site. Hosted vector features require Agent Builder Pro; curated markdown knowledge is not locked behind a paywall.

## Knowledge sections

### Basic mode

The card explains that you can paste text, upload a file, or pick pages that already exist on this site. **Add knowledge** opens the **Add Knowledge** wizard (a separate guided page — see below). There is no wiki editor, no section list, and no Instructions / Memory / Vector Store in Basic mode.

Switch **Advanced** (the page reloads) to manage the wiki yourself.

### Advanced mode — hero

- Eyebrow: **Knowledge**
- Heading: **Teach your agents what is true**
- Lede: the free wiki uses Google's Open Knowledge Format (OKF) — plain markdown concepts you can version, search, and maintain like a document library, entirely on your server
- **OKF v0.2**
- **Docs →** — opens the public Knowledge Wiki / OKF docs in a new tab

### Wiki

Left nav: **Wiki**, **Instructions**, **Memory**, **Vector Store · Pro**. Hover a section for a one-line explanation:

| Section | What it's for |
| --- | --- |
| **Wiki** | Facts, policies, and FAQs every agent can look up — the site's shared source of truth. |
| **Instructions** | How one specific agent talks: its tone, greeting, and persona. Not shared facts — that's the Wiki. |
| **Memory** | Short notes an agent keeps between chats, e.g. things a user told it. Optional and site-owned. |
| **Vector Store** | Hosted semantic search over large documents (PDFs, long pages) for faster, more precise recall. |

#### Toolbar

- **Wiki** scope dropdown — **Site-wide wiki**, plus **Agent: {name}** for each installed agent
- **Search concepts…** — filters the list by title, ID, summary, or type
- **Guided setup** — opens the Add Knowledge wizard
- **Export** — downloads a markdown bundle of the current wiki (`okf-{scope}-{date}.md`). Status: **Export downloaded.**
- **Import persona text** — copies that agent's bundled persona knowledge file into its agent wiki. Select an agent wiki first (not Site-wide); otherwise: **Select an agent wiki first, then import that agent's persona knowledge text.** Success: **Imported into the agent knowledge wiki.**
- **New concept** — opens a blank editor

#### Concept list

The list shows a **{n} concepts** count. Each row: type (and **Example** / **Always on** when those flags are set), title, and summary.

Empty: **No concepts yet. Create a FAQ, policy, or product fact to get started.**  
No search matches: **No concepts match your search. Try a different keyword or clear the search.**

A first-time Advanced visit seeds demo example concepts (brand voice, hours, SEO notes, and similar). They stay marked **Example** until you uncheck that flag.

With nothing selected, the right pane is **Your knowledge wiki** / **Add facts, policies, and FAQs your agents can use.** The **Add knowledge** button here opens a blank concept in the editor — it does **not** open the wizard. **Guided setup** in the toolbar is the wizard.

#### Concept editor

Click a row (or **New concept**) to edit:

- **Title** (required; placeholder `Returns policy`) — leaving it empty and saving shows **Title is required.**
- **ID** — short name (e.g. `returns-policy`). Filled from the title on new concepts if you leave it blank; it becomes read-only after the first save
- **Type** — FAQ (default), Policy, Playbook, Brand voice, Product, Service, Pricing, Shipping, Returns, Hours & location, Contact, Team, Page brief, Post outline, SEO notes, Schema notes, Menu & navigation, Form, WooCommerce, Membership, Support reply, Escalation, Onboarding, Runbook, Incident, Security, Plugin notes, Theme notes, Integrations, API, Metric, Glossary, Reference, or **Custom…** (then type your own)
- **Keywords** — optional, comma-separated (not WordPress post tags)
- **Status** — **Draft**, **Stable** (default), **Deprecated**
- **Review by** — optional date; after it, the concept is marked as needing a refresh
- **Example** — **Demo only — agents cannot use this until you uncheck Example and adapt it for your site.** Opening an example shows **Demo example — agents cannot use this until you uncheck Example.**
- **Always include in prompts** — inject this concept into every agent's system prompt (best for site overview, tone, standing policies; keep short). Ignored while **Example** is checked
- **Summary** — one sentence for the list and search
- **Content** — what agents should know; write plainly, Markdown is optional
- **Related link (optional)** — a page or doc this is about; the content above is still the main knowledge

**Save concept** writes the file (status **Saved.**). **Delete** confirms **Delete this concept? This cannot be undone.** then **Deleted.**

### Instructions

How one agent talks — not shared facts (those belong in the Wiki).

- **Select agent**
- **Welcome message** — first bubble in [Agent Chat](https://agentic-plugin.com/docs/chat/)
- **Persona notes** — appended to the system prompt; they do not replace it
- **Response style** — a free-text note (not a preset dropdown)
- **Save changes** — success: **Settings saved.**

Empty: **No agents installed.**

### Memory

Optional, site-owned notes agents can keep across conversations.

- **Default memory TTL (days)** — how long a note lasts; **0 = never expires** (default is 30)
- **Enable local browser memory** — off by default
- **Save changes**

Turn this off if you prefer agents not to retain short notes across chats.

### Vector Store · Pro

On this WordPress.org build the Vector tab is an upgrade card only (the hosted admin UI ships in Agent Builder Pro):

- **Vector Store & RAG** — train on large document sets and whole-site content with hosted embeddings
- Scan posts & pages into a private vector namespace
- Upload PDF / TXT / MD with automatic chunking
- Automatic retrieval into agent context (with citations)
- WP-CLI: `wp agent rag status|train|upload|search`

The free wiki stays fully available without Pro. **See Pro plans** and **How Vector Store works** open agentic-plugin.com.

### Add Knowledge wizard (from **Add knowledge** or **Guided setup**)

Separate page titled **Add Knowledge**. Heading copy: teach your agents a fact, policy, or FAQ in a few quick steps; you can add more or fine-tune it later from the Knowledge page.

Two steps:

**1. Source** — **Where is this knowledge coming from?**
- **Paste text** — **Paste the text** (a fact, policy, FAQ answer)
- **Upload a file** — plain text or Markdown (`.txt` or `.md`). Other types: **Please choose a plain text or Markdown file (.txt or .md).**
- **Pick existing pages** — **Search your pages and posts** (leave blank for recent published posts/pages). Select one or more, then **Continue**

**2. Title & tags**
- **Title** (required) — e.g. "Return policy" or "Support hours"
- **Tags (optional)** — comma-separated

**Save knowledge** writes a site-wide wiki concept (same format as the editor). Success: **"{title}" is saved!** with **Add another**, **View Knowledge Wiki**, or **Back to Dashboard**.

Wizard concepts are not marked Example. Long pasted or page content is truncated to keep a single concept a usable size.

## Common tasks

### Adding a fact or policy (Basic)

1. Open **Knowledge**
2. Click **Add knowledge**
3. Choose **Paste text**, **Upload a file**, or **Pick existing pages**
4. Click **Continue**, give it a **Title**, and **Save knowledge**
5. Use **View Knowledge Wiki** if you want to fine-tune it (switch this screen to Advanced if you land back on the Basic card)

### Creating or editing a wiki concept (Advanced)

1. Open **Knowledge** and set the toggle to **Advanced**
2. Stay on **Wiki**
3. Pick **Site-wide wiki** or an agent wiki
4. Click **New concept** (or a row to edit)
5. Fill **Title** and **Content**; set **Type**, **Keywords**, and **Always include in prompts** if you need them
6. Uncheck **Example** on any seeded demo you actually want agents to use
7. **Save concept**

### Changing how one agent greets people

1. Open **Knowledge** in Advanced
2. Open **Instructions**
3. **Select agent**
4. Edit **Welcome message**, **Persona notes**, and **Response style**
5. **Save changes**

The welcome is what [Agent Chat](https://agentic-plugin.com/docs/chat/) shows first. Site-wide facts still belong in the Wiki.

### Exporting the wiki

1. Open **Wiki** in Advanced
2. Choose the scope (site-wide or one agent)
3. Click **Export**
4. A markdown file downloads

### Turning off notes between chats

1. Open **Memory** in Advanced
2. Clear **Enable local browser memory** if you don't want browser-side notes
3. Set **Default memory TTL (days)** if server-side notes should expire
4. **Save changes**

## FAQ

**Q: Where did the wiki editor go?**

A: It's in Advanced mode. Click **Advanced** next to the Basic / Advanced pair on this page (not the site-wide switch in the header). Basic mode only offers **Add knowledge**.

**Q: What's the difference between Wiki, Instructions, and Memory?**

A: **Wiki** is shared facts every agent can look up. **Instructions** is one agent's greeting and persona. **Memory** is optional notes kept between chats. Hover each section in the left nav for the one-line version.

**Q: Why can't agents use the sample returns policy?**

A: Seeded demos are marked **Example**. Uncheck **Example**, adapt the content for your site, and **Save concept**. Until then they are demo-only.

**Q: Add knowledge in the empty wiki didn't open the wizard.**

A: In Advanced, the empty-state **Add knowledge** button starts a blank concept in the editor. The wizard is **Guided setup** in the toolbar (or **Add knowledge** from Basic mode).

**Q: Import persona text does nothing.**

A: Switch the Wiki dropdown from **Site-wide wiki** to **Agent: {name}** first. The command reads that agent's bundled persona knowledge file; there isn't one for the site-wide wiki.

**Q: Do I need Pro to use Knowledge?**

A: No. The wiki, Instructions, and Memory are included. **Vector Store · Pro** is hosted embeddings and RAG over large document sets — an upgrade card on this build. Curated markdown knowledge is not paywalled.

**Q: What's the difference between this page's Basic / Advanced and the site-wide switch?**

A: This page's toggle changes only Knowledge (landing vs wiki) and is remembered per user. The site-wide switch in the admin header is the default for every Agent Builder screen.

**Q: Who can open this page?**

A: Administrators, and anyone granted permission to manage plugin settings. Everyone else gets "You do not have permission to access this page."
