Param(
    [string]$NaturalJuiceFolder = "$(Split-Path $PSScriptRoot -Parent)\public\NaturalJuice"
)

Write-Host "Converting images in '$NaturalJuiceFolder' to JPG..." -ForegroundColor Cyan

if (-not (Test-Path -LiteralPath $NaturalJuiceFolder)) {
    Write-Error "Folder not found: $NaturalJuiceFolder"
    exit 1
}

Add-Type -AssemblyName System.Drawing

$files = Get-ChildItem -LiteralPath $NaturalJuiceFolder -File | Where-Object {
    $_.Extension.ToLower() -ne ".jpg"
}

if (-not $files) {
    Write-Host "No non-JPG images found to convert." -ForegroundColor Yellow
    exit 0
}

foreach ($file in $files) {
    $sourcePath = $file.FullName
    $destPath = [System.IO.Path]::ChangeExtension($sourcePath, ".jpg")

    try {
        Write-Host "Converting $($file.Name) -> $(Split-Path $destPath -Leaf)"
        $image = [System.Drawing.Image]::FromFile($sourcePath)
        $image.Save($destPath, [System.Drawing.Imaging.ImageFormat]::Jpeg)
        $image.Dispose()

        # Uncomment the line below if you want to delete the original file after successful conversion
        # Remove-Item -LiteralPath $sourcePath
    }
    catch {
        Write-Warning "Failed to convert $($file.Name): $_"
    }
}

Write-Host "Conversion finished." -ForegroundColor Green


