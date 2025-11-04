<?php

declare(strict_types=1);

// Load configuration from the public bundle
$config = require_once __DIR__ . '/config.php';

require_once __DIR__ . '/../src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', dirname(__DIR__));

// Load core tacview library from the project root
require_once $corePath . '/tacview.php';
require_once __DIR__ . '/../src/EventGraph/autoload.php';

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

			// Resolve the glob relative to the project root so local static serving works.
			$debriefingsGlob = __DIR__ . '/../' . ltrim($config['debriefings_path'], '/');

			$xmlFiles = glob($debriefingsGlob) ?: [];

			$showStatusOverlay = ($config['show_status_overlay'] ?? false) || (isset($_GET['debug']) && $_GET['debug'] === '1');
			$debugMessages = [];
			$criticalMessages = [];

			if ($showStatusOverlay) {
				$debugMessages[] = '<p>Looking for XML files in debriefings folder...</p>';
				$debugMessages[] = '<p>Found ' . count($xmlFiles) . ' XML files.</p>';
			}

			if ($xmlFiles === []) {
				$criticalMessages[] = '<p>No Tacview XML debriefings found in <code>' . htmlspecialchars($debriefingsBase) . '</code>.</p>';
				$allFiles = glob($debriefingsBase . '*') ?: [];
				if ($allFiles !== []) {
					$listItems = '';
					foreach ($allFiles as $file) {
						$listItems .= '<li>' . htmlspecialchars(basename($file)) . '</li>';
					}
					$criticalMessages[] = '<p>Files present:</p><ul>' . $listItems . '</ul>';
				}
				$criticalMessages[] = '<p><strong>Note:</strong> Upload converted Tacview XML files. Raw .acmi recordings need to be exported to XML before analysis.</p>';
			} else {
				$aggregatorOptions = $config['aggregator'] ?? [];
				$aggregator = new EventGraphAggregator($config['default_language'], $aggregatorOptions);

				foreach ($xmlFiles as $filexml) {
					if ($showStatusOverlay) {
						$debugMessages[] = '<p>Aggregating ' . htmlspecialchars(basename($filexml)) . '...</p>';
					}
					try {
						$aggregator->ingestFile($filexml);
					} catch (\Throwable $exception) {
						$criticalMessages[] = "<p style='color: #ff6b6b;'>Failed to ingest " . htmlspecialchars(basename($filexml)) . ': ' . htmlspecialchars($exception->getMessage()) . '</p>';
					}
				}

				$mission = $aggregator->toAggregatedMission();
				$tv->proceedAggregatedStats(
					$mission->getMissionName(),
					$mission->getStartTime(),
					$mission->getDuration(),
					$mission->getEvents()
				);
				echo $tv->getOutput();

				if ($showStatusOverlay) {
					$metrics = $aggregator->getMetrics();
					$debugMessages[] = '<h2>Aggregation Summary</h2>';
					$debugMessages[] = '<ul>'
						. '<li>Total raw events: ' . (int)($metrics['raw_event_count'] ?? 0) . '</li>'
						. '<li>Merged events: ' . (int)($metrics['merged_events'] ?? 0) . '</li>'
						. '<li>Duplicates suppressed: ' . (int)($metrics['duplicates_suppressed'] ?? 0) . '</li>'
						. '<li>Inferred links: ' . (int)($metrics['inferred_links'] ?? 0) . '</li>'
						. '</ul>';

					$sources = $mission->getSources();
					if ($sources !== []) {
						$sourceItems = '';
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
							$sourceItems .= "<li>{$label}{$baselineMarker} ({$eventsCount} events, offset {$offsetHtml}{$strategyLabel})</li>";
						}
						$debugMessages[] = '<h3>Source Recordings</h3><ul>' . $sourceItems . '</ul>';
					}
				}
			}

			if ($showStatusOverlay || $criticalMessages !== []) {
				$statusHtml = implode('', array_merge($criticalMessages, $showStatusOverlay ? $debugMessages : []));
				if ($statusHtml !== '') {
					echo "<div class=\"status-overlay\" style=\"margin-top: 40px; padding: 20px; border-top: 1px solid #333;\">" . $statusHtml . '</div>';
				}
			}

		?>
	</body>
</html>
