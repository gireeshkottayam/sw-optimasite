# build.ps1 — produce an installable WordPress plugin .zip for OptimaSite.
# Usage:  powershell -File build.ps1 [version]   (defaults to 1.0.0)
# Output: build/sw-optimasite-<version>.zip containing a top-level
#         sw-optimasite/ folder (for Upload in Plugins > Add New).
param([string]$Version = "")

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$version = if ($Version -eq "") { "1.0.0" } else { $Version }
$build = Join-Path $root "build"
New-Item -ItemType Directory -Force -Path $build | Out-Null

$exe = "D:\wamp64\bin\php\php8.3.28\php.exe"
if (Test-Path $exe) {
    Get-ChildItem (Join-Path $root "sw-optimasite.php"), (Join-Path $root "includes") -Filter *.php | ForEach-Object {
        & $exe -l $_.FullName | Out-Host
    }
}

$zip = Join-Path $build "sw-optimasite-$version.zip"
if (Test-Path $zip) { Remove-Item $zip }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$tmp = Join-Path $build ("pkg_" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path (Join-Path $tmp "sw-optimasite") | Out-Null

foreach ($item in @("sw-optimasite.php", "includes", "assets", "readme.txt")) {
    $src = Join-Path $root $item
    if (Test-Path $src) {
        Copy-Item $src (Join-Path $tmp "sw-optimasite") -Recurse -Force
    }
}

[System.IO.Compression.ZipFile]::CreateFromDirectory($tmp, $zip)
Remove-Item $tmp -Recurse -Force
Write-Host "Built: $zip"
