param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('verify', 'migrate', 'test')]
    [string]$Action
)

$ErrorActionPreference = 'Stop'
$php = (Get-Command php.exe -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
}

if ($Action -eq 'migrate') {
    & $php artisan stage3:verify-test-db --env=stage3-test
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & $php artisan migrate:fresh --env=stage3-test --force
    exit $LASTEXITCODE
}

if ($Action -eq 'test') {
    & $php artisan stage3:verify-test-db --env=stage3-test-app
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & $php artisan test --env=stage3-test-app --configuration=phpunit.stage3.xml
    exit $LASTEXITCODE
}

& $php artisan stage3:verify-test-db --env=stage3-test-app
exit $LASTEXITCODE
