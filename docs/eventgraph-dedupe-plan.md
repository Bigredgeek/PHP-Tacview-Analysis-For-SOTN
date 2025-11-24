# EventGraph Deduplication Roadmap

This document translates the recent investigation notes into a to-do style plan that an autonomous agent can execute. Each section tracks the status of a requested safeguard and outlines concrete implementation and validation steps.

## 1. Weapon-Instance + Target Signature Clustering
- **Status:** Not implemented. `coalesceDuplicateEvents()` (see `src/EventGraph/EventGraphAggregator.php`, lines ~438-520) only groups events by loose object keys and per-type windows. It never emits canonical hashes like `[eventType, targetKey, weaponKey, timeBucket]`.
- **Implementation Plan:**
  1. During ingestion or immediately after normalization, compute a `canonicalTargetKey` via `ObjectIdentity::forObject()` and a `weaponInstanceKey()` (ID → parent fallback). Add a helper that returns `null` when both are missing, logging metrics for diagnostics.
  2. Add a `buildCompositeSignature(NormalizedEvent $event): ?string` helper that returns `strtolower($event->getType()) . '|' . $canonicalTargetKey . '|' . ($weaponKey ?? 'unknown') . '|' . $roundedBucket` where `roundedBucket = floor(missionTime / 5)` (or configurable value).
  3. Maintain a `signatureIndex` map when calling `addOrMerge()` so duplicate signatures always merge before inference regardless of evidence order.
  4. Track metrics (`composite_signatures_emitted`, `composite_signature_merges`) to confirm hits during regression runs.
- **Verification:**
  - Re-run `php scripts/eventgraph-dedupe-audit.php --mode events --type HasFired --pilot "Skunk"` and confirm six unique HasFired rows remain.
  - Run `php scripts/eventgraph-dedupe-audit.php --duplicates-only --type HasBeenDestroyed | Select-String "Olympus"` and ensure Olympus truck destructions now emit once per kill.

## 2. Post-Build Reconciliation Pass
- **Status:** Missing. After `runInference()` the pipeline only calls `applyPostMergeFilters()` and `pruneDisconnectDestructions()` (both prune, not merge).
- **Implementation Plan:**
  1. Introduce `reconcileDestructions()` after `runInference()` but before filters. It should iterate chronologically, derive `canonicalTargetKey`, and compare `HasBeenDestroyed` events within an override window (default 5 s) regardless of earlier dedupe results.
  2. When merging, combine evidence, choose the earliest mission time, and update `metrics['post_inference_merges']`.
  3. Ensure this pass respects coalition sanity checks and emits debug metrics for cases skipped due to mismatched keys.
- **Verification:**
  - Add a temporary trace log (conditionally via `config.php` flag) to ensure reconciliation triggers for the Menton/Olympus stack.
  - Re-export the debriefing (`php debriefing.php > tmp/debriefing-output.html`) and confirm no duplicate destruction rows remain.

## 3. Regression Fixture + PHPUnit Test
- **Status:** ✅ `tests/EventGraph/EventGraphAggregatorTest.php` now loads the `menton_dupe` Tacview fixture, proving composite signatures and the reconciliation window collapse duplicates before merges ship.
- **Implementation Plan:**
  1. Keep `composer install` plus `vendor/bin/phpunit` (or `npm run test`) in the developer checklist so regressions fail fast.
  2. Extend the fixture set when new edge cases appear (e.g., add Franz strike or Skunk HasFired noise) so coverage scales beyond Menton/Olympus.
  3. Mirror any future suites in CI by invoking the existing `test` npm script.
- **Verification:**
  - `vendor/bin/phpunit --testsuite event-graph` ensures duplicate destructions collapse to two targets and enforces the expected metrics counters.

## 4. EventGraph Dedupe Audit CLI
- **Status:** ✅ `scripts/eventgraph-dedupe-audit.php` now ships with the repo and replaces the ad-hoc `tmp/*.php` probes.
- **Implementation Notes:**
  1. The CLI bootstraps the normal aggregator stack (shared config + EventGraph autoloader), ingests the selected Tacview glob, and emits composite-signature clusters with `targetKey`, `weaponKey`, bucket range, and evidence counts.
  2. Filters include `--pilot`, `--type` (repeatable), `--target`, `--weapon`, `--window <seconds>` for bucket sizing, `--duplicates-only` to focus on contested signatures, `--limit`, and `--json` to hand results to tooling.
  3. Usage is documented in the README “Diagnostics” section so investigators can grep duplicates without writing new scripts.
- **Verification:**
  - `php scripts/eventgraph-dedupe-audit.php --mode events --type HasBeenDestroyed --pilot "Skunk" --limit 3` highlights the Skunk truck destructions with the same canonical keys as the retired inspectors.
  - Running with `--json` mirrors the evidence counts and metrics reported by the inspector scripts, demonstrating parity and enabling CI hooks.

## 5. Multi-Set Regression Runs
- **Status:** ✅ `scripts/run-regressions.php` now exercises the canonical Tacview bundles (GT6 rc4 set, Franz STRIKE3002 archive, and the Nov 8 evening stack), records aggregator metrics + duplicate-cluster counts, and stores machine-readable logs under `tmp/regressions/<timestamp>/`.
- **Implementation Notes:**
  1. The harness boots the EventGraph stack, optionally shells out to `php vendor/bin/phpunit --testsuite event-graph`, then iterates each dataset glob defined in `regressionSets()`.
  2. For every dataset it ingests the files, captures mission duration, raw→merged counts, composite/post-inference merges, and a quick duplicate-cluster scan (target/weapon bucket signature) to highlight any regressions.
  3. Results are persisted as JSON per set plus a top-level `summary.json`, making it easy to diff runs or feed the numbers into dashboards; logs are timestamped for traceability.
- **Verification:**
  - `php scripts/run-regressions.php --skip-tests` (run on 2025-11-23) generated `tmp/regressions/20251124-044821/` with clean summaries for GT6 rc4, Franz STRIKE3002, and the Nov 8 evening Tacviews—no duplicate clusters detected in any set.
  - README and `TEST_RESULTS.txt` now outline the workflow so future engineers can rerun the bundle (or add new ones) before shipping dedupe changes.

## Execution Order for an Agentic Model
1. Implement the composite signature pipeline (Section 1) and ensure unit metrics pass.
2. Add `reconcileDestructions()` (Section 2) and re-run inspectors to validate dedupe.
3. Scaffold PHPUnit + fixtures (Section 3) so future changes are gated.
4. Build the audit CLI (Section 4) to replace ad-hoc scripts.
5. Automate and document regression runs across mission sets (Section 5), updating `CHANGELOG.md` and `TEST_RESULTS.txt` with results.

Follow each section in order, verifying outputs after every stage before moving on.
