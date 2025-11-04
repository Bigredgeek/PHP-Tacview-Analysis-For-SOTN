#!/usr/bin/env node

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const targetDir = path.join(root, 'php-tacview-core');
const fallbackLocalCore = path.join(root, 'core');

if (fs.existsSync(targetDir) || fs.existsSync(fallbackLocalCore)) {
    console.log('Tacview core assets already present locally; skipping download.');
    process.exit(0);
}

const repoUrl = process.env.TACVIEW_CORE_GIT_URL || 'https://github.com/Bigredgeek/php-tacview-core.git';
const repoRef = process.env.TACVIEW_CORE_GIT_REF || 'main';

console.log(`Cloning ${repoUrl} (${repoRef}) into ${targetDir}...`);

const result = spawnSync('git', ['clone', '--depth', '1', '--branch', repoRef, repoUrl, targetDir], {
    stdio: 'inherit',
});

if (result.error) {
    console.error(`Failed to execute git: ${result.error.message}`);
    process.exit(1);
}

if (typeof result.status === 'number' && result.status !== 0) {
    console.error(`git clone exited with status ${result.status}`);
    process.exit(result.status);
}

const gitDir = path.join(targetDir, '.git');
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

console.log('php-tacview-core installed.');
