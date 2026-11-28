<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('audit:prune')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('telescope:prune', ['--hours' => config('footfy.telescope_prune_hours')])->daily();
