#!/usr/bin/env node

/**
 * Install PHP for Vercel build environment
 * Downloads a static/portable PHP binary that works without root access
 */

const { spawnSync } = require('node:child_process');
const https = require('node:https');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { pipeline } = require('node:stream');
const { promisify } = require('node:util');
const { createWriteStream } = fs;

const pipelineAsync = promisify(pipeline);

// Check if PHP is already available
function isPhpAvailable() {
    const result = spawnSync('php', ['--version'], {
        stdio: 'pipe',
        encoding: 'utf8',
    });
    
    if (result.error) {
        return false;
    }
    
    return result.status === 0;
}

async function downloadFile(url, destPath) {
    return new Promise((resolve, reject) => {
        const client = url.startsWith('https:') ? https : http;
        
        client.get(url, (response) => {
            if (response.statusCode === 301 || response.statusCode === 302) {
                // Follow redirect
                return downloadFile(response.headers.location, destPath)
                    .then(resolve)
                    .catch(reject);
            }
            
            if (response.statusCode !== 200) {
                reject(new Error(`Failed to download: HTTP ${response.statusCode}`));
                return;
            }
            
            const file = createWriteStream(destPath);
            response.pipe(file);
            
            file.on('finish', () => {
                file.close();
                resolve();
            });
            
            file.on('error', (err) => {
                fs.unlink(destPath, () => {});
                reject(err);
            });
        }).on('error', (err) => {
            reject(err);
        });
    });
}

async function installPhp() {
    console.log('Setting up PHP for build-time pre-processing...');
    
    if (isPhpAvailable()) {
        const result = spawnSync('php', ['--version'], {
            stdio: 'pipe',
            encoding: 'utf8',
        });
        console.log(`✓ PHP already available: ${result.stdout.split('\n')[0]}`);
        return true;
    }
    
    const platform = process.platform;
    const arch = process.arch;
    
    console.log(`Platform: ${platform} ${arch}`);
    
    if (platform !== 'linux') {
        console.log('⚠ Non-Linux platform, skipping PHP installation');
        return false;
    }
    
    // Determine architecture
    let phpArch;
    if (arch === 'x64') {
        phpArch = 'x86_64';
    } else if (arch === 'arm64') {
        phpArch = 'aarch64';
    } else {
        console.log(`⚠ Unsupported architecture: ${arch}`);
        return false;
    }
    
    const phpDir = path.join(process.env.HOME || '/tmp', '.php-static');
    fs.mkdirSync(phpDir, { recursive: true });
    
    const phpBinary = path.join(phpDir, 'php');
    
    // Download static PHP binary from static-php-cli project
    // This provides truly static PHP binaries that work anywhere
    const downloadUrl = `https://dl.static-php.dev/static-php-cli/common/php-8.2-cli-linux-${phpArch}.tar.gz`;
    const archivePath = path.join(phpDir, 'php.tar.gz');
    
    try {
        console.log(`Downloading static PHP binary from ${downloadUrl}...`);
        await downloadFile(downloadUrl, archivePath);
        
        console.log('Extracting PHP...');
        const extractResult = spawnSync('tar', ['-xzf', archivePath, '-C', phpDir], {
            stdio: 'inherit',
        });
        
        if (extractResult.status !== 0) {
            throw new Error('Failed to extract PHP archive');
        }
        
        // The extracted binary should be in phpDir
        // Find the PHP binary
        const files = fs.readdirSync(phpDir);
        const phpFile = files.find(f => f === 'php' || f.startsWith('php'));
        
        if (!phpFile) {
            throw new Error('PHP binary not found after extraction');
        }
        
        const extractedPhp = path.join(phpDir, phpFile);
        
        // Make it executable
        fs.chmodSync(extractedPhp, 0o755);
        
        // Create a symlink or update PATH
        const binDir = path.join(phpDir, 'bin');
        fs.mkdirSync(binDir, { recursive: true });
        
        const phpLink = path.join(binDir, 'php');
        if (fs.existsSync(phpLink)) {
            fs.unlinkSync(phpLink);
        }
        
        // Copy instead of symlink for better compatibility
        fs.copyFileSync(extractedPhp, phpLink);
        fs.chmodSync(phpLink, 0o755);
        
        // Update PATH
        process.env.PATH = `${binDir}:${process.env.PATH}`;
        
        // Write PATH to a file so other processes can use it
        const pathFile = path.join(phpDir, 'path.txt');
        fs.writeFileSync(pathFile, binDir);
        
        // Verify
        const verifyResult = spawnSync(phpLink, ['--version'], {
            stdio: 'pipe',
            encoding: 'utf8',
        });
        
        if (verifyResult.status === 0) {
            console.log(`✓ PHP installed successfully: ${verifyResult.stdout.split('\n')[0]}`);
            console.log(`  Binary location: ${phpLink}`);
            console.log(`  Add to PATH: export PATH="${binDir}:$PATH"`);
            return true;
        } else {
            throw new Error('PHP verification failed');
        }
        
    } catch (error) {
        console.error('✗ Failed to install PHP:', error.message);
        console.log('  Pre-processing will be skipped');
        return false;
    }
}

// Main execution
installPhp()
    .then((success) => {
        process.exit(success ? 0 : 0); // Always exit 0 to not fail the build
    })
    .catch((err) => {
        console.error('Error during PHP installation:', err);
        process.exit(0); // Don't fail the build
    });
