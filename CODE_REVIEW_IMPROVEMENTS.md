# Code Review Improvements Plan
**Date:** November 8, 2025  
**Repository:** php-tacview-sotn  
**Branch:** feature/event-graph-model  
**Reviewers:** GPT-5 Codex, GitHub Copilot

## Executive Summary

Comprehensive code review of commits `9a6d463..6e0bd95` revealed **1 critical bug**, **3 medium-severity issues**, and **2 low-priority improvements** in the recently added core-fetching scripts and event normalization logic. The most critical issue is a cross-device filesystem rename failure that will break deployments on Vercel and most CI/CD platforms.

---

## Critical Issues

### 1. Cross-Device Rename Failure in `fetch-core.php` ⚠️ BLOCKER

**File:** `scripts/fetch-core.php`  
**Lines:** 97-100  
**Severity:** Critical - Blocks production deployments  
**Commits:** `9a6d463`

#### Problem Description

```php
if (!rename($extractedRoot, $targetDir)) {
    fwrite(STDERR, "Failed to move extracted core into place at {$targetDir}." . PHP_EOL);
    return 1;
}
```

The script attempts to use `rename()` to move the extracted `php-tacview-core` directory from a temporary location (typically `/tmp` or `C:\Windows\Temp`) to the project workspace. On most CI/CD platforms (Vercel, GitHub Actions, GitLab CI), these directories exist on different filesystems, causing `rename()` to fail with `EXDEV: Invalid cross-device link`.

#### Impact

- **Vercel deployments:** Build fails, no core assets loaded, site returns 500 errors
- **Docker builds:** May work locally but fail in multi-stage builds with separate volumes
- **GitHub Actions:** Fails when runner uses separate temp volumes

#### Root Cause Analysis

PHP's `rename()` function is essentially a wrapper around the POSIX `rename()` system call, which only works within the same filesystem. When source and destination are on different filesystems, the operation must be:
1. Recursively copy source → destination
2. Delete source

#### Proposed Solution

Replace the single `rename()` call with a cross-device-safe move operation:

```php
// Attempt atomic rename first (fast path for same-filesystem)
if (!@rename($extractedRoot, $targetDir)) {
    // Cross-device or permission issue - fall back to copy+delete
    if (!recursiveCopy($extractedRoot, $targetDir)) {
        fwrite(STDERR, "Failed to copy extracted core into place at {$targetDir}." . PHP_EOL);
        return 1;
    }
    
    // Clean up source after successful copy
    $removeDir($extractedRoot);
}

// Helper function (add before final cleanup)
function recursiveCopy(string $source, string $destination): bool {
    if (!is_dir($source)) {
        return false;
    }
    
    if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
        return false;
    }
    
    $dir = opendir($source);
    if ($dir === false) {
        return false;
    }
    
    while (($entry = readdir($dir)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        
        $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
        $destPath = $destination . DIRECTORY_SEPARATOR . $entry;
        
        if (is_dir($sourcePath)) {
            if (!recursiveCopy($sourcePath, $destPath)) {
                closedir($dir);
                return false;
            }
        } else {
            if (!copy($sourcePath, $destPath)) {
                closedir($dir);
                return false;
            }
        }
    }
    
    closedir($dir);
    return true;
}
```

#### Testing Strategy

1. **Unit test - Same filesystem:** Verify fast path still works
   ```bash
   php scripts/fetch-core.php
   ```

2. **Integration test - Cross-device:** Force cross-device scenario
   ```php
   // In test script, mock sys_get_temp_dir() to return path on different volume
   ```

3. **Platform tests:**
   - Local Windows development (different drives)
   - Vercel build preview
   - Docker multi-stage build
   - GitHub Actions workflow

#### Verification Checklist

- [ ] Script completes successfully on Windows (C: → D: volumes)
- [ ] Script completes successfully on Linux (tmpfs → ext4)
- [ ] Vercel deployment build passes
- [ ] `php-tacview-core/` directory contains all expected files
- [ ] No leftover temp directories after successful install
- [ ] Error messages clearly indicate copy vs. rename failure

