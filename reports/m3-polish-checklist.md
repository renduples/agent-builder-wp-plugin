# M3: UI/UX Polish Checklist

A screen "passes" M3 when every item below is true, checked against a real screenshot at desktop width (1440px, matching `bin/screenshot-admin.js`'s existing baseline convention) and at mobile width (~400px). Not every item applies to every screen — mark N/A with a one-line reason rather than skipping silently.

Established visual language to match (observed directly in this codebase across M1/M2 this session — reuse these, don't invent new ones):

- **Colors**: text `#1d2327`, muted/secondary text `#646970`/`#50575e`, WP admin blue `#2271b1` (current/active state), light-blue fill `#f0f6fc`, borders `#dcdcde`/`#c3c4c7`, error red `#d63638` on `#fcf0f1`, grey pill badges via `.agentic-badge-pill-grey`.
- **Shape**: 6-12px border-radius on cards/tiles/buttons, 1px solid borders in the palette above.
- **Existing shared components** (reuse, don't reinvent): `.agentic-react-panel` (card container), `.agentic-screen-mode-toggle` (Basic/Advanced two-button switch), `.agentic-secondary-nav` (the M1 nav rail), `InfoTip` (small `?` inline help), risk badges (`.agentic-react-risk--{tier}`).

## 1. Copy

- [ ] Every heading and label uses plain, direct language — no unexplained jargon. If a technical term is unavoidable (e.g. "risk tier", "MCP", "manifest integrity"), it has an `InfoTip` or inline explanation the first time it appears on a Basic-mode screen.
- [ ] Button labels describe the action, not the mechanism (e.g. "Enable tool", not "Submit" or "POST").
- [ ] No placeholder/lorem-ipsum text, no leftover developer-facing debug strings.
- [ ] Consistent terminology for the same concept across screens (e.g. "Agents" is never also called "Assistants" or "Bots" elsewhere).
- [ ] Error and empty-state messages tell the user what happened and what to do next, not just "Error" or "No data."

## 2. Hierarchy

- [ ] One clear primary heading per screen; secondary content is visually subordinate (smaller, muted, or indented).
- [ ] The single most important action on the screen is the most visually prominent (primary button styling), not competing with secondary actions.
- [ ] Related content is grouped (cards/sections), not a flat list of unrelated controls.

## 3. Spacing

- [ ] Consistent vertical rhythm between sections (compare against the established 16-24px section-gap pattern already used in Safety Center/Settings).
- [ ] No cramped text (line-height, padding) and no excessive dead space that makes the screen feel sparse or broken.
- [ ] Table/list rows have consistent padding; nothing visually clipped or overlapping.

## 4. Empty states

- [ ] Every list/table that can legitimately be empty (no agents, no tools matching a filter, no pending approvals, no logs yet) has a real empty-state message, not a blank area or a broken-looking table header with zero rows.
- [ ] Empty states suggest the next action where one exists ("Add an agent" link, not just "No agents").

## 5. Error states

- [ ] Failed API calls show a real, visible error notice (not a silent failure or a raw stack trace/JSON dump).
- [ ] Form validation errors are shown next to the relevant field, not just as a generic banner.
- [ ] A user can recover from an error state without a full page reload where reasonably possible (retry action, dismissible notice).

## 6. Accessibility

- [ ] Every interactive control (button, toggle, link) has an accessible name (visible text, `aria-label`, or both) — not an icon alone with no label/title.
- [ ] Color is never the only signal (e.g. risk badges already pair color with a text label — keep that pattern; don't add color-only indicators).
- [ ] Focus states are visible on interactive elements (the established `.agentic-*` components already have a focus box-shadow pattern — match it).
- [ ] Modals trap focus and are dismissible via Escape and a visible close control (Phase 4's HIGH-risk modal is a good reference implementation).

## 7. Mobile (~400px width)

- [ ] No horizontal scroll on the page body (tables/wide content scroll within their own container, not the whole page).
- [ ] Multi-column layouts (card grids, the nav rail, side-by-side panels) collapse to a single column or otherwise remain usable, not just shrunk illegibly.
- [ ] Touch targets (buttons, toggles) are large enough to tap reliably (not tiny icon-only controls with no padding).

---

## Process for each screen

1. Screenshot at 1440px and ~400px (extend `bin/screenshot-admin.js` if a screen isn't already covered — note Safety Center isn't in the current baseline set and needs adding).
2. Review the screenshot against every applicable item above; note failures with specifics (not "spacing is off" — "the gap between the risk-tier strip and the per-agent cards is inconsistent with the 16px gap used between other Safety Center sections").
3. Fix.
4. Re-screenshot both widths; confirm the specific issues are resolved and nothing else regressed.
5. Publish the before/after pair under `screenshots/` (matching the existing `screenshots/baseline/` convention — a natural home is `screenshots/m3-polish/<screen-slug>-before.png` / `-after.png`).
