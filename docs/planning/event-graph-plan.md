# Event Graph Aggregation Plan

## Goal
Implement a multi-recording reconciliation engine that models Tacview events as a directed graph, deduplicates overlapping observations, and infers causal relationships (e.g., weapon origins for destruction events) before rendering a unified mission timeline.

## High-Level Architecture
- **Ingestion**: Parse each Tacview XML export into a `SourceRecording` with normalized event objects. Reuse existing `tacview` parser for field extraction to avoid reimplementing XML traversal.
- **Graph Construction**: Represent each real-world entity (objects, weapons, events) as nodes. Add typed edges describing relationships such as `initiated_by`, `targets`, `causes`, and `observed_by`.
- **Reconciliation**: While building the graph, merge nodes/events that fall inside configurable temporal/spatial tolerances and share key identifiers. Maintain `Evidence` records per source for transparency.
- **Inference**: Run passes over the graph to fill gaps: connect destructions to likely weapon impacts, propagate initiators to indirect events, and score confidence based on corroborating evidence.
- **Emission**: Collapse the graph back into ordered mission events ready for the existing UI, including provenance metadata and confidence values for future display.

## Proposed Class Layout
- `EventGraph\SourceRecording`
  - Holds metadata (call sign, start time, mission time offset) and raw events extracted from one Tacview file.
- `EventGraph\EventNode`
  - Unique key (`type`, `object ids`, `canonicalTime`). Stores consolidated attributes and evidence list.
- `EventGraph\ObjectNode`
  - Represents aircraft/weapon/platform with merged identifiers across sources.
- `EventGraph\Graph`
  - Central registry of nodes and edges. Provides lookup helpers (by time window, object match, weapon chain).
- `EventGraph\Aggregator`
  - Public API: `ingestFile`, `build`, `toTacviewDataset`. Coordinates ingestion, deduplication, inference, and output formatting.

## Deduplication Strategy
1. **Time Alignment**: Normalize event timestamps to mission-time using mission metadata or first meaningful event alignment.
2. **Primary Key Matching**: Find existing event nodes with same `type` and overlapping participants (same normalized object IDs, weapon ID).
3. **Tolerance Checks**:
   - Temporal window: default ±1.0s (configurable per event type).
   - Spatial window: ≤250m radius for impacts/destructions when coordinates exist.
4. **Evidence Merging**: When duplicates detected, merge by appending `Evidence` entries and recalculating confidence (0–1 scale based on number/quality of sources).

## Inference Passes
- **Missile → Kill Attribution**: Search for weapon disappearance/impact events preceding a platform destruction. Link via `causes` edge and assign initiator if missing.
- **Gunfire Attribution**: Aggregate burst events and look for subsequent damage/destruction to same target within 3s to infer shooter.
- **Support Events**: Filter noisy support-only events (no primary object). Skip or down-rank them to avoid clutter.

## Output Format
- `AggregatedMission` data structure containing:
  - `missionName`, `startTime`, `duration`
  - Ordered `events` (already deduplicated and enriched)
  - `stats` scaffolding compatible with `tacview` calculations

## Integration Plan
1. Add new namespace under `src/EventGraph/` (autoloaded via Composer or manual requires) to host aggregator classes.
2. Modify `debriefing.php`, `public/debriefing.php`, and `api/debriefing.php` to:
   - Collect all `*.xml` files
   - Instantiate `EventGraph\Aggregator`
   - Ingest each file and build unified dataset
   - Feed dataset into the existing `tacview` renderer via a new method (`proceedAggregatedStats`) that accepts precomputed events.
3. Update `core/tacview.php` with helper to accept aggregated data without re-parsing XML.
4. Extend tests (create new test harness) using provided SOTN XML exports to verify deduplication and attribution inference.

## Configuration & Extensibility
- Add `config.php` settings for tolerance thresholds and confidence weights.
- Provide CLI debug command (optional) to dump graph edges for tuning.

## Risks & Mitigations
- **Complexity of Graph Build**: Start with minimal viable edges (fire → hit → destroy) before layering advanced heuristics.
- **Performance**: Ensure graph operations scale to 10k+ events by using indexed lookups (e.g., by event type and time buckets).
- **Compatibility**: Keep fallback path (single-file parse via existing `proceedStats`) for scenarios with one recording or when aggregator disabled.

## Immediate Tasks
1. Implement basic ingestion to create `SourceRecording` objects and normalized events.
2. Build initial deduplication pass (time-based clustering) and verify with sample XML.
3. Introduce `proceedAggregatedStats` in `tacview` to render aggregated dataset.
4. Wire aggregator into entry points and confirm UI renders combined mission with no duplicates.
5. Iterate on inference heuristics and confidence scoring.
