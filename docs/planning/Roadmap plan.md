
## Master vision##
Here's my grand vision for this project. I'd like to submit it as a tool to the DCS and BMS military flight sim communities, but I need to add some final features and work out the rest of the bugs. Ideally I'd like Vercel, or a suitable competitor for free and easy web hosting to handle that for people who don't have access to a dedicated web server, and I'd like an easy to use docker container to also be capable of hosting it for those who do have a server available. Missions are not necessarily on a 2 week schedule, they happen at random intervals so the project needs to be dynamic

So here's the total feature list I would like in this project:
1. Ingest multiple tacview recordings from different players for a mission, align all of the events to account for inaccuracies in the way tacview records events on the clients, and have effective de-duplication methods that don't scrub out valid events, and present all of this into a timeline - Mostly functional, room for accuracy improvements or improved filtering logic
2. Generate a table of all of the player pilot's and a summary of their kills/hits/Friendly fire incidents/etc. - Mostly done, but I would like more seperation based on coalition (ie. Allies(Warsaw Pact/Redfor) and Enemies(NATO/Blufor) in the tacview files)
3. Be able to click on a pilot's line in the table and get a detailed breakdown of that pilot's performance. Details like number of sorties, weapons used, victories, and their timeline of events - Mostly done, but room for more insights. I'd also like to be able to click on a time in the pilot's debrief timeline, and have that jump down to the main timeline for that event
4. Have some way to seperate the data for the same pilot flying multiple times, such as have airframe or sortie specific highlights if they fly multiple sorties or fly a different aircraft later in the mission - Not yet implemented
5. Generate a detailed report of all the munitions and airframes sortied/expended/destroyed/wasted/etc. per coalition and group, (ie. menton, Defekt, etc.) - not yet implemented
6. Have a timeline showing the lengths and windows that the different sources cover - implemented but needs refinement
7. Be able to click back through the history of multiple missions, ie. each group of tacview exports. Ie. for SOTN, GT4, GT6, it would be ideal if they were in some sort of master timeline, and you could click on them to pull up the standard tacview debrief for that mission we currently have implemented
8. Have a master dashboard that game masters/wing/squadron commanders could reference for stats and information for their squadrons throughout an entire campaign. Perhaps this is where the master timeline in 7. could be displayed as well. 
## --REMOVED FROM SCOPE 9. be able to dynamically upload new missions to the website directly, either permanently or just temporarily, in addition to being able to add the files via github commits to the debriefings folders --
10. Maintain compatibility with a site like vercel or another easy to use free hosting site, and docker. The idea being that an organizer from another DCS group could clone the repo, personalize the stylings for their own group, and make it their own.

Generate a detailed plan for each of these items, including any information that would make it easier for vscode github agentic copilot to implement. Create a roadmap for how best to organize the implementation, testing, and release of these features gradually so we can minimize bugs and issues.

## initial, poor quality plan from copilot ##
Plan: Campaign-Ready Tacview Suite
Deliver a multi-phase roadmap that upgrades EventGraph fidelity, expands analytics, and ensures both static builds and dynamic uploads run smoothly on Vercel/Docker. Each feature plan references existing PHP/Tacview architecture so GPT-5.1-Codex can execute follow-up tasks efficiently.

