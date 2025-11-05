#!/usr/bin/env node

/**
 * Node.js wrapper for PHP debriefing pre-processor
 * 
 * This script checks if PHP is available and runs the pre-processing script.
 * It first attempts to use a custom-installed PHP (from install-php.js),
 * then falls back to system PHP if available.
 */

const { spawnSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');

// Check if PHP is available (either in PATH or custom location)
function findPhp() {
    // First, check if PHP is in PATH
    const systemPhp = spawnSync('php', ['--version'], {
        stdio: 'pipe',
        encoding: 'utf8',
    });
    
    if (!systemPhp.error && systemPhp.status === 0) {
        return { command: 'php', version: systemPhp.stdout.split('\n')[0] };
    }
    
    // Check for custom-installed PHP
    const phpDir = path.join(process.env.HOME || '/tmp', '.php-static');
    const customPhp = path.join(phpDir, 'bin', 'php');
    
    if (fs.existsSync(customPhp)) {
        const customPhpCheck = spawnSync(customPhp, ['--version'], {
            stdio: 'pipe',
            encoding: 'utf8',
        });
        
        if (!customPhpCheck.error && customPhpCheck.status === 0) {
            // Update PATH to include custom PHP
            process.env.PATH = `${path.dirname(customPhp)}:${process.env.PATH}`;
            return { command: customPhp, version: customPhpCheck.stdout.split('\n')[0] };
        }
    }
    
    return null;
}

// Main execution
const php = findPhp();

if (!php) {
    console.log('ℹ️  PHP not available - skipping pre-processing');
    console.log('ℹ️  Run "node scripts/install-php.js" to install PHP for build-time optimization');
    console.log('ℹ️  Application will use runtime processing');
    process.exit(0);
}

console.log(`✓ PHP found: ${php.version}`);
console.log('  Running debriefing pre-processor...');

const phpScript = path.join(__dirname, 'preprocess-debriefings.php');
const result = spawnSync(php.command, [phpScript], {
    stdio: 'inherit',
    env: process.env,
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

