param(
  [string]$HooksPath = ".githooks"
)

Write-Host "Configuring git hooks path to '$HooksPath'..."
& git config core.hooksPath $HooksPath
if ($LASTEXITCODE -ne 0) {
  Write-Error "Failed to set core.hooksPath. Ensure you're in the repo root."
  exit 1
}

# Make bash hook executable (for Git Bash environments)
$preCommit = Join-Path -Path (Get-Location) -ChildPath "$HooksPath/pre-commit"
if (Test-Path $preCommit) {
  try {
    # On Windows, chmod may not exist; ignore errors
    bash -lc "chmod +x '$preCommit'" | Out-Null
  } catch { }
}

Write-Host "Hooks installed. Pre-commit will auto-normalize objectIcons/*.jpg|png before committing."