Steps
Alignment & Timeline Accuracy – Enhance src/EventGraph/EventGraphAggregator.php offset heuristics (drift detection, coalition-weighted anchors), extend scripts/run-regressions.php and scripts/eventgraph-dedupe-audit.php to prove improvements.
Coalition + Pilot Analytics – Update tacview.php rendering flows to segment pilot summaries by coalition, add REST helpers in public/api/ for pilot drill-down JSON, wire UI callbacks that jump from pilot timelines to mission timeline anchors.
Sorties & Reports – Teach AggregatedMission to bucket sorties (gap- or airframe-based) and add munitions/airframe aggregation modules feeding new sections in debriefing.php plus export-ready APIs (public/api/reports.php).
History & Dashboard – Build preprocessing scripts to emit per-mission JSON/HTML, expose listings in public/history.php, and layer a new campaign dashboard (e.g., public/campaign.php) that combines multi-mission stats.
## REMOVED FROM SCOPE Dynamic Uploads & Packaging – Implement authenticated upload endpoint writing to debriefings/uploads/, trigger preprocessing jobs, and solidify deployment: static pre-gen build script invoked via vercel.json buildCommand, Dockerfile multi-stage build, plus documentation in README.md/CHANGELOG.md.
Further Considerations
Decisions needed: Where to store processed mission assets? Option A filesystem, B S3-compatible blob, C Vercel KV.
Upload auth model? Option A shared secret, B OAuth (Discord), C simple token per group.

## Second implementation plan after some cajoling ##
elow is a concrete implementation strategy for each master-vision bullet. File references use paths as they exist now (e.g., src/EventGraph/EventGraphAggregator.php, debriefing.php, tacview.php). This gives GPT‑5.1‑Codex and humans precise touchpoints for future tasks.

