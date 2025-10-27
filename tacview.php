<?php

declare(strict_types=1);

// PHPTacview
// Copyright (c) 2006 Julien "Ezor" RozÃ©

// History:
// 2025-10-25 (Updated for PHP 8.2+)
// * ADDED strict type declarations
// * MODERNIZED array syntax from [] to []
// * ADDED type hints for all functions and properties
// * IMPROVED PHP 8.2+ compatibility


//2023-03-09 (Updated by BuzyBee)
// * FIXED bug - hits reported as kills

// 2021-07-28 (Updated by BuzyBee)
// * ADDED aircraft identification photos for new aircraft
// * ADDED Occurrences of multiple projectiles fired at the same time. 
// * ADDED tag HasTakenOff (prev. called HasTakeOff)
// * ADDED stat - kills of trucks
// * IMPROVED readability (color scheme, spacing)
// * FIXED Many warnings about bad array indexes

// 2015-03-23 (Updated by Vyrtuoz)
// * ADDED Missing labels from English and French translations
// * FIXED Many warnings about bad array indexes
// * Optimized JPEG pictures (without loss)
// * Minor source code cleanup

// 2015-02-26 (Updated by Khamsin)
// * MODIFY arrays cause new XML

// 2011-08-01 (Updated by Aikanaro)
// * ADDED Italian localization
// * ADDED Group Field
// * ADDED Group in Event
// * MODIFIED css file colour
// * ADDED Destroyed in pilot stats
// * FIXED bug count display destroyed in pilot stats by Aikanaro
// * MODIFIED & ADDED icon IMAGES Bomb, Parachutist, Chaff, Flare, Hit
// * ADDED images in objectIcons
// * MODIFIED debriefing.php
// * FIXED bug display multy file .xml in debriefing.php
// * FIXED bug display Kill in pilots stats

// 2011-04-09 (Updated by Vyrtuoz)
// * ADDED Support for XML Debriefings v0.93
// * ADDED English localization
// * FIXED Localization files are now all in UTF-8
// * FIXED Player pictures paths
// * FIXED Several PHP warnings (not all of them)

// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA

class tacview
{
	public array $language = [];
	public string $htmlOutput = "";

	// Oggetto Airport
	public array $airport = [];
	public bool $tagAirportOpened = false;
	public int $airportCurrentId = 0;

	// Oggetto Primary
	public array $primaryObjects = [];
	public bool $tagPrimaryObjectOpened = false;
	public int $primaryObjectCurrentId = 0;

	// Oggetto Secondary
	public array $secondaryObjects = [];
	public bool $tagSecondaryObjectOpened = false;
	public int $secondaryObjectCurrentId = 0;

	// Oggetto Parent
	public array $parentObjects = [];
	public bool $tagParentObjectOpened = false;
	public int $parentObjectCurrentId = 0;

	// vettore oggetti -- non usato --
	public array $objects = [];
	public bool $tagObjectOpened = false;
	public int $objectCurrentId = 0;

	// vettore eventi
	public array $events = [];
	public bool $tagEventOpened = false;
	public int $eventCurrentId = 0;
	public array $stats = [];
	public array $weaponOwners = []; // Track weapon ID -> pilot who fired it
	public string $missionName = "";
	public mixed $xmlParser = null; // XMLParser object in PHP 8+
	public string $currentData = "";
	public bool $tagObjectsOpened = false;
	public bool $tagEventsOpened = false;
	public array $sam_enemies = [];
	public string $tagOpened = "";
	public mixed $startTime = null;
	public mixed $duration = null;
	public string $image_path = "";
	public mixed $firephp = null;
	// we log today’s date as an example. you could log whatever variable you want to

	//
	// constructor
	//
	public function __construct(string $aLanguage = "en")
	{
		$language_path = __DIR__ . "/languages/tacview_" . $aLanguage . ".php";
		if (file_exists($language_path)) {
			include $language_path;
			if (isset($_LANGUAGE) && is_array($_LANGUAGE)) {
				$this->language = $_LANGUAGE;
			} else {
				$this->language = [];
			}
		} else {
			$this->language = [];
		}
	}

	//
	// return language caption
	//
	public function L(string $aId): string
	{
		return isset($this->language[$aId]) ? $this->language[$aId] : $aId;
	}

	//
	// get correct object icon filename with fallback mapping
	//
	public function getObjectIcon(string $aircraftName): string
	{
		// Clean the aircraft name for filename
		$iconName = str_replace([" ","/"], ["_","_"], $aircraftName);
		
		// Icon mapping for missing files to existing alternatives
		$iconMappings = [
			'MiG-29_Fulcrum' => 'MiG-29A_Fulcrum-A',
			'Humvee' => 'HUMMER',
			'leopard-2A4' => 'LEOPARD2',
			'F-104_Starfighter' => 'F-16C_Fighting_Falcon', // Similar fighter jet
			'Mirage_F1_EE' => 'Mirage_2000C', // Similar Mirage variant
			'A-4E_Skyhawk' => 'AV-8B_Harrier_II_NA', // Similar attack aircraft
			'BTR-80' => 'BTR70', // Similar APC
			'T-72B' => 'M60', // Similar tank
			'MTLB' => 'M113', // Similar APC
			'ZU-23-2' => 'AVANGER', // Similar AA gun
			'Soldier_Redeye_LDM' => 'IGLA', // Similar MANPADS
			'SA-7_Grail' => 'IGLA', // Similar MANPADS
			'UAZ-469' => 'GAZ66' // Similar utility vehicle
		];
		
		// Check if we need to map this icon to an existing one
		if (isset($iconMappings[$iconName])) {
			$iconName = $iconMappings[$iconName];
		}
		
		// Prefer jpg, then png
		$basePath = $this->image_path . 'objectIcons/';
		$jpg = $iconName . '.jpg';
		$png = $iconName . '.png';
		// When building absolute/relative paths, $this->image_path is used at render time, so here we only return filename
		if (file_exists(__DIR__ . '/objectIcons/' . $jpg)) {
			return $jpg;
		}
		if (file_exists(__DIR__ . '/objectIcons/' . $png)) {
			return $png;
		}
		return $jpg; // default convention
	}