---

## Medium Severity Issues

### 2. Suppressed Errors and Missing SSL Verification in `fetch-core.php`

**File:** `scripts/fetch-core.php`  
**Lines:** 33-46  
**Severity:** Medium - Security and debuggability concerns  
**Commits:** `9a6d463`

#### Problems Identified

**A. Error Suppression (Line 42)**
```php
$downloaded = @file_get_contents($archiveUrl, false, $context);
```

The `@` operator suppresses ALL error messages, making debugging network failures impossible. Developers won't see:
- DNS resolution failures
- SSL certificate errors
- HTTP 404/500 responses
- Timeout errors

**B. Missing SSL Verification (Lines 35-38)**
```php
$context = stream_context_create([
    'http' => [
        'timeout' => 60,
    ],
]);
```

The stream context lacks SSL verification options, making the download vulnerable to man-in-the-middle attacks. An attacker on the network could inject malicious code.

**C. No Content Verification**

Downloaded ZIP file has no checksum or signature verification - if GitHub is compromised or DNS is poisoned, malicious code could be executed.

#### Proposed Solutions

**A. Remove Error Suppression**
```php
// Remove @ and let errors surface naturally
$downloaded = file_get_contents($archiveUrl, false, $context);
if ($downloaded === false) {
    $error = error_get_last();
    fwrite(STDERR, "Unable to download core archive from {$archiveUrl}." . PHP_EOL);
    if ($error !== null) {
        fwrite(STDERR, "Error: {$error['message']}" . PHP_EOL);
    }
    return 1;
}
```

**B. Add SSL Verification**
```php
$context = stream_context_create([
    'http' => [
        'timeout' => 60,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
    ],
]);
```

**C. Optional: Add Checksum Verification**
```php
// After download, verify against known good checksum
// (requires maintaining checksums in repo or fetching from GitHub releases)
$expectedHash = getenv('TACVIEW_CORE_ARCHIVE_SHA256');
if ($expectedHash !== false && trim($expectedHash) !== '') {
    $actualHash = hash('sha256', $downloaded);
    if (!hash_equals($expectedHash, $actualHash)) {
        fwrite(STDERR, "Archive checksum mismatch - expected {$expectedHash}, got {$actualHash}" . PHP_EOL);
        return 1;
    }
}
```

#### Testing Strategy

1. Test successful download with proper SSL
2. Test with invalid certificate (should fail)
3. Test with unreachable URL (should show clear error)
4. Test with network timeout

---

### 3. Missing Git Dependency Check in `fetch-core.js`

**File:** `scripts/fetch-core.js`  
**Lines:** 22-32  
**Severity:** Medium - Build failures without clear diagnosis  
**Commits:** `00a159c`

#### Problem Description

```javascript
const result = spawnSync('git', ['clone', '--depth', '1', '--branch', repoRef, repoUrl, targetDir], {
    stdio: 'inherit',
});
```

The script assumes `git` is available in the system PATH. If git is not installed or not in PATH (common on minimal Docker images or Windows environments), `spawnSync` will fail with a cryptic error.

**Current behavior:**
```
Failed to execute git: spawn git ENOENT
```

Users won't immediately understand they need to install git.

#### Proposed Solution

Add explicit git availability check with helpful error message:

```javascript
#!/usr/bin/env node

const { spawnSync, execSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

// Check git availability first
function checkGitAvailable() {
    try {
        execSync('git --version', { stdio: 'pipe' });
        return true;
    } catch (error) {
        return false;
    }
}

const root = path.resolve(__dirname, '..');
const targetDir = path.join(root, 'php-tacview-core');
const fallbackLocalCore = path.join(root, 'core');

if (fs.existsSync(targetDir) || fs.existsSync(fallbackLocalCore)) {
    console.log('Tacview core assets already present locally; skipping download.');
    process.exit(0);
}

// Verify git is available
if (!checkGitAvailable()) {
    console.error('ERROR: git is not installed or not in PATH.');
    console.error('');
    console.error('Please install git:');
    console.error('  • Windows: https://git-scm.com/download/win');
    console.error('  • macOS: brew install git');
    console.error('  • Linux: apt-get install git OR yum install git');
    console.error('');
    console.error('Alternatively, manually clone php-tacview-core:');
    console.error(`  git clone https://github.com/Bigredgeek/php-tacview-core.git ${targetDir}`);
    process.exit(1);
}

