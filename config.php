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
    'minify_html' => true,  // Minify HTML to reduce payload size
    
    // EventGraph aggregator tuning
    'aggregator' => [
        'time_tolerance' => 1.5,
        'hit_backtrack_window' => 5.0,
        'anchor_tolerance' => 120.0,
        'anchor_min_matches' => 5,          // Increased from 3 - require more matches to prevent false positives
        'max_fallback_offset' => 300.0,     // Reduced from 900 to 5 minutes - most legitimate offsets are small
        'max_anchor_offset' => 600.0,       // Reduced from 14400 to 10 minutes - reject large offsets that are likely false positives
        'mission_time_congruence_tolerance' => 1800.0,
        // Phase 1 enhancements - alignment tuning
        'anchor_decay' => 0.95,           // Decay factor for older anchor matches (0.0-1.0, 1.0 = no decay)
        'drift_sample_window' => 60.0,    // Time window (seconds) for drift detection sampling
        'coalition_alignment_weight' => 0.15, // How much coalition alignment affects confidence (0.0-1.0)
    ],
];
