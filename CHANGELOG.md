# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed - 2025-11-05
#### Copilot Instructions Enhancement
- Enhanced `.github/copilot-instructions.md` with YAML frontmatter for better scope definition
- Added comprehensive validation checklist for code changes
- Added detailed testing instructions with bash commands
- Added common task guidelines for adding features, fixing bugs, performance improvements, and internationalization
- Added "Issue Requirements & Best Practices" section to guide issue-based development
- Added helpful links section with references to GitHub Copilot best practices, changelog format, PSR-12 standards, and Tacview documentation
- Improved structure to follow GitHub Copilot coding agent best practices
- Enhanced instructions now provide clearer guidance for surgical, minimal changes
- All enhancements align with recommendations from https://docs.github.com/en/copilot/tutorials/coding-agent/get-the-best-results

### Added - 2025-11-05
#### GitHub Configuration Documentation
- Created `.github/README.md` to document the Copilot instructions setup and configuration
- Added overview of what's configured, developer guidance, and maintenance notes
- Provided links to relevant GitHub Copilot documentation for future reference

#### Performance Investigation
- Conducted comprehensive performance analysis for mobile and weaker PC issues reported by users
- Created detailed performance analysis document (`planning/performance-analysis-2025-11-05.md`) with findings and recommendations
- Identified root causes:
  - EventGraphAggregator processing ~1.3s per page load (parsing 4 XML files)
  - Large HTML payload of 1.6MB (mission events with inline tooltips)
  - CSS animation overhead from Cold War Command Center theme
  - Large DOM with thousands of table rows
- Proposed four solution approaches:
  - **Solution A (Recommended):** Build-time pre-processing of debriefings (98%+ performance improvement)
  - **Solution B:** Client-side lazy loading with AJAX
  - **Solution C:** Payload optimization and caching (quick wins)
  - **Solution D:** Hybrid approach combining A and C (best overall)
- Recommended immediate quick wins: HTTP compression, CSS performance optimizations, lazy image loading
- Documented implementation plan with phases, success criteria, and testing approach

#### Build-Time Pre-Processing Implementation (Solution D - Hybrid)
- Created `scripts/preprocess-debriefings.php` to process Tacview XML files at build time instead of runtime
- Implemented `debriefing-optimized.php` that loads pre-processed data with automatic fallback to runtime processing
- Updated `package.json` build script to include pre-processing step
- Added comprehensive documentation in `docs/build-time-preprocessing.md`
- Performance improvements achieved:
  - **Page load time:** 1.3s → 46ms (97% reduction) when using pre-processed data
  - **Server CPU load:** Eliminated per-request XML parsing and aggregation
  - **Scalability:** Same performance regardless of concurrent user count
  - **Mobile experience:** Near-instant load times
- Pre-processor generates:
  - `public/debriefings/aggregated.html` - Static HTML output (~1.5MB)
  - `public/debriefings/aggregated.json` - Metadata with file hashes, timestamps, and metrics
- Optimized page intelligently selects fast path (pre-processed) or fallback (runtime)
- Debug mode shows performance metrics and data source information
- Added build artifacts to `.gitignore` (regenerated during build/deploy)

### Changed - 2025-11-05
#### Quick-Win Performance Optimizations
- Added `.htaccess` with gzip/brotli compression configuration
  - Expected payload reduction: 1.6MB → ~200KB (87.5%)
  - Configured cache headers for static assets (1 year) and HTML (1 hour)
  - Added security headers: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection
- Updated `vercel.json` with compression and cache control headers for Vercel deployments
- Enhanced `public/tacview.css` with performance optimizations:
  - Added `@media (prefers-reduced-motion)` to disable animations for accessibility
  - Disabled heavy animations on mobile devices (≤768px width) for better battery life
  - Added CSS containment (`contain: layout style`) to statistics tables for improved rendering
  - Maintained Cold War aesthetic while respecting user performance preferences
- Updated `.gitignore` to exclude build-time generated files

### Fixed - 2025-11-03
- Removed duplicate Franz 1-2 sorties by pruning takeoff/landing pairs under two minutes with matching airfields and no intervening events during Tacview event normalization, ensuring the mission timeline mirrors the deduplicated core renderer.
- Rebased aggregated event mission clocks to start at the consensus mission time so timeline rows follow the master time sync instead of the earliest outlier recording, fixing 09:52Z entries under an 11:14Z Mission Information header.
- Restored proper spacing and bracket closures around flight group names in mission events so strings render as `[Axeman 3] has entered the area` instead of `Axeman 3has entered the area`.
- Replaced the debug-heavy inline `showDetails` stub with the streamlined core helper to eliminate the `Unexpected end of input` console error on the API output.