const repoUrl = process.env.TACVIEW_CORE_GIT_URL || 'https://github.com/Bigredgeek/php-tacview-core.git';
const repoRef = process.env.TACVIEW_CORE_GIT_REF || 'main';

console.log(`Cloning ${repoUrl} (${repoRef}) into ${targetDir}...`);

// ... rest of script
```

#### Additional Improvement: Keep .git for Debugging

Currently, the script removes the `.git` directory:

```javascript
if (fs.existsSync(gitDir)) {
    try {
        if (fs.rmSync) {
            fs.rmSync(gitDir, { recursive: true, force: true });
        } else {
            fs.rmdirSync(gitDir, { recursive: true });
        }
    } catch (error) {
        console.warn(`Warning: unable to remove ${gitDir}: ${error.message}`);
    }
}
```

**Recommendation:** Keep `.git` directory to preserve commit information for debugging. Add `.git` to `.gitignore` instead:

```bash
# In .gitignore
php-tacview-core/.git/
```

This allows developers to check which version was fetched:
```bash
cd php-tacview-core && git rev-parse HEAD
```

---

### 4. Silent Fallback in `fetch-core.php` Temp File Rename

**File:** `scripts/fetch-core.php`  
**Lines:** 26-29  
**Severity:** Medium - Silent failures complicate debugging  
**Commits:** `9a6d463`

#### Problem Description

```php
$zipPath = $tmpZip . '.zip';
if (!rename($tmpZip, $zipPath)) {
    $zipPath = $tmpZip; // fall back to original temp file without extension
}
```

If the rename to add `.zip` extension fails, the script silently falls back to using the extensionless temp file. This can cause issues with:
- ZipArchive expecting `.zip` extension
- File type detection utilities
- Debugging (developer doesn't know rename failed)

#### Proposed Solution

Log the fallback:

```php
$zipPath = $tmpZip . '.zip';
if (!rename($tmpZip, $zipPath)) {
    fwrite(STDERR, "Warning: Could not rename temp file to {$zipPath}, using {$tmpZip}" . PHP_EOL);
    $zipPath = $tmpZip; // fall back to original temp file without extension
}
```

Or fail fast if the rename is critical:

```php
$zipPath = $tmpZip . '.zip';
if (!rename($tmpZip, $zipPath)) {
    fwrite(STDERR, "Failed to prepare temporary ZIP file at {$zipPath}." . PHP_EOL);
    @unlink($tmpZip);
    return 1;
}
```

---

## Low Priority Issues

### 5. Empty Events Array Edge Case in `getMinimumEventMissionTime()`

**File:** `src/EventGraph/EventGraphAggregator.php`  
**Lines:** 1740-1749  
**Severity:** Low - Works correctly but relies on implicit behavior  
**Commits:** `94b8d08`

#### Problem Description

```php
private function getMinimumEventMissionTime(): float
{
    $minimum = 0.0;

    foreach ($this->events as $event) {
        $minimum = min($minimum, $event->getMissionTime());
    }

    return $minimum;
}
```

If `$this->events` is empty, the function returns `0.0` by default. This works correctly because:
1. The calling function `applyStartTimeConsensus()` has an early return if `$this->startTimeSamples === []`
2. Event array is only populated after recordings are ingested

However, this relies on implicit understanding of call order and state.

#### Proposed Solution (Optional)

Make the empty case explicit for clarity:

```php
private function getMinimumEventMissionTime(): float
{
    if ($this->events === []) {
        return 0.0;
    }

    $minimum = 0.0;

    foreach ($this->events as $event) {
        $minimum = min($minimum, $event->getMissionTime());
    }

    return $minimum;
}
```

Or use array functions for clarity:

```php
private function getMinimumEventMissionTime(): float
{
    if ($this->events === []) {
        return 0.0;
    }

    $times = array_map(
        static fn(NormalizedEvent $event): float => $event->getMissionTime(),
        $this->events
    );

    return min(0.0, min($times));
}
```

---

### 6. Non-Idiomatic Return Codes in `fetch-core.php`

**File:** `scripts/fetch-core.php`  
**Throughout**  
**Severity:** Low - Cosmetic/style issue  
**Commits:** `9a6d463`

#### Problem Description

The script uses `return 0;` and `return 1;` at the top level without an explicit function wrapper. While this works (PHP will use these as exit codes), it's not immediately clear to readers that these are exit codes rather than function returns.

```php
if (is_dir($targetDir) || is_dir($fallbackLocalCore)) {
    fwrite(STDOUT, "Tacview core assets already present locally; skipping download." . PHP_EOL);
    return 0;  // <- Looks like function return but is actually exit code
}
```

#### Proposed Solution

Use explicit `exit()` calls for clarity:

```php
if (is_dir($targetDir) || is_dir($fallbackLocalCore)) {
    fwrite(STDOUT, "Tacview core assets already present locally; skipping download." . PHP_EOL);
    exit(0);
}
```

Or wrap in a main function:

```php
<?php

