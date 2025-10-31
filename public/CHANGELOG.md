# SOTN Public Bundle Changelog

## [Unreleased]
- Added `.vercelignore` and `vercel.json` redirect to keep `/debriefing.php` served by the API.
- Introduced `public/debriefing.php` for local PHP dev servers so the main view loads without refresh loops.
- Made sticky header row fully opaque with a cyan trim so labels stay legible while scrolling.
- Restored the gradient styling and hover affordance for pilot statistic rows so the retro theme matches Brownwater again.
