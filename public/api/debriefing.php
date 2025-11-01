<?php

declare(strict_types=1);

// Load configuration from the public bundle
$config = require_once __DIR__ . '/../config.php';

// Load the shared Tacview engine from the core submodule
require_once __DIR__ . '/../../' . $config['core_path'] . '/tacview.php';

?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo htmlspecialchars($config['page_title']); ?></title>
		<link rel="stylesheet" href="/tacview.css" />
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
		$tv->image_path = '/'; // Ensure asset paths resolve from root when served under /api

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
