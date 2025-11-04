<?php

declare(strict_types=1);

// Load configuration from parent directory
$config = require_once __DIR__ . "/../config.php";

require_once __DIR__ . '/../src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', dirname(__DIR__));

// Load tacview library directly from the shared core submodule
require_once $corePath . "/tacview.php";
require_once __DIR__ . "/../src/EventGraph/autoload.php";

use EventGraph\EventGraphAggregator;

?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo htmlspecialchars($config['page_title']); ?></title>
		<link rel="stylesheet" href="/tacview.css" />
		<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
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