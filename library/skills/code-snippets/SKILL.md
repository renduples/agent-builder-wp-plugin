---
name: code-snippets
description: "Use when user wants to add custom CSS or JavaScript to the site, list active code snippets, or inject code without modifying theme files."
---

# Code Snippets Skill

## Available Tools

| Tool | When to use |
|------|-------------|
| `add_custom_css` | Add a CSS snippet that will be output on all pages or frontend only. |
| `add_custom_js` | Add a JavaScript snippet to the page head or footer. |
| `list_code_snippets` | List all CSS and JS snippets currently managed by Siteloom. |

## Workflows

### Add custom styling

1. Ask the user for the CSS they want to add and a descriptive label.
2. Ask whether it should be global (all pages including admin) or frontend only.
3. Call `add_custom_css` with the provided CSS, label, and location.
4. Confirm the snippet was added and show the preview.

### Add a tracking or analytics script

1. Ask for the script code and confirm it should go in the `footer` (recommended) or `head`.
2. Call `add_custom_js` with the JS, a label like "Google Analytics", and `location: footer`.
3. Confirm the snippet ID and note that it will be active on the next page load.

### Review all active snippets

1. Call `list_code_snippets` to retrieve all CSS and JS snippets.
2. Present them with their label, type, location, size, and creation date.
3. If the user wants to remove a snippet, note they'll need to do so via the Siteloom settings panel (no delete tool yet).

## Rules

- Always ask the user to review injected JavaScript before adding it — malicious or broken JS can break the frontend for all visitors.
- CSS snippets with `location: global` are also registered with the WordPress customizer via `wp_update_custom_css_post`, which may overwrite existing customizer CSS if it has been manually edited there.
- JS snippets are output as raw inline `<script>` tags — they are not defer/async by default. For performance-sensitive scripts, recommend the user use the footer location.
- There is currently no delete tool — to remove a snippet, the user must go to Siteloom settings or use the `update_option` tool to modify `agentic_custom_css` or `agentic_custom_js` directly.
