#!/usr/bin/env node

/**
 * Node.js wrapper for PHP debriefing pre-processor
 * 
 * This script checks if PHP is available and runs the pre-processing script.
 * If PHP is not available (e.g., in Vercel's Node.js build environment),
 * it gracefully skips the pre-processing step.
 * 
 * The application will fall back to runtime processing via the vercel-php runtime.
 */

const { spawnSync } = require('node:child_process');
const path = require('node:path');

// Check if PHP is available
function isPhpAvailable() {
    const result = spawnSync('php', ['--version'], {
        stdio: 'pipe',
        encoding: 'utf8',
    });
    
    // If there's an error (e.g., ENOENT), PHP is not available
    if (result.error) {
        return false;
    }
    
    return result.status === 0;
}

// Main execution
const phpAvailable = isPhpAvailable();

if (!phpAvailable) {
    console.log('ℹ️  PHP not available in build environment - skipping pre-processing');
    console.log('ℹ️  Application will use runtime processing via Vercel PHP runtime');
    process.exit(0);
}

console.log('✓ PHP detected - running debriefing pre-processor...');

const phpScript = path.join(__dirname, 'preprocess-debriefings.php');
const result = spawnSync('php', [phpScript], {
    stdio: 'inherit',
});

if (result.error) {
    console.error(`Failed to execute PHP: ${result.error.message}`);
    process.exit(1);
}

if (result.status !== 0) {
    console.error(`PHP pre-processor exited with status ${result.status}`);
    process.exit(result.status);
}

console.log('✓ Debriefing pre-processing completed successfully');
