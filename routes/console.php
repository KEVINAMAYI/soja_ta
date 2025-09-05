<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;


Schedule::command('attendance:seed')->dailyAt('00:05');
Schedule::command('reports:send')->everyMinute();
