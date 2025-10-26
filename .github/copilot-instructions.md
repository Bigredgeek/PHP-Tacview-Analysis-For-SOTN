# GitHub Copilot Instructions

## Project Overview
This is a PHP-based Tacview analysis tool for DCS (Digital Combat Simulator) debriefing files. The application parses and visualizes Tacview XML files to provide tactical analysis for flight operations.

## Code Style & Standards
 *****THE FOLLOWING IS VERY IMPORTANT*****
- If this project does not have a changelog.md file, make one before making any changes.
- Before making any changes read the changelog.md file and see if what you are planning has already been tried by other developers
- After any changes update the changelog.md file with what was done for future developers to reference.
- Update the changelog as you make changes to the project to avoid recursive loops and ineffective strategies
- Always run a test on a local server with a real Tacview XML debriefing file after making any PHP changes to ensure no warnings or errors occur.

### PHP
- Use PHP 8.2+ syntax and features
- Follow PSR-12 coding standards
- Use strict typing where applicable (`declare(strict_types=1)`)
- Prefer explicit error handling over silent failures
- Use meaningful variable names (e.g., `$aircraftData` not `$ad`)
- Use modern array syntax `[]` instead of `array()`
- Include type hints for function parameters and return types

### File Structure
- Main entry points: `index.php`, `tacview.php`, `debriefing.php`
- API endpoints in `/api/` directory
- Internationalization files in `/languages/` directory
- Static assets: icons in `/categoryIcons/` and `/objectIcons/`
- XML debriefing files in `/debriefings/`

### XML Processing
- When working with Tacview XML files, preserve the structure and formatting
- Use proper XML parsing libraries (SimpleXML or DOMDocument)
- Handle large XML files efficiently with streaming when possible

### Deployment Targets
- Vercel (serverless PHP via `vercel.json`)
- Docker (see `Dockerfile`)

## Domain Knowledge

### Tacview Format
- Tacview files contain timestamped 3D position data and events from DCS missions
- Key elements: aircraft tracks, weapon releases, kills, damage events
- Coordinate system: latitude/longitude/altitude

### Military Aviation Context
- Code should respect military terminology and conventions
- Aircraft types, weapon systems, and tactical concepts should be handled accurately
- Time formats typically use Zulu/UTC

## Testing & Validation
- Test with sample debriefing files in `/debriefings/`
- Verify multilingual support when modifying language files
- Ensure changes work across deployment platforms (Vercel, Docker)

## Security Considerations
- Sanitize all user inputs before XML parsing
- Validate file uploads (type, size, content)
- Use proper escaping for HTML output
- Never expose sensitive paths or configuration details

## Performance
- XML parsing should be optimized for files up to 100MB+
- Consider memory limits when processing large debriefings
- Cache parsed data when appropriate

## Helpful Context
- This tool is primarily used by the Air Goons Wargame community for the analysis of DCS mission debriefings for Song of the Nibelung community wargame
- Focus on features that support post-mission debriefing and tactical analysis
- UI should be clear and focused on mission data visualization

## RECOMMENDATIONS FOR FUTURE DEVELOPERS
1. Maintain strict typing throughout - don't revert to untyped code
2. Always check CHANGELOG.md before making modifications
3. Run test suite after any PHP modifications
4. Keep all 10 language files in sync between /languages and /public/languages
5. Test with actual Tacview XML files during development
6. Ensure type hints are updated when adding new methods