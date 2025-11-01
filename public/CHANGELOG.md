# SOTN Public Bundle Changelog

## [Unreleased]
- Added `.vercelignore` and `vercel.json` redirect to keep `/debriefing.php` served by the API.
- Introduced `public/debriefing.php` for local PHP dev servers so the main view loads without refresh loops.
- Forced Tacview to build root-relative icon URLs from every debriefing entry point and smoke-tested via `php -S localhost:8001 -t public` with the sanitized mission export.
- Pointed the A-4E Skyhawk and F-104 Starfighter back to their dedicated thumbnails and lowercased the Mi-24P Hind-F asset so Linux deployments pick up the file.
- Made sticky header row fully opaque with a cyan trim so labels stay legible while scrolling.
- Restored the gradient styling and hover affordance for pilot statistic rows so the retro theme matches Brownwater again.
- Refreshed the A-4E Skyhawk and F-104 Starfighter thumbnails with normalized Wikimedia imagery for consistent mod aircraft coverage.
