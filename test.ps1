$isDocker = $env:IS_DOCKER

if ($isDocker -eq '1') {
    Write-Host "Running in Docker... Environment clears itself on start." -ForegroundColor Cyan
    Write-Host "Starting test suite." -ForegroundColor Cyan
    vendor/bin/phpunit
} else {
    Write-Host "Running locally... Temporarily bootstrapping Laravel for E2E consistency..." -ForegroundColor Cyan
    
    # 1. Clean existing dummy app
    if (Test-Path "tests/sandbox/test-app") {
        Remove-Item "tests/sandbox/test-app" -Recurse -Force
    }
    
    # 2. Build fresh app quietly
    Write-Host "Installing fresh Laravel application..." -ForegroundColor Yellow
    composer create-project laravel/laravel tests/sandbox/test-app --prefer-dist -q
    
    # 3. Run tests
    Write-Host "Executing PHPUnit..." -ForegroundColor Yellow
    vendor/bin/phpunit
    
    # 4. Clean up
    Write-Host "Cleaning up local Laravel environment..." -ForegroundColor Green
    if (Test-Path "tests/sandbox/test-app") {
        Remove-Item "tests/sandbox/test-app" -Recurse -Force
    }
    Write-Host "Tests completed securely." -ForegroundColor Green
}
