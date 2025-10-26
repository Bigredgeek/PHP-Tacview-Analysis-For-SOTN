# Aircraft thumbnails: sourcing, sizes, and tooling

This project uses per-aircraft thumbnails in `objectIcons/`.

## Sourcing guidelines
- Prefer Wikimedia Commons images with clear licensing (CC BY or CC BY-SA).
- Use a clean side-on or 3/4 view with minimal background.
- Target landscape crop; avoid busy/low-contrast backgrounds.
- Record license and attribution in `data/aircraft_icons_manifest.json`.

## Technical specs
- Preferred aspect: 16:9 (landscape)
- Target width: 640 px (height ≈ 360 px)
- Format: JPG preferred; PNG allowed if transparency is required
- Filenames: Based on aircraft name with spaces and slashes replaced by underscores, e.g. `F-16C_Fighting_Falcon.jpg`

## Workflow
1. Add or update entries in `data/aircraft_icons_manifest.json` with selected `fileUrl`, `license`, and `attribution`.
2. Download artifacts (optional): `php tools/download_icons.php`
3. Normalize images (crop/resize): `php tools/normalize_icons.php`
4. Verify in the browser at `/debriefing.php`

## Pre-commit hook (optional)
You can install a git pre-commit hook to auto-normalize changed icons:

- Install: `pwsh tools/install-git-hooks.ps1`
- The hook will normalize staged images under `objectIcons/` and re-stage them.
