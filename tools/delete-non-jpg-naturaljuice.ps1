$naturalJuiceFolder = Join-Path $PSScriptRoot "..\public\NaturalJuice"

Write-Host "Deleting non-JPG files in '$naturalJuiceFolder'..." -ForegroundColor Cyan

if (-not (Test-Path -LiteralPath $naturalJuiceFolder)) {
    Write-Error "Folder not found: $naturalJuiceFolder"
    exit 1
}

$filesToDelete = Get-ChildItem -LiteralPath $naturalJuiceFolder -File | Where-Object {
    $_.Extension.ToLower() -ne '.jpg'
}

if (-not $filesToDelete) {
    Write-Host "No non-JPG files found to delete." -ForegroundColor Yellow
    exit 0
}

foreach ($file in $filesToDelete) {
    Write-Host "Deleting: $($file.Name)" -ForegroundColor Yellow
    Remove-Item -LiteralPath $file.FullName -Force
}

Write-Host "Deletion finished. Removed $($filesToDelete.Count) file(s)." -ForegroundColor Green

