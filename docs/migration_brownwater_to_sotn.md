# Migration Plan: Brownwater → SOTN

This document lists the Brownwater improvements and what needs to be ported (or explicitly skipped) into SOTN. Each task includes acceptance criteria and validation steps so we can proceed one step at a time.

## Legend
- Required: Must be implemented in SOTN.
- Optional: Nice-to-have or situational.
- N/A: Brownwater-specific; do not port.

---

## 1) Deployment hardening for Vercel
Status in SOTN: Partial (rewrites exist). Required.

Changes to apply:
- Add a `.vercelignore` file to prevent accidental upload of local-only PHP entry points (if they appear later):
  - `public/debriefing.php`
  - `public/api/debriefing.php`
- Ensure `/debriefing.php` always resolves to the serverless handler:
  - Keep the existing rewrite in `vercel.json`:
    - `{"source": "/debriefing.php", "destination": "/api/debriefing"}`
  - Optional hardening: add a temporary redirect for `/debriefing.php` to `/api/debriefing` (mirrors Brownwater) to avoid browsers downloading a static PHP file if it ever appears.

Acceptance criteria:
- New deploy does not prompt a download when visiting `/debriefing.php`.
- Homepage redirect (`/` → `/debriefing.php`) still lands on the rendered HTML output.

Validation steps:
- Deploy preview on Vercel.
- Open `/` and `/debriefing.php` and confirm rendered HTML.

---

## 2) Public bundle changelog
Status in SOTN: Not present. Optional.

Changes to apply:
- Add `public/CHANGELOG.md` summarizing key user-facing updates. This helps downstream users reviewing the deployment bundle.

Acceptance criteria:
- `public/CHANGELOG.md` exists with a brief Unreleased section.

Validation steps:
- Verify file is in the deployed `public/` output.

---

## 3) Sticky header semantics and CSS
Status in SOTN: Already implemented (CSS sticky + `<thead>/<tbody>` per changelog). N/A for code; Required for verification.

Changes to apply:
- Code changes expected. Verify that:
  - Statistics table output uses `<thead>` and `<tbody>`.
  - CSS sets `border-collapse: separate` and allows `position: sticky` to work.
  - No JavaScript row cloning is active.
  - Open a local server to test before deployment

Acceptance criteria:
- Header remains visually consistent and sticky across scroll in SOTN theme.

Validation steps:
- Local run with a real Tacview XML, scroll the Aircrew Performance Summary table, observe sticky header.

---

## 4) API debriefing status messages and path handling
Status in SOTN: Implemented similarly to Brownwater. N/A (confirm only).

Changes to apply:
- Confirm `api/debriefing.php`:
  - Emits bottom status block with file discovery results and processed file names.
  - Computes the debriefings folder path relative to `/api/` (works with `public/config.php`).

Acceptance criteria:
- When no XML is present, SOTN shows the friendly status list and the conversion note.
- When XML files exist, SOTN renders outputs for each and still includes the status block.

Validation steps:
- Local run with and without an XML present.

---

## 5) Brownwater-specific features
Status in SOTN: Not applicable. N/A.

Items to skip or adapt:
- Neon dystopia visual theme and mission-info color scheme changes. SOTN uses the Cold War Command Center theme; retain consistency.

Acceptance criteria:
- SOTN theme and content remain canon to its setting.

---

## 6) Language file parity
Status in SOTN: Present in both `/languages` and `/public/languages` (10 total). Required (verification only).

Changes to apply:
- Keep files in sync when making language updates.

Acceptance criteria:
- 10 language files exist in both directories and match.

Validation steps:
- Spot-check 2–3 language files across both locations.

---

## 7) Test and validation pass
Status in SOTN: To run. Required.

Changes to apply:
- After config changes, run local server with a real Tacview XML.
- Verify no PHP warnings/errors and sticky header behavior.

Acceptance criteria:
- No PHP errors in the terminal.
- Page renders correctly with SOTN theme.

Validation steps (local):
```pwsh
cd php-tacview-sotn
php -S localhost:8001 -t public
# Open http://localhost:8001
```

---

## Checklist (execution order)
1) Add `.vercelignore` (Required)
2) Update `vercel.json` with optional redirect (Optional)
3) Add `public/CHANGELOG.md` (Optional)
4) Verify sticky header semantics and CSS (Required – verification)
5) Confirm API status messaging and path resolution (Required – verification)
6) Verify language file parity (Required – verification)
7) Run local validation with a real Tacview XML (Required)

## Notes
- Keep SOTN’s theme/styling distinct from Brownwater.
- Do not port Brownwater-only mod logic unless a SOTN-specific need emerges.
- Always update `CHANGELOG.md` as edits are made.
