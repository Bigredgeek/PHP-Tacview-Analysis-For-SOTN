<?php

declare(strict_types=1);

// Load configuration
$config = require_once __DIR__ . '/config.php';

require_once __DIR__ . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', __DIR__);

// Load core tacview library and event graph autoloader
require_once $corePath . '/tacview.php';

$eventGraphAutoloadCandidates = [
	__DIR__ . '/src/EventGraph/autoload.php',
	__DIR__ . '/public/src/EventGraph/autoload.php',
];

$eventGraphAutoloadPath = null;
foreach ($eventGraphAutoloadCandidates as $candidate) {
	if (is_file($candidate)) {
		$eventGraphAutoloadPath = $candidate;
		break;
	}
}

if ($eventGraphAutoloadPath === null) {
	throw new \RuntimeException('Unable to locate EventGraph autoloader. Checked: ' . implode(', ', $eventGraphAutoloadCandidates));
}

require_once $eventGraphAutoloadPath;

use EventGraph\EventGraphAggregator;

if (!function_exists('tacview_normalize_url_path')) {
	function tacview_normalize_url_path(?string $path): string
	{
		if ($path === null || $path === '' || $path === '.' || $path === DIRECTORY_SEPARATOR) {
			return '/';
		}

		$normalized = str_replace('\\', '/', $path);
		if ($normalized === '/' || $normalized === '') {
			return '/';
		}

		return '/' . trim($normalized, '/');
	}
}

if (!function_exists('tacview_url_parent')) {
	function tacview_url_parent(string $url): string
	{
		if ($url === '' || $url === '/' || $url === '\\') {
			return '/';
		}

		$trimmed = trim($url, '/');
		if ($trimmed === '') {
			return '/';
		}

		$segments = explode('/', $trimmed);
		array_pop($segments);

		if (empty($segments)) {
			return '/';
		}

		return '/' . implode('/', $segments);
	}
}

if (!function_exists('tacview_with_trailing_slash')) {
	function tacview_with_trailing_slash(?string $url): string
	{
		if ($url === null || $url === '' || $url === '/' || $url === '\\') {
			return '/';
		}

		return rtrim($url, '/') . '/';
	}
}

if (!function_exists('tacview_join_url')) {
	function tacview_join_url(?string $base, string $append): string
	{
		$base = $base ?? '/';
		$append = trim($append, '/');

		if ($append === '') {
			return $base === '' ? '/' : $base;
		}

		if ($base === '' || $base === '/' || $base === '\\') {
			return '/' . $append;
		}

		return rtrim($base, '/') . '/' . $append;
	}
}

if (!function_exists('tacview_build_directory_levels')) {
	function tacview_build_directory_levels(string $startFs, string $startUrl, int $maxDepth = 4): array
	{
		$levels = [];
		$currentFs = $startFs;
		$currentUrl = $startUrl;

		for ($i = 0; $i < $maxDepth; $i++) {
			$levels[] = [
				'fs' => $currentFs,
				'url' => $currentUrl,
			];

			$parentFs = dirname($currentFs);
			$parentUrl = tacview_url_parent($currentUrl);

			if ($parentFs === $currentFs || $parentFs === '' || $parentUrl === $currentUrl) {
				break;
			}

			$currentFs = $parentFs;
			$currentUrl = $parentUrl;
		}

		return $levels;
	}
}

