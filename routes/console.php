<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes & Task Scheduling
|--------------------------------------------------------------------------
|
| Automated background daemons for health checking and telemetry pruning.
|
*/

// Non-blocking parallel health checks across all cluster upstreams every minute
Schedule::command('loadbalancer:health-check --timeout=3 --consecutive=2')->everyMinute();

// Prune historical request telemetry older than 7 days daily
Schedule::command('loadbalancer:prune-metrics --days=7')->daily();