### Changed - 2025-11-03
- Renamed the pilot statistics "Targets Destroyed" column to "Airframes Lost" to match the dataset now tracking sorties lost instead of kills.
- Updated the Russian and Ukrainian language packs so the new "Airframes Lost" label appears correctly in every localized pilot statistics view.
- Hid the aggregator status overlay on the root/API/public debriefing routes by default, keeping ingest failures visible while exposing the full metrics panel only when `show_status_overlay` is enabled or `?debug=1` is supplied.

### Added - 2025-11-02
- Introduced build-time core fetchers (`scripts/fetch-core.js` for CI and `scripts/fetch-core.php` for local CLI) so deployments automatically clone the `php-tacview-core` bundle when the shared assets are absent.
- Flagged orphan timeline events by tagging `HasFired` rows with no matching hits inside a type-aware time-of-flight window and `HasBeenDestroyed` kills without a preceding launch, surfacing potential recording gaps without deleting legitimate misses.
- Surfaced coalition voting tallies and per-source reliability in the aggregator output so mixed-side evidence (e.g., two blue vs. one red Tacview) is easy to spot in downstream tooling.
- Weighted each recording by coverage and event volume, feeding those reliabilities into the merge logic and the new confidence calculator.
- Replaced the linear confidence formula with a tiered model that distinguishes rich (Tier A) weapon attributions from partial (Tier B) and inferred (Tier C) evidence, reaching 100% when two high-detail recordings agree and scaling down through 88%, 75%, 70%, and 62% according to the user-defined thresholds.
- Extended the pilot statistics grid with a disconnect counter and detail drilldown so sortie summaries highlight mid-mission client drops alongside takeoffs and landings.
- Synced the embedded core submodule so every language pack exposes `disconnects`, `confidence`, and `sources` labels for the new mission timeline and pilot statistics columns.

### Changed - 2025-11-02
- Rebased mission start time on the earliest consensus MissionTime pulled from the Tacview headers (within a 30-minute window) so timezone-skewed recordings like the 08:05 export no longer drag the site clock and duration several hours early; exposed the new `mission_time_congruence_tolerance` option across root/API/public configs.
- Expanded the EventGraph merge window for `HasBeenHitBy`/`HasBeenDestroyed` actions to 4–5 seconds so multi-source recordings collapse into a single damage/killed row instead of duplicating Tincan-style double hits when timestamps drift by a couple seconds.
- Hardened the offset diagnostic helper to pull every Tacview XML (including the SOTN GT2 flight log) straight from the debriefings directory, so source comparisons stay in sync without manual lists.
- Trimmed mission timeline source badges to display numeric counts only while keeping full per-recording tooltips for analysts who need the detail on hover.
- Styled the mission timeline confidence and evidence columns with centered layouts and pill badges so the numeric-only indicators remain readable in the retro UI theme.
- Removed the embedded `core/` submodule so the workspace only tracks the standalone `php-tacview-core` repository next to SOTN.
- Reworked pilot stats so `Targets Hit` now reflects strikes the pilot delivered, paired with a new `Times Hit` column that captures incoming enemy hits.

