---
name: xlsx
description: "Use this skill any time a spreadsheet file is the primary input or output. Trigger when the user wants to open, read, edit, fix, or create an .xlsx, .xlsm, .csv, or .tsv file — including adding columns, applying formulas, formatting, cleaning messy data, or converting between tabular formats. Also trigger when the user references a spreadsheet file by name or path and wants something done to it. The deliverable must be a spreadsheet file or data extracted from one. Do NOT trigger when the primary deliverable is a Word document, HTML table, database query, or WooCommerce report page — only when a spreadsheet file is the actual output."
---

# Spreadsheet Skill

## Available Tools

Use these tools to work with spreadsheet files in the WordPress uploads directory.

| Tool | When to use |
|---|---|
| `read_spreadsheet` | Inspect any spreadsheet before working on it. Returns sheet names, row/column counts, and up to `max_rows` rows of data. |
| `analyze_spreadsheet` | Get a statistical summary: column names, inferred types, min/max/average, blank counts, unique values. Use when the user wants to understand or describe a file. |
| `create_spreadsheet` | Create a new `.xlsx` file from headers and data rows you supply. |
| `edit_spreadsheet` | Set individual cell values or formulas, append rows, or rename a sheet in an existing file. |
| `convert_spreadsheet` | Convert between xlsx, csv, and tsv. |

## File Paths

All file paths are **relative to the WordPress uploads directory** (e.g. `"2024/01/export.xlsx"` or `"siteloom/exports/report.xlsx"`).

When creating new files, use the `siteloom/exports/` subfolder with a descriptive filename.

## Workflows

### Reading / inspecting a file
1. Call `read_spreadsheet` with `max_rows: 20` for a quick preview.
2. If the user needs statistics or a structural summary, also call `analyze_spreadsheet`.
3. Report findings; ask if anything is ambiguous before proceeding.

### Creating a new spreadsheet
1. Prepare headers and data rows as arrays.
2. Call `create_spreadsheet` — pass `bold_header: true` and let auto-sizing handle column widths unless specific widths are requested.
3. Report the returned `url` so the user can download the file.

### Editing an existing spreadsheet
1. Call `read_spreadsheet` first to understand structure (note existing row/column layout).
2. Build a `changes` list of operations (`set_cell`, `append_row`, `rename_sheet`).
3. Call `edit_spreadsheet` with those changes.
4. Confirm success and report the file path.

### Converting formats
1. Call `convert_spreadsheet` with `output_format` set to the target.
2. Report the returned `url`.

## Quality Rules

- **Use Excel formulas, not pre-calculated values.** When creating or editing files that involve calculations, write actual formulas (e.g. `=SUM(B2:B10)`) rather than computing the result yourself and hardcoding it. This keeps the spreadsheet dynamic.
- **Preserve existing formatting.** When editing, do not alter styles, column widths, or structure beyond what was asked.
- **Zero formula errors.** Never leave a spreadsheet with `#REF!`, `#DIV/0!`, `#VALUE!`, `#N/A`, or `#NAME?` errors.
- **Professional fonts.** When creating new files, the default font is Arial or Calibri unless the user specifies otherwise.
- **Warn on large files.** Files over ~10 MB or 50 000 rows may be slow. Use `max_rows` to work with a sample first, and warn the user.
- **Headers in row 1.** Always use row 1 for column headers; data starts in row 2.
