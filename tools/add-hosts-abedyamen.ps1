$hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
$entry = "127.0.0.1 abedyamen.local"
$text = Get-Content $hostsPath -Raw
if ($text -notmatch '(?m)^\s*127\.0\.0\.1\s+abedyamen\.local\b') {
    Add-Content -Path $hostsPath -Value "`r`n$entry" -Encoding ASCII
    "ADDED" | Out-File "C:\Users\win\Documents\laravel\abedyamen\hosts-check.txt"
} else {
    "EXISTS" | Out-File "C:\Users\win\Documents\laravel\abedyamen\hosts-check.txt"
}
Get-Content $hostsPath | Select-String "abedyamen" | Out-File "C:\Users\win\Documents\laravel\abedyamen\hosts-check.txt" -Append