	//
	// sort statistics by Group first, then by Pilot Name within groups
	//
	public function sortStatsByGroupAndPilot(array $stats): array
	{
		// Convert to array with pilot names as keys for sorting
		$sortableArray = [];
		foreach ($stats as $pilotName => $stat) {
			// Only include entries that are Aircraft or Helicopter types
			if ($pilotName != "" && 
			    substr($pilotName, 0, 5) != "Pilot" && 
			    isset($stat["Aircraft"]) && 
			    isset($stat["Type"]) && 
			    ($stat["Type"] == "Aircraft" || $stat["Type"] == "Helicopter")) {
				$sortableArray[] = [
					'pilotName' => $pilotName,
					'group' => isset($stat["Group"]) ? $stat["Group"] : "ZZZ_NoGroup", // Put no-group entries at end
					'data' => $stat
				];
			}
		}
		
		// Sort by group first, then by pilot name
		usort($sortableArray, function($a, $b) {
			// Primary sort: by Group
			$groupCompare = strcasecmp($a['group'], $b['group']);
			if ($groupCompare !== 0) {
				return $groupCompare;
			}
			// Secondary sort: by Pilot Name within same group
			return strcasecmp($a['pilotName'], $b['pilotName']);
		});
		
		// Convert back to original format
		$sortedStats = [];
		foreach ($sortableArray as $entry) {
			$sortedStats[$entry['pilotName']] = $entry['data'];
		}
		
		return $sortedStats;
	}

	//
	// add HTML to the current output
	//
	public function addOutput(string $aHtml): void
	{
		$this->htmlOutput .= $aHtml;
	}

	//
	// return output html
	//
	public function getOutput(): string
	{
		return $this->htmlOutput;
	}

	//
	// return a formated timestamp
	//
	public function displayTime(float|int $aTime): string
	{
		$lTime        = $aTime;
		$lHour        = floor($lTime / 3600);
		$lHourDisplay = floor($lHour - (floor($lHour / 24) * 24));
		$lMinute      = floor(($lTime - ($lHour * 3600)) / 60);
		$lSecond      = floor($lTime - ($lHour * 3600) - $lMinute * 60);

		if ($lMinute == "")
		{
			$lMinute = "00";
		}
		else
		{
			if ($lMinute < 10)
			{
				$lMinute = "0" . $lMinute;
			}
		}

		if ($lSecond < 10)
		{
			$lSecond = "0" . $lSecond;
		}

		if ($lHourDisplay < 10)
		{
			$lHourDisplay = "0" . $lHourDisplay;
		}

		$lHTML = $lHourDisplay . ":" . $lMinute . ":" . $lSecond;

		return $lHTML;
	}

	//
	// Increase statistic (safe)
	//
	public function increaseStat(array &$Array, string|int $Key0, string|int|null $Key1 = null): void
	{
		if (isset($Key1))
		{

			if (!array_key_exists($Key0, $Array))
			{
				$Array[$Key0] = [];
			}

			if (!array_key_exists($Key1, $Array[$Key0]))
			{
				$Array[$Key0][$Key1] = 1;
			}
			else
			{
				$Array[$Key0][$Key1]++;
			}
		} else {

			if (!array_key_exists($Key0, $Array))
			{
				$Array[$Key0] = 1;
			}
			else
			{
				$Array[$Key0]++;
			}
		}
	}

	//
	// Retrieve stats count (safe)
	//
	public function getStat(array $Array, string|int $Key0, string|int|null $Key1 = null): mixed
	{

		if (isset($Array) && array_key_exists($Key0, $Array))
		{
			if (!isset($Key1))
			{
				return $Array[$Key0]['Count'];
			}

			if (array_key_exists($Key1, $Array[$Key0]))
			{
				return $Array[$Key0][$Key1]['Count'];
			}
		}

		return null;
	}