### Fixed - 2025-11-02
- Resolved remote deployments that lacked `/core` by probing for the shared Tacview engine in sibling `php-tacview-core` directories or an environment override before bootstrapping the debriefing endpoints, eliminating the Vercel fatal error.
- Updated `test.php` to exercise the runtime aggregator workflow across every XML in `debriefings/`, matching deployment behaviour and catching core-path issues during local validation.
- Recreated `src/EventGraph/EventGraphAggregator.php` from scratch so the event merge, coalition validation, orphan tagging, and disconnect pruning pipeline runs without the previously corrupted file.
- Let the EventGraph anchor chooser pick the highest-evidence cluster even when it sits beyond the primary tolerance window, so recordings like `Tacview-20251025-144445` realign at the ~4,900 second offset instead of the spurious 12-second match that was reintroducing duplicate Menton 2-1 kill rows.
- Pruned HasFired timeline rows whose coalition and icon attribution contradict higher-evidence events, removing single-source misfires like Nitro 2-1 inheriting `weapons.shells.2A7` from the opposing ground unit while retaining multi-source SHORAD volleys.
- Extended the coalition mismatch guard so any non-hit, non-destruction event with conflicting factional icons (primary, secondary, or parent objects) is discarded, preventing cross-coalition takeoff/kill artefacts while leaving `HasBeenDestroyed`/kill events intact.
- Ignored mid-air disconnect destruction rows during stats aggregation while still recording them as disconnect events, eliminating false aircraft loss tallies for clients that simply dropped connection.
- Reset the tacview renderer state and normalized aggregated event lists before rendering so `proceedAggregatedStats()` once again yields pilot statistics and the mission event log.
- Restored EventGraph confidence percentages and source badges in the mission timeline (including the pilot detail panes) with tier-aware tooltips sourced from aggregated evidence.
- Treated `HasBeenDestroyed`/`HasBeenHitBy` duplicates that only differ by a missing attacker as the same engagement, collapsing Nomad-style pairs where one recording lacks the secondary object data.
- Formatted per-pilot disconnect annotations with the mission start offset so the labels mirror the corrected event timeline instead of raw recording clocks.

### Added - 2025-11-01
- Authored `planning/canonical-model-blueprint.md` detailing the staged ingestion → normalization → reconciliation pipeline for the canonical multi-Tacview aggregator; implementation deferred until event-graph exploration completes.
- Drafted `planning/event-graph-plan.md` outlining the ingestion → graph construction → inference approach for the probabilistic event graph aggregator that will replace the legacy multi-file loop.
- Surfaced default `time_tolerance` and `hit_backtrack_window` settings in every environment config so EventGraph tuning stays consistent across root, API, and public bundles.
- Extended the mission timeline tables with EventGraph-derived confidence percentages and source counts, complete with tooltips listing contributing recordings for each merged event.
- Added automatic cross-recording time alignment: the aggregator now detects matching anchor events, computes per-file offsets, and merges multi-pilot kills without duplicating entries.

### Removed - 2025-11-01
- Mirrored the Brownwater cleanup by deleting the local copies of `tacview.php`, `tacview.css`, language packs, icon bundles, tooling, and docs so the `core/` submodule stays the single source of truth for shared assets.
- Dropped the bundled `public/tacview.php` and language pack duplicates; the deployment build now loads everything through the shared core just like local development.

### Changed - 2025-11-01
- Hardened `.gitignore` to block reintroducing the shared engine files, languages, icons, data, tooling, docs, and public PHP shims that are now supplied by the submodule.

### Fixed - 2025-11-01
- Clustered EventGraph anchor detection so late-start Tacviews still derive a consistent offset when three matching events align, eliminating residual duplicate kill rows (e.g., CAROL 11 vs DEFEKT 1-1) while capping adjustments at 900 seconds.
- Let the EventGraph offset solver chain anchors through already-aligned recordings, picking the strongest match set before falling back and surfacing the delta/match count in the source summary so single-source Tacviews no longer reset to a zero offset.
- Expanded EventGraph duplicate suppression for low-frequency timeline actions (takeoff/landing/area transitions) by relaxing their merge window to 30–45 seconds so tiny inter-recording offset errors no longer show Twin copies like the Sting 2 takeoff pair.
- Promoted the EventGraph baseline recording to whichever file offers the strongest anchor connectivity, preventing outlier Tacviews from setting the zero point and duplicating launches such as the Sting 2 Sparrow volley.
- Raised the EventGraph anchor offset ceiling to a configurable multi-hour window so shared events like the 08:50:33 MiG-21 hit can sync recordings that start hours apart without spawning duplicate timeline rows.
- Pulled in the core `resolveCategoryIcon()` fallback so building events gracefully fall back to coalition/neutral glyphs instead of producing 404s; verified by rendering `php public/debriefing.php` after the cleanup.
- Adopted the shared asset resolver helpers across root, public, and API entry points so `$tv->image_path` now prefers the bundled `public/` icons and CSS before falling back to the core pack; spot-checked by dumping `/public/categoryIcons` references from the rebuilt container.
- Normalized EventGraph object key generation to ignore Tacview per-recording numeric IDs when richer metadata is present, allowing multi-track events (e.g., Menton 1 kills) to merge into unified rows with combined source evidence; confirmed by rerunning the aggregator CLI metrics.
- Guarded EventGraph's fallback offset logic behind a 10-minute threshold so unrelated sorties (like the 23:25 recording) stay on their native timeline instead of snapping to T+0; exposed the limit as `max_fallback_offset` in every config bundle and surfaced the applied strategy in the source summary readout.

