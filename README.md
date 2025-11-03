# PHP Tacview Analysis For SOTN

A fork of PHP Tacview (by Ezor, Release Date: Mon, 13 Mar 2023) adapted for compatibility with the latest version of PHP and enhanced with additional functionality for Air Goons Wargame's Song of the Nibelungs.

PHP Tacview transforms your XML flight log into a visually understandable, interactive, detailed summary of your flight missions.

## Features

- Parse Tacview XML files and display mission events
- Chronological timeline of combat events
- Visual icons for different unit types and actions
- 10 languages available (English, German, Spanish, Finnish, French, Croatian, Italian, Portuguese, Russian, Ukrainian) (AI Generated localisations, please advise any changes required)
- PHP 8.4+ compatibility with modern error handling
- Works seamlessly in both local development and cloud serverless environments

## Requirements

- **PHP 8.4 or higher** (fully modernized for PHP 8.4+)
- Web server (Apache, Nginx, or PHP built-in server)

## Installation

1. Clone this repository
2. Place Tacview XML files in the `debriefings/` directory
3. Start a web server:
   ```bash
   php -S localhost:8000
   ```
4. Open your browser and navigate to `http://localhost:8000/debriefing.php`

## Usage

1. Export your flight log as XML from Tacview
2. Place the XML file in PHP Tacview's `debriefings/` folder
3. Access the web interface through your browser
4. The application will automatically detect and process available XML files
5. View detailed mission timelines with unit movements, weapons firing, and combat events

## File Structure

- `debriefing.php` - Main web interface
- `tacview.php` - Core XML processing class
- `tacview.css` - Stylesheet for the web interface
- `languages/` - Language localization files
- `categoryIcons/` - Icons for event categories
- `objectIcons/` - Icons for specific unit types
- `debriefings/` - Directory for Tacview XML files
- 
## Deployment

### Local Development
```bash
php -S localhost:8000
# Navigate to http://localhost:8000/debriefing.php
```

### Cloud Deployment (Vercel)
This project is configured for seamless deployment to Vercel:

1. **Automatic Deployment**: Connected to GitHub for automatic deployments
2. **Serverless Functions**: PHP functions run as serverless endpoints at `/api/debriefing`
3. **Static Assets**: CSS, icons, and data files served from `public/` directory
4. **Modern Configuration**: Uses latest Vercel best practices with `rewrites` instead of legacy `routes`

