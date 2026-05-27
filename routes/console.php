<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\FetchZKBioTransactions;

Schedule::command('attendance:seed')->everyMinute();
Schedule::command('reports:send')->everyMinute();

Schedule::job(FetchZKBioTransactions::class)
    ->everyMinute()
    ->withoutOverlapping();
