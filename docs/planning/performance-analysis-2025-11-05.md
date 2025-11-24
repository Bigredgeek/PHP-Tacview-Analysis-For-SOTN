# Performance Analysis: Mobile and Weaker PC Issues
**Date:** 2025-11-05  
**Investigator:** Copilot Agent  
**Branch:** `copilot/investigate-performance-issues`

## Executive Summary

Performance issues on mobile and weaker PCs are caused by a combination of server-side processing delays, large HTML payloads, and client-side rendering overhead. The primary culprit is the EventGraphAggregator system that processes multiple Tacview XML files on every page load.

### Key Metrics (Current State)
- **Server Processing Time:** ~1.3 seconds (4 XML files @ ~380KB each)
- **HTML Payload Size:** 1.6MB (uncompressed)
- **Total XML Input:** ~1.5MB across 4 files
- **Number of Events:** Thousands of mission events with tooltips
- **JavaScript Interaction:** Single showDetails() function for pilot row expansion

## Root Causes

### 1. Server-Side Processing Bottleneck (Primary Issue)
**Impact: HIGH** | **Affects: All users, especially mobile**

The EventGraphAggregator (`src/EventGraph/EventGraphAggregator.php`, 1809 lines) performs complex operations on every page load:

#### What It Does:
- Parses 4 XML files (~380KB each = 1.5MB total)
- Normalizes events from multiple recordings
- Detects matching anchor events across recordings
- Computes per-file time offsets
- Merges duplicate events using time windows
- Calculates confidence scores (Tier A/B/C)
- Resolves coalition conflicts
- Tags orphan events
- Aggregates pilot statistics

#### Processing Complexity:
```php
// From EventGraphAggregator.php
private const EVENT_TIME_OVERRIDES = [
    'HasTakenOff' => 30.0,
    'HasLanded' => 45.0,
    'HasEnteredTheArea' => 45.0,
    'HasLeftTheArea' => 45.0,
    'HasBeenHitBy' => 4.0,
    'HasBeenDestroyed' => 5.0,
];
```

The aggregator uses sophisticated algorithms:
- Time tolerance windows (1.5s default)
- Anchor detection with 120s tolerance
- Fallback offset calculation up to 900s
- Coalition voting and reliability weighting
- Event deduplication with configurable merge windows

#### Performance Profile:
- **XML Parsing:** ~0.3s (4 files via SimpleXML)
- **Event Graph Construction:** ~0.5s (anchor detection, offset calculation)
- **Event Merging & Deduplication:** ~0.3s (thousands of comparisons)
- **Statistics Aggregation:** ~0.2s (pilot stats, event categorization)
- **Total:** ~1.3s per page load

### 2. Large HTML Payload
**Impact: MEDIUM-HIGH** | **Affects: Mobile networks, slower connections**

The generated HTML is 1.6MB uncompressed:

#### Payload Breakdown:
- **Mission Information Table:** ~5KB
- **Pilot Statistics Table:** ~200KB (one row per pilot with hidden detail rows)
- **Mission Events Timeline:** ~1.3MB (main culprit)
  - Each event has multiple table cells
  - Inline confidence tooltips with tier breakdowns
  - Source recording tooltips listing all contributing files
  - Repeated HTML structure for thousands of events

#### Example Event Row:
```html
<tr>
  <td>13:07:24</td>
  <td class="ptv_rowType"><img src="/public/categoryIcons/hit.gif" alt="" /></td>
  <td class="ptv_rowEnemies">Mirage F1 EE (Carol 2-3 | LigOluap) [Carol 2has been hit by...</td>
  <td class="eventsConfidence">
    <span title="95.0%% overall confidence | Tier A: 3, Tier B: 0, Tier C: 0 | Coalition evidence allies: 2, enemies: 1">95%</span>
  </td>
  <td class="eventsEvidence">
    <span title="SOTN GT2 Flight Log GM perspective • Tier A • 55.0%
Tacview-20251025-230708-DCS-Client • Tier A • 55.0%
Tacview-20251025-232536-DCS-Client • Tier A • 55.0%">3</span>
  </td>
</tr>
```

Each pilot can have 50+ events, with 20+ pilots = 1000+ rows.

### 3. CSS Animation Overhead
**Impact: LOW-MEDIUM** | **Affects: Weaker PCs, older mobile devices**

The Cold War Command Center theme (`public/tacview.css`) includes:
- CRT scanline animation (100vh translateY)
- Animated tactical grid background
- Phosphor glow pulse animation
- CRT flicker effect (0.15s infinite)
- Radar sweep animation

These animations run continuously and can impact:
- Battery life on mobile
- Frame rate on older GPUs
- Overall page responsiveness

### 4. DOM Size and Complexity
**Impact: MEDIUM** | **Affects: Initial page render, scrolling performance**

Large DOM with thousands of elements:
- Main statistics table with nested hidden rows
- Thousands of event rows with tooltips
- Multiple inline styles and classes
- Deep nesting (tables within tables)