### Added - 2025-10-31
#### Deployment Hardening Parity
- Added `.vercelignore` to keep locally mirrored PHP entry points out of Vercel uploads.
- Introduced temporary `/debriefing.php` redirect to `/api/debriefing` in `vercel.json` to prevent accidental static downloads if a stray file appears.

#### Public Bundle Documentation
- Created `public/CHANGELOG.md` so downstream consumers can review user-facing updates in the deployment bundle.

#### Mod Aircraft Icon Refresh
- Replaced the A-4E Skyhawk and F-104 Starfighter thumbnails with freshly normalized 640x360 Wikimedia Commons images and synced the updates across `objectIcons/` and `public/objectIcons/`.

### Fixed - 2025-10-31
#### Root-Relative Icon Regression
- Assigned `$tv->image_path = '/'` across API, root, and public debriefing entry points so mod aircraft thumbnails stay anchored to the site root regardless of routing.
- Verified the fix by serving `php -S localhost:8001 -t public` with the sanitized Tacview export to confirm icons display without PHP notices.
- Removed legacy fallback mappings for the A-4E Skyhawk and F-104 Starfighter so the renderer now uses the refreshed Wikimedia thumbnails, and normalized the Mi-24P Hind-F filename case to avoid Linux deployment misses.

#### Event Log Aircraft Name Normalization
- Added a `normalizeAircraftObject()` helper to `core/tacview.php` and `public/tacview.php` so aircraft, helicopter, and parent objects get corrected through `correctAircraftName()` before stats/event aggregation.
- Persisted the corrected aircraft names back onto `$this->events` so the mission timeline, stats tables, and weapon attribution all display `OV-10A Bronco` instead of the Tacview-exported `B-1 Lancer` alias.
- Mirrored the Brownwater verification step by replaying the sanitized debriefing through the local PHP server to ensure no regressions in the event feed or pilot summaries.
- Safeguarded the event loop by skipping Tacview records without a `PrimaryObject`, eliminating the PHP analyzer warnings triggered by support-only entries.

#### Local Dev Regression (Infinite Refresh)
- Added `public/debriefing.php` to the bundle so the PHP built-in server serves the real debriefing output instead of looping back to `index.html`.
- Normalized glob handling to build absolute paths and avoid warnings when no debriefings exist.

### Changed - 2025-10-31
#### Sticky Header Contrast
- Swapped the statistics header row to an opaque gradient with a cyan edge so scroll-sticky labels stay readable over the data grids.

#### Pilot Row Gradient Regression
- Restored the Cold War gradient, pointer cursor, and hover easing on `tr.statisticsTable` after the Brownwater port accidentally stripped the selector block braces.

### Verified - 2025-10-31
#### Brownwater Migration Checklist
- Confirmed sticky header semantics remain intact (`<thead>/<tbody>` and `border-collapse: separate`) in `tacview.php` and theme CSS.
- Validated `api/debriefing.php` status messaging by serving the project locally (`php -S localhost:8001 -t public`) and loading `/debriefing.php` with the sanitized Tacview XML.
- Spot-checked `languages/tacview_en.php` and `public/languages/tacview_en.php` to ensure language file parity across directories.

### Changed - 2025-10-27
#### Sticky Table Header CSS Implementation
- **UPDATED**: Converted Aircrew Performance Summary header to pure CSS sticky behavior
  - Added semantic `<thead>/<tbody>` structure for the statistics table output
  - Overrode global table overflow to allow `position: sticky` on SOTN header cells
  - Kept cold-war theme styling while adding cyan glow shadow for the fixed header
- **REASON**: Align SOTN project with Brownwater sticky-header improvements without cloning rows in JavaScript

### Changed - 2025-10-26
#### Enhanced User Interaction for Pilot Statistics Table
- **Improved Row Interaction Model**:
  - Entire pilot row is now clickable (not just pilot name)
  - Click anywhere in the row to expand/collapse pilot details
  - Removed distracting hover effects from non-interactive cells
  - Added whole-row hover highlighting with smooth cyan glow
