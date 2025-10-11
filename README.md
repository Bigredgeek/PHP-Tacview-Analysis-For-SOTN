# PHP Tacview Analysis For SOTN

A fork of PHP Tacview (by Ezor, Release Date: Mon, 13 Mar 2023) adapted for compatibility with the latest version of PHP and enhanced with additional functionality for Air Goons Wargame's Song of the Nibelungs.

PHP Tacview transforms your XML flight log into a visually understandable, interactive, detailed summary of your flight missions.

## Features

- Parse Tacview XML files and display mission events
- Chronological timeline of combat events
- Visual icons for different unit types and actions
- Web-based interface with responsive design
- Multi-language support (English, Spanish, French, Croatian, Italian)
- PHP 8.4+ compatibility with modern error handling

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

- Fixed PHP 8.4+ compatibility issues
- Updated deprecated XML function calls
- Added proper error handling for undefined array keys
- Modernized code structure
- Enhanced for Song of the Nibelungs campaign analysis

## Original Credits

Created by Ezor, modified by various contributors for enhanced functionality and modern PHP compatibility.

## License

See License.txt for details.