	//
	// proceed stats of the xml file
	//
	public function proceedStats(string $aFile, string $aMissionName): void
	{
		$this->htmlOutput  = "";
		$this->objects     = [];
		$this->events      = [];
		$this->stats       = [];

		// parse XML file to get events and objects

		$this->parseXML($aFile);

		// echo '============================================================================';
		// echo '<pre>'; print_r($this->events); echo '</pre>';
		// echo '============================================================================';

		if ($this->missionName == "")
		{
			$this->missionName = $aMissionName;
		}

		// some scripts

		$this->addOutput('<script type="text/javascript">');
		$this->addOutput('function showDetails(zoneAffiche, rowElement){');
		$this->addOutput('	var detailRow = document.getElementById(zoneAffiche);');
		$this->addOutput('	var pilotRow = rowElement || event.currentTarget;');
		$this->addOutput('	');
		$this->addOutput('	// Toggle display');
		$this->addOutput('	if(detailRow.style.display==""){');
		$this->addOutput('		detailRow.style.display="none";');
		$this->addOutput('		pilotRow.classList.remove("active-pilot");');
		$this->addOutput('	}else{');
		$this->addOutput('		// Hide all other detail rows and remove active class');
		$this->addOutput('		var allDetails = document.querySelectorAll(".hiddenRow");');
		$this->addOutput('		var allPilotRows = document.querySelectorAll("tr.statisticsTable");');
		$this->addOutput('		allDetails.forEach(function(row){ row.style.display="none"; });');
		$this->addOutput('		allPilotRows.forEach(function(row){ row.classList.remove("active-pilot"); });');
		$this->addOutput('		');
		$this->addOutput('		// Show this detail row and add active class');
		$this->addOutput('		detailRow.style.display="";');
		$this->addOutput('		pilotRow.classList.add("active-pilot");');
		$this->addOutput('	}');
		$this->addOutput('	return false;');
		$this->addOutput('}');
		$this->addOutput('</script>');

		// ***********************************************************
		// PRESENTATION TABLE - Mission Name, Time, Duration
		// ***********************************************************

		$this->addOutput('<h1>' . $this->L('information') . '</h1>');
		$this->addOutput('<table class="presentationTable">');
		$this->addOutput('<tr class="presentationTable">');
		$this->addOutput('<td class="presentationTable">' . $this->L('missionName') . ':</td>');
		$this->addOutput('<td class="presentationTable">' . $this->missionName . '</td>');
		$this->addOutput('</tr >');
		$this->addOutput('<tr class="presentationTable">');
		$this->addOutput('<td class="presentationTable">' . $this->L('missionTime') . ':</td>');
		$this->addOutput('<td class="presentationTable">' . $this->displayTime($this->startTime) . '</td>');
		$this->addOutput('</tr>');
		$this->addOutput('<tr class="presentationTable">');
		$this->addOutput('<td class="presentationTable">' . $this->L('missionDuration') . ':</td>');
		$this->addOutput('<td class="presentationTable">' . $this->displayTime($this->duration) . '</td>');
		$this->addOutput('</tr>');
		$this->addOutput('</table>');

		// ***********************************************************
		// Iterate through events
		// ***********************************************************

		foreach ($this->events as $key => $event)
		{
			// List pilots of Aircraft and Helicopters

			if ($event["PrimaryObject"]["Type"] == "Aircraft" or $event["PrimaryObject"]["Type"] == "Helicopter") 
			{
				if(array_key_exists("Pilot",$event["PrimaryObject"]))
				{
					$primaryObjectPilot = $event["PrimaryObject"]["Pilot"];
					// crea il ramo per ogni Pilota (di aereo o di elicottero)
					$this->stats[$primaryObjectPilot]["Aircraft"] = $event["PrimaryObject"]["Name"];
					$this->stats[$primaryObjectPilot]["Type"] = $event["PrimaryObject"]["Type"];
				}
				else
				{
					continue;
				}

				if(array_key_exists("Group",$event["PrimaryObject"]))
				{
					$this->stats[$primaryObjectPilot]["Group"] = $event["PrimaryObject"]["Group"] ?? ""; // ADDED field Group by Aikanaro
				}

				if (!array_key_exists("Events", $this->stats[$primaryObjectPilot]))
				{
					$this->stats[$primaryObjectPilot]["Events"] = [];
				}

				array_push($this->stats[$primaryObjectPilot]["Events"], $event);

				// fine creazione ramo

				switch ($event["Action"])
				{
					case "HasLanded":

						// Ensure pilot entry exists for HasLanded events - this can be the first event for a pilot
						if (!isset($this->stats[$primaryObjectPilot]))
						{
							// Create pilot entry if it doesn't exist
							$this->stats[$primaryObjectPilot]["Aircraft"] = $event["PrimaryObject"]["Name"];
							$this->stats[$primaryObjectPilot]["Group"]    = $event["PrimaryObject"]["Group"] ?? "";
							$this->stats[$primaryObjectPilot]["Type"]     = $event["PrimaryObject"]["Type"];

							if (!array_key_exists("Events", $this->stats[$primaryObjectPilot]))
							{
								$this->stats[$primaryObjectPilot]["Events"] = [];
							}
						}

						$this->increaseStat($this->stats[$primaryObjectPilot], "Lands", "Count");

						if (!isset($event["Airport"]))
						{
							$this->increaseStat($this->stats[$primaryObjectPilot]["Lands"], "No Airport");
						}
						else
						{
							$this->increaseStat($this->stats[$primaryObjectPilot]["Lands"], $event["Airport"]["Name"]);
						}

						break;

					case "HasTakeOff":	// obsolete
					case "HasTakenOff":

						// Ensure pilot entry exists for HasTakenOff events - this can be the first event for a pilot
						if (!isset($this->stats[$primaryObjectPilot]))
						{
							// Create pilot entry if it doesn't exist
							$this->stats[$primaryObjectPilot]["Aircraft"] = $event["PrimaryObject"]["Name"];
							$this->stats[$primaryObjectPilot]["Group"]    = $event["PrimaryObject"]["Group"] ?? "";
							$this->stats[$primaryObjectPilot]["Type"]     = $event["PrimaryObject"]["Type"];

							if (!array_key_exists("Events", $this->stats[$primaryObjectPilot]))
							{
								$this->stats[$primaryObjectPilot]["Events"] = [];
							}
						}

						$this->increaseStat($this->stats[$primaryObjectPilot], "TakeOffs", "Count");

						if (!isset($event["Airport"]))
						{
							$this->increaseStat($this->stats[$primaryObjectPilot]["TakeOffs"], "No Airport");
						}
						else
						{
							$this->increaseStat($this->stats[$primaryObjectPilot]["TakeOffs"], $event["Airport"]["Name"]);
						}

						break;

				case "HasFired":
				
					// Ensure pilot entry exists for HasFired events - this can be the first event for a pilot
					if (!isset($this->stats[$primaryObjectPilot]))
					{
						// Create pilot entry if it doesn't exist
						$this->stats[$primaryObjectPilot]["Aircraft"] = $event["PrimaryObject"]["Name"];
						$this->stats[$primaryObjectPilot]["Group"]    = $event["PrimaryObject"]["Group"] ?? "";
						$this->stats[$primaryObjectPilot]["Type"]     = $event["PrimaryObject"]["Type"];

						if (!array_key_exists("Events", $this->stats[$primaryObjectPilot]))
						{
							$this->stats[$primaryObjectPilot]["Events"] = [];
						}
					}
				
					if	(	array_key_exists("SecondaryObject",$event) and
							array_key_exists("Type",$event["SecondaryObject"]) and
							$event["SecondaryObject"]["Type"] != "Parachutist"
						)
					{
						$this->increaseStat($this->stats[$primaryObjectPilot], "Fired", "Count");
						$this->increaseStat($this->stats[$primaryObjectPilot], "Fired", $event["SecondaryObject"]["Name"]);
						
						// Track weapon ownership for kill attribution
						if (isset($event["SecondaryObject"]["ID"]) && 
						    ($event["SecondaryObject"]["Type"] == "Missile" || $event["SecondaryObject"]["Type"] == "Bomb"))
						{
							$this->weaponOwners[$event["SecondaryObject"]["ID"]] = [
								'pilot' => $primaryObjectPilot,
								'aircraft' => $event["PrimaryObject"]["Name"],
								'group' => $event["PrimaryObject"]["Group"] ?? "",
								'type' => $event["PrimaryObject"]["Type"],
								'weapon' => $event["SecondaryObject"]["Name"],
								'time' => $event["Time"]
							];
						}
					}

					break;					case "HasBeenDestroyed":

						// Ensure pilot entry exists for HasBeenDestroyed events - this can be the first event for a pilot
						if (!isset($this->stats[$primaryObjectPilot]))
						{
							// Create pilot entry if it doesn't exist
							$this->stats[$primaryObjectPilot]["Aircraft"] = $event["PrimaryObject"]["Name"];
							$this->stats[$primaryObjectPilot]["Group"]    = $event["PrimaryObject"]["Group"] ?? "";
							$this->stats[$primaryObjectPilot]["Type"]     = $event["PrimaryObject"]["Type"];

							if (!array_key_exists("Events", $this->stats[$primaryObjectPilot]))
							{
								$this->stats[$primaryObjectPilot]["Events"] = [];
							}
						}

					$this->increaseStat($this->stats[$primaryObjectPilot], "Destroyed", "Count");
					
					$secondaryObjectPilot = null;
					
					if (	array_key_exists("SecondaryObject",$event) and
							array_key_exists("Pilot", $event["SecondaryObject"]) 
						)
					{							
						$secondaryObjectPilot = $event["SecondaryObject"]["Pilot"];
						
						if (!isset($this->stats[$secondaryObjectPilot]))
						{
							// If Pilot of Seconday Object does not exist  yet, create them.

							$this->stats[$secondaryObjectPilot]["Aircraft"] = $event["SecondaryObject"]["Name"];
							$this->stats[$secondaryObjectPilot]["Group"]    = $event["SecondaryObject"]["Group"] ?? "";
							$this->stats[$secondaryObjectPilot]["Type"]     = $event["SecondaryObject"]["Type"];

							if (!array_key_exists("Events", $this->stats[$secondaryObjectPilot]))
							{
								$this->stats[$secondaryObjectPilot]["Events"] = [];
							}
							
						}
						
						array_push($this->stats[$secondaryObjectPilot]["Events"], $event);
					}
					else
					{
						// No explicit killer - try to infer from weapon tracking
						// Look for weapons destroyed at approximately the same time as this aircraft
						$currentTime = $event["Time"];
						$currentID = $event["PrimaryObject"]["ID"];
						
						// Search recent events for weapon destruction that matches this kill
						foreach ($this->events as $otherEvent)
						{
							// Look for weapon HasBeenDestroyed events within 1 second
							if (isset($otherEvent["Action"]) && $otherEvent["Action"] == "HasBeenDestroyed" &&
							    isset($otherEvent["PrimaryObject"]["Type"]) && 
							    ($otherEvent["PrimaryObject"]["Type"] == "Missile" || $otherEvent["PrimaryObject"]["Type"] == "Bomb") &&
							    isset($otherEvent["Time"]) &&
							    abs($otherEvent["Time"] - $currentTime) < 1.0 &&
							    isset($otherEvent["PrimaryObject"]["ID"]))
							{
								$weaponID = $otherEvent["PrimaryObject"]["ID"];
								
								// Check if we know who fired this weapon
								if (isset($this->weaponOwners[$weaponID]))
								{
									$weaponOwner = $this->weaponOwners[$weaponID];
									$secondaryObjectPilot = $weaponOwner['pilot'];
									
									// Make sure pilot exists in stats
									if (!isset($this->stats[$secondaryObjectPilot]))
									{
										$this->stats[$secondaryObjectPilot]["Aircraft"] = $weaponOwner['aircraft'];
										$this->stats[$secondaryObjectPilot]["Group"]    = $weaponOwner['group'];
										$this->stats[$secondaryObjectPilot]["Type"]     = $weaponOwner['type'];

										if (!array_key_exists("Events", $this->stats[$secondaryObjectPilot]))
										{
											$this->stats[$secondaryObjectPilot]["Events"] = [];
										}
									}
									
									// Add event to killer's record
									array_push($this->stats[$secondaryObjectPilot]["Events"], $event);
									break; // Found the killer, stop searching
								}
							}
						}
						
						// If still no killer found, skip this kill attribution
						if ($secondaryObjectPilot === null)
						{
							continue 2;
						}
					}							
						
					if (!array_key_exists("Killed", $this->stats[$secondaryObjectPilot]))
					{
						$this->stats[$secondaryObjectPilot]["Killed"] = [];
					}

					$this->increaseStat($this->stats[$secondaryObjectPilot]["Killed"], $event["PrimaryObject"]["Type"], "Count"); 
					$this->increaseStat($this->stats[$secondaryObjectPilot]["Killed"], $event["PrimaryObject"]["Type"], $event["PrimaryObject"]["Name"]);						break;

					case "HasBeenHitBy":

						// Ensure pilot entry exists for HasBeenHitBy events - this can be the first event for a pilot
						if (!isset($this->stats[$primaryObjectPilot]))
						{
							// Create pilot entry if it doesn't exist
							$this->stats[$primaryObjectPilot]["Aircraft"] = $event["PrimaryObject"]["Name"];
							$this->stats[$primaryObjectPilot]["Group"]    = $event["PrimaryObject"]["Group"] ?? "";
							$this->stats[$primaryObjectPilot]["Type"]     = $event["PrimaryObject"]["Type"];

							if (!array_key_exists("Events", $this->stats[$primaryObjectPilot]))
							{
								$this->stats[$primaryObjectPilot]["Events"] = [];
							}
						}

						$this->increaseStat($this->stats[$primaryObjectPilot], "Hit", "Count");
						$this->increaseStat($this->stats[$primaryObjectPilot], "Hit", $event["SecondaryObject"]["Name"]);

						if 	(	array_key_exists("ParentObject",$event) and
								array_key_exists("Pilot", $event["ParentObject"])
							)
						{

							$parentObjectPilot = $event["ParentObject"]["Pilot"];
							
							if (!isset($this->stats[$parentObjectPilot]))
							{
								// If Pilot of Parent Object does not exist yet, create them.
	
								$this->stats[$parentObjectPilot]["Aircraft"] = $event["ParentObject"]["Name"];
								$this->stats[$parentObjectPilot]["Group"]    = $event["ParentObject"]["Group"] ?? "";
								$this->stats[$parentObjectPilot]["Type"]     = $event["ParentObject"]["Type"];
	
								if (!array_key_exists("Events", $this->stats[$parentObjectPilot]))
								{
									$this->stats[$parentObjectPilot]["Events"] = [];
								}
							}
													
							array_push($this->stats[$parentObjectPilot]["Events"], $event);
						}
						else
						{
							continue 2;
						}

						// Friendly Fire?

							if 	(	array_key_exists("Coalition", $event["ParentObject"]) and
									array_key_exists("Coalition",$event["PrimaryObject"]) and
									$event["ParentObject"]["Coalition"] == $event["PrimaryObject"]["Coalition"]
								)
						{
								$this->increaseStat($this->stats[$parentObjectPilot], "FriendlyFire", "Count");
								$this->increaseStat($this->stats[$parentObjectPilot], "FriendlyFire", $event["PrimaryObject"]["Name"]);
						}
											
						break;
				}
			}
			elseif ($event["PrimaryObject"]["Type"] == "Tank" or
					$event["PrimaryObject"]["Type"] == "SAM/AAA" or
					$event["PrimaryObject"]["Type"] == "Ship" or 
					$event["PrimaryObject"]["Type"] == "Car" )

					// Aggiunto da 36.Sparrow per consentire le statistiche sugli abbattimenti A/G ed elicotteri
				{
					switch ($event["Action"])
					{
						case "HasBeenHitBy":
						
							if 	(	array_key_exists("ParentObject",$event) and
									array_key_exists("Pilot", $event["ParentObject"])
								)
							{
								$parentObjectPilot = $event["ParentObject"]["Pilot"];

								if (!isset($this->stats[$parentObjectPilot]))
								{
									// If Pilot of Parent Object does not exist yet, create them.

									$this->stats[$parentObjectPilot]["Aircraft"] = $event["ParentObject"]["Name"];
									$this->stats[$parentObjectPilot]["Group"]    = $event["ParentObject"]["Group"] ?? "";
									$this->stats[$parentObjectPilot]["Type"]     = $event["ParentObject"]["Type"];

									if (!array_key_exists("Events", $this->stats[$parentObjectPilot]))
									{
										$this->stats[$parentObjectPilot]["Events"] = [];
									}
								}

								array_push($this->stats[$parentObjectPilot]["Events"], $event);
							}
							else
							{
								continue 2;
							}

							// Was it Friendly Fire?

							if 	(	array_key_exists("Coalition", $event["ParentObject"]) and
									array_key_exists("Coalition",$event["PrimaryObject"]) and
									$event["ParentObject"]["Coalition"] == $event["PrimaryObject"]["Coalition"]
								)
							{
								  $this->increaseStat($this->stats[$parentObjectPilot], "FriendlyFire", "Count");
								  $this->increaseStat($this->stats[$parentObjectPilot], "FriendlyFire", $event["PrimaryObject"]["Name"]);
							}

						break;

						case "HasBeenDestroyed":

							if 	(	array_key_exists("SecondaryObject",$event) and
									array_key_exists("Pilot", $event["SecondaryObject"])
								)
							{
								$secondaryObjectPilot = $event["SecondaryObject"]["Pilot"];

								if (!isset($this->stats[$secondaryObjectPilot]))
								{
									// If Pilot of Secondary Object does not exist yet, create them.

									$this->stats[$secondaryObjectPilot]["Aircraft"] = $event["SecondaryObject"]["Name"];
									$this->stats[$secondaryObjectPilot]["Group"]    = $event["SecondaryObject"]["Group"] ?? "";
									$this->stats[$secondaryObjectPilot]["Type"]     = $event["SecondaryObject"]["Type"];

									if (!array_key_exists("Events", $this->stats[$secondaryObjectPilot]))
									{
										$this->stats[$secondaryObjectPilot]["Events"] = [];
									}
								}

								array_push($this->stats[$secondaryObjectPilot]["Events"], $event);
							}
							else
							{
								continue 2;
							}

							if (!array_key_exists("Killed", $this->stats[$event["SecondaryObject"]["Pilot"]]))
							{
								$this->stats[$event["SecondaryObject"]["Pilot"]]["Killed"] = [];
							}

							$this->increaseStat($this->stats[$secondaryObjectPilot]["Killed"], $event["PrimaryObject"]["Type"], "Count");
							$this->increaseStat($this->stats[$secondaryObjectPilot]["Killed"], $event["PrimaryObject"]["Type"], $event["PrimaryObject"]["Name"]);

						break;
					}

				}
		}


		// ***********************************************************
		// STATISTICS TABLE - Display Stats per pilot
		// ***********************************************************

		$this->addOutput('<h1>' . $this->L('statsByPilot') . '</h1>');
		$this->addOutput('<table class="statisticsTable">');
		$this->addOutput('<tr class="statisticsTable">');
		$this->addOutput('<th class="statisticsTable">' . $this->L('pilotName') . '</th>');
	//  $this->addOutput('<th class="statisticsTable">' . $this->L('model') . '</th>');
		$this->addOutput('<th colspan="2" class="statisticsTable">' . $this->L('aircraft') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('group') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('takeoff') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('landing') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('firedArmement') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('killedAircraft') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('killedHelo') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('killedShip') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('killedSAM') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('killedTank') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('killedCar') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('teamKill') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('hit') . '</th>');
		$this->addOutput('<th class="statisticsTable">' . $this->L('destroyed') . '</th>');
		$this->addOutput('</tr>');

		//$class = "row1";

		// Sort statistics by Group first, then by Pilot Name within groups
		$sortedStats = $this->sortStatsByGroupAndPilot($this->stats);

		foreach ($sortedStats as $key => $stat)
		{

			// Only display aircraft and helicopters (check both Aircraft field and Type)
			if ($key != "" and 
			    substr($key, 0, 5) != "Pilot" and 
			    isset($stat["Aircraft"]) and 
			    isset($stat["Type"]) and 
			    ($stat["Type"] == "Aircraft" or $stat["Type"] == "Helicopter"))
			{
				// $this->displayEventRow($event);
				$this->addOutput('<tr class="statisticsTable" onclick="showDetails(\'' . $key . '\', this); return false;">');
				$this->addOutput('<td class="statisticsTable">' . $key . '</td>');
				$this->addOutput('<td class="statisticsTable"><img class="statisticsTable" src="' . $this->image_path . 'objectIcons/' . $this->getObjectIcon($stat["Aircraft"]) . '" alt=""/></td>');
				$this->addOutput('<td class="statisticsTable">' . $stat["Aircraft"] . '</td>');

				if(array_key_exists("Group",$stat))
				{
					$this->addOutput('<td class="statisticsTable">' . $stat["Group"] . '</td>');
				}
				else
				{
					$this->addOutput('<td class=statisticsTable></td>');
				}

				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "TakeOffs") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Lands") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Fired") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Killed", "Aircraft") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Killed", "Helicopter") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Killed", "Ship") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Killed", "SAM/AAA") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Killed", "Tank") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Killed", "Car") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "FriendlyFire") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Hit") . '</td>');
				$this->addOutput('<td class="statisticsTable">' . $this->getStat($stat, "Destroyed") . '</td>');
				$this->addOutput('</tr>');

			// ***********************************************************
			// HIDDEN ROW & TABLE - Drill Down Per Pilot
			// ***********************************************************

				$this->addOutput('<tr id="'.$key.'" class="hiddenRow" style="display: none;">');
				$this->addOutput('<td class="hiddenRow" colspan="16">');
				$this->addOutput('<h2>' . $key . '</h2>');

				$this->addOutput('<table class="hiddenStatsTable">');

				// FIRST ROW - Aircraft icon

				$this->addOutput('<tr class="hiddenStatsTable">');
				
				$this->addOutput('<td class="hiddenStatsTable" colspan="3">');

				$this->addOutput('<h2>' . $this->L("aircraft") . '</h2>');

				if (isset($stat["Aircraft"]))
					$x_air = $stat["Aircraft"];
				else
					$x_air = "";

				$this->addOutput('<img class="hiddenStatsTable" src="' . $this->image_path . 'objectIcons/' . $this->getObjectIcon($x_air) . '" alt="" />');

				$this->addOutput('<h2>' . $this->L("pilotStats") . '</h2>');

				$this->addOutput('</td>');
				$this->addOutput('</tr>');

				// SECOND ROW - First cell

				$this->addOutput('<tr class="hiddenStatsTable">');
				
				$this->addOutput('<td class="hiddenStatsTableRow2">');

				//Takeoff
				$this->addOutput('<span>' . $this->L("takeoff_long") . '</span> :');

				if (isset($stat["TakeOffs"]) and is_array($stat["TakeOffs"]))
				{
					foreach ($stat["TakeOffs"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["TakeOffs"]) or $stat["TakeOffs"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Landings

				$this->addOutput('<span>' . $this->L("landing_long") . ' :</span>');

				if (isset($stat["Lands"]) and is_array($stat["Lands"]))
				{
					foreach ($stat["Lands"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Lands"]) or $stat["Lands"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Fired Weapons

				$this->addOutput('<span>' . $this->L("firedArmement_long") . ' :</span>');

				if (isset($stat["Fired"]) and is_array($stat["Fired"]))
				{
					foreach ($stat["Fired"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Fired"]) or $stat["Fired"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				$this->addOutput('</td>');

				// SECOND ROW - Second cell

				$this->addOutput('<td class="hiddenStatsTableRow2">');

				// Friendly Fire

				$this->addOutput('<span>' . $this->L("teamKill") . ' :</span>');

				if (isset($stat["Killed"]["Destroyed"]) and is_array($stat["FriendlyFire"]))
				{
					foreach ($stat["FriendlyFire"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["FriendlyFire"]) or $stat["FriendlyFire"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Hit by

				$this->addOutput('<span>' . $this->L("hitBy") . ' :</span>');

				if (isset($stat["Hit"]) and is_array($stat["Hit"]))
				{
					foreach ($stat["Hit"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Hit"]) or $stat["Hit"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Destroyed

				$this->addOutput('<span>' . $this->L("destroyed") . ' :</span>'); // ADDED Destroyed in pilot stats by Aikanaro

				if (isset($stat["Destroyed"]) and is_array($stat["Destroyed"]))
				{
					foreach ($stat["Destroyed"] as $v)
					{
						if ($v != "Count")
						{
							$this->addOutput('<p>(' . $v . ')</p>'); // Fix bug count display destroyed in pilot stats by Aikanaro
						}
					}
				}

				if (!isset($stat["Destroyed"]) or $stat["Destroyed"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				$this->addOutput('</td>');

				// SECOND ROW - Third Cell

				$this->addOutput('<td class="hiddenStatsTableRow2">');

				// Kill A/A

				$this->addOutput('<span>' . $this->L("killedAircraft") . ' :</span>');

				if (isset($stat["Killed"]["Aircraft"]) and is_array($stat["Killed"]["Aircraft"]))
				{
					foreach ($stat["Killed"]["Aircraft"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Killed"]["Aircraft"]) or $stat["Killed"]["Aircraft"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')<p/>');
				}

				// Kill Helo

				$this->addOutput('<span>' . $this->L("killedHelo") . ' :</span>');

				if (isset($stat["Killed"]["Helicopter"]) and is_array($stat["Killed"]["Helicopter"]))
				{
					foreach ($stat["Killed"]["Helicopter"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Killed"]["Helicopter"]) or $stat["Killed"]["Helicopter"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Kill Ship

				$this->addOutput('<span>' . $this->L("killedShip") . ' :</span>');

				if (isset($stat["Killed"]["Ship"]) and is_array($stat["Killed"]["Ship"]))
				{
					foreach ($stat["Killed"]["Ship"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Killed"]["Ship"]) or $stat["Killed"]["Ship"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Kill SAM/AAA

				$this->addOutput('<span>' . $this->L("killedSAM") . ' :</span>');

				if (isset($stat["Killed"]["SAM/AAA"]) and is_array($stat["Killed"]["SAM/AAA"]))
				{
					foreach ($stat["Killed"]["SAM/AAA"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if (!isset($stat["Killed"]["SAM/AAA"]) or $stat["Killed"]["SAM/AAA"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Kill Tank

				$this->addOutput('<span>' . $this->L("killedTank") . ' :</span>');

				if (isset($stat["Killed"]["Tank"]) and is_array($stat["Killed"]["Tank"]))
				{
					foreach ($stat["Killed"]["Tank"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if 	(!isset($stat["Killed"]["Tank"]) or $stat["Killed"]["Tank"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				// Kill Car

				$this->addOutput('<span>' . $this->L("killedCar") . ' :</span>');

				if (isset($stat["Killed"]["Car"]) and is_array($stat["Killed"]["Car"]))
				{
					foreach ($stat["Killed"]["Car"] as $k => $v)
					{
						if ($k != "Count")
						{
							$this->addOutput('<p>&nbsp;' . $k . ' (' . $v . ')</p>');
						}
					}
				}

				if 	(!isset($stat["Killed"]["Car"]) or $stat["Killed"]["Car"]["Count"] == "")
				{
					$this->addOutput('<p>(' . $this->L("nothing") . ')</p>');
				}

				$this->addOutput('</td>');

				/*if (isset($stat["Hit"]) and $stat["Hit"]["Count"] != "")
				{
					$this->addOutput('<td>');
					$this->addOutput('</td>');
				}*/

				$this->addOutput('</tr>');

				//THIRD ROW - Events per pilot table

				$this->addOutput('<tr class="hiddenStatsTable">');

				$this->addOutput('<td class="hiddenStatsTable" colspan="3">');
				$this->addOutput('<h2>' . $this->L("events") . '</h2>');

				$this->addOutput('<table class="hiddenEventsTable">');
				$this->addOutput('<tr>');
				$this->addOutput('<th>' . $this->L('time') . '</th>');
				$this->addOutput('<th>' . $this->L('type') . '</th>');
				$this->addOutput('<th>' . $this->L('action') . '</th>');
				$this->addOutput('</tr>');

				foreach ($stat["Events"] as $key => $event)
				{
					$this->displayEventRow($event);
				}

				$this->addOutput('</table>');

				$this->addOutput('</td>');
				$this->addOutput('</tr>');

				$this->addOutput('</table>');

				$this->addOutput('</td>');
				$this->addOutput('</tr>');

			}
		}

		$this->addOutput('</table>');

		// ***********************************************************
		// EVENTS TABLE - Display all events
		// ***********************************************************

		$this->addOutput('<h1>' . $this->L('events') . '</h1>');
		$this->addOutput('<table class="eventsTable">');
		$this->addOutput('<tr>');
		$this->addOutput('<th>' . $this->L('time') . '</th>');
		$this->addOutput('<th>' . $this->L('type') . '</th>');
		$this->addOutput('<th>' . $this->L('action') . '</th>');
		$this->addOutput('</tr>');

		foreach ($this->events as $key => $event)
		{
			$this->displayEventRow($event);
		}

		$this->addOutput('</table>');
	}

	// Add to output informations of one event

	public function displayEventRow(array $event): void
	{
		// hit ? des ?
		$hit = false;

		if ($event["Action"] == "HasBeenHitBy" or $event["Action"] == "HasBeenDestroyed")
		{
			$hit = true;
		}

		$this->addOutput('<tr>');

		// Time
		$this->addOutput('<td>');
		$this->addOutput($this->displayTime($this->startTime + $event["Time"]));
		$this->addOutput('</td>');

		// Type
		$this->addOutput('<td class="ptv_rowType">');

		switch ($event["PrimaryObject"]["Type"])
		{
			case "SAM/AAA":

				$lImage = '<img src="' . $this->image_path . 'categoryIcons/SAM-AAA_' . $event["PrimaryObject"]["Coalition"] . '.gif" alt="" />';

				break;

			case "Parachutist":

				$lImage = '<img src="' . $this->image_path . 'categoryIcons/Parachutist_.gif" alt="" />'; // ADDED icon Parachutis by Aikanaro

				break;

			case "Bomb":

				$lImage = '<img src="' . $this->image_path . 'categoryIcons/Bomb_' . $event["PrimaryObject"]["Coalition"] . '.gif" alt="" />'; // ADDED icon Bomb by Aikanaro

				break;

			case "Chaff":

				$lImage = '<img src="' . $this->image_path . 'categoryIcons/Chaff_' . $event["PrimaryObject"]["Coalition"] . '.gif" alt="" />'; // Added icon Chaff by Aikanaro

				break;

			case "Flare":

				$lImage = '<img src="' . $this->image_path . 'categoryIcons/Flare_' . $event["PrimaryObject"]["Coalition"] . '.gif" alt="" />'; // Added icon Flare by Aikanaro

				break;

			default:

				$lImage = '<img src="' . $this->image_path . 'categoryIcons/' . $event["PrimaryObject"]["Type"] . '_' . $event["PrimaryObject"]["Coalition"] . '.gif" alt="" />';

				break;
		}

		if ($hit === true and $event["Action"] == "HasBeenHitBy")
		{
			$lImage = '<img src="' . $this->image_path . 'categoryIcons/hit.gif" alt="" />';
		}

		$this->addOutput($lImage);
		$this->addOutput('</td>');

		// Name

		$class = "";

		if ($hit === true)
		{
			$class = $event["Action"] == "HasBeenHitBy" ? 'rowHit' : 'rowDestroy';

			if (	array_key_exists("SecondaryObject", $event) and 
					array_key_exists("Coalition", $event["PrimaryObject"]) and
					array_key_exists("Coalition", $event["SecondaryObject"]) and
					$event["PrimaryObject"]["Coalition"] == $event["SecondaryObject"]["Coalition"])
			{
				$class = "rowTeamKill";
			}
		}

		if ($class != "rowDestroy" && $class != "rowTeamKill")
		{
			// echo "clas to coalition:".var_dump($event["PrimaryObject"]);
			if (array_key_exists("Coalition", $event["PrimaryObject"]) and isset($event["PrimaryObject"]["Coalition"]))
			{
				$class = 'row' . $event["PrimaryObject"]["Coalition"];
			}
			else
			{
				$class = "other";
			}
		}
		$this->addOutput('<td class="ptv_' . $class . '">');

		$lmsg = "";

		$nameExists = array_key_exists("Name", $event["PrimaryObject"]);
		$pilotExists = array_key_exists("Pilot", $event["PrimaryObject"]) and $event["PrimaryObject"]["Pilot"] != "";
		$groupExists = array_key_exists("Group", $event["PrimaryObject"]) and $event["PrimaryObject"]["Group"] ?? "" != "";

		/*if($class == "rowTeamKill")
		{
			$lmsg = $lmsg . $this->L('teamKill');
		}*/

		if ($nameExists)
		{
			$lmsg = $lmsg . " " . $event["PrimaryObject"]["Name"] . " ";
		}

		if ($pilotExists)
		{

			$lmsg = $lmsg . "(" . $event["PrimaryObject"]["Pilot"] . ")";
		}

		if($groupExists)
		{

			$lmsg = $lmsg . " [" . $event["PrimaryObject"]["Group"] ?? "" . "] ";	// ADDED Group in Event by Aikanaro
		}

		$this->addOutput($lmsg . $this->L($event["Action"]) . " ");

		// Action
		switch ($event["Action"])
		{

			case "HasLanded":
			case "HasTakeOff":	// obsolete
			case "HasTakenOff":

				if (isset($event["Airport"]) and $event["Airport"] != "")
				{
					$this->addOutput(' <img src="' . $this->image_path . 'categoryIcons/airport.gif" alt="" /> ' . $event["Airport"]["Name"]);
				}
				else if(	array_key_exists("SecondaryObject", $event) and
							array_key_exists("Type", $event["SecondaryObject"]) and
							$event["SecondaryObject"]["Type"] == "Carrier")
				{
					$this->addOutput(' <img src="' . $this->image_path . 'categoryIcons/airport.gif" alt="" /> ' . $event["SecondaryObject"]["Name"]);

				}
				else
				{
					$this->addOutput(' <img src="' . $this->image_path . 'categoryIcons/airport.gif" alt="" /> ');
				}

				break;

			case "HasBeenHitBy":

				// echo "hasbeebhit_>".$event["SecondaryObject"]["ID"];

				if(array_key_exists("SecondaryObject",$event))
				{
					$SecondaryObject = $event["SecondaryObject"];

					if(array_key_exists("Coalition",$SecondaryObject))
					{
						$SecondaryObjectCoalition = $SecondaryObject["Coalition"];

						$this->addOutput(' <img src="' . $this->image_path . 'categoryIcons/Mini_Missile_' . $SecondaryObjectCoalition . '.gif" alt="" /> ');

					}

					if (array_key_exists("Occurrences", $event))
					{
						$this->addOutput(' ' . $event["Occurrences"] . ' x ');
					}

					$this->addOutput($SecondaryObject["Name"]);

				}
				else	// No secondary object = no more info available
				{
					$this->addOutput('???');
				}

				if(array_key_exists("ParentObject",$event))
				{
					$ParentObject = $event["ParentObject"];

					if(array_key_exists("Pilot",$ParentObject))
					{
						$this->addOutput(' <i>[' . $ParentObject["Name"] . ' (' . $ParentObject["Pilot"] . ')</i>]');
					}
				}

				break;

			case "HasFired":

				if (array_key_exists("SecondaryObject",$event) && array_key_exists("Coalition", $event["SecondaryObject"]))
				{
					$this->addOutput(' <img src="' . $this->image_path . 'categoryIcons/Mini_Missile_' . $event["SecondaryObject"]["Coalition"] . '.gif" alt="" /> ');
				}
				if (array_key_exists("Occurrences", $event))
				{
					$this->addOutput(' ' . $event["Occurrences"] . ' x ');
				}

				$this->addOutput($event["SecondaryObject"]["Name"]);

				break;
				
				
			case "HasBeenDestroyed":
			
				if (array_key_exists("SecondaryObject",$event) and array_key_exists("Pilot",$event["SecondaryObject"]))
				{				
					$this->addOutput(' by ' . $event["SecondaryObject"]["Pilot"]);
				}

				break;
		}

		$this->addOutput('</td>');
		$this->addOutput('</tr>');
	}


	// Aggiunto da 53.Sparrow per consentire l'utilizzo della funzione anche per le vecchie versioni di php
	public function date_parse_from_format(string $format, string $date): array
	{
		$dt     = [
			'hour' => '',
			'minute' => '',
			'second' => '',
			'year' => '',
			'month' => '',
			'day' => '',
			'other' => ''
		];
		// "YYYY?mm?dd?HH?ii?ss?"
		$dMask  = [
			'H' => 'hour',
			'i' => 'minute',
			's' => 'second',
			'Y' => 'year',
			'm' => 'month',
			'd' => 'day',
			'?' => 'other'
		];
		$format = preg_split('//', $format, -1, PREG_SPLIT_NO_EMPTY);
		$date   = preg_split('//', $date, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($date as $k => $v)
		{

			if ($dMask[$format[$k]])
			{
				$dt[$dMask[$format[$k]]] .= $v;
			}
		}

		return $dt;
	}

	//
	// Parse XML file and get events and objects
	//
	public function parseXML(string $aFile): void
	{
		$this->xmlParser = xml_parser_create("UTF-8");

		xml_parser_set_option($this->xmlParser, XML_OPTION_CASE_FOLDING, false);
		xml_set_element_handler($this->xmlParser, [$this, "startTag"], [$this, "endTag"]);
		xml_set_character_data_handler($this->xmlParser, [$this, "cdata"]);

		$lXmlData = file_get_contents($aFile);

		$data = xml_parse($this->xmlParser, $lXmlData);
		if (!$data)
		{
			die(sprintf("XML error: %s at line %d", xml_error_string(xml_get_error_code($this->xmlParser)), xml_get_current_line_number($this->xmlParser)));
		}

		xml_parser_free($this->xmlParser);
	}
	
	public function startTag(mixed $aParser, string $aName, array $aAttrs): void
	{
		$this->currentData = "";
		/*
		 * // vettore generale Objects -- non esiste piu' --- if($aName == "Objects") { $this->tagObjectsOpened = true; } if($this->tagObjectsOpened === true) { if($aName == "Object") { $this->objectCurrentId = $aAttrs['ID']; $this->tagObjectOpened = true; } if($aName == "Parent") { if(array_key_exists('ID',$aAttrs)) { // Tacview 0.85 (obsolete) $this->objects[$this->objectCurrentId][$aName] = $this->objects[$aAttrs['ID']]; } } }
		 */
		// vettore generale Events
		if ($aName == "Events")
		{
			$this->tagEventsOpened = true;
		}

		if ($this->tagEventsOpened === true)
		{

			if ($aName == "Event")
			{
				$lID                  = $this->eventCurrentId + 1;
				$this->eventCurrentId = $lID;
				$this->tagEventOpened = true;
			}

			if ($aName == "PrimaryObject")
			{
				$this->tagPrimaryObjectOpened = true;
			}

			if ($aName == "SecondaryObject")
			{
				$this->tagSecondaryObjectOpened = true;
			}

			if ($aName == "ParentObject")
			{
				$this->tagParentObjectOpened = true;
			}

			if ($aName == "Airport")
			{
				$this->tagAirportOpened = true;
			}

			if ($aName == "PrimaryObject")
			{
				if (array_key_exists('ID', $aAttrs))
				{
					$this->events[$this->eventCurrentId][$aName]['ID'] = $aAttrs['ID'];
				}
			}

			if ($aName == "SecondaryObject")
			{
				if (array_key_exists('ID', $aAttrs))
				{
					$this->events[$this->eventCurrentId][$aName]['ID'] = $aAttrs['ID'];
				}
			}

			if ($aName == "ParentObject")
			{
				if (array_key_exists('ID', $aAttrs))
				{
					$this->events[$this->eventCurrentId][$aName]['ID'] = $aAttrs['ID'];
				}
			}
		}
	}

	public function cdata(mixed $aParser, string $aData): void
	{

		if (trim($aData))
		{
			$this->currentData = $aData;
		}
	}

	public function endTag(mixed $aParser, string $aName): void
	{
		if ($aName == "Title")
		{
			$this->missionName = $this->currentData;
		}

		if ($aName == "Duration")
		{
			$this->duration = (float)$this->currentData;
		}

		if ($aName == "StartTime")
		{
			// Tacview 0.85 (obsolete)
			$this->startTime = (float)$this->currentData;
		}

		if ($aName == "MissionTime")
		{
			// Tacview 0.93 (full UTC date format)
			$startTime       = $this->date_parse_from_format("YYYY?mm?dd?HH?ii?ss?", $this->currentData);
			$this->startTime = $startTime["hour"] * 60 * 60 + $startTime["minute"] * 60 + $startTime["second"];
		}

		/*
		 * if($aName == "Objects") { $this->tagObjectsOpened = false; } if($this->tagObjectsOpened === true) { if($aName == "Object") { $this->tagObjectOpened = false; } if($aName == "Parent") { if($this->currentData) { $this->objects[$this->objectCurrentId][$aName] = $this->objects[$this->currentData]; } } if($aName != "Object" and $aName != "Parent") { $str = $this->currentData; if($aName == "Type") { if($str == "Bullet" or $str == "Shell") { $this->objects[$this->objectCurrentId]["Name"] = $this->L("Object".$str); } } $str = str_replace(' ', ' ', $str); $str = str_replace('Ã%u201A', ' ', $str); if(!isset($this->objects[$this->objectCurrentId][$aName])){ // Aggiunto da 53.Sparrow per consentire la visualizzazione dei proiettili $this->objects[$this->objectCurrentId][$aName] = $str; } } }
		 */
		if ($aName == "Events")
		{
			$this->tagEventsOpened = false;
		}

		if ($this->tagEventsOpened === true)
		{

			if ($aName == "Event")
			{
				$this->tagEventOpened = false;
			}

			if ($aName == "PrimaryObject")
			{
				$this->tagPrimaryObjectOpened = false;
			}

			if ($aName == "SecondaryObject")
			{
				$this->tagSecondaryObjectOpened = false;
			}

			if ($aName == "ParentObject")
			{
				$this->tagParentObjectOpened = false;
			}

			if ($aName == "Airport")
			{
				$this->tagAirportOpened = false;
			}

			if ($aName != "Event" and $aName != "PrimaryObject" and $aName != "SecondaryObject" and $aName != "ParentObject" and $aName != "Airport")
			{
				if ($this->tagPrimaryObjectOpened === true)
				{
					$this->events[$this->eventCurrentId]["PrimaryObject"][$aName] = $this->currentData;
				}
				else if ($this->tagSecondaryObjectOpened === true)
				{
					$this->events[$this->eventCurrentId]["SecondaryObject"][$aName] = $this->currentData;
				}
				else if ($this->tagParentObjectOpened === true)
				{
					$this->events[$this->eventCurrentId]["ParentObject"][$aName] = $this->currentData;
				}
				else if ($this->tagAirportOpened === true)
				{
					$this->events[$this->eventCurrentId]["Airport"][$aName] = $this->currentData;
				}
				else
				{
					$this->events[$this->eventCurrentId][$aName] = $this->currentData;
				}
			}

			/*
			 * if($aName == "PrimaryObject" OR $aName == "SecondaryObject") { if($this->currentData) { //$this->events[$this->eventCurrentId][$aName] = $this->objects[$this->currentData]; $this->events[$this->eventCurrentId][$aName] = $this->currentData; } } if($aName != "Event" and $aName != "PrimaryObject" and $aName != "SecondaryObject") { $this->events[$this->eventCurrentId][$aName] = $this->currentData; }
			 */
		}
	}
}

?>
