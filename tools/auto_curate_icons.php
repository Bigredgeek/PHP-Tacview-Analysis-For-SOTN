<?php
declare(strict_types=1);

// Auto-curate aircraft thumbnails from Wikimedia Commons (File namespace) via MediaWiki API
// For each aircraft seen in debriefings, query Commons and fetch a representative image.
// Writes images to objectIcons/ with conventional filenames and updates manifest metadata.

const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';

$root = dirname(__DIR__);
$destDir = $root . '/objectIcons';
$manifestPath = $root . '/data/aircraft_icons_manifest.json';

@mkdir($destDir, 0775, true);

function http_json(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'user_agent' => 'php-tacview/1.0'
        ],
        'https' => [
            'timeout' => 20,
            'user_agent' => 'php-tacview/1.0'
        ]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) return null;
    $json = json_decode($data, true);
    return is_array($json) ? $json : null;
}

function commons_search_first(string $query): ?array {
    $params = [
        'action' => 'query',
        'format' => 'json',
        'generator' => 'search',
        'gsrsearch' => $query,
        'gsrnamespace' => '6', // File namespace
        'gsrlimit' => '1',
        'prop' => 'imageinfo',
        'iiprop' => 'url|extmetadata',
        'iiurlwidth' => '1280',
    ];
    $url = COMMONS_API . '?' . http_build_query($params);
    $json = http_json($url);
    if (!$json || !isset($json['query']['pages'])) return null;
    $pages = $json['query']['pages'];
    $page = reset($pages);
    if (!isset($page['imageinfo'][0])) return null;
    $ii = $page['imageinfo'][0];
    $thumb = $ii['thumburl'] ?? $ii['url'] ?? null;
    $full = $ii['url'] ?? null;
    $meta = $ii['extmetadata'] ?? [];
    return [
        'title' => $page['title'] ?? '',
        'thumb' => $thumb,
        'url' => $full,
        'meta' => $meta,
        'descUrl' => $ii['descriptionurl'] ?? ''
    ];
}

function aircraft_list_from_xml(string $dir): array {
    $aircraft = [];
    if (!is_dir($dir)) return $aircraft;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (strtolower($file->getExtension()) !== 'xml') continue;
        $xml = @simplexml_load_file($file->getPathname());
        if (!$xml || !isset($xml->Events)) continue;
        foreach ($xml->Events->Event as $event) {
            foreach (['PrimaryObject','SecondaryObject'] as $node) {
                if (isset($event->{$node}) && (string)$event->{$node}->Type === 'Aircraft') {
                    $name = trim((string)$event->{$node}->Name);
                    if ($name !== '') $aircraft[$name] = true;
                }
            }
        }
    }
    $names = array_keys($aircraft);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function filename_for_aircraft(string $name): string {
    return str_replace([' ', '/'], ['_', '_'], $name) . '.jpg';
}

function save_manifest_meta(string $manifestPath, string $name, string $target, array $meta, string $sourceUrl, string $descUrl): void {
    $manifest = [ 'meta' => ['description' => '', 'created' => date('Y-m-d'), 'notes' => ''], 'aircraft' => [] ];
    if (is_file($manifestPath)) {
        $data = json_decode((string)file_get_contents($manifestPath), true);
        if (is_array($data)) $manifest = $data;
    }
    $found = false;
    foreach ($manifest['aircraft'] as &$entry) {
        if (($entry['name'] ?? '') === $name) {
            $entry['targetFilename'] = $target;
            $entry['fileUrl'] = $sourceUrl;
            $entry['license'] = $meta['LicenseShortName']['value'] ?? ($meta['UsageTerms']['value'] ?? null);
            $entry['attribution'] = $meta['Artist']['value'] ?? ($meta['Credit']['value'] ?? null);
            $entry['descriptionPage'] = $descUrl;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $manifest['aircraft'][] = [
            'name' => $name,
            'targetFilename' => $target,
            'fileUrl' => $sourceUrl,
            'license' => $meta['LicenseShortName']['value'] ?? ($meta['UsageTerms']['value'] ?? null),
            'attribution' => $meta['Artist']['value'] ?? ($meta['Credit']['value'] ?? null),
            'descriptionPage' => $descUrl,
        ];
    }
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

// Main
$names = aircraft_list_from_xml($root . '/debriefings');
if (!$names) {
    fwrite(STDERR, "No aircraft found in debriefings.\n");
    exit(1);
}

$done = 0; $fail = 0;
foreach ($names as $name) {
    $target = filename_for_aircraft($name);
    $query = $name . ' aircraft';
    $res = commons_search_first($query);
    if (!$res || !$res['url']) {
        // try without 'aircraft'
        $res = commons_search_first($name);
    }
    if (!$res || !$res['url']) {
        echo "MISS\t$name\n";
        $fail++;
        continue;
    }
    $srcUrl = $res['thumb'] ?: $res['url'];
    $data = @file_get_contents($srcUrl);
    if ($data === false) {
        echo "DLFAIL\t$name\t$srcUrl\n";
        $fail++;
        continue;
    }
    $outPath = $destDir . '/' . $target;
    file_put_contents($outPath, $data);
    save_manifest_meta($manifestPath, $name, $target, $res['meta'] ?? [], $res['url'], $res['descUrl']);
    echo "OK\t$name\t$target\n";
    $done++;
}

echo "\nCompleted. OK=$done FAIL=$fail\n";
