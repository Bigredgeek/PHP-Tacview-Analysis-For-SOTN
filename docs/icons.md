# Aircraft thumbnails: sourcing, sizes, and tooling

This project uses per-aircraft thumbnails in `objectIcons/`.

## Sourcing guidelines
- **Primary source:** Wikipedia article infobox images (canonical, high-quality photos)
- Images are automatically fetched from Wikipedia using the pageimages API
- The curator maps aircraft names to their full Wikipedia article titles (e.g., "F-16C Fighting Falcon" → "General Dynamics F-16 Fighting Falcon")
- All images are properly licensed under Wikipedia/Commons terms (CC BY-SA, public domain, etc.)

## Technical specs
- Preferred aspect: 16:9 (landscape, automatically normalized)
- Target width: 640 px (height ≈ 360 px)
- Format: JPG preferred; PNG allowed if transparency is required
- Filenames: Based on aircraft name with spaces and slashes replaced by underscores, e.g. `F-16C_Fighting_Falcon.jpg`

## Workflow

### Automated (recommended)
1. Run the curator to fetch Wikipedia infobox images for all aircraft:
   ```powershell
   pwsh tools/auto_curate_icons.ps1 -Force
   ```
   This will:
   - Scan `debriefings/` for unique aircraft
   - Map each to its Wikipedia article title
   - Download the main infobox image
   - Update `data/aircraft_icons_manifest.json` with metadata

2. Normalize images (crop/resize to 16:9 @ 640px):
   ```bash
   php tools/normalize_icons.php --dir=objectIcons --width=640
   ```
   Requires either GD extension or ImageMagick CLI (`magick`)

3. Sync to public deployment:
   ```powershell
   Copy-Item objectIcons/*.jpg public/objectIcons/ -Force
   Copy-Item objectIcons/*.png public/objectIcons/ -Force
   ```

4. Verify in browser at `http://localhost:8000/debriefing.php`

### Manual curation (if needed)
1. Add or update entries in `data/aircraft_icons_manifest.json` with selected `fileUrl`, `license`, and `attribution`.
2. Download artifacts: `php tools/download_icons.php`
3. Normalize images: `php tools/normalize_icons.php`
4. Verify in the browser

## Pre-commit hook (optional)
You can install a git pre-commit hook to auto-normalize changed icons:

- Install: `pwsh tools/install-git-hooks.ps1`
- The hook will normalize staged images under `objectIcons/` and re-stage them.

## Wikipedia article title mappings
The curator includes mappings for DCS aircraft names to Wikipedia articles:
- A-10A/C Thunderbolt II → Fairchild Republic A-10 Thunderbolt II
- F-16C Fighting Falcon → General Dynamics F-16 Fighting Falcon
- MiG-29 Fulcrum → Mikoyan MiG-29
- Su-25 Frogfoot → Sukhoi Su-25
- (see `tools/auto_curate_icons.ps1` for full list)
