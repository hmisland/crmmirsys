# Builds upload.zip in the project root: exactly what goes onto cPanel.
#
# Included: .htaccess, app/, config/, database/, lang/, modules/, public/,
# routes/, themes/, the bundled PDF library in vendor/ (runtime files only),
# and an EMPTY storage/ skeleton (folders + .gitkeep only).
# Never included: .env, .dev-credentials, ddddd/, .git/, local logs/mail/
# uploads, docs/tests, SQL dumps, other zips, editor/IDE folders.
#
# Usage (from the project root):  powershell -ExecutionPolicy Bypass -File tools\build-upload.ps1

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $PSScriptRoot
$zipPath = Join-Path $root 'upload.zip'
$codeDirs = 'app', 'config', 'database', 'lang', 'modules', 'public', 'routes', 'themes'
$rootFiles = '.htaccess'
$storageDirs = 'storage/cache', 'storage/file-library', 'storage/logs', 'storage/mail', 'storage/uploads'

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
$count = 0

function Add-File($absolute, $entryName) {
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $absolute, $entryName, 'Optimal') | Out-Null
    $script:count++
}

try {
    foreach ($f in $rootFiles) { Add-File (Join-Path $root $f) $f }

    foreach ($dir in $codeDirs) {
        Get-ChildItem (Join-Path $root $dir) -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($root.Length + 1).Replace('\', '/')
            if ($rel -match '\.(sql|zip|log|bak)$') { return }
            Add-File $_.FullName $rel
        }
    }

    # vendor: bundled PDF library (mPDF + deps) — runtime files only
    Add-File (Join-Path $root 'vendor/autoload.php') 'vendor/autoload.php'
    $vendorDirs = 'vendor/mpdf/mpdf/src', 'vendor/mpdf/mpdf/data', 'vendor/mpdf/mpdf/ttfonts',
        'vendor/mpdf/psr-http-message-shim/src', 'vendor/mpdf/psr-log-aware-trait/src',
        'vendor/myclabs/deep-copy/src', 'vendor/psr/http-message/src', 'vendor/psr/log/src', 'vendor/setasign/fpdi/src'
    foreach ($dir in $vendorDirs) {
        Get-ChildItem (Join-Path $root $dir) -Recurse -File | ForEach-Object {
            Add-File $_.FullName ($_.FullName.Substring($root.Length + 1).Replace('\', '/'))
        }
    }
    Get-ChildItem (Join-Path $root 'vendor') -Recurse -File -Include 'LICENSE*', 'COPYING*' | ForEach-Object {
        Add-File $_.FullName ($_.FullName.Substring($root.Length + 1).Replace('\', '/'))
    }

    # storage: structure only, never local runtime data
    foreach ($dir in $storageDirs) {
        $keep = Join-Path $root ($dir + '/.gitkeep')
        if (Test-Path $keep) { Add-File $keep ($dir + '/.gitkeep') }
        else { $zip.CreateEntry($dir + '/') | Out-Null }
    }
}
finally {
    $zip.Dispose()
}

$size = [math]::Round((Get-Item $zipPath).Length / 1KB)
Write-Output "upload.zip: $count files, $size KB"
