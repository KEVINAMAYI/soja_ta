<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;


Schedule::command('attendance:seed')->everyMinute();
Schedule::command('reports:send')->everyMinute();
