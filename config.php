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
    'show_status_overlay' => false, // Toggle to surface aggregator debug output by default
    
    // Paths (relative to project root)
    'debriefings_path' => 'debriefings/*.xml',
    'core_path' => 'core',  // Path to the bundled core assets
    
    // Output compression (helps with serverless environments like Vercel)
    'enable_compression' => true,  // Enable gzip output compression
    
    // EventGraph aggregator tuning
    'aggregator' => [
        'time_tolerance' => 1.5,
        'hit_backtrack_window' => 5.0,
        'anchor_tolerance' => 120.0,
        'anchor_min_matches' => 3,
        'max_fallback_offset' => 900.0,
        'max_anchor_offset' => 14400.0,
        'mission_time_congruence_tolerance' => 1800.0,
    ],
];
