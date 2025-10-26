<?php
declare(strict_types=1);

$dest = __DIR__ . '/../objectIcons';
if (!is_dir($dest)) { mkdir($dest, 0775, true); }

// 1x1 transparent PNG
$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';
$names = [
    'Su-25_Frogfoot.png',
    'A-50_Mainstay.png'
];
foreach ($names as $name) {
    $path = $dest . '/' . $name;
    if (!file_exists($path)) {
        file_put_contents($path, base64_decode($pngBase64));
        echo "Created placeholder: $name\n";
    } else {
        echo "Already exists: $name\n";
    }
}
