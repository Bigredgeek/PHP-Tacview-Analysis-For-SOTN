param(
  [string]$Api = 'https://commons.wikimedia.org/w/api.php',
  [string]$ListPath = 'tools/aircraft_list.txt',
  [string]$OutDir = 'objectIcons',
  [switch]$Force
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Ensure list exists
if (-not (Test-Path $ListPath)) {
  php tools/list_aircraft.php | Out-File -FilePath $ListPath -Encoding utf8
}

$root = Get-Location
$manifestPath = Join-Path $root 'data/aircraft_icons_manifest.json'
if (Test-Path $manifestPath) {
  $manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
} else {
  $manifest = [PSCustomObject]@{ meta = [PSCustomObject]@{ description=''; created=(Get-Date -Format 'yyyy-MM-dd'); notes='' }; aircraft = @() }
}

$lines = Get-Content $ListPath | Where-Object { $_ -and ($_ -notmatch '^Found ') }
$names = @()
foreach ($line in $lines) {
  $parts = $line -split "`t"
  if ($parts.Length -ge 1) { $names += $parts[0].Trim() }
}
$names = $names | Sort-Object -Unique

# Map aircraft names to Wikipedia article titles
$wikiTitleMap = @{
  'A-10A Thunderbolt II' = 'Fairchild Republic A-10 Thunderbolt II'
  'A-10C Thunderbolt II' = 'Fairchild Republic A-10 Thunderbolt II'
  'A-4E Skyhawk' = 'Douglas A-4 Skyhawk'
  'A-50 Mainstay' = 'Beriev A-50'
  'AJS 37 Viggen' = 'Saab 37 Viggen'
  'AV-8B Harrier II NA' = 'McDonnell Douglas AV-8B Harrier II'
  'F-104 Starfighter' = 'Lockheed F-104 Starfighter'
  'F-14A Tomcat' = 'Grumman F-14 Tomcat'
  'F-14B Tomcat' = 'Grumman F-14 Tomcat'
  'F-15C Eagle' = 'McDonnell Douglas F-15 Eagle'
  'F-15E Strike Eagle' = 'McDonnell Douglas F-15E Strike Eagle'
  'F-16C Fighting Falcon' = 'General Dynamics F-16 Fighting Falcon'
  'F-4E Phantom II' = 'McDonnell Douglas F-4 Phantom II'
  'F/A-18C Hornet' = 'McDonnell Douglas F/A-18 Hornet'
  'MiG-21bis Fishbed-L/N' = 'Mikoyan-Gurevich MiG-21'
  'MiG-23MLD Flogger-K' = 'Mikoyan-Gurevich MiG-23'
  'MiG-25PD Foxbat-E' = 'Mikoyan-Gurevich MiG-25'
  'MiG-29 Fulcrum' = 'Mikoyan MiG-29'
  'MiG-29A Fulcrum-A' = 'Mikoyan MiG-29'
  'Mirage 2000C' = 'Dassault Mirage 2000'
  'Mirage F1 EE' = 'Dassault Mirage F1'
  'Su-17M4 Fitter-K' = 'Sukhoi Su-17'
  'Su-25 Frogfoot' = 'Sukhoi Su-25'
  'Su-25T Frogfoot' = 'Sukhoi Su-25'
}

New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

function Set-Prop($obj, [string]$name, $value) {
  $p = $obj.PSObject.Properties[$name]
  if ($null -ne $p) { $obj.$name = $value }
  else { $obj | Add-Member -NotePropertyName $name -NotePropertyValue $value }
}

function Get-WikipediaMainImage($articleTitle) {
  # Get the main infobox image from Wikipedia using pageimages API
  $wikiApi = 'https://en.wikipedia.org/w/api.php'
  $params = @{
    action = 'query'
    format = 'json'
    titles = $articleTitle
    prop = 'pageimages'
    pithumbsize = 1280
  }
  $uri = "$($wikiApi)?$(($params.GetEnumerator() | ForEach-Object { '{0}={1}' -f [System.Web.HttpUtility]::UrlEncode($_.Key), [System.Web.HttpUtility]::UrlEncode([string]$_.Value) }) -join '&')"
  try {
    $json = Invoke-RestMethod -Uri $uri -Headers @{ 'User-Agent'='php-tacview/1.0' } -TimeoutSec 30
  } catch {
    return $null
  }
  if (-not $json.query.pages) { return $null }
  $page = $json.query.pages.PSObject.Properties.Value | Select-Object -First 1
  if ($page.PSObject.Properties['missing'] -or (-not $page.PSObject.Properties['thumbnail'])) { return $null }
  
  $thumb = $page.thumbnail.source
  # Get full-size URL by manipulating thumbnail URL
  $fullUrl = $thumb -replace '/thumb/', '/' -replace '/\d+px-[^/]+$', ''
  
  return [PSCustomObject]@{
    Title = $page.title
    Thumb = $thumb
    Url   = $fullUrl
    Desc  = "https://en.wikipedia.org/wiki/$([System.Web.HttpUtility]::UrlEncode($page.title))"
    Meta  = $null
  }
}

$ok = 0; $fail = 0
foreach ($name in $names) {
  $base = ($name -replace '[ /]', '_')
  $jpgPath = Join-Path $OutDir ($base + '.jpg')
  $pngPath = Join-Path $OutDir ($base + '.png')
  if ( ((Test-Path $jpgPath) -or (Test-Path $pngPath)) -and (-not $Force) ) {
    $existing = if (Test-Path $jpgPath) { (Split-Path $jpgPath -Leaf) } else { (Split-Path $pngPath -Leaf) }
    Write-Host "SKIP`t$name`t$existing (exists)" -ForegroundColor Yellow
    continue
  }
  # Look up the Wikipedia article title
  $wikiTitle = if ($wikiTitleMap.ContainsKey($name)) { $wikiTitleMap[$name] } else { $name }
  
  # Try Wikipedia article first
  $res = Get-WikipediaMainImage $wikiTitle
  if (-not $res) {
    # Try with " (aircraft)" suffix for disambiguation
    $res = Get-WikipediaMainImage "$wikiTitle (aircraft)"
  }
  if (-not $res -or -not $res.Url) {
    Write-Host "MISS`t$name" -ForegroundColor Red
    $fail++
    continue
  }
  $src = $res.Thumb
  if (-not $src) { $src = $res.Url }
  $ext = if ($src -match '\.png($|\?)') { 'png' } elseif ($src -match '\.jpe?g($|\?)') { 'jpg' } else { 'jpg' }
  $target = "$base.$ext"
  $dst = Join-Path $OutDir $target
  try {
    Invoke-WebRequest -Uri $src -Headers @{ 'User-Agent'='php-tacview/1.0' } -OutFile $dst -TimeoutSec 60
    Write-Host "OK`t$name`t$target" -ForegroundColor Green
    $ok++
    # Update manifest entry
    $entry = $manifest.aircraft | Where-Object { $_.name -eq $name } | Select-Object -First 1
    if (-not $entry) {
      $entry = [PSCustomObject]@{ name=$name; targetFilename=$target }
      $manifest.aircraft += $entry
    }
    Set-Prop $entry 'targetFilename' $target
  Set-Prop $entry 'fileUrl' $res.Url
  Set-Prop $entry 'descriptionPage' $res.Desc
  $meta = $res.Meta
  $lic = $null
  if ($meta -and $meta.PSObject.Properties['LicenseShortName']) { $lic = $meta.LicenseShortName.value }
  if (-not $lic -and $meta -and $meta.PSObject.Properties['UsageTerms']) { $lic = $meta.UsageTerms.value }
  if ($lic) { Set-Prop $entry 'license' $lic }
  $attr = $null
  if ($meta -and $meta.PSObject.Properties['Artist']) { $attr = $meta.Artist.value }
  if (-not $attr -and $meta -and $meta.PSObject.Properties['Credit']) { $attr = $meta.Credit.value }
  if ($attr) { Set-Prop $entry 'attribution' $attr }
  } catch {
    Write-Host "DLFAIL`t$name`t$src`t$($_.Exception.Message)" -ForegroundColor Red
    $fail++
  }
}

$manifest | ConvertTo-Json -Depth 6 | Out-File -FilePath $manifestPath -Encoding utf8

Write-Host "`nCompleted. OK=$ok FAIL=$fail" -ForegroundColor Cyan