declare(strict_types=1);

function main(): int {
    $root = dirname(__DIR__);
    // ... all the logic
    return 0;
}

exit(main());
```

---

## Documentation Improvements

### 7. Add "Why" Context to Negative Event Time Fix

**File:** `CHANGELOG.md`  
**Lines:** ~10-12  
**Severity:** Low - Documentation gap  
**Commits:** `94b8d08`

#### Problem Description

The changelog entry documents WHAT was fixed but not WHY:

```markdown
### Fixed - 2025-11-03
- Rebased aggregated event mission clocks to start at the consensus mission time so timeline rows follow the master time sync instead of the earliest outlier recording, fixing 09:52Z entries under an 11:14Z Mission Information header.
```

Future developers won't understand:
- What causes events to have negative mission times?
- Why is this check necessary?
- What real-world scenario triggers this?

#### Proposed Addition

Add context to the changelog or inline code comments:

**In CHANGELOG.md:**
```markdown
### Fixed - 2025-11-04
- Normalized negative mission times that can occur when Tacview recordings use different reference points for MissionTime headers. The aggregator now detects when merged events dip below T+0 and shifts the entire timeline forward so all events have positive timestamps relative to the consensus mission start. This fixes cases where recordings with misaligned clocks (e.g., one recording starting at 08:05, another at 11:14) would produce negative event times after offset alignment.
```

**In code (EventGraphAggregator.php):**
```php
private function applyStartTimeConsensus(): void
{
    if ($this->startTimeSamples === []) {
        return;
    }

    $consensus = $this->computeCongruentStartTime();
    if ($consensus !== null) {
        $this->startTime = $consensus;
    } elseif ($this->startTime === null) {
        $this->startTime = min($this->startTimeSamples);
    }

    // After aligning recordings with different offsets, some events may end up with
    // negative mission times if a recording started significantly after the consensus
    // mission start. Shift the entire timeline forward to normalize to T+0.
    $minimumEventTime = $this->getMinimumEventMissionTime();
    if ($minimumEventTime < 0.0) {
        $this->shiftAllEvents(-$minimumEventTime);
    }
}
```

---

## Implementation Plan

### Phase 1: Critical Fixes (Pre-Deployment) ⚠️

**Priority:** IMMEDIATE  
**Target:** Before next Vercel deployment

1. **Fix cross-device rename in `fetch-core.php`**
   - [ ] Implement `recursiveCopy()` function
   - [ ] Update rename logic with fallback
   - [ ] Test on Windows (different drives)
   - [ ] Test on Linux (tmpfs → ext4)
   - [ ] Update `CHANGELOG.md`

2. **Test deployment pipeline**
   - [ ] Trigger Vercel preview deployment
   - [ ] Verify core assets loaded successfully
   - [ ] Check build logs for warnings

### Phase 2: Security & Reliability (Week 1)

**Priority:** HIGH  
**Target:** Within 7 days

1. **Harden `fetch-core.php` security**
   - [ ] Remove `@` error suppression
   - [ ] Add SSL verification
   - [ ] Improve error messages
   - [ ] Test with invalid URLs/certs

2. **Improve `fetch-core.js` reliability**
   - [ ] Add git availability check
   - [ ] Improve error messages
   - [ ] Consider keeping `.git` directory
   - [ ] Update `.gitignore`

3. **Update documentation**
   - [ ] Add installation troubleshooting to README
   - [ ] Document git requirement
   - [ ] Add SSL cert troubleshooting

### Phase 3: Code Quality (Week 2)

**Priority:** MEDIUM  
**Target:** Within 14 days

1. **Refactor `fetch-core.php`**
   - [ ] Replace `return` with `exit()`
   - [ ] Add warning for temp file rename fallback
   - [ ] Consider wrapping in main function

2. **Improve EventGraphAggregator clarity**
   - [ ] Add explicit empty check in `getMinimumEventMissionTime()`
   - [ ] Add inline comments for time normalization
   - [ ] Update changelog with "why" context

3. **Add tests**
   - [ ] Unit tests for `recursiveCopy()`
   - [ ] Integration test for fetch-core scripts
   - [ ] Mock cross-device scenarios

### Phase 4: Future Enhancements (Backlog)

**Priority:** LOW  
**Target:** Future sprint

1. **Add checksum verification**
   - Research GitHub Actions workflow for publishing checksums
   - Implement SHA256 verification
   - Document checksum update process

2. **Consider consolidating fetch scripts**
   - Evaluate keeping only Node.js version
   - Or add PHP as fallback when Node unavailable
   - Reduce maintenance burden

---

## Testing Checklist

### Pre-Deployment Tests (Critical)

- [ ] **Windows cross-drive test**
  ```powershell
  # Force temp to different drive
  $env:TMP = "D:\temp"
  php scripts/fetch-core.php
  ```

- [ ] **Linux cross-filesystem test**
  ```bash
  # Mount tmpfs and test
  sudo mount -t tmpfs tmpfs /tmp/test
  TMPDIR=/tmp/test php scripts/fetch-core.php
  ```

- [ ] **Vercel preview deployment**
  - Push to feature branch
  - Check preview build logs
  - Verify `/debriefing.php` loads without errors

- [ ] **Docker multi-stage build**
  ```dockerfile
  FROM php:8.2-cli AS builder
  COPY scripts/fetch-core.php .
  RUN php fetch-core.php
  
  FROM php:8.2-apache
  COPY --from=builder /app/php-tacview-core /var/www/html/php-tacview-core
  ```

### Security Tests (High Priority)

- [ ] **SSL verification test**
  ```bash
  # Should fail with invalid cert
  TACVIEW_CORE_ARCHIVE_URL=https://self-signed.badssl.com/download.zip \
    php scripts/fetch-core.php
  ```

- [ ] **Network error handling**
  ```bash
  # Should show clear error message
  TACVIEW_CORE_ARCHIVE_URL=https://invalid.domain.example/file.zip \
    php scripts/fetch-core.php
  ```

### Regression Tests (Medium Priority)

- [ ] **Normal operation (happy path)**
  ```bash
  rm -rf php-tacview-core
  php scripts/fetch-core.php
  test -d php-tacview-core/languages
  ```

- [ ] **Already-exists skip**
  ```bash
  # Second run should skip download
  php scripts/fetch-core.php | grep "already present"
  ```

- [ ] **Node.js version**
  ```bash
  rm -rf php-tacview-core
  npm run build
  test -d php-tacview-core/categoryIcons
  ```

---

## Code References

### Files to Modify

1. `scripts/fetch-core.php` - Primary fixes
2. `scripts/fetch-core.js` - Git check improvements
3. `src/EventGraph/EventGraphAggregator.php` - Optional clarity improvements
4. `CHANGELOG.md` - Documentation updates
5. `.gitignore` - Add `php-tacview-core/.git/` if keeping git dir

### Related Files (No Changes Needed)

- `package.json` - Build script already wired correctly
- `vercel.json` - Deployment config working as designed
- `src/EventGraph/NormalizedEvent.php` - `shiftMissionTime()` working correctly

---

## Success Criteria

### Must Have (Blocking Release)
✅ Vercel deployment completes successfully  
✅ `php-tacview-core/` directory populated with all assets  
✅ No temp directory cleanup failures  
✅ Clear error messages for all failure modes  

### Should Have (High Value)
✅ SSL verification enabled  
✅ Git availability check with helpful message  
✅ Error suppression removed  
✅ Cross-device copy tested on 3+ platforms  

### Nice to Have (Quality of Life)
✅ Inline code comments explaining time normalization  
✅ Explicit empty array checks  
✅ Idiomatic exit codes  
✅ Checksum verification (future)  

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Cross-device rename breaks production | **HIGH** | **CRITICAL** | Implement recursive copy fallback immediately |
| SSL MITM attack during core fetch | Low | High | Enable SSL verification |
| Missing git breaks CI builds | Medium | Medium | Add clear error message with install instructions |
| Regression in time normalization | Low | High | Add unit tests for edge cases |
| Performance degradation from recursive copy | Low | Low | Profile on large repos; likely negligible for ~5MB core |

---

## Questions for Code Review

1. **Deployment Strategy:** Should we merge fixes to `main` or deploy via feature branch first?
2. **Git Directory:** Keep or remove `.git` in cloned core? (Tradeoff: size vs. debuggability)
3. **Checksum Verification:** Is this overkill for a GitHub-hosted repo, or worth the security?
4. **Script Consolidation:** Should we maintain both PHP and Node.js fetchers long-term?
5. **Error Verbosity:** Current proposal adds verbose errors - is this too noisy for CI logs?

---

## Additional Resources

### Testing Environments

- **Local Windows:** C: drive (SSD) + D: drive (HDD)
- **Local Linux:** ext4 root + tmpfs /tmp
- **Vercel:** Preview deployment from feature branch
- **Docker Desktop:** Multi-stage build test

### Reference Documentation

- PHP `rename()` cross-device issue: https://www.php.net/manual/en/function.rename.php#refsect1-function.rename-notes
- PHP ZipArchive: https://www.php.net/manual/en/class.ziparchive.php
- Node.js spawnSync: https://nodejs.org/api/child_process.html#child_processspawnsynccommand-args-options
- Vercel build process: https://vercel.com/docs/concepts/deployments/build-step

---

## Changelog Entry Template

```markdown
### Fixed - 2025-11-08
- Fixed cross-device filesystem rename failure in `scripts/fetch-core.php` that blocked Vercel deployments; the script now falls back to recursive copy when temp directory is on a different volume than the project workspace.
- Removed error suppression and added SSL certificate verification to core download operation, preventing silent failures and MITM attacks during automated builds.
- Added git availability check to `scripts/fetch-core.js` with installation instructions, improving error messages when git is missing from CI environments.

### Changed - 2025-11-08
- Enhanced error logging throughout fetch-core scripts to surface network issues, permission errors, and filesystem constraints during troubleshooting.
- Updated EventGraphAggregator time normalization with inline comments explaining how negative mission times occur when recordings use misaligned reference points.
```

---

**End of Review Plan**
