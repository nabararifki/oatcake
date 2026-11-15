<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:prune-photos')->daily();
Schedule::command('leave:accrue-annual')->daily();
Schedule::command('leave:prune-expired')->daily();
