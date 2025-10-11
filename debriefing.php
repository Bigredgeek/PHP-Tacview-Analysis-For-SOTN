<!DOCTYPE html>
<html>
	<head>
		<title>PHPTacview</title>
		<link rel="stylesheet" href="tacview.css" />
		<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
	</head>
	<body>
		<h1>PHP Tacview Debriefing</h1>
		<?php

			require_once "./tacview.php";

			$tv = new tacview("en");

			// Check for XML files
			$xmlFiles = glob("debriefings/*.xml");
			echo "<p>Looking for XML files in debriefings folder...</p>";
			echo "<p>Found " . count($xmlFiles) . " XML files.</p>";
			
			if (count($xmlFiles) == 0) {
				echo "<p>No XML files found. Looking for other files...</p>";
				$allFiles = glob("debriefings/*");
				echo "<ul>";
				foreach ($allFiles as $file) {
					echo "<li>" . basename($file) . "</li>";
				}
				echo "</ul>";
				echo "<p><strong>Note:</strong> This application currently processes XML files only. You have an .acmi file which needs to be converted to XML format.</p>";
			}

			foreach ($xmlFiles as $filexml) {
				echo "<h2>Processing: " . basename($filexml) . "</h2>";
				$tv->proceedStats("$filexml","Mission Test");
				echo $tv->getOutput();
			}

		?>
	</body>
</html>