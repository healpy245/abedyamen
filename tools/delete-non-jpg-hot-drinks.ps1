$hotDrinksFolder = Join-Path $PSScriptRoot "..\public\HotDrinks"

Write-Host "Deleting non-JPG files in '$hotDrinksFolder'..." -ForegroundColor Cyan

if (-not (Test-Path -LiteralPath $hotDrinksFolder)) {
    Write-Error "Folder not found: $hotDrinksFolder"
    exit 1
}

$filesToDelete = Get-ChildItem -LiteralPath $hotDrinksFolder -File | Where-Object {
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

