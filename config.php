<?php

declare(strict_types=1);

/**
 * PHPTacview Configuration - Song of the Nibelungs
 */

return [
    // Group branding
    'group_name' => 'Song of the Nibelungs',
    'logo_path' => 'AGWG_ICON.png',
    'logo_alt' => 'AGWG Logo',
    'group_link' => 'https://sites.google.com/airgoons.com/songofthenibelungs/home',
    
    // Page settings
    'page_title' => 'PHP Tacview Debriefing',
    'default_language' => 'en',
    
    // Paths (relative to project root)
    'debriefings_path' => 'debriefings/*.xml',
    'core_path' => 'core',  // Path to the bundled core assets
    // EventGraph aggregator tuning
    'aggregator' => [
        'time_tolerance' => 1.5,
        'hit_backtrack_window' => 5.0,
        'anchor_tolerance' => 120.0,
        'anchor_min_matches' => 3,
        'max_fallback_offset' => 900.0,
        'max_anchor_offset' => 14400.0,
    ],
];