**Live Demo**: [Deploy to Vercel](https://vercel.com/new/clone?repository-url=https://github.com/Bigredgeek/PHP-Tacview-Analysis-For-SOTN)

## EventGraph Aggregator Configuration

- Tuning lives under the `aggregator` key inside `config.php`, `api/config.php`, `public/config.php`, and `public/api/config.php`.
- `time_tolerance` (seconds) controls how aggressively identical events from separate Tacview exports get merged.
- `hit_backtrack_window` (seconds) defines how far back the inference engine looks when linking destruction events to earlier hits.
- `anchor_tolerance` (seconds) determines the maximum delta allowed when auto-aligning recordings by matching anchor events (takeoffs, kills, etc.).
- `anchor_min_matches` is the number of matching anchors required before trusting the auto-aligned offset; falls back to file start-time differences otherwise.
- Adjust these floats if multi-pilot missions need looser or stricter attribution windows; leave them as-is to keep parity with the core defaults.
- The mission timeline now surfaces EventGraph confidence percentages and a source count badge; hover the badge to see which recordings corroborated each merged event.

## Project Structure

### For Local Development
- `debriefing.php` - Main web interface
- `tacview.php` - Core XML processing class  
- `tacview.css` - Stylesheet for the web interface

### For Cloud Deployment
- `api/` - Serverless function endpoints
  - `debriefing.php` - Main API endpoint for Vercel
- `public/` - Static assets directory
  - `tacview.css` - Stylesheet
  - `categoryIcons/` - Icons for event categories
  - `objectIcons/` - Icons for specific unit types
  - `languages/` - Language localization files
  - `debriefings/` - Directory for Tacview XML files

## Original Credits

Created by Ezor

## License

See License.txt for details.

## Changelog

### October 11, 2025 - Multilingual Expansion & PHP 8 Modernization
- Added 5 new language localizations:
  - German (de) - Professional military aviation terminology
  - Finnish (fi) - Complete tactical analysis translations
  - Portuguese (pt) - Brazilian/European Portuguese support
  - Russian (ru) - Full Cyrillic character support
  - Ukrainian (uk) - Modern Ukrainian military terminology
- All languages now available: English, German, Spanish, Finnish, French, Croatian, Italian, Portuguese, Russian, Ukrainian
- Usage: Add `?lang=XX` to URL (e.g., `/debriefing.php?lang=de` for German)
- Complete PHP 8.4 modernization:
  - Replaced all 33 deprecated `var` property declarations with `public` visibility modifiers
  - Updated PHP 4-style constructor `function tacview()` to modern `function __construct()`
  - Eliminated all PHP 4 legacy syntax patterns
  - Full compatibility with PHP 8.4+ strict standards
- Fixed Vercel deployment:
  - Created simple redirect `index.html` in public directory
  - Updated `vercel.json` routing configuration
  - Fixed "Invalid URL" error on root path
- Code quality improvements:
  - Removed all debug statements from production code
  - Synchronized root and public directory files
  - Verified no deprecated functions (ereg, mysql_*, split, etc.)
  - Confirmed modern PCRE regex usage throughout

### October 11, 2025 - Kill Attribution & Translation Fixes
- Fixed kill attribution system - Properly tracks weapon ownership for accurate pilot credit
- Improve language used to describe table headers - Updated English language file:
  - "PILOTNAME" → "Aircrew"
  - "FIREDARMEMENT" → "Weapons Fired"
- Fixed language loading - Resolved PHP 8.4 constructor issue preventing translations from loading
- Code cleanup - Removed obsolete test files and debug output

### October 11, 2025 - Aircraft-Only Statistics Filtering
- Enhanced pilot statistics filtering - Removed ground units from main statistics table
- Aircraft and helicopter focus - Table now shows only airborne units with pilot data
- Problem resolution - Eliminated ground units like '207MRD/2TA-8-3', 'Depot Guard-13-1', 'Olympus-20-3' from pilot statistics
- Dual filtering implementation:
  - Enhanced `sortStatsByGroupAndPilot()` function with `isset($stat["Aircraft"])` validation
  - Added safety check in table display loop to ensure only aircraft/helicopter entries

### October 11, 2025 - Smart Icon Mapping System & Table Sorting
- Added intelligent icon fallback system - Eliminates 404 errors for missing aircraft/vehicle icons
- Created getObjectIcon() function with comprehensive mapping table for missing icons:
  - `MiG-29_Fulcrum` → `MiG-29A_Fulcrum-A` (Similar MiG-29 variant)
  - `Humvee` → `HUMMER` (Same vehicle, different naming)
  - `leopard-2A4` → `LEOPARD2` (Same tank, different naming)
  - Plus mappings for all missing ground vehicles (BTR-80, T-72B, MTLB, etc.)
- Implemented multi-level table sorting for statistics display:
  - Primary Sort: GROUP (organizes pilots by tactical units/squadrons)
  - Secondary Sort: PILOT NAME (alphabetical within each group)
- Cross-platform compatibility - All enhancements work in both local and serverless environments

### October 11, 2025 - Vercel Configuration Optimization
- Updated to modern Vercel best practices per official documentation
- Replaced legacy `routes` with `rewrites` for better performance and support
- Added JSON schema reference for autocomplete and validation
- Specified `outputDirectory` for explicit static file location
- Corrected API endpoint paths to use clean URLs without .php extensions
- Fixed redirect URLs in index.html to match new API routing
- Enhanced package.json configuration with proper main field reference
- Validated all file paths and directory structure for deployment compatibility

### October 11, 2025 - Vercel Deployment Configuration
- Added Vercel deployment support with serverless PHP functions
- Created public directory structure for static asset serving
- Implemented API endpoints at `/api/debriefing` for serverless function handling
- Updated file paths to reference public directory for CSS, icons, and language files
- Configured vercel.json with proper routing for static files and API endpoints
- Added package.json with vercel-php runtime support
- Established proper directory structure required by Vercel platform:
  - `public/` - Contains all static assets (CSS, icons, language files, XML data)
  - `api/` - Contains serverless function endpoints
- Fixed deployment errors related to missing output directory
- Enhanced GitHub integration for automatic deployments via Vercel

### October 11, 2025 - PHP 8.4+ Compatibility
- Fixed deprecated XML function calls - Updated `xml_set_object()` usage for modern PHP
- Added proper property declarations - Fixed dynamic property creation warnings
- Modernized L() function - Updated language handling for current PHP standards
- Enhanced error handling - Added proper checks for undefined array keys
- Updated XML parser callbacks - Ensured compatibility with PHP 8.4+ XML handling

---
**Last Updated**: October 11, 2025  
**PHP Version**: 8.4+ Required  
**Languages**: 10 (en, de, es, fi, fr, hr, it, pt, ru, uk)  
**Status**: Production Ready - Fully PHP 8.4 Compatible
