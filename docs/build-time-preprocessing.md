# Build-Time Pre-Processing

## Overview

This feature dramatically improves performance for mobile and weaker PCs by moving the expensive EventGraphAggregator processing from runtime to build time.

## Performance Improvements

- **Page Load Time:** 1.3s → 46ms (97% reduction)
- **Server CPU:** Eliminated per-request XML processing
- **Scalability:** Same performance regardless of user count
- **Mobile Experience:** Near-instant load times

## How It Works

### 1. Pre-Processing Script
The `scripts/preprocess-debriefings.php` script:
- Loads all XML files from `debriefings/`
- Runs EventGraphAggregator at build time
- Generates static HTML output
- Saves metadata for cache invalidation

### 2. Optimized Debriefing Page
The `debriefing-optimized.php` page:
- Checks for pre-processed data
- If available: loads static HTML (fast path)
- If not available: falls back to runtime processing (original behavior)
- Shows performance status in debug mode

## Usage

### Building Pre-Processed Data

Run the build command:
```bash
npm run build
```

This executes:
1. `node scripts/fetch-core.js` - Fetches php-tacview-core
2. `php scripts/preprocess-debriefings.php` - Pre-processes debriefings

Or run the pre-processor directly:
```bash
php scripts/preprocess-debriefings.php
```

### Output Files

Pre-processed data is saved to:
- `public/debriefings/aggregated.html` - Rendered HTML (1.5MB)
- `public/debriefings/aggregated.json` - Metadata with cache info

### Deployment

#### Vercel
The build is automatically triggered on deployment. Add this to your Vercel project settings if not using the default build command:

```
Build Command: npm run build
Output Directory: public
```

#### Docker
Build the image with pre-processed data:

```bash
docker build -t tacview-analysis .
docker run -p 8000:8000 tacview-analysis
```

#### Traditional Hosting
1. Run `npm run build` locally
2. Upload all files including `public/debriefings/` to your server
3. Ensure `.htaccess` is uploaded for compression and caching

## Cache Invalidation

### When to Rebuild
Rebuild pre-processed data when:
- XML files in `debriefings/` are added, removed, or modified
- Configuration in `config.php` changes (aggregator settings)
- Core tacview library is updated

### Automatic Detection
The `aggregated.json` metadata includes:
- File hashes for each XML source
- Generation timestamp
- Processing metrics

You can check if rebuilding is needed by comparing XML file hashes.

### Manual Rebuild
```bash
php scripts/preprocess-debriefings.php
```

## Debug Mode

Enable debug information:
```
http://your-site.com/debriefing-optimized.php?debug=1
```

Or set in `config.php`:
```php
'show_status_overlay' => true,
```

Debug mode shows:
- Whether pre-processed data is being used
- Processing time (build time vs runtime)
- Source file information
- Performance metrics

## Switching Between Modes

### Using Optimized Mode (Recommended)
Replace `debriefing.php` with `debriefing-optimized.php`:

```bash
mv debriefing.php debriefing-original.php
mv debriefing-optimized.php debriefing.php
```

### Using Original Mode
Keep using `debriefing.php` for runtime processing.

The optimized version automatically falls back to runtime processing if pre-processed data is not available, so it's safe to switch.

## CI/CD Integration

### GitHub Actions Example
```yaml
name: Build and Deploy

on:
  push:
    branches: [main]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      
      - name: Setup Node
        uses: actions/setup-node@v3
        with:
          node-version: '18'
      
      - name: Build
        run: npm run build
      
      - name: Deploy
        # Your deployment step here
```

### Vercel Integration
Add to `vercel.json`:
```json
{
  "buildCommand": "npm run build"
}
```

## Troubleshooting

### Error: "No XML files found"
- Ensure XML files are in `debriefings/` directory
- Check file permissions
- Verify `config.php` has correct `debriefings_path`

### Pre-Processed Data Not Loading
- Check `public/debriefings/aggregated.html` exists
- Verify file permissions (readable by web server)
- Enable debug mode to see error messages

### Stale Data
- Rebuild after updating XML files
- Check file modification times in `aggregated.json`
- Consider automating rebuild with file watchers

### Memory Errors
- Increase PHP memory limit: `php -d memory_limit=512M scripts/preprocess-debriefings.php`
- Process fewer XML files at once
- Optimize EventGraph configuration in `config.php`

## Configuration

Adjust EventGraph settings in `config.php`:
```php
'aggregator' => [
    'time_tolerance' => 1.5,
    'hit_backtrack_window' => 5.0,
    'anchor_tolerance' => 120.0,
    'anchor_min_matches' => 3,
    'max_fallback_offset' => 900.0,
    'max_anchor_offset' => 14400.0,
    'mission_time_congruence_tolerance' => 1800.0,
],
```

These settings affect both runtime and build-time processing.

## Performance Metrics

### Before Optimization
- Page load: ~1.3s
- HTML payload: 1.6MB uncompressed
- Server processing: Every request
- Mobile experience: Slow, high battery drain

### After Optimization
- Page load: ~46ms (97% faster)
- HTML payload: ~200KB with compression (87.5% smaller)
- Server processing: None (pre-built)
- Mobile experience: Fast, minimal battery impact

## Future Enhancements

Potential improvements:
- [ ] Incremental rebuilds (only changed files)
- [ ] Multiple missions support
- [ ] API endpoint for checking stale data
- [ ] Webhook integration for automatic rebuilds
- [ ] Client-side lazy loading for very large missions
- [ ] Progressive web app (PWA) features

## Related Documentation

- `planning/performance-analysis-2025-11-05.md` - Performance investigation
- `CHANGELOG.md` - Implementation history
- `README.md` - Main project documentation
