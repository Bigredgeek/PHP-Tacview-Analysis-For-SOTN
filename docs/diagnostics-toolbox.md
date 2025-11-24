# Diagnostics Toolbox

This guide replaces the historical `tmp/*.php` grab bag with a supported set of diagnostics helpers. Use these entry points whenever you need to inspect duplicate clusters, raw events, or Tacview source metadata.

## EventGraph Dedupe Audit CLI
`scripts/eventgraph-dedupe-audit.php` now supports three modes:

- **clusters** (default): surfaces composite-signature buckets so you can spot duplicate kills or launches. Pair with `--type`, `--pilot`, `--target`, `--weapon`, `--window`, `--limit`, and `--duplicates-only` to zero in on suspicious merges.
- **events**: dumps the raw merged events that match your filters. Add `--time <seconds>` plus `--time-window` to focus on a specific point in the mission timeline, or supply `--parent` to filter on the firing platform.
- **sources**: prints the per-recording offsets, offset strategies, and mission coverage so you can audit time-alignment decisions without bespoke scripts.

Example commands:

```sh
php scripts/eventgraph-dedupe-audit.php --type HasBeenDestroyed --pilot "Skunk" --duplicates-only
php scripts/eventgraph-dedupe-audit.php --mode events --type HasFired --pilot "Skunk 1-2 | Zach" --time 2500 --time-window 5
php scripts/eventgraph-dedupe-audit.php --mode sources
```

## Regression Harness
`scripts/run-regressions.php` remains the canonical way to sanity-check whole Tacview bundles. It ingests each configured dataset, reports raw→merged counts, duplicate clusters, and writes timestamped JSON artifacts in `tmp/regressions/<ts>/`. Pass `--skip-tests` to bypass PHPUnit when you only need aggregator metrics.

## Specialized Helpers
Only two bespoke probes remain, both relocated to `scripts/diagnostics/`:

- `analyze-coalition-mismatch.php` — scans a raw Tacview XML for coalition inconsistencies without going through the aggregator.
- `source-event-dump.php` — loads individual Tacview exports and lists mission-time windows straight from the `SourceRecording`, which is handy when you need to compare pre-aggregation metadata.

All other historical `tmp/*.php` utilities were removed because their functionality now lives in the audit CLI or regression harness. Keep `tmp/` reserved for generated artifacts (regression logs, temporary debriefing exports) so future investigators know exactly where to look.
