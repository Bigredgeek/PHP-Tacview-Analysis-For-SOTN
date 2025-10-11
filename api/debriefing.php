<?php
// Debriefing API endpoint for Vercel
// Include the main tacview class and debriefing logic

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
echo '<h1>PHP Tacview Debriefing</h1>';

// Change to the public directory so relative paths work correctly
$original_cwd = getcwd();
chdir($base_path);

// Initialize tacview
$tv = new tacview("en");

// Set the correct image path for Vercel deployment
$tv->image_path = "/";

// Check for XML files in debriefings
$xmlFiles = glob("debriefings/*.xml");
echo "<p>Looking for XML files in debriefings folder...</p>";
echo "<p>Found " . count($xmlFiles) . " XML files.</p>";

if (count($xmlFiles) == 0) {
    echo "<p>No XML files found. Looking for other files...</p>";
    $allFiles = glob("debriefings/*");
    echo "<ul>";
    foreach ($allFiles as $file) {
        echo "<li>" . basename($file) . "</li>";
    }
    echo "</ul>";
    echo "<p><strong>Note:</strong> This application currently processes XML files only. You have an .acmi file which needs to be converted to XML format.</p>";
}

foreach ($xmlFiles as $filexml) {
    echo "<h2>Processing: " . basename($filexml) . "</h2>";
    $tv->proceedStats("$filexml","Mission Test");
    echo $tv->getOutput();
}

// Restore original working directory
chdir($original_cwd);

echo '</body>';
echo '</html>';
?>