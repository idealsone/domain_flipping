$php = "C:\Users\Lenovo\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root
Write-Host "CVTara local server: http://127.0.0.1:8080"
Write-Host "Open /install.php after MySQL is ready, or / for the public site."
& $php -S 127.0.0.1:8080 -t "$root\public_html" "$root\public_html\router.php"