Steps
1. Multi-source Alignment & Deduplication – Extend src/EventGraph/EventGraphAggregator.php with coalition-weighted anchor selection, rolling drift checks, and per-event confidence tagging. Persist metrics in AggregatedMission so debriefing.php can flag suspect offsets. Add config knobs in config.php, document them in README.md, and expand scripts/run-regressions.php/scripts/eventgraph-dedupe-audit.php to compare offsets and duplicate suppression before releases.
2. Coalition-Aware Pilot Tables – Carry coalition metadata from NormalizedEvent through to tacview.php so pilot summaries render in separate <tbody> sections (Allies vs Enemies). Update translation strings under languages, adjust CSS (public/tacview.css, core/tacview.css) for coalition coloring, and expose the same split via an optional JSON endpoint (public/api/pilots.php) to support dashboards.
3. Pilot Drill-Down & Timeline Linking – Assign stable IDs to mission events in AggregatedMission::addEvent, emit them onto pilot detail rows, and add JS in debriefing.php that scrolls/highlights the main mission timeline when a pilot event is clicked. Expand the pilot modal to include sortie counts, weapons used, and top events by calling new helpers in tacview.php.
4. Sortie & Airframe Separation – Introduce a SortieSegmenter class (e.g., src/EventGraph/SortieSegmenter.php) that groups each pilot’s events by aircraft changes or long inactivity gaps, tagging each event with sortieId and airframe. Surface these segments in pilot detail panels and optionally add per-sortie badges near the main table. Cache sortie metadata in scripts/preprocess-debriefings.php so static builds stay fast.
5. Munitions & Airframe Reports – Build an aggregation pass (MissionOrdnanceReport) that tallies sorties, weapons fired, hits, attrition per coalition and unit. Render a new report section in debriefing.php (tables + optional CSV download) and expose the same data via /api/reports.php. Ensure NormalizedEvent parsing captures weapon GUIDs/types and update icon references under categoryIcons/ and objectIcons/.
6. Source-Coverage Timeline Refinement – Improve coverage math in EventGraphAggregator::computeAlignedWindow() to record gaps/confidence intervals, then render a stacked coverage bar (SVG or div grid) above the main timeline in both tacview.php and public/tacview.css. Provide a toggle to show/hide coverage and display metrics (overlap percentage) in the status overlay for debugging.
7. Mission History Navigation – Enhance scripts/preprocess-debriefings.php to scan debriefings/ (and subfolders) and emit public/debriefings/manifest.json plus static HTML/JSON for each mission. Add public/history/index.php that lists missions (campaign, date, participants) and links to their cached debriefs. Update debriefing.php to accept a ?mission= query that loads the correct cache when needed.
8. Campaign Master Dashboard – Create src/Campaign/CampaignAggregator.php to consume the mission manifest and ordnance summaries, producing campaign-wide KPIs (win/loss, attrition, sortie rates). Build public/campaign.php (or /dashboard) that loads this summary JSON, renders charts (lightweight JS), and shows filters per campaign/coalition. Store campaign metadata in docs/campaigns/*.json for maintainability.
## REMOVED FROM SCOPE 9. Dynamic Mission Uploads – Implement an authenticated upload endpoint (public/api/upload.php) that validates .xml, writes to debriefings/uploads/, and triggers preprocessing (in Docker via a watcher, on Vercel via background job or manual command). Provide UI (public/upload.html) for organizers, enforce size/type limits, and support both temporary and permanent publish modes. Abstract storage so Vercel deployments can target Vercel Blob/S3 while Docker keeps local volumes.
10. Hosting & Packaging – Add a preprocessing build step (php scripts/preprocess-debriefings.php --all) invoked via vercel.json buildCommand and package.json scripts so deployments ship static HTML/JSON caches. Refine Dockerfile into a multi-stage build with Composer/vendor install, opcache, health-check endpoint (public/api/health.php). Update README.md, SETUP_GUIDE.md, and CHANGELOG.md to describe Vercel/Docker workflows, env vars, and rollback procedures.
Further Considerations

## Roadmap Phasing
 – Phase 1: Features 1 & 6 (core accuracy).
     Multi-source Alignment & Deduplication Enhancements
    Refine offset estimation (EventGraphAggregator.php):
    Layer coalition-aware anchor scoring and drift sampling into estimateRecordingOffset() / computeRecordingMetadata().
    When a source drifts, record a per-source confidenceScore
    Improve duplicate handling:
    Extend duplicate windows to consider weapon GUIDs/coalitions (update DUPLICATE_TIME_WINDOWS, merge logic).
    Track duplicate suppressions per event for later diagnostics.
    Expose diagnostics:
    Add new metrics (alignment_conflicts, anchor_confidence, etc.) to $metrics.
    Surface the data by augmenting AggregatedMission::addSource() payloads (include confidenceScore, warnings) so debriefing.php can highlight suspect recordings.
    Configuration & docs:
    Introduce config toggles in config.php (e.g., anchor_decay, drift_sample_window) with defaults and update README.md + CHANGELOG.md.
    Regression coverage:
    Expand scripts/run-regressions.php and scripts/eventgraph-dedupe-audit.php to dump offset/duplicate summaries and compare against golden baselines in tests/fixtures/.
2. Source-Coverage Timeline Refinement
Data layer:
Enhance buildRecordingCoverageWindows()/computeAlignedWindow() to capture gaps, overlap %, and a sorted list of coverage segments.
Store these segments in each source entry returned by AggregatedMission::addSource() and in any cached JSON produced by scripts/preprocess-debriefings.php.
UI updates:
Add a coverage visualization (stacked bars or SVG) near the top of tacview.php / debriefing.php.
Style via core/tacview.css + public/tacview.css, including hover tooltips that show aligned start/end and confidence.
Add a toggle (e.g., checkbox) in the UI to hide/show coverage for clutter control.
Debug overlay:
In the existing status/debug overlay, print coverage stats and drift warnings per source to help triage misaligned uploads.
3. Verification Plan
Run php -S 127.0.0.1:8000 -t public with representative missions to visually confirm coverage bars and detect alignment issues.
Execute the expanded regression scripts on the sample Tacview XMLs under debriefings/ to ensure offsets/dedupe metrics regress cleanly.
Update documentation (docs/planning/Roadmap plan.md, README.md, CHANGELOG.md) describing Phase 1 outcomes and how to interpret new diagnostics.
 - Phase 2: Features 2–4 (pilot analytics). 
 - Phase 3: Features 5, 7, 8 (campaign intelligence).
 - Phase 4: Features 10 (operations & hosting). Each phase ends with regression scripts + manual Tacview tests.
