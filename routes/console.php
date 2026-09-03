<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\FetchZKBioTransactions;
use App\Jobs\ProcessExpiredCheckInApprovals;


Schedule::command('attendance:seed')->everyMinute();
Schedule::command('reports:send')->everyMinute();

Schedule::job(FetchZKBioTransactions::class)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(new ProcessExpiredCheckInApprovals)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('employees:auto-deactivate')
    ->daily()
    ->appendOutputTo(storage_path('logs/employees-auto-deactivate.log'));
