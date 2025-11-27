<?php

declare(strict_types=1);

// Load root configuration and layer any public/public-api overrides on top
$rootConfigPath = dirname(__DIR__, 2) . '/config.php';
$config = [];
if (is_file($rootConfigPath)) {
	$rootConfig = require $rootConfigPath;
	$config = is_array($rootConfig) ? $rootConfig : [];
}

$configOverridePaths = [
	dirname(__DIR__) . '/config.php', // public bundle defaults
	__DIR__ . '/config.php', // public/api specific overrides
];

foreach ($configOverridePaths as $overridePath) {
	if (!is_file($overridePath)) {
		continue;
	}

	$override = require $overridePath;
	if (!is_array($override)) {
		continue;
	}

	$config = $config === [] ? $override : array_replace_recursive($config, $override);
}

if ($config === []) {
	throw new \RuntimeException('Failed to load configuration for public/api/debriefing.php');
}

// Enable aggressive output compression for Vercel payload limits
if (($config['enable_compression'] ?? true) && !headers_sent()) {
	if (extension_loaded('zlib') && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
		// Use output buffering with gzip for better compression
		ob_start('ob_gzhandler');
		// Set aggressive compression level
		ini_set('zlib.output_compression', '1');
		ini_set('zlib.output_compression_level', '9');
	}
}

// Load the shared Tacview engine from the core submodule
require_once __DIR__ . '/../../' . $config['core_path'] . '/tacview.php';

$eventGraphAutoloadCandidates = [
	__DIR__ . '/../../src/EventGraph/autoload.php',
	__DIR__ . '/../src/EventGraph/autoload.php',
	__DIR__ . '/../../public/src/EventGraph/autoload.php',
	__DIR__ . '/../EventGraph/autoload.php',
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

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/debriefing.php';
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
			var pilotRow = rowElement || (typeof event !== 'undefined' ? event.currentTarget : null);
			if(!pilotRow){
				return false;
			}
			var isHidden = window.getComputedStyle(detailRow).display === 'none';
			document.querySelectorAll('tr.hiddenRow').forEach(function(row){ row.style.display='none'; });
			document.querySelectorAll('tr.statisticsTable').forEach(function(row){ row.classList.remove('active-pilot'); });
			if(isHidden){
				detailRow.style.display='table-row';
				pilotRow.classList.add('active-pilot');
			}else{
				detailRow.style.display='none';
				pilotRow.classList.remove('active-pilot');
			}
			return false;
		}
		</script>
	</head>
	<body>
		<div class="header-container">
			<a href="<?php echo htmlspecialchars($config['group_link']); ?>" class="logo-link" target="_blank">
				<img src="/<?php echo htmlspecialchars($config['logo_path']); ?>" alt="<?php echo htmlspecialchars($config['logo_alt']); ?>" class="logo" />
			</a>
			<h1><?php echo htmlspecialchars($config['page_title']); ?></h1>
		</div>
		<?php

		$tv = new tacview($config['default_language']);
		$tv->image_path = $assetBaseUrl;


		$debriefingsGlob = __DIR__ . '/../../' . ltrim($config['debriefings_path'], '/');
		$xmlFiles = glob($debriefingsGlob) ?: [];
		$debriefingsDir = rtrim(dirname($debriefingsGlob), DIRECTORY_SEPARATOR);

		$showStatusOverlay = ($config['show_status_overlay'] ?? false) || (isset($_GET['debug']) && $_GET['debug'] === '1');
		$debugMessages = [];
		$criticalMessages = [];

		if ($showStatusOverlay) {
			$debugMessages[] = '<p>Looking for XML files in debriefings folder...</p>';
			$debugMessages[] = '<p>Found ' . count($xmlFiles) . ' XML files.</p>';
		}

		if ($xmlFiles === []) {
			$criticalMessages[] = '<p>No Tacview XML debriefings found in <code>' . htmlspecialchars($debriefingsDir) . '</code>.</p>';
			$allFiles = glob($debriefingsDir . '/*') ?: [];
			if ($allFiles !== []) {
				$listItems = '';
				foreach ($allFiles as $file) {
					$listItems .= '<li>' . htmlspecialchars(basename($file)) . '</li>';
				}
				$criticalMessages[] = '<p>Files present:</p><ul>' . $listItems . '</ul>';
			}
			$criticalMessages[] = '<p><strong>Note:</strong> This application currently processes XML files only. You may have an .acmi file which needs to be converted to XML format.</p>';
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

		    if ($showStatusOverlay) {
		    	$metrics = $aggregator->getMetrics();
		    	$debugMessages[] = '<h2>Aggregation Summary</h2>';
		    	$debugMessages[] = '<ul>'
		    		. '<li>Total raw events: ' . (int)($metrics['raw_event_count'] ?? 0) . '</li>'
		    		. '<li>Merged events: ' . (int)($metrics['merged_events'] ?? 0) . '</li>'
		    		. '<li>Duplicates suppressed: ' . (int)($metrics['duplicates_suppressed'] ?? 0) . '</li>'
		    		. '<li>Inferred links: ' . (int)($metrics['inferred_links'] ?? 0) . '</li>'
		    		. '</ul>';

		    	if ($sources !== []) {
		    		$debugMessages[] = '<h3>Source Recordings</h3><ul>';
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
		    		$debugMessages[] = $sourceItems . '</ul>';
		    	}
		    }
		}

		if ($showStatusOverlay || $criticalMessages !== []) {
			$statusHtml = implode('', array_merge($criticalMessages, $showStatusOverlay ? $debugMessages : []));
			if ($statusHtml !== '') {
				echo '<div class="status-overlay" style="margin-top: 40px; padding: 20px; border-top: 1px solid #333;">' . $statusHtml . '</div>';
			}
		}
		?>
	</body>
</html>
<?php
// Minify HTML output to reduce payload size for Vercel
if (($config['minify_html'] ?? false) && ob_get_level() > 0) {
	$html = ob_get_clean();
	
	// Aggressive HTML minification
	$html = preg_replace('/\s+/', ' ', $html); // Collapse whitespace
	$html = preg_replace('/>\s+</', '><', $html); // Remove space between tags
	$html = preg_replace('/\s+([\/>])/', '$1', $html); // Remove trailing spaces in tags
	
	echo $html;
}

// Ensure gzip buffer is flushed
if (ob_get_level() > 0) {
	ob_end_flush();
}
?>