- **Active State Management**:
  - Selected pilot row remains highlighted while details are shown
  - Active row displays enhanced cyan border and glow effects
  - Clicking another pilot automatically closes previous details
  - Visual feedback clearly indicates which pilot's details are displayed
- **JavaScript Enhancements**:
  - Updated `showDetails()` function to manage active row state
  - Fixed toggle logic to properly detect hidden row display state
  - Automatic cleanup of all other open detail sections
  - Improved accessibility with cursor pointer on clickable rows
- **CSS Refinements**:
  - Removed scale/transform effects that caused text blurring
  - Added `.active-pilot` class for persistent highlight state
  - Enhanced row hover with box-shadow and inset glow
  - Pilot name column now displays with increased font weight
- **User Experience**: Significantly more intuitive interaction - users can click anywhere in a pilot's row rather than targeting the small name link
- **Bug Fix**: Corrected JavaScript display toggle logic to check for both `""` and `"none"` states

### Added - 2025-10-26
#### Cold War Command Center Visual Theme Implementation
- **MAJOR VISUAL OVERHAUL**: Implemented authentic 1980s military command center aesthetic for debriefing pages
- **Retro CRT Display Effects**:
  - Animated horizontal scanlines with phosphor glow
  - Green monochrome phosphor effect on text elements
  - CRT screen curvature simulation via subtle distortion
  - Flicker animation for authentic terminal feel
  - VHS noise texture overlay
