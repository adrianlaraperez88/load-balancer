#!/bin/bash
# Cross-platform testing script

if [ "$IS_DOCKER" = "1" ]; then
    echo "Running in Docker... Environment clears itself on start."
    echo "Starting test suite."
    vendor/bin/phpunit
else
    echo "Running locally... Temporarily bootstrapping Laravel for E2E consistency..."
    
    # 1. Clean existing dummy app
    rm -rf tests/sandbox/test-app
    
    # 2. Build fresh app quietly
    echo "Installing fresh Laravel application..."
    composer create-project laravel/laravel tests/sandbox/test-app --prefer-dist -q
    
    # 3. Run tests
    echo "Executing PHPUnit..."
    vendor/bin/phpunit
    
    # 4. Clean up
    echo "Cleaning up local Laravel environment..."
    rm -rf tests/sandbox/test-app
    echo "Tests completed securely."
fi
