---
name: docx
description: "Use this skill whenever a Word document (.docx) is the primary input or output. Trigger when the user wants to: read or extract text from an uploaded .docx file; create a new Word document from content (reports, contracts, letters, proposals, meeting notes); export a WordPress post or page as a Word document; or when the user mentions a .docx file and wants something done with it. Do NOT trigger when the primary deliverable is a PDF, spreadsheet, or HTML page — only when a .docx file is explicitly needed."
---

# Word Document (docx) Skill

**Availability:** These tools require the PhpWord library, which is not bundled with the free WordPress.org release of this plugin (only spreadsheet tools ship there — see the plugin's FAQ). If `read_docx`, `create_docx`, or `html_to_docx` returns an "unavailable" error, tell the user Word document generation isn't available on this install rather than retrying — a self-hosted install with the library present does include it.

## Available Tools

| Tool | When to use |
|---|---|
| `read_docx` | Read and extract text, headings, and tables from an uploaded .docx file. |
| `create_docx` | Create a new .docx from structured content blocks (headings, paragraphs, lists, tables). Best for documents you build from scratch. |
| `html_to_docx` | Convert an HTML string to .docx. Best for exporting WordPress post content or any existing HTML. |

## File Paths

All paths are **relative to the WordPress uploads directory** (e.g. `"2024/01/contract.docx"`). Created files are saved to `agentic-exports/` (e.g. `"agentic-exports/report.docx"`).

## Workflows

### Reading an uploaded document
1. Call `read_docx` with the file path.
2. Review the returned `headings` for structure and `text` for content.
3. Use `tables` if the document contains data tables.

### Creating a document from scratch
1. Plan the content structure as sections (heading1, heading2, paragraph, table, etc.).
2. Call `create_docx` with the `filename`, optional `title`/`author`, and the `sections` array.
3. Report the returned `url`.

### Exporting a WordPress post as a Word document
1. Use `db_get_post` or a similar tool to retrieve the post content.
2. Pass the `post_content` (which is HTML) to `html_to_docx` with a descriptive `filename`.
3. Report the returned `url`.

## Section Types for create_docx

```json
[
  { "type": "heading1", "text": "Annual Report 2026" },
  { "type": "heading2", "text": "Executive Summary" },
  { "type": "paragraph", "text": "This report covers..." },
  { "type": "paragraph", "text": "Key finding highlighted here.", "bold": true },
  { "type": "bullet_list", "items": ["Point one", "Point two", "Point three"] },
  { "type": "numbered_list", "items": ["First step", "Second step"] },
  { "type": "table",
    "headers": ["Month", "Revenue", "Expenses"],
    "rows": [
      ["January", "$10,000", "$6,000"],
      ["February", "$12,000", "$7,000"]
    ]
  },
  { "type": "page_break" },
  { "type": "heading2", "text": "Appendix" }
]
```

## HTML Tips for html_to_docx

PhpWord's HTML parser supports:
- Headings: `<h1>` – `<h6>`
- Text: `<p>`, `<strong>`, `<em>`, `<u>`, `<a>`
- Lists: `<ul>`, `<ol>`, `<li>`
- Tables: `<table>`, `<tr>`, `<th>`, `<td>`
- Line breaks: `<br>`

**Avoid:** `<div>` with complex CSS, `<img>` (limited support), JavaScript, embedded styles beyond basic font formatting.

For WordPress post content, strip shortcodes and embedded blocks first if they produce noisy output.

## Quality Rules

- **Document titles matter** — always set `title` in metadata so the file is identifiable in the user's downloads.
- **Use `heading1` for document title, `heading2` for major sections** — this produces a proper heading hierarchy in Word.
- **Tables need consistent column counts** — ensure every row has the same number of cells as the headers.
- **For contracts or formal documents** — use `paragraph` blocks with `bold: true` for important clauses, and add a signature section as a table with blank rows.
- **html_to_docx is best for WordPress content** — post HTML maps naturally to Word elements. `create_docx` is better for structured data or templates.
