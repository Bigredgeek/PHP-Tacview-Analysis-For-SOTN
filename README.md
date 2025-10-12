# PHP Tacview Analysis For SOTN

A fork of PHP Tacview (by Ezor, Release Date: Mon, 13 Mar 2023) adapted for compatibility with the latest version of PHP and enhanced with additional functionality for Air Goons Wargame's Song of the Nibelungs.

PHP Tacview transforms your XML flight log into a visually understandable, interactive, detailed summary of your flight missions.

## Features

- Parse Tacview XML files and display mission events
- Chronological timeline of combat events
- Visual icons for different unit types and actions
- **Smart icon mapping system** - Automatically handles missing aircraft/vehicle icons with intelligent fallbacks
- **Multi-level table sorting** - Organizes pilots by GROUP first, then alphabetically by name within groups
- **Professional tactical analysis format** - Military-style reporting optimized for squadron operations
- Web-based interface with responsive design
- Multi-language support (English, Spanish, French, Croatian, Italian)
- PHP 8.4+ compatibility with modern error handling
- **Dual deployment support** - Works seamlessly in both local development and cloud serverless environments

## Requirements

- PHP 8.0 or higher
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

## Recent Updates

- **🎯 MAJOR: Smart Icon Mapping System** - Eliminates missing picture issues with intelligent fallbacks
- **📊 MAJOR: Multi-Level Table Sorting** - Professional organization by GROUP → PILOT NAME
- **🚀 ENHANCED: Tactical Analysis Format** - Military-style reporting for complex operations
- Fixed PHP 8.4+ compatibility issues
- Updated deprecated XML function calls
- Added proper error handling for undefined array keys
- Modernized code structure
- Enhanced for Song of the Nibelungs campaign analysis
- **NEW**: Full Vercel deployment support with modern configuration
- **NEW**: Optimized serverless function architecture
- **NEW**: Best practices compliance per Vercel documentation

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

Created by Ezor, modified by various contributors for enhanced functionality and modern PHP compatibility.

## License

See License.txt for details.

## Changelog

### October 11, 2025 - Aircraft-Only Statistics Filtering
- **🚁 Enhanced pilot statistics filtering** - Removed ground units from main statistics table
- **✈️ Aircraft and helicopter focus** - Table now shows only airborne units with pilot data
- **🎯 Problem resolution** - Eliminated ground units like '207MRD/2TA-8-3', 'Depot Guard-13-1', 'Olympus-20-3' from pilot statistics
- **🔧 Dual filtering implementation**:
  - Enhanced `sortStatsByGroupAndPilot()` function with `isset($stat["Aircraft"])` validation
  - Added safety check in table display loop to ensure only aircraft/helicopter entries
- **📊 Cleaner tactical analysis** - Professional pilot performance metrics without ground unit clutter
- **🎖️ Military standard compliance** - Proper separation of air and ground operations reporting

### October 11, 2025 - Smart Icon Mapping System & Table Sorting
- **🎯 Added intelligent icon fallback system** - Eliminates 404 errors for missing aircraft/vehicle icons
- **🔧 Created getObjectIcon() function** with comprehensive mapping table for missing icons:
  - `MiG-29_Fulcrum` → `MiG-29A_Fulcrum-A` (Similar MiG-29 variant)
  - `Humvee` → `HUMMER` (Same vehicle, different naming)
  - `leopard-2A4` → `LEOPARD2` (Same tank, different naming)
  - `F-104_Starfighter` → `F-16C_Fighting_Falcon` (Similar fighter jet)
  - `Mirage_F1_EE` → `Mirage_2000C` (Similar Mirage variant)
  - `A-4E_Skyhawk` → `AV-8B_Harrier_II_NA` (Similar attack aircraft)
  - Plus mappings for all missing ground vehicles (BTR-80, T-72B, MTLB, etc.)
- **📊 Implemented multi-level table sorting** for statistics display:
  - **Primary Sort**: GROUP (organizes pilots by tactical units/squadrons)
  - **Secondary Sort**: PILOT NAME (alphabetical within each group)
- **🚀 Enhanced tactical analysis presentation**:
  - Professional military-style reporting format
  - Clear squadron/flight organization visibility
  - Improved readability for large-scale operations
  - Better understanding of mission force structure
- **⚡ Cross-platform compatibility** - All enhancements work in both local and serverless environments
- **🎖️ Optimized for Song of the Nibelungs analysis** - Perfect organization for complex multi-squadron operations

### October 11, 2025 - Vercel Configuration Optimization
- **Updated to modern Vercel best practices** per official documentation
- **Replaced legacy `routes` with `rewrites`** for better performance and support
- **Added JSON schema reference** for autocomplete and validation
- **Specified `outputDirectory`** for explicit static file location
- **Corrected API endpoint paths** to use clean URLs without .php extensions
- **Fixed redirect URLs** in index.html to match new API routing
- **Enhanced package.json configuration** with proper main field reference
- **Validated all file paths** and directory structure for deployment compatibility

### October 11, 2025 - Vercel Deployment Configuration
- **Added Vercel deployment support** with serverless PHP functions
- **Created public directory structure** for static asset serving
- **Implemented API endpoints** at `/api/debriefing` for serverless function handling
- **Updated file paths** to reference public directory for CSS, icons, and language files
- **Configured vercel.json** with proper routing for static files and API endpoints
- **Added package.json** with vercel-php runtime support
- **Established proper directory structure** required by Vercel platform:
  - `public/` - Contains all static assets (CSS, icons, language files, XML data)
  - `api/` - Contains serverless function endpoints
- **Fixed deployment errors** related to missing output directory
- **Enhanced GitHub integration** for automatic deployments via Vercel

### October 11, 2025 - PHP 8.4+ Compatibility
- **Fixed deprecated XML function calls** - Updated `xml_set_object()` usage for modern PHP
- **Added proper property declarations** - Fixed dynamic property creation warnings
- **Modernized L() function** - Updated language handling for current PHP standards
- **Enhanced error handling** - Added proper checks for undefined array keys
- **Updated XML parser callbacks** - Ensured compatibility with PHP 8.4+ XML handling

---
**Last Deployment**: October 11, 2025 18:43 MST - Pilot initialization fix deployment
