# Canonical Event Aggregation Blueprint

## Objective
Design and implement an intermediate canonical data model that ingests multiple Tacview XML exports from the same mission, reconciles their flight/event logs, and produces a single coherent timeline for downstream rendering.

## Guiding Principles
- **Deterministic pipeline**: parsing, normalization, reconciliation, and rendering should be distinct stages so each can be unit-tested in isolation.
- **Source transparency**: retain provenance metadata for every canonical event to explain how it was derived and which recordings contributed to it.
- **Extensible heuristics**: make duplicate detection, attribution repair, and conflict resolution pluggable to accommodate future tuning without rewriting the pipeline.

## Proposed Architecture
1. **Ingestion Layer**
   - Parse each XML into an intermediate `RawEvent` structure using existing Tacview parser logic (reuse `tacview` class where possible).
   - Record metadata: source id, mission timestamps, recording offsets, player coalition, and estimated sensor slant range per event (if available).
2. **Normalization Layer**
   - Convert `RawEvent` instances into canonical `FlightEvent` objects with standardized fields (ISO timestamps, UUIDs, normalized object ids, weapon identifiers, geocoordinates, and confidence scores).
   - Apply existing corrections (`normalizeAircraftObject`, weapon attribution fixes) during this step.
3. **Reconciliation Layer**
   - **Time Alignment**: establish recording offsets using mission start time, first takeoff, or matched anchor events; adjust event timestamps to mission-absolute time.
   - **Duplicate Clustering**: bucket canonical events by type, object(s), and time window (±1–2s adaptive). Collapse clusters into a single event while aggregating provenance and raising confidence.
   - **Attribution Repair**: search within contextual windows for causal links (weapon fired → missile disappear/impact → target destroyed). Fill missing relationships when any source provides matching precursor/successor events.
   - **Conflict Resolution**: when sources disagree (e.g., different killer for same destruction), pick best candidate using confidence weighting; fallback to flagging conflict for UI display.
4. **Emission Layer**
   - Output sorted canonical timeline along with per-source deltas for audit/debug views.
   - Provide adapters so existing UI components can consume canonical events with minimal changes (e.g., convert to current stats/event arrays).

## Data Model Sketch
```text
RawEvent {
  sourceId: string
  recordingTime: float // seconds from recording start
  missionTime: float|null
  type: 'fire' | 'impact' | 'hit' | 'destroy' | ...
  primaryObject: ObjectRef
  secondaryObject: ObjectRef|null
  weapon: string|null
  position: GeoPoint|null
  extra: array
}

FlightEvent {
  id: string // UUID
  missionTime: float // canonical unified timeline
  type: string
  actors: {
    initiator: ObjectRef|null
    recipient: ObjectRef|null
    weapon: ObjectRef|null
  }
  position: GeoPoint|null
  confidence: float
  provenance: SourceEvidence[]
  attributes: array
}

SourceEvidence {
  sourceId: string
  recordingTime: float
  confidence: float
  rawEventId: string
}
```

## Key Heuristics
- **Temporal tolerances**: dynamic windows based on event type (e.g., 0.5s for weapon fire, 2s for destruction events).
- **Spatial checks**: when coordinates exist, require proximity within configurable 3D radius before treating events as duplicates.
- **Object identity mapping**: unify Tacview ids by matching coalition, callsign/group, aircraft type, and velocity vectors.
- **Confidence scoring**: base score on slant range, event type reliability, and number of corroborating sources.

## Implementation Tasks
1. Create `DebriefingAggregator` service to orchestrate ingestion → reconciliation → emission.
2. Extend parser to output `RawEvent` objects with provenance metadata.
3. Build normalization utilities for object identity, time alignment, and confidence estimation.
4. Implement duplicate clustering (initially missionTime ±1s, same type, same primary object) with pluggable strategies.
5. Add attribution repair module to link destruction events to prior weapon launches.
6. Provide API endpoint updates so `/api/debriefing` selects canonical pipeline when multiple files present.
7. Update unit/integration tests to cover multi-source merges using supplied SOTN Tacview files.
8. Document configuration knobs (tolerances, confidence weights) in README/SETUP guides.

## Testing Plan
- Build fixture set from provided Tacview exports with expected combined timeline.
- Write PHPUnit tests for normalization and clustering heuristics.
- Add CLI harness to run aggregation against selected XML sets and output diff reports.
- Manual validation via local server to ensure UI renders single, de-duplicated mission log.

## Open Questions
- How to handle partially overlapping mission segments (should we enforce contiguous missionTime ranges or allow gaps)?
- Do we surface provenance/confidence in the UI, or keep for debugging only?
- Should aggregation run asynchronously (pre-processed) or on-demand per request?

## Next Steps
- Pause implementation here per project direction.
- When ready to resume, start with `DebriefingAggregator` scaffolding and parser refactor to emit `RawEvent` objects.
