<?php

declare(strict_types=1);

// Load configuration
$config = require_once __DIR__ . "/config.php";

// Load core tacview library
require_once __DIR__ . "/" . $config['core_path'] . "/tacview.php";

?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo htmlspecialchars($config['page_title']); ?></title>
		<link rel="stylesheet" href="<?php echo htmlspecialchars($config['core_path']); ?>/tacview.css" />
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

			// Check for XML files
			$xmlFiles = glob($config['debriefings_path']);
			
			// Store status messages to display at the bottom
			$statusMessages = "<div style='margin-top: 40px; padding: 20px; border-top: 1px solid #333;'>";
			$statusMessages .= "<p>Looking for XML files in debriefings folder...</p>";
			$statusMessages .= "<p>Found " . count($xmlFiles) . " XML files.</p>";
			
			if (count($xmlFiles) == 0) {
				$statusMessages .= "<p>No XML files found. Looking for other files...</p>";
				$allFiles = glob("debriefings/*");
				$statusMessages .= "<ul>";
				foreach ($allFiles as $file) {
					$statusMessages .= "<li>" . basename($file) . "</li>";
				}
				$statusMessages .= "</ul>";
				$statusMessages .= "<p><strong>Note:</strong> This application currently processes XML files only. You have an .acmi file which needs to be converted to XML format.</p>";
			}

			foreach ($xmlFiles as $filexml) {
				$statusMessages .= "<h2>Processed: " . basename($filexml) . "</h2>";
				$tv->proceedStats("$filexml","Mission Test");
				echo $tv->getOutput();
			}
			
			$statusMessages .= "</div>";
			
			// Output status messages at the bottom
			echo $statusMessages;

		?>
	</body>
</html>