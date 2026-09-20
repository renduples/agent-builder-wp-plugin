---
name: pdf
description: "Use this skill whenever the user wants to do anything with a PDF file. Trigger when the user wants to: read or extract text from a PDF; check a PDF's page count, title, or author; generate a new PDF from content (invoices, reports, letters, certificates, contracts); or combine multiple PDFs into one. Also trigger when the user mentions a .pdf file by name or path and wants something done with it. Do NOT trigger when the primary deliverable is a Word document, spreadsheet, or plain HTML page — only when a PDF file is explicitly needed."
allowed-tools: get_pdf_info read_pdf create_pdf merge_pdfs
---

# PDF Skill

**Availability:** These tools require the mPDF and pdfparser libraries, which are not bundled with the free WordPress.org release of this plugin (only spreadsheet tools ship there — see the plugin's FAQ). If any of the tools below return an "unavailable" error, tell the user PDF generation/reading isn't available on this install rather than retrying — a self-hosted install with the libraries present does include it.

## Available Tools

| Tool | When to use |
|---|---|
| `get_pdf_info` | First step before reading — returns page count, title, author, file size, and whether the PDF is text-based or scanned. |
| `read_pdf` | Extract text from a text-based PDF, page by page. Scanned PDFs will return little or no text. |
| `create_pdf` | Generate a new PDF from HTML content. Supports tables, lists, headings, inline CSS, and images by URL. |
| `merge_pdfs` | Combine two or more PDF files (from uploads) into a single output PDF. |

## File Paths

All file paths are **relative to the WordPress uploads directory** (e.g. `"2024/01/contract.pdf"`). Created files are saved to `agentic-exports/` (e.g. `"agentic-exports/invoice-123.pdf"`).

## Workflows

### Reading an existing PDF
1. Call `get_pdf_info` to check page count and confirm it is text-based (`likely_scanned: false`).
2. Call `read_pdf` — use `page_start`/`page_end` to read in chunks if the document is long.
3. If `likely_scanned: true`, tell the user that OCR is not available in the current environment and they will need to use an OCR service first.

### Generating a PDF
1. Write the HTML for the document. Use inline or `<style>` CSS — mPDF supports most standard HTML elements and CSS properties.
2. Call `create_pdf` with `html`, `filename`, and optional `css`, `orientation`, `format`, and `margin`.
3. Report the returned `url` so the user can download the file.

### Merging PDFs
1. Confirm the paths of all source PDFs with the user.
2. Call `merge_pdfs` with the ordered `files` array and an output `filename`.
3. Report the returned `url`.

## HTML Tips for create_pdf

### Recommended structure
```html
<style>
  body { font-family: Arial, sans-serif; font-size: 11pt; color: #333; }
  h1   { font-size: 18pt; color: #1a1a1a; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; }
  th    { background: #f0f0f0; padding: 6px 8px; text-align: left; border: 1px solid #ccc; }
  td    { padding: 6px 8px; border: 1px solid #ccc; }
  .total { font-weight: bold; text-align: right; }
</style>

<h1>Invoice #123</h1>
<p><strong>Bill To:</strong> Jane Smith<br>Date: 2026-04-28</p>

<table>
  <tr><th>Description</th><th>Qty</th><th>Price</th><th>Total</th></tr>
  <tr><td>Consulting</td><td>3</td><td>$200</td><td>$600</td></tr>
</table>
<p class="total">Total: $600.00</p>
```

### Page breaks
```html
<div style="page-break-after: always;"></div>
```

### Headers and footers (mPDF syntax)
```html
<htmlpageheader name="header">
  <div style="text-align:right; font-size:9pt; color:#888;">My Company</div>
</htmlpageheader>
<sethtmlpageheader name="header" page="ALL" />
```

## Quality Rules

- **Always call `get_pdf_info` before `read_pdf`** — confirm it's text-based and know the page count.
- **Warn the user if `likely_scanned: true`** — extractable text will be minimal or empty.
- **For generated PDFs:** use Arial or a common web-safe font. Avoid fonts that require installation.
- **Keep HTML clean** — avoid JavaScript, external stylesheets (linked CSS), and form elements.
- **Images:** reference by full URL (`https://...`), not file path. mPDF fetches them at render time.
- **Large PDFs:** `read_pdf` returns up to 50 pages at once. Use `page_start`/`page_end` to paginate.
