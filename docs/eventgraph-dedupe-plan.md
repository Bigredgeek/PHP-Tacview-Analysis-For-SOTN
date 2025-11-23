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
  - Re-run `php tmp/inspect_zach.php` and confirm six unique HasFired rows remain.
  - Run `php tmp/find_duplicates.php | Select-String "Olympus"` and ensure Olympus truck destructions now emit once per kill.

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
- **Status:** Not present. `scripts/` only hosts deployment utilities; investigators rely on ad-hoc `tmp/*.php` scripts.
- **Implementation Plan:**
  1. Add `scripts/eventgraph-dedupe-audit.php` that bootstraps the app (require `config.php`), instantiates `EventGraphAggregator`, and iterates merged events printing `canonicalTargetKey`, `weaponKey`, mission time, and evidence counts.
  2. Support filters via CLI options (e.g., `--pilot "Skunk 1-2"`, `--type HasBeenDestroyed`, `--window 10`).
  3. Document usage in `README.md` under a new “Diagnostics” section.
- **Verification:**
  - Run `php scripts/eventgraph-dedupe-audit.php --pilot "Skunk 1-2" --type HasBeenDestroyed` and confirm output matches inspector scripts.
  - Replace ad-hoc one-off scripts with this CLI inside `tmp/README.md` (if any) to reduce drift.

## 5. Multi-Set Regression Runs
- **Status:** Only the sanitized GT6 set is mentioned in `CHANGELOG.md` (Unreleased → Fixed 2025-11-22). No evidence of reruns against the Franz strike or other missions.
- **Implementation Plan:**
  1. Prepare a regression checklist referencing key Tacview bundles (GT6 sanitized set, Franz strike, Olympus Menton, Brownwater baseline).
  2. For each set, run:
     - `php tmp/find_duplicates.php --path ./debriefings/[SET]`
     - `php tmp/inspect_zach.php` or analogous inspector per squadron
     - `php public/api/debriefing.php?debriefing=[SET]` via curl to verify API output
  3. Capture summaries in `TEST_RESULTS.txt` and append bullet points to `CHANGELOG.md` under the corresponding date.
  4. Automate via a PowerShell or PHP harness (`scripts/run-regressions.ps1`) so future agents can re-run with one command.
- **Verification:**
  - Archive the command logs under `tmp/regressions/DATE/` for traceability.
  - Share findings with the team (Slack/README) if new clusters appear.

## Execution Order for an Agentic Model
1. Implement the composite signature pipeline (Section 1) and ensure unit metrics pass.
2. Add `reconcileDestructions()` (Section 2) and re-run inspectors to validate dedupe.
3. Scaffold PHPUnit + fixtures (Section 3) so future changes are gated.
4. Build the audit CLI (Section 4) to replace ad-hoc scripts.
5. Automate and document regression runs across mission sets (Section 5), updating `CHANGELOG.md` and `TEST_RESULTS.txt` with results.

Follow each section in order, verifying outputs after every stage before moving on.