## Proposed Solutions

### Solution A: Build-Time Pre-Processing (RECOMMENDED)
**Difficulty:** Medium | **Impact:** High | **Maintenance:** Low

#### Overview
Move EventGraphAggregator processing to build time, generating static HTML/JSON during deployment.

#### Implementation Plan:

1. **Create Pre-Processor Script** (`scripts/preprocess-debriefings.php`):
   ```php
   <?php
   // Load all XML files
   // Run EventGraphAggregator
   // Generate static HTML or JSON output
   // Save to public/debriefings/ directory
   ```

2. **Modify Build Process** (`package.json`):
   ```json
   {
     "scripts": {
       "build": "node scripts/fetch-core.js && php scripts/preprocess-debriefings.php",
       "prebuild": "echo 'Starting build process...'",
       "postbuild": "echo 'Build completed - debriefing data pre-processed'"
     }
   }
   ```

3. **Update Debriefing Pages**:
   - `debriefing.php`: Load pre-processed data instead of processing XML
   - `api/debriefing.php`: Same modification
   - Add cache invalidation mechanism

4. **Add Rebuild Trigger**:
   - Git hook to detect debriefings/*.xml changes
   - Manual rebuild script for new missions
   - CI/CD integration for automatic rebuilds

#### Benefits:
- **Page Load:** 1.3s → ~50ms (96% reduction)
- **Server Load:** Eliminated per-request processing
- **Scalability:** Same performance regardless of user count
- **Mobile Experience:** Near-instant load times

#### Drawbacks:
- Requires rebuild when XML files change
- Slightly more complex deployment workflow
- Static output may need cache busting

#### Estimated Implementation Time: 4-6 hours

---

### Solution B: Client-Side Lazy Loading
**Difficulty:** Medium-High | **Impact:** Medium | **Maintenance:** Medium

#### Overview
Load minimal HTML initially, fetch detailed data via AJAX as users interact.

#### Implementation Plan:

1. **Initial Page Load**:
   - Load mission info and pilot statistics table only
   - Show loading placeholders for event details
   - ~200KB instead of 1.6MB

2. **On-Demand Loading**:
   - When user expands pilot details: fetch events via AJAX
   - Cache responses in browser
   - Progressive enhancement with loading indicators

3. **API Endpoints**:
   ```
   /api/pilot-events.php?pilot=Carol%202-3
   /api/mission-timeline.php?page=1&limit=100
   ```

4. **Pagination**:
   - Break mission timeline into pages (100 events per page)
   - Infinite scroll or "Load More" button
   - Reduce initial DOM size

#### Benefits:
- **Initial Load:** 1.6MB → ~200KB (87.5% reduction)
- **Perceived Performance:** Faster time to interactive
- **Bandwidth Savings:** Only load data user views

#### Drawbacks:
- More complex JavaScript required
- Multiple HTTP requests
- Requires API endpoint development
- May feel less responsive on slow connections

#### Estimated Implementation Time: 8-12 hours

---

### Solution C: Optimize Payload & Caching
**Difficulty:** Low-Medium | **Impact:** Medium | **Maintenance:** Low

#### Overview
Reduce HTML size and leverage browser caching without changing architecture.

#### Implementation Plan:

1. **Compress Tooltips**:
   - Move verbose tooltips to separate JSON
   - Use data attributes with shorter IDs
   - Hydrate tooltips with JavaScript

2. **Enable HTTP Compression**:
   - Add `.htaccess` or server config for gzip/brotli
   - 1.6MB → ~200KB compressed (87.5% reduction)

3. **Implement Browser Caching**:
   - Add ETag headers based on XML file hashes
   - Cache static assets (CSS, icons) for 1 year
   - Cache HTML for reasonable duration

4. **Lazy Load Images**:
   - Use `loading="lazy"` on aircraft icons
   - Reduce initial image requests

5. **CSS Optimization**:
   - Add `prefers-reduced-motion` media queries
   - Disable animations on mobile/low-power devices
   - Use CSS containment for better rendering

#### Benefits:
- **Payload:** 1.6MB → ~200KB with compression (87.5% reduction)
- **Quick Wins:** Easy to implement
- **No Architecture Changes:** Minimal risk
- **Better Mobile Experience:** Respects user preferences

#### Drawbacks:
- Doesn't address server processing time
- Still processes XML on every request
- Limited impact on weak PCs

#### Estimated Implementation Time: 2-4 hours

---

### Solution D: Hybrid Approach (BEST OVERALL)
**Difficulty:** Medium-High | **Impact:** Very High | **Maintenance:** Low-Medium

#### Overview
Combine build-time pre-processing with payload optimization.

#### Implementation Plan:

1. **Implement Solution A** (build-time pre-processing)
2. **Add Solution C optimizations** (compression, caching, CSS)
3. **Optional:** Add lazy loading for very large missions

#### Benefits:
- **Page Load:** 1.3s → ~20ms (98.5% reduction)
- **Payload:** 1.6MB → ~100-150KB compressed
- **Mobile Experience:** Excellent
- **Server Load:** Minimal
- **Best of all worlds**

#### Estimated Implementation Time: 6-10 hours

---

## Recommendations

### Immediate Actions (Quick Wins - 2-4 hours)
1. ✅ **Enable HTTP Compression** (gzip/brotli)
   - Add to `.htaccess` or Vercel config
   - Expected: 87.5% payload reduction

2. ✅ **Add CSS Performance Optimizations**
   - Implement `prefers-reduced-motion`
   - Disable heavy animations on mobile
   - Expected: Better battery life, smoother scrolling

3. ✅ **Optimize Image Loading**
   - Add `loading="lazy"` to icons
   - Expected: Faster initial render

### Primary Solution (Medium Term - 6-10 hours)
✅ **Implement Build-Time Pre-Processing (Solution D - Hybrid)**
   - Create pre-processor script
   - Integrate into build pipeline
   - Add compression and caching
   - Expected: 98%+ performance improvement

### Future Enhancements (Optional)
- Consider lazy loading for missions with 1000+ events
- Implement pagination for timeline view
- Add progressive web app (PWA) features for offline access
- Consider WebAssembly for client-side processing

## Testing Plan

### Performance Benchmarks
Test on representative devices:
- **Desktop:** Modern PC (baseline)
- **Laptop:** 5-year-old laptop with integrated graphics
- **Mobile:** Mid-range Android phone (3-4 years old)
- **Mobile:** Budget smartphone with limited RAM
- **Network:** Throttled to 3G speeds

### Metrics to Track
- Time to First Byte (TTFB)
- First Contentful Paint (FCP)
- Time to Interactive (TTI)
- Total Blocking Time (TBT)
- Cumulative Layout Shift (CLS)
- Page load time (complete)
- Memory usage
- CPU utilization

### Success Criteria
- **Page Load:** < 1 second on desktop, < 2 seconds on mobile
- **Payload:** < 300KB compressed
- **Time to Interactive:** < 2 seconds on mobile
- **Scrolling:** 60 FPS on mid-range devices

## Implementation Priority

### Phase 1: Quick Wins (Week 1)
- [ ] Enable HTTP compression
- [ ] Add CSS performance optimizations
- [ ] Implement lazy image loading
- [ ] Test and measure improvements

### Phase 2: Build-Time Pre-Processing (Week 2-3)
- [ ] Create pre-processor script
- [ ] Integrate into build pipeline
- [ ] Update debriefing pages to use pre-processed data
- [ ] Add rebuild automation
- [ ] Test thoroughly

### Phase 3: Polish & Optimization (Week 4)
- [ ] Fine-tune compression settings
- [ ] Optimize cache headers
- [ ] Add performance monitoring
- [ ] Document new build process
- [ ] Update CHANGELOG.md

## Appendix

### File Locations
- **Main Aggregator:** `src/EventGraph/EventGraphAggregator.php` (1809 lines)
- **Debriefing Pages:** `debriefing.php`, `api/debriefing.php`, `public/debriefing.php`
- **Config:** `config.php`
- **CSS Theme:** `public/tacview.css`
- **XML Files:** `debriefings/*.xml` (4 files, ~1.5MB total)

### Related Documentation
- `planning/canonical-model-blueprint.md` - Original aggregator architecture
- `planning/event-graph-plan.md` - EventGraph approach documentation
- `CHANGELOG.md` - Recent EventGraph improvements (2025-11-01 through 2025-11-03)

### Configuration Options
Current EventGraph settings in `config.php`:
```php
'aggregator' => [
    'time_tolerance' => 1.5,              // Event merge window (seconds)
    'hit_backtrack_window' => 5.0,        // Weapon hit detection window
    'anchor_tolerance' => 120.0,          // Anchor match tolerance
    'anchor_min_matches' => 3,            // Minimum anchors to sync recordings
    'max_fallback_offset' => 900.0,       // Max offset for fallback sync
    'max_anchor_offset' => 14400.0,       // Max offset for anchor-based sync
    'mission_time_congruence_tolerance' => 1800.0, // Mission time tolerance
],
```

### Performance Profiling Notes
Test run with 4 XML files (2025-11-05):
```
Input: 4 XML files (~1.5MB total)
Processing time: 1.348s
Output: 1.6MB HTML
Peak memory: < 512MB

Breakdown estimate:
- XML parsing: ~0.3s (22%)
- Event graph construction: ~0.5s (37%)
- Event merging: ~0.3s (22%)
- Stats aggregation: ~0.2s (15%)
- HTML generation: ~0.05s (4%)
```

The EventGraphAggregator is sophisticated but expensive. Build-time pre-processing is the most impactful solution.
