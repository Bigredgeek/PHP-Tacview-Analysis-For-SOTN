<?php

declare(strict_types=1);

// Load configuration from parent directory
$config = require_once __DIR__ . "/../config.php";

// Load tacview library directly from the shared core submodule
require_once __DIR__ . "/../" . $config['core_path'] . "/tacview.php";
require_once __DIR__ . "/../src/EventGraph/autoload.php";

use EventGraph\EventGraphAggregator;

?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo htmlspecialchars($config['page_title']); ?></title>
		<link rel="stylesheet" href="/tacview.css" />
		<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
		<script type="text/javascript">
		function showDetails(zoneAffiche, rowElement){
			console.log("showDetails called with ID:", zoneAffiche);
			var detailRow = document.getElementById(zoneAffiche);
			console.log("detailRow found:", detailRow);
			var pilotRow = rowElement || event.currentTarget;
			
			if(!detailRow){
				console.error("Detail row not found for ID:", zoneAffiche);
				return false;
			}
			
			// Get computed style to check actual visibility
			var computedDisplay = window.getComputedStyle(detailRow).display;
			console.log("Computed display:", computedDisplay);
			var isHidden = computedDisplay === "none";
			console.log("isHidden:", isHidden);
			
			if(isHidden){
				console.log("Showing detail row");
				// Hide all other detail rows first (only target TR elements, not TD)
				var allDetails = document.querySelectorAll("tr.hiddenRow");
				var allPilotRows = document.querySelectorAll("tr.statisticsTable");
				allDetails.forEach(function(row){ row.style.display="none"; });
				allPilotRows.forEach(function(row){ row.classList.remove("active-pilot"); });
				
				// Show this detail row
				detailRow.style.display="table-row";
				pilotRow.classList.add("active-pilot");
			}else{
				console.log("Hiding detail row");
				// Hide this detail row
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
				<img src="/<?php echo htmlspecialchars($config['logo_path']); ?>" alt="<?php echo htmlspecialchars($config['logo_alt']); ?>" class="logo" />
			</a>
			<h1><?php echo htmlspecialchars($config['page_title']); ?></h1>
		</div>
		<?php

		$tv = new tacview($config['default_language']);
		$tv->image_path = '/'; // Force icon lookups to start at document root, even under /api

		$debriefingsBase = __DIR__ . "/../" . str_replace('*.xml', '', $config['debriefings_path']);
		$debriefingsPath = rtrim($debriefingsBase, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . "*.xml";

		$xmlFiles = glob($debriefingsPath) ?: [];

		$statusMessages = "<div style='margin-top: 40px; padding: 20px; border-top: 1px solid #333;'>";
		$statusMessages .= "<p>Looking for XML files in debriefings folder...</p>";
		$statusMessages .= "<p>Found " . count($xmlFiles) . " XML files.</p>";

		if ($xmlFiles === []) {
			$statusMessages .= "<p>No XML files found. Looking for other files...</p>";
			$allFiles = glob($debriefingsBase . '*') ?: [];
			$statusMessages .= "<ul>";
			foreach ($allFiles as $file) {
				$statusMessages .= "<li>" . htmlspecialchars(basename($file)) . "</li>";
			}
			$statusMessages .= "</ul>";
			$statusMessages .= "<p><strong>Note:</strong> This application currently processes XML files only. You have an .acmi file which needs to be converted to XML format.</p>";
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
			$tv->proceedAggregatedStats(
				$mission->getMissionName(),
				$mission->getStartTime(),
				$mission->getDuration(),
				$mission->getEvents()
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

			$sources = $mission->getSources();
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

		$statusMessages .= "</div>";
		echo $statusMessages;

		?>
	</body>
</html>