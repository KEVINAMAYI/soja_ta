<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

Schedule::command('attendance:seed')->everyMinute();
Schedule::command('reports:send')->everyMinute();

Schedule::command('zkbio:sync')
    ->everyMinute()
    ->withoutOverlapping(5)    // lock expires after 5 min if command hangs
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/zkbio-sync.log'));

Schedule::command('elevate-hr:fetch')
    ->dailyAt('00:00')
    ->withoutOverlapping();