- **Tactical Grid Background**:
  - Animated Tron-style neon grid with perspective depth
  - Moving grid lines in cyan/green spectrum (#00ffff, #39ff14)
  - Radar sweep animation effect
  - Layered parallax grid movement
  - Subtle glow and bloom effects
- **Military Terminal Aesthetic**:
  - Neon cyan/electric blue primary colors (#00ffff, #0af)
  - Neon green accents (#0f0, #39ff14)
  - Amber warning highlights (#ff6b00)
  - Monospace terminal font integration
- **Analog Display Borders**:
  - Radar screen-style rounded corners with glow
  - Tactical display bezels on tables
  - Corner brackets reminiscent of HUD displays
  - Pulsing status indicators
- **Typography Enhancements**:
  - Retro computer terminal font stack (Share Tech Mono, Courier)
  - Green phosphor text glow effects
  - CRT-style text rendering with slight blur
  - Enhanced readability with backdrop filters
- **Interactive Elements**:
  - Smooth transitions maintaining period authenticity
  - Enhanced logo with command center styling
  - Tactical table highlights in neon colors
  - Retro hover effects
- **Period-Accurate Details**:
  - Film grain texture for 80s authenticity
  - Color palette restricted to period CRT monitors
  - Intentional low-fi digital aesthetic
- **Performance**: All effects optimized for smooth 60fps rendering

### Added - 2025-10-26
#### Automated icon curation
- PowerShell curator `tools/auto_curate_icons.ps1` now fetches canonical infobox images directly from Wikipedia articles using the pageimages API.
- Added Wikipedia article title mapping for all 23 aircraft to ensure correct article lookups (e.g., "F-16C Fighting Falcon" → "General Dynamics F-16 Fighting Falcon").
- Downloaded and normalized high-quality Wikipedia infobox thumbnails for all aircraft (23/23 success).

### Changed - 2025-10-26
#### Normalization tooling
- `tools/normalize_icons.php`: added ImageMagick CLI fallback (`magick`) when PHP GD is not available; avoids Windows `convert.exe` collision by restricting to `magick` on Windows.
- `tools/normalize_icons.php`: enhanced findMagick() to probe common Windows install paths (Program Files) when magick is not on PATH.

### Fixed - 2025-10-26
#### Curation script robustness
- Fixed URL interpolation bug in `tools/auto_curate_icons.ps1` where `$Api?` was parsed as a variable; use `$($Api)?...` to disambiguate.
- Replaced Commons search with Wikipedia pageimages API for more reliable and canonical aircraft photos.
- Hardened property access checks throughout PowerShell script using PSObject.Properties to avoid "property cannot be found" errors.

### Added - 2025-10-25
#### Aircraft icon improvement tooling
- Added `tools/list_aircraft.php` to scan debriefing XMLs and enumerate unique aircraft names with local icon presence
- Added `data/aircraft_icons_manifest.json` with suggested Wikimedia Commons category links for each aircraft and fields for file URL, license, and attribution
- Added `tools/download_icons.php` to download thumbnails defined in the manifest into `objectIcons/`
- Added placeholder PNGs for missing icons (`Su-25_Frogfoot.png`, `A-50_Mainstay.png`) to verify runtime `.png` fallback
- Added `tools/normalize_icons.php` to auto crop/resize thumbnails to 16:9 at 640px width (JPG preferred, PNG preserved with alpha)
- Added optional git pre-commit hook in `.githooks/pre-commit` and installer `tools/install-git-hooks.ps1`
- Added `docs/icons.md` with sourcing guidance, technical specs, and workflow

### Fixed - 2025-10-25
#### Browser Testing & Runtime Fixes
- **Fixed `declare(strict_types=1);` placement in debriefing.php**: Moved strict_types declaration to the very first statement in the file (before HTML output) to comply with PHP's strict type declaration rules. PHP requires this declaration to be the absolute first statement, before any output.
- **Removed duplicate object instantiation**: Cleaned up duplicate `$tv = new tacview("en");` line in debriefing.php
- **Verified missing asset icons**: Identified missing icon files (Su-25_Frogfoot.jpg and A-50_Mainstay.jpg) that return 404 errors but don't break functionality
- **Confirmed successful page load**: Application now loads successfully with HTTP 200 status, CSS styling applied, and AGWG logo displaying

### Changed - 2025-10-25
#### PHP 8.2 Modernization Initiative
Complete modernization of codebase to PHP 8.2 standards as per copilot instructions requirement for strict typing and modern syntax.

**Core Language Updates:**
- Added `declare(strict_types=1);` to all PHP files across the project
- Converted all `array()` syntax to modern `[]` shorthand throughout entire codebase (100+ instances)
- Implemented comprehensive type declarations for the main `tacview` class

**Type System Improvements:**
- Added explicit type declarations to all 20+ class properties in `tacview` class
  - Arrays: `$language`, `$airport`, `$primaryObjects`, `$secondaryObjects`, `$parentObjects`, `$objects`, `$events`, `$stats`, `$weaponOwners`, `$sam_enemies`
  - Strings: `$htmlOutput`, `$missionName`, `$currentData`, `$tagOpened`, `$image_path`
  - Booleans: `$tagAirportOpened`, `$tagPrimaryObjectOpened`, `$tagSecondaryObjectOpened`, `$tagParentObjectOpened`, `$tagObjectOpened`, `$tagEventOpened`, `$tagObjectsOpened`, `$tagEventsOpened`
  - Integers: `$airportCurrentId`, `$primaryObjectCurrentId`, `$secondaryObjectCurrentId`, `$parentObjectCurrentId`, `$objectCurrentId`, `$eventCurrentId`
  - Mixed: `$xmlParser` (XMLParser object in PHP 8.0+), `$startTime`, `$duration`, `$firephp`

- Added type hints to all function parameters:
  - `__construct(string $aLanguage = "en")`
  - `L(string $aId): string`
  - `getObjectIcon(string $aircraftName): string`
  - `sortStatsByGroupAndPilot(array $stats): array`
  - `addOutput(string $aHtml): void`
  - `getOutput(): string`
  - `displayTime(float|int $aTime): string`
  - `increaseStat(array &$Array, string|int $Key0, string|int|null $Key1 = null): void`
  - `getStat(array $Array, string|int $Key0, string|int|null $Key1 = null): mixed`
  - `proceedStats(string $aFile, string $aMissionName): void`
  - `displayEventRow(array $event): void`
  - `date_parse_from_format(string $format, string $date): array`
  - `parseXML(string $aFile): void`
  - `startTag(mixed $aParser, string $aName, array $aAttrs): void`
  - `cdata(mixed $aParser, string $aData): void`
  - `endTag(mixed $aParser, string $aName): void`

**Type Conversion Fixes:**
- Fixed `$duration` assignment to cast string to float: `$this->duration = (float)$this->currentData;`
- Fixed `$startTime` assignment to cast string to float: `$this->startTime = (float)$this->currentData;`
- Changed `$xmlParser` initialization from `null` to proper empty string handling in `$currentData`
- Updated `$xmlParser` property type from `int` to `mixed` to accommodate PHP 8.0+ XMLParser objects

**Icon Handling Improvements:**
- Updated `getObjectIcon()` to support `.png` fallback and to return existing file type when available (jpg preferred), enabling higher-quality or transparent PNG thumbnails

**Files Modified:**
- Core PHP files (5):
  - `tacview.php` - Main class with 1650+ lines modernized
  - `public/tacview.php` - Synchronized copy
  - `index.php` - Added strict types
  - `debriefing.php` - Added strict types
  - `api/debriefing.php` - Added strict types
  - `api/index.php` - Added strict types

- Language files (20 total):
  - Root `/languages/`: `tacview_de.php`, `tacview_en.php`, `tacview_es.php`, `tacview_fi.php`, `tacview_fr.php`, `tacview_hr.php`, `tacview_it.php`, `tacview_pt.php`, `tacview_ru.php`, `tacview_uk.php`
  - Public `/public/languages/`: All 10 language files mirrored
  - All converted from `array()` to `[]` syntax
  - All received `declare(strict_types=1);` declaration

**Configuration Updates:**
- `.php-version`: Confirmed PHP 8.2 as target version
- `Dockerfile`: Updated base image to `php:8.2-apache`
- `.github/copilot-instructions.md`: Updated to document PHP 8.2+ requirements with explicit mention of:
  - Strict typing requirement
  - Modern array syntax
  - Type hints for parameters and return types

### Removed - 2025-10-25
#### Wasmer Platform Support
- Deleted `wasmer.toml` configuration file
- Removed `.wasmer/` from `.gitignore`
- Removed all Wasmer references from copilot instructions
- Removed Wasmer from deployment targets documentation
- Rationale: Consolidating deployment platforms to focus on Vercel and Docker only

### Added - 2025-10-25
#### Project Documentation
- Created `.github/copilot-instructions.md` with comprehensive project guidelines
- Established code style standards and conventions
- Documented domain knowledge about Tacview format and military aviation context
- Added security and performance considerations
- Created this CHANGELOG.md file as per copilot instructions requirement

## Notes for Future Developers

### PHP 8.2 Modernization (2025-10-25)
This modernization effort was comprehensive and touched virtually every PHP file in the project. Key learnings:

1. **Type System Migration**: The codebase was originally written in PHP 5.x/7.x style without type declarations. Full migration to strict types required careful analysis of data flow, especially for:
   - XML parsing operations where data comes in as strings but needs numeric conversion
   - The `$xmlParser` property which changed from `resource` in PHP 7 to `XMLParser` object in PHP 8.0+
   - Undefined array keys for optional fields like "Group" in events, now handled with null coalescing operator (`??`)

2. **Array Syntax**: Simple find-replace of `array()` to `[]` works for most cases, but requires verification that no nested or complex array constructions broke

3. **Testing**: A comprehensive test suite was run with the actual Tacview XML debriefing file to verify:
   - XML parsing with 961KB+ files works correctly
   - Statistical calculations remain accurate
   - Display/output generation functions properly (846KB+ HTML output generated)
   - All 8 language functions load correctly
   - Type system prevents accidental type mismatches

4. **Test Results** (2025-10-25):
   - ✓ PHP 8.4.13 (backward compatible with PHP 8.2+)
   - ✓ Strict types declaration and enforcement working
   - ✓ All 20+ class properties properly typed
   - ✓ XML parsing successful with 961,086 byte test file
   - ✓ HTML output generation: 843,002 characters produced
   - ✓ Language system operational (10 languages tested)
   - ✓ Type safety verified with mock data
   - ✓ **ZERO PHP warnings or errors**

5. **Bug Fixes During Modernization**:
   - Fixed undefined array key warnings for optional "Group" field by using null coalescing operator (`??`) throughout
   - This improves code robustness and prevents warnings when XML doesn't include group information

6. **Why This Was Done**: The copilot instructions explicitly require that developers check and update the changelog before making changes. This modernization ensures the codebase follows current PHP best practices and makes future maintenance easier with explicit type checking.

### Previous Attempt Log
No previous attempts logged - this is the first modernization effort documented in the changelog.

---

## Template for Future Entries

```markdown
## [Version] - YYYY-MM-DD

### Added
- New features

### Changed
- Changes to existing functionality

### Deprecated
- Features that will be removed in future versions

### Removed
- Features removed in this version

### Fixed
- Bug fixes

### Security
- Security-related changes
```
