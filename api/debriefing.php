<?php

declare(strict_types=1);

// Debriefing API endpoint for Vercel
// Include the main tacview class and debriefing logic

// Suppress warnings and notices
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// Set the correct paths for includes - tacview.php is now in public directory
$base_path = __DIR__ . '/../public';

// Include the tacview class
require_once $base_path . '/tacview.php';

// Output HTML headers
echo '<!DOCTYPE html>';
echo '<html>';
echo '<head>';
echo '<title>PHP Tacview Debriefing</title>';
echo '<link rel="stylesheet" href="/tacview.css" />';
echo '<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />';
echo '</head>';
echo '<body>';
echo '<div class="header-container">';
echo '<a href="https://sites.google.com/airgoons.com/songofthenibelungs/home" class="logo-link" target="_blank">';
echo '<img src="/AGWG_ICON.png" alt="AGWG Logo" class="logo" />';
echo '</a>';
echo '<h1>PHP Tacview Debriefing</h1>';
echo '</div>';

// Change to the public directory so relative paths work correctly
$original_cwd = getcwd();
chdir($base_path);

// Initialize tacview
$tv = new tacview("en");

// Set the correct image path for Vercel deployment
$tv->image_path = "/";

// Check for XML files in debriefings
$xmlFiles = glob("debriefings/*.xml");

// Store status messages to display at the bottom
$statusMessages = "<div style='margin-top: 40px; padding: 20px; border-top: 1px solid #333;'>";
$statusMessages .= "<p>Looking for XML files in debriefings folder...</p>";
$statusMessages .= "<p>Found " . count($xmlFiles) . " XML files.</p>";

if (count($xmlFiles) == 0) {
    $statusMessages .= "<p>No XML files found. Looking for other files...</p>";
    $allFiles = glob("debriefings/*");
    $statusMessages .= "<ul>";
    foreach ($allFiles as $file) {
        $statusMessages .= "<li>" . basename($file) . "</li>";
    }
    $statusMessages .= "</ul>";
    $statusMessages .= "<p><strong>Note:</strong> This application currently processes XML files only. You have an .acmi file which needs to be converted to XML format.</p>";
}

foreach ($xmlFiles as $filexml) {
    $statusMessages .= "<h2>Processed: " . basename($filexml) . "</h2>";
    $tv->proceedStats("$filexml","Mission Test");
    echo $tv->getOutput();
}

$statusMessages .= "</div>";

// Restore original working directory
chdir($original_cwd);

// Output status messages at the bottom
echo $statusMessages;

echo '</body>';
echo '</html>';
?>