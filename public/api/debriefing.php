<?php

declare(strict_types=1);

// Load configuration from the public bundle
$config = require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../../src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', dirname(__DIR__, 2));

// Load the shared Tacview engine from the core submodule
require_once $corePath . '/tacview.php';

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
			console.log('showDetails called with ID:', zoneAffiche);
			var detailRow = document.getElementById(zoneAffiche);
			console.log('detailRow found:', detailRow);
			var pilotRow = rowElement || event.currentTarget;
			
			if(!detailRow){
				console.error('Detail row not found for ID:', zoneAffiche);
				return false;
			}
			
			// Get computed style to check actual visibility
			var computedDisplay = window.getComputedStyle(detailRow).display;
			console.log('Computed display:', computedDisplay);
			var isHidden = computedDisplay === 'none';
			console.log('isHidden:', isHidden);
			
			if(isHidden){
				console.log('Showing detail row');
				// Hide all other detail rows first (only target TR elements, not TD)
				var allDetails = document.querySelectorAll('tr.hiddenRow');
				var allPilotRows = document.querySelectorAll('tr.statisticsTable');
				allDetails.forEach(function(row){ row.style.display='none'; });
				allPilotRows.forEach(function(row){ row.classList.remove('active-pilot'); });
				
				// Show this detail row
				detailRow.style.display='table-row';
				pilotRow.classList.add('active-pilot');
			}else{
				console.log('Hiding detail row');
				// Hide this detail row
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

		// Use the absolute glob from the public configuration
		$xmlFiles = glob($config['debriefings_path']);

		// Store status messages to display at the bottom
		$statusMessages = "<div style='margin-top: 40px; padding: 20px; border-top: 1px solid #333;'>";
		$statusMessages .= "<p>Looking for XML files in debriefings folder...</p>";
		$statusMessages .= "<p>Found " . count($xmlFiles) . " XML files.</p>";

		if (count($xmlFiles) == 0) {
		    $statusMessages .= "<p>No XML files found. Looking for other files...</p>";
		    $allFiles = glob(dirname($config['debriefings_path']) . '/*');
		    $statusMessages .= "<ul>";
		    foreach ($allFiles as $file) {
		        $statusMessages .= "<li>" . basename($file) . "</li>";
		    }
		    $statusMessages .= "</ul>";
		    $statusMessages .= "<p><strong>Note:</strong> This application currently processes XML files only. You may have an .acmi file which needs to be converted to XML format.</p>";
		}

		foreach ($xmlFiles as $filexml) {
		    $statusMessages .= "<h2>Processed: " . basename($filexml) . "</h2>";
		    $tv->proceedStats($filexml, 'Mission Test');
		    echo $tv->getOutput();
		}

		$statusMessages .= "</div>";

		// Output status messages at the bottom
		echo $statusMessages;
		?>
	</body>
</html>