if (!function_exists('tacview_resolve_asset_paths')) {
	function tacview_resolve_asset_paths(string $baseDir, string $scriptName, string $corePath): array
	{
		$scriptDirUrl = tacview_normalize_url_path(dirname($scriptName));
		$levels = tacview_build_directory_levels($baseDir, $scriptDirUrl);
		$assetUrl = null;

		foreach ($levels as $level) {
			if (is_dir($level['fs'] . DIRECTORY_SEPARATOR . 'categoryIcons')) {
				$assetUrl = tacview_with_trailing_slash($level['url']);
				break;
			}

			$publicFs = $level['fs'] . DIRECTORY_SEPARATOR . 'public';
			if (is_dir($publicFs . DIRECTORY_SEPARATOR . 'categoryIcons')) {
				$assetUrl = tacview_with_trailing_slash(tacview_join_url($level['url'], 'public'));
				break;
			}
		}

		$corePathTrimmed = trim($corePath, DIRECTORY_SEPARATOR . '/');

		if ($assetUrl === null && $corePathTrimmed !== '') {
			foreach ($levels as $level) {
				$coreFs = rtrim($level['fs'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $corePathTrimmed;
				if (is_dir($coreFs . DIRECTORY_SEPARATOR . 'categoryIcons')) {
					$assetUrl = tacview_with_trailing_slash(tacview_join_url($level['url'], $corePathTrimmed));
					break;
				}
			}
		}

		if ($assetUrl === null) {
			$assetUrl = '/';
		}

		$cssHref = null;

		foreach ($levels as $level) {
			if (is_file($level['fs'] . DIRECTORY_SEPARATOR . 'tacview.css')) {
				$cssHref = tacview_join_url($level['url'], 'tacview.css');
				break;
			}

			$publicFs = $level['fs'] . DIRECTORY_SEPARATOR . 'public';
			if (is_file($publicFs . DIRECTORY_SEPARATOR . 'tacview.css')) {
				$cssHref = tacview_join_url(tacview_join_url($level['url'], 'public'), 'tacview.css');
				break;
			}
		}

		if ($cssHref === null && $corePathTrimmed !== '') {
			foreach ($levels as $level) {
				$coreFs = rtrim($level['fs'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $corePathTrimmed;
				if (is_file($coreFs . DIRECTORY_SEPARATOR . 'tacview.css')) {
					$cssHref = tacview_join_url(tacview_join_url($level['url'], $corePathTrimmed), 'tacview.css');
					break;
				}
			}
		}

		if ($cssHref === null) {
			$cssHref = '/tacview.css';
		}

		return [$assetUrl, $cssHref];
	}
}

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/debriefing.php';
[$assetBaseUrl, $cssHref] = tacview_resolve_asset_paths(__DIR__, $scriptName, $config['core_path']);

?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo htmlspecialchars($config['page_title']); ?></title>
		<link rel="stylesheet" href="<?php echo htmlspecialchars($cssHref); ?>" />
		<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
		<script type="text/javascript">
		function showDetails(zoneAffiche, rowElement){
			var detailRow = document.getElementById(zoneAffiche);
			if(!detailRow){
				return false;
			}
			var pilotRow = rowElement || (typeof event !== "undefined" ? event.currentTarget : null);
			if(!pilotRow){
				return false;
			}
			var isHidden = window.getComputedStyle(detailRow).display === "none";
			document.querySelectorAll("tr.hiddenRow").forEach(function(row){ row.style.display="none"; });
			document.querySelectorAll("tr.statisticsTable").forEach(function(row){ row.classList.remove("active-pilot"); });
			if(isHidden){
				detailRow.style.display="table-row";
				pilotRow.classList.add("active-pilot");
			}else{
				detailRow.style.display="none";
				pilotRow.classList.remove("active-pilot");
			}
			return false;
		}

		</script>
	</head>
	<body>
		<div class="header-container">
			<a href="<?php echo htmlspecialchars($config['group_link']); ?>" class="logo-link" target="_blank">
				<img src="<?php echo htmlspecialchars($config['logo_path']); ?>" alt="<?php echo htmlspecialchars($config['logo_alt']); ?>" class="logo" />
			</a>
			<h1><?php echo htmlspecialchars($config['page_title']); ?></h1>
		</div>
		<?php

			$tv = new tacview($config['default_language']);
			$tv->image_path = $assetBaseUrl;

			// Check for pre-aggregated cache
			$cacheFile = __DIR__ . '/public/debriefings/aggregated-cache.json';
			$useCachedData = is_file($cacheFile);

			$debriefingsGlob = __DIR__ . '/' . ltrim($config['debriefings_path'], '/');
			$xmlFiles = glob($debriefingsGlob) ?: [];

			$statusMessages = "<div style='margin-top: 40px; padding: 20px; border-top: 1px solid #333;'>";

			if ($useCachedData) {
				echo "<div style='position: fixed; top: 10px; right: 10px; background: rgba(0, 255, 0, 0.1); border: 1px solid #0f0; padding: 8px 12px; border-radius: 4px; font-size: 12px; z-index: 9999;'>";
				echo "⚡ Fast Mode (Pre-Processed)";
				echo "</div>";
				
				$cache = json_decode(file_get_contents($cacheFile), true);
				
				if ($cache && isset($cache['mission'])) {
					$sources = $cache['mission']['sources'] ?? [];
					$tv->proceedAggregatedStats(
						$cache['mission']['name'],
						$cache['mission']['startTime'],
						$cache['mission']['duration'],
						$cache['mission']['events'],
						count($sources),
						$sources
					);
					echo $tv->getOutput();
					
					// Display cache info
					$statusMessages .= "<h2>📦 Cached Aggregation (Generated: " . date('Y-m-d H:i:s', $cache['generated']) . ")</h2>";
					$statusMessages .= "<p>✅ Loaded pre-processed data from cache</p>";
					$statusMessages .= "<p>📁 File count: " . $cache['fileCount'] . "</p>";
					
					$metrics = $cache['metrics'] ?? [];
					$statusMessages .= "<h3>Aggregation Metrics</h3>";
					$statusMessages .= "<ul>";
					$statusMessages .= "<li>Total raw events: " . (int)($metrics['raw_event_count'] ?? 0) . "</li>";
					$statusMessages .= "<li>Merged events: " . (int)($metrics['merged_events'] ?? 0) . "</li>";
					$statusMessages .= "<li>Duplicates suppressed: " . (int)($metrics['duplicates_suppressed'] ?? 0) . "</li>";
					$statusMessages .= "<li>Inferred links: " . (int)($metrics['inferred_links'] ?? 0) . "</li>";
					$statusMessages .= "</ul>";

					// Phase 1: Coverage & Alignment Statistics
					$statusMessages .= "<h3>Coverage & Alignment</h3>";
					$statusMessages .= "<ul>";
					$alignmentConfidenceAvg = isset($metrics['alignment_confidence_avg']) ? round((float)$metrics['alignment_confidence_avg'] * 100, 1) : null;
					if ($alignmentConfidenceAvg !== null) {
						$statusMessages .= "<li>Avg alignment confidence: {$alignmentConfidenceAvg}%</li>";
					}
					$alignmentConflicts = (int)($metrics['alignment_conflicts'] ?? 0);
					if ($alignmentConflicts > 0) {
						$statusMessages .= "<li>⚠️ Alignment conflicts: {$alignmentConflicts}</li>";
					}
					$coalitionMatches = (int)($metrics['coalition_alignment_matches'] ?? 0);
					$coalitionMismatches = (int)($metrics['coalition_alignment_mismatches'] ?? 0);
					if ($coalitionMatches + $coalitionMismatches > 0) {
						$statusMessages .= "<li>Coalition alignments: {$coalitionMatches} matches, {$coalitionMismatches} mismatches</li>";
					}
					$coverageStats = $metrics['coverage_stats'] ?? null;
					if ($coverageStats !== null) {
						$gapPercent = round((float)($coverageStats['gapPercent'] ?? 0), 1);
						$overlapPercent = round((float)($coverageStats['overlapPercent'] ?? 0), 1);
						$totalCoverage = (float)($coverageStats['totalCoverage'] ?? 0);
						$statusMessages .= "<li>Coverage gaps: {$gapPercent}%</li>";
						$statusMessages .= "<li>Coverage overlap: {$overlapPercent}%</li>";
						$statusMessages .= "<li>Total coverage time: " . gmdate('H:i:s', (int)$totalCoverage) . "</li>";
					}
					$statusMessages .= "</ul>";
					
					$sources = $cache['mission']['sources'] ?? [];
					if (!empty($sources)) {
						$statusMessages .= "<h3>Source Recordings</h3><ul>";
						foreach ($sources as $source) {
							$label = htmlspecialchars($source['filename'] ?? $source['id'] ?? 'unknown');
							$eventsCount = (int)($source['events'] ?? 0);
							$offsetSeconds = isset($source['offset']) && is_numeric($source['offset']) ? (float)$source['offset'] : 0.0;
							$offsetLabel = sprintf('%+.2fs', $offsetSeconds);
							$offsetHtml = htmlspecialchars($offsetLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							$strategy = $source['offsetStrategy'] ?? null;
							$strategyLabel = '';
							if (is_string($strategy) && str_starts_with($strategy, 'anchor')) {
								$strategyLabel = ' via anchor match';
							} elseif ($strategy === 'fallback-applied') {
								$strategyLabel = ' via fallback';
							} elseif ($strategy === 'fallback-skipped') {
								$strategyLabel = ' (fallback skipped)';
							}
							$baselineMarker = !empty($source['baseline']) ? ' <strong>(baseline)</strong>' : '';
							$confLabel = '';
							if (isset($source['alignmentConfidence'])) {
								$confPercent = round((float)$source['alignmentConfidence'] * 100, 0);
								$confLabel = ", conf {$confPercent}%";
							}
							$covLabel = '';
							if (isset($source['coveragePercent'])) {
								$covLabel = ", cov " . round((float)$source['coveragePercent'], 1) . "%";
							}
							$statusMessages .= "<li>{$label}{$baselineMarker} ({$eventsCount} events, offset {$offsetHtml}{$confLabel}{$covLabel}{$strategyLabel})</li>";
						}
						$statusMessages .= "</ul>";
					}
				} else {
					echo "<p style='color: red;'>⚠️ Cache file corrupted, falling back to runtime processing</p>";
					$useCachedData = false;
				}
			}

			// Fallback to runtime processing if cache not available
			if (!$useCachedData) {
				$statusMessages .= "<p>Looking for XML files in debriefings folder...</p>";
				$statusMessages .= "<p>Found " . count($xmlFiles) . " XML files.</p>";

			if ($xmlFiles === []) {
				$statusMessages .= "<p>No XML files found. Looking for other files...</p>";
				$allFiles = glob(__DIR__ . '/debriefings/*') ?: [];
				$statusMessages .= "<ul>";
				foreach ($allFiles as $file) {
					$statusMessages .= "<li>" . htmlspecialchars(basename($file)) . "</li>";
				}
				$statusMessages .= "</ul>";
				$statusMessages .= "<p><strong>Note:</strong> This application currently processes XML files only. You may have an .acmi file which needs to be converted to XML format.</p>";
			} else {
				$aggregatorOptions = $config['aggregator'] ?? [];
				$aggregator = new EventGraphAggregator($config['default_language'], $aggregatorOptions);

				foreach ($xmlFiles as $filexml) {
					$statusMessages .= "<p>Aggregating " . htmlspecialchars(basename($filexml)) . "...</p>";
					try {
						$aggregator->ingestFile($filexml);
					} catch (\Throwable $exception) {
						$statusMessages .= "<p style='color: #ff6b6b;'>Failed to ingest " . htmlspecialchars(basename($filexml)) . ': ' . htmlspecialchars($exception->getMessage()) . "</p>";
					}
				}

				$mission = $aggregator->toAggregatedMission();
				$sources = $mission->getSources();
				$tv->proceedAggregatedStats(
					$mission->getMissionName(),
					$mission->getStartTime(),
					$mission->getDuration(),
					$mission->getEvents(),
					count($sources),
					$sources
				);
				echo $tv->getOutput();

				$metrics = $aggregator->getMetrics();
				$statusMessages .= "<h2>Aggregation Summary</h2>";
				$statusMessages .= "<ul>";
				$statusMessages .= "<li>Total raw events: " . (int)($metrics['raw_event_count'] ?? 0) . "</li>";
				$statusMessages .= "<li>Merged events: " . (int)($metrics['merged_events'] ?? 0) . "</li>";
				$statusMessages .= "<li>Duplicates suppressed: " . (int)($metrics['duplicates_suppressed'] ?? 0) . "</li>";
				$statusMessages .= "<li>Inferred links: " . (int)($metrics['inferred_links'] ?? 0) . "</li>";
				$statusMessages .= "</ul>";

				if ($sources !== []) {
					$statusMessages .= "<h3>Source Recordings</h3><ul>";
					foreach ($sources as $source) {
						$label = htmlspecialchars($source['filename'] ?? $source['id'] ?? 'unknown');
						$eventsCount = (int)($source['events'] ?? 0);
						$offsetSeconds = isset($source['offset']) && is_numeric($source['offset']) ? (float)$source['offset'] : 0.0;
						$offsetLabel = sprintf('%+.2fs', $offsetSeconds);
						$offsetHtml = htmlspecialchars($offsetLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
						$strategy = $source['offsetStrategy'] ?? null;
						$strategyLabel = '';
						if ($strategy === 'anchor') {
							$strategyLabel = ' via anchor match';
						} elseif ($strategy === 'fallback-applied') {
							$strategyLabel = ' via fallback';
						} elseif ($strategy === 'fallback-skipped') {
							$strategyLabel = ' (fallback skipped)';
						}
						$baselineMarker = !empty($source['baseline']) ? ' <strong>(baseline)</strong>' : '';
						$statusMessages .= "<li>{$label}{$baselineMarker} ({$eventsCount} events, offset {$offsetHtml}{$strategyLabel})</li>";
					}
					$statusMessages .= "</ul>";
				}
			}
			}

			$statusMessages .= "</div>";
			echo $statusMessages;

		?>
	</body>
</html>