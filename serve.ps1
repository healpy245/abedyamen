# Serve Laravel via XAMPP Apache (much faster than php artisan serve).
# Site: http://abedyamen.local
Set-Location $PSScriptRoot

$hostsScript = Join-Path $PSScriptRoot "tools\add-hosts-abedyamen.ps1"
$hostsText = Get-Content "$env:SystemRoot\System32\drivers\etc\hosts" -Raw -ErrorAction SilentlyContinue
if ($hostsText -notmatch "(?m)^\s*127\.0\.0\.1\s+abedyamen\.local\b") {
    Write-Host "Adding hosts entry (UAC prompt)..."
    Start-Process powershell -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$hostsScript`"" -Wait
}

$httpd = "C:\xampp\apache\bin\httpd.exe"
if (-not (Test-Path $httpd)) {
    Write-Error "XAMPP Apache not found at $httpd"
    exit 1
}

& $httpd -t 2>&1 | ForEach-Object { "$_" }

if (-not (Get-Process httpd -ErrorAction SilentlyContinue)) {
    Write-Host "Starting Apache..."
    Start-Process -FilePath $httpd -WindowStyle Hidden
    Start-Sleep -Seconds 2
} else {
    Write-Host "Apache already running."
}

if (-not (Get-Process httpd -ErrorAction SilentlyContinue)) {
    Write-Host "Apache failed to start. Open XAMPP Control Panel and start Apache, then visit:"
    Write-Host "  http://abedyamen.local"
    exit 1
}

Write-Host ""
Write-Host "Fast local site: http://abedyamen.local"
Write-Host "Optional Vite:     npm run dev"
Write-Host "Optional full:     composer run dev   (queue + logs + vite; Apache already serves PHP)"
