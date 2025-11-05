---
title: "Copilot Instructions for PHP Tacview Analysis For SOTN"
description: "These instructions apply to the entire repository"
reference: "https://docs.github.com/en/copilot/customizing-copilot/adding-custom-instructions-for-github-copilot"
applies_to:
  - "**/*.php"
  - "**/*.css"
  - "**/*.js"
  - "**/*.md"
  - "**/Dockerfile"
  - "**/vercel.json"
---

# GitHub Copilot Instructions

## Project Overview
This is a PHP-based Tacview analysis tool for DCS (Digital Combat Simulator) debriefing files. The application parses and visualizes Tacview XML files to provide tactical analysis for flight operations.

## Code Style & Standards
 *****THE FOLLOWING IS VERY IMPORTANT*****
- If this project does not have a changelog.md file, make one before making any changes.
- Before making any changes read the changelog.md file and see if what you are planning has already been tried by other developers
- After any changes update the changelog.md file with what was done for future developers to reference.
- Update the changelog as you make changes to the project to avoid recursive loops and ineffective strategies
- Always run a test on a local server with a real Tacview XML debriefing file after making any PHP changes to ensure no warnings or errors occur, and display it in a browser preview to verify proper functionality.
- Ensure all relevant changes are reflected in the /public/ directory if applicable

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

### Validation Checklist
Before finalizing any changes, ensure:
- [ ] Code follows PSR-12 standards
- [ ] All PHP files have `declare(strict_types=1);`
- [ ] Type hints are present on all function parameters and return types
- [ ] Changes tested with actual Tacview XML files (use `php -S localhost:8000` and test in browser)
- [ ] No PHP warnings or errors in output
- [ ] Changes reflected in `/public/` directory if applicable
- [ ] All 10 language files updated if strings changed
- [ ] CHANGELOG.md updated with changes
- [ ] Documentation updated if behavior changed

### Running Tests
```bash
# Start local PHP server
php -S localhost:8000

# Test in browser
# Navigate to http://localhost:8000/debriefing.php

# Check for PHP errors
php -l tacview.php
php -l debriefing.php
```

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

## Common Task Guidelines

### Adding New Features
1. Check CHANGELOG.md for previous attempts
2. Start with minimal implementation
3. Test with real Tacview XML files
4. Update all 10 language files
5. Add to relevant sections in README.md
6. Document in CHANGELOG.md

### Fixing Bugs
1. Reproduce the issue locally first
2. Identify root cause before making changes
3. Fix with minimal code changes
4. Verify fix doesn't break existing functionality
5. Add test case if possible
6. Document in CHANGELOG.md under "Fixed" section

### Performance Improvements
1. Profile before optimizing
2. Focus on XML parsing and rendering performance
3. Test with large files (100MB+)
4. Verify memory usage stays reasonable
5. Document performance gains in CHANGELOG.md

### Internationalization
1. Never hardcode strings in PHP or HTML
2. Always use `$tv->L('KEY')` for translations
3. Update all 10 language files: en, de, es, fi, fr, hr, it, pt, ru, uk
4. Keep translations concise and context-appropriate
5. Test language switching with `?lang=XX` parameter

## RECOMMENDATIONS FOR FUTURE DEVELOPERS
1. Maintain strict typing throughout - don't revert to untyped code
2. Always check CHANGELOG.md before making modifications
3. Run test suite after any PHP modifications
4. Keep all 10 language files in sync between /languages and /public/languages
5. Test with actual Tacview XML files during development
6. Ensure type hints are updated when adding new methods

## Issue Requirements & Best Practices
When working on GitHub issues:
- Read and understand the issue description and all comments thoroughly
- Check the CHANGELOG.md for previous attempts at similar work
- Write clear, focused acceptance criteria
- Make minimal, surgical changes to achieve the goal
- Include tests when adding new functionality
- Document your changes in CHANGELOG.md as you work
- Test on local server with real Tacview XML files before finalizing

## Helpful Links
- [GitHub Copilot Best Practices](https://docs.github.com/en/copilot/tutorials/coding-agent/get-the-best-results)
- [Keep a Changelog Format](https://keepachangelog.com/en/1.0.0/)
- [PSR-12 Coding Standards](https://www.php-fig.org/psr/psr-12/)
- [Tacview Documentation](https://www.tacview.net/documentation/)