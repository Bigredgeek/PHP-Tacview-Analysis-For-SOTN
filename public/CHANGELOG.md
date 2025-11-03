# SOTN Public Bundle Changelog

## [Unreleased]
- Pulled updated core translations for disconnect, confidence, and source labels so the new mission and pilot table columns display across every supported language.
- Reset the tacview renderer before aggregated playback and normalize merged event arrays so pilot stats and the mission timeline render when `proceedAggregatedStats()` runs in the public bundle.
- Restored EventGraph confidence percentages and source badges in both mission and pilot-level tables, complete with tier-aware tooltips sourced from aggregated evidence.
- Updated mission timeline source badges to show only the numeric count while retaining hover tooltips listing each contributing recording.
- Added `aggregator` configuration block with default `time_tolerance` and `hit_backtrack_window` values to mirror the core runtime options.
- Clustered EventGraph anchor detection so late-start Tacviews still align on a shared offset when three matching events agree, removing the duplicate kill rows that ghosted in from the `Tacview-20251025-232536` recording and capping adjustments at 900 seconds.
- Displaying EventGraph confidence percentages and source counts beside each mission event, including tooltips that enumerate the contributing Tacview recordings.
- Synced with core auto-alignment logic so the public bundle resolves per-recording offsets before merging events.
- Corrected EventGraph deduplication so per-recording Tacview IDs no longer prevent multi-source events from merging; Menton 1's kill log now shows the appropriate combined source counts.
- Limited EventGraph fallback time shifts to ±10 minutes so disconnected recordings (e.g., late-night Tacviews) no longer get forced onto the baseline timeline; added `max_fallback_offset` to public configs and annotate the source summary with the applied offset strategy.
- Updated the public PHP entry points to use the shared asset resolver so deployment favors packaged `/public` icons and styles before falling back to the core bundle.
- Added coalition-aware fallbacks for building category icons so mission timelines no longer request missing `Building_*` sprites.
- Added `.vercelignore` and `vercel.json` redirect to keep `/debriefing.php` served by the API.
- Introduced `public/debriefing.php` for local PHP dev servers so the main view loads without refresh loops.
- Forced Tacview to build root-relative icon URLs from every debriefing entry point and smoke-tested via `php -S localhost:8001 -t public` with the sanitized mission export.
- Added a disconnect column and drilldown list to the pilot statistics table so mid-mission client dropouts surface alongside sortie totals without counting as kills.
- Pointed the A-4E Skyhawk and F-104 Starfighter back to their dedicated thumbnails and lowercased the Mi-24P Hind-F asset so Linux deployments pick up the file.
- Made sticky header row fully opaque with a cyan trim so labels stay legible while scrolling.
- Restored the gradient styling and hover affordance for pilot statistic rows so the retro theme matches Brownwater again.
- Refreshed the A-4E Skyhawk and F-104 Starfighter thumbnails with normalized Wikimedia imagery for consistent mod aircraft coverage.
- Normalized event log aircraft names by running `correctAircraftName()` across primary, secondary, and parent objects before persisting events so Bronco flights stay labeled correctly throughout the UI.
- Skipped Tacview events missing `PrimaryObject` data so support entries no longer trigger PHP analyzer warnings during the public build.
